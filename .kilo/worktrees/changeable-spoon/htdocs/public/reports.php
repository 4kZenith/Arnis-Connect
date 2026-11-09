<?php
/**
 * Reports - server-rendered, printable, CSV-exportable.
 *
 * The old page posted to generate_report.php and rendered an AJAX fragment.
 * It had no RBAC gate and could not be printed or exported. This version:
 *   - is gated to SuperAdmin + Officer (reports.read === true),
 *   - runs one whitelisted query per report type,
 *   - supports date filters, CSV export, and browser print-to-PDF,
 *   - writes an audit entry when a CSV is exported.
 */
require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/AuditLog.php';
require_once __DIR__ . '/../classes/Settings.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (permissionValue('reports', 'read') !== true) {
    http_response_code(403);
    exit('Access Denied: You do not have permission to view reports.');
}

$allowedReports = [
    'members' => 'Members',
    'attendance' => 'Attendance',
    'trainings' => 'Trainings',
    'events' => 'Events',
    'funds' => 'Funds',
];

$reportType = isset($_GET['report']) && is_string($_GET['report']) ? $_GET['report'] : 'members';
if (!array_key_exists($reportType, $allowedReports)) {
    $reportType = 'members';
}

$startDate = '';
$endDate = '';
foreach (['start_date', 'end_date'] as $key) {
    $raw = $_GET[$key] ?? '';
    if (is_string($raw) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($raw)) === 1) {
        if ($key === 'start_date') {
            $startDate = trim($raw);
        } else {
            $endDate = trim($raw);
        }
    }
}
if ($startDate !== '' && $endDate !== '' && $startDate > $endDate) {
    [$startDate, $endDate] = [$endDate, $startDate];
}
if ($startDate === '' && $endDate === '' && $reportType !== 'members') {
    $startDate = date('Y-m-01');
    $endDate = date('Y-m-d');
}

$export = isset($_GET['export']) && $_GET['export'] === 'csv';
$orgName = 'Arnis Connect';
$db = null;

try {
    $db = (new Database())->connect();
    $orgName = (new Settings($db))->get('organization_name', 'Arnis Connect');
} catch (Throwable $e) {
    error_log('reports.php DB error: ' . $e->getMessage());
    http_response_code(500);
    exit('Reports are temporarily unavailable. Please try again later.');
}

$rangeStart = $startDate !== '' ? $startDate . ' 00:00:00' : null;
$rangeEnd = $endDate !== '' ? $endDate . ' 23:59:59' : null;

$columns = [];
$rows = [];
$summary = [];
$title = $allowedReports[$reportType];
$note = '';
$reportSql = '';
$reportParams = [];

