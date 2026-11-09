<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/Attendance.php';
require_once __DIR__ . '/../classes/Members.php';
require_once __DIR__ . '/../classes/Trainings.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error'] = 'Invalid request.';
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
$rawIds = $_POST['member_ids'] ?? [];

if ($trainingId <= 0) {
    $_SESSION['error'] = 'Invalid training session.';
    header('Location: ../public/trainings.php');
    exit;
}

if (!is_array($rawIds) || empty($rawIds)) {
    $_SESSION['error'] = 'No members selected.';
    header('Location: ../public/attendance_session.php?id=' . $trainingId);
    exit;
}

$memberIds = array_values(array_unique(array_map('intval', $rawIds)));
$memberIds = array_filter($memberIds, static fn(int $id): bool => $id > 0);

if (empty($memberIds)) {
    $_SESSION['error'] = 'No members selected.';
    header('Location: ../public/attendance_session.php?id=' . $trainingId);
    exit;
}

try {
    $db = (new Database())->connect();
    $trainingsModel = new Trainings($db);
    $membersModel = new Members($db);
    $attendance = new Attendance($db);

    $training = $trainingsModel->getById($trainingId);
    if (!$training) {
        $_SESSION['error'] = 'Training session not found.';
        header('Location: ../public/trainings.php');
        exit;
    }

    $targetGroup = (string)($training['target_group'] ?? 'Mixed');

    $validMemberIds = [];
    foreach ($memberIds as $memberId) {
        $member = $membersModel->getById($memberId);
        if (!$member || ($member['status'] ?? '') !== 'Active') {
            continue;
        }
        $category = (string)($member['player_category'] ?? 'Public');
        $eligible = $targetGroup === 'Mixed'
            || ($targetGroup === 'Public' && in_array($category, ['Public', 'Mixed'], true))
            || ($targetGroup === 'Private' && in_array($category, ['Private', 'Mixed'], true));
        if ($eligible) {
            $validMemberIds[] = $memberId;
        }
    }

    if (empty($validMemberIds)) {
        $_SESSION['error'] = 'Selected members are invalid, inactive, or not eligible for this session.';
        header('Location: ../public/attendance_session.php?id=' . $trainingId);
        exit;
    }

    $added = $attendance->addMultipleMembers($trainingId, $validMemberIds, (int)$_SESSION['user_id']);

    if ($added) {
        $_SESSION['success'] = count($validMemberIds) . ' member(s) successfully added to training.';
    } else {
        $_SESSION['error'] = 'Failed to add members. They may already be in this session.';
    }
} catch (Throwable $e) {
    error_log('add_to_training failed: ' . $e->getMessage());
    $_SESSION['error'] = 'Failed to add members. Please try again.';
}

header('Location: ../public/attendance_session.php?id=' . $trainingId);
exit;