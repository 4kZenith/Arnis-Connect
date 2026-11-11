<?php
declare(strict_types=1);

// Arnis Connect operates on Philippine time (Asia/Manila); stored start/end
// times are local. Force the default timezone so time() and strtotime() compare
// apples-to-apples regardless of the server's system timezone.
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/Attendance.php';
require_once __DIR__ . '/../classes/Trainings.php';
require_once __DIR__ . '/../classes/Members.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Content-Type: application/json; charset=UTF-8');

function checkinError(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    checkinError(405, 'Method not allowed');
}

if (!isset($_POST['csrf_token']) || !arnisVerifyCsrfToken((string)$_POST['csrf_token'])) {
    checkinError(403, 'Invalid security token. Please reload the page and try again.');
}

$trainingId = (isset($_POST['training_id']) && is_numeric($_POST['training_id'])) ? (int)$_POST['training_id'] : 0;
if ($trainingId <= 0) {
    checkinError(400, 'Invalid training session.');
}

$memberId = (isset($_POST['member_id']) && is_numeric($_POST['member_id'])) ? (int)$_POST['member_id'] : 0;
if ($memberId <= 0) {
    checkinError(400, 'Invalid member ID.');
}

/* 
 * Permission: Members and Applicants can only check in themselves (attendance.write = 'own')
 * Coaches/Admins/Officers can check in anyone
 */
requirePermission('attendance', 'write');

