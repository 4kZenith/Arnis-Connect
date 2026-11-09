<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';

header('Content-Type: application/json');

// Member/Applicant (members.read=false) must not enumerate other members.
if (!canAccess('members', 'read')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit;
}

$eventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;
if ($eventId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid event id.']);
    exit;
}

$db = (new Database())->connect();

$eventStmt = $db->prepare("SELECT event_id, event_name, event_type, target_player_category, expected_participants FROM events WHERE event_id = :id AND is_voided = 0 LIMIT 1");
$eventStmt->execute([':id' => $eventId]);
$event = $eventStmt->fetch(PDO::FETCH_ASSOC);
if (!$event) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Event not found.']);
    exit;
}

$whereGroup = '';
if (($event['target_player_category'] ?? 'Mixed') === 'Public') {
    $whereGroup = " AND m.player_category IN ('Public', 'Mixed') ";
} elseif (($event['target_player_category'] ?? 'Mixed') === 'Private') {
    $whereGroup = " AND m.player_category IN ('Private', 'Mixed') ";
}

$sql = "
    SELECT
        m.member_id,
        m.full_name,
        m.rank,
        m.member_type,
        m.player_category,
        m.training_preference,
        m.affiliated_school,
        SUM(CASE WHEN a.status = 'Present' THEN 1 WHEN a.status = 'Late' THEN 0.7 ELSE 0 END) AS attendance_points_raw,
        COUNT(DISTINCT CASE WHEN a.status IN ('Present','Late') THEN a.attendance_id END) AS attendance_count,
        COUNT(DISTINCT ep2.event_id) AS past_events
    FROM members m
    LEFT JOIN attendance a
        ON a.member_id = m.member_id
       AND a.is_voided = 0
       AND a.recorded_at >= DATE_SUB(NOW(), INTERVAL 180 DAY)
    LEFT JOIN event_participants ep2
        ON ep2.member_id = m.member_id
       AND ep2.is_voided = 0
    WHERE m.is_voided = 0
      AND m.status = 'Active'
      AND m.member_id NOT IN (
            SELECT ep.member_id FROM event_participants ep WHERE ep.event_id = :event_id AND ep.is_voided = 0
      )
      $whereGroup
    GROUP BY m.member_id, m.full_name, m.rank, m.member_type, m.player_category, m.training_preference, m.affiliated_school
";
$stmt = $db->prepare($sql);
$stmt->execute([':event_id' => $eventId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$rankOrder = [
    'None' => 0,
    'I. White Belt' => 1,
    'II. Yellow Belt' => 2,
    'III. Green Belt' => 3,
    'IV. Blue Belt' => 4,
    'V. Brown Belt' => 5,
    'VI. Black Belt' => 6,
    '1st Degree- Junior Instructor' => 7,
    '2nd Degree- Junior Instructor' => 8,
    '3rd Degree- Instructor' => 9,
    '4th Degree- Instructor' => 10,
    '5th Degree- Senior Instructor' => 11,
    '6th Degree- Senior Instructor' => 12,
    '7th Degree- Junior Master' => 13,
    '8th Degree- Master' => 14,
    '9th Degree- Senior Master' => 15,
    'VII. Red Belt' => 16,
    '10th Degree- Grandmaster' => 17,
    '11th Degree- Senior Grandmaster' => 18,
    '12th Degree- Superior Grandmaster' => 19,
];

function suggestionRoleForEvent(string $eventType): string {
    return match($eventType) {
        'Tournament' => 'Competitor',
        'Performance' => 'Performer',
        default => 'Facilitator',
    };
}

$suggestions = [];
foreach ($rows as $row) {
    $attendanceCount = (int)($row['attendance_count'] ?? 0);
    $pastEvents = (int)($row['past_events'] ?? 0);
    $rankValue = $rankOrder[$row['rank'] ?? 'None'] ?? 0;

    $attendanceScore = min(40, $attendanceCount * 4);
    $rankScore = min(25, $rankValue * 1.3);
    $eventExperienceScore = min(15, $pastEvents * 3);

    $preferenceScore = 0;
    $target = $event['target_player_category'] ?? 'Mixed';
    $pref = $row['training_preference'] ?? 'Group';
    if ($target === 'Public') {
        $preferenceScore = in_array($pref, ['Group', 'Flexible'], true) ? 10 : 3;
    } elseif ($target === 'Private') {
        $preferenceScore = in_array($pref, ['Private', 'Flexible'], true) ? 10 : 3;
    } else {
        $preferenceScore = 6;
    }

    $typeBonus = 0;
    if (($event['event_type'] ?? '') === 'Tournament' && in_array($row['member_type'], ['Athlete', 'Player'], true)) {
        $typeBonus = 5;
    } elseif (($event['event_type'] ?? '') === 'Performance' && in_array($row['member_type'], ['Player', 'Athlete', 'Trainer'], true)) {
        $typeBonus = 4;
    } elseif (($event['event_type'] ?? '') === 'Outreach' && in_array($row['member_type'], ['Coach', 'Trainer', 'Player'], true)) {
        $typeBonus = 4;
    }

    $totalScore = round($attendanceScore + $rankScore + $eventExperienceScore + $preferenceScore + $typeBonus, 1);
    $reasons = [];
    if ($attendanceCount > 0) $reasons[] = $attendanceCount . ' recent attendance record' . ($attendanceCount > 1 ? 's' : '');
    if ($rankValue > 0) $reasons[] = 'ranked ' . ($row['rank'] ?? 'None');
    if ($pastEvents > 0) $reasons[] = $pastEvents . ' past event' . ($pastEvents > 1 ? 's' : '');
    if ($target !== 'Mixed') $reasons[] = 'fits ' . strtolower($target) . ' program';
    if ($pref !== '') $reasons[] = strtolower($pref) . ' training preference';

    $suggestions[] = [
        'member_id' => (int)$row['member_id'],
        'full_name' => $row['full_name'],
        'rank' => $row['rank'],
        'member_type' => $row['member_type'],
        'player_category' => $row['player_category'],
        'training_preference' => $row['training_preference'],
        'affiliated_school' => $row['affiliated_school'],
        'score' => $totalScore,
        'attendance_count' => $attendanceCount,
        'past_events' => $pastEvents,
        'suggested_role' => suggestionRoleForEvent($event['event_type'] ?? 'Performance'),
        'reason' => implode(' • ', $reasons),
    ];
}

usort($suggestions, fn($a, $b) => $b['score'] <=> $a['score']);
$limit = max(1, (int)($event['expected_participants'] ?? 8));
$suggestions = array_slice($suggestions, 0, min($limit, 12));

echo json_encode([
    'success' => true,
    'event' => $event,
    'suggestions' => $suggestions,
    'message' => empty($suggestions) ? 'No eligible suggestions found for this event.' : null
]);
