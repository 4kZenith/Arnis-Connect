<?php
require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/Trainings.php';
require_once __DIR__ . '/../classes/Attendance.php';

/*
 * config/permissions.php is the single RBAC source of truth. The inline matrix
 * that used to live here covered only 5 roles across 2 modules.
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

/*
 * Members reach this page only to self check-in with face + geolocation, never
 * to edit another athlete's record: $selfCheckinMode hides every staff control
 * and scopes the roster to the viewer's own row. Applicants have no
 * trainings.read, so they are already stopped by the gate above.
 */
$attendanceScope = permissionValue('attendance', 'read');
if ($attendanceScope === false) {
    http_response_code(403);
    die('Access Denied: You do not have permission to view this attendance session.');
}
$selfCheckinMode = ($attendanceScope === 'own');
/* Resolved after the DB connection below (session link, then name fallback). */
$viewerMemberId = 0;
/* Filled in below once the roster is loaded; drives the header + stats card. */
$ownAttendance = null;

/* Flash messages written by update_attendance.php / remove_from_training.php. */
$flashSuccess = $_SESSION['success'] ?? null;
$flashError = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);

/*
 * CRITICAL: this was passed to Trainings::getById(int $trainingId) straight
 * from $_GET, so ?id=abc or ?id[]=1 raised a TypeError and returned HTTP 500
 * with a blank page.
 */
$rawId = $_GET['id'] ?? null;
if (!is_string($rawId) || !is_numeric($rawId) || (int)$rawId <= 0) {
    $_SESSION['error'] = 'Invalid training session reference.';
    header('Location: trainings.php');
    exit;
}

$trainingId = (int)$rawId;

$requestedPage = (isset($_GET['page']) && is_numeric($_GET['page'])) ? max(1, (int)$_GET['page']) : 1;
$perPage = 10;

try {
    $db = (new Database())->connect();
    $trainingsModel = new Trainings($db);
    $attendanceModel = new Attendance($db);

    if ($selfCheckinMode) {
        $viewerMemberId = function_exists('resolveSessionMemberId') ? resolveSessionMemberId($db) : 0;
    }

    $training = $trainingsModel->getById($trainingId);
    if ($training === null) {
        // Render error inline instead of redirecting (redirect can fail if headers already sent)
        $trainingNotFound = true;
    } else {
        $trainingNotFound = false;
        $totalAttendance = (int)$attendanceModel->countByTrainingId($trainingId);
        $totalPages = $totalAttendance > 0 ? (int)ceil($totalAttendance / $perPage) : 1;

        /* ?page=999 used to render an empty roster labelled "Page 999 of 1". */
        $page = min($requestedPage, max(1, $totalPages));
        $offset = ($page - 1) * $perPage;

        $attendanceList = $attendanceModel->getByTrainingId($trainingId, $perPage, $offset);
        if (!is_array($attendanceList)) {
            $attendanceList = [];
        }

        $stats = $attendanceModel->getAttendanceStats($trainingId);
        if (!is_array($stats)) {
            $stats = [];
        }
        $presentCount = (int)($stats['present_count'] ?? 0);
        $lateCount = (int)($stats['late_count'] ?? 0);
        $absentCount = (int)($stats['absent_count'] ?? 0);

        $availableMembers = $attendanceModel->getMembersNotInTraining($trainingId);
        if (!is_array($availableMembers)) {
            $availableMembers = [];
        }

        /*
         * attendance.read = 'own' (Member): scope the roster to the viewer's
         * own record so no other athlete's attendance is exposed, and drop the
         * staff-only "eligible to add" list. attendance.read = 'own_trainees'
         * (Coach) keeps the full roster.
         */
        if ($selfCheckinMode) {
            $availableMembers = [];
            $ownRows = [];
            if ($viewerMemberId > 0) {
                foreach ($attendanceModel->getByTrainingId($trainingId) as $row) {
                    if ((int)($row['member_id'] ?? 0) === $viewerMemberId) {
                        $ownRows[] = $row;
                        break;
                    }
                }
            }
            $attendanceList = $ownRows;
            $ownAttendance = $ownRows[0] ?? null;
            $totalAttendance = count($ownRows);
            $totalPages = 1;
            $page = 1;
            $offset = 0;
        }
    }
} catch (Throwable $e) {
    error_log('attendance_session.php error: ' . $e->getMessage());
    die('Error loading the training session. Please contact the administrator.');
}

// Default values when training not found
if (isset($trainingNotFound) && $trainingNotFound) {
    $totalAttendance = 0;
    $totalPages = 1;
    $page = 1;
    $offset = 0;
    $attendanceList = [];
    $presentCount = 0;
    $lateCount = 0;
    $absentCount = 0;
    $availableMembers = [];
    // Create a minimal training array for the "Back" link and page title
    $training = [
        'training_id' => $trainingId,
        'location' => 'Unknown',
        'training_type' => 'Regular',
        'target_group' => 'Mixed',
        'venue_type' => 'Custom',
        'fee_amount' => 0,
        'start_time' => null,
        'end_time' => null,
        'description' => '',
        'coach_name' => null,
        'coach_rank' => null,
        'coach_type' => null,
        'created_at' => null,
        'updated_at' => null,
    ];
}

