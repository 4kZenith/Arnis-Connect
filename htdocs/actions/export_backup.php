<?php
declare(strict_types=1);

/*
 * FULL SYSTEM BACKUP DOWNLOAD — SuperAdmin only.
 * Dumps all 10 data tables to a JSON file the browser downloads.
 * Keep this file: it is what "Restore" feeds back into import_backup.php.
 * Pure PDO (no mysqldump — unavailable on InfinityFree).
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/PrivateTrainings.php';

const ARNIS_BACKUP_TABLES = [
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
    'private_training_requests',
];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Invalid request method.');
}

if (!arnisVerifyCsrfToken($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid security token.');
}

if (!hasRole('SuperAdmin')) {
    http_response_code(403);
    exit('Access denied.');
}

try {
    $db = (new Database())->connect();
    PrivateTrainings::ensureSchema($db);

    $payload = [
        'app' => 'arnis-connect',
        'version' => 1,
        'exported_at' => date('Y-m-d H:i:s'),
        'exported_by' => (int)($_SESSION['user_id'] ?? 0),
        'tables' => [],
    ];

    foreach (ARNIS_BACKUP_TABLES as $table) {
        $rows = $db->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
        $payload['tables'][$table] = $rows ?: [];
    }

    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        throw new RuntimeException('Backup encoding failed.');
    }

    $filename = 'arnis-backup-' . date('Ymd-His') . '.json';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($json));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store, max-age=0');
    echo $json;
    exit;
} catch (Throwable $e) {
    error_log('export_backup error: ' . $e->getMessage());
    http_response_code(500);
    exit('Backup failed. Please try again.');
}