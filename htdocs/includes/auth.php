<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/session.php';
arnisStartSession();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Content-Security-Policy: frame-ancestors \'self\';');
header('Referrer-Policy: same-origin');

/**
 * Mandatory CSRF gate for state-changing endpoints.
 *
 * The old pattern in several action files was:
 *   if (isset($_POST['csrf_token']) && $_POST['csrf_token'] !== '') { verify... }
 * which SKIPPED verification entirely when the field was missing. A missing
 * token must fail, not pass. Call this at the top of every POST action.
 */
if (!function_exists('arnisRequireCsrf')) {
    function arnisRequireCsrf(bool $json = false): void
    {
        $token = $_POST['csrf_token'] ?? null;
        if (!is_string($token) || $token === '' || !arnisVerifyCsrfToken($token)) {
            http_response_code(403);
            if ($json) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => false,
                    'message' => 'Invalid or missing security token. Please refresh and try again.',
                ], JSON_UNESCAPED_UNICODE);
            } else {
                echo 'Invalid or missing security token. Please refresh and try again.';
            }
            exit;
        }
    }
}

if (!function_exists('arnisClientIp')) {
    function arnisClientIp(): string
    {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        return substr($ip, 0, 45);
    }
}

if (!function_exists('arnisAppUrl')) {
    /**
     * Absolute URL for a path in the application root (e.g. verify_email.php).
     * Falls back to a relative path when the host is unavailable.
     */
    function arnisAppUrl(string $path = ''): string
    {
        $path = ltrim($path, '/');
        $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
        $base = '';
        foreach (['/actions/', '/classes/', '/includes/', '/config/', '/public/'] as $marker) {
            $pos = strpos($script, $marker);
            if ($pos !== false) {
                $base = substr($script, 0, $pos);
                break;
            }
        }
        if ($base === '') {
            $base = rtrim(str_replace('\\', '/', dirname($script)), '/');
            if ($base === '.' || $base === '/') {
                $base = '';
            }
        }

        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        if ($host === '') {
            return $base . '/' . $path;
        }
        $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
        return $scheme . '://' . $host . $base . '/' . $path;
    }
}

if (!function_exists('arnisLoginUrl')) {
    function arnisLoginUrl(string $query = ''): string
    {
        $script = (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php');
        $script = str_replace('\\', '/', $script);
        $base = '';
        foreach (['/public/', '/actions/', '/classes/', '/includes/', '/config/'] as $marker) {
            $pos = strpos($script, $marker);
            if ($pos !== false) {
                $base = substr($script, 0, $pos);
                break;
            }
        }
        if ($base === '') {
            $dir = str_replace('\\', '/', (string)dirname($script));
            if ($dir !== '' && $dir !== '/' && $dir !== '.') {
                $base = $dir;
            }
        }
        if ($base === '/' || $base === '.') {
            $base = '';
        }
        return $base . '/index.php' . $query;
    }
}

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . arnisLoginUrl());
    exit;
}

$lastActivity = (int)($_SESSION['last_activity'] ?? 0);
if ($lastActivity <= 0 || (time() - $lastActivity) > ARNIS_SESSION_IDLE_TIMEOUT) {
    arnisDestroySession();
    header('Location: ' . arnisLoginUrl('?error=timeout'));
    exit;
}

$expectedFingerprint = (string)($_SESSION['fingerprint'] ?? '');
if ($expectedFingerprint === '' || !hash_equals($expectedFingerprint, arnisSessionFingerprint())) {
    arnisDestroySession();
    header('Location: ' . arnisLoginUrl('?error=session'));
    exit;
}

try {
    require_once __DIR__ . '/../config/Database.php';
    $db = (new Database())->connect();
    $stmt = $db->prepare('
        SELECT user_id, full_name, username, email, role, is_active, member_id, session_version
        FROM users
        WHERE user_id = :user_id
        LIMIT 1
    ');
    $stmt->execute([':user_id' => (int)$_SESSION['user_id']]);
    $currentUser = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Arnis Connect auth database check failed: ' . $e->getMessage());
    http_response_code(503);
    exit('System temporarily unavailable. Please try again later.');
}

if (!$currentUser || (int)$currentUser['is_active'] !== 1) {
    arnisDestroySession();
    header('Location: ' . arnisLoginUrl('?error=inactive'));
    exit;
}

$sessionVersion = (int)($_SESSION['session_version'] ?? 1);
if ($sessionVersion !== (int)$currentUser['session_version']) {
    arnisDestroySession();
    header('Location: ' . arnisLoginUrl('?error=session'));
    exit;
}

$_SESSION['username'] = (string)$currentUser['username'];
$_SESSION['full_name'] = (string)$currentUser['full_name'];
$_SESSION['role'] = (string)$currentUser['role'];
$_SESSION['member_id'] = $currentUser['member_id'] !== null ? (int)$currentUser['member_id'] : null;
$_SESSION['session_version'] = (int)$currentUser['session_version'];

arnisRefreshSessionActivity();