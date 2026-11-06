<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/Trainings.php';
require_once __DIR__ . '/../classes/AuditLog.php';
require_once __DIR__ . '/../classes/Mailer.php';

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
if ($data['training_date'] < date('Y-m-d')) {
    echo json_encode(['success' => false, 'message' => 'Training date cannot be in the past.']);
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

    if ($trainingsModel->create($data, (int)$_SESSION['user_id'])) {
        $newTrainingId = (int)$db->lastInsertId();

        AuditLog::record(
            $db,
            'create',
            'trainings',
            $newTrainingId > 0 ? $newTrainingId : null,
            'Scheduled ' . $data['training_type'] . ' training on ' . $data['training_date'],
            ['coach_id' => $data['coach_id']]
        );

        /*
         * Email the assigned coach and matching active members. The coach is a
         * member row (coaches are members with a coach position), so both
         * audiences come from `members`.
         */
        try {
            $mailer = new Mailer($db);
            if ($mailer->notificationsEnabled()) {
                $coachStmt = $db->prepare(
                    "SELECT full_name, email FROM members WHERE member_id = :id LIMIT 1"
                );
                $coachStmt->execute([':id' => $data['coach_id']]);
                $coach = $coachStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                if (!empty($coach['email'])) {
                    $mailer->sendTrainingNotification(
                        (string)$coach['email'],
                        (string)($coach['full_name'] ?? 'Coach'),
                        $data
                    );
                }

                $memberSql = "SELECT member_id, email FROM members
                              WHERE is_voided = 0 AND status = 'Active'
                                AND email IS NOT NULL AND email <> ''
                                AND member_id <> :coach_id";
                $memberParams = [':coach_id' => $data['coach_id']];
                if ($data['target_group'] !== 'Mixed') {
                    $memberSql .= " AND player_category = :category";
                    $memberParams[':category'] = $data['target_group'];
                }
                $memberStmt = $db->prepare($memberSql);
                $memberStmt->execute($memberParams);
                $recipients = [];
                foreach ($memberStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    if (!empty($row['email'])) {
                        $recipients[] = (string)$row['email'];
                    }
                }

                if ($recipients !== []) {
                    $safeDate = htmlspecialchars((string)$data['training_date'], ENT_QUOTES, 'UTF-8');
                    $safeLocation = htmlspecialchars((string)($data['location'] ?? 'To be announced'), ENT_QUOTES, 'UTF-8');
                    $safeType = htmlspecialchars((string)$data['training_type'], ENT_QUOTES, 'UTF-8');
                    $safeTime = htmlspecialchars(
                        trim((string)$data['start_time'] . ' - ' . (string)$data['end_time']),
                        ENT_QUOTES,
                        'UTF-8'
                    );
                    $mailer->sendBulk(
                        $recipients,
                        'New training session: ' . $data['training_date'],
                        '<p>A new <strong>' . $safeType . '</strong> training session has been scheduled.</p>'
                        . '<p>Date: ' . $safeDate . '<br>Time: ' . $safeTime . '<br>Location: ' . $safeLocation . '</p>'
                        . '<p>Sign in to Arnis Connect for the full session details.</p>'
                    );
                }
            }
        } catch (Throwable $mailError) {
            error_log('process_add_training notification skipped: ' . $mailError->getMessage());
        }

        echo json_encode(['success' => true, 'message' => 'Training session scheduled successfully!']);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to schedule training session.']);
    }
} catch (PDOException $e) {
    error_log('process_add_training DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
} catch (Throwable $e) {
    error_log('process_add_training error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
}