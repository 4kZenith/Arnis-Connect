<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/Events.php';
require_once __DIR__ . '/../classes/AuditLog.php';
require_once __DIR__ . '/../classes/Mailer.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

requirePermission('events', 'write');

arnisRequireCsrf(true);

try {
    $name = trim((string)($_POST['event_name'] ?? ''));
    $type = (string)($_POST['event_type'] ?? '');
    $date = trim((string)($_POST['event_date'] ?? ''));
    $location = trim((string)($_POST['location'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $targetPlayerCategory = (string)($_POST['target_player_category'] ?? 'Mixed');
    $expectedParticipants = (int)($_POST['expected_participants'] ?? 8);

    $allowedTypes = ['Performance', 'Tournament', 'Outreach'];
    $allowedTargets = ['Public', 'Private', 'Mixed'];

    if ($name === '' || !in_array($type, $allowedTypes, true) || $date === '' || !in_array($targetPlayerCategory, $allowedTargets, true)) {
        echo json_encode(['success' => false, 'message' => 'Please fill all required fields correctly.']);
        exit;
    }

    if (mb_strlen($name) > 100) {
        echo json_encode(['success' => false, 'message' => 'Event name must not exceed 100 characters.']);
        exit;
    }
    if ($location !== '' && mb_strlen($location) > 255) {
        echo json_encode(['success' => false, 'message' => 'Location must not exceed 255 characters.']);
        exit;
    }

    $dateObj = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dateObj || $dateObj->format('Y-m-d') !== $date) {
        echo json_encode(['success' => false, 'message' => 'Invalid date format. Use YYYY-MM-DD.']);
        exit;
    }

    if ($expectedParticipants < 1) {
        $expectedParticipants = 8;
    }
    $expectedParticipants = min(500, $expectedParticipants);

    $db = (new Database())->connect();

    /* Optional Lead Coach / Event Handler. Must be an active, non-voided
     * member whose position is Head Coach or Coach (same eligibility as a
     * training's Lead Coach). Validated server-side, never trusted from the
     * dropdown. */
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
            echo json_encode(['success' => false, 'message' => 'The selected event handler is not an active Head Coach or Coach.']);
            exit;
        }
    } else {
        $handlerId = null;
    }

    $eventsModel = new Events($db);

    $data = [
        'event_name' => $name,
        'event_type' => $type,
        'event_date' => $date,
        'location' => $location !== '' ? $location : null,
        'description' => $description !== '' ? $description : null,
        'target_player_category' => $targetPlayerCategory,
        'expected_participants' => $expectedParticipants,
        'created_by' => (int)$_SESSION['user_id'],
        'handler_id' => $handlerId,
    ];

    if ($eventsModel->create($data)) {
        $newEventId = (int)$db->lastInsertId();

        AuditLog::record(
            $db,
            'create',
            'events',
            $newEventId > 0 ? $newEventId : null,
            'Created event ' . $name,
            ['handler_id' => $handlerId]
        );

        /*
         * Email notifications: the assigned handler gets a personal message;
         * matching active members get one BCC message (addresses never leak).
         * Failures are logged by Mailer and never fail the event creation.
         */
        try {
            $mailer = new Mailer($db);
            $eventForMail = [
                'event_name' => $name,
                'event_type' => $type,
                'event_date' => $date,
                'location' => $location,
            ];
            if ($handlerId !== null) {
                $handler = $mailer->contactForMember($handlerId);
                if ($handler !== null) {
                    $mailer->sendEventNotification($handler['email'], $handler['name'], $eventForMail);
                }
            }
            $mailer->sendPlainBulk(
                $mailer->activeMemberEmails($targetPlayerCategory, $handlerId !== null ? [$handlerId] : []),
                'New event: ' . $name,
                'A new ' . $type . ' event was posted: ' . $name . ' on ' . $date
                . ' at ' . ($location !== '' ? $location : 'a location to be announced') . '.'
            );
        } catch (Throwable $mailError) {
            error_log('process_add_event notification skipped: ' . $mailError->getMessage());
        }

        echo json_encode(['success' => true, 'message' => 'Event created successfully.']);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to create event. Please try again.']);
    }
} catch (PDOException $e) {
    error_log('process_add_event DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
} catch (Throwable $e) {
    error_log('process_add_event error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
}