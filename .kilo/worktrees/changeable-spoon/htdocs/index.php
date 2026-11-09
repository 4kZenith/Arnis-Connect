<?php
require_once __DIR__ . '/config/session.php';
arnisStartSession();

if (isset($_SESSION['user_id'])) {
    header("Location: public/dashboard.php");
    exit;
}

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");
header("X-Content-Type-Options: nosniff");

$errorMessage = '';
$showResendLink = false;
if (isset($_GET['error']) && is_string($_GET['error'])) {
    switch ($_GET['error']) {
        case 'empty':
            $errorMessage = 'Please enter both username and password.';
            break;
        case 'invalid':
            $errorMessage = 'Invalid username/email or password.';
            break;
        case 'inactive':
            $errorMessage = 'This account is inactive. Please contact an administrator.';
            break;
        case 'unverified':
            $errorMessage = 'Please verify your email address before signing in.';
            $showResendLink = true;
            break;
        case 'timeout':
            $errorMessage = 'Your session expired due to inactivity. Please log in again.';
            break;
        case 'session':
            $errorMessage = 'Your session is no longer valid. Please log in again.';
            break;
        case 'security':
            $errorMessage = 'The security token is invalid or expired. Please try again.';
            break;
        case 'throttled':
            $errorMessage = 'Too many failed attempts. Please wait 90 seconds and try again.';
            break;
        case 'service':
            $errorMessage = 'Login is temporarily unavailable. Please try again later.';
            break;
    }
}
?>

<!DOCTYPE html>
<html lang="en" class="text-[13px]">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | Arnis Connect</title>
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
        .login-input { background: #1d212a; border: 1px solid transparent; transition: all 0.3s; }
        .login-input:focus { border-color: #a855f7; outline: none; box-shadow: 0 0 15px rgba(168, 85, 247, 0.1); }
    </style>
</head>

<body class="flex items-center justify-center min-h-screen p-4 overflow-hidden">

    <div class="fixed top-[-10%] left-[-10%] w-[40%] h-[40%] bg-purple-600/10 blur-[120px] rounded-full"></div>
    <div class="fixed bottom-[-10%] right-[-10%] w-[40%] h-[40%] bg-blue-600/10 blur-[120px] rounded-full"></div>

    <main id="loginContent" class="w-full max-w-[400px] opacity-0 translate-y-8 transition-all duration-700 ease-out z-10">

        <?php if ($errorMessage !== ''): ?>
            <div class="mb-4 p-3 rounded-xl bg-red-500/20 border border-red-400 text-red-300 text-[11px] font-bold text-center">
                <i class="fas fa-exclamation-triangle mr-2"></i> <?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <?php if ($showResendLink): ?>
            <div class="mb-4 text-center">
                <a href="resend_verification.php" class="text-[11px] uppercase tracking-[0.16em] font-black text-purple-400 hover:text-white transition">
                    <i class="fas fa-paper-plane mr-2"></i>Resend verification email
                </a>
            </div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-purple-500/10 text-purple-500 border border-purple-500/20 mb-4 shadow-lg shadow-purple-900/20">
                <i class="fas fa-shield-halved text-2xl"></i>
            </div>
            <h1 class="text-2xl font-black uppercase tracking-tighter italic text-white">Arnis <span class="text-purple-500">Connect</span></h1>
            <div class="flex items-center justify-center gap-2 mt-2">
                <span class="h-[1px] w-8 bg-gray-800"></span>
                <p class="text-[10px] text-gray-500 uppercase font-bold tracking-[0.2em]">Staff login and public member registration</p>
                <span class="h-[1px] w-8 bg-gray-800"></span>
            </div>
        </div>

        <div class="card-bg p-8 rounded-[2.5rem] shadow-2xl relative overflow-hidden">
            <div class="absolute top-0 left-0 w-full h-1 bg-gradient-to-r from-transparent via-purple-500 to-transparent opacity-50"></div>

            <form action="classes/process_login.php" method="POST" class="space-y-6">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(arnisCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">

                <div class="space-y-2">
                    <label class="text-[10px] text-gray-500 uppercase font-black tracking-widest ml-1">Username or Email</label>
                    <div class="relative">
                        <i class="fas fa-user absolute left-4 top-1/2 -translate-y-1/2 text-gray-600 text-xs"></i>
                        <input type="text" name="username" required
                            placeholder="Enter your username or email"
                            class="login-input w-full py-4 pl-12 pr-4 rounded-2xl text-white text-sm placeholder:text-gray-700">
                    </div>
                </div>

                <div class="space-y-2">
                    <div class="flex justify-between items-center ml-1">
                        <label class="text-[10px] text-gray-500 uppercase font-black tracking-widest">Password</label>
                    </div>

                    <div class="relative">
                        <i class="fas fa-lock absolute left-4 top-1/2 -translate-y-1/2 text-gray-600 text-xs"></i>

                        <input
                            id="passwordInput"
                            type="password"
                            name="password"
                            required
                            placeholder="••••••••"
                            class="login-input w-full py-4 pl-12 pr-12 rounded-2xl text-white text-sm placeholder:text-gray-700"
                        >

                        <button
                            type="button"
                            onclick="togglePassword()"
                            class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-600 hover:text-gray-400 transition"
                        >
                            <i id="passwordIcon" class="fas fa-eye text-xs"></i>
                        </button>
                    </div>
                </div>

                <button type="submit"
                    class="w-full bg-purple-600 hover:bg-purple-500 text-white font-black uppercase text-[11px] tracking-[0.2em] py-5 rounded-2xl transition-all active:scale-95 shadow-lg shadow-purple-900/40 flex items-center justify-center gap-3">
                    Log In
                    <i class="fas fa-arrow-right text-[10px]"></i>
                </button>
            </form>
        </div>

        <div class="mt-6 rounded-2xl bg-white/[0.03] border border-white/5 p-4 flex flex-col sm:flex-row items-center justify-between gap-3">
            <div>
                <p class="text-[10px] uppercase tracking-[0.2em] font-black text-gray-500">New member applicant?</p>
                <p class="text-xs text-gray-400 mt-1">Register as a public or private player and wait for approval.</p>
            </div>
            <a href="register.php" class="px-4 py-3 rounded-2xl bg-white/5 hover:bg-purple-500 hover:text-white transition text-[11px] font-black uppercase tracking-[0.16em] text-purple-300">Register as Member</a>
        </div>

        <div class="mt-8 text-center space-y-4">
            <p class="text-gray-600 text-[9px] uppercase tracking-widest">
                &copy; 2026 Lapu-Lapu Arnis de Abanico. All rights reserved.
            </p>
        </div>
    </main>

    <script>
        window.addEventListener("load", () => {
            const content = document.getElementById("loginContent");
            content.classList.remove("opacity-0", "translate-y-8");
        });
    </script>

    <script>
        if (window.history && window.history.pushState) {
            window.history.pushState(null, null, window.location.href);
            window.onpopstate = function () {
                window.location.href = 'index.php';
            };
        }

        function togglePassword() {
            const input = document.getElementById('passwordInput');
            const icon = document.getElementById('passwordIcon');

            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
    </script>

</body>
</html>