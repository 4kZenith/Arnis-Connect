<?php
declare(strict_types=1);

/*
 * ASSIGN REPLACEMENT LEAD COACH / EVENT HANDLER — Admin (SuperAdmin) only.
 *
 * Called from the Admin dashboard "Unassigned Work" panel after a coach or
 * handler account was archived. Assigns an active, eligible member
 * (position Head Coach or Coach) to a training (trainings.coach_id) or an
 * event (events.handler_id), clearing the needs-* alert and the remembered
 * previous assignment.
 *
 * POST: type=trainings|events, id, handler_member_id, csrf_token.
 * Assignment (like removal) is an administrator action; scoped writers are
 * rejected with 403.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/AuditLog.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

if (!arnisVerifyCsrfToken($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
    exit;
}

/* Strict: only an unscoped administrator may assign replacements. */
if (permissionValue('users', 'delete') !== true || !hasRole('SuperAdmin')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Only an administrator can assign replacements.']);
    exit;
}

try {
    $type = (string)($_POST['type'] ?? '');
    $id = isset($_POST['id']) && is_numeric($_POST['id']) ? (int)$_POST['id'] : 0;
    $handlerMemberId = isset($_POST['handler_member_id']) && is_numeric($_POST['handler_member_id'])
        ? (int)$_POST['handler_member_id']
        : 0;

    if (!in_array($type, ['trainings', 'events'], true) || $id <= 0 || $handlerMemberId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing or invalid assignment details.']);
        exit;
    }

    $db = (new Database())->connect();

    /* The replacement must be an active, non-voided Head Coach or Coach. */
    $check = $db->prepare(
        "SELECT member_id FROM members
         WHERE member_id = :id AND is_voided = 0 AND status = 'Active'
           AND member_type IN ('Head Coach','Coach') LIMIT 1"
    );
    $check->execute([':id' => $handlerMemberId]);
    if (!$check->fetchColumn()) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Select an active Head Coach or Coach.']);
        exit;
    }

    $actor = (int)($_SESSION['user_id'] ?? 0);
    $db->beginTransaction();

    if ($type === 'trainings') {
        $stmt = $db->prepare(
            "UPDATE trainings
             SET coach_id = :mid, needs_coach = 0, previous_coach_id = NULL,
                 updated_at = NOW(), updated_by = :actor
             WHERE training_id = :id AND is_voided = 0"
        );
        $stmt->execute([':mid' => $handlerMemberId, ':actor' => $actor, ':id' => $id]);
    } else {
        $stmt = $db->prepare(
            "UPDATE events
             SET handler_id = :mid, needs_handler = 0, previous_handler_id = NULL,
                 updated_at = NOW(), updated_by = :actor
             WHERE event_id = :id AND is_voided = 0"
        );
        $stmt->execute([':mid' => $handlerMemberId, ':actor' => $actor, ':id' => $id]);
    }

    if ($stmt->rowCount() < 1) {
        $db->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Record not found or already assigned.']);
        exit;
    }

    $db->commit();

    AuditLog::record(
        $db,
        'update',
        $type,
        $id,
        'Assigned member #' . $handlerMemberId . ' as '
            . ($type === 'trainings' ? 'coach for training #' : 'handler for event #') . $id,
        ['handler_member_id' => $handlerMemberId]
    );

    echo json_encode(['success' => true, 'message' => 'Replacement assigned successfully.']);
} catch (PDOException $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('assign_handler DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error. Ensure migration 002 has been imported.']);
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('assign_handler error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An unexpected error occurred.']);
}
