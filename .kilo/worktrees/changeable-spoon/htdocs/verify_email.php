<?php
declare(strict_types=1);

require_once __DIR__ . '/config/session.php';
arnisStartSession();
require_once __DIR__ . '/config/Database.php';
require_once __DIR__ . '/classes/AuditLog.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');

$token = isset($_GET['token']) && is_string($_GET['token']) ? trim($_GET['token']) : '';
$status = 'error';
$title = 'Verification failed';
$message = 'This verification link is invalid or has already been used.';

if (preg_match('/^[a-f0-9]{64}$/i', $token) === 1) {
    try {
        $db = (new Database())->connect();
        $stmt = $db->prepare(
            'SELECT user_id, full_name, username, role, email_verified_at,
                    email_verification_expires_at
             FROM users
             WHERE email_verification_token = :token
             LIMIT 1'
        );
        $stmt->execute([':token' => $token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (is_array($user)) {
            $expiresAt = (string)($user['email_verification_expires_at'] ?? '');
            $expired = $expiresAt === '' || strtotime($expiresAt) === false || strtotime($expiresAt) < time();

            if ($user['email_verified_at'] !== null) {
                $status = 'success';
                $title = 'Email already verified';
                $message = 'Your email address is already confirmed. You can sign in now.';
            } elseif ($expired) {
                $title = 'Verification link expired';
                $message = 'This link has expired. Request a new verification email from the login page.';
            } else {
                $update = $db->prepare(
                    'UPDATE users
                     SET email_verified_at = NOW(),
                         email_verification_token = NULL,
                         email_verification_expires_at = NULL,
                         updated_at = NOW()
                     WHERE user_id = :user_id
                       AND email_verification_token = :token'
                );
                $update->execute([
                    ':user_id' => (int)$user['user_id'],
                    ':token' => $token,
                ]);

                if ($update->rowCount() > 0) {
                    $status = 'success';
                    $title = 'Email verified';
                    $message = 'Thank you, ' . (string)$user['full_name'] . '. Your email address is confirmed. You can now sign in.';

                    AuditLog::recordForUser(
                        $db,
                        (int)$user['user_id'],
                        (string)$user['full_name'],
                        (string)($user['role'] ?? 'Applicant'),
                        'verify_email',
                        'auth',
                        (int)$user['user_id'],
                        'Verified email address for ' . (string)$user['username']
                    );
                }
            }
        }
    } catch (Throwable $e) {
        error_log('verify_email.php error: ' . $e->getMessage());
        $message = 'Verification is temporarily unavailable. Please try again later.';
    }
}

$isSuccess = $status === 'success';
?>
<!DOCTYPE html>
<html lang="en" class="text-[13px]">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?> | Arnis Connect</title>
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
    </style>
</head>
<body class="flex items-center justify-center min-h-screen p-4">
    <div class="fixed top-[-10%] left-[-10%] w-[40%] h-[40%] bg-purple-600/10 blur-[120px] rounded-full"></div>
    <div class="fixed bottom-[-10%] right-[-10%] w-[40%] h-[40%] bg-blue-600/10 blur-[120px] rounded-full"></div>

    <main class="relative z-10 w-full max-w-md">
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl <?= $isSuccess ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20' : 'bg-red-500/10 text-red-400 border-red-500/20' ?> border mb-4 shadow-lg">
                <i class="fas <?= $isSuccess ? 'fa-envelope-circle-check' : 'fa-triangle-exclamation' ?> text-2xl"></i>
            </div>
            <h1 class="text-2xl font-black uppercase tracking-tighter italic text-white"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
        </div>

        <div class="card-bg p-8 rounded-[2rem] shadow-2xl">
            <p class="text-sm text-gray-300 leading-relaxed text-center mb-6"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
            <div class="flex flex-col gap-3">
                <a href="index.php" class="w-full text-center bg-purple-600 hover:bg-purple-500 text-white font-black uppercase text-[11px] tracking-[0.2em] py-4 rounded-2xl transition">
                    <i class="fas fa-right-to-bracket mr-2"></i>Go to Login
                </a>
                <?php if (!$isSuccess): ?>
                <a href="resend_verification.php" class="w-full text-center bg-white/5 hover:bg-white/10 text-gray-300 font-black uppercase text-[11px] tracking-[0.2em] py-4 rounded-2xl transition">
                    Resend Verification Email
                </a>
                <?php endif; ?>
            </div>
        </div>
    </main>
</body>
</html>