/*
 * Marking + roster changes are staff-only (Admin/Officer full write, Coach
 * own trainees). Member holds attendance.write = 'own' (self check-in only,
 * via the upcoming face + GPS flow), which canAccess() alone reports as
 * granted — so the strict scope check below is what actually disables every
 * Present/Late/Absent/Add/Remove control for Members.
 */
$staffAttendanceWrite = in_array(permissionValue('attendance', 'write'), [true, 'own_trainees'], true);
$canWriteAttendance = $staffAttendanceWrite;
$canDeleteAttendance = canAccess('attendance', 'delete');
$canWriteTraining = canAccess('trainings', 'write');
$csrfToken = arnisCsrfToken();

/* as* prefix: this page includes modals that define their own helpers. */
function asE(?string $value, string $fallback = ''): string
{
    $value = trim((string)$value);
    return htmlspecialchars($value !== '' ? $value : $fallback, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function asFormatDate(?string $date, string $fallback = 'TBA'): string
{
    $date = trim((string)$date);
    if ($date === '' || !preg_match('/^(\d{4}-\d{2}-\d{2})/', $date, $m) || str_starts_with($m[1], '0000-00-00')) {
        return $fallback;
    }
    $parsed = DateTime::createFromFormat('!Y-m-d', $m[1]);
    $errors = DateTime::getLastErrors();
    if ($parsed === false || ($errors !== false && $errors['warning_count'] > 0)) {
        return $fallback;
    }
    return $parsed->format('M d, Y');
}

/** trainings.start_time / end_time are `time DEFAULT NULL`. */
function asFormatTime(?string $time, string $fallback = 'TBA'): string
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

function asTypeClass(?string $type): string
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

function asGroupClass(?string $group): string
{
    switch ($group) {
        case 'Private':
            return 'text-yellow-300';
        case 'Mixed':
            return 'text-pink-300';
        default:
            return 'text-green-300';
    }
}

function asInitials(?string $name): string
{
    $name = trim((string)$name);
    if ($name === '') {
        return '??';
    }
    $parts = preg_split('/\s+/', $name) ?: [];
    if (count($parts) >= 2) {
        return strtoupper(substr($parts[0], 0, 1) . substr($parts[1], 0, 1));
    }
    return strtoupper(substr($name, 0, 2));
}

/**
 * removeMember(trainingId, memberId, name)
 *
 * The old code used addslashes($full_name) inside onclick="...". addslashes
 * emits a backslash-quote, but the HTML parser terminates a double-quoted
 * attribute on a raw quote and ignores the backslash entirely, so a member name
 * containing " broke out of the attribute - stored XSS reachable through the
 * member form. json_encode with the HEX flags leaves nothing to break out with.
 */
function asRemoveOnclick(int $trainingId, int $memberId, ?string $name, bool $allowed): string
{
    if (!$allowed) {
        return 'return false;';
    }

    return 'removeMember(' . $trainingId . ', ' . $memberId . ', '
        . json_encode(trim((string)$name), JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP)
        . ')';
}

function asStatusOnclick(int $trainingId, int $memberId, string $status, bool $allowed): string
{
    if (!$allowed) {
        return 'return false;';
    }
    return 'updateAttendance(' . $trainingId . ', ' . $memberId . ', '
        . json_encode($status, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP)
        . ')';
}

$sessionPageLink = static function (int $target) use ($trainingId): string {
    return 'attendance_session.php?id=' . $trainingId . '&page=' . $target;
};
?>

<!DOCTYPE html>
<html lang="en" class="text-[13px]">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Training Details | Arnis Portal</title>
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
        #editMap { z-index: 10; }
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
                <?= asE($flashError !== null ? (string)$flashError : (string)$flashSuccess) ?>
            </div>
            <?php endif; ?>

            <?php if (isset($trainingNotFound) && $trainingNotFound): ?>
            <div class="mb-6 p-6 rounded-2xl border bg-red-500/10 border-red-500/20 text-red-300 text-center">
                <i class="fas fa-exclamation-triangle text-3xl mb-3"></i>
                <h2 class="text-lg font-bold mb-2">Training Session Not Found</h2>
                <p class="text-sm mb-4">The training session you're looking for (ID: #TRN-<?= str_pad((string)(int)$trainingId, 4, '0', STR_PAD_LEFT) ?>) does not exist or has been voided.</p>
                <a href="trainings.php" class="inline-flex items-center gap-2 px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-full text-sm font-bold transition">
                    <i class="fas fa-arrow-left"></i> Back to Trainings
                </a>
            </div>
            <?php endif; ?>

            <!-- Header -->
            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 sm:mb-8 gap-4">
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 sm:mb-8 gap-4 w-full">
                    <div class="flex items-center gap-3 sm:gap-4">
                        <a href="trainings.php" class="card-bg w-8 h-8 sm:w-10 sm:h-10 rounded-full flex items-center justify-center hover:bg-gray-800 transition flex-shrink-0">
                            <i class="fas fa-arrow-left text-xs sm:text-sm"></i>
                        </a>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-3">
                                <h1 class="text-lg sm:text-xl lg:text-2xl font-bold italic uppercase tracking-wider truncate">
                                    <?= asE($training['location'] ?? null, 'Training Session') ?>
                                </h1>
                                <span class="<?= asTypeClass($training['training_type'] ?? null) ?> px-2 sm:px-3 py-1 rounded-full text-[9px] sm:text-[10px] font-bold uppercase border w-fit">
                                    <?= asE(strtoupper(trim((string)($training['training_type'] ?? ''))), 'REGULAR') ?>
                                </span>
                            </div>
                            <p class="text-[9px] sm:text-[10px] text-gray-500 uppercase tracking-widest mt-1">Training ID: #TRN-<?= str_pad((string)(int)$trainingId, 4, '0', STR_PAD_LEFT) ?></p>
                        </div>
                    </div>

                    <?php if ($selfCheckinMode): ?>
                        <?php if (!(isset($trainingNotFound) && $trainingNotFound)): ?>
                            <?php if ($ownAttendance !== null && (string)($ownAttendance['status'] ?? '') !== 'Absent'): ?>
                            <span class="px-4 py-2 rounded-full text-[10px] font-bold uppercase tracking-wider flex items-center gap-2 whitespace-nowrap bg-emerald-500/15 border border-emerald-500/30 text-emerald-300">
                                <i class="fas fa-check-circle text-xs"></i>
                                Checked In: <?= asE((string)($ownAttendance['status'] ?? ''), 'Recorded') ?>
                            </span>
                            <?php elseif ($viewerMemberId > 0): ?>
                            <button type="button"
                                    onclick="openCheckinModal(<?= (int)$trainingId ?>, <?= (int)$viewerMemberId ?>)"
                                    class="px-4 py-2 rounded-full text-[10px] font-bold uppercase tracking-wider transition flex items-center gap-2 whitespace-nowrap bg-gradient-to-r from-purple-600 to-pink-600 hover:from-purple-700 hover:to-pink-700 text-white shadow-lg shadow-purple-900/30 cursor-pointer">
                                <i class="fas fa-user-check text-xs"></i>
                                Check In: Face + GPS
                            </button>
                            <?php else: ?>
                            <span class="px-4 py-2 rounded-full text-[10px] font-bold uppercase tracking-wider flex items-center gap-2 whitespace-nowrap bg-amber-500/15 border border-amber-500/30 text-amber-300">
                                <i class="fas fa-link-slash text-xs"></i>
                                Account Not Linked
                            </span>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php else: ?>
                    <!-- Edit Button. modals/edit_training.php reads $training and
                         calls $trainingsModel->getCoaches() itself, so both
                         variables stay in scope here. -->
                    <button type="button" onclick="openEditTrainingModal()"
                            class="px-4 py-2 rounded-full text-[10px] font-bold uppercase tracking-wider transition flex items-center gap-2 whitespace-nowrap <?= $canWriteTraining ? 'bg-purple-600/20 hover:bg-purple-600/30 border border-purple-500/30 text-purple-300 hover:text-purple-200 cursor-pointer' : 'bg-gray-700/20 border border-gray-700/30 text-gray-600 cursor-not-allowed' ?>"
                            <?= $canWriteTraining ? '' : 'disabled' ?>
                            title="<?= $canWriteTraining ? 'Edit this training session' : 'No permission to edit trainings' ?>">
                        <i class="fas fa-edit text-xs"></i>
                        Edit Session
                    </button>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!isset($trainingNotFound) || !$trainingNotFound): ?>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 sm:gap-6 lg:gap-8">

                <!-- Session Info -->
                <div class="lg:col-span-1 space-y-4 sm:space-y-6">
                    <div class="card-bg p-6 sm:p-8 rounded-2xl sm:rounded-3xl">
                        <h3 class="text-gray-500 text-[10px] font-black uppercase tracking-[0.2em] mb-4 sm:mb-6">Session Info</h3>

                        <div class="space-y-4 sm:space-y-6">
                            <div class="flex items-center gap-3 sm:gap-4">
                                <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-lg sm:rounded-xl bg-blue-500/10 flex items-center justify-center text-blue-400 flex-shrink-0">
                                    <i class="fas fa-calendar-day text-xs sm:text-sm"></i>
                                </div>
                                <div>
                                    <div class="text-xs sm:text-sm font-bold"><?= asE(asFormatDate($training['training_date'] ?? null)) ?></div>
                                    <div class="text-[9px] sm:text-[10px] text-gray-500 uppercase font-medium">Date</div>
                                </div>
                            </div>

                            <div class="flex items-center gap-3 sm:gap-4">
                                <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-lg sm:rounded-xl bg-green-500/10 flex items-center justify-center text-green-400 flex-shrink-0">
                                    <i class="fas fa-clock text-xs sm:text-sm"></i>
                                </div>
                                <div>
                                    <div class="text-xs sm:text-sm font-bold">
                                        <?= asE(asFormatTime($training['start_time'] ?? null)) ?> - <?= asE(asFormatTime($training['end_time'] ?? null)) ?>
                                    </div>
                                    <div class="text-[9px] sm:text-[10px] text-gray-500 uppercase font-medium">Schedule</div>
                                </div>
                            </div>

                            <div class="flex items-center gap-3 sm:gap-4">
                                <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-lg sm:rounded-xl bg-orange-500/10 flex items-center justify-center text-orange-400 flex-shrink-0">
                                    <i class="fas fa-map-marker-alt text-xs sm:text-sm"></i>
                                </div>
                                <div>
                                    <div class="text-xs sm:text-sm font-bold"><?= asE($training['location'] ?? null, 'N/A') ?></div>
                                    <div class="text-[9px] sm:text-[10px] text-gray-500 uppercase font-medium">Location</div>
                                </div>
                            </div>

                            <div class="flex items-center gap-3 sm:gap-4">
                                <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-lg sm:rounded-xl bg-purple-500/10 flex items-center justify-center text-purple-400 flex-shrink-0">
                                    <i class="fas fa-user-tie text-xs sm:text-sm"></i>
                                </div>
                                <div>
                                    <!-- coach_name comes from a LEFT JOIN, so it is
                                         NULL whenever no coach is assigned. -->
                                    <div class="text-xs sm:text-sm font-bold"><?= asE($training['coach_name'] ?? null, 'Unassigned') ?></div>
                                    <div class="text-[9px] sm:text-[10px] text-gray-500 uppercase font-medium">Lead Coach</div>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-2">
                                <div class="bg-black/20 p-3 rounded-xl border border-gray-800">
                                    <div class="text-[9px] sm:text-[10px] text-gray-500 uppercase font-medium mb-1">Target Group</div>
                                    <div class="text-xs sm:text-sm font-bold <?= asGroupClass($training['target_group'] ?? null) ?>">
                                        <?= asE($training['target_group'] ?? null, 'Mixed') ?>
                                    </div>
                                </div>
                                <div class="bg-black/20 p-3 rounded-xl border border-gray-800">
                                    <div class="text-[9px] sm:text-[10px] text-gray-500 uppercase font-medium mb-1">Venue Type</div>
                                    <div class="text-xs sm:text-sm font-bold text-gray-200"><?= asE($training['venue_type'] ?? null, 'Custom') ?></div>
                                </div>
                                <div class="bg-black/20 p-3 rounded-xl border border-gray-800 sm:col-span-2">
                                    <div class="text-[9px] sm:text-[10px] text-gray-500 uppercase font-medium mb-1">Fee Setup</div>
                                    <div class="text-xs sm:text-sm font-bold text-blue-300">&#8369;<?= number_format((float)($training['fee_amount'] ?? 0), 2, '.', ',') ?></div>
                                    <div class="text-[9px] text-gray-500 mt-1">Public sessions are typically free. Private sessions can use a custom fee.</div>
                                </div>
                            </div>
                        </div>

                        <?php if (trim((string)($training['description'] ?? '')) !== ''): ?>
                            <div class="mt-6 sm:mt-8 pt-6 sm:pt-8 border-t border-gray-800">
                                <h4 class="text-[10px] sm:text-xs font-bold text-gray-400 uppercase tracking-widest mb-2 sm:mb-3">Training Notes</h4>
                                <div class="text-xs sm:text-sm text-gray-400 leading-relaxed italic bg-black/20 p-3 sm:p-4 rounded-xl sm:rounded-2xl border-l-2 border-purple-500">
                                    "<?= asE($training['description'] ?? null) ?>"
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Stats Card -->
                    <?php if ($selfCheckinMode): ?>
                    <?php
                    $ownStatus = $ownAttendance !== null ? (string)($ownAttendance['status'] ?? '') : '';
                    $ownStatusClass = $ownStatus === 'Present'
                        ? 'text-green-400'
                        : ($ownStatus === 'Late' ? 'text-orange-400' : 'text-red-400');
                    ?>
                    <div class="card-bg p-6 sm:p-8 rounded-2xl sm:rounded-3xl text-center">
                        <div class="text-[9px] sm:text-[10px] text-gray-500 uppercase tracking-[0.2em] mb-3">Your Attendance</div>
                        <?php if ($ownAttendance !== null && (string)($ownAttendance['status'] ?? '') !== 'Absent'): ?>
                            <div class="text-2xl font-bold <?= $ownStatusClass ?>"><?= asE($ownStatus, 'Recorded') ?></div>
                            <div class="text-[10px] text-gray-500 mt-2">Your recorded status for this session</div>
                        <?php elseif ($viewerMemberId > 0): ?>
                            <div class="text-lg font-bold text-gray-400">Not Checked In Yet</div>
                            <div class="text-[10px] text-gray-500 mt-2">Use "Check In: Face + GPS" to record your attendance.</div>
                        <?php else: ?>
                            <div class="text-lg font-bold text-amber-300">Account Not Linked</div>
                            <div class="text-[10px] text-gray-500 mt-2">Your login is not linked to a member record. Ask an administrator to link it under Users &#8594; Edit User.</div>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <div class="card-bg p-4 sm:p-6 rounded-2xl sm:rounded-3xl flex justify-around text-center">
                        <div>
                            <div class="text-lg sm:text-xl font-bold text-green-400"><?= $presentCount ?></div>
                            <div class="text-[9px] sm:text-[10px] text-gray-500 uppercase">Present</div>
                        </div>
                        <div class="w-px h-6 sm:h-8 bg-gray-800 self-center"></div>
                        <div>
                            <div class="text-lg sm:text-xl font-bold text-orange-400"><?= $lateCount ?></div>
                            <div class="text-[9px] sm:text-[10px] text-gray-500 uppercase">Late</div>
                        </div>
                        <div class="w-px h-6 sm:h-8 bg-gray-800 self-center"></div>
                        <div>
                            <div class="text-lg sm:text-xl font-bold text-red-400"><?= $absentCount ?></div>
                            <div class="text-[9px] sm:text-[10px] text-gray-500 uppercase">Absent</div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Attendance List -->
                <div class="lg:col-span-2">
                    <div class="card-bg rounded-2xl sm:rounded-3xl overflow-hidden">
                    <div class="p-4 sm:p-6 border-b border-gray-800 bg-white/5">
                        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-4">
                            <div>
                                <h3 class="font-bold tracking-wide text-xs sm:text-sm uppercase"><?= $selfCheckinMode ? 'Your Attendance' : 'Attendance Roster' ?></h3>
                                <p class="text-[9px] sm:text-[10px] text-gray-500 mt-1">
                                    <?= $selfCheckinMode
                                        ? 'Your own record for this session'
                                        : 'Update status for each member • ' . count($availableMembers) . ' eligible to add' ?>
                                </p>
                            </div>
                            <?php if (!$selfCheckinMode): ?>
                            <button type="button" onclick="openAddPeopleModal()"
                                    class="text-white border border-gray-700 px-4 py-2 rounded-full text-[10px] font-bold uppercase tracking-wider transition whitespace-nowrap <?= $canWriteAttendance ? 'bg-white/5 hover:bg-white/10 cursor-pointer' : 'bg-gray-800 opacity-50 cursor-not-allowed' ?>"
                                    <?= $canWriteAttendance ? '' : 'disabled' ?>
                                    title="<?= $canWriteAttendance ? 'Add members to this session' : 'No permission to add members' ?>">
                                <i class="fas fa-user-plus mr-2 text-purple-400"></i> Add People
                            </button>
                            <?php endif; ?>
                        </div>

                        <!-- Search Bar -->
                        <div class="relative max-w-md">
                            <input type="search" id="attendanceSearch"
                                placeholder="Search by name or rank..."
                                autocomplete="off"
                                class="w-full bg-gray-900/60 border border-gray-700 rounded-xl pl-10 pr-4 py-2.5 text-sm text-white placeholder-gray-500 focus:outline-none focus:border-purple-500/50 transition">
                            <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-500 text-sm"></i>
                        </div>
                    </div>

                        <div class="p-2 max-h-[600px] overflow-y-auto" id="attendanceList">
                            <?php if (empty($attendanceList)): ?>
                                <div class="p-8 text-center text-gray-500">
                                    <i class="fas fa-user-check text-3xl mb-3 opacity-30"></i>
                                    <?php if ($selfCheckinMode): ?>
                                        <p class="text-sm">You have not checked in yet</p>
                                        <p class="text-xs text-gray-600 mt-1">Use the "Check In: Face + GPS" button at the top of this page to record your attendance.</p>
                                    <?php else: ?>
                                        <p class="text-sm">No members added yet</p>
                                        <p class="text-xs text-gray-600 mt-1">Click "Add People" to start taking attendance for the <?= asE(strtolower(trim((string)($training['target_group'] ?? 'mixed')))) ?: 'mixed' ?> roster.</p>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <?php foreach ($attendanceList as $attendance):
                                    $memberId = (int)($attendance['member_id'] ?? 0);
                                    $memberName = trim((string)($attendance['full_name'] ?? ''));
                                    $currentStatus = (string)($attendance['status'] ?? '');
                                    $checkinId = (int)($attendance['checkin_id'] ?? 0);
                                    $checkinMethod = (string)($attendance['checkin_method'] ?? '');
                                    $faceScore = $attendance['face_score'] !== null ? (float)$attendance['face_score'] : null;
                                    $distanceM = $attendance['distance_m'] !== null ? (int)$attendance['distance_m'] : null;
                                    $isFlagged = (int)($attendance['flagged'] ?? 0) === 1;
                                    $flagReason = trim((string)($attendance['flag_reason'] ?? ''));
                                    ?>
                                    <!-- data-name / data-rank drive the client-side
                                         filter. The previous implementation matched
                                         Tailwind class strings, which broke silently
                                         on any class reordering. -->
                                    <div data-attendance-row
                                         data-name="<?= asE(strtolower($memberName)) ?>"
                                         data-rank="<?= asE(strtolower(trim((string)($attendance['rank'] ?? '')))) ?>"
                                         class="flex flex-col sm:flex-row items-start sm:items-center justify-between p-3 sm:p-4 hover:bg-white/[0.02] rounded-xl sm:rounded-2xl transition border-b border-gray-800/50 last:border-0 gap-3 sm:gap-0">
                                        <div class="flex items-center gap-3 sm:gap-4 w-full sm:w-auto">
                                            <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-full bg-gradient-to-br from-purple-500/20 to-blue-500/20 flex items-center justify-center text-[10px] sm:text-xs font-bold border border-white/5 flex-shrink-0">
                                                <?= asE(asInitials($attendance['full_name'] ?? null), '??') ?>
                                            </div>
                                            <div>
                                                <div class="text-xs sm:text-sm font-bold tracking-wide"><?= asE($attendance['full_name'] ?? null, 'Unknown') ?></div>
                                                <div class="text-[9px] sm:text-[10px] text-gray-400 font-medium uppercase tracking-tighter"><?= asE($attendance['rank'] ?? null, 'None') ?></div>
                                                <div class="text-[9px] text-gray-500"><?= asE($attendance['player_category'] ?? null, 'Public') ?> Player</div>
                                                <?php if ($checkinId > 0): ?>
                                                <div class="flex flex-wrap items-center gap-1.5 mt-2">
                                                    <span class="px-2 py-0.5 rounded-full text-[8px] font-bold uppercase tracking-wider bg-blue-500/10 text-blue-300 border border-blue-500/20">
                                                        <?= $checkinMethod === 'Manual' ? 'Manual' : 'Face + GPS' ?>
                                                    </span>
                                                    <?php if ($faceScore !== null): ?>
                                                    <span class="px-2 py-0.5 rounded-full text-[8px] font-bold uppercase tracking-wider bg-purple-500/10 text-purple-300 border border-purple-500/20" title="Face match confidence">
                                                        Face <?= number_format($faceScore * 100, 1) ?>%
                                                    </span>
                                                    <?php endif; ?>
                                                    <?php if ($distanceM !== null): ?>
                                                    <span class="px-2 py-0.5 rounded-full text-[8px] font-bold uppercase tracking-wider bg-cyan-500/10 text-cyan-300 border border-cyan-500/20" title="Distance from the session venue">
                                                        <?= $distanceM ?> m
                                                    </span>
                                                    <?php endif; ?>
                                                    <?php if ($isFlagged): ?>
                                                    <span class="px-2 py-0.5 rounded-full text-[8px] font-bold uppercase tracking-wider bg-red-500/15 text-red-300 border border-red-500/30" title="<?= asE($flagReason !== '' ? $flagReason : 'Flagged check-in') ?>">
                                                        <i class="fas fa-flag mr-1"></i>Flagged<?= $flagReason !== '' ? ': ' . asE($flagReason) : '' ?>
                                                    </span>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if (!$selfCheckinMode && $canWriteAttendance): ?>
                                                <form method="POST" action="../actions/review_checkin.php" class="flex flex-wrap items-center gap-1.5 mt-2">
                                                    <input type="hidden" name="csrf_token" value="<?= asE($csrfToken) ?>">
                                                    <input type="hidden" name="checkin_id" value="<?= $checkinId ?>">
                                                    <input type="hidden" name="training_id" value="<?= (int)$trainingId ?>">
                                                    <input type="hidden" name="page" value="<?= (int)$page ?>">
                                                    <?php if ($isFlagged): ?>
                                                    <button type="submit" name="decision" value="clear" class="px-2.5 py-1 rounded-lg bg-emerald-500/10 text-emerald-300 border border-emerald-500/20 text-[8px] font-bold uppercase tracking-wider hover:bg-emerald-500 hover:text-white transition">
                                                        <i class="fas fa-check mr-1"></i>Clear flag
                                                    </button>
                                                    <button type="submit" name="decision" value="confirm" class="px-2.5 py-1 rounded-lg bg-red-500/10 text-red-300 border border-red-500/20 text-[8px] font-bold uppercase tracking-wider hover:bg-red-500 hover:text-white transition">
                                                        <i class="fas fa-flag mr-1"></i>Confirm flag
                                                    </button>
                                                    <?php else: ?>
                                                    <button type="submit" name="decision" value="flag" class="px-2.5 py-1 rounded-lg bg-white/5 text-gray-400 border border-gray-700 text-[8px] font-bold uppercase tracking-wider hover:bg-red-500 hover:text-white transition" title="Flag this check-in for review">
                                                        <i class="fas fa-flag mr-1"></i>Flag
                                                    </button>
                                                    <?php endif; ?>
                                                </form>
                                                <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <?php if ($selfCheckinMode): ?>
                                        <span class="px-3 py-1.5 rounded-full text-[10px] font-bold uppercase tracking-wider <?= $currentStatus === 'Present' ? 'bg-green-500/15 text-green-400 border border-green-500/20' : ($currentStatus === 'Late' ? 'bg-orange-500/15 text-orange-400 border border-orange-500/20' : 'bg-red-500/15 text-red-400 border border-red-500/20') ?>"><?= asE($currentStatus, 'Recorded') ?></span>
                                        <?php else: ?>
                                        <div class="flex items-center gap-1.5 sm:gap-2 w-full sm:w-auto justify-end sm:justify-start">
                                            <button type="button" onclick="<?= asE(asStatusOnclick($trainingId, $memberId, 'Present', $canWriteAttendance)) ?>"
                                                    class="w-8 h-8 sm:w-10 sm:h-10 rounded-lg sm:rounded-xl <?= $currentStatus === 'Present' ? 'bg-green-500 text-white shadow-lg shadow-green-900/40' : 'bg-gray-800/50 text-gray-500 hover:text-green-400' ?> flex items-center justify-center text-xs transition <?= $canWriteAttendance ? 'cursor-pointer' : 'opacity-50 cursor-not-allowed' ?>"
                                                    title="<?= $canWriteAttendance ? 'Mark Present' : 'No permission to update attendance' ?>"
                                                    <?= $canWriteAttendance ? '' : 'disabled' ?>>
                                                <i class="fas fa-check"></i>
                                            </button>
                                            <button type="button" onclick="<?= asE(asStatusOnclick($trainingId, $memberId, 'Late', $canWriteAttendance)) ?>"
                                                    class="w-8 h-8 sm:w-10 sm:h-10 rounded-lg sm:rounded-xl <?= $currentStatus === 'Late' ? 'bg-orange-500 text-white shadow-lg shadow-orange-900/40' : 'bg-gray-800/50 text-gray-500 hover:text-orange-400' ?> flex items-center justify-center text-xs transition <?= $canWriteAttendance ? 'cursor-pointer' : 'opacity-50 cursor-not-allowed' ?>"
                                                    title="<?= $canWriteAttendance ? 'Mark Late' : 'No permission to update attendance' ?>"
                                                    <?= $canWriteAttendance ? '' : 'disabled' ?>>
                                                <i class="fas fa-clock"></i>
                                            </button>
                                            <button type="button" onclick="<?= asE(asStatusOnclick($trainingId, $memberId, 'Absent', $canWriteAttendance)) ?>"
                                                    class="w-8 h-8 sm:w-10 sm:h-10 rounded-lg sm:rounded-xl <?= $currentStatus === 'Absent' ? 'bg-red-500 text-white shadow-lg shadow-red-900/40' : 'bg-gray-800/50 text-gray-500 hover:text-red-400' ?> flex items-center justify-center text-xs transition <?= $canWriteAttendance ? 'cursor-pointer' : 'opacity-50 cursor-not-allowed' ?>"
                                                    title="<?= $canWriteAttendance ? 'Mark Absent' : 'No permission to update attendance' ?>"
                                                    <?= $canWriteAttendance ? '' : 'disabled' ?>>
                                                <i class="fas fa-times"></i>
                                            </button>
                                            <button type="button" onclick="<?= asE(asRemoveOnclick($trainingId, $memberId, $attendance['full_name'] ?? null, $canDeleteAttendance)) ?>"
                                                    class="w-8 h-8 sm:w-10 sm:h-10 rounded-lg sm:rounded-xl bg-gray-800/50 flex items-center justify-center text-xs transition <?= $canDeleteAttendance ? 'text-gray-500 hover:text-yellow-400 cursor-pointer' : 'text-gray-700 opacity-50 cursor-not-allowed' ?>"
                                                    title="<?= $canDeleteAttendance ? 'Remove from session' : 'No permission to remove members' ?>"
                                                    <?= $canDeleteAttendance ? '' : 'disabled' ?>>
                                                <i class="fas fa-user-minus"></i>
                                            </button>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <!-- Pagination Controls -->
                        <?php if ($totalPages > 1): ?>
                        <div class="px-4 py-4 border-t border-gray-800 flex justify-between items-center flex-wrap gap-3">
                            <div class="text-[10px] text-gray-500 uppercase">
                                Showing <?= $totalAttendance > 0 ? $offset + 1 : 0 ?> to <?= min($offset + $perPage, $totalAttendance) ?> of <?= $totalAttendance ?> members
                            </div>
                            <div class="flex gap-2">
                                <?php if ($page > 1): ?>
                                <a href="<?= asE($sessionPageLink(1)) ?>" class="bg-white/5 hover:bg-white/10 px-3 py-2 rounded-lg text-[10px] font-bold transition">
                                    <i class="fas fa-chevron-left mr-1"></i>First
                                </a>
                                <a href="<?= asE($sessionPageLink($page - 1)) ?>" class="bg-white/5 hover:bg-white/10 px-3 py-2 rounded-lg text-[10px] font-bold transition">
                                    <i class="fas fa-chevron-left mr-1"></i>Prev
                                </a>
                                <?php endif; ?>

                                <span class="px-3 py-2 text-[10px] font-bold text-gray-400">
                                    Page <?= (int)$page ?> of <?= (int)$totalPages ?>
                                </span>

                                <?php if ($page < $totalPages): ?>
                                <a href="<?= asE($sessionPageLink($page + 1)) ?>" class="bg-white/5 hover:bg-white/10 px-3 py-2 rounded-lg text-[10px] font-bold transition">
                                    Next<i class="fas fa-chevron-right ml-1"></i>
                                </a>
                                <a href="<?= asE($sessionPageLink($totalPages)) ?>" class="bg-white/5 hover:bg-white/10 px-3 py-2 rounded-lg text-[10px] font-bold transition">
                                    Last<i class="fas fa-chevron-right ml-1"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="text-[9px] sm:text-[10px] text-gray-600 space-y-1 pl-2 mt-4">
                        <p>CREATED: <?= asE(asFormatDate($training['created_at'] ?? null)) ?></p>
                        <p>LAST UPDATE: <?= asE(asFormatDate($training['updated_at'] ?? null)) ?></p>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </main>
        <!-- Reveal <main> immediately after it closes. It used to be revealed by a script at the very
             end of the document, so any PHP fatal in a modal included below left <main> stuck at
             opacity-0 (a blank content area) even though the content had been rendered. -->
        <script>
        (function () {
            var page = document.getElementById('page');
            if (!page) { return; }
            var reveal = function () { page.classList.remove('opacity-0', 'translate-x-8'); };
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', reveal);
            } else {
                reveal();
            }
        })();
        </script>
    </div>

    <!-- face-api.js (vladmandic fork v1.7.12) powers the face-verified self check-in. -->
    <?php if ($selfCheckinMode): ?>
    <script src="https://cdn.jsdelivr.net/npm/@vladmandic/face-api@1.7.12/dist/face-api.min.js"></script>
    <?php endif; ?>
    <?php
    /*
     * The self check-in modal targets the signed-in member. By this point
     * $memberId holds the last roster row, so pass the viewer explicitly.
     */
    $checkinMemberId = $viewerMemberId;
    $checkinTraining = is_array($training ?? null) ? $training : [];
    $checkinTrainingId = $trainingId;
    $checkinCsrf = $csrfToken;
    ?>
    <!--
        Leaflet + the shared location picker power the Edit Session modal's
        venue map. These were previously missing here, which is why the map
        rendered blank on this page (L was undefined).
    -->
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
    <?php include_once __DIR__ . '/modals/add_to_training.php'; ?>
    <?php include_once __DIR__ . '/modals/edit_training.php'; ?>
    <?php include_once __DIR__ . '/modals/attendance_checkin.php'; ?>
    <?php include_once __DIR__ . '/modals/void_confirmation.php'; ?>
    <?php include_once __DIR__ . '/modals/user_menu.php'; ?>
    <?php include_once __DIR__ . '/modals/edit_profile_user.php'; ?>
