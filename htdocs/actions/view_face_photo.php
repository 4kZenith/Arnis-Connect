<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/FaceEnrollments.php';

function facePhotoDeny(int $code, string $message): void
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

$memberId = isset($_GET['member_id']) && is_numeric($_GET['member_id']) ? (int)$_GET['member_id'] : 0;
$requestId = isset($_GET['request_id']) && is_numeric($_GET['request_id']) ? (int)$_GET['request_id'] : 0;
if (($memberId > 0 && $requestId > 0) || ($memberId <= 0 && $requestId <= 0)) {
    facePhotoDeny(400, 'Invalid photo request.');
}

try {
    $db = (new Database())->connect();
    $faces = new FaceEnrollments($db);
    $selfMemberId = function_exists('resolveSessionMemberId') ? resolveSessionMemberId($db) : 0;
    if ($requestId > 0) {
        $row = $faces->find($requestId);
        if ($row === null) {
            facePhotoDeny(404, 'Face photo not found.');
        }
        $ownerId = (int)$row['member_id'];
        $stored = (string)($row['photo_path'] ?? '');
    } else {
        $stmt = $db->prepare('SELECT member_id, face_photo FROM members WHERE member_id = :id AND is_voided = 0 LIMIT 1');
        $stmt->execute([':id' => $memberId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            facePhotoDeny(404, 'Face photo not found.');
        }
        $ownerId = (int)$row['member_id'];
        $stored = (string)($row['face_photo'] ?? '');
    }
} catch (Throwable $e) {
    error_log('view_face_photo error: ' . $e->getMessage());
    facePhotoDeny(500, 'Could not load the face photo.');
}

$isOwner = $selfMemberId > 0 && $selfMemberId === $ownerId;
if (!$isOwner && !FaceEnrollments::canReview()) {
    facePhotoDeny(403, 'Access denied.');
}

$file = FaceEnrollments::storedFile($stored);
if ($file === null) {
    facePhotoDeny(404, 'No face photo is stored for this record.');
}

$mime = function_exists('mime_content_type') ? (string)@mime_content_type($file) : 'application/octet-stream';
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
    facePhotoDeny(415, 'That file is not a photo.');
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($file));
header('Content-Disposition: inline; filename="face-photo"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
readfile($file);
exit;
