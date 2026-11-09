<?php
declare(strict_types=1);

/**
 * Marks Present / Late / Absent on one event participant.
 *
 * Mirrors actions/update_attendance.php (training roster) but for the
 * event roster: event_participants gains attendance_status via
 * Database/add_event_attendance.sql.
 *
 * POST: participant_id, event_id, status (Present|Late|Absent), csrf_token
 * Returns JSON: { success, message } | { success:false, error }
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/AuditLog.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Invalid request method.');
    }

    /* Recording roster attendance is a WRITE on the event roster. */
    requirePermission('event_participants', 'write');

    arnisRequireCsrf(true);

    if (!isset($_POST['participant_id']) || !is_numeric($_POST['participant_id'])) {
        throw new RuntimeException('Invalid participant ID.');
    }
    $participantId = (int) $_POST['participant_id'];

    if (!isset($_POST['event_id']) || !is_numeric($_POST['event_id'])) {
        throw new RuntimeException('Invalid event ID.');
    }
    $eventId = (int) $_POST['event_id'];

    if ($participantId <= 0 || $eventId <= 0) {
        throw new RuntimeException('Invalid participant ID.');
    }

    /* ENUM whitelist - never interpolate the status into SQL. */
    $status = isset($_POST['status']) && is_string($_POST['status']) ? trim($_POST['status']) : '';
    if (!in_array($status, ['Present', 'Late', 'Absent'], true)) {
        throw new RuntimeException('Invalid attendance status.');
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

    if ((int) $participant['event_id'] !== $eventId) {
        throw new RuntimeException('Event ID mismatch.');
    }

    /*
     * Scope check for Coach/Trainer/Head Coach ('own_events'): they may only
     * record attendance on events they created or are assigned to handle.
     * Officers/SuperAdmin hold `true` and skip this.
     */
    $writeScope = permissionValue('event_participants', 'write');
    if ($writeScope !== true) {
        if (!canPerformAction('event_participants', 'write', [
            'event_created_by' => (int)($participant['created_by'] ?? 0),
            'handler_id' => (int)($participant['handler_id'] ?? 0),
        ])) {
            throw new RuntimeException('You do not have permission to record attendance for this event.');
        }
    }

    $stmt = $db->prepare(
        "UPDATE event_participants
         SET attendance_status = :status,
             attendance_recorded_by = :user_id,
             attendance_recorded_at = NOW(),
             updated_by = :user_id
         WHERE participant_id = :participant_id AND is_voided = 0"
    );
    $stmt->execute([
        ':status' => $status,
        ':user_id' => (int) $_SESSION['user_id'],
        ':participant_id' => $participantId,
    ]);

    if ($stmt->rowCount() === 0) {
        /*
         * Zero rows can also mean the UPDATE matched but changed nothing
         * (same status re-clicked) - that is still a successful state, so
         * only fail when the column itself is missing (handled below).
         */
        $check = $db->prepare('SELECT attendance_status FROM event_participants WHERE participant_id = :participant_id LIMIT 1');
        $check->execute([':participant_id' => $participantId]);
        $check->fetch(PDO::FETCH_ASSOC);
    }

    echo json_encode([
        'success' => true,
        'status' => $status,
        'message' => (string) $participant['full_name'] . ' marked as ' . strtolower($status) . '.',
    ]);

    AuditLog::record(
        $db,
        'update',
        'event_participants',
        $participantId,
        'Marked ' . (string)$participant['full_name'] . ' as ' . $status . ' for event #' . $eventId,
        ['event_id' => $eventId, 'status' => $status]
    );
} catch (PDOException $e) {
    /* 1054 = unknown column: migration has not been run yet. */
    if ((string) ($e->errorInfo[1] ?? '') === '1054') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Event attendance is not set up yet. Run Database/add_event_attendance.sql in phpMyAdmin once, then reload this page.',
        ]);
        exit;
    }
    error_log('update_event_attendance DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error.']);
} catch (RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('update_event_attendance error: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Failed to update attendance.']);
}
