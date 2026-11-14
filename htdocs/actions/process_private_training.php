<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/AuditLog.php';
require_once __DIR__ . '/../classes/Mailer.php';
require_once __DIR__ . '/../classes/PrivateTrainings.php';

function ptRedirect(string $type, string $message): void
{
    $_SESSION['private_training_flash'] = ['type' => $type, 'message' => $message];
    header('Location: ../public/private_trainings.php');
    exit;
}

function ptNotify(PDO $db, ?string $email, string $name, string $subject, string $message): void
{
    if ($email === null || $email === '') {
        return;
    }
    try {
        (new Mailer($db))->sendNotice($email, $name, $subject, $message);
    } catch (Throwable $e) {
        error_log('private training notice failed: ' . $e->getMessage());
    }
}

function ptPeso(float $amount): string
{
    return 'PHP ' . number_format($amount, 2);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Invalid request method.');
}
arnisRequireCsrf(false);

$role = currentUserRole() ?? '';
$userId = (int)($_SESSION['user_id'] ?? 0);
$op = (string)($_POST['op'] ?? '');
$todayManila = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');

try {
    $db = (new Database())->connect();
    $pt = new PrivateTrainings($db);
} catch (Throwable $e) {
    error_log('process_private_training DB error: ' . $e->getMessage());
    ptRedirect('error', 'Private trainings are temporarily unavailable.');
}

$isAdminReviewer = in_array($role, ['SuperAdmin', 'Head Coach'], true);
$coachMemberId = in_array($role, ['Head Coach', 'Coach/Trainer'], true) ? (int)(getCurrentCoachMemberId($db) ?? 0) : 0;
$selfMemberId = $role === 'Member' ? resolveSessionMemberId($db) : 0;
$canRecordPayment = permissionValue('funds', 'write') === true;

$validTime = static fn(string $t): bool => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t) === 1;
$validDate = static function (string $d): bool {
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $d);
    return $dt !== false && $dt->format('Y-m-d') === $d;
};

if ($op === 'request') {
    if ($role !== 'Member' || !$pt->memberCanRequest($selfMemberId)) {
        ptRedirect('error', 'Only active Private or Mixed players can request private training.');
    }
    if ($pt->openCount($selfMemberId) >= PrivateTrainings::MAX_OPEN_PER_MEMBER) {
        ptRedirect('error', 'You already have ' . PrivateTrainings::MAX_OPEN_PER_MEMBER . ' open requests. Wait for a reply or cancel one first.');
    }
    $date = trim((string)($_POST['preferred_date'] ?? ''));
    $time = trim((string)($_POST['preferred_time'] ?? ''));
    $coachRaw = (int)($_POST['preferred_coach_id'] ?? 0);
    $notes = mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 500);

    if (!$validDate($date) || $date < $todayManila) {
        ptRedirect('error', 'Choose a preferred date from today onwards.');
    }
    if ($time !== '' && !$validTime($time)) {
        ptRedirect('error', 'Preferred time is invalid.');
    }
    if ($coachRaw > 0 && !$pt->isCoach($coachRaw)) {
        ptRedirect('error', 'The selected coach is not available.');
    }

    $id = $pt->create($selfMemberId, $userId, $date, $time !== '' ? $time : null, $coachRaw > 0 ? $coachRaw : null, $notes);
    AuditLog::record($db, 'create', 'private_trainings', $id, 'Requested private training for ' . $date);
    ptRedirect('success', 'Request sent. A coach will review it and confirm the schedule.');
}

$requestId = (int)($_POST['request_id'] ?? 0);
$request = $requestId > 0 ? $pt->find($requestId) : null;
if ($request === null) {
    ptRedirect('error', 'Request not found.');
}
$status = (string)$request['status'];
$isOwner = $role === 'Member' && $selfMemberId > 0 && (int)$request['member_id'] === $selfMemberId;
$coachMayReview = $coachMemberId > 0 && (
    (int)($request['coach_id'] ?? 0) === $coachMemberId
    || ($status === 'Pending' && in_array((int)($request['preferred_coach_id'] ?? 0), [0, $coachMemberId], true))
);
$canReview = $isAdminReviewer || $coachMayReview;
$memberEmail = (string)($request['member_email'] ?? '');
$memberName = (string)$request['member_name'];

