<?php
/**
 * Users Management
 * RBAC source of truth: config/permissions.php via includes/authorize.php
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';

$roles = require __DIR__ . '/../config/roles.php';

/* ---------------------------------------------------------------------------
 * Backwards-compatible aliases.
 * The modals included below still call canAccess()/getPermission(); keep the
 * names alive but delegate to the centralized RBAC instead of a stale matrix.
 * ------------------------------------------------------------------------- */
if (!function_exists('canAccess')) {
    function canAccess(string $table, string $action = 'read'): bool
    {
        return hasPermission($table, $action);
    }
}
if (!function_exists('getPermission')) {
    function getPermission(string $table, string $action = 'read')
    {
        return permissionValue($table, $action);
    }
}

/* ------------------------------------------------------------- access gate */
/*
 * users.php manages login accounts, roles, and account status. The RBAC
 * matrix grants users.read only to SuperAdmin; require the literal `true`
 * so a future scoped grant can never reopen the page by accident.
 */
$usersScope = permissionValue('users', 'read');
if ($usersScope !== true) {
    http_response_code(403);
    exit('Access Denied: You do not have permission to view users.');
}

/*
 * Only an *unscoped* grant (literal true) permits creating accounts or
 * toggling status. 'own' is deliberately NOT sufficient: hasPermission() only
 * tests `!== false`, so a Member's 'own' write grant would otherwise let them
 * POST role=SuperAdmin and mint an administrator account.
 */
$canManageUsers = permissionValue('users', 'write') === true;
$canToggleUsers = permissionValue('users', 'delete') === true;

$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$currentRole   = (string)($_SESSION['role'] ?? '');

/* ------------------------------------------------------------------ filters */
$statusFilter = '';
$rawStatus    = $_GET['status'] ?? '';
if (is_string($rawStatus) && in_array($rawStatus, ['Active', 'Deactivated'], true)) {
    $statusFilter = $rawStatus;
}

$roleFilter = '';
$rawRole    = $_GET['role'] ?? '';
if (is_string($rawRole) && $rawRole !== '' && array_key_exists($rawRole, $roles)) {
    $roleFilter = $rawRole;
}

$search = '';
$rawSearch = $_GET['search'] ?? '';
if (is_string($rawSearch)) {
    $search = trim($rawSearch);
    if ($search !== '') {
        $search = mb_substr($search, 0, 100);
    }
}

/* -------------------------------------------------------------------- query */
$where = [];
$params = [];

if ($usersScope === 'own') {
    // 'own' is NOT enforced by hasPermission(); enforce it here.
    $where[]            = 'u.user_id = :scope_user';
    $params[':scope_user'] = $currentUserId;
}

if ($statusFilter !== '') {
    $where[]            = 'u.is_active = :status';
    $params[':status']  = $statusFilter === 'Active' ? 1 : 0;
}

if ($roleFilter !== '') {
    $where[]          = 'u.role = :role';
    $params[':role']  = $roleFilter;
}

if ($search !== '') {
    // Plain LIKE, one placeholder per column (native prepares forbid
    // reusing a name). No ESCAPE clause: on hosts with NO_BACKSLASH_ESCAPES
    // sql_mode, ESCAPE '\\' throws and EVERY search fails. A % or _
    // typed in the box just acts as a wildcard — harmless for search.
    // m.full_name covers linked member names, so searching a member's name
    // finds their login account (if they have one).
    $like = '%' . $search . '%';
    $where[] = "(u.full_name LIKE :search1 OR u.username LIKE :search2 OR u.email LIKE :search3 OR m.full_name LIKE :search4)";
    $params[':search1'] = $like;
    $params[':search2'] = $like;
    $params[':search3'] = $like;
    $params[':search4'] = $like;
}

$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$perPage        = 10;
$requestedPage  = (isset($_GET['page']) && is_numeric($_GET['page'])) ? max(1, (int)$_GET['page']) : 1;

$users      = [];
$totalUsers = 0;
$totalPages = 1;
$page       = 1;
$page = min($requestedPage, $totalPages);
$offset     = ($page - 1) * $perPage;

