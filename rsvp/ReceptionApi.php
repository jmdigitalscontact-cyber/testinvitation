<?php
/**
 * Public reception venue API (guest search, gallery).
 */

function receptionRequireApiKey() {
    $expected = trim((string)EnvironmentLoader::get('RECEPTION_API_KEY', ''));
    if ($expected === '') {
        sendResponse([
            'success' => false,
            'error' => 'Reception access is not configured. Please set RECEPTION_API_KEY in .env.'
        ], 503);
    }

    $provided = trim((string)($_SERVER['HTTP_X_RECEPTION_KEY'] ?? $_GET['key'] ?? ''));
    if ($provided === '' || !hash_equals($expected, $provided)) {
        sendResponse(['success' => false, 'error' => 'Unauthorized'], 401);
    }
}

function receptionUploadsDir() {
    // Allow overriding the uploads path via environment (recommended: outside web root)
    $default = dirname(dirname(__DIR__)) . '/uploads/reception';
    $envPath = trim((string)EnvironmentLoader::get('RECEPTION_UPLOADS_PATH', ''));
    $dir = $envPath !== '' ? $envPath : $default;

    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    return $dir;
}

function receptionEnsurePhotosTable($mysqli) {
    $result = $mysqli->query("SHOW TABLES LIKE 'reception_photos'");
    if ($result && $result->num_rows > 0) {
        return true;
    }

    $engine = defined('DB_ENGINE') ? DB_ENGINE : 'mysql';
    $schemaFile = ($engine === 'mysql') ? 'database-reception-photos-mysql.sql' : 'database-reception-photos.sql';
    $sql = file_get_contents(__DIR__ . '/' . $schemaFile);
    if ($sql) {
        $statements = array_filter(array_map('trim', preg_split('/;\s*\n/', $sql)));
        foreach ($statements as $statement) {
            $statement = preg_replace('/^--.*\R/m', '', $statement);
            $statement = trim($statement);
            if ($statement === '') {
                continue;
            }
            $mysqli->query($statement);
        }
    }

    return true;
}

function receptionNormalizeName($name) {
    return trim(html_entity_decode((string)$name, ENT_QUOTES, 'UTF-8'));
}

/**
 * Public endpoint used by the reception lock screen to verify an access key
 * before revealing the main content. Uses a timing-safe comparison so the
 * key is not leaked through timing side-channels.
 */
function handleVerifyReceptionKey() {
    $expected = trim((string)EnvironmentLoader::get('RECEPTION_API_KEY', ''));
    $provided = trim((string)($_GET['key'] ?? $_POST['key'] ?? ''));

    if ($expected === '') {
        sendResponse([
            'success' => false,
            'error' => 'Reception access is not configured. Please set RECEPTION_API_KEY in .env.'
        ], 503);
    }

    if ($provided === '' || !hash_equals($expected, $provided)) {
        // Return the same body regardless so attackers can't enumerate keys.
        sendResponse(['success' => false, 'error' => 'Invalid reception key'], 401);
    }

    sendResponse(['success' => true, 'message' => 'Reception key valid']);
}

/**
 * Admin-only: generate (or regenerate) the reception access QR code.
 * The QR embeds PUBLIC_BASE_URL/reception/?key=<RECEPTION_API_KEY>.
 */
function handleGenerateReceptionQR() {
    requireAdminAuth();

    $key = trim((string)EnvironmentLoader::get('RECEPTION_API_KEY', ''));
    if ($key === '') {
        sendResponse([
            'success' => false,
            'error' => 'RECEPTION_API_KEY is not set in .env. Add it and re-deploy, then regenerate the QR.'
        ], 503);
    }

    $qr_gen = new QRCodeGenerator();
    $result = $qr_gen->generateReceptionQRCode($key);

    if (!$result || empty($result['qr_image_path'])) {
        sendResponse([
            'success' => false,
            'error' => 'Reception QR could not be generated. Check write permissions for rsvp/qr_codes.'
        ], 500);
    }

    sendResponse([
        'success' => true,
        'data' => [
            'qr_image_path' => $result['qr_image_path'],
            'qr_url' => $result['qr_url'],
            'file_name' => $result['file_name'],
        ],
    ]);
}

