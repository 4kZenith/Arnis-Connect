<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/AuditLog.php';
require_once __DIR__ . '/../classes/Members.php';
require_once __DIR__ . '/../classes/RankPromotions.php';

function rpRedirect(string $type, string $message, int $memberId): void
{
    $_SESSION['rank_promotion_flash'] = ['type' => $type, 'message' => $message];
    $back = (string)($_POST['return_to'] ?? '');
    if ($back === 'members') {
        header('Location: ../public/members_management.php');
    } else {
        header('Location: ../public/member_profile.php?id=' . $memberId);
    }
    exit;
}

function rpStoreProof(int $memberId): ?string
{
    if (!isset($_FILES['proof']) || !is_array($_FILES['proof'])) {
        return null;
    }
    $file = $_FILES['proof'];
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        throw new RuntimeException('That file is too large. Use a photo or PDF under 5 MB.');
    }
    if ($error !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || $file['tmp_name'] === '') {
        throw new RuntimeException('The certificate could not be uploaded. Please try again.');
    }
    if ((int)$file['size'] <= 0 || (int)$file['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('That file is too large. Use a photo or PDF under 5 MB.');
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : false;
    if ($finfo) {
        finfo_close($finfo);
    }
    $exts = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];
    if (!is_string($mime) || !isset($exts[$mime])) {
        throw new RuntimeException('Upload a JPG, PNG, WEBP, or PDF certificate.');
    }
    if ($mime !== 'application/pdf') {
        $info = @getimagesize($file['tmp_name']);
        if ($info === false) {
            throw new RuntimeException('That file is not a readable photo.');
        }
    }

    $dir = RankPromotions::proofDirectory();
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('The certificate could not be saved. Please try again.');
    }
    $filename = 'promo_' . $memberId . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $exts[$mime];
    $dest = $dir . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new RuntimeException('The certificate could not be saved. Please try again.');
    }
    return 'uploads/rank_promotions/' . $filename;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Invalid request method.');
}
arnisRequireCsrf(false);

$role = currentUserRole() ?? '';
$userId = (int)($_SESSION['user_id'] ?? 0);
$op = (string)($_POST['op'] ?? '');
$memberId = (int)($_POST['member_id'] ?? 0);

try {
    $db = (new Database())->connect();
    $promos = new RankPromotions($db);
    $members = new Members($db);
} catch (Throwable $e) {
    error_log('process_rank_promotion DB error: ' . $e->getMessage());
    rpRedirect('error', 'Promotion requests are temporarily unavailable.', $memberId);
}

$selfMemberId = resolveSessionMemberId($db);
$writeScope = permissionValue('members', 'write');
$canReview = in_array($writeScope, [true, 'own_trainees'], true);

try {
    if ($op === 'request') {
        if ($role !== 'Member' || $selfMemberId <= 0 || $selfMemberId !== $memberId) {
            rpRedirect('error', 'You can only request a promotion for your own profile.', $memberId);
        }
        $member = $members->getById($memberId);
        if (!$member || (int)($member['is_voided'] ?? 0) === 1 || (string)($member['status'] ?? '') !== 'Active') {
            rpRedirect('error', 'Only an active member can request a promotion.', $memberId);
        }
        $current = (string)($member['rank'] ?? 'None');
        $next = Members::nextRankAfter($current);
        if ($next === null) {
            rpRedirect('error', 'You are already at the highest rank.', $memberId);
        }
        $message = mb_substr(trim((string)($_POST['message'] ?? '')), 0, 500);
        $proof = rpStoreProof($memberId);
        if ($message === '' && $proof === null) {
            rpRedirect('error', 'Write a short note or upload a certificate.', $memberId);
        }
        try {
            $id = $promos->submit($memberId, $userId, $current, $next, $message, $proof);
        } catch (Throwable $e) {
            if ($proof !== null) {
                RankPromotions::unlinkProof($proof);
            }
            throw $e;
        }
        AuditLog::record($db, 'create', 'members', $memberId, (string)$member['full_name'] . ' requested promotion to ' . $next);
        rpRedirect('success', 'Promotion request sent. A coach will approve or decline it.', $id > 0 ? $memberId : $memberId);
    }

    $requestId = (int)($_POST['request_id'] ?? 0);
    $request = $requestId > 0 ? $promos->find($requestId) : null;
    if ($request === null) {
        rpRedirect('error', 'Promotion request not found.', $memberId);
    }
    $memberId = (int)$request['member_id'];
    $mayReviewThis = $canReview && (
        $writeScope === true
        || canPerformAction('members', 'write', ['member_id' => $memberId])
    );

    if ($op === 'cancel') {
        if ($selfMemberId !== $memberId) {
            rpRedirect('error', 'You can only cancel your own request.', $memberId);
        }
        $promos->cancel($requestId, $memberId);
        AuditLog::record($db, 'status_change', 'members', $memberId, 'Cancelled promotion request');
        rpRedirect('success', 'Promotion request cancelled.', $memberId);
    }

    if (!$mayReviewThis || $selfMemberId === $memberId) {
        rpRedirect('error', 'You cannot review this promotion request.', $memberId);
    }

    if ($op === 'decline') {
        $note = mb_substr(trim((string)($_POST['review_note'] ?? '')), 0, 255);
        $promos->decline($requestId, $userId, $note);
        AuditLog::record($db, 'reject', 'members', $memberId, 'Declined promotion request: ' . $note);
        rpRedirect('success', 'Promotion request declined.', $memberId);
    }

    if ($op === 'approve') {
        $member = $members->getById($memberId);
        if (!$member) {
            rpRedirect('error', 'Member not found.', $memberId);
        }
        $current = (string)($member['rank'] ?? 'None');
        $next = Members::nextRankAfter($current);
        if ($next === null || $next !== (string)$request['requested_rank']) {
            rpRedirect('error', 'This member’s rank changed. Decline this request and ask them to send a new one.', $memberId);
        }
        $result = $members->update($memberId, ['rank' => $next], $userId);
        if (!($result['success'] ?? false)) {
            rpRedirect('error', (string)($result['error'] ?? 'The rank could not be updated.'), $memberId);
        }
        $note = trim((string)($request['message'] ?? ''));
        $members->logRankChange($memberId, $current, $next, $userId, $note !== '' ? $note : 'Approved from the member’s promotion request');
        $promos->markApproved($requestId, $userId, 'Approved');
        AuditLog::record(
            $db,
            'promote',
            'members',
            $memberId,
            'Approved promotion for ' . (string)$member['full_name'] . ' from ' . $current . ' to ' . $next,
            ['old_rank' => $current, 'new_rank' => $next]
        );
        rpRedirect('success', 'Approved. The member is now ' . $next . '.', $memberId);
    }
} catch (Throwable $e) {
    error_log('process_rank_promotion error: ' . $e->getMessage());
    $safe = $e instanceof RuntimeException && !$e instanceof PDOException;
    rpRedirect('error', $safe ? $e->getMessage() : 'The promotion request could not be saved.', $memberId);
}

rpRedirect('error', 'Unknown action.', $memberId);
