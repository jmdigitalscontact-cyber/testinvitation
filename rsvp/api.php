<?php
// API Router - handles all API endpoints
require_once 'config.php';
require_once 'Database.php';
require_once 'Authentication.php';
require_once 'RSVPHandler.php';
require_once 'QRCodeGenerator.php';
require_once 'GoogleSheetsExporter.php';
require_once 'ReceptionApi.php';

// Get request method and action
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}
$action = $_GET['action'] ?? $_POST['action'] ?? $input['action'] ?? '';

// Response helper
function sendResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit();
}

function getRequestInput() {
    global $input;
    return is_array($input) ? $input : [];
}

function encodeInvitedGuestNamesForNotes($names) {
    if (!is_array($names) || empty($names)) return '';
    return 'INVITED_GUEST_NAMES:' . json_encode(array_values($names));
}

function decodeInvitedGuestNamesFromNotes($notes) {
    $notes = (string)$notes;
    if (strpos($notes, 'INVITED_GUEST_NAMES:') !== 0) {
        return [];
    }
    $json = substr($notes, strlen('INVITED_GUEST_NAMES:'));
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

function getBearerToken() {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

    // Some servers (Apache, CGI) do not expose Authorization -> fallback:
    if (empty($authHeader) && function_exists('apache_request_headers')) {
        $reqHeaders = apache_request_headers();
        if (!empty($reqHeaders['Authorization'])) {
            $authHeader = $reqHeaders['Authorization'];
        } elseif (!empty($reqHeaders['authorization'])) {
            $authHeader = $reqHeaders['authorization'];
        }
    }

    if (empty($authHeader)) {
        return '';
    }

    if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        return trim($matches[1]);
    }

    return '';
}

function requireAdminAuth() {
    $token = getBearerToken();
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if (empty($token) || empty($csrfToken) || !hash_equals($token, $csrfToken)) {
        error_log("[requireAdminAuth] Unauthorized. token='" . substr($token, 0, 8) . "...' csrf='" . substr($csrfToken, 0, 8) . "...'");
        sendResponse(['success' => false, 'error' => 'Unauthorized (CSRF token missing or mismatch)'], 401);
    }

    $auth = new Authentication();
    $admin = $auth->validateAdminToken($token);

    if (!$admin) {
        sendResponse(['success' => false, 'error' => 'Invalid or expired admin token'], 401);
    }

    return $admin;
}

function isLocalRequest() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return in_array($ip, ['127.0.0.1', '::1'], true);
}

function isTruthyEnvValue($value) {
    $normalized = strtolower(trim((string)$value));
    return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
}

function ensureInvitedGuestNamesColumn($mysqli) {
    $result = $mysqli->query("SHOW COLUMNS FROM invitations LIKE 'invited_guest_names'");
    if ($result && $result->num_rows > 0) {
        return true;
    }

    $mysqli->query("ALTER TABLE invitations ADD COLUMN IF NOT EXISTS invited_guest_names TEXT");

    $check = $mysqli->query("SHOW COLUMNS FROM invitations LIKE 'invited_guest_names'");
    return ($check && $check->num_rows > 0);
}

try {
    switch ($action) {
        // ==================== GUEST ENDPOINTS ====================
        
        case 'verify-invitation':
            handleVerifyInvitation();
            break;

        case 'verify-invitation-qr':
            handleVerifyInvitationQR();
            break;

        case 'get-invitation-details':
            handleGetInvitationDetails();
            break;

        case 'submit-rsvp':
            handleSubmitRSVP();
            break;

        case 'get-rsvp-status':
            handleGetRSVPStatus();
            break;

        case 'check-rsvp-submitted':
            handleCheckRSVPSubmitted();
            break;

        // ==================== ADMIN ENDPOINTS ====================

        case 'admin-login':
            handleAdminLogin();
            break;

        case 'create-invitation':
            handleCreateInvitation();
            break;

        case 'update-invitation':
            handleUpdateInvitation();
            break;

        case 'delete-invitation':
            handleDeleteInvitation();
            break;

        case 'generate-qr':
            handleGenerateQR();
            break;

        case 'get-invitations':
            handleGetInvitations();
            break;

        case 'get-rsvp-summary':
            handleGetRSVPSummary();
            break;

        case 'export-rsvp':
            handleExportRSVP();
            break;

        case 'export-invitations':
            handleExportInvitations();
            break;

        case 'export-responses':
            handleExportResponses();
            break;

        case 'export-to-google-sheets-invitations':
            handleExportToGoogleSheetsInvitations();
            break;

        case 'export-to-google-sheets-responses':
            handleExportToGoogleSheetsResponses();
            break;

        case 'get-table-assignments':
            handleGetTableAssignments();
            break;

        case 'assign-table':
            handleAssignTable();
            break;

        // ==================== RECEPTION VENUE (PUBLIC) ====================

        case 'get-reception-guests':
            handleGetReceptionGuests();
            break;

        case 'get-reception-photos':
            handleGetReceptionPhotos();
            break;

        case 'upload-reception-photo':
            handleUploadReceptionPhoto();
            break;

        default:
            sendResponse(['error' => 'Invalid action'], 400);
    }
} catch (Exception $e) {
    sendResponse(['error' => $e->getMessage()], 500);
}

