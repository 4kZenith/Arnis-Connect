<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/Database.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$username = isset($_GET['username']) ? trim((string)$_GET['username']) : '';
if ($username === '' || !preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
    echo json_encode(['available' => false, 'message' => 'Invalid username format']);
    exit;
}

try {
    $db = (new Database())->connect();
    // Check users table
    $stmt = $db->prepare('SELECT user_id FROM users WHERE username = :username LIMIT 1');
    $stmt->execute([':username' => $username]);
    if ($stmt->fetch()) {
        echo json_encode(['available' => false, 'message' => 'Username is already taken']);
        exit;
    }
    // Check pending applications
    $stmt = $db->prepare("SELECT request_id FROM registration_requests WHERE status = 'Pending' AND username = :username LIMIT 1");
    $stmt->execute([':username' => $username]);
    if ($stmt->fetch()) {
        echo json_encode(['available' => false, 'message' => 'Username has a pending application']);
        exit;
    }
    echo json_encode(['available' => true, 'message' => 'Username available']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['available' => false, 'message' => 'Error checking username']);
}