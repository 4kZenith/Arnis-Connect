<?php
declare(strict_types=1);

/*
 * RESTORE FROM BACKUP FILE — SuperAdmin only.
 * Accepts the JSON file produced by export_backup.php, validates its full
 * structure FIRST, then wipes all tables and re-inserts every row
 * (ids preserved, so member/training/event links survive).
 * 20 MB upload cap. Always download a fresh backup before restoring.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';

const ARNIS_RESTORE_TABLES = [
    'users',
    'members',
    'rank_history',
    'rank_verification_history',
    'registration_requests',
    'trainings',
    'attendance',
    'attendance_checkins',
    'events',
    'event_participants',
    'event_documents',
    'achievements',
    'funds',
];

/* Tables that may be absent from older backup files without failing restore. */
const ARNIS_RESTORE_OPTIONAL_TABLES = [
    'rank_history',
    'rank_verification_history',
    'attendance_checkins',
];

const ARNIS_RESTORE_MAX_BYTES = 20971520; // 20 MB

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

if (!arnisVerifyCsrfToken($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
    exit;
}

if (!hasRole('SuperAdmin')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit;
}

try {
    if (!isset($_FILES['backup_file']) || !is_array($_FILES['backup_file'])
        || ($_FILES['backup_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No backup file uploaded.']);
        exit;
    }

    $tmpPath = (string)$_FILES['backup_file']['tmp_name'];
    $size = (int)$_FILES['backup_file']['size'];
    if ($size <= 0 || $size > ARNIS_RESTORE_MAX_BYTES) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Backup file must be 1 byte to 20 MB.']);
        exit;
    }

    $raw = file_get_contents($tmpPath);
    if ($raw === false || $raw === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Could not read the uploaded file.']);
        exit;
    }

    $data = json_decode($raw, true);
    if (!is_array($data) || ($data['app'] ?? '') !== 'arnis-connect'
        || !isset($data['tables']) || !is_array($data['tables'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Not a valid Arnis backup file.']);
        exit;
    }

    /* Validate everything BEFORE touching the database.
     * The optional tables are allowed to be missing so backups taken before
     * they existed still load. */
    foreach (ARNIS_RESTORE_TABLES as $table) {
        if (!array_key_exists($table, $data['tables'])) {
            if (in_array($table, ARNIS_RESTORE_OPTIONAL_TABLES, true)) {
                $data['tables'][$table] = [];
                continue;
            }
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Backup is missing table '{$table}'."]);
            exit;
        }
        if (!is_array($data['tables'][$table])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Backup table '{$table}' is corrupt."]);
            exit;
        }
        foreach ($data['tables'][$table] as $row) {
            if (!is_array($row)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => "Backup table '{$table}' is corrupt."]);
                exit;
            }
        }
    }

    $db = (new Database())->connect();
    $db->exec('SET FOREIGN_KEY_CHECKS = 0');

    try {
        /* Wipe first (TRUNCATE also resets AUTO_INCREMENT for clean ids). */
        foreach (ARNIS_RESTORE_TABLES as $table) {
            $db->exec("TRUNCATE TABLE `{$table}`");
        }

        $restored = [];
        foreach (ARNIS_RESTORE_TABLES as $table) {
            $rows = $data['tables'][$table];
            $count = 0;
            foreach ($rows as $row) {
                if (empty($row)) {
                    continue;
                }
                $columns = array_keys($row);
                $placeholders = array_map(static fn(string $c): string => ':' . $c, $columns);
                $sql = 'INSERT INTO `' . $table . '` (`'
                    . implode('`, `', $columns) . '`) VALUES ('
                    . implode(', ', $placeholders) . ')';
                $stmt = $db->prepare($sql);
                foreach ($row as $col => $value) {
                    $stmt->bindValue(':' . $col, $value);
                }
                $stmt->execute();
                $count++;
            }
            $restored[$table] = $count;
        }
    } finally {
        $db->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /* Your own session row may have been replaced — refresh it. */
    $refresh = $db->prepare('SELECT session_version, member_id FROM users WHERE user_id = :id LIMIT 1');
    $refresh->execute([':id' => (int)($_SESSION['user_id'] ?? 0)]);
    $me = $refresh->fetch(PDO::FETCH_ASSOC);
    if ($me) {
        $_SESSION['session_version'] = (int)$me['session_version'];
        $_SESSION['member_id'] = $me['member_id'] !== null ? (int)$me['member_id'] : null;
    }

    $total = array_sum($restored);
    echo json_encode([
        'success' => true,
        'message' => "Restore complete: {$total} records across " . count($restored) . ' tables.',
        'tables' => $restored,
    ]);
} catch (Throwable $e) {
    error_log('import_backup error: ' . $e->getMessage());
    try {
        (new Database())->connect()->exec('SET FOREIGN_KEY_CHECKS = 1');
    } catch (Throwable $ignored) {
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Restore failed. Your data may be incomplete — restore again from a good backup.']);
}