try {
    switch ($reportType) {
        case 'members':
            $reportSql = "
                SELECT m.full_name, m.rank, m.member_type, m.player_category, m.status,
                       m.created_at, m.rank_verification_status
                FROM members m
                WHERE m.is_voided = 0
            ";
            if ($rangeStart !== null && $rangeEnd !== null) {
                $reportSql .= ' AND m.created_at BETWEEN :start AND :end';
                $reportParams = [':start' => $rangeStart, ':end' => $rangeEnd];
            }
            $reportSql .= ' ORDER BY m.full_name ASC';

            $columns = [
                'full_name' => 'Member',
                'rank' => 'Rank',
                'member_type' => 'Type',
                'player_category' => 'Category',
                'status' => 'Status',
                'rank_verification_status' => 'Rank Verification',
                'created_at' => 'Joined',
            ];

            $stmt = $db->prepare($reportSql);
            $stmt->execute($reportParams);
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $counts = ['Active' => 0, 'Inactive' => 0, 'On Leave' => 0];
            foreach ($records as $record) {
                $status = (string)($record['status'] ?? 'Active');
                if (isset($counts[$status])) {
                    $counts[$status]++;
                }
                $record['created_at'] = rpDate((string)($record['created_at'] ?? ''));
                $rows[] = $record;
            }
            $summary = [
                ['label' => 'Total Members', 'value' => (string)count($records)],
                ['label' => 'Active', 'value' => (string)$counts['Active']],
                ['label' => 'Inactive', 'value' => (string)$counts['Inactive']],
                ['label' => 'On Leave', 'value' => (string)$counts['On Leave']],
            ];
            $note = $startDate !== '' || $endDate !== ''
                ? 'Filtered by the date the member record was created.'
                : 'Current snapshot of all active member records.';
            break;

        case 'attendance':
            $reportSql = "
                SELECT t.training_date, COALESCE(t.location, 'Session') AS location,
                       m.full_name, a.status, a.recorded_at,
                       COALESCE(u.full_name, 'System') AS recorded_by
                FROM attendance a
                INNER JOIN trainings t ON a.training_id = t.training_id
                INNER JOIN members m ON a.member_id = m.member_id
                LEFT JOIN users u ON a.recorded_by = u.user_id
                WHERE a.is_voided = 0 AND t.is_voided = 0 AND m.is_voided = 0
                  AND t.training_date BETWEEN :start AND :end
                ORDER BY t.training_date DESC, m.full_name ASC
                LIMIT 2000
            ";
            $reportParams = [':start' => $startDate, ':end' => $endDate];

            $columns = [
                'training_date' => 'Training Date',
                'location' => 'Location',
                'full_name' => 'Member',
                'status' => 'Status',
                'recorded_by' => 'Recorded By',
            ];

            $stmt = $db->prepare($reportSql);
            $stmt->execute($reportParams);
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $present = $late = $absent = 0;
            foreach ($records as $record) {
                $status = (string)($record['status'] ?? '');
                if ($status === 'Present') {
                    $present++;
                } elseif ($status === 'Late') {
                    $late++;
                } elseif ($status === 'Absent') {
                    $absent++;
                }
                $record['training_date'] = rpDate((string)($record['training_date'] ?? ''));
                $rows[] = $record;
            }
            $total = count($records);
            $rate = $total > 0 ? round((($present + $late) / $total) * 100, 1) : 0.0;
            $summary = [
                ['label' => 'Attendance Records', 'value' => (string)$total],
                ['label' => 'Present', 'value' => (string)$present],
                ['label' => 'Late', 'value' => (string)$late],
                ['label' => 'Absent', 'value' => (string)$absent],
                ['label' => 'Attendance Rate', 'value' => $rate . '%'],
            ];
            $note = $total >= 2000 ? 'Showing the most recent 2,000 records. Narrow the date range for a complete extract.' : 'Present + Late are counted as attended.';
            break;

        case 'trainings':
            $reportSql = "
                SELECT t.training_date, t.training_type, t.target_group,
                       COALESCE(t.location, 'To be announced') AS location,
                       COALESCE(m.full_name, 'Unassigned') AS coach_name,
                       t.fee_amount,
                       (SELECT COUNT(*) FROM attendance a
                         WHERE a.training_id = t.training_id AND a.is_voided = 0
                           AND a.status IN ('Present', 'Late')) AS attended
                FROM trainings t
                LEFT JOIN members m ON t.coach_id = m.member_id
                WHERE t.is_voided = 0
                  AND t.training_date BETWEEN :start AND :end
                ORDER BY t.training_date DESC
                LIMIT 2000
            ";
            $reportParams = [':start' => $startDate, ':end' => $endDate];

            $columns = [
                'training_date' => 'Date',
                'training_type' => 'Type',
                'target_group' => 'Target Group',
                'coach_name' => 'Coach',
                'location' => 'Location',
                'attended' => 'Attended',
                'fee_amount' => 'Fee',
            ];

            $stmt = $db->prepare($reportSql);
            $stmt->execute($reportParams);
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $attendedTotal = 0;
            $feeTotal = 0.0;
            foreach ($records as $record) {
                $attendedTotal += (int)($record['attended'] ?? 0);
                $feeTotal += (float)($record['fee_amount'] ?? 0);
                $record['training_date'] = rpDate((string)($record['training_date'] ?? ''));
                $record['fee_amount'] = rpMoney((float)($record['fee_amount'] ?? 0));
                $rows[] = $record;
            }
            $summary = [
                ['label' => 'Sessions', 'value' => (string)count($records)],
                ['label' => 'Total Attendances', 'value' => (string)$attendedTotal],
                ['label' => 'Session Fees', 'value' => rpMoney($feeTotal)],
            ];
            $note = 'Attendance counts exclude voided records.';
            break;

        case 'events':
            $reportSql = "
                SELECT e.event_date, e.event_name, e.event_type,
                       COALESCE(e.location, 'To be announced') AS location,
                       COALESCE(hm.full_name, 'Unassigned') AS handler_name,
                       (SELECT COUNT(*) FROM event_participants ep
                         WHERE ep.event_id = e.event_id AND ep.is_voided = 0) AS participants,
                       (SELECT COUNT(*) FROM event_participants ep
                         WHERE ep.event_id = e.event_id AND ep.is_voided = 0
                           AND ep.attendance_status IN ('Present', 'Late')) AS present_count,
                       (SELECT COUNT(*) FROM achievements ac
                         WHERE ac.event_id = e.event_id AND ac.is_voided = 0) AS achievements
                FROM events e
                LEFT JOIN members hm ON e.handler_id = hm.member_id
                WHERE e.is_voided = 0
                  AND e.event_date BETWEEN :start AND :end
                ORDER BY e.event_date DESC
                LIMIT 2000
            ";
            $reportParams = [':start' => $startDate, ':end' => $endDate];

            $columns = [
                'event_date' => 'Date',
                'event_name' => 'Event',
                'event_type' => 'Type',
                'handler_name' => 'Event Handler',
                'location' => 'Location',
                'participants' => 'Participants',
                'present_count' => 'Present',
                'achievements' => 'Achievements',
            ];

            $stmt = $db->prepare($reportSql);
            $stmt->execute($reportParams);
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $participantTotal = $presentTotal = $achievementTotal = 0;
            foreach ($records as $record) {
                $participantTotal += (int)($record['participants'] ?? 0);
                $presentTotal += (int)($record['present_count'] ?? 0);
                $achievementTotal += (int)($record['achievements'] ?? 0);
                $record['event_date'] = rpDate((string)($record['event_date'] ?? ''));
                $rows[] = $record;
            }
            $summary = [
                ['label' => 'Events', 'value' => (string)count($records)],
                ['label' => 'Participants', 'value' => (string)$participantTotal],
                ['label' => 'Present', 'value' => (string)$presentTotal],
                ['label' => 'Achievements', 'value' => (string)$achievementTotal],
            ];
            $note = 'The event handler is the staff member responsible for running the event.';
            break;

        case 'funds':
            $reportSql = "
                SELECT f.transaction_date, f.transaction_type, f.source_or_purpose,
                       f.amount, COALESCE(e.event_name, '—') AS event_name,
                       COALESCE(u.full_name, 'System') AS recorded_by
                FROM funds f
                LEFT JOIN events e ON f.related_event_id = e.event_id
                LEFT JOIN users u ON f.recorded_by = u.user_id
                WHERE f.is_voided = 0
                  AND f.transaction_date BETWEEN :start AND :end
                ORDER BY f.transaction_date DESC, f.fund_id DESC
                LIMIT 2000
            ";
            $reportParams = [':start' => $startDate, ':end' => $endDate];

            $columns = [
                'transaction_date' => 'Date',
                'transaction_type' => 'Type',
                'source_or_purpose' => 'Source / Purpose',
                'event_name' => 'Related Event',
                'amount' => 'Amount',
                'recorded_by' => 'Recorded By',
            ];

            $stmt = $db->prepare($reportSql);
            $stmt->execute($reportParams);
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $income = $expense = 0.0;
            foreach ($records as $record) {
                $amount = (float)($record['amount'] ?? 0);
                if ((string)($record['transaction_type'] ?? '') === 'Income') {
                    $income += $amount;
                } else {
                    $expense += $amount;
                }
                $record['transaction_date'] = rpDate((string)($record['transaction_date'] ?? ''));
                $record['amount'] = rpMoney($amount);
                $rows[] = $record;
            }
            $summary = [
                ['label' => 'Income', 'value' => rpMoney($income)],
                ['label' => 'Expenses', 'value' => rpMoney($expense)],
                ['label' => 'Net Balance', 'value' => rpMoney($income - $expense)],
                ['label' => 'Transactions', 'value' => (string)count($records)],
            ];
            $note = 'Voided transactions are excluded. Amounts are in Philippine pesos.';
            break;
    }
} catch (Throwable $e) {
    error_log('reports.php query error: ' . $e->getMessage());
    http_response_code(500);
    exit('The selected report could not be generated. Please try again.');
}

