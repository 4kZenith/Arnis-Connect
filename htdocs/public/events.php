<?php
require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/Events.php';

/*
 * config/permissions.php is the single RBAC source of truth. The inline matrix
 * that used to live here gave Coach/Trainer events.read = true instead of
 * 'own', so $userRestrictions was never populated and coaches saw every event.
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

if (!canAccess('events', 'read')) {
    http_response_code(403);
    die('Access Denied: You do not have permission to view events.');
}

$allowedEventTypes = ['Performance', 'Tournament', 'Outreach'];

$readPerm = getPermission('events', 'read');
$writePerm = getPermission('events', 'write');
$canVoidEvents = canAccess('events', 'delete');

/*
 * GET values are attacker-controlled. Anything that is not a plain string is
 * discarded (?search[]=x would otherwise reach Events::getAll() as an array and
 * blow up on string concatenation), and dates must be real Y-m-d values or the
 * SQL comparison silently matches nothing.
 */
$rawSearch = (isset($_GET['search']) && is_string($_GET['search'])) ? $_GET['search'] : '';
$rawType = (isset($_GET['type']) && is_string($_GET['type'])) ? $_GET['type'] : '';
$rawStartDate = (isset($_GET['start_date']) && is_string($_GET['start_date'])) ? $_GET['start_date'] : '';
$rawEndDate = (isset($_GET['end_date']) && is_string($_GET['end_date'])) ? $_GET['end_date'] : '';

$filters = [
    'search'     => mb_substr(trim($rawSearch), 0, 100),
    'type'       => in_array($rawType, $allowedEventTypes, true) ? $rawType : '',
    'start_date' => '',
    'end_date'   => '',
];

foreach (['start_date' => $rawStartDate, 'end_date' => $rawEndDate] as $key => $value) {
    if ($value === '') {
        continue;
    }
    $parsed = DateTime::createFromFormat('Y-m-d', $value);
    if ($parsed && $parsed->format('Y-m-d') === $value) {
        $filters[$key] = $value;
    }
}

// Swap the range if the user entered it backwards so the page still renders.
if ($filters['start_date'] !== '' && $filters['end_date'] !== ''
    && $filters['start_date'] > $filters['end_date']) {
    [$filters['start_date'], $filters['end_date']] = [$filters['end_date'], $filters['start_date']];
}

$userRestrictions = [];
if ($readPerm === 'own') {
    if (currentUserRole() === 'Member') {
        // Members see only events they are rostered on as participants.
        $sessionMemberId = isset($_SESSION['member_id']) && is_numeric($_SESSION['member_id'])
            ? (int)$_SESSION['member_id']
            : 0;
        if ($sessionMemberId > 0) {
            $userRestrictions['participant_member_id'] = $sessionMemberId;
        }
    } else {
        $userRestrictions['created_by'] = (int)$_SESSION['user_id'];
    }
}

$perPage = 10;
$page = (isset($_GET['page']) && is_numeric($_GET['page'])) ? max(1, (int)$_GET['page']) : 1;

try {
    $db = (new Database())->connect();
    $eventsModel = new Events($db);
    $eventHandlers = $eventsModel->getEligibleHandlers();

    $totalEvents = $eventsModel->getCount($filters, $userRestrictions);
    $totalPages = max(0, (int)ceil($totalEvents / $perPage));
    $page = min($page, max(1, $totalPages));

    $events = $eventsModel->getAll($filters, $perPage, $page, $userRestrictions);
    $stats = $eventsModel->getStats($filters, $userRestrictions);
} catch (Throwable $e) {
    error_log('events.php error: ' . $e->getMessage());
    die('Error loading events. Please contact the administrator.');
}

function formatDate($date): string
{
    $date = (string)($date ?? '');
    if ($date === '' || str_starts_with($date, '0000-00-00')) {
        return 'TBA';
    }
    $timestamp = strtotime($date);
    return $timestamp === false ? 'TBA' : date('M d, Y', $timestamp);
}

