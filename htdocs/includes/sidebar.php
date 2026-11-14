<?php
/**
 * Sidebar navigation.
 *
 * Visibility is derived from config/permissions.php through includes/authorize.php
 * so the menu can never drift away from what the target page actually allows.
 * A null session role fails CLOSED (no items) instead of defaulting to a role.
 */
require_once __DIR__ . '/../includes/authorize.php';

$current_page = basename((string)($_SERVER['PHP_SELF'] ?? ''));

/*
 * No role fallback. The previous `$_SESSION['role'] ?? 'Coach/Trainer'` handed
 * the full Coach/Trainer navigation to any session whose role key was missing -
 * a missing-guard bug anywhere would become a UI leak instead of a blank page.
 */
$userRole = currentUserRole();
if ($userRole === null) {
    $userRole = '';
}

$userRoleLabel = '';
$roleConfig    = $permissions['__roles'] ?? null;
$rolesFile     = require __DIR__ . '/../config/roles.php';
if (isset($rolesFile[$userRole]['label'])) {
    $userRoleLabel = (string)$rolesFile[$userRole]['label'];
}

/*
 * Menu definition.
 *   module === null  -> visible to every authenticated role (Dashboard)
 *   otherwise -> visible when hasPermission($module, $action)
 *   highlights -> extra pages that should light up this menu entry
 */
$menu_items = [
    [
        'label'      => 'Dashboard',
        'file'       => 'dashboard.php',
        'icon'       => 'fas fa-dashboard',
        'module'     => null,
        'action'     => 'read',
        'highlights' => [],
    ],
    [
        'label'      => 'Members',
        'file'       => 'members_management.php',
        'icon'       => 'fas fa-users',
        'module'     => 'members',
        'action'     => 'read',
        'highlights' => [],
    ],
    [
        'label'      => 'Trainings',
        'file'       => 'trainings.php',
        'icon'       => 'fas fa-book-open',
        'module'     => 'trainings',
        'action'     => 'read',
        'highlights' => ['attendance_session.php'],
    ],
    [
        'label'      => 'Private Training',
        'file'       => 'private_trainings.php',
        'icon'       => 'fas fa-user-ninja',
        'module'     => null,
        'action'     => 'read',
        'roles'      => ['SuperAdmin', 'Head Coach', 'Coach/Trainer', 'Officer', 'Member'],
        'highlights' => [],
    ],
    [
        'label'      => 'Attendance Report',
        'file'       => 'attendance_report.php',
        'icon'       => 'fas fa-chart-line',
        'module'     => null,
        'action'     => 'read',
        'roles'      => ['SuperAdmin', 'Head Coach', 'Coach/Trainer', 'Member'],
        'highlights' => [],
    ],
    [
        'label'      => 'Events',
        'file'       => 'events.php',
        'icon'       => 'far fa-calendar-alt',
        'module'     => 'events',
        'action'     => 'read',
        'highlights' => ['event_detail.php'],
    ],
    [
        'label'      => 'Funds',
        'file'       => 'funds.php',
        'icon'       => 'fas fa-wallet',
        'module'     => 'funds',
        'action'     => 'read',
        'highlights' => [],
    ],
    [
        'label'      => 'Users',
        'file'       => 'users.php',
        'icon'       => 'fas fa-user',
        'module'     => 'users',
        'action'     => 'read',
        'highlights' => [],
    ],
    [
        'label'      => 'Announcements',
        'file'       => 'announcements.php',
        'icon'       => 'fas fa-bullhorn',
        'module'     => 'announcements',
        'action'     => 'read',
        'highlights' => [],
    ],
    [
        'label'      => 'Reports',
        'file'       => 'reports.php',
        'icon'       => 'fas fa-chart-bar',
        'module'     => 'reports',
        'action'     => 'read',
        'highlights' => [],
    ],
    [
        'label'      => 'Activity Log',
        'file'       => 'activity_log.php',
        'icon'       => 'fas fa-clipboard-list',
        'module'     => null,
        'action'     => 'read',
        'roles'      => ['SuperAdmin'],
        'highlights' => [],
    ],
    [
        'label'      => 'Settings',
        'file'       => 'settings.php',
        'icon'       => 'fas fa-gear',
        'module'     => null,
        'action'     => 'read',
        'roles'      => ['SuperAdmin'],
        'highlights' => [],
    ],
];

$visible_items = [];
foreach ($menu_items as $item) {
    if ($userRole === '') {
        break; // fail closed
    }

    if (isset($item['roles']) && (!is_array($item['roles']) || !in_array($userRole, $item['roles'], true))) {
        continue;
    }

    if ($item['module'] === null || hasPermission($item['module'], $item['action'])) {
        $visible_items[] = $item;
    }
}

/*
 * Members have no other route to their own member profile, which is where face
 * enrollment lives (the "View/Edit Profile" user-menu item is the account
 * modal). Surface it right under Dashboard. Prefer the linked member id.
 * Don't add My Profile to sidebar for Applicant.
 */
$selfMemberId = (isset($_SESSION['member_id']) && $_SESSION['member_id'] !== null && (int)$_SESSION['member_id'] > 0)
    ? (int)$_SESSION['member_id']
    : 0;
$sidebarRole = currentUserRole() ?? '';

