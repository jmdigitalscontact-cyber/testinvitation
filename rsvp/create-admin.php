<?php
/**
 * Admin User Creation Script
 * Creates an initial admin user in the database
 */

require_once 'config.php';
require_once 'Database.php';

try {
    $db = Database::getInstance();
    $mysqli = $db->getConnection();
    
    // Admin credentials
    $username = 'admin';
    $password = 'password'; // Change this to a secure password
    $email = 'admin@wedding.local';
    
    // Hash the password
    $password_hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    
    // Check if admin already exists
    $check_stmt = $mysqli->prepare("SELECT id FROM admin_users WHERE username = ?");
    $check_stmt->bind_param("s", $username);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    
    if ($result->num_rows > 0) {
        echo "Admin user already exists!\n";
        exit;
    }
    
    // Insert admin user
    $stmt = $mysqli->prepare("INSERT INTO admin_users (username, password_hash, email) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $username, $password_hash, $email);
    
    if ($stmt->execute()) {
        echo "✓ Admin user created successfully!\n";
        echo "Username: " . htmlspecialchars($username) . "\n";
        echo "Password: " . htmlspecialchars($password) . "\n";
        echo "Email: " . htmlspecialchars($email) . "\n";
        echo "\n⚠️ IMPORTANT: Change the password immediately after first login!\n";
    } else {
        echo "Error creating admin user: " . $stmt->error . "\n";
    }
    
    $stmt->close();
    $check_stmt->close();
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
