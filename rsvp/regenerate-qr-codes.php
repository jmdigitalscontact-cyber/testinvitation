<?php
/**
 * Regenerate all invitation QR codes using PUBLIC_BASE_URL.
 * Run: php rsvp/regenerate-qr-codes.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/QRCodeGenerator.php';

$db = Database::getInstance();
$conn = $db->getConnection();
$result = $conn->query('SELECT invitation_id, guest_name FROM invitations ORDER BY invitation_id');

if (!$result || $result->num_rows === 0) {
    echo "No invitations found.\n";
    exit(0);
}

$qrGen = new QRCodeGenerator();
$updated = 0;
$failed = 0;

while ($row = $result->fetch_assoc()) {
    $id = $row['invitation_id'];
    $name = $row['guest_name'];
    $generated = $qrGen->generateQRCode($id, $name);

    if ($generated && !empty($generated['qr_url'])) {
        echo "OK  $id -> {$generated['qr_url']}\n";
        $updated++;
    } else {
        echo "FAIL $id\n";
        $failed++;
    }
}

echo "\nUpdated: $updated, Failed: $failed\n";
echo "Base URL: " . PUBLIC_BASE_URL . "\n";
