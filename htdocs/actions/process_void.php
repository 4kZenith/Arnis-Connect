<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/AuditLog.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $redirect);
    exit;
}

arnisRequireCsrf(false);

if (!arnisVerifyCsrfToken($_POST['csrf_token'] ?? null)) {
    $_SESSION['error'] = 'Invalid security token. Please refresh and try again.';
    header('Location: ../public/dashboard.php');
    exit;
}

$db = (new Database())->connect();

$targetId = isset($_POST['target_id']) && is_numeric($_POST['target_id']) ? (int)$_POST['target_id'] : 0;
$targetType = (string)($_POST['target_type'] ?? '');
$voidReason = trim((string)($_POST['void_reason'] ?? ''));
$voidedBy = (int)($_SESSION['user_id'] ?? 0);

$allowedTypes = ['Training', 'Member', 'Event', 'Fund', 'Attendance', 'Achievement', 'Document'];
$typeToModule = [
    'Training' => 'trainings',
    'Member' => 'members',
    'Event' => 'events',
    'Fund' => 'funds',
    'Attendance' => 'attendance',
    'Achievement' => 'achievements',
    'Document' => 'event_documents',
];
$defaultRedirect = [
    'Training' => '../public/trainings.php',
    'Member' => '../public/members_management.php',
    'Event' => '../public/events.php',
    'Fund' => '../public/funds.php',
    'Attendance' => '../public/trainings.php',
    'Achievement' => '../public/events.php',
    'Document' => '../public/events.php',
];

if ($targetId <= 0 || !in_array($targetType, $allowedTypes, true) || $voidReason === '' || $voidedBy <= 0) {
    $_SESSION['error'] = 'Missing required fields.';
    header('Location: ../public/dashboard.php');
    exit;
}
if (mb_strlen($voidReason) > 255) {
    $_SESSION['error'] = 'Void reason must not exceed 255 characters.';
    header('Location: ' . ($defaultRedirect[$targetType] ?? '../public/dashboard.php'));
    exit;
}

$module = $typeToModule[$targetType];
if (!hasPermission($module, 'delete')) {
    $_SESSION['error'] = 'You do not have permission to void this record.';
    header('Location: ' . ($defaultRedirect[$targetType] ?? '../public/dashboard.php'));
    exit;
}

/*
 * Resolve the ownership context from the target row.
 *
 * The previous version passed the *target id* where the ownership resolver
 * expected a creator/coach/training id:
 *   Event      -> created_by = event_id
 *   Training   -> coach_id   = training_id
 *   Attendance -> training_id = attendance_id
 * Those values never match, so scoped staff were either always denied or (for
 * full-access roles) the check was meaningless. Resolve the real columns here
 * before calling canPerformAction().
 */
$ownershipContext = [];
$recordLabel = '';
$recordDescription = '';

