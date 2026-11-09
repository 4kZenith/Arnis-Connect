<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/Members.php';
require_once __DIR__ . '/../classes/AuditLog.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Content-Type: application/json; charset=UTF-8');

/** JSON responder: the errors carry no database internals. */
function profileImageError(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    profileImageError(405, 'Method not allowed');
}

/*
 * CRITICAL: this endpoint previously had no permission check at all, so any
 * logged-in Member or Applicant could POST member_id=1 and overwrite the club's
 * profile photos.
 */
requirePermission('members', 'write');

if (!isset($_POST['csrf_token']) || !arnisVerifyCsrfToken((string)$_POST['csrf_token'])) {
    profileImageError(403, 'Invalid security token. Please reload the page and try again.');
}

$memberId = (isset($_POST['member_id']) && is_numeric($_POST['member_id'])) ? (int)$_POST['member_id'] : 0;
if ($memberId <= 0) {
    profileImageError(400, 'Invalid member ID');
}

/* 'own' must be enforced, not just detected: hasPermission() only tests !== false. */
$writeScope = permissionValue('members', 'write');
if ($writeScope !== true) {
    // For 'own', 'own_trainees', 'limited' - use canPerformAction with ownerContext
    if (!canPerformAction('members', 'write', ['member_id' => $memberId])) {
        profileImageError(403, 'You do not have permission to change this member\'s profile picture.');
    }
}

if (!isset($_POST['cloudinary_url']) || !is_string($_POST['cloudinary_url'])) {
    profileImageError(400, 'Cloudinary URL is required.');
}

$cloudinaryUrl = trim($_POST['cloudinary_url']);
if ($cloudinaryUrl === '') {
    profileImageError(400, 'Cloudinary URL is required.');
}
if (strlen($cloudinaryUrl) > 500) {
    profileImageError(400, 'Cloudinary URL is too long.');
}
if (filter_var($cloudinaryUrl, FILTER_VALIDATE_URL) === false) {
    profileImageError(400, 'Invalid URL format');
}

/*
 * FILTER_VALIDATE_URL alone accepts http://, https://attacker.example/x.png and
 * any other host. This value is stored in members.profile_img, rendered as an
 * <img src>, and re-fetched server-side by actions/download_document.php, so the
 * host and scheme are both pinned.
 */
$parts = parse_url($cloudinaryUrl);
$scheme = strtolower((string)($parts['scheme'] ?? ''));
$host = strtolower((string)($parts['host'] ?? ''));

if ($scheme !== 'https') {
    profileImageError(400, 'Only https image URLs are accepted.');
}
if ($host !== 'res.cloudinary.com' && !str_ends_with($host, '.res.cloudinary.com')) {
    profileImageError(400, 'Only Cloudinary image URLs are accepted.');
}

try {
    $db = (new Database())->connect();
    $membersModel = new Members($db);

    $existing = $membersModel->getById($memberId);
    if (!$existing) {
        profileImageError(404, 'Member not found');
    }

    $membersModel->update($memberId, ['profile_img' => $cloudinaryUrl], (int)$_SESSION['user_id']);

    /*
     * Members::update() reports its outcome as an array, but MySQL also reports
     * 0 changed rows when the submitted URL already matches. Re-reading the row
     * is the only reliable confirmation, and it keeps this endpoint independent
     * of the model's exact return shape.
     */
    $verify = $membersModel->getById($memberId);
    if (!$verify || trim((string)($verify['profile_img'] ?? '')) !== $cloudinaryUrl) {
        profileImageError(500, 'Failed to save profile image.');
    }

    AuditLog::record(
        $db,
        'update',
        'members',
        $memberId,
        'Updated profile image for ' . (string)($existing['full_name'] ?? ('member #' . $memberId))
    );

    echo json_encode([
        'success' => true,
        'message' => 'Profile image updated successfully',
        'profile_img' => $cloudinaryUrl,
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    error_log('update_profile_image DB error: ' . $e->getMessage());
    profileImageError(500, 'Failed to update profile image.');
} catch (Throwable $e) {
    error_log('update_profile_image error: ' . $e->getMessage());
    profileImageError(500, 'Failed to update profile image.');
}