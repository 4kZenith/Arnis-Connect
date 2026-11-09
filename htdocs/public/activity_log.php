<?php
/**
 * Activity Log - real, append-only audit trail.
 *
 * Replaces the old UNION of created_at / updated_at / voided_at columns. That
 * feed re-described the same row up to three times and could not show logins,
 * approvals, promotions, settings changes, report exports, or the actor's
 * role/IP. AuditLog::record() writes one intentional event per action.
 *
 * RBAC: the audit trail is security-sensitive (it contains names, IPs, and
 * security events), so only the SuperAdmin may read it.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';

if (!hasRole('SuperAdmin')) {
    http_response_code(403);
    exit('Access Denied: You do not have permission to view the audit log.');
}

/* ---------------------------------------------------------------- filters */
$allowedModules = [
    'auth', 'members', 'events', 'event_participants', 'event_documents',
    'trainings', 'attendance', 'funds', 'achievements', 'users',
    'registration', 'reports', 'settings', 'announcements',
];

$allowedActions = [
    'create', 'update', 'void', 'restore', 'delete', 'login', 'logout',
    'login_failed', 'approve', 'reject', 'promote', 'status_change',
    'verify_email', 'resend_verification', 'settings_update',
    'announcement_create', 'announcement_archive', 'export',
];

$filters = [
    'module' => '',
    'action' => '',
    'actor' => '',
    'start_date' => '',
    'end_date' => '',
];

$rawModule = $_GET['module'] ?? '';
if (is_string($rawModule) && in_array($rawModule, $allowedModules, true)) {
    $filters['module'] = $rawModule;
}

$rawAction = $_GET['action'] ?? '';
if (is_string($rawAction) && in_array($rawAction, $allowedActions, true)) {
    $filters['action'] = $rawAction;
}

$rawActor = $_GET['actor'] ?? '';
if (is_string($rawActor)) {
    $filters['actor'] = mb_substr(trim($rawActor), 0, 100);
}

foreach (['start_date', 'end_date'] as $key) {
    $raw = $_GET[$key] ?? '';
    if (is_string($raw) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($raw)) === 1) {
        $filters[$key] = trim($raw);
    }
}

if ($filters['start_date'] !== '' && $filters['end_date'] !== ''
    && $filters['start_date'] > $filters['end_date']) {
    [$filters['start_date'], $filters['end_date']] = [$filters['end_date'], $filters['start_date']];
}

$hasFilters = $filters['module'] !== '' || $filters['action'] !== ''
    || $filters['actor'] !== '' || $filters['start_date'] !== '' || $filters['end_date'] !== '';