if ($sidebarRole !== 'Applicant') {
    if ($selfMemberId > 0 || $sidebarRole === 'Member') {
        array_splice($visible_items, 1, 0, [[
            'label'      => 'My Profile',
            'file'       => $selfMemberId > 0 ? 'member_profile.php?id=' . $selfMemberId : 'member_profile.php',
            'icon'       => 'fas fa-id-badge',
            'highlights' => ['member_profile.php'],
        ]]);
    }
}

// Hide Members and Users from Applicant - keep only Dashboard for Applicant
$visible_items = array_values(array_filter($visible_items, function($item) use ($sidebarRole) {
    if ($sidebarRole === 'Applicant') {
        return $item['module'] === null;
    }
    return true;
}));

/*
 * member_profile.php is reachable from BOTH "Members" (someone else's
 * profile) and "My Profile" (your own). Previously both entries listed it in
 * `highlights`, so both lit up at once. Highlight exactly one based on whose
 * profile is open.
 */
$profileId = (isset($_GET['id']) && is_numeric($_GET['id'])) ? (int)$_GET['id'] : 0;
$isSelfProfilePage = ($current_page === 'member_profile.php'
    && $selfMemberId > 0
    && $profileId === $selfMemberId);

foreach ($visible_items as &$navItem) {
    if (($navItem['label'] ?? '') === 'My Profile') {
        $navItem['highlights'] = $isSelfProfilePage ? ['member_profile.php'] : [];
    } elseif (($navItem['label'] ?? '') === 'Members') {
        $navItem['highlights'] = ($current_page === 'member_profile.php' && !$isSelfProfilePage)
            ? ['member_profile.php']
            : [];
    }
}
unset($navItem);

?>

<aside class="w-64 bg-sidebar-dark pt-4 px-3 pb-0 flex flex-col h-screen sidebar" id="sidebar">

    <div class="flex items-center pb-3 mb-3">
        <img src="../arnis-logo.png" alt="Arnis Connect" class="w-50 h-auto">
    </div>

    <div class="bg-sidebar-cont rounded-t-3xl rounded-b-none flex flex-col flex-grow relative overflow-hidden">

        <nav class="p-2 flex-grow">
            <ul>
                <?php if (empty($visible_items)): ?>
                    <li class="px-3 py-4 text-[10px] text-gray-500 leading-relaxed">
                        Navigation unavailable. Please sign in again.
                    </li>
                <?php endif; ?>

                <?php foreach ($visible_items as $item):
                    $file  = (string)$item['file'];
                    $label = (string)$item['label'];
                    $icon  = (string)$item['icon'];

                    $highlightFiles = array_merge([$file], (array)$item['highlights']);
                    $is_active = in_array($current_page, $highlightFiles, true);

                    $active_classes = $is_active
                        ? 'text-active-link bg-active-link translate-x-2'
                        : 'text-light-gray';
                ?>
                    <li class="mb-3 relative">
                        <a href="<?= htmlspecialchars($file, ENT_QUOTES, 'UTF-8') ?>"
                           title="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>"
                           <?= $is_active ? 'aria-current="page"' : '' ?>
                           class="nav-link flex items-center p-3 rounded-lg hover:bg-gray-700/50 transition duration-200 <?= htmlspecialchars($active_classes, ENT_QUOTES, 'UTF-8') ?>">
                            <i class="<?= htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?> mr-4 w-5 text-center"></i>
                            <span class="sidebar-text"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </nav>

        <?php if ($userRoleLabel !== ''): ?>
            <div class="px-3 pb-4 sidebar-text">
                <div class="text-[9px] uppercase tracking-widest text-gray-500">Signed in as</div>
                <div class="text-[11px] font-bold text-gray-300 truncate" title="<?= htmlspecialchars($userRoleLabel, ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars($userRoleLabel, ENT_QUOTES, 'UTF-8') ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <button type="button" id="toggle-btn" class="toggle-btn" aria-label="Collapse sidebar">
        <i class="fas fa-chevron-left"></i>
    </button>
</aside>

<script>
(function () {
    'use strict';

    const STORAGE_KEY = 'arnis_sidebar_collapsed';

    const sidebar = document.getElementById('sidebar');
    const toggleBtn = document.getElementById('toggle-btn');

    if (!sidebar || !toggleBtn) {
        return;
    }

    const toggleIcon = toggleBtn.querySelector('i');

    const applyState = function (collapsed) {
        sidebar.classList.toggle('collapsed', collapsed);
        toggleBtn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');

        if (!toggleIcon) {
            return;
        }

        toggleIcon.classList.toggle('fa-chevron-left', !collapsed);
        toggleIcon.classList.toggle('fa-chevron-right', collapsed);
    };

    let stored = null;
    try {
        stored = window.localStorage.getItem(STORAGE_KEY);
    } catch (e) {
        stored = null;
    }
    applyState(stored === '1');

    toggleBtn.addEventListener('click', function () {
        const collapsed = sidebar.classList.toggle('collapsed');
        toggleBtn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');

        if (toggleIcon) {
            toggleIcon.classList.toggle('fa-chevron-left', !collapsed);
            toggleIcon.classList.toggle('fa-chevron-right', collapsed);
        }

        try {
            window.localStorage.setItem(STORAGE_KEY, collapsed ? '1' : '0');
        } catch (e) {
            /* storage unavailable - toggle still works for this page view */
        }
    });
})();
</script>