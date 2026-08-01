<?php
/**
 * Run table migration for the configured database engine.
 * Uses MySQL schema files by default (GoDaddy phpMyAdmin compatible).
 */
require_once 'config.php';
require_once 'Database.php';

try {
    $db = Database::getInstance();
    $mysqli = $db->getConnection();

    $engine = defined('DB_ENGINE') ? DB_ENGINE : 'mysql';
    $schemaFile = ($engine === 'mysql') ? 'database-table-assignments-mysql.sql' : 'database-table-assignments.sql';

    $sql = file_get_contents($schemaFile);
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    $ok = true;
    foreach ($statements as $statement) {
        if (!$mysqli->query($statement)) {
            $ok = false;
            echo "Error executing statement: " . $mysqli->error . "\n";
            break;
        }
    }

    if ($ok) {
        echo "Migration executed successfully\n";
    }
} catch (Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
}

