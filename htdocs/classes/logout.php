<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/session.php';
arnisStartSession();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: text/plain; charset=utf-8');
    exit('Method Not Allowed');
}

if (isset($_SESSION['user_id'])) {
    if (!arnisVerifyCsrfToken(isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : null)) {
        // Logged for audit only. A forced logout is a low-severity CSRF (OWASP
        // CSRF cheat sheet), and hard-failing here would strand the user in a
        // session they explicitly asked to end, with a blank 403 page.
        error_log('Arnis Connect logout CSRF failure for user_id=' . (int)$_SESSION['user_id']);
    }

    /* Record the sign-out before the session (and actor identity) is cleared. */
    try {
        require_once __DIR__ . '/../config/Database.php';
        require_once __DIR__ . '/AuditLog.php';
        $logoutDb = (new Database())->connect();
        AuditLog::record(
            $logoutDb,
            'logout',
            'auth',
            (int)$_SESSION['user_id'],
            'Signed out'
        );
    } catch (Throwable $e) {
        error_log('Logout audit failed: ' . $e->getMessage());
    }}

arnisDestroySession();

header('Location: ../index.php');
exit;