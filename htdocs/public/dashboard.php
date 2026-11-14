<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/Members.php';
require_once __DIR__ . '/../classes/Trainings.php';
require_once __DIR__ . '/../classes/Events.php';
require_once __DIR__ . '/../classes/Funds.php';
require_once __DIR__ . '/../classes/AuditLog.php';

/*
 * config/permissions.php is the single source of truth for the RBAC matrix.
 * The previous inline $permissions array in this file only covered 5 roles, so
 * Member and Applicant resolved every lookup to false and saw an empty
 * dashboard. canAccess()/getPermission() are kept as thin aliases so the
 * template below does not change.
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

$userRole = (string)($_SESSION['role'] ?? '');
$userId = (int)($_SESSION['user_id'] ?? 0);

$activityPage = isset($_GET['activity_page']) ? max(1, (int)$_GET['activity_page']) : 1;
$trainingPage = isset($_GET['training_page']) ? max(1, (int)$_GET['training_page']) : 1;
$eventPage = isset($_GET['event_page']) ? max(1, (int)$_GET['event_page']) : 1;

$activityPerPage = 10;
$trainingPerPage = 5;
$eventPerPage = 2;

/** Tables the activity feed is allowed to DESCRIBE. Hardcoded whitelist, no interpolation of user input. */
const DASHBOARD_FEED_TABLES = ['members', 'events', 'trainings', 'funds', 'achievements'];

/**
 * Resolves the member_id that this login owns, so coach_id / event_participants
 * filters are compared against a member_id and not against users.user_id.
 * Prefers users.member_id (populated by auth.php); falls back to the legacy
 * full_name match for rows added before the member_id column existed.
 */