function handleGetReceptionGuests() {
    receptionRequireApiKey();

    $db = Database::getInstance();
    $mysqli = $db->getConnection();
    $guests = [];

    $query = "
        SELECT
            a.id AS attendee_id,
            a.attendee_name,
            a.invitation_id,
            ta_att.table_number AS attendee_table,
            ta_att.seat_number AS attendee_seat,
            ta_inv.table_number AS invitation_table,
            ta_inv.seat_number AS invitation_seat
        FROM attendees a
        INNER JOIN rsvp_responses r ON r.invitation_id = a.invitation_id AND r.attending = 'yes'
        LEFT JOIN table_assignments ta_att ON ta_att.attendee_id = a.id
        LEFT JOIN table_assignments ta_inv ON ta_inv.invitation_id = a.invitation_id AND ta_inv.attendee_id IS NULL
        ORDER BY a.attendee_name ASC
    ";

    $result = $mysqli->query($query);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $name = receptionNormalizeName($row['attendee_name'] ?? '');
            if ($name === '') {
                continue;
            }
            $tableNumber = $row['attendee_table'] ?? $row['invitation_table'] ?? null;
            $seatNumber = $row['attendee_seat'] ?? $row['invitation_seat'] ?? null;
            $guests[] = [
                'id' => 'a-' . $row['attendee_id'],
                'name' => $name,
                'tableNumber' => $tableNumber !== null ? (int)$tableNumber : null,
                'seatNumber' => $seatNumber !== null ? (int)$seatNumber : null,
                'invitationId' => $row['invitation_id'] ?? '',
            ];
        }
    }

    if (empty($guests)) {
        $fallback = "
            SELECT i.invitation_id, i.guest_name, r.attendees, r.special_notes,
                   ta.table_number, ta.seat_number
            FROM invitations i
            INNER JOIN rsvp_responses r ON r.invitation_id = i.invitation_id AND r.attending = 'yes'
            LEFT JOIN table_assignments ta ON ta.invitation_id = i.invitation_id AND ta.attendee_id IS NULL
            ORDER BY i.guest_name ASC
        ";
        $result = $mysqli->query($fallback);
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $names = [];
                $attendees = !empty($row['attendees']) ? json_decode($row['attendees'], true) : [];
                if (is_array($attendees)) {
                    foreach ($attendees as $att) {
                        if (!is_array($att)) {
                            continue;
                        }
                        $n = receptionNormalizeName($att['attendee_name'] ?? $att['name'] ?? '');
                        if ($n !== '') {
                            $names[] = $n;
                        }
                    }
                }
                if (empty($names)) {
                    $primary = receptionNormalizeName($row['guest_name'] ?? '');
                    if ($primary !== '') {
                        $names[] = $primary;
                    }
                }
                $tableNumber = isset($row['table_number']) ? (int)$row['table_number'] : null;
                $seatNumber = isset($row['seat_number']) ? (int)$row['seat_number'] : null;
                foreach ($names as $index => $name) {
                    $guests[] = [
                        'id' => 'i-' . $row['invitation_id'] . '-' . $index,
                        'name' => $name,
                        'tableNumber' => $tableNumber > 0 ? $tableNumber : null,
                        'seatNumber' => $seatNumber > 0 ? $seatNumber : null,
                        'invitationId' => $row['invitation_id'] ?? '',
                    ];
                }
            }
        }
    }

    sendResponse(['success' => true, 'data' => $guests]);
}

function handleGetReceptionPhotos() {
    receptionRequireApiKey();

    $db = Database::getInstance();
    $mysqli = $db->getConnection();
    receptionEnsurePhotosTable($mysqli);

    $photos = [];
    $result = $mysqli->query("
        SELECT id, file_name, storage_path, mime_type, uploaded_at
        FROM reception_photos
        ORDER BY uploaded_at DESC
        LIMIT 200
    ");

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $path = str_replace('\\', '/', (string)$row['storage_path']);
            $photos[] = [
                'id' => (int)$row['id'],
                'fileName' => $row['file_name'],
                'url' => receptionPhotoPublicUrl($path),
                'mimeType' => $row['mime_type'],
                'uploadedAt' => $row['uploaded_at'],
            ];
        }
    }

    sendResponse(['success' => true, 'data' => $photos]);
}

