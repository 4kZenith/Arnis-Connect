<?php
declare(strict_types=1);

/*
 * INLINE RANK-EVIDENCE VIEWER.
 *
 * Streams the certificate attached to a registration request so reviewers can
 * open it from Members Management (button) and the applicant can re-open the
 * file they uploaded from the dashboard.
 *
 * Access: any signed-in reviewer who can modify members (SuperAdmin, Officer,
 * Coach/Trainer) OR the applicant that owns the request.
 *
 * Safety: the file is resolved by request_id (never a raw client path). The
 * stored path may be either "uploads/rank_evidence/xxx" or a legacy relative
 * form such as "../uploads/rank_evidence/xxx"; both are normalised and the
 * final real path must stay inside uploads/rank_evidence.
 */

require_once __DIR__ . '/../includes/auth.php';      // requires a valid login
require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';

function evidenceDeny(int $code, string $message): void
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

$requestId = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;
if ($requestId <= 0) {
    evidenceDeny(400, 'Invalid request.');
}

try {
    $db = (new Database())->connect();
    $stmt = $db->prepare(
        'SELECT request_id, user_id, rank_evidence_path
         FROM registration_requests WHERE request_id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $requestId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('view_rank_evidence lookup error: ' . $e->getMessage());
    evidenceDeny(500, 'Could not load the document.');
}

if (!$row) {
    evidenceDeny(404, 'Document not found.');
}

$sessionUserId = (int)($_SESSION['user_id'] ?? 0);
$isOwner = (int)($row['user_id'] ?? 0) > 0 && (int)$row['user_id'] === $sessionUserId;
$canReview = hasRole('SuperAdmin') || hasRole('Officer') || hasRole('Coach/Trainer');

if (!$isOwner && !$canReview) {
    evidenceDeny(403, 'Access denied.');
}

$storedPath = trim((string)($row['rank_evidence_path'] ?? ''));
if ($storedPath === '') {
    evidenceDeny(404, 'No document was uploaded for this request.');
}

$uploadRoot = realpath(__DIR__ . '/../uploads/rank_evidence');
if ($uploadRoot === false || !is_dir($uploadRoot)) {
    evidenceDeny(404, 'No document was uploaded for this request.');
}

// Normalise the stored path to a filename relative to uploads/rank_evidence.
$normalized = str_replace('\\', '/', $storedPath);
$normalized = preg_replace('#^(?:\.\./)+#', '', $normalized) ?? $normalized;
$normalized = preg_replace('#^uploads/rank_evidence/#i', '', $normalized) ?? $normalized;
$normalized = ltrim($normalized, '/');

if ($normalized === '' || strpos($normalized, '..') !== false) {
    evidenceDeny(404, 'Document not found.');
}

$candidate = realpath($uploadRoot . DIRECTORY_SEPARATOR . $normalized);
$rootPrefix = rtrim($uploadRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
if ($candidate === false
    || strpos($candidate, $rootPrefix) !== 0
    || !is_file($candidate)) {
    evidenceDeny(404, 'Document not found.');
}

$mime = function_exists('mime_content_type') ? (string)@mime_content_type($candidate) : 'application/octet-stream';
$allow = ['application/pdf', 'image/jpeg', 'image/png'];
if (!in_array($mime, $allow, true)) {
    $mime = 'application/octet-stream';
}

$size = (int)@filesize($candidate);
header('Content-Type: ' . $mime);
header('Content-Length: ' . $size);
header('Content-Disposition: inline; filename="' . basename($candidate) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');

readfile($candidate);
exit;
