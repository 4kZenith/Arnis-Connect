<?php
require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/Funds.php';
require_once __DIR__ . '/../classes/Events.php';

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

if (!canAccess('funds', 'read')) {
    http_response_code(403);
    die('Access Denied: You do not have permission to view funds.');
}

/* Flash messages written by process_edit_transaction.php / process_transaction.php. */
$flashSuccess = $_SESSION['success'] ?? null;
$flashError = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);

$rawMonth = (isset($_GET['month']) && is_string($_GET['month'])) ? trim($_GET['month']) : '';
$rawType = (isset($_GET['type']) && is_string($_GET['type'])) ? trim($_GET['type']) : '';

$month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $rawMonth) ? $rawMonth : date('Y-m');
$type = in_array($rawType, ['Income', 'Expense'], true) ? $rawType : '';

$filters = ['month' => $month];
if ($type !== '') {
    $filters['type'] = $type;
}

$perPage = 10;
$page = (isset($_GET['page']) && is_numeric($_GET['page'])) ? max(1, (int)$_GET['page']) : 1;

try {
    $db = (new Database())->connect();
    $fundsModel = new Funds($db);
    $eventsModel = new Events($db);

    $totalFunds = $fundsModel->getCount($filters);
    $totalPages = max(0, (int)ceil($totalFunds / $perPage));
    $page = min($page, max(1, $totalPages));

    $funds = $fundsModel->getAll($filters, $perPage, $page);
    $stats = $fundsModel->getStats($filters);

    /*
     * modals/edit_transaction.php iterates $events for its "Link to Event"
     * select. This variable was never defined here, so the dropdown was always
     * empty and transactions could never be attached to an event.
     */
    $events = $eventsModel->getAll([], 200, 1);
} catch (Throwable $e) {
    error_log('funds.php error: ' . $e->getMessage());
    die('Error loading transactions. Please contact the administrator.');
}

$canWriteFunds = canAccess('funds', 'write');
$canVoidFunds = canAccess('funds', 'delete');

$monthTimestamp = strtotime($month . '-01');
$monthLabel = $monthTimestamp === false ? $month : date('F Y', $monthTimestamp);

