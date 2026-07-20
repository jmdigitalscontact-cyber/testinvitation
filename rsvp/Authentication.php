<?php
// Authentication and Validation Logic

class Authentication {
    private $db;
    private $mysqli;
    private $hasInvitedGuestNamesColumn = null;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->mysqli = $this->db->getConnection();
    }

    private function hasInvitedGuestNamesColumn() {
        if ($this->hasInvitedGuestNamesColumn !== null) {
            return $this->hasInvitedGuestNamesColumn;
        }

        $result = $this->mysqli->query("SHOW COLUMNS FROM invitations LIKE 'invited_guest_names'");
        $this->hasInvitedGuestNamesColumn = (bool)($result && $result->num_rows > 0);
        return $this->hasInvitedGuestNamesColumn;
    }

    private function decodeInvitedGuestNames($value) {
        return !empty($value) ? (json_decode($value, true) ?: []) : [];
    }

    private function decodeInvitedGuestNamesFromNotes($notes) {
        $notes = (string)$notes;
        if (strpos($notes, 'INVITED_GUEST_NAMES:') !== 0) {
            return [];
        }
        $json = substr($notes, strlen('INVITED_GUEST_NAMES:'));
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Verify invitation and password
     * @param string $invitation_id
     * @param string $password
     * @return array|false
     */
    public function verifyInvitation($invitation_id, $password) {
        // Check rate limiting first
        if (!$this->checkRateLimit($invitation_id)) {
            return false;
        }

        $selectInvited = $this->hasInvitedGuestNamesColumn() ? ", invited_guest_names" : ", notes";
        $stmt = $this->mysqli->prepare("
            SELECT id, invitation_id, guest_name, max_guests, status{$selectInvited}
            FROM invitations
            WHERE invitation_id = ?
        ");
        
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param("s", $invitation_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            $this->logLoginAttempt($invitation_id, false);
            return false;
        }

        $invitation = $result->fetch_assoc();
        $invitation['invited_guest_names'] = $this->hasInvitedGuestNamesColumn()
            ? $this->decodeInvitedGuestNames($invitation['invited_guest_names'] ?? '')
            : $this->decodeInvitedGuestNamesFromNotes($invitation['notes'] ?? '');
        $stmt->close();

        // Get password hash from database
        $stmt = $this->mysqli->prepare("
            SELECT password_hash FROM invitations WHERE invitation_id = ?
        ");
        $stmt->bind_param("s", $invitation_id);
        $stmt->execute();
        $passwordResult = $stmt->get_result();
        $passwordRow = $passwordResult->fetch_assoc();
        $stmt->close();

        if ($passwordRow && password_verify($password, $passwordRow['password_hash'])) {
            $this->logLoginAttempt($invitation_id, true);
            // Create session token
            $invitation['token'] = $this->generateToken($invitation_id);
            return $invitation;
        }

        $this->logLoginAttempt($invitation_id, false);
        return false;
    }

    /**
     * Verify invitation through QR scan only (no password prompt)
     * Requires the invitation to have an existing QR code record.
     * @param string $invitation_id
     * @return array|false
     */
    /**
     * Rate limit QR verification attempts by IP (all invitation IDs).
     * @return bool
     */
    public function checkQrRateLimit() {
        $ip = $this->getClientIP();
        $currentTime = time();
        $windowStart = $currentTime - LOGIN_ATTEMPT_WINDOW;

        $stmt = $this->mysqli->prepare("
            SELECT COUNT(*) as failed_attempts
            FROM login_attempts
            WHERE ip_address = ?
            AND attempt_time > FROM_UNIXTIME(?)
            AND success = FALSE
        ");

        if (!$stmt) {
            return true;
        }

        $stmt->bind_param("si", $ip, $windowStart);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        return (int)($row['failed_attempts'] ?? 0) < MAX_LOGIN_ATTEMPTS;
    }

    public function verifyInvitationByQRCode($invitation_id) {
        if (!$this->checkQrRateLimit()) {
            return ['rate_limited' => true];
        }

        $selectInvited = $this->hasInvitedGuestNamesColumn() ? ", i.invited_guest_names" : ", i.notes";
        $stmt = $this->mysqli->prepare("
            SELECT i.id, i.invitation_id, i.guest_name, i.max_guests, i.status{$selectInvited}
            FROM invitations i
            WHERE i.invitation_id = ?
        ");

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param("s", $invitation_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            $stmt->close();
            $this->logLoginAttempt($invitation_id, false);
            return false;
        }

        $invitation = $result->fetch_assoc();
        $invitation['invited_guest_names'] = $this->hasInvitedGuestNamesColumn()
            ? $this->decodeInvitedGuestNames($invitation['invited_guest_names'] ?? '')
            : $this->decodeInvitedGuestNamesFromNotes($invitation['notes'] ?? '');
        $stmt->close();

        $this->logLoginAttempt($invitation_id, true);
        $invitation['token'] = $this->generateToken($invitation_id);
        return $invitation;
    }

    /**
     * Authenticate an admin user and create an admin session token
     * @param string $username
     * @param string $password
     * @return array|false
     */
    public function adminLogin($username, $password) {
        $stmt = $this->mysqli->prepare("SELECT id, username, password_hash FROM admin_users WHERE username = ?");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            $stmt->close();
            return false;
        }

        $admin = $result->fetch_assoc();
        $stmt->close();

        if (!password_verify($password, $admin['password_hash'])) {
            return false;
        }

        $this->updateAdminLastLogin($admin['id']);
        $token = $this->generateAdminToken($admin['id']);

        return [
            'admin_id' => $admin['id'],
            'username' => $admin['username'],
            'token' => $token
        ];
    }

    /**
     * Generate admin session token
     * @param int $admin_id
     * @return string
     */
    private function generateAdminToken($admin_id) {
        $token = bin2hex(random_bytes(32));
        $expiry = time() + 3600; // 1 hour

        $stmt = $this->mysqli->prepare("INSERT INTO admin_sessions (admin_id, token, expiry_time) VALUES (?, ?, FROM_UNIXTIME(?)) ON DUPLICATE KEY UPDATE token = VALUES(token), expiry_time = VALUES(expiry_time)");
        $stmt->bind_param("isi", $admin_id, $token, $expiry);
        $stmt->execute();
        $stmt->close();

        return $token;
    }

    /**
     * Validate admin token
     * @param string $token
     * @return array|false
     */
    public function validateAdminToken($token) {
        $stmt = $this->mysqli->prepare("SELECT a.id, a.username FROM admin_sessions s JOIN admin_users a ON s.admin_id = a.id WHERE s.token = ? AND s.expiry_time > NOW()");
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param("s", $token);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            $stmt->close();
            return false;
        }

        $admin = $result->fetch_assoc();
        $stmt->close();

        return $admin;
    }

    /**
     * Update admin last login datetime
     * @param int $admin_id
     */
    private function updateAdminLastLogin($admin_id) {
        $stmt = $this->mysqli->prepare("UPDATE admin_users SET last_login = NOW() WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $admin_id);
            $stmt->execute();
            $stmt->close();
        }
    }

    /**
     * Check rate limiting to prevent brute force
     * @param string $invitation_id
     * @return bool
     */
    private function checkRateLimit($invitation_id) {
        $ip = $this->getClientIP();
        $currentTime = time();
        $windowStart = $currentTime - LOGIN_ATTEMPT_WINDOW;

        // Check for lockout
        $stmt = $this->mysqli->prepare("
            SELECT COUNT(*) as failed_attempts 
            FROM login_attempts 
            WHERE invitation_id = ? AND ip_address = ? 
            AND attempt_time > FROM_UNIXTIME(?) 
            AND success = FALSE
        ");
        
        if (!$stmt) {
            // If logging table is unavailable, do not block guest access.
            return true;
        }

        $stmt->bind_param("ssi", $invitation_id, $ip, $windowStart);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        if ($row['failed_attempts'] >= MAX_LOGIN_ATTEMPTS) {
            return false;
        }

        return true;
    }

    /**
     * Log login attempts
     * @param string $invitation_id
     * @param bool $success
     */
    private function logLoginAttempt($invitation_id, $success = false) {
        $ip = $this->getClientIP();
        $stmt = $this->mysqli->prepare("
            INSERT INTO login_attempts (invitation_id, ip_address, success) 
            VALUES (?, ?, ?)
        ");

        if (!$stmt) {
            // Fail silently when audit table is unavailable.
            return;
        }

        $success_int = $success ? 1 : 0;
        $stmt->bind_param("ssi", $invitation_id, $ip, $success_int);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Generate unique token for session
     * @param string $invitation_id
     * @return string
     */
    private function generateToken($invitation_id) {
        $token = bin2hex(random_bytes(32));
        $expiry = time() + 3600; // 1 hour

        $stmt = $this->mysqli->prepare("
            INSERT INTO sessions (invitation_id, token, expiry_time) 
            VALUES (?, ?, FROM_UNIXTIME(?))
            ON DUPLICATE KEY UPDATE token = VALUES(token), expiry_time = VALUES(expiry_time)
        ");

        if (!$stmt) {
            // Return a token even if persistence fails; caller will still use inline invitation data.
            return $token;
        }

        $stmt->bind_param("ssi", $invitation_id, $token, $expiry);
        $stmt->execute();
        $stmt->close();

        return $token;
    }

    /**
     * Validate token
     * @param string $token
     * @return array|false
     */
    public function validateToken($token) {
        $stmt = $this->mysqli->prepare("
            SELECT invitation_id FROM sessions 
            WHERE token = ? AND expiry_time > NOW()
        ");

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param("s", $token);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            $stmt->close();
            return false;
        }

        $session = $result->fetch_assoc();
        $stmt->close();

        // Get invitation details
        return $this->getInvitationDetails($session['invitation_id']);
    }

    /**
     * Get invitation details including RSVP status
     * @param string $invitation_id
     * @return array|false
     */
    public function getInvitationDetails($invitation_id) {
        $selectInvited = $this->hasInvitedGuestNamesColumn() ? ", i.invited_guest_names" : ", i.notes";
        $stmt = $this->mysqli->prepare("
            SELECT i.id, i.invitation_id, i.guest_name, i.max_guests, i.status{$selectInvited},
                   COALESCE(r.attending, 'pending') as rsvp_status,
                   COALESCE(r.attendee_count, 0) as attendee_count,
                   COALESCE(r.dietary_restrictions, '') as dietary_restrictions,
                   COALESCE(r.special_notes, '') as special_notes
            FROM invitations i
            LEFT JOIN rsvp_responses r ON i.invitation_id = r.invitation_id
            WHERE i.invitation_id = ?
        ");
        
        $stmt->bind_param("s", $invitation_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            $stmt->close();
            return false;
        }

        $data = $result->fetch_assoc();
        $data['invited_guest_names'] = $this->hasInvitedGuestNamesColumn()
            ? $this->decodeInvitedGuestNames($data['invited_guest_names'] ?? '')
            : $this->decodeInvitedGuestNamesFromNotes($data['notes'] ?? '');
        $stmt->close();
        return $data;
    }

    /**
     * Get client IP address
     * @return string
     */
    private function getClientIP() {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        return filter_var($ip, FILTER_VALIDATE_IP) ?: '0.0.0.0';
    }
}

?>