function receptionUploadRateLogFile() {
    $logDir = dirname(__DIR__) . '/logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    return $logDir . '/reception-upload-rate.json';
}

function receptionLoadUploadRateData() {
    $logFile = receptionUploadRateLogFile();
    $now = time();
    $window = 3600;
    $data = [];

    if (is_file($logFile)) {
        $raw = file_get_contents($logFile);
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $data = $decoded;
        }
    }

    foreach ($data as $ip => $timestamps) {
        if (!is_array($timestamps)) {
            unset($data[$ip]);
            continue;
        }
        $data[$ip] = array_values(array_filter($timestamps, function ($ts) use ($now, $window) {
            return ($now - (int)$ts) < $window;
        }));
    }

    return $data;
}

function receptionSaveUploadRateData(array $data) {
    file_put_contents(receptionUploadRateLogFile(), json_encode($data), LOCK_EX);
}

function receptionAssertUploadRateLimit() {
    $maxPerHour = (int)EnvironmentLoader::get('RECEPTION_UPLOAD_MAX_PER_HOUR', 40);
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $data = receptionLoadUploadRateData();

    if (!isset($data[$ip]) || !is_array($data[$ip])) {
        $data[$ip] = [];
    }

    if (count($data[$ip]) >= $maxPerHour) {
        sendResponse([
            'success' => false,
            'error' => 'Upload limit reached for now. Please try again in a little while.',
        ], 429);
    }

    return [$ip, $data];
}

function receptionRecordSuccessfulUpload($ip, array $data) {
    if (!isset($data[$ip]) || !is_array($data[$ip])) {
        $data[$ip] = [];
    }
    $data[$ip][] = time();
    receptionSaveUploadRateData($data);
}

function receptionUploadErrorMessage($code) {
    switch ((int)$code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'File is too large for the server (max 10MB).';
        case UPLOAD_ERR_PARTIAL:
            return 'Upload was interrupted. Please try again.';
        case UPLOAD_ERR_NO_FILE:
            return 'No photo was received. Please try again.';
        default:
            return 'Upload failed. Please try again.';
    }
}

function receptionPhotoPublicUrl($storagePath) {
    // Return a secure URL that streams the photo via the API (requires RECEPTION_API_KEY)
    $file = basename((string)$storagePath);
    $base = defined('PUBLIC_BASE_URL') ? rtrim(PUBLIC_BASE_URL, '/') : '';
    $url = '/rsvp/api.php?action=serve-reception-photo&file=' . rawurlencode($file);
    return $base !== '' ? $base . $url : $url;
}

function handleServeReceptionPhoto() {
    // Securely serve stored reception photos. Requires reception API key.
    receptionRequireApiKey();

    $file = isset($_GET['file']) ? basename($_GET['file']) : '';
    if ($file === '') {
        http_response_code(400);
        echo 'Missing file parameter';
        exit;
    }

    $uploadsDir = receptionUploadsDir();
    $filePath = $uploadsDir . DIRECTORY_SEPARATOR . $file;

    if (is_file($filePath) && is_readable($filePath)) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? finfo_file($finfo, $filePath) : 'application/octet-stream';
        if ($finfo) finfo_close($finfo);

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string)filesize($filePath));
        header('Cache-Control: public, max-age=86400');
        $fp = fopen($filePath, 'rb');
        if ($fp) {
            while (!feof($fp)) {
                echo fread($fp, 8192);
                flush();
            }
            fclose($fp);
        }
        exit;
    }

    if (receptionGooglePhotosEnabled()) {
        $remote = receptionFetchGooglePhotoBytes($file);
        if ($remote && !empty($remote['body'])) {
            header('Content-Type: ' . ($remote['mimeType'] ?? 'application/octet-stream'));
            header('Content-Length: ' . (string)strlen($remote['body']));
            header('Cache-Control: public, max-age=86400');
            echo $remote['body'];
            exit;
        }
    }

    http_response_code(404);
    echo 'Not found';
    exit;
}

function receptionCanConvertToWebp() {
    return (
        function_exists('imagewebp')
        && function_exists('imagecreatefromjpeg')
        && function_exists('imagecreatefrompng')
    ) || class_exists('Imagick') || receptionFindExternalMagickCommand() !== '';
}

