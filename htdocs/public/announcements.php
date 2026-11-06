<?php
/**
 * Announcements - minimal in-app + email announcement module.
 *
 * Read: every authenticated role except Applicant (config/permissions.php).
 * Write: SuperAdmin and Head Coach. Archive: SuperAdmin only.
 * Audience controls both the in-app list and who receives the email.
 */
require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';

$readScope = permissionValue('announcements', 'read');
if ($readScope === false) {
    http_response_code(403);
    exit('Access Denied: You do not have permission to view announcements.');
}

$canWrite = permissionValue('announcements', 'write') === true;
$canArchive = permissionValue('announcements', 'delete') === true;
$role = currentUserRole() ?? '';
$showArchived = $canWrite && isset($_GET['archived']) && $_GET['archived'] === '1';

$announcements = [];
$loadError = '';
$tableReady = true;

try {
    $db = (new Database())->connect();

    $where = [];
    $params = [];

    if ($canWrite) {
        $where[] = 'a.is_archived = :archived';
        $params[':archived'] = $showArchived ? 1 : 0;
    } else {
        $where[] = 'a.is_archived = 0';
    }

    if (!hasRole('SuperAdmin') && !hasRole('Head Coach')) {
        $audienceFilter = match ($role) {
            'Coach/Trainer' => ['All', 'Coaches'],
            'Officer' => ['All', 'Officers'],
            'Member' => ['All', 'Members'],
            default => ['All'],
        };
        $placeholders = [];
        foreach ($audienceFilter as $index => $audience) {
            $key = ':aud' . $index;
            $placeholders[] = $key;
            $params[$key] = $audience;
        }
        $where[] = 'a.audience IN (' . implode(',', $placeholders) . ')';
    }

    $sql = "SELECT a.announcement_id, a.title, a.body, a.audience, a.created_at,
                   a.email_sent_at, a.is_archived, COALESCE(u.full_name, 'System') AS author
            FROM announcements a
            LEFT JOIN users u ON a.created_by = u.user_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY a.created_at DESC, a.announcement_id DESC
            LIMIT 100";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    error_log('announcements.php error: ' . $e->getMessage());
    $tableReady = false;
    $loadError = 'Announcements are not available yet. Run database migration 003, then reload this page.';
}

$flashSuccess = isset($_SESSION['success']) && is_string($_SESSION['success']) ? $_SESSION['success'] : '';
$flashError = isset($_SESSION['error']) && is_string($_SESSION['error']) ? $_SESSION['error'] : '';
unset($_SESSION['success'], $_SESSION['error']);

function anE(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function anDate(?string $value): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    $timestamp = strtotime($value);
    return $timestamp === false ? '—' : date('M d, Y g:i A', $timestamp);
}

