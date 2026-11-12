<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/session.php';
arnisStartSession();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

/** Failed attempts allowed per identifier before a temporary lockout. */
const ARNIS_LOGIN_MAX_ATTEMPTS = 5;

/** Seconds an identifier stays locked out once the attempt limit is reached. */
const ARNIS_LOGIN_LOCKOUT_SECONDS = 90;

/**
 * A syntactically valid bcrypt hash that matches no password.
 * password_verify() short-circuits to false for malformed hashes, so a
 * well-formed dummy is required to keep the response time constant on the
 * "unknown account" path and prevent username/email enumeration by timing.
 */
const ARNIS_LOGIN_DUMMY_HASH = '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';

function arnisLoginRedirect(?string $errorCode = null): void
{
    $location = ($errorCode === null || $errorCode === '')
        ? '../index.php'
        : '../index.php?error=' . $errorCode;
    header('Location: ' . $location);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    arnisLoginRedirect();
}

if (!arnisVerifyCsrfToken(isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : null)) {
    arnisLoginRedirect('security');
}

$login = trim((string)($_POST['username'] ?? ''));
$password = (string)($_POST['password'] ?? '');

if ($login === '' || $password === '') {
    arnisLoginRedirect('empty');
}

// Normalise the identifier so "Admin" and "admin" share one throttle bucket.
$throttleKey = substr(hash('sha256', strtolower($login)), 0, 32);

if (!isset($_SESSION['login_attempts']) || !is_array($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = [];
}
if (!isset($_SESSION['login_locked_until']) || !is_array($_SESSION['login_locked_until'])) {
    $_SESSION['login_locked_until'] = [];
}

if ((int)($_SESSION['login_locked_until'][$throttleKey] ?? 0) > time()) {
    arnisLoginRedirect('throttled');
}

try {
    require_once __DIR__ . '/../config/Database.php';
    require_once __DIR__ . '/AuditLog.php';
    // roles.php is a `return [...]` config file. Use require (not require_once)
    // so the returned array is always assigned - a second require_once would
    // return true instead of the array.
    $allowedRoles = array_keys(require __DIR__ . '/../config/roles.php');

    $conn = (new Database())->connect();

    $stmt = $conn->prepare('
        SELECT user_id, full_name, username, email, password_hash, role, is_active, member_id,
               session_version, email_verified_at, email_verification_token
        FROM users
        WHERE username = :username OR email = :email
        LIMIT 1
    ');
    $stmt->execute([':username' => $login, ':email' => $login]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Always verify a password so the success and failure paths take the same
    // amount of time, whether or not the account exists.
    $hash = is_array($user) && isset($user['password_hash'])
        ? (string)$user['password_hash']
        : ARNIS_LOGIN_DUMMY_HASH;
    $passwordValid = password_verify($password, $hash);

    if (!is_array($user) || !$passwordValid) {
        if (is_array($user)) {
            AuditLog::recordForUser(
                $conn,
                (int)$user['user_id'],
                (string)($user['full_name'] ?? ''),
                (string)($user['role'] ?? ''),
                'login_failed',
                'auth',
                (int)$user['user_id'],
                'Failed sign-in for ' . (string)($user['username'] ?? $login)
            );
        }

        $attempts = (int)($_SESSION['login_attempts'][$throttleKey] ?? 0) + 1;

        if ($attempts >= ARNIS_LOGIN_MAX_ATTEMPTS) {
            unset($_SESSION['login_attempts'][$throttleKey]);
            $_SESSION['login_locked_until'][$throttleKey] = time() + ARNIS_LOGIN_LOCKOUT_SECONDS;
            arnisLoginRedirect('throttled');
        }

        $_SESSION['login_attempts'][$throttleKey] = $attempts;
        arnisLoginRedirect('invalid');
    }

    if ((int)($user['is_active'] ?? 0) !== 1) {
        // Credentials were correct but the account is disabled. Send the code
        // that index.php has always handled for this case.
        arnisLoginRedirect('inactive');
    }

    /*
     * Applicants must always have a verified email. Other accounts are only
     * blocked while they carry a pending verification token, so staff and
     * members created by an admin (no token) are not locked out.
     */
    $isUnverified = $user['email_verified_at'] === null
        && (($user['role'] ?? '') === 'Applicant' || !empty($user['email_verification_token']));
    if ($isUnverified) {
        AuditLog::recordForUser(
            $conn,
            (int)$user['user_id'],
            (string)($user['full_name'] ?? ''),
            (string)($user['role'] ?? ''),
            'login_failed',
            'auth',
            (int)$user['user_id'],
            'Sign-in blocked: email not verified for ' . (string)($user['username'] ?? $login)
        );
        arnisLoginRedirect('unverified');
    }

    $role = (string)($user['role'] ?? '');
    if (!in_array($role, $allowedRoles, true)) {
        error_log('Arnis Connect login blocked: user_id=' . (int)$user['user_id']
            . ' has unmapped role "' . $role . '".');
        arnisLoginRedirect('service');
    }
    $user['role'] = $role;

    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        $newHash = password_hash($password, PASSWORD_DEFAULT);
        $rehash = $conn->prepare(
            'UPDATE users
             SET password_hash = :password_hash, session_version = session_version + 1, updated_at = NOW()
             WHERE user_id = :user_id'
        );
        $rehash->execute([
            ':password_hash' => $newHash,
            ':user_id' => (int)$user['user_id'],
        ]);
        // Bumping session_version in the database invalidates this user's other
        // sessions, so mirror it here or the new session dies on its first
        // auth.php check.
        $user['session_version'] = (int)($user['session_version'] ?? 1) + 1;
    }

    $user['session_version'] = (int)($user['session_version'] ?? 1);

    $loginUpdate = $conn->prepare('UPDATE users SET last_login_at = NOW() WHERE user_id = :user_id');
    $loginUpdate->execute([':user_id' => (int)$user['user_id']]);

    AuditLog::recordForUser(
        $conn,
        (int)$user['user_id'],
        (string)($user['full_name'] ?? ''),
        (string)($user['role'] ?? ''),
        'login',
        'auth',
        (int)$user['user_id'],
        'Signed in as ' . (string)($user['username'] ?? $login)
    );

    // arnisSetAuthenticatedSession() replaces $_SESSION wholesale, which clears
    // the throttle counters and the pre-auth CSRF token as well.
    arnisSetAuthenticatedSession($user);

    header('Location: ../public/dashboard.php');
    exit;
} catch (Throwable $e) {
    error_log('Arnis Connect login failure: ' . $e->getMessage());
    arnisLoginRedirect('service');
}