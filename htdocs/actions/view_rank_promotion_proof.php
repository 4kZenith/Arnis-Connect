<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/RankPromotions.php';

function promoProofDeny(int $code, string $message): void
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

$requestId = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;
if ($requestId <= 0) {
    promoProofDeny(400, 'Invalid request.');
}

try {
    $db = (new Database())->connect();
    $row = (new RankPromotions($db))->find($requestId);
} catch (Throwable $e) {
    error_log('view_rank_promotion_proof error: ' . $e->getMessage());
    promoProofDeny(500, 'Could not load the certificate.');
}

if ($row === null) {
    promoProofDeny(404, 'Certificate not found.');
}

$sessionMemberId = resolveSessionMemberId($db);
$isOwner = $sessionMemberId > 0 && $sessionMemberId === (int)$row['member_id'];
$writeScope = permissionValue('members', 'write');
$canReview = in_array($writeScope, [true, 'own_trainees'], true)
    && ($writeScope === true || canPerformAction('members', 'write', ['member_id' => (int)$row['member_id']]));

if (!$isOwner && !$canReview) {
    promoProofDeny(403, 'Access denied.');
}

$file = RankPromotions::proofFile((string)($row['proof_path'] ?? ''));
if ($file === null) {
    promoProofDeny(404, 'No certificate was uploaded for this request.');
}

$mime = function_exists('mime_content_type') ? (string)@mime_content_type($file) : 'application/octet-stream';
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true)) {
    $mime = 'application/octet-stream';
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($file));
header('Content-Disposition: inline; filename="' . basename($file) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
readfile($file);
exit;
