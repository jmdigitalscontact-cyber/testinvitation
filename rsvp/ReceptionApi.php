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
    // Primary storage per project spec: reception/uploads
    $default = __DIR__ . '/../reception/uploads';
    $envPath = trim((string)EnvironmentLoader::get('RECEPTION_UPLOADS_PATH', ''));
    $dir = $envPath !== '' ? $envPath : $default;

    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (is_dir($dir) && !is_writable($dir)) {
        @chmod($dir, 0755);
    }

    return $dir;
}

function receptionLegacyUploadsDir() {
    return __DIR__ . '/../uploads/reception';
}

function receptionUploadsCandidateDirs() {
    $dirs = [receptionUploadsDir()];
    $legacy = receptionLegacyUploadsDir();
    if ($legacy !== $dirs[0] && is_dir($legacy)) {
        $dirs[] = $legacy;
    }
    return $dirs;
}

function receptionResolvePhotoFilePath($storagePath) {
    $file = basename((string)$storagePath);
    if ($file === '') {
        return null;
    }

    foreach (receptionUploadsCandidateDirs() as $dir) {
        $candidate = $dir . DIRECTORY_SEPARATOR . $file;
        if (is_file($candidate) && is_readable($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function receptionEnsurePhotosTable($mysqli) {
    $result = $mysqli->query("SHOW TABLES LIKE 'reception_photos'");
    if (!$result || $result->num_rows === 0) {
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

    // Auto-migrate missing columns for existing tables
    $columnsRes = $mysqli->query("SHOW COLUMNS FROM reception_photos");
    $existing = [];
    if ($columnsRes) {
        while ($col = $columnsRes->fetch_assoc()) {
            $existing[$col['Field']] = true;
        }
    }

    if (!isset($existing['uploader_name'])) {
        $mysqli->query("ALTER TABLE reception_photos ADD COLUMN uploader_name VARCHAR(128) DEFAULT NULL");
    }
    if (!isset($existing['table_number'])) {
        $mysqli->query("ALTER TABLE reception_photos ADD COLUMN table_number INT DEFAULT NULL");
    }
    if (!isset($existing['likes_count'])) {
        $mysqli->query("ALTER TABLE reception_photos ADD COLUMN likes_count INT DEFAULT 0");
    }
    if (!isset($existing['is_approved'])) {
        $mysqli->query("ALTER TABLE reception_photos ADD COLUMN is_approved TINYINT(1) DEFAULT 1");
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

function seatingNormalizeGuestName($name) {
    $name = trim(html_entity_decode((string)$name, ENT_QUOTES, 'UTF-8'));
    $name = preg_replace('/\s+/u', ' ', $name);
    return is_string($name) ? $name : '';
}

function seatingGuestKey($name) {
    $normalized = seatingNormalizeGuestName($name);
    if ($normalized === '') {
        return '';
    }
    return function_exists('mb_strtolower')
        ? mb_strtolower($normalized, 'UTF-8')
        : strtolower($normalized);
}

function seatingPersonIsGoing($attendee) {
    if (!is_array($attendee)) {
        return false;
    }
    $name = seatingNormalizeGuestName($attendee['attendee_name'] ?? $attendee['name'] ?? '');
    if ($name === '') {
        return false;
    }
    foreach (['attending', 'going', 'is_going'] as $flag) {
        if (!array_key_exists($flag, $attendee)) {
            continue;
        }
        $value = $attendee[$flag];
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'y', 'on', 'checked'], true);
        }
        return (bool)$value;
    }
    return true;
}

function seatingSqlIntOrNull($value) {
    if ($value === null || $value === '') {
        return 'NULL';
    }
    return (string)((int)$value);
}

function seatingEnsureAssignmentsTable($mysqli) {
    $exists = $mysqli->query("SHOW TABLES LIKE 'table_assignments'");
    if (!$exists || $exists->num_rows === 0) {
        $attendeeFk = '';
        if (seatingTableExists($mysqli, 'attendees')) {
            $attendeeFk = ', CONSTRAINT fk_table_assignments_attendee FOREIGN KEY (attendee_id) REFERENCES attendees(id) ON DELETE SET NULL';
        }
        $adminFk = '';
        if (seatingTableExists($mysqli, 'admin_users')) {
            $adminFk = ', CONSTRAINT fk_table_assignments_admin FOREIGN KEY (assigned_by) REFERENCES admin_users(id) ON DELETE SET NULL';
        }
        $created = $mysqli->query("
            CREATE TABLE IF NOT EXISTS table_assignments (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                invitation_id VARCHAR(50) NOT NULL,
                attendee_id BIGINT NULL,
                guest_name VARCHAR(255) NOT NULL DEFAULT '',
                table_number INT NOT NULL,
                seat_number INT NULL,
                assigned_by BIGINT NULL,
                assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_table_assignments_invitation FOREIGN KEY (invitation_id) REFERENCES invitations(invitation_id) ON DELETE CASCADE
                {$attendeeFk}
                {$adminFk}
            )
        ");
        if ($created) {
            $mysqli->query("CREATE INDEX idx_table_assignments_invitation_id ON table_assignments(invitation_id)");
            $mysqli->query("CREATE INDEX idx_table_assignments_attendee_id ON table_assignments(attendee_id)");
            $mysqli->query("CREATE INDEX idx_table_assignments_table_number ON table_assignments(table_number)");
            $mysqli->query("CREATE INDEX idx_table_assignments_guest_name ON table_assignments(invitation_id, guest_name)");
        }
        return;
    }

    $column = $mysqli->query("SHOW COLUMNS FROM table_assignments LIKE 'guest_name'");
    if ($column && $column->num_rows === 0) {
        $mysqli->query("ALTER TABLE table_assignments ADD COLUMN guest_name VARCHAR(255) NOT NULL DEFAULT ''");
    }

    $unique = $mysqli->query("SHOW INDEX FROM table_assignments");
    if ($unique) {
        while ($indexRow = $unique->fetch_assoc()) {
            if (($indexRow['Key_name'] ?? '') === 'unique_invitation_attendee') {
                $mysqli->query("ALTER TABLE table_assignments DROP INDEX unique_invitation_attendee");
                break;
            }
        }
    }

    if (seatingTableExists($mysqli, 'attendees')) {
        $fk = $mysqli->query("
            SELECT CONSTRAINT_NAME, DELETE_RULE
            FROM information_schema.REFERENTIAL_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE()
              AND TABLE_NAME = 'table_assignments'
              AND REFERENCED_TABLE_NAME = 'attendees'
            LIMIT 1
        ");
        if ($fk && ($row = $fk->fetch_assoc()) && !empty($row['CONSTRAINT_NAME']) && strtoupper((string)$row['DELETE_RULE']) === 'CASCADE') {
            $name = str_replace('`', '', $row['CONSTRAINT_NAME']);
            $mysqli->query("ALTER TABLE table_assignments DROP FOREIGN KEY `{$name}`");
            $mysqli->query("
                ALTER TABLE table_assignments
                ADD CONSTRAINT fk_table_assignments_attendee
                FOREIGN KEY (attendee_id) REFERENCES attendees(id) ON DELETE SET NULL
            ");
        }
    }
}

function seatingTableExists($mysqli, $tableName) {
    $escaped = $mysqli->real_escape_string($tableName);
    $result = $mysqli->query("SHOW TABLES LIKE '{$escaped}'");
    return $result && $result->num_rows > 0;
}

function seatingCollectConfirmedPeople($mysqli) {
    $people = [];
    $result = $mysqli->query("
        SELECT i.invitation_id, i.guest_name AS party_name,
               r.attendees, r.special_notes
        FROM invitations i
        INNER JOIN rsvp_responses r ON r.invitation_id = i.invitation_id
        WHERE r.attending = 'yes'
        ORDER BY i.guest_name ASC
    ");
    if (!$result) {
        return $people;
    }

    $attendeesByInvitation = [];
    if (seatingTableExists($mysqli, 'attendees')) {
        $attendeeResult = $mysqli->query("
            SELECT a.id, a.invitation_id, a.attendee_name
            FROM attendees a
            INNER JOIN rsvp_responses r ON r.invitation_id = a.invitation_id AND r.attending = 'yes'
            ORDER BY a.id ASC
        ");
        if ($attendeeResult) {
            while ($row = $attendeeResult->fetch_assoc()) {
                $name = seatingNormalizeGuestName($row['attendee_name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $invitationId = (string)$row['invitation_id'];
                $attendeesByInvitation[$invitationId][] = [
                    'attendee_id' => (int)$row['id'],
                    'guest_name' => $name,
                ];
            }
        }
    }

    while ($row = $result->fetch_assoc()) {
        $invitationId = (string)$row['invitation_id'];
        $partyName = seatingNormalizeGuestName($row['party_name'] ?? '');
        $names = $attendeesByInvitation[$invitationId] ?? [];

        if (!$names) {
            $attendees = !empty($row['attendees']) ? json_decode($row['attendees'], true) : [];
            if (is_array($attendees)) {
                foreach ($attendees as $attendee) {
                    if (!seatingPersonIsGoing($attendee)) {
                        continue;
                    }
                    $name = seatingNormalizeGuestName($attendee['attendee_name'] ?? $attendee['name'] ?? '');
                    if ($name === '') {
                        continue;
                    }
                    $names[] = [
                        'attendee_id' => null,
                        'guest_name' => $name,
                    ];
                }
            }
        }

        if (!$names && !empty($row['special_notes'])) {
            $noteNames = preg_split('/\r\n|\r|\n|,/', (string)$row['special_notes']);
            foreach ($noteNames as $noteName) {
                $name = seatingNormalizeGuestName($noteName);
                if ($name === '') {
                    continue;
                }
                $names[] = [
                    'attendee_id' => null,
                    'guest_name' => $name,
                ];
            }
        }

        if (!$names && $partyName !== '') {
            $names[] = [
                'attendee_id' => null,
                'guest_name' => $partyName,
            ];
        }

        $usedKeys = [];
        foreach ($names as $person) {
            $key = seatingGuestKey($person['guest_name']);
            if ($key === '' || isset($usedKeys[$key])) {
                continue;
            }
            $usedKeys[$key] = true;
            $people[] = [
                'invitation_id' => $invitationId,
                'party_name' => $partyName,
                'guest_name' => $person['guest_name'],
                'attendee_id' => $person['attendee_id'],
            ];
        }
    }

    usort($people, function ($a, $b) {
        $byName = strcasecmp($a['guest_name'], $b['guest_name']);
        if ($byName !== 0) {
            return $byName;
        }
        return strcasecmp($a['party_name'], $b['party_name']);
    });

    return $people;
}

function seatingLoadAssignments($mysqli) {
    if (!seatingTableExists($mysqli, 'table_assignments')) {
        return [];
    }
    $result = $mysqli->query("
        SELECT id, invitation_id, attendee_id, guest_name, table_number, seat_number
        FROM table_assignments
        ORDER BY id ASC
    ");
    if (!$result) {
        return [];
    }
    $assignments = [];
    while ($row = $result->fetch_assoc()) {
        $assignments[] = [
            'id' => (int)$row['id'],
            'invitation_id' => (string)$row['invitation_id'],
            'attendee_id' => $row['attendee_id'] !== null ? (int)$row['attendee_id'] : null,
            'guest_name' => seatingNormalizeGuestName($row['guest_name'] ?? ''),
            'table_number' => (int)$row['table_number'],
            'seat_number' => $row['seat_number'] !== null ? (int)$row['seat_number'] : null,
        ];
    }
    return $assignments;
}

function seatingFindAssignment(array $assignments, array $person) {
    $invitationId = (string)($person['invitation_id'] ?? '');
    $attendeeId = isset($person['attendee_id']) ? (int)$person['attendee_id'] : 0;
    $guestKey = seatingGuestKey($person['guest_name'] ?? '');

    foreach ($assignments as $assignment) {
        if ($assignment['invitation_id'] !== $invitationId) {
            continue;
        }
        if ($attendeeId > 0 && (int)($assignment['attendee_id'] ?? 0) === $attendeeId) {
            return $assignment;
        }
    }

    foreach ($assignments as $assignment) {
        if ($assignment['invitation_id'] !== $invitationId) {
            continue;
        }
        if ($guestKey !== '' && seatingGuestKey($assignment['guest_name']) === $guestKey) {
            return $assignment;
        }
    }

    foreach ($assignments as $assignment) {
        if ($assignment['invitation_id'] !== $invitationId) {
            continue;
        }
        if (($assignment['guest_name'] === '' || $assignment['guest_name'] === null)
            && empty($assignment['attendee_id'])) {
            return $assignment;
        }
    }

    return null;
}

function seatingMigrateLegacyPartyAssignments($mysqli) {
    if (!seatingTableExists($mysqli, 'table_assignments')) {
        return;
    }

    $legacy = $mysqli->query("
        SELECT id, invitation_id, attendee_id, table_number, seat_number, assigned_by
        FROM table_assignments
        WHERE guest_name = '' OR guest_name IS NULL
    ");
    if (!$legacy || $legacy->num_rows === 0) {
        return;
    }

    $people = seatingCollectConfirmedPeople($mysqli);
    $peopleByInvitation = [];
    foreach ($people as $person) {
        $peopleByInvitation[$person['invitation_id']][] = $person;
    }

    $namedAssignments = seatingLoadAssignments($mysqli);
    $namedKeys = [];
    foreach ($namedAssignments as $assignment) {
        if ($assignment['guest_name'] === '') {
            continue;
        }
        $namedKeys[$assignment['invitation_id'] . "\n" . seatingGuestKey($assignment['guest_name'])] = true;
    }

    while ($row = $legacy->fetch_assoc()) {
        $assignmentId = (int)$row['id'];
        $invitationId = (string)$row['invitation_id'];
        $tableNumber = (int)$row['table_number'];
        $seatNumberSql = seatingSqlIntOrNull($row['seat_number']);
        $assignedBySql = seatingSqlIntOrNull($row['assigned_by']);
        $attendeeId = $row['attendee_id'] !== null ? (int)$row['attendee_id'] : 0;

        if ($attendeeId > 0) {
            $matchedName = '';
            foreach ($peopleByInvitation[$invitationId] ?? [] as $person) {
                if ((int)$person['attendee_id'] === $attendeeId) {
                    $matchedName = $person['guest_name'];
                    break;
                }
            }
            if ($matchedName === '' && seatingTableExists($mysqli, 'attendees')) {
                $lookup = $mysqli->prepare("SELECT attendee_name FROM attendees WHERE id = ? LIMIT 1");
                if ($lookup) {
                    $lookup->bind_param('i', $attendeeId);
                    $lookup->execute();
                    $found = $lookup->get_result()->fetch_assoc();
                    $lookup->close();
                    $matchedName = seatingNormalizeGuestName($found['attendee_name'] ?? '');
                }
            }
            if ($matchedName !== '') {
                $stmt = $mysqli->prepare("UPDATE table_assignments SET guest_name = ? WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param('si', $matchedName, $assignmentId);
                    $stmt->execute();
                    $stmt->close();
                }
            }
            continue;
        }

        $invitationSql = $mysqli->real_escape_string($invitationId);
        foreach ($peopleByInvitation[$invitationId] ?? [] as $person) {
            $key = $invitationId . "\n" . seatingGuestKey($person['guest_name']);
            if (isset($namedKeys[$key])) {
                continue;
            }
            $guestSql = $mysqli->real_escape_string($person['guest_name']);
            $personAttendeeSql = seatingSqlIntOrNull($person['attendee_id']);
            $mysqli->query("
                INSERT INTO table_assignments (invitation_id, attendee_id, guest_name, table_number, seat_number, assigned_by)
                VALUES ('{$invitationSql}', {$personAttendeeSql}, '{$guestSql}', {$tableNumber}, {$seatNumberSql}, {$assignedBySql})
            ");
            $namedKeys[$key] = true;
        }

        $stmt = $mysqli->prepare("DELETE FROM table_assignments WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $assignmentId);
            $stmt->execute();
            $stmt->close();
        }
    }
}

function seatingDecoratePeople(array $people, array $assignments) {
    $decorated = [];
    foreach ($people as $person) {
        $assignment = seatingFindAssignment($assignments, $person);
        $personal = $assignment && (
            seatingGuestKey($assignment['guest_name']) === seatingGuestKey($person['guest_name'])
            || ((int)($assignment['attendee_id'] ?? 0) > 0 && (int)($assignment['attendee_id'] ?? 0) === (int)($person['attendee_id'] ?? 0))
        );
        $decorated[] = [
            'invitation_id' => $person['invitation_id'],
            'party_name' => $person['party_name'],
            'guest_name' => $person['guest_name'],
            'attendee_id' => $person['attendee_id'],
            'assignment_id' => $assignment && $personal ? (int)$assignment['id'] : ($assignment ? (int)$assignment['id'] : null),
            'table_number' => $assignment ? (int)$assignment['table_number'] : null,
            'seat_number' => $assignment && !empty($assignment['seat_number']) ? (int)$assignment['seat_number'] : null,
            'inherited' => $assignment && !$personal,
        ];
    }
    return $decorated;
}

function seatingPrepareAdminList($mysqli) {
    seatingEnsureAssignmentsTable($mysqli);
    seatingMigrateLegacyPartyAssignments($mysqli);
    $people = seatingCollectConfirmedPeople($mysqli);
    $assignments = seatingLoadAssignments($mysqli);
    return seatingDecoratePeople($people, $assignments);
}

function seatingFindConfirmedPerson($mysqli, $invitationId, $guestName) {
    $key = seatingGuestKey($guestName);
    if ($invitationId === '' || $key === '') {
        return null;
    }
    foreach (seatingCollectConfirmedPeople($mysqli) as $person) {
        if ($person['invitation_id'] === $invitationId && seatingGuestKey($person['guest_name']) === $key) {
            return $person;
        }
    }
    return null;
}

function seatingUpsertGuestAssignment($mysqli, $invitationId, $guestName, $tableNumber, $adminId) {
    seatingEnsureAssignmentsTable($mysqli);
    seatingMigrateLegacyPartyAssignments($mysqli);

    $person = seatingFindConfirmedPerson($mysqli, $invitationId, $guestName);
    if (!$person) {
        return ['ok' => false, 'error' => 'That guest is not on the confirmed list.'];
    }

    $assignments = seatingLoadAssignments($mysqli);
    $existing = null;
    foreach ($assignments as $assignment) {
        if ($assignment['invitation_id'] !== $invitationId) {
            continue;
        }
        $sameName = seatingGuestKey($assignment['guest_name']) === seatingGuestKey($person['guest_name']);
        $sameAttendee = (int)($assignment['attendee_id'] ?? 0) > 0
            && (int)($assignment['attendee_id'] ?? 0) === (int)($person['attendee_id'] ?? 0);
        if ($sameName || $sameAttendee) {
            $existing = $assignment;
            break;
        }
    }

    $guestName = $person['guest_name'];
    $invitationSql = $mysqli->real_escape_string($invitationId);
    $guestSql = $mysqli->real_escape_string($guestName);
    $attendeeSql = seatingSqlIntOrNull($person['attendee_id']);
    $adminSql = seatingSqlIntOrNull($adminId > 0 ? $adminId : null);
    $tableNumber = (int)$tableNumber;

    if ($existing) {
        $assignmentId = (int)$existing['id'];
        $ok = $mysqli->query("
            UPDATE table_assignments
            SET guest_name = '{$guestSql}', attendee_id = {$attendeeSql}, table_number = {$tableNumber}, assigned_by = {$adminSql}
            WHERE id = {$assignmentId}
        ");
        if (!$ok) {
            return ['ok' => false, 'error' => 'Could not update the table assignment: ' . $mysqli->error];
        }
        return ['ok' => true];
    }

    $ok = $mysqli->query("
        INSERT INTO table_assignments (invitation_id, attendee_id, guest_name, table_number, assigned_by)
        VALUES ('{$invitationSql}', {$attendeeSql}, '{$guestSql}', {$tableNumber}, {$adminSql})
    ");
    if (!$ok) {
        return ['ok' => false, 'error' => 'Could not save the table assignment: ' . $mysqli->error];
    }
    return ['ok' => true];
}

function seatingDeleteGuestAssignment($mysqli, $assignmentId, $invitationId = '', $guestName = '') {
    seatingEnsureAssignmentsTable($mysqli);
    seatingMigrateLegacyPartyAssignments($mysqli);

    if ((int)$assignmentId > 0) {
        $id = (int)$assignmentId;
        $stmt = $mysqli->prepare("DELETE FROM table_assignments WHERE id = ?");
        if (!$stmt) {
            return ['ok' => false, 'error' => 'Could not remove the table assignment.'];
        }
        $stmt->bind_param('i', $id);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok ? ['ok' => true] : ['ok' => false, 'error' => 'Could not remove the table assignment.'];
    }

    $person = seatingFindConfirmedPerson($mysqli, $invitationId, $guestName);
    if (!$person) {
        return ['ok' => false, 'error' => 'That guest is not on the confirmed list.'];
    }
    $assignment = seatingFindAssignment(seatingLoadAssignments($mysqli), $person);
    if (!$assignment) {
        return ['ok' => true];
    }
    if (!empty($assignment['inherited']) || (
        seatingGuestKey($assignment['guest_name']) === '' && empty($assignment['attendee_id'])
    )) {
        return ['ok' => false, 'error' => 'Assign this guest individually before removing their seat.'];
    }

    $id = (int)$assignment['id'];
    $stmt = $mysqli->prepare("DELETE FROM table_assignments WHERE id = ?");
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Could not remove the table assignment.'];
    }
    $stmt->bind_param('i', $id);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok ? ['ok' => true] : ['ok' => false, 'error' => 'Could not remove the table assignment.'];
}

function handleGetReceptionGuests() {
    receptionRequireApiKey();

    $db = Database::getInstance();
    $mysqli = $db->getConnection();
    seatingEnsureAssignmentsTable($mysqli);

    $people = seatingCollectConfirmedPeople($mysqli);
    $assignments = seatingLoadAssignments($mysqli);
    $guests = [];

    foreach (seatingDecoratePeople($people, $assignments) as $index => $person) {
        $guests[] = [
            'id' => $person['attendee_id']
                ? 'a-' . $person['attendee_id']
                : 'i-' . $person['invitation_id'] . '-' . $index,
            'name' => $person['guest_name'],
            'tableNumber' => $person['table_number'] !== null ? (int)$person['table_number'] : null,
            'seatNumber' => $person['seat_number'] !== null ? (int)$person['seat_number'] : null,
            'invitationId' => $person['invitation_id'],
        ];
    }

    sendResponse(['success' => true, 'data' => $guests]);
}


function receptionMapPhotoRow(array $row) {
    $path = str_replace('\\', '/', (string)$row['storage_path']);
    return [
        'id' => (int)$row['id'],
        'fileName' => $row['file_name'],
        'url' => receptionPhotoPublicUrl($path),
        'mimeType' => $row['mime_type'],
        'uploaderName' => $row['uploader_name'] ?? null,
        'tableNumber' => $row['table_number'] !== null ? (int)$row['table_number'] : null,
        'likesCount' => (int)($row['likes_count'] ?? 0),
        'uploadedAt' => $row['uploaded_at'],
    ];
}

function handleGetReceptionPhotos() {
    receptionRequireApiKey();

    $db = Database::getInstance();
    $mysqli = $db->getConnection();
    receptionEnsurePhotosTable($mysqli);

    $sinceId = (int)($_GET['since_id'] ?? 0);
    $photos = [];

    if ($sinceId > 0) {
        $stmt = $mysqli->prepare("
            SELECT id, file_name, storage_path, mime_type, uploader_name, table_number, likes_count, uploaded_at
            FROM reception_photos
            WHERE is_approved = 1 AND id > ?
            ORDER BY id ASC
            LIMIT 100
        ");
        $stmt->bind_param('i', $sinceId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $photos[] = receptionMapPhotoRow($row);
        }
        $stmt->close();
    } else {
        $result = $mysqli->query("
            SELECT id, file_name, storage_path, mime_type, uploader_name, table_number, likes_count, uploaded_at
            FROM reception_photos
            WHERE is_approved = 1
            ORDER BY uploaded_at DESC
            LIMIT 200
        ");

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $photos[] = receptionMapPhotoRow($row);
            }
        }
    }

    sendResponse(['success' => true, 'data' => $photos]);
}

function receptionGooglePhotosShareUrl() {
    $url = defined('GOOGLE_PHOTOS_SHARE_URL') ? trim((string)GOOGLE_PHOTOS_SHARE_URL) : '';
    return $url !== '' ? $url : 'https://photos.app.goo.gl/LyebvyWMcerYSJmR6';
}

function receptionHttpGetLimited($url, $maxBytes = 5242880) {
    $headers = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Accept: text/html,application/xhtml+xml',
    ];

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 18,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_BUFFERSIZE => 256000,
        ]);
        $body = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $finalUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);
        if ($httpCode < 200 || $httpCode >= 300 || $body === false) {
            return false;
        }
        if (strlen($body) > $maxBytes) {
            return false;
        }
        return ['body' => $body, 'url' => $finalUrl];
    }

    $ctx = stream_context_create([
        'http' => [
            'timeout' => 18,
            'header' => implode("\r\n", $headers),
            'follow_location' => 1,
            'max_redirects' => 5,
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false || strlen($body) > $maxBytes) {
        return false;
    }
    $finalUrl = $url;
    if (!empty($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $line) {
            if (stripos($line, 'Location:') === 0) {
                $finalUrl = trim(substr($line, 9));
            }
        }
    }
    return ['body' => $body, 'url' => $finalUrl];
}

function receptionGooglePhotoHostAllowed($url) {
    $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?: ''));
    return in_array($host, ['photos.app.goo.gl', 'photos.google.com', 'lh3.googleusercontent.com'], true);
}

function receptionGooglePhotoDisplayUrl($src) {
    $base = preg_replace('/=.+$/', '', (string)$src);
    return $base . '=w1200-no';
}

function receptionParseGooglePhotosAlbum($html) {
    $items = [];
    $pattern = '/\["(AF1Qip[A-Za-z0-9_-]+)",\["(https:\/\/lh3\.googleusercontent\.com\/pw\/[^"]+)",(\d+),(\d+)[\s\S]{0,500}?\],(\d{13}),"[^"]*",\d+,(\d{13})/';
    if (!preg_match_all($pattern, (string)$html, $matches, PREG_SET_ORDER)) {
        return $items;
    }

    $seen = [];
    foreach ($matches as $match) {
        $id = $match[1];
        if (isset($seen[$id])) {
            continue;
        }
        $seen[$id] = true;
        $src = receptionGooglePhotoDisplayUrl($match[2]);
        $items[] = [
            'id' => $id,
            'src' => $src,
            'width' => (int)$match[3],
            'height' => (int)$match[4],
            'createdAt' => (int)$match[5],
            'addedAt' => (int)$match[6],
        ];
    }

    usort($items, static function ($a, $b) {
        return ($b['addedAt'] <=> $a['addedAt']) ?: ($b['createdAt'] <=> $a['createdAt']);
    });

    return $items;
}

function receptionGoogleAlbumCachePath() {
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'berbers-google-photos-album.json';
}

function receptionGoogleAlbumReadCache() {
    $path = receptionGoogleAlbumCachePath();
    if (!is_file($path)) {
        return null;
    }
    $cached = json_decode((string)file_get_contents($path), true);
    if (!is_array($cached) || !isset($cached['items']) || !is_array($cached['items'])) {
        return null;
    }
    $cached['_mtime'] = (int)filemtime($path);
    return $cached;
}

function receptionSendGoogleAlbumResponse($items, $source, $limit) {
    header('Cache-Control: public, max-age=60, s-maxage=300, stale-while-revalidate=86400');
    sendResponse([
        'success' => true,
        'source' => $source,
        'albumUrl' => receptionGooglePhotosShareUrl(),
        'items' => array_slice(array_values($items), 0, $limit),
    ]);
}

function handleGetGooglePhotosAlbum() {
    receptionRequireApiKey();

    $limit = (int)($_GET['limit'] ?? 200);
    if ($limit < 1) {
        $limit = 200;
    }
    if ($limit > 200) {
        $limit = 200;
    }

    $cacheTtl = 300;
    $cached = receptionGoogleAlbumReadCache();
    if ($cached && (time() - (int)$cached['_mtime']) < $cacheTtl) {
        receptionSendGoogleAlbumResponse($cached['items'], 'google-photos-cache', $limit);
    }

    $fetched = receptionHttpGetLimited(receptionGooglePhotosShareUrl());
    $finalUrl = is_array($fetched) ? (string)($fetched['url'] ?? '') : '';
    $body = is_array($fetched) ? (string)($fetched['body'] ?? '') : '';
    $hostOk = $finalUrl === '' || receptionGooglePhotoHostAllowed($finalUrl);

    if ($body === '' || !$hostOk) {
        if ($cached) {
            receptionSendGoogleAlbumResponse($cached['items'], 'google-photos-stale', $limit);
        }
        header('Cache-Control: public, max-age=30');
        sendResponse([
            'success' => true,
            'source' => 'google-photos-unavailable',
            'albumUrl' => receptionGooglePhotosShareUrl(),
            'items' => [],
        ]);
    }

    $items = receptionParseGooglePhotosAlbum($body);
    @file_put_contents(
        receptionGoogleAlbumCachePath(),
        json_encode(['items' => $items, 'fetchedAt' => time()], JSON_UNESCAPED_SLASHES)
    );

    receptionSendGoogleAlbumResponse($items, 'google-photos', $limit);
}

function receptionDbEngine() {
    return defined('DB_ENGINE') ? DB_ENGINE : 'mysql';
}

function receptionEnsureVotesTable($mysqli) {
    if (receptionDbEngine() === 'pgsql') {
        $statements = [
            "CREATE TABLE IF NOT EXISTS reception_votes (
                id BIGSERIAL PRIMARY KEY,
                voter_token CHAR(64) NOT NULL UNIQUE,
                team VARCHAR(5) NOT NULL CHECK (team IN ('bride', 'groom')),
                voted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE INDEX IF NOT EXISTS idx_reception_votes_team ON reception_votes(team)",
        ];
    } else {
        $statements = [
            "CREATE TABLE IF NOT EXISTS reception_votes (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                voter_token CHAR(64) NOT NULL,
                team ENUM('bride', 'groom') NOT NULL,
                voted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_reception_voter_token (voter_token),
                INDEX idx_reception_votes_team (team)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ];
    }

    foreach ($statements as $statement) {
        if (!$mysqli->query($statement)) {
            throw new Exception('Could not initialize reception voting.');
        }
    }
}

function receptionVoteTokenHash($token) {
    $token = trim((string)$token);
    if (!preg_match('/^[A-Za-z0-9-]{16,128}$/', $token)) {
        sendResponse(['success' => false, 'error' => 'Invalid voter token'], 400);
    }
    return hash('sha256', $token);
}

function receptionGetVoteCounts($mysqli, $tokenHash = null) {
    $counts = ['bride' => 0, 'groom' => 0, 'total' => 0, 'myTeam' => null];
    $result = $mysqli->query("
        SELECT
            SUM(CASE WHEN team = 'bride' THEN 1 ELSE 0 END) AS bride_count,
            SUM(CASE WHEN team = 'groom' THEN 1 ELSE 0 END) AS groom_count,
            COUNT(*) AS total_count
        FROM reception_votes
    ");

    if ($result && ($row = $result->fetch_assoc())) {
        $counts['bride'] = (int)($row['bride_count'] ?? 0);
        $counts['groom'] = (int)($row['groom_count'] ?? 0);
        $counts['total'] = (int)($row['total_count'] ?? 0);
    }

    if ($tokenHash !== null) {
        $stmt = $mysqli->prepare("SELECT team FROM reception_votes WHERE voter_token = ? LIMIT 1");
        $stmt->bind_param('s', $tokenHash);
        $stmt->execute();
        $teamResult = $stmt->get_result();
        $teamRow = $teamResult ? $teamResult->fetch_assoc() : null;
        $stmt->close();
        $counts['myTeam'] = $teamRow['team'] ?? null;
    }

    return $counts;
}

function handleGetReceptionVotes() {
    receptionRequireApiKey();

    $tokenHash = receptionVoteTokenHash($_GET['voter_token'] ?? '');
    $db = Database::getInstance();
    $mysqli = $db->getConnection();
    receptionEnsureVotesTable($mysqli);

    sendResponse(['success' => true, 'data' => receptionGetVoteCounts($mysqli, $tokenHash)]);
}

function handleSubmitReceptionVote() {
    receptionRequireApiKey();

    $input = getRequestInput();
    $team = strtolower(trim((string)($input['team'] ?? $_POST['team'] ?? '')));
    if (!in_array($team, ['bride', 'groom'], true)) {
        sendResponse(['success' => false, 'error' => 'Choose Team Bride or Team Groom'], 400);
    }

    $tokenHash = receptionVoteTokenHash($input['voter_token'] ?? $_POST['voter_token'] ?? '');
    $db = Database::getInstance();
    $mysqli = $db->getConnection();
    receptionEnsureVotesTable($mysqli);

    // Ignoring duplicates keeps retries idempotent and preserves the first, locked vote.
    $insertSql = receptionDbEngine() === 'pgsql'
        ? "INSERT INTO reception_votes (voter_token, team) VALUES (?, ?) ON CONFLICT (voter_token) DO NOTHING"
        : "INSERT IGNORE INTO reception_votes (voter_token, team) VALUES (?, ?)";

    $stmt = $mysqli->prepare($insertSql);
    $stmt->bind_param('ss', $tokenHash, $team);
    if (!$stmt->execute()) {
        $stmt->close();
        sendResponse(['success' => false, 'error' => 'Could not save vote'], 500);
    }
    // Row counts land on the connection adapter, not the statement.
    $created = (int)$mysqli->affected_rows > 0;
    $stmt->close();

    sendResponse([
        'success' => true,
        'data' => array_merge(receptionGetVoteCounts($mysqli, $tokenHash), [
            'created' => $created,
        ]),
    ]);
}

function handleAdminGetReceptionVotes() {
    requireAdminAuth();

    $db = Database::getInstance();
    $mysqli = $db->getConnection();
    receptionEnsureVotesTable($mysqli);

    $votes = [];
    $result = $mysqli->query("
        SELECT id, voter_token, team, voted_at
        FROM reception_votes
        ORDER BY voted_at DESC, id DESC
        LIMIT 500
    ");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $votes[] = [
                'id' => (int)$row['id'],
                'voter' => substr((string)$row['voter_token'], 0, 8),
                'team' => $row['team'],
                'votedAt' => $row['voted_at'],
            ];
        }
    }

    sendResponse([
        'success' => true,
        'data' => array_merge(receptionGetVoteCounts($mysqli), ['votes' => $votes]),
    ]);
}

function handleAdminUpdateReceptionVote() {
    requireAdminAuth();

    $input = getRequestInput();
    $voteId = (int)($input['vote_id'] ?? 0);
    $team = strtolower(trim((string)($input['team'] ?? '')));
    if ($voteId < 1 || !in_array($team, ['bride', 'groom'], true)) {
        sendResponse(['success' => false, 'error' => 'Invalid vote or team'], 400);
    }

    $db = Database::getInstance();
    $mysqli = $db->getConnection();
    receptionEnsureVotesTable($mysqli);

    $stmt = $mysqli->prepare("UPDATE reception_votes SET team = ? WHERE id = ?");
    $stmt->bind_param('si', $team, $voteId);
    if (!$stmt->execute()) {
        $stmt->close();
        sendResponse(['success' => false, 'error' => 'Could not update vote'], 500);
    }
    $changed = (int)$mysqli->affected_rows > 0;
    $stmt->close();

    if (!$changed) {
        $check = $mysqli->prepare("SELECT id FROM reception_votes WHERE id = ? LIMIT 1");
        $check->bind_param('i', $voteId);
        $check->execute();
        $exists = $check->get_result()->fetch_assoc();
        $check->close();
        if (!$exists) {
            sendResponse(['success' => false, 'error' => 'Vote not found'], 404);
        }
    }

    sendResponse([
        'success' => true,
        'message' => 'Vote updated.',
        'data' => receptionGetVoteCounts($mysqli),
    ]);
}

function handleAdminDeleteReceptionVote() {
    requireAdminAuth();

    $input = getRequestInput();
    $voteId = (int)($input['vote_id'] ?? 0);
    if ($voteId < 1) {
        sendResponse(['success' => false, 'error' => 'Invalid vote ID'], 400);
    }

    $db = Database::getInstance();
    $mysqli = $db->getConnection();
    receptionEnsureVotesTable($mysqli);

    $stmt = $mysqli->prepare("DELETE FROM reception_votes WHERE id = ?");
    $stmt->bind_param('i', $voteId);
    if (!$stmt->execute()) {
        $stmt->close();
        sendResponse(['success' => false, 'error' => 'Could not delete vote'], 500);
    }
    $deleted = (int)$mysqli->affected_rows > 0;
    $stmt->close();

    if (!$deleted) {
        sendResponse(['success' => false, 'error' => 'Vote not found'], 404);
    }

    sendResponse([
        'success' => true,
        'message' => 'Vote deleted.',
        'data' => receptionGetVoteCounts($mysqli),
    ]);
}

function handleAdminClearReceptionVotes() {
    requireAdminAuth();

    $input = getRequestInput();
    if (($input['confirm'] ?? '') !== 'RESET') {
        sendResponse(['success' => false, 'error' => 'Reset confirmation is required'], 400);
    }

    $db = Database::getInstance();
    $mysqli = $db->getConnection();
    receptionEnsureVotesTable($mysqli);

    if (!$mysqli->query("DELETE FROM reception_votes")) {
        sendResponse(['success' => false, 'error' => 'Could not reset votes'], 500);
    }

    sendResponse([
        'success' => true,
        'message' => 'All Team Bride / Team Groom votes were reset.',
        'data' => ['deleted' => (int)$mysqli->affected_rows],
    ]);
}

function receptionEnsureMessagesTable($mysqli) {
    if (receptionDbEngine() === 'pgsql') {
        $statements = [
            "CREATE TABLE IF NOT EXISTS reception_messages (
                id BIGSERIAL PRIMARY KEY,
                guest_name VARCHAR(128) NOT NULL,
                message TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE INDEX IF NOT EXISTS idx_reception_messages_created ON reception_messages(created_at)",
        ];
    } else {
        $statements = [
            "CREATE TABLE IF NOT EXISTS reception_messages (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                guest_name VARCHAR(128) NOT NULL,
                message TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_reception_messages_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ];
    }

    foreach ($statements as $statement) {
        if (!$mysqli->query($statement)) {
            throw new Exception('Could not initialize reception messages.');
        }
    }
}

function receptionMessageRateLogFile() {
    $logDir = dirname(__DIR__) . '/logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    return $logDir . '/reception-message-rate.json';
}

function receptionAssertMessageRateLimit() {
    $maxPerHour = (int)EnvironmentLoader::get('RECEPTION_MESSAGE_MAX_PER_HOUR', 30);
    if ($maxPerHour < 1) {
        $maxPerHour = 30;
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $logFile = receptionMessageRateLogFile();
    $now = time();
    $window = 3600;
    $data = [];

    if (is_file($logFile)) {
        $decoded = json_decode((string)file_get_contents($logFile), true);
        if (is_array($decoded)) {
            $data = $decoded;
        }
    }

    foreach ($data as $key => $timestamps) {
        if (!is_array($timestamps)) {
            unset($data[$key]);
            continue;
        }
        $data[$key] = array_values(array_filter($timestamps, function ($ts) use ($now, $window) {
            return ($now - (int)$ts) < $window;
        }));
    }

    if (!isset($data[$ip]) || !is_array($data[$ip])) {
        $data[$ip] = [];
    }

    if (count($data[$ip]) >= $maxPerHour) {
        sendResponse([
            'success' => false,
            'error' => 'Message limit reached for now. Please try again later.',
            'code' => 'rate_limited',
        ], 429);
    }

    return [$ip, $data, $logFile];
}

function receptionRecordSuccessfulMessage($ip, array $data, $logFile) {
    if (!isset($data[$ip]) || !is_array($data[$ip])) {
        $data[$ip] = [];
    }
    $data[$ip][] = time();
    file_put_contents($logFile, json_encode($data), LOCK_EX);
}

function handleSubmitReceptionMessage() {
    receptionRequireApiKey();
    [$rateIp, $rateData, $rateFile] = receptionAssertMessageRateLimit();

    $input = getRequestInput();
    $guestName = trim(sanitize($input['guest_name'] ?? $_POST['guest_name'] ?? ''));
    $message = trim(sanitize($input['message'] ?? $_POST['message'] ?? ''));

    if ($guestName === '') {
        sendResponse(['success' => false, 'error' => 'Please enter your name'], 400);
    }
    if (strlen($guestName) > 128) {
        sendResponse(['success' => false, 'error' => 'Name is too long (max 128 characters)'], 400);
    }
    if ($message === '') {
        sendResponse(['success' => false, 'error' => 'Please write a message for the couple'], 400);
    }
    if (strlen($message) > 1000) {
        sendResponse(['success' => false, 'error' => 'Message is too long (max 1000 characters)'], 400);
    }

    $db = Database::getInstance();
    $mysqli = $db->getConnection();
    receptionEnsureMessagesTable($mysqli);

    $stmt = $mysqli->prepare("
        INSERT INTO reception_messages (guest_name, message)
        VALUES (?, ?)
    ");
    $stmt->bind_param('ss', $guestName, $message);
    if (!$stmt->execute()) {
        $stmt->close();
        sendResponse(['success' => false, 'error' => 'Could not save message'], 500);
    }
    $id = (int)$db->lastInsertId();
    if ($id < 1) {
        $engine = receptionDbEngine();
        $lastValSql = ($engine === 'mysql') ? 'SELECT LAST_INSERT_ID() AS id' : 'SELECT LASTVAL() AS id';
        $idResult = $mysqli->query($lastValSql);
        if ($idResult) {
            $idRow = $idResult->fetch_assoc();
            $id = (int)($idRow['id'] ?? 0);
        }
    }
    $stmt->close();

    receptionRecordSuccessfulMessage($rateIp, $rateData, $rateFile);

    sendResponse([
        'success' => true,
        'message' => 'Your message was saved for Jason & Rhona Mae.',
        'data' => ['id' => $id],
    ]);
}

function handleAdminGetReceptionMessages() {
    requireAdminAuth();

    $db = Database::getInstance();
    $mysqli = $db->getConnection();
    receptionEnsureMessagesTable($mysqli);

    $messages = [];
    $result = $mysqli->query("
        SELECT id, guest_name, message, created_at
        FROM reception_messages
        ORDER BY created_at DESC, id DESC
        LIMIT 2000
    ");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $messages[] = [
                'id' => (int)$row['id'],
                'guestName' => $row['guest_name'],
                'message' => $row['message'],
                'createdAt' => $row['created_at'],
            ];
        }
    }

    sendResponse([
        'success' => true,
        'data' => [
            'total' => count($messages),
            'messages' => $messages,
        ],
    ]);
}

function handleAdminDeleteReceptionMessage() {
    requireAdminAuth();

    $input = getRequestInput();
    $messageId = (int)($input['message_id'] ?? 0);
    if ($messageId < 1) {
        sendResponse(['success' => false, 'error' => 'Invalid message ID'], 400);
    }

    $db = Database::getInstance();
    $mysqli = $db->getConnection();
    receptionEnsureMessagesTable($mysqli);

    $stmt = $mysqli->prepare("DELETE FROM reception_messages WHERE id = ?");
    $stmt->bind_param('i', $messageId);
    if (!$stmt->execute()) {
        $stmt->close();
        sendResponse(['success' => false, 'error' => 'Could not delete message'], 500);
    }
    $deleted = (int)$mysqli->affected_rows > 0;
    $stmt->close();

    if (!$deleted) {
        sendResponse(['success' => false, 'error' => 'Message not found'], 404);
    }

    sendResponse(['success' => true, 'message' => 'Message deleted.']);
}

function handleAdminClearReceptionMessages() {
    requireAdminAuth();

    $input = getRequestInput();
    if (($input['confirm'] ?? '') !== 'DELETE') {
        sendResponse(['success' => false, 'error' => 'Clear confirmation is required'], 400);
    }

    $db = Database::getInstance();
    $mysqli = $db->getConnection();
    receptionEnsureMessagesTable($mysqli);

    if (!$mysqli->query("DELETE FROM reception_messages")) {
        sendResponse(['success' => false, 'error' => 'Could not clear messages'], 500);
    }

    sendResponse([
        'success' => true,
        'message' => 'All guest messages were cleared.',
        'data' => ['deleted' => (int)$mysqli->affected_rows],
    ]);
}

function handleAdminExportReceptionMessagesCsv() {
    requireAdminAuth();

    $db = Database::getInstance();
    $mysqli = $db->getConnection();
    receptionEnsureMessagesTable($mysqli);

    $result = $mysqli->query("
        SELECT id, guest_name, message, created_at
        FROM reception_messages
        ORDER BY created_at ASC, id ASC
    ");

    $filename = 'couple-messages-' . date('Ymd-His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    // UTF-8 BOM helps Excel open accented names correctly.
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['id', 'guest_name', 'message', 'created_at']);

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            fputcsv($out, [
                (int)$row['id'],
                $row['guest_name'],
                $row['message'],
                $row['created_at'],
            ]);
        }
    }

    fclose($out);
    exit;
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
    // Venue Wi-Fi shares one public IP across many guests, so the default must
    // be high enough for wedding-day bulk uploads (not a per-person cap).
    $maxPerHour = (int)EnvironmentLoader::get('RECEPTION_UPLOAD_MAX_PER_HOUR', 500);
    if ($maxPerHour < 1) {
        $maxPerHour = 500;
    }
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $data = receptionLoadUploadRateData();

    if (!isset($data[$ip]) || !is_array($data[$ip])) {
        $data[$ip] = [];
    }

    if (count($data[$ip]) >= $maxPerHour) {
        sendResponse([
            'success' => false,
            'error' => 'Upload limit reached for this network right now. Please try again in a little while.',
            'code' => 'rate_limited',
            'limit' => $maxPerHour,
        ], 429);
    }

    return [$ip, $data];
}

function receptionClearUploadRateData() {
    $logFile = receptionUploadRateLogFile();
    if (is_file($logFile)) {
        @unlink($logFile);
    }
}

function receptionRecordSuccessfulUpload($ip, array $data) {
    if (!isset($data[$ip]) || !is_array($data[$ip])) {
        $data[$ip] = [];
    }
    $data[$ip][] = time();
    receptionSaveUploadRateData($data);
}

function receptionUploadErrorMessage($code) {
    $appMaxMb = max(1, (int)round(((int)EnvironmentLoader::get('RECEPTION_UPLOAD_MAX_BYTES', 10485760)) / 1048576));
    $iniMax = ini_get('upload_max_filesize') ?: 'unknown';
    switch ((int)$code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return "File is too large for the server (PHP upload_max_filesize={$iniMax}, app max {$appMaxMb}MB).";
        case UPLOAD_ERR_PARTIAL:
            return 'Upload was interrupted. Please try again.';
        case UPLOAD_ERR_NO_FILE:
            return 'No photo was received. Please try again.';
        default:
            return 'Upload failed. Please try again.';
    }
}

function receptionPhotoPublicUrl($storagePath) {
    // Return a secure URL that streams the photo via the API.
    $file = basename((string)$storagePath);
    $url = '/rsvp/api.php?action=serve-reception-photo&file=' . rawurlencode($file);

    $expectedKey = trim((string)EnvironmentLoader::get('RECEPTION_API_KEY', ''));
    if ($expectedKey !== '') {
        $url .= '&key=' . rawurlencode($expectedKey);
    }

    return $url;
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

    $filePath = receptionResolvePhotoFilePath($file);

    if ($filePath) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? finfo_file($finfo, $filePath) : 'application/octet-stream';
        if ($finfo) finfo_close($finfo);

        if (ob_get_level()) {
            @ob_end_clean();
        }

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string)filesize($filePath));
        header('Cache-Control: public, max-age=86400');
        readfile($filePath);
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

function receptionParseIniBytes($value) {
    $value = trim((string)$value);
    if ($value === '') {
        return 0;
    }
    if (is_numeric($value)) {
        return (int)$value;
    }
    $unit = strtolower(substr($value, -1));
    $number = (float)substr($value, 0, -1);
    switch ($unit) {
        case 'g':
            return (int)round($number * 1024 * 1024 * 1024);
        case 'm':
            return (int)round($number * 1024 * 1024);
        case 'k':
            return (int)round($number * 1024);
        default:
            return (int)$number;
    }
}

function handleUploadReceptionPhoto($asAdmin = false) {
    @ini_set('memory_limit', '512M');
    $rateIp = '';
    $rateData = [];

    if ($asAdmin) {
        requireAdminAuth();
    } else {
        receptionRequireApiKey();
        [$rateIp, $rateData] = receptionAssertUploadRateLimit();
    }

    // Admin failover always stores locally. Do not enable the Google-replace path.
    $useGooglePhotos = $asAdmin ? false : receptionGooglePhotosEnabled();
    $safeName = 'photo.webp';
    $destPath = '';

    // When the request body exceeds post_max_size, PHP empties $_POST/$_FILES.
    $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    $postMax = receptionParseIniBytes(ini_get('post_max_size'));
    if ($contentLength > 0 && $postMax > 0 && $contentLength > $postMax && empty($_FILES)) {
        sendResponse([
            'success' => false,
            'error' => 'Photo is larger than the server post_max_size (' . ini_get('post_max_size') . '). Compress it or raise PHP limits.',
            'code' => 'post_too_large',
        ], 413);
    }

    if (!isset($_FILES['photo']) || !is_array($_FILES['photo'])) {
        sendResponse(['success' => false, 'error' => 'No photo uploaded'], 400);
    }

    $file = $_FILES['photo'];
    if (isset($file['tmp_name']) && is_array($file['tmp_name'])) {
        // PHP may provide multiple file upload arrays even if only one file is selected.
        $file = [
            'name' => $file['name'][0] ?? '',
            'type' => $file['type'][0] ?? '',
            'tmp_name' => $file['tmp_name'][0] ?? '',
            'error' => $file['error'][0] ?? UPLOAD_ERR_NO_FILE,
            'size' => $file['size'][0] ?? 0,
        ];
    }

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
    $mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : '';
    if ($finfo) {
        finfo_close($finfo);
    }

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
        sendResponse(['success' => false, 'error' => 'Only JPEG, PNG, WebP, HEIC, or HEIF images are allowed'], 400);
    }

    // Require a decodable image header — blocks polyglot/non-image files even if finfo mis-detects.
    $imageInfo = @getimagesize($file['tmp_name']);
    if ($imageInfo === false && !in_array($mime, ['image/heic', 'image/heif'], true)) {
        sendResponse(['success' => false, 'error' => 'Uploaded file is not a valid image'], 400);
    }

    $uploadsDir = receptionUploadsDir();
    $storagePath = '';
    $storedMime = $mime;
    $usedGooglePhotos = false;

    $canConvert = receptionCanConvertToWebp();

    if ($useGooglePhotos) {
        $originalName = basename((string)($file['name'] ?? 'photo'));
        $googleResult = receptionUploadToGooglePhotos($file['tmp_name'], $mime, $originalName);

        if ($googleResult && !empty($googleResult['id'])) {
            $storagePath = $googleResult['id'];
            $storedMime = $googleResult['mimeType'] ?? $mime;
            $usedGooglePhotos = true;

            $secLog = dirname(__DIR__) . '/logs/reception-upload-security.log';
            @file_put_contents($secLog, date('c') . " - upload_ok - " . json_encode(['google_item_id' => $storagePath]) . PHP_EOL, FILE_APPEND | LOCK_EX);
        } else {
            $secLog = dirname(__DIR__) . '/logs/reception-upload-security.log';
            @file_put_contents($secLog, date('c') . " - google_upload_failed - " . json_encode(['file' => basename((string)$file['name']), 'mime' => $mime]) . PHP_EOL, FILE_APPEND | LOCK_EX);

            if (!$canConvert) {
                sendResponse(['success' => false, 'error' => 'Google Photos upload failed and local storage is unavailable'], 500);
            }
        }
    }

    if (!$usedGooglePhotos) {
        if (!$canConvert) {
            sendResponse(['success' => false, 'error' => 'Image processing is not available on this server'], 500);
        }

        // Always re-encode to WebP so stored bytes are a fresh image, not a raw upload blob.
        $safeName = 'reception-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.webp';
        $destPath = $uploadsDir . DIRECTORY_SEPARATOR . $safeName;

        if (!receptionConvertToWebp($file['tmp_name'], $mime, $destPath)) {
            sendResponse(['success' => false, 'error' => 'Could not process uploaded image'], 400);
        }
        $storedMime = 'image/webp';

        @chmod($destPath, 0644);

        $clamCmd = trim((string)EnvironmentLoader::get('CLAMAV_SCAN_CMD', ''));
        if ($clamCmd !== '' && function_exists('exec')) {
            // Verify command exists first
            exec((PHP_SHLIB_SUFFIX === 'dll' ? 'where ' : 'command -v ') . escapeshellarg($clamCmd) . ' 2>&1', $clamCheckOut, $clamCheckCode);
            if ($clamCheckCode === 0) {
                $scanCmd = escapeshellcmd($clamCmd) . ' --no-summary ' . escapeshellarg($destPath) . ' 2>&1';
                exec($scanCmd, $scanOut, $scanCode);
                if ($scanCode !== 0) {
                    @unlink($destPath);
                    $secLog = dirname(__DIR__) . '/logs/reception-upload-security.log';
                    @file_put_contents($secLog, date('c') . " - scan_failed - " . json_encode(['file' => $destPath, 'cmd' => $scanCmd, 'out' => $scanOut, 'code' => $scanCode]) . PHP_EOL, FILE_APPEND | LOCK_EX);
                    sendResponse(['success' => false, 'error' => 'Uploaded file failed malware scan'], 400);
                }
            }
        }

        $secLog = dirname(__DIR__) . '/logs/reception-upload-security.log';
        @file_put_contents($secLog, date('c') . " - upload_ok - " . json_encode(['file' => $destPath]) . PHP_EOL, FILE_APPEND | LOCK_EX);

        $storagePath = $safeName;
    }

    $uploaderName = isset($_POST['uploader_name']) ? trim(sanitize($_POST['uploader_name'])) : null;
    if ($uploaderName === '') $uploaderName = null;
    $tableNumber = isset($_POST['table_number']) && is_numeric($_POST['table_number']) ? (int)$_POST['table_number'] : null;

    $db = Database::getInstance();
    $mysqli = $db->getConnection();
    receptionEnsurePhotosTable($mysqli);

    $stmt = $mysqli->prepare("
        INSERT INTO reception_photos (file_name, storage_path, mime_type, uploader_name, table_number)
        VALUES (?, ?, ?, ?, ?)
    ");
    $originalName = basename((string)($file['name'] ?? $safeName));
    $stmt->bind_param('ssssi', $originalName, $storagePath, $storedMime, $uploaderName, $tableNumber);

    if (!$stmt->execute()) {
        if (!empty($destPath) && is_file($destPath)) {
            @unlink($destPath);
        }
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

    if (!$asAdmin) {
        receptionRecordSuccessfulUpload($rateIp, $rateData);
    }

    sendResponse([
        'success' => true,
        'data' => [
            'id' => $id,
            'fileName' => $originalName,
            'url' => receptionPhotoPublicUrl($storagePath),
            'mimeType' => $storedMime,
            'uploaderName' => $uploaderName,
            'tableNumber' => $tableNumber,
            'likesCount' => 0,
            'uploadedAt' => date('c'),
            'useGooglePhotos' => $usedGooglePhotos,
            'failover' => $asAdmin,
        ],
    ]);
}

function handleLikeReceptionPhoto() {
    receptionRequireApiKey();

    $input = getRequestInput();
    $photoId = (int)($_POST['photo_id'] ?? $input['photo_id'] ?? $_GET['photo_id'] ?? 0);

    if ($photoId < 1) {
        sendResponse(['success' => false, 'error' => 'Invalid photo ID'], 400);
    }

    $db = Database::getInstance();
    $mysqli = $db->getConnection();
    receptionEnsurePhotosTable($mysqli);

    $stmt = $mysqli->prepare("UPDATE reception_photos SET likes_count = likes_count + 1 WHERE id = ?");
    $stmt->bind_param('i', $photoId);
    $stmt->execute();
    $stmt->close();

    $stmtGet = $mysqli->prepare("SELECT likes_count FROM reception_photos WHERE id = ?");
    $stmtGet->bind_param('i', $photoId);
    $stmtGet->execute();
    $res = $stmtGet->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmtGet->close();

    $likes = $row ? (int)$row['likes_count'] : 0;

    sendResponse([
        'success' => true,
        'data' => [
            'id' => $photoId,
            'likesCount' => $likes,
        ]
    ]);
}

function handleAdminGetReceptionPhotos() {
    requireAdminAuth();

    $db = Database::getInstance();
    $mysqli = $db->getConnection();
    receptionEnsurePhotosTable($mysqli);

    $photos = [];
    $result = $mysqli->query("
        SELECT id, file_name, storage_path, mime_type, uploader_name, table_number, likes_count, is_approved, uploaded_at
        FROM reception_photos
        ORDER BY uploaded_at DESC
    ");

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $photos[] = array_merge(receptionMapPhotoRow($row), [
                'isApproved' => (bool)$row['is_approved'],
            ]);
        }
    }

    sendResponse(['success' => true, 'data' => $photos]);
}

function handleAdminHideReceptionPhoto() {
    requireAdminAuth();

    $input = getRequestInput();
    $photoId = (int)($_POST['photo_id'] ?? $input['photo_id'] ?? $_GET['photo_id'] ?? 0);

    if ($photoId < 1) {
        sendResponse(['success' => false, 'error' => 'Invalid photo ID'], 400);
    }

    $db = Database::getInstance();
    $mysqli = $db->getConnection();
    receptionEnsurePhotosTable($mysqli);

    $stmt = $mysqli->prepare("UPDATE reception_photos SET is_approved = 0 WHERE id = ?");
    $stmt->bind_param('i', $photoId);
    $stmt->execute();
    $changed = (int)$mysqli->affected_rows > 0;
    $stmt->close();

    if (!$changed) {
        sendResponse(['success' => false, 'error' => 'Photo not found'], 404);
    }

    sendResponse(['success' => true, 'message' => 'Photo hidden from gallery']);
}

function handleAdminDeleteReceptionPhoto() {
    requireAdminAuth();

    $input = getRequestInput();
    $photoId = (int)($_POST['photo_id'] ?? $input['photo_id'] ?? $_GET['photo_id'] ?? 0);

    if ($photoId < 1) {
        sendResponse(['success' => false, 'error' => 'Invalid photo ID'], 400);
    }

    $db = Database::getInstance();
    $mysqli = $db->getConnection();
    receptionEnsurePhotosTable($mysqli);

    // Soft delete / hide by default or remove file
    $stmt = $mysqli->prepare("SELECT storage_path FROM reception_photos WHERE id = ?");
    $stmt->bind_param('i', $photoId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if ($row) {
        $storagePath = $row['storage_path'];
        $filePath = receptionResolvePhotoFilePath($storagePath);
        if ($filePath) {
            @unlink($filePath);
        }

        $delStmt = $mysqli->prepare("DELETE FROM reception_photos WHERE id = ?");
        $delStmt->bind_param('i', $photoId);
        $delStmt->execute();
        $delStmt->close();
    }

    sendResponse(['success' => true, 'message' => 'Photo deleted successfully']);
}

function handleAdminClearAllReceptionPhotos() {
    requireAdminAuth();

    $db = Database::getInstance();
    $mysqli = $db->getConnection();
    receptionEnsurePhotosTable($mysqli);

    $result = $mysqli->query("SELECT id, storage_path FROM reception_photos");
    if (!$result) {
        sendResponse(['success' => false, 'error' => 'Could not read photos'], 500);
    }

    $deletedFiles = 0;
    while ($row = $result->fetch_assoc()) {
        $filePath = receptionResolvePhotoFilePath($row['storage_path'] ?? '');
        if ($filePath && @unlink($filePath)) {
            $deletedFiles++;
        }
    }

    $mysqli->query("DELETE FROM reception_photos");
    $deletedRows = (int)$mysqli->affected_rows;

    // Clearing the gallery for testing should also clear the hourly upload counter.
    receptionClearUploadRateData();

    sendResponse([
        'success' => true,
        'message' => 'All guest POV photos cleared.',
        'data' => [
            'deletedRows' => $deletedRows,
            'deletedFiles' => $deletedFiles,
        ],
    ]);
}

function handleAdminDownloadPhotosZip() {
    requireAdminAuth();

    $db = Database::getInstance();
    $mysqli = $db->getConnection();
    receptionEnsurePhotosTable($mysqli);

    $result = $mysqli->query("SELECT storage_path, file_name FROM reception_photos ORDER BY id ASC");
    if (!$result || $result->num_rows === 0) {
        sendResponse(['success' => false, 'error' => 'No photos available to download'], 404);
    }

    if (class_exists('ZipArchive')) {
        $zipFileName = 'wedding-pov-photos-' . date('Ymd-His') . '.zip';
        $zipPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $zipFileName;

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            sendResponse(['success' => false, 'error' => 'Could not create ZIP archive'], 500);
        }

        $count = 0;
        while ($row = $result->fetch_assoc()) {
            $fullPath = receptionResolvePhotoFilePath($row['storage_path']);
            if ($fullPath) {
                $count++;
                $file = basename($row['storage_path']);
                $origExt = pathinfo($file, PATHINFO_EXTENSION);
                $entryName = sprintf('POV_%03d_%s', $count, !empty($row['file_name']) ? basename($row['file_name']) : 'photo.' . $origExt);
                $zip->addFile($fullPath, $entryName);
            }
        }
        $zip->close();

        if ($count === 0 || !is_file($zipPath)) {
            sendResponse(['success' => false, 'error' => 'No photo files found on disk to zip'], 404);
        }

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $zipFileName . '"');
        header('Content-Length: ' . filesize($zipPath));
        readfile($zipPath);
        @unlink($zipPath);
        exit;
    } else {
        sendResponse(['success' => false, 'error' => 'PHP ZipArchive extension is not enabled on server'], 500);
    }
}

function receptionFloorPlanPath() {
    return __DIR__ . '/../reception/data/floor-plan.json';
}

function receptionDefaultFloorPlan() {
    return [
        'legend' => [
            ['id' => 'stage', 'label' => 'Stage'],
            ['id' => 'entrance', 'label' => 'Entrance'],
            ['id' => 'bar', 'label' => 'Mobile Bar'],
            ['id' => 'buffet', 'label' => 'Buffet'],
            ['id' => 'video360', 'label' => '360 Video'],
        ],
        'tables' => [
            ['number' => 1, 'left' => 41, 'top' => 73.5, 'kind' => 'round', 'label' => '1'],
            ['number' => 2, 'left' => 42, 'top' => 34.7, 'kind' => 'round', 'label' => '2'],
            ['number' => 3, 'left' => 47.9, 'top' => 63.8, 'kind' => 'round', 'label' => '3'],
            ['number' => 4, 'left' => 47.9, 'top' => 44.4, 'kind' => 'round', 'label' => '4'],
            ['number' => 5, 'left' => 52.7, 'top' => 73.5, 'kind' => 'round', 'label' => '5'],
            ['number' => 6, 'left' => 54.7, 'top' => 35.4, 'kind' => 'round', 'label' => '6'],
            ['number' => 7, 'left' => 58.6, 'top' => 63.8, 'kind' => 'round', 'label' => '7'],
            ['number' => 8, 'left' => 61.5, 'top' => 44.4, 'kind' => 'round', 'label' => '8'],
            ['number' => 9, 'left' => 64.5, 'top' => 73.5, 'kind' => 'round', 'label' => '9'],
            ['number' => 10, 'left' => 67.4, 'top' => 35.4, 'kind' => 'round', 'label' => '10'],
            ['number' => 11, 'left' => 70.3, 'top' => 63.8, 'kind' => 'round', 'label' => '11'],
            ['number' => 12, 'left' => 72.3, 'top' => 44.4, 'kind' => 'round', 'label' => '12'],
            ['number' => 13, 'left' => 76.2, 'top' => 73.5, 'kind' => 'round', 'label' => '13'],
            ['number' => 14, 'left' => 79.1, 'top' => 35.4, 'kind' => 'round', 'label' => '14'],
            ['number' => 15, 'left' => 28.3, 'top' => 65.2, 'kind' => 'vip', 'label' => 'VIP 1'],
            ['number' => 16, 'left' => 28.3, 'top' => 43, 'kind' => 'vip', 'label' => 'VIP 2'],
        ],
        'markers' => [
            'stage' => ['left' => 2, 'top' => 42, 'width' => 10, 'height' => 22, 'label' => 'Stage'],
            'entrance' => ['left' => 42, 'top' => 88, 'width' => 16, 'height' => 6, 'label' => 'Entrance'],
            'bar' => ['left' => 52, 'top' => 86, 'width' => 16, 'height' => 6, 'label' => 'Mobile Bar'],
            'buffet' => ['left' => 52, 'top' => 16, 'width' => 20, 'height' => 6, 'label' => 'Buffet'],
            'video360' => ['left' => 78, 'top' => 70, 'width' => 8, 'height' => 10, 'label' => '360 Video'],
        ],
    ];
}

function receptionClampPercent($value, $min = 0, $max = 100) {
    $number = is_numeric($value) ? (float)$value : $min;
    if ($number < $min) {
        return $min;
    }
    if ($number > $max) {
        return $max;
    }
    return round($number, 2);
}

function receptionNormalizeFloorPlan($raw) {
    $defaults = receptionDefaultFloorPlan();
    $plan = is_array($raw) ? $raw : [];
    $tables = [];
    $seen = [];
    $sourceTables = isset($plan['tables']) && is_array($plan['tables']) ? $plan['tables'] : $defaults['tables'];

    foreach ($sourceTables as $table) {
        if (!is_array($table)) {
            continue;
        }
        $number = (int)($table['number'] ?? 0);
        if ($number < 1 || $number > 40 || isset($seen[$number])) {
            continue;
        }
        $seen[$number] = true;
        $kind = strtolower(trim((string)($table['kind'] ?? 'round')));
        if ($kind !== 'vip') {
            $kind = 'round';
        }
        $label = trim((string)($table['label'] ?? ''));
        if ($label === '') {
            $label = $kind === 'vip' ? ('VIP ' . $number) : (string)$number;
        }
        $tables[] = [
            'number' => $number,
            'left' => receptionClampPercent($table['left'] ?? 50, 4, 96),
            'top' => receptionClampPercent($table['top'] ?? 50, 8, 94),
            'kind' => $kind,
            'label' => substr($label, 0, 24),
        ];
        if (count($tables) >= 40) {
            break;
        }
    }

    usort($tables, static function ($a, $b) {
        return $a['number'] <=> $b['number'];
    });
    if (!$tables) {
        $tables = $defaults['tables'];
    }

    $markers = [];
    $sourceMarkers = isset($plan['markers']) && is_array($plan['markers']) ? $plan['markers'] : [];
    $markerIds = array_unique(array_merge(array_keys($defaults['markers']), array_keys($sourceMarkers)));
    foreach ($markerIds as $rawId) {
        $id = strtolower(preg_replace('/[^a-z0-9_-]/', '', (string)$rawId));
        if ($id === '') {
            continue;
        }
        $defaultMarker = isset($defaults['markers'][$id]) && is_array($defaults['markers'][$id])
            ? $defaults['markers'][$id]
            : ['left' => 40, 'top' => 40, 'width' => 12, 'height' => 8, 'label' => $id];
        $marker = isset($sourceMarkers[$rawId]) && is_array($sourceMarkers[$rawId])
            ? $sourceMarkers[$rawId]
            : (isset($sourceMarkers[$id]) && is_array($sourceMarkers[$id]) ? $sourceMarkers[$id] : $defaultMarker);
        $label = trim((string)($marker['label'] ?? $defaultMarker['label']));
        if ($label === '') {
            $label = $defaultMarker['label'];
        }
        $markers[$id] = [
            'left' => receptionClampPercent($marker['left'] ?? $defaultMarker['left'], 0, 96),
            'top' => receptionClampPercent($marker['top'] ?? $defaultMarker['top'], 0, 96),
            'width' => receptionClampPercent($marker['width'] ?? $defaultMarker['width'], 4, 80),
            'height' => receptionClampPercent($marker['height'] ?? $defaultMarker['height'], 3, 60),
            'label' => substr($label, 0, 32),
        ];
        if (count($markers) >= 20) {
            break;
        }
    }

    $legend = [];
    foreach ($markers as $id => $marker) {
        $legend[] = ['id' => $id, 'label' => $marker['label']];
    }

    return [
        'legend' => $legend,
        'tables' => $tables,
        'markers' => $markers,
    ];
}

function receptionReadFloorPlan() {
    $path = receptionFloorPlanPath();
    if (!is_file($path)) {
        return receptionDefaultFloorPlan();
    }
    $decoded = json_decode((string)file_get_contents($path), true);
    return receptionNormalizeFloorPlan($decoded);
}

function handleGetFloorPlan() {
    receptionRequireApiKey();
    header('Cache-Control: no-store');
    sendResponse([
        'success' => true,
        'data' => receptionReadFloorPlan(),
    ]);
}

function handleAdminGetFloorPlan() {
    requireAdminAuth();
    sendResponse([
        'success' => true,
        'data' => receptionReadFloorPlan(),
    ]);
}

function handleAdminSaveFloorPlan() {
    requireAdminAuth();
    $input = getRequestInput();
    $plan = receptionNormalizeFloorPlan($input['plan'] ?? $input);
    $path = receptionFloorPlanPath();
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        sendResponse(['success' => false, 'error' => 'Could not create floor plan folder.'], 500);
    }
    $json = json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false || @file_put_contents($path, $json) === false) {
        sendResponse(['success' => false, 'error' => 'Could not save the floor plan. Check that reception/data is writable.'], 500);
    }
    sendResponse([
        'success' => true,
        'data' => $plan,
    ]);
}

function receptionMenuPath() {
    return __DIR__ . '/../reception/data/menu.json';
}

function receptionDefaultMenu() {
    return [
        'sections' => [
            [
                'id' => 'appetizers',
                'title' => 'Appetizers',
                'items' => [
                    ['name' => 'Garden Greens with Champagne Vinaigrette', 'description' => 'Seasonal leaves, edible flowers, and toasted almonds', 'tags' => ['V', 'GF'], 'recommended' => true],
                    ['name' => 'Caprese Skewers', 'description' => 'Fresh mozzarella, heirloom tomato, basil oil', 'tags' => ['GF'], 'recommended' => false],
                    ['name' => 'Mini Crab Cakes', 'description' => 'Lemon aioli and micro herbs', 'tags' => [], 'recommended' => false],
                ],
            ],
            [
                'id' => 'mains',
                'title' => 'Main Course',
                'items' => [
                    ['name' => 'Herb-Crusted Beef Tenderloin', 'description' => 'Roasted garlic jus, truffle potato puree, broccolini', 'tags' => ['GF'], 'recommended' => true],
                    ['name' => 'Pan-Seared Salmon', 'description' => 'Citrus beurre blanc, asparagus, saffron rice', 'tags' => ['GF'], 'recommended' => false],
                    ['name' => 'Wild Mushroom Risotto', 'description' => 'Parmesan crisp, aged balsamic', 'tags' => ['V', 'GF'], 'recommended' => false],
                ],
            ],
            [
                'id' => 'desserts',
                'title' => 'Desserts',
                'items' => [
                    ['name' => 'Wedding Cake', 'description' => 'Vanilla bean sponge, berry compote', 'tags' => ['N'], 'recommended' => true],
                    ['name' => 'Chocolate Mousse', 'description' => 'Gold leaf and fresh raspberries', 'tags' => ['GF'], 'recommended' => false],
                    ['name' => 'Seasonal Fruit Tart', 'description' => 'Pastry cream and mint', 'tags' => ['N'], 'recommended' => false],
                ],
            ],
            [
                'id' => 'drinks',
                'title' => 'Drinks',
                'items' => [
                    ['name' => 'Signature Cocktails', 'description' => 'Berber Bloom (gin) and Golden Hour (sparkling wine)', 'tags' => [], 'recommended' => false],
                    ['name' => 'Wine Selection', 'description' => 'White, red, and rosé — service with dinner', 'tags' => [], 'recommended' => false],
                    ['name' => 'Coffee & Tea', 'description' => 'Espresso, herbal tea, and chilled water', 'tags' => ['V', 'GF'], 'recommended' => false],
                ],
            ],
        ],
        'tagLegend' => [
            'V' => 'Vegan',
            'GF' => 'Gluten-Free',
            'N' => 'Contains Nuts',
        ],
    ];
}

function receptionMenuSlug($title, array &$used) {
    $base = strtolower(trim((string)$title));
    $base = preg_replace('/[^a-z0-9]+/', '-', $base);
    $base = trim((string)$base, '-');
    if ($base === '') {
        $base = 'course';
    }
    $base = substr($base, 0, 32);
    $id = $base;
    $n = 2;
    while (isset($used[$id])) {
        $id = substr($base, 0, 28) . '-' . $n;
        $n += 1;
    }
    $used[$id] = true;
    return $id;
}

function receptionNormalizeMenu($raw) {
    $defaults = receptionDefaultMenu();
    $plan = is_array($raw) ? $raw : [];

    $tagLegend = [];
    $sourceLegend = isset($plan['tagLegend']) && is_array($plan['tagLegend']) ? $plan['tagLegend'] : $defaults['tagLegend'];
    foreach ($sourceLegend as $code => $label) {
        $key = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', (string)$code));
        $key = substr($key, 0, 6);
        if ($key === '' || isset($tagLegend[$key])) {
            continue;
        }
        $text = trim((string)$label);
        if ($text === '') {
            $text = $key;
        }
        $tagLegend[$key] = substr($text, 0, 32);
        if (count($tagLegend) >= 12) {
            break;
        }
    }
    if (!$tagLegend) {
        $tagLegend = $defaults['tagLegend'];
    }

    $sections = [];
    $usedIds = [];
    $sourceSections = isset($plan['sections']) && is_array($plan['sections']) ? $plan['sections'] : $defaults['sections'];
    foreach ($sourceSections as $section) {
        if (!is_array($section)) {
            continue;
        }
        $title = trim((string)($section['title'] ?? ''));
        if ($title === '') {
            $title = 'Course';
        }
        $title = substr($title, 0, 48);
        $idSource = trim((string)($section['id'] ?? ''));
        $id = $idSource !== '' ? receptionMenuSlug($idSource, $usedIds) : receptionMenuSlug($title, $usedIds);

        $items = [];
        $sourceItems = isset($section['items']) && is_array($section['items']) ? $section['items'] : [];
        foreach ($sourceItems as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = trim((string)($item['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $tags = [];
            if (isset($item['tags']) && is_array($item['tags'])) {
                foreach ($item['tags'] as $tag) {
                    $tagKey = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', (string)$tag));
                    if ($tagKey !== '' && isset($tagLegend[$tagKey]) && !in_array($tagKey, $tags, true)) {
                        $tags[] = $tagKey;
                    }
                }
            }
            $items[] = [
                'name' => substr($name, 0, 96),
                'description' => substr(trim((string)($item['description'] ?? '')), 0, 280),
                'tags' => $tags,
                'recommended' => !empty($item['recommended']),
            ];
            if (count($items) >= 20) {
                break;
            }
        }
        if (!$items) {
            continue;
        }
        $sections[] = [
            'id' => $id,
            'title' => $title,
            'items' => $items,
        ];
        if (count($sections) >= 12) {
            break;
        }
    }
    if (!$sections) {
        return $defaults;
    }

    return [
        'sections' => $sections,
        'tagLegend' => $tagLegend,
    ];
}

function receptionReadMenu() {
    $path = receptionMenuPath();
    if (!is_file($path)) {
        return receptionDefaultMenu();
    }
    $decoded = json_decode((string)file_get_contents($path), true);
    return receptionNormalizeMenu($decoded);
}

function handleGetMenu() {
    receptionRequireApiKey();
    header('Cache-Control: no-store');
    sendResponse([
        'success' => true,
        'data' => receptionReadMenu(),
    ]);
}

function handleAdminGetMenu() {
    requireAdminAuth();
    sendResponse([
        'success' => true,
        'data' => receptionReadMenu(),
    ]);
}

function handleAdminSaveMenu() {
    requireAdminAuth();
    $input = getRequestInput();
    $menu = receptionNormalizeMenu($input['menu'] ?? $input);
    $path = receptionMenuPath();
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        sendResponse(['success' => false, 'error' => 'Could not create menu folder.'], 500);
    }
    $json = json_encode($menu, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || @file_put_contents($path, $json) === false) {
        sendResponse(['success' => false, 'error' => 'Could not save the menu. Check that reception/data is writable.'], 500);
    }
    sendResponse([
        'success' => true,
        'data' => $menu,
    ]);
}
