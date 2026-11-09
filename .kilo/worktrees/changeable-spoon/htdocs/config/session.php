<?php
declare(strict_types=1);

const ARNIS_SESSION_IDLE_TIMEOUT = 1800;
const ARNIS_SESSION_REGENERATION_INTERVAL = 900;

function arnisIsHttps(): bool
{
    return !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
}

function arnisStartSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', arnisIsHttps() ? '1' : '0');
    ini_set('session.gc_maxlifetime', (string)ARNIS_SESSION_IDLE_TIMEOUT);

    session_name('arnis_connect_sid');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => arnisIsHttps(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

function arnisSessionFingerprint(): string
{
    $userAgent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    return hash('sha256', $userAgent);
}

function arnisSetAuthenticatedSession(array $user): void
{
    arnisStartSession();
    session_regenerate_id(true);

    $_SESSION = [
        'user_id' => (int)$user['user_id'],
        'username' => (string)$user['username'],
        'full_name' => (string)$user['full_name'],
        'role' => (string)$user['role'],
        'member_id' => isset($user['member_id']) && $user['member_id'] !== null ? (int)$user['member_id'] : null,
        'session_version' => isset($user['session_version']) ? (int)$user['session_version'] : 1,
        'authenticated_at' => time(),
        'last_activity' => time(),
        'last_rotation' => time(),
        'fingerprint' => arnisSessionFingerprint(),
    ];
}

function arnisRefreshSessionActivity(): void
{
    arnisStartSession();
    $_SESSION['last_activity'] = time();

    $lastRotation = (int)($_SESSION['last_rotation'] ?? 0);
    if ($lastRotation === 0 || (time() - $lastRotation) >= ARNIS_SESSION_REGENERATION_INTERVAL) {
        session_regenerate_id(true);
        $_SESSION['last_rotation'] = time();
    }
}

function arnisDestroySession(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $params['path'] ?? '/',
        'domain' => $params['domain'] ?? '',
        'secure' => (bool)($params['secure'] ?? arnisIsHttps()),
        'httponly' => (bool)($params['httponly'] ?? true),
        'samesite' => 'Lax',
    ]);

    $_SESSION = [];
    session_destroy();
}

function arnisCsrfToken(): string
{
    arnisStartSession();

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string)$_SESSION['csrf_token'];
}

function arnisVerifyCsrfToken(?string $token): bool
{
    arnisStartSession();

    $stored = (string)($_SESSION['csrf_token'] ?? '');
    return $stored !== '' && is_string($token) && hash_equals($stored, $token);
}

function arnisJsonError(string $message, int $statusCode = 400): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_SLASHES);
    exit;
}