try {
    $db = (new Database())->connect();

    $countStmt = $db->prepare('SELECT COUNT(*) FROM users u LEFT JOIN members m ON m.member_id = u.member_id' . $whereSql);
    $countStmt->execute($params);
    $totalUsers = (int)$countStmt->fetchColumn();

    $totalPages = max(1, (int)ceil($totalUsers / $perPage));
    $page       = min($requestedPage, $totalPages);
    $offset     = ($page - 1) * $perPage;

    if ($totalUsers > 0) {
        $listSql = 'SELECT u.user_id, u.full_name, u.username, u.email, u.role,
 u.is_active, u.created_at, u.updated_at, u.last_login_at
                    FROM users u LEFT JOIN members m ON m.member_id = u.member_id' . $whereSql . '
                    ORDER BY u.created_at DESC, u.user_id DESC
                    LIMIT ' . $perPage . ' OFFSET ' . $offset;

        $listStmt = $db->prepare($listSql);
        $listStmt->execute($params);
        $users = $listStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Throwable $e) {
    error_log('users.php query error: ' . $e->getMessage());
    $users      = [];
    $totalUsers = 0;
    $totalPages = 1;
    $page       = 1;
    $offset     = 0;
}

/* ------------------------------------------------------------------- flash */
$flashSuccess = isset($_SESSION['success']) && is_string($_SESSION['success'])
    ? $_SESSION['success'] : null;
$flashError   = isset($_SESSION['error']) && is_string($_SESSION['error'])
    ? $_SESSION['error'] : null;
unset($_SESSION['success'], $_SESSION['error']);

/* ----------------------------------------------------------------- helpers */
function usrE(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Safe value for a JS-in-HTML-attribute position. */
function usrJsArg($value): string
{
    $json = json_encode(
        (string)$value,
        JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE
    );
    return htmlspecialchars((string)$json, ENT_QUOTES, 'UTF-8');
}

function usrInitials(?string $name): string
{
    $name = trim((string)$name);
    if ($name === '') {
        return '?';
    }
    $parts = preg_split('/\s+/', $name) ?: [];
    $out   = '';
    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }
        $out .= strtoupper(mb_substr($part, 0, 1));
        if (mb_strlen($out) >= 2) {
            break;
        }
    }
    return $out !== '' ? $out : '?';
}

function usrFormatDate(?string $value): string
{
    if ($value === null || trim($value) === '' || str_starts_with($value, '0000-00-00')) {
        return '—';
    }
    $ts = strtotime($value);
    return $ts === false ? '—' : date('M d, Y', $ts);
}

function usrFormatDateTime(?string $value): string
{
    if ($value === null || trim($value) === '' || str_starts_with($value, '0000-00-00')) {
        return '—';
    }
    $ts = strtotime($value);
    return $ts === false ? '—' : date('M d, Y g:i A', $ts);
}

function usrRoleBadge(?string $role): array
{
    $badges = [
        'SuperAdmin' => ['bg' => 'bg-red-500/10',     'text' => 'text-red-500',    'border' => 'border-red-500/20',    'color' => 'red'],
        'Coach/Trainer' => ['bg' => 'bg-blue-500/10',  'text' => 'text-blue-400',   'border' => 'border-blue-500/20',   'color' => 'blue'],
        'Officer'   => ['bg' => 'bg-green-500/10',    'text' => 'text-green-400',  'border' => 'border-green-500/20',  'color' => 'green'],
        'Committee' => ['bg' => 'bg-yellow-500/10',   'text' => 'text-yellow-400', 'border' => 'border-yellow-500/20', 'color' => 'yellow'],
        'Board Member' => ['bg' => 'bg-purple-500/10', 'text' => 'text-purple-400', 'border' => 'border-purple-500/20', 'color' => 'purple'],
        'Member'    => ['bg' => 'bg-teal-500/10',     'text' => 'text-teal-400',   'border' => 'border-teal-500/20',   'color' => 'teal'],
        'Applicant' => ['bg' => 'bg-amber-500/10',    'text' => 'text-amber-400',  'border' => 'border-amber-500/20',  'color' => 'amber'],
    ];

    return $badges[(string)$role] ?? [
        'bg' => 'bg-gray-500/10', 'text' => 'text-gray-400',
        'border' => 'border-gray-500/20', 'color' => 'gray',
    ];
}

function usrPageUrl(int $page, string $status, string $role, string $search): string
{
    $query = [];
    if ($status !== '') {
        $query['status'] = $status;
    }
    if ($role !== '') {
        $query['role'] = $role;
    }
    if ($search !== '') {
        $query['search'] = $search;
    }
    $query['page'] = $page;
    return '?' . http_build_query($query);
}