function dashboardResolveCoachMemberId(PDO $db): ?int
{
    static $resolved = false;
    static $coachMemberId = null;

    if ($resolved) {
        return $coachMemberId;
    }
    $resolved = true;

    $sessionMemberId = $_SESSION['member_id'] ?? null;
    if ($sessionMemberId !== null && (int)$sessionMemberId > 0) {
        $coachMemberId = (int)$sessionMemberId;
        return $coachMemberId;
    }

    try {
        $stmt = $db->prepare(
            "SELECT member_id
             FROM members
             WHERE is_voided = 0
               AND member_type IN ('Head Coach', 'Coach', 'Trainer')
               AND full_name = :full_name
             ORDER BY member_id ASC
             LIMIT 1"
        );
        $stmt->execute([':full_name' => (string)($_SESSION['full_name'] ?? '')]);
        $found = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($found) {
            $coachMemberId = (int)$found['member_id'];
        }
    } catch (Throwable $e) {
        error_log('Dashboard coach member lookup failed: ' . $e->getMessage());
    }

    return $coachMemberId;
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

function formatTime($time): string
{
    $time = (string)($time ?? '');
    if ($time === '' || str_starts_with($time, '00:00:0') && strlen($time) <= 5) {
        return 'TBA';
    }
    $timestamp = strtotime($time);
    return $timestamp === false ? 'TBA' : date('h:i A', $timestamp);
}

function formatCurrency($amount): string
{
    return "\u{20B1}" . number_format((float)$amount, 2);
}

try {
    $db = (new Database())->connect();

    $coachMemberId = dashboardResolveCoachMemberId($db);

    /*
     * The member behind this login. Prefer the linked users.member_id, but
     * many accounts were created before linking existed — fall back to an
     * exact full_name match so Member/Applicant/Coach panels still render.
     * (Set Users → Edit → Linked Member ID for a permanent link.)
     */
    $myMemberId = (isset($_SESSION['member_id']) && $_SESSION['member_id'] !== null)
        ? (int)$_SESSION['member_id'] : null;
    if (($myMemberId === null || $myMemberId <= 0)
        && in_array($userRole, ['Member', 'Applicant', 'Coach/Trainer', 'Head Coach'], true)) {
        try {
            $stmt = $db->prepare(
                "SELECT member_id FROM members
                 WHERE is_voided = 0 AND full_name = :name
                 ORDER BY member_id ASC LIMIT 1"
            );
            $stmt->execute([':name' => (string)($_SESSION['full_name'] ?? '')]);
            $foundMember = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($foundMember) {
                $myMemberId = (int)$foundMember['member_id'];
            }
        } catch (Throwable $e) {
            error_log('Dashboard member fallback lookup failed: ' . $e->getMessage());
        }
    }

    $membersReadPerm = getPermission('members', 'read');
    $trainingsReadPerm = getPermission('trainings', 'read');
    $trainingsWritePerm = getPermission('trainings', 'write');
    $eventsReadPerm = getPermission('events', 'read');
    $eventsWritePerm = getPermission('events', 'write');

    $trainingsScopedToOwn = in_array('own', [$trainingsReadPerm, $trainingsWritePerm], true);
    $eventsScopedToOwn = ($eventsReadPerm === 'own');

    // --- Members ---------------------------------------------------------
    $allMembers = [];
    $activeMembers = 0;
    $memberStats = ['total_members' => 0, 'public_count' => 0, 'private_count' => 0, 'mixed_count' => 0];
    if (canAccess('members')) {
        $membersModel = new Members($db);
        $allMembers = $membersModel->getAll();
        $activeMembers = count(array_filter(
            $allMembers,
            static fn(array $m): bool => (string)($m['status'] ?? '') === 'Active'
        ));
        if (method_exists($membersModel, 'getStats')) {
            $memberStats = $membersModel->getStats();
        }
    }

    // --- Trainings -------------------------------------------------------
    $trainings = [];
    $upcomingTrainings = [];
    $trainingStats = ['upcoming_trainings' => 0, 'public_sessions' => 0, 'private_sessions' => 0];
    if (canAccess('trainings')) {
        $trainingsModel = new Trainings($db);
        $trainings = $trainingsModel->getAll();

        if ($trainingsScopedToOwn) {
            if ($userRole === 'Member' && $myMemberId !== null && $myMemberId > 0) {
                /* Players see sessions they are rostered on — not sessions
                 * they coach (coach filter below would leave them empty). */
                try {
                    $stmt = $db->prepare(
                        "SELECT DISTINCT training_id FROM attendance
                         WHERE member_id = :mid AND is_voided = 0"
                    );
                    $stmt->execute([':mid' => $myMemberId]);
                    $myTrainingIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
                    $trainings = array_values(array_filter(
                        $trainings,
                        static fn(array $t): bool => in_array((int)($t['training_id'] ?? 0), $myTrainingIds, true)
                    ));
                } catch (Throwable $e) {
                    error_log('Dashboard member trainings failed: ' . $e->getMessage());
                    $trainings = [];
                }
            } elseif ($coachMemberId !== null) {
                $trainings = array_values(array_filter(
                    $trainings,
                    static fn(array $t): bool => (int)($t['coach_id'] ?? 0) === $coachMemberId
                ));
            } else {
                $trainings = [];
            }
        }

        $today = strtotime(date('Y-m-d'));
        $upcomingTrainings = array_values(array_filter(
            $trainings,
            static fn(array $t): bool => (int)($t['is_voided'] ?? 0) === 0
                && strtotime((string)($t['training_date'] ?? '')) >= $today
        ));

        if (method_exists($trainingsModel, 'getStatsOptimized')) {
            $trainingStats = $trainingsModel->getStatsOptimized();
        }
    }

    // --- Funds -----------------------------------------------------------
    $fundsStats = ['income' => 0, 'expenses' => 0, 'balance' => 0, 'month' => date('Y-m')];
    $voidedCount = 0;
    if (canAccess('funds')) {
        $fundsModel = new Funds($db);
        $fundsStats = $fundsModel->getStats();
        // getVoided() only returns the first page; getVoidedCount() is the real total.
        $voidedCount = $fundsModel->getVoidedCount();
    }

    // --- Events ----------------------------------------------------------
    $events = [];
    $upcomingEvents = [];
    if (canAccess('events')) {
        $eventsModel = new Events($db);
        $events = $eventsModel->getAll();

        if ($eventsScopedToOwn) {
            if ($coachMemberId !== null) {
                $stmt = $db->prepare(
                    "SELECT DISTINCT event_id
                     FROM event_participants
                     WHERE member_id = :member_id AND is_voided = 0"
                );
                $stmt->execute([':member_id' => $coachMemberId]);
                $ownEventIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
                $events = array_values(array_filter(
                    $events,
                    static fn(array $e): bool => in_array((int)($e['event_id'] ?? 0), $ownEventIds, true)
                ));
            } else {
                $events = [];
            }
        }

        $today = strtotime(date('Y-m-d'));
        $upcomingEvents = array_values(array_filter(
            $events,
            static fn(array $e): bool => (int)($e['is_voided'] ?? 0) === 0
                && strtotime((string)($e['event_date'] ?? '')) >= $today
        ));
    }

    // --- Role-specific panels --------------------------------------------
    // Member/Applicant/Coach see "my" data here so their dashboard is a
    // personal workspace, not a shrunken admin overview. All queries are
    // scoped to the session's member_id / user_id and fail soft.
    // ($myMemberId was resolved near the top, with a full_name fallback.)
    $pendingApprovalCount = 0;
    if (canAccess('members', 'write')) {
        try {
            $stmt = $db->prepare("SELECT COUNT(*) FROM registration_requests WHERE status = 'Pending'");
            $stmt->execute();
            $pendingApprovalCount = (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('Dashboard pending approvals failed: ' . $e->getMessage());
        }
    }
    $myProfileRow = null;
    $myAttendance = ['present' => 0, 'late' => 0, 'absent' => 0, 'total' => 0];
    $myAchievements = [];
    $myPromoCount = 0;
    $myRecentPromos = [];
    $mySessionsAtBelt = 0;
    $myBeltSince = null;
    $myUpcomingSessions = [];
    $myApplication = null;
    $myTraineeCount = 0;
    $myCreatedEvents = 0;

    if ($myMemberId !== null && $myMemberId > 0
        && in_array($userRole, ['Member', 'Applicant', 'Coach/Trainer', 'Head Coach'], true)) {
        try {
            $stmt = $db->prepare(
                "SELECT full_name, rank, status, player_category, member_type
                 FROM members WHERE member_id = :id AND is_voided = 0 LIMIT 1"
            );
            $stmt->execute([':id' => $myMemberId]);
            $myProfileRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

            $stmt = $db->prepare(
                "SELECT status, COUNT(*) AS c FROM attendance
                 WHERE member_id = :id AND is_voided = 0 GROUP BY status"
            );
            $stmt->execute([':id' => $myMemberId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $arow) {
                $ak = strtolower((string)($arow['status'] ?? ''));
                if (isset($myAttendance[$ak])) {
                    $myAttendance[$ak] = (int)$arow['c'];
                }
            }
            $myAttendance['total'] = $myAttendance['present'] + $myAttendance['late'] + $myAttendance['absent'];

            $stmt = $db->prepare(
                "SELECT a.achievement_type, a.level, a.achieved_at, e.event_name
                 FROM achievements a LEFT JOIN events e ON e.event_id = a.event_id
                 WHERE a.member_id = :id AND a.is_voided = 0
                 ORDER BY a.achieved_at DESC, a.achievement_id DESC LIMIT 5"
            );
            $stmt->execute([':id' => $myMemberId]);
            $myAchievements = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM rank_history WHERE member_id = :id"
            );
            $stmt->execute([':id' => $myMemberId]);
            $myPromoCount = (int)$stmt->fetchColumn();

            /* Belt-progress widget data: latest promotions + sessions held
             * at the current belt (Present only) for the guideline bar. */
            $myRecentPromos = [];
            $mySessionsAtBelt = 0;
            $myBeltSince = null;
            try {
                $stmt = $db->prepare(
                    "SELECT old_rank, new_rank, promoted_at
                     FROM rank_history WHERE member_id = :id
                     ORDER BY promoted_at DESC, history_id DESC LIMIT 3"
                );
                $stmt->execute([':id' => $myMemberId]);
                $myRecentPromos = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                if (!empty($myRecentPromos[0]['promoted_at'])) {
                    $myBeltSince = substr((string)$myRecentPromos[0]['promoted_at'], 0, 10);
                }
                if ($myBeltSince !== null) {
                    $stmt = $db->prepare(
                        "SELECT COUNT(*) FROM attendance a
                         JOIN trainings t ON t.training_id = a.training_id
                         WHERE a.member_id = :id AND a.is_voided = 0 AND t.is_voided = 0
                           AND a.status = 'Present' AND t.training_date >= :since"
                    );
                    $stmt->execute([':id' => $myMemberId, ':since' => $myBeltSince]);
                } else {
                    $stmt = $db->prepare(
                        "SELECT COUNT(*) FROM attendance
                         WHERE member_id = :id AND is_voided = 0 AND status = 'Present'"
                    );
                    $stmt->execute([':id' => $myMemberId]);
                }
                $mySessionsAtBelt = (int)$stmt->fetchColumn();
            } catch (Throwable $e) {
                error_log('Dashboard belt progress failed: ' . $e->getMessage());
            }

            /* Upcoming sessions this member is rostered in (read-only —
             * marking is the coach's job until face + GPS check-in lands). */
            $stmt = $db->prepare(
                "SELECT t.training_id, t.training_date, t.start_time, t.location,
                        m.full_name AS coach_name
                 FROM attendance a
                 JOIN trainings t ON t.training_id = a.training_id
                 LEFT JOIN members m ON m.member_id = t.coach_id
                 WHERE a.member_id = :id AND a.is_voided = 0
                   AND t.is_voided = 0 AND t.training_date >= CURDATE()
                 ORDER BY t.training_date ASC, t.start_time ASC LIMIT 5"
            );
            $stmt->execute([':id' => $myMemberId]);
            $myUpcomingSessions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('Dashboard my-panel failed: ' . $e->getMessage());
        }
    }

    /* Next belt target for the Member journey card. */
    $beltOrder = ['None', 'I. White Belt', 'II. Yellow Belt', 'III. Green Belt', 'IV. Blue Belt', 'V. Brown Belt', 'VI. Black Belt', 'VII. Red Belt', '1st Degree- Junior Instructor', '2nd Degree- Junior Instructor', '3rd Degree- Instructor', '4th Degree- Instructor', '5th Degree- Senior Instructor', '6th Degree- Senior Instructor', '7th Degree- Junior Master', '8th Degree- Master', '9th Degree- Senior Master', '10th Degree- Grandmaster', '11th Degree- Senior Grandmaster', '12th Degree- Superior Grandmaster'];
    $myNextRank = null;
    if ($myProfileRow !== null) {
        $pos = array_search((string)($myProfileRow['rank'] ?? 'None'), $beltOrder, true);
        if ($pos !== false && isset($beltOrder[$pos + 1])) {
            $myNextRank = $beltOrder[$pos + 1];
        }
    }

    if ($userRole === 'Applicant') {
        try {
            $stmt = $db->prepare(
                "SELECT request_id, full_name, status, submitted_at, reviewed_at, player_category,
                        requested_rank, rank_verification_status, rank_verification_notes,
                        prev_organization, prev_instructor, rank_awarded_year,
                        rank_evidence_path, review_notes
                 FROM registration_requests WHERE user_id = :uid
                 ORDER BY submitted_at DESC LIMIT 1"
            );
            $stmt->execute([':uid' => $userId]);
            $myApplication = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            error_log('Dashboard application lookup failed: ' . $e->getMessage());
        }
    }

    if (in_array($userRole, ['Coach/Trainer', 'Head Coach'], true) && $coachMemberId !== null) {
        try {
            $stmt = $db->prepare(
                "SELECT COUNT(DISTINCT a.member_id) FROM attendance a
                 JOIN trainings t ON t.training_id = a.training_id
                 WHERE t.coach_id = :cid AND a.is_voided = 0 AND t.is_voided = 0"
            );
            $stmt->execute([':cid' => $coachMemberId]);
            $myTraineeCount = (int)$stmt->fetchColumn();

            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM events WHERE created_by = :uid AND is_voided = 0"
            );
            $stmt->execute([':uid' => $userId]);
            $myCreatedEvents = (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('Dashboard coach stats failed: ' . $e->getMessage());
        }
    }

    // Real participant counts, replacing the previous mt_rand(15, 35) stub.
    $participantCounts = [];
    $eventIds = array_map(static fn(array $e): int => (int)($e['event_id'] ?? 0), $upcomingEvents);
    $eventIds = array_values(array_filter($eventIds, static fn(int $id): bool => $id > 0));
    if (!empty($eventIds)) {
        $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
        $countStmt = $db->prepare(
            "SELECT event_id, COUNT(*) AS total
             FROM event_participants
             WHERE is_voided = 0 AND event_id IN ($placeholders)
             GROUP BY event_id"
        );
        $countStmt->execute($eventIds);
        foreach ($countStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $participantCounts[(int)$row['event_id']] = (int)$row['total'];
        }
    }

    // --- Admin: unassigned (needs replacement) work ---------------------
    /*
     * Sessions / events whose Lead Coach / Handler account was archived are
     * flagged (needs_coach / needs_handler) so Admin can reassign them. The
     * columns only exist after migration 002, so feature-detect to keep the
     * dashboard rendering on an un-migrated database.
     */
    $unassignedTrainings = [];
    $unassignedEvents = [];
    $assignableCoaches = [];
    if ($userRole === 'SuperAdmin') {
        $hasNeedsCoach = false;
        $hasNeedsHandler = false;
        try {
            $hasNeedsCoach = (bool)$db->query("SHOW COLUMNS FROM trainings LIKE 'needs_coach'")->fetch();
        } catch (Throwable $e) {
            error_log('Dashboard needs_coach detect failed: ' . $e->getMessage());
        }
        try {
            $hasNeedsHandler = (bool)$db->query("SHOW COLUMNS FROM events LIKE 'needs_handler'")->fetch();
        } catch (Throwable $e) {
            error_log('Dashboard needs_handler detect failed: ' . $e->getMessage());
        }

        if ($hasNeedsCoach) {
            try {
                $stmt = $db->query(
                    "SELECT t.training_id, t.training_date, t.start_time, t.location,
                            pc.full_name AS previous_coach
                     FROM trainings t
                     LEFT JOIN members pc ON pc.member_id = t.previous_coach_id
                     WHERE t.is_voided = 0 AND t.needs_coach = 1 AND t.training_date >= CURDATE()
                     ORDER BY t.training_date ASC, t.start_time ASC
                     LIMIT 20"
                );
                $unassignedTrainings = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e) {
                error_log('Dashboard unassigned trainings failed: ' . $e->getMessage());
            }
        }

        if ($hasNeedsHandler) {
            try {
                $stmt = $db->query(
                    "SELECT e.event_id, e.event_name, e.event_date,
                            ph.full_name AS previous_handler
                     FROM events e
                     LEFT JOIN members ph ON ph.member_id = e.previous_handler_id
                     WHERE e.is_voided = 0 AND e.needs_handler = 1 AND e.event_date >= CURDATE()
                     ORDER BY e.event_date ASC
                     LIMIT 20"
                );
                $unassignedEvents = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e) {
                error_log('Dashboard unassigned events failed: ' . $e->getMessage());
            }
        }

        try {
            $assignableCoaches = (new Trainings($db))->getCoaches();
        } catch (Throwable $e) {
            error_log('Dashboard assignable coaches failed: ' . $e->getMessage());
        }
    }

    // --- Pagination (totals first, so the page numbers can be clamped) ----
    $totalTrainingPages = max(0, (int)ceil(count($upcomingTrainings) / $trainingPerPage));
    $trainingPage = min($trainingPage, max(1, $totalTrainingPages));
    $paginatedTrainings = array_slice($upcomingTrainings, ($trainingPage - 1) * $trainingPerPage, $trainingPerPage);

    $totalEventPages = max(0, (int)ceil(count($upcomingEvents) / $eventPerPage));
    $eventPage = min($eventPage, max(1, $totalEventPages));
    $paginatedEvents = array_slice($upcomingEvents, ($eventPage - 1) * $eventPerPage, $eventPerPage);

    // --- Recent activity (real audit trail) ------------------------------
    /*
     * The old feed was a UNION of created_at / updated_at / voided_at across
     * five tables. It fetched up to 100 rows per table, merged them in PHP and
     * showed the same record up to three times. This reads the append-only
     * audit_logs table instead: one intentional row per action, one indexed
     * query, SQL pagination. Non-SuperAdmins see only their own actions.
     */
    $activities = [];
    $totalActivityPages = 0;
    $paginatedActivities = [];
    $auditReady = true;
    try {
        AuditLog::ensureSchema($db);
        $auditWhere = '';
        $auditParams = [];
        // Admin/Head Coach/Officer/Coach see all activity; only Member/Applicant see own
        $staffRoles = ['SuperAdmin', 'Head Coach', 'Officer', 'Coach/Trainer'];
        if (!in_array(currentUserRole(), $staffRoles, true)) {
            $auditWhere = ' WHERE user_id = :uid';
            $auditParams[':uid'] = $userId;
        }

        $auditCountStmt = $db->prepare('SELECT COUNT(*) FROM audit_logs' . $auditWhere);
        $auditCountStmt->execute($auditParams);
        $totalActivities = (int)$auditCountStmt->fetchColumn();

        $totalActivityPages = max(1, (int)ceil($totalActivities / $activityPerPage));
        $activityPage = min($activityPage, $totalActivityPages);
        $activityOffset = ($activityPage - 1) * $activityPerPage;

        if ($totalActivities > 0) {
            $auditStmt = $db->prepare(
                'SELECT actor_name, actor_role, action, module, description, created_at
                 FROM audit_logs' . $auditWhere . '
                 ORDER BY created_at DESC, audit_id DESC
                 LIMIT ' . (int)$activityPerPage . ' OFFSET ' . (int)$activityOffset
            );
            $auditStmt->execute($auditParams);
            foreach ($auditStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $activities[] = [
                    'ts' => (string)($row['created_at'] ?? ''),
                    'actor' => (string)($row['actor_name'] ?? 'System'),
                    'label' => (string)($row['description'] ?? ''),
                    'kind' => (string)($row['action'] ?? ''),
                    'module' => (string)($row['module'] ?? ''),
                ];
            }
        }
        $paginatedActivities = $activities;
    } catch (Throwable $e) {
        error_log('Dashboard audit feed unavailable: ' . $e->getMessage());
        $auditReady = false;
        $totalActivityPages = 0;
    }

    // Pagination links must preserve the other two independent page cursors.
    $pageLink = static function (string $param, int $value) use ($trainingPage, $eventPage, $activityPage): string {
        $query = [
            'training_page' => $trainingPage,
            'event_page' => $eventPage,
            'activity_page' => $activityPage,
        ];
        $query[$param] = $value;
        return '?' . http_build_query($query);
    };
} catch (Throwable $e) {
    error_log('Dashboard Error: ' . $e->getMessage());
    die('Error loading dashboard data. Please contact administrator.');
}
?>

<!DOCTYPE html>
<html lang="en" class="text-[13px]">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Arnis Portal</title>
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" type="image/x-icon" href="../arnis-favicon.ico">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,100..900;1,100..900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/sidebar.css">
    <link rel="stylesheet" href="css/style.css">
</head>

<body class="overflow-x-hidden bg-dark-purple text-white min-h-screen">

<div class="flex">
    <?php include_once __DIR__ . '/../includes/sidebar.php'; ?>

    <main id="page" class="flex-1 p-4 sm:p-5 lg:p-6 h-screen overflow-y-auto custom-scrollbar opacity-0 translate-x-8 transition-all duration-500 ease-out">
        <?php include_once __DIR__ . '/../includes/header.php'; ?>

        <!-- Role hero: every role gets its own dashboard identity + shortcuts -->
        <div class="card-bg p-4 sm:p-5 rounded-xl sm:rounded-2xl mb-4 lg:mb-6 relative overflow-hidden">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <?php if ($userRole === 'SuperAdmin'): ?>
                        <p class="text-[9px] sm:text-[10px] text-red-400 uppercase font-black tracking-widest">Admin / Lead Coach</p>
                        <h2 class="text-xl sm:text-2xl font-black italic uppercase tracking-tight mt-1">Command Overview</h2>
                        <p class="text-xs text-gray-400 mt-1">Full organization health — members, sessions, events and funds.</p>
                    <?php elseif ($userRole === 'Officer'): ?>
                        <p class="text-[9px] sm:text-[10px] text-green-400 uppercase font-black tracking-widest">Finance Officer</p>
                        <h2 class="text-xl sm:text-2xl font-black italic uppercase tracking-tight mt-1">Finance Overview</h2>
                        <p class="text-xs text-gray-400 mt-1">Money in, money out — <?= htmlspecialchars(formatCurrency($fundsStats['income']), ENT_QUOTES, 'UTF-8') ?> in / <?= htmlspecialchars(formatCurrency($fundsStats['expenses']), ENT_QUOTES, 'UTF-8') ?> out (<?= htmlspecialchars((string)($fundsStats['month'] ?? ''), ENT_QUOTES, 'UTF-8') ?>).</p>
                    <?php elseif ($userRole === 'Coach/Trainer'): ?>
                        <p class="text-[9px] sm:text-[10px] text-blue-400 uppercase font-black tracking-widest">Coach</p>
                        <h2 class="text-xl sm:text-2xl font-black italic uppercase tracking-tight mt-1">Coach Workspace</h2>
                        <p class="text-xs text-gray-400 mt-1"><?= (int)$myTraineeCount ?> trainee<?= (int)$myTraineeCount === 1 ? '' : 's' ?> &bull; <?= count($trainings) ?> session<?= count($trainings) === 1 ? '' : 's' ?> &bull; <?= (int)$myCreatedEvents ?> event<?= (int)$myCreatedEvents === 1 ? '' : 's' ?> created.</p>
                    <?php elseif ($userRole === 'Head Coach'): ?>
                        <p class="text-[9px] sm:text-[10px] text-indigo-400 uppercase font-black tracking-widest">Head Coach</p>
                        <h2 class="text-xl sm:text-2xl font-black italic uppercase tracking-tight mt-1">Coach Workspace</h2>
                        <p class="text-xs text-gray-400 mt-1"><?= (int)$myTraineeCount ?> trainee<?= (int)$myTraineeCount === 1 ? '' : 's' ?> &bull; <?= count($trainings) ?> session<?= count($trainings) === 1 ? '' : 's' ?> &bull; <?= (int)$myCreatedEvents ?> event<?= (int)$myCreatedEvents === 1 ? '' : 's' ?> created.</p>
                    <?php elseif ($userRole === 'Member'): ?>
                        <p class="text-[9px] sm:text-[10px] text-teal-400 uppercase font-black tracking-widest">Member / Player</p>
                        <h2 class="text-xl sm:text-2xl font-black italic uppercase tracking-tight mt-1">My Arnis Journey</h2>
                        <p class="text-xs text-gray-400 mt-1">
                            <?= $myProfileRow !== null ? htmlspecialchars((string)($myProfileRow['full_name'] ?? ''), ENT_QUOTES, 'UTF-8') . ' &bull; ' . htmlspecialchars((string)($myProfileRow['rank'] ?? 'None'), ENT_QUOTES, 'UTF-8') : 'Your attendance, belt and achievements.' ?>
                        </p>
                    <?php else: ?>
                        <p class="text-[9px] sm:text-[10px] text-amber-400 uppercase font-black tracking-widest">Applicant</p>
                        <h2 class="text-xl sm:text-2xl font-black italic uppercase tracking-tight mt-1">Application Tracker</h2>
                        <p class="text-xs text-gray-400 mt-1">Follow your application from pending to member.</p>
                    <?php endif; ?>
                </div>
                <div class="flex flex-wrap gap-2">
        <?php if ($userRole === 'SuperAdmin'): ?>
            <?php if (canAccess('members', 'write')): ?><a href="members_management.php#pendingRequestsSection" class="bg-yellow-500/10 text-yellow-300 hover:bg-yellow-500 hover:text-white px-4 py-2 rounded-xl text-[10px] font-bold uppercase transition">Approvals<?= $pendingApprovalCount > 0 ? ' (' . (int)$pendingApprovalCount . ')' : '' ?></a><?php endif; ?>
            <?php if (canAccess('users')): ?><a href="users.php" class="bg-white/5 hover:bg-white/10 px-4 py-2 rounded-xl text-[10px] font-bold uppercase transition">Users</a><?php endif; ?>
            <?php if (canAccess('reports')): ?><a href="reports.php" class="bg-white/5 hover:bg-white/10 px-4 py-2 rounded-xl text-[10px] font-bold uppercase transition">Reports</a><?php endif; ?>
        <?php elseif ($userRole === 'Officer'): ?>
            <?php if (canAccess('funds')): ?><a href="funds.php" class="bg-green-500/10 text-green-300 hover:bg-green-500 hover:text-white px-4 py-2 rounded-xl text-[10px] font-bold uppercase transition">Record Transaction</a><?php endif; ?>
            <?php if (canAccess('reports')): ?><a href="reports.php" class="bg-white/5 hover:bg-white/10 px-4 py-2 rounded-xl text-[10px] font-bold uppercase transition">Reports</a><?php endif; ?>
            <?php if (canAccess('members', 'write')): ?><a href="members_management.php#pendingRequestsSection" class="bg-white/5 hover:bg-white/10 px-4 py-2 rounded-xl text-[10px] font-bold uppercase transition">Approvals<?= $pendingApprovalCount > 0 ? ' (' . (int)$pendingApprovalCount . ')' : '' ?></a><?php endif; ?>
        <?php elseif ($userRole === 'Coach/Trainer' || $userRole === 'Head Coach'): ?>
            <?php if (canAccess('trainings', 'write')): ?><a href="trainings.php" class="bg-blue-500/10 text-blue-300 hover:bg-blue-500 hover:text-white px-4 py-2 rounded-xl text-[10px] font-bold uppercase transition">Schedule Training</a><?php endif; ?>
            <?php if (canAccess('events', 'write')): ?><a href="events.php" class="bg-white/5 hover:bg-white/10 px-4 py-2 rounded-xl text-[10px] font-bold uppercase transition">Manage Events</a><?php endif; ?>
        <?php elseif ($userRole === 'Member'): ?>
            <?php if ($myMemberId !== null): ?><a href="member_profile.php?id=<?= (int)$myMemberId ?>" class="bg-teal-500/10 text-teal-300 hover:bg-teal-500 hover:text-white px-4 py-2 rounded-xl text-[10px] font-bold uppercase transition">My Full Profile</a><?php endif; ?>
            <?php if (canAccess('trainings')): ?><a href="trainings.php" class="bg-white/5 hover:bg-white/10 px-4 py-2 rounded-xl text-[10px] font-bold uppercase transition">Sessions</a><?php endif; ?>
            <?php if (canAccess('events')): ?><a href="events.php" class="bg-white/5 hover:bg-white/10 px-4 py-2 rounded-xl text-[10px] font-bold uppercase transition">Events</a><?php endif; ?>
        <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-2 sm:gap-3 lg:gap-4 mb-4 lg:mb-6">
            <?php if (canAccess('members') && in_array($membersReadPerm, ['own', 'own_trainees'], true) && $myProfileRow !== null): ?>
            <div class="card-bg p-4 sm:p-5 rounded-xl sm:rounded-2xl relative overflow-hidden group hover:border-teal-500/50 transition">
                <div class="flex justify-between items-start">
                    <div class="min-w-0">
                        <p class="text-[9px] sm:text-[10px] text-gray-500 uppercase font-black tracking-widest">My Profile</p>
                        <h3 class="text-lg sm:text-xl font-bold mt-1 truncate"><?= htmlspecialchars((string)($myProfileRow['full_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></h3>
                    </div>
                    <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-lg sm:rounded-xl bg-teal-500/10 flex items-center justify-center text-teal-400 flex-shrink-0">
                        <i class="fas fa-id-card text-xs sm:text-sm"></i>
                    </div>
                </div>
                <div class="mt-3 sm:mt-4 flex flex-wrap gap-2 text-[8px] sm:text-[9px] font-bold uppercase">
                    <span class="text-yellow-300"><?= htmlspecialchars((string)($myProfileRow['rank'] ?? 'None'), ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="<?= ((string)($myProfileRow['status'] ?? '') === 'Active') ? 'text-green-400' : 'text-orange-400' ?>"><?= htmlspecialchars((string)($myProfileRow['status'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="text-gray-400"><?= htmlspecialchars((string)($myProfileRow['player_category'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <?php if ($myMemberId !== null): ?>
                <a href="member_profile.php?id=<?= (int)$myMemberId ?>" class="inline-block mt-3 text-[9px] sm:text-[10px] text-teal-400 hover:text-white uppercase font-bold">Open full profile →</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Members Card -->
            <?php if (canAccess('members') && !in_array($membersReadPerm, ['own', 'own_trainees'], true)): ?>
            <div class="card-bg p-4 sm:p-5 rounded-xl sm:rounded-2xl relative overflow-hidden group hover:border-purple-500/50 transition">
                <div class="flex justify-between items-start">
                    <div>
                        <p class="text-[9px] sm:text-[10px] text-gray-500 uppercase font-black tracking-widest">Active Members</p>
                        <?php if ($membersReadPerm === 'limited'): ?>
                            <h3 class="text-xl sm:text-2xl font-bold mt-1 text-gray-500">Limited View</h3>
                        <?php else: ?>
                            <h3 class="text-2xl sm:text-3xl font-bold mt-1"><?= (int)$activeMembers ?></h3>
                        <?php endif; ?>
                    </div>
                    <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-lg sm:rounded-xl bg-purple-500/10 flex items-center justify-center text-purple-400">
                        <i class="fas fa-users text-xs sm:text-sm"></i>
                    </div>
                </div>
                <?php if ($membersReadPerm !== 'limited'): ?>
                <div class="mt-3 sm:mt-4 text-[9px] sm:text-[10px] font-bold space-y-1">
                    <div class="text-green-400"><i class="fas fa-caret-up mr-1"></i> Total: <?= count($allMembers) ?></div>
                    <div class="flex flex-wrap gap-2 text-[8px] uppercase">
                        <span class="text-cyan-300">Public: <?= (int)($memberStats['public_count'] ?? 0) ?></span>
                        <span class="text-pink-300">Private: <?= (int)($memberStats['private_count'] ?? 0) ?></span>
                    </div>
                </div>
                <?php else: ?>
                <p class="mt-3 sm:mt-4 text-[9px] text-gray-500 italic">Basic info only</p>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Trainings Card -->
            <?php if (canAccess('trainings')): ?>
            <div class="card-bg p-4 sm:p-5 rounded-xl sm:rounded-2xl group hover:border-blue-500/50 transition">
                <div class="flex justify-between items-start">
                    <div>
                        <p class="text-[9px] sm:text-[10px] text-gray-500 uppercase font-black tracking-widest">
                            <?= ($trainingsReadPerm === 'own') ? 'My Trainings' : 'Next 7 Days' ?>
                        </p>
                        <h3 class="text-2xl sm:text-3xl font-bold mt-1"><?= str_pad((string)count($upcomingTrainings), 2, '0', STR_PAD_LEFT) ?></h3>
                    </div>
                    <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-lg sm:rounded-xl bg-blue-500/10 flex items-center justify-center text-blue-400">
                        <i class="fas fa-calendar-alt text-xs sm:text-sm"></i>
                    </div>
                </div>
                <div class="text-[9px] sm:text-[10px] text-gray-500 mt-3 sm:mt-4 uppercase">Trainings Scheduled</div>
                <div class="mt-2 flex flex-wrap gap-2 text-[8px] uppercase font-bold">
                    <span class="text-cyan-300">Public: <?= (int)($trainingStats['public_sessions'] ?? 0) ?></span>
                    <span class="text-pink-300">Private: <?= (int)($trainingStats['private_sessions'] ?? 0) ?></span>
                </div>
            </div>
            <?php endif; ?>

            <!-- Funds Card -->
            <?php if (canAccess('funds')): ?>
            <div class="card-bg p-4 sm:p-5 rounded-xl sm:rounded-2xl group hover:border-green-500/50 transition">
                <div class="flex justify-between items-start">
                    <div>
                        <p class="text-[9px] sm:text-[10px] text-gray-500 uppercase font-black tracking-widest">Net Balance (All-time)</p>
                        <h3 class="text-2xl sm:text-3xl font-bold mt-1 italic"><?= htmlspecialchars(formatCurrency($fundsStats['balance']), ENT_QUOTES, 'UTF-8') ?></h3>
                    </div>
                    <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-lg sm:rounded-xl bg-green-500/10 flex items-center justify-center text-green-400">
                        <i class="fas fa-wallet text-xs sm:text-sm"></i>
                    </div>
                </div>
                <div class="mt-3 sm:mt-4 flex gap-2 sm:gap-3 text-[8px] sm:text-[9px] uppercase font-bold">
                    <span class="text-green-400">In (<?= htmlspecialchars((string)($fundsStats['month'] ?? date('Y-m')), ENT_QUOTES, 'UTF-8') ?>): <?= htmlspecialchars(formatCurrency($fundsStats['income']), ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="text-red-400">Out (<?= htmlspecialchars((string)($fundsStats['month'] ?? date('Y-m')), ENT_QUOTES, 'UTF-8') ?>): <?= htmlspecialchars(formatCurrency($fundsStats['expenses']), ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <?php if (getPermission('funds', 'write') === false): ?>
                <p class="mt-2 text-[8px] text-yellow-500 italic">View Only</p>
                <?php endif; ?>
            </div>

            <!-- Voided Records Card -->
            <div class="card-bg p-4 sm:p-5 rounded-xl sm:rounded-2xl group hover:border-orange-500/50 transition">
                <div class="flex justify-between items-start">
                    <div>
                        <p class="text-[9px] sm:text-[10px] text-gray-500 uppercase font-black tracking-widest">Audit (Voided)</p>
                        <h3 class="text-2xl sm:text-3xl font-bold mt-1"><?= (int)$voidedCount ?></h3>
                    </div>
                    <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-lg sm:rounded-xl bg-orange-500/10 flex items-center justify-center text-orange-400">
                        <i class="fas fa-exclamation-triangle text-xs sm:text-sm"></i>
                    </div>
                </div>
                <p class="text-[9px] sm:text-[10px] text-orange-400 mt-3 sm:mt-4 uppercase font-bold italic">Records Found</p>
            </div>
            <?php endif; ?>
        </div>

        <!-- Admin: Unassigned Work (needs replacement coach / handler) -->
        <?php if ($userRole === 'SuperAdmin' && (!empty($unassignedTrainings) || !empty($unassignedEvents))): ?>
        <div class="card-bg rounded-2xl sm:rounded-3xl p-4 sm:p-6 shadow-xl mb-4 lg:mb-6 border border-amber-500/30" id="unassignedWorkPanel">
            <div class="flex items-center gap-3 mb-4">
                <div class="w-10 h-10 rounded-xl bg-amber-500/10 flex items-center justify-center text-amber-400">
                    <i class="fas fa-triangle-exclamation"></i>
                </div>
                <div>
                    <h2 class="font-bold text-sm sm:text-base uppercase tracking-wider text-amber-300">Unassigned Work</h2>
                    <p class="text-[10px] text-gray-500 uppercase tracking-widest">Lead Coach / Event Handler needed after an account was archived.</p>
                </div>
            </div>
            <div id="unassignedWorkMsg" class="hidden mb-4 px-4 py-3 rounded-xl text-xs"></div>

            <?php if (!empty($unassignedTrainings)): ?>
            <div class="mb-5">
                <p class="text-[10px] text-gray-400 uppercase font-black tracking-widest mb-2"><i class="fas fa-dumbbell mr-1"></i> Unassigned Training Sessions</p>
                <div class="space-y-2">
                    <?php foreach ($unassignedTrainings as $ut): ?>
                    <div class="bg-white/[0.03] rounded-xl p-3 flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-3">
                        <div class="flex-1 min-w-0 text-xs">
                            <div class="text-gray-200 font-bold"><?= htmlspecialchars(formatDate($ut['training_date'] ?? null), ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars(formatTime($ut['start_time'] ?? null), ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="text-gray-500 truncate"><?= htmlspecialchars((string)($ut['location'] ?? 'No location'), ENT_QUOTES, 'UTF-8') ?>
                                <?php if (!empty($ut['previous_coach'])): ?> · previously <?= htmlspecialchars((string)$ut['previous_coach'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
                            </div>
                        </div>
                        <select id="assign_trainings_<?= (int)$ut['training_id'] ?>" class="bg-[#1d212a] rounded-lg px-3 py-2 text-xs text-white outline-none min-w-[160px]">
                            <option value="">Select coach…</option>
                            <?php foreach ($assignableCoaches as $c): ?>
                                <option value="<?= (int)$c['coach_id'] ?>"><?= htmlspecialchars((string)$c['full_name'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars((string)($c['member_type'] ?? ''), ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" onclick="assignReplacement('trainings', <?= (int)$ut['training_id'] ?>)" class="px-4 py-2 rounded-lg bg-amber-500/10 text-amber-300 hover:bg-amber-500 hover:text-white text-[11px] font-black uppercase tracking-wider transition">Assign</button>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($unassignedEvents)): ?>
            <div>
                <p class="text-[10px] text-gray-400 uppercase font-black tracking-widest mb-2"><i class="fas fa-calendar-plus mr-1"></i> Unassigned Upcoming Events</p>
                <div class="space-y-2">
                    <?php foreach ($unassignedEvents as $ue): ?>
                    <div class="bg-white/[0.03] rounded-xl p-3 flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-3">
                        <div class="flex-1 min-w-0 text-xs">
                            <div class="text-gray-200 font-bold"><?= htmlspecialchars((string)($ue['event_name'] ?? 'Event'), ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="text-gray-500 truncate"><?= htmlspecialchars(formatDate($ue['event_date'] ?? null), ENT_QUOTES, 'UTF-8') ?>
                                <?php if (!empty($ue['previous_handler'])): ?> · previously <?= htmlspecialchars((string)$ue['previous_handler'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
                            </div>
                        </div>
                        <select id="assign_events_<?= (int)$ue['event_id'] ?>" class="bg-[#1d212a] rounded-lg px-3 py-2 text-xs text-white outline-none min-w-[160px]">
                            <option value="">Select handler…</option>
                            <?php foreach ($assignableCoaches as $c): ?>
                                <option value="<?= (int)$c['coach_id'] ?>"><?= htmlspecialchars((string)$c['full_name'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars((string)($c['member_type'] ?? ''), ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" onclick="assignReplacement('events', <?= (int)$ue['event_id'] ?>)" class="px-4 py-2 rounded-lg bg-amber-500/10 text-amber-300 hover:bg-amber-500 hover:text-white text-[11px] font-black uppercase tracking-wider transition">Assign</button>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <script>
        async function assignReplacement(type, id) {
            var msg = document.getElementById('unassignedWorkMsg');
            var sel = document.getElementById('assign_' + type + '_' + id);
            if (!sel || !sel.value) {
                if (msg) { msg.textContent = 'Choose a coach/handler first.'; msg.className = 'mb-4 px-4 py-3 rounded-xl text-xs bg-red-500/10 border border-red-500/30 text-red-400'; msg.classList.remove('hidden'); }
                return;
            }
            var csrf = '<?= htmlspecialchars(arnisCsrfToken(), ENT_QUOTES, 'UTF-8') ?>';
            var body = new URLSearchParams({ type: type, id: String(id), handler_member_id: sel.value, csrf_token: csrf });
            try {
                var res = await fetch('../actions/assign_handler.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: body.toString()
                });
                var data = await res.json();
                if (data && data.success) {
                    if (msg) { msg.textContent = data.message || 'Assigned.'; msg.className = 'mb-4 px-4 py-3 rounded-xl text-xs bg-emerald-500/10 border border-emerald-500/30 text-emerald-400'; msg.classList.remove('hidden'); }
                    setTimeout(function () { location.reload(); }, 900);
                } else {
                    throw new Error((data && (data.message || data.error)) || 'Assignment failed.');
                }
            } catch (err) {
                if (msg) { msg.textContent = (err && err.message) ? err.message : 'Assignment failed.'; msg.className = 'mb-4 px-4 py-3 rounded-xl text-xs bg-red-500/10 border border-red-500/30 text-red-400'; msg.classList.remove('hidden'); }
            }
        }
        </script>
        <?php endif; ?>

        <!-- Member workspace: My Journey -->
        <?php if ($userRole === 'Member' && $myProfileRow !== null): ?>
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-2 sm:gap-3 lg:gap-4 mb-4 lg:mb-6">
            <div class="card-bg p-4 sm:p-5 rounded-xl sm:rounded-2xl text-center">
                <i class="fas fa-check-circle text-xl sm:text-2xl mb-2 text-green-400 opacity-80"></i>
                <div class="text-2xl sm:text-3xl font-bold"><?= (int)$myAttendance['present'] ?></div>
                <div class="text-[10px] sm:text-xs text-gray-400 uppercase tracking-widest mt-1">Times Present</div>
            </div>
            <div class="card-bg p-4 sm:p-5 rounded-xl sm:rounded-2xl text-center border border-yellow-500/20">
                <i class="fas fa-medal text-xl sm:text-2xl mb-2 text-yellow-400 opacity-80"></i>
                <div class="text-sm sm:text-base font-bold mt-1 leading-tight"><?= htmlspecialchars((string)($myProfileRow['rank'] ?? 'None'), ENT_QUOTES, 'UTF-8') ?></div>
                <div class="text-[10px] sm:text-xs text-gray-400 uppercase tracking-widest mt-1">My Belt &bull; <?= (int)$myPromoCount ?> promotion<?= (int)$myPromoCount === 1 ? '' : 's' ?></div>
                <?php if ($myMemberId !== null): ?>
                <a href="member_profile.php?id=<?= (int)$myMemberId ?>" class="inline-block mt-2 text-[9px] sm:text-[10px] text-yellow-400 hover:text-white uppercase font-bold">View progression →</a>
                <?php endif; ?>
            </div>
            <div class="card-bg p-4 sm:p-5 rounded-xl sm:rounded-2xl text-center">
                <i class="fas fa-medal text-xl sm:text-2xl mb-2 text-yellow-400 opacity-80"></i>
                <div class="text-2xl sm:text-3xl font-bold"><?= count($myAchievements) > 0 ? count($myAchievements) . '+' : (int)$myPromoCount ?></div>
                <div class="text-[10px] sm:text-xs text-gray-400 uppercase tracking-widest mt-1">Results &amp; Promotions</div>
            </div>
            <div class="card-bg p-4 sm:p-5 rounded-xl sm:rounded-2xl text-center col-span-2">
                <i class="fas fa-trophy text-xl sm:text-2xl mb-2 text-purple-400 opacity-80"></i>
                <div class="text-sm sm:text-base font-bold mt-1 space-y-1">
                    <?php if (!empty($myAchievements)): ?>
                        <?php foreach (array_slice($myAchievements, 0, 3) as $ma): ?>
                        <div class="text-gray-300"><?= htmlspecialchars((string)($ma['achievement_type'] ?? ''), ENT_QUOTES, 'UTF-8') ?> &bull; <span class="text-gray-500"><?= htmlspecialchars((string)($ma['event_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span></div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-gray-500 text-xs">No achievements yet — join an event to earn your first.</div>
                    <?php endif; ?>
                </div>
                <div class="text-[10px] sm:text-xs text-gray-400 uppercase tracking-widest mt-2">Latest Results</div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Member workspace: belt + upcoming sessions -->
        <?php if ($userRole === 'Member' && $myProfileRow !== null): ?>
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-2 sm:gap-3 lg:gap-4 mb-4 lg:mb-6">
            <div class="card-bg p-4 sm:p-5 rounded-xl sm:rounded-2xl text-center border border-yellow-500/20">
                <i class="fas fa-award text-xl sm:text-2xl mb-2 text-yellow-400 opacity-80"></i>
                <div class="text-base sm:text-lg font-black mt-1"><?= $myNextRank !== null ? htmlspecialchars($myNextRank, ENT_QUOTES, 'UTF-8') : 'Highest Rank' ?></div>
                <div class="text-[10px] sm:text-xs text-gray-400 uppercase tracking-widest mt-1">Next Belt Goal</div>
                <?php if ($myNextRank !== null && $myMemberId !== null):
                    $beltPct = min(100, (int)round($mySessionsAtBelt / 12 * 100)); ?>
                <div class="h-2 rounded-full bg-white/5 overflow-hidden mt-3">
                    <div class="h-full rounded-full bg-gradient-to-r from-purple-500 to-yellow-400" style="width: <?= $beltPct ?>%"></div>
                </div>
                <div class="text-[10px] text-gray-500 mt-2"><?= (int)$mySessionsAtBelt ?> / 12 guideline sessions<?= $myBeltSince !== null ? ' since ' . htmlspecialchars($myBeltSince, ENT_QUOTES, 'UTF-8') : '' ?></div>
                <?php endif; ?>
                <div class="text-[10px] text-gray-500 mt-2"><?= (int)$myPromoCount ?> promotion<?= (int)$myPromoCount === 1 ? '' : 's' ?> so far</div>
                <?php if (!empty($myRecentPromos)): ?>
                <div class="mt-2 space-y-1 text-left">
                    <?php foreach ($myRecentPromos as $rp):
                        $rpDate = '';
                        $rpTs = strtotime((string)($rp['promoted_at'] ?? ''));
                        if ($rpTs !== false) { $rpDate = date('M Y', $rpTs); }
                    ?>
                    <div class="text-[10px] text-gray-400 truncate"><i class="fas fa-medal text-yellow-500/70 mr-1"></i><?= htmlspecialchars((string)($rp['old_rank'] ?? '—'), ENT_QUOTES, 'UTF-8') ?> → <span class="text-gray-200 font-bold"><?= htmlspecialchars((string)($rp['new_rank'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span> <span class="text-gray-600"><?= htmlspecialchars($rpDate, ENT_QUOTES, 'UTF-8') ?></span></div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <?php if ($myMemberId !== null): ?>
                <a href="member_profile.php?id=<?= (int)$myMemberId ?>#belt-progression" class="inline-block mt-2 text-[9px] sm:text-[10px] text-yellow-400 hover:text-white uppercase font-bold">Open full progression →</a>
                <?php endif; ?>
            </div>
            <div class="card-bg p-4 sm:p-5 rounded-xl sm:rounded-2xl">
                <h4 class="text-[10px] sm:text-xs font-black uppercase tracking-widest text-gray-400 mb-3"><i class="fas fa-calendar-check mr-2 text-blue-400"></i>My Upcoming Sessions</h4>
                <?php if (!empty($myUpcomingSessions)): ?>
                <ul class="space-y-2 text-xs">
                    <?php foreach ($myUpcomingSessions as $ms): ?>
                    <li class="flex justify-between items-center gap-2 bg-white/[0.02] rounded-xl px-3 py-2 border border-gray-800/50">
                        <span class="font-bold"><?= htmlspecialchars(formatDate($ms['training_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="text-gray-500 truncate"><?= htmlspecialchars((string)($ms['location'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php else: ?>
                <p class="text-xs text-gray-500">No upcoming sessions on your roster. Your coach adds you to sessions you belong to.</p>
                <?php endif; ?>
            </div>
            <div class="card-bg p-4 sm:p-5 rounded-xl sm:rounded-2xl text-center">
                <i class="fas fa-face-smile text-xl sm:text-2xl mb-2 text-purple-400 opacity-80"></i>
                <div class="text-base sm:text-lg font-black mt-1">Self Check-In</div>
                <div class="text-[10px] sm:text-xs text-gray-400 uppercase tracking-widest mt-1">Face + GPS</div>
                <p class="text-[10px] text-gray-500 mt-2">Open an upcoming training session to check in with face recognition and location (when enabled for that session).</p>
            </div>
        </div>
        <?php endif; ?>

        <!-- Applicant workspace: Application Tracker -->
        <?php if ($userRole === 'Applicant'): ?>
        <div class="card-bg p-4 sm:p-5 rounded-xl sm:rounded-2xl mb-4 lg:mb-6">
            <div class="flex flex-col gap-4">
                <div class="flex items-center gap-3 sm:gap-4">
                    <?php
                    $appStatus = (string)($myApplication['status'] ?? '');
                    $appDot = $appStatus === 'Approved' ? 'bg-green-500' : ($appStatus === 'Rejected' ? 'bg-red-500' : 'bg-yellow-500');
                    ?>
                    <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-full <?= $appDot ?>/20 flex items-center justify-center flex-shrink-0">
                        <i class="fas <?= $appStatus === 'Approved' ? 'fa-check text-green-400' : ($appStatus === 'Rejected' ? 'fa-times text-red-400' : 'fa-hourglass-half text-yellow-400') ?>"></i>
                    </div>
                    <div>
                        <h4 class="font-bold uppercase text-xs sm:text-sm">
                            <?php if ($appStatus === 'Pending'): ?>Application Under Review
                            <?php elseif ($appStatus === 'Approved'): ?>Application Approved — Welcome!
                            <?php elseif ($appStatus === 'Rejected'): ?>Application Not Approved
                            <?php else: ?>Application Status Unavailable<?php endif; ?>
                        </h4>
                    <p class="text-[10px] sm:text-xs text-gray-500 mt-1">
                        <?php if ($myApplication !== null): ?>
                            Submitted <?= htmlspecialchars(formatDate($myApplication['submitted_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?> as <?= htmlspecialchars((string)($myApplication['player_category'] ?? ''), ENT_QUOTES, 'UTF-8') ?> player.
                            <?php if ($appStatus === 'Pending'): ?>Your application is waiting for review.<?php endif; ?>
                            <?php if ($appStatus === 'Approved'): ?>Application approved! Sign out and sign back in to access your Member dashboard.<?php endif; ?>
                            <?php if ($appStatus === 'Rejected'): ?>Application not approved.<?php if (!empty($myApplication['review_notes'])): ?><br><span class="text-gray-400">Note: <?= htmlspecialchars((string)$myApplication['review_notes'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?><?php endif; ?>
                        <?php else: ?>
                            No application linked to this login yet.
                        <?php endif; ?>
                    </p>
                    </div>
                </div>

                <?php if ($myApplication !== null && $appStatus === 'Pending'): ?>
                <div class="bg-white/5 rounded-xl p-4 border border-white/10">
                    <h5 class="text-[11px] uppercase font-bold tracking-widest text-purple-300 mb-2">Rank Verification</h5>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs text-gray-400 mb-3">
                        <?php if (!empty($myApplication['requested_rank'])): ?><div>Requested: <span class="text-yellow-300"><?= htmlspecialchars($myApplication['requested_rank']) ?></span></div><?php endif; ?>
                        <?php if (!empty($myApplication['rank_verification_status'])): ?><div>Status: <span class="text-blue-300"><?= htmlspecialchars($myApplication['rank_verification_status']) ?></span></div><?php endif; ?>
                        <?php if (!empty($myApplication['prev_organization'])): ?><div>Previous Org: <?= htmlspecialchars($myApplication['prev_organization']) ?></div><?php endif; ?>
                        <?php if (!empty($myApplication['prev_instructor'])): ?><div>Previous Instructor: <?= htmlspecialchars($myApplication['prev_instructor']) ?></div><?php endif; ?>
                        <?php if (!empty($myApplication['rank_awarded_year'])): ?><div>Year Awarded: <?= htmlspecialchars($myApplication['rank_awarded_year']) ?></div><?php endif; ?>
                        <?php if (!empty($myApplication['rank_evidence_path'])): ?><div class="sm:col-span-2"><a href="../actions/view_rank_evidence.php?id=<?= (int)($myApplication['request_id'] ?? 0) ?>" target="_blank" rel="noopener" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-purple-500 hover:bg-purple-400 text-white text-[11px] font-black uppercase tracking-[0.16em] transition"><i class="fas fa-up-right-from-square"></i> View Uploaded Evidence</a></div><?php endif; ?>
                    </div>
                    <?php if ((string)$myApplication['requested_rank'] !== 'None'): ?>
                    <form action="../actions/upload_rank_evidence.php" method="POST" enctype="multipart/form-data" class="flex flex-col sm:flex-row gap-2">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(arnisCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="file" name="rank_evidence" accept=".pdf,.jpg,.jpeg,.png" required class="flex-1 text-xs text-gray-400 file:mr-3 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-xs file:font-semibold file:bg-purple-500 file:text-white hover:file:bg-purple-600">
                        <button type="submit" class="px-4 py-2 rounded-xl bg-purple-500/10 hover:bg-purple-500 hover:text-white text-purple-300 text-xs font-bold uppercase transition">Upload/Replace Evidence</button>
                    </form>
                    <p class="text-[9px] text-gray-500 mt-2">Upload additional evidence if requested. PDF/JPG/PNG, max 5MB.</p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Main Content Area -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 lg:gap-8">
            <!-- Training Schedule & Event -->
            <div class="lg:col-span-2 space-y-4 sm:space-y-6">
                <!-- Training Schedule Table -->
                <?php if (canAccess('trainings')): ?>
                <div class="card-bg rounded-2xl sm:rounded-3xl overflow-hidden">
                    <div class="p-4 sm:p-6 border-b border-gray-800 flex justify-between items-center">
                        <h3 class="text-xs sm:text-sm font-bold uppercase tracking-widest italic">
                            <?= ($trainingsReadPerm === 'own') ? 'My Training Schedule' : 'Upcoming Training Schedule' ?>
                        </h3>
                        <?php if ($trainingsWritePerm !== false): ?>
                        <a href="trainings.php" class="text-[9px] sm:text-[10px] text-purple-400 hover:underline uppercase font-bold">View Calendar</a>
                        <?php endif; ?>
                    </div>

                    <!-- Mobile: Card View -->
                    <div class="block lg:hidden p-2 space-y-2">
                        <?php if (!empty($paginatedTrainings)):
                            foreach ($paginatedTrainings as $training):
                                $badge = ($training['training_type'] ?? '') === 'Regular' ? 'purple' : 'blue';
                        ?>
                        <div class="bg-white/[0.02] rounded-xl p-4 border border-gray-800/50">
                            <div class="flex justify-between items-start mb-3">
                                <div>
                                    <p class="font-bold text-sm"><?= htmlspecialchars(formatDate($training['training_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
                                    <p class="text-[10px] text-gray-500"><?= htmlspecialchars(formatTime($training['start_time'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
                                </div>
                                <span class="bg-<?= $badge ?>-500/10 text-<?= $badge ?>-400 px-2 py-1 rounded text-[9px] font-bold uppercase border border-<?= $badge ?>-500/20"><?= htmlspecialchars((string)($training['training_type'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                            <p class="text-xs font-medium mb-1"><?= htmlspecialchars((string)($training['coach_name'] ?? 'TBA'), ENT_QUOTES, 'UTF-8') ?></p>
                            <p class="text-[10px] text-gray-400"><?= htmlspecialchars((string)($training['location'] ?? 'Location TBA'), ENT_QUOTES, 'UTF-8') ?></p>
                        </div>
                        <?php endforeach; else: ?>
                        <div class="bg-white/[0.02] rounded-xl p-4 border border-gray-800/50 text-center text-gray-500">
                            <p class="text-sm">No upcoming trainings</p>
                        </div>
                        <?php endif; ?>

                        <!-- Pagination Controls -->
                        <?php if ($totalTrainingPages > 1): ?>
                        <div class="flex justify-center gap-2 pt-2">
                            <?php if ($trainingPage > 1): ?>
                            <a href="<?= htmlspecialchars($pageLink('training_page', $trainingPage - 1), ENT_QUOTES, 'UTF-8') ?>" class="px-3 py-1 bg-purple-500/10 hover:bg-purple-500/20 rounded text-xs transition">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                            <?php endif; ?>
                            <span class="px-3 py-1 text-xs text-gray-400">Page <?= (int)$trainingPage ?> of <?= (int)$totalTrainingPages ?></span>
                            <?php if ($trainingPage < $totalTrainingPages): ?>
                            <a href="<?= htmlspecialchars($pageLink('training_page', $trainingPage + 1), ENT_QUOTES, 'UTF-8') ?>" class="px-3 py-1 bg-purple-500/10 hover:bg-purple-500/20 rounded text-xs transition">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Desktop: Table View -->
                    <div class="hidden lg:block p-2">
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-sm">
                                <thead>
                                    <tr class="text-[10px] uppercase text-gray-500 border-b border-gray-800">
                                        <th class="p-4 font-black">Date & Time</th>
                                        <th class="p-4 font-black">Type</th>
                                        <th class="p-4 font-black">Lead Coach</th>
                                        <th class="p-4 font-black">Location</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($paginatedTrainings)):
                                        foreach ($paginatedTrainings as $training):
                                            $badge = ($training['training_type'] ?? '') === 'Regular' ? 'purple' : 'blue';
                                    ?>
                                    <tr class="border-b border-gray-800/50 hover:bg-white/[0.02] transition">
                                        <td class="p-4">
                                            <p class="font-bold"><?= htmlspecialchars(formatDate($training['training_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
                                            <p class="text-[10px] text-gray-500"><?= htmlspecialchars(formatTime($training['start_time'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
                                        </td>
                                        <td class="p-4">
                                            <span class="bg-<?= $badge ?>-500/10 text-<?= $badge ?>-400 px-2 py-1 rounded text-[9px] font-bold uppercase border border-<?= $badge ?>-500/20"><?= htmlspecialchars((string)($training['training_type'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                                        </td>
                                        <td class="p-4 text-xs font-medium"><?= htmlspecialchars((string)($training['coach_name'] ?? 'TBA'), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td class="p-4 text-[10px] text-gray-400"><?= htmlspecialchars((string)($training['location'] ?? 'Location TBA'), ENT_QUOTES, 'UTF-8') ?></td>
                                    </tr>
                                    <?php endforeach; else: ?>
                                    <tr>
                                        <td colspan="4" class="p-4 text-center text-gray-500">No upcoming trainings scheduled</td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination Controls -->
                        <?php if ($totalTrainingPages > 1): ?>
                        <div class="flex justify-center gap-2 pt-4 pb-2">
                            <?php if ($trainingPage > 1): ?>
                            <a href="<?= htmlspecialchars($pageLink('training_page', $trainingPage - 1), ENT_QUOTES, 'UTF-8') ?>" class="px-4 py-2 bg-purple-500/10 hover:bg-purple-500/20 rounded-lg text-xs transition font-bold uppercase">
                                <i class="fas fa-chevron-left mr-1"></i> Previous
                            </a>
                            <?php endif; ?>
                            <span class="px-4 py-2 text-xs text-gray-400 flex items-center">Page <?= (int)$trainingPage ?> of <?= (int)$totalTrainingPages ?></span>
                            <?php if ($trainingPage < $totalTrainingPages): ?>
                            <a href="<?= htmlspecialchars($pageLink('training_page', $trainingPage + 1), ENT_QUOTES, 'UTF-8') ?>" class="px-4 py-2 bg-purple-500/10 hover:bg-purple-500/20 rounded-lg text-xs transition font-bold uppercase">
                                Next <i class="fas fa-chevron-right ml-1"></i>
                            </a>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php else: ?>
                <!-- No Access Message -->
                <div class="card-bg rounded-2xl sm:rounded-3xl overflow-hidden p-8 text-center">
                    <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-red-500/10 flex items-center justify-center">
                        <i class="fas fa-lock text-2xl text-red-400"></i>
                    </div>
                    <h3 class="text-lg font-bold mb-2">Access Restricted</h3>
                    <p class="text-sm text-gray-400">You don't have permission to view training schedules.</p>
                </div>
                <?php endif; ?>

                <!-- Event Cards -->
                <?php if (canAccess('events')): ?>
                <div class="space-y-3">
                    <?php
                    if (!empty($paginatedEvents)):
                        foreach ($paginatedEvents as $event):
                            $eventId = (int)($event['event_id'] ?? 0);
                            $participantCount = $participantCounts[$eventId] ?? 0;
                            $eventTimestamp = strtotime((string)($event['event_date'] ?? ''));
                            $daysUntil = $eventTimestamp === false
                                ? 0
                                : max(0, (int)ceil(($eventTimestamp - strtotime(date('Y-m-d'))) / 86400));
                    ?>
                    <div class="card-bg p-4 sm:p-5 rounded-xl sm:rounded-2xl flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                        <div class="flex items-center gap-3 sm:gap-4">
                            <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-full bg-purple-500/20 flex items-center justify-center text-purple-400 text-base sm:text-lg flex-shrink-0">
                                <i class="fas fa-trophy"></i>
                            </div>
                            <div>
                                <h4 class="font-bold italic uppercase text-xs sm:text-sm"><?= htmlspecialchars((string)($event['event_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></h4>
                                <p class="text-[9px] sm:text-[10px] text-gray-500 uppercase mt-1">
                                    <?= $daysUntil > 0 ? 'Starts in ' . $daysUntil . ' days' : 'Today' ?> &bull; <?= (int)$participantCount ?> Participant<?= $participantCount === 1 ? '' : 's' ?>
                                </p>
                                <?php if ($eventsReadPerm === 'own'): ?>
                                <p class="text-[8px] text-blue-400 italic mt-1">You're participating</p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if ($eventsWritePerm !== false): ?>
                        <a href="events.php" class="bg-white/5 hover:bg-white/10 px-4 py-2 rounded-xl text-[10px] font-bold uppercase transition w-full sm:w-auto text-center">Manage</a>
                        <?php else: ?>
                        <span class="bg-gray-500/10 px-4 py-2 rounded-xl text-[10px] font-bold uppercase w-full sm:w-auto text-center text-gray-500">View Only</span>
                        <?php endif; ?>
                    </div>
                    <?php
                        endforeach;
                    else:
                    ?>
                    <div class="card-bg p-4 sm:p-5 rounded-xl sm:rounded-2xl flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                        <div class="flex items-center gap-3 sm:gap-4">
                            <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-full bg-purple-500/20 flex items-center justify-center text-purple-400 text-base sm:text-lg flex-shrink-0">
                                <i class="fas fa-calendar"></i>
                            </div>
                            <div>
                                <h4 class="font-bold italic uppercase text-xs sm:text-sm">
                                    <?= ($eventsReadPerm === 'own') ? 'No Events You\'re Participating In' : 'No Upcoming Events' ?>
                                </h4>
                                <p class="text-[9px] sm:text-[10px] text-gray-500 uppercase mt-1">Check back soon</p>
                            </div>
                        </div>
                        <?php if ($eventsWritePerm !== false): ?>
                        <a href="events.php" class="bg-white/5 hover:bg-white/10 px-4 py-2 rounded-xl text-[10px] font-bold uppercase transition w-full sm:w-auto text-center">Add Event</a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Pagination Controls for Events -->
                    <?php if ($totalEventPages > 1): ?>
                    <div class="flex justify-center gap-2 pt-2">
                        <?php if ($eventPage > 1): ?>
                        <a href="<?= htmlspecialchars($pageLink('event_page', $eventPage - 1), ENT_QUOTES, 'UTF-8') ?>" class="px-4 py-2 bg-purple-500/10 hover:bg-purple-500/20 rounded-lg text-xs transition font-bold uppercase">
                            <i class="fas fa-chevron-left mr-1"></i> Previous
                        </a>
                        <?php endif; ?>
                        <span class="px-4 py-2 text-xs text-gray-400 flex items-center">Page <?= (int)$eventPage ?> of <?= (int)$totalEventPages ?></span>
                        <?php if ($eventPage < $totalEventPages): ?>
                        <a href="<?= htmlspecialchars($pageLink('event_page', $eventPage + 1), ENT_QUOTES, 'UTF-8') ?>" class="px-4 py-2 bg-purple-500/10 hover:bg-purple-500/20 rounded-lg text-xs transition font-bold uppercase">
                            Next <i class="fas fa-chevron-right ml-1"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <!-- No Access Message for Events -->
                <div class="card-bg rounded-2xl sm:rounded-3xl overflow-hidden p-8 text-center">
                    <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-red-500/10 flex items-center justify-center">
                        <i class="fas fa-lock text-2xl text-red-400"></i>
                    </div>
                    <h3 class="text-lg font-bold mb-2">Access Restricted</h3>
                    <p class="text-sm text-gray-400">You don't have permission to view events.</p>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($userRole !== 'Applicant'): ?>
            <!-- Recent Activity -->
            <div class="lg:col-span-1">
                <div class="card-bg rounded-2xl sm:rounded-3xl h-full flex flex-col">
                    <div class="p-4 sm:p-6 border-b border-gray-800 flex justify-between items-center">
                        <h3 class="text-xs sm:text-sm font-bold uppercase tracking-widest italic">Recent Activity</h3>
                        <?php if (hasRole('SuperAdmin')): ?>
                        <a href="activity_log.php" class="text-[9px] sm:text-[10px] text-purple-400 hover:text-white uppercase font-bold italic transition">View All</a>
                        <?php endif; ?>
                    </div>
                    <div class="p-4 sm:p-6 flex-1 overflow-y-auto custom-scrollbar space-y-4 sm:space-y-6 max-h-[400px] sm:max-h-[600px]">

                        <?php if (!empty($paginatedActivities)):
                            foreach ($paginatedActivities as $act):
                                $kind = (string)$act['kind'];
                                $icon = 'fa-circle-info';
                                $dotColor = 'bg-gray-500';

                                if ($kind === 'create') {
                                    $icon = 'fa-plus';
                                    $dotColor = 'bg-blue-500';
                                } elseif ($kind === 'update') {
                                    $icon = 'fa-pen';
                                    $dotColor = 'bg-yellow-500';
                                } elseif ($kind === 'void' || $kind === 'delete') {
                                    $icon = 'fa-ban';
                                    $dotColor = 'bg-red-500';
                                } elseif ($kind === 'restore') {
                                    $icon = 'fa-rotate-left';
                                    $dotColor = 'bg-emerald-500';
                                } elseif ($kind === 'login' || $kind === 'logout') {
                                    $icon = 'fa-right-to-bracket';
                                    $dotColor = 'bg-blue-500';
                                } elseif ($kind === 'login_failed') {
                                    $icon = 'fa-triangle-exclamation';
                                    $dotColor = 'bg-red-500';
                                } elseif ($kind === 'approve' || $kind === 'verify_email') {
                                    $icon = 'fa-check';
                                    $dotColor = 'bg-emerald-500';
                                } elseif ($kind === 'reject') {
                                    $icon = 'fa-xmark';
                                    $dotColor = 'bg-red-500';
                                } elseif ($kind === 'promote') {
                                    $icon = 'fa-arrow-trend-up';
                                    $dotColor = 'bg-emerald-500';
                                } elseif ($kind === 'settings_update') {
                                    $icon = 'fa-gear';
                                    $dotColor = 'bg-purple-500';
                                } elseif ($kind === 'announcement_create') {
                                    $icon = 'fa-bullhorn';
                                    $dotColor = 'bg-blue-500';
                                } elseif ($kind === 'export') {
                                    $icon = 'fa-download';
                                    $dotColor = 'bg-cyan-500';
                                }
                        ?>
                        <div class="flex gap-3 sm:gap-4 relative">
                            <div class="w-1 <?= $dotColor ?> h-full absolute -left-4 sm:-left-6 opacity-50"></div>
                            <div class="min-w-[32px] sm:min-w-[40px] h-8 sm:h-10 rounded-full bg-white/[0.02] flex items-center justify-center text-gray-300 flex-shrink-0">
                                <i class="fas <?= $icon ?> text-xs"></i>
                            </div>
                            <div>
                                <p class="text-[11px] sm:text-xs leading-relaxed"><span class="font-bold text-gray-300"><?= htmlspecialchars($act['actor'], ENT_QUOTES, 'UTF-8') ?></span> <?= htmlspecialchars($act['label'], ENT_QUOTES, 'UTF-8') ?></p>
                                <p class="text-[9px] text-gray-500 uppercase mt-1 italic"><?= htmlspecialchars(date('M d, Y H:i', (int)strtotime($act['ts'])), ENT_QUOTES, 'UTF-8') ?></p>
                            </div>
                        </div>
                        <?php
                            endforeach;
                        else:
                        ?>
                        <div class="flex gap-3 sm:gap-4 opacity-50">
                            <div class="min-w-[32px] sm:min-w-[40px] h-8 sm:h-10 rounded-full bg-gray-500/10 flex items-center justify-center text-gray-400 flex-shrink-0">
                                <i class="fas fa-check text-xs"></i>
                            </div>
                            <div>
                                <p class="text-[11px] sm:text-xs leading-relaxed">
                                    <span class="font-bold text-gray-400">No recent activity</span>
                                </p>
                                <p class="text-[9px] text-gray-600 uppercase mt-1 italic">
                                    <?= (!$auditReady && hasRole('SuperAdmin')) ? 'Run migration 003 to enable the audit log' : 'Based on your access level' ?>
                                </p>
                            </div>
                        </div>
                        <?php endif; ?>

                    </div>

                    <!-- Activity Pagination -->
                    <?php if ($totalActivityPages > 1): ?>
                    <div class="p-4 border-t border-gray-800">
                        <div class="flex justify-center gap-2">
                            <?php if ($activityPage > 1): ?>
                            <a href="<?= htmlspecialchars($pageLink('activity_page', $activityPage - 1), ENT_QUOTES, 'UTF-8') ?>" class="px-3 py-1.5 bg-purple-500/10 hover:bg-purple-500/20 rounded-lg text-[10px] transition font-bold uppercase">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                            <?php endif; ?>
                            <span class="px-3 py-1.5 text-[10px] text-gray-400 flex items-center uppercase">Page <?= (int)$activityPage ?> of <?= (int)$totalActivityPages ?></span>
                            <?php if ($activityPage < $totalActivityPages): ?>
                            <a href="<?= htmlspecialchars($pageLink('activity_page', $activityPage + 1), ENT_QUOTES, 'UTF-8') ?>" class="px-3 py-1.5 bg-purple-500/10 hover:bg-purple-500/20 rounded-lg text-[10px] transition font-bold uppercase">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<?php include_once __DIR__ . '/modals/user_menu.php'; ?>
<?php include_once __DIR__ . '/modals/edit_profile_user.php'; ?>

<script>
// Close modal when clicking outside
window.addEventListener('click', function(e) {
    const modal = document.getElementById('editUserModal');
    if (e.target === modal) {
        closeEditUserModal();
    }
});

// Page load animation
window.addEventListener("load", () => {
    const page = document.getElementById("page");
    page.classList.remove("opacity-0", "translate-x-8");
});
</script>
</body>
</html>