function receptionFindExternalMagickCommand() {
    if (!function_exists('exec')) {
        return '';
    }

    $tests = ['magick', 'convert'];
    foreach ($tests as $command) {
        exec((PHP_SHLIB_SUFFIX === 'dll' ? 'where ' : 'command -v ') . escapeshellarg($command) . ' 2>&1', $output, $code);
        if ($code === 0) {
            return $command;
        }
    }

    return '';
}

function receptionGooglePhotosEnabled() {
    return GOOGLE_PHOTOS_CLIENT_ID !== ''
        && GOOGLE_PHOTOS_CLIENT_SECRET !== ''
        && GOOGLE_PHOTOS_REFRESH_TOKEN !== '';
}

function receptionGooglePhotosAccessToken() {
    static $token = null;
    static $expires = 0;

    if ($token !== null && $expires > time() + 30) {
        return $token;
    }

    if (!function_exists('curl_init')) {
        return false;
    }

    $ch = curl_init('https://oauth2.googleapis.com/token');
    $payload = http_build_query([
        'client_id' => GOOGLE_PHOTOS_CLIENT_ID,
        'client_secret' => GOOGLE_PHOTOS_CLIENT_SECRET,
        'refresh_token' => GOOGLE_PHOTOS_REFRESH_TOKEN,
        'grant_type' => 'refresh_token',
    ]);

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_SSL_VERIFYPEER => false,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        return false;
    }

    $data = json_decode($response, true);
    if (empty($data['access_token'])) {
        return false;
    }

    $token = $data['access_token'];
    $expires = time() + (int)($data['expires_in'] ?? 3600);
    return $token;
}

function receptionUploadToGooglePhotos($tmpPath, $mime, $fileName) {
    $accessToken = receptionGooglePhotosAccessToken();
    if (!$accessToken) {
        return false;
    }

    if (!function_exists('curl_init')) {
        return false;
    }

    $fileContents = file_get_contents($tmpPath);
    if ($fileContents === false) {
        return false;
    }

    $uploadHeaders = [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/octet-stream',
        'X-Goog-Upload-Protocol: raw',
        'X-Goog-Upload-File-Name: ' . $fileName,
    ];

    $ch = curl_init('https://photoslibrary.googleapis.com/v1/uploads');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $fileContents,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $uploadHeaders,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);

    $uploadToken = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$uploadToken) {
        return false;
    }

    return receptionCreateGooglePhotosMediaItem($uploadToken, $fileName, $accessToken);
}

function receptionCreateGooglePhotosMediaItem($uploadToken, $fileName, $accessToken) {
    $payload = [
        'newMediaItems' => [
            [
                'description' => 'Reception photo upload',
                'simpleMediaItem' => [
                    'uploadToken' => $uploadToken,
                    'fileName' => $fileName,
                ],
            ],
        ],
    ];

    if (GOOGLE_PHOTOS_ALBUM_ID !== '') {
        $payload['albumId'] = GOOGLE_PHOTOS_ALBUM_ID;
    }

    $ch = curl_init('https://photoslibrary.googleapis.com/v1/mediaItems:batchCreate');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ],
        CURLOPT_SSL_VERIFYPEER => false,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        return false;
    }

    $data = json_decode($response, true);
    if (empty($data['newMediaItemResults'][0]['mediaItem'])) {
        return false;
    }

    return $data['newMediaItemResults'][0]['mediaItem'];
}

function receptionGetGooglePhotosMediaItem($mediaItemId) {
    $accessToken = receptionGooglePhotosAccessToken();
    if (!$accessToken) {
        return false;
    }

    $url = 'https://photoslibrary.googleapis.com/v1/mediaItems/' . rawurlencode($mediaItemId);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
        CURLOPT_SSL_VERIFYPEER => false,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        return false;
    }

    $data = json_decode($response, true);
    return is_array($data) ? $data : false;
}

function receptionFetchGooglePhotoBytes($mediaItemId) {
    $item = receptionGetGooglePhotosMediaItem($mediaItemId);
    if (!$item || empty($item['baseUrl'])) {
        return false;
    }

    $accessToken = receptionGooglePhotosAccessToken();
    if (!$accessToken) {
        return false;
    }

    $downloadUrl = $item['baseUrl'] . '=d';
    $ch = curl_init($downloadUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);

    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: ($item['mimeType'] ?? 'application/octet-stream');
    curl_close($ch);

    if ($httpCode < 200 || $httpCode >= 300 || $body === false) {
        return false;
    }

    return [
        'body' => $body,
        'mimeType' => $contentType,
    ];
}

