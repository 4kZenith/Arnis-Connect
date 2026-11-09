<?php
require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/MemberProfile.php';

/*
 * config/permissions.php is the single RBAC source of truth. The inline matrix
 * that used to live here covered only 5 roles, so Member and Applicant were
 * denied outright.
 */
if (!function_exists('canAccess')) {
    function canAccess($module, $action = 'read')
    {
        return hasPermission((string)$module, (string)$action);
    }
}

if (!function_exists('getPermission')) {
    function getPermission($module, $action = 'read')
    {
        return permissionValue((string)$module, (string)$action);
    }
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Block Applicants from accessing member profile
if (currentUserRole() === 'Applicant') {
    http_response_code(403);
    die('Access Denied: Applicants do not have access to member profiles.');
}

/*
 * The member row behind this login. users.member_id is the canonical link
 * (auth.php puts it in the session); accounts created before linking existed
 * have it NULL, so resolveSessionMemberId() falls back to an exact full_name
 * match - the same convention dashboard.php already uses.
 */
try {
    require_once __DIR__ . '/../config/Database.php';
    $db = (new Database())->connect();
} catch (Throwable $e) {
    error_log('member_profile.php bootstrap DB failed: ' . $e->getMessage());
    http_response_code(503);
    die('System temporarily unavailable. Please try again later.');
}

$selfMemberId = function_exists('resolveSessionMemberId') ? resolveSessionMemberId($db) : 0;

/*
 * ?id=<member_id> opens a specific profile. With no id, a player opens their
 * own ("My Profile" sidebar entry / user-menu button); staff must pass an id.
 */
$rawId = $_GET['id'] ?? null;
if (is_string($rawId) && is_numeric($rawId) && (int)$rawId > 0) {
    $memberId = (int)$rawId;
} elseif ($selfMemberId > 0) {
    $memberId = $selfMemberId;
} else {
    header('Location: members_management.php');
    exit;
}

/*
 * IDOR guard.
 *
 * Member/Applicant have members.read = false (no directory). Members still
 * need their own profile, so self-access is authorized by the session's
 * linked member_id rather than by the module grant. Coaches are narrowed to
 * members they actually train ('own_trainees'), resolved per record.
 */
$readScope = getPermission('members', 'read');
$writeScope = getPermission('members', 'write');
$isOwnProfile = $selfMemberId > 0 && $selfMemberId === $memberId;

if ($readScope === false && !$isOwnProfile) {
    http_response_code(403);
    die('Access Denied: You do not have permission to view member profiles.');
}

if ($readScope === 'own' && !$isOwnProfile) {
    http_response_code(403);
    die('Access Denied: You may only view your own member profile.');
}

if ($readScope === 'own_trainees' && !$isOwnProfile
    && !canPerformAction('members', 'read', ['member_id' => $memberId])) {
    http_response_code(403);
    die('Access Denied: You may only view members you train.');
}

/*
 * Editing is separate from viewing:
 *   - Members may edit their own contact/profile fields.
 *   - Coaches may edit their own trainees' profile fields.
 *   - Only SuperAdmin may change rank/position/payment notes directly.
 *   - Status and promotion are staff-only (never self).
 */
$canWriteMember = false;
if ($writeScope === 'own') {
    $canWriteMember = $isOwnProfile;
} elseif ($writeScope === 'own_trainees') {
    $canWriteMember = $isOwnProfile
        || canPerformAction('members', 'write', ['member_id' => $memberId]);
} elseif ($writeScope === true) {
    $canWriteMember = true;
}

$canManageStatus = (!$isOwnProfile && in_array($writeScope, [true, 'own_trainees'], true));
$canManagePrivileged = (!$isOwnProfile && $writeScope === true);
$canPromote = (!$isOwnProfile
    && $canWriteMember
    && in_array($writeScope, [true, 'own_trainees'], true));

/*
 * Field-level permissions for the shared edit modal. Disabled inputs are not
 * submitted; process_edit_member.php falls back to the stored values, so a
 * hidden/disabled field can never reset rank, status, position or notes.
 */
$memberFieldPermissions = [
    'rank' => $canManagePrivileged,
    'status' => $canManageStatus,
    'member_type' => $canManagePrivileged,
    'payment_notes' => $canManagePrivileged,
];

/* Filters that were previously dead <select>/<input> controls with no handler. */
$achievementFilter = (isset($_GET['achievement']) && is_string($_GET['achievement']))
    ? trim($_GET['achievement'])
    : '';
if (!in_array($achievementFilter, MemberProfile::ACHIEVEMENT_TYPES, true)) {
    $achievementFilter = '';
}

$eventMonth = (isset($_GET['event_month']) && is_string($_GET['event_month']))
    ? trim($_GET['event_month'])
    : '';
if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $eventMonth) !== 1) {
    $eventMonth = '';
}

try {
    if (!isset($db) || !($db instanceof PDO)) {
        $db = (new Database())->connect();
    }
    $memberProfileModel = new MemberProfile($db);

    $profileData = $memberProfileModel->getProfileData($memberId, $achievementFilter, $eventMonth);
} catch (Throwable $e) {
    error_log('member_profile.php error: ' . $e->getMessage());
    http_response_code(500);
    die('Error loading member profile. Please contact the administrator.');
}

if ($profileData === null) {
    header('Location: members_management.php');
    exit;
}

$member = $profileData['member'];
$age = $profileData['age'];
$presentCount = (int)$profileData['attendance']['present'];
$lateCount = (int)$profileData['attendance']['late'];
$absentCount = (int)$profileData['attendance']['absent'];
$attendanceTotal = (int)$profileData['attendance']['total'];
$attendanceRate = (float)$profileData['attendance']['rate'];
$achievements = $profileData['achievements'];
$eventParticipations = $profileData['eventParticipations'];

/* Belt progression timeline (rank_history). Soft-fails to [] when the
 * migration has not been run yet, so the profile never breaks. */
$rankHistory = [];
$membersClassReady = false;
try {
    if (is_file(__DIR__ . '/../classes/Members.php')) {
        require_once __DIR__ . '/../classes/Members.php';
        $membersClassReady = class_exists('Members');
    }
    if ($membersClassReady) {
        $rankHistory = (new Members($db))->getRankHistory($memberId);
    }
} catch (Throwable $e) {
    error_log('member_profile rank history skipped: ' . $e->getMessage());
    $rankHistory = [];
}

/* Next-belt progress: sessions attended while holding the current belt.
 * Purely motivational — promotion stays a coach/admin decision.
 * Guarded so a stale classes/Members.php can never fatal the whole page. */
$currentRankName = (string)($member['rank'] ?? 'None');
$nextRankName = ($membersClassReady && method_exists('Members', 'nextRankAfter'))
    ? Members::nextRankAfter($currentRankName)
    : null;
$beltSince = null;
if (!empty($rankHistory) && !empty($rankHistory[0]['promoted_at'])) {
    $beltSince = substr((string)$rankHistory[0]['promoted_at'], 0, 10);
}
$sessionsAtBelt = 0;
$beltTenureDays = null;
try {
    if ($beltSince !== null) {
        $beltStmt = $db->prepare(
            "SELECT COUNT(*) FROM attendance a
             JOIN trainings t ON t.training_id = a.training_id
             WHERE a.member_id = :id AND a.is_voided = 0 AND t.is_voided = 0
               AND a.status = 'Present' AND t.training_date >= :since"
        );
        $beltStmt->execute([':id' => $memberId, ':since' => $beltSince]);
    } else {
        $beltStmt = $db->prepare(
            "SELECT COUNT(*) FROM attendance a
             WHERE a.member_id = :id AND a.is_voided = 0 AND a.status = 'Present'"
        );
        $beltStmt->execute([':id' => $memberId]);
    }
    $sessionsAtBelt = (int)$beltStmt->fetchColumn();
    if ($beltSince !== null) {
        $sinceTs = strtotime($beltSince);
        if ($sinceTs !== false) {
            $beltTenureDays = max(0, (int)floor((time() - $sinceTs) / 86400));
        }
    }
} catch (Throwable $e) {
    error_log('member_profile belt progress skipped: ' . $e->getMessage());
}
$beltGuideline = ($membersClassReady && defined('Members::SESSIONS_PER_BELT_GUIDELINE'))
    ? (int)Members::SESSIONS_PER_BELT_GUIDELINE
    : 12;
$beltProgressPct = min(100, (int)round($sessionsAtBelt / max(1, $beltGuideline) * 100));

/* Promotion readiness (display-only guide — promotion itself stays a human
 * staff decision per proposal limits). 90-day attendance rate + lifetime
 * achievements + next belt from the shared spine in config/belts.php. */
$readiness = null;
try {
    if (is_file(__DIR__ . '/../config/belts.php')) {
        require_once __DIR__ . '/../config/belts.php';
    }
    if (!function_exists('arnisNextRank') || !function_exists('arnisRankProgress')) {
        throw new RuntimeException('config/belts.php helpers unavailable');
    }
    $stmt = $db->prepare(
        "SELECT COUNT(*) AS total,
                SUM(CASE WHEN a.status = 'Present' THEN 1 ELSE 0 END) AS present
         FROM attendance a
         JOIN trainings t ON t.training_id = a.training_id
         WHERE a.member_id = :id AND a.is_voided = 0 AND t.is_voided = 0
           AND t.training_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)"
    );
    $stmt->execute([':id' => $memberId]);
    $rq = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM achievements WHERE member_id = :id AND is_voided = 0"
    );
    $stmt->execute([':id' => $memberId]);
    $achTotal = (int)$stmt->fetchColumn();
    $rTotal = (int)($rq['total'] ?? 0);
    $rPresent = (int)($rq['present'] ?? 0);
    $rRate = $rTotal > 0 ? (int)round($rPresent / $rTotal * 100) : 0;
    $nextRank = function_exists('arnisNextRank')
        ? arnisNextRank((string)($member['rank'] ?? 'None'))
        : null;
    $readiness = [
        'rate' => $rRate,
        'sessions' => $rTotal,
        'achievements' => $achTotal,
        'next' => $nextRank,
        'ready' => $nextRank !== null && $rRate >= 75 && $rTotal >= 4 && $achTotal >= 1,
    ];
} catch (Throwable $e) {
    error_log('member_profile readiness skipped: ' . $e->getMessage());
    $readiness = null;
}

