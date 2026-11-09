<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/Members.php';
require_once __DIR__ . '/../classes/AuditLog.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

/*
 * Belt progression is staff-controlled and strictly sequential:
 *   - SuperAdmin, Head Coach, and Coach (for their own trainees) may promote.
 *   - Members/Applicants can never promote, including themselves.
 *   - Only the NEXT rank in Members::RANK_ORDER is accepted, so a crafted
 *     POST cannot skip belts or jump straight to Grandmaster.
 */
$writeScope = permissionValue('members', 'write');
if (!in_array($writeScope, [true, 'own_trainees'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit;
}

arnisRequireCsrf(true);

$memberId = isset($_POST['member_id']) && is_numeric($_POST['member_id']) ? (int)$_POST['member_id'] : 0;
if ($memberId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid member ID.']);
    exit;
}

$sessionMemberId = isset($_SESSION['member_id']) && $_SESSION['member_id'] !== null
    ? (int)$_SESSION['member_id']
    : 0;
if ($sessionMemberId > 0 && $sessionMemberId === $memberId) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You cannot promote yourself.']);
    exit;
}

$notes = trim((string)($_POST['notes'] ?? ''));
if (mb_strlen($notes) > 255) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Promotion notes must not exceed 255 characters.']);
    exit;
}

try {
    $db = (new Database())->connect();
    $membersModel = new Members($db);

    $member = $membersModel->getById($memberId);
    if (!$member) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Member not found.']);
        exit;
    }

    if ($writeScope === 'own_trainees'
        && !canPerformAction('members', 'write', ['member_id' => $memberId])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'You can only promote your own trainees.']);
        exit;
    }

    $currentRank = (string)($member['rank'] ?? 'None');
    $nextRank = Members::nextRankAfter($currentRank);
    if ($nextRank === null) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'This member is already at the highest rank.',
        ]);
        exit;
    }

    // Optional explicit target must match the next rank exactly.
    $requestedRank = trim((string)($_POST['new_rank'] ?? ''));
    if ($requestedRank !== '' && $requestedRank !== $nextRank) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'Promotions must advance exactly one rank. Next rank: ' . $nextRank,
        ]);
        exit;
    }

    $result = $membersModel->update($memberId, ['rank' => $nextRank], (int)$_SESSION['user_id']);
    if (!($result['success'] ?? false)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $result['error'] ?? 'Failed to promote member.']);
        exit;
    }

    $membersModel->logRankChange(
        $memberId,
        $currentRank,
        $nextRank,
        (int)($_SESSION['user_id'] ?? 0),
        $notes !== '' ? $notes : null
    );

    AuditLog::record(
        $db,
        'promote',
        'members',
        $memberId,
        'Promoted ' . (string)($member['full_name'] ?? 'member') . ' from ' . $currentRank . ' to ' . $nextRank,
        ['old_rank' => $currentRank, 'new_rank' => $nextRank]
    );

    echo json_encode([
        'success' => true,
        'message' => 'Promoted to ' . $nextRank . '.',
        'old_rank' => $currentRank,
        'new_rank' => $nextRank,
    ]);
} catch (Throwable $e) {
    error_log('promote_member failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to promote member.']);
}
