<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

function redirectBack(string $type, string $msg): void {
    $ref = $_SERVER['HTTP_REFERER'] ?? '../public/dashboard.php';
    header('Location: ' . $ref . (strpos($ref, '?') === false ? '?' : '&') . 'type=' . urlencode($type) . '&msg=' . urlencode($msg));
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirectBack('error', 'Invalid request');
}

if (!isset($_POST['csrf_token']) || !arnisVerifyCsrfToken((string)$_POST['csrf_token'])) {
    redirectBack('error', 'Invalid security token');
}

// Only Applicant or Member (if allowed) - but mainly Applicant with pending request
$role = currentUserRole();
if ($role !== 'Applicant') {
    redirectBack('error', 'Only applicants can upload rank evidence');
}

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    redirectBack('error', 'Not logged in');
}

if (!isset($_FILES['rank_evidence']) || $_FILES['rank_evidence']['error'] !== UPLOAD_ERR_OK) {
    redirectBack('error', 'No file uploaded or upload failed');
}

$file = $_FILES['rank_evidence'];
$allowed = [
    'application/pdf' => 'pdf',
    'image/jpeg' => 'jpg',
    'image/pjpeg' => 'jpg',
    'image/png' => 'png',
];
$max = 5 * 1024 * 1024;
if ($file['size'] > $max) {
    redirectBack('error', 'File too large (max 5MB)');
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);
if (!isset($allowed[$mime])) {
    redirectBack('error', 'Invalid file type. Only PDF/JPG/PNG allowed');
}

$ext = $allowed[$mime];
$uploadDir = __DIR__ . '/../uploads/rank_evidence/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}
$filename = 'rank_' . $userId . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$dest = $uploadDir . $filename;
if (!move_uploaded_file($file['tmp_name'], $dest)) {
    redirectBack('error', 'Failed to save file');
}

try {
    $db = (new Database())->connect();
    // Update latest pending registration request for this user
    // (registration_requests has no updated_at column).
    $stmt = $db->prepare("UPDATE registration_requests SET rank_evidence_path = :path WHERE user_id = :uid AND status = 'Pending' ORDER BY submitted_at DESC LIMIT 1");
    $stmt->execute([':path' => 'uploads/rank_evidence/' . $filename, ':uid' => $userId]);
    redirectBack('success', 'Rank evidence uploaded successfully');
} catch (Throwable $e) {
    @unlink($dest);
    redirectBack('error', 'Database error');
}