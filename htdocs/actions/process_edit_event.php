<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/Events.php';
require_once __DIR__ . '/../classes/AuditLog.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Invalid request method.');
    }

    requirePermission('events', 'write');

    arnisRequireCsrf(true);

    if (!isset($_POST['event_id']) || !is_numeric($_POST['event_id'])) {
        throw new RuntimeException('Invalid event ID.');
    }
    $eventId = (int)$_POST['event_id'];
    if ($eventId <= 0) {
        throw new RuntimeException('Invalid event ID.');
    }

    $eventName = trim((string)($_POST['event_name'] ?? ''));
    $eventType = (string)($_POST['event_type'] ?? '');
    $eventDate = trim((string)($_POST['event_date'] ?? ''));
    $location = trim((string)($_POST['location'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $targetPlayerCategory = (string)($_POST['target_player_category'] ?? 'Mixed');
    $expectedParticipants = isset($_POST['expected_participants']) && is_numeric($_POST['expected_participants'])
        ? (int)$_POST['expected_participants']
        : 8;

    if ($eventName === '') {
        throw new RuntimeException('Event name is required.');
    }
    if (mb_strlen($eventName) > 100) {
        throw new RuntimeException('Event name must not exceed 100 characters.');
    }
    if (!in_array($eventType, ['Performance', 'Tournament', 'Outreach'], true)) {
        throw new RuntimeException('Invalid event type.');
    }
    $dateObj = DateTime::createFromFormat('Y-m-d', $eventDate);
    if (!$dateObj || $dateObj->format('Y-m-d') !== $eventDate) {
        throw new RuntimeException('Invalid event date. Use YYYY-MM-DD.');
    }
    if (!in_array($targetPlayerCategory, ['Public', 'Private', 'Mixed'], true)) {
        throw new RuntimeException('Invalid target player group.');
    }
    if ($location !== '' && mb_strlen($location) > 255) {
        throw new RuntimeException('Location must not exceed 255 characters.');
    }
    if ($expectedParticipants < 1) {
        $expectedParticipants = 8;
    }
    $expectedParticipants = min(500, $expectedParticipants);

    $db = (new Database())->connect();
    $eventsModel = new Events($db);

    $existing = $eventsModel->getById($eventId);
    if (!$existing) {
        throw new RuntimeException('Event not found.');
    }
    if (!canPerformAction('events', 'write', [
        'created_by' => (int)($existing['created_by'] ?? 0),
        'handler_id' => (int)($existing['handler_id'] ?? 0),
    ])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied.']);
        exit;
    }

    /* Lead Coach / Event Handler — validated against active members whose
     * position is Head Coach or Coach. Empty selection clears the handler. */
    $handlerId = isset($_POST['handler_id']) && is_numeric($_POST['handler_id'])
        ? (int)$_POST['handler_id']
        : 0;
    if ($handlerId > 0) {
        $handlerCheck = $db->prepare(
            "SELECT member_id FROM members
             WHERE member_id = :id AND is_voided = 0 AND status = 'Active'
               AND member_type IN ('Head Coach','Coach') LIMIT 1"
        );
        $handlerCheck->execute([':id' => $handlerId]);
        if (!$handlerCheck->fetchColumn()) {
            throw new RuntimeException('The selected event handler is not an active Head Coach or Coach.');
        }
    } else {
        $handlerId = null;
    }

    $previousHandlerId = isset($existing['handler_id']) && (int)$existing['handler_id'] > 0
        ? (int)$existing['handler_id']
        : null;
    $handlerChanged = (int)($previousHandlerId ?? 0) !== (int)($handlerId ?? 0);

    $ok = $eventsModel->update($eventId, [
        'event_name' => $eventName,
        'event_type' => $eventType,
        'event_date' => $eventDate,
        'location' => $location !== '' ? $location : null,
        'description' => $description !== '' ? $description : null,
        'target_player_category' => $targetPlayerCategory,
        'expected_participants' => $expectedParticipants,
        'updated_by' => (int)$_SESSION['user_id'],
        'handler_id' => $handlerId,
        // Only rewrite the previous handler when ownership actually changes.
        'previous_handler_id' => $handlerChanged
            ? $previousHandlerId
            : ($existing['previous_handler_id'] ?? null),
    ]);

    if (!$ok) {
        throw new RuntimeException('Failed to update event or no changes were made.');
    }

    AuditLog::record(
        $db,
        'update',
        'events',
        $eventId,
        'Updated event ' . $eventName,
        [
            'previous_handler_id' => $previousHandlerId,
            'handler_id' => $handlerId,
        ]
    );

    echo json_encode(['success' => true, 'message' => 'Event updated successfully.']);
} catch (RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (PDOException $e) {
    error_log('process_edit_event DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error.']);
} catch (Throwable $e) {
    error_log('process_edit_event error: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Failed to update event.']);
}