function formatAmount($amount, $type): array
{
    $sign = $type === 'Income' ? '+ ' : '- ';
    $color = $type === 'Income' ? 'text-green-400' : 'text-red-400';
    return [
        'display' => $sign . number_format((float)$amount, 2, '.', ','),
        'color' => $color,
    ];
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

/** LEFT JOIN columns are nullable; never pass null to htmlspecialchars(). */
function fundRecordedBy(array $fund): string
{
    $name = $fund['recorded_by_name'] ?? null;
    return ($name === null || $name === '') ? 'Unknown' : (string)$name;
}

function fundNotes(array $fund): string
{
    $notes = $fund['notes'] ?? null;
    return ($notes === null || $notes === '') ? '' : (string)$notes;
}

function fundEventName(array $fund): string
{
    $name = $fund['event_name'] ?? null;
    return ($name === null || $name === '') ? 'N/A' : (string)$name;
}

/**
 * Object literal consumed by openEditTransactionModal().
 *
 * addslashes() is not XSS-safe in an HTML attribute and also corrupts the JS
 * when the value contains a backslash or newline. json_encode with the HEX
 * flags escapes</> and  so the payload is safe in both layers.
 */
function fundEditOnclick(array $fund): string
{
    $payload = [
        'id'       => (int)$fund['fund_id'],
        'type'     => (string)$fund['transaction_type'],
        'amount'   => (float)$fund['amount'],
        'date'     => (string)($fund['transaction_date'] ?? ''),
        'purpose'  => (string)($fund['source_or_purpose'] ?? ''),
        'event_id' => $fund['related_event_id'] !== null ? (int)$fund['related_event_id'] : null,
        'notes'    => fundNotes($fund),
    ];

    return 'openEditTransactionModal('
        . json_encode($payload, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE)
        . ')';
}

/**
 * htmlspecialchars(..., ENT_QUOTES) is unsafe here: the browser HTML-decodes
 * the attribute before evaluating the JavaScript, so &#039; becomes ' again
 * and escapes the JS string literal.
 */
function fundVoidOnclick(array $fund): string
{
    return "openVoidModal('Fund', " . (int)$fund['fund_id'] . ', '
        . json_encode(
            (string)($fund['source_or_purpose'] ?? ''),
            JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE
        )
        . ')';
}

$pageLink = static function (int $target) use ($month, $type): string {
    $query = ['month' => $month];
    if ($type !== '') {
        $query['type'] = $type;
    }
    $query['page'] = $target;
    return '?' . http_build_query($query);
};
?>

<!DOCTYPE html>
<html lang="en" class="text-[13px]">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Funds | Arnis Portal</title>
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

        <!-- RESPONSIVE: Scale padding -->
        <main id="page" class="flex-1 p-4 sm:p-6 lg:p-8 h-screen overflow-y-auto opacity-0 translate-x-8 transition-all duration-500 ease-out">
            <?php include_once __DIR__ . '/../includes/header.php'; ?>

            <?php if ($flashSuccess !== null || $flashError !== null): ?>
            <div class="mb-6 p-4 rounded-2xl border text-sm font-semibold <?= $flashError !== null ? 'bg-red-500/10 border-red-500/20 text-red-300' : 'bg-emerald-500/10 border-emerald-500/20 text-emerald-300' ?>">
                <i class="fas <?= $flashError !== null ? 'fa-exclamation-triangle' : 'fa-check-circle' ?> mr-2"></i>
                <?= htmlspecialchars((string)($flashError ?? $flashSuccess), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            </div>
            <?php endif; ?>

            <!-- RESPONSIVE: Stats cards - 1 col on mobile, 3 cols on desktop -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3 sm:gap-4 lg:gap-6 mb-6 sm:mb-8">
                <div class="card-bg p-4 sm:p-6 rounded-2xl sm:rounded-3xl shadow-xl">
                    <div class="text-[9px] sm:text-[10px] text-gray-500 uppercase font-bold tracking-widest mb-1">Current Balance (All-time)</div>
                    <div class="text-2xl sm:text-3xl font-bold mt-1 italic">&#8369;<?= number_format((float)($stats['balance'] ?? 0), 2, '.', ',') ?></div>
                </div>
                <div class="card-bg p-4 sm:p-6 rounded-2xl sm:rounded-3xl shadow-xl">
                    <div class="text-[9px] sm:text-[10px] text-gray-500 uppercase font-bold tracking-widest mb-1">Total Income (<?= htmlspecialchars($monthLabel, ENT_QUOTES, 'UTF-8') ?>)</div>
                    <div class="text-2xl sm:text-3xl font-bold mt-1 italic text-green-400">+ &#8369;<?= number_format((float)($stats['income'] ?? 0), 2, '.', ',') ?></div>
                </div>
                <div class="card-bg p-4 sm:p-6 rounded-2xl sm:rounded-3xl shadow-xl">
                    <div class="text-[9px] sm:text-[10px] text-gray-500 uppercase font-bold tracking-widest mb-1">Total Expenses (<?= htmlspecialchars($monthLabel, ENT_QUOTES, 'UTF-8') ?>)</div>
                    <div class="text-2xl sm:text-3xl font-bold mt-1 italic text-red-400">- &#8369;<?= number_format((float)($stats['expenses'] ?? 0), 2, '.', ',') ?></div>
                </div>
            </div>

            <!-- RESPONSIVE: Filters - plain GET form so the query string drives
                 both the list and the pagination links -->
            <form method="get" action="funds.php" id="fundsFilterForm"
                  class="flex flex-col sm:flex-row justify-between items-stretch sm:items-center mb-6 gap-3">
                <div class="flex flex-col sm:flex-row gap-2 sm:gap-3">
                    <input type="month" name="month" id="monthFilter"
                           value="<?= htmlspecialchars($month, ENT_QUOTES, 'UTF-8') ?>"
                           onchange="this.form.submit()"
                           class="card-bg border-none rounded-full px-4 sm:px-5 py-3 sm:py-4 text-xs sm:text-sm outline-none text-center w-full sm:w-40 lg:w-48">
                    <select name="type" id="typeFilter" onchange="this.form.submit()"
                            class="card-bg border-none rounded-full px-4 sm:px-5 py-3 sm:py-4 text-xs sm:text-sm outline-none text-center w-full sm:w-40 lg:w-48">
                        <option value="">All Transactions</option>
                        <option value="Income"<?= $type === 'Income' ? ' selected' : '' ?>>Income Only</option>
                        <option value="Expense"<?= $type === 'Expense' ? ' selected' : '' ?>>Expenses Only</option>
                    </select>
                </div>
                <button onclick="openTransactionModal()" type="button"
                        class="px-5 sm:px-6 py-4 sm:py-5 rounded-full font-bold text-xs sm:text-sm whitespace-nowrap transition <?= $canWriteFunds ? 'bg-accent-purple hover:opacity-90 cursor-pointer' : 'bg-gray-600 opacity-50 cursor-not-allowed' ?>"
                        <?= $canWriteFunds ? '' : 'disabled' ?>>
                    + Add Transaction
                </button>
            </form>

            <!-- RESPONSIVE: Cards on mobile, table on desktop -->
            <div class="card-bg rounded-2xl sm:rounded-3xl overflow-hidden shadow-xl">
                <!-- MOBILE: Card View -->
                <div class="block lg:hidden" id="mobileFundsView">
                    <div class="p-2 space-y-2">
                        <?php if (empty($funds)): ?>
                            <div class="text-center text-gray-500 py-8">No transactions found</div>
                        <?php else: ?>
                            <?php foreach ($funds as $fund):
                                $formatted = formatAmount($fund['amount'], $fund['transaction_type']);
                                $writeClass = $canWriteFunds
                                    ? 'bg-purple-500/10 text-purple-400 hover:bg-purple-500 hover:text-white cursor-pointer'
                                    : 'bg-gray-700 text-gray-600 opacity-50 cursor-not-allowed';
                                $writeDisabled = $canWriteFunds ? '' : 'disabled';
                                $writeTitle = $canWriteFunds ? 'Edit transaction' : 'No permission to edit transactions';
                                $voidClass = $canVoidFunds
                                    ? 'w-10 text-xs transition rounded-lg bg-white/5 hover:bg-orange-500/20 text-gray-500 hover:text-orange-500 cursor-pointer'
                                    : 'w-10 text-xs transition rounded-lg bg-gray-700 text-gray-700 opacity-50 cursor-not-allowed';
                                $voidDisabled = $canVoidFunds ? '' : 'disabled';
                                $voidTitle = $canVoidFunds ? 'Void' : 'No permission to void transactions';
                            ?>
                            <!-- Transaction Card -->
                            <div class="bg-white/[0.02] rounded-xl p-4 border border-gray-800/50">
                                <div class="flex justify-between items-start mb-3">
                                    <div class="flex-1 min-w-0">
                                        <div class="font-bold text-sm mb-1"><?= htmlspecialchars((string)$fund['source_or_purpose'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                                        <div class="text-[10px] text-gray-500 italic"><?= htmlspecialchars(fundNotes($fund), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                                    </div>
                                    <div class="font-black <?= $formatted['color'] ?> text-sm ml-2"><?= htmlspecialchars($formatted['display'], ENT_QUOTES, 'UTF-8') ?></div>
                                </div>

                                <div class="grid grid-cols-2 gap-2 text-[10px] text-gray-400 mb-3">
                                    <div>
                                        <span class="text-gray-500 block">Date:</span>
                                        <span class="text-gray-300"><?= htmlspecialchars(formatDate($fund['transaction_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                    <div>
                                        <span class="text-gray-500 block">Event:</span>
                                        <span class="text-gray-300 italic"><?= htmlspecialchars(fundEventName($fund), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                                    </div>
                                    <div class="col-span-2">
                                        <span class="text-gray-500 block">Recorded by:</span>
                                        <span class="text-gray-300"><?= htmlspecialchars(fundRecordedBy($fund), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                                    </div>
                                </div>

                                <div class="flex gap-2">
                                    <button onclick="<?= htmlspecialchars(fundEditOnclick($fund), ENT_QUOTES, 'UTF-8') ?>"
                                            class="flex-1 text-center py-2 rounded-lg text-[10px] font-bold transition <?= $writeClass ?>"
                                            <?= $writeDisabled ?>
                                            title="<?= htmlspecialchars($writeTitle, ENT_QUOTES, 'UTF-8') ?>">
                                        <i class="fas fa-edit mr-1"></i> Edit
                                    </button>
                                    <button onclick="<?= htmlspecialchars(fundVoidOnclick($fund), ENT_QUOTES, 'UTF-8') ?>"
                                            class="<?= $voidClass ?>"
                                            title="<?= htmlspecialchars($voidTitle, ENT_QUOTES, 'UTF-8') ?>"
                                            <?= $voidDisabled ?>>
                                        <i class="fas fa-ban"></i>
                                    </button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- DESKTOP: Table View -->
                <div class="hidden lg:block overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead class="text-gray-400 text-xs uppercase tracking-widest border-b border-gray-800">
                            <tr>
                                <th class="p-4 lg:p-6">Date</th>
                                <th class="p-4 lg:p-6">Purpose / Source</th>
                                <th class="p-4 lg:p-6">Related Event</th>
                                <th class="p-4 lg:p-6">Recorded By</th>
                                <th class="p-4 lg:p-6">Amount</th>
                                <th class="p-4 lg:p-6">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="text-sm" id="desktopFundsTable">
                            <?php if (empty($funds)): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-gray-500 py-8">No transactions found</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($funds as $fund):
                                    $formatted = formatAmount($fund['amount'], $fund['transaction_type']);
                                    $writeClass = $canWriteFunds
                                        ? 'text-gray-600 hover:text-purple-400 cursor-pointer'
                                        : 'text-gray-700 opacity-50 cursor-not-allowed';
                                    $writeDisabled = $canWriteFunds ? '' : 'disabled';
                                    $writeTitle = $canWriteFunds ? 'Edit transaction' : 'No permission to edit transactions';
                                    $voidClass = $canVoidFunds
                                        ? 'text-gray-600 hover:text-orange-500 cursor-pointer'
                                        : 'text-gray-700 opacity-50 cursor-not-allowed';
                                    $voidDisabled = $canVoidFunds ? '' : 'disabled';
                                    $voidTitle = $canVoidFunds ? 'Void Transaction' : 'No permission to void transactions';
                                ?>
                                <tr class="border-b border-gray-800 hover:bg-white/5 transition group">
                                    <td class="p-4 lg:p-6 whitespace-nowrap text-gray-400"><?= htmlspecialchars(formatDate($fund['transaction_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="p-4 lg:p-6">
                                        <div class="font-bold text-white"><?= htmlspecialchars((string)$fund['source_or_purpose'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                                        <div class="text-[10px] text-gray-500 italic"><?= htmlspecialchars(fundNotes($fund), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                                    </td>
                                    <td class="p-4 lg:p-6 text-gray-400 italic"><?= htmlspecialchars(fundEventName($fund), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                    <td class="p-4 lg:p-6 text-gray-500 text-xs"><?= htmlspecialchars(fundRecordedBy($fund), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                    <td class="p-4 lg:p-6 font-black <?= $formatted['color'] ?>"><?= htmlspecialchars($formatted['display'], ENT_QUOTES, 'UTF-8') ?></td>

                                    <td class="p-4 lg:p-6 whitespace-nowrap space-x-2">
                                        <button onclick="<?= htmlspecialchars(fundEditOnclick($fund), ENT_QUOTES, 'UTF-8') ?>"
                                                class="transition text-xs <?= $writeClass ?>"
                                                <?= $writeDisabled ?>
                                                title="<?= htmlspecialchars($writeTitle, ENT_QUOTES, 'UTF-8') ?>">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button onclick="<?= htmlspecialchars(fundVoidOnclick($fund), ENT_QUOTES, 'UTF-8') ?>"
                                                class="transition text-xs <?= $voidClass ?>"
                                                title="<?= htmlspecialchars($voidTitle, ENT_QUOTES, 'UTF-8') ?>"
                                                <?= $voidDisabled ?>>
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
                    <div id="fundsPagination" class="px-4 py-4 border-t border-gray-800 flex justify-between items-center flex-wrap gap-3">
                        <div class="text-[10px] text-gray-500 uppercase">
                            Showing <?= $totalFunds > 0 ? min((($page - 1) * $perPage) + 1, $totalFunds) : 0 ?> to <?= min($page * $perPage, $totalFunds) ?> of <?= (int)$totalFunds ?> transactions
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

    <?php include_once __DIR__ . '/modals/add_transaction.php'; ?>
    <?php include_once __DIR__ . '/modals/void_confirmation.php'; ?>
    <?php include_once __DIR__ . '/modals/edit_transaction.php'; ?>
    <?php include_once __DIR__ . '/modals/user_menu.php'; ?>
    <?php include_once __DIR__ . '/modals/edit_profile_user.php'; ?>

</body>

<script>
window.addEventListener("load", () => {
    const page = document.getElementById("page");
    page.classList.remove("opacity-0", "translate-x-8");
});

/*
 * The month and type filters submit the form, which re-renders the stats cards,
 * both view variants and the pagination links from one query string.
 *
 * The previous implementation fetched actions/filter_funds.php and replaced
 * only the two view bodies. That left the stats cards, the month label and the
 * pagination links describing the *old* filter, so "Next" after filtering
 * reloaded an unfiltered page.
 */
function applyFundsFilters() {
    document.getElementById('fundsFilterForm').submit();
}
</script>

</html>