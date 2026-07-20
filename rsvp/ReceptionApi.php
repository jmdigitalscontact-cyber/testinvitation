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
    $dir = dirname(__DIR__) . '/reception/uploads';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir;
}

function receptionEnsurePhotosTable($mysqli) {
    $result = $mysqli->query("SHOW TABLES LIKE 'reception_photos'");
    if ($result && $result->num_rows > 0) {
        return true;
    }

    $sql = file_get_contents(__DIR__ . '/database-reception-photos.sql');
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
            return 'File is too large for the server (max 5MB).';
        case UPLOAD_ERR_PARTIAL:
            return 'Upload was interrupted. Please try again.';
        case UPLOAD_ERR_NO_FILE:
            return 'No photo was received. Please try again.';
        default:
            return 'Upload failed. Please try again.';
    }
}

function receptionPhotoPublicUrl($storagePath) {
    $path = '/' . ltrim(str_replace('\\', '/', (string)$storagePath), '/');
    $base = defined('PUBLIC_BASE_URL') ? rtrim(PUBLIC_BASE_URL, '/') : '';
    return $base !== '' ? $base . $path : $path;
}

function receptionCanConvertToWebp() {
    return function_exists('imagewebp')
        && function_exists('imagecreatefromjpeg')
        && function_exists('imagecreatefrompng')
        && function_exists('imagecreatefromwebp');
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
    $image = receptionCreateImageResource($tmpPath, $mime);
    if (!$image) {
        return false;
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

    return $ok && is_file($destPath) && filesize($destPath) > 0;
}

function handleUploadReceptionPhoto() {
    receptionRequireApiKey();
    [$rateIp, $rateData] = receptionAssertUploadRateLimit();

    if (!receptionCanConvertToWebp()) {
        sendResponse([
            'success' => false,
            'error' => 'Server image conversion is not available. Please enable PHP GD with WebP support.'
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

    $maxBytes = (int)EnvironmentLoader::get('RECEPTION_UPLOAD_MAX_BYTES', 5242880);
    if ($file['size'] > $maxBytes) {
        sendResponse(['success' => false, 'error' => 'File is too large (max 5MB)'], 400);
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
    ];

    if (!isset($allowed[$mime])) {
        $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
        $extMap = ['jpg' => 'jpg', 'jpeg' => 'jpg', 'png' => 'png', 'webp' => 'webp'];
        if (isset($extMap[$ext])) {
            $mime = $ext === 'jpg' || $ext === 'jpeg' ? 'image/jpeg' : 'image/' . $extMap[$ext];
        } else {
            sendResponse(['success' => false, 'error' => 'Only JPEG, PNG, or WebP images are allowed'], 400);
        }
    }

    $uploadsDir = receptionUploadsDir();
    $safeName = 'reception-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.webp';
    $destPath = $uploadsDir . DIRECTORY_SEPARATOR . $safeName;

    if (!receptionConvertToWebp($file['tmp_name'], $mime, $destPath)) {
        sendResponse(['success' => false, 'error' => 'Could not convert image to WebP'], 500);
    }

    $storagePath = 'reception/uploads/' . $safeName;
    $storedMime = 'image/webp';
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
        $idResult = $mysqli->query('SELECT LASTVAL() AS id');
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
