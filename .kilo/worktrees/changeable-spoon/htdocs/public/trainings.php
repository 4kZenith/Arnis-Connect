<?php
require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/Trainings.php';

/*
 * config/permissions.php is the single RBAC source of truth. The inline matrix
 * that used to live here covered only 5 roles.
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

if (!canAccess('trainings', 'read')) {
    http_response_code(403);
    die('Access Denied: You do not have permission to view trainings.');
}

/* Flash messages written by process_add_training.php / update_training.php. */
$flashSuccess = $_SESSION['success'] ?? null;
$flashError = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);

/*
 * Every filter is validated before it reaches the model. Previously
 * ?search[]=x produced an array, which became '%Array%' in the LIKE clause.
 */
$search = (isset($_GET['search']) && is_string($_GET['search'])) ? trim($_GET['search']) : '';
$search = mb_substr($search, 0, 100);

$rawType = (isset($_GET['type']) && is_string($_GET['type'])) ? trim($_GET['type']) : '';
$type = in_array($rawType, ['Regular', 'Private', 'Custom'], true) ? $rawType : '';

$rawGroup = (isset($_GET['target_group']) && is_string($_GET['target_group'])) ? trim($_GET['target_group']) : '';
$targetGroup = in_array($rawGroup, ['Public', 'Private', 'Mixed'], true) ? $rawGroup : '';

$startDate = (isset($_GET['start_date']) && is_string($_GET['start_date'])) ? trim($_GET['start_date']) : '';
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) !== 1) {
    $startDate = '';
}

$endDate = (isset($_GET['end_date']) && is_string($_GET['end_date'])) ? trim($_GET['end_date']) : '';
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate) !== 1) {
    $endDate = '';
}

/* A reversed range silently returns nothing; swap it instead. */
if ($startDate !== '' && $endDate !== '' && $startDate > $endDate) {
    [$startDate, $endDate] = [$endDate, $startDate];
}

$filters = [
    'search' => $search,
    'type' => $type,
    'target_group' => $targetGroup,
    'start_date' => $startDate,
    'end_date' => $endDate,
];

$perPage = 10;
$requestedPage = (isset($_GET['page']) && is_numeric($_GET['page'])) ? max(1, (int)$_GET['page']) : 1;

try {
    $db = (new Database())->connect();
    $trainingsModel = new Trainings($db);

    $result = $trainingsModel->getAllPaginated($filters, $requestedPage, $perPage);
    $totalPages = max(1, (int)($result['totalPages'] ?? 1));

    /*
     * ?page=999 used to render "Page 999 of 1" over an empty table. Clamp, and
     * only re-query when the requested page was actually out of range.
     */
    $page = min($requestedPage, $totalPages);
    if ($page !== $requestedPage) {
        $result = $trainingsModel->getAllPaginated($filters, $page, $perPage);
    }

    $paginatedTrainings = $result['trainings'];
    $totalTrainings = (int)($result['total'] ?? 0);
} catch (Throwable $e) {
    error_log('trainings.php error: ' . $e->getMessage());
    die('Error loading training sessions. Please contact the administrator.');
}

/* Stats must never kill the list: fall back to zeros on failure. */
try {
    $stats = $trainingsModel->getStatsOptimized($filters);
} catch (Throwable $e) {
    error_log('trainings.php stats error: ' . $e->getMessage());
    $stats = ['total' => $totalTrainings, 'upcoming' => 0, 'public_sessions' => 0, 'private_sessions' => 0, 'cancelled' => 0];
}

$canWriteTrainings = canAccess('trainings', 'write');
$canVoidTrainings = canAccess('trainings', 'delete');