// ==================== GUEST FUNCTIONS ====================

function handleVerifyInvitation() {
    $input = getRequestInput();
    $invitation_id = sanitize($input['invitation_id'] ?? '');
    $password = sanitize($input['password'] ?? '');

    if (empty($invitation_id) || empty($password)) {
        sendResponse(['success' => false, 'error' => 'Missing required fields'], 400);
    }

    // Optional local development guest bypass: disabled by default.
    $guestBypassEnabled = isTruthyEnvValue(EnvironmentLoader::get('ENABLE_DEV_GUEST_BYPASS', 'false'));
    $devInvitationId = EnvironmentLoader::get('DEV_GUEST_INVITATION_ID', 'dev');
    $devPassword = EnvironmentLoader::get('DEV_GUEST_PASSWORD', 'dev');
    $devGuestName = EnvironmentLoader::get('DEV_GUEST_NAME', 'Local Dev Guest');
    $devMaxGuests = (int)EnvironmentLoader::get('DEV_GUEST_MAX_GUESTS', 2);
    if (
        $guestBypassEnabled &&
        isLocalRequest() &&
        hash_equals((string)$devInvitationId, (string)$invitation_id) &&
        hash_equals((string)$devPassword, (string)$password)
    ) {
        sendResponse([
            'success' => true,
            'message' => 'Authentication successful (dev guest bypass)',
            'data' => [
                'token' => 'dev-guest-bypass-token',
                'guest_name' => $devGuestName,
                'max_guests' => $devMaxGuests,
                'invitation_id' => $devInvitationId
            ]
        ]);
    }

    sendResponse([
        'success' => false,
        'error' => 'RSVP login by password is disabled. Please use your invitation QR code.'
    ], 403);
}

function handleVerifyInvitationQR() {
    $input = getRequestInput();
    $invitation_id = sanitize($input['invitation_id'] ?? $_GET['invitation_id'] ?? '');

    if (empty($invitation_id)) {
        sendResponse(['success' => false, 'error' => 'Missing invitation ID'], 400);
    }

    $auth = new Authentication();

    if (!$auth->checkQrRateLimit()) {
        sendResponse(['success' => false, 'error' => 'Too many attempts. Please try again later.'], 429);
    }

    $result = $auth->verifyInvitationByQRCode($invitation_id);

    if ($result === false) {
        sendResponse(['success' => false, 'error' => 'Invalid QR invitation'], 401);
    }

    if (is_array($result) && !empty($result['rate_limited'])) {
        sendResponse(['success' => false, 'error' => 'Too many attempts. Please try again later.'], 429);
    }

    sendResponse([
        'success' => true,
        'message' => 'QR authentication successful',
        'data' => [
            'token' => $result['token'],
            'guest_name' => $result['guest_name'],
            'max_guests' => $result['max_guests'],
            'invitation_id' => $result['invitation_id'],
            'invited_guest_names' => $result['invited_guest_names'] ?? []
        ]
    ]);
}

function handleGetInvitationDetails() {
    $token = sanitize($_GET['token'] ?? '');
    
    if (empty($token)) {
        sendResponse(['success' => false, 'error' => 'Missing token'], 400);
    }

    $auth = new Authentication();
    $invitation = $auth->validateToken($token);

    if (!$invitation) {
        sendResponse(['success' => false, 'error' => 'Invalid or expired token'], 401);
    }

    sendResponse([
        'success' => true,
        'data' => $invitation
    ]);
}

function handleSubmitRSVP() {
    $input = getRequestInput();
    $token = sanitize($input['token'] ?? '');
    $attending = sanitize($input['attending'] ?? '');
    $attendee_count = (int)($input['attendee_count'] ?? 0);
    $attendees = $input['attendees'] ?? [];
    $dietary_restrictions = sanitize($input['dietary_restrictions'] ?? '');
    $special_notes = sanitize($input['special_notes'] ?? '');

    if (empty($token) || empty($attending)) {
        sendResponse(['success' => false, 'error' => 'Missing required fields'], 400);
    }

    if (!in_array($attending, ['yes', 'no', 'maybe'])) {
        sendResponse(['success' => false, 'error' => 'Invalid attendance value'], 400);
    }

    $auth = new Authentication();
    $invitation = $auth->validateToken($token);

    if (!$invitation) {
        sendResponse(['success' => false, 'error' => 'Invalid or expired token'], 401);
    }

    $rsvp = new RSVPHandler();
    $result = $rsvp->submitRSVP(
        $invitation['invitation_id'],
        $attending,
        $attendee_count,
        $attendees,
        $dietary_restrictions,
        $special_notes
    );

    if ($result['success']) {
        sendResponse($result);
    } else {
        sendResponse($result, 400);
    }
}