try {
    switch ($targetType) {
        case 'Training':
            $stmt = $db->prepare(
                'SELECT training_id, coach_id, training_date, training_type
                 FROM trainings
                 WHERE training_id = :id AND is_voided = 0
                 LIMIT 1'
            );
            $stmt->execute([':id' => $targetId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $_SESSION['error'] = 'Training not found or already voided.';
                header('Location: ' . $defaultRedirect[$targetType]);
                exit;
            }
            $ownershipContext = ['coach_id' => (int)($row['coach_id'] ?? 0)];
            $recordLabel = 'training on ' . (string)($row['training_date'] ?? '');
            break;

        case 'Member':
            $stmt = $db->prepare(
                'SELECT member_id, full_name
                 FROM members
                 WHERE member_id = :id AND is_voided = 0
                 LIMIT 1'
            );
            $stmt->execute([':id' => $targetId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $_SESSION['error'] = 'Member not found or already voided.';
                header('Location: ' . $defaultRedirect[$targetType]);
                exit;
            }
            $ownershipContext = ['member_id' => (int)$row['member_id']];
            $recordLabel = (string)($row['full_name'] ?? ('member #' . $targetId));
            break;

        case 'Event':
            $stmt = $db->prepare(
                'SELECT event_id, event_name, created_by, handler_id
                 FROM events
                 WHERE event_id = :id AND is_voided = 0
                 LIMIT 1'
            );
            $stmt->execute([':id' => $targetId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $_SESSION['error'] = 'Event not found or already voided.';
                header('Location: ' . $defaultRedirect[$targetType]);
                exit;
            }
            $ownershipContext = [
                'created_by' => (int)($row['created_by'] ?? 0),
                'handler_id' => (int)($row['handler_id'] ?? 0),
            ];
            $recordLabel = (string)($row['event_name'] ?? ('event #' . $targetId));
            break;

        case 'Fund':
            $stmt = $db->prepare(
                'SELECT fund_id, source_or_purpose, amount, transaction_type
                 FROM funds
                 WHERE fund_id = :id AND is_voided = 0
                 LIMIT 1'
            );
            $stmt->execute([':id' => $targetId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $_SESSION['error'] = 'Transaction not found or already voided.';
                header('Location: ' . $defaultRedirect[$targetType]);
                exit;
            }
            $recordLabel = (string)($row['transaction_type'] ?? 'Fund') . ' — '
                . (string)($row['source_or_purpose'] ?? ('fund #' . $targetId));
            break;

        case 'Attendance':
            $stmt = $db->prepare(
                'SELECT a.attendance_id, a.training_id, m.full_name
                 FROM attendance a
                 LEFT JOIN members m ON m.member_id = a.member_id
                 WHERE a.attendance_id = :id AND a.is_voided = 0
                 LIMIT 1'
            );
            $stmt->execute([':id' => $targetId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $_SESSION['error'] = 'Attendance record not found or already voided.';
                header('Location: ' . $defaultRedirect[$targetType]);
                exit;
            }
            $ownershipContext = ['training_id' => (int)($row['training_id'] ?? 0)];
            $recordLabel = 'attendance for ' . (string)($row['full_name'] ?? ('member record #' . $targetId));
            break;

        case 'Achievement':
            $stmt = $db->prepare(
                'SELECT a.achievement_id, a.member_id, a.achievement_type, m.full_name
                 FROM achievements a
                 LEFT JOIN members m ON m.member_id = a.member_id
                 WHERE a.achievement_id = :id AND a.is_voided = 0
                 LIMIT 1'
            );
            $stmt->execute([':id' => $targetId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $_SESSION['error'] = 'Achievement not found or already voided.';
                header('Location: ' . $defaultRedirect[$targetType]);
                exit;
            }
            $ownershipContext = ['achievement_id' => $targetId];
            $recordLabel = (string)($row['achievement_type'] ?? 'Achievement') . ' for '
                . (string)($row['full_name'] ?? ('member #' . (int)$row['member_id']));
            break;

        case 'Document':
            $stmt = $db->prepare(
                'SELECT d.document_id, d.event_id, d.document_name,
                        e.created_by AS event_created_by, e.handler_id
                 FROM event_documents d
                 JOIN events e ON e.event_id = d.event_id
                 WHERE d.document_id = :document_id AND d.is_voided = 0
                 LIMIT 1'
            );
            $stmt->execute([':document_id' => $targetId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $_SESSION['error'] = 'Document not found or already voided.';
                header('Location: ' . $defaultRedirect[$targetType]);
                exit;
            }
            $ownershipContext = [
                'event_created_by' => (int)($row['event_created_by'] ?? 0),
                'handler_id' => (int)($row['handler_id'] ?? 0),
            ];
            $recordLabel = (string)($row['document_name'] ?? ('document #' . $targetId));
            break;
    }

    if (!canPerformAction($module, 'delete', $ownershipContext)) {
        $_SESSION['error'] = 'You do not have permission to void this ' . strtolower($targetType) . '.';
        header('Location: ' . ($defaultRedirect[$targetType] ?? '../public/dashboard.php'));
        exit;
    }

    $success = false;
    $redirectPage = $defaultRedirect[$targetType];

    switch ($targetType) {
        case 'Training':
            require_once __DIR__ . '/../classes/Trainings.php';
            $model = new Trainings($db);
            $success = $model->void($targetId, $voidedBy, $voidReason);
            break;

        case 'Member':
            require_once __DIR__ . '/../classes/Members.php';
            $model = new Members($db);
            $success = $model->void($targetId, $voidedBy, $voidReason);
            break;

        case 'Event':
            require_once __DIR__ . '/../classes/Events.php';
            $model = new Events($db);
            $success = $model->void($targetId, $voidedBy, $voidReason);
            break;

        case 'Fund':
            require_once __DIR__ . '/../classes/Funds.php';
            $model = new Funds($db);
            $success = $model->void($targetId, $voidedBy, $voidReason);
            break;

        case 'Attendance':
            require_once __DIR__ . '/../classes/Attendance.php';
            $model = new Attendance($db);
            $success = $model->void($targetId, $voidedBy, $voidReason);
            break;

        case 'Achievement':
            $stmt = $db->prepare(
                'UPDATE achievements
                 SET is_voided = 1, voided_at = NOW(), voided_by = :voided_by, void_reason = :reason
                 WHERE achievement_id = :id AND is_voided = 0'
            );
            $stmt->execute([':voided_by' => $voidedBy, ':reason' => $voidReason, ':id' => $targetId]);
            $success = $stmt->rowCount() > 0;
            break;

        case 'Document':
            $success = voidDocument($db, $targetId, $voidedBy, $voidReason);
            break;
    }

    if ($success) {
        AuditLog::record(
            $db,
            'void',
            $module,
            $targetId,
            'Voided ' . strtolower($targetType) . ' ' . $recordLabel . '. Reason: ' . $voidReason,
            [
                'reason' => $voidReason,
                'target_type' => $targetType,
            ]
        );
        $_SESSION['success'] = $targetType . ' voided successfully.';
    } else {
        $_SESSION['error'] = 'Record not found or already voided.';
    }

    header('Location: ' . $redirectPage);
    exit;
} catch (Throwable $e) {
    error_log('process_void failed: ' . $e->getMessage());
    $_SESSION['error'] = 'An error occurred. Please try again.';
    header('Location: ../public/dashboard.php');
    exit;
}

function voidDocument(PDO $db, int $documentId, int $voidedBy, string $reason): bool
{
    try {
        $stmt = $db->prepare(
            'UPDATE event_documents
             SET is_voided = 1, voided_at = NOW(), voided_by = :voided_by, void_reason = :reason
             WHERE document_id = :document_id AND is_voided = 0'
        );
        $stmt->execute([
            ':voided_by' => $voidedBy,
            ':reason' => $reason,
            ':document_id' => $documentId,
        ]);
        return $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        error_log('Document void error: ' . $e->getMessage());
        return false;
    }
}
