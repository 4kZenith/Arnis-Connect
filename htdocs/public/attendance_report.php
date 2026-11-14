<?php
declare(strict_types=1);

/**
 * Monthly attendance per member, with a 3-month rate and sessions attended
 * since the last promotion to support belt-promotion decisions.
 *
 * Scope: SuperAdmin / Head Coach see everyone, a Coach sees members rostered on
 * their own sessions, a Member sees only themselves. Sessions that have not
 * finished yet are not counted, so placeholder "Absent" rows never skew rates.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/AuditLog.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

const AR_REVIEW_MIN_RATE = 80;
const AR_REVIEW_MIN_SESSIONS = 8;

$role = currentUserRole() ?? '';
if (!in_array($role, ['SuperAdmin', 'Head Coach', 'Coach/Trainer', 'Member'], true)) {
    http_response_code(403);
    exit('Access Denied: You do not have permission to view attendance reports.');
}

function arE($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$tz = new DateTimeZone('Asia/Manila');
$now = new DateTimeImmutable('now', $tz);

$monthRaw = isset($_GET['month']) && is_string($_GET['month']) ? $_GET['month'] : '';
$monthStart = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $monthRaw) === 1
    ? new DateTimeImmutable($monthRaw . '-01', $tz)
    : $now->modify('first day of this month')->setTime(0, 0);
$monthEnd = $monthStart->modify('last day of this month');
$quarterStart = $monthStart->modify('-2 months');
$monthValue = $monthStart->format('Y-m');

$search = isset($_GET['q']) && is_string($_GET['q']) ? mb_substr(trim($_GET['q']), 0, 100) : '';
$export = isset($_GET['export']) && $_GET['export'] === 'csv';

$rows = [];
$loadError = null;
$scopeNote = '';

try {
    $db = (new Database())->connect();

    $finished = "(t.training_date < :today1 OR (t.training_date = :today2
                  AND COALESCE(t.end_time, ADDTIME(t.start_time, '03:00:00'), '23:59:59') <= :nowtime))";
    $attended = "a.status IN ('Present', 'Late')";
    $inMonth = 't.training_date BETWEEN :ms1 AND :me1';

    $where = ['m.is_voided = 0'];
    $params = [
        ':today1' => $now->format('Y-m-d'),
        ':today2' => $now->format('Y-m-d'),
        ':nowtime' => $now->format('H:i:s'),
        ':ms1' => $monthStart->format('Y-m-d'), ':me1' => $monthEnd->format('Y-m-d'),
        ':ms2' => $monthStart->format('Y-m-d'), ':me2' => $monthEnd->format('Y-m-d'),
        ':ms3' => $monthStart->format('Y-m-d'), ':me3' => $monthEnd->format('Y-m-d'),
        ':ms4' => $monthStart->format('Y-m-d'), ':me4' => $monthEnd->format('Y-m-d'),
        ':qs1' => $quarterStart->format('Y-m-d'), ':qe1' => $monthEnd->format('Y-m-d'),
        ':qs2' => $quarterStart->format('Y-m-d'), ':qe2' => $monthEnd->format('Y-m-d'),
    ];

    if ($role === 'Member') {
        $where[] = 'm.member_id = :self';
        $params[':self'] = resolveSessionMemberId($db);
        $scopeNote = 'Your own attendance.';
    } elseif ($role === 'Coach/Trainer') {
        $where[] = 'm.member_id IN (SELECT a2.member_id FROM attendance a2
                     JOIN trainings t2 ON t2.training_id = a2.training_id
                     WHERE t2.coach_id = :coach AND a2.is_voided = 0 AND t2.is_voided = 0)';
        $params[':coach'] = (int)(getCurrentCoachMemberId($db) ?? 0);
        $scopeNote = 'Members you have coached. Counts include all their sessions, not only yours.';
    } else {
        $where[] = "(m.member_type = 'Player' OR m.member_id IN (SELECT a3.member_id FROM attendance a3 WHERE a3.is_voided = 0))";
        $scopeNote = 'All players.';
    }
    if ($search !== '') {
        $where[] = 'm.full_name LIKE :q';
        $params[':q'] = '%' . $search . '%';
    }

    $sql = "
        SELECT m.member_id, m.full_name, m.rank, m.player_category, m.status, rh.last_promo,
               COALESCE(SUM(t.training_id IS NOT NULL AND {$inMonth}), 0) AS m_total,
               COALESCE(SUM(t.training_id IS NOT NULL AND t.training_date BETWEEN :ms2 AND :me2 AND a.status = 'Present'), 0) AS m_present,
               COALESCE(SUM(t.training_id IS NOT NULL AND t.training_date BETWEEN :ms3 AND :me3 AND a.status = 'Late'), 0) AS m_late,
               COALESCE(SUM(t.training_id IS NOT NULL AND t.training_date BETWEEN :ms4 AND :me4 AND a.status = 'Absent'), 0) AS m_absent,
               COALESCE(SUM(t.training_id IS NOT NULL AND t.training_date BETWEEN :qs1 AND :qe1), 0) AS q_total,
               COALESCE(SUM(t.training_id IS NOT NULL AND t.training_date BETWEEN :qs2 AND :qe2 AND {$attended}), 0) AS q_attended,
               COALESCE(SUM(t.training_id IS NOT NULL AND {$attended}
                            AND (rh.last_promo IS NULL OR t.training_date > DATE(rh.last_promo))), 0) AS since_promo
        FROM members m
        LEFT JOIN (SELECT member_id, MAX(promoted_at) AS last_promo FROM rank_history GROUP BY member_id) rh
               ON rh.member_id = m.member_id
        LEFT JOIN attendance a ON a.member_id = m.member_id AND a.is_voided = 0
        LEFT JOIN trainings t ON t.training_id = a.training_id AND t.is_voided = 0 AND {$finished}
        WHERE " . implode(' AND ', $where) . "
        GROUP BY m.member_id, m.full_name, m.rank, m.player_category, m.status, rh.last_promo
        ORDER BY m.full_name ASC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        $mTotal = (int)$r['m_total'];
        $qTotal = (int)$r['q_total'];
        $r['m_rate'] = $mTotal > 0 ? (int)round(((int)$r['m_present'] + (int)$r['m_late']) * 100 / $mTotal) : null;
        $r['q_rate'] = $qTotal > 0 ? (int)round((int)$r['q_attended'] * 100 / $qTotal) : null;
        $r['review'] = $r['q_rate'] !== null && $r['q_rate'] >= AR_REVIEW_MIN_RATE
            && (int)$r['since_promo'] >= AR_REVIEW_MIN_SESSIONS;
        $rows[] = $r;
    }
} catch (Throwable $e) {
    error_log('attendance_report.php error: ' . $e->getMessage());
    $loadError = 'The attendance report is not available right now. Please reload the page.';
}

if ($export && $loadError === null) {
    AuditLog::record($db, 'export', 'reports', null, 'Exported monthly attendance report for ' . $monthValue);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="attendance_' . $monthValue . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Member', 'Rank', 'Category', 'Sessions', 'Present', 'Late', 'Absent', 'Attendance %',
        '3-Month %', 'Attended Since Last Promotion', 'Last Promotion', 'Consider For Promotion']);
    foreach ($rows as $r) {
        $line = [
            $r['full_name'], $r['rank'], $r['player_category'], $r['m_total'], $r['m_present'], $r['m_late'],
            $r['m_absent'], $r['m_rate'] ?? '', $r['q_rate'] ?? '', $r['since_promo'],
            $r['last_promo'] ? substr((string)$r['last_promo'], 0, 10) : '', $r['review'] ? 'Yes' : 'No',
        ];
        foreach ($line as $i => $cell) {
            if (is_string($cell) && $cell !== '' && strpbrk($cell[0], '=+-@') !== false) {
                $line[$i] = "'" . $cell;
            }
        }
        fputcsv($out, $line);
    }
    fclose($out);
    exit;
}

$totals = ['sessions' => 0, 'attended' => 0, 'review' => 0];
foreach ($rows as $r) {
    $totals['sessions'] += (int)$r['m_total'];
    $totals['attended'] += (int)$r['m_present'] + (int)$r['m_late'];
    $totals['review'] += $r['review'] ? 1 : 0;
}
$overallRate = $totals['sessions'] > 0 ? (int)round($totals['attended'] * 100 / $totals['sessions']) : null;
$canOpenProfiles = $role !== 'Member';

$rateClass = static function (?int $rate): string {
    if ($rate === null) {
        return 'text-gray-500';
    }
    return $rate >= AR_REVIEW_MIN_RATE ? 'text-green-400' : ($rate >= 50 ? 'text-yellow-400' : 'text-red-400');
};
?>
<!DOCTYPE html>
<html lang="en" class="text-[13px]">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Report | Arnis Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" type="image/ico" href="../arnis-favicon.ico">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,100..900;1,100..900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/sidebar.css">
    <link rel="stylesheet" href="css/style.css">
    <style>@media print { .no-print, #sidebar { display: none !important; } #page { height: auto; overflow: visible; } }</style>
</head>
<body class="text-white bg-dark-purple overflow-x-hidden">
    <div class="flex">
        <?php include_once "../includes/sidebar.php"; ?>

        <main id="page" class="flex-1 p-4 sm:p-6 lg:p-8 h-screen overflow-y-auto opacity-0 translate-x-8 transition-all duration-500 ease-out">
            <div class="no-print"><?php include_once "../includes/header.php"; ?></div>

            <?php if ($loadError !== null): ?>
                <div class="bg-red-500/10 border border-red-500/30 text-red-400 rounded-2xl px-4 py-3 mb-6 text-xs sm:text-sm">
                    <i class="fas fa-exclamation-circle mr-2"></i><?= arE($loadError) ?>
                </div>
            <?php endif; ?>

            <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-2 mb-4">
                <div>
                    <h1 class="text-xl font-black uppercase italic tracking-tight">Attendance Report · <span class="text-purple-400"><?= arE($monthStart->format('F Y')) ?></span></h1>
                    <p class="text-[11px] text-gray-500 mt-1"><?= arE($scopeNote) ?> Only finished sessions are counted.</p>
                </div>
            </div>

            <form method="GET" class="card-bg rounded-2xl p-4 mb-6 flex flex-col sm:flex-row flex-wrap items-stretch sm:items-end gap-3 no-print">
                <label class="flex flex-col gap-1">
                    <span class="text-[10px] uppercase tracking-widest text-gray-400 font-bold">Month</span>
                    <input type="month" name="month" value="<?= arE($monthValue) ?>" max="<?= arE($now->format('Y-m')) ?>" class="card-bg border border-white/10 rounded-xl px-3 py-2.5 text-xs outline-none">
                </label>
                <?php if ($role !== 'Member'): ?>
                <label class="flex flex-col gap-1 flex-1 min-w-[180px]">
                    <span class="text-[10px] uppercase tracking-widest text-gray-400 font-bold">Member</span>
                    <input type="text" name="q" value="<?= arE($search) ?>" placeholder="Search name" class="card-bg border border-white/10 rounded-xl px-3 py-2.5 text-xs outline-none">
                </label>
                <?php endif; ?>
                <button class="bg-purple-600 hover:bg-purple-500 px-4 py-2.5 rounded-xl text-[11px] font-black uppercase tracking-widest"><i class="fas fa-filter mr-1"></i>Show</button>
                <a href="?<?= arE(http_build_query(['month' => $monthValue, 'q' => $search, 'export' => 'csv'])) ?>" class="bg-white/5 hover:bg-white/10 px-4 py-2.5 rounded-xl text-[11px] font-black uppercase tracking-widest text-center"><i class="fas fa-file-csv mr-1"></i>CSV</a>
                <button type="button" onclick="window.print()" class="bg-white/5 hover:bg-white/10 px-4 py-2.5 rounded-xl text-[11px] font-black uppercase tracking-widest"><i class="fas fa-print mr-1"></i>Print</button>
            </form>

            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4 mb-6">
                <div class="card-bg p-4 rounded-2xl text-center">
                    <div class="text-2xl font-bold"><?= count($rows) ?></div>
                    <div class="text-[10px] text-gray-400 uppercase tracking-widest mt-1">Members</div>
                </div>
                <div class="card-bg p-4 rounded-2xl text-center">
                    <div class="text-2xl font-bold"><?= (int)$totals['sessions'] ?></div>
                    <div class="text-[10px] text-gray-400 uppercase tracking-widest mt-1">Rostered Sessions</div>
                </div>
                <div class="card-bg p-4 rounded-2xl text-center">
                    <div class="text-2xl font-bold <?= $rateClass($overallRate) ?>"><?= $overallRate === null ? '—' : $overallRate . '%' ?></div>
                    <div class="text-[10px] text-gray-400 uppercase tracking-widest mt-1">Overall Attendance</div>
                </div>
                <div class="card-bg p-4 rounded-2xl text-center">
                    <div class="text-2xl font-bold text-purple-400"><?= (int)$totals['review'] ?></div>
                    <div class="text-[10px] text-gray-400 uppercase tracking-widest mt-1">Consider for Promotion</div>
                </div>
            </div>

            <div class="card-bg rounded-2xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead class="text-gray-400 text-[10px] uppercase tracking-widest border-b border-gray-700">
                            <tr>
                                <th class="p-3 whitespace-nowrap">Member</th>
                                <th class="p-3 whitespace-nowrap">Rank</th>
                                <th class="p-3 text-center">Sessions</th>
                                <th class="p-3 text-center">Present</th>
                                <th class="p-3 text-center">Late</th>
                                <th class="p-3 text-center">Absent</th>
                                <th class="p-3 text-center whitespace-nowrap">This Month</th>
                                <th class="p-3 text-center whitespace-nowrap">3 Months</th>
                                <th class="p-3 text-center whitespace-nowrap">Since Promotion</th>
                                <th class="p-3 whitespace-nowrap">Promotion</th>
                            </tr>
                        </thead>
                        <tbody class="text-xs">
                            <?php if ($rows === []): ?>
                                <tr><td colspan="10" class="p-6 text-center text-gray-500">No members found.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($rows as $r): ?>
                                <tr class="border-b border-gray-800/70 hover:bg-white/5 transition">
                                    <td class="p-3 font-semibold">
                                        <?php if ($canOpenProfiles): ?>
                                            <a href="member_profile.php?id=<?= (int)$r['member_id'] ?>" class="hover:text-purple-300"><?= arE($r['full_name']) ?></a>
                                        <?php else: ?>
                                            <?= arE($r['full_name']) ?>
                                        <?php endif; ?>
                                        <div class="text-[10px] text-gray-500 font-normal"><?= arE($r['player_category']) ?><?= $r['status'] !== 'Active' ? ' · ' . arE($r['status']) : '' ?></div>
                                    </td>
                                    <td class="p-3 whitespace-nowrap"><?= arE($r['rank']) ?></td>
                                    <td class="p-3 text-center"><?= (int)$r['m_total'] ?></td>
                                    <td class="p-3 text-center text-green-400"><?= (int)$r['m_present'] ?></td>
                                    <td class="p-3 text-center text-yellow-400"><?= (int)$r['m_late'] ?></td>
                                    <td class="p-3 text-center text-red-400"><?= (int)$r['m_absent'] ?></td>
                                    <td class="p-3 text-center font-bold <?= $rateClass($r['m_rate']) ?>"><?= $r['m_rate'] === null ? '—' : $r['m_rate'] . '%' ?></td>
                                    <td class="p-3 text-center <?= $rateClass($r['q_rate']) ?>"><?= $r['q_rate'] === null ? '—' : $r['q_rate'] . '%' ?></td>
                                    <td class="p-3 text-center"><?= (int)$r['since_promo'] ?></td>
                                    <td class="p-3 whitespace-nowrap">
                                        <?php if ($r['review']): ?>
                                            <span class="text-[10px] px-2 py-0.5 rounded-full font-bold uppercase tracking-wider bg-purple-500/15 text-purple-300">Consider</span>
                                        <?php endif; ?>
                                        <div class="text-[10px] text-gray-500"><?= $r['last_promo'] ? 'Last: ' . arE(date('M j, Y', strtotime((string)$r['last_promo']))) : 'Never promoted' ?></div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <p class="text-[10px] text-gray-500 mt-4">
                Attendance % counts Present and Late. "Consider" means at least <?= AR_REVIEW_MIN_RATE ?>% attendance over the last 3 months
                and at least <?= AR_REVIEW_MIN_SESSIONS ?> sessions attended since the last promotion. It is a guide only; the coach still assesses skill before promoting.
                Generated <?= arE($now->format('M j, Y g:i A')) ?> (Philippine time).
            </p>
        </main>
    </div>

    <?php include_once "modals/user_menu.php"; ?>
    <?php include_once "modals/edit_profile_user.php"; ?>
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
