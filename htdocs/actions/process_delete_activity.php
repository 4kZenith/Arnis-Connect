<?php
declare(strict_types=1);

/*
 * Removes rows from the activity list only.
 * The member, training, payment, or other record described by the line is left as it is.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/AuditLog.php';

function activityDeleteRedirect(string $type, string $message): void
{
    $_SESSION['activity_delete_flash'] = ['type' => $type, 'message' => $message];
    $back = (string)($_POST['return_to'] ?? '');
    if ($back === 'activity_log') {
        header('Location: ../public/activity_log.php');
    } else {
        header('Location: ../public/dashboard.php');
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Invalid request method.');
}

if (!arnisVerifyCsrfToken(isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : null)) {
    activityDeleteRedirect('error', 'Invalid security token. Refresh the page and try again.');
}

if (!hasRole('SuperAdmin')) {
    http_response_code(403);
    activityDeleteRedirect('error', 'Only the SuperAdmin can clear activity lines.');
}

$ids = [];
if (isset($_POST['only_id']) && is_numeric($_POST['only_id'])) {
    $one = (int)$_POST['only_id'];
    if ($one > 0) {
        $ids[] = $one;
    }
} elseif (isset($_POST['audit_ids']) && is_array($_POST['audit_ids'])) {
    foreach ($_POST['audit_ids'] as $raw) {
        if (is_numeric($raw) && (int)$raw > 0) {
            $ids[] = (int)$raw;
        }
    }
}
$ids = array_values(array_unique($ids));
if ($ids === []) {
    activityDeleteRedirect('error', 'Select at least one activity line.');
}
if (count($ids) > 100) {
    activityDeleteRedirect('error', 'Select up to 100 lines at a time.');
}

try {
    $db = (new Database())->connect();
    AuditLog::ensureSchema($db);
    $placeholders = [];
    $params = [];
    foreach ($ids as $i => $id) {
        $key = ':id' . $i;
        $placeholders[] = $key;
        $params[$key] = $id;
    }
    $stmt = $db->prepare('DELETE FROM audit_logs WHERE audit_id IN (' . implode(', ', $placeholders) . ')');
    $stmt->execute($params);
    $removed = $stmt->rowCount();
} catch (Throwable $e) {
    error_log('process_delete_activity error: ' . $e->getMessage());
    activityDeleteRedirect('error', 'Those activity lines could not be removed. Please try again.');
}

if ($removed < 1) {
    activityDeleteRedirect('error', 'Those activity lines were already gone.');
}

activityDeleteRedirect(
    'success',
    $removed === 1
        ? 'Removed 1 activity line. Nothing else was changed.'
        : 'Removed ' . $removed . ' activity lines. Nothing else was changed.'
);
