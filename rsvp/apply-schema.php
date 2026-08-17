<?php
/**
 * Apply schema files for the configured database engine.
 * Run from project root: php rsvp/apply-schema.php
 *
 * Uses MySQL schema files by default (compatible with GoDaddy phpMyAdmin).
 * Uses PostgreSQL schema files when DB_ENGINE=pgsql.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Database.php';

$engine = defined('DB_ENGINE') ? DB_ENGINE : 'mysql';

if ($engine === 'mysql') {
    echo "Database engine: MySQL/MariaDB\n";
    $files = [
        // Single consolidated migration file (recommended for GoDaddy).
        'database-full-mysql.sql',
        // Granular files applied for backwards compatibility (safe to re-run).
        'database-schema-mysql.sql',
        'database-schema-additional-mysql.sql',
        'database-table-assignments-mysql.sql',
        'database-migration-edit-once.sql',
        'database-reception-photos-mysql.sql',
        'database-reception-votes-mysql.sql',
    ];
} else {
    echo "Database engine: PostgreSQL\n";
    $files = [
        'database-schema.sql',
        'database-schema-additional.sql',
        'database-table-assignments.sql',
        'database-migration-edit-once.sql',
        'database-reception-photos.sql',
        'database-reception-votes.sql',
    ];
}

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
