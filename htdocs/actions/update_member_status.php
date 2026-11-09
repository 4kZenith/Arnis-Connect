<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/Members.php';
require_once __DIR__ . '/../classes/AuditLog.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function statusError(int $status, string $message): void {
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    statusError(405, 'Method not allowed');
}

$input = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE) statusError(400, 'Invalid JSON');

if (!isset($input['csrf_token']) || !arnisVerifyCsrfToken((string)$input['csrf_token'])) {
    statusError(403, 'Invalid security token.');
}

$memberId = (isset($input['member_id']) && is_numeric($input['member_id'])) ? (int)$input['member_id'] : 0;
if ($memberId <= 0) statusError(400, 'Invalid member ID');

$newStatus = isset($input['status']) && is_string($input['status']) ? trim($input['status']) : '';
if (!in_array($newStatus, ['Active', 'Inactive', 'On Leave'], true)) {
    statusError(400, 'Invalid status value');
}

/*
 * Status is a staff-controlled field. Member/Applicant hold members.write =
 * 'own', which hasPermission() reports as granted, so the old check let a
 * member change their own status (and reactivate an inactive account).
 */
$sessionMemberId = isset($_SESSION['member_id']) && $_SESSION['member_id'] !== null
    ? (int)$_SESSION['member_id']
    : 0;
if ($sessionMemberId > 0 && $sessionMemberId === $memberId) {
    statusError(403, 'You cannot change your own membership status.');
}

$writeScope = permissionValue('members', 'write');
if (!in_array($writeScope, [true, 'own_trainees'], true)) {
    statusError(403, 'Permission denied.');
}
if ($writeScope === 'own_trainees' && !canPerformAction('members', 'write', ['member_id' => $memberId])) {
    statusError(403, 'Permission denied.');
}

try {
    $db = (new Database())->connect();
    $model = new Members($db);
    $existing = $model->getById($memberId);
    if (!$existing) statusError(404, 'Member not found');

    $result = $model->update($memberId, ['status' => $newStatus], (int)$_SESSION['user_id']);
    if (!$result['success']) statusError(500, $result['error'] ?? 'Failed');

    $verify = $model->getById($memberId);
    if (!$verify || (string)($verify['status'] ?? '') !== $newStatus) {
        statusError(500, 'Failed to verify.');
    }

    AuditLog::record(
        $db,
        'update',
        'members',
        $memberId,
        'Changed member status to ' . $newStatus,
        ['old_status' => (string)($existing['status'] ?? ''), 'new_status' => $newStatus]
    );

    echo json_encode(['success' => true, 'message' => 'Status updated.', 'status' => $newStatus], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    error_log('update_member_status: ' . $e->getMessage());
    statusError(500, 'Database error.');
} catch (Throwable $e) {
    error_log('update_member_status: ' . $e->getMessage());
    statusError(500, 'Server error.');
}