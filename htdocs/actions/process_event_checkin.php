<?php
declare(strict_types=1);

// Arnis Connect operates on Philippine time (Asia/Manila); stored start/end
// times are local. Force the default timezone so time() and strtotime() compare
// apples-to-apples regardless of the server's system timezone.
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/Members.php';
require_once __DIR__ . '/../classes/AuditLog.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Content-Type: application/json; charset=UTF-8');

function eventCheckinError(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    eventCheckinError(405, 'Method not allowed');
}

if (!isset($_POST['csrf_token']) || !arnisVerifyCsrfToken((string)$_POST['csrf_token'])) {
    eventCheckinError(403, 'Invalid security token. Please reload the page and try again.');
}

$eventId = (isset($_POST['event_id']) && is_numeric($_POST['event_id'])) ? (int)$_POST['event_id'] : 0;
if ($eventId <= 0) {
    eventCheckinError(400, 'Invalid event.');
}

$memberId = (isset($_POST['member_id']) && is_numeric($_POST['member_id'])) ? (int)$_POST['member_id'] : 0;
if ($memberId <= 0) {
    eventCheckinError(400, 'Invalid member ID.');
}

// Self check-in: members may check themselves in (own participant)
$writeScope = permissionValue('event_participants', 'write');
$isSelfCheckin = false;
if ($writeScope !== true) {
    // resolve after DB connect below
} else {
    $isSelfCheckin = false;
}

