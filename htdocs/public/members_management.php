<?php
require_once "../includes/auth.php";
require_once "../includes/authorize.php";
require_once "../config/Database.php";
require_once "../classes/Members.php";

/*
 * config/permissions.php is the single RBAC source of truth.
 * The inline matrix that used to live here diverged from config/permissions.php
 * (missing SuperAdmin, Applicant; Officer delete='limited' vs false).
 * Use the centralized hasPermission()/permissionValue() instead.
 */

if (!canAccess('members', 'read')) {
    http_response_code(403);
    die("Access Denied: You do not have permission to view members.");
}

$getStr = static function (string $key): string {
    return (isset($_GET[$key]) && is_string($_GET[$key])) ? mb_substr(trim($_GET[$key]), 0, 100) : '';
};
$page = (isset($_GET['page']) && is_numeric($_GET['page'])) ? max(1, (int)$_GET['page']) : 1;
$perPage = 10;

$filters = [
    'search' => $getStr('search'),
    'status' => $getStr('status'),
    'rank' => $getStr('rank'),
    'member_type' => $getStr('member_type'),
    'player_category' => $getStr('player_category')
];

/* Drives the Reset button. Enter key submits the GET form below,
 * so search works even if the live AJAX endpoint is unreachable. */
$hasActiveFilters = ($filters['search'] !== '' || $filters['status'] !== ''
    || $filters['rank'] !== '' || $filters['member_type'] !== ''
    || $filters['player_category'] !== '');

$paginatedMembers = [];
$totalMembers = 0;
$totalPages = 1;
$stats = ['total' => 0, 'active' => 0, 'public_count' => 0, 'private_count' => 0];
$pendingApprovals = [];
$pendingApprovalCount = 0;
$membersError = null;

try {
    $db = (new Database())->connect();
    $membersModel = new Members($db);

    /*
     * Resolve the READ scope properly. The old line tested for the literal
     * string 'limited', but the permission matrix stores 'own_trainees', so
     * every Coach/Trainer silently received the full directory.
     */
    $readScope = permissionValue('members', 'read');
    $userPermission = ($readScope === 'own_trainees') ? 'limited' : 'all';
    $scopeCoachId = ($userPermission === 'limited') ? getCurrentCoachMemberId($db) : null;

/*
 * Add / Edit / Approve buttons. Member + Applicant hold members.write =
 * 'own' (self only), which hasPermission() reports as granted — so the raw
 * canAccess() check showed them Add/Edit controls for the whole directory.
 * Only full-write (Admin) and trainee-scoped (Coach) roles modify other
 * members; Member self-service lives on member_profile.php.
 */
$canModifyMembers = in_array(permissionValue('members', 'write'), [true, 'own_trainees'], true);
$isSuperAdmin = hasRole('SuperAdmin');
$memberWriteScope = permissionValue('members', 'write');
$memberFieldPermissions = [
    // Only the SuperAdmin may correct rank/position/payment notes directly.
    'rank' => $memberWriteScope === true,
    'status' => in_array($memberWriteScope, [true, 'own_trainees'], true),
    'member_type' => $memberWriteScope === true,
    'payment_notes' => $memberWriteScope === true,
];

    $result = $membersModel->getAllPaginated($filters, $page, $perPage, $userPermission, $scopeCoachId);
    $stats = $membersModel->getStats($filters, $userPermission, $scopeCoachId);
    // Only SuperAdmin may see or act on pending registration requests.
    $pendingApprovals = $isSuperAdmin ? $membersModel->getPendingRegistrationRequests(20) : [];
    $pendingApprovalCount = $isSuperAdmin ? $membersModel->countPendingRegistrationRequests() : 0;

    $paginatedMembers = $result['members'];
    $totalMembers = $result['total'];
    $totalPages = max(1, (int)$result['totalPages']);
    $page = min($page, $totalPages);
} catch (Throwable $e) {
    // Never white-page on search: log and show an empty list with a notice.
    error_log('members_management.php error: ' . $e->getMessage());
    $membersError = 'Could not load members for those filters. Showing no results — try clearing the search.';
}

function buildQuery(array $filters, int $page): string {
    $filters['page'] = $page;
    return http_build_query($filters);
}
?>
<!DOCTYPE html>
<html lang="en" class="text-[13px]">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Members | Arnis Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" type="image" href="../arnis-favicon.ico">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,100..900;1,100..900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/sidebar.css">
    <link rel="stylesheet" href="css/style.css">
    <script src="https://upload-widget.cloudinary.com/global/all.js" type="text/javascript"></script>
