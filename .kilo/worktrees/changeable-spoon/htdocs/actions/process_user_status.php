<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/Users.php';
require_once __DIR__ . '/../classes/AuditLog.php';

/*
 * Redirect whitelist. The previous version built $referer from
 * $_SERVER['HTTP_REFERER'] and passed it straight to header('Location: ...'),
 * which is an open redirect (any external host could be injected via the
 * Referer header).
 */
$REDIRECT_WHITELIST = [
    'users.php'                      => '../public/users.php',
    '../public/users.php'            => '../public/users.php',
    '/public/users.php'              => '../public/users.php',
];

$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

$fallbackRedirect = '../public/users.php';

/** Resolve a safe, whitelisted redirect target. */
$resolveRedirect = static function () use ($REDIRECT_WHITELIST, $fallbackRedirect, $isAjax): string {
    if ($isAjax) {
        return '';
    }

    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    if (!is_string($referer) || $referer === '') {
        return $fallbackRedirect;
    }

    $path = parse_url($referer, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        return $fallbackRedirect;
    }

    $path = ltrim($path, '/');
    $key  = basename($path);

    return $REDIRECT_WHITELIST[$key] ?? $REDIRECT_WHITELIST[$path] ?? $fallbackRedirect;
};

/** Emit an error in whichever format the caller expects. */
$fail = static function (string $message, int $status = 403) use ($isAjax, $resolveRedirect): void {
    if ($isAjax) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_SLASHES);
    } else {
        $_SESSION['error'] = $message;
        header('Location: ' . $resolveRedirect());
    }
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $fail('Invalid request method.', 405);
}

if (!arnisVerifyCsrfToken($_POST['csrf_token'] ?? null)) {
    $fail('Invalid security token.');
}

/*
 * Account activation/deactivation is administrator-only. requirePermission()
 * would be wrong here: 'own' grants satisfy hasPermission().
 */
if (!hasRole('SuperAdmin')) {
    $fail('Access denied.');
}

try {
    $db = (new Database())->connect();
    $usersModel = new Users($db);

    $userId       = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
    $status       = isset($_POST['status']) ? (int)$_POST['status'] : null;
    $performedBy  = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

    if ($userId <= 0 || $status === null || $performedBy <= 0) {
        $fail('Missing required fields.', 400);
    }

    if ($status !== 0 && $status !== 1) {
        $fail('Invalid status value.', 400);
    }

    // Never lock yourself out.
    if ($userId === $performedBy) {
        $fail('You cannot change your own account status.', 400);
    }

    $existing = $db->prepare('SELECT user_id, full_name, username, role FROM users WHERE user_id = ?');
    $existing->execute([$userId]);
    $target = $existing->fetch(PDO::FETCH_ASSOC);

    if (!$target) {
        $fail('User not found.', 404);
    }

    $success = $status === 1        ? $usersModel->activate($userId)
        : $usersModel->deactivate($userId);

    if ($success) {
        $statusText = $status === 1 ? 'activated' : 'deactivated';

        AuditLog::record(
            $db,
            'status_change',
            'users',
            $userId,
            ucfirst($statusText) . ' account "' . (string)($target['username'] ?? ('#' . $userId)) . '"'
                . ' (' . (string)($target['full_name'] ?? '') . ', ' . (string)($target['role'] ?? '') . ')',
            ['is_active' => $status]
        );

        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => true,
                'message' => "User has been $statusText successfully.",
            ], JSON_UNESCAPED_SLASHES);
        } else {
            $_SESSION['success'] = "User has been $statusText successfully.";
            header('Location: ' . $resolveRedirect());
        }
        exit;
    }

    $fail('Failed to update user status.', 500);
} catch (Throwable $e) {
    // Never leak $e->getMessage() to the browser - it carries SQL and host details.
    error_log('process_user_status error: ' . $e->getMessage());
    $fail('An unexpected error occurred. Please try again.', 500);
}