$hasFilters = ($statusFilter !== '' || $roleFilter !== '' || $search !== '');
?>

<!DOCTYPE html>
<html lang="en" class="text-[13px]">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users | Arnis Portal</title>
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

            <?php if ($usersScope === 'own'): ?>
                <div class="card-bg border-l-4 border-l-teal-500 rounded-2xl px-4 py-3 mb-6 text-xs text-gray-300">
                    <i class="fas fa-user-lock mr-2 text-teal-400"></i>
                    You are viewing your own account record only.
                </div>
            <?php endif; ?>

            <?php if ($flashSuccess !== null): ?>
                <div class="bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 rounded-2xl px-4 py-3 mb-6 text-xs sm:text-sm">
                    <i class="fas fa-check-circle mr-2"></i><?= usrE($flashSuccess) ?>
                </div>
            <?php endif; ?>
            <?php if ($flashError !== null): ?>
                <div class="bg-red-500/10 border border-red-500/30 text-red-400 rounded-2xl px-4 py-3 mb-6 text-xs sm:text-sm">
                    <i class="fas fa-exclamation-circle mr-2"></i><?= usrE($flashError) ?>
                </div>
            <?php endif; ?>

            <!-- RESPONSIVE: Filters - Stack on mobile -->
            <form method="GET" class="flex flex-col lg:flex-row justify-between items-stretch lg:items-center mb-6 gap-3">
                <div class="flex flex-col sm:flex-row gap-2 sm:gap-3">
                    <select name="status" onchange="this.form.submit()" class="card-bg border-none rounded-full px-4 sm:px-5 py-3 sm:py-4 text-xs sm:text-sm outline-none text-center w-full sm:w-40 lg:w-48">
                        <option value="" <?= $statusFilter === '' ? 'selected' : '' ?>>All Status</option>
                        <option value="Active" <?= $statusFilter === 'Active' ? 'selected' : '' ?>>Active</option>
                        <option value="Deactivated" <?= $statusFilter === 'Deactivated' ? 'selected' : '' ?>>Deactivated</option>
                    </select>
                    <select name="role" onchange="this.form.submit()" class="card-bg border-none rounded-full px-4 sm:px-5 py-3 sm:py-4 text-xs sm:text-sm outline-none text-center w-full sm:w-40 lg:w-48 overflow-hidden whitespace-nowrap text-ellipsis">
                        <option value="" <?= $roleFilter === '' ? 'selected' : '' ?>>All Roles</option>
                        <?php foreach ($roles as $roleKey => $roleMeta): ?>
                            <option value="<?= usrE($roleKey) ?>" <?= $roleFilter === $roleKey ? 'selected' : '' ?>>
                                <?= usrE($roleMeta['label'] ?? $roleKey) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 sm:gap-4">
                    <div class="relative">
                        <i class="fas fa-search absolute left-3 sm:left-4 top-1/2 -translate-y-1/2 text-gray-500 text-xs sm:text-sm"></i>
                        <input type="search" name="search" maxlength="100" value="<?= usrE($search) ?>" placeholder="Search name, username, email..." class="card-bg rounded-full pl-9 sm:pl-10 pr-4 py-2 text-xs sm:text-sm outline-none w-full sm:w-48 lg:w-64">
                    </div>
                    <button type="submit" class="px-5 sm:px-6 py-4 sm:py-5 rounded-full font-bold text-xs sm:text-sm whitespace-nowrap transition bg-accent-purple hover:opacity-90 cursor-pointer">
                        <i class="fas fa-search mr-1"></i> Search
                    </button>
                    <?php if ($hasFilters): ?>
                        <a href="users.php" class="px-5 sm:px-6 py-4 sm:py-5 rounded-full font-bold text-xs sm:text-sm whitespace-nowrap transition bg-white/5 hover:bg-white/10 text-center">
                            <i class="fas fa-redo mr-1"></i> Reset
                        </a>
                    <?php endif; ?>
                    <?php if ($canManageUsers): ?>
                        <button onclick="openAddUserModal()" type="button"
                                class="px-5 sm:px-6 py-4 sm:py-5 rounded-full font-bold text-xs sm:text-sm whitespace-nowrap transition bg-accent-purple hover:opacity-90 cursor-pointer">
                            + Add New User
                        </button>
                    <?php endif; ?>
                </div>
            </form>

            <div class="text-[10px] sm:text-xs text-gray-500 uppercase tracking-widest mb-3">
                Showing <?= $totalUsers === 0 ? 0 : ($offset + 1) ?>–<?= min($offset + $perPage, $totalUsers) ?>
                of <?= $totalUsers ?> user<?= $totalUsers === 1 ? '' : 's' ?>
            </div>

            <!-- RESPONSIVE: Cards on mobile, table on desktop -->
            <div class="card-bg rounded-2xl sm:rounded-3xl overflow-hidden shadow-xl">
                <!-- MOBILE: Card View -->
                <div class="block lg:hidden">
                    <div class="p-2 space-y-2">
                        <?php if (empty($users)): ?>
                            <div class="text-center text-gray-500 py-8">No users found</div>
                        <?php else: ?>
                            <?php foreach ($users as $user):
                                $userRole    = (string)($user['role'] ?? '');
                                $badge       = usrRoleBadge($userRole);
                                $initials    = usrInitials($user['full_name'] ?? null);
                                $isActive    = (int)($user['is_active'] ?? 0) === 1;
                                $statusBg    = $isActive ? 'bg-green-500' : 'bg-gray-500';
                                $statusText  = $isActive ? 'Active' : 'Deactivated';
                                $colorClass  = $badge['color'];
                            ?>
                            <div class="bg-white/[0.02] rounded-xl p-4 border border-gray-800/50">
                                <div class="flex items-start gap-3 mb-3">
                                    <div class="w-10 h-10 rounded-full bg-<?= $colorClass ?>-500/20 flex items-center justify-center text-<?= $colorClass ?>-400 font-bold uppercase text-xs flex-shrink-0">
                                        <?= usrE($initials) ?>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="font-bold text-sm mb-1 break-words"><?= usrE($user['full_name'] ?? '') ?></div>
                                        <div class="text-[10px] text-gray-500 italic mb-1 break-all">@<?= usrE($user['username'] ?? '') ?></div>
                                        <?php if (!empty($user['email'])): ?>
                                            <div class="text-[10px] text-gray-500 italic mb-2 break-all"><?= usrE($user['email']) ?></div>
                                        <?php endif; ?>
                                        <span class="px-2 py-1 rounded-full <?= $badge['bg'] ?> <?= $badge['text'] ?> text-[9px] font-black uppercase tracking-tighter border <?= $badge['border'] ?> inline-block">
                                            <?= usrE($roles[$userRole]['label'] ?? $userRole) ?>
                                        </span>
                                    </div>
                                </div>

                                <div class="flex justify-between items-center text-xs mb-2 pb-2 border-b border-gray-800">
                                    <div class="flex items-center gap-2">
                                        <span class="w-2 h-2 rounded-full <?= $statusBg ?>"></span>
                                        <span class="text-gray-300"><?= $statusText ?></span>
                                    </div>
                                </div>

                                <div class="text-[10px] text-gray-500 space-y-1 mb-3">
                                    <div class="flex justify-between">
                                        <span>Created:</span>
                                        <span><?= usrE(usrFormatDate($user['created_at'] ?? null)) ?></span>
                                    </div>
                                    <?php if (!empty($user['updated_at'])): ?>
                                    <div class="flex justify-between">
                                        <span>Updated:</span>
                                        <span><?= usrE(usrFormatDateTime($user['updated_at'])) ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($user['last_login_at'])): ?>
                                    <div class="flex justify-between">
                                        <span>Last Login:</span>
                                        <span><?= usrE(usrFormatDateTime($user['last_login_at'])) ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <?php if ($canManageUsers || $canToggleUsers): ?>
                                <div class="flex gap-2">
                                    <?php if ($canManageUsers): ?>
                                        <button onclick="openEditUserModal(<?= (int)($user['user_id'] ?? 0) ?>)"
                                                class="flex-1 text-center py-2 rounded-lg text-[10px] font-bold transition bg-purple-500/10 text-purple-400 hover:bg-purple-500 hover:text-white cursor-pointer"
                                                title="Edit user">
                                            <i class="fas fa-edit mr-1"></i> Edit
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($canToggleUsers): ?>
                                        <?php if ($isActive): ?>
                                            <button onclick="openDeactivateModal(<?= (int)$user['user_id'] ?>, <?= usrJsArg($user['full_name'] ?? '') ?>)"
                                                    class="flex-1 text-center py-2 rounded-lg text-[10px] font-bold transition bg-red-500/10 text-red-500 hover:bg-red-500 hover:text-white cursor-pointer"
                                                    title="Deactivate user">
                                                <i class="fas fa-power-off mr-1"></i> Deactivate
                                            </button>
                                        <?php else: ?>
                                            <button onclick="openActivateModal(<?= (int)$user['user_id'] ?>, <?= usrJsArg($user['full_name'] ?? '') ?>)"
                                                    class="flex-1 text-center py-2 rounded-lg text-[10px] font-bold transition bg-green-500/10 text-green-500 hover:bg-green-500 hover:text-white cursor-pointer"
                                                    title="Activate user">
                                                <i class="fas fa-power-off mr-1"></i> Activate
                                            </button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if ($canToggleUsers): ?>
                                        <button onclick="openDeleteUserModal(<?= (int)$user['user_id'] ?>, <?= usrJsArg($user['full_name'] ?? '') ?>)"
                                                class="flex-1 text-center py-2 rounded-lg text-[10px] font-bold transition bg-gray-500/10 text-gray-400 hover:bg-red-600 hover:text-white cursor-pointer"
                                                title="Delete user permanently">
                                            <i class="fas fa-trash mr-1"></i> Delete
                                        </button>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- DESKTOP: Table View -->
                <div class="hidden lg:block overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead class="text-gray-400 text-xs uppercase tracking-widest border-b border-gray-800">
                            <tr>
                                <th class="p-4 lg:p-6">User Details</th>
                                <th class="p-4 lg:p-6">System Role</th>
                                <th class="p-4 lg:p-6">Status</th>
                                <th class="p-4 lg:p-6">Created At</th>
                                <th class="p-4 lg:p-6">Last Updated</th>
                                <th class="p-4 lg:p-6">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="text-sm">
                            <?php if (empty($users)): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-gray-500 py-8">No users found</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($users as $user):
                                    $userRole   = (string)($user['role'] ?? '');
                                    $badge      = usrRoleBadge($userRole);
                                    $initials   = usrInitials($user['full_name'] ?? null);
                                    $isActive   = (int)($user['is_active'] ?? 0) === 1;
                                    $statusBg   = $isActive ? 'bg-green-500' : 'bg-gray-500';
                                    $statusText = $isActive ? 'Active' : 'Deactivated';
                                    $colorClass = $badge['color'];
                                ?>
                                <tr class="border-b border-gray-800 hover:bg-white/5 transition">
                                    <td class="p-4 lg:p-6">
                                        <div class="flex items-center gap-3">
                                            <div class="w-10 h-10 rounded-full bg-<?= $colorClass ?>-500/20 flex items-center justify-center text-<?= $colorClass ?>-400 font-bold uppercase flex-shrink-0">
                                                <?= usrE($initials) ?>
                                            </div>
                                            <div class="min-w-0">
                                                <div class="font-bold text-white break-words"><?= usrE($user['full_name'] ?? '') ?></div>
                                                <div class="text-[10px] text-gray-500 italic break-all">@<?= usrE($user['username'] ?? '') ?></div>
                                                <?php if (!empty($user['email'])): ?>
                                                    <div class="text-[10px] text-gray-600 italic break-all"><?= usrE($user['email']) ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="p-4 lg:p-6">
                                        <span class="px-3 py-1 rounded-full <?= $badge['bg'] ?> <?= $badge['text'] ?> text-[10px] font-black uppercase tracking-tighter border <?= $badge['border'] ?> whitespace-nowrap">
                                            <?= usrE($roles[$userRole]['label'] ?? $userRole) ?>
                                        </span>
                                    </td>
                                    <td class="p-4 lg:p-6">
                                        <div class="flex items-center gap-2">
                                            <span class="w-2 h-2 rounded-full <?= $statusBg ?>"></span>
                                            <span class="text-xs text-gray-300"><?= $statusText ?></span>
                                        </div>
                                    </td>
                                    <td class="p-4 lg:p-6 text-gray-500 text-xs whitespace-nowrap"><?= usrE(usrFormatDate($user['created_at'] ?? null)) ?></td>
                                    <td class="p-4 lg:p-6 text-gray-500 text-xs">
                                        <?php if (!empty($user['updated_at'])): ?>
                                            <span class="whitespace-nowrap"><?= usrE(usrFormatDateTime($user['updated_at'])) ?></span>
                                        <?php else: ?>
                                            <span class="text-gray-600 italic">Never</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="p-4 lg:p-6 space-x-2">
                                        <?php if ($canManageUsers): ?>
                                            <button onclick="openEditUserModal(<?= (int)$user['user_id'] ?>)"
                                                    title="Edit User"
                                                    class="transition text-gray-600 hover:text-white cursor-pointer">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                        <?php endif; ?>
                                        <?php if ($canToggleUsers): ?>
                                            <?php if ($isActive): ?>
                                                <button onclick="openDeactivateModal(<?= (int)$user['user_id'] ?>, <?= usrJsArg($user['full_name'] ?? '') ?>)"
                                                        title="Deactivate User"
                                                        class="transition text-gray-600 hover:text-red-500 cursor-pointer">
                                                    <i class="fas fa-power-off"></i>
                                                </button>
                                            <?php else: ?>
                                                <button onclick="openActivateModal(<?= (int)$user['user_id'] ?>, <?= usrJsArg($user['full_name'] ?? '') ?>)"
                                                        title="Activate User"
                                                        class="transition text-gray-600 hover:text-green-500 cursor-pointer">
                                                    <i class="fas fa-power-off"></i>
                                                </button>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <?php if ($canToggleUsers): ?>
                                            <button onclick="openDeleteUserModal(<?= (int)$user['user_id'] ?>, <?= usrJsArg($user['full_name'] ?? '') ?>)"
                                                    title="Delete User Permanently"
                                                    class="transition text-gray-600 hover:text-red-600 cursor-pointer">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        <?php endif; ?>
                                        <?php if (!$canManageUsers && !$canToggleUsers): ?>
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
                        Page <?= $page ?> of <?= $totalPages ?>
                    </div>
                    <div class="flex gap-2">
                        <?php if ($page > 1): ?>
                            <a href="<?= usrE(usrPageUrl(1, $statusFilter, $roleFilter, $search)) ?>" class="bg-white/5 hover:bg-white/10 px-3 py-2 rounded-lg text-[10px] font-bold transition">
                                <i class="fas fa-chevron-left mr-1"></i>First
                            </a>
                            <a href="<?= usrE(usrPageUrl($page - 1, $statusFilter, $roleFilter, $search)) ?>" class="bg-white/5 hover:bg-white/10 px-3 py-2 rounded-lg text-[10px] font-bold transition">
                                <i class="fas fa-chevron-left mr-1"></i>Prev
                            </a>
                        <?php endif; ?>

                        <span class="px-3 py-2 text-[10px] font-bold text-gray-400">Page <?= $page ?> of <?= $totalPages ?></span>

                        <?php if ($page < $totalPages): ?>
                            <a href="<?= usrE(usrPageUrl($page + 1, $statusFilter, $roleFilter, $search)) ?>" class="bg-white/5 hover:bg-white/10 px-3 py-2 rounded-lg text-[10px] font-bold transition">
                                Next<i class="fas fa-chevron-right ml-1"></i>
                            </a>
                            <a href="<?= usrE(usrPageUrl($totalPages, $statusFilter, $roleFilter, $search)) ?>" class="bg-white/5 hover:bg-white/10 px-3 py-2 rounded-lg text-[10px] font-bold transition">
                                Last<i class="fas fa-chevron-right ml-1"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($canToggleUsers): ?>
            <!-- System Backup & Reset (SuperAdmin only) -->
            <div class="card-bg rounded-2xl sm:rounded-3xl overflow-hidden shadow-xl mt-6">
                <div class="p-4 sm:p-6 border-b border-gray-800 bg-white/5">
                    <h2 class="font-bold text-xs sm:text-sm tracking-widest uppercase flex items-center gap-2">
                        <i class="fas fa-database text-purple-400"></i> System Backup &amp; Reset
                    </h2>
                    <p class="text-[10px] uppercase tracking-[0.16em] text-gray-500 mt-2">SuperAdmin only. Download a backup before wiping — reset keeps SuperAdmin logins, removes everything else.</p>
                </div>
                <div class="p-4 sm:p-6 flex flex-col lg:flex-row gap-3">
                    <form method="POST" action="../actions/export_backup.php" class="flex-1">
                        <input type="hidden" name="csrf_token" value="<?= usrE(arnisCsrfToken()) ?>">
                        <button type="submit" class="w-full px-5 py-4 rounded-full font-bold text-xs sm:text-sm transition bg-blue-500/10 text-blue-300 hover:bg-blue-500 hover:text-white cursor-pointer">
                            <i class="fas fa-download mr-1"></i> Download Full Backup (JSON)
                        </button>
                    </form>
                    <form id="restoreBackupForm" enctype="multipart/form-data" class="flex-1 flex gap-2" onsubmit="return false;">
                        <input type="hidden" name="csrf_token" value="<?= usrE(arnisCsrfToken()) ?>">
                        <label class="flex-1 px-5 py-4 rounded-full font-bold text-xs sm:text-sm transition bg-white/5 text-gray-300 hover:bg-white/10 cursor-pointer text-center truncate">
                            <i class="fas fa-upload mr-1"></i> <span id="restoreFileLabel">Choose backup file…</span>
                            <input type="file" id="restoreFileInput" name="backup_file" accept=".json,application/json" class="hidden">
                        </label>
                        <button type="button" onclick="submitRestoreBackup()" id="restoreBackupBtn" class="px-5 py-4 rounded-full font-bold text-xs sm:text-sm transition bg-green-500/10 text-green-300 hover:bg-green-500 hover:text-white cursor-pointer">
                            Restore
                        </button>
                    </form>
                    <button type="button" onclick="openSystemResetModal()" class="flex-1 px-5 py-4 rounded-full font-bold text-xs sm:text-sm transition bg-red-500/10 text-red-400 hover:bg-red-600 hover:text-white cursor-pointer">
                        <i class="fas fa-exclamation-triangle mr-1"></i> Reset All Data…
                    </button>
                </div>
                <div id="systemBackupMsg" class="hidden mx-4 sm:mx-6 mb-4 sm:mb-6 px-4 py-3 rounded-2xl text-xs sm:text-sm"></div>
            </div>

            <div id="systemResetModal" class="fixed inset-0 bg-black/90 backdrop-blur-md hidden z-[110] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300">
                <div id="systemResetCard" class="card-bg w-full max-w-sm rounded-3xl border border-red-500/30 p-8 text-center transform scale-95 transition-all duration-300 shadow-2xl">
                    <div class="w-20 h-20 bg-red-500/10 text-red-500 rounded-full flex items-center justify-center mx-auto mb-6">
                        <i class="fas fa-exclamation-triangle text-3xl"></i>
                    </div>
                    <h2 class="text-xl font-bold uppercase italic tracking-wider mb-2 text-white">Wipe All Data?</h2>
                    <p class="text-gray-400 text-sm mb-6 leading-relaxed">
                        Removes ALL members, trainings, attendance, events, funds, requests and non-admin users.
                        <span class="text-white font-bold">SuperAdmin logins are kept.</span><br>
                        <span class="text-red-400/80 text-[11px] uppercase tracking-widest font-bold mt-2 block">Type RESET below to confirm. Cannot be undone.</span>
                    </p>
                    <input type="text" id="systemResetConfirm" maxlength="10" placeholder="Type RESET" autocomplete="off"
                           class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm text-center tracking-[0.3em] focus:outline-none border border-transparent focus:border-red-500 transition text-white mb-4">
                    <div id="systemResetError" class="text-red-500 text-xs hidden mb-4"></div>
                    <div class="flex gap-3">
                        <button type="button" onclick="closeSystemResetModal()" class="flex-1 py-4 rounded-2xl bg-gray-800 text-[10px] font-bold uppercase tracking-[0.2em] text-gray-400 hover:text-white transition">Cancel</button>
                        <button type="button" onclick="confirmSystemReset()" id="systemResetBtn" class="flex-1 py-4 rounded-2xl bg-red-600 text-black font-black text-[10px] uppercase tracking-[0.2em] hover:bg-red-500 transition">Wipe Now</button>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </main>
    </div>

    <?php include_once "modals/add_user.php"; ?>
    <?php include_once "modals/edit_user.php"; ?>
    <?php include_once "modals/deactivate_confirmation.php"; ?>
    <?php include_once "modals/activate_confirmation.php"; ?>
    <?php include_once "modals/delete_user.php"; ?>
    <?php include_once "modals/edit_profile_user_page.php"; ?>
    <?php include_once "modals/user_menu.php"; ?>

