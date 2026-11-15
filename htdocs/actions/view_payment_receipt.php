<?php
declare(strict_types=1);

/*
 * Shows a private-training payment photo.
 * The picture is opened by request id, never by a path the browser sends.
 * The member who paid, the treasurer, the administrator, the head coach,
 * and the coach assigned to that session can open it.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/PrivateTrainings.php';

function receiptDeny(int $code, string $message): void
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

$requestId = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;
if ($requestId <= 0) {
    receiptDeny(400, 'Invalid request.');
}

try {
    $db = (new Database())->connect();
    $row = (new PrivateTrainings($db))->find($requestId);
} catch (Throwable $e) {
    error_log('view_payment_receipt lookup error: ' . $e->getMessage());
    receiptDeny(500, 'Could not load the receipt.');
}

if ($row === null) {
    receiptDeny(404, 'Receipt not found.');
}

$sessionMemberId = resolveSessionMemberId($db);
$isOwner = $sessionMemberId > 0 && $sessionMemberId === (int)$row['member_id'];
$canSeeMoney = permissionValue('funds', 'read') === true || hasRole('Head Coach');
$coachMemberId = hasRole('Coach/Trainer') ? (int)(getCurrentCoachMemberId($db) ?? 0) : 0;
$isAssignedCoach = $coachMemberId > 0 && $coachMemberId === (int)($row['coach_id'] ?? 0);

if (!$isOwner && !$canSeeMoney && !$isAssignedCoach) {
    receiptDeny(403, 'Access denied.');
}

$file = PrivateTrainings::receiptFile((string)($row['receipt_path'] ?? ''));
if ($file === null) {
    receiptDeny(404, 'No receipt photo was uploaded for this session.');
}

$mime = function_exists('mime_content_type') ? (string)@mime_content_type($file) : 'application/octet-stream';
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
    $mime = 'application/octet-stream';
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($file));
header('Content-Disposition: inline; filename="' . basename($file) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
readfile($file);
exit;