function receptionCreateImageResource($tmpPath, $mime) {
    switch ($mime) {
        case 'image/jpeg':
        case 'image/jpg':
        case 'image/pjpeg':
            return @imagecreatefromjpeg($tmpPath);
        case 'image/png':
            return @imagecreatefrompng($tmpPath);
        case 'image/webp':
        case 'image/x-webp':
            return @imagecreatefromwebp($tmpPath);
        default:
            return false;
    }
}

function receptionConvertToWebp($tmpPath, $mime, $destPath) {
    if (in_array($mime, ['image/heic', 'image/heif'], true)) {
        return receptionConvertHeicToWebp($tmpPath, $destPath);
    }

    if (function_exists('imagewebp') && function_exists('imagecreatefromjpeg') && function_exists('imagecreatefrompng')) {
        $info = @getimagesize($tmpPath);
        if ($info && !empty($info[0]) && !empty($info[1])) {
            $srcImage = receptionCreateImageResource($tmpPath, $mime);
            if ($srcImage) {
                $srcWidth = (int)$info[0];
                $srcHeight = (int)$info[1];
                $maxDimension = (int)EnvironmentLoader::get('RECEPTION_UPLOAD_MAX_DIMENSION', 2400);
                if ($maxDimension < 800) {
                    $maxDimension = 800;
                }

                $image = $srcImage;
                if (max($srcWidth, $srcHeight) > $maxDimension) {
                    $scale = $maxDimension / max($srcWidth, $srcHeight);
                    $destWidth = max(1, (int)floor($srcWidth * $scale));
                    $destHeight = max(1, (int)floor($srcHeight * $scale));

                    $resized = @imagecreatetruecolor($destWidth, $destHeight);
                    if ($resized) {
                        if (in_array($mime, ['image/png', 'image/webp', 'image/x-webp'], true)) {
                            @imagealphablending($resized, false);
                            @imagesavealpha($resized, true);
                        }
                        @imagecopyresampled($resized, $srcImage, 0, 0, 0, 0, $destWidth, $destHeight, $srcWidth, $srcHeight);
                        imagedestroy($srcImage);
                        $image = $resized;
                    }
                }

                if (function_exists('imagepalettetotruecolor')) {
                    @imagepalettetotruecolor($image);
                }
                @imagealphablending($image, true);
                @imagesavealpha($image, true);

                $quality = (int)EnvironmentLoader::get('RECEPTION_WEBP_QUALITY', 82);
                if ($quality < 50) $quality = 50;
                if ($quality > 100) $quality = 100;

                $ok = @imagewebp($image, $destPath, $quality);
                imagedestroy($image);

                if ($ok && is_file($destPath) && filesize($destPath) > 0) {
                    return true;
                }
            }
        }
    }

    if (class_exists('Imagick')) {
        return receptionConvertGenericImageWithImagick($tmpPath, $destPath);
    }

    return receptionConvertWithExternalMagick($tmpPath, $destPath);
}

function receptionConvertGenericImageWithImagick($tmpPath, $destPath) {
    try {
        $imagick = new Imagick($tmpPath);
        $imagick->setImageFormat('webp');
        $quality = (int)EnvironmentLoader::get('RECEPTION_WEBP_QUALITY', 82);
        if ($quality < 50) $quality = 50;
        if ($quality > 100) $quality = 100;
        $imagick->setImageCompressionQuality($quality);
        $imagick->stripImage();
        $result = $imagick->writeImage($destPath);
        $imagick->clear();
        $imagick->destroy();
        return $result && is_file($destPath) && filesize($destPath) > 0;
    } catch (Exception $e) {
        return false;
    }
}