</body>

<script>
window.addEventListener("load", () => {
    const page = document.getElementById("page");
    if (page) {
        page.classList.remove("opacity-0", "translate-x-8");
    }
});

function systemBackupMsg(text, ok) {
    const box = document.getElementById('systemBackupMsg');
    if (!box) return;
    box.textContent = String(text || '');
    box.classList.remove('hidden',
        'bg-emerald-500/10', 'border', 'border-emerald-500/30', 'text-emerald-400',
        'bg-red-500/10', 'border-red-500/30', 'text-red-400');
    box.classList.add('border',
        ok ? 'bg-emerald-500/10' : 'bg-red-500/10',
        ok ? 'border-emerald-500/30' : 'border-red-500/30',
        ok ? 'text-emerald-400' : 'text-red-400');
}

document.addEventListener('DOMContentLoaded', () => {
    const fileInput = document.getElementById('restoreFileInput');
    const label = document.getElementById('restoreFileLabel');
    if (fileInput && label) {
        fileInput.addEventListener('change', () => {
            const f = fileInput.files && fileInput.files[0];
            label.textContent = f ? f.name : 'Choose backup file…';
        });
    }
});

async function submitRestoreBackup() {
    const fileInput = document.getElementById('restoreFileInput');
    const btn = document.getElementById('restoreBackupBtn');
    const f = fileInput && fileInput.files ? fileInput.files[0] : null;
    if (!f) {
        systemBackupMsg('Choose a backup .json file first.', false);
        return;
    }
    if (!confirm('Restore will REPLACE all current data with this backup. Continue?')) {
        return;
    }

    const csrfInput = document.querySelector('#restoreBackupForm input[name="csrf_token"]');
    const formData = new FormData();
    formData.append('backup_file', f);
    if (csrfInput) formData.append('csrf_token', csrfInput.value);

    btn.disabled = true;
    const original = btn.textContent;
    btn.textContent = 'Restoring...';

    try {
        const response = await fetch('../actions/import_backup.php', {
            method: 'POST',
            body: formData,
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        });
        const result = await response.json();
        if (result && result.success) {
            systemBackupMsg(result.message || 'Restore complete.', true);
            setTimeout(() => window.location.reload(), 2000);
        } else {
            throw new Error((result && (result.error || result.message)) || 'Restore failed.');
        }
    } catch (err) {
        systemBackupMsg((err && err.message) ? err.message : 'Restore failed.', false);
    } finally {
        btn.disabled = false;
        btn.textContent = original;
    }
}

