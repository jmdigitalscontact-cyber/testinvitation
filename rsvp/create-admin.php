<?php
/**
 * Admin User Creation Script
 * Creates an initial admin user in the database.
 *
 * Usage:
 *   php rsvp/create-admin.php                    # prompts for username + password
 *   php rsvp/create-admin.php admin MySecretPass # or pass them as arguments
 */

require_once 'config.php';
require_once 'Database.php';

function prompt($label, $hidden = false) {
    echo $label . ': ';
    if ($hidden && PHP_OS_FAMILY === 'Windows') {
        // Windows: read a line without echoing (best effort).
        $value = shell_exec('powershell -NoProfile -Command "$p=Read-Host -AsSecureString; [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($p))"');
        echo "\n";
        return trim((string)$value);
    }
    if ($hidden && function_exists('system') && stripos(PHP_OS, 'WIN') !== 0) {
        system('stty -echo');
    }
    $value = fgets(STDIN);
    if ($hidden && function_exists('system') && stripos(PHP_OS, 'WIN') !== 0) {
        system('stty echo');
        echo "\n";
    }
    return trim((string)$value);
}

try {
    $db = Database::getInstance();
    $mysqli = $db->getConnection();

    // Collect username / password from args or prompt.
    $username = $argv[1] ?? '';
    $password = $argv[2] ?? '';
    $email = $argv[3] ?? 'admin@wedding.local';

    if (empty($username)) {
        $username = prompt('Admin username');
    }
    if (empty($username)) {
        throw new Exception('Username cannot be empty.');
    }

    if (empty($password)) {
        $password = prompt('Admin password', true);
    }
    if (strlen((string)$password) < 8) {
        throw new Exception('Password must be at least 8 characters long.');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $email = 'admin@wedding.local';
    }

    // Hash the password
    $password_hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

    // Check if admin already exists
    $check_stmt = $mysqli->prepare("SELECT id FROM admin_users WHERE username = ?");
    $check_stmt->bind_param("s", $username);
    $check_stmt->execute();
    $result = $check_stmt->get_result();

    if ($result->num_rows > 0) {
        echo "Admin user '{$username}' already exists!\n";
        exit;
    }

    // Insert admin user
    $stmt = $mysqli->prepare("INSERT INTO admin_users (username, password_hash, email) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $username, $password_hash, $email);

    if ($stmt->execute()) {
        echo "✓ Admin user created successfully!\n";
        echo "Username: " . htmlspecialchars($username) . "\n";
        echo "Email: " . htmlspecialchars($email) . "\n";
        echo "\n⚠️ Store these credentials securely. The password is not displayed again.\n";
    } else {
        echo "Error creating admin user: " . $stmt->error . "\n";
    }

    $stmt->close();
    $check_stmt->close();
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

