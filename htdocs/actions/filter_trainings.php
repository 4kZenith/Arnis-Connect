<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/Trainings.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Content-Type: application/json; charset=UTF-8');

/*
 * public/trainings.php no longer calls this endpoint - the page filters through
 * a GET form so the stat cards and the pagination links are rendered from the
 * same query string. It is kept working and hardened for any other consumer.
 */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

/* The endpoint had no authorize.php at all - any account could POST it. */
requirePermission('trainings', 'read');

/* POST values can be arrays (?search[]=x), so each one is read defensively. */
$postString = static function (string $key): string {
    return (isset($_POST[$key]) && is_string($_POST[$key])) ? trim($_POST[$key]) : '';
};

$search = mb_substr($postString('search'), 0, 100);

$rawType = $postString('type');
$type = in_array($rawType, ['Regular', 'Private', 'Custom'], true) ? $rawType : '';

$rawGroup = $postString('target_group');
$targetGroup = in_array($rawGroup, ['Public', 'Private', 'Mixed'], true) ? $rawGroup : '';

$startDate = $postString('start_date');
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) !== 1) {
    $startDate = '';
}

$endDate = $postString('end_date');
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate) !== 1) {
    $endDate = '';
}

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
$page = (isset($_POST['page']) && is_numeric($_POST['page'])) ? max(1, (int)$_POST['page']) : 1;

/* Escaped for an HTML attribute; the HEX flags also protect the JS string. */
$jsonFlags = JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE;

