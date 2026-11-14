<?php
declare(strict_types=1);

require_once __DIR__ . '/config/session.php';
arnisStartSession();
require_once __DIR__ . '/config/Database.php';
require_once __DIR__ . '/classes/AuditLog.php';
require_once __DIR__ . '/classes/PasswordReset.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

$token = isset($_REQUEST['token']) && is_string($_REQUEST['token']) ? trim($_REQUEST['token']) : '';
$error = '';
$valid = null;

try {
    $db = (new Database())->connect();
    $resets = new PasswordReset($db);
    $valid = $resets->findValid($token);

    if ($valid !== null && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $password = (string)($_POST['password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');

        if (!arnisVerifyCsrfToken(isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : null)) {
            $error = 'Your session expired. Please try again.';
        } elseif (strlen($password) < 8 || strlen($password) > 100) {
            $error = 'Password must be 8 to 100 characters.';
        } elseif (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            $error = 'Password must contain at least one letter and one number.';
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } elseif ($resets->complete($valid['reset_id'], $valid['user_id'], $password)) {
            $who = $db->prepare('SELECT full_name, username, role FROM users WHERE user_id = :id');
            $who->execute([':id' => $valid['user_id']]);
            $u = $who->fetch(PDO::FETCH_ASSOC) ?: [];
            AuditLog::recordForUser(
                $db,
                $valid['user_id'],
                (string)($u['full_name'] ?? ''),
                (string)($u['role'] ?? ''),
                'password_reset',
                'auth',
                $valid['user_id'],
                'Reset password by email link for ' . (string)($u['username'] ?? '')
            );
            header('Location: index.php?reset=1');
            exit;
        } else {
            $valid = null;
        }
    }
} catch (Throwable $e) {
    error_log('reset_password.php error: ' . $e->getMessage());
    $error = 'Password reset is temporarily unavailable. Please try again later.';
}

$csrfToken = arnisCsrfToken();
?>
<!DOCTYPE html>
<html lang="en" class="text-[13px]">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password | Arnis Connect</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" type="image/x-icon" href="arnis-favicon.ico">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,100..900;1,100..900&display=swap" rel="stylesheet">
    <style>
        :root { --dark-purple: #1d212a; --card-bg: #161a23; }
        body { font-family: 'Montserrat', sans-serif; background-color: var(--dark-purple); }
        .card-bg { background-color: var(--card-bg); border: 1px solid rgba(255,255,255,0.05); }
        .form-input { background: #1d212a; border: 1px solid transparent; transition: all 0.3s; color: white; }
        .form-input:focus { border-color: #a855f7; outline: none; box-shadow: 0 0 15px rgba(168, 85, 247, 0.1); }
    </style>
</head>
<body class="flex items-center justify-center min-h-screen p-4">
    <div class="fixed top-[-10%] left-[-10%] w-[40%] h-[40%] bg-purple-600/10 blur-[120px] rounded-full"></div>
    <div class="fixed bottom-[-10%] right-[-10%] w-[40%] h-[40%] bg-blue-600/10 blur-[120px] rounded-full"></div>

    <main class="relative z-10 w-full max-w-md">
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-purple-500/10 text-purple-400 border border-purple-500/20 mb-4 shadow-lg">
                <i class="fas fa-lock text-2xl"></i>
            </div>
            <h1 class="text-2xl font-black uppercase tracking-tighter italic text-white">Set New <span class="text-purple-500">Password</span></h1>
        </div>

        <?php if ($error !== ''): ?>
        <div class="mb-4 p-4 rounded-2xl border text-sm font-semibold bg-red-500/10 border-red-500/20 text-red-300">
            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
        </div>
        <?php endif; ?>

        <div class="card-bg p-8 rounded-[2rem] shadow-2xl">
            <?php if ($valid !== null): ?>
            <form method="POST" action="reset_password.php" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
                <div>
                    <label for="password" class="block text-[10px] uppercase tracking-[0.16em] font-black text-gray-500 mb-2">New Password</label>
                    <input type="password" id="password" name="password" required minlength="8" maxlength="100" autocomplete="new-password" class="form-input w-full rounded-2xl px-4 py-4 text-sm" placeholder="At least 8 characters, with a letter and a number">
                </div>
                <div>
                    <label for="confirm_password" class="block text-[10px] uppercase tracking-[0.16em] font-black text-gray-500 mb-2">Confirm Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" required minlength="8" maxlength="100" autocomplete="new-password" class="form-input w-full rounded-2xl px-4 py-4 text-sm">
                </div>
                <button type="submit" class="w-full bg-purple-600 hover:bg-purple-500 text-white font-black uppercase text-[11px] tracking-[0.2em] py-4 rounded-2xl transition">
                    <i class="fas fa-check mr-2"></i>Save New Password
                </button>
            </form>
            <?php else: ?>
            <p class="text-sm text-gray-300 leading-relaxed text-center mb-6">This reset link is invalid, already used, or expired. Request a new one.</p>
            <a href="forgot_password.php" class="block w-full text-center bg-purple-600 hover:bg-purple-500 text-white font-black uppercase text-[11px] tracking-[0.2em] py-4 rounded-2xl transition">
                Request New Link
            </a>
            <?php endif; ?>
            <div class="mt-5 text-center">
                <a href="index.php" class="text-xs uppercase tracking-[0.16em] font-black text-gray-400 hover:text-white transition">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Login
                </a>
            </div>
        </div>
    </main>
</body>
</html>
