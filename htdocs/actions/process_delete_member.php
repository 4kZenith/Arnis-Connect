<?php
declare(strict_types=1);

/*
 * Permanently remove a member and every login tied to that profile.
 * After this they no longer appear in Members and cannot sign in.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/AuditLog.php';
require_once __DIR__ . '/../classes/Users.php';
require_once __DIR__ . '/../classes/PrivateTrainings.php';
require_once __DIR__ . '/../classes/RankPromotions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

if (!arnisVerifyCsrfToken(isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : null)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
    exit;
}

if (!hasRole('SuperAdmin')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Only the SuperAdmin can delete a member.']);
    exit;
}

$memberId = isset($_POST['member_id']) && is_numeric($_POST['member_id']) ? (int)$_POST['member_id'] : 0;
$performedBy = (int)($_SESSION['user_id'] ?? 0);
if ($memberId <= 0 || $performedBy <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid member.']);
    exit;
}

try {
    $db = (new Database())->connect();
    $memberStmt = $db->prepare('SELECT member_id, full_name FROM members WHERE member_id = :id LIMIT 1');
    $memberStmt->execute([':id' => $memberId]);
    $member = $memberStmt->fetch(PDO::FETCH_ASSOC);
    if (!$member) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Member not found.']);
        exit;
    }

    $userStmt = $db->prepare('SELECT user_id, full_name, role, is_active FROM users WHERE member_id = :id');
    $userStmt->execute([':id' => $memberId]);
    $accounts = $userStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($accounts as $account) {
        if ((int)$account['user_id'] === $performedBy) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'You cannot delete your own account from here.']);
            exit;
        }
    }

    $superAdmins = 0;
    foreach ($accounts as $account) {
        if (($account['role'] ?? '') === 'SuperAdmin' && (int)$account['is_active'] === 1) {
            $superAdmins++;
        }
    }
    if ($superAdmins > 0 && (new Users($db))->countActiveSuperAdmins() <= $superAdmins) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'You cannot remove the last active SuperAdmin.']);
        exit;
    }

    $proofFiles = [];
    try {
        $proofStmt = $db->prepare(
            "SELECT proof_path FROM rank_promotion_requests
             WHERE member_id = :id AND proof_path IS NOT NULL AND proof_path <> ''"
        );
        $proofStmt->execute([':id' => $memberId]);
        $proofFiles = $proofStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        $proofFiles = [];
    }

    $receiptFiles = [];
    try {
        $pathStmt = $db->prepare(
            "SELECT receipt_path FROM private_training_requests
             WHERE member_id = :id AND receipt_path IS NOT NULL AND receipt_path <> ''"
        );
        $pathStmt->execute([':id' => $memberId]);
        $receiptFiles = $pathStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        $receiptFiles = [];
    }

    $db->beginTransaction();

    $db->prepare('UPDATE trainings SET coach_id = NULL WHERE coach_id = :id')->execute([':id' => $memberId]);
    $db->prepare('UPDATE events SET handler_id = NULL WHERE handler_id = :id')->execute([':id' => $memberId]);

    $ptr = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = :t'
    );
    $ptr->execute([':t' => 'private_training_requests']);
    if ((int)$ptr->fetchColumn() > 0) {
        $db->prepare('UPDATE private_training_requests SET preferred_coach_id = NULL WHERE preferred_coach_id = :id')->execute([':id' => $memberId]);
        $db->prepare('UPDATE private_training_requests SET coach_id = NULL WHERE coach_id = :id')->execute([':id' => $memberId]);
    }

    foreach ([
        'attendance',
        'attendance_checkins',
        'event_participants',
        'achievements',
        'rank_history',
        'private_training_requests',
    ] as $table) {
        $exists = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = :t'
        );
        $exists->execute([':t' => $table]);
        if ((int)$exists->fetchColumn() === 0) {
            continue;
        }
        $db->prepare("DELETE FROM `{$table}` WHERE member_id = :id")->execute([':id' => $memberId]);
    }

    $deleteLogins = $db->prepare('DELETE FROM users WHERE member_id = :id');
    $deleteLogins->execute([':id' => $memberId]);
    $loginsDeleted = $deleteLogins->rowCount();

    $db->prepare('DELETE FROM members WHERE member_id = :id')->execute([':id' => $memberId]);
    $db->commit();

    foreach ($receiptFiles as $receiptPath) {
        PrivateTrainings::unlinkReceipt((string)$receiptPath);
    }
    foreach ($proofFiles as $proofPath) {
        RankPromotions::unlinkProof((string)$proofPath);
    }

    AuditLog::record(
        $db,
        'delete',
        'members',
        $memberId,
        'Permanently deleted member "' . (string)$member['full_name'] . '" and ' . $loginsDeleted . ' linked login(s)'
    );

    echo json_encode([
        'success' => true,
        'message' => (string)$member['full_name'] . ' was deleted. Their login can no longer sign in.',
    ]);
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('process_delete_member error: ' . $e->getMessage());
    $safe = $e instanceof PDOException && $e->getCode() === '23000';
    http_response_code($safe ? 409 : 500);
    echo json_encode([
        'success' => false,
        'message' => $safe
            ? 'This member is still linked to a protected record and could not be deleted.'
            : 'The member could not be deleted. Please try again.',
    ]);
}
