<?php
require_once 'config.php';
require_once 'Database.php';

try {
    $db = Database::getInstance();
    $mysqli = $db->getConnection();

    $sql = file_get_contents('database-table-assignments.sql');
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
?>