$csrfToken = arnisCsrfToken();
?>
<!DOCTYPE html>
<html lang="en" class="text-[13px]">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Announcements | Arnis Portal</title>
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

            <div class="mb-6 flex flex-col lg:flex-row lg:items-end justify-between gap-4">
                <div>
                    <div class="text-[10px] uppercase tracking-[0.2em] text-purple-400 font-black">Communication</div>
                    <h1 class="text-2xl sm:text-3xl font-black uppercase italic tracking-tight">Announcements</h1>
                    <p class="text-xs text-gray-400 mt-1">Club updates for members, coaches, and officers.</p>
                </div>
                <?php if ($canWrite): ?>
                <div class="flex gap-2">
                    <a href="announcements.php" class="px-4 py-2.5 rounded-full text-[10px] font-black uppercase tracking-widest transition <?= !$showArchived ? 'bg-purple-600 text-white' : 'bg-white/5 text-gray-400 hover:bg-white/10' ?>">Active</a>
                    <a href="announcements.php?archived=1" class="px-4 py-2.5 rounded-full text-[10px] font-black uppercase tracking-widest transition <?= $showArchived ? 'bg-purple-600 text-white' : 'bg-white/5 text-gray-400 hover:bg-white/10' ?>">Archived</a>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($flashSuccess !== ''): ?>
                <div class="bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 rounded-2xl px-4 py-3 mb-6 text-sm"><i class="fas fa-check-circle mr-2"></i><?= anE($flashSuccess) ?></div>
            <?php endif; ?>
            <?php if ($flashError !== ''): ?>
                <div class="bg-red-500/10 border border-red-500/30 text-red-300 rounded-2xl px-4 py-3 mb-6 text-sm"><i class="fas fa-exclamation-circle mr-2"></i><?= anE($flashError) ?></div>
            <?php endif; ?>
            <?php if ($loadError !== ''): ?>
                <div class="bg-red-500/10 border border-red-500/30 text-red-300 rounded-2xl px-4 py-3 mb-6 text-sm"><i class="fas fa-exclamation-circle mr-2"></i><?= anE($loadError) ?></div>
            <?php endif; ?>

            <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
                <?php if ($canWrite): ?>
                <div class="xl:col-span-1">
                    <div class="card-bg rounded-3xl p-5 sm:p-6">
                        <h2 class="text-sm font-black uppercase tracking-widest text-purple-300 mb-4"><i class="fas fa-bullhorn mr-2"></i>New Announcement</h2>
                        <form method="POST" action="../actions/process_announcement.php" class="space-y-4">
                            <input type="hidden" name="csrf_token" value="<?= anE($csrfToken) ?>">
                            <input type="hidden" name="action" value="create">
                            <div>
                                <label class="block text-[10px] uppercase tracking-[0.16em] font-black text-gray-500 mb-2">Title</label>
                                <input type="text" name="title" required maxlength="150" class="w-full bg-gray-900/60 border border-gray-700 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-purple-500/50" placeholder="Schedule change for Saturday">
                            </div>
                            <div>
                                <label class="block text-[10px] uppercase tracking-[0.16em] font-black text-gray-500 mb-2">Audience</label>
                                <select name="audience" class="w-full bg-gray-900/60 border border-gray-700 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-purple-500/50">
                                    <option value="All">Everyone</option>
                                    <option value="Members">Members</option>
                                    <option value="Coaches">Coaches</option>
                                    <option value="Officers">Officers</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10px] uppercase tracking-[0.16em] font-black text-gray-500 mb-2">Message</label>
                                <textarea name="body" rows="6" required maxlength="5000" class="w-full bg-gray-900/60 border border-gray-700 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-purple-500/50" placeholder="Write the announcement..."></textarea>
                            </div>
                            <label class="flex items-start gap-3 text-xs text-gray-300">
                                <input type="checkbox" name="send_email" value="1" checked class="mt-0.5 w-4 h-4 rounded border-gray-600 bg-gray-800 text-purple-600 focus:ring-purple-500">
                                <span>Also send this by email to the selected audience.</span>
                            </label>
                            <button type="submit" class="w-full bg-purple-600 hover:bg-purple-500 text-white font-black uppercase text-[11px] tracking-[0.2em] py-4 rounded-2xl transition">
                                <i class="fas fa-paper-plane mr-2"></i>Post Announcement
                            </button>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

                <div class="<?= $canWrite ? 'xl:col-span-2' : 'xl:col-span-3' ?> space-y-4">
                    <?php if (empty($announcements)): ?>
                        <div class="card-bg rounded-3xl p-10 text-center text-gray-500">
                            <i class="fas fa-bullhorn text-3xl mb-3 opacity-30"></i>
                            <p class="text-sm">No announcements<?= $showArchived ? ' in the archive' : '' ?> yet.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($announcements as $announcement): ?>
                            <article class="card-bg rounded-3xl p-5 sm:p-6">
                                <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-3 mb-3">
                                    <div>
                                        <div class="flex flex-wrap items-center gap-2 mb-2">
                                            <span class="px-2.5 py-1 rounded-full text-[9px] font-black uppercase tracking-widest bg-purple-500/10 text-purple-300 border border-purple-500/20"><?= anE((string)$announcement['audience']) ?></span>
                                            <?php if ($announcement['email_sent_at'] !== null): ?>
                                            <span class="px-2.5 py-1 rounded-full text-[9px] font-black uppercase tracking-widest bg-emerald-500/10 text-emerald-300 border border-emerald-500/20"><i class="fas fa-envelope-circle-check mr-1"></i>Emailed</span>
                                            <?php endif; ?>
                                            <?php if (!empty($announcement['is_archived'])): ?>
                                            <span class="px-2.5 py-1 rounded-full text-[9px] font-black uppercase tracking-widest bg-gray-500/10 text-gray-400 border border-gray-500/20">Archived</span>
                                            <?php endif; ?>
                                        </div>
                                        <h2 class="text-lg font-black tracking-tight"><?= anE((string)$announcement['title']) ?></h2>
                                    </div>
                                    <?php if ($canArchive && empty($announcement['is_archived'])): ?>
                                    <form method="POST" action="../actions/process_announcement.php" onsubmit="return confirm('Archive this announcement?');">
                                        <input type="hidden" name="csrf_token" value="<?= anE($csrfToken) ?>">
                                        <input type="hidden" name="action" value="archive">
                                        <input type="hidden" name="announcement_id" value="<?= (int)$announcement['announcement_id'] ?>">
                                        <button type="submit" class="text-[9px] font-black uppercase tracking-widest text-gray-500 hover:text-red-300 transition">
                                            <i class="fas fa-box-archive mr-1"></i>Archive
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                                <div class="text-sm text-gray-300 leading-relaxed"><?= nl2br(anE((string)$announcement['body'])) ?></div>
                                <div class="mt-4 text-[10px] text-gray-500 uppercase tracking-widest">
                                    <i class="fas fa-user mr-1"></i><?= anE((string)$announcement['author']) ?>
                                    &middot; <i class="fas fa-clock mr-1"></i><?= anE(anDate((string)$announcement['created_at'])) ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
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