function handleGetRSVPStatus() {
    $token = sanitize($_GET['token'] ?? '');

    if (empty($token)) {
        sendResponse(['success' => false, 'error' => 'Missing token'], 400);
    }

    $auth = new Authentication();
    $invitation = $auth->validateToken($token);

    if (!$invitation) {
        sendResponse(['success' => false, 'error' => 'Invalid or expired token'], 401);
    }

    $rsvp = new RSVPHandler();
    $rsvp_response = $rsvp->getRSVPResponse($invitation['invitation_id']);

    sendResponse([
        'success' => true,
        'data' => $rsvp_response ?? null
    ]);
}

function handleCheckRSVPSubmitted() {
    $invitation_id = sanitize($_GET['invitation_id'] ?? '');

    if (empty($invitation_id)) {
        sendResponse(['success' => false, 'error' => 'Missing invitation_id'], 400);
    }

    $rsvp = new RSVPHandler();
    $rsvp_response = $rsvp->getRSVPResponse($invitation_id);

    sendResponse([
        'success' => true,
        'submitted' => !empty($rsvp_response),
        'data' => $rsvp_response ?? null
    ]);
}

// ==================== ADMIN FUNCTIONS ====================

function handleAdminLogin() {
    $input = getRequestInput();
    $username = sanitize($input['username'] ?? '');
    $password = sanitize($input['password'] ?? '');

    if (empty($username) || empty($password)) {
        sendResponse(['success' => false, 'error' => 'Missing credentials'], 400);
    }

    // Optional local development bypass: disabled by default.
    $devBypassEnabled = isTruthyEnvValue(EnvironmentLoader::get('ENABLE_DEV_ADMIN_BYPASS', 'false'));
    $devUsername = EnvironmentLoader::get('DEV_ADMIN_USERNAME', 'dev');
    $devPassword = EnvironmentLoader::get('DEV_ADMIN_PASSWORD', 'dev');
    if (
        $devBypassEnabled &&
        isLocalRequest() &&
        hash_equals((string)$devUsername, (string)$username) &&
        hash_equals((string)$devPassword, (string)$password)
    ) {
        sendResponse([
            'success' => true,
            'token' => 'dev-local-bypass-token',
            'csrf_token' => 'dev-local-bypass-token',
            'username' => $devUsername
        ]);
    }

    $auth = new Authentication();
    $admin = $auth->adminLogin($username, $password);

    if (!$admin) {
        sendResponse(['success' => false, 'error' => 'Invalid credentials'], 401);
    }

    sendResponse([
        'success' => true,
        'token' => $admin['token'],
        'csrf_token' => $admin['token'],
        'username' => $admin['username']
    ]);
}