function getEventTypeBadge($type): array
{
    $badges = [
        'Performance' => ['bg' => 'bg-blue-500/10', 'text' => 'text-blue-400', 'icon' => 'fa-star'],
        'Tournament'  => ['bg' => 'bg-purple-500/10', 'text' => 'text-purple-400', 'icon' => 'fa-trophy'],
        'Outreach'    => ['bg' => 'bg-orange-500/10', 'text' => 'text-orange-400', 'icon' => 'fa-bullhorn'],
    ];
    return $badges[(string)$type] ?? $badges['Performance'];
}

/**
 * Builds the openVoidModal() argument list.
 *
 * htmlspecialchars(..., ENT_QUOTES) is unsafe here: the browser HTML-decodes
 * the attribute before running the JavaScript, so &#039; becomes ' again and
 * escapes the string literal. json_encode with the HEX flags emits
 * \u0027 / \u003C / \u003E instead, which survive both layers.
 */
function evVoidOnclick(array $event, bool $enabled): string
{
    $args = [
        'Event',
        (int)$event['event_id'],
        json_encode(
            (string)($event['event_name'] ?? ''),
            JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE
        ),
    ];
    return 'openVoidModal(' . implode(', ', $args) . ')';
}

$pageLink = static function (int $target) use ($filters): string {
    $query = array_filter($filters, static fn($value) => $value !== '');
    $query['page'] = $target;
    return '?' . http_build_query($query);
};
?>
<!DOCTYPE html>
<html lang="en" class="text-[13px]">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Events | Arnis Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" type="image/x-icon" href="../arnis-favicon.ico">
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
        <main id="page" class="flex-1 p-4 sm:p-6 lg:p-8 h-screen overflow-y-auto opacity-0 translate-x-8 transition-all duration-500 ease-out">
            <?php include_once __DIR__ . '/../includes/header.php'; ?>

            <!-- Stats Cards -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4 lg:gap-6 mb-6 lg:mb-8">
                <div class="card-bg p-4 sm:p-6 rounded-2xl sm:rounded-3xl text-center shadow-xl">
                    <i class="fas fa-trophy text-xl sm:text-2xl mb-2 opacity-50"></i>
                    <div class="text-2xl sm:text-3xl lg:text-4xl font-bold"><?= (int)($stats['total'] ?? 0) ?></div>
                    <div class="text-[10px] sm:text-xs text-gray-400 uppercase tracking-widest mt-1">Total Events</div>
                </div>
                <div class="card-bg p-4 sm:p-6 rounded-2xl sm:rounded-3xl text-center shadow-xl">
                    <i class="fas fa-star text-xl sm:text-2xl mb-2 text-blue-400 opacity-80"></i>
                    <div class="text-2xl sm:text-3xl lg:text-4xl font-bold"><?= (int)($stats['performances'] ?? 0) ?></div>
                    <div class="text-[10px] sm:text-xs text-gray-400 uppercase tracking-widest mt-1">Performances</div>
                </div>
                <div class="card-bg p-4 sm:p-6 rounded-2xl sm:rounded-3xl text-center shadow-xl">
                    <i class="fas fa-trophy text-xl sm:text-2xl mb-2 text-purple-400 opacity-80"></i>
                    <div class="text-2xl sm:text-3xl lg:text-4xl font-bold"><?= (int)($stats['tournaments'] ?? 0) ?></div>
                    <div class="text-[10px] sm:text-xs text-gray-400 uppercase tracking-widest mt-1">Tournaments</div>
                </div>
                <div class="card-bg p-4 sm:p-6 rounded-2xl sm:rounded-3xl text-center shadow-xl">
                    <i class="fas fa-bullhorn text-xl sm:text-2xl mb-2 text-orange-400 opacity-80"></i>
                    <div class="text-2xl sm:text-3xl lg:text-4xl font-bold"><?= (int)($stats['outreach'] ?? 0) ?></div>
                    <div class="text-[10px] sm:text-xs text-gray-400 uppercase tracking-widest mt-1">Outreach</div>
                </div>
            </div>

            <!-- Filters -->
            <form method="get" action="events.php" id="eventsFilterForm"
                  class="flex flex-col xl:flex-row justify-between items-stretch xl:items-center mb-6 gap-4">
                <div class="flex flex-col sm:flex-row flex-wrap gap-2 sm:gap-3 flex-1">
                    <input type="search" name="search" id="searchInput"
                           placeholder="Search event name, location, description..."
                           value="<?= htmlspecialchars($filters['search'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                           autocomplete="off"
                           class="card-bg rounded-full px-4 py-3 text-xs sm:text-sm outline-none w-full sm:w-56">
                    <select name="type" id="filterType" class="card-bg border-none rounded-full px-4 py-3 text-xs sm:text-sm outline-none text-center" onchange="this.form.submit()">
                        <option value="">All Types</option>
                        <option value="Performance"<?= ($filters['type'] ?? '') === 'Performance' ? ' selected' : '' ?>>Performance</option>
                        <option value="Tournament"<?= ($filters['type'] ?? '') === 'Tournament' ? ' selected' : '' ?>>Tournament</option>
                        <option value="Outreach"<?= ($filters['type'] ?? '') === 'Outreach' ? ' selected' : '' ?>>Outreach</option>
                    </select>
                    <input type="date" name="start_date" id="startDate" value="<?= htmlspecialchars($filters['start_date'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                           class="card-bg border-none rounded-full px-4 py-3 text-xs sm:text-sm outline-none text-center">
                    <input type="date" name="end_date" id="endDate" value="<?= htmlspecialchars($filters['end_date'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                           class="card-bg border-none rounded-full px-4 py-3 text-xs sm:text-sm outline-none text-center">
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="bg-accent-purple hover:opacity-90 px-5 py-4 rounded-full font-bold text-xs sm:text-sm whitespace-nowrap transition">
                        <i class="fas fa-filter mr-1"></i> Apply
                    </button>
                    <?php 
                    $hasActiveFilters = ($filters['search'] ?? '') !== '' || ($filters['type'] ?? '') !== '' || ($filters['start_date'] ?? '') !== '' || ($filters['end_date'] ?? '') !== '';
                    if ($hasActiveFilters): ?>
                    <a href="events.php" class="bg-white/5 hover:bg-white/10 px-5 py-4 rounded-full font-bold text-xs sm:text-sm whitespace-nowrap transition">
                        <i class="fas fa-redo mr-1"></i> Reset
                    </a>
                    <?php endif; ?>
                    <button type="button" onclick="openEventModal()"
                            class="px-5 sm:px-6 py-4 rounded-full font-bold text-xs sm:text-sm whitespace-nowrap transition <?= $writePerm !== false ? 'bg-accent-purple hover:opacity-90 cursor-pointer' : 'bg-gray-600 opacity-50 cursor-not-allowed' ?>"
                            <?= $writePerm !== false ? '' : 'disabled' ?>>
                        + Create Event
                    </button>
                </div>
            </form>

            <!-- Events List -->
            <div class="card-bg rounded-2xl sm:rounded-3xl overflow-hidden shadow-xl">
                <!-- Mobile Card View -->
                <div class="block lg:hidden" id="mobileEventsView">
                    <div class="p-2 space-y-2">
                        <?php if (empty($events)): ?>
                            <div class="text-center py-8 text-gray-500">No events found.</div>
                        <?php else: ?>
                            <?php foreach ($events as $event): ?>
                                <?php
                                $badge = getEventTypeBadge($event['event_type'] ?? '');
                                $eventDate = formatDate($event['event_date'] ?? null);
                                $voidOnclick = evVoidOnclick($event, $canVoidEvents);
                                $canViewDetail = true; // All authenticated users can view event detail
                                ?>
                                <div class="bg-white/[0.02] rounded-xl p-4 border border-gray-800/50">
                                    <div class="flex justify-between items-start mb-3">
                                        <div class="flex-1 min-w-0">
                                            <div class="font-bold text-sm mb-1"><?= htmlspecialchars((string)($event['event_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                            <div class="text-[10px] text-gray-400"><?= htmlspecialchars($eventDate, ENT_QUOTES, 'UTF-8') ?></div>
                                        </div>
                                        <span class="<?= $badge['bg'] ?> <?= $badge['text'] ?> px-2 py-1 rounded-full text-[9px] font-bold uppercase inline-block">
                                            <?= htmlspecialchars((string)($event['event_type'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </div>
                                    <div class="text-[10px] text-gray-400 space-y-1 mb-3">
                                        <div><i class="fas fa-map-marker-alt w-4"></i> <?= htmlspecialchars((string)($event['location'] ?? 'N/A'), ENT_QUOTES, 'UTF-8') ?></div>
                                        <div><i class="fas fa-calendar w-4"></i> Created: <?= formatDate($event['created_at'] ?? null) ?></div>
                                    </div>
                                    <div class="flex gap-2">
                                        <a href="event_detail.php?id=<?= (int)($event['event_id'] ?? 0) ?>"
                                           class="flex-1 bg-blue-500/10 text-blue-400 text-center py-2 rounded-lg text-[10px] font-bold hover:bg-blue-500 hover:text-white transition">
                                            <i class="fas fa-external-link-alt mr-1"></i> View Details
                                        </a>
                                        <button type="button" onclick="<?= htmlspecialchars(evVoidOnclick($event, $canVoidEvents), ENT_QUOTES, 'UTF-8') ?>"
                                                class="w-10 text-xs transition rounded-lg
                                                     <?= $canVoidEvents ? 'bg-white/5 hover:bg-orange-500/20 text-gray-500 hover:text-orange-500 cursor-pointer' : 'bg-gray-700 text-gray-700 opacity-50 cursor-not-allowed' ?>"
                                                title="<?= $canVoidEvents ? 'Void event' : 'No permission to void events' ?>"
                                                <?= $canVoidEvents ? '' : 'disabled' ?>>
                                            <i class="fas fa-ban"></i>
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Desktop Table View -->
                <div class="hidden lg:block overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead class="text-gray-400 text-xs uppercase tracking-widest border-b border-gray-700">
                            <tr>
                                <th class="p-4 lg:p-6">ID</th>
                                <th class="p-4 lg:p-6">Event Name</th>
                                <th class="p-4 lg:p-6">Type</th>
                                <th class="p-4 lg:p-6">Date</th>
                                <th class="p-4 lg:p-6">Location</th>
                                <th class="p-4 lg:p-6">Description</th>
                                <th class="p-4 lg:p-6">Created</th>
                                <th class="p-4 lg:p-6 text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="text-sm" id="desktopEventsTable">
                            <?php if (empty($events)): ?>
                                <tr>
                                    <td colspan="8" class="text-center text-gray-500 py-8">No events found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($events as $event): ?>
                                    <?php
                                    $badge = getEventTypeBadge($event['event_type'] ?? null);
                                    $eventDate = formatDate($event['event_date'] ?? '');
                                    $voidOnclick = evVoidOnclick($event, $canVoidEvents);
                                    ?>
                                    <tr class="border-b border-gray-800 hover:bg-white/5 transition">
                                        <td class="p-4 lg:p-6 text-gray-500"><?= (int)($event['event_id'] ?? 0) ?></td>
                                        <td class="p-4 lg:p-6 font-bold tracking-wide"><?= htmlspecialchars((string)($event['event_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td class="p-4 lg:p-6">
                                            <span class="<?= $badge['bg'] ?> <?= $badge['text'] ?> px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-tighter">
                                                <?= htmlspecialchars((string)($event['event_type'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                        </td>
                                        <td class="p-4 lg:p-6 text-gray-300"><?= htmlspecialchars($eventDate, ENT_QUOTES, 'UTF-8') ?></td>
                                        <td class="p-4 lg:p-6 text-gray-400 italic"><?= htmlspecialchars((string)($event['location'] ?? 'N/A'), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td class="p-4 lg:p-6 text-gray-400 max-w-xs truncate" title="<?= htmlspecialchars((string)($event['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars((string)($event['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                        </td>
                                        <td class="p-4 lg:p-6 text-gray-400 whitespace-nowrap"><?= formatDate($event['created_at'] ?? '') ?></td>
                                        <td class="p-4 lg:p-6 text-center space-x-2">
                                            <a href="event_detail.php?id=<?= (int)($event['event_id'] ?? 0) ?>"
                                               class="text-gray-500 hover:text-purple-400 transition">
                                                <i class="fas fa-external-link-alt text-purple-400"></i>
                                            </a>
                                            <button type="button" onclick="<?= htmlspecialchars(evVoidOnclick($event, $canVoidEvents), ENT_QUOTES, 'UTF-8') ?>"
                                                    class="transition text-xs
                                                        <?= $canVoidEvents ? 'text-gray-600 hover:text-orange-500 cursor-pointer' : 'text-gray-700 opacity-50 cursor-not-allowed' ?>"
                                                    title="<?= $canVoidEvents ? 'Void event' : 'No permission to void events' ?>"
                                                    <?= $canVoidEvents ? '' : 'disabled' ?>>
                                                <i class="fas fa-ban"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                    <div id="eventsPagination" class="px-4 py-4 border-t border-gray-800 flex justify-between items-center flex-wrap gap-3">
                        <div class="text-[10px] text-gray-500 uppercase">
                            Showing <?= $totalEvents > 0 ? min((($page - 1) * $perPage) + 1, $totalEvents) : 0 ?> to <?= min($page * $perPage, $totalEvents) ?> of <?= (int)$totalEvents ?> events
                        </div>
                        <div class="flex gap-2">
                            <?php if ($page > 1): ?>
                            <a href="<?= htmlspecialchars($pageLink(1), ENT_QUOTES, 'UTF-8') ?>" class="bg-white/5 hover:bg-white/10 px-3 py-2 rounded-lg text-[10px] font-bold transition">
                                <i class="fas fa-chevron-left mr-1"></i>First
                            </a>
                            <a href="<?= htmlspecialchars($pageLink($page - 1), ENT_QUOTES, 'UTF-8') ?>" class="bg-white/5 hover:bg-white/10 px-3 py-2 rounded-lg text-[10px] font-bold transition">
                                <i class="fas fa-chevron-left mr-1"></i>Prev
                            </a>
                            <?php endif; ?>

                            <span class="px-3 py-2 text-[10px] font-bold text-gray-400">
                                Page <?= (int)$page ?> of <?= (int)$totalPages ?>
                            </span>

                            <?php if ($page < $totalPages): ?>
                            <a href="<?= htmlspecialchars($pageLink($page + 1), ENT_QUOTES, 'UTF-8') ?>" class="bg-white/5 hover:bg-white/10 px-3 py-2 rounded-lg text-[10px] font-bold transition">
                                Next<i class="fas fa-chevron-right ml-1"></i>
                            </a>
                            <a href="<?= htmlspecialchars($pageLink($totalPages), ENT_QUOTES, 'UTF-8') ?>" class="bg-white/5 hover:bg-white/10 px-3 py-2 rounded-lg text-[10px] font-bold transition">
                                Last<i class="fas fa-chevron-right ml-1"></i>
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <?php include_once __DIR__ . '/modals/add_event.php'; ?>
    <?php include_once __DIR__ . '/modals/edit_event.php'; ?>
    <?php include_once __DIR__ . '/modals/void_confirmation.php'; ?>
    <?php include_once __DIR__ . '/modals/user_menu.php'; ?>
    <?php include_once __DIR__ . '/modals/edit_profile_user.php'; ?>
</body>

<script>
window.addEventListener("load", () => {
    document.getElementById("page").classList.remove("opacity-0", "translate-x-8");
});

/*
 * The month and type filters submit the form, which re-renders the stats cards,
 * both view variants and the pagination links from one query string.
 */
function applyEventsFilters() {
    document.getElementById('eventsFilterForm').submit();
}
</script>
</html>