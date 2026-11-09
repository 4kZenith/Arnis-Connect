<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once "../includes/authorize.php";
require_once "../config/Database.php";
require_once "../classes/Members.php";


// Validate member ID
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid member ID']);
    exit;
}

$memberId = (int) $_GET['id'];
if ($memberId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid member ID']);
    exit;
}

/*
 * PII endpoint: own record, full readers, or the coach who trains this member.
 * Without this gate any signed-in Member could read every other member's
 * attendance, achievements, and event history.
 */
$sessionMemberId = isset($_SESSION['member_id']) && $_SESSION['member_id'] !== null
    ? (int)$_SESSION['member_id']
    : 0;
$isSelf = $sessionMemberId > 0 && $sessionMemberId === $memberId;
$readScope = permissionValue('members', 'read');

$allowed = $isSelf || $readScope === true;
if (!$allowed && $readScope === 'own_trainees') {
    $allowed = canPerformAction('members', 'read', ['member_id' => $memberId]);
}
if (!$allowed) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied.']);
    exit;
}

// Initialize database
$db = (new Database())->connect();
$membersModel = new Members($db);

// Fetch member details
$member = $membersModel->getById($memberId);
if (!$member) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Member not found']);
    exit;
}

// Calculate age
$age = null;
if ($member['dob']) {
    $birthDate = new DateTime($member['dob']);
    $today = new DateTime('today');
    $age = $birthDate->diff($today)->y;
}

// Fetch attendance summary
$stmt = $db->prepare("
    SELECT 
        status,
        COUNT(*) as count
    FROM attendance
    WHERE member_id = :member_id AND is_voided = 0
    GROUP BY status
");
$stmt->execute([':member_id' => $memberId]);
$attendanceData = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$presentCount = $attendanceData['Present'] ?? 0;
$lateCount = $attendanceData['Late'] ?? 0;
$absentCount = $attendanceData['Absent'] ?? 0;
$totalAttendance = $presentCount + $lateCount + $absentCount;
$attendanceRate = $totalAttendance > 0 ? (($presentCount / $totalAttendance) * 100) : 0;

// Fetch achievements
$stmt = $db->prepare("
    SELECT 
        a.achievement_id,
        a.achievement_type AS achievement,
        a.achievement_type AS type,
        a.level,
        a.achieved_at AS date_earned,
        a.notes,
        e.event_name
    FROM achievements a
    LEFT JOIN events e ON a.event_id = e.event_id
    WHERE a.member_id = :member_id AND a.is_voided = 0
    ORDER BY a.achieved_at DESC
");
$stmt->execute([':member_id' => $memberId]);
$achievements = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch event participations with talent fee
$stmt = $db->prepare("
    SELECT 
        ep.participant_id,
        ep.role,
        e.event_id,
        e.event_name,
        e.event_type,
        e.event_date,
        e.location as event_location,
        COALESCE(SUM(f.amount), 0) as event_income
    FROM event_participants ep
    JOIN events e ON ep.event_id = e.event_id
    LEFT JOIN funds f ON f.related_event_id = e.event_id AND f.transaction_type = 'Income' AND f.is_voided = 0
    WHERE ep.member_id = :member_id AND ep.is_voided = 0
    GROUP BY ep.participant_id, ep.role, e.event_id, e.event_name, e.event_type, e.event_date, e.location
    ORDER BY e.event_date DESC
");
$stmt->execute([':member_id' => $memberId]);
$eventParticipations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Return all data as response
http_response_code(200);
echo json_encode([
    'success' => true,
    'data' => [
        'member' => $member,
        'age' => $age,
        'attendance' => [
            'present' => $presentCount,
            'late' => $lateCount,
            'absent' => $absentCount,
            'rate' => round($attendanceRate, 2)
        ],
        'achievements' => $achievements,
        'eventParticipations' => $eventParticipations
    ]
]);