try {
    $db = (new Database())->connect();

    $currentMemberId = function_exists('resolveSessionMemberId') ? resolveSessionMemberId($db) : 0;
    $isSelfCheckin = ($currentMemberId > 0 && $currentMemberId === $memberId);

    if ($writeScope === false) {
        // Members have no general write, but may self check-in
        if (!$isSelfCheckin) {
            eventCheckinError(403, 'Access denied.');
        }
    } elseif ($writeScope === 'own') {
        if (!$isSelfCheckin) {
            eventCheckinError(403, 'You can only check in yourself.');
        }
    } elseif ($writeScope === 'own_events') {
        if (!$isSelfCheckin) {
            $evStmt = $db->prepare('SELECT created_by, handler_id FROM events WHERE event_id = :id AND is_voided = 0 LIMIT 1');
            $evStmt->execute([':id' => $eventId]);
            $ev = $evStmt->fetch(PDO::FETCH_ASSOC);
            if (!$ev || !canPerformAction('event_participants', 'write', [
                'event_created_by' => (int)($ev['created_by'] ?? 0),
                'handler_id' => (int)($ev['handler_id'] ?? 0),
            ])) {
                eventCheckinError(403, 'You do not have permission to record attendance for this event.');
            }
        }
    }

    $evStmt = $db->prepare('SELECT event_id, event_name, event_date, start_time, end_time, location, venue_lat, venue_lng, geofence_m FROM events WHERE event_id = :id AND is_voided = 0 LIMIT 1');
    $evStmt->execute([':id' => $eventId]);
    $event = $evStmt->fetch(PDO::FETCH_ASSOC);
    if (!$event) {
        eventCheckinError(404, 'Event not found or has been voided.');
    }

    // Block check-in before the event starts
    $startTime = $event['start_time'] ?? null;
    $endTime   = $event['end_time'] ?? null;
    $nowTs     = time();
    $startTs   = $startTime !== null ? strtotime($startTime) : null;

    $today = date('Y-m-d');
    $eventDate = (string)($event['event_date'] ?? '');
    if ($eventDate > $today) {
        eventCheckinError(400, 'Cannot check in for future events.');
    }

    if ($startTs !== null && $nowTs < $startTs) {
        eventCheckinError(400, 'Check-in is not yet open. Event starts at ' . date('h:i A', $startTs) . '.');
    }

    $pStmt = $db->prepare('SELECT participant_id, attendance_status FROM event_participants WHERE event_id = :eid AND member_id = :mid AND is_voided = 0 LIMIT 1');
    $pStmt->execute([':eid' => $eventId, ':mid' => $memberId]);
    $participant = $pStmt->fetch(PDO::FETCH_ASSOC);
    if (!$participant) {
        eventCheckinError(403, 'You are not registered as a participant in this event.');
    }

    $currentStatus = (string)($participant['attendance_status'] ?? '');
    if (in_array($currentStatus, ['Present', 'Late'], true)) {
        eventCheckinError(409, 'You are already checked in. Current status: ' . $currentStatus);
    }

    $memberModel = new Members($db);
    $member = $memberModel->getById($memberId);
    if (!$member) {
        eventCheckinError(404, 'Member not found.');
    }
    if (($member['status'] ?? '') !== 'Active') {
        eventCheckinError(403, 'Only active members can check in.');
    }

    $faceScore = null;
    $faceDescriptorInput = isset($_POST['face_descriptor']) ? trim((string)$_POST['face_descriptor']) : '';
    $storedDescriptor = trim((string)($member['face_descriptor'] ?? ''));

    if ($faceDescriptorInput !== '' && $storedDescriptor !== '') {
        $inputDescriptor = json_decode($faceDescriptorInput, true);
        $stored = json_decode($storedDescriptor, true);
        if (!is_array($inputDescriptor) || !is_array($stored) || count($inputDescriptor) !== 128 || count($stored) !== 128) {
            eventCheckinError(400, 'Could not read the face data. Please recapture your face and try again.');
        }
        $distance = 0.0;
        for ($i = 0; $i < 128; $i++) {
            $diff = (float)$inputDescriptor[$i] - (float)$stored[$i];
            $distance += $diff * $diff;
        }
        $distance = sqrt($distance);
        $threshold = 0.6;
        if ($distance > $threshold) {
            eventCheckinError(403, 'Face verification failed. Please face the camera in good lighting and try again.');
        }
        $faceScore = round(1 - ($distance / $threshold), 4);
        if ($faceScore < 0) $faceScore = 0;
        if ($faceScore > 1) $faceScore = 1;
    } elseif ($storedDescriptor !== '') {
        eventCheckinError(400, 'Face verification required. Please enroll your face first.');
    }

    // Geolocation + geofence enforcement (same as trainings)
    $distanceM = null;
    $userLat = isset($_POST['latitude']) && is_numeric($_POST['latitude']) ? (float)$_POST['latitude'] : null;
    $userLng = isset($_POST['longitude']) && is_numeric($_POST['longitude']) ? (float)$_POST['longitude'] : null;
    $venueLat = isset($event['venue_lat']) && $event['venue_lat'] !== null ? (float)$event['venue_lat'] : null;
    $venueLng = isset($event['venue_lng']) && $event['venue_lng'] !== null ? (float)$event['venue_lng'] : null;
    $geofenceM = isset($event['geofence_m']) && $event['geofence_m'] !== null ? (int)$event['geofence_m'] : 100;

    if ($userLat !== null && $userLng !== null && $venueLat !== null && $venueLng !== null) {
        $earthRadius = 6371000;
        $lat1 = deg2rad($userLat);
        $lon1 = deg2rad($userLng);
        $lat2 = deg2rad($venueLat);
        $lon2 = deg2rad($venueLng);
        $dLat = $lat2 - $lat1;
        $dLon = $lon2 - $lon1;
        $a = sin($dLat/2) * sin($dLat/2) + cos($lat1) * cos($lat2) * sin($dLon/2) * sin($dLon/2);
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        $distanceM = (int)round($earthRadius * $c);
        if ($distanceM > $geofenceM) {
            eventCheckinError(403, "You are too far from the venue. Distance: {$distanceM}m, Allowed: {$geofenceM}m. Please move closer to the event location.");
        }
    } elseif ($venueLat !== null && $venueLng !== null) {
        eventCheckinError(400, 'Location access required. Please enable GPS and try again.');
    }

    // Mark Late if checked in past the halfway point of the event
    $status = 'Present';
    if ($startTs !== null && $endTime !== null) {
        $endTs = strtotime($endTime);
        $duration = $endTs - $startTs;
        if ($duration > 0 && $nowTs > $startTs + ($duration / 2)) {
            $status = 'Late';
        }
    }
    $method = ($faceDescriptorInput !== '' && $storedDescriptor !== '') ? 'FaceGPS' : 'Manual';
    $userId = (int)($_SESSION['user_id'] ?? 0);

    $upd = $db->prepare("
        UPDATE event_participants
        SET attendance_status = :status,
            attendance_remarks = :remarks,
            attendance_recorded_by = :user_id,
            attendance_recorded_at = NOW(),
            updated_by = :user_id,
            updated_at = NOW()
        WHERE participant_id = :pid AND is_voided = 0
    ");
    $upd->execute([
        ':status' => $status,
        ':remarks' => 'Self check-in via ' . $method . ($faceScore !== null ? ' (score ' . round($faceScore * 100) . '%)' : ''),
        ':user_id' => $userId,
        ':pid' => (int)$participant['participant_id'],
    ]);

    AuditLog::record(
        $db,
        'update',
        'event_participants',
        (int)$participant['participant_id'],
        'Self check-in as ' . $status . ' for event #' . $eventId . ' via ' . $method,
        ['event_id' => $eventId, 'member_id' => $memberId, 'status' => $status, 'method' => $method]
    );

    echo json_encode([
        'success' => true,
        'message' => 'Checked in as ' . $status . ($faceScore !== null ? ' (Face verified, ' . round($faceScore * 100) . '% match)' : ''),
        'data' => [
            'participant_id' => (int)$participant['participant_id'],
            'status' => $status,
            'method' => $method,
            'face_score' => $faceScore,
        ],
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    if ((string)($e->errorInfo[1] ?? '') === '1054') {
        eventCheckinError(400, 'Event attendance columns are not set up yet. Run Database/add_event_attendance.sql in phpMyAdmin.');
    }
    error_log('process_event_checkin DB error: ' . $e->getMessage());
    eventCheckinError(500, 'Database error. Please try again.');
} catch (Throwable $e) {
    error_log('process_event_checkin error: ' . $e->getMessage());
    eventCheckinError(500, 'An error occurred. Please try again.');
}
