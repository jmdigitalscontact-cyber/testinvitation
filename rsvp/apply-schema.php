<?php
/**
 * Apply PostgreSQL schema files (idempotent where supported).
 * Run from project root: php rsvp/apply-schema.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Database.php';

$files = [
    'database-schema.sql',
    'database-schema-additional.sql',
    'database-table-assignments.sql',
    'database-migration-edit-once.sql',
    'database-reception-photos.sql',
];

$db = Database::getInstance();
$conn = $db->getConnection();

foreach ($files as $file) {
    $path = __DIR__ . '/' . $file;
    if (!is_file($path)) {
        echo "Skip missing: $file\n";
        continue;
    }

    echo "Applying $file...\n";
    $sql = file_get_contents($path);
    // Remove full-line SQL comments before splitting, so statements
    // preceded by comments are still executed.
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    $statements = array_filter(array_map('trim', preg_split('/;\s*\n/', $sql)));

    foreach ($statements as $statement) {
        if ($statement === '') {
            continue;
        }
        if (!$conn->query($statement)) {
            echo "  Warning: " . $conn->error . "\n";
        }
    }
    echo "  Done.\n";
}

echo "\nSchema apply finished.\n";
