<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/Events.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['success' => false, 'error' => 'Invalid request method.']);
    exit;
}

if (!hasPermission('events', 'read')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$allowedEventTypes = ['Performance', 'Tournament', 'Outreach'];

$filters = [
    'search'     => trim((string)($_POST['search'] ?? '')),
    'type'       => trim((string)($_POST['type'] ?? '')),
    'start_date' => trim((string)($_POST['start_date'] ?? '')),
    'end_date'   => trim((string)($_POST['end_date'] ?? '')),
];

if ($filters['type'] !== '' && !in_array($filters['type'], $allowedEventTypes, true)) {
    $filters['type'] = '';
}

if (mb_strlen($filters['search']) > 100) {
    $filters['search'] = mb_substr($filters['search'], 0, 100);
}

foreach (['start_date', 'end_date'] as $dateKey) {
    if ($filters[$dateKey] === '') {
        continue;
    }
    $parsed = DateTime::createFromFormat('Y-m-d', $filters[$dateKey]);
    if (!$parsed || $parsed->format('Y-m-d') !== $filters[$dateKey]) {
        $filters[$dateKey] = '';
    }
}

if ($filters['start_date'] !== '' && $filters['end_date'] !== ''
    && $filters['start_date'] > $filters['end_date']) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Start date cannot be after end date.']);
    exit;
}

/*
 * Role scoping. Coach/Trainer has events.read = 'own', which the previous
 * version never applied because it only ever filtered in PHP over an
 * unfiltered 25-row page.
 */
$userRestrictions = [];
if (permissionValue('events', 'read') === 'own') {
    $userRestrictions['created_by'] = (int)$_SESSION['user_id'];
}

$perPage = isset($_POST['per_page']) && is_numeric($_POST['per_page'])
    ? min(50, max(1, (int)$_POST['per_page']))
    : 10;
$page = isset($_POST['page']) && is_numeric($_POST['page'])
    ? max(1, (int)$_POST['page'])
    : 1;

try {
    $db = (new Database())->connect();
    $eventsModel = new Events($db);

    $totalEvents = $eventsModel->getCount($filters, $userRestrictions);
    $totalPages = max(1, (int)ceil($totalEvents / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }

    $filteredEvents = $eventsModel->getAll($filters, $perPage, $page, $userRestrictions);

    $canVoidEvents = hasPermission('events', 'delete');
} catch (PDOException $e) {
    error_log('filter_events DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error.']);
    exit;
} catch (Throwable $e) {
    error_log('filter_events error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to load events.']);
    exit;
}

if (!function_exists('arnisFilterEventsFormatDate')) {
    function arnisFilterEventsFormatDate($date): string
    {
        $date = (string)($date ?? '');
        if ($date === '' || str_starts_with($date, '0000-00-00')) {
            return 'TBA';
        }
        $timestamp = strtotime($date);
        return $timestamp === false ? 'TBA' : date('M d, Y', $timestamp);
    }
}

if (!function_exists('arnisFilterEventsBadge')) {
    function arnisFilterEventsBadge(?string $type): array
    {
        $badges = [
            'Performance' => ['bg' => 'bg-blue-500/10', 'text' => 'text-blue-400', 'icon' => 'fa-star'],
            'Tournament'  => ['bg' => 'bg-purple-500/10', 'text' => 'text-purple-400', 'icon' => 'fa-trophy'],
            'Outreach'    => ['bg' => 'bg-orange-500/10', 'text' => 'text-orange-400', 'icon' => 'fa-bullhorn'],
        ];
        return $badges[(string)$type] ?? $badges['Performance'];
    }
}

/**
 * Builds the onclick argument list for openVoidModal().
 *
 * htmlspecialchars(..., ENT_QUOTES) is NOT safe here: the browser HTML-decodes
 * the attribute value before evaluating the JavaScript, so &#039; turns back
 * into ' and escapes the string literal. json_encode with the HEX flags emits
 * \u0027 / \u003C / \u003E instead, which survive both layers.
 */
function arnisFilterEventsVoidOnclick(array $event, bool $enabled): string
{
    $args = [
        "'Event'",
        (string)(int)$event['event_id'],
        json_encode(
            (string)($event['event_name'] ?? ''),
            JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE
        ),
    ];
    return "openVoidModal(" . implode(', ', $args) . ')';
}

