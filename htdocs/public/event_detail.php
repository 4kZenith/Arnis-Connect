<?php
/**
 * EVENT DETAIL
 * public/event_detail.php
 *
 * Fixes in this revision:
 *  - Removed the 4th copy of showNotification() (page fataled on redeclare).
 *  - 'own' read scope for Coach/Trainer is now ENFORCED, not just listed.
 *  - Event financials card is gated on funds.read (was leaking to Member/Applicant).
 *  - Hardcoded "Dec 15, 2025 / Officer Homelander / Talent Fees / Transport" replaced
 *    with real joined audit data + real fund rows.
 *  - file_path / member data XSS eliminated (hex json_encode + DOM-built cards).
 *  - CSRF token sent on remove-participant and quick-add.
 *  - Dead voidDocument() (404 endpoint) removed; explicit column lists;
 *    zero/NULL-date safe formatters; no external placeholder.com request.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/Events.php';

/* ==================================================================
 * Page-local helpers (prefixed "ed" — the modals included at the
 * bottom of this file declare helpers of their own).
 * ================================================================== */

function edE($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function edJsArg($value): string
{
    return htmlspecialchars(
        (string) json_encode(
            (string) ($value ?? ''),
            JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE
        ),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

/**
 * Present / Late / Absent buttons + a status pill for one participant.
 * Used by BOTH the mobile cards and the desktop table in the Participants
 * section. Each wrap carries data-att-current so the summary counters can
 * be adjusted in place (no page reload per click).
 */
function edAttendanceControls(array $participant, bool $allowed): string
{
    $pid    = (int) ($participant['participant_id'] ?? 0);
    $status = (string) ($participant['attendance_status'] ?? '');

    if ($status === 'Present') {
        $pillClass  = 'bg-green-500/15 text-green-400 border-green-500/20';
        $pillLabel  = 'Present';
    } elseif ($status === 'Late') {
        $pillClass  = 'bg-orange-500/15 text-orange-400 border-orange-500/20';
        $pillLabel  = 'Late';
    } elseif ($status === 'Absent') {
        $pillClass  = 'bg-red-500/15 text-red-400 border-red-500/20';
        $pillLabel  = 'Absent';
    } else {
        $pillClass  = 'bg-gray-500/10 text-gray-400 border-gray-500/20';
        $pillLabel  = 'Not marked';
    }

    $defs = [
        'Present' => ['icon' => 'fa-check',  'hover' => 'hover:text-green-400',  'active' => 'bg-green-500',  'shadow' => 'shadow-green-900/40'],
        'Late'    => ['icon' => 'fa-clock',  'hover' => 'hover:text-orange-400', 'active' => 'bg-orange-500', 'shadow' => 'shadow-orange-900/40'],
        'Absent'  => ['icon' => 'fa-times',  'hover' => 'hover:text-red-400',    'active' => 'bg-red-500',    'shadow' => 'shadow-red-900/40'],
    ];

    $html = '<div class="flex items-center gap-1.5" data-att-wrap="' . $pid . '" data-att-current="' . edE($status) . '">'
          . '<span data-att-pill class="px-2 py-1 rounded-full text-[9px] font-bold uppercase tracking-wider border ' . $pillClass . '">'
          . edE($pillLabel) . '</span>';

    foreach ($defs as $label => $def) {
        $active = ($status === $label);
        if ($allowed) {
            $btnClass = $active
                ? $def['active'] . ' text-white shadow-lg ' . $def['shadow']
                : 'bg-gray-800/60 text-gray-500 ' . $def['hover'];
            $html .= '<button type="button" onclick="updateEventAttendance(' . $pid . ',\'' . $label . '\')"'
                   . ' data-att-btn="' . $label . '"'
                   . ' class="w-7 h-7 rounded-lg flex items-center justify-center text-[10px] transition cursor-pointer ' . $btnClass . '"'
                   . ' title="Mark ' . $label . '"><i class="fas ' . $def['icon'] . '"></i></button>';
        } else {
            $html .= '<button type="button" disabled'
                   . ' class="w-7 h-7 rounded-lg flex items-center justify-center text-[10px] bg-gray-800/40 text-gray-600 opacity-50 cursor-not-allowed"'
                   . ' title="No permission to update attendance"><i class="fas ' . $def['icon'] . '"></i></button>';
        }
    }

    return $html . '</div>';
}

function edFormatDate($date): string
{
    $date = trim((string) $date);
    if ($date === '' || str_starts_with($date, '0000-00-00')) {
        return 'TBA';
    }
    $ts = strtotime($date);
    return ($ts === false) ? 'TBA' : date('M j, Y', $ts);
}

function edFormatDateTime($date): string
{
    $date = trim((string) $date);
    if ($date === '' || str_starts_with($date, '0000-00-00')) {
        return 'TBA';
    }
    $ts = strtotime($date);
    return ($ts === false) ? 'TBA' : date('M j, Y g:i A', $ts);
}

function edAchievementEmoji($type): string
{
    $emojis = ['Gold' => '🥇', 'Silver' => '🥈', 'Bronze' => '🥉', 'Recognition' => '⭐'];
    return $emojis[(string) $type] ?? '🏅';
}

function edFileIconHtml($type): string
{
    switch (strtolower(trim((string) $type))) {
        case 'image':
            return '<i class="fas fa-image"></i>';
        case 'receipt':
            return '<i class="fas fa-receipt text-green-500"></i>';
        case 'video':
            return '<i class="fas fa-video text-purple-400"></i>';
        case 'note':
            return '<i class="fas fa-file-lines text-yellow-400"></i>';
        default:
            return '<i class="fas fa-file"></i>';
    }
}

/** Does this signed-in user own the event (creator OR assigned handler)? */
function edOwnsEvent(array $event, int $userId): bool
{
    $createdBy = (int) ($event['created_by'] ?? 0);
    if ($userId > 0 && $createdBy > 0 && $createdBy === $userId) {
        return true;
    }

    /*
     * The event handler is a member, not a user. A coach assigned as handler
     * must be able to manage the event even though they did not create it.
     */
    $handlerId = (int) ($event['handler_id'] ?? 0);
    if ($handlerId > 0) {
        $sessionMemberId = isset($_SESSION['member_id']) && $_SESSION['member_id'] !== null
            ? (int) $_SESSION['member_id']
            : 0;
        if ($sessionMemberId <= 0) {
            $sessionMemberId = function_exists('resolveSessionMemberId')
                ? resolveSessionMemberId()
                : 0;
        }
        if ($sessionMemberId > 0 && $sessionMemberId === $handlerId) {
            return true;
        }
    }

    return false;
}

/**
 * Centralised permission check.
 * hasPermission() only rejects the value `false`, so a scope string such as
 * 'own' / 'own_events' / 'own_trainees' MUST be resolved against the record here * or any role holding it can act on every row (IDOR).
 */
function edCanPerform(string $module, string $action, ?array $event, int $currentUserId): bool
{
    if (!hasPermission($module, $action)) {
        return false;
    }

    $value = permissionValue($module, $action);

    if (in_array($value, ['own', 'own_events', 'own_trainees'], true)) {
        return $event !== null && edOwnsEvent($event, $currentUserId);
    }

    return true;
}

/* ==================================================================
 * 1. READ GATE
 * ================================================================== */

$eventsReadScope = permissionValue('events', 'read');

if ($eventsReadScope === false) {
    http_response_code(403);
    exit('Access Denied: You do not have permission to view events.');
}

/* ==================================================================
 * 2. VALIDATE ?id=  (never blank: invalid ids render an in-layout
 * error card instead of header() redirect, which blanks on
 * InfinityFree when headers are already sent)
 * ================================================================== */

$eventId = null;
$eventIdRaw = $_GET['id'] ?? null;

if (is_string($eventIdRaw)) {
    $validated = filter_var($eventIdRaw, FILTER_VALIDATE_INT);
    if ($validated !== false && $validated > 0) {
        $eventId = (int) $validated;
    }
} elseif (is_int($eventIdRaw) && $eventIdRaw > 0) {
    $eventId = $eventIdRaw;
}

/* Renders sidebar + message and stops. Guarantees the page is never
 * an empty dark panel (your id=6 screenshot). */
$edFatal = static function (string $title, string $message): void {
    http_response_code(404);
    ?>
    <!DOCTYPE html>
    <html lang="en" class="text-[13px]">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?> | Arnis Portal</title>
        <script src="https://cdn.tailwindcss.com"></script>
        <link rel="icon" type="image/x-icon" href="../arnis-favicon.ico">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css">
        <link rel="stylesheet" href="css/sidebar.css">
        <link rel="stylesheet" href="css/style.css">
    </head>
    <body class="text-white bg-dark-purple overflow-x-hidden">
        <div class="flex">
            <?php include_once __DIR__ . '/../includes/sidebar.php'; ?>
            <main class="flex-1 p-4 sm:p-6 lg:p-8 h-screen overflow-y-auto">
                <?php include_once __DIR__ . '/../includes/header.php'; ?>
                <div class="card-bg rounded-3xl p-8 sm:p-12 text-center max-w-xl mx-auto mt-8">
                    <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-orange-500/10 flex items-center justify-center">
                        <i class="fas fa-calendar-times text-2xl text-orange-400"></i>
                    </div>
                    <h1 class="text-xl font-black uppercase italic"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
                    <p class="text-sm text-gray-400 mt-2"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="text-[11px] text-gray-600 mt-2">Valid IDs right now: 4, 5. IDs 2–3 are voided, ID 6 does not exist.</p>
                    <a href="events.php" class="inline-block mt-6 bg-accent-purple hover:opacity-90 px-6 py-3 rounded-full font-bold text-xs uppercase transition">
                        <i class="fas fa-chevron-left mr-2"></i>Back to Events
                    </a>
                </div>
            </main>
        </div>
    </body>
    </html>
    <?php
    exit;
};

if ($eventId === null) {
    $edFatal('Invalid event', 'Missing or invalid ?id= parameter.');
}

$currentUserId = (int) ($_SESSION['user_id'] ?? 0);

/* ==================================================================
 * 3. LOAD EVENT
 * ================================================================== */

try {
    $db = (new Database())->connect();
    $eventsModel = new Events($db);
    $event = $eventsModel->getById($eventId);
} catch (Throwable $e) {
    error_log('[event_detail] load failed: ' . $e->getMessage());
    $edFatal('Load failed', 'Could not load the event. Please try again.');
}

if (!is_array($event) || $event === []) {
    $edFatal('Event not found', 'This event does not exist or it has been voided.');
}

/* ==================================================================
 * 4. ENFORCE 'own' ON READ  (list page already filters; this closes the URL bypass)
 * ================================================================== */

if ($eventsReadScope === 'own' && !edOwnsEvent($event, $currentUserId)) {
    http_response_code(403);
    exit('Access Denied: You can only view events that you created.');
}

/* ==================================================================
 * 5. RELATED DATA
 * ================================================================== */

$participants  = [];
$achievements  = [];
$documents     = [];
$recentFunds   = [];
$financials    = ['income' => 0.0, 'expenses' => 0.0, 'net' => 0.0, 'count' => 0];
$meta          = ['created_at' => null, 'created_by_name' => null, 'updated_at' => null, 'updated_by_name' => null];

$canViewFinancials = hasPermission('funds', 'read');

try {
    /* Participants -------------------------------------------------- */
    $stmt = $db->prepare("
        SELECT ep.participant_id, ep.role,
               ep.attendance_status, ep.attendance_remarks, ep.attendance_recorded_at,
               m.member_id, m.full_name, m.rank
        FROM event_participants ep
        INNER JOIN members m ON m.member_id = ep.member_id
        WHERE ep.event_id = :id AND ep.is_voided = 0
        ORDER BY m.full_name ASC
    ");
    $stmt->execute([':id' => $eventId]);
    $participants = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    /* Members can only view events they are rostered on */
    if (currentUserRole() === 'Member') {
        $sessionMemberId = isset($_SESSION['member_id']) && is_numeric($_SESSION['member_id'])
            ? (int)$_SESSION['member_id']
            : 0;
        $isParticipant = false;
        foreach ($participants as $p) {
            if ((int)($p['member_id'] ?? 0) === $sessionMemberId) {
                $isParticipant = true;
                break;
            }
        }
        if (!$isParticipant) {
            http_response_code(403);
            exit('Access Denied: You can only view events you are registered for.');
        }
    }

    /* Achievements / results ---------------------------------------- */
    $stmt = $db->prepare("
        SELECT a.achievement_id, a.achievement_type, a.level, a.notes, a.achieved_at,
               m.member_id, m.full_name
        FROM achievements a
        INNER JOIN members m ON m.member_id = a.member_id
        WHERE a.event_id = :id AND a.is_voided = 0
        ORDER BY m.full_name ASC, a.achievement_id ASC
    ");
    $stmt->execute([':id' => $eventId]);
    $achievements = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    /* Documents ----------------------------------------------------- */
    $stmt = $db->prepare("
        SELECT document_id, file_type, file_path, uploaded_at
        FROM event_documents
        WHERE event_id = :id AND is_voided = 0
        ORDER BY uploaded_at DESC, document_id DESC
    ");
    $stmt->execute([':id' => $eventId]);
    $documents = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    /* Financials (funds.read holders only) ------------------------- */
    if ($canViewFinancials) {
        $stmt = $db->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN transaction_type = 'Income'  THEN amount END), 0) AS income,
                COALESCE(SUM(CASE WHEN transaction_type = 'Expense' THEN amount END), 0) AS expenses,
                COUNT(*) AS tx_count
            FROM funds
            WHERE related_event_id = :id AND is_voided = 0
        ");
        $stmt->execute([':id' => $eventId]);
        $agg = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $income   = (float) ($agg['income'] ?? 0);
        $expenses = (float) ($agg['expenses'] ?? 0);

        $financials = [
            'income'   => $income,
            'expenses' => $expenses,
            'net'      => $income - $expenses,
            'count'    => (int) ($agg['tx_count'] ?? 0),
        ];

        $stmt = $db->prepare("
            SELECT transaction_type, source_or_purpose, amount, transaction_date
            FROM funds
            WHERE related_event_id = :id AND is_voided = 0
            ORDER BY transaction_date DESC, fund_id DESC
            LIMIT 6
        ");
        $stmt->execute([':id' => $eventId]);
        $recentFunds = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /* Audit metadata ------------------------------------------------ */
    $stmt = $db->prepare("
        SELECT
            e.created_at,
            cu.full_name AS created_by_name,
            e.updated_at,
            uu.full_name AS updated_by_name,
            hm.full_name AS handler_name,
            hm.member_type AS handler_type
        FROM events e
        LEFT JOIN users cu ON cu.user_id = e.created_by
        LEFT JOIN users uu ON uu.user_id = e.updated_by
        LEFT JOIN members hm ON hm.member_id = e.handler_id
        WHERE e.event_id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $eventId]);
    $meta = $stmt->fetch(PDO::FETCH_ASSOC) ?: $meta;
} catch (Throwable $e) {
    error_log('[event_detail] related data failed: ' . $e->getMessage());
    $participants = [];
    $achievements = [];
    $documents    = [];
    $recentFunds  = [];
    $financials   = ['income' => 0.0, 'expenses' => 0.0, 'net' => 0.0, 'count' => 0];
}

/* ==================================================================
 * 6. PERMISSION FLAGS
 * ================================================================== */

$canEditEvent         = edCanPerform('events', 'write', $event, $currentUserId);
$canAddParticipant    = edCanPerform('event_participants', 'write', $event, $currentUserId);
$canRemoveParticipant = edCanPerform('event_participants', 'delete', $event, $currentUserId);
$canAddAchievement    = edCanPerform('achievements', 'write', $event, $currentUserId);
$canDeleteAchievement = edCanPerform('achievements', 'delete', $event, $currentUserId);
$canUploadDocument    = edCanPerform('event_documents', 'write', $event, $currentUserId);
$canDeleteDocument    = edCanPerform('event_documents', 'delete', $event, $currentUserId);
$canSuggestPlayers    = $canAddParticipant;

/* Eligible event handlers (active Head Coaches / Coaches) for the edit modal. */
$eventHandlers = [];
try {
    $stmt = $db->query(
        "SELECT member_id, full_name, member_type
         FROM members
         WHERE is_voided = 0 AND status = 'Active'
           AND member_type IN ('Head Coach','Coach')
         ORDER BY full_name ASC"
    );
    $eventHandlers = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    error_log('[event_detail] handler list failed: ' . $e->getMessage());
}

/*
 * Event attendance (mirrors the training roster): same permission as any
 * other roster write, and NOT NULL attendance_status means "not marked yet".
 */
$canMarkAttendance = $canAddParticipant;
$attPresent = 0;
$attLate    = 0;
$attAbsent  = 0;
foreach ($participants as $participantRow) {
    $attStatus = (string) ($participantRow['attendance_status'] ?? '');
    if ($attStatus === 'Present') {
        $attPresent++;
    } elseif ($attStatus === 'Late') {
        $attLate++;
    } elseif ($attStatus === 'Absent') {
        $attAbsent++;
    }
}

$csrfToken = arnisCsrfToken();

// Self check-in context for the logged-in member
$viewerMemberId = function_exists('resolveSessionMemberId') ? resolveSessionMemberId($db ?? null) : 0;
$viewerParticipant = null;
if ($viewerMemberId > 0) {
    foreach ($participants as $p) {
        if ((int)($p['member_id'] ?? 0) === $viewerMemberId) {
            $viewerParticipant = $p;
            break;
        }
    }
}
$canSelfCheckinEvent = false;
if ($viewerParticipant !== null) {
    $vpStatus = (string)($viewerParticipant['attendance_status'] ?? '');
    $eventDate = (string)($event['event_date'] ?? '');
    if (!in_array($vpStatus, ['Present', 'Late'], true) && $eventDate <= date('Y-m-d')) {
        $canSelfCheckinEvent = true;
    }
}

$eventTypeLabel      = trim((string) ($event['event_type'] ?? '')) ?: 'Event';
$targetGroupLabel    = strtolower(trim((string) ($event['target_player_category'] ?? 'Mixed'))) ?: 'mixed';
$targetCount         = (int) ($event['expected_participants'] ?? 0);
$hasDescription      = trim((string) ($event['description'] ?? '')) !== '';
$lastUpdateIsLater = (trim((string) ($meta['updated_at'] ?? '')) !== '')
                     && (strtotime((string) $meta['updated_at']) > strtotime((string) ($meta['created_at'] ?? '1970-01-01')));
?>
<!DOCTYPE html>
<html lang="en" class="text-[13px]">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= edE($event['event_name'] ?? 'Event') ?> | Arnis Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" type="image" href="../arnis-favicon.ico">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,100..900;1,100..900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/sidebar.css">
    <link rel="stylesheet" href="css/style.css">
</head>
<body class="text-white bg-dark-purple overflow-x-hidden">

    <div class="flex">
        <?php include_once __DIR__ . '/../includes/sidebar.php'; ?>

        <main id="page" class="flex-1 p-4 sm:p-6 lg:p-8 h-screen overflow-y-auto custom-scrollbar">

            <?php include_once __DIR__ . '/../includes/header.php'; ?>

            <!-- Header -->
            <div class="flex flex-col md:flex-row md:items-end justify-between mb-6 sm:mb-8 gap-4">
                <div>
                    <div class="flex items-center gap-2 sm:gap-3 mb-2">
                        <a href="events.php" class="text-gray-500 hover:text-white transition"><i class="fas fa-chevron-left"></i></a>
                        <span class="bg-purple-500/20 text-purple-400 px-2 sm:px-3 py-1 rounded-full text-[9px] sm:text-[10px] font-bold uppercase tracking-widest border border-purple-500/30"><?= edE($eventTypeLabel) ?></span>
                    </div>
                    <h1 class="text-2xl sm:text-3xl lg:text-4xl font-black italic tracking-tighter uppercase"><?= edE($event['event_name'] ?? 'Event') ?></h1>
                    <p class="text-gray-400 mt-2 flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-4 text-xs sm:text-sm">
                        <span><i class="fas fa-calendar-day mr-2 text-purple-500"></i> <?= edE(edFormatDate($event['event_date'] ?? null)) ?></span>
                        <span><i class="fas fa-map-marker-alt mr-2 text-purple-500"></i> <?= edE(trim((string) ($event['location'] ?? '')) ?: 'N/A') ?></span>
                        <span><i class="fas fa-layer-group mr-2 text-purple-500"></i> <?= edE($targetGroupLabel) ?> roster</span>
                        <span><i class="fas fa-bullseye mr-2 text-purple-500"></i> Target <?= $targetCount > 0 ? edE((string) $targetCount) . ' players' : 'TBA' ?></span>
                    </p>
                </div>
                <div class="flex gap-2 sm:gap-3">
                    <?php if ($canEditEvent): ?>
                    <button onclick="openEditEventModal(<?= (int) $eventId ?>)"
                            class="flex-1 sm:flex-none px-4 sm:px-5 py-2.5 rounded-xl text-xs font-bold transition flex items-center justify-center gap-2 bg-gray-800 hover:bg-gray-700 text-white cursor-pointer"
                            title="Edit this event">
                        <i class="fas fa-edit"></i> EDIT
                    </button>
                    <?php else: ?>
                    <button disabled
                            class="flex-1 sm:flex-none px-4 sm:px-5 py-2.5 rounded-xl text-xs font-bold transition flex items-center justify-center gap-2 bg-gray-700 text-gray-600 opacity-50 cursor-not-allowed"
                            title="No permission to edit events">
                        <i class="fas fa-edit"></i> EDIT
                    </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Description -->
            <div class="card-bg p-4 sm:p-6 rounded-2xl sm:rounded-3xl mb-6 sm:mb-8">
                <h3 class="text-[10px] uppercase tracking-widest text-gray-500 font-bold mb-2">Event Description</h3>
                <p class="text-gray-300 text-xs sm:text-sm leading-relaxed italic">
                    <?= $hasDescription ? nl2br(edE($event['description'])) : '<span class="text-gray-500 not-italic">No description provided</span>' ?>
                </p>
            </div>

            <!-- Player suggestions -->
            <section class="card-bg rounded-2xl sm:rounded-3xl overflow-hidden shadow-2xl mb-6 sm:mb-8">
                <div class="p-4 sm:p-6 border-b border-gray-800 flex flex-col lg:flex-row lg:items-center justify-between gap-3 bg-white/5">
                    <div>
                        <h2 class="font-bold text-xs sm:text-sm tracking-widest uppercase flex items-center gap-2">
                            <i class="fas fa-wand-magic-sparkles text-purple-400"></i> Player Suggestions
                        </h2>
                        <p class="text-[10px] uppercase tracking-[0.16em] text-gray-500 mt-2">Based on attendance, rank, past events, and <?= edE($targetGroupLabel) ?> eligibility.</p>
                    </div>
                    <div class="flex gap-2">
                        <?php if ($canSuggestPlayers): ?>
                        <button onclick="loadSuggestedPlayers()" class="px-4 py-3 rounded-xl bg-purple-500/10 text-purple-300 hover:bg-purple-500 hover:text-white transition text-[11px] font-black uppercase tracking-[0.16em]">
                            <i class="fas fa-bolt mr-2"></i>Suggest Players
                        </button>
                        <button onclick="openParticipantModal()" class="px-4 py-3 rounded-xl bg-white/5 text-gray-300 hover:bg-white/10 transition text-[11px] font-black uppercase tracking-[0.16em]">Manual Add</button>
                        <?php else: ?>
                        <p class="text-[10px] uppercase tracking-[0.16em] text-gray-600 py-3">
                            <i class="fas fa-lock mr-1"></i>No permission to modify this roster
                        </p>
                        <?php endif; ?>
                    </div>
                </div>
                <div id="suggestedPlayersWrap" class="p-4 sm:p-6">
                    <div class="text-center py-10 text-gray-500">
                        <i class="fas fa-user-gear text-2xl mb-3 opacity-40"></i>
                        <p class="text-sm">
                            <?php if ($canSuggestPlayers): ?>
                                Click <span class="text-purple-300 font-bold">Suggest Players</span> to generate a recommended roster.
                            <?php else: ?>
                                Suggestions are only available to users who can manage this roster.
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            </section>

            <!-- Main grid -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 sm:gap-6 lg:gap-8">

                <div class="lg:col-span-2 space-y-4 sm:space-y-6 lg:space-y-8">

                    <!-- PARTICIPANTS -->
                    <section class="card-bg rounded-2xl sm:rounded-3xl overflow-hidden shadow-2xl">
                        <div class="p-4 sm:p-6 border-b border-gray-800 flex flex-col sm:flex-row justify-between items-start sm:items-center bg-white/5 gap-3">
                            <h2 class="font-bold text-xs sm:text-sm tracking-widest uppercase flex items-center gap-2">
                                <i class="fas fa-users text-purple-400"></i> Participants (<?= count($participants) ?>)
                            </h2>
                            <?php if ($canAddParticipant): ?>
                            <button onclick="openParticipantModal()"
                                    class="text-xs font-bold whitespace-nowrap text-purple-400 hover:text-white cursor-pointer"
                                    title="Add members to this event">+ ADD MEMBER</button>
                            <?php else: ?>
                            <button disabled
                                    class="text-xs font-bold whitespace-nowrap text-gray-600 opacity-50 cursor-not-allowed"
                                    title="No permission to add participants">+ ADD MEMBER</button>
                            <?php endif; ?>
                        </div>

                        <?php if ($canSelfCheckinEvent && $viewerParticipant !== null): ?>
                        <div class="p-4 sm:p-6 border-b border-gray-800 bg-purple-500/5">
                            <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-[10px] uppercase tracking-[0.16em] text-purple-300 font-bold">Your Attendance</p>
                                    <p class="text-sm text-gray-300 mt-1">Check in for this event with face verification and location.</p>
                                </div>
                                <button type="button"
                                        onclick="openCheckinModal(<?= (int)$eventId ?>, <?= (int)$viewerMemberId ?>)"
                                        class="px-4 py-2 rounded-full text-[10px] font-bold uppercase tracking-wider transition flex items-center gap-2 whitespace-nowrap bg-gradient-to-r from-purple-600 to-pink-600 hover:from-purple-700 hover:to-pink-700 text-white shadow-lg shadow-purple-900/30">
                                    <i class="fas fa-user-check text-xs"></i> Check In: Face + GPS
                                </button>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Attendance summary (updates in place when a status is clicked) -->
                        <?php if (!empty($participants)): ?>
                        <div class="px-4 sm:px-6 py-2.5 border-b border-gray-800/60 bg-black/20 flex flex-wrap items-center gap-x-4 gap-y-1 text-[10px] font-bold uppercase tracking-widest">
                            <span class="text-green-400"><i class="fas fa-check-circle mr-1"></i><span data-att-count="Present"><?= $attPresent ?></span> Present</span>
                            <span class="text-orange-400"><i class="fas fa-clock mr-1"></i><span data-att-count="Late"><?= $attLate ?></span> Late</span>
                            <span class="text-red-400"><i class="fas fa-times-circle mr-1"></i><span data-att-count="Absent"><?= $attAbsent ?></span> Absent</span>
                            <span class="text-gray-500 font-semibold normal-case tracking-normal">
                                <?= $canMarkAttendance
                                    ? 'of ' . count($participants) . ' participants — use the buttons below to take attendance'
                                    : 'of ' . count($participants) . ' participants' ?>
                            </span>
                        </div>
                        <?php endif; ?>

                        <!-- MOBILE -->
                        <div class="block sm:hidden">
                            <div class="p-2 space-y-2">
                                <?php if (empty($participants)): ?>
                                    <div class="text-center py-6 text-gray-400">No participants yet</div>
                                <?php else: ?>
                                    <?php foreach ($participants as $participant): ?>
                                    <div class="bg-white/[0.02] rounded-xl p-3 border border-gray-800/50">
                                        <div class="flex justify-between items-start mb-2">
                                            <div>
                                                <div class="font-bold text-sm"><?= edE($participant['full_name']) ?></div>
                                                <div class="text-[10px] text-gray-400 mt-1"><?= edE(trim((string) ($participant['rank'] ?? '')) ?: 'N/A') ?></div>
                                            </div>
                                            <span class="bg-blue-500/10 text-blue-400 px-2 py-0.5 rounded text-[9px] font-bold uppercase"><?= edE(trim((string) ($participant['role'] ?? '')) ?: 'Competitor') ?></span>
                                        </div>
                                        <div class="mt-2 pt-2 border-t border-gray-800/50 flex items-center justify-between gap-2">
                                            <span class="text-[9px] text-gray-500 uppercase tracking-widest">Attendance</span>
                                            <?= edAttendanceControls($participant, $canMarkAttendance) ?>
                                        </div>
                                        <div class="flex gap-2 justify-end">
                                            <a href="member_profile.php?id=<?= (int) ($participant['member_id'] ?? 0) ?>" class="text-gray-500 hover:text-green-500 transition-colors text-sm" title="View member profile">
                                                <i class="fa-solid fa-eye"></i>
                                            </a>
                                            <?php if ($canRemoveParticipant): ?>
                                            <button onclick="removeParticipant(<?= (int) ($participant['participant_id'] ?? 0) ?>)"
                                                    class="text-sm transition text-gray-600 hover:text-red-500 cursor-pointer"
                                                    title="Remove from event">
                                                <i class="fas fa-user-minus"></i>
                                            </button>
                                            <?php else: ?>
                                            <button disabled
                                                    class="text-sm transition text-gray-700 opacity-50 cursor-not-allowed"
                                                    title="No permission to remove participants">
                                                <i class="fas fa-user-minus"></i>
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- DESKTOP -->
                        <div class="hidden sm:block overflow-x-auto">
                            <table class="w-full text-left border-collapse">
                                <thead class="text-[10px] uppercase text-gray-500 border-b border-gray-800 bg-black/20">
                                    <tr>
                                        <th class="p-3 sm:p-4 pl-4 sm:pl-6">Member Name</th>
                                        <th class="p-3 sm:p-4">Rank</th>
                                        <th class="p-3 sm:p-4">Role</th>
                                        <th class="p-3 sm:p-4">Attendance</th>
                                        <th class="p-3 sm:p-4 text-right pr-4 sm:pr-6">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="text-sm">
                                    <?php if (empty($participants)): ?>
                                        <tr>
                                            <td colspan="5" class="text-center py-6 text-gray-400">No participants yet</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($participants as $participant): ?>
                                        <tr class="border-b border-gray-800/50 hover:bg-white/5 transition">
                                            <td class="p-3 sm:p-4 pl-4 sm:pl-6 font-bold"><?= edE($participant['full_name']) ?></td>
                                            <td class="p-3 sm:p-4 text-gray-400"><?= edE(trim((string) ($participant['rank'] ?? '')) ?: 'N/A') ?></td>
                                            <td class="p-3 sm:p-4"><span class="bg-blue-500/10 text-blue-400 px-2 py-0.5 rounded text-[10px] font-bold uppercase"><?= edE(trim((string) ($participant['role'] ?? '')) ?: 'Competitor') ?></span></td>
                                            <td class="p-3 sm:p-4"><?= edAttendanceControls($participant, $canMarkAttendance) ?></td>
                                            <td class="p-3 sm:p-4 text-right pr-4 sm:pr-6 space-x-2">
                                                <a href="member_profile.php?id=<?= (int) ($participant['member_id'] ?? 0) ?>" class="text-gray-500 hover:text-green-500 transition-colors" title="View member profile">
                                                    <i class="fa-solid fa-eye"></i>
                                                </a>
                                                <?php if ($canRemoveParticipant): ?>
                                                <button onclick="removeParticipant(<?= (int) ($participant['participant_id'] ?? 0) ?>)"
                                                        class="transition text-gray-600 hover:text-red-500 cursor-pointer"
                                                        title="Remove from event">
                                                    <i class="fas fa-user-minus"></i>
                                                </button>
                                                <?php else: ?>
                                                <button disabled
                                                        class="transition text-gray-700 opacity-50 cursor-not-allowed"
                                                        title="No permission to remove participants">
                                                    <i class="fas fa-user-minus"></i>
                                                </button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <!-- RESULTS -->
                    <section class="card-bg rounded-2xl sm:rounded-3xl overflow-hidden shadow-2xl">
                        <div class="p-4 sm:p-6 border-b border-gray-800 flex flex-col sm:flex-row justify-between items-start sm:items-center bg-white/5 gap-3">
                            <h2 class="font-bold text-xs sm:text-sm tracking-widest uppercase flex items-center gap-2">
                                <i class="fas fa-medal text-yellow-500"></i> Results &amp; Achievements (<?= count($achievements) ?>)
                            </h2>
                            <?php if ($canAddAchievement): ?>
                            <button onclick="openAchievementModal()"
                                    class="text-xs font-bold whitespace-nowrap text-yellow-500 hover:text-white cursor-pointer"
                                    title="Record member achievements">+ RECORD RESULT</button>
                            <?php else: ?>
                            <button disabled
                                    class="text-xs font-bold whitespace-nowrap text-gray-600 opacity-50 cursor-not-allowed"
                                    title="No permission to record achievements">+ RECORD RESULT</button>
                            <?php endif; ?>
                        </div>

                        <!-- MOBILE -->
                        <div class="block sm:hidden">
                            <div class="p-2 space-y-2">
                                <?php if (empty($achievements)): ?>
                                    <div class="text-center py-6 text-gray-400">No achievements recorded</div>
                                <?php else: ?>
                                    <?php foreach ($achievements as $achievement): ?>
                                    <div class="bg-white/[0.02] rounded-xl p-3 border border-gray-800/50">
                                        <div class="font-bold text-sm mb-2"><?= edE($achievement['full_name']) ?></div>
                                        <div class="flex items-center gap-2 text-yellow-500 font-bold mb-2 text-sm">
                                            <i class="fas fa-award"></i>
                                            <?= edAchievementEmoji($achievement['achievement_type'] ?? null) ?>
                                            <?= edE($achievement['achievement_type'] ?? '') ?>
                                        </div>
                                        <div class="flex justify-between items-end">
                                            <div class="flex flex-col text-[10px]">
                                                <span class="text-gray-400"><?= edE($achievement['level'] ?? '') ?> &middot; <?= edE(edFormatDate($achievement['achieved_at'] ?? null)) ?></span>
                                                <span class="italic text-gray-500"><?= edE(trim((string) ($achievement['notes'] ?? '')) ?: 'No notes') ?></span>
                                            </div>
                                            <?php if ($canDeleteAchievement): ?>
                                            <button onclick="openVoidModal('Achievement', <?= (int) ($achievement['achievement_id'] ?? 0) ?>, <?= edJsArg(trim((string) ($achievement['achievement_type'] ?? '')) . ' - ' . (string) ($achievement['full_name'] ?? '')) ?>)"
                                                    class="text-sm transition text-gray-600 hover:text-red-500 cursor-pointer"
                                                    title="Remove achievement">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                            <?php else: ?>
                                            <button disabled
                                                    class="text-sm transition text-gray-700 opacity-50 cursor-not-allowed"
                                                    title="No permission to remove achievements">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- DESKTOP -->
                        <div class="hidden sm:block overflow-x-auto">
                            <table class="w-full text-left border-collapse">
                                <thead class="text-[10px] uppercase text-gray-500 border-b border-gray-800 bg-black/20">
                                    <tr>
                                        <th class="p-3 sm:p-4 pl-4 sm:pl-6">Member</th>
                                        <th class="p-3 sm:p-4">Achievement</th>
                                        <th class="p-3 sm:p-4">Level</th>
                                        <th class="p-3 sm:p-4">Date</th>
                                        <th class="p-3 sm:p-4">Notes</th>
                                        <th class="p-3 sm:p-4 text-right pr-4 sm:pr-6">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="text-sm">
                                    <?php if (empty($achievements)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center py-6 text-gray-400">No achievements recorded</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($achievements as $achievement): ?>
                                        <tr class="border-b border-gray-800/50 hover:bg-white/5 transition">
                                            <td class="p-3 sm:p-4 pl-4 sm:pl-6 font-bold"><?= edE($achievement['full_name']) ?></td>
                                            <td class="p-3 sm:p-4">
                                                <span class="flex items-center gap-2 text-yellow-500 font-bold">
                                                    <?= edAchievementEmoji($achievement['achievement_type'] ?? null) ?> <?= edE($achievement['achievement_type'] ?? '') ?>
                                                </span>
                                            </td>
                                            <td class="p-3 sm:p-4 text-gray-400"><?= edE($achievement['level'] ?? '') ?></td>
                                            <td class="p-3 sm:p-4 text-gray-400 whitespace-nowrap"><?= edE(edFormatDate($achievement['achieved_at'] ?? null)) ?></td>
                                            <td class="p-3 sm:p-4 italic text-gray-500"><?= edE(trim((string) ($achievement['notes'] ?? '')) ?: '—') ?></td>
                                            <td class="p-3 sm:p-4 text-right pr-4 sm:pr-6">
                                                <?php if ($canDeleteAchievement): ?>
                                                <button onclick="openVoidModal('Achievement', <?= (int) ($achievement['achievement_id'] ?? 0) ?>, <?= edJsArg(trim((string) ($achievement['achievement_type'] ?? '')) . ' - ' . (string) ($achievement['full_name'] ?? '')) ?>)"
                                                        class="transition text-gray-600 hover:text-red-500 cursor-pointer"
                                                        title="Remove achievement">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                                <?php else: ?>
                                                <button disabled
                                                        class="transition text-gray-700 opacity-50 cursor-not-allowed"
                                                        title="No permission to remove achievements">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>

                <!-- SIDEBAR COLUMN -->
                <div class="space-y-4 sm:space-y-6 lg:space-y-8">

                    <!-- DOCUMENTATION -->
                    <section class="card-bg rounded-2xl sm:rounded-3xl overflow-hidden shadow-2xl">
                        <div class="p-4 sm:p-6 border-b border-gray-800 flex flex-col sm:flex-row justify-between items-start sm:items-center bg-white/5 gap-3">
                            <h2 class="font-bold text-xs sm:text-sm tracking-widest uppercase flex items-center gap-2">
                                <i class="fas fa-folder text-blue-500"></i> Event Documentation (<?= count($documents) ?>)
                            </h2>
                            <?php if ($canUploadDocument): ?>
                            <button onclick="openDocumentModal()"
                                    class="text-xs font-bold whitespace-nowrap text-blue-500 hover:text-white cursor-pointer"
                                    title="Upload event files">+ UPLOAD FILE</button>
                            <?php else: ?>
                            <button disabled
                                    class="text-xs font-bold whitespace-nowrap text-gray-600 opacity-50 cursor-not-allowed"
                                    title="No permission to upload documents">+ UPLOAD FILE</button>
                            <?php endif; ?>
                        </div>

                        <div class="p-4 sm:p-6">
                            <?php if (empty($documents)): ?>
                                <div class="text-center py-8 text-gray-400">No documents uploaded yet</div>
                            <?php else: ?>
                                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
                                    <?php foreach ($documents as $doc): ?>
                                    <?php
                                    $docId = (int) ($doc['document_id'] ?? 0);
                                    $docPath = (string) ($doc['file_path'] ?? '');
                                    $docType = (string) ($doc['file_type'] ?? '');
                                    ?>
                                    <div class="relative group">
                                        <div class="bg-black/20 rounded-lg p-3 text-center hover:bg-white/5 transition cursor-pointer"
                                             onclick="openDocumentPreview(<?= $docId ?>, <?= edJsArg($docPath) ?>, <?= edJsArg($docType) ?>)"
                                             title="Open <?= edE(basename($docPath)) ?>">
                                            <div class="text-blue-500 text-2xl mb-2"><?= edFileIconHtml($docType) ?></div>
                                            <p class="text-xs font-bold truncate"><?= edE(basename($docPath)) ?></p>
                                            <p class="text-[10px] text-gray-400"><?= edE($docType) ?></p>
                                            <p class="text-[10px] text-gray-500 mt-1"><?= edE(edFormatDate($doc['uploaded_at'] ?? null)) ?></p>

                                            <div class="mt-2 opacity-0 group-hover:opacity-100 transition-opacity">
                                                <a href="../actions/download_document.php?id=<?= $docId ?>"
                                                   class="inline-block bg-blue-500/20 text-blue-400 hover:bg-blue-500 hover:text-white px-3 py-1 rounded text-[10px] font-bold transition"
                                                   title="Download this file"
                                                   onclick="event.stopPropagation()">
                                                    <i class="fas fa-download mr-1"></i>Download
                                                </a>
                                            </div>
                                        </div>

                                        <?php if ($canDeleteDocument): ?>
                                        <button onclick="event.stopPropagation(); openVoidModal('Document', <?= $docId ?>, <?= edJsArg($docType . ': ' . basename($docPath)) ?>)"
                                                class="absolute top-1 right-1 bg-red-500/80 text-white rounded-full p-1 opacity-0 group-hover:opacity-100 transition-opacity hover:bg-red-600"
                                                title="Void document">
 <i class="fas fa-trash text-xs"></i>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </section>

                    <!-- FINANCIALS (funds.read only) -->
                    <?php if ($canViewFinancials): ?>
                    <section class="card-bg rounded-2xl sm:rounded-3xl p-4 sm:p-6">
                        <h2 class="font-bold text-xs tracking-widest uppercase mb-4">Event Financials</h2>
                        <div class="space-y-3 sm:space-y-4">
                            <div class="flex justify-between items-center">
                                <span class="text-gray-500 text-[10px] sm:text-xs">Income</span>
                                <span class="text-green-400 font-bold text-sm sm:text-base">&#8369;<?= number_format($financials['income'], 2) ?></span>
                            </div>
                            <div class="flex justify-between items-center border-b border-gray-800 pb-2">
                                <span class="text-gray-500 text-[10px] sm:text-xs">Expenses</span>
                                <span class="text-red-400 font-bold text-sm sm:text-base">&#8369;<?= number_format($financials['expenses'], 2) ?></span>
                            </div>
                            <div class="flex justify-between items-center pt-2">
                                <span class="text-white font-bold text-xs sm:text-sm">Net</span>
                                <span class="text-white font-black text-base sm:text-lg">&#8369;<?= number_format($financials['net'], 2) ?></span>
                            </div>
                        </div>

                        <p class="text-[10px] text-gray-600 mt-3">
                            <?= (int) $financials['count'] ?> linked transaction<?= ((int) $financials['count'] === 1) ? '' : 's' ?>
                        </p>

                        <?php if (!empty($recentFunds)): ?>
                        <ul class="mt-3 space-y-1 border-t border-gray-800 pt-3">
                            <?php foreach ($recentFunds as $tx): ?>
                            <li class="flex justify-between items-center gap-3 text-[10px]">
                                <span class="text-gray-500 truncate">
                                    <span class="<?= ((string) $tx['transaction_type']) === 'Income' ? 'text-green-500' : 'text-red-500' ?>">&bull;</span>
                                    <?= edE(trim((string) ($tx['source_or_purpose'] ?? '')) ?: 'Untitled') ?>
                                    <span class="text-gray-600"><?= edE(edFormatDate($tx['transaction_date'] ?? null)) ?></span>
                                </span>
                                <span class="<?= ((string) $tx['transaction_type']) === 'Income' ? 'text-green-400' : 'text-red-400' ?> font-bold whitespace-nowrap">
                                    <?= ((string) $tx['transaction_type']) === 'Income' ? '+' : '−' ?>&#8369;<?= number_format((float) ($tx['amount'] ?? 0), 2) ?>
                                </span>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php endif; ?>

                        <a href="funds.php" class="block text-center mt-4 sm:mt-6 text-[9px] sm:text-[10px] font-bold text-gray-500 hover:text-purple-400 uppercase tracking-widest transition">View in Funds Module <i class="fas fa-external-link-alt ml-1"></i></a>
                    </section>
                    <?php endif; ?>

                    <!-- REAL AUDIT METADATA -->
                    <?php if (!empty($meta['created_at']) || $lastUpdateIsLater): ?>
                    <div class="text-[9px] sm:text-[10px] text-gray-600 space-y-1 pl-2">
                        <?php if (!empty($meta['created_at'])): ?>
                        <p>
                            CREATED: <?= edE(edFormatDateTime($meta['created_at'])) ?>
                            <?php if (!empty($meta['created_by_name'])): ?>
                                &bull; <?= edE($meta['created_by_name']) ?>
                            <?php endif; ?>
                        </p>
                        <?php endif; ?>
                        <p>
                            EVENT HANDLER:
                            <?php if (!empty($meta['handler_name'])): ?>
                                <span class="text-gray-300"><?= edE($meta['handler_name']) ?></span>
                                <?php if (!empty($meta['handler_type'])): ?>
                                    <span class="text-gray-600">(<?= edE($meta['handler_type']) ?>)</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-yellow-500/80">Unassigned</span>
                                &mdash; only the creator can manage this event until a handler is assigned.
                            <?php endif; ?>
                        </p>
                        <?php if ($lastUpdateIsLater): ?>
                        <p>
                            LAST UPDATE: <?= edE(edFormatDateTime($meta['updated_at'])) ?>
                            <?php if (!empty($meta['updated_by_name'])): ?>
                                &bull; <?= edE($meta['updated_by_name']) ?>
                            <?php endif; ?>
                        </p>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <?php include_once __DIR__ . '/modals/add_participant.php'; ?>
    <?php include_once __DIR__ . '/modals/add_achievement.php'; ?>
    <?php include_once __DIR__ . '/modals/add_document.php'; ?>
    <?php include_once __DIR__ . '/modals/edit_event.php'; ?>
    <?php include_once __DIR__ . '/modals/void_confirmation.php'; ?>
    <?php include_once __DIR__ . '/modals/user_menu.php'; ?>
    <?php include_once __DIR__ . '/modals/edit_profile_user.php'; ?>

<script>
/* Page reveal runs in its own block so a later JS error can never leave a blank screen. */
document.addEventListener('DOMContentLoaded', () => {
    const page = document.getElementById('page');
    if (page) {
        page.classList.remove('opacity-0', 'translate-x-8');
    }
});
</script>
<script>
/* Event + CSRF constants. NOTE: raw json_encode (no htmlspecialchars) — in a
 * <script> block entities are NOT decoded, so edJsArg() would emit &quot;
 * and break the whole block, leaving #page at opacity-0 (blank page). */

const ED_EVENT_ID    = <?= (int) $eventId ?>;
const ED_CSRF_TOKEN  = <?= json_encode((string)$csrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

/* Guaranteed-safe fallback toast, only installed if no modal supplied one. */
if (typeof window.showNotification !== 'function') {
    window.showNotification = function (message, type) {
        const box = document.createElement('div');
        box.className = 'fixed top-6 left-1/2 -translate-x-1/2 px-6 py-4 rounded-xl shadow-2xl border flex items-center gap-3 z-[9999] '
            + (type === 'success'
                ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-400'
                : 'bg-amber-500/10 border-amber-500/30 text-amber-400');
        const icon = document.createElement('i');
        icon.className = type === 'success' ? 'fas fa-check-circle text-lg' : 'fas fa-exclamation-circle text-lg';
        const text = document.createElement('span');
        text.className = 'text-sm font-semibold';
        text.textContent = String(message == null ? '' : message);
        box.appendChild(icon);
        box.appendChild(text);
        document.body.appendChild(box);
        setTimeout(() => box.remove(), 3000);
    };
}

/* ---------- helpers ------------------------------------------------ */

async function edPostJson(url, params) {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams(params).toString()
    });

    const raw = await response.text();
    try {
        return JSON.parse(raw);
    } catch (e) {
        throw new Error(response.ok
            ? 'Unexpected server response.'
            : 'Request failed (HTTP ' + response.status + ').');
    }
}

function edRenderMessage(wrap, text, toneClass, iconClass) {
    wrap.textContent = '';
    const box = document.createElement('div');
    box.className = 'text-center py-10 ' + toneClass;

    if (iconClass) {
        const icon = document.createElement('i');
        icon.className = iconClass;
        box.appendChild(icon);
        box.appendChild(document.createElement('br'));
    }

    box.appendChild(document.createTextNode(String(text == null ? '' : text)));
    wrap.appendChild(box);
}

function edBadge(text, className) {
    const span = document.createElement('span');
    span.className = 'px-2 py-1 rounded-full text-[10px] font-bold ' + className;
    span.textContent = String(text == null ? '' : text);
    return span;
}

function edBadgeClassForProgram(program) {
    if (program === 'Private') return 'bg-yellow-500/10 text-yellow-300';
    if (program === 'Mixed')   return 'bg-pink-500/10 text-pink-300';
    return 'bg-green-500/10 text-green-300';
}

/* ---------- participants ------------------------------------------ */

async function removeParticipant(participantId) {
    if (!confirm('Remove this participant from the event?')) {
        return;
    }

    try {
        const data = await edPostJson('../actions/remove_from_event.php', {
            participant_id: String(participantId),
            event_id: String(ED_EVENT_ID),
            csrf_token: ED_CSRF_TOKEN
        });

        if (data && data.success) {
            showNotification(data.message || 'Participant removed.', 'success');
            setTimeout(() => location.reload(), 1200);
        } else {
            showNotification((data && (data.error || data.message)) || 'Failed to remove participant.', 'error');
        }
    } catch (err) {
        showNotification(err.message || 'Failed to remove participant.', 'error');
    }
}

/* ---------- event attendance ---------------------------------------- */

function edAttPillClass(status) {
    if (status === 'Present') return 'bg-green-500/15 text-green-400 border-green-500/20';
    if (status === 'Late')    return 'bg-orange-500/15 text-orange-400 border-orange-500/20';
    if (status === 'Absent')  return 'bg-red-500/15 text-red-400 border-red-500/20';
    return 'bg-gray-500/10 text-gray-400 border-gray-500/20';
}

function edAttBtnClass(status, active) {
    if (active) {
        if (status === 'Present') return 'bg-green-500 text-white shadow-lg shadow-green-900/40';
        if (status === 'Late')    return 'bg-orange-500 text-white shadow-lg shadow-orange-900/40';
        return 'bg-red-500 text-white shadow-lg shadow-red-900/40';
    }
    const hover = status === 'Present' ? 'hover:text-green-400'
        : (status === 'Late' ? 'hover:text-orange-400' : 'hover:text-red-400');
    return 'bg-gray-800/60 text-gray-500 ' + hover;
}

/** Repaints every copy of this participant's controls (mobile card + desktop row)
 *  and nudges the Present/Late/Absent counters without a page reload. */
function edPaintAttendance(participantId, status) {
    const wraps = document.querySelectorAll('[data-att-wrap="' + participantId + '"]');
    if (!wraps.length) return;

    const previous = wraps[0].getAttribute('data-att-current') || '';

    ['Present', 'Late', 'Absent'].forEach(function (key) {
        const el = document.querySelector('[data-att-count="' + key + '"]');
        if (!el) return;
        let n = parseInt(el.textContent, 10) || 0;
        if (key === previous && previous !== status) n = Math.max(0, n - 1);
        if (key === status && previous !== status) n += 1;
        el.textContent = String(n);
    });

    wraps.forEach(function (wrap) {
        wrap.setAttribute('data-att-current', status);
        const pill = wrap.querySelector('[data-att-pill]');
        if (pill) {
            pill.textContent = status;
            pill.className = 'px-2 py-1 rounded-full text-[9px] font-bold uppercase tracking-wider border ' + edAttPillClass(status);
        }
        wrap.querySelectorAll('[data-att-btn]').forEach(function (btn) {
            const active = btn.getAttribute('data-att-btn') === status;
            btn.className = 'w-7 h-7 rounded-lg flex items-center justify-center text-[10px] transition cursor-pointer ' + edAttBtnClass(btn.getAttribute('data-att-btn'), active);
        });
    });
}

async function updateEventAttendance(participantId, status) {
    try {
        const data = await edPostJson('../actions/update_event_attendance.php', {
            participant_id: String(participantId),
            event_id: String(ED_EVENT_ID),
            status: String(status),
            csrf_token: ED_CSRF_TOKEN
        });

        if (data && data.success) {
            edPaintAttendance(participantId, status);
            showNotification(data.message || ('Marked ' + status + '.'), 'success');
        } else {
            showNotification((data && (data.error || data.message)) || 'Failed to update attendance.', 'error');
        }
    } catch (err) {
        showNotification(err.message || 'Failed to update attendance.', 'error');
    }
}

async function quickAddSuggestedPlayer(memberId, role, memberName) {
    try {
        const data = await edPostJson('../actions/process_add_participant.php', {
            event_id: String(ED_EVENT_ID),
            member_id: String(memberId),
            role: String(role || 'Competitor'),
            csrf_token: ED_CSRF_TOKEN
        });

        if (data && data.success) {
            showNotification((memberName || 'Player') + ' added to event.', 'success');
            setTimeout(() => location.reload(), 1200);
        } else {
            showNotification((data && (data.error || data.message)) || 'Failed to add suggested player.', 'error');
        }
    } catch (err) {
        showNotification(err.message || 'Failed to add suggested player.', 'error');
    }
}

/* ---------- documents ---------------------------------------------- */

function openDocumentPreview(documentId, filePath, fileType) {
    // Images open inline from their stored CDN URL; every other type goes
    // through download_document.php, which enforces RBAC + void state and
    // validates the host allow-list server-side.
    const url = (fileType === 'Image')
        ? String(filePath || '')
        : '../actions/download_document.php?id=' + encodeURIComponent(String(documentId));

    if (!url) {
        return;
    }
    window.open(url, '_blank', 'noopener,noreferrer');
}

/* ---------- suggestions (DOM built — no HTML string interpolation) -- */

function edBuildSuggestionCard(player) {
    const card = document.createElement('div');
    card.className = 'rounded-2xl border border-white/5 bg-white/[0.03] p-4';

    /* header */
    const head = document.createElement('div');
    head.className = 'flex items-start justify-between gap-3 mb-3';

    const headLeft = document.createElement('div');
    const nameEl = document.createElement('div');
    nameEl.className = 'font-black text-sm';
    nameEl.textContent = String(player.full_name || 'Unnamed member');

    const metaEl = document.createElement('div');
    metaEl.className = 'text-[10px] text-gray-400 uppercase tracking-[0.16em] mt-1';
    metaEl.textContent = String(player.rank || 'None') + ' • ' + String(player.member_type || 'Player');

    headLeft.appendChild(nameEl);
    headLeft.appendChild(metaEl);

    const headRight = document.createElement('div');
    headRight.className = 'text-right';
    const scoreLabel = document.createElement('div');
    scoreLabel.className = 'text-[10px] uppercase tracking-[0.16em] text-gray-500';
    scoreLabel.textContent = 'Score';
    const scoreValue = document.createElement('div');
    scoreValue.className = 'text-xl font-black text-purple-300';
    scoreValue.textContent = String(player.score == null ? 0 : player.score);
    headRight.appendChild(scoreLabel);
    headRight.appendChild(scoreValue);

    head.appendChild(headLeft);
    head.appendChild(headRight);
    card.appendChild(head);

    /* badges */
    const badges = document.createElement('div');
    badges.className = 'flex flex-wrap gap-2 mb-3';
    badges.appendChild(edBadge(player.player_category, edBadgeClassForProgram(player.player_category)));
    badges.appendChild(edBadge(player.training_preference || 'Group', 'bg-white/5 text-gray-300'));

    const pastCount = Number(player.past_events) || 0;
    badges.appendChild(edBadge(
        pastCount + ' past event' + (pastCount === 1 ? '' : 's'),
        'bg-blue-500/10 text-blue-300'
    ));
    card.appendChild(badges);

    /* reason */
    const reason = document.createElement('p');
    reason.className = 'text-xs text-gray-400 leading-relaxed min-h-[56px]';
    reason.textContent = String(player.reason || 'Suggested from available member data.');
    card.appendChild(reason);

    /* actions */
    const actions = document.createElement('div');
    actions.className = 'mt-4 flex gap-2';

    const addBtn = document.createElement('button');
    addBtn.type = 'button';
    addBtn.className = 'flex-1 px-3 py-3 rounded-xl bg-purple-500/10 hover:bg-purple-500 hover:text-white text-purple-300 transition text-[11px] font-black uppercase tracking-[0.16em]';
    addBtn.textContent = 'Add to Event';
    addBtn.addEventListener('click', () => {
        quickAddSuggestedPlayer(player.member_id, player.suggested_role || 'Competitor', player.full_name || 'Player');
    });
    actions.appendChild(addBtn);

    const viewLink = document.createElement('a');
    viewLink.className = 'px-3 py-3 rounded-xl bg-white/5 hover:bg-white/10 text-gray-300 transition text-[11px] font-black uppercase tracking-[0.16em]';
    viewLink.textContent = 'View';
    viewLink.href = 'member_profile.php?id=' + encodeURIComponent(String(player.member_id));
    actions.appendChild(viewLink);

    card.appendChild(actions);
    return card;
}

async function loadSuggestedPlayers() {
    const wrap = document.getElementById('suggestedPlayersWrap');
    if (!wrap) {
        return;
    }

    edRenderMessage(wrap, 'Generating suggestions...', 'text-gray-400', 'fas fa-spinner fa-spin text-2xl mb-3');

    try {
        const response = await fetch(
            '../actions/get_suggested_players.php?event_id=' + encodeURIComponent(String(ED_EVENT_ID)),
            { headers: { 'Accept': 'application/json' } }
        );

        const raw = await response.text();
        let data;
        try {
            data = JSON.parse(raw);
        } catch (e) {
            throw new Error('Unexpected server response (HTTP ' + response.status + ').');
        }

        if (!data || data.success !== true) {
            edRenderMessage(wrap, (data && (data.message || data.error)) || 'Failed to load suggestions.', 'text-red-300', '');
            return;
        }

        const list = Array.isArray(data.suggestions) ? data.suggestions : [];

        if (list.length === 0) {
            edRenderMessage(wrap, data.message || 'No eligible suggestions found.', 'text-gray-400', 'fas fa-circle-info text-2xl mb-3 opacity-40');
            return;
        }

        wrap.textContent = '';
        const grid = document.createElement('div');
        grid.className = 'grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4';
        list.forEach((player) => grid.appendChild(edBuildSuggestionCard(player)));
        wrap.appendChild(grid);
    } catch (err) {
        edRenderMessage(wrap, err.message || 'Unable to load player suggestions right now.', 'text-red-300', '');
    }
}
</script>
<?php if ($canSelfCheckinEvent && $viewerParticipant !== null): ?>
<!-- Face + GPS Self Check-in (Member) -->
<script src="https://cdn.jsdelivr.net/npm/@vladmandic/face-api@1.7.12/dist/face-api.min.js"></script>
<?php
$checkinType = 'event';
$checkinEvent = $event;
$checkinEventId = $eventId;
$checkinMemberId = $viewerMemberId;
$checkinCsrf = $csrfToken;
include __DIR__ . '/modals/attendance_checkin.php';
?>
<?php endif; ?>
</body>
</html>