/* tr* prefix: this page includes modals that define their own helpers. */
function trE(?string $value, string $fallback = ''): string
{
    $value = trim((string)$value);
    return htmlspecialchars($value !== '' ? $value : $fallback, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** trainings.training_date is NOT NULL, but strtotime() can still return false. */
function trFormatDate(?string $date, string $fallback = 'TBA'): string
{
    $date = trim((string)$date);
    if ($date === '' || !preg_match('/^(\d{4}-\d{2}-\d{2})/', $date, $m)) {
        return $fallback;
    }
    if (str_starts_with($m[1], '0000-00-00')) {
        return $fallback;
    }
    $parsed = DateTime::createFromFormat('!Y-m-d', $m[1]);
    if ($parsed === false || DateTime::getLastErrors() !== false && (DateTime::getLastErrors()['warning_count'] > 0)) {
        return $fallback;
    }
    return $parsed->format('M d, Y');
}

/**
 * trainings.start_time / end_time are `time DEFAULT NULL`.
 * strtotime(null) is deprecated in PHP 8.1 and date('h:i A', false) renders the
 * epoch, i.e. "01:00 AM" in Asia/Manila, for every untimed session.
 */
function trFormatTime(?string $time, string $fallback = 'TBA'): string
{
    $time = trim((string)$time);
    if ($time === '') {
        return $fallback;
    }

    foreach (['H:i:s', 'H:i'] as $format) {
        $parsed = DateTime::createFromFormat('!' . $format, $time);
        $errors = DateTime::getLastErrors();
        $hasErrors = ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0));
        if ($parsed !== false && !$hasErrors) {
            return $parsed->format('h:i A');
        }
    }

    $timestamp = strtotime($time);
    return $timestamp === false ? $fallback : date('h:i A', $timestamp);
}

function trTypeClass(?string $type): string
{
    switch ($type) {
        case 'Private':
            return 'bg-blue-500/10 text-blue-400 border border-blue-500/20';
        case 'Custom':
            return 'bg-pink-500/10 text-pink-300 border border-pink-500/20';
        default:
            return 'bg-purple-500/10 text-purple-400 border border-purple-500/20';
    }
}

function trGroupClass(?string $group): string
{
    switch ($group) {
        case 'Private':
            return 'bg-yellow-500/10 text-yellow-300';
        case 'Mixed':
            return 'bg-pink-500/10 text-pink-300';
        default:
            return 'bg-green-500/10 text-green-300';
    }
}

function trTargetGroup(?string $group): string
{
    $group = trim((string)$group);
    return $group !== '' ? $group : 'Mixed';
}

function trFee(?string $amount): string
{
    return '₱' . number_format((float)$amount, 2, '.', ',');
}

/**
 * openVoidModal() is called from an HTML attribute, so the browser HTML-decodes
 * the attribute before the JavaScript runs. htmlspecialchars(..., ENT_QUOTES)
 * therefore does NOT protect a JS string literal; the HEX flags leave nothing
 * the HTML parser can act on.
 */
function trVoidOnclick(array $training): string
{
    $label = 'Training on ' . trFormatDate(
        isset($training['training_date']) ? (string)$training['training_date'] : null
    );

    return 'openVoidModal('
        . json_encode('Training', JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP)
        . ', ' . (int)($training['training_id'] ?? 0) . ', '
        . json_encode($label, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP)
        . ')';
}

/**
 * Edit Session payload for openEditTrainingModal().
 *
 * Every field modals/edit_training.php writes back must be present here: the
 * modal substitutes its own default for a missing key (Active-style defaults,
 * geofence 100m, empty lat/lng) and that default is what process_edit_training
 * would save over the real value. The HEX flags keep the JSON safe inside an
 * onclick attribute (HTML-decoded before the JS runs).
 */