function handleCreateInvitation() {
    requireAdminAuth();
    $input = getRequestInput();
    $guest_name = sanitize($input['guest_name'] ?? '');
    $max_guests = (int)($input['max_guests'] ?? 1);
    $password = sanitize($input['password'] ?? '');
    $email = sanitize($input['email'] ?? '');
    $rawInvitedGuestNames = $input['invited_guest_names'] ?? [];

    if (empty($guest_name) || empty($password)) {
        sendResponse(['success' => false, 'error' => 'Missing required fields'], 400);
    }

    // TODO: Validate admin authentication token

    // Generate unique invitation ID
    $invitation_id = 'INV-' . strtoupper(substr(md5(time() . $guest_name), 0, 12));
    $password_hash = password_hash($password, PASSWORD_BCRYPT);

    $db = Database::getInstance();
    $mysqli = $db->getConnection();
    $hasInvitedGuestNamesColumn = ensureInvitedGuestNamesColumn($mysqli);

    $invitedGuestNames = [];
    if (is_array($rawInvitedGuestNames)) {
        foreach ($rawInvitedGuestNames as $name) {
            $cleanName = sanitize((string)$name);
            if ($cleanName !== '') {
                $invitedGuestNames[] = $cleanName;
            }
        }
    }

    if (!empty($invitedGuestNames) && count($invitedGuestNames) > $max_guests) {
        sendResponse([
            'success' => false,
            'error' => 'Invited guest names cannot exceed max guests'
        ], 400);
    }

    $invitedGuestNamesJson = !empty($invitedGuestNames) ? json_encode(array_values($invitedGuestNames)) : null;

    if ($hasInvitedGuestNamesColumn) {
        $stmt = $mysqli->prepare("
            INSERT INTO invitations (invitation_id, guest_name, password_hash, max_guests, email, invited_guest_names) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
    } else {
        $notes = encodeInvitedGuestNamesForNotes($invitedGuestNames);
        $stmt = $mysqli->prepare("
            INSERT INTO invitations (invitation_id, guest_name, password_hash, max_guests, email, notes) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
    }

    if (!$stmt) {
        sendResponse(['success' => false, 'error' => 'Database error'], 500);
    }

    if ($hasInvitedGuestNamesColumn) {
        $stmt->bind_param("sssiss", $invitation_id, $guest_name, $password_hash, $max_guests, $email, $invitedGuestNamesJson);
    } else {
        $stmt->bind_param("sssiss", $invitation_id, $guest_name, $password_hash, $max_guests, $email, $notes);
    }

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        sendResponse(['success' => false, 'error' => 'Failed to create invitation: ' . $error], 500);
    }
    $stmt->close();

    // Generate QR code
    $qr_gen = new QRCodeGenerator();
    $qr_result = $qr_gen->generateQRCode($invitation_id, $guest_name);

    sendResponse([
        'success' => true,
        'message' => 'Invitation created successfully',
        'data' => [
            'invitation_id' => $invitation_id,
            'guest_name' => $guest_name,
            'max_guests' => $max_guests,
            'invited_guest_names' => $invitedGuestNames,
            'qr_code' => $qr_result
        ]
    ]);
}

function handleGenerateQR() {
    requireAdminAuth();
    $invitation_id = sanitize($_GET['invitation_id'] ?? '');

    if (empty($invitation_id)) {
        sendResponse(['success' => false, 'error' => 'Missing invitation ID'], 400);
    }

    $qr_gen = new QRCodeGenerator();
    $qr = $qr_gen->getQRCode($invitation_id);

    // Regenerate if QR points at old XAMPP path, wrong port, or legacy RSVP URL.
    if ($qr && QRCodeGenerator::qrCodeNeedsRegeneration($qr)) {
        $db = Database::getInstance();
        $mysqli = $db->getConnection();
        $stmt = $mysqli->prepare("SELECT guest_name FROM invitations WHERE invitation_id = ?");
        if ($stmt) {
            $stmt->bind_param("s", $invitation_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $invitation = $result->fetch_assoc();
            $stmt->close();

            if ($invitation) {
                $regenerated = $qr_gen->generateQRCode($invitation_id, $invitation['guest_name']);
                if ($regenerated && !empty($regenerated['qr_image_path'])) {
                    sendResponse([
                        'success' => true,
                        'data' => [
                            'invitation_id' => $invitation_id,
                            'qr_image_path' => $regenerated['qr_image_path'],
                            'qr_code_data' => $regenerated['qr_url'] ?? ''
                        ]
                    ]);
                }
            }
        }
    }

    if (!$qr) {
        // QR missing: regenerate from invitation record
        $db = Database::getInstance();
        $mysqli = $db->getConnection();

        $stmt = $mysqli->prepare("SELECT guest_name FROM invitations WHERE invitation_id = ?");
        if (!$stmt) {
            sendResponse(['success' => false, 'error' => 'Database error while loading invitation'], 500);
        }

        $stmt->bind_param("s", $invitation_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $invitation = $result->fetch_assoc();
        $stmt->close();

        if (!$invitation) {
            sendResponse(['success' => false, 'error' => 'Invitation not found'], 404);
        }

        $generated = $qr_gen->generateQRCode($invitation_id, $invitation['guest_name']);
        if (!$generated || empty($generated['qr_image_path'])) {
            sendResponse([
                'success' => false,
                'error' => 'QR code could not be generated right now. Please check server write permissions for rsvp/qr_codes.'
            ], 500);
        }

        sendResponse([
            'success' => true,
            'data' => [
                'invitation_id' => $invitation_id,
                'qr_image_path' => $generated['qr_image_path'],
                'qr_code_data' => $generated['qr_url'] ?? ''
            ]
        ]);
    }

    sendResponse([
        'success' => true,
        'data' => $qr
    ]);
}

function handleUpdateInvitation() {
    requireAdminAuth();
    $input = getRequestInput();
    $invitation_id = sanitize($input['invitation_id'] ?? '');
    $guest_name = sanitize($input['guest_name'] ?? '');
    $max_guests = (int)($input['max_guests'] ?? 1);
    $email = sanitize($input['email'] ?? '');
    $password = sanitize($input['password'] ?? '');
    $rawInvitedGuestNames = $input['invited_guest_names'] ?? [];

    if (empty($invitation_id) || empty($guest_name) || $max_guests < 1) {
        sendResponse(['success' => false, 'error' => 'Missing or invalid required fields'], 400);
    }

    $db = Database::getInstance();
    $mysqli = $db->getConnection();
    $hasInvitedGuestNamesColumn = ensureInvitedGuestNamesColumn($mysqli);

    $invitedGuestNames = [];
    if (is_array($rawInvitedGuestNames)) {
        foreach ($rawInvitedGuestNames as $name) {
            $cleanName = sanitize((string)$name);
            if ($cleanName !== '') {
                $invitedGuestNames[] = $cleanName;
            }
        }
    }

    if (!empty($invitedGuestNames) && count($invitedGuestNames) > $max_guests) {
        sendResponse([
            'success' => false,
            'error' => 'Invited guest names cannot exceed max guests'
        ], 400);
    }

    $invitedGuestNamesJson = !empty($invitedGuestNames) ? json_encode(array_values($invitedGuestNames)) : null;

    if ($hasInvitedGuestNamesColumn && !empty($password)) {
        $password_hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $mysqli->prepare("
            UPDATE invitations
            SET guest_name = ?, max_guests = ?, email = ?, invited_guest_names = ?, password_hash = ?
            WHERE invitation_id = ?
        ");
        if (!$stmt) {
            sendResponse(['success' => false, 'error' => 'Database error'], 500);
        }
        $stmt->bind_param("sissss", $guest_name, $max_guests, $email, $invitedGuestNamesJson, $password_hash, $invitation_id);
    } elseif ($hasInvitedGuestNamesColumn) {
        $stmt = $mysqli->prepare("
            UPDATE invitations
            SET guest_name = ?, max_guests = ?, email = ?, invited_guest_names = ?
            WHERE invitation_id = ?
        ");
        if (!$stmt) {
            sendResponse(['success' => false, 'error' => 'Database error'], 500);
        }
        $stmt->bind_param("sisss", $guest_name, $max_guests, $email, $invitedGuestNamesJson, $invitation_id);
    } elseif (!empty($password)) {
        $notes = encodeInvitedGuestNamesForNotes($invitedGuestNames);
        $password_hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $mysqli->prepare("
            UPDATE invitations
            SET guest_name = ?, max_guests = ?, email = ?, notes = ?, password_hash = ?
            WHERE invitation_id = ?
        ");
        if (!$stmt) {
            sendResponse(['success' => false, 'error' => 'Database error'], 500);
        }
        $stmt->bind_param("sissss", $guest_name, $max_guests, $email, $notes, $password_hash, $invitation_id);
    } else {
        $notes = encodeInvitedGuestNamesForNotes($invitedGuestNames);
        $stmt = $mysqli->prepare("
            UPDATE invitations
            SET guest_name = ?, max_guests = ?, email = ?, notes = ?
            WHERE invitation_id = ?
        ");
        if (!$stmt) {
            sendResponse(['success' => false, 'error' => 'Database error'], 500);
        }
        $stmt->bind_param("sisss", $guest_name, $max_guests, $email, $notes, $invitation_id);
    }

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        sendResponse(['success' => false, 'error' => 'Failed to update invitation: ' . $error], 500);
    }
    if ($stmt->affected_rows < 0) {
        $stmt->close();
        sendResponse(['success' => false, 'error' => 'Failed to update invitation'], 500);
    }
    $stmt->close();

    sendResponse([
        'success' => true,
        'message' => 'Invitation updated successfully'
    ]);
}

function handleDeleteInvitation() {
    requireAdminAuth();
    $input = getRequestInput();
    $invitation_id = sanitize($input['invitation_id'] ?? '');

    if (empty($invitation_id)) {
        sendResponse(['success' => false, 'error' => 'Missing invitation ID'], 400);
    }

    $db = Database::getInstance();
    $mysqli = $db->getConnection();
    $stmt = $mysqli->prepare("DELETE FROM invitations WHERE invitation_id = ?");
    if (!$stmt) {
        sendResponse(['success' => false, 'error' => 'Database error'], 500);
    }
    $stmt->bind_param("s", $invitation_id);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        sendResponse(['success' => false, 'error' => 'Failed to delete invitation: ' . $error], 500);
    }
    $deleted = $stmt->affected_rows;
    $stmt->close();

    if ($deleted < 1) {
        sendResponse(['success' => false, 'error' => 'Invitation not found'], 404);
    }

    sendResponse([
        'success' => true,
        'message' => 'Invitation deleted successfully'
    ]);
}

function handleGetInvitations() {
    requireAdminAuth();

    $db = Database::getInstance();
    $mysqli = $db->getConnection();
    $hasInvitedGuestNamesColumn = ensureInvitedGuestNamesColumn($mysqli);

    $result = $mysqli->query("
        SELECT i.*, 
               COALESCE(r.attending, 'pending') as rsvp_status,
               COALESCE(r.attendee_count, 0) as confirmed_count
        FROM invitations i
        LEFT JOIN rsvp_responses r ON i.invitation_id = r.invitation_id
        ORDER BY i.created_at DESC
    ");

    $invitations = [];
    while ($row = $result->fetch_assoc()) {
        $row['invited_guest_names'] = ($hasInvitedGuestNamesColumn && !empty($row['invited_guest_names']))
            ? (json_decode($row['invited_guest_names'], true) ?: [])
            : decodeInvitedGuestNamesFromNotes($row['notes'] ?? '');
        $invitations[] = $row;
    }

    sendResponse([
        'success' => true,
        'data' => $invitations
    ]);
}

function handleGetRSVPSummary() {
    requireAdminAuth();

    $db = Database::getInstance();
    $mysqli = $db->getConnection();

    $result = $mysqli->query("
        SELECT i.guest_name, i.max_guests, i.invitation_id,
               r.attending, r.attendee_count, r.attendees, r.special_notes,
               r.submitted_at
        FROM invitations i
        LEFT JOIN rsvp_responses r ON i.invitation_id = r.invitation_id
        WHERE r.attending IS NOT NULL
        ORDER BY i.guest_name
    ");

    $data = [];
    while ($row = $result->fetch_assoc()) {
        $row['attendees'] = !empty($row['attendees']) ? json_decode($row['attendees'], true) : [];
        $data[] = $row;
    }

    sendResponse([
        'success' => true,
        'data' => $data
    ]);
}

function decodeAttendeesField($raw) {
    if (empty($raw)) {
        return [];
    }
    if (is_array($raw)) {
        return $raw;
    }
    $decoded = json_decode((string)$raw, true);
    return is_array($decoded) ? $decoded : [];
}

function extractGuestNamesFromExportRow($row) {
    $names = [];
    $attendees = decodeAttendeesField($row['attendees'] ?? null);
    foreach ($attendees as $att) {
        if (!is_array($att)) {
            continue;
        }
        $name = trim((string)($att['attendee_name'] ?? $att['name'] ?? ''));
        if ($name !== '') {
            $names[] = $name;
        }
    }

    if (empty($names) && !empty($row['special_notes'])) {
        $notes = (string)$row['special_notes'];
        if (strpos($notes, 'INVITED_GUEST_NAMES:') === 0) {
            return '';
        }
        $lines = preg_split('/\r\n|\r|\n|,/', $notes);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $names[] = $line;
            }
        }
    }

    return implode('; ', $names);
}

function flattenInvitationExportRow($row) {
    return [
        'invitation_id' => $row['invitation_id'] ?? '',
        'guest_name' => html_entity_decode((string)($row['guest_name'] ?? ''), ENT_QUOTES, 'UTF-8'),
        'email' => $row['email'] ?? '',
        'max_guests' => $row['max_guests'] ?? '',
        'status' => $row['status'] ?? '',
        'created_at' => $row['created_at'] ?? '',
    ];
}

function flattenRsvpExportRow($row) {
    return [
        'invitation_id' => $row['invitation_id'] ?? '',
        'guest_name' => html_entity_decode((string)($row['guest_name'] ?? ''), ENT_QUOTES, 'UTF-8'),
        'max_guests' => $row['max_guests'] ?? '',
        'attending' => $row['attending'] ?? '',
        'attendee_count' => $row['attendee_count'] ?? 0,
        'guest_names' => extractGuestNamesFromExportRow($row),
        'dietary_restrictions' => $row['dietary_restrictions'] ?? '',
        'special_notes' => $row['special_notes'] ?? '',
        'submitted_at' => $row['submitted_at'] ?? '',
    ];
}

function getGoogleSheetsExporterOrError() {
    $sheetId = GOOGLE_SHEETS_ID;
    $candidates = array_values(array_filter([
        GOOGLE_SHEETS_CREDENTIALS_PATH,
        dirname(__DIR__) . '/JSON/wedding-rsvp-490917-330ce3b24634.json',
        dirname(__DIR__) . '/credentials/google-sheets.json',
        BASE_PATH . '/credentials/google-sheets.json',
    ]));

    $resolvedPath = null;
    foreach ($candidates as $path) {
        if ($path && file_exists($path)) {
            $resolvedPath = realpath($path);
            break;
        }
    }

    if (!$resolvedPath) {
        return [
            'error' => 'Google Sheets credentials not found. Add GOOGLE_SHEETS_CREDENTIALS_PATH to .env pointing to your service account JSON file.'
        ];
    }

    try {
        return ['exporter' => new GoogleSheetsExporter($sheetId, $resolvedPath)];
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

function handleExportInvitations() {
    requireAdminAuth();

    $db = Database::getInstance();
    $mysqli = $db->getConnection();
    $result = $mysqli->query("
        SELECT invitation_id, guest_name, email, max_guests, status, created_at
        FROM invitations
        ORDER BY created_at DESC
    ");

    if (!$result) {
        sendResponse(['success' => false, 'error' => 'Export query failed'], 500);
    }

    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = flattenInvitationExportRow($row);
    }

    sendResponse(['success' => true, 'data' => $data]);
}

function handleExportResponses() {
    requireAdminAuth();

    $db = Database::getInstance();
    $mysqli = $db->getConnection();
    $result = $mysqli->query("
        SELECT i.invitation_id, i.guest_name, i.max_guests,
               r.attending, r.attendee_count, r.attendees, r.dietary_restrictions,
               r.special_notes, r.submitted_at
        FROM invitations i
        INNER JOIN rsvp_responses r ON i.invitation_id = r.invitation_id
        WHERE r.attending IS NOT NULL
        ORDER BY i.guest_name
    ");

    if (!$result) {
        sendResponse(['success' => false, 'error' => 'Export query failed'], 500);
    }

    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = flattenRsvpExportRow($row);
    }

    sendResponse(['success' => true, 'data' => $data]);
}

function handleExportRSVP() {
    requireAdminAuth();

    $db = Database::getInstance();
    $mysqli = $db->getConnection();

    $result = $mysqli->query("
        SELECT i.invitation_id, i.guest_name, i.email, i.max_guests, i.status,
               r.attending, r.attendee_count, r.attendees, r.dietary_restrictions,
               r.special_notes, r.submitted_at
        FROM invitations i
        LEFT JOIN rsvp_responses r ON i.invitation_id = r.invitation_id
        ORDER BY i.guest_name
    ");

    if (!$result) {
        sendResponse(['success' => false, 'error' => 'Export query failed'], 500);
    }

    $data = [];
    while ($row = $result->fetch_assoc()) {
        $flat = flattenInvitationExportRow($row);
        $flat['attending'] = $row['attending'] ?? '';
        $flat['attendee_count'] = $row['attendee_count'] ?? 0;
        $flat['guest_names'] = extractGuestNamesFromExportRow($row);
        $flat['dietary_restrictions'] = $row['dietary_restrictions'] ?? '';
        $flat['special_notes'] = $row['special_notes'] ?? '';
        $flat['submitted_at'] = $row['submitted_at'] ?? '';
        $data[] = $flat;
    }

    sendResponse([
        'success' => true,
        'data' => $data
    ]);
}

function handleExportToGoogleSheetsInvitations() {
    requireAdminAuth();

    $sheets = getGoogleSheetsExporterOrError();
    if (!empty($sheets['error'])) {
        sendResponse(['success' => false, 'error' => $sheets['error']], 503);
    }

    try {
        $db = Database::getInstance();
        $mysqli = $db->getConnection();

        $result = $mysqli->query("
            SELECT invitation_id, guest_name, max_guests, status, created_at
            FROM invitations
            ORDER BY created_at DESC
        ");

        if (!$result) {
            sendResponse(['success' => false, 'error' => 'Failed to load invitations'], 500);
        }

        $invitations = [];
        while ($row = $result->fetch_assoc()) {
            $invitations[] = $row;
        }

        $exportResult = $sheets['exporter']->exportInvitations($invitations);
        sendResponse($exportResult, !empty($exportResult['success']) ? 200 : 500);
    } catch (Exception $e) {
        sendResponse([
            'success' => false,
            'error' => 'Error: ' . $e->getMessage()
        ], 500);
    }
}

function handleExportToGoogleSheetsResponses() {
    requireAdminAuth();

    $sheets = getGoogleSheetsExporterOrError();
    if (!empty($sheets['error'])) {
        sendResponse(['success' => false, 'error' => $sheets['error']], 503);
    }

    try {
        $db = Database::getInstance();
        $mysqli = $db->getConnection();

        $result = $mysqli->query("
            SELECT i.guest_name, i.max_guests, i.invitation_id,
                   r.attending, r.attendee_count, r.attendees, r.special_notes,
                   r.submitted_at
            FROM invitations i
            INNER JOIN rsvp_responses r ON i.invitation_id = r.invitation_id
            WHERE r.attending IS NOT NULL
            ORDER BY i.guest_name
        ");

        if (!$result) {
            sendResponse(['success' => false, 'error' => 'Failed to load responses'], 500);
        }

        $responses = [];
        while ($row = $result->fetch_assoc()) {
            $row['guest_names'] = extractGuestNamesFromExportRow($row);
            $responses[] = $row;
        }

        $exportResult = $sheets['exporter']->exportResponses($responses);
        sendResponse($exportResult, !empty($exportResult['success']) ? 200 : 500);
    } catch (Exception $e) {
        sendResponse([
            'success' => false,
            'error' => 'Error: ' . $e->getMessage()
        ], 500);
    }
}

// ==================== TABLE MANAGEMENT FUNCTIONS ====================

function handleGetTableAssignments() {
    requireAdminAuth();
    try {
        $db = Database::getInstance();
        $mysqli = $db->getConnection();

        // Check if table exists, create if not
        $result = $mysqli->query("SHOW TABLES LIKE 'table_assignments'");
        if ($result->num_rows === 0) {
            // Table doesn't exist, return empty array
            sendResponse(['success' => true, 'data' => []]);
            return;
        }

        $query = "
            SELECT ta.*, i.guest_name, r.attendees AS attendees, r.special_notes AS special_notes
            FROM table_assignments ta
            JOIN invitations i ON ta.invitation_id = i.invitation_id
            LEFT JOIN rsvp_responses r ON ta.invitation_id = r.invitation_id
            ORDER BY ta.table_number, i.guest_name
        ";

        $result = $mysqli->query($query);
        if (!$result) {
            throw new Exception('Database query failed: ' . $mysqli->error);
        }

        $assignments = [];
        while ($row = $result->fetch_assoc()) {
            $row['attendees'] = !empty($row['attendees']) ? json_decode($row['attendees'], true) : [];

            if (empty($row['attendees']) && !empty($row['special_notes'])) {
                $nameLines = preg_split('/\r\n|\r|\n|,/', trim($row['special_notes']));
                $nameLines = array_filter(array_map('trim', $nameLines));
                foreach ($nameLines as $name) {
                    $row['attendees'][] = ['attendee_name' => $name];
                }
            }

            $assignments[] = $row;
        }

        sendResponse(['success' => true, 'data' => $assignments]);
    } catch (Exception $e) {
        sendResponse(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

function handleAssignTable() {
    requireAdminAuth();
    try {
        $input = getRequestInput();
        $invitation_id = sanitize($input['invitation_id'] ?? '');
        $table_number = (int)($input['table_number'] ?? 0);

        if (empty($invitation_id) || $table_number < 1) {
            sendResponse(['success' => false, 'error' => 'Missing or invalid required fields'], 400);
        }

        $db = Database::getInstance();
        $mysqli = $db->getConnection();

        // Check if table exists, create if not
        $result = $mysqli->query("SHOW TABLES LIKE 'table_assignments'");
        if ($result->num_rows === 0) {
            // Create the table
            $createTableQuery = "
                CREATE TABLE IF NOT EXISTS table_assignments (
                    id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                    invitation_id VARCHAR(50) NOT NULL,
                    attendee_id BIGINT NULL,
                    table_number INTEGER NOT NULL,
                    seat_number INTEGER NULL,
                    assigned_by BIGINT NULL,
                    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT fk_table_assignments_invitation FOREIGN KEY (invitation_id) REFERENCES invitations(invitation_id) ON DELETE CASCADE,
                    CONSTRAINT fk_table_assignments_attendee FOREIGN KEY (attendee_id) REFERENCES attendees(id) ON DELETE CASCADE,
                    CONSTRAINT fk_table_assignments_admin FOREIGN KEY (assigned_by) REFERENCES admin_users(id) ON DELETE SET NULL,
                    CONSTRAINT unique_invitation_attendee UNIQUE (invitation_id, attendee_id)
                )
            ";

            if (!$mysqli->query($createTableQuery)) {
                throw new Exception('Failed to create table_assignments table: ' . $mysqli->error);
            }
            $mysqli->query("CREATE INDEX IF NOT EXISTS idx_table_assignments_invitation_id ON table_assignments(invitation_id)");
            $mysqli->query("CREATE INDEX IF NOT EXISTS idx_table_assignments_attendee_id ON table_assignments(attendee_id)");
            $mysqli->query("CREATE INDEX IF NOT EXISTS idx_table_assignments_table_number ON table_assignments(table_number)");
        }

        // First, delete any existing assignments for this invitation (without attendee_id)
        // This ensures when changing tables, the old assignment is removed
        $deleteStmt = $mysqli->prepare("
            DELETE FROM table_assignments
            WHERE invitation_id = ? AND attendee_id IS NULL
        ");

        if (!$deleteStmt) {
            throw new Exception('Failed to prepare delete statement: ' . $mysqli->error);
        }

        $deleteStmt->bind_param('s', $invitation_id);

        if (!$deleteStmt->execute()) {
            throw new Exception('Failed to delete old table assignment: ' . $deleteStmt->error);
        }

        // Now insert the new assignment
        $stmt = $mysqli->prepare("
            INSERT INTO table_assignments (invitation_id, table_number, assigned_by)
            VALUES (?, ?, 1)
        ");

        if (!$stmt) {
            throw new Exception('Failed to prepare statement: ' . $mysqli->error);
        }

        $stmt->bind_param('si', $invitation_id, $table_number);

        if (!$stmt->execute()) {
            throw new Exception('Failed to save table assignment: ' . $stmt->error);
        }

        sendResponse([
            'success' => true,
            'message' => 'Table assignment saved successfully'
        ]);
    } catch (Exception $e) {
        sendResponse(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

// ==================== UTILITY FUNCTIONS ====================

function sanitize($input) {
    if (is_array($input)) {
        return array_map('sanitize', $input);
    }
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

?>
