<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/Attendance.php';
require_once __DIR__ . '/../classes/AuditLog.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

/**
 * The redirect target is always rebuilt from validated input.
 *
 * The old code did header("Location: " . $_SERVER['HTTP_REFERER']) on both
 * error paths, which is an open redirect: an attacker-hosted page can POST here
 * with its own Referer and bounce the user off-site.
 */
function attendanceRedirect(int $trainingId, int $page = 1): string
{
    return '../public/attendance_session.php?id=' . $trainingId
        . '&page=' . max(1, $page);
}

function attendanceFail(string $message, int $trainingId = 0, int $page = 1): void
{
    $_SESSION['error'] = $message;
    header('Location: ' . ($trainingId > 0 ? attendanceRedirect($trainingId, $page) : '../public/trainings.php'));
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    attendanceFail('Method not allowed.');
}

/*
 * Roster editing (marking Present/Late/Absent on any member) is staff-only.
 * requirePermission() alone is not enough: attendance.write = 'own' for a
 * Member is not false, so it would pass. Only full and own_trainees scopes may
 * proceed; Members self check-in via process_attendance_checkin.php instead.
 */
if (!in_array(permissionValue('attendance', 'write'), [true, 'own_trainees'], true)) {
    attendanceFail('You do not have permission to update attendance.');
}

if (!isset($_POST['csrf_token']) || !arnisVerifyCsrfToken((string)$_POST['csrf_token'])) {
    attendanceFail('Invalid security token. Please reload the page and try again.');
}

$trainingId = (isset($_POST['training_id']) && is_numeric($_POST['training_id'])) ? (int)$_POST['training_id'] : 0;
$memberId = (isset($_POST['member_id']) && is_numeric($_POST['member_id'])) ? (int)$_POST['member_id'] : 0;
$page = (isset($_POST['page']) && is_numeric($_POST['page'])) ? max(1, (int)$_POST['page']) : 1;

if ($trainingId <= 0) {
    attendanceFail('Invalid training session.');
}

$status = isset($_POST['status']) && is_string($_POST['status']) ? trim($_POST['status']) : '';
if ($memberId <= 0) {
    attendanceFail('Invalid member.', $trainingId, $page);
}

/* ENUM whitelist - previously the value was interpolated into the SQL string. */
if (!in_array($status, ['Present', 'Late', 'Absent'], true)) {
    attendanceFail('Invalid attendance status.', $trainingId, $page);
}

$remarks = (isset($_POST['remarks']) && is_string($_POST['remarks'])) ? trim($_POST['remarks']) : null;
if ($remarks !== null) {
    if (mb_strlen($remarks) > 255) {
        attendanceFail('Remarks must not exceed 255 characters.', $trainingId, $page);
    }
    $remarks = $remarks !== '' ? $remarks : null;
}

try {
    $db = (new Database())->connect();
    $attendanceModel = new Attendance($db);

    /* The session must exist and not be voided. */
    $sessionCheck = $db->prepare(
        'SELECT training_id, coach_id
         FROM trainings
         WHERE training_id = :id AND is_voided = 0
         LIMIT 1'
    );
    $sessionCheck->execute([':id' => $trainingId]);
    $session = $sessionCheck->fetch(PDO::FETCH_ASSOC);
    if (!is_array($session)) {
        attendanceFail('Training session not found, or it has been voided.', $trainingId, $page);
    }

    /*
     * Scoped staff (Head Coach / Coach) may only edit attendance for their own
     * sessions. The old code checked the permission value but never resolved it
     * against the training's coach_id, so any coach could edit any roster by
     * changing training_id in the POST body.
     */
    if (permissionValue('attendance', 'write') !== true) {
        $allowed = canPerformAction('attendance', 'write', [
            'training_id' => $trainingId,
            'coach_id' => (int)($session['coach_id'] ?? 0),
        ]);
        if (!$allowed) {
            attendanceFail('You can only update attendance for your own training sessions.', $trainingId, $page);
        }
    }

    /*
     * The roster row must exist and not be voided, otherwise the UPDATE
     * silently matches zero rows and the user is told "Failed to update".
     */
    $rosterCheck = $db->prepare("
        SELECT a.attendance_id, a.status AS previous_status, m.full_name
        FROM attendance a
        LEFT JOIN members m ON m.member_id = a.member_id
        WHERE a.training_id = :training_id
          AND a.member_id = :member_id
          AND a.is_voided = 0
        LIMIT 1
    ");
    $rosterCheck->execute([':training_id' => $trainingId, ':member_id' => $memberId]);
    $roster = $rosterCheck->fetch(PDO::FETCH_ASSOC);
    if (!is_array($roster)) {
        attendanceFail('That member is not on this session roster.', $trainingId, $page);
    }

    if (!$attendanceModel->updateByTrainingAndMember($trainingId, $memberId, $status, $remarks, (int)$_SESSION['user_id'])) {
        attendanceFail('Failed to update attendance. Please try again.', $trainingId, $page);
    }

    $memberName = trim((string)($roster['full_name'] ?? '')) ?: ('member #' . $memberId);
    $previousStatus = (string)($roster['previous_status'] ?? '');
    AuditLog::record(
        $db,
        'update',
        'attendance',
        (int)($roster['attendance_id'] ?? 0) ?: null,
        'Marked ' . $memberName . ' as ' . $status
            . ($previousStatus !== '' && $previousStatus !== $status ? ' (was ' . $previousStatus . ')' : '')
            . ' for training #' . $trainingId,
        [
            'training_id' => $trainingId,
            'member_id' => $memberId,
            'previous_status' => $previousStatus,
            'new_status' => $status,
        ]
    );

    $_SESSION['success'] = 'Attendance updated successfully!';
} catch (PDOException $e) {
    error_log('update_attendance DB error: ' . $e->getMessage());
    attendanceFail('Failed to update attendance. Please try again.', $trainingId, $page);
} catch (Throwable $e) {
    error_log('update_attendance error: ' . $e->getMessage());
    attendanceFail('Failed to update attendance. Please try again.', $trainingId, $page);
}

header('Location: ' . attendanceRedirect($trainingId, $page));
exit;
