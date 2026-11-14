<?php
/**
 * System Settings - SuperAdmin only.
 *
 * Values live in the `settings` table and are read through classes/Settings.php.
 * The page is intentionally small: only settings the application actually
 * consumes are exposed. Unsupported "features" (SMS, ML, multi-organization)
 * are not offered.
 */
require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/Settings.php';

if (!hasRole('SuperAdmin')) {
    http_response_code(403);
    exit('Access Denied: Only the SuperAdmin may view system settings.');
}

$db = null;
$settingsReady = false;
$settings = Settings::DEFAULTS;
$loadError = '';

try {
    $db = (new Database())->connect();
    $settingsObject = new Settings($db);
    $settings = $settingsObject->all();
    $settingsReady = true;
    try {
        $db->query('SELECT 1 FROM settings LIMIT 1');
    } catch (Throwable $e) {
        $settingsReady = false;
    }
} catch (Throwable $e) {
    error_log('settings.php error: ' . $e->getMessage());
    $loadError = 'The settings page could not connect to the database.';
}

$flashSuccess = isset($_SESSION['success']) && is_string($_SESSION['success']) ? $_SESSION['success'] : '';
$flashError = isset($_SESSION['error']) && is_string($_SESSION['error']) ? $_SESSION['error'] : '';
unset($_SESSION['success'], $_SESSION['error']);