try {
    switch ($op) {
        case 'approve':
            if (!$canReview || $status !== 'Pending') {
                ptRedirect('error', 'You cannot approve this request.');
            }
            $coachId = $isAdminReviewer ? (int)($_POST['coach_id'] ?? 0) : $coachMemberId;
            $date = trim((string)($_POST['session_date'] ?? ''));
            $start = trim((string)($_POST['start_time'] ?? ''));
            $end = trim((string)($_POST['end_time'] ?? ''));
            $location = mb_substr(trim((string)($_POST['location'] ?? '')), 0, 255);
            $feeRaw = trim((string)($_POST['fee_amount'] ?? ''));
            $note = mb_substr(trim((string)($_POST['decision_note'] ?? '')), 0, 255);

            if ($coachId <= 0 || !$pt->isCoach($coachId)) {
                ptRedirect('error', 'Select the coach who will handle the session.');
            }
            if (!$validDate($date) || $date < $todayManila) {
                ptRedirect('error', 'Session date must be today or later.');
            }
            if (!$validTime($start) || !$validTime($end) || $end <= $start) {
                ptRedirect('error', 'Enter a start time and an end time after it.');
            }
            if ($location === '') {
                ptRedirect('error', 'Location is required.');
            }
            if (!is_numeric($feeRaw) || (float)$feeRaw < 0 || (float)$feeRaw > 100000) {
                ptRedirect('error', 'Fee must be between 0 and 100,000.');
            }
            $fee = round((float)$feeRaw, 2);

            $newStatus = $pt->approve($request, $userId, $coachId, $date, $start, $end, $location, $fee, $note);
            AuditLog::record($db, 'approve', 'private_trainings', $requestId, "Approved private training for {$memberName} on {$date} at " . ptPeso($fee) . " ({$newStatus})");

            $when = date('M j, Y', strtotime($date)) . ' ' . date('g:i A', strtotime($start)) . ' to ' . date('g:i A', strtotime($end));
            if ($newStatus === 'Confirmed') {
                ptNotify($db, $memberEmail, $memberName, 'Private training confirmed',
                    "Your private training is confirmed for {$when} at {$location}. Fee: " . ptPeso($fee) . '.');
                ptRedirect('success', 'Approved and scheduled. The session now appears in Trainings.');
            }
            ptNotify($db, $memberEmail, $memberName, 'Private training: please confirm the fee',
                "A coach can train you on {$when} at {$location} for " . ptPeso($fee) . '. Open Private Trainings to accept or decline.');
            ptRedirect('success', 'Sent to the member to accept the ' . ptPeso($fee) . ' fee.');

        case 'reject':
            if (!$canReview || $status !== 'Pending') {
                ptRedirect('error', 'You cannot reject this request.');
            }
            $note = mb_substr(trim((string)($_POST['decision_note'] ?? '')), 0, 255);
            if ($note === '') {
                ptRedirect('error', 'Give the member a short reason.');
            }
            $pt->close($request, 'Rejected', $userId, $note);
            AuditLog::record($db, 'reject', 'private_trainings', $requestId, "Rejected private training for {$memberName}: {$note}");
            ptNotify($db, $memberEmail, $memberName, 'Private training request declined', "Your private training request was not approved. Reason: {$note}");
            ptRedirect('success', 'Request rejected.');

        case 'accept':
            if (!$isOwner || $status !== 'Awaiting Member') {
                ptRedirect('error', 'This request is not waiting for your answer.');
            }
            if ((string)$request['session_date'] < $todayManila) {
                $pt->close($request, 'Cancelled', $userId, 'Offer expired before it was accepted');
                ptRedirect('error', 'That session date has passed. Please send a new request.');
            }
            $pt->memberAccept($requestId, $userId);
            AuditLog::record($db, 'approve', 'private_trainings', $requestId, 'Member accepted private training fee ' . ptPeso((float)$request['fee_amount']));
            $coachEmail = $db->prepare('SELECT email, full_name FROM members WHERE member_id = :id');
            $coachEmail->execute([':id' => (int)$request['coach_id']]);
            $coach = $coachEmail->fetch(PDO::FETCH_ASSOC) ?: [];
            ptNotify($db, (string)($coach['email'] ?? ''), (string)($coach['full_name'] ?? 'Coach'), 'Private training confirmed',
                "{$memberName} accepted the private training on {$request['session_date']}.");
            ptRedirect('success', 'Confirmed. The session is now in your Trainings list.');

        case 'decline':
            if (!$isOwner || $status !== 'Awaiting Member') {
                ptRedirect('error', 'This request is not waiting for your answer.');
            }
            $pt->close($request, 'Declined', $userId, '');
            AuditLog::record($db, 'reject', 'private_trainings', $requestId, 'Member declined the private training fee');
            ptRedirect('success', 'Offer declined. You can send a new request any time.');

        case 'cancel':
            $memberMayCancel = $isOwner && in_array($status, PrivateTrainings::OPEN_STATUSES, true);
            $staffMayCancel = $canReview && (in_array($status, PrivateTrainings::OPEN_STATUSES, true)
                || ($status === 'Confirmed' && (string)$request['session_date'] >= $todayManila));
            if (!$memberMayCancel && !$staffMayCancel) {
                ptRedirect('error', 'This request can no longer be cancelled.');
            }
            if ($request['payment_status'] === 'Paid') {
                ptRedirect('error', 'This session is already paid. Settle the refund first, then void the Funds entry.');
            }
            $note = mb_substr(trim((string)($_POST['decision_note'] ?? '')), 0, 255);
            $pt->close($request, 'Cancelled', $userId, $note);
            AuditLog::record($db, 'status_change', 'private_trainings', $requestId, "Cancelled private training for {$memberName}" . ($note !== '' ? ": {$note}" : ''));
            if (!$isOwner) {
                ptNotify($db, $memberEmail, $memberName, 'Private training cancelled',
                    'Your private training was cancelled.' . ($note !== '' ? " Reason: {$note}" : ''));
            }
            ptRedirect('success', 'Request cancelled.');

        case 'mark_paid':
            if (!$canRecordPayment) {
                ptRedirect('error', 'Only the treasurer or administrator can record payments.');
            }
            if ($status !== 'Confirmed' || $request['payment_status'] !== 'Unpaid' || (float)$request['fee_amount'] <= 0) {
                ptRedirect('error', 'There is no unpaid fee on this session.');
            }
            $fundId = $pt->markPaid($request, $userId);
            AuditLog::record($db, 'create', 'funds', $fundId, 'Recorded private training fee ' . ptPeso((float)$request['fee_amount']) . " from {$memberName}");
            ptRedirect('success', 'Payment recorded and added to Funds as income.');
    }
} catch (Throwable $e) {
    error_log('process_private_training error: ' . $e->getMessage());
    $safe = $e instanceof RuntimeException && !$e instanceof PDOException;
    ptRedirect('error', $safe ? $e->getMessage() : 'Something went wrong. Please try again.');
}

ptRedirect('error', 'Unknown action.');