function receptionConvertWithExternalMagick($tmpPath, $destPath) {
    $command = receptionFindExternalMagickCommand();
    if ($command === '') {
        return false;
    }

    if (!function_exists('exec')) {
        return false;
    }

    $quality = (int)EnvironmentLoader::get('RECEPTION_WEBP_QUALITY', 82);
    if ($quality < 50) $quality = 50;
    if ($quality > 100) $quality = 100;

    if ($command === 'magick') {
        $cmd = escapeshellcmd($command) . ' ' . escapeshellarg($tmpPath) . ' -quality ' . escapeshellarg((string)$quality) . ' ' . escapeshellarg($destPath) . ' 2>&1';
    } else {
        $cmd = escapeshellcmd($command) . ' ' . escapeshellarg($tmpPath) . ' -quality ' . escapeshellarg((string)$quality) . ' ' . escapeshellarg($destPath) . ' 2>&1';
    }

    exec($cmd, $output, $resultCode);
    return $resultCode === 0 && is_file($destPath) && filesize($destPath) > 0;
}

function receptionConvertHeicToWebp($tmpPath, $destPath) {
    if (class_exists('Imagick')) {
        try {
            $imagick = new Imagick($tmpPath);
            $imagick->setImageFormat('webp');
            $quality = (int)EnvironmentLoader::get('RECEPTION_WEBP_QUALITY', 82);
            if ($quality < 50) $quality = 50;
            if ($quality > 100) $quality = 100;
            $imagick->setImageCompressionQuality($quality);
            $imagick->stripImage();
            $result = $imagick->writeImage($destPath);
            $imagick->clear();
            $imagick->destroy();
            return $result && is_file($destPath) && filesize($destPath) > 0;
        } catch (Exception $e) {
            return false;
        }
    }

    if (!function_exists('exec')) {
        return false;
    }

    $command = 'magick';
    exec((PHP_SHLIB_SUFFIX === 'dll' ? 'where ' : 'command -v ') . escapeshellarg($command) . ' 2>&1', $found, $code);
    if ($code !== 0) {
        return false;
    }

    $cmd = escapeshellcmd($command) . ' ' . escapeshellarg($tmpPath) . ' -quality ' . escapeshellarg((string)EnvironmentLoader::get('RECEPTION_WEBP_QUALITY', 82)) . ' ' . escapeshellarg($destPath) . ' 2>&1';
    exec($cmd, $output, $resultCode);
    return $resultCode === 0 && is_file($destPath) && filesize($destPath) > 0;
}