function stE(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$csrfToken = arnisCsrfToken();
?>
<!DOCTYPE html>
<html lang="en" class="text-[13px]">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings | Arnis Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" type="image/ico" href="../arnis-favicon.ico">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,100..900;1,100..900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/sidebar.css">
    <link rel="stylesheet" href="css/style.css">
</head>
<body class="text-white bg-dark-purple overflow-x-hidden">
    <div class="flex">
        <?php include_once "../includes/sidebar.php"; ?>

        <main id="page" class="flex-1 p-4 sm:p-6 lg:p-8 h-screen overflow-y-auto opacity-0 translate-x-8 transition-all duration-500 ease-out">
            <?php include_once "../includes/header.php"; ?>

            <div class="mb-6">
                <div class="text-[10px] uppercase tracking-[0.2em] text-purple-400 font-black">Administration</div>
                <h1 class="text-2xl sm:text-3xl font-black uppercase italic tracking-tight">System Settings</h1>
                <p class="text-xs text-gray-400 mt-1">Only settings used by the application are shown. Changes are recorded in the audit log.</p>
            </div>

            <?php if ($flashSuccess !== ''): ?>
                <div class="bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 rounded-2xl px-4 py-3 mb-6 text-sm">
                    <i class="fas fa-check-circle mr-2"></i><?= stE($flashSuccess) ?>
                </div>
            <?php endif; ?>
            <?php if ($flashError !== ''): ?>
                <div class="bg-red-500/10 border border-red-500/30 text-red-300 rounded-2xl px-4 py-3 mb-6 text-sm">
                    <i class="fas fa-exclamation-circle mr-2"></i><?= stE($flashError) ?>
                </div>
            <?php endif; ?>
            <?php if ($loadError !== ''): ?>
                <div class="bg-red-500/10 border border-red-500/30 text-red-300 rounded-2xl px-4 py-3 mb-6 text-sm">
                    <i class="fas fa-exclamation-circle mr-2"></i><?= stE($loadError) ?>
                </div>
            <?php endif; ?>
            <?php if (!$settingsReady && $loadError === ''): ?>
                <div class="bg-yellow-500/10 border border-yellow-500/30 text-yellow-200 rounded-2xl px-4 py-3 mb-6 text-sm">
                    <i class="fas fa-triangle-exclamation mr-2"></i>
                    The <code>settings</code> table is missing. Run <code>database/migrations/003_security_privacy_audit_settings.sql</code> in phpMyAdmin before saving settings.
                </div>
            <?php endif; ?>

            <form method="POST" action="../actions/save_settings.php" class="space-y-6 max-w-4xl">
                <input type="hidden" name="csrf_token" value="<?= stE($csrfToken) ?>">

                <div class="card-bg rounded-3xl p-5 sm:p-6">
                    <h2 class="text-sm font-black uppercase tracking-widest text-purple-300 mb-4"><i class="fas fa-building mr-2"></i>Organization</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-[10px] uppercase tracking-[0.16em] font-black text-gray-500 mb-2">Organization Name</label>
                            <input type="text" name="organization_name" required maxlength="100" value="<?= stE((string)$settings['organization_name']) ?>" class="w-full bg-gray-900/60 border border-gray-700 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-purple-500/50">
                        </div>
                        <div>
                            <label class="block text-[10px] uppercase tracking-[0.16em] font-black text-gray-500 mb-2">Public Contact Email</label>
                            <input type="email" name="contact_email" maxlength="100" value="<?= stE((string)$settings['contact_email']) ?>" class="w-full bg-gray-900/60 border border-gray-700 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-purple-500/50" placeholder="club@example.com">
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-[10px] uppercase tracking-[0.16em] font-black text-gray-500 mb-2">Privacy Contact (shown in the registration notice)</label>
                            <input type="text" name="privacy_contact" maxlength="150" value="<?= stE((string)$settings['privacy_contact']) ?>" class="w-full bg-gray-900/60 border border-gray-700 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-purple-500/50" placeholder="Data protection officer name / email / phone">
                        </div>
                    </div>
                </div>

                <div class="card-bg rounded-3xl p-5 sm:p-6">
                    <h2 class="text-sm font-black uppercase tracking-widest text-purple-300 mb-4"><i class="fas fa-envelope mr-2"></i>Email</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-[10px] uppercase tracking-[0.16em] font-black text-gray-500 mb-2">From Email</label>
                            <input type="email" name="from_email" maxlength="100" value="<?= stE((string)$settings['from_email']) ?>" class="w-full bg-gray-900/60 border border-gray-700 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-purple-500/50" placeholder="no-reply@yourdomain.com">
                            <p class="text-[10px] text-gray-500 mt-1">Leave blank to use no-reply@&lt;your-host&gt;.</p>
                        </div>
                        <div>
                            <label class="block text-[10px] uppercase tracking-[0.16em] font-black text-gray-500 mb-2">From Name</label>
                            <input type="text" name="from_name" maxlength="100" value="<?= stE((string)$settings['from_name']) ?>" class="w-full bg-gray-900/60 border border-gray-700 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-purple-500/50">
                        </div>
                        <div>
                            <label class="block text-[10px] uppercase tracking-[0.16em] font-black text-gray-500 mb-2">SMTP Login (Brevo)</label>
                            <input type="text" name="smtp_user" maxlength="150" autocomplete="off" value="<?= stE((string)$settings['smtp_user']) ?>" class="w-full bg-gray-900/60 border border-gray-700 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-purple-500/50" placeholder="xxxxxxx@smtp-brevo.com">
                        </div>
                        <div>
                            <label class="block text-[10px] uppercase tracking-[0.16em] font-black text-gray-500 mb-2">SMTP Key</label>
                            <input type="password" name="smtp_pass" maxlength="255" autocomplete="new-password" value="" class="w-full bg-gray-900/60 border border-gray-700 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-purple-500/50" placeholder="<?= trim((string)$settings['smtp_pass']) !== '' ? 'Saved - leave blank to keep' : 'Paste your Brevo SMTP key' ?>">
                            <p class="text-[10px] text-gray-500 mt-1">Required for verification, password reset and notification emails.</p>
                        </div>
                        <label class="flex items-start gap-3 md:col-span-2 text-xs text-gray-300">
                            <input type="checkbox" name="email_notifications" value="1" <?= !empty($settings['email_notifications']) && $settings['email_notifications'] !== '0' ? 'checked' : '' ?> class="mt-0.5 w-4 h-4 rounded border-gray-600 bg-gray-800 text-purple-600 focus:ring-purple-500">
                            <span>Send event, training, and announcement emails. Requires working PHP mail on the host.</span>
                        </label>
                    </div>
                </div>

                <div class="card-bg rounded-3xl p-5 sm:p-6">
                    <h2 class="text-sm font-black uppercase tracking-widest text-purple-300 mb-4"><i class="fas fa-user-plus mr-2"></i>Registration &amp; Verification</h2>
                    <div class="space-y-4">
                        <label class="flex items-start gap-3 text-xs text-gray-300">
                            <input type="checkbox" name="allow_registration" value="1" <?= !empty($settings['allow_registration']) && $settings['allow_registration'] !== '0' ? 'checked' : '' ?> class="mt-0.5 w-4 h-4 rounded border-gray-600 bg-gray-800 text-purple-600 focus:ring-purple-500">
                            <span>Allow new member applications from the public registration page.</span>
                        </label>
                        <p class="flex items-start gap-3 text-xs text-gray-400">
                            <i class="fas fa-envelope-circle-check mt-0.5 text-purple-400"></i>
                            <span>Applicants must always verify their email address before they can sign in.</span>
                        </p>
                    </div>
                </div>

                <div class="card-bg rounded-3xl p-5 sm:p-6">
                    <h2 class="text-sm font-black uppercase tracking-widest text-purple-300 mb-4"><i class="fas fa-location-dot mr-2"></i>Attendance Defaults</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-[10px] uppercase tracking-[0.16em] font-black text-gray-500 mb-2">Face Match Threshold</label>
                            <input type="number" name="face_match_threshold" min="0.10" max="1.00" step="0.01" value="<?= stE((string)$settings['face_match_threshold']) ?>" class="w-full bg-gray-900/60 border border-gray-700 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-purple-500/50">
                            <p class="text-[10px] text-gray-500 mt-1">0.10 to 1.00. Lower is stricter; 0.60 is recommended.</p>
                        </div>
                        <div>
                            <label class="block text-[10px] uppercase tracking-[0.16em] font-black text-gray-500 mb-2">Default Geofence (metres)</label>
                            <input type="number" name="default_geofence_m" min="5" max="5000" step="1" value="<?= stE((string)$settings['default_geofence_m']) ?>" class="w-full bg-gray-900/60 border border-gray-700 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-purple-500/50">
                            <p class="text-[10px] text-gray-500 mt-1">Used as the starting value when creating a training session.</p>
                        </div>
                        <div>
                            <label class="block text-[10px] uppercase tracking-[0.16em] font-black text-gray-500 mb-2">Check-in Opens (minutes before start)</label>
                            <input type="number" name="checkin_open_minutes" min="0" max="240" step="1" value="<?= stE((string)$settings['checkin_open_minutes']) ?>" class="w-full bg-gray-900/60 border border-gray-700 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-purple-500/50">
                            <p class="text-[10px] text-gray-500 mt-1">Check-in closes when the session ends. Members who never check in stay Absent.</p>
                        </div>
                        <div>
                            <label class="block text-[10px] uppercase tracking-[0.16em] font-black text-gray-500 mb-2">Late After (minutes past start)</label>
                            <input type="number" name="late_grace_minutes" min="0" max="180" step="1" value="<?= stE((string)$settings['late_grace_minutes']) ?>" class="w-full bg-gray-900/60 border border-gray-700 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-purple-500/50">
                            <p class="text-[10px] text-gray-500 mt-1">Checking in after this grace period is recorded as Late.</p>
                        </div>
                    </div>
                </div>

                <div class="flex flex-col sm:flex-row gap-3">
                    <button type="submit" <?= $settingsReady ? '' : 'disabled' ?> class="flex-1 bg-purple-600 hover:bg-purple-500 disabled:opacity-40 disabled:cursor-not-allowed text-white font-black uppercase text-[11px] tracking-[0.2em] py-4 rounded-2xl transition">
                        <i class="fas fa-floppy-disk mr-2"></i>Save Settings
                    </button>
                    <a href="dashboard.php" class="flex-1 text-center bg-white/5 hover:bg-white/10 text-gray-300 font-black uppercase text-[11px] tracking-[0.2em] py-4 rounded-2xl transition">Cancel</a>
                </div>
            </form>
        </main>
    </div>

    <?php include_once "modals/user_menu.php"; ?>
    <?php include_once "modals/edit_profile_user.php"; ?>
</body>
<script>
window.addEventListener("load", () => {
    const page = document.getElementById("page");
    if (page) {
        page.classList.remove("opacity-0", "translate-x-8");
    }
});
</script>
</html>
