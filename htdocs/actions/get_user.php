<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';

try {
    requirePermission('users', 'read');

    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        throw new RuntimeException('Invalid user ID.');
    }
    $userId = (int)$_GET['id'];
    if ($userId <= 0) {
        throw new RuntimeException('Invalid user ID.');
    }

    $perm = permissionValue('users', 'read');
    if ($perm === 'own' && $userId !== (int)($_SESSION['user_id'] ?? 0)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied.']);
        exit;
    }

    $db = (new Database())->connect();
    $stmt = $db->prepare(
        "SELECT user_id, full_name, username, email, member_id, role, is_active, created_at, updated_at
         FROM users
         WHERE user_id = :user_id
         LIMIT 1"
    );
    $stmt->execute([':user_id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        throw new RuntimeException('User not found.');
    }

    echo json_encode(['success' => true, 'data' => $user]);
} catch (RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (PDOException $e) {
    error_log('get_user DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error.']);
} catch (Throwable $e) {
    error_log('get_user error: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Failed to load user.']);
}