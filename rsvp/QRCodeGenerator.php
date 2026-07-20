<?php
// QR Code Generation Class
// This version uses a simple QR code library that doesn't require external dependencies
// For production, consider using: https://github.com/endroid/qrcode

class QRCodeGenerator {
    private $db;
    private $mysqli;
    private $qr_path;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->mysqli = $this->db->getConnection();
        $this->qr_path = QR_CODE_PATH;

        // Create directory if it doesn't exist
        if (!is_dir($this->qr_path)) {
            mkdir($this->qr_path, 0755, true);
        }
    }

    /**
     * Generate QR code for an invitation
     * @param string $invitation_id
     * @param string $guest_name
     * @return array|false
     */
    /**
     * Guest-facing landing URL encoded in QR codes.
     */
    public static function buildInvitationLandingUrl($invitation_id) {
        $base = defined('PUBLIC_BASE_URL') ? PUBLIC_BASE_URL : 'http://localhost:3000';
        return $base . '/index.html?invite=' . rawurlencode($invitation_id);
    }

    /**
     * True when stored QR link is missing or points at a legacy/wrong host.
     */
    public static function qrCodeNeedsRegeneration($qr) {
        if (!$qr || empty($qr['qr_code_data'])) {
            return true;
        }

        $stored = (string)$qr['qr_code_data'];
        $expected = self::buildInvitationLandingUrl($qr['invitation_id'] ?? '');

        if ($stored === $expected) {
            return false;
        }

        $legacyPatterns = [
            '/InvitationTest/i',
            '/rsvp\/index\.php/i',
            '#\?invite=#i',
        ];

        if (!preg_match($legacyPatterns[2], $stored)) {
            return true;
        }

        if (preg_match($legacyPatterns[0], $stored) || preg_match($legacyPatterns[1], $stored)) {
            return true;
        }

        $base = defined('PUBLIC_BASE_URL') ? PUBLIC_BASE_URL : 'http://localhost:3000';
        if (strpos($stored, $base) !== 0) {
            return true;
        }

        if (strpos($stored, '/index.html?invite=') === false) {
            return true;
        }

        return false;
    }

    public function generateQRCode($invitation_id, $guest_name) {
        $qr_url = self::buildInvitationLandingUrl($invitation_id);

        // Generate QR code image path
        $qr_filename = $invitation_id . '.png';
        $qr_filepath = $this->qr_path . $qr_filename;

        // Use Google Charts API for QR generation (free, no dependencies)
        $qr_image_url = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($qr_url);

        // Prefer storing a local PNG, but fall back to hosted QR URL when
        // local download fails in restricted server environments.
        $storedImagePath = 'qr_codes/' . $qr_filename;
        if (!$this->downloadQRCode($qr_image_url, $qr_filepath)) {
            $storedImagePath = $qr_image_url;
        }

        // Store QR code information in database
        $stmt = $this->mysqli->prepare("
            INSERT INTO qr_codes (invitation_id, qr_code_data, qr_image_path) 
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE qr_image_path = VALUES(qr_image_path)
        ");

        if ($stmt) {
            $stmt->bind_param("sss", $invitation_id, $qr_url, $storedImagePath);
            $stmt->execute();
            $stmt->close();

            return [
                'success' => true,
                'invitation_id' => $invitation_id,
                'qr_image_path' => $storedImagePath,
                'qr_url' => $qr_url
            ];
        }

        return false;
    }

    /**
     * Download QR code image from URL
     * @param string $qr_url
     * @param string $filepath
     * @return bool
     */
    private function downloadQRCode($qr_url, $filepath) {
        try {
            $context = stream_context_create([
                'http' => [
                    'timeout' => 10,
                    'user_agent' => 'WeddingRSVP/1.0'
                ],
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false
                ]
            ]);

            $image_data = @file_get_contents($qr_url, false, $context);

            if ($image_data === false && function_exists('curl_init')) {
                $ch = curl_init($qr_url);
                if ($ch !== false) {
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_TIMEOUT => 10,
                        CURLOPT_CONNECTTIMEOUT => 5,
                        CURLOPT_SSL_VERIFYPEER => false,
                        CURLOPT_SSL_VERIFYHOST => false,
                        CURLOPT_USERAGENT => 'WeddingRSVP/1.0'
                    ]);
                    $curlData = curl_exec($ch);
                    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);

                    if ($curlData !== false && $httpCode >= 200 && $httpCode < 300) {
                        $image_data = $curlData;
                    }
                }
            }

            if ($image_data === false) {
                return false;
            }

            return file_put_contents($filepath, $image_data) !== false;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Alternative: Generate QR code using text-based representation
     * This can be used if image generation fails
     * @param string $text
     * @return string
     */
    public function generateQRCodeText($text) {
        // This is a fallback method that generates a simple text representation
        // In production, use a proper QR code library
        $encoded = base64_encode($text);
        return 'QR:' . substr($encoded, 0, 50) . '...';
    }

    /**
     * Get all QR codes for an invitation
     * @param string $invitation_id
     * @return array|false
     */
    public function getQRCode($invitation_id) {
        $stmt = $this->mysqli->prepare("
            SELECT * FROM qr_codes WHERE invitation_id = ?
        ");

        $stmt->bind_param("s", $invitation_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            $stmt->close();
            return false;
        }

        $qr = $result->fetch_assoc();
        $stmt->close();
        return $qr;
    }
}

?>
