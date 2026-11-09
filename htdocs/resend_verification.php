<?php
declare(strict_types=1);

require_once __DIR__ . '/config/session.php';
arnisStartSession();
require_once __DIR__ . '/config/Database.php';
require_once __DIR__ . '/classes/Mailer.php';
require_once __DIR__ . '/classes/AuditLog.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');

if (isset($_SESSION['user_id'])) {
    header('Location: public/dashboard.php');
    exit;
}

$notice = '';
$noticeType = 'success';
$submitted = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!arnisVerifyCsrfToken(isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : null)) {
        $notice = 'Your session expired. Please reload the page and try again.';
        $noticeType = 'error';
    } elseif ((int)($_SESSION['verification_resend_at'] ?? 0) > time() - 60) {
        $notice = 'Please wait a minute before requesting another verification email.';
        $noticeType = 'error';
    } else {
        $identifier = trim((string)($_POST['identifier'] ?? ''));
        $_SESSION['verification_resend_at'] = time();

        if ($identifier !== '') {
            try {
                $db = (new Database())->connect();
                $stmt = $db->prepare(
                    'SELECT user_id, full_name, username, email, role
                     FROM users
                     WHERE (username = :username OR email = :email)
                       AND email_verification_token IS NOT NULL
                       AND email_verified_at IS NULL
                     LIMIT 1'
                );
                $stmt->execute([':username' => $identifier, ':email' => $identifier]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if (is_array($user) && filter_var((string)($user['email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
                    $token = bin2hex(random_bytes(32));
                    $expiresAt = date('Y-m-d H:i:s', time() + 86400);

                    $update = $db->prepare(
                        'UPDATE users
                         SET email_verification_token = :token,
                             email_verification_expires_at = :expires_at,
                             updated_at = NOW()
                         WHERE user_id = :user_id'
                    );
                    $update->execute([
                        ':token' => $token,
                        ':expires_at' => $expiresAt,
                        ':user_id' => (int)$user['user_id'],
                    ]);

                    $mailer = new Mailer($db);
                    $mailer->sendVerification((string)$user['email'], (string)$user['full_name'], $token);

                    AuditLog::recordForUser(
                        $db,
                        (int)$user['user_id'],
                        (string)$user['full_name'],
                        (string)($user['role'] ?? 'Applicant'),
                        'resend_verification',
                        'auth',
                        (int)$user['user_id'],
                        'Requested a new verification email for ' . (string)$user['username']
                    );
                }
            } catch (Throwable $e) {
                error_log('resend_verification.php error: ' . $e->getMessage());
            }
        }

        /*
         * Always show the same message so this page cannot be used to discover
         * which email addresses or usernames have unverified accounts.
         */
        $submitted = true;
        $notice = 'If an unverified account matches that username or email, a new verification link has been sent. Check your inbox and spam folder.';
        $noticeType = 'success';
    }
}

$csrfToken = arnisCsrfToken();
?>
<!DOCTYPE html>
<html lang="en" class="text-[13px]">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resend Verification | Arnis Connect</title>
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
                <i class="fas fa-envelope text-2xl"></i>
            </div>
            <h1 class="text-2xl font-black uppercase tracking-tighter italic text-white">Resend <span class="text-purple-500">Verification</span></h1>
            <p class="text-gray-400 mt-2 text-xs">Enter the username or email you registered with.</p>
        </div>

        <?php if ($notice !== ''): ?>
        <div class="mb-4 p-4 rounded-2xl border text-sm font-semibold <?= $noticeType === 'success' ? 'bg-emerald-500/10 border-emerald-500/20 text-emerald-300' : 'bg-red-500/10 border-red-500/20 text-red-300' ?>">
            <?= htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') ?>
        </div>
        <?php endif; ?>

        <div class="card-bg p-8 rounded-[2rem] shadow-2xl">
            <form method="POST" action="resend_verification.php" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <div>
                    <label for="identifier" class="block text-[10px] uppercase tracking-[0.16em] font-black text-gray-500 mb-2">Username or Email</label>
                    <input type="text" id="identifier" name="identifier" required maxlength="100" autocomplete="username" class="form-input w-full rounded-2xl px-4 py-4 text-sm" placeholder="juan_delacruz or you@example.com">
                </div>
                <button type="submit" class="w-full bg-purple-600 hover:bg-purple-500 text-white font-black uppercase text-[11px] tracking-[0.2em] py-4 rounded-2xl transition">
                    <i class="fas fa-paper-plane mr-2"></i>Send Verification Link
                </button>
            </form>

            <div class="mt-5 text-center">
                <a href="index.php" class="text-xs uppercase tracking-[0.16em] font-black text-gray-400 hover:text-white transition">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Login
                </a>
            </div>
        </div>
    </main>
</body>
</html>