</head>
<body class="text-white bg-dark-purple overflow-x-hidden">
    <div class="flex">
        <?php include_once "../includes/sidebar.php"; ?>
        <main id="page" class="flex-1 p-4 sm:p-6 lg:p-8 h-screen overflow-y-auto opacity-0 translate-x-8 transition-all duration-500 ease-out">
            <?php include_once "../includes/header.php"; ?>

            <?php if (!empty($membersError)): ?>
            <div class="bg-red-500/10 border border-red-500/30 text-red-400 rounded-2xl px-4 py-3 mb-6 text-xs sm:text-sm">
                <i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($membersError, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <?php endif; ?>

            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4 lg:gap-6 mb-6 lg:mb-8">
                <div class="card-bg p-4 sm:p-6 rounded-2xl sm:rounded-3xl text-center shadow-xl">
                    <i class="fas fa-users text-xl sm:text-2xl mb-2 opacity-50"></i>
                    <div class="text-2xl sm:text-3xl lg:text-4xl font-bold" data-stat-total><?= (int)($stats['total'] ?? 0) ?></div>
                    <div class="text-[10px] sm:text-xs text-gray-400 uppercase tracking-widest mt-1">Total Members</div>
                </div>
                <div class="card-bg p-4 sm:p-6 rounded-2xl sm:rounded-3xl text-center shadow-xl">
                    <i class="fas fa-user-check text-xl sm:text-2xl mb-2 text-blue-400 opacity-80"></i>
                    <div class="text-2xl sm:text-3xl lg:text-4xl font-bold" data-stat-active><?= (int)($stats['active'] ?? 0) ?></div>
                    <div class="text-[10px] sm:text-xs text-gray-400 uppercase tracking-widest mt-1">Active Members</div>
                </div>
                <div class="card-bg p-4 sm:p-6 rounded-2xl sm:rounded-3xl text-center shadow-xl">
                    <i class="fas fa-school text-xl sm:text-2xl mb-2 text-green-400 opacity-80"></i>
                    <div class="text-2xl sm:text-3xl lg:text-4xl font-bold" data-stat-public><?= (int)($stats['public_count'] ?? 0) ?></div>
                    <div class="text-[10px] sm:text-xs text-gray-400 uppercase tracking-widest mt-1">Public Players</div>
                </div>
                <div class="card-bg p-4 sm:p-6 rounded-2xl sm:rounded-3xl text-center shadow-xl">
                    <i class="fas fa-coins text-xl sm:text-2xl mb-2 text-yellow-400 opacity-80"></i>
                    <div class="text-2xl sm:text-3xl lg:text-4xl font-bold" data-stat-private><?= (int)($stats['private_count'] ?? 0) ?></div>
                    <div class="text-[10px] sm:text-xs text-gray-400 uppercase tracking-widest mt-1">Private Players</div>
                </div>
            </div>

            <!-- Plain GET form: search runs ONLY on Enter, the Search
                 button, or a filter change (full page reload). Typing alone
                 never searches. -->
            <form method="get" action="members_management.php" id="membersFilterForm" class="flex flex-col xl:flex-row justify-between items-stretch xl:items-center mb-6 gap-4">
                <div class="flex flex-col sm:flex-row flex-wrap gap-2 sm:gap-3">
                    <select name="status" id="statusFilter" class="card-bg border-none rounded-full px-4 py-3 text-xs sm:text-sm outline-none text-center" onchange="this.form.submit()">
                        <option value="">All Status</option>
                        <option value="Active" <?= $filters['status'] === 'Active' ? 'selected' : '' ?>>Active</option>
                        <option value="On Leave" <?= $filters['status'] === 'On Leave' ? 'selected' : '' ?>>On Leave</option>
                        <option value="Inactive" <?= $filters['status'] === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                    <select name="rank" id="rankFilter" class="card-bg border-none rounded-full px-4 py-3 text-xs sm:text-sm outline-none text-center" onchange="this.form.submit()">
                        <option value="">All Ranks</option>
                        <option value="None" <?= $filters['rank'] === 'None' ? 'selected' : '' ?>>No Rank</option>
                        <option value="I. White Belt" <?= $filters['rank'] === 'I. White Belt' ? 'selected' : '' ?>>I. White Belt</option>
                        <option value="II. Yellow Belt" <?= $filters['rank'] === 'II. Yellow Belt' ? 'selected' : '' ?>>II. Yellow Belt</option>
                        <option value="III. Green Belt" <?= $filters['rank'] === 'III. Green Belt' ? 'selected' : '' ?>>III. Green Belt</option>
                        <option value="IV. Blue Belt" <?= $filters['rank'] === 'IV. Blue Belt' ? 'selected' : '' ?>>IV. Blue Belt</option>
                        <option value="V. Brown Belt" <?= $filters['rank'] === 'V. Brown Belt' ? 'selected' : '' ?>>V. Brown Belt</option>
                        <option value="VI. Black Belt" <?= $filters['rank'] === 'VI. Black Belt' ? 'selected' : '' ?>>VI. Black Belt</option>
                        <option value="1st Degree- Junior Instructor" <?= $filters['rank'] === '1st Degree- Junior Instructor' ? 'selected' : '' ?>>1st Degree- Junior Instructor</option>
                        <option value="2nd Degree- Junior Instructor" <?= $filters['rank'] === '2nd Degree- Junior Instructor' ? 'selected' : '' ?>>2nd Degree- Junior Instructor</option>
                        <option value="3rd Degree- Instructor" <?= $filters['rank'] === '3rd Degree- Instructor' ? 'selected' : '' ?>>3rd Degree- Instructor</option>
                        <option value="4th Degree- Instructor" <?= $filters['rank'] === '4th Degree- Instructor' ? 'selected' : '' ?>>4th Degree- Instructor</option>
                        <option value="5th Degree- Senior Instructor" <?= $filters['rank'] === '5th Degree- Senior Instructor' ? 'selected' : '' ?>>5th Degree- Senior Instructor</option>
                        <option value="6th Degree- Senior Instructor" <?= $filters['rank'] === '6th Degree- Senior Instructor' ? 'selected' : '' ?>>6th Degree- Senior Instructor</option>
                        <option value="7th Degree- Junior Master" <?= $filters['rank'] === '7th Degree- Junior Master' ? 'selected' : '' ?>>7th Degree- Junior Master</option>
                        <option value="8th Degree- Master" <?= $filters['rank'] === '8th Degree- Master' ? 'selected' : '' ?>>8th Degree- Master</option>
                        <option value="9th Degree- Senior Master" <?= $filters['rank'] === '9th Degree- Senior Master' ? 'selected' : '' ?>>9th Degree- Senior Master</option>
                        <option value="VII. Red Belt" <?= $filters['rank'] === 'VII. Red Belt' ? 'selected' : '' ?>>VII. Red Belt</option>
                        <option value="10th Degree- Grandmaster" <?= $filters['rank'] === '10th Degree- Grandmaster' ? 'selected' : '' ?>>10th Degree- Grandmaster</option>
                        <option value="11th Degree- Senior Grandmaster" <?= $filters['rank'] === '11th Degree- Senior Grandmaster' ? 'selected' : '' ?>>11th Degree- Senior Grandmaster</option>
                        <option value="12th Degree- Superior Grandmaster" <?= $filters['rank'] === '12th Degree- Superior Grandmaster' ? 'selected' : '' ?>>12th Degree- Superior Grandmaster</option>
                    </select>
                    <select name="member_type" id="memberTypeFilter" class="card-bg border-none rounded-full px-4 py-3 text-xs sm:text-sm outline-none text-center" onchange="this.form.submit()">
                        <option value="">All Positions</option>
                        <option value="Head Coach" <?= $filters['member_type'] === 'Head Coach' ? 'selected' : '' ?>>Head Coach</option>
                        <option value="Coach" <?= $filters['member_type'] === 'Coach' ? 'selected' : '' ?>>Coach</option>
                        <option value="Player" <?= $filters['member_type'] === 'Player' ? 'selected' : '' ?>>Player</option>
                    </select>
                    <select name="player_category" id="playerCategoryFilter" class="card-bg border-none rounded-full px-4 py-3 text-xs sm:text-sm outline-none text-center" onchange="this.form.submit()">
                        <option value="">All Programs</option>
                        <option value="Public" <?= $filters['player_category'] === 'Public' ? 'selected' : '' ?>>Public</option>
                        <option value="Private" <?= $filters['player_category'] === 'Private' ? 'selected' : '' ?>>Private</option>
                        <option value="Mixed" <?= $filters['player_category'] === 'Mixed' ? 'selected' : '' ?>>Mixed</option>
                    </select>
                </div>

                <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 sm:gap-4">
                    <div class="relative">
                        <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-gray-500 text-xs sm:text-sm"></i>
                        <input type="search" name="search" id="searchInput" placeholder="Search name, school, contact, email..." class="card-bg rounded-full pl-10 pr-4 py-3 text-xs sm:text-sm outline-none w-full sm:w-72" value="<?= htmlspecialchars($filters['search']) ?>">
                    </div>
                    <button type="submit" class="px-5 sm:px-6 py-4 rounded-full font-bold text-xs sm:text-sm whitespace-nowrap transition bg-accent-purple hover:opacity-90 cursor-pointer">
                        <i class="fas fa-search mr-1"></i> Search
                    </button>
                    <?php if ($hasActiveFilters): ?>
                    <a href="members_management.php" class="px-5 sm:px-6 py-4 rounded-full font-bold text-xs sm:text-sm whitespace-nowrap transition bg-white/5 hover:bg-white/10 text-gray-300 text-center">
                        <i class="fas fa-redo mr-1"></i> Reset
                    </a>
                    <?php endif; ?>
                </div>
            </form>

            <div class="flex flex-col sm:flex-row items-stretch sm:items-center justify-end gap-2 sm:gap-4 mb-6">
                    <?php if ($isSuperAdmin): ?>
                    <a href="#pendingRequestsSection" class="px-5 sm:px-6 py-4 rounded-full font-bold text-xs sm:text-sm whitespace-nowrap transition bg-white/5 hover:bg-white/10 text-gray-300 text-center">
                        Pending Approvals <span class="ml-2 inline-flex items-center justify-center min-w-[24px] px-2 py-1 rounded-full bg-yellow-500/10 text-yellow-300 text-[10px]"><?= (int)$pendingApprovalCount ?></span>
                    </a>
                    <?php endif; ?>
            </div>

            <div class="card-bg rounded-2xl sm:rounded-3xl overflow-hidden">
                <div class="block lg:hidden">
                    <div class="p-2 space-y-2" id="mobileMembersWrap">
                        <?php if (empty($paginatedMembers)): ?>
                            <div class="p-6 text-center text-gray-500">No members found.</div>
                        <?php else: ?>
                            <?php foreach ($paginatedMembers as $member): ?>
                                <div class="bg-white/[0.02] rounded-xl p-4 border border-gray-800/50">
                                    <div class="flex justify-between items-start mb-3 gap-3">
                                        <div>
                                            <div class="text-xs text-gray-500 mb-1">ID: <?= $member['member_id'] ?></div>
                                            <div class="font-bold text-sm"><?= htmlspecialchars($member['full_name']) ?></div>
                                            <div class="text-[10px] text-gray-400 mt-1">Age: <?= $member['age'] ?? '—' ?> • <?= htmlspecialchars($member['rank']) ?></div>
                                        </div>
                                        <span class="text-[9px] font-bold px-2 py-1 rounded-full <?= $member['status'] === 'Active' ? 'text-green-400 bg-green-400/10' : ($member['status'] === 'On Leave' ? 'text-orange-400 bg-orange-400/10' : 'text-red-400 bg-red-400/10') ?>">
                                            <?= htmlspecialchars($member['status']) ?>
                                        </span>
                                    </div>
                                    <div class="flex flex-wrap gap-2 mb-3">
                                        <span class="px-2 py-1 rounded-full text-[9px] font-bold bg-blue-500/10 text-blue-400"><?= htmlspecialchars($member['member_type'] ?? 'Player') ?></span>
                                        <span class="px-2 py-1 rounded-full text-[9px] font-bold <?= ($member['player_category'] ?? 'Public') === 'Private' ? 'bg-yellow-500/10 text-yellow-300' : (($member['player_category'] ?? 'Public') === 'Mixed' ? 'bg-pink-500/10 text-pink-300' : 'bg-green-500/10 text-green-300') ?>">
                                            <?= htmlspecialchars($member['player_category'] ?? 'Public') ?>
                                        </span>
                                    </div>
                                    <div class="text-[10px] text-gray-400 space-y-1 mb-3">
                                        <div><i class="fas fa-phone w-4"></i> <?= htmlspecialchars($member['contact_number'] ?? '—') ?></div>
                                        <div><i class="fas fa-school w-4"></i> <?= htmlspecialchars($member['affiliated_school'] ?? '—') ?></div>
                                        <div><i class="fas fa-map-marker-alt w-4"></i> <?= htmlspecialchars($member['address'] ?? '—') ?></div>
                                    </div>
                                    <div class="flex gap-2">
                                        <a href="member_profile.php?id=<?= $member['member_id'] ?>" class="flex-1 bg-white/5 hover:bg-white/10 text-center py-2 rounded-lg text-xs font-bold transition"><i class="fa-solid fa-eye mr-1"></i> View</a>
                                        <button onclick="openEditMemberModal({id: <?= $member['member_id'] ?>, name: '<?= htmlspecialchars($member['full_name'], ENT_QUOTES) ?>', dob: '<?= htmlspecialchars($member['dob'] ?? '', ENT_QUOTES) ?>', email: '<?= htmlspecialchars($member['email'] ?? '', ENT_QUOTES) ?>', rank: '<?= htmlspecialchars($member['rank'] ?? 'None', ENT_QUOTES) ?>', address: '<?= htmlspecialchars($member['address'] ?? '', ENT_QUOTES) ?>', contact: '<?= htmlspecialchars($member['contact_number'] ?? '', ENT_QUOTES) ?>', height: '<?= htmlspecialchars($member['height'] ?? '', ENT_QUOTES) ?>', weight: '<?= htmlspecialchars($member['weight'] ?? '', ENT_QUOTES) ?>', member_type: '<?= htmlspecialchars($member['member_type'] ?? 'Player', ENT_QUOTES) ?>', player_category: '<?= htmlspecialchars($member['player_category'] ?? 'Public', ENT_QUOTES) ?>', training_preference: '<?= htmlspecialchars($member['training_preference'] ?? 'Group', ENT_QUOTES) ?>', affiliated_school: '<?= htmlspecialchars($member['affiliated_school'] ?? '', ENT_QUOTES) ?>', guardian_name: '<?= htmlspecialchars($member['guardian_name'] ?? '', ENT_QUOTES) ?>', guardian_contact: '<?= htmlspecialchars($member['guardian_contact'] ?? '', ENT_QUOTES) ?>', payment_notes: '<?= htmlspecialchars($member['payment_notes'] ?? '', ENT_QUOTES) ?>', status: '<?= htmlspecialchars($member['status'] ?? 'Active', ENT_QUOTES) ?>', current_profile_img: '<?= htmlspecialchars($member['profile_img'] ?? '', ENT_QUOTES) ?>'})"
                                                class="flex-1 bg-white/5 hover:bg-white/10 text-center py-2 rounded-lg text-xs font-bold transition <?= $canModifyMembers ? 'text-blue-400 hover:text-white cursor-pointer' : 'text-gray-700 opacity-50 cursor-not-allowed' ?>" <?= !$canModifyMembers ? 'disabled' : '' ?>><i class="fa-solid fa-edit mr-1"></i> Edit</button>
                                        <button onclick="openVoidModal('Member', <?= $member['member_id'] ?>, '<?= htmlspecialchars($member['full_name'], ENT_QUOTES) ?>')" class="flex-1 text-center py-2 rounded-lg text-xs font-bold transition <?= canAccess('members', 'delete') ? 'bg-white/5 hover:bg-orange-500/20 cursor-pointer' : 'bg-gray-700 opacity-50 cursor-not-allowed' ?>" <?= !canAccess('members', 'delete') ? 'disabled' : '' ?>><i class="fas fa-ban mr-1"></i> Void</button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="hidden lg:block overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead class="text-gray-400 text-xs uppercase tracking-widest border-b border-gray-700">
                            <tr>
                                <th class="p-4 lg:p-6">ID</th>
                                <th class="p-4 lg:p-6">Name</th>
                                <th class="p-4 lg:p-6">Age</th>
                                <th class="p-4 lg:p-6">Rank</th>
                                <th class="p-4 lg:p-6">Role</th>
                                <th class="p-4 lg:p-6">Program</th>
                                <th class="p-4 lg:p-6">School / Club</th>
                                <th class="p-4 lg:p-6">Contact</th>
                                <th class="p-4 lg:p-6">Status</th>
                                <th class="p-4 lg:p-6 text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="text-sm" id="desktopMembersBody">
                            <?php if (empty($paginatedMembers)): ?>
                                <tr><td colspan="10" class="p-6 text-center text-gray-500">No members found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($paginatedMembers as $member): ?>
                                    <tr class="border-b border-gray-800 hover:bg-white/5 transition">
                                        <td class="p-4 lg:p-6"><?= $member['member_id'] ?></td>
                                        <td class="p-4 lg:p-6"><?= htmlspecialchars($member['full_name']) ?></td>
                                        <td class="p-4 lg:p-6"><?= $member['age'] ?? '—' ?></td>
                                        <td class="p-4 lg:p-6"><?= htmlspecialchars($member['rank']) ?></td>
                                        <td class="p-4 lg:p-6"><span class="px-2 py-1 rounded-full text-xs font-semibold bg-blue-500/10 text-blue-400"><?= htmlspecialchars($member['member_type'] ?? 'Player') ?></span></td>
                                        <td class="p-4 lg:p-6"><span class="px-2 py-1 rounded-full text-xs font-semibold <?= ($member['player_category'] ?? 'Public') === 'Private' ? 'bg-yellow-500/10 text-yellow-300' : (($member['player_category'] ?? 'Public') === 'Mixed' ? 'bg-pink-500/10 text-pink-300' : 'bg-green-500/10 text-green-300') ?>"><?= htmlspecialchars($member['player_category'] ?? 'Public') ?></span></td>
                                        <td class="p-4 lg:p-6"><?= htmlspecialchars($member['affiliated_school'] ?? '—') ?></td>
                                        <td class="p-4 lg:p-6"><?= htmlspecialchars($member['contact_number'] ?? '—') ?></td>
                                        <td class="p-4 lg:p-6"><span class="px-3 py-1 rounded-full text-xs <?= $member['status'] === 'Active' ? 'text-green-400 bg-green-400/10' : ($member['status'] === 'On Leave' ? 'text-orange-400 bg-orange-400/10' : 'text-red-400 bg-red-400/10') ?>"><?= htmlspecialchars($member['status']) ?></span></td>
                                        <td class="p-4 lg:p-6 text-center space-x-3">
                                            <a href="member_profile.php?id=<?= $member['member_id'] ?>" class="text-gray-500 hover:text-green-500 transition-colors"><i class="fa-solid fa-eye"></i></a>
                                            <button onclick="openEditMemberModal({id: <?= $member['member_id'] ?>, name: '<?= htmlspecialchars($member['full_name'], ENT_QUOTES) ?>', dob: '<?= htmlspecialchars($member['dob'] ?? '', ENT_QUOTES) ?>', email: '<?= htmlspecialchars($member['email'] ?? '', ENT_QUOTES) ?>', rank: '<?= htmlspecialchars($member['rank'] ?? 'None', ENT_QUOTES) ?>', address: '<?= htmlspecialchars($member['address'] ?? '', ENT_QUOTES) ?>', contact: '<?= htmlspecialchars($member['contact_number'] ?? '', ENT_QUOTES) ?>', height: '<?= htmlspecialchars($member['height'] ?? '', ENT_QUOTES) ?>', weight: '<?= htmlspecialchars($member['weight'] ?? '', ENT_QUOTES) ?>', member_type: '<?= htmlspecialchars($member['member_type'] ?? 'Player', ENT_QUOTES) ?>', player_category: '<?= htmlspecialchars($member['player_category'] ?? 'Public', ENT_QUOTES) ?>', training_preference: '<?= htmlspecialchars($member['training_preference'] ?? 'Group', ENT_QUOTES) ?>', affiliated_school: '<?= htmlspecialchars($member['affiliated_school'] ?? '', ENT_QUOTES) ?>', guardian_name: '<?= htmlspecialchars($member['guardian_name'] ?? '', ENT_QUOTES) ?>', guardian_contact: '<?= htmlspecialchars($member['guardian_contact'] ?? '', ENT_QUOTES) ?>', payment_notes: '<?= htmlspecialchars($member['payment_notes'] ?? '', ENT_QUOTES) ?>', status: '<?= htmlspecialchars($member['status'] ?? 'Active', ENT_QUOTES) ?>', current_profile_img: '<?= htmlspecialchars($member['profile_img'] ?? '', ENT_QUOTES) ?>'})"
                                                class="transition <?= $canModifyMembers ? 'text-blue-400 hover:text-white cursor-pointer' : 'text-gray-700 opacity-50 cursor-not-allowed' ?>" <?= !$canModifyMembers ? 'disabled' : '' ?>><i class="fas fa-edit"></i></button>
                                            <button onclick="openVoidModal('Member', <?= $member['member_id'] ?>, '<?= htmlspecialchars($member['full_name'], ENT_QUOTES) ?>')" class="<?= canAccess('members', 'delete') ? 'text-gray-600 hover:text-orange-500 transition cursor-pointer' : 'text-gray-700 opacity-50 cursor-not-allowed' ?>" <?= !canAccess('members', 'delete') ? 'disabled' : '' ?>><i class="fas fa-ban"></i></button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalPages > 1): ?>
                <div class="px-4 py-4 border-t border-gray-800 flex justify-between items-center flex-wrap gap-3">
                    <div class="text-[10px] text-gray-500 uppercase" data-pagination-info>
                        Showing <?= min(($page - 1) * $perPage + 1, $totalMembers) ?> to <?= min($page * $perPage, $totalMembers) ?> of <?= $totalMembers ?> members
                    </div>
                    <div class="flex gap-2">
                        <?php if ($page > 1): ?>
                        <a href="?<?= buildQuery($filters, 1) ?>" class="bg-white/5 hover:bg-white/10 px-3 py-2 rounded-lg text-[10px] font-bold transition"><i class="fas fa-chevron-left mr-1"></i>First</a>
                        <a href="?<?= buildQuery($filters, $page - 1) ?>" class="bg-white/5 hover:bg-white/10 px-3 py-2 rounded-lg text-[10px] font-bold transition"><i class="fas fa-chevron-left mr-1"></i>Prev</a>
                        <?php endif; ?>
                        <span class="px-3 py-2 text-[10px] font-bold text-gray-400">Page <?= $page ?> of <?= $totalPages ?></span>
                        <?php if ($page < $totalPages): ?>
                        <a href="?<?= buildQuery($filters, $page + 1) ?>" class="bg-white/5 hover:bg-white/10 px-3 py-2 rounded-lg text-[10px] font-bold transition">Next<i class="fas fa-chevron-right ml-1"></i></a>
                        <a href="?<?= buildQuery($filters, $totalPages) ?>" class="bg-white/5 hover:bg-white/10 px-3 py-2 rounded-lg text-[10px] font-bold transition">Last<i class="fas fa-chevron-right ml-1"></i></a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($isSuperAdmin): ?>
            <section id="pendingRequestsSection" class="mt-6 card-bg rounded-2xl sm:rounded-3xl overflow-hidden">
                <div class="p-5 sm:p-6 border-b border-gray-800 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 bg-white/5">
                    <div>
                        <h2 class="font-bold text-xs sm:text-sm tracking-widest uppercase flex items-center gap-2">
                            <i class="fas fa-user-clock text-yellow-300"></i> Pending Registration Requests
                        </h2>
                        <p class="text-[10px] uppercase tracking-[0.16em] text-gray-500 mt-2">Approve requests to turn applicants into members.</p>
                    </div>
                    <div class="text-[11px] font-black uppercase tracking-[0.16em] text-yellow-300">
                        <?= (int)$pendingApprovalCount ?> pending
                    </div>
                </div>
                <div class="p-4 sm:p-6">
                    <?php if (empty($pendingApprovals)): ?>
                        <div class="text-center py-10 text-gray-500">
                            <i class="fas fa-check-circle text-2xl mb-3 opacity-40"></i>
                            <p>No pending registration requests right now.</p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-3">
                            <?php foreach ($pendingApprovals as $request): ?>
                                <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4">
                                    <div class="flex flex-col lg:flex-row lg:items-start justify-between gap-4">
                                        <div class="space-y-2">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <h3 class="font-black text-sm sm:text-base"><?= htmlspecialchars($request['full_name']) ?></h3>
                                                <span class="px-2 py-1 rounded-full text-[10px] font-bold <?= ($request['player_category'] ?? 'Public') === 'Private' ? 'bg-yellow-500/10 text-yellow-300' : (($request['player_category'] ?? 'Public') === 'Mixed' ? 'bg-pink-500/10 text-pink-300' : 'bg-green-500/10 text-green-300') ?>"><?= htmlspecialchars($request['player_category']) ?></span>
                                                <span class="px-2 py-1 rounded-full text-[10px] font-bold bg-white/5 text-gray-300"><?= htmlspecialchars($request['training_preference']) ?></span>
                                            </div>
                                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs text-gray-400">
                                                <div><i class="fas fa-envelope w-4 text-gray-500"></i> <?= htmlspecialchars($request['email'] ?: 'No email') ?></div>
                                                <div><i class="fas fa-phone w-4 text-gray-500"></i> <?= htmlspecialchars($request['contact_number'] ?: 'No contact') ?></div>
                                                <div><i class="fas fa-school w-4 text-gray-500"></i> <?= htmlspecialchars($request['affiliated_school'] ?: 'No school / club') ?></div>
                                                <div><i class="fas fa-calendar w-4 text-gray-500"></i> Submitted <?= htmlspecialchars(date('M d, Y', strtotime($request['submitted_at']))) ?></div>
                                            </div>
                                            <?php if (!empty($request['payment_notes'])): ?>
                                            <p class="text-xs text-gray-500 leading-relaxed"><?= htmlspecialchars($request['payment_notes']) ?></p>
                                            <?php endif; ?>
                                            <div class="mt-2 pt-2 border-t border-white/5 grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs">
                                                <div><span class="text-gray-500">Requested Rank:</span> <span class="text-yellow-300"><?= htmlspecialchars($request['requested_rank'] ?? 'N/A') ?></span></div>
                                                <?php if (!empty($request['rank_verification_status'])): ?>
                                                <div><span class="text-gray-500">Rank Verification:</span> <span class="text-blue-300"><?= htmlspecialchars($request['rank_verification_status']) ?></span></div>
                                                <?php endif; ?>
                                                <?php if (!empty($request['prev_organization'])): ?>
                                                <div><span class="text-gray-500">Previous Org:</span> <?= htmlspecialchars($request['prev_organization']) ?></div>
                                                <?php endif; ?>
                                                <?php if (!empty($request['prev_instructor'])): ?>
                                                <div><span class="text-gray-500">Previous Instructor:</span> <?= htmlspecialchars($request['prev_instructor']) ?></div>
                                                <?php endif; ?>
                                                <?php if (!empty($request['rank_awarded_year'])): ?>
                                                <div><span class="text-gray-500">Year Awarded:</span> <?= htmlspecialchars($request['rank_awarded_year']) ?></div>
                                                <?php endif; ?>
                                            </div>
                                            <?php
                                            $evidencePath = trim((string)($request['rank_evidence_path'] ?? ''));
                                            $requestedRank = (string)($request['requested_rank'] ?? 'None');
                                            $showReview = ($evidencePath !== '') || ($requestedRank !== 'None')
                                                || !empty($request['prev_organization']) || !empty($request['prev_instructor']);
                                            ?>
                                            <?php if ($showReview): ?>
                                            <div class="mt-3 rounded-xl border border-purple-500/30 bg-purple-500/10 p-3 sm:p-4">
                                                <div class="flex items-center gap-2 mb-3">
                                                    <i class="fas fa-file-shield text-purple-300"></i>
                                                    <span class="text-[10px] font-black uppercase tracking-[0.18em] text-purple-200">Review Documents</span>
                                                </div>
                                                <?php if ($evidencePath !== ''): ?>
                                                <a href="../actions/view_rank_evidence.php?id=<?= (int)$request['request_id'] ?>" target="_blank" rel="noopener"
                                                   class="inline-flex items-center justify-center gap-2 w-full sm:w-auto px-5 py-3 rounded-xl bg-purple-500 hover:bg-purple-400 text-white text-[11px] font-black uppercase tracking-[0.16em] transition shadow-lg shadow-purple-900/30">
                                                    <i class="fas fa-arrow-up-right-from-square"></i> View Rank Evidence
                                                </a>
                                                <p class="text-[10px] text-gray-400 mt-2">Opens the certificate the applicant uploaded (PDF / JPG / PNG).</p>
                                                <?php else: ?>
                                                <p class="text-[11px] text-yellow-300"><i class="fas fa-triangle-exclamation mr-1"></i> No rank evidence file was attached to this request.</p>
                                                <?php endif; ?>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="flex flex-col sm:flex-row gap-2 lg:min-w-[250px]">
                                            <button onclick="reviewRegistrationRequest(<?= (int)$request['request_id'] ?>, 'approve')" class="flex-1 px-4 py-3 rounded-xl bg-emerald-500/10 hover:bg-emerald-500 hover:text-white text-emerald-300 transition text-[11px] font-black uppercase tracking-[0.16em]">Approve</button>
                                            <button onclick="reviewRegistrationRequest(<?= (int)$request['request_id'] ?>, 'reject')" class="flex-1 px-4 py-3 rounded-xl bg-red-500/10 hover:bg-red-500 hover:text-white text-red-300 transition text-[11px] font-black uppercase tracking-[0.16em]">Reject</button>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
            <?php endif; ?>

        </main>
    </div>

    <script>
    window.arnisMemberFieldPermissions = <?= json_encode($memberFieldPermissions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    </script>

    <?php include_once "modals/void_confirmation.php"; ?>
    <?php include_once "modals/user_menu.php"; ?>
    <?php include_once "modals/edit_member.php"; ?>
    <?php include_once "modals/edit_profile_user.php"; ?>
</body>
<script>
window.addEventListener("load", () => {
    document.getElementById("page").classList.remove("opacity-0", "translate-x-8");
});

/* Search runs ONLY on Enter / Search button / filter change, via plain
 * GET form submit (full page reload, server-rendered results). There is
 * intentionally NO live search while typing and NO AJAX path, so a broken
 * filter endpoint can never blank the list with "No members found". */
function applyFilters() {
    document.getElementById('membersFilterForm').submit();
}

function reviewRegistrationRequest(requestId, decision) {
    const isApprove = decision === 'approve';

    if (!isApprove) {
        const proceed = confirm('Reject this registration request?');
        if (!proceed) return;
    }

    const body = new URLSearchParams({
        request_id: requestId,
        decision: decision,
        review_notes: '',
        csrf_token: <?= json_encode(arnisCsrfToken(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
    });

    fetch('../actions/process_registration_review.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
        body: body.toString()
    })
    .then(response => response.json())
    .then(data => {
        alert(data.message || (data.success ? 'Request reviewed successfully.' : 'Failed to review request.'));
        if (data.success) window.location.reload();
    })
    .catch(() => alert('Failed to review registration request.'));
}

</script>
</html>
