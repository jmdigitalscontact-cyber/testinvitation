<?php
// Load environment configuration from .env file
require_once __DIR__ . '/EnvironmentLoader.php';

// ──────────────────────────────────────────────────────────
// Database Configuration (MySQL/MariaDB — primary for GoDaddy)
// ──────────────────────────────────────────────────────────
define('DB_HOST', EnvironmentLoader::get('DB_HOST', 'localhost'));
define('DB_PORT', (int)EnvironmentLoader::get('DB_PORT', 3306));
define('DB_NAME', EnvironmentLoader::get('DB_NAME', 'wedding_rsvp'));
define('DB_USER', EnvironmentLoader::get('DB_USER', 'root'));
define('DB_PASS', EnvironmentLoader::get('DB_PASS', ''));

// ──────────────────────────────────────────────────────────
// PostgreSQL configuration (legacy — used for local dev only)
// ──────────────────────────────────────────────────────────
define('PG_HOST', EnvironmentLoader::get('PG_HOST', 'localhost'));
define('PG_PORT', (int)EnvironmentLoader::get('PG_PORT', 5432));
define('PG_DB', EnvironmentLoader::get('PG_DB', 'wedding_rsvp'));
define('PG_USER', EnvironmentLoader::get('PG_USER', 'postgres'));
define('PG_PASS', EnvironmentLoader::get('PG_PASS', ''));

// ──────────────────────────────────────────────────────────
// Database Engine Selection
// ──────────────────────────────────────────────────────────
// Set to 'mysql' (default) or 'pgsql'
define('DB_ENGINE', EnvironmentLoader::get('DB_ENGINE', 'mysql'));

// Public site URL for QR codes and guest links (no trailing slash)
define('PUBLIC_BASE_URL', rtrim(EnvironmentLoader::get('PUBLIC_BASE_URL', 'http://localhost:3000'), '/'));

// RSVP Settings
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_ATTEMPT_WINDOW', 900); // 15 minutes in seconds
define('LOCKOUT_DURATION', 3600); // 1 hour in seconds

// Encryption Settings
define('ENCRYPTION_ENABLED', EnvironmentLoader::has('ENCRYPTION_KEY'));
define('SENSITIVE_FIELDS_ENCRYPTED', true); // Automatically encrypt sensitive fields

// Fields that should be encrypted automatically
$ENCRYPTED_FIELDS = [
    'invitations' => ['guest_name', 'email', 'phone', 'notes'],
    'rsvp_responses' => ['dietary_restrictions', 'special_notes', 'attendees']
];

// Session and security
define('SESSION_TIMEOUT', EnvironmentLoader::get('SESSION_TIMEOUT', 3600));
define('SESSION_SECURE_COOKIE', EnvironmentLoader::get('SESSION_SECURE_COOKIE', false));
define('SESSION_HTTPONLY', EnvironmentLoader::get('SESSION_HTTPONLY', true));
define('SESSION_SAMESITE', EnvironmentLoader::get('SESSION_SAMESITE', 'Lax'));

// Wedding Invitation Capacity
define('TOTAL_INVITATION_CAPACITY', 200); // Total number of invitation slots available

// API Settings
define('API_VERSION', '1.0');
define('API_TIMEOUT', 30);

// Paths
define('BASE_PATH', dirname(__FILE__));
define('QR_CODE_PATH', BASE_PATH . '/qr_codes/');

// Google Sheets export (optional — set in .env)
define('GOOGLE_SHEETS_ID', EnvironmentLoader::get(
    'GOOGLE_SHEETS_ID',
    '1Y0447zO9KI2G7FKLTbH-qwTaghTVXTlB4ztapHr3mtY'
));
define('GOOGLE_SHEETS_CREDENTIALS_PATH', EnvironmentLoader::get('GOOGLE_SHEETS_CREDENTIALS_PATH', ''));

// Security headers
$allowed_origins_env = EnvironmentLoader::get('ALLOWED_ORIGINS', 'http://localhost,http://127.0.0.1,https://yourdomain.com');
$allowed_origins = array_map('trim', explode(',', $allowed_origins_env));

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowed_origins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token, X-Reception-Key');
header('Access-Control-Allow-Credentials: true');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=(), interest-cohort=()');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: https: http:; connect-src 'self'; frame-ancestors 'none'; object-src 'none'; base-uri 'self';");

// Only set JSON Content-Type for API requests (api.php sets its own via sendResponse).
// This avoids breaking HTML pages that include config.php.
$isApiRequest = (isset($_SERVER['SCRIPT_NAME']) && basename($_SERVER['SCRIPT_NAME']) === 'api.php');
if (!$isApiRequest) {
    header('Content-Type: text/html; charset=utf-8');
}

// Enforce HTTPS in production — respect X-Forwarded-Proto for reverse proxies.
$environment = EnvironmentLoader::get('ENVIRONMENT', 'development');
$isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

if ($environment === 'production' && !$isSecure) {
    header('Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
    exit;
}

if ($isSecure) {
    // Set HSTS header for HTTPS connections
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
}

if ((isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS')) {
    http_response_code(204);
    exit;
}

?>
