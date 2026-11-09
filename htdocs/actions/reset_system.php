<?php
declare(strict_types=1);

/*
 * FULL SYSTEM RESET — SuperAdmin only.
 * Wipes every test/experiment row but keeps ALL SuperAdmin login accounts
 * (role = 'SuperAdmin') so you can always sign back in.
 * Requires typing RESET in the confirm box + a valid CSRF token.
 * TRUNCATE resets AUTO_INCREMENT, so ids start clean at 1.
 * ALWAYS download a backup first — this cannot be undone.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';

const ARNIS_RESET_TABLES = [
    'achievements',
    'attendance',
    'attendance_checkins',
    'event_documents',
    'event_participants',
    'funds',
    'events',
    'trainings',
    'rank_history',
    'rank_verification_history',
    'registration_requests',
    'members',
];

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
    $confirmation = trim((string)($_POST['confirmation'] ?? ''));
    if ($confirmation !== 'RESET') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Type RESET to confirm.']);
        exit;
    }

    $db = (new Database())->connect();

    $kept = (int)$db->query("SELECT COUNT(*) FROM users WHERE role = 'SuperAdmin'")->fetchColumn();
    if ($kept < 1) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No SuperAdmin account exists — reset blocked to avoid lockout.']);
        exit;
    }

    $db->exec('SET FOREIGN_KEY_CHECKS = 0');
    try {
        foreach (ARNIS_RESET_TABLES as $table) {
            $db->exec("TRUNCATE TABLE `{$table}`");
        }
        /* Keep every SuperAdmin login; remove all other accounts. */
        $deletedUsers = $db->exec("DELETE FROM users WHERE role <> 'SuperAdmin'");
    } finally {
        $db->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    error_log('System reset performed by user_id ' . (int)($_SESSION['user_id'] ?? 0)
        . " — removed {$deletedUsers} non-admin users, kept {$kept} SuperAdmin(s).");

    echo json_encode([
        'success' => true,
        'message' => "System wiped clean. {$kept} SuperAdmin login(s) kept — all members, trainings, events, funds and other users were removed.",
    ]);
} catch (Throwable $e) {
    error_log('reset_system error: ' . $e->getMessage());
    try {
        (new Database())->connect()->exec('SET FOREIGN_KEY_CHECKS = 1');
    } catch (Throwable $ignored) {
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Reset failed. Please try again.']);
}