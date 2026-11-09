<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/AuditLog.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

function reviewRedirect(int $trainingId, int $page = 1): void
{
    header('Location: ../public/attendance_session.php?id=' . max(0, $trainingId) . '&page=' . max(1, $page));
    exit;
}

function reviewFail(string $message, int $trainingId = 0, int $page = 1): void
{
    $_SESSION['error'] = $message;
    reviewRedirect($trainingId, $page);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    reviewFail('Method not allowed.');
}

if (!arnisVerifyCsrfToken(isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : null)) {
    reviewFail('Invalid security token. Please reload the page and try again.');
}

$checkinId = isset($_POST['checkin_id']) && is_numeric($_POST['checkin_id']) ? (int)$_POST['checkin_id'] : 0;
$trainingId = isset($_POST['training_id']) && is_numeric($_POST['training_id']) ? (int)$_POST['training_id'] : 0;
$page = isset($_POST['page']) && is_numeric($_POST['page']) ? max(1, (int)$_POST['page']) : 1;
$decision = isset($_POST['decision']) && is_string($_POST['decision']) ? strtolower(trim($_POST['decision'])) : '';

if ($checkinId <= 0 || $trainingId <= 0) {
    reviewFail('Invalid check-in reference.');
}
if (!in_array($decision, ['clear', 'confirm', 'flag'], true)) {
    reviewFail('Invalid review decision.', $trainingId, $page);
}

if (!in_array(permissionValue('attendance', 'write'), [true, 'own_trainees'], true)) {
    reviewFail('You do not have permission to review check-ins.', $trainingId, $page);
}

try {
    $db = (new Database())->connect();

    $stmt = $db->prepare(
        'SELECT ac.checkin_id, ac.training_id, ac.member_id, ac.flagged, ac.flag_reason,
                t.coach_id
         FROM attendance_checkins ac
         INNER JOIN trainings t ON t.training_id = ac.training_id
         WHERE ac.checkin_id = :checkin_id
           AND ac.training_id = :training_id
           AND t.is_voided = 0
         LIMIT 1'
    );
    $stmt->execute([':checkin_id' => $checkinId, ':training_id' => $trainingId]);
    $checkin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!is_array($checkin)) {
        reviewFail('That check-in no longer exists.', $trainingId, $page);
    }

    /*
     * Full admins pass; coaches must own the training that produced the
     * check-in. canPerformAction resolves 'own_trainees' against coach_id.
     */
    $allowed = canPerformAction('attendance', 'write', [
        'training_id' => $trainingId,
        'coach_id' => (int)($checkin['coach_id'] ?? 0),
        'member_id' => (int)($checkin['member_id'] ?? 0),
    ]);
    if (!$allowed) {
        reviewFail('You can only review check-ins for your own training sessions.', $trainingId, $page);
    }

    $reviewer = trim((string)($_SESSION['full_name'] ?? 'Staff'));
    if ($reviewer === '') {
        $reviewer = 'Staff';
    }

    if ($decision === 'clear') {
        $newFlagged = 0;
        $newReason = null;
        $description = 'Cleared the flag on check-in #' . $checkinId . ' for member #' . (int)$checkin['member_id'];
    } elseif ($decision === 'confirm') {
        $existing = trim((string)($checkin['flag_reason'] ?? ''));
        $newFlagged = 1;
        $newReason = 'Confirmed by ' . $reviewer . ($existing !== '' ? ' — ' . $existing : '');
        $description = 'Confirmed the flag on check-in #' . $checkinId . ' for member #' . (int)$checkin['member_id'];
    } else {
        $newFlagged = 1;
        $newReason = 'Flagged by ' . $reviewer . ' for review';
        $description = 'Flagged check-in #' . $checkinId . ' for member #' . (int)$checkin['member_id'];
    }

    if (mb_strlen((string)$newReason) > 255) {
        $newReason = mb_substr((string)$newReason, 0, 255);
    }

    $update = $db->prepare(
        'UPDATE attendance_checkins
         SET flagged = :flagged, flag_reason = :flag_reason
         WHERE checkin_id = :checkin_id'
    );
    $update->execute([
        ':flagged' => $newFlagged,
        ':flag_reason' => $newReason,
        ':checkin_id' => $checkinId,
    ]);

    AuditLog::record(
        $db,
        'update',
        'attendance',
        $checkinId,
        $description,
        ['training_id' => $trainingId, 'decision' => $decision]
    );

    $_SESSION['success'] = $decision === 'clear'
        ? 'Check-in flag cleared.'
        : ($decision === 'confirm' ? 'Check-in flag confirmed.' : 'Check-in flagged for review.');
} catch (Throwable $e) {
    error_log('review_checkin.php error: ' . $e->getMessage());
    reviewFail('The check-in could not be updated. Please try again.', $trainingId, $page);
}

reviewRedirect($trainingId, $page);