function trEditOnclick(array $training): string
{
    $nullable = static function ($value) {
        return ($value === null || $value === '') ? null : $value;
    };

    $payload = [
        'training_id' => (int)($training['training_id'] ?? 0),
        'training_date' => (string)($training['training_date'] ?? ''),
        'training_type' => (string)($training['training_type'] ?? 'Regular'),
        'target_group' => (string)($training['target_group'] ?? 'Mixed'),
        'venue_type' => (string)($training['venue_type'] ?? 'Custom'),
        'start_time' => (string)($training['start_time'] ?? ''),
        'end_time' => (string)($training['end_time'] ?? ''),
        'location' => (string)($training['location'] ?? ''),
        'fee_amount' => $nullable($training['fee_amount'] ?? null),
        'coach_id' => (int)($training['coach_id'] ?? 0),
        'venue_lat' => $nullable($training['venue_lat'] ?? null),
        'venue_lng' => $nullable($training['venue_lng'] ?? null),
        'geofence_m' => $nullable($training['geofence_m'] ?? null),
        'description' => (string)($training['description'] ?? ''),
    ];

    $json = json_encode(
        $payload,
        JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if ($json === false) {
        return 'void 0';
    }

    return 'openEditTrainingModal(' . $json . ')';
}

/** trainings.description is free text; ENT_QUOTES is mandatory in title="". */
function trDescriptionCell(?string $description): string
{
    $description = trim((string)$description);
    if ($description === '') {
        return '<span class="text-gray-600">—</span>';
    }
    $escaped = htmlspecialchars($description, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return '<div class="truncate text-gray-500 italic" title="' . $escaped . '">' . $escaped . '</div>';
}

$pageLink = static function (int $target) use ($filters): string {
    $query = array_filter($filters, static fn($value) => $value !== '');
    $query['page'] = $target;
    return '?' . http_build_query($query);
};

$hasActiveFilters = ($search !== '' || $type !== '' || $targetGroup !== '' || $startDate !== '' || $endDate !== '');
?>

<!DOCTYPE html>
<html lang="en" class="text-[13px]">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trainings | Arnis Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" type="image/x-icon" href="../arnis-favicon.ico">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,100..900;1,100..900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
    <link rel="stylesheet" href="css/sidebar.css">
    <link rel="stylesheet" href="css/style.css">
    <style>
        #addMap, #editMap { z-index: 10; }
        .leaflet-container { background: #1d212a; }
    </style>
</head>
<body class="text-white bg-dark-purple overflow-x-hidden">
<div class="flex">
    <?php include_once __DIR__ . '/../includes/sidebar.php'; ?>
    <main id="page" class="flex-1 p-4 sm:p-6 lg:p-8 h-screen overflow-y-auto opacity-0 translate-x-8 transition-all duration-500 ease-out">
        <?php include_once __DIR__ . '/../includes/header.php'; ?>

        <?php if ($flashSuccess !== null || $flashError !== null): ?>
        <div class="mb-6 p-4 rounded-2xl border text-sm font-semibold <?= $flashError !== null ? 'bg-red-500/10 border-red-500/20 text-red-300' : 'bg-emerald-500/10 border-emerald-500/20 text-emerald-300' ?>">
            <i class="fas <?= $flashError !== null ? 'fa-exclamation-triangle' : 'fa-check-circle' ?> mr-2"></i>
            <?= trE($flashError !== null ? (string)$flashError : (string)$flashSuccess) ?>
        </div>
        <?php endif; ?>

        <div class="grid grid-cols-2 lg:grid-cols-5 gap-3 sm:gap-4 lg:gap-6 mb-6 lg:mb-8">
            <div class="card-bg p-4 sm:p-6 rounded-2xl sm:rounded-3xl text-center shadow-xl">
                <i class="fas fa-calendar-alt text-xl sm:text-2xl mb-2 opacity-50"></i>
                <div class="text-2xl sm:text-3xl lg:text-4xl font-bold" data-stat-total><?= (int)($stats['total'] ?? 0) ?></div>
                <div class="text-[10px] sm:text-xs text-gray-400 uppercase tracking-widest mt-1">Total Sessions</div>
            </div>
            <div class="card-bg p-4 sm:p-6 rounded-2xl sm:rounded-3xl text-center shadow-xl">
                <i class="fas fa-clock text-xl sm:text-2xl mb-2 text-blue-400 opacity-80"></i>
                <div class="text-2xl sm:text-3xl lg:text-4xl font-bold" data-stat-upcoming><?= (int)($stats['upcoming'] ?? 0) ?></div>
                <div class="text-[10px] sm:text-xs text-gray-400 uppercase tracking-widest mt-1">Upcoming</div>
            </div>
            <div class="card-bg p-4 sm:p-6 rounded-2xl sm:rounded-3xl text-center shadow-xl">
                <i class="fas fa-school text-xl sm:text-2xl mb-2 text-green-400 opacity-80"></i>
                <div class="text-2xl sm:text-3xl lg:text-4xl font-bold" data-stat-public><?= (int)($stats['public_sessions'] ?? 0) ?></div>
                <div class="text-[10px] sm:text-xs text-gray-400 uppercase tracking-widest mt-1">Public Target</div>
            </div>
            <div class="card-bg p-4 sm:p-6 rounded-2xl sm:rounded-3xl text-center shadow-xl">
                <i class="fas fa-user-lock text-xl sm:text-2xl mb-2 text-yellow-400 opacity-80"></i>
                <div class="text-2xl sm:text-3xl lg:text-4xl font-bold" data-stat-private><?= (int)($stats['private_sessions'] ?? 0) ?></div>
                <div class="text-[10px] sm:text-xs text-gray-400 uppercase tracking-widest mt-1">Private Target</div>
            </div>
            <div class="card-bg p-4 sm:p-6 rounded-2xl sm:rounded-3xl text-center shadow-xl">
                <i class="fas fa-exclamation-triangle text-xl sm:text-2xl mb-2 text-red-500 opacity-80"></i>
                <div class="text-2xl sm:text-3xl lg:text-4xl font-bold" data-stat-cancelled><?= (int)($stats['cancelled'] ?? 0) ?></div>
                <div class="text-[10px] sm:text-xs text-gray-400 uppercase tracking-widest mt-1">Voided</div>
            </div>
        </div>

        <!-- RESPONSIVE: Filters are a plain GET form so the query string drives
             the stat cards, both view variants and the pagination links. -->
        <form method="get" action="trainings.php" id="trainingsFilterForm"
              class="flex flex-col xl:flex-row justify-between items-stretch xl:items-center mb-6 gap-4">
            <div class="flex flex-col sm:flex-row flex-wrap gap-2 sm:gap-3 flex-1">
                <input type="search" name="search" id="searchInput"
                       placeholder="Search training, location, coach..."
                       value="<?= trE($search) ?>"
                       autocomplete="off"
                       class="card-bg rounded-full px-4 py-3 text-xs sm:text-sm outline-none w-full sm:w-56">
                <select name="type" id="filterType" class="card-bg border-none rounded-full px-4 py-3 text-xs sm:text-sm outline-none text-center">
                    <option value="">All Types</option>
                    <option value="Regular"<?= $type === 'Regular' ? ' selected' : '' ?>>Regular</option>
                    <option value="Private"<?= $type === 'Private' ? ' selected' : '' ?>>Private</option>
                    <option value="Custom"<?= $type === 'Custom' ? ' selected' : '' ?>>Custom</option>
                </select>
                <select name="target_group" id="targetGroupFilter" class="card-bg border-none rounded-full px-4 py-3 text-xs sm:text-sm outline-none text-center">
                    <option value="">All Player Groups</option>
                    <option value="Public"<?= $targetGroup === 'Public' ? ' selected' : '' ?>>Public</option>
                    <option value="Private"<?= $targetGroup === 'Private' ? ' selected' : '' ?>>Private</option>
                    <option value="Mixed"<?= $targetGroup === 'Mixed' ? ' selected' : '' ?>>Mixed</option>
                </select>
                <input type="date" name="start_date" id="startDate" value="<?= trE($startDate) ?>"
                       class="card-bg border-none rounded-full px-4 py-3 text-xs sm:text-sm outline-none text-center">
                <input type="date" name="end_date" id="endDate" value="<?= trE($endDate) ?>"
                       class="card-bg border-none rounded-full px-4 py-3 text-xs sm:text-sm outline-none text-center">
            </div>
            <div class="flex gap-2">
                <button type="submit" class="bg-accent-purple hover:opacity-90 px-5 py-4 rounded-full font-bold text-xs sm:text-sm whitespace-nowrap transition">
                    <i class="fas fa-filter mr-1"></i> Apply
                </button>
                <?php if ($hasActiveFilters): ?>
                <a href="trainings.php" class="bg-white/5 hover:bg-white/10 px-5 py-4 rounded-full font-bold text-xs sm:text-sm whitespace-nowrap transition">
                    <i class="fas fa-redo mr-1"></i> Reset
                </a>
                <?php endif; ?>
                <button type="button" onclick="openTrainingModal()"
                        class="px-5 sm:px-6 py-4 rounded-full font-bold text-xs sm:text-sm whitespace-nowrap transition <?= $canWriteTrainings ? 'bg-accent-purple hover:opacity-90 cursor-pointer' : 'bg-gray-600 opacity-50 cursor-not-allowed' ?>"
                        <?= $canWriteTrainings ? '' : 'disabled' ?>>
                    + Schedule Training
                </button>
            </div>
        </form>

        <div class="card-bg rounded-2xl sm:rounded-3xl overflow-hidden shadow-xl">
            <div class="block lg:hidden">
                <div class="p-2 space-y-2" id="mobileTrainingsView">
                    <?php if (empty($paginatedTrainings)): ?>
                        <div class="p-6 text-center text-gray-500">No training sessions found.</div>
                    <?php else: ?>
                        <?php foreach ($paginatedTrainings as $training): ?>
                            <div class="bg-white/[0.02] rounded-xl p-4 border border-gray-800/50">
                                <div class="flex justify-between items-start mb-3 gap-3">
                                    <div>
                                        <div class="font-bold text-sm mb-1"><?= trE(trFormatDate($training['training_date'] ?? null)) ?></div>
                                        <div class="text-[10px] text-gray-400">
                                            <?= trE(trFormatTime($training['start_time'] ?? null)) ?> - <?= trE(trFormatTime($training['end_time'] ?? null)) ?>
                                        </div>
                                    </div>
                                    <span class="px-2 py-1 rounded-full text-[9px] font-bold <?= trTypeClass($training['training_type'] ?? null) ?>">
                                        <?= trE(strtoupper(trim((string)($training['training_type'] ?? ''))), 'REGULAR') ?>
                                    </span>
                                </div>
                                <div class="flex flex-wrap gap-2 mb-3">
                                    <span class="px-2 py-1 rounded-full text-[9px] font-bold <?= trGroupClass($training['target_group'] ?? null) ?>"><?= trE(trTargetGroup($training['target_group'] ?? null)) ?></span>
                                    <span class="px-2 py-1 rounded-full text-[9px] font-bold bg-white/5 text-gray-300"><?= trE($training['venue_type'] ?? null, 'Custom') ?></span>
                                    <span class="px-2 py-1 rounded-full text-[9px] font-bold bg-blue-500/10 text-blue-300"><?= trE(trFee($training['fee_amount'] ?? 0)) ?></span>
                                </div>
                                <div class="text-[10px] text-gray-400 space-y-1 mb-3">
                                    <div><i class="fas fa-map-marker-alt w-4"></i> <?= trE($training['location'] ?? null, 'N/A') ?></div>
                                    <div><i class="fas fa-user-tie w-4"></i> <?= trE($training['coach_name'] ?? null, 'Unassigned') ?></div>
                                </div>
                                <?php if (trim((string)($training['description'] ?? '')) !== ''): ?>
                                    <div class="text-[10px] text-gray-500 italic mb-3 line-clamp-2"><?= trE($training['description'] ?? null) ?></div>
                                <?php endif; ?>
                                <div class="flex gap-2">
                                    <a href="attendance_session.php?id=<?= (int)($training['training_id'] ?? 0) ?>" class="flex-1 bg-blue-500/10 text-blue-400 text-center py-2 rounded-lg text-[10px] font-bold hover:bg-blue-500 hover:text-white transition">VIEW SESSION</a>
                                    <?php if ($canWriteTrainings): ?>
                                        <button type="button" onclick="<?= trE(trEditOnclick($training)) ?>"
                                                class="flex-1 bg-white/5 text-gray-300 py-2 rounded-lg text-[10px] font-bold hover:bg-purple-600 hover:text-white transition"
                                                title="Edit session">
                                            <i class="fas fa-pen"></i> EDIT
                                        </button>
                                    <?php endif; ?>
                                    <button type="button" onclick="<?= trE(trVoidOnclick($training)) ?>"
                                            class="w-10 text-xs transition rounded-lg <?= $canVoidTrainings ? 'bg-white/5 hover:bg-orange-500/20 text-gray-500 hover:text-orange-500 cursor-pointer' : 'bg-gray-700 text-gray-700 opacity-50 cursor-not-allowed' ?>"
                                            title="<?= $canVoidTrainings ? 'Void session' : 'No permission to void training sessions' ?>"
                                            <?= $canVoidTrainings ? '' : 'disabled' ?>>
                                        <i class="fas fa-ban"></i>
                                    </button>
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
                            <th class="p-4 lg:p-6">Date</th>
                            <th class="p-4 lg:p-6">Time</th>
                            <th class="p-4 lg:p-6">Type</th>
                            <th class="p-4 lg:p-6">Target Group</th>
                            <th class="p-4 lg:p-6">Venue</th>
                            <th class="p-4 lg:p-6">Fee</th>
                            <th class="p-4 lg:p-6">Location</th>
                            <th class="p-4 lg:p-6">Coach</th>
                            <th class="p-4 lg:p-6">Notes</th>
                            <th class="p-4 lg:p-6 text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="desktopTrainingsBody">
                        <?php if (empty($paginatedTrainings)): ?>
                            <tr><td colspan="10" class="p-6 text-center text-gray-500">No training sessions found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($paginatedTrainings as $training): ?>
                                <tr class="border-b border-gray-800 hover:bg-white/5 transition">
                                    <td class="p-4 lg:p-6 font-semibold whitespace-nowrap"><?= trE(trFormatDate($training['training_date'] ?? null)) ?></td>
                                    <td class="p-4 lg:p-6 text-gray-300 whitespace-nowrap"><?= trE(trFormatTime($training['start_time'] ?? null)) ?> - <?= trE(trFormatTime($training['end_time'] ?? null)) ?></td>
                                    <td class="p-4 lg:p-6"><span class="px-3 py-1 rounded-full text-[10px] font-bold <?= trTypeClass($training['training_type'] ?? null) ?>"><?= trE($training['training_type'] ?? null, 'Regular') ?></span></td>
                                    <td class="p-4 lg:p-6"><span class="px-2 py-1 rounded-full text-xs font-semibold <?= trGroupClass($training['target_group'] ?? null) ?>"><?= trE(trTargetGroup($training['target_group'] ?? null)) ?></span></td>
                                    <td class="p-4 lg:p-6 text-xs text-gray-400"><?= trE($training['venue_type'] ?? null, 'Custom') ?></td>
                                    <td class="p-4 lg:p-6 whitespace-nowrap"><?= trE(trFee($training['fee_amount'] ?? 0)) ?></td>
                                    <td class="p-4 lg:p-6"><?= trE($training['location'] ?? null, 'N/A') ?></td>
                                    <td class="p-4 lg:p-6 whitespace-nowrap"><?= trE($training['coach_name'] ?? null, 'Unassigned') ?></td>
                                    <td class="p-4 lg:p-6 max-w-[220px]"><?= trDescriptionCell($training['description'] ?? null) ?></td>
                                    <td class="p-4 lg:p-6 text-center space-x-4 whitespace-nowrap">
                                        <a href="attendance_session.php?id=<?= (int)($training['training_id'] ?? 0) ?>" class="bg-blue-500/10 text-blue-400 px-4 py-2 rounded-full text-xs font-bold hover:bg-blue-500 hover:text-white transition inline-block">VIEW SESSION</a>
                                        <?php if ($canWriteTrainings): ?>
                                            <button type="button" onclick="<?= trE(trEditOnclick($training)) ?>"
                                                    class="bg-purple-500/10 text-purple-300 px-4 py-2 rounded-full text-xs font-bold hover:bg-purple-600 hover:text-white transition"
                                                    title="Edit session">
                                                <i class="fas fa-pen"></i> EDIT
                                            </button>
                                        <?php endif; ?>
                                        <button type="button" onclick="<?= trE(trVoidOnclick($training)) ?>"
                                                class="transition text-xs <?= $canVoidTrainings ? 'text-gray-600 hover:text-orange-500 cursor-pointer' : 'text-gray-700 opacity-50 cursor-not-allowed' ?>"
                                                title="<?= $canVoidTrainings ? 'Void session' : 'No permission to void training sessions' ?>"
                                                <?= $canVoidTrainings ? '' : 'disabled' ?>>
                                            <i class="fas fa-ban"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPages > 1): ?>
            <div id="trainingsPagination" class="px-4 py-4 border-t border-gray-800 flex justify-between items-center flex-wrap gap-3">
                <div class="text-[10px] text-gray-500 uppercase" data-pagination-info>
                    Showing <?= $totalTrainings > 0 ? min((($page - 1) * $perPage) + 1, $totalTrainings) : 0 ?> to <?= min($page * $perPage, $totalTrainings) ?> of <?= $totalTrainings ?> sessions
                </div>
                <div class="flex gap-2">
                    <?php if ($page > 1): ?>
                    <a href="<?= trE($pageLink(1)) ?>" class="bg-white/5 hover:bg-white/10 px-3 py-2 rounded-lg text-[10px] font-bold transition"><i class="fas fa-chevron-left mr-1"></i>First</a>
                    <a href="<?= trE($pageLink($page - 1)) ?>" class="bg-white/5 hover:bg-white/10 px-3 py-2 rounded-lg text-[10px] font-bold transition"><i class="fas fa-chevron-left mr-1"></i>Prev</a>
                    <?php endif; ?>
                    <span class="px-3 py-2 text-[10px] font-bold text-gray-400">Page <?= (int)$page ?> of <?= (int)$totalPages ?></span>
                    <?php if ($page < $totalPages): ?>
                    <a href="<?= trE($pageLink($page + 1)) ?>" class="bg-white/5 hover:bg-white/10 px-3 py-2 rounded-lg text-[10px] font-bold transition">Next<i class="fas fa-chevron-right ml-1"></i></a>
                    <a href="<?= trE($pageLink($totalPages)) ?>" class="bg-white/5 hover:bg-white/10 px-3 py-2 rounded-lg text-[10px] font-bold transition">Last<i class="fas fa-chevron-right ml-1"></i></a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

    </main>
</div>

<?php include_once __DIR__ . '/modals/add_training.php'; ?>
<?php include_once __DIR__ . '/modals/edit_training.php'; ?>
<?php include_once __DIR__ . '/modals/void_confirmation.php'; ?>
<?php include_once __DIR__ . '/modals/user_menu.php'; ?>
<?php include_once __DIR__ . '/modals/edit_profile_user.php'; ?>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<?php
/*
 * Optional Google Places key for the venue map's search box
 * (config/google_maps_key.php). Empty string = free built-in search.
 */
$arnisGoogleMapsKey = '';
$arnisMapsKeyFile = __DIR__ . '/../config/google_maps_key.php';
if (is_file($arnisMapsKeyFile)) {
    $arnisGoogleMapsKey = (string) (require $arnisMapsKeyFile);
}
?>
<script>window.ARNIS_GOOGLE_MAPS_KEY = <?= json_encode($arnisGoogleMapsKey, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script src="js/location-picker.js?v=3"></script>
<script>
window.addEventListener("load", () => {
    document.getElementById("page").classList.remove("opacity-0", "translate-x-8");
});

/*
 * Filters submit via Apply button or Enter key only. The previous
 * auto-submit on every keystroke (350ms debounce) reloaded the page per
 * letter and any transient query failure rendered the full-page
 * "Error loading training sessions" die() — perceived as "search is broken".
 */
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('trainingsFilterForm');
    if (!form) return;

    // Any filter change resets to page 1.
    form.addEventListener('submit', function () {
        const url = new URL(window.location.href);
        url.searchParams.delete('page');
        history.replaceState({}, '', url.toString());
    });
});
</script>
</body>
</html>