/** mp* prefix: this page includes modals that define their own helpers. */
function mpE(?string $value, string $fallback = ''): string
{
    return htmlspecialchars(MemberProfile::text($value, $fallback), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function mpDate(?string $value, string $fallback = 'N/A'): string
{
    return htmlspecialchars(MemberProfile::formatDate($value, $fallback), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function mpLongDate(?string $value, string $fallback = 'N/A'): string
{
    return htmlspecialchars(MemberProfile::formatLongDate($value, $fallback), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

const MP_DEFAULT_AVATAR = 'https://i.pinimg.com/736x/b5/21/c6/b521c64d12283825c68c6d228de54310.jpg';

function mpAvatar(?string $url): string
{
    /* ?? only catches null, so an empty string produced <img src="">. */
    $url = trim((string)$url);
    if ($url === '') {
        return MP_DEFAULT_AVATAR;
    }
    return htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Payload for openEditMemberModal().
 *
 * htmlspecialchars(json_encode(...), ENT_QUOTES) is not enough here:
 * json_encode() returns false on malformed UTF-8, which silently collapses the
 * whole argument list. The HEX flags additionally strip every character the
 * HTML parser could act on before the JS sees it.
 */
function mpEditOnclick(array $member, bool $allowed): string
{
    if (!$allowed) {
        return 'return false;';
    }

    $payload = [
        'id' => (int)($member['member_id'] ?? 0),
        'name' => MemberProfile::text(isset($member['full_name']) ? (string)$member['full_name'] : null),
        'dob' => MemberProfile::text(isset($member['dob']) ? (string)$member['dob'] : null),
        'email' => MemberProfile::text(isset($member['email']) ? (string)$member['email'] : null),
        'rank' => MemberProfile::text(isset($member['rank']) ? (string)$member['rank'] : null, 'None'),
        'address' => MemberProfile::text(isset($member['address']) ? (string)$member['address'] : null),
        'contact' => MemberProfile::text(isset($member['contact_number']) ? (string)$member['contact_number'] : null),
        'height' => isset($member['height']) && $member['height'] !== null ? (string)$member['height'] : '',
        'weight' => isset($member['weight']) && $member['weight'] !== null ? (string)$member['weight'] : '',
        'member_type' => MemberProfile::text(isset($member['member_type']) ? (string)$member['member_type'] : null, 'Player'),
        'player_category' => MemberProfile::text(isset($member['player_category']) ? (string)$member['player_category'] : null, 'Public'),
        'training_preference' => MemberProfile::text(isset($member['training_preference']) ? (string)$member['training_preference'] : null, 'Group'),
        'affiliated_school' => MemberProfile::text(isset($member['affiliated_school']) ? (string)$member['affiliated_school'] : null),
        'guardian_name' => MemberProfile::text(isset($member['guardian_name']) ? (string)$member['guardian_name'] : null),
        'guardian_contact' => MemberProfile::text(isset($member['guardian_contact']) ? (string)$member['guardian_contact'] : null),
        'payment_notes' => MemberProfile::text(isset($member['payment_notes']) ? (string)$member['payment_notes'] : null),
        /* status must be sent: the modal defaults a missing value to 'Active',
         * so saving used to silently reactivate Inactive / On Leave members. */
        'status' => MemberProfile::text(isset($member['status']) ? (string)$member['status'] : null, 'Active'),
        'current_profile_img' => trim((string)($member['profile_img'] ?? '')),
    ];

    $json = json_encode(
        $payload,
        JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        return 'return false;';
    }

    return 'openEditMemberModal(' . $json . ')';
}

$mpEditOnclick = mpEditOnclick($member, $canWriteMember);

$memberStatus = MemberProfile::text(isset($member['status']) ? (string)$member['status'] : null, 'Active');
$memberStatusClasses = [
    'Active' => 'bg-green-500/20 text-green-400',
    'Inactive' => 'bg-red-500/20 text-red-400',
    'On Leave' => 'bg-yellow-500/20 text-yellow-400',
];
$statusBadgeClass = $memberStatusClasses[$memberStatus] ?? 'bg-yellow-500/20 text-yellow-400';

$mpFilterLink = static function (array $overrides = []) use ($memberId, $achievementFilter, $eventMonth): string {
    $query = ['id' => $memberId];
    if ($achievementFilter !== '') {
        $query['achievement'] = $achievementFilter;
    }
    if ($eventMonth !== '') {
        $query['event_month'] = $eventMonth;
    }
    foreach ($overrides as $key => $value) {
        if ($value === '' || $value === null) {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }
    return 'member_profile.php?' . http_build_query($query);
};

$achievementLabel = ($achievementFilter !== '') ? $achievementFilter : 'All';
$eventMonthTs = ($eventMonth !== '') ? strtotime($eventMonth . '-01') : false;
$eventMonthLabel = ($eventMonthTs !== false) ? date('F Y', $eventMonthTs) : 'All dates';

$csrfToken = arnisCsrfToken();
?>

<!DOCTYPE html>
<html lang="en" class="text-[13px]">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Member Profile | Arnis Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- face-api.js (vladmandic fork v1.7.12) for client-side face enrollment -->
    <script src="https://cdn.jsdelivr.net/npm/@vladmandic/face-api@1.7.12/dist/face-api.min.js"></script>
    <link rel="icon" type="image/x-icon" href="../arnis-favicon.ico">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,100..900;1,100..900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/sidebar.css">
    <link rel="stylesheet" href="css/style.css">
    <style>
        .image-preview {
            max-width: 150px;
            max-height: 150px;
            border-radius: 10px;
            border: 2px dashed #bf46c7;
            object-fit: cover;
        }
    </style>
</head>
<body class="text-white bg-dark-purple overflow-x-hidden">

<div class="flex">

<?php include_once __DIR__ . '/../includes/sidebar.php'; ?>

<!-- RESPONSIVE: Scale padding -->
<main id="page" class="flex-1 p-4 sm:p-6 lg:p-8 h-screen overflow-y-auto opacity-0 translate-x-8 transition-all duration-500 ease-out">
    <?php include_once __DIR__ . '/../includes/header.php'; ?>

    <!-- RESPONSIVE: Profile Header - Horizontal alignment -->
<div class="flex flex-col md:flex-row md:items-center gap-6 sm:gap-8 lg:gap-10 mb-6 sm:mb-8">

    <!-- Profile Image with edit button and face enrollment -->
    <div class="relative flex-shrink-0 group">
        <img id="memberAvatar" src="<?= mpAvatar(isset($member['profile_img']) ? (string)$member['profile_img'] : null) ?>"
             alt="<?= mpE(isset($member['full_name']) ? (string)$member['full_name'] : null, 'Member') ?>"
             class="w-32 h-32 sm:w-40 sm:h-40 lg:w-48 lg:h-48 rounded-full object-cover border-4 border-gray-700 group-hover:opacity-80 transition">

        <?php if ($canWriteMember): ?>
        <button type="button" onclick="openImageUploadModal()"
                class="absolute bottom-2 right-2 bg-[#bf46c7] text-white rounded-full p-2 opacity-0 group-hover:opacity-100 transition-opacity hover:scale-110"
                title="Change profile picture">
            <i class="fas fa-camera text-sm"></i>
        </button>

        <!-- Face Enrollment Status Badge & Button -->
        <div class="absolute bottom-2 left-2 flex flex-col gap-1">
            <span id="faceEnrollBadge"
                  class="px-2 py-1 rounded-full text-[9px] font-bold uppercase whitespace-nowrap
                         <?= !empty($member['face_descriptor']) ? 'bg-green-500/20 text-green-400' : 'bg-gray-500/20 text-gray-400' ?>">
                <?= !empty($member['face_descriptor']) ? 'Face Enrolled' : 'Not Enrolled' ?>
            </span>
            <button type="button" id="faceEnrollBtn" onclick="openFaceEnrollModal()"
                    class="bg-[#bf46c7] hover:bg-purple-600 text-white rounded-full px-3 py-1.5 text-[10px] font-bold uppercase tracking-wider transition shadow-lg shadow-purple-900/30 flex items-center gap-1.5 cursor-pointer"
                    title="<?= !empty($member['face_descriptor']) ? 'Re-enroll face' : 'Enroll face for smart attendance' ?>">
                <i class="fas fa-user-secret text-xs"></i>
                <?= !empty($member['face_descriptor']) ? 'Re-enroll Face' : 'Enroll Face' ?>
            </button>
        </div>
        <?php elseif (!empty($member['face_descriptor'])): ?>
        <!-- Read-only badge for users without write permission -->
        <span class="absolute bottom-2 left-2 px-2 py-1 rounded-full text-[9px] font-bold uppercase whitespace-nowrap bg-green-500/20 text-green-400">
            Face Enrolled
        </span>
        <?php endif; ?>
    </div>

    <!-- Name, DOB, Rank -->
    <div class="flex-1 flex flex-col sm:flex-row sm:items-center gap-8">

        <div class="flex flex-col gap-1">
    <!-- Name, Age, Edit -->
    <div class="flex items-center gap-2 flex-wrap">
        <h2 class="text-xl sm:text-2xl lg:text-3xl font-bold">
            <?= mpE(isset($member['full_name']) ? (string)$member['full_name'] : null, 'Unknown') ?><?php if ($age !== null): ?>, <?= (int)$age ?><?php endif; ?>
        </h2>

        <!-- Status Badge with Dropdown (staff only; never self-service) -->
        <div class="relative group">
            <button type="button" id="statusBtn" class="px-3 py-1 rounded-full text-xs font-semibold transition <?= $statusBadgeClass ?> <?= $canManageStatus ? 'cursor-pointer hover:opacity-80' : 'cursor-not-allowed opacity-80' ?>"
                onclick="<?= $canManageStatus ? 'toggleStatusDropdown()' : 'return false;' ?>"
                title="<?= $canManageStatus ? 'Click to change status' : 'No permission to change status' ?>">
                <?= htmlspecialchars($memberStatus, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            </button>
            <?php if ($canManageStatus): ?>
            <div id="statusDropdown" class="hidden absolute top-full mt-2 right-0 bg-gray-900 border border-gray-700 rounded-lg shadow-lg z-10 min-w-max">
                <button type="button" onclick="updateMemberStatus('Active')" class="block w-full text-left px-4 py-2 text-sm text-gray-300 hover:bg-green-500/20 hover:text-green-400 transition">Active</button>
                <button type="button" onclick="updateMemberStatus('Inactive')" class="block w-full text-left px-4 py-2 text-sm text-gray-300 hover:bg-red-500/20 hover:text-red-400 transition">Inactive</button>
                <button type="button" onclick="updateMemberStatus('On Leave')" class="block w-full text-left px-4 py-2 text-sm text-gray-300 hover:bg-yellow-500/20 hover:text-yellow-400 transition">On Leave</button>
            </div>
            <?php endif; ?>
        </div>

        <i
            onclick="<?= htmlspecialchars($mpEditOnclick, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
            class="fas fa-edit text-xs sm:text-sm transition <?= $canWriteMember ? 'text-blue-400 hover:text-white cursor-pointer' : 'text-gray-700 opacity-50 cursor-not-allowed' ?>"
            title="<?= $canWriteMember ? 'Edit Member' : 'No permission to edit members' ?>"
        ></i>
    </div>

    <!-- DOB -->
    <p class="text-gray-400 text-sm sm:text-base">
        <?= mpLongDate(isset($member['dob']) ? (string)$member['dob'] : null) ?>
    </p>

    <!-- Rank -->
    <p class="text-gray-400 italic text-xs sm:text-sm lg:text-base flex items-center gap-3 flex-wrap">
        <span><?= mpE(isset($member['rank']) ? (string)$member['rank'] : null, 'None') ?></span>
        <?php if ($canPromote && $nextRankName !== null): ?>
        <button type="button" onclick="openPromoteModal()"
                class="not-italic px-3 py-1.5 rounded-full bg-yellow-500/10 hover:bg-yellow-500 hover:text-black text-yellow-300 text-[10px] font-black uppercase tracking-[0.12em] transition"
                title="Promote exactly one rank to <?= htmlspecialchars($nextRankName, ENT_QUOTES, 'UTF-8') ?>">
            <i class="fas fa-arrow-up-right-dots mr-1"></i> Promote to <?= htmlspecialchars($nextRankName, ENT_QUOTES, 'UTF-8') ?>
        </button>
        <?php endif; ?>
    </p>
</div>


        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-x-8 gap-y-4 text-xs sm:text-sm mt-4 sm:mt-0 w-full">

            <div class="flex flex-col">
                <span class="text-gray-500">Member Role</span>
                <span class="text-blue-400 font-semibold"><?= mpE(isset($member['member_type']) ? (string)$member['member_type'] : null, 'Player') ?></span>
            </div>

            <?php $playerCategory = MemberProfile::text(isset($member['player_category']) ? (string)$member['player_category'] : null, 'Public'); ?>
            <div class="flex flex-col">
                <span class="text-gray-500">Player Category</span>
                <span class="<?= $playerCategory === 'Private' ? 'text-yellow-300' : ($playerCategory === 'Mixed' ? 'text-pink-300' : 'text-green-300') ?> font-semibold"><?= htmlspecialchars($playerCategory, ENT_QUOTES, 'UTF-8') ?></span>
            </div>

            <div class="flex flex-col">
                <span class="text-gray-500">Training Preference</span>
                <span class="text-gray-300"><?= mpE(isset($member['training_preference']) ? (string)$member['training_preference'] : null, 'Group') ?></span>
            </div>

            <div class="flex flex-col">
                <span class="text-gray-500">School / Club</span>
                <span class="text-gray-300"><?= mpE(isset($member['affiliated_school']) ? (string)$member['affiliated_school'] : null, 'N/A') ?></span>
            </div>

            <div class="flex flex-col">
                <span class="text-gray-500">Address</span>
                <span class="text-gray-300"><?= mpE(isset($member['address']) ? (string)$member['address'] : null, 'N/A') ?></span>
            </div>

            <div class="flex flex-col">
                <span class="text-gray-500">Contact</span>
                <span class="text-gray-300"><?= mpE(isset($member['contact_number']) ? (string)$member['contact_number'] : null, 'N/A') ?></span>
            </div>

            <div class="flex flex-col">
                <span class="text-gray-500">Email</span>
                <span class="text-gray-300"><?= mpE(isset($member['email']) ? (string)$member['email'] : null, 'N/A') ?></span>
            </div>

            <div class="flex flex-col">
                <span class="text-gray-500">Date Joined</span>
                <span class="text-gray-300"><?= mpDate(isset($member['created_at']) ? (string)$member['created_at'] : null) ?></span>
            </div>

            <div class="flex flex-col">
                <span class="text-gray-500">Height</span>
                <span class="text-gray-300">
                    <?php
                    $mpHeight = isset($member['height']) && $member['height'] !== null ? trim((string)$member['height']) : '';
                    echo $mpHeight !== '' ? htmlspecialchars($mpHeight . ' cm', ENT_QUOTES, 'UTF-8') : 'N/A';
                    ?>
                </span>
            </div>

            <div class="flex flex-col">
                <span class="text-gray-500">Weight</span>
                <span class="text-gray-300">
                    <?php
                    $mpWeight = isset($member['weight']) && $member['weight'] !== null ? trim((string)$member['weight']) : '';
                    echo $mpWeight !== '' ? htmlspecialchars($mpWeight . ' kg', ENT_QUOTES, 'UTF-8') : 'N/A';
                    ?>
                </span>
            </div>

            <div class="flex flex-col">
                <span class="text-gray-500">Guardian</span>
                <span class="text-gray-300"><?= mpE(isset($member['guardian_name']) ? (string)$member['guardian_name'] : null, 'N/A') ?></span>
            </div>

            <div class="flex flex-col">
                <span class="text-gray-500">Guardian Contact</span>
                <span class="text-gray-300"><?= mpE(isset($member['guardian_contact']) ? (string)$member['guardian_contact'] : null, 'N/A') ?></span>
            </div>

            <div class="flex flex-col lg:col-span-2">
                <span class="text-gray-500">Payment / Program Notes</span>
                <span class="text-gray-300"><?= mpE(isset($member['payment_notes']) ? (string)$member['payment_notes'] : null, 'N/A') ?></span>
            </div>

        </div>

    </div>

</div>

    <hr class="border-gray-800 mb-6 sm:mb-8">

    <!-- RESPONSIVE: Attendance Summary -->
    <div class="mb-8 sm:mb-12">
        <div class="flex items-center gap-2 mb-4 sm:mb-6">
            <h3 class="text-gray-400 font-semibold text-sm sm:text-base">Attendance Summary</h3>
            <i class="fas fa-info-circle text-gray-600 text-xs"></i>
        </div>
        <!-- RESPONSIVE: Grid on mobile, flex on desktop -->
        <div class="grid grid-cols-2 sm:flex sm:gap-12 lg:gap-20 gap-4">
            <div>
                <p class="text-gray-500 text-[10px] sm:text-xs uppercase mb-1">Present:</p>
                <div onclick="openAttendanceDetails(<?= $memberId ?>, 'Present')" class="cursor-pointer">
                    <p class="text-blue-500 font-bold text-base sm:text-lg"><u><?= $presentCount ?></u></p>
                </div>
            </div>
            <div>
                <p class="text-gray-500 text-[10px] sm:text-xs uppercase mb-1">Late:</p>
                <div onclick="openAttendanceDetails(<?= $memberId ?>, 'Late')" class="cursor-pointer">
                    <p class="text-blue-500 font-bold text-base sm:text-lg"><u><?= $lateCount ?></u></p>
                </div>
            </div>
            <div>
                <p class="text-gray-500 text-[10px] sm:text-xs uppercase mb-1">Absent:</p>
                <div onclick="openAttendanceDetails(<?= $memberId ?>, 'Absent')" class="cursor-pointer">
                    <p class="text-blue-500 font-bold text-base sm:text-lg"><u><?= $absentCount ?></u></p>
                </div>
            </div>
            <div>
                <p class="text-gray-500 text-[10px] sm:text-xs uppercase mb-1">Attendance Rate:</p>
                <p class="text-blue-400 font-bold text-base sm:text-lg"><?= (int)round($attendanceRate) ?>%</p>
                <p class="text-gray-600 text-[9px] sm:text-[10px] mt-0.5">
                    <?= $presentCount ?> present of <?= $attendanceTotal ?> recorded
                </p>
            </div>
        </div>
    </div>

    <hr class="border-gray-800 mb-6 sm:mb-8">

    <!-- Belt Progression Timeline -->
    <div class="mb-8 sm:mb-12" id="belt-progression">
        <div class="flex items-center gap-2 mb-4 sm:mb-6">
            <h3 class="text-gray-400 font-semibold text-sm sm:text-base">Belt Progression</h3>
            <i class="fas fa-award text-yellow-500/70 text-xs"></i>
            <span class="ml-auto text-[10px] uppercase tracking-widest text-gray-500"><?= count($rankHistory) ?> promotion<?= count($rankHistory) === 1 ? '' : 's' ?></span>
        </div>
        <?php if ($nextRankName !== null): ?>
        <div class="card-bg rounded-2xl p-4 sm:p-6 mb-3 border border-purple-500/20">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-[9px] sm:text-[10px] text-gray-500 uppercase tracking-widest font-bold">Next belt</p>
                    <p class="text-sm sm:text-base font-black mt-1">
                        <?= htmlspecialchars($currentRankName, ENT_QUOTES, 'UTF-8') ?>
                        <i class="fas fa-arrow-right text-purple-400 mx-1 text-[10px]"></i>
                        <span class="text-yellow-300"><?= htmlspecialchars($nextRankName, ENT_QUOTES, 'UTF-8') ?></span>
                    </p>
                    <p class="text-[10px] sm:text-xs text-gray-500 mt-1">
                        <?= (int)$sessionsAtBelt ?> / <?= (int)$beltGuideline ?> guideline sessions at this belt
                        <?php if ($beltTenureDays !== null): ?> &bull; <?= (int)$beltTenureDays ?> day<?= (int)$beltTenureDays === 1 ? '' : 's' ?> holding<?php endif; ?>
                    </p>
                </div>
                <div class="sm:text-right">
                    <p class="text-xl sm:text-2xl font-black text-purple-300"><?= (int)$beltProgressPct ?>%</p>
                    <p class="text-[9px] text-gray-600 uppercase">of guideline</p>
                </div>
            </div>
            <div class="h-2 rounded-full bg-white/5 overflow-hidden">
                <div class="h-full rounded-full bg-gradient-to-r from-purple-500 to-yellow-400 transition-all" style="width: <?= (int)$beltProgressPct ?>%"></div>
            </div>
            <p class="text-[9px] sm:text-[10px] text-gray-600 mt-2 italic">Guideline only — your coach decides every promotion based on skill, attendance and attitude.</p>
        </div>
        <?php else: ?>
        <div class="card-bg rounded-2xl p-4 sm:p-6 mb-3 border border-yellow-500/30 text-center">
            <i class="fas fa-crown text-yellow-400 text-2xl mb-2"></i>
            <p class="text-sm sm:text-base font-black text-yellow-300">Highest rank achieved — <?= htmlspecialchars($currentRankName, ENT_QUOTES, 'UTF-8') ?></p>
            <p class="text-[10px] sm:text-xs text-gray-500 mt-1">You now mentor the next generation of players.</p>
        </div>
        <?php endif; ?>
        <?php if (empty($rankHistory)): ?>
            <div class="card-bg rounded-2xl p-4 sm:p-6 text-center text-gray-500 text-xs sm:text-sm">
                <i class="fas fa-medal text-2xl mb-2 opacity-30"></i>
                <p>No promotions recorded yet — current rank: <span class="text-yellow-400 font-bold"><?= mpE($member['rank'] ?? 'None') ?></span></p>
                <p class="text-[10px] text-gray-600 mt-1">Every future rank change is journaled here with who approved it.</p>
            </div>
        <?php else: ?>
            <div class="space-y-2">
                <?php foreach ($rankHistory as $i => $promo):
                    $isLatest = ($i === 0);
                    $promoDate = '';
                    try {
                        $ts = strtotime((string)($promo['promoted_at'] ?? ''));
                        $promoDate = $ts === false ? '' : date('M d, Y', $ts);
                    } catch (Throwable $e) { $promoDate = ''; }
                ?>
                <div class="card-bg rounded-2xl p-4 flex items-center gap-3 sm:gap-4 <?= $isLatest ? 'border border-yellow-500/30' : '' ?>">
                    <div class="w-9 h-9 sm:w-10 sm:h-10 rounded-full flex items-center justify-center flex-shrink-0 <?= $isLatest ? 'bg-yellow-500/15 text-yellow-400' : 'bg-white/5 text-gray-400' ?>">
                        <i class="fas fa-medal text-sm"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="text-xs sm:text-sm font-bold truncate">
                            <?= mpE($promo['old_rank'] ?? '—') ?>
                            <i class="fas fa-arrow-right text-purple-400 mx-1 text-[10px]"></i>
                            <?= mpE($promo['new_rank'] ?? '') ?>
                            <?php if ($isLatest): ?>
                                <span class="ml-2 px-2 py-0.5 rounded-full bg-yellow-500/10 text-yellow-300 text-[9px] font-black uppercase">Current</span>
                            <?php endif; ?>
                        </div>
                        <div class="text-[10px] text-gray-500 mt-1">
                            <?= $promoDate !== '' ? htmlspecialchars($promoDate, ENT_QUOTES, 'UTF-8') . ' &bull; ' : '' ?>
                            by <?= mpE($promo['promoted_by_name'] ?? 'System') ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <hr class="border-gray-800 mb-6 sm:mb-8">

    <!-- Promotion Readiness (guide only — staff decides) -->
    <?php if ($readiness !== null): ?>
    <div class="mb-8 sm:mb-12">
        <div class="flex items-center gap-2 mb-4 sm:mb-6">
            <h3 class="text-gray-400 font-semibold text-sm sm:text-base">Promotion Readiness</h3>
            <i class="fas fa-compass text-purple-400/70 text-xs"></i>
            <?php if ($readiness['ready']): ?>
                <span class="ml-auto px-2 py-1 rounded-full bg-green-500/10 text-green-300 text-[9px] font-black uppercase">Ready for review</span>
            <?php elseif ($readiness['next'] === null): ?>
                <span class="ml-auto px-2 py-1 rounded-full bg-purple-500/10 text-purple-300 text-[9px] font-black uppercase">Highest rank</span>
            <?php else: ?>
                <span class="ml-auto px-2 py-1 rounded-full bg-white/5 text-gray-400 text-[9px] font-black uppercase">In progress</span>
            <?php endif; ?>
        </div>
        <div class="card-bg rounded-2xl p-4 sm:p-6">
            <div class="w-full bg-[#1d212a] rounded-full h-2 mb-4">
                <div class="bg-gradient-to-r from-purple-500 to-yellow-400 h-2 rounded-full transition-all" style="width: <?= (int)arnisRankProgress((string)($member['rank'] ?? 'None')) ?>%"></div>
            </div>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-center">
                <div>
                    <div class="text-sm sm:text-base font-bold text-white"><?= $readiness['rate'] ?>%</div>
                    <div class="text-[9px] text-gray-500 uppercase mt-1">Attendance<br>90 days (<?= (int)$readiness['sessions'] ?> sessions)</div>
                </div>
                <div>
                    <div class="text-sm sm:text-base font-bold text-white"><?= (int)$readiness['achievements'] ?></div>
                    <div class="text-[9px] text-gray-500 uppercase mt-1">Lifetime<br>achievements</div>
                </div>
                <div>
                    <div class="text-sm sm:text-base font-bold text-yellow-300"><?= mpE($member['rank'] ?? 'None') ?></div>
                    <div class="text-[9px] text-gray-500 uppercase mt-1">Current<br>belt</div>
                </div>
                <div>
                    <div class="text-sm sm:text-base font-bold text-purple-300"><?= $readiness['next'] !== null ? mpE($readiness['next']) : '—' ?></div>
                    <div class="text-[9px] text-gray-500 uppercase mt-1">Next<br>belt</div>
                </div>
            </div>
            <p class="text-[10px] text-gray-600 mt-4 text-center">Guide only: ≥75% attendance (4+ sessions) + ≥1 achievement suggests readiness. Final promotion is decided by the coach.</p>
        </div>
    </div>
    <?php endif; ?>

    <hr class="border-gray-800 mb-6 sm:mb-8">

    <!-- RESPONSIVE: Achievements Section -->
    <div class="mb-8 sm:mb-12">
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-4 sm:mb-6 gap-3">
            <h3 class="text-gray-400 font-semibold text-sm sm:text-base">Achievements Table</h3>

            <!-- The select had no name, no id and no handler: it did nothing. -->
            <form method="get" action="member_profile.php" class="flex flex-col sm:flex-row items-start sm:items-center gap-2 sm:gap-4 w-full sm:w-auto">
                <input type="hidden" name="id" value="<?= $memberId ?>">
                <?php if ($eventMonth !== ''): ?>
                <input type="hidden" name="event_month" value="<?= htmlspecialchars($eventMonth, ENT_QUOTES, 'UTF-8') ?>">
                <?php endif; ?>

                <span class="text-gray-500 text-xs sm:text-sm whitespace-nowrap">Filter by Achievement:</span>
                <div class="flex items-center gap-2 w-full sm:w-auto">
                    <select name="achievement" onchange="this.form.submit()"
                            class="card-bg border-none rounded-full px-4 sm:px-5 py-3 sm:py-4 text-xs sm:text-sm outline-none text-center w-full sm:w-48">
                        <option value=""<?= $achievementFilter === '' ? ' selected' : '' ?>>All</option>
                        <?php foreach (MemberProfile::ACHIEVEMENT_TYPES as $mpType): ?>
                        <option value="<?= htmlspecialchars($mpType, ENT_QUOTES, 'UTF-8') ?>"<?= $achievementFilter === $mpType ? ' selected' : '' ?>><?= htmlspecialchars($mpType, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($achievementFilter !== ''): ?>
                    <a href="<?= htmlspecialchars($mpFilterLink(['achievement' => '']), ENT_QUOTES, 'UTF-8') ?>"
                       class="text-gray-500 hover:text-white transition text-xs whitespace-nowrap" title="Clear filter">
                        <i class="fas fa-xmark"></i>
                    </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- RESPONSIVE: Cards on mobile, table on desktop -->
        <div class="card-bg rounded-2xl sm:rounded-3xl overflow-hidden">
            <!-- MOBILE: Card View -->
            <div class="block lg:hidden">
                <div class="p-2 space-y-2">
                    <?php if (empty($achievements)): ?>
                        <div class="text-center py-6 text-gray-400">No achievements recorded yet.</div>
                    <?php else: ?>
                        <?php foreach ($achievements as $achievement): ?>
                            <?php $mpType = isset($achievement['achievement_type']) ? (string)$achievement['achievement_type'] : ''; ?>
                        <div class="bg-white/[0.02] rounded-xl p-4 border border-gray-800/50">
                            <div class="flex justify-between items-start mb-3">
                                <div class="min-w-0">
                                    <div class="font-bold text-sm mb-1"><?= mpE(isset($achievement['event_name']) ? (string)$achievement['event_name'] : null, 'Unlinked achievement') ?></div>
                                    <div class="text-lg"><?= MemberProfile::getAchievementEmoji($mpType) ?> <?= htmlspecialchars($mpType, ENT_QUOTES, 'UTF-8') ?></div>
                                </div>
                                <span class="text-[10px] px-2 py-1 rounded-full whitespace-nowrap <?= MemberProfile::getAchievementColor($mpType) ?>"><?= mpE(isset($achievement['level']) ? (string)$achievement['level'] : null, 'N/A') ?></span>
                            </div>
                            <div class="text-[10px] sm:text-xs text-gray-400 space-y-1">
                                <div><i class="fas fa-calendar w-4"></i> <?= mpDate(isset($achievement['date_earned']) ? (string)$achievement['date_earned'] : null) ?></div>
                                <div><i class="fas fa-note-sticky w-4"></i> <?= mpE(isset($achievement['notes']) ? (string)$achievement['notes'] : null, '-') ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- DESKTOP: Table View. The old "Type" column duplicated "Achievement";
                 the level chip replaces it with real information. -->
            <div class="hidden lg:block overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead class="text-gray-400 text-xs uppercase tracking-widest border-b border-gray-700">
                        <tr>
                            <th class="p-4 lg:p-6">Event</th>
                            <th class="p-4 lg:p-6">Achievement</th>
                            <th class="p-4 lg:p-6">Date</th>
                            <th class="p-4 lg:p-6">Level</th>
                            <th class="p-4 lg:p-6">Notes</th>
                        </tr>
                    </thead>
                    <tbody class="text-sm">
                        <?php if (empty($achievements)): ?>
                            <tr>
                                <td colspan="5" class="text-center py-6 text-gray-400">No achievements recorded yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($achievements as $achievement): ?>
                                <?php $mpType = isset($achievement['achievement_type']) ? (string)$achievement['achievement_type'] : ''; ?>
                            <tr class="border-b border-gray-800 hover:bg-white/5 transition">
                                <td class="p-4 lg:p-6"><?= mpE(isset($achievement['event_name']) ? (string)$achievement['event_name'] : null, 'Unlinked achievement') ?></td>
                                <td class="p-4 lg:p-6">
                                    <span><?= MemberProfile::getAchievementEmoji($mpType) ?></span> <?= htmlspecialchars($mpType, ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td class="p-4 lg:p-6"><?= mpDate(isset($achievement['date_earned']) ? (string)$achievement['date_earned'] : null) ?></td>
                                <td class="p-4 lg:p-6"><?= mpE(isset($achievement['level']) ? (string)$achievement['level'] : null, 'N/A') ?></td>
                                <td class="p-4 lg:p-6"><?= mpE(isset($achievement['notes']) ? (string)$achievement['notes'] : null, '-') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($achievementFilter !== ''): ?>
        <p class="text-[10px] text-gray-500 mt-3">
            Showing <span class="text-gray-300"><?= htmlspecialchars($achievementLabel, ENT_QUOTES, 'UTF-8') ?></span> achievements only &mdash;
            <a href="<?= htmlspecialchars($mpFilterLink(['achievement' => '']), ENT_QUOTES, 'UTF-8') ?>" class="text-purple-400 hover:underline">show all</a>
        </p>
        <?php endif; ?>
    </div>

    <hr class="border-gray-800 mb-6 sm:mb-8">

    <!-- RESPONSIVE: Events Section -->
    <div class="mb-8 sm:mb-12">
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-4 sm:mb-6 gap-3">
            <h3 class="text-gray-400 font-semibold text-sm sm:text-base">Events and performances participated in</h3>

            <form method="get" action="member_profile.php" class="flex flex-col sm:flex-row items-start sm:items-center gap-2 sm:gap-4 w-full sm:w-auto">
                <input type="hidden" name="id" value="<?= $memberId ?>">
                <?php if ($achievementFilter !== ''): ?>
                <input type="hidden" name="achievement" value="<?= htmlspecialchars($achievementFilter, ENT_QUOTES, 'UTF-8') ?>">
                <?php endif; ?>

                <span class="text-gray-500 text-xs sm:text-sm whitespace-nowrap">Filter by date:</span>
                <div class="flex items-center gap-2 w-full sm:w-auto">
                    <input type="month" name="event_month" value="<?= htmlspecialchars($eventMonth, ENT_QUOTES, 'UTF-8') ?>"
                           onchange="this.form.submit()"
                           class="card-bg border border-gray-800 rounded-full px-4 sm:px-5 py-3 sm:py-4 text-xs sm:text-sm outline-none text-gray-300 w-full sm:w-auto">
                    <?php if ($eventMonth !== ''): ?>
                    <a href="<?= htmlspecialchars($mpFilterLink(['event_month' => '']), ENT_QUOTES, 'UTF-8') ?>"
                       class="text-gray-500 hover:text-white transition text-xs whitespace-nowrap" title="Clear filter">
                        <i class="fas fa-xmark"></i>
                    </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- RESPONSIVE: Cards on mobile, table on desktop -->
        <div class="card-bg rounded-2xl sm:rounded-3xl overflow-hidden">
            <!-- MOBILE: Card View -->
            <div class="block lg:hidden">
                <div class="p-2 space-y-2">
                    <?php if (empty($eventParticipations)): ?>
                        <div class="text-center py-6 text-gray-400">No event participations recorded yet.</div>
                    <?php else: ?>
                        <?php foreach ($eventParticipations as $participation): ?>
                        <div class="bg-white/[0.02] rounded-xl p-4 border border-gray-800/50">
                            <div class="flex justify-between items-start mb-3">
                                <div class="min-w-0">
                                    <div class="font-bold text-sm mb-1"><?= mpE(isset($participation['event_name']) ? (string)$participation['event_name'] : null, 'Unknown event') ?></div>
                                    <div class="text-[10px] text-gray-400"><?= mpE(isset($participation['event_type']) ? (string)$participation['event_type'] : null, 'N/A') ?></div>
                                </div>
                                <span class="text-green-400 bg-green-400/10 px-2 py-1 rounded-full text-[9px] font-bold whitespace-nowrap">Participated</span>
                            </div>
                            <div class="grid grid-cols-2 gap-2 text-[10px] sm:text-xs text-gray-400 mb-3">
                                    <div>
                                        <span class="text-gray-500 block">Date:</span>
                                        <span><?= mpDate(isset($participation['event_date']) ? (string)$participation['event_date'] : null) ?></span>
                                    </div>
                                    <div>
                                        <span class="text-gray-500 block">Location:</span>
                                        <span><?= mpE(isset($participation['event_location']) ? (string)$participation['event_location'] : null, 'N/A') ?></span>
                                    </div>
                                    <div>
                                        <span class="text-gray-500 block">Role:</span>
                                        <span><?= mpE(isset($participation['role']) ? (string)$participation['role'] : null, 'Participant') ?></span>
                                    </div>
                                    <div>
                                        <span class="text-gray-500 block">Registered:</span>
                                        <span><?= mpDate(isset($participation['added_at']) ? (string)$participation['added_at'] : null) ?></span>
                                    </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- DESKTOP: Table View. The old "Remarks" column was hardcoded to
                 "Participated"; the badge now carries that and the column shows
                 the real recorded-at date. -->
            <div class="hidden lg:block overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead class="text-gray-400 text-xs uppercase tracking-widest border-b border-gray-700">
                        <tr>
                            <th class="p-4 lg:p-6">Event Name</th>
                            <th class="p-4 lg:p-6">Type</th>
                            <th class="p-4 lg:p-6">Date</th>
                            <th class="p-4 lg:p-6">Location</th>
                            <th class="p-4 lg:p-6">Role</th>
                            <th class="p-4 lg:p-6">Status</th>
                        </tr>
                    </thead>
                    <tbody class="text-sm">
                        <?php if (empty($eventParticipations)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-6 text-gray-400">No event participations recorded yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($eventParticipations as $participation): ?>
                            <tr class="border-b border-gray-800 hover:bg-white/5 transition">
                                <td class="p-4 lg:p-6"><?= mpE(isset($participation['event_name']) ? (string)$participation['event_name'] : null, 'Unknown event') ?></td>
                                <td class="p-4 lg:p-6"><?= mpE(isset($participation['event_type']) ? (string)$participation['event_type'] : null, 'N/A') ?></td>
                                <td class="p-4 lg:p-6"><?= mpDate(isset($participation['event_date']) ? (string)$participation['event_date'] : null) ?></td>
                                <td class="p-4 lg:p-6 italic"><?= mpE(isset($participation['event_location']) ? (string)$participation['event_location'] : null, 'N/A') ?></td>
                                <td class="p-4 lg:p-6"><?= mpE(isset($participation['role']) ? (string)$participation['role'] : null, 'Participant') ?></td>
                                <td class="p-4 lg:p-6">
                                    <span class="text-green-400 bg-green-400/10 px-2 py-1 rounded-full text-[9px] font-bold">Participated</span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($eventMonth !== ''): ?>
        <p class="text-[10px] text-gray-500 mt-3">
            Showing <span class="text-gray-300"><?= htmlspecialchars($eventMonthLabel, ENT_QUOTES, 'UTF-8') ?></span> events only &mdash;
            <a href="<?= htmlspecialchars($mpFilterLink(['event_month' => '']), ENT_QUOTES, 'UTF-8') ?>" class="text-purple-400 hover:underline">show all</a>
        </p>
        <?php endif; ?>
    </div>
</main>
</div>

<?php if ($canPromote && $nextRankName !== null): ?>
<div id="promoteMemberModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4">
    <div class="card-bg w-full max-w-md rounded-3xl overflow-hidden shadow-2xl border border-gray-800">
        <div class="px-6 py-5 border-b border-gray-800 flex justify-between items-center">
            <h2 class="text-lg font-bold italic uppercase tracking-wider text-yellow-300">Promote Member</h2>
            <button type="button" onclick="closePromoteModal()" class="text-gray-500 hover:text-white"><i class="fas fa-times"></i></button>
        </div>
        <div class="p-6 space-y-4">
            <p class="text-sm text-gray-300">
                <span class="font-bold"><?= mpE(isset($member['full_name']) ? (string)$member['full_name'] : null, 'Member') ?></span>
                will advance exactly one rank:
                <span class="text-gray-400"><?= htmlspecialchars($currentRankName, ENT_QUOTES, 'UTF-8') ?></span>
                <i class="fas fa-arrow-right mx-1 text-yellow-300"></i>
                <span class="text-yellow-300 font-bold"><?= htmlspecialchars($nextRankName, ENT_QUOTES, 'UTF-8') ?></span>
            </p>
            <div>
                <label for="promoteNotes" class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Promotion Notes (optional)</label>
                <textarea id="promoteNotes" rows="3" maxlength="255" class="w-full bg-[#1d212a] rounded-xl px-4 py-3 text-sm text-white focus:outline-none border border-transparent focus:border-yellow-500 transition resize-none" placeholder="Assessment, exam result, or ceremony date"></textarea>
            </div>
            <div class="flex gap-3">
                <button type="button" onclick="closePromoteModal()" class="flex-1 px-5 py-3 rounded-full bg-white/5 hover:bg-white/10 text-xs font-bold uppercase tracking-widest text-gray-400">Cancel</button>
                <button type="button" id="promoteSubmitBtn" onclick="submitPromotion()" class="flex-1 px-5 py-3 rounded-full bg-yellow-500 hover:bg-yellow-400 text-black text-xs font-black uppercase tracking-widest transition">Confirm Promotion</button>
            </div>
        </div>
    </div>
</div>
<script>
function openPromoteModal() {
    const modal = document.getElementById('promoteMemberModal');
    if (modal) modal.classList.remove('hidden');
}
function closePromoteModal() {
    const modal = document.getElementById('promoteMemberModal');
    if (modal) modal.classList.add('hidden');
}
function submitPromotion() {
    const btn = document.getElementById('promoteSubmitBtn');
    const notes = document.getElementById('promoteNotes');
    if (btn) { btn.disabled = true; btn.textContent = 'Promoting...'; }

    const body = new URLSearchParams({
        member_id: <?= (int)$memberId ?>,
        new_rank: <?= json_encode($nextRankName, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        notes: notes ? notes.value : '',
        csrf_token: <?= json_encode(arnisCsrfToken(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
    });

    fetch('../actions/promote_member.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
        body: body.toString()
    })
    .then(function (response) { return response.json(); })
    .then(function (data) {
        alert(data.message || (data.success ? 'Member promoted.' : 'Promotion failed.'));
        if (data.success) window.location.reload();
        else if (btn) { btn.disabled = false; btn.textContent = 'Confirm Promotion'; }
    })
    .catch(function () {
        alert('Promotion failed. Please try again.');
        if (btn) { btn.disabled = false; btn.textContent = 'Confirm Promotion'; }
    });
}
</script>
<?php endif; ?>

<script>
window.arnisMemberFieldPermissions = <?= json_encode($memberFieldPermissions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>

<?php include_once __DIR__ . '/modals/edit_member.php'; ?>
<?php include_once __DIR__ . '/modals/attendance_details.php'; ?>
<?php include_once __DIR__ . '/modals/attendance_checkin.php'; ?>
<?php include_once __DIR__ . '/modals/user_menu.php'; ?>
<?php include_once __DIR__ . '/modals/edit_profile_user.php'; ?>

<!-- Cloudinary Image Upload Modal -->
<div id="imageUploadModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4">
    <div id="imageUploadCard"
     class="card-bg w-full max-w-md rounded-3xl max-h-[80vh] flex flex-col overflow-hidden shadow-2xl
            transform transition-all duration-300 ease-out
            scale-95 opacity-0">
        <div class="px-8 py-6 border-b border-gray-800 flex justify-between items-center">
            <h2 class="text-xl tracking-wide font-bold italic uppercase text-purple-400">Update Profile Picture</h2>
            <button type="button" onclick="closeImageUploadModal()" class="text-gray-500 hover:text-white transition">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>

        <form id="imageUploadForm" class="p-8 flex-1 overflow-y-auto" onsubmit="return false;">
            <input type="hidden" name="member_id" value="<?= $memberId ?>">
            <input type="hidden" name="cloudinary_url" id="cloudinary_url">

            <div class="space-y-6">
                <!-- Current Image -->
                <div class="text-center">
                    <p class="text-gray-500 text-sm mb-3">Current Image</p>
                    <img id="currentProfileImage"
                         src="<?= mpAvatar(isset($member['profile_img']) ? (string)$member['profile_img'] : null) ?>"
                         alt="Current profile picture"
                         class="w-32 h-32 rounded-full object-cover border-4 border-gray-700 mx-auto">
                </div>

                <div class="text-center mb-4">
                    <button type="button" onclick="document.getElementById('cloudinaryFileInput').click()"
                            class="w-full px-6 py-4 bg-gradient-to-r from-purple-600 to-pink-600 hover:from-purple-700 hover:to-pink-700 rounded-xl transition text-sm font-semibold shadow-lg shadow-purple-900/30">
                        <i class="fas fa-cloud-upload-alt mr-2"></i>Choose file
                    </button>
                    <p class="text-xs text-gray-400 mt-2">Select an image from your computer</p>
                </div>

                <input type="file" id="cloudinaryFileInput" accept="image/jpeg,image/png,image/gif,image/webp" class="hidden">

                <!-- Upload Progress -->
                <div id="uploadProgress" class="hidden">
                    <div class="flex items-center gap-3">
                        <div class="flex-1 bg-gray-800 rounded-full h-2">
                            <div id="progressBar" class="bg-purple-500 h-2 rounded-full transition-all duration-300" style="width: 0%"></div>
                        </div>
                        <span id="progressText" class="text-xs text-gray-400">0%</span>
                    </div>
                    <p id="uploadStatus" class="text-xs text-gray-500 mt-2 text-center"></p>
                </div>

                <!-- Preview -->
                <div id="cloudinaryPreview" class="hidden text-center">
                    <p class="text-gray-500 text-sm mb-2">New Image Preview:</p>
                    <img id="previewImage" src="" alt="Preview" class="w-32 h-32 rounded-full object-cover border-4 border-gray-700 mx-auto">
                    <p class="text-xs text-green-400 mt-2">Ready to update!</p>
                </div>

                <!-- Validation message -->
                <div id="imageUploadMessage" class="text-xs text-red-400 hidden"></div>
            </div>

            <div class="mt-10 flex gap-4">
                <button type="button" onclick="closeImageUploadModal()" class="flex-1 px-6 py-3 rounded-3xl hover:bg-gray-800 transition text-sm font-semibold">CANCEL</button>
                <button type="button" onclick="saveCloudinaryImage()" id="saveImageBtn" disabled class="flex-1 px-6 py-3 bg-[#bf46c7] rounded-3xl opacity-50 cursor-not-allowed transition text-sm font-semibold shadow-lg shadow-purple-900/20">UPDATE IMAGE</button>
            </div>
        </form>
    </div>
</div>

<!-- Face Enrollment Modal -->
<div id="faceEnrollModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4">
    <div id="faceEnrollCard"
     class="card-bg w-full max-w-md rounded-3xl max-h-[90vh] flex flex-col overflow-hidden shadow-2xl
            transform transition-all duration-300 ease-out
            scale-95 opacity-0">
        <div class="px-8 py-6 border-b border-gray-800 flex justify-between items-center">
            <h2 class="text-xl tracking-wide font-bold italic uppercase text-purple-400">Face Enrollment</h2>
            <button type="button" onclick="closeFaceEnrollModal()" class="text-gray-500 hover:text-white transition">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>

        <form id="faceEnrollForm" class="p-8 flex-1 overflow-y-auto" onsubmit="return false;">
            <input type="hidden" name="member_id" value="<?= $memberId ?>">
            <input type="hidden" name="face_descriptor" id="faceDescriptorInput">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

            <div class="space-y-6">
                <!-- Instructions -->
                <div class="text-center">
                    <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-purple-500/10 flex items-center justify-center">
                        <i class="fas fa-user-secret text-2xl text-purple-400"></i>
                    </div>
                    <p class="text-gray-300 text-sm mb-2" id="faceEnrollTitle">
                        <?= !empty($member['face_descriptor']) ? 'Re-enroll your face' : 'Enroll your face for smart attendance' ?>
                    </p>
                    <p class="text-gray-500 text-xs" id="faceEnrollSubtitle">
                        Position your face in the frame. Ensure good lighting and look directly at the camera.
                    </p>
                </div>

                <!-- Camera Feed -->
                <div class="relative" id="cameraContainer">
                    <video id="faceVideo" class="w-full aspect-video rounded-xl bg-gray-900" autoplay muted playsinline></video>
                    <canvas id="faceCanvas" class="absolute top-0 left-0 w-full h-full rounded-xl pointer-events-none"></canvas>
                    <div id="faceOverlay" class="absolute top-0 left-0 w-full h-full rounded-xl flex items-center justify-center pointer-events-none">
                        <div class="w-48 h-48 border-2 border-purple-400/50 rounded-full animate-pulse"></div>
                    </div>
                </div>

                <!-- Status & Progress -->
                <div id="faceEnrollStatus" class="text-center text-sm text-gray-400 hidden">
                    <div class="flex items-center justify-center gap-2">
                        <div class="w-4 h-4 border-2 border-purple-400 border-t-transparent rounded-full animate-spin"></div>
                        <span id="faceEnrollStatusText">Initializing camera...</span>
                    </div>
                </div>

                <div id="faceEnrollError" class="text-center text-sm text-red-400 hidden" role="alert"></div>

                <div id="faceEnrollSuccess" class="text-center text-sm text-green-400 hidden" role="status"></div>

                <!-- Capture Button -->
                <div class="flex gap-3" id="faceEnrollActions">
                    <button type="button" onclick="startFaceEnrollment()"
                            id="startEnrollBtn"
                            class="flex-1 px-6 py-3 bg-gradient-to-r from-purple-600 to-pink-600 hover:from-purple-700 hover:to-pink-700 rounded-xl transition text-sm font-semibold shadow-lg shadow-purple-900/30 hidden">
                        <i class="fas fa-video mr-2"></i>Start Camera
                    </button>
                    <button type="button" onclick="captureFaceDescriptor()"
                            id="captureBtn"
                            class="flex-1 px-6 py-3 bg-green-600 hover:bg-green-700 rounded-xl transition text-sm font-semibold shadow-lg shadow-green-900/30 hidden disabled:opacity-50 disabled:cursor-not-allowed"
                            disabled>
                        <i class="fas fa-camera-reticle mr-2"></i>Capture Face
                    </button>
                    <button type="button" onclick="retakeFaceEnrollment()"
                            id="retakeBtn"
                            class="flex-1 px-6 py-3 bg-gray-700 hover:bg-gray-600 rounded-xl transition text-sm font-semibold hidden">
                        <i class="fas fa-redo mr-2"></i>Retake
                    </button>
                </div>

                <div class="flex gap-3" id="saveActions" hidden>
                    <button type="button" onclick="closeFaceEnrollModal()"
                            class="flex-1 px-6 py-3 rounded-3xl hover:bg-gray-800 transition text-sm font-semibold">CANCEL</button>
                    <button type="button" onclick="saveFaceDescriptor()"
                            id="saveFaceBtn"
                            class="flex-1 px-6 py-3 bg-[#bf46c7] rounded-3xl transition text-sm font-semibold shadow-lg shadow-purple-900/20">
                        <i class="fas fa-save mr-2"></i>SAVE DESCRIPTOR
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

</body>

<script>
window.addEventListener("load", () => {
    const page = document.getElementById("page");
    page.classList.remove("opacity-0", "translate-x-8");
});

const MEMBER_ID = <?= (int)$memberId ?>;
const CSRF_TOKEN = <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const CLOUD_NAME = <?= json_encode('c1xfzhkb', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const UPLOAD_PRESET = <?= json_encode('members_upload', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const ALLOWED_UPLOAD_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
const MAX_UPLOAD_BYTES = 5 * 1024 * 1024;

const STATUS_CLASSES = {
    'Active': 'bg-green-500/20 text-green-400',
    'Inactive': 'bg-red-500/20 text-red-400',
    'On Leave': 'bg-yellow-500/20 text-yellow-400'
};

// Toggle status dropdown
function toggleStatusDropdown() {
    const dropdown = document.getElementById('statusDropdown');
    if (dropdown) {
        dropdown.classList.toggle('hidden');
    }
}

// Close dropdown when clicking outside
document.addEventListener('click', function (event) {
    const statusBtn = document.getElementById('statusBtn');
    const dropdown = document.getElementById('statusDropdown');
    if (statusBtn && dropdown && !statusBtn.contains(event.target) && !dropdown.contains(event.target)) {
        dropdown.classList.add('hidden');
    }
});

// Update member status via AJAX
function updateMemberStatus(newStatus) {
    if (!STATUS_CLASSES.hasOwnProperty(newStatus)) {
        showNotification('Invalid status value.', 'error');
        return;
    }

    const statusBtn = document.getElementById('statusBtn');
    const dropdown = document.getElementById('statusDropdown');

    if (dropdown) dropdown.classList.add('hidden');

    const previousHtml = statusBtn ? statusBtn.innerHTML : '';
    const previousClass = statusBtn ? statusBtn.className : '';

    if (statusBtn) {
        statusBtn.textContent = 'Updating...';
        statusBtn.disabled = true;
    }

    fetch('../actions/update_member_status.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
        body: JSON.stringify({
            member_id: MEMBER_ID,
            status: newStatus,
            csrf_token: CSRF_TOKEN
        })
    })
    .then(res => res.json().catch(() => ({success: false, error: 'Unexpected server response.'})))
    .then(resp => {
        if (statusBtn) statusBtn.disabled = false;

        if (resp.success) {
            if (statusBtn) {
                statusBtn.textContent = newStatus;
                statusBtn.className = previousClass.replace(/\b(bg-\S+|text-\S+)\b/g, '') + ' ' + STATUS_CLASSES[newStatus];
            }
            showNotification('Status updated to ' + newStatus, 'success');
        } else {
            if (statusBtn) {
                statusBtn.innerHTML = previousHtml;
                statusBtn.className = previousClass;
            }
            showNotification(resp.error || 'Failed to update status.', 'error');
        }
    })
    .catch(() => {
        if (statusBtn) {
            statusBtn.innerHTML = previousHtml;
            statusBtn.className = previousClass;
            statusBtn.disabled = false;
        }
        showNotification('Network error. Please try again.', 'error');
    });
}

function setUploadProgress(percent, statusText) {
    document.getElementById('uploadProgress').classList.remove('hidden');
    document.getElementById('progressBar').style.width = percent + '%';
    document.getElementById('progressText').textContent = percent + '%';
    if (statusText !== undefined) {
        document.getElementById('uploadStatus').textContent = statusText;
    }
}

function showUploadMessage(text) {
    const box = document.getElementById('imageUploadMessage');
    box.textContent = text;
    box.classList.remove('hidden');
}

document.getElementById('cloudinaryFileInput').addEventListener('change', async function (event) {
    const file = event.target.files && event.target.files[0];
    if (!file) return;

    if (file.size > MAX_UPLOAD_BYTES) {
        showNotification('Image size must be less than 5MB', 'error');
        event.target.value = '';
        return;
    }

    if (ALLOWED_UPLOAD_TYPES.indexOf(file.type) === -1) {
        showNotification('Please select a valid image file (JPEG, PNG, GIF, WebP)', 'error');
        event.target.value = '';
        return;
    }

    document.getElementById('imageUploadMessage').classList.add('hidden');
    setUploadProgress(10, 'Uploading to Cloudinary...');

    try {
        const formData = new FormData();
        formData.append('file', file);
        formData.append('upload_preset', UPLOAD_PRESET);
        /* cloud_name belongs in the endpoint URL, not in the multipart body. */

        const response = await fetch('https://api.cloudinary.com/v1_1/' + CLOUD_NAME + '/image/upload', {
            method: 'POST',
            body: formData
        });

        setUploadProgress(70, 'Processing image...');

        const data = await response.json().catch(() => ({}));

        if (data.error) {
            throw new Error(data.error.message || 'Upload failed');
        }

        if (!data.secure_url) {
            throw new Error('No secure URL returned from Cloudinary');
        }

        setUploadProgress(100, 'Upload complete!');

        document.getElementById('cloudinary_url').value = data.secure_url;
        document.getElementById('previewImage').src = data.secure_url;
        document.getElementById('cloudinaryPreview').classList.remove('hidden');

        const saveBtn = document.getElementById('saveImageBtn');
        saveBtn.disabled = false;
        saveBtn.classList.remove('opacity-50', 'cursor-not-allowed');
        saveBtn.classList.add('hover:opacity-90');

        showNotification('Image uploaded. Click UPDATE IMAGE to save.', 'success');

        setTimeout(() => {
            document.getElementById('uploadProgress').classList.add('hidden');
        }, 2000);
    } catch (error) {
        console.error('Cloudinary upload error:', error);
        document.getElementById('uploadProgress').classList.add('hidden');

        if (error.message && error.message.indexOf('upload preset') !== -1) {
            showUploadMessage('Cloudinary upload preset not found. Check the UPLOAD_PRESET constant.');
            showNotification('Cloudinary upload preset not found.', 'error');
        } else {
            showUploadMessage(error.message || 'Upload failed');
            showNotification('Upload failed: ' + (error.message || 'unknown error'), 'error');
        }
    }
});

function saveCloudinaryImage() {
    const cloudinaryUrl = document.getElementById('cloudinary_url').value;
    const saveBtn = document.getElementById('saveImageBtn');

    if (!cloudinaryUrl) {
        showNotification('Please upload an image first', 'error');
        return;
    }

    saveBtn.disabled = true;
    saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Saving...';

    const body = new URLSearchParams();
    body.set('member_id', String(MEMBER_ID));
    body.set('cloudinary_url', cloudinaryUrl);
    body.set('csrf_token', CSRF_TOKEN);

    fetch('../actions/update_profile_image.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
        },
        body: body.toString()
    })
    .then(res => res.json().catch(() => ({success: false, error: 'Unexpected server response.'})))
    .then(data => {
        if (data.success) {
            const stamp = data.profile_img + '?t=' + Date.now();
            const currentImage = document.getElementById('currentProfileImage');
            const avatar = document.getElementById('memberAvatar');
            if (currentImage) currentImage.src = stamp;
            if (avatar) avatar.src = stamp;

            closeImageUploadModal();
            showNotification('Profile image updated!', 'success');
        } else {
            showNotification(data.error || 'Failed to save', 'error');
            saveBtn.disabled = false;
            saveBtn.innerHTML = 'UPDATE IMAGE';
        }
    })
    .catch(() => {
        showNotification('Failed to save image', 'error');
        saveBtn.disabled = false;
        saveBtn.innerHTML = 'UPDATE IMAGE';
    });
}

function openImageUploadModal() {
    const modal = document.getElementById('imageUploadModal');
    const card = document.getElementById('imageUploadCard');

    document.getElementById('cloudinary_url').value = '';
    document.getElementById('cloudinaryFileInput').value = '';
    document.getElementById('cloudinaryPreview').classList.add('hidden');
    document.getElementById('uploadProgress').classList.add('hidden');
    document.getElementById('imageUploadMessage').classList.add('hidden');

    const saveBtn = document.getElementById('saveImageBtn');
    saveBtn.disabled = true;
    saveBtn.classList.add('opacity-50', 'cursor-not-allowed');
    saveBtn.classList.remove('hover:opacity-90');
    saveBtn.innerHTML = 'UPDATE IMAGE';

    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';

    requestAnimationFrame(() => {
        card.classList.remove('scale-95', 'opacity-0');
        card.classList.add('scale-100', 'opacity-100');
    });
}

function closeImageUploadModal() {
    const modal = document.getElementById('imageUploadModal');
    const card = document.getElementById('imageUploadCard');

    card.classList.remove('scale-100', 'opacity-100');
    card.classList.add('scale-95', 'opacity-0');

    setTimeout(() => {
        modal.classList.add('hidden');
        document.body.style.overflow = 'auto';
    }, 300);
}

/* Notification helper */
function showNotification(message, type = 'success') {
    const toastContainer = document.getElementById('toastContainer') || createToastContainer();

    const toast = document.createElement('div');
    toast.className = `
        fixed top-6 left-1/2 -translate-x-1/2 px-6 py-4 rounded-xl shadow-2xl border flex items-center gap-3
        transform transition-all duration-300 ease-out
        ${type === 'success'
            ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-400'
            : 'bg-amber-500/10 border-amber-500/30 text-amber-400'}
        animate-in slide-in-from-top-5
        z-[9999]
    `;

    const icon = type === 'success'
        ? '<i class="fas fa-check-circle text-lg"></i>'
        : '<i class="fas fa-exclamation-circle text-lg"></i>';

    /* textContent, not innerHTML: the message can carry server-supplied text. */
    const span = document.createElement('span');
    span.className = 'text-sm font-semibold';
    span.textContent = message;

    toast.innerHTML = icon;
    toast.appendChild(span);
    toastContainer.appendChild(toast);

    setTimeout(() => {
        toast.style.animation = 'slideOutTop 0.3s ease-in forwards';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

function createToastContainer() {
    const container = document.createElement('div');
    container.id = 'toastContainer';
    document.body.appendChild(container);

    const style = document.createElement('style');
    style.textContent = `
        @keyframes slideOutTop {
            to {
                transform: translateY(-100px);
                opacity: 0;
            }
        }
        @keyframes slideInFromTop {
            from {
                transform: translateY(-100px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        .animate-in {
            animation: slideInFromTop 0.3s ease-out;
        }
    `;
    document.head.appendChild(style);

    return container;
}

/* Close the upload modal when the backdrop itself is clicked.
   The previous version assigned window.onclick, which clobbered any handler
   already registered by an included modal. */
document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('imageUploadModal');
    if (modal) {
        modal.addEventListener('mousedown', function (event) {
            if (event.target === modal) {
                closeImageUploadModal();
            }
        });
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            const statusDropdown = document.getElementById('statusDropdown');
            if (statusDropdown) statusDropdown.classList.add('hidden');
            if (modal && !modal.classList.contains('hidden')) {
                closeImageUploadModal();
            }
            const faceModal = document.getElementById('faceEnrollModal');
            if (faceModal && !faceModal.classList.contains('hidden')) {
                closeFaceEnrollModal();
            }
        }
    });
});

/* ========================================================================
   FACE ENROLLMENT — client-side face-api.js (vladmandic fork v1.7.12)
   Runs fully in-browser; no ML backend. Saves 128-dim Float32Array as JSON
   to members.face_descriptor via actions/save_face_descriptor.php.
   ======================================================================== */

// Face enrollment state
let faceVideo = null;
let faceCanvas = null;
let faceDetection = null;
let modelsLoaded = false;
let stream = null;

// Model files served from jsDelivr (same CDN as the library)
const MODEL_BASE_URL = 'https://cdn.jsdelivr.net/npm/@vladmandic/face-api@1.7.12/model/';

async function loadFaceApiModels() {
    if (modelsLoaded) return true;

    try {
        // Load the three models needed for face recognition
        // 1. TinyFaceDetector (fast detection)
        // 2. FaceLandmark68Net (68-point landmarks for alignment)
        // 3. FaceRecognitionNet (128-dim descriptor)
        await Promise.all([
            faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_BASE_URL),
            faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_BASE_URL),
            faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_BASE_URL),
        ]);
        modelsLoaded = true;
        return true;
    } catch (error) {
        console.error('Failed to load face-api.js models:', error);
        return false;
    }
}

function openFaceEnrollModal() {
    const modal = document.getElementById('faceEnrollModal');
    const card = document.getElementById('faceEnrollCard');

    // Reset form state
    document.getElementById('faceDescriptorInput').value = '';
    document.getElementById('faceEnrollStatus').classList.add('hidden');
    document.getElementById('faceEnrollError').classList.add('hidden');
    document.getElementById('faceEnrollSuccess').classList.add('hidden');
    document.getElementById('startEnrollBtn').classList.remove('hidden');
    document.getElementById('captureBtn').classList.add('hidden');
    document.getElementById('retakeBtn').classList.add('hidden');
    document.getElementById('saveActions').hidden = true;
    document.getElementById('cameraContainer').classList.remove('hidden');
    document.getElementById('faceOverlay').classList.remove('hidden');

    // Update title based on existing descriptor
    const hasDescriptor = !!(document.getElementById('faceEnrollBadge') &&
        document.getElementById('faceEnrollBadge').textContent.includes('Enrolled'));
    document.getElementById('faceEnrollTitle').textContent = hasDescriptor
        ? 'Re-enroll your face'
        : 'Enroll your face for smart attendance';
    document.getElementById('faceEnrollSubtitle').textContent =
        'Position your face in the frame. Ensure good lighting and look directly at the camera.';

    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';

    requestAnimationFrame(() => {
        card.classList.remove('scale-95', 'opacity-0');
        card.classList.add('scale-100', 'opacity-100');
    });

    // Initialize video element reference
    faceVideo = document.getElementById('faceVideo');
    faceCanvas = document.getElementById('faceCanvas');
}

function closeFaceEnrollModal() {
    const modal = document.getElementById('faceEnrollModal');
    const card = document.getElementById('faceEnrollCard');

    // Stop camera stream
    stopCamera();

    card.classList.remove('scale-100', 'opacity-100');
    card.classList.add('scale-95', 'opacity-0');

    setTimeout(() => {
        modal.classList.add('hidden');
        document.body.style.overflow = 'auto';
    }, 300);
}

function stopCamera() {
    if (stream) {
        stream.getTracks().forEach(track => track.stop());
        stream = null;
    }
    if (faceVideo) {
        faceVideo.srcObject = null;
    }
    if (faceDetection) {
        cancelAnimationFrame(faceDetection);
        faceDetection = null;
    }
}

async function startFaceEnrollment() {
    const statusEl = document.getElementById('faceEnrollStatus');
    const statusText = document.getElementById('faceEnrollStatusText');
    const errorEl = document.getElementById('faceEnrollError');
    const startBtn = document.getElementById('startEnrollBtn');
    const captureBtn = document.getElementById('captureBtn');
    const overlay = document.getElementById('faceOverlay');

    // Hide previous errors
    errorEl.classList.add('hidden');
    errorEl.textContent = '';

    // Show loading
    startBtn.disabled = true;
    startBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Loading models...';
    statusEl.classList.remove('hidden');
    statusText.textContent = 'Loading face detection models...';

    // Load face-api.js models
    const loaded = await loadFaceApiModels();
    if (!loaded) {
        statusEl.classList.add('hidden');
        errorEl.textContent = 'Failed to load face detection models. Check your internet connection.';
        errorEl.classList.remove('hidden');
        startBtn.disabled = false;
        startBtn.innerHTML = '<i class="fas fa-video mr-2"></i>Start Camera';
        return;
    }

    statusText.textContent = 'Requesting camera access...';

    try {
        // Request camera access (prefer front camera on mobile)
        stream = await navigator.mediaDevices.getUserMedia({
            video: {
                facingMode: 'user',
                width: { ideal: 640 },
                height: { ideal: 480 },
            },
            audio: false,
        });

        faceVideo.srcObject = stream;

        // Wait for video to be ready
        await new Promise((resolve) => {
            faceVideo.onloadedmetadata = () => {
                faceVideo.play().then(resolve).catch(resolve);
            };
        });

        // Set canvas size to match video
        faceCanvas.width = faceVideo.videoWidth;
        faceCanvas.height = faceVideo.videoHeight;

        statusEl.classList.add('hidden');
        startBtn.classList.add('hidden');
        captureBtn.classList.remove('hidden');
        captureBtn.disabled = false;
        overlay.classList.add('hidden');

        // Start detection loop
        detectFaceLoop();

    } catch (error) {
        console.error('Camera access error:', error);
        statusEl.classList.add('hidden');
        startBtn.disabled = false;
        startBtn.innerHTML = '<i class="fas fa-video mr-2"></i>Start Camera';

        let msg = 'Could not access camera. ';
        if (error.name === 'NotAllowedError' || error.name === 'PermissionDeniedError') {
            msg += 'Please allow camera access in your browser settings.';
        } else if (error.name === 'NotFoundError' || error.name === 'DevicesNotFoundError') {
            msg += 'No camera found on this device.';
        } else if (error.name === 'NotReadableError' || error.name === 'TrackStartError') {
            msg += 'Camera is in use by another application.';
        } else {
            msg += error.message || 'Unknown error.';
        }
        errorEl.textContent = msg;
        errorEl.classList.remove('hidden');
    }
}

function detectFaceLoop() {
    if (!faceVideo || !faceCanvas || faceVideo.paused || faceVideo.ended) {
        return;
    }

    const ctx = faceCanvas.getContext('2d');
    const displaySize = { width: faceVideo.videoWidth, height: faceVideo.videoHeight };
    faceapi.matchDimensions(faceCanvas, displaySize);

    // Detect faces with landmarks and compute descriptor
    faceapi.detectAllFaces(faceVideo, new faceapi.TinyFaceDetectorOptions({
        inputSize: 320,
        scoreThreshold: 0.5,
    }))
    .withFaceLandmarks()
    .withFaceDescriptors()
    .then(detections => {
        // Clear canvas
        ctx.clearRect(0, 0, faceCanvas.width, faceCanvas.height);

        // Draw detection box for the first (largest) face
        if (detections.length > 0) {
            // Sort by face area (largest first)
            detections.sort((a, b) => {
                const areaA = a.detection.box.width * a.detection.box.height;
                const areaB = b.detection.box.width * b.detection.box.height;
                return areaB - areaA;
            });

            const bestDetection = detections[0];
            const resized = faceapi.resizeResults(bestDetection, displaySize);

            // Draw face box
            const box = resized.detection.box;
            ctx.strokeStyle = '#bf46c7';
            ctx.lineWidth = 3;
            ctx.strokeRect(box.x, box.y, box.width, box.height);

            // Draw landmarks
            ctx.fillStyle = '#bf46c7';
            resized.landmarks.positions.forEach(point => {
                ctx.beginPath();
                ctx.arc(point.x, point.y, 2, 0, 2 * Math.PI);
                ctx.fill();
            });

            // Store the best detection for capture
            faceDetection = bestDetection;
        } else {
            faceDetection = null;
        }

        // Continue loop
        requestAnimationFrame(detectFaceLoop);
    })
    .catch(err => {
        console.error('Detection error:', err);
        requestAnimationFrame(detectFaceLoop);
    });
}

function captureFaceDescriptor() {
    const captureBtn = document.getElementById('captureBtn');
    const retakeBtn = document.getElementById('retakeBtn');
    const saveActions = document.getElementById('saveActions');
    const errorEl = document.getElementById('faceEnrollError');
    const successEl = document.getElementById('faceEnrollSuccess');
    const overlay = document.getElementById('faceOverlay');

    errorEl.classList.add('hidden');
    successEl.classList.add('hidden');

    if (!faceDetection) {
        errorEl.textContent = 'No face detected. Position your face in the frame and try again.';
        errorEl.classList.remove('hidden');
        return;
    }

    // Get the 128-dim descriptor
    const descriptor = faceDetection.descriptor;
    if (!descriptor || descriptor.length !== 128) {
        errorEl.textContent = 'Failed to compute face descriptor. Please try again.';
        errorEl.classList.remove('hidden');
        return;
    }

    // Convert Float32Array to regular array for JSON serialization
    const descriptorArray = Array.from(descriptor);

    // Store in hidden input
    document.getElementById('faceDescriptorInput').value = JSON.stringify(descriptorArray);

    // Update UI
    captureBtn.classList.add('hidden');
    retakeBtn.classList.remove('hidden');
    saveActions.hidden = false;
    overlay.classList.remove('hidden');

    successEl.textContent = 'Face captured successfully! Review and click SAVE DESCRIPTOR.';
    successEl.classList.remove('hidden');
}

function retakeFaceEnrollment() {
    const captureBtn = document.getElementById('captureBtn');
    const retakeBtn = document.getElementById('retakeBtn');
    const saveActions = document.getElementById('saveActions');
    const errorEl = document.getElementById('faceEnrollError');
    const successEl = document.getElementById('faceEnrollSuccess');
    const overlay = document.getElementById('faceOverlay');

    // Reset form
    document.getElementById('faceDescriptorInput').value = '';
    faceDetection = null;

    errorEl.classList.add('hidden');
    successEl.classList.add('hidden');
    captureBtn.classList.remove('hidden');
    captureBtn.disabled = false;
    retakeBtn.classList.add('hidden');
    saveActions.hidden = true;
    overlay.classList.remove('hidden');
}

async function saveFaceDescriptor() {
    const saveBtn = document.getElementById('saveFaceBtn');
    const descriptorJson = document.getElementById('faceDescriptorInput').value;
    const errorEl = document.getElementById('faceEnrollError');
    const successEl = document.getElementById('faceEnrollSuccess');

    if (!descriptorJson) {
        errorEl.textContent = 'No face descriptor to save. Please capture your face first.';
        errorEl.classList.remove('hidden');
        return;
    }

    errorEl.classList.add('hidden');
    successEl.classList.add('hidden');

    saveBtn.disabled = true;
    saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Saving...';

    try {
        const body = new URLSearchParams();
        body.set('member_id', String(MEMBER_ID));
        body.set('face_descriptor', descriptorJson);
        body.set('csrf_token', CSRF_TOKEN);

        const response = await fetch('../actions/save_face_descriptor.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
            },
            body: body.toString(),
        });

        const data = await response.json().catch(() => ({ success: false, error: 'Unexpected server response.' }));

        if (data.success) {
            // Update badge in profile header
            const badge = document.getElementById('faceEnrollBadge');
            if (badge) {
                badge.textContent = 'Face Enrolled';
                badge.className = 'px-2 py-1 rounded-full text-[9px] font-bold uppercase whitespace-nowrap bg-green-500/20 text-green-400';
            }

            // Update the header enroll button
            const enrollBtn = document.getElementById('faceEnrollBtn');
            if (enrollBtn) {
                enrollBtn.title = 'Re-enroll face';
                enrollBtn.innerHTML = '<i class="fas fa-user-secret text-xs"></i> Re-enroll Face';
            }

            closeFaceEnrollModal();
            showNotification('Face descriptor saved successfully!', 'success');
        } else {
            errorEl.textContent = data.error || 'Failed to save face descriptor.';
            errorEl.classList.remove('hidden');
            saveBtn.disabled = false;
            saveBtn.innerHTML = '<i class="fas fa-save mr-2"></i>SAVE DESCRIPTOR';
        }
    } catch (error) {
        console.error('Save face descriptor error:', error);
        errorEl.textContent = 'Network error. Please try again.';
        errorEl.classList.remove('hidden');
        saveBtn.disabled = false;
        saveBtn.innerHTML = '<i class="fas fa-save mr-2"></i>SAVE DESCRIPTOR';
    }
}

// Close face enroll modal when backdrop is clicked
document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('faceEnrollModal');
    if (modal) {
        modal.addEventListener('mousedown', function (event) {
            if (event.target === modal) {
                closeFaceEnrollModal();
            }
        });
    }
});
</script>

</html>