function handleUploadReceptionPhoto() {
    @ini_set('memory_limit', '512M');
    receptionRequireApiKey();
    [$rateIp, $rateData] = receptionAssertUploadRateLimit();

    $useGooglePhotos = receptionGooglePhotosEnabled();

    if (!$useGooglePhotos && !receptionCanConvertToWebp()) {
        sendResponse([
            'success' => false,
            'error' => 'Server image conversion is not available. Please enable PHP GD with WebP support or configure Google Photos.'
        ], 500);
    }

    if (!isset($_FILES['photo']) || !is_array($_FILES['photo'])) {
        sendResponse(['success' => false, 'error' => 'No photo uploaded'], 400);
    }

    $file = $_FILES['photo'];
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        sendResponse(['success' => false, 'error' => 'No photo uploaded'], 400);
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        sendResponse(['success' => false, 'error' => receptionUploadErrorMessage($file['error'])], 400);
    }

    $maxBytes = (int)EnvironmentLoader::get('RECEPTION_UPLOAD_MAX_BYTES', 10485760);
    if ($file['size'] > $maxBytes) {
        sendResponse(['success' => false, 'error' => 'File is too large (max 10MB)'], 400);
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/pjpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/x-webp' => 'webp',
        'image/heic' => 'heic',
        'image/heif' => 'heif',
    ];

    if (!isset($allowed[$mime])) {
        $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
        $extMap = ['jpg' => 'jpg', 'jpeg' => 'jpg', 'png' => 'png', 'webp' => 'webp', 'heic' => 'heic', 'heif' => 'heif'];
        if (isset($extMap[$ext])) {
            $mime = $ext === 'jpg' || $ext === 'jpeg' ? 'image/jpeg' : 'image/' . $extMap[$ext];
        } else {
            sendResponse(['success' => false, 'error' => 'Only JPEG, PNG, WebP, HEIC, or HEIF images are allowed'], 400);
        }
    }

    $uploadsDir = receptionUploadsDir();
    $storagePath = '';
    $storedMime = $mime;

    if ($useGooglePhotos) {
        $originalName = basename((string)($file['name'] ?? 'photo'));
        $googleResult = receptionUploadToGooglePhotos($file['tmp_name'], $mime, $originalName);
        if (!$googleResult || empty($googleResult['id'])) {
            sendResponse(['success' => false, 'error' => 'Google Photos upload failed'], 500);
        }

        $storagePath = $googleResult['id'];
        $storedMime = $googleResult['mimeType'] ?? $mime;

        // Record a successful upload security entry (best-effort)
        $secLog = dirname(__DIR__) . '/logs/reception-upload-security.log';
        @file_put_contents($secLog, date('c') . " - upload_ok - " . json_encode(['google_item_id' => $storagePath]) . PHP_EOL, FILE_APPEND | LOCK_EX);
    } else {
        $safeName = 'reception-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.webp';
        $destPath = $uploadsDir . DIRECTORY_SEPARATOR . $safeName;

        if (!receptionConvertToWebp($file['tmp_name'], $mime, $destPath)) {
            sendResponse(['success' => false, 'error' => 'Could not convert image to WebP'], 500);
        }

        // Ensure the generated file is not executable and has restrictive permissions
        @chmod($destPath, 0644);

        // Optional antivirus scan (ClamAV compatible). Enable by setting CLAMAV_SCAN_CMD in .env.
        $clamCmd = trim((string)EnvironmentLoader::get('CLAMAV_SCAN_CMD', ''));
        if ($clamCmd !== '' && function_exists('exec')) {
            $scanCmd = escapeshellcmd($clamCmd) . ' --no-summary ' . escapeshellarg($destPath) . ' 2>&1';
            exec($scanCmd, $scanOut, $scanCode);
            if ($scanCode !== 0) {
                @unlink($destPath);
                // Log the security event (best-effort)
                $secLog = dirname(__DIR__) . '/logs/reception-upload-security.log';
                @file_put_contents($secLog, date('c') . " - scan_failed - " . json_encode(['file' => $destPath, 'cmd' => $scanCmd, 'out' => $scanOut, 'code' => $scanCode]) . PHP_EOL, FILE_APPEND | LOCK_EX);
                sendResponse(['success' => false, 'error' => 'Uploaded file failed malware scan'], 400);
            }
        }

        // Record a successful upload security entry (best-effort)
        $secLog = dirname(__DIR__) . '/logs/reception-upload-security.log';
        @file_put_contents($secLog, date('c') . " - upload_ok - " . json_encode(['file' => $destPath]) . PHP_EOL, FILE_APPEND | LOCK_EX);

        if ($storagePath === '') {
            $storagePath = $safeName;
            $storedMime = 'image/webp';
        }
    }

    $db = Database::getInstance();
    $mysqli = $db->getConnection();
    receptionEnsurePhotosTable($mysqli);

    $stmt = $mysqli->prepare("
        INSERT INTO reception_photos (file_name, storage_path, mime_type)
        VALUES (?, ?, ?)
    ");
    $originalName = basename((string)($file['name'] ?? $safeName));
    $stmt->bind_param('sss', $originalName, $storagePath, $storedMime);

    if (!$stmt->execute()) {
        @unlink($destPath);
        sendResponse(['success' => false, 'error' => 'Database error'], 500);
    }

    $id = (int)$db->lastInsertId();
    if ($id < 1) {
        $engine = defined('DB_ENGINE') ? DB_ENGINE : 'mysql';
        $lastValSql = ($engine === 'mysql') ? 'SELECT LAST_INSERT_ID() AS id' : 'SELECT LASTVAL() AS id';
        $idResult = $mysqli->query($lastValSql);
        if ($idResult) {
            $idRow = $idResult->fetch_assoc();
            $id = (int)($idRow['id'] ?? 0);
        }
    }
    $stmt->close();

    receptionRecordSuccessfulUpload($rateIp, $rateData);

    sendResponse([
        'success' => true,
        'data' => [
            'id' => $id,
            'fileName' => $originalName,
            'url' => receptionPhotoPublicUrl($storagePath),
            'mimeType' => $storedMime,
            'uploadedAt' => date('c'),
        ],
    ]);
}
