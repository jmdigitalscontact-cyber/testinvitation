<?php
/**
 * AuditLogger Class - Comprehensive audit logging for security monitoring
 * 
 * Features:
 * - Immutable audit logs in database
 * - Tracks authentication, RSVP changes, admin actions
 * - Tamper detection with HMAC
 * - Search and reporting capabilities
 */

class AuditLogger {
    private static $instance = null;
    private $db;
    private $mysqli;
    private $enabled = true;

    private const LOG_TYPES = [
        'AUTH_SUCCESS' => 'Authentication successful',
        'AUTH_FAILED' => 'Authentication failed',
        'AUTH_LOCKOUT' => 'Account locked (too many attempts)',
        'RSVP_SUBMITTED' => 'RSVP submitted',
        'RSVP_VIEWED' => 'RSVP viewed',
        'ADMIN_LOGIN' => 'Admin logged in',
        'ADMIN_LOGOUT' => 'Admin logged out',
        'ADMIN_CREATE_INVITATION' => 'Admin created invitation',
        'ADMIN_DELETE_INVITATION' => 'Admin deleted invitation',
        'ADMIN_EXPORT_DATA' => 'Admin exported RSVP data',
        'ADMIN_USER_CREATED' => 'Admin user created',
        'ADMIN_USER_DELETED' => 'Admin user deleted',
        'DATA_ENCRYPTED' => 'Data encrypted',
        'DATA_DECRYPTED' => 'Data decrypted (for compliance audit)',
        'ENCRYPTION_KEY_ROTATED' => 'Encryption key rotated',
        'SECURITY_ALERT' => 'Security alert triggered',
        'DATA_ACCESSED' => 'Sensitive data accessed',
        'CONFIG_CHANGED' => 'Configuration changed'
    ];

