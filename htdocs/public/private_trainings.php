<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/PrivateTrainings.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$role = currentUserRole() ?? '';
if (!in_array($role, ['SuperAdmin', 'Head Coach', 'Coach/Trainer', 'Officer', 'Member'], true)) {
    http_response_code(403);
    exit('Access Denied: You do not have permission to view private trainings.');
}

function ptE($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ptMoney($v): string
{
    return $v === null || $v === '' ? '—' : '₱' . number_format((float)$v, 2);
}

function ptWhen(?string $date, ?string $start, ?string $end = null): string
{
    if ($date === null || $date === '') {
        return '—';
    }
    $out = date('D, M j, Y', strtotime($date));
    if ($start) {
        $out .= ' · ' . date('g:i A', strtotime($start));
        if ($end) {
            $out .= '–' . date('g:i A', strtotime($end));
        }
    }
    return $out;
}

$flash = $_SESSION['private_training_flash'] ?? null;
unset($_SESSION['private_training_flash']);

$loadError = null;
$requests = [];
$coaches = [];
$standardFee = 0.0;
$canRequest = false;
$selfMemberId = 0;
$coachMemberId = 0;
$todayManila = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
$isAdminReviewer = in_array($role, ['SuperAdmin', 'Head Coach'], true);
$canRecordPayment = permissionValue('funds', 'write') === true;

try {
    $db = (new Database())->connect();
    $pt = new PrivateTrainings($db);
    $standardFee = $pt->standardFee();
    $coaches = $pt->coaches();

    if ($role === 'Member') {
        $selfMemberId = resolveSessionMemberId($db);
        $canRequest = $pt->memberCanRequest($selfMemberId);
        $requests = $selfMemberId > 0 ? $pt->listFor('member', $selfMemberId) : [];
    } elseif ($isAdminReviewer) {
        $coachMemberId = (int)(getCurrentCoachMemberId($db) ?? 0);
        $requests = $pt->listFor('all');
    } elseif ($role === 'Coach/Trainer') {
        $coachMemberId = (int)(getCurrentCoachMemberId($db) ?? 0);
        $requests = $coachMemberId > 0 ? $pt->listFor('coach', $coachMemberId) : [];
    } else {
        $requests = $pt->listFor('payments');
    }
} catch (Throwable $e) {
    error_log('private_trainings.php error: ' . $e->getMessage());
    $loadError = 'Private trainings are not available right now. Please reload the page.';
}

$counts = ['Pending' => 0, 'Awaiting Member' => 0, 'Upcoming' => 0, 'Unpaid' => 0.0];
foreach ($requests as $r) {
    if (isset($counts[$r['status']])) {
        $counts[$r['status']]++;
    }
    if ($r['status'] === 'Confirmed' && (string)$r['session_date'] >= $todayManila) {
        $counts['Upcoming']++;
    }
    if ($r['status'] === 'Confirmed' && $r['payment_status'] === 'Unpaid') {
        $counts['Unpaid'] += (float)$r['fee_amount'];
    }
}

$canReviewRow = static function (array $r) use ($isAdminReviewer, $coachMemberId): bool {
    if ($isAdminReviewer) {
        return true;
    }
    if ($coachMemberId <= 0) {
        return false;
    }
    return (int)($r['coach_id'] ?? 0) === $coachMemberId
        || ($r['status'] === 'Pending' && in_array((int)($r['preferred_coach_id'] ?? 0), [0, $coachMemberId], true));
};

$statusBadge = [
    'Pending' => 'bg-yellow-500/10 text-yellow-400',
    'Awaiting Member' => 'bg-blue-500/10 text-blue-400',
    'Confirmed' => 'bg-green-500/10 text-green-400',
    'Rejected' => 'bg-red-500/10 text-red-400',
    'Declined' => 'bg-gray-500/10 text-gray-400',
    'Cancelled' => 'bg-gray-500/10 text-gray-400',
];

$csrf = arnisCsrfToken();
$inputClass = 'w-full bg-gray-900/60 border border-gray-700 rounded-xl px-3 py-2.5 text-xs focus:outline-none focus:border-purple-500/50';
?>
<!DOCTYPE html>
<html lang="en" class="text-[13px]">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Private Trainings | Arnis Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" type="image/ico" href="../arnis-favicon.ico">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,100..900;1,100..900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/sidebar.css">
    <link rel="stylesheet" href="css/style.css">
</head>
<body class="text-white bg-dark-purple overflow-x-hidden">
    <div class="flex">
        <?php include_once "../includes/sidebar.php"; ?>

        <main id="page" class="flex-1 p-4 sm:p-6 lg:p-8 h-screen overflow-y-auto opacity-0 translate-x-8 transition-all duration-500 ease-out">
            <?php include_once "../includes/header.php"; ?>

            <?php if ($flash): ?>
                <div class="<?= $flash['type'] === 'success' ? 'bg-green-500/10 border-green-500/30 text-green-400' : 'bg-red-500/10 border-red-500/30 text-red-400' ?> border rounded-2xl px-4 py-3 mb-6 text-xs sm:text-sm">
                    <i class="fas <?= $flash['type'] === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?> mr-2"></i><?= ptE($flash['message']) ?>
                </div>
            <?php endif; ?>
            <?php if ($loadError !== null): ?>
                <div class="bg-red-500/10 border border-red-500/30 text-red-400 rounded-2xl px-4 py-3 mb-6 text-xs sm:text-sm">
                    <i class="fas fa-exclamation-circle mr-2"></i><?= ptE($loadError) ?>
                </div>
            <?php endif; ?>

            <div class="card-bg border-l-4 border-l-purple-500 rounded-2xl px-4 py-3 mb-6 text-xs text-gray-300">
                <i class="fas fa-user-ninja mr-2 text-purple-400"></i>
                Group trainings are free. Private one-on-one sessions cost the standard fee of
                <strong class="text-white"><?= ptMoney($standardFee) ?></strong> per session unless the coach offers a different amount, which the member must accept before the session is booked.
            </div>

            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4 lg:gap-6 mb-6 lg:mb-8">
                <div class="card-bg p-4 sm:p-6 rounded-2xl sm:rounded-3xl text-center shadow-xl">
                    <div class="text-2xl sm:text-3xl font-bold text-yellow-400"><?= (int)$counts['Pending'] ?></div>
                    <div class="text-[10px] sm:text-xs text-gray-400 uppercase tracking-widest mt-1">Pending Review</div>
                </div>
                <div class="card-bg p-4 sm:p-6 rounded-2xl sm:rounded-3xl text-center shadow-xl">
                    <div class="text-2xl sm:text-3xl font-bold text-blue-400"><?= (int)$counts['Awaiting Member'] ?></div>
                    <div class="text-[10px] sm:text-xs text-gray-400 uppercase tracking-widest mt-1">Awaiting Member</div>
                </div>
                <div class="card-bg p-4 sm:p-6 rounded-2xl sm:rounded-3xl text-center shadow-xl">
                    <div class="text-2xl sm:text-3xl font-bold text-green-400"><?= (int)$counts['Upcoming'] ?></div>
                    <div class="text-[10px] sm:text-xs text-gray-400 uppercase tracking-widest mt-1">Upcoming Sessions</div>
                </div>
                <div class="card-bg p-4 sm:p-6 rounded-2xl sm:rounded-3xl text-center shadow-xl">
                    <div class="text-2xl sm:text-3xl font-bold text-purple-400"><?= ptMoney($counts['Unpaid']) ?></div>
                    <div class="text-[10px] sm:text-xs text-gray-400 uppercase tracking-widest mt-1">Unpaid Fees</div>
                </div>
            </div>

            <?php if ($role === 'Member'): ?>
                <?php if ($canRequest): ?>
                <form method="POST" action="../actions/process_private_training.php" class="card-bg rounded-2xl p-4 sm:p-6 mb-6">
                    <input type="hidden" name="csrf_token" value="<?= ptE($csrf) ?>">
                    <input type="hidden" name="op" value="request">
                    <h2 class="text-sm font-black uppercase tracking-widest mb-4"><i class="fas fa-plus mr-2 text-purple-400"></i>Request a Private Session</h2>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                        <label class="block">
                            <span class="block text-[10px] uppercase tracking-[0.16em] font-black text-gray-500 mb-1">Preferred Date</span>
                            <input type="date" name="preferred_date" required min="<?= ptE($todayManila) ?>" class="<?= $inputClass ?>">
                        </label>
                        <label class="block">
                            <span class="block text-[10px] uppercase tracking-[0.16em] font-black text-gray-500 mb-1">Preferred Time (optional)</span>
                            <input type="time" name="preferred_time" class="<?= $inputClass ?>">
                        </label>
                        <label class="block">
                            <span class="block text-[10px] uppercase tracking-[0.16em] font-black text-gray-500 mb-1">Coach (optional)</span>
                            <select name="preferred_coach_id" class="<?= $inputClass ?>">
                                <option value="0">Any available coach</option>
                                <?php foreach ($coaches as $c): ?>
                                    <option value="<?= (int)$c['member_id'] ?>"><?= ptE($c['full_name']) ?> (<?= ptE($c['member_type']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="block">
                            <span class="block text-[10px] uppercase tracking-[0.16em] font-black text-gray-500 mb-1">What to work on (optional)</span>
                            <input type="text" name="notes" maxlength="500" placeholder="e.g. sparring, forms for grading" class="<?= $inputClass ?>">
                        </label>
                    </div>
                    <button type="submit" class="mt-4 bg-purple-600 hover:bg-purple-500 text-white font-black uppercase text-[11px] tracking-[0.2em] px-6 py-3 rounded-xl transition">
                        <i class="fas fa-paper-plane mr-2"></i>Send Request
                    </button>
                </form>
                <?php else: ?>
                <div class="card-bg rounded-2xl px-4 py-3 mb-6 text-xs text-gray-400">
                    <i class="fas fa-info-circle mr-2"></i>Private sessions are available to Private and Mixed players. Ask the administrator to update your player category if you want private training.
                </div>
                <?php endif; ?>
            <?php endif; ?>

            <div class="space-y-3">
                <?php if ($requests === []): ?>
                    <div class="card-bg rounded-2xl p-8 text-center text-gray-500 text-xs">No private training requests yet.</div>
                <?php endif; ?>

                <?php foreach ($requests as $r):
                    $rid = (int)$r['request_id'];
                    $st = (string)$r['status'];
                    $canReview = $role !== 'Member' && $canReviewRow($r);
                    $isOwner = $role === 'Member' && (int)$r['member_id'] === $selfMemberId;
                    $isOpen = in_array($st, PrivateTrainings::OPEN_STATUSES, true);
                    $staffCancel = $canReview && ($isOpen || ($st === 'Confirmed' && (string)$r['session_date'] >= $todayManila)) && $r['payment_status'] !== 'Paid';
                ?>
                <div class="card-bg rounded-2xl p-4 sm:p-5">
                    <div class="flex flex-col lg:flex-row lg:items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2 mb-1">
                                <span class="text-sm font-bold"><?= ptE($r['member_name']) ?></span>
                                <span class="text-[10px] px-2 py-0.5 rounded-full font-bold uppercase tracking-wider <?= $statusBadge[$st] ?? '' ?>"><?= ptE($st) ?></span>
                                <?php if ($st === 'Confirmed' && (float)$r['fee_amount'] > 0): ?>
                                    <span class="text-[10px] px-2 py-0.5 rounded-full font-bold uppercase tracking-wider <?= $r['payment_status'] === 'Paid' ? 'bg-emerald-500/10 text-emerald-400' : 'bg-orange-500/10 text-orange-400' ?>"><?= ptE($r['payment_status']) ?></span>
                                <?php endif; ?>
                                <span class="text-[10px] text-gray-500">#<?= $rid ?></span>
                            </div>
                            <?php if ($st === 'Confirmed' || $st === 'Awaiting Member'): ?>
                                <p class="text-xs text-gray-300"><i class="far fa-calendar mr-1 text-gray-500"></i><?= ptE(ptWhen($r['session_date'], $r['start_time'], $r['end_time'])) ?> · <?= ptE($r['location']) ?></p>
                                <p class="text-xs text-gray-400">Coach: <?= ptE($r['coach_name'] ?? '—') ?> · Fee: <strong class="text-white"><?= ptMoney($r['fee_amount']) ?></strong></p>
                            <?php else: ?>
                                <p class="text-xs text-gray-300"><i class="far fa-calendar mr-1 text-gray-500"></i>Preferred: <?= ptE(ptWhen($r['preferred_date'], $r['preferred_time'])) ?></p>
                                <p class="text-xs text-gray-400">Coach: <?= ptE($r['coach_name'] ?? $r['preferred_coach_name'] ?? 'Any available coach') ?></p>
                            <?php endif; ?>
                            <?php if (!empty($r['notes'])): ?>
                                <p class="text-xs text-gray-400 mt-1"><i class="far fa-comment mr-1"></i><?= ptE($r['notes']) ?></p>
                            <?php endif; ?>
                            <?php if (!empty($r['decision_note'])): ?>
                                <p class="text-xs text-gray-400 mt-1"><i class="fas fa-reply mr-1"></i><?= ptE($r['decision_note']) ?></p>
                            <?php endif; ?>
                        </div>

                        <div class="flex flex-wrap gap-2 shrink-0">
                            <?php if ($st === 'Confirmed' && !empty($r['training_id']) && $role !== 'Officer'): ?>
                                <a href="attendance_session.php?id=<?= (int)$r['training_id'] ?>" class="px-3 py-2 rounded-xl bg-white/5 hover:bg-white/10 text-[10px] font-black uppercase tracking-widest"><i class="fas fa-clipboard-check mr-1"></i>Session</a>
                            <?php endif; ?>

                            <?php if ($isOwner && $st === 'Awaiting Member'): ?>
                                <form method="POST" action="../actions/process_private_training.php">
                                    <input type="hidden" name="csrf_token" value="<?= ptE($csrf) ?>">
                                    <input type="hidden" name="request_id" value="<?= $rid ?>">
                                    <button name="op" value="accept" class="px-3 py-2 rounded-xl bg-green-600 hover:bg-green-500 text-[10px] font-black uppercase tracking-widest">Accept <?= ptMoney($r['fee_amount']) ?></button>
                                    <button name="op" value="decline" class="px-3 py-2 rounded-xl bg-white/5 hover:bg-red-500/20 text-[10px] font-black uppercase tracking-widest">Decline</button>
                                </form>
                            <?php endif; ?>

                            <?php if ($canRecordPayment && $st === 'Confirmed' && $r['payment_status'] === 'Unpaid' && (float)$r['fee_amount'] > 0): ?>
                                <form method="POST" action="../actions/process_private_training.php" onsubmit="return confirm('Record <?= ptE(ptMoney($r['fee_amount'])) ?> as received? This adds a Funds income entry.');">
                                    <input type="hidden" name="csrf_token" value="<?= ptE($csrf) ?>">
                                    <input type="hidden" name="request_id" value="<?= $rid ?>">
                                    <button name="op" value="mark_paid" class="px-3 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-[10px] font-black uppercase tracking-widest"><i class="fas fa-peso-sign mr-1"></i>Mark Paid</button>
                                </form>
                            <?php endif; ?>

                            <?php if (($isOwner && $isOpen) || $staffCancel): ?>
                                <form method="POST" action="../actions/process_private_training.php" onsubmit="return confirm('Cancel this private training?');">
                                    <input type="hidden" name="csrf_token" value="<?= ptE($csrf) ?>">
                                    <input type="hidden" name="request_id" value="<?= $rid ?>">
                                    <button name="op" value="cancel" class="px-3 py-2 rounded-xl bg-white/5 hover:bg-red-500/20 text-[10px] font-black uppercase tracking-widest">Cancel</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($canReview && $st === 'Pending'): ?>
                    <details class="mt-4 border-t border-gray-800 pt-4">
                        <summary class="cursor-pointer text-[11px] font-black uppercase tracking-widest text-purple-400">Review request</summary>
                        <form method="POST" action="../actions/process_private_training.php" class="mt-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                            <input type="hidden" name="csrf_token" value="<?= ptE($csrf) ?>">
                            <input type="hidden" name="request_id" value="<?= $rid ?>">
                            <?php if ($isAdminReviewer): ?>
                            <label class="block">
                                <span class="block text-[10px] uppercase tracking-[0.16em] font-black text-gray-500 mb-1">Coach</span>
                                <select name="coach_id" class="<?= $inputClass ?>">
                                    <?php $pick = (int)($r['preferred_coach_id'] ?? 0) ?: $coachMemberId; ?>
                                    <?php foreach ($coaches as $c): ?>
                                        <option value="<?= (int)$c['member_id'] ?>"<?= (int)$c['member_id'] === $pick ? ' selected' : '' ?>><?= ptE($c['full_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <?php endif; ?>
                            <label class="block">
                                <span class="block text-[10px] uppercase tracking-[0.16em] font-black text-gray-500 mb-1">Date</span>
                                <input type="date" name="session_date" min="<?= ptE($todayManila) ?>" value="<?= ptE(max((string)$r['preferred_date'], $todayManila)) ?>" class="<?= $inputClass ?>">
                            </label>
                            <?php $prefStart = $r['preferred_time'] ? substr((string)$r['preferred_time'], 0, 5) : '16:00'; ?>
                            <label class="block">
                                <span class="block text-[10px] uppercase tracking-[0.16em] font-black text-gray-500 mb-1">Start</span>
                                <input type="time" name="start_time" value="<?= ptE($prefStart) ?>" class="<?= $inputClass ?>">
                            </label>
                            <label class="block">
                                <span class="block text-[10px] uppercase tracking-[0.16em] font-black text-gray-500 mb-1">End</span>
                                <input type="time" name="end_time" value="<?= ptE(date('H:i', strtotime($prefStart) + 3600)) ?>" class="<?= $inputClass ?>">
                            </label>
                            <label class="block">
                                <span class="block text-[10px] uppercase tracking-[0.16em] font-black text-gray-500 mb-1">Location</span>
                                <input type="text" name="location" maxlength="255" placeholder="Venue" class="<?= $inputClass ?>">
                            </label>
                            <label class="block">
                                <span class="block text-[10px] uppercase tracking-[0.16em] font-black text-gray-500 mb-1">Fee (standard <?= ptMoney($standardFee) ?>)</span>
                                <input type="number" name="fee_amount" min="0" max="100000" step="0.01" value="<?= ptE(number_format($standardFee, 2, '.', '')) ?>" class="<?= $inputClass ?>">
                            </label>
                            <label class="block sm:col-span-2 lg:col-span-3">
                                <span class="block text-[10px] uppercase tracking-[0.16em] font-black text-gray-500 mb-1">Message to member (required to reject)</span>
                                <input type="text" name="decision_note" maxlength="255" class="<?= $inputClass ?>">
                            </label>
                            <div class="sm:col-span-2 lg:col-span-3 flex flex-wrap gap-2">
                                <button name="op" value="approve" class="px-4 py-2.5 rounded-xl bg-green-600 hover:bg-green-500 text-[10px] font-black uppercase tracking-widest"><i class="fas fa-check mr-1"></i>Approve &amp; Schedule</button>
                                <button name="op" value="reject" class="px-4 py-2.5 rounded-xl bg-white/5 hover:bg-red-500/20 text-[10px] font-black uppercase tracking-widest"><i class="fas fa-times mr-1"></i>Reject</button>
                            </div>
                            <p class="sm:col-span-2 lg:col-span-3 text-[10px] text-gray-500">At the standard fee the session is booked immediately. A different fee is sent to the member to accept first.</p>
                        </form>
                    </details>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
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
