<?php
declare(strict_types=1);

/*
 * USER REMOVAL — SuperAdmin (Admin) only.
 *
 * Three distinct, role-aware behaviours. There is NO broad destructive
 * CASCADE: historical training / event / attendance / participation /
 * achievement / audit rows are always preserved.
 *
 *   1) APPLICANT (self-registered login)
 *        Hard-deletes the account AND its own pending registration request,
 *        plus the request's exclusive temporary data: rank_verification_history
 *        rows for that request (also FK CASCADE) and the uploaded
 *        uploads/rank_evidence/<file> evidence file on disk. Unrelated
 *        records are never touched.
 *
 *   2) MEMBER-LINKED login (Head Coach / Coach / Member-Player / any account
 *      with users.member_id):
 *        ARCHIVES instead of deleting.
 *          - users.is_active = 0 (login revoked, session invalidated)
 *          - members.is_voided = 1 (+ voided_at / voided_by / void_reason)
 *          - future / in-progress trainings they led  -> coach_id cleared,
 *            needs_coach = 1, previous_coach_id remembered (Admin alert)
 *          - future / in-progress events they handled -> handler_id cleared,
 *            needs_handler = 1, previous_handler_id remembered
 *        Attendance, achievements, event participation and rank history are
 *        kept so the member's record and past attribution survive.
 *
 *   3) PLAIN login with no member link and not an Applicant (Finance/Admin):
 *        hard-deletes the row. Actor/audit columns elsewhere are ON DELETE
 *        SET NULL, so this can never destroy trainings, events or funds.
 *
 * Blocked when: target is yourself or the last active SuperAdmin.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/Users.php';
require_once __DIR__ . '/../classes/AuditLog.php';

if (!function_exists('arnisHasColumn')) {
    /**
     * Feature-detect a column so the archive step degrades gracefully on a
     * database where migration 002 has not been imported yet.
     */
    function arnisHasColumn(PDO $db, string $table, string $column): bool
    {
        try {
            $stmt = $db->prepare(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
            );
            $stmt->execute([':t' => $table, ':c' => $column]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

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

if (!hasRole('SuperAdmin')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit;
}

try {
    $userId = isset($_POST['user_id']) && is_numeric($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
    $performedBy = (int)($_SESSION['user_id'] ?? 0);

    if ($userId <= 0 || $performedBy <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing required fields.']);
        exit;
    }

    if ($userId === $performedBy) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'You cannot remove your own account.']);
        exit;
    }

    $db = (new Database())->connect();
    $usersModel = new Users($db);

    $stmt = $db->prepare('SELECT user_id, full_name, role, is_active, member_id FROM users WHERE user_id = :id LIMIT 1');
    $stmt->execute([':id' => $userId]);
    $target = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$target) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'User not found.']);
        exit;
    }

    if ($target['role'] === 'SuperAdmin' && (int)$target['is_active'] === 1
        && $usersModel->countActiveSuperAdmins() <= 1) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'You cannot remove the last active SuperAdmin.']);
        exit;
    }

    $memberId = isset($target['member_id']) && $target['member_id'] !== null
        ? (int)$target['member_id']
        : 0;
    $role = (string)$target['role'];

    /* ============================================================== 1) APPLICANT */
    if ($role === 'Applicant') {
        $evidencePaths = [];
        try {
            $db->beginTransaction();

            $pathStmt = $db->prepare(
                "SELECT rank_evidence_path FROM registration_requests
                 WHERE user_id = :user_id AND rank_evidence_path IS NOT NULL AND rank_evidence_path <> ''"
            );
            $pathStmt->execute([':user_id' => $userId]);
            foreach ($pathStmt->fetchAll(PDO::FETCH_COLUMN) as $p) {
                $evidencePaths[] = (string)$p;
            }

            // rank_verification_history rows are request-scoped (FK CASCADE);
            // delete explicitly too so it also works on older schemas.
            $db->prepare(
                'DELETE h FROM rank_verification_history h
                 JOIN registration_requests r ON r.request_id = h.request_id
                 WHERE r.user_id = :user_id AND r.status = \'Pending\''
            )->execute([':user_id' => $userId]);

            // Only this applicant's own registration request(s) — reviewed
            // requests belong to a converted member and are NOT removed here.
            $db->prepare(
                "DELETE FROM registration_requests WHERE user_id = :user_id AND status = 'Pending'"
            )->execute([':user_id' => $userId]);

            $deleted = $usersModel->delete($userId);
            $db->commit();
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if ($e->getCode() === '23000') {
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => 'This account is still referenced by protected records. Deactivate it instead of deleting.']);
                exit;
            }
            throw $e;
        }

        // Best-effort removal of the exclusive evidence files after commit.
        foreach ($evidencePaths as $storedPath) {
            $normalized = str_replace('\\', '/', trim($storedPath));
            $normalized = preg_replace('#^\.\./#', '', $normalized) ?? $normalized;
            $normalized = preg_replace('#^uploads/rank_evidence/#i', '', $normalized) ?? $normalized;
            if ($normalized === '' || str_contains($normalized, '..')) {
                continue;
            }
            $abs = __DIR__ . '/../uploads/rank_evidence/' . basename($normalized);
            if (is_file($abs)) {
                @unlink($abs);
            }
        }

        if ($deleted) {
            AuditLog::record(
                $db,
                'delete',
                'users',
                $userId,
                'Deleted applicant account "' . (string)($target['full_name'] ?? ('#' . $userId))
                    . '" and its pending registration request'
            );
        }

        echo json_encode([
            'success' => $deleted,
            'message' => $deleted
                ? 'Applicant account, its pending registration request and temporary evidence were removed.'
                : 'Nothing to remove.',
        ]);
        exit;
    }

    /* ==================================================== 2) MEMBER-LINKED (ARCHIVE) */
    if ($memberId > 0) {
        $unassignedTrainings = 0;
        $unassignedEvents = 0;
        $hasTrainingFlag = arnisHasColumn($db, 'trainings', 'needs_coach');
        $hasEventFlag = arnisHasColumn($db, 'events', 'needs_handler');

        $db->beginTransaction();

        if ($hasTrainingFlag) {
            $upd = $db->prepare(
                "UPDATE trainings
                 SET coach_id = NULL, needs_coach = 1, previous_coach_id = :member_id,
                     updated_at = NOW(), updated_by = :by
                 WHERE coach_id = :member_id AND is_voided = 0 AND training_date >= CURDATE()"
            );
            $upd->execute([':member_id' => $memberId, ':by' => $performedBy]);
            $unassignedTrainings = $upd->rowCount();
        } else {
            $upd = $db->prepare(
                "UPDATE trainings SET coach_id = NULL, updated_at = NOW(), updated_by = :by
                 WHERE coach_id = :member_id AND is_voided = 0 AND training_date >= CURDATE()"
            );
            $upd->execute([':member_id' => $memberId, ':by' => $performedBy]);
            $unassignedTrainings = $upd->rowCount();
        }

        if ($hasEventFlag) {
            $upd = $db->prepare(
                "UPDATE events
                 SET handler_id = NULL, needs_handler = 1, previous_handler_id = :member_id,
                     updated_at = NOW(), updated_by = :by
                 WHERE handler_id = :member_id AND is_voided = 0 AND event_date >= CURDATE()"
            );
            $upd->execute([':member_id' => $memberId, ':by' => $performedBy]);
            $unassignedEvents = $upd->rowCount();
        } else {
            $upd = $db->prepare(
                "UPDATE events SET handler_id = NULL, updated_at = NOW(), updated_by = :by
                 WHERE handler_id = :member_id AND is_voided = 0 AND event_date >= CURDATE()"
            );
            $upd->execute([':member_id' => $memberId, ':by' => $performedBy]);
            $unassignedEvents = $upd->rowCount();
        }

        // Revoke the login (also invalidates any live session).
        $usersModel->deactivate($userId);

        // Archive (soft-delete) the member record; history stays intact.
        $db->prepare(
            "UPDATE members
             SET is_voided = 1, voided_at = NOW(), voided_by = :by,
                 void_reason = :reason, updated_at = NOW(), updated_by = :by
             WHERE member_id = :member_id AND is_voided = 0"
        )->execute([
            ':by' => $performedBy,
            ':reason' => 'Account archived: linked login removed by admin.',
            ':member_id' => $memberId,
        ]);

        $db->commit();

        AuditLog::record(
            $db,
            'delete',
            'users',
            $userId,
            'Archived member-linked account "' . (string)($target['full_name'] ?? ('#' . $userId))
                . '" and deactivated member #' . $memberId,
            [
                'archived' => true,
                'member_id' => $memberId,
                'unassigned_trainings' => $unassignedTrainings,
                'unassigned_events' => $unassignedEvents,
            ]
        );

        $parts = [];
        if ($unassignedTrainings > 0) {
            $parts[] = $unassignedTrainings . ' upcoming training session' . ($unassignedTrainings === 1 ? '' : 's');
        }
        if ($unassignedEvents > 0) {
            $parts[] = $unassignedEvents . ' upcoming event' . ($unassignedEvents === 1 ? '' : 's');
        }
        $tail = $parts
            ? ' ' . implode(' and ', $parts) . ' now need a replacement coach/handler.'
            : '';

        echo json_encode([
            'success' => true,
            'archived' => true,
            'message' => 'Account archived and member deactivated. History preserved.' . $tail,
        ]);
        exit;
    }

    /* ==================================================== 3) PLAIN login (no member) */
    $deleted = $usersModel->delete($userId);
    if ($deleted) {
        AuditLog::record(
            $db,
            'delete',
            'users',
            $userId,
            'Permanently deleted account "' . (string)($target['full_name'] ?? ('#' . $userId))
                . '" (' . $role . ')'
        );
        echo json_encode(['success' => true, 'message' => 'User permanently deleted.']);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to delete user.']);
    }
} catch (Throwable $e) {
    error_log('process_delete_user error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An unexpected error occurred. Please try again.']);
}
