<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/Funds.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['success' => false, 'error' => 'Invalid request method.']);
    exit;
}

if (!hasPermission('funds', 'read')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$canWriteFunds = hasPermission('funds', 'write');
$canVoidFunds = hasPermission('funds', 'delete');

$month = trim((string)($_POST['month'] ?? ''));
if ($month === '') {
    $month = date('Y-m');
}
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
    $month = date('Y-m');
}

$type = trim((string)($_POST['type'] ?? ''));
if (!in_array($type, ['Income', 'Expense'], true)) {
    $type = '';
}

$filters = ['month' => $month];
if ($type !== '') {
    $filters['type'] = $type;
}

$perPage = isset($_POST['per_page']) && is_numeric($_POST['per_page'])
    ? min(50, max(1, (int)$_POST['per_page']))
    : 10;
$page = isset($_POST['page']) && is_numeric($_POST['page'])
    ? max(1, (int)$_POST['page'])
    : 1;

try {
    $db = (new Database())->connect();
    $fundsModel = new Funds($db);

    $totalFunds = $fundsModel->getCount($filters);
    $totalPages = max(1, (int)ceil($totalFunds / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }

    $funds = $fundsModel->getAll($filters, $perPage, $page);
    $stats = $fundsModel->getStats($filters);
} catch (PDOException $e) {
    error_log('filter_funds DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error.']);
    exit;
} catch (Throwable $e) {
    error_log('filter_funds error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to load transactions.']);
    exit;
}

if (!function_exists('arnisFundsFormatAmount')) {
    function arnisFundsFormatAmount($amount, string $transactionType): array
    {
        $sign = $transactionType === 'Income' ? '+ ' : '- ';
        $color = $transactionType === 'Income' ? 'text-green-400' : 'text-red-400';
        return [
            'display' => $sign . number_format((float)$amount, 2, '.', ','),
            'color' => $color,
        ];
    }
}

if (!function_exists('arnisFundsFormatDate')) {
    function arnisFundsFormatDate($date): string
    {
        $date = (string)($date ?? '');
        if ($date === '' || str_starts_with($date, '0000-00-00')) {
            return 'TBA';
        }
        $timestamp = strtotime($date);
        return $timestamp === false ? 'TBA' : date('M d, Y', $timestamp);
    }
}

/**
 * Serialises a value for use inside a double-quoted HTML attribute that * contains JavaScript.
 *
 * addslashes() is not sufficient: it leaves < > & untouched, so a purpose of
 * "</button><script>…" escapes the attribute, and it also corrupts JS when the
 * value contains a backslash or newline. json_encode with the HEX flags emits
 *  </> and \\ escapes that are safe in both layers.
 */