// Get training to verify it exists and get geofence settings
try {
    $db = (new Database())->connect();

    /*
     * Scope check runs here because 'own' needs the resolved member id, which
     * may require the name fallback (resolveSessionMemberId) for accounts whose
     * users.member_id was never linked.
     */
    $writeScope = permissionValue('attendance', 'write');
    if ($writeScope !== true) {
        if ($writeScope === 'own') {
            $currentMemberId = function_exists('resolveSessionMemberId') ? resolveSessionMemberId($db) : 0;
            if ($currentMemberId <= 0 || $currentMemberId !== $memberId) {
                checkinError(403, 'You can only check in yourself.');
            }
        } elseif ($writeScope === 'own_trainees') {
            // Coach can check in their trainees
            if (!canPerformAction('attendance', 'write', ['member_id' => $memberId])) {
                checkinError(403, 'You can only check in your own trainees.');
            }
        }
    }

    $trainingsModel = new Trainings($db);
    $training = $trainingsModel->getById($trainingId);
    
    if (!$training) {
        checkinError(404, 'Training session not found or has been voided.');
    }
    
    // Check if training is today or past (allow check-in for today's sessions)
    $today = date('Y-m-d');
    if ($training['training_date'] > $today) {
        checkinError(400, 'Cannot check in for future training sessions.');
    }
    
    /*
     * A row may already exist for this (training, member): staff "Add People"
     * inserts every pick with status 'Absent' as a placeholder, and attendance
     * has UNIQUE(training_id, member_id). Only block a genuine duplicate
     * check-in (already Present/Late); an 'Absent' placeholder - or a soft
     * deleted row - is promoted below instead.
     */
    $attendanceModel = new Attendance($db);
    $existingAttendance = $db->prepare("
        SELECT attendance_id, status, is_voided FROM attendance 
        WHERE training_id = :training_id AND member_id = :member_id
        LIMIT 1
    ");
    $existingAttendance->execute([':training_id' => $trainingId, ':member_id' => $memberId]);
    $existing = $existingAttendance->fetch(PDO::FETCH_ASSOC);
    $existingAttendanceId = $existing ? (int)$existing['attendance_id'] : 0;

    if ($existingAttendanceId > 0
        && (int)($existing['is_voided'] ?? 0) === 0
        && in_array((string)($existing['status'] ?? ''), ['Present', 'Late'], true)) {
        checkinError(409, 'You are already checked in for this session. Current status: ' . $existing['status']);
    }
    
    // Verify member is eligible for this training (based on target_group and player_category)
    $memberModel = new Members($db);
    $member = $memberModel->getById($memberId);
    if (!$member) {
        checkinError(404, 'Member not found.');
    }
    
    // Check if member is active
    if ($member['status'] !== 'Active') {
        checkinError(403, 'Only active members can check in.');
    }
    
    // Verify member matches training target_group
    $targetGroup = $training['target_group'] ?? 'Mixed';
    $playerCategory = $member['player_category'] ?? 'Public';
    
    $eligible = false;
    if ($targetGroup === 'Mixed') {
        $eligible = true;
    } elseif ($targetGroup === 'Public' && in_array($playerCategory, ['Public', 'Mixed'], true)) {
        $eligible = true;
    } elseif ($targetGroup === 'Private' && in_array($playerCategory, ['Private', 'Mixed'], true)) {
        $eligible = true;
    }
    
    if (!$eligible) {
        checkinError(403, 'You are not eligible for this training session.');
    }
    
    // Face verification (if provided)
    $faceScore = null;
    $faceDescriptorInput = isset($_POST['face_descriptor']) ? trim((string)$_POST['face_descriptor']) : '';
    $storedDescriptor = trim((string)($member['face_descriptor'] ?? ''));
    
    if ($faceDescriptorInput !== '' && $storedDescriptor !== '') {
        // Verify the captured descriptor against the member's enrolled one.
        $inputDescriptor = json_decode($faceDescriptorInput, true);
        $stored = json_decode($storedDescriptor, true);

        if (!is_array($inputDescriptor) || !is_array($stored)
            || count($inputDescriptor) !== 128 || count($stored) !== 128) {
            checkinError(400, 'Could not read the face data. Please recapture your face and try again.');
        }

        // Euclidean distance between the two 128-d face descriptors.
        $distance = 0.0;
        for ($i = 0; $i < 128; $i++) {
            $diff = (float)$inputDescriptor[$i] - (float)$stored[$i];
            $distance += $diff * $diff;
        }
        $distance = sqrt($distance);

        // 0.6 is the face-api.js default matching threshold (lower = stricter).
        $threshold = 0.6;

        if ($distance > $threshold) {
            checkinError(403, 'Face verification failed - the captured face does not match your enrolled face. Please face the camera in good lighting and try again.');
        }

        $faceScore = round(1 - ($distance / $threshold), 4); // similarity score 0-1
        if ($faceScore < 0) $faceScore = 0;
        if ($faceScore > 1) $faceScore = 1;
    } elseif ($storedDescriptor !== '') {
        // Member has enrolled face but didn't provide one
        checkinError(400, 'Face verification required. Please enroll your face first.');
    }
    // If no stored descriptor, allow manual check-in (fallback)
    
    // Geolocation verification (if provided)
    $distanceM = null;
    $userLat = isset($_POST['latitude']) && is_numeric($_POST['latitude']) ? (float)$_POST['latitude'] : null;
    $userLng = isset($_POST['longitude']) && is_numeric($_POST['longitude']) ? (float)$_POST['longitude'] : null;
    $venueLat = isset($training['venue_lat']) && $training['venue_lat'] !== null ? (float)$training['venue_lat'] : null;
    $venueLng = isset($training['venue_lng']) && $training['venue_lng'] !== null ? (float)$training['venue_lng'] : null;
    $geofenceM = isset($training['geofence_m']) && $training['geofence_m'] !== null ? (int)$training['geofence_m'] : 100;
    
    if ($userLat !== null && $userLng !== null && $venueLat !== null && $venueLng !== null) {
        // Haversine formula for distance calculation
        $earthRadius = 6371000; // meters
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
            checkinError(403, "You are too far from the venue. Distance: {$distanceM}m, Allowed: {$geofenceM}m. Please move closer to the training location.");
        }
    } elseif ($venueLat !== null && $venueLng !== null) {
        // Venue has geofence but user didn't provide location
        checkinError(400, 'Location access required. Please enable GPS and try again.');
    }
    // If no venue coordinates, allow check-in without geofence check
    
    // Determine status based on time window and session duration
    $status = 'Present';
    $startTime = $training['start_time'] ?? null;
    $endTime   = $training['end_time'] ?? null;
    $nowTs     = time();

    // Block check-in before the session starts
    if ($startTime !== null && $nowTs < strtotime($startTime)) {
        checkinError(400, 'Check-in is not yet open. Session starts at ' . date('h:i A', strtotime($startTime)) . '.');
    }

    // Mark Late if checked in past the halfway point of the session
    if ($startTime !== null && $endTime !== null) {
        $startTs = strtotime($startTime);
        $endTs   = strtotime($endTime);
        $duration = $endTs - $startTs;
        if ($duration > 0 && $nowTs > $startTs + ($duration / 2)) {
            $status = 'Late';
        }
    }
    
    $method = ($faceDescriptorInput !== '' && $storedDescriptor !== '') ? 'FaceGPS' : 'Manual';
    $flagged = 0;
    $flagReason = null;
    
    // Flag if face match confidence is marginal but accepted
    if ($faceScore !== null && $faceScore < 0.5) {
        $flagged = 1;
        $flagReason = 'Low face match confidence: ' . round($faceScore * 100) . '%';
    }
    // Flag if distance is close to geofence boundary
    if ($distanceM !== null && $distanceM > $geofenceM * 0.8) {
        $flagged = 1;
        $flagReason = ($flagReason ? $flagReason . '; ' : '') . 'Near geofence boundary (' . $distanceM . 'm/' . $geofenceM . 'm)';
    }
    
    // Start transaction
    $db->beginTransaction();
    
    try {
        /*
         * Promote the coach-created 'Absent' placeholder (or reactivate a soft
         * deleted row) instead of INSERTing, which would violate the
         * UNIQUE(training_id, member_id) key. Only create a new row when the
         * member was never on the roster.
         */
        if ($existingAttendanceId > 0) {
            $promote = $db->prepare("
                UPDATE attendance
                SET status = :status,
                    remarks = :remarks,
                    updated_by = :updated_by,
                    updated_at = NOW(),
                    is_voided = 0,
                    voided_at = NULL,
                    voided_by = NULL,
                    void_reason = NULL
                WHERE attendance_id = :id
            ");
            $promote->execute([
                ':status'     => $status,
                ':remarks'    => 'Self check-in via ' . $method,
                ':updated_by' => (int)$_SESSION['user_id'],
                ':id'         => $existingAttendanceId,
            ]);
            $attendanceId = $existingAttendanceId;
        } else {
            $attendanceResult = $attendanceModel->create([
                'training_id' => $trainingId,
                'member_id' => $memberId,
                'status' => $status,
                'remarks' => 'Self check-in via ' . $method,
                'recorded_by' => (int)$_SESSION['user_id'],
            ]);

            if (!$attendanceResult) {
                throw new RuntimeException('Failed to create attendance record');
            }

            $attendanceId = (int)$db->lastInsertId();
        }
        
        // Create check-in record (upsert: attendance_checkins also has a
        // UNIQUE(training_id, member_id) key).
        $checkinStmt = $db->prepare("
            INSERT INTO attendance_checkins 
            (training_id, member_id, method, face_score, distance_m, status, flagged, flag_reason, recorded_by, created_at)
            VALUES (:training_id, :member_id, :method, :face_score, :distance_m, :status, :flagged, :flag_reason, :recorded_by, NOW())
            ON DUPLICATE KEY UPDATE
                method = VALUES(method),
                face_score = VALUES(face_score),
                distance_m = VALUES(distance_m),
                status = VALUES(status),
                flagged = VALUES(flagged),
                flag_reason = VALUES(flag_reason),
                recorded_by = VALUES(recorded_by),
                created_at = NOW()
        ");
        
        $checkinStmt->execute([
            ':training_id' => $trainingId,
            ':member_id' => $memberId,
            ':method' => $method,
            ':face_score' => $faceScore,
            ':distance_m' => $distanceM,
            ':status' => $status,
            ':flagged' => $flagged,
            ':flag_reason' => $flagReason,
            ':recorded_by' => (int)$_SESSION['user_id'],
        ]);
        
        $checkinId = (int)$db->lastInsertId();
        
        $db->commit();
        
        $message = 'Checked in as ' . $status;
        if ($method === 'FaceGPS') {
            $message .= ' (Face verified' . ($faceScore ? ', ' . round($faceScore * 100) . '% match' : '') . ')';
            if ($distanceM !== null) {
                $message .= ', ' . $distanceM . 'm from venue';
            }
        }
        
        echo json_encode([
            'success' => true,
            'message' => $message,
            'data' => [
                'attendance_id' => $attendanceId,
                'checkin_id' => $checkinId,
                'status' => $status,
                'method' => $method,
                'face_score' => $faceScore,
                'distance_m' => $distanceM,
                'flagged' => $flagged,
            ]
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('process_attendance_checkin error: ' . $e->getMessage());
        checkinError(500, 'Failed to process check-in. Please try again.');
    }
    
} catch (PDOException $e) {
    error_log('process_attendance_checkin DB error: ' . $e->getMessage());
    checkinError(500, 'Database error. Please try again.');
} catch (Throwable $e) {
    error_log('process_attendance_checkin error: ' . $e->getMessage());
    checkinError(500, 'An error occurred. Please try again.');
}