function openSystemResetModal() {
    const modal = document.getElementById('systemResetModal');
    const card = document.getElementById('systemResetCard');
    if (!modal || !card) return;
    document.getElementById('systemResetConfirm').value = '';
    const errBox = document.getElementById('systemResetError');
    if (errBox) { errBox.textContent = ''; errBox.classList.add('hidden'); }
    modal.classList.remove('hidden');
    setTimeout(() => {
        modal.classList.add('opacity-100');
        card.classList.remove('scale-95');
        card.classList.add('scale-100');
    }, 10);
}

function closeSystemResetModal() {
    const modal = document.getElementById('systemResetModal');
    const card = document.getElementById('systemResetCard');
    if (!modal || !card) return;
    modal.classList.remove('opacity-100');
    card.classList.replace('scale-100', 'scale-95');
    setTimeout(() => modal.classList.add('hidden'), 300);
}

async function confirmSystemReset() {
    const typed = document.getElementById('systemResetConfirm').value.trim();
    const errBox = document.getElementById('systemResetError');
    const btn = document.getElementById('systemResetBtn');
    const csrfInput = document.querySelector('#restoreBackupForm input[name="csrf_token"]');

    if (typed !== 'RESET') {
        errBox.textContent = 'Type RESET exactly to confirm.';
        errBox.classList.remove('hidden');
        return;
    }

    btn.disabled = true;
    btn.textContent = 'Wiping...';

    try {
        const body = new URLSearchParams({
            confirmation: 'RESET',
            csrf_token: csrfInput ? csrfInput.value : ''
        });
        const response = await fetch('../actions/reset_system.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: body.toString()
        });
        const result = await response.json();
        if (result && result.success) {
            closeSystemResetModal();
            systemBackupMsg(result.message || 'System wiped.', true);
            setTimeout(() => window.location.reload(), 2500);
        } else {
            throw new Error((result && (result.error || result.message)) || 'Reset failed.');
        }
    } catch (err) {
        errBox.textContent = (err && err.message) ? err.message : 'Reset failed.';
        errBox.classList.remove('hidden');
        btn.disabled = false;
        btn.textContent = 'Wipe Now';
    }
}
</script>

</html>