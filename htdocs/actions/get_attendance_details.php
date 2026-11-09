<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Content-Type: application/json; charset=UTF-8');

function attendanceDetailsError(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

/*
 * Previously there was no permission check, so
 * get_attendance_details.php?member_id=1&status=Present returned any member's
 * full attendance history to any logged-in account.
 */
requirePermission('members', 'read');

$rawMemberId = $_GET['member_id'] ?? null;
$rawStatus = $_GET['status'] ?? null;

if (!is_string($rawMemberId) || !is_numeric($rawMemberId)) {
    attendanceDetailsError(400, 'Invalid member ID.');
}

$memberId = (int)$rawMemberId;
$statusType = is_string($rawStatus) ? trim($rawStatus) : '';

if ($memberId <= 0) {
    attendanceDetailsError(400, 'Invalid member ID.');
}
if (!in_array($statusType, ['Present', 'Late', 'Absent'], true)) {
    attendanceDetailsError(400, 'Invalid status.');
}

/* members.read === 'own' for Member and Applicant. */
if (permissionValue('members', 'read') === 'own') {
    $selfMemberId = (isset($_SESSION['member_id']) && $_SESSION['member_id'] !== null && $_SESSION['member_id'] !== '')
        ? (int)$_SESSION['member_id']
        : null;
    if ($selfMemberId === null) {
        attendanceDetailsError(403, 'No member record is linked to your account.');
    }
    if ($selfMemberId !== $memberId) {
        attendanceDetailsError(403, 'You may only view your own attendance records.');
    }
}

try {
    $db = (new Database())->connect();

    /*
     * trainings.coach_id stores members.member_id, so the LEFT JOIN key is
     * correct. start_time is nullable, and MySQL sorts NULL first in DESC,
     * which pushed untimed sessions above real ones - hence the explicit
     * "NULLs last" ordering term.
     */
    $stmt = $db->prepare("
        SELECT
            a.attendance_id,
            a.status,
            a.recorded_at,
            a.remarks,
            t.training_id,
            t.training_date,
            t.training_type,
            t.location,
            t.start_time,
            t.end_time,
            m.full_name AS coach_name,
            m.rank AS coach_rank
        FROM attendance a
        INNER JOIN trainings t ON a.training_id = t.training_id
        LEFT JOIN members m ON t.coach_id = m.member_id
        WHERE a.member_id = :member_id
          AND a.status = :status
          AND a.is_voided = 0
          AND t.is_voided = 0
        ORDER BY t.training_date DESC, (t.start_time IS NULL) ASC, t.start_time DESC
        LIMIT 50
    ");

    $stmt->execute([
        ':member_id' => $memberId,
        ':status' => $statusType,
    ]);

    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /*
     * training_date, location, start_time and coach_name all reach the modal's
     * template literals. JSON_HEX_TAG|JSON_HEX_AMP keeps a stray "</td>" or "&"
     * in a member-controlled string from breaking out of the rendered markup.
     */
    echo json_encode([
        'success' => true,
        'data' => $records,
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
} catch (PDOException $e) {
    error_log('get_attendance_details DB error: ' . $e->getMessage());
    attendanceDetailsError(500, 'Unable to load attendance records.');
} catch (Throwable $e) {
    error_log('get_attendance_details error: ' . $e->getMessage());
    attendanceDetailsError(500, 'Unable to load attendance records.');
}