    /**
     * Singleton instance
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new AuditLogger();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        try {
            $this->db = Database::getInstance();
            $this->mysqli = $this->db->getConnection();
            $this->enabled = EnvironmentLoader::has('ENABLE_AUDIT_LOG');
            
            if ($this->enabled) {
                $this->ensureTableExists();
            }
        } catch (Exception $e) {
            // Log initialization error but don't crash
            error_log('AuditLogger init error: ' . $e->getMessage());
            $this->enabled = false;
        }
    }

    /**
     * Log an event
     * 
     * @param string $logType Type from LOG_TYPES
     * @param string $invitationId Guest/Invitation ID (if applicable)
     * @param string $details JSON details of the event
     * @param string $ipAddress Client IP address
     * @param string $userAgent Client user agent
     * @return bool Success
     */
    public function log($logType, $invitationId = null, $details = '', $ipAddress = null, $userAgent = null) {
        if (!$this->enabled) {
            return false;
        }

        try {
            // Validate log type
            if (!isset(self::LOG_TYPES[$logType])) {
                $logType = 'SECURITY_ALERT';
                $details = 'Unknown event type: ' . $details;
            }

            // Get IP if not provided
            $ipAddress = $ipAddress ?? self::getClientIp();
            $userAgent = $userAgent ?? ($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown');

            // Prepare data
            $timestamp = date('Y-m-d H:i:s');
            $severity = $this->getEventSeverity($logType);

            // Create HMAC for integrity verification
            $hmacData = $logType . '|' . ($invitationId ?? '') . '|' . $timestamp . '|' . $ipAddress;
            $hmac = hash_hmac('sha256', $hmacData, getenv('ENCRYPTION_KEY') ?? 'default');

            // Insert audit log
            $stmt = $this->mysqli->prepare("
                INSERT INTO audit_logs 
                (timestamp, log_type, invitation_id, details, ip_address, user_agent, severity, integrity_hash) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");

            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->mysqli->error);
            }

            $stmt->bind_param(
                'ssssssss',
                $timestamp,
                $logType,
                $invitationId,
                $details,
                $ipAddress,
                $userAgent,
                $severity,
                $hmac
            );

            if (!$stmt->execute()) {
                throw new Exception("Execute failed: " . $stmt->error);
            }

            $stmt->close();
            return true;

        } catch (Exception $e) {
            error_log('AuditLogger error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Log authentication event
     */
    public function logAuth($invitationId, $success, $details = '', $ipAddress = null) {
        $logType = $success ? 'AUTH_SUCCESS' : 'AUTH_FAILED';
        $detailsJson = json_encode([
            'event' => $success ? 'successful_auth' : 'failed_auth',
            'details' => $details
        ]);
        
        return $this->log($logType, $invitationId, $detailsJson, $ipAddress);
    }

    /**
     * Log RSVP submission
     */
    public function logRsvpSubmission($invitationId, $attending, $guestCount, $ipAddress = null) {
        $details = json_encode([
            'attending' => $attending,
            'guest_count' => $guestCount,
            'submission_type' => 'new_rsvp'
        ]);

        return $this->log('RSVP_SUBMITTED', $invitationId, $details, $ipAddress);
    }

    /**
     * Log data access
     */
    public function logDataAccess($invitationId, $adminId = null, $fieldsAccessed = [], $ipAddress = null) {
        $details = json_encode([
            'admin_id' => $adminId,
            'fields_accessed' => $fieldsAccessed,
            'access_type' => 'query'
        ]);

        return $this->log('DATA_ACCESSED', $invitationId, $details, $ipAddress);
    }

    /**
     * Log security alert
     */
    public function logSecurityAlert($alertType, $details = '', $invitationId = null, $ipAddress = null) {
        $detailsJson = json_encode([
            'alert_type' => $alertType,
            'description' => $details
        ]);

        return $this->log('SECURITY_ALERT', $invitationId, $detailsJson, $ipAddress);
    }

    /**
     * Get severity level for event
     */
    private function getEventSeverity($logType) {
        $criticalEvents = [
            'AUTH_LOCKOUT',
            'ADMIN_DELETE_INVITATION',
            'ADMIN_USER_DELETED',
            'ENCRYPTION_KEY_ROTATED',
            'SECURITY_ALERT'
        ];

        if (in_array($logType, $criticalEvents)) {
            return 'CRITICAL';
        }

        $highPriority = [
            'ADMIN_LOGIN',
            'ADMIN_LOGOUT',
            'ADMIN_CREATE_INVITATION',
            'ADMIN_EXPORT_DATA',
            'DATA_ENCRYPTED',
            'CONFIG_CHANGED'
        ];

        return in_array($logType, $highPriority) ? 'HIGH' : 'INFO';
    }

    /**
     * Ensure audit_logs table exists
     */
    private function ensureTableExists() {
        $checkTable = $this->mysqli->query("
            SELECT 1 FROM information_schema.tables 
            WHERE table_schema = 'public' 
            AND TABLE_NAME = 'audit_logs' LIMIT 1
        ");

        if ($checkTable && $checkTable->num_rows > 0) {
            return; // Table exists
        }

        // Create audit_logs table
        $createTable = "
            CREATE TABLE IF NOT EXISTS audit_logs (
                id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                timestamp TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                log_type VARCHAR(50) NOT NULL,
                invitation_id VARCHAR(50),
                admin_id BIGINT,
                details JSONB,
                ip_address VARCHAR(45),
                user_agent TEXT,
                severity VARCHAR(20) DEFAULT 'INFO',
                integrity_hash VARCHAR(64)
            );
        ";

        if (!$this->mysqli->query($createTable)) {
            throw new Exception("Failed to create audit_logs table: " . $this->mysqli->error);
        }
        $this->mysqli->query("CREATE INDEX IF NOT EXISTS idx_audit_logs_timestamp ON audit_logs(timestamp)");
        $this->mysqli->query("CREATE INDEX IF NOT EXISTS idx_audit_logs_log_type ON audit_logs(log_type)");
        $this->mysqli->query("CREATE INDEX IF NOT EXISTS idx_audit_logs_invitation ON audit_logs(invitation_id)");
        $this->mysqli->query("CREATE INDEX IF NOT EXISTS idx_audit_logs_severity ON audit_logs(severity)");
        $this->mysqli->query("CREATE INDEX IF NOT EXISTS idx_audit_logs_ip ON audit_logs(ip_address)");
    }

    /**
     * Get client IP address
     */
    private static function getClientIp() {
        $client_ip = '';
        
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $client_ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $client_ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $client_ip = $_SERVER['REMOTE_ADDR'];
        }

        // Validate IP address
        if (!filter_var($client_ip, FILTER_VALIDATE_IP)) {
            $client_ip = 'UNKNOWN';
        }

        return $client_ip;
    }

    /**
     * Query audit logs
     * 
     * @param string $logType Optional filter by log type
     * @param int $limit Number of records to return
     * @return array Audit logs
     */
    public function query($logType = null, $limit = 100) {
        if (!$this->enabled) {
            return [];
        }

        try {
            if ($logType) {
                $stmt = $this->mysqli->prepare("
                    SELECT * FROM audit_logs 
                    WHERE log_type = ? 
                    ORDER BY timestamp DESC 
                    LIMIT ?
                ");
                $stmt->bind_param('si', $logType, $limit);
            } else {
                $stmt = $this->mysqli->prepare("
                    SELECT * FROM audit_logs 
                    ORDER BY timestamp DESC 
                    LIMIT ?
                ");
                $stmt->bind_param('i', $limit);
            }

            $stmt->execute();
            $result = $stmt->get_result();
            $logs = [];

            while ($row = $result->fetch_assoc()) {
                $logs[] = $row;
            }

            $stmt->close();
            return $logs;

        } catch (Exception $e) {
            error_log('AuditLogger query error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Check integrity of audit log entry
     * 
     * @param array $logEntry
     * @return bool
     */
    public function verifyIntegrity($logEntry) {
        $hmacData = $logEntry['log_type'] . '|' . ($logEntry['invitation_id'] ?? '') . '|' . 
                   $logEntry['timestamp'] . '|' . $logEntry['ip_address'];
        $expectedHmac = hash_hmac('sha256', $hmacData, getenv('ENCRYPTION_KEY') ?? 'default');
        
        return hash_equals($expectedHmac, $logEntry['integrity_hash'] ?? '');
    }

    /**
     * Get security summary
     */
    public function getSecuritySummary($hours = 24) {
        if (!$this->enabled) {
            return [];
        }

        try {
            $result = $this->mysqli->query("
                SELECT 
                    log_type,
                    severity,
                    COUNT(*) as count,
                    COUNT(DISTINCT ip_address) as unique_ips
                FROM audit_logs
                WHERE timestamp >= DATE_SUB(NOW(), INTERVAL $hours HOUR)
                GROUP BY log_type, severity
                ORDER BY severity DESC, count DESC
            ");

            $summary = [];
            while ($row = $result->fetch_assoc()) {
                $summary[] = $row;
            }

            return $summary;

        } catch (Exception $e) {
            error_log('AuditLogger summary error: ' . $e->getMessage());
            return [];
        }
    }
}

?>
