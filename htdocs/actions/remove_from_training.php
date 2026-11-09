<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/Attendance.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../public/trainings.php');
    exit;
}

/* Roster management is staff-only. requirePermission() only rejects false, and
 * attendance.write = 'own' for a Member is not false, so check the scope. */
if (!in_array(permissionValue('attendance', 'write'), [true, 'own_trainees'], true)) {
    $_SESSION['error'] = 'You do not have permission to modify this session roster.';
    header('Location: ../public/trainings.php');
    exit;
}

/* The token is mandatory. It used to be checked only when present, so a forged
 * cross-site POST that simply omitted csrf_token skipped the check entirely. */
$submittedToken = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : null;
if (!arnisVerifyCsrfToken($submittedToken)) {
    $_SESSION['error'] = 'Invalid security token. Please refresh and try again.';
    header('Location: ../public/trainings.php');
    exit;
}

$trainingId = isset($_POST['training_id']) && is_numeric($_POST['training_id']) ? (int)$_POST['training_id'] : 0;
$memberId = isset($_POST['member_id']) && is_numeric($_POST['member_id']) ? (int)$_POST['member_id'] : 0;

$fallback = '../public/trainings.php';
$sessionUrl = $trainingId > 0 ? '../public/attendance_session.php?id=' . $trainingId : $fallback;

if ($trainingId <= 0 || $memberId <= 0) {
    $_SESSION['error'] = 'Missing required fields.';
    header('Location: ' . $sessionUrl);
    exit;
}

try {
    $db = (new Database())->connect();
    $attendanceModel = new Attendance($db);

    $removed = $attendanceModel->removeFromTraining($trainingId, $memberId, (int)$_SESSION['user_id'], 'Removed from training');

    if ($removed) {
        $_SESSION['success'] = 'Member successfully removed from attendance.';
    } else {
        $_SESSION['error'] = 'Member was not found in this session.';
    }
} catch (Throwable $e) {
    error_log('remove_from_training failed: ' . $e->getMessage());
    $_SESSION['error'] = 'An error occurred. Please try again.';
}

header('Location: ' . $sessionUrl);
exit;