</body>

<script>
const CSRF_TOKEN = <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const CURRENT_PAGE = <?= (int)$page ?>;

/** Builds and submits a one-shot POST form with the CSRF token attached. */
function postTo(action, fields) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = action;

    const payload = Object.assign({csrf_token: CSRF_TOKEN}, fields || {});
    Object.keys(payload).forEach(function (key) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = key;
        input.value = payload[key];
        form.appendChild(input);
    });

    document.body.appendChild(form);
    form.submit();
}

function updateAttendance(trainingId, memberId, status) {
    postTo('../actions/update_attendance.php', {
        training_id: trainingId,
        member_id: memberId,
        status: status,
        /* Preserve the roster page across the round-trip. */
        page: CURRENT_PAGE
    });
}

function removeMember(trainingId, memberId, memberName) {
    if (!confirm('Remove ' + memberName + ' from this session?')) {
        return;
    }

    postTo('../actions/remove_from_training.php', {
        training_id: trainingId,
        member_id: memberId,
        page: CURRENT_PAGE
    });
}

document.addEventListener('DOMContentLoaded', function () {
    const searchInput = document.getElementById('attendanceSearch');
    const attendanceContainer = document.getElementById('attendanceList');

    if (!searchInput || !attendanceContainer) return;

    let noResults = null;

    searchInput.addEventListener('input', function () {
        const term = this.value.toLowerCase().trim();
        const rows = attendanceContainer.querySelectorAll('[data-attendance-row]');

        let visibleCount = 0;

        rows.forEach(function (row) {
            const name = row.getAttribute('data-name') || '';
            const rank = row.getAttribute('data-rank') || '';
            const matches = term === '' || name.indexOf(term) !== -1 || rank.indexOf(term) !== -1;

            row.style.display = matches ? '' : 'none';
            if (matches) visibleCount++;
        });

        if (visibleCount === 0 && rows.length > 0) {
            if (!noResults) {
                noResults = document.createElement('div');
                noResults.id = 'no-results-message';
                noResults.className = 'p-8 text-center text-gray-500';
                noResults.textContent = 'No matching members found';
                attendanceContainer.appendChild(noResults);
            }
            noResults.classList.remove('hidden');
        } else if (noResults) {
            noResults.classList.add('hidden');
        }
    });
});
</script>
</html>