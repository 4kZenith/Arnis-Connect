<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/Trainings.php';
require_once __DIR__ . '/../classes/AuditLog.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

requirePermission('trainings', 'write');

arnisRequireCsrf(true);

$validTypes = ['Regular', 'Private', 'Custom'];
$validGroups = ['Public', 'Private', 'Mixed'];
$validVenues = ['School', 'Private Venue', 'Custom'];

$trainingId = (isset($_POST['training_id']) && is_numeric($_POST['training_id'])) ? (int)$_POST['training_id'] : 0;
if ($trainingId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid training ID.']);
    exit;
}

$data = [
    'training_date' => trim((string)($_POST['training_date'] ?? '')),
    'training_type' => (string)($_POST['training_type'] ?? ''),
    'target_group' => (string)($_POST['target_group'] ?? 'Mixed'),
    'venue_type' => (string)($_POST['venue_type'] ?? 'Custom'),
    'start_time' => trim((string)($_POST['start_time'] ?? '')),
    'end_time' => trim((string)($_POST['end_time'] ?? '')),
    'location' => trim((string)($_POST['location'] ?? '')),
    'description' => trim((string)($_POST['description'] ?? '')),
    'coach_id' => $_POST['coach_id'] ?? null,
    'fee_amount' => $_POST['fee_amount'] ?? 0,
    'venue_lat' => $_POST['venue_lat'] ?? null,
    'venue_lng' => $_POST['venue_lng'] ?? null,
    'geofence_m' => $_POST['geofence_m'] ?? 100,
];

if ($data['training_date'] === '' || $data['training_type'] === '' || $data['start_time'] === '' || $data['end_time'] === '' || $data['coach_id'] === null || $data['coach_id'] === '') {
    echo json_encode(['success' => false, 'message' => 'Please fill in all required fields.']);
    exit;
}

if (!in_array($data['training_type'], $validTypes, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid training type.']);
    exit;
}
if (!in_array($data['target_group'], $validGroups, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid target group.']);
    exit;
}
if (!in_array($data['venue_type'], $validVenues, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid venue type.']);
    exit;
}

$dateObj = DateTime::createFromFormat('Y-m-d', $data['training_date']);
if (!$dateObj || $dateObj->format('Y-m-d') !== $data['training_date']) {
    echo json_encode(['success' => false, 'message' => 'Invalid training date.']);
    exit;
}

$startObj = DateTime::createFromFormat('H:i', $data['start_time']) ?: DateTime::createFromFormat('H:i:s', $data['start_time']);
$endObj = DateTime::createFromFormat('H:i', $data['end_time']) ?: DateTime::createFromFormat('H:i:s', $data['end_time']);
if (!$startObj || !$endObj) {
    echo json_encode(['success' => false, 'message' => 'Invalid start or end time.']);
    exit;
}
if ($data['end_time'] <= $data['start_time']) {
    echo json_encode(['success' => false, 'message' => 'End time must be after start time.']);
    exit;
}

if (!is_numeric($data['coach_id']) || (int)$data['coach_id'] <= 0) {
    echo json_encode(['success' => false, 'message' => 'Please select a valid coach.']);
    exit;
}
$data['coach_id'] = (int)$data['coach_id'];

if (!is_numeric($data['fee_amount']) || (float)$data['fee_amount'] < 0) {
    echo json_encode(['success' => false, 'message' => 'Fee amount cannot be negative.']);
    exit;
}
$data['fee_amount'] = (float)$data['fee_amount'];

if ($data['target_group'] === 'Public') {
    $data['fee_amount'] = 0.0;
}

if ($data['location'] !== '' && mb_strlen($data['location']) > 255) {
    echo json_encode(['success' => false, 'message' => 'Location must not exceed 255 characters.']);
    exit;
}
if ($data['description'] !== '' && mb_strlen($data['description']) > 255) {
    echo json_encode(['success' => false, 'message' => 'Description must not exceed 255 characters.']);
    exit;
}
$data['location'] = $data['location'] !== '' ? $data['location'] : null;
$data['description'] = $data['description'] !== '' ? $data['description'] : null;

// Validate geofence fields
if ($data['venue_lat'] !== null && $data['venue_lat'] !== '') {
    $lat = (float)$data['venue_lat'];
    if ($lat < -90 || $lat > 90) {
        echo json_encode(['success' => false, 'message' => 'Invalid latitude. Must be between -90 and 90.']);
        exit;
    }
    $data['venue_lat'] = $lat;
} else {
    $data['venue_lat'] = null;
}

if ($data['venue_lng'] !== null && $data['venue_lng'] !== '') {
    $lng = (float)$data['venue_lng'];
    if ($lng < -180 || $lng > 180) {
        echo json_encode(['success' => false, 'message' => 'Invalid longitude. Must be between -180 and 180.']);
        exit;
    }
    $data['venue_lng'] = $lng;
} else {
    $data['venue_lng'] = null;
}

if ($data['geofence_m'] !== null && $data['geofence_m'] !== '') {
    $geofence = (int)$data['geofence_m'];
    if ($geofence < 10 || $geofence > 1000) {
        echo json_encode(['success' => false, 'message' => 'Geofence radius must be between 10 and 1000 meters.']);
        exit;
    }
    $data['geofence_m'] = $geofence;
} else {
    $data['geofence_m'] = 100;
}

try {
    $db = (new Database())->connect();
    $trainingsModel = new Trainings($db);

    /* Read the stored row first. Ownership must be checked against the
     * training's CURRENT coach, not the coach_id submitted in the form:
     * otherwise a coach could take over another coach's session simply by
     * assigning it to themselves in the edit form. */
    $existingStmt = $db->prepare(
        'SELECT training_id, coach_id, training_date, training_type
         FROM trainings
         WHERE training_id = :id AND is_voided = 0
         LIMIT 1'
    );
    $existingStmt->execute([':id' => $trainingId]);
    $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($existing)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Training session not found or already voided.']);
        exit;
    }

    $coachOk = false;
    foreach ($trainingsModel->getCoaches() as $coach) {
        if ((int)$coach['coach_id'] === $data['coach_id']) {
            $coachOk = true;
            break;
        }
    }
    if (!$coachOk) {
        echo json_encode(['success' => false, 'message' => 'Selected coach is not active or not eligible.']);
        exit;
    }

    // Check ownership for scoped permissions against the stored coach_id.
    $writeScope = permissionValue('trainings', 'write');
    if ($writeScope !== true) {
        if (!canPerformAction('trainings', 'write', [
            'coach_id' => (int)($existing['coach_id'] ?? 0),
            'training_id' => $trainingId,
        ])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'You do not have permission to edit this training session.']);
            exit;
        }
    }

    if ($trainingsModel->update($trainingId, $data)) {
        AuditLog::record(
            $db,
            'update',
            'trainings',
            $trainingId,
            'Updated ' . $data['training_type'] . ' training on ' . $data['training_date'],
            [
                'previous_coach_id' => (int)($existing['coach_id'] ?? 0),
                'coach_id' => (int)$data['coach_id'],
                'training_date' => $data['training_date'],
            ]
        );
        echo json_encode(['success' => true, 'message' => 'Training session updated successfully!']);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to update training session.']);
    }
} catch (PDOException $e) {
    error_log('process_edit_training DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
} catch (Throwable $e) {
    error_log('process_edit_training error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
}