$mobileHtml = '';
if (empty($filteredEvents)) {
    $mobileHtml = '<div class="p-6 text-center text-gray-500">No events found.</div>';
} else {
    foreach ($filteredEvents as $event) {
        $badge = arnisFilterEventsBadge($event['event_type'] ?? null);
        $onclick = arnisFilterEventsVoidOnclick($event, $canVoidEvents);

        if ($canVoidEvents) {
            $voidClass = 'w-10 text-xs transition rounded-lg bg-white/5 hover:bg-orange-500/20 text-gray-500 hover:text-orange-500 cursor-pointer';
            $voidTitle = 'Void';
            $voidDisabled = '';
        } else {
            $voidClass = 'w-10 text-xs transition rounded-lg bg-gray-700 text-gray-700 opacity-50 cursor-not-allowed';
            $voidTitle = 'No permission to void events';
            $voidDisabled = ' disabled';
        }

        $mobileHtml .= '
            <div class="bg-white/[0.02] rounded-xl p-4 border border-gray-800/50">
                <div class="flex justify-between items-start mb-3">
                    <div class="flex-1 min-w-0">
                        <div class="text-xs text-gray-500 mb-1">ID: ' . (int)$event['event_id'] . '</div>
                        <div class="font-bold text-sm mb-1 line-clamp-2">' . htmlspecialchars((string)$event['event_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>
                        <span class="' . $badge['bg'] . ' ' . $badge['text'] . ' px-2 py-1 rounded-full text-[9px] font-bold uppercase inline-block">' . htmlspecialchars((string)$event['event_type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>
                    </div>
                </div>

                <div class="text-[10px] text-gray-400 space-y-2 mb-3">
                    <div>
                        <span class="text-gray-500 block">Date:</span>
                        <span class="text-gray-300">' . htmlspecialchars(arnisFilterEventsFormatDate($event['event_date'] ?? ''), ENT_QUOTES, 'UTF-8') . '</span>
                    </div>
                    <div>
                        <span class="text-gray-500 block">Location:</span>
                        <span class="text-gray-300">' . htmlspecialchars((string)($event['location'] ?? 'N/A'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>
                    </div>
                    <div>
                        <span class="text-gray-500 block">Description:</span>
                        <span class="text-gray-300 line-clamp-2">' . htmlspecialchars((string)($event['description'] ?? 'N/A'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>
                    </div>
                    <div>
                        <span class="text-gray-500 block">Created At:</span>
                        <span class="text-gray-300">' . htmlspecialchars(arnisFilterEventsFormatDate($event['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') . '</span>
                    </div>
                </div>

                <div class="flex gap-2">
                    <a href="event_detail.php?id=' . (int)$event['event_id'] . '" class="flex-1 bg-purple-500/10 text-purple-400 text-center py-2 rounded-lg text-[10px] font-bold hover:bg-purple-500 hover:text-white transition">
                        <i class="fas fa-external-link-alt mr-1"></i> View Details
                    </a>
                    <button onclick="' . $onclick . '"
                            class="' . $voidClass . '"
                            title="' . $voidTitle . '"' . $voidDisabled . '>
                        <i class="fas fa-ban"></i>
                    </button>
                </div>
            </div>
        ';
    }
}

$desktopHtml = '';
if (empty($filteredEvents)) {
    $desktopHtml = '<tr><td colspan="8" class="p-6 text-center text-gray-500">No events found.</td></tr>';
} else {
    foreach ($filteredEvents as $event) {
        $badge = arnisFilterEventsBadge($event['event_type'] ?? null);
        $onclick = arnisFilterEventsVoidOnclick($event, $canVoidEvents);

        if ($canVoidEvents) {
            $voidClass = 'transition text-xs text-gray-600 hover:text-orange-500 cursor-pointer';
            $voidTitle = 'Void Event';
            $voidDisabled = '';
        } else {
            $voidClass = 'transition text-xs text-gray-700 opacity-50 cursor-not-allowed';
            $voidTitle = 'No permission to void events';
            $voidDisabled = ' disabled';
        }

        $description = (string)($event['description'] ?? 'N/A');

        $desktopHtml .= '
            <tr class="border-b border-gray-800 hover:bg-white/5 transition">
                <td class="p-4 lg:p-6 text-gray-500">' . (int)$event['event_id'] . '</td>
                <td class="p-4 lg:p-6 font-bold tracking-wide">' . htmlspecialchars((string)$event['event_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>
                <td class="p-4 lg:p-6">
                    <span class="' . $badge['bg'] . ' ' . $badge['text'] . ' px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-tighter">' . htmlspecialchars((string)$event['event_type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>
                </td>
                <td class="p-4 lg:p-6 text-gray-300">' . htmlspecialchars(arnisFilterEventsFormatDate($event['event_date'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>
                <td class="p-4 lg:p-6 italic">' . htmlspecialchars((string)($event['location'] ?? 'N/A'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>
                <td class="p-4 lg:p-6 text-gray-400 max-w-xs truncate" title="' . htmlspecialchars($description, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' . htmlspecialchars($description, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>
                <td class="p-4 lg:p-6 text-gray-400 whitespace-nowrap">' . htmlspecialchars(arnisFilterEventsFormatDate($event['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>
                <td class="p-4 lg:p-6 text-center space-x-2">
                    <a href="event_detail.php?id=' . (int)$event['event_id'] . '" class="text-gray-500 hover:text-purple-400 transition">
                        <i class="fas fa-external-link-alt text-purple-400"></i>
                    </a>
                    <button onclick="' . $onclick . '"
                            class="' . $voidClass . '"
                            title="' . $voidTitle . '"' . $voidDisabled . '>
                        <i class="fas fa-ban"></i>
                    </button>
                </td>
            </tr>
        ';
    }
}

echo json_encode([
    'success'     => true,
    'events'      => $filteredEvents,
    'totalEvents' => $totalEvents,
    'totalPages'  => $totalPages,
    'currentPage' => $page,
    'perPage'     => $perPage,
    'mobileHtml'  => $mobileHtml,
    'desktopHtml' => $desktopHtml,
]);