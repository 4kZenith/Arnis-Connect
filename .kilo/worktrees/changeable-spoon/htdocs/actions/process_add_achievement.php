<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/AuditLog.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Invalid request method.']);
        exit;
    }

    requirePermission('achievements', 'write');

    arnisRequireCsrf(true);

    if (!isset($_POST['event_id']) || !is_numeric($_POST['event_id'])) {
        throw new RuntimeException('Invalid event ID.');
    }
    $eventId = (int)$_POST['event_id'];
    if ($eventId <= 0) {
        throw new RuntimeException('Invalid event ID.');
    }

    if (!isset($_POST['member_id']) || !is_numeric($_POST['member_id'])) {
        throw new RuntimeException('Member is required.');
    }
    $memberId = (int)$_POST['member_id'];
    if ($memberId <= 0) {
        throw new RuntimeException('Member is required.');
    }

    $allowedTypes = ['Gold', 'Silver', 'Bronze', 'Recognition'];
    $achievementType = trim((string)($_POST['achievement_type'] ?? ''));
    if (!in_array($achievementType, $allowedTypes, true)) {
        throw new RuntimeException('Invalid achievement type.');
    }

    $allowedLevels = ['School', 'City', 'Regional', 'National'];
    $level = trim((string)($_POST['level'] ?? ''));
    if (!in_array($level, $allowedLevels, true)) {
        throw new RuntimeException('Invalid level.');
    }

    $notes = trim((string)($_POST['notes'] ?? ''));
    if ($notes === '') {
        $notes = null;
    } elseif (mb_strlen($notes) > 255) {
        throw new RuntimeException('Notes must not exceed 255 characters.');
    }

    $db = (new Database())->connect();

    $stmt = $db->prepare(
        "SELECT event_id, event_date FROM events
         WHERE event_id = :event_id AND is_voided = 0
         LIMIT 1"
    );
    $stmt->execute([':event_id' => $eventId]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$event) {
        throw new RuntimeException('Event not found or has been voided.');
    }

    $stmt = $db->prepare(
        "SELECT member_id FROM members
         WHERE member_id = :member_id AND is_voided = 0
         LIMIT 1"
    );
    $stmt->execute([':member_id' => $memberId]);
    if (!$stmt->fetch()) {
        throw new RuntimeException('Member not found or has been voided.');
    }

    $stmt = $db->prepare(
        "SELECT participant_id FROM event_participants
         WHERE event_id = :event_id AND member_id = :member_id AND is_voided = 0
         LIMIT 1"
    );
    $stmt->execute([':event_id' => $eventId, ':member_id' => $memberId]);
    if (!$stmt->fetch()) {
        throw new RuntimeException('Member is not a participant in this event.');
    }

    // Check ownership for scoped permissions (Coach/Trainer 'own_trainees')
    $writeScope = permissionValue('achievements', 'write');
    if ($writeScope !== true) {
        if (!canPerformAction('achievements', 'write', ['member_id' => $memberId])) {
            throw new RuntimeException('You do not have permission to record achievements for this member.');
        }
    }

    $achievedAt = null;
    $rawDate = trim((string)($_POST['achieved_at'] ?? ''));
    if ($rawDate !== '') {
        $dt = DateTime::createFromFormat('Y-m-d', $rawDate);
        if (!$dt || $dt->format('Y-m-d') !== $rawDate) {
            throw new RuntimeException('Invalid achieved date. Use YYYY-MM-DD.');
        }
        if ($rawDate > date('Y-m-d')) {
            throw new RuntimeException('Achieved date cannot be in the future.');
        }
        $achievedAt = $rawDate;
    } else {
        $eventDate = (string)($event['event_date'] ?? '');
        $dt = DateTime::createFromFormat('Y-m-d', $eventDate);
        $achievedAt = ($dt && $dt->format('Y-m-d') === $eventDate) ? $eventDate : date('Y-m-d');
    }

    $stmt = $db->prepare(
        "SELECT achievement_id FROM achievements
         WHERE event_id = :event_id
           AND member_id = :member_id
           AND achievement_type = :achievement_type
           AND level = :level
           AND achieved_at = :achieved_at
           AND is_voided = 0
         LIMIT 1"
    );
    $stmt->execute([
        ':event_id' => $eventId,
        ':member_id' => $memberId,
        ':achievement_type' => $achievementType,
        ':level' => $level,
        ':achieved_at' => $achievedAt,
    ]);
    if ($stmt->fetch()) {
        throw new RuntimeException('This achievement has already been recorded for this member.');
    }

    $stmt = $db->prepare(
        "INSERT INTO achievements (event_id, member_id, achievement_type, level, notes, achieved_at, created_by, is_voided)
         VALUES (:event_id, :member_id, :achievement_type, :level, :notes, :achieved_at, :created_by, 0)"
    );
    $stmt->execute([
        ':event_id' => $eventId,
        ':member_id' => $memberId,
        ':achievement_type' => $achievementType,
        ':level' => $level,
        ':notes' => $notes,
        ':achieved_at' => $achievedAt,
        ':created_by' => (int)$_SESSION['user_id'],
    ]);

    AuditLog::record(
        $db,
        'create',
        'achievements',
        (int)$db->lastInsertId() ?: null,
        'Recorded ' . $achievementType . ' (' . $level . ') for member #' . $memberId . ' at event #' . $eventId,
        ['event_id' => $eventId, 'member_id' => $memberId, 'achievement_type' => $achievementType, 'level' => $level]
    );

    echo json_encode([
        'success' => true,
        'message' => 'Achievement recorded successfully.',
    ]);
} catch (RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (PDOException $e) {
    error_log('process_add_achievement DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error. Please try again.']);
} catch (Throwable $e) {
    error_log('process_add_achievement error: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}