<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/Members.php';
require_once __DIR__ . '/../classes/Events.php';
require_once __DIR__ . '/../classes/Trainings.php';
require_once __DIR__ . '/../classes/Funds.php';
require_once __DIR__ . '/../classes/Attendance.php';
require_once __DIR__ . '/../classes/Achievements.php';
require_once __DIR__ . '/../classes/AuditLog.php';

/*
 * Restoring a soft-deleted row is a delete-class operation. The old inline
 * matrix here duplicated RBAC and still listed the retired Committee / Board
 * Member roles. Delegate to the canonical matrix instead: restore requires
 * `delete === true` on the owning module (SuperAdmin everywhere, Officer for
 * funds only).
 */
function canRestore(string $table): bool
{
    $module = $table === 'event_documents' ? 'event_documents' : $table;
    return permissionValue($module, 'delete') === true;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

arnisRequireCsrf(true);

if (!isset($_POST['table']) || !isset($_POST['id']) || !is_numeric($_POST['id'])) {
    echo json_encode(['success' => false, 'message' => 'Missing parameters.']);
    exit;
}

$table = (string)$_POST['table'];
$id = (int)$_POST['id'];
$userId = (int)($_SESSION['user_id'] ?? 0);

$allowedTables = ['members', 'events', 'trainings', 'funds', 'achievements', 'event_documents', 'attendance'];
if (!in_array($table, $allowedTables, true) || $id <= 0 || $userId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters.']);
    exit;
}

if (!canRestore($table)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'No permission to restore records.']);
    exit;
}

try {
    $db = (new Database())->connect();
    $success = false;

    switch ($table) {
        case 'members':
            $success = (new Members($db))->restore($id, $userId);
            break;
        case 'events':
            $success = (new Events($db))->restore($id, $userId);
            break;
        case 'trainings':
            $success = (new Trainings($db))->restore($id, $userId);
            break;
        case 'funds':
            $success = (new Funds($db))->restore($id, $userId);
            break;
        case 'achievements':
            $success = (new Achievements($db))->restore($id, $userId);
            break;
        case 'attendance':
            $success = (new Attendance($db))->restore($id, $userId);
            break;
        case 'event_documents':
            $stmt = $db->prepare(
                "UPDATE event_documents
                 SET is_voided = 0, void_reason = NULL, voided_at = NULL, voided_by = NULL,
                     updated_at = NOW(), updated_by = :restored_by
                 WHERE document_id = :id AND is_voided = 1"
            );
            $stmt->execute([':restored_by' => $userId, ':id' => $id]);
            $success = $stmt->rowCount() > 0;
            break;
    }

    if ($success) {
        AuditLog::record(
            $db,
            'restore',
            $table,
            $id,
            'Restored voided ' . str_replace('_', ' ', $table) . ' record #' . $id
        );
        echo json_encode(['success' => true, 'message' => ucfirst($table) . ' restored successfully.']);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Record not found or already active.']);
    }
} catch (PDOException $e) {
    error_log('restore_record DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
} catch (Throwable $e) {
    error_log('restore_record error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to restore record.']);
}