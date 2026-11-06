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

    requirePermission('event_participants', 'delete');

    arnisRequireCsrf(true);

    if (!isset($_POST['participant_id']) || !is_numeric($_POST['participant_id'])) {
        throw new RuntimeException('Invalid participant ID.');
    }
    $participantId = (int)$_POST['participant_id'];

    if (!isset($_POST['event_id']) || !is_numeric($_POST['event_id'])) {
        throw new RuntimeException('Invalid event ID.');
    }
    $eventId = (int)$_POST['event_id'];

    if ($participantId <= 0 || $eventId <= 0) {
        throw new RuntimeException('Invalid participant ID.');
    }

    $db = (new Database())->connect();

    $stmt = $db->prepare(
        "SELECT ep.participant_id, ep.event_id, m.full_name, e.created_by, e.handler_id
         FROM event_participants ep
         JOIN members m ON ep.member_id = m.member_id
         JOIN events e ON ep.event_id = e.event_id
         WHERE ep.participant_id = :participant_id AND ep.is_voided = 0
         LIMIT 1"
    );
    $stmt->execute([':participant_id' => $participantId]);
    $participant = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$participant) {
        throw new RuntimeException('Participant not found or already removed.');
    }

    if ((int)$participant['event_id'] !== $eventId) {
        throw new RuntimeException('Event ID mismatch.');
    }

    // Check ownership for scoped permissions (Coach/Trainer 'own_events').
    // The assigned event handler owns the event too, not only its creator.
    $deleteScope = permissionValue('event_participants', 'delete');
    if ($deleteScope !== true) {
        if (!canPerformAction('event_participants', 'delete', [
            'event_created_by' => (int)($participant['created_by'] ?? 0),
            'handler_id' => (int)($participant['handler_id'] ?? 0),
        ])) {
            throw new RuntimeException('You do not have permission to remove participants from this event.');
        }
    }

    $stmt = $db->prepare(
        "UPDATE event_participants
         SET is_voided = 1, voided_at = NOW(), voided_by = :user_id, void_reason = :reason
         WHERE participant_id = :participant_id AND is_voided = 0"
    );
    $stmt->execute([
        ':participant_id' => $participantId,
        ':user_id' => (int)$_SESSION['user_id'],
        ':reason' => 'Removed from event',
    ]);

    if ($stmt->rowCount() === 0) {
        throw new RuntimeException('Failed to remove participant.');
    }

    AuditLog::record(
        $db,
        'void',
        'event_participants',
        $participantId,
        'Removed ' . (string)$participant['full_name'] . ' from event #' . $eventId,
        ['event_id' => $eventId]
    );

    echo json_encode([
        'success' => true,
        'message' => (string)$participant['full_name'] . ' has been removed from the event.',
    ]);
} catch (RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (PDOException $e) {
    error_log('remove_from_event DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error.']);
} catch (Throwable $e) {
    error_log('remove_from_event error: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Failed to remove participant.']);
}