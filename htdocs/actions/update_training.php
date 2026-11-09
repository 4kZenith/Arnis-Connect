<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/Trainings.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../public/trainings.php');
    exit;
}

requirePermission('trainings', 'write');

arnisRequireCsrf(false);

$trainingId = isset($_POST['training_id']) && is_numeric($_POST['training_id']) ? (int)$_POST['training_id'] : 0;
if ($trainingId <= 0) {
    $_SESSION['error'] = 'Training ID is required.';
    header('Location: ../public/trainings.php');
    exit;
}

$db = (new Database())->connect();
$trainingsModel = new Trainings($db);

$existing = $trainingsModel->getById($trainingId);
if (!$existing) {
    $_SESSION['error'] = 'Training session not found.';
    header('Location: ../public/trainings.php');
    exit;
}

$sessionUrl = '../public/attendance_session.php?id=' . $trainingId;

$validTypes = ['Regular', 'Private', 'Custom'];
$validGroups = ['Public', 'Private', 'Mixed'];
$validVenues = ['School', 'Private Venue', 'Custom'];

$data = [
    'training_date' => trim((string)($_POST['training_date'] ?? '')),
    'training_type' => (string)($_POST['training_type'] ?? 'Regular'),
    'target_group' => (string)($_POST['target_group'] ?? 'Mixed'),
    'venue_type' => (string)($_POST['venue_type'] ?? 'Custom'),
    'fee_amount' => $_POST['fee_amount'] ?? 0,
    'start_time' => trim((string)($_POST['start_time'] ?? '')),
    'end_time' => trim((string)($_POST['end_time'] ?? '')),
    'location' => trim((string)($_POST['location'] ?? '')),
    'description' => trim((string)($_POST['description'] ?? '')),
    'coach_id' => isset($_POST['coach_id']) && is_numeric($_POST['coach_id']) ? (int)$_POST['coach_id'] : 0,
    'updated_by' => (int)$_SESSION['user_id'],
];

if ($data['training_date'] === '' || $data['training_type'] === '' || $data['start_time'] === '' || $data['end_time'] === '' || $data['coach_id'] <= 0) {
    $_SESSION['error'] = 'Please fill in all required fields.';
    header('Location: ' . $sessionUrl);
    exit;
}

if (!in_array($data['training_type'], $validTypes, true)
    || !in_array($data['target_group'], $validGroups, true)
    || !in_array($data['venue_type'], $validVenues, true)) {
    $_SESSION['error'] = 'Invalid training details selected.';
    header('Location: ' . $sessionUrl);
    exit;
}

$dateObj = DateTime::createFromFormat('Y-m-d', $data['training_date']);
if (!$dateObj || $dateObj->format('Y-m-d') !== $data['training_date']) {
    $_SESSION['error'] = 'Invalid training date.';
    header('Location: ' . $sessionUrl);
    exit;
}
if ($data['training_date'] < date('Y-m-d') && ($existing['training_date'] ?? '') >= date('Y-m-d')) {
    $_SESSION['error'] = 'Training date cannot be moved to the past.';
    header('Location: ' . $sessionUrl);
    exit;
}

if ($data['end_time'] <= $data['start_time']) {
    $_SESSION['error'] = 'End time must be after start time.';
    header('Location: ' . $sessionUrl);
    exit;
}

if (!is_numeric($data['fee_amount']) || (float)$data['fee_amount'] < 0) {
    $_SESSION['error'] = 'Fee amount cannot be negative.';
    header('Location: ' . $sessionUrl);
    exit;
}
$data['fee_amount'] = (float)$data['fee_amount'];

if ($data['target_group'] === 'Public') {
    $data['fee_amount'] = 0.0;
}

if (mb_strlen($data['location']) > 255 || mb_strlen($data['description']) > 255) {
    $_SESSION['error'] = 'Location and description must not exceed 255 characters.';
    header('Location: ' . $sessionUrl);
    exit;
}
$data['location'] = $data['location'] !== '' ? $data['location'] : null;
$data['description'] = $data['description'] !== '' ? $data['description'] : null;

$coachOk = false;
foreach ($trainingsModel->getCoaches() as $coach) {
    if ((int)$coach['coach_id'] === $data['coach_id']) {
        $coachOk = true;
        break;
    }
}
if (!$coachOk && (int)($existing['coach_id'] ?? 0) !== $data['coach_id']) {
    $_SESSION['error'] = 'Selected coach is not active or not eligible.';
    header('Location: ' . $sessionUrl);
    exit;
}

try {
    if ($trainingsModel->update($trainingId, $data)) {
        $_SESSION['success'] = 'Training session updated successfully!';
    } else {
        $_SESSION['error'] = 'Failed to update training session.';
    }
} catch (Throwable $e) {
    error_log('update_training failed: ' . $e->getMessage());
    $_SESSION['error'] = 'An error occurred. Please try again.';
}

header('Location: ' . $sessionUrl);
exit;