/* ------------------------------------------------------------- CSV export */
if ($export) {
    AuditLog::record(
        $db,
        'export',
        'reports',
        null,
        'Exported ' . strtolower($title) . ' report as CSV',
        [
            'report' => $reportType,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'rows' => count($rows),
        ]
    );

    $filename = 'arnis-' . $reportType . '-report-' . date('Ymd-His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'wb');
    if ($out === false) {
        exit;
    }
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [$orgName . ' - ' . $title . ' Report']);
    fputcsv($out, ['Range', $startDate !== '' ? $startDate : 'All dates', $endDate !== '' ? $endDate : 'All dates']);
    fputcsv($out, ['Generated', date('Y-m-d H:i:s')]);
    fputcsv($out, []);
    fputcsv($out, array_values($columns));
    foreach ($rows as $row) {
        $line = [];
        foreach (array_keys($columns) as $key) {
            $line[] = (string)($row[$key] ?? '');
        }
        fputcsv($out, $line);
    }
    fputcsv($out, []);
    foreach ($summary as $item) {
        fputcsv($out, [$item['label'], $item['value']]);
    }
    fclose($out);
    exit;
}

/* --------------------------------------------------------------- helpers */
function rpE(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function rpDate(string $value): string
{
    if ($value === '' || str_starts_with($value, '0000-00-00')) {
        return '—';
    }
    $timestamp = strtotime($value);
    return $timestamp === false ? '—' : date('M d, Y', $timestamp);
}

function rpMoney(float $value): string
{
    return '₱' . number_format($value, 2);
}

function rpUrl(array $overrides = []): string
{
    global $reportType, $startDate, $endDate;
    $query = array_merge([
        'report' => $reportType,
        'start_date' => $startDate,
        'end_date' => $endDate,
    ], $overrides);
    return '?' . http_build_query($query);
}

$reportTabs = $allowedReports;
?>
<!DOCTYPE html>
<html lang="en" class="text-[13px]">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= rpE($title) ?> Report | Arnis Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" type="image/ico" href="../arnis-favicon.ico">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,100..900;1,100..900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/sidebar.css">
    <link rel="stylesheet" href="css/style.css">
    <style>
        @media print {
            aside, .no-print { display: none !important; }
            body { background: #fff !important; color: #111 !important; }
            #page { height: auto !important; overflow: visible !important; padding: 0 !important; opacity: 1 !important; transform: none !important; }
            .card-bg { background: #fff !important; border: 1px solid #d1d5db !important; box-shadow: none !important; }
            .print-text, .print-text * { color: #111 !important; }
            table { width: 100% !important; border-collapse: collapse !important; }
            th, td { border-bottom: 1px solid #d1d5db !important; padding: 6px !important; font-size: 10px !important; }
            .print-summary { break-inside: avoid; }
            a { color: #111 !important; text-decoration: none !important; }
        }
    </style>
</head>
<body class="text-white bg-dark-purple overflow-x-hidden">
    <div class="flex">
        <?php include_once "../includes/sidebar.php"; ?>

        <main id="page" class="flex-1 p-4 sm:p-6 lg:p-8 h-screen overflow-y-auto opacity-0 translate-x-8 transition-all duration-500 ease-out">
            <div class="no-print">
                <?php include_once "../includes/header.php"; ?>
            </div>

            <div class="print-text">
                <div class="mb-6 flex flex-col lg:flex-row lg:items-end justify-between gap-4">
                    <div>
                        <div class="text-[10px] uppercase tracking-[0.2em] text-purple-400 font-black no-print">Reports</div>
                        <h1 class="text-2xl sm:text-3xl font-black uppercase italic tracking-tight"><?= rpE($title) ?> Report</h1>
                        <p class="text-xs text-gray-400 mt-1">
                            <?= rpE($orgName) ?>
                            <?php if ($startDate !== '' || $endDate !== ''): ?>
                                &middot; <?= rpE($startDate !== '' ? $startDate : 'Start') ?> to <?= rpE($endDate !== '' ? $endDate : 'Today') ?>
                            <?php else: ?>
                                &middot; All dates
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2 no-print">
                        <a href="<?= rpE(rpUrl(['export' => 'csv'])) ?>" class="bg-emerald-600 hover:bg-emerald-500 px-4 py-3 rounded-full text-[10px] font-black uppercase tracking-widest transition">
                            <i class="fas fa-file-csv mr-1"></i> Export CSV
                        </a>
                        <button type="button" onclick="window.print()" class="bg-purple-600 hover:bg-purple-500 px-4 py-3 rounded-full text-[10px] font-black uppercase tracking-widest transition">
                            <i class="fas fa-print mr-1"></i> Print / PDF
                        </button>
                    </div>
                </div>

                <!-- Report tabs -->
                <div class="flex flex-wrap gap-2 mb-4 no-print">
                    <?php foreach ($reportTabs as $key => $label): ?>
                        <a href="<?= rpE(rpUrl(['report' => $key])) ?>"
                           class="px-4 py-2.5 rounded-full text-[10px] font-black uppercase tracking-widest transition <?= $key === $reportType ? 'bg-purple-600 text-white' : 'bg-white/5 text-gray-400 hover:bg-white/10 hover:text-white' ?>">
                            <?= rpE($label) ?>
                        </a>
                    <?php endforeach; ?>
                </div>

                <!-- Filters -->
                <form method="GET" class="card-bg rounded-2xl p-4 sm:p-5 mb-6 flex flex-col sm:flex-row flex-wrap items-stretch sm:items-end gap-3 no-print">
                    <input type="hidden" name="report" value="<?= rpE($reportType) ?>">
                    <div>
                        <label class="block text-[9px] uppercase tracking-[0.16em] font-black text-gray-500 mb-1">Start Date</label>
                        <input type="date" name="start_date" value="<?= rpE($startDate) ?>" class="card-bg border border-white/10 rounded-xl px-3 py-2.5 text-xs outline-none">
                    </div>
                    <div>
                        <label class="block text-[9px] uppercase tracking-[0.16em] font-black text-gray-500 mb-1">End Date</label>
                        <input type="date" name="end_date" value="<?= rpE($endDate) ?>" class="card-bg border border-white/10 rounded-xl px-3 py-2.5 text-xs outline-none">
                    </div>
                    <button type="submit" class="bg-white/10 hover:bg-white/20 px-5 py-2.5 rounded-xl text-[10px] font-black uppercase tracking-widest transition">
                        <i class="fas fa-filter mr-1"></i> Apply
                    </button>
                    <a href="reports.php?report=<?= rpE($reportType) ?>" class="text-[10px] font-black uppercase tracking-widest text-gray-400 hover:text-white px-3 py-2.5 transition">Reset dates</a>
                </form>

                <!-- Summary -->
                <div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-6 print-summary">
                    <?php foreach ($summary as $item): ?>
                        <div class="card-bg rounded-2xl p-4 text-center">
                            <div class="text-xl sm:text-2xl font-black"><?= rpE((string)$item['value']) ?></div>
                            <div class="text-[9px] text-gray-400 uppercase tracking-widest mt-1"><?= rpE((string)$item['label']) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <p class="text-[11px] text-gray-500 mb-4"><?= rpE($note) ?></p>

                <!-- Table -->
                <div class="card-bg rounded-2xl overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse">
                            <thead class="text-gray-400 text-[10px] uppercase tracking-widest border-b border-gray-700">
                                <tr>
                                    <?php foreach ($columns as $label): ?>
                                        <th class="p-3 lg:p-4 whitespace-nowrap"><?= rpE($label) ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody class="text-xs">
                                <?php if (empty($rows)): ?>
                                    <tr>
                                        <td colspan="<?= count($columns) ?>" class="p-6 text-center text-gray-500">No records found for this report and date range.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($rows as $row): ?>
                                        <tr class="border-b border-gray-800/70 hover:bg-white/5 transition">
                                            <?php foreach (array_keys($columns) as $key): ?>
                                                <td class="p-3 lg:p-4 align-top"><?= rpE((string)($row[$key] ?? '—')) ?></td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="mt-4 text-[10px] text-gray-500 flex flex-col sm:flex-row justify-between gap-2">
                    <span><?= count($rows) ?> row<?= count($rows) === 1 ? '' : 's' ?> shown.</span>
                    <span>Generated <?= date('M d, Y g:i A') ?> by <?= rpE((string)($_SESSION['full_name'] ?? 'System')) ?></span>
                </div>
            </div>
        </main>
    </div>

    <div class="no-print">
        <?php include_once "modals/user_menu.php"; ?>
        <?php include_once "modals/edit_profile_user.php"; ?>
    </div>
</body>
<script>
window.addEventListener("load", () => {
    const page = document.getElementById("page");
    if (page) {
        page.classList.remove("opacity-0", "translate-x-8");
    }
});
</script>
</html>