function arnisFundsJsArg($value): string
{
    return json_encode(
        (string)($value ?? ''),
        JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP
            | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
}

function arnisFundsRecordedBy(array $fund): string
{
    $name = $fund['recorded_by_name'] ?? null;
    return ($name === null || $name === '') ? 'Unknown' : (string)$name;
}

function arnisFundsNotes(array $fund): string
{
    $notes = $fund['notes'] ?? null;
    return ($notes === null || $notes === '') ? '' : (string)$notes;
}

function arnisFundsEventName(array $fund): string
{
    $name = $fund['event_name'] ?? null;
    return ($name === null || $name === '') ? 'N/A' : (string)$name;
}

/** Object literal consumed by openEditTransactionModal(). */
function arnisFundsEditOnclick(array $fund): string
{
    $payload = [
        'id'       => (int)$fund['fund_id'],
        'type'     => (string)$fund['transaction_type'],
        'amount'   => (float)$fund['amount'],
        'date'     => (string)($fund['transaction_date'] ?? ''),
        'purpose'  => (string)($fund['source_or_purpose'] ?? ''),
        'event_id' => $fund['related_event_id'] !== null ? (int)$fund['related_event_id'] : null,
        'notes'    => arnisFundsNotes($fund),
    ];

    return 'openEditTransactionModal('
        . json_encode($payload, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE)
        . ')';
}

function arnisFundsVoidOnclick(array $fund): string
{
    return "openVoidModal('Fund', " . (int)$fund['fund_id'] . ', '
        . arnisFundsJsArg($fund['source_or_purpose'] ?? '') . ')';
}

if ($canWriteFunds) {
    $writeClass = 'transition text-xs text-gray-600 hover:text-purple-400 cursor-pointer';
    $writeTitle = 'Edit transaction';
    $writeDisabled = '';
} else {
    $writeClass = 'transition text-xs text-gray-700 opacity-50 cursor-not-allowed';
    $writeTitle = 'No permission to edit transactions';
    $writeDisabled = ' disabled';
}

if ($canVoidFunds) {
    $voidClass = 'transition text-xs text-gray-600 hover:text-orange-500 cursor-pointer';
    $voidTitle = 'Void Transaction';
    $voidDisabled = '';
} else {
    $voidClass = 'transition text-xs text-gray-700 opacity-50 cursor-not-allowed';
    $voidTitle = 'No permission to void transactions';
    $voidDisabled = ' disabled';
}

$mobileHtml = '';
if (empty($funds)) {
    $mobileHtml = '<div class="text-center text-gray-500 py-8">No transactions found</div>';
} else {
    foreach ($funds as $fund) {
        $formatted = arnisFundsFormatAmount($fund['amount'], (string)$fund['transaction_type']);
        $notes = arnisFundsNotes($fund);
        $eventName = arnisFundsEventName($fund);

        $mobileWriteClass = $canWriteFunds
            ? 'bg-purple-500/10 text-purple-400 hover:bg-purple-500 hover:text-white cursor-pointer'
            : 'bg-gray-700 text-gray-600 opacity-50 cursor-not-allowed';
        $mobileVoidClass = $canVoidFunds
            ? 'w-10 text-xs transition rounded-lg bg-white/5 hover:bg-orange-500/20 text-gray-500 hover:text-orange-500 cursor-pointer'
            : 'w-10 text-xs transition rounded-lg bg-gray-700 text-gray-700 opacity-50 cursor-not-allowed';
        $mobileVoidTitle = $canVoidFunds ? 'Void' : 'No permission to void transactions';

        $mobileHtml .= '
            <div class="bg-white/[0.02] rounded-xl p-4 border border-gray-800/50">
                <div class="flex justify-between items-start mb-3">
                    <div class="flex-1 min-w-0">
                        <div class="font-bold text-sm mb-1">' . htmlspecialchars((string)($fund['source_or_purpose'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>
                        <div class="text-[10px] text-gray-500 italic">' . htmlspecialchars($notes, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>
                    </div>
                    <div class="font-black ' . $formatted['color'] . ' text-sm ml-2">' . htmlspecialchars($formatted['display'], ENT_QUOTES, 'UTF-8') . '</div>
                </div>

                <div class="grid grid-cols-2 gap-2 text-[10px] text-gray-400 mb-3">
                    <div>
                        <span class="text-gray-500 block">Date:</span>
                        <span class="text-gray-300">' . htmlspecialchars(arnisFundsFormatDate($fund['transaction_date'] ?? ''), ENT_QUOTES, 'UTF-8') . '</span>
                    </div>
                    <div>
                        <span class="text-gray-500 block">Event:</span>
                        <span class="text-gray-300 italic">' . htmlspecialchars($eventName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>
                    </div>
                    <div class="col-span-2">
                        <span class="text-gray-500 block">Recorded by:</span>
                        <span class="text-gray-300">' . htmlspecialchars(arnisFundsRecordedBy($fund), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>
                    </div>
                </div>

                <div class="flex gap-2">
                    <button onclick="' . arnisFundsEditOnclick($fund) . '"
                            class="flex-1 text-center py-2 rounded-lg text-[10px] font-bold transition ' . $mobileWriteClass . '"'
                            . $writeDisabled . '
                            title="' . $writeTitle . '">
                        <i class="fas fa-edit mr-1"></i> Edit
                    </button>
                    <button onclick="' . arnisFundsVoidOnclick($fund) . '"
                            class="' . $mobileVoidClass . '"
                            title="' . $mobileVoidTitle . '"'
                            . $voidDisabled . '>
                        <i class="fas fa-ban"></i>
                    </button>
                </div>
            </div>
        ';
    }
}

$desktopHtml = '';
if (empty($funds)) {
    $desktopHtml = '<tr><td colspan="6" class="text-center text-gray-500 py-8">No transactions found</td></tr>';
} else {
    foreach ($funds as $fund) {
        $formatted = arnisFundsFormatAmount($fund['amount'], (string)$fund['transaction_type']);
        $notes = arnisFundsNotes($fund);
        $eventName = arnisFundsEventName($fund);

        $desktopHtml .= '
            <tr class="border-b border-gray-800 hover:bg-white/5 transition group">
                <td class="p-4 lg:p-6 whitespace-nowrap text-gray-400">' . htmlspecialchars(arnisFundsFormatDate($fund['transaction_date'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>
                <td class="p-4 lg:p-6">
                    <div class="font-bold text-white">' . htmlspecialchars((string)($fund['source_or_purpose'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>
                    <div class="text-[10px] text-gray-500 italic">' . htmlspecialchars($notes, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>
                </td>
                <td class="p-4 lg:p-6 text-gray-400 italic">' . htmlspecialchars($eventName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>
                <td class="p-4 lg:p-6 text-gray-500 text-xs">' . htmlspecialchars(arnisFundsRecordedBy($fund), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>
                <td class="p-4 lg:p-6 font-black ' . $formatted['color'] . '">' . htmlspecialchars($formatted['display'], ENT_QUOTES, 'UTF-8') . '</td>

                <td class="p-4 lg:p-6 whitespace-nowrap space-x-2">
                    <button onclick="' . arnisFundsEditOnclick($fund) . '"
                            class="' . $writeClass . '"'
                            . $writeDisabled . '
                            title="' . $writeTitle . '">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button onclick="' . arnisFundsVoidOnclick($fund) . '"
                            class="' . $voidClass . '"'
                            . $voidDisabled . '
                            title="' . $voidTitle . '">
                        <i class="fas fa-ban"></i>
                    </button>
                </td>
            </tr>
        ';
    }
}

echo json_encode([
    'success'     => true,
    'funds'       => $funds,
    'totalFunds'  => $totalFunds,
    'totalPages'  => $totalPages,
    'currentPage' => $page,
    'perPage'     => $perPage,
    'stats'       => $stats,
    'mobileHtml'  => $mobileHtml,
    'desktopHtml' => $desktopHtml,
]);