/** trainings.start_time / end_time are `time DEFAULT NULL`. */
$fmtTime = static function (?string $time): string {
    $time = trim((string)$time);
    if ($time === '') {
        return 'TBA';
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
    return $timestamp === false ? 'TBA' : date('h:i A', $timestamp);
};

$fmtDate = static function (?string $date): string {
    $date = trim((string)$date);
    if ($date === '' || preg_match('/^(\d{4}-\d{2}-\d{2})/', $date, $m) !== 1 || str_starts_with($m[1], '0000-00-00')) {
        return 'TBA';
    }
    $parsed = DateTime::createFromFormat('!Y-m-d', $m[1]);
    return $parsed === false ? 'TBA' : $parsed->format('M d, Y');
};

$typeClass = static function (?string $type): string {
    switch ($type) {
        case 'Private':
            return 'bg-blue-500/10 text-blue-400 border border-blue-500/20';
        case 'Custom':
            return 'bg-pink-500/10 text-pink-300 border border-pink-500/20';
        default:
            return 'bg-purple-500/10 text-purple-400 border border-purple-500/20';
    }
};

$groupClass = static function (?string $group): string {
    switch ($group) {
        case 'Private':
            return 'bg-yellow-500/10 text-yellow-300';
        case 'Mixed':
            return 'bg-pink-500/10 text-pink-300';
        default:
            return 'bg-green-500/10 text-green-300';
    }
};

$esc = static fn(?string $value, string $fallback = ''): string => htmlspecialchars(
    trim((string)$value) !== '' ? trim((string)$value) : $fallback,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);

/**
 * openVoidModal() is invoked from an HTML attribute. htmlspecialchars(...,
 * ENT_QUOTES) does not protect a JS string literal here because the browser
 * HTML-decodes the attribute before evaluating it, turning &#039; back into '.
 */
$voidOnclick = static function (array $training) use ($fmtDate, $jsonFlags): string {
    $label = 'Training on ' . $fmtDate($training['training_date'] ?? null);
    return 'openVoidModal('
        . json_encode('Training', $jsonFlags) . ', '
        . (int)($training['training_id'] ?? 0) . ', '
        . json_encode($label, $jsonFlags)
        . ')';
};

try {
    $db = (new Database())->connect();
    $trainingsModel = new Trainings($db);

    $result = $trainingsModel->getAllPaginated($filters, $page, $perPage);
    $totalPages = max(1, (int)($result['totalPages'] ?? 1));
    $page = min($page, $totalPages);
    if ($page !== (int)($result['page'] ?? $page)) {
        $result = $trainingsModel->getAllPaginated($filters, $page, $perPage);
    }

    $trainings = $result['trainings'];
    $totalTrainings = (int)($result['total'] ?? 0);
    $stats = $trainingsModel->getStatsOptimized($filters);

    /*
     * RBAC is applied to the rendered buttons. The old renderer emitted an
     * always-enabled void button, so the AJAX list bypassed the
     * trainings.delete check that the server-rendered list applies.
     */
    $canVoid = hasPermission('trainings', 'delete');
    $voidButtonClass = $canVoid
        ? 'bg-white/5 hover:bg-orange-500/20 text-gray-500 hover:text-orange-500 cursor-pointer'
        : 'bg-gray-700 text-gray-700 opacity-50 cursor-not-allowed';
    $voidIconClass = $canVoid
        ? 'transition text-xs text-gray-600 hover:text-orange-500 cursor-pointer'
        : 'transition text-xs text-gray-700 opacity-50 cursor-not-allowed';
    $voidDisabled = $canVoid ? '' : ' disabled';

    $mobileHtml = '<div class="p-6 text-center text-gray-500">No training sessions found.</div>';
    foreach ($trainings as $training) {
        $description = trim((string)($training['description'] ?? ''));
        $mobileHtml .= '
            <div class="bg-white/[0.02] rounded-xl p-4 border border-gray-800/50">
                <div class="flex justify-between items-start mb-3 gap-3">
                    <div>
                        <div class="font-bold text-sm mb-1">' . $esc($fmtDate($training['training_date'] ?? null)) . '</div>
                        <div class="text-[10px] text-gray-400">' . $esc($fmtTime($training['start_time'] ?? null)) . ' - ' . $esc($fmtTime($training['end_time'] ?? null)) . '</div>
                    </div>
                    <span class="px-2 py-1 rounded-full text-[9px] font-bold ' . $typeClass($training['training_type'] ?? null) . '">'
                        . $esc(strtoupper(trim((string)($training['training_type'] ?? ''))), 'REGULAR') . '</span>
                </div>
                <div class="flex flex-wrap gap-2 mb-3">
                    <span class="px-2 py-1 rounded-full text-[9px] font-bold ' . $groupClass($training['target_group'] ?? null) . '">'
                        . $esc($training['target_group'] ?? null, 'Mixed') . '</span>
                    <span class="px-2 py-1 rounded-full text-[9px] font-bold bg-white/5 text-gray-300">'
                        . $esc($training['venue_type'] ?? null, 'Custom') . '</span>
                    <span class="px-2 py-1 rounded-full text-[9px] font-bold bg-blue-500/10 text-blue-300">&#8369;'
                        . number_format((float)($training['fee_amount'] ?? 0), 2, '.', ',') . '</span>
                </div>
                <div class="text-[10px] text-gray-400 space-y-1 mb-3">
                    <div><i class="fas fa-map-marker-alt w-4"></i> ' . $esc($training['location'] ?? null, 'N/A') . '</div>
                    <div><i class="fas fa-user-tie w-4"></i> ' . $esc($training['coach_name'] ?? null, 'Unassigned') . '</div>
                </div>'
            . ($description !== ''
                ? '<div class="text-[10px] text-gray-500 italic mb-3 line-clamp-2">' . htmlspecialchars($description, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>'
                : '')
            . '
                <div class="flex gap-2">
                    <a href="attendance_session.php?id=' . (int)($training['training_id'] ?? 0) . '" class="flex-1 bg-blue-500/10 text-blue-400 text-center py-2 rounded-lg text-[10px] font-bold hover:bg-blue-500 hover:text-white transition">VIEW SESSION</a>
                    <button type="button" onclick="' . htmlspecialchars($voidOnclick($training), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" class="w-10 text-xs transition rounded-lg ' . $voidButtonClass . '"' . $voidDisabled . '><i class="fas fa-ban"></i></button>
                </div>
            </div>';
    }

    $desktopHtml = '<tr><td colspan="10" class="p-6 text-center text-gray-500">No training sessions found.</td></tr>';
    foreach ($trainings as $training) {
        $description = trim((string)($training['description'] ?? ''));
        /* ENT_QUOTES is required: description is free text and lands in title="". */
        $descCell = $description !== ''
            ? '<div class="truncate text-gray-500 italic" title="' . htmlspecialchars($description, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">'
                . htmlspecialchars($description, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>'
            : '<span class="text-gray-600">—</span>';

        $desktopHtml .= '
            <tr class="border-b border-gray-800 hover:bg-white/5 transition">
                <td class="p-4 lg:p-6 font-semibold whitespace-nowrap">' . $esc($fmtDate($training['training_date'] ?? null)) . '</td>
                <td class="p-4 lg:p-6 text-gray-300 whitespace-nowrap">' . $esc($fmtTime($training['start_time'] ?? null)) . ' - ' . $esc($fmtTime($training['end_time'] ?? null)) . '</td>
                <td class="p-4 lg:p-6"><span class="px-3 py-1 rounded-full text-[10px] font-bold ' . $typeClass($training['training_type'] ?? null) . '">'
                    . $esc($training['training_type'] ?? null, 'Regular') . '</span></td>
                <td class="p-4 lg:p-6"><span class="px-2 py-1 rounded-full text-xs font-semibold ' . $groupClass($training['target_group'] ?? null) . '">'
                    . $esc($training['target_group'] ?? null, 'Mixed') . '</span></td>
                <td class="p-4 lg:p-6 text-xs text-gray-400">' . $esc($training['venue_type'] ?? null, 'Custom') . '</td>
                <td class="p-4 lg:p-6 whitespace-nowrap">&#8369;' . number_format((float)($training['fee_amount'] ?? 0), 2, '.', ',') . '</td>
                <td class="p-4 lg:p-6">' . $esc($training['location'] ?? null, 'N/A') . '</td>
                <td class="p-4 lg:p-6 whitespace-nowrap">' . $esc($training['coach_name'] ?? null, 'Unassigned') . '</td>
                <td class="p-4 lg:p-6 max-w-[220px]">' . $descCell . '</td>
                <td class="p-4 lg:p-6 text-center space-x-4 whitespace-nowrap">
                    <a href="attendance_session.php?id=' . (int)($training['training_id'] ?? 0) . '" class="bg-blue-500/10 text-blue-400 px-4 py-2 rounded-full text-xs font-bold hover:bg-blue-500 hover:text-white transition inline-block">VIEW SESSION</a>
                    <button type="button" onclick="' . htmlspecialchars($voidOnclick($training), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" class="' . $voidIconClass . '"' . $voidDisabled . '><i class="fas fa-ban"></i></button>
                </td>
            </tr>';
    }

    echo json_encode([
        'success' => true,
        'trainings' => $trainings,
        'totalTrainings' => $totalTrainings,
        'totalPages' => $totalPages,
        'currentPage' => $page,
        'perPage' => $perPage,
        'mobileHtml' => $mobileHtml,
        'desktopHtml' => $desktopHtml,
        'stats' => [
            'total' => (int)($stats['total'] ?? 0),
            'upcoming' => (int)($stats['upcoming'] ?? 0),
            'public_sessions' => (int)($stats['public_sessions'] ?? 0),
            'private_sessions' => (int)($stats['private_sessions'] ?? 0),
            'cancelled' => (int)($stats['cancelled'] ?? 0),
        ],
    ], $jsonFlags);
} catch (PDOException $e) {
    error_log('filter_trainings DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to load training sessions.']);
} catch (Throwable $e) {
    error_log('filter_trainings error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to load training sessions.']);
}