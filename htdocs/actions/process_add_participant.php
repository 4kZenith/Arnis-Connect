<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/AuditLog.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Invalid request method.');
    }

    requirePermission('event_participants', 'write');

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

    $role = trim((string)($_POST['role'] ?? ''));
    if ($role === '') {
        throw new RuntimeException('Role is required.');
    }
    if (mb_strlen($role) > 255) {
        throw new RuntimeException('Role must not exceed 255 characters.');
    }

    $db = (new Database())->connect();

    $stmt = $db->prepare('SELECT event_id, target_player_category, created_by, handler_id FROM events WHERE event_id = :id AND is_voided = 0 LIMIT 1');
    $stmt->execute([':id' => $eventId]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$event) {
        throw new RuntimeException('Event not found.');
    }

    // Check ownership for scoped permissions (Coach/Trainer 'own_events').
    // The assigned event handler owns the event too, not only its creator.
    $writeScope = permissionValue('event_participants', 'write');
    if ($writeScope !== true) {
        if (!canPerformAction('event_participants', 'write', [
            'event_created_by' => (int)($event['created_by'] ?? 0),
            'handler_id' => (int)($event['handler_id'] ?? 0),
        ])) {
            throw new RuntimeException('You do not have permission to add participants to this event.');
        }
    }

    $stmt = $db->prepare('SELECT member_id, status, player_category FROM members WHERE member_id = :id AND is_voided = 0 LIMIT 1');
    $stmt->execute([':id' => $memberId]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$member) {
        throw new RuntimeException('Member not found.');
    }
    if (($member['status'] ?? '') !== 'Active') {
        throw new RuntimeException('Only active members can join events.');
    }

    $stmt = $db->prepare(
        "SELECT participant_id FROM event_participants
         WHERE event_id = :event_id AND member_id = :member_id AND is_voided = 0
         LIMIT 1"
    );
    $stmt->execute([':event_id' => $eventId, ':member_id' => $memberId]);
    if ($stmt->fetch()) {
        throw new RuntimeException('Member is already a participant in this event.');
    }

    $stmt = $db->prepare(
        "INSERT INTO event_participants (event_id, member_id, role, created_by, is_voided)
         VALUES (:event_id, :member_id, :role, :created_by, 0)"
    );
    $stmt->execute([
        ':event_id' => $eventId,
        ':member_id' => $memberId,
        ':role' => $role,
        ':created_by' => (int)$_SESSION['user_id'],
    ]);

    AuditLog::record(
        $db,
        'create',
        'event_participants',
        (int)$db->lastInsertId(),
        'Added ' . (string)($member['full_name'] ?? ('member #' . $memberId)) . ' to event #' . $eventId . ' as ' . $role,
        ['event_id' => $eventId, 'member_id' => $memberId]
    );

    echo json_encode(['success' => true, 'message' => 'Participant added successfully.']);
} catch (RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (PDOException $e) {
    error_log('process_add_participant DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error.']);
} catch (Throwable $e) {
    error_log('process_add_participant error: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Failed to add participant.']);
}