/* ------------------------------------------------------- CSV export */
$export = isset($_GET['export']) && $_GET['export'] === 'csv';
if ($export) {
    $db = (new Database())->connect();
    $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
    $stmt = $db->prepare(
        'SELECT audit_id, actor_name, actor_role, action, module, target_table,
                target_id, description, ip_address, created_at
         FROM audit_logs' . $whereSql . '
         ORDER BY created_at DESC, audit_id DESC'
    );
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="audit_log_' . date('Ymd_His') . '.csv"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Actor', 'Role', 'Action', 'Module', 'Target Table', 'Target ID', 'Description', 'IP Address', 'Created At']);
    foreach ($rows as $row) {
        fputcsv($out, [
            $row['audit_id'] ?? '',
            $row['actor_name'] ?? '',
            $row['actor_role'] ?? '',
            $row['action'] ?? '',
            $row['module'] ?? '',
            $row['target_table'] ?? '',
            $row['target_id'] ?? '',
            $row['description'] ?? '',
            $row['ip_address'] ?? '',
            $row['created_at'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

/* ------------------------------------------------------- SQL construction */
$where = [];
$params = [];

if ($filters['module'] !== '') {
    $where[] = 'module = :module';
    $params[':module'] = $filters['module'];
}
if ($filters['action'] !== '') {
    $where[] = 'action = :action';
    $params[':action'] = $filters['action'];
}
if ($filters['actor'] !== '') {
    $where[] = 'actor_name LIKE :actor';
    $params[':actor'] = '%' . $filters['actor'] . '%';
}
if ($filters['start_date'] !== '') {
    $where[] = 'created_at >= :start_date';
    $params[':start_date'] = $filters['start_date'] . ' 00:00:00';
}
if ($filters['end_date'] !== '') {
    $where[] = 'created_at <= :end_date';
    $params[':end_date'] = $filters['end_date'] . ' 23:59:59';
}

$whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

/* ------------------------------------------------------- fetch + paginate */
$perPage       = 30;
$requestedPage = max(1, (int)($_GET['page'] ?? 1));
$page          = 1;
$totalPages    = 1;
$totalEvents   = 0;
$todayEvents   = 0;
$securityEvents = 0;
$uniqueActors  = 0;
$events        = [];
$offset        = 0;
$loadError     = null;
$auditReady    = true;

try {
    $db = (new Database())->connect();

    $statsStmt = $db->prepare(
        "SELECT
            COUNT(*) AS total,
            COALESCE(SUM(CASE WHEN created_at >= CURDATE() THEN 1 ELSE 0 END), 0) AS today,
            COALESCE(SUM(CASE WHEN module = 'auth' THEN 1 ELSE 0 END), 0) AS security,
            COUNT(DISTINCT actor_name) AS actors
         FROM audit_logs" . $whereSql
    );
    $statsStmt->execute($params);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $totalEvents    = (int)($stats['total'] ?? 0);
    $todayEvents    = (int)($stats['today'] ?? 0);
    $securityEvents = (int)($stats['security'] ?? 0);
    $uniqueActors   = (int)($stats['actors'] ?? 0);

    $totalPages = max(1, (int)ceil($totalEvents / $perPage));
    $page       = min($requestedPage, $totalPages);
    $offset     = ($page - 1) * $perPage;

    if ($totalEvents > 0) {
        // LIMIT/OFFSET are ints derived from (int) casts, so interpolation is
        // safe and required for native prepared statements.
        $pageStmt = $db->prepare(
            'SELECT audit_id, actor_name, actor_role, action, module, record_id,
                    description, ip_address, created_at
             FROM audit_logs' . $whereSql . '
             ORDER BY created_at DESC, audit_id DESC
             LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset
        );
        $pageStmt->execute($params);
        $events = $pageStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Throwable $e) {
    error_log('activity_log.php query error: ' . $e->getMessage());
    $auditReady = false;
    $loadError = 'The audit log is not available yet. Run database migration 003, then reload this page.';
}

/* ----------------------------------------------------------------- helpers */
function alE(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function alJsArg($value): string
{
    $json = json_encode(
        (string)$value,
        JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE
    );
    return htmlspecialchars((string)$json, ENT_QUOTES, 'UTF-8');
}

function alFormatDateTime($value): string
{
    if (!is_string($value)) {
        return '—';
    }
    $value = trim($value);
    if ($value === '' || str_starts_with($value, '0000-00-00')) {
        return '—';
    }
    $timestamp = strtotime($value);
    return $timestamp === false ? '—' : date('M d, Y g:i A', $timestamp);
}

function alModuleLabel(string $module): string
{
    $labels = [
        'auth' => 'Authentication',
        'members' => 'Members',
        'events' => 'Events',
        'event_participants' => 'Event Participants',
        'event_documents' => 'Event Documents',
        'trainings' => 'Trainings',
        'attendance' => 'Attendance',
        'funds' => 'Funds',
        'achievements' => 'Achievements',
        'users' => 'Users',
        'registration' => 'Registration',
        'reports' => 'Reports',
        'settings' => 'System Settings',
        'announcements' => 'Announcements',
    ];
    return $labels[$module] ?? ($module !== '' ? ucwords(str_replace('_', ' ', $module)) : '—');
}

/** @return array{icon:string,badge:string,label:string} */
function alActionMeta(string $action): array
{
    return match ($action) {
        'create' => ['icon' => 'fa-plus', 'badge' => 'bg-green-500/10 text-green-400', 'label' => 'Created'],
        'update' => ['icon' => 'fa-pen', 'badge' => 'bg-yellow-500/10 text-yellow-400', 'label' => 'Updated'],
        'void' => ['icon' => 'fa-ban', 'badge' => 'bg-red-500/10 text-red-400', 'label' => 'Voided'],
        'restore' => ['icon' => 'fa-rotate-left', 'badge' => 'bg-emerald-500/10 text-emerald-400', 'label' => 'Restored'],
        'delete' => ['icon' => 'fa-trash', 'badge' => 'bg-red-500/10 text-red-400', 'label' => 'Deleted'],
        'login' => ['icon' => 'fa-right-to-bracket', 'badge' => 'bg-blue-500/10 text-blue-400', 'label' => 'Login'],
        'logout' => ['icon' => 'fa-right-from-bracket', 'badge' => 'bg-gray-500/10 text-gray-400', 'label' => 'Logout'],
        'login_failed' => ['icon' => 'fa-triangle-exclamation', 'badge' => 'bg-red-500/10 text-red-400', 'label' => 'Failed login'],
        'approve' => ['icon' => 'fa-check', 'badge' => 'bg-green-500/10 text-green-400', 'label' => 'Approved'],
        'reject' => ['icon' => 'fa-xmark', 'badge' => 'bg-red-500/10 text-red-400', 'label' => 'Rejected'],
        'promote' => ['icon' => 'fa-arrow-trend-up', 'badge' => 'bg-emerald-500/10 text-emerald-400', 'label' => 'Promoted'],
        'status_change' => ['icon' => 'fa-toggle-on', 'badge' => 'bg-blue-500/10 text-blue-400', 'label' => 'Status change'],
        'verify_email' => ['icon' => 'fa-envelope-circle-check', 'badge' => 'bg-emerald-500/10 text-emerald-400', 'label' => 'Email verified'],
        'resend_verification' => ['icon' => 'fa-envelope', 'badge' => 'bg-blue-500/10 text-blue-400', 'label' => 'Verification sent'],
        'settings_update' => ['icon' => 'fa-gear', 'badge' => 'bg-purple-500/10 text-purple-400', 'label' => 'Settings updated'],
        'announcement_create' => ['icon' => 'fa-bullhorn', 'badge' => 'bg-blue-500/10 text-blue-400', 'label' => 'Announcement'],
        'announcement_archive' => ['icon' => 'fa-box-archive', 'badge' => 'bg-gray-500/10 text-gray-400', 'label' => 'Archived'],
        'export' => ['icon' => 'fa-download', 'badge' => 'bg-cyan-500/10 text-cyan-400', 'label' => 'Exported'],
        default => ['icon' => 'fa-circle-info', 'badge' => 'bg-gray-500/10 text-gray-400', 'label' => ucwords(str_replace('_', ' ', $action))],
    };
}

function alPageUrl(int $page, array $filters): string
{
    $query = ['page' => $page];
    foreach (['module', 'action', 'actor', 'start_date', 'end_date'] as $key) {
        if (($filters[$key] ?? '') !== '') {
            $query[$key] = $filters[$key];
        }
    }
    return '?' . http_build_query($query);
}

function alCanRestore(string $module, string $action): bool
{
    $restorable = ['members', 'events', 'trainings', 'funds', 'achievements', 'event_documents', 'attendance'];
    return $action === 'void'
        && in_array($module, $restorable, true)
        && permissionValue($module, 'delete') === true;
}

$pageStart = $totalEvents === 0 ? 0 : ($offset + 1);
$pageEnd   = $totalEvents === 0 ? 0 : min($offset + $perPage, $totalEvents);
$csrfToken = arnisCsrfToken();
?>

<!DOCTYPE html>
<html lang="en" class="text-[13px]">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Log | Arnis Portal</title>
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

            <?php if ($loadError !== null): ?>
                <div class="bg-red-500/10 border border-red-500/30 text-red-400 rounded-2xl px-4 py-3 mb-6 text-xs sm:text-sm">
                    <i class="fas fa-exclamation-circle mr-2"></i><?= alE($loadError) ?>
                </div>
            <?php endif; ?>

            <div class="card-bg border-l-4 border-l-purple-500 rounded-2xl px-4 py-3 mb-6 text-xs text-gray-300">
                <i class="fas fa-shield-halved mr-2 text-purple-400"></i>
                Append-only audit trail. Every entry records who did what, when, and from which IP address. Entries cannot be edited or deleted from the application.
            </div>

            <!-- Stats Cards -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4 lg:gap-6 mb-6 lg:mb-8">
                <div class="card-bg p-4 sm:p-6 rounded-2xl sm:rounded-3xl text-center shadow-xl">
                    <i class="fas fa-clipboard-list text-xl sm:text-2xl mb-2 opacity-50"></i>
                    <div class="text-2xl sm:text-3xl lg:text-4xl font-bold"><?= $totalEvents ?></div>
                    <div class="text-[10px] sm:text-xs text-gray-400 uppercase tracking-widest mt-1">Matching Events</div>
                </div>
                <div class="card-bg p-4 sm:p-6 rounded-2xl sm:rounded-3xl text-center shadow-xl">
                    <i class="fas fa-calendar-day text-xl sm:text-2xl mb-2 text-blue-400 opacity-80"></i>
                    <div class="text-2xl sm:text-3xl lg:text-4xl font-bold"><?= $todayEvents ?></div>
                    <div class="text-[10px] sm:text-xs text-gray-400 uppercase tracking-widest mt-1">Today</div>
                </div>
                <div class="card-bg p-4 sm:p-6 rounded-2xl sm:rounded-3xl text-center shadow-xl">
                    <i class="fas fa-user-shield text-xl sm:text-2xl mb-2 text-yellow-400 opacity-80"></i>
                    <div class="text-2xl sm:text-3xl lg:text-4xl font-bold"><?= $securityEvents ?></div>
                    <div class="text-[10px] sm:text-xs text-gray-400 uppercase tracking-widest mt-1">Security Events</div>
                </div>
                <div class="card-bg p-4 sm:p-6 rounded-2xl sm:rounded-3xl text-center shadow-xl">
                    <i class="fas fa-users text-xl sm:text-2xl mb-2 text-green-400 opacity-80"></i>
                    <div class="text-2xl sm:text-3xl lg:text-4xl font-bold"><?= $uniqueActors ?></div>
                    <div class="text-[10px] sm:text-xs text-gray-400 uppercase tracking-widest mt-1">Unique Actors</div>
                </div>
            </div>

            <!-- Filters -->
            <div class="flex flex-col xl:flex-row justify-between items-stretch xl:items-center mb-6 gap-4">
                <form method="GET" id="activityFilterForm" class="flex flex-col sm:flex-row flex-wrap gap-2 sm:gap-3 flex-1">
                    <select name="module" class="card-bg border-none rounded-full px-4 sm:px-5 py-3 sm:py-4 text-xs sm:text-sm outline-none text-center w-full sm:w-44">
                        <option value="">All modules</option>
                        <?php foreach ($allowedModules as $module): ?>
                            <option value="<?= alE($module) ?>" <?= $filters['module'] === $module ? 'selected' : '' ?>><?= alE(alModuleLabel($module)) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <select name="action" class="card-bg border-none rounded-full px-4 sm:px-5 py-3 sm:py-4 text-xs sm:text-sm outline-none text-center w-full sm:w-40">
                        <option value="">All actions</option>
                        <?php foreach ($allowedActions as $action): ?>
                            <option value="<?= alE($action) ?>" <?= $filters['action'] === $action ? 'selected' : '' ?>><?= alE(alActionMeta($action)['label']) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <input type="text" name="actor" value="<?= alE($filters['actor']) ?>" maxlength="100" placeholder="Actor name" class="card-bg border-none rounded-full px-4 sm:px-5 py-3 sm:py-4 text-xs sm:text-sm outline-none text-center w-full sm:w-40">

                    <input type="date" name="start_date" value="<?= alE($filters['start_date']) ?>" aria-label="Start date" class="card-bg border-none rounded-full px-4 sm:px-5 py-3 sm:py-4 text-xs sm:text-sm outline-none text-center w-full sm:w-40">

                    <input type="date" name="end_date" value="<?= alE($filters['end_date']) ?>" aria-label="End date" class="card-bg border-none rounded-full px-4 sm:px-5 py-3 sm:py-4 text-xs sm:text-sm outline-none text-center w-full sm:w-40">

                    <div class="flex gap-2">
                        <button type="submit" class="flex-1 sm:flex-none bg-accent-purple hover:opacity-90 px-5 sm:px-6 py-4 sm:py-5 rounded-full font-bold text-xs sm:text-sm whitespace-nowrap transition">
                            <i class="fas fa-filter mr-1"></i> Apply
                        </button>
                        <?php if ($hasFilters): ?>
                        <a href="activity_log.php" class="flex-1 sm:flex-none bg-white/5 hover:bg-white/10 px-5 sm:px-6 py-4 sm:py-5 rounded-full font-bold text-xs sm:text-sm whitespace-nowrap transition text-center">
                            <i class="fas fa-redo mr-1"></i> Reset
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Log Table -->
            <div class="card-bg rounded-2xl sm:rounded-3xl overflow-hidden">
                <!-- Mobile cards -->
                <div class="block lg:hidden">
                    <div class="p-2 space-y-2">
                        <?php if (empty($events)): ?>
                            <div class="p-6 text-center text-gray-500">No audit events found.</div>
                        <?php else: ?>
                            <?php foreach ($events as $event):
                                $meta = alActionMeta((string)($event['action'] ?? ''));
                                $module = (string)($event['module'] ?? '');
                                $recordId = (int)($event['record_id'] ?? 0);
                                $canRestore = alCanRestore($module, (string)($event['action'] ?? ''));
                            ?>
                                <div class="bg-white/[0.02] rounded-xl p-4 border border-gray-800/50">
                                    <div class="flex items-center gap-2 mb-2">
                                        <span class="<?= $meta['badge'] ?> px-2 py-1 rounded-full text-[10px] font-bold uppercase">
                                            <i class="fas <?= $meta['icon'] ?> mr-1"></i><?= alE($meta['label']) ?>
                                        </span>
                                        <span class="bg-blue-500/10 text-blue-400 px-2 py-1 rounded-full text-[10px] font-semibold">
                                            <?= alE(alModuleLabel($module)) ?>
                                        </span>
                                    </div>
                                    <div class="text-sm font-semibold break-words mb-2"><?= alE((string)($event['description'] ?? '—')) ?></div>
                                    <div class="text-[10px] text-gray-400 space-y-1">
                                        <div><i class="fas fa-user w-4"></i> <?= alE((string)($event['actor_name'] ?? 'System')) ?> <span class="text-gray-600">(<?= alE((string)($event['actor_role'] ?? '—')) ?>)</span></div>
                                        <div><i class="fas fa-clock w-4"></i> <?= alE(alFormatDateTime($event['created_at'] ?? null)) ?></div>
                                        <div><i class="fas fa-network-wired w-4"></i> <?= alE((string)($event['ip_address'] ?? '—')) ?></div>
                                    </div>
                                    <?php if ($canRestore && $recordId > 0): ?>
                                    <button onclick="openRestoreModal(<?= alJsArg($module) ?>, <?= $recordId ?>, <?= alJsArg((string)($event['description'] ?? '')) ?>)"
                                            class="mt-3 w-full bg-green-500/10 text-green-400 text-center py-2 rounded-lg text-[10px] font-bold hover:bg-green-500 hover:text-white transition">
                                        <i class="fas fa-undo mr-1"></i> Restore
                                    </button>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Desktop table -->
                <div class="hidden lg:block overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead class="text-gray-400 text-xs uppercase tracking-widest border-b border-gray-700">
                            <tr>
                                <th class="p-4 lg:p-6">Date &amp; Time</th>
                                <th class="p-4 lg:p-6">Actor</th>
                                <th class="p-4 lg:p-6">Action</th>
                                <th class="p-4 lg:p-6">Module</th>
                                <th class="p-4 lg:p-6">Description</th>
                                <th class="p-4 lg:p-6">IP Address</th>
                                <th class="p-4 lg:p-6">Restore</th>
                            </tr>
                        </thead>
                        <tbody class="text-sm">
                            <?php if (empty($events)): ?>
                                <tr>
                                    <td colspan="7" class="p-6 text-center text-gray-500">No audit events found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($events as $event):
                                    $meta = alActionMeta((string)($event['action'] ?? ''));
                                    $module = (string)($event['module'] ?? '');
                                    $recordId = (int)($event['record_id'] ?? 0);
                                    $canRestore = alCanRestore($module, (string)($event['action'] ?? ''));
                                ?>
                                    <tr class="border-b border-gray-800 hover:bg-white/5 transition">
                                        <td class="p-4 lg:p-6 text-xs whitespace-nowrap"><?= alE(alFormatDateTime($event['created_at'] ?? null)) ?></td>
                                        <td class="p-4 lg:p-6">
                                            <div class="font-semibold"><?= alE((string)($event['actor_name'] ?? 'System')) ?></div>
                                            <div class="text-[10px] text-gray-500 uppercase"><?= alE((string)($event['actor_role'] ?? '—')) ?></div>
                                        </td>
                                        <td class="p-4 lg:p-6">
                                            <span class="<?= $meta['badge'] ?> px-3 py-1 rounded-full text-xs font-bold uppercase whitespace-nowrap">
                                                <i class="fas <?= $meta['icon'] ?> mr-1"></i><?= alE($meta['label']) ?>
                                            </span>
                                        </td>
                                        <td class="p-4 lg:p-6">
                                            <span class="px-2 py-1 rounded-full text-xs font-semibold bg-blue-500/10 text-blue-400 whitespace-nowrap">
                                                <?= alE(alModuleLabel($module)) ?>
                                            </span>
                                        </td>
                                        <td class="p-4 lg:p-6 text-xs max-w-md break-words"><?= alE((string)($event['description'] ?? '—')) ?></td>
                                        <td class="p-4 lg:p-6 text-xs text-gray-400 whitespace-nowrap"><?= alE((string)($event['ip_address'] ?? '—')) ?></td>
                                        <td class="p-4 lg:p-6">
                                            <?php if ($canRestore && $recordId > 0): ?>
                                                <button onclick="openRestoreModal(<?= alJsArg($module) ?>, <?= $recordId ?>, <?= alJsArg((string)($event['description'] ?? '')) ?>)"
                                                        class="bg-green-500/10 text-green-400 hover:bg-green-500 hover:text-white px-3 py-1 rounded-full text-xs font-bold transition whitespace-nowrap">
                                                    <i class="fas fa-undo mr-1"></i> Restore
                                                </button>
                                            <?php else: ?>
                                                <span class="text-gray-600 text-xs">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                <div class="px-4 py-4 border-t border-gray-800 flex justify-between items-center flex-wrap gap-3">
                    <div class="text-[10px] text-gray-500 uppercase">
                        Showing <?= $pageStart ?> to <?= $pageEnd ?> of <?= $totalEvents ?> events
                    </div>
                    <div class="flex gap-2">
                        <?php if ($page > 1): ?>
                        <a href="<?= alE(alPageUrl(1, $filters)) ?>" class="bg-white/5 hover:bg-white/10 px-3 py-2 rounded-lg text-[10px] font-bold transition">
                            <i class="fas fa-chevron-left mr-1"></i>First
                        </a>
                        <a href="<?= alE(alPageUrl($page - 1, $filters)) ?>" class="bg-white/5 hover:bg-white/10 px-3 py-2 rounded-lg text-[10px] font-bold transition">
                            <i class="fas fa-chevron-left mr-1"></i>Prev
                        </a>
                        <?php endif; ?>

                        <span class="px-3 py-2 text-[10px] font-bold text-gray-400">
                            Page <?= $page ?> of <?= $totalPages ?>
                        </span>

                        <?php if ($page < $totalPages): ?>
                        <a href="<?= alE(alPageUrl($page + 1, $filters)) ?>" class="bg-white/5 hover:bg-white/10 px-3 py-2 rounded-lg text-[10px] font-bold transition">
                            Next<i class="fas fa-chevron-right ml-1"></i>
                        </a>
                        <a href="<?= alE(alPageUrl($totalPages, $filters)) ?>" class="bg-white/5 hover:bg-white/10 px-3 py-2 rounded-lg text-[10px] font-bold transition">
                            Last<i class="fas fa-chevron-right ml-1"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Restore Confirmation Modal -->
    <div id="restoreConfirmationModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4">
        <div id="restoreConfirmationCard"
             class="card-bg w-full max-w-md rounded-3xl transform transition-all duration-300 ease-out scale-95 opacity-0 shadow-2xl">
            <div class="px-8 py-6 border-b border-gray-800 flex justify-between items-center">
                <h2 class="text-xl tracking-wide font-bold italic uppercase text-green-400">RESTORE RECORD</h2>
                <button type="button" onclick="closeRestoreModal()" class="text-gray-500 hover:text-white transition">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>

            <div class="p-8">
                <div class="flex items-center gap-4 mb-6">
                    <div class="flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full bg-green-500/10">
                        <i class="fas fa-undo text-green-400 text-lg"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-bold text-white mb-1">Restore Record</h3>
                        <p class="text-sm text-gray-400">Are you sure you want to restore this record?</p>
                    </div>
                </div>

                <div class="bg-white/5 rounded-xl p-4 mb-6 border border-gray-800/50">
                    <div class="text-sm text-gray-500 mb-1">Record Details</div>
                    <div class="text-sm font-bold text-white mb-2 break-words" id="restoreRecordName"></div>
                    <div class="text-xs text-gray-400" id="restoreRecordType"></div>
                </div>

                <div class="flex gap-4">
                    <button type="button" onclick="closeRestoreModal()"
                            class="flex-1 px-6 py-3 bg-white/5 rounded-3xl hover:bg-white/10 transition text-sm font-semibold">
                        CANCEL
                    </button>
                    <button type="button" id="confirmRestoreBtn" onclick="confirmRestore()"
                            class="flex-1 px-6 py-3 bg-green-600 rounded-3xl hover:bg-green-700 transition text-sm font-semibold shadow-lg shadow-green-900/20">
                        <i class="fas fa-undo mr-2"></i> YES, RESTORE
                    </button>
                </div>
            </div>
        </div>
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

document.addEventListener('DOMContentLoaded', function () {
    const filterForm = document.getElementById('activityFilterForm');
    if (!filterForm) {
        return;
    }

    const startDateInput = filterForm.querySelector('input[name="start_date"]');
    const endDateInput = filterForm.querySelector('input[name="end_date"]');

    filterForm.addEventListener('submit', function (e) {
        if (startDateInput && endDateInput && startDateInput.value && endDateInput.value) {
            if (new Date(startDateInput.value) > new Date(endDateInput.value)) {
                e.preventDefault();
                alToast('End date cannot be earlier than start date.', 'error');
            }
        }
    });
});

/* ------------------------------------------------------------------ toast */
function alToast(message, type) {
    let container = document.getElementById('toastContainer');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toastContainer';
        document.body.appendChild(container);
    }

    const toast = document.createElement('div');
    toast.className =
        'fixed top-6 left-1/2 -translate-x-1/2 px-6 py-4 rounded-xl shadow-2xl border flex items-center gap-3 ' +
        'transform transition-all duration-300 ease-out z-[9999] ' +
        (type === 'success'
            ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-400'
            : 'bg-amber-500/10 border-amber-500/30 text-amber-400');

    const icon = document.createElement('i');
    icon.className = type === 'success'
        ? 'fas fa-check-circle text-lg'
        : 'fas fa-exclamation-circle text-lg';

    const text = document.createElement('span');
    text.className = 'text-sm font-semibold';
    text.textContent = String(message == null ? '' : message);

    toast.appendChild(icon);
    toast.appendChild(text);
    container.appendChild(toast);

    setTimeout(function () {
        toast.remove();
    }, 3200);
}

/* ---------------------------------------------------------- restore modal */
const AL_CSRF_TOKEN = <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?>;

let currentRestoreTable = '';
let currentRestoreId = 0;

function openRestoreModal(table, id, recordName) {
    const numericId = parseInt(id, 10);
    if (!Number.isFinite(numericId) || numericId <= 0) {
        alToast('Invalid record reference.', 'error');
        return;
    }

    currentRestoreTable = String(table || '').toLowerCase();
    currentRestoreId = numericId;

    document.getElementById('restoreRecordName').textContent = String(recordName == null ? '' : recordName);
    document.getElementById('restoreRecordType').textContent =
        'Type: ' + currentRestoreTable.charAt(0).toUpperCase() + currentRestoreTable.slice(1).replace(/_/g, ' ');

    const modal = document.getElementById('restoreConfirmationModal');
    const card = document.getElementById('restoreConfirmationCard');

    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';

    requestAnimationFrame(function () {
        card.classList.remove('scale-95', 'opacity-0');
        card.classList.add('scale-100', 'opacity-100');
    });
}

function closeRestoreModal() {
    const modal = document.getElementById('restoreConfirmationModal');
    const card = document.getElementById('restoreConfirmationCard');

    card.classList.remove('scale-100', 'opacity-100');
    card.classList.add('scale-95', 'opacity-0');
    document.body.style.overflow = 'auto';

    setTimeout(function () {
        modal.classList.add('hidden');
        currentRestoreTable = '';
        currentRestoreId = 0;
    }, 300);
}

async function confirmRestore() {
    if (!currentRestoreTable || !currentRestoreId) {
        closeRestoreModal();
        return;
    }

    const restoreBtn = document.getElementById('confirmRestoreBtn');
    const originalHtml = restoreBtn.innerHTML;
    restoreBtn.disabled = true;
    restoreBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> RESTORING...';

    const formData = new FormData();
    formData.append('table', currentRestoreTable);
    formData.append('id', String(currentRestoreId));
    formData.append('csrf_token', AL_CSRF_TOKEN);

    try {
        const response = await fetch('../actions/restore_record.php', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        });

        const text = await response.text();
        let result = null;
        try {
            result = JSON.parse(text);
        } catch (err) {
            result = null;
        }

        if (result && result.success) {
            alToast(result.message || 'Record restored successfully.', 'success');
            closeRestoreModal();
            setTimeout(function () {
                window.location.reload();
            }, 900);
            return;
        }

        restoreBtn.disabled = false;
        restoreBtn.innerHTML = originalHtml;
        alToast((result && (result.error || result.message)) || 'Restore failed.', 'error');
    } catch (err) {
        restoreBtn.disabled = false;
        restoreBtn.innerHTML = originalHtml;
        alToast('Could not reach the server. Please try again.', 'error');
    }
}

document.addEventListener('keydown', function (e) {
    const modal = document.getElementById('restoreConfirmationModal');
    if (e.key === 'Escape' && modal && !modal.classList.contains('hidden')) {
        closeRestoreModal();
    }
});

document.addEventListener('click', function (event) {
    const modal = document.getElementById('restoreConfirmationModal');
    if (modal && event.target === modal) {
        closeRestoreModal();
    }
});
</script>
</html>
