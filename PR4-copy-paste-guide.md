# PR #4 copy-paste guide

Every gray code box below has a **copy button** (top right). For each file: click copy, open the file in InfinityFree's File Manager, choose **Edit**, press **Ctrl+A**, paste, and **Save**. The path above each box is where the file goes on InfinityFree.

Work top to bottom: Part 1 (new files) first, because the changed files need them.

Contents:
- Part 1: New files (create them) - 8 files
- Part 2: Changed files (replace everything) - 19 files
- Part 3: Small edits in 4 big files (only a few lines)
- Part 4: Files to delete
- Part 5: After uploading

`htdocs/public/trainings.php` only had a comment changed, so you can skip it.

## Part 1: New files (create them)

In File Manager go to the folder, click **New File**, type the exact file name, then Edit, paste and Save.

### New 1/8: `htdocs/includes/checkin_window.php`

`````php
<?php
declare(strict_types=1);

/**
 * Self check-in window for a training session or event, in Asia/Manila time.
 *
 * Opens $openBeforeMin minutes before start_time and closes at end_time
 * (or 3 hours after start when no end time is set). Arriving more than
 * $graceMin minutes after start_time is Late. Sessions without a start time
 * are open for the whole day and never Late.
 *
 * Returned state:
 *   'upcoming' - a later date, or today but before the window opens
 *   'open'     - check-in allowed now
 *   'closed'   - the window has passed; roster members who never checked in
 *                stay Absent
 *
 * @return array{state:string, late:bool, opens_at:?int, closes_at:?int}
 */
function arnisCheckinWindow(string $date, ?string $startTime, ?string $endTime, int $openBeforeMin = 30, int $graceMin = 15, ?int $now = null): array
{
    $tz = new DateTimeZone('Asia/Manila');
    $now = $now ?? time();
    $today = (new DateTimeImmutable('@' . $now))->setTimezone($tz)->format('Y-m-d');
    $date = substr(trim($date), 0, 10);

    $toTs = static function (?string $time) use ($date, $tz): ?int {
        $time = trim((string)$time);
        if ($time === '') {
            return null;
        }
        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $date . ' ' . (strlen($time) === 5 ? $time . ':00' : $time), $tz);
        return $dt === false ? null : $dt->getTimestamp();
    };

    $startTs = $toTs($startTime);
    $endTs = $toTs($endTime);

    if ($startTs === null) {
        $dayStart = (new DateTimeImmutable($date . ' 00:00:00', $tz))->getTimestamp();
        $opensAt = $dayStart;
        $closesAt = $endTs ?? ($dayStart + 86399);
    } else {
        $opensAt = $startTs - max(0, $openBeforeMin) * 60;
        $closesAt = ($endTs !== null && $endTs > $startTs) ? $endTs : $startTs + 3 * 3600;
    }

    if ($date > $today || $now < $opensAt) {
        $state = 'upcoming';
    } elseif ($date < $today || $now > $closesAt) {
        $state = 'closed';
    } else {
        $state = 'open';
    }

    return [
        'state' => $state,
        'late' => $startTs !== null && $now > $startTs + max(0, $graceMin) * 60,
        'opens_at' => $opensAt,
        'closes_at' => $closesAt,
    ];
}

function arnisFormatManila(string $format, int $timestamp): string
{
    return (new DateTimeImmutable('@' . $timestamp))
        ->setTimezone(new DateTimeZone('Asia/Manila'))
        ->format($format);
}
`````

### New 2/8: `htdocs/classes/PasswordReset.php`

`````php
<?php
declare(strict_types=1);

/**
 * One-time, 1-hour password reset tokens sent by email.
 *
 * Only a SHA-256 hash of the token is stored, so a leaked database or backup
 * cannot be used to reset anyone's password.
 */
class PasswordReset
{
    public const TTL_SECONDS = 3600;
    public const MAX_REQUESTS_PER_HOUR = 3;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS `password_resets` (
               `reset_id` int(11) NOT NULL AUTO_INCREMENT,
               `user_id` int(11) NOT NULL,
               `token_hash` char(64) NOT NULL,
               `expires_at` datetime NOT NULL,
               `used_at` datetime DEFAULT NULL,
               `created_at` datetime NOT NULL DEFAULT current_timestamp(),
               PRIMARY KEY (`reset_id`),
               UNIQUE KEY `uq_password_resets_token` (`token_hash`),
               KEY `idx_password_resets_user` (`user_id`, `created_at`),
               CONSTRAINT `fk_password_resets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
        );
    }

    /** Returns the plain token to email, or null when the hourly limit is reached. */
    public function issue(int $userId): ?string
    {
        $recent = $this->db->prepare(
            'SELECT COUNT(*) FROM password_resets
             WHERE user_id = :uid AND created_at > (NOW() - INTERVAL 1 HOUR)'
        );
        $recent->execute([':uid' => $userId]);
        if ((int)$recent->fetchColumn() >= self::MAX_REQUESTS_PER_HOUR) {
            return null;
        }

        $token = bin2hex(random_bytes(32));
        $this->db->prepare(
            'INSERT INTO password_resets (user_id, token_hash, expires_at, created_at)
             VALUES (:uid, :hash, NOW() + INTERVAL ' . (int)self::TTL_SECONDS . ' SECOND, NOW())'
        )->execute([':uid' => $userId, ':hash' => hash('sha256', $token)]);

        return $token;
    }

    /** @return array{reset_id:int,user_id:int}|null */
    public function findValid(string $token): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }
        $stmt = $this->db->prepare(
            'SELECT r.reset_id, r.user_id
             FROM password_resets r
             JOIN users u ON u.user_id = r.user_id
             WHERE r.token_hash = :hash AND r.used_at IS NULL
               AND r.expires_at > NOW() AND u.is_active = 1
             LIMIT 1'
        );
        $stmt->execute([':hash' => hash('sha256', $token)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? ['reset_id' => (int)$row['reset_id'], 'user_id' => (int)$row['user_id']] : null;
    }

    /**
     * Sets the new password, signs the user out everywhere, and burns every
     * outstanding token for that user. Following the emailed link also proves
     * the address, so it is marked verified.
     */
    public function complete(int $resetId, int $userId, string $newPassword): bool
    {
        $this->db->beginTransaction();
        try {
            $burn = $this->db->prepare(
                'UPDATE password_resets SET used_at = NOW() WHERE reset_id = :rid AND used_at IS NULL'
            );
            $burn->execute([':rid' => $resetId]);
            if ($burn->rowCount() !== 1) {
                $this->db->rollBack();
                return false;
            }

            $this->db->prepare(
                'UPDATE users
                 SET password_hash = :hash, session_version = session_version + 1,
                     email_verified_at = COALESCE(email_verified_at, NOW()),
                     email_verification_token = NULL, email_verification_expires_at = NULL,
                     updated_at = NOW()
                 WHERE user_id = :uid'
            )->execute([':hash' => password_hash($newPassword, PASSWORD_DEFAULT), ':uid' => $userId]);

            $this->db->prepare(
                'UPDATE password_resets SET used_at = NOW() WHERE user_id = :uid AND used_at IS NULL'
            )->execute([':uid' => $userId]);

            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
`````

### New 3/8: `htdocs/classes/PrivateTrainings.php`

`````php
<?php
declare(strict_types=1);

require_once __DIR__ . '/Settings.php';

/**
 * Private training requests.
 *
 * Flow:
 *   Pending          member asked for a session at the standard fee
 *   Awaiting Member  coach approved with a different fee; member must accept
 *   Confirmed        a Private training + roster row exist; check-in works as usual
 *   Rejected / Declined / Cancelled  closed without a session
 *
 * Payment is tracked per request and, when marked paid, recorded as Funds income.
 */
class PrivateTrainings
{
    public const OPEN_STATUSES = ['Pending', 'Awaiting Member'];
    public const MAX_OPEN_PER_MEMBER = 3;

    private PDO $db;
    private static bool $schemaReady = false;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        self::ensureSchema($db);
    }

    public static function ensureSchema(PDO $db): void
    {
        if (self::$schemaReady) {
            return;
        }
        $db->exec(
            "CREATE TABLE IF NOT EXISTS `private_training_requests` (
               `request_id` int(11) NOT NULL AUTO_INCREMENT,
               `member_id` int(11) NOT NULL,
               `requested_by` int(11) DEFAULT NULL,
               `preferred_date` date NOT NULL,
               `preferred_time` time DEFAULT NULL,
               `preferred_coach_id` int(11) DEFAULT NULL,
               `notes` varchar(500) DEFAULT NULL,
               `status` enum('Pending','Awaiting Member','Confirmed','Rejected','Declined','Cancelled') NOT NULL DEFAULT 'Pending',
               `coach_id` int(11) DEFAULT NULL,
               `fee_amount` decimal(10,2) DEFAULT NULL,
               `session_date` date DEFAULT NULL,
               `start_time` time DEFAULT NULL,
               `end_time` time DEFAULT NULL,
               `location` varchar(255) DEFAULT NULL,
               `decision_note` varchar(255) DEFAULT NULL,
               `training_id` int(11) DEFAULT NULL,
               `payment_status` enum('Unpaid','Paid') NOT NULL DEFAULT 'Unpaid',
               `paid_at` datetime DEFAULT NULL,
               `fund_id` int(11) DEFAULT NULL,
               `reviewed_by` int(11) DEFAULT NULL,
               `reviewed_at` datetime DEFAULT NULL,
               `created_at` datetime NOT NULL DEFAULT current_timestamp(),
               `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
               PRIMARY KEY (`request_id`),
               KEY `idx_ptr_member` (`member_id`, `status`),
               KEY `idx_ptr_coach` (`coach_id`, `status`),
               CONSTRAINT `fk_ptr_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`member_id`) ON DELETE CASCADE
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
        self::$schemaReady = true;
    }

    public function standardFee(): float
    {
        return round((float)(new Settings($this->db))->get('private_session_fee', '500'), 2);
    }

    public function find(int $requestId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT r.*, m.full_name AS member_name, m.email AS member_email,
                    c.full_name AS coach_name, pc.full_name AS preferred_coach_name
             FROM private_training_requests r
             JOIN members m ON m.member_id = r.member_id
             LEFT JOIN members c ON c.member_id = r.coach_id
             LEFT JOIN members pc ON pc.member_id = r.preferred_coach_id
             WHERE r.request_id = :id"
        );
        $stmt->execute([':id' => $requestId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @param string $scope 'all' | 'coach' | 'member' | 'payments'
     */
    public function listFor(string $scope, int $memberId = 0): array
    {
        $where = '1=1';
        $params = [];
        if ($scope === 'member') {
            $where = 'r.member_id = :mid';
            $params[':mid'] = $memberId;
        } elseif ($scope === 'coach') {
            $where = "(r.coach_id = :mid OR r.preferred_coach_id = :mid2
                       OR (r.status = 'Pending' AND r.preferred_coach_id IS NULL))";
            $params[':mid'] = $memberId;
            $params[':mid2'] = $memberId;
        } elseif ($scope === 'payments') {
            $where = "r.status = 'Confirmed'";
        }

        $stmt = $this->db->prepare(
            "SELECT r.*, m.full_name AS member_name, c.full_name AS coach_name,
                    pc.full_name AS preferred_coach_name
             FROM private_training_requests r
             JOIN members m ON m.member_id = r.member_id
             LEFT JOIN members c ON c.member_id = r.coach_id
             LEFT JOIN members pc ON pc.member_id = r.preferred_coach_id
             WHERE {$where}
             ORDER BY FIELD(r.status, 'Pending', 'Awaiting Member', 'Confirmed') = 0,
                      FIELD(r.status, 'Pending', 'Awaiting Member', 'Confirmed'),
                      COALESCE(r.session_date, r.preferred_date) DESC, r.request_id DESC
             LIMIT 300"
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function coaches(): array
    {
        return $this->db->query(
            "SELECT member_id, full_name, member_type FROM members
             WHERE is_voided = 0 AND status = 'Active' AND member_type IN ('Head Coach', 'Coach')
             ORDER BY full_name"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function isCoach(int $memberId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM members WHERE member_id = :id AND is_voided = 0
             AND status = 'Active' AND member_type IN ('Head Coach', 'Coach')"
        );
        $stmt->execute([':id' => $memberId]);
        return (bool)$stmt->fetchColumn();
    }

    /** Private and Mixed players may book private sessions. */
    public function memberCanRequest(int $memberId): bool
    {
        if ($memberId <= 0) {
            return false;
        }
        $stmt = $this->db->prepare(
            "SELECT 1 FROM members WHERE member_id = :id AND is_voided = 0
             AND status = 'Active' AND player_category IN ('Private', 'Mixed')"
        );
        $stmt->execute([':id' => $memberId]);
        return (bool)$stmt->fetchColumn();
    }

    public function openCount(int $memberId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM private_training_requests
             WHERE member_id = :id AND status IN ('Pending', 'Awaiting Member')"
        );
        $stmt->execute([':id' => $memberId]);
        return (int)$stmt->fetchColumn();
    }

    public function create(int $memberId, int $userId, string $date, ?string $time, ?int $coachId, string $notes): int
    {
        $this->db->prepare(
            "INSERT INTO private_training_requests
               (member_id, requested_by, preferred_date, preferred_time, preferred_coach_id, notes, fee_amount)
             VALUES (:mid, :uid, :d, :t, :c, :n, :fee)"
        )->execute([
            ':mid' => $memberId,
            ':uid' => $userId,
            ':d' => $date,
            ':t' => $time,
            ':c' => $coachId,
            ':n' => $notes !== '' ? $notes : null,
            ':fee' => $this->standardFee(),
        ]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * Coach decision. At the standard fee the member already agreed when they
     * requested, so the session is confirmed at once; any other fee waits for
     * the member to accept it.
     */
    public function approve(array $request, int $reviewerUserId, int $coachId, string $date, string $start, string $end, string $location, float $fee, string $note): string
    {
        $needsAcceptance = abs($fee - $this->standardFee()) > 0.004;

        $this->db->beginTransaction();
        try {
            $upd = $this->db->prepare(
                "UPDATE private_training_requests
                 SET status = 'Awaiting Member', coach_id = :c, session_date = :d, start_time = :s,
                     end_time = :e, location = :l, fee_amount = :f, decision_note = :n,
                     reviewed_by = :u, reviewed_at = NOW()
                 WHERE request_id = :id AND status = 'Pending'"
            );
            $upd->execute([
                ':c' => $coachId, ':d' => $date, ':s' => $start, ':e' => $end, ':l' => $location,
                ':f' => $fee, ':n' => $note !== '' ? $note : null, ':u' => $reviewerUserId,
                ':id' => (int)$request['request_id'],
            ]);
            if ($upd->rowCount() !== 1) {
                throw new RuntimeException('This request was already handled.');
            }
            if (!$needsAcceptance) {
                $this->confirmLocked((int)$request['request_id'], $reviewerUserId);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return $needsAcceptance ? 'Awaiting Member' : 'Confirmed';
    }

    public function memberAccept(int $requestId, int $userId): void
    {
        $this->db->beginTransaction();
        try {
            $this->confirmLocked($requestId, $userId);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /** Must run inside a transaction. Creates the training and roster row. */
    private function confirmLocked(int $requestId, int $actorUserId): void
    {
        $stmt = $this->db->prepare(
            "SELECT r.*, m.full_name AS member_name FROM private_training_requests r
             JOIN members m ON m.member_id = r.member_id
             WHERE r.request_id = :id AND r.status = 'Awaiting Member' FOR UPDATE"
        );
        $stmt->execute([':id' => $requestId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            throw new RuntimeException('This request is no longer waiting for confirmation.');
        }

        $geofence = (int)(new Settings($this->db))->get('default_geofence_m', '100');
        $this->db->prepare(
            "INSERT INTO trainings
               (training_date, training_type, target_group, venue_type, fee_amount, start_time, end_time,
                location, description, coach_id, created_by, geofence_m)
             VALUES (:d, 'Private', 'Private', 'Custom', :f, :s, :e, :l, :desc, :c, :u, :g)"
        )->execute([
            ':d' => $r['session_date'],
            ':f' => (float)$r['fee_amount'],
            ':s' => $r['start_time'],
            ':e' => $r['end_time'],
            ':l' => $r['location'],
            ':desc' => mb_substr('Private training for ' . $r['member_name'] . ' (request #' . $requestId . ')', 0, 255),
            ':c' => (int)$r['coach_id'],
            ':u' => $actorUserId,
            ':g' => $geofence > 0 ? $geofence : 100,
        ]);
        $trainingId = (int)$this->db->lastInsertId();

        $this->db->prepare(
            "INSERT INTO attendance (training_id, member_id, status, recorded_by)
             VALUES (:t, :m, 'Absent', :u)"
        )->execute([':t' => $trainingId, ':m' => (int)$r['member_id'], ':u' => $actorUserId]);

        $this->db->prepare(
            "UPDATE private_training_requests SET status = 'Confirmed', training_id = :t WHERE request_id = :id"
        )->execute([':t' => $trainingId, ':id' => $requestId]);
    }

    /** Close a request. Cancelling a confirmed one also voids its training. */
    public function close(array $request, string $newStatus, int $actorUserId, string $note): void
    {
        $this->db->beginTransaction();
        try {
            $upd = $this->db->prepare(
                "UPDATE private_training_requests
                 SET status = :ns, decision_note = COALESCE(:n, decision_note),
                     reviewed_by = COALESCE(reviewed_by, :u), reviewed_at = COALESCE(reviewed_at, NOW())
                 WHERE request_id = :id AND status = :os"
            );
            $upd->execute([
                ':ns' => $newStatus, ':n' => $note !== '' ? $note : null, ':u' => $actorUserId,
                ':id' => (int)$request['request_id'], ':os' => (string)$request['status'],
            ]);
            if ($upd->rowCount() !== 1) {
                throw new RuntimeException('This request was already handled.');
            }
            if ($request['status'] === 'Confirmed' && !empty($request['training_id'])) {
                $this->db->prepare(
                    "UPDATE trainings SET is_voided = 1, voided_at = NOW(), voided_by = :u,
                            void_reason = 'Private training request cancelled'
                     WHERE training_id = :t AND is_voided = 0"
                )->execute([':u' => $actorUserId, ':t' => (int)$request['training_id']]);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function markPaid(array $request, int $actorUserId): int
    {
        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                "INSERT INTO funds (transaction_type, source_or_purpose, amount, transaction_date, notes, recorded_by)
                 VALUES ('Income', :src, :amt, CURDATE(), :notes, :u)"
            )->execute([
                ':src' => mb_substr('Private training fee - ' . $request['member_name'], 0, 255),
                ':amt' => (float)$request['fee_amount'],
                ':notes' => mb_substr('Private training request #' . $request['request_id'] . ' on ' . $request['session_date'], 0, 255),
                ':u' => $actorUserId,
            ]);
            $fundId = (int)$this->db->lastInsertId();

            $upd = $this->db->prepare(
                "UPDATE private_training_requests
                 SET payment_status = 'Paid', paid_at = NOW(), fund_id = :f
                 WHERE request_id = :id AND status = 'Confirmed' AND payment_status = 'Unpaid'"
            );
            $upd->execute([':f' => $fundId, ':id' => (int)$request['request_id']]);
            if ($upd->rowCount() !== 1) {
                throw new RuntimeException('This session is already marked as paid.');
            }
            $this->db->commit();
            return $fundId;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
`````

### New 4/8: `htdocs/forgot_password.php`

`````php
<?php
declare(strict_types=1);

require_once __DIR__ . '/config/session.php';
arnisStartSession();
require_once __DIR__ . '/config/Database.php';
require_once __DIR__ . '/classes/Mailer.php';
require_once __DIR__ . '/classes/AuditLog.php';
require_once __DIR__ . '/classes/PasswordReset.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');

if (isset($_SESSION['user_id'])) {
    header('Location: public/dashboard.php');
    exit;
}

$notice = '';
$noticeType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!arnisVerifyCsrfToken(isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : null)) {
        $notice = 'Your session expired. Please reload the page and try again.';
        $noticeType = 'error';
    } elseif ((int)($_SESSION['password_reset_requested_at'] ?? 0) > time() - 60) {
        $notice = 'Please wait a minute before requesting another reset link.';
        $noticeType = 'error';
    } else {
        $identifier = trim((string)($_POST['identifier'] ?? ''));
        $_SESSION['password_reset_requested_at'] = time();

        if ($identifier !== '') {
            try {
                $db = (new Database())->connect();
                $stmt = $db->prepare(
                    'SELECT user_id, full_name, username, email, role
                     FROM users
                     WHERE (username = :username OR email = :email) AND is_active = 1
                     LIMIT 1'
                );
                $stmt->execute([':username' => $identifier, ':email' => $identifier]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if (is_array($user) && filter_var((string)($user['email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
                    $token = (new PasswordReset($db))->issue((int)$user['user_id']);
                    if ($token !== null) {
                        (new Mailer($db))->sendPasswordReset((string)$user['email'], (string)$user['full_name'], $token);
                        AuditLog::recordForUser(
                            $db,
                            (int)$user['user_id'],
                            (string)$user['full_name'],
                            (string)($user['role'] ?? ''),
                            'password_reset_request',
                            'auth',
                            (int)$user['user_id'],
                            'Requested a password reset email for ' . (string)$user['username']
                        );
                    }
                }
            } catch (Throwable $e) {
                error_log('forgot_password.php error: ' . $e->getMessage());
            }
        }

        // Same message either way so this page cannot reveal which accounts exist.
        $notice = 'If an account with an email address matches, a password reset link has been sent. Check your inbox and spam folder. The link expires in 1 hour.';
    }
}

$csrfToken = arnisCsrfToken();
?>
<!DOCTYPE html>
<html lang="en" class="text-[13px]">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password | Arnis Connect</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" type="image/x-icon" href="arnis-favicon.ico">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,100..900;1,100..900&display=swap" rel="stylesheet">
    <style>
        :root { --dark-purple: #1d212a; --card-bg: #161a23; }
        body { font-family: 'Montserrat', sans-serif; background-color: var(--dark-purple); }
        .card-bg { background-color: var(--card-bg); border: 1px solid rgba(255,255,255,0.05); }
        .form-input { background: #1d212a; border: 1px solid transparent; transition: all 0.3s; color: white; }
        .form-input:focus { border-color: #a855f7; outline: none; box-shadow: 0 0 15px rgba(168, 85, 247, 0.1); }
    </style>
</head>
<body class="flex items-center justify-center min-h-screen p-4">
    <div class="fixed top-[-10%] left-[-10%] w-[40%] h-[40%] bg-purple-600/10 blur-[120px] rounded-full"></div>
    <div class="fixed bottom-[-10%] right-[-10%] w-[40%] h-[40%] bg-blue-600/10 blur-[120px] rounded-full"></div>

    <main class="relative z-10 w-full max-w-md">
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-purple-500/10 text-purple-400 border border-purple-500/20 mb-4 shadow-lg">
                <i class="fas fa-key text-2xl"></i>
            </div>
            <h1 class="text-2xl font-black uppercase tracking-tighter italic text-white">Forgot <span class="text-purple-500">Password</span></h1>
            <p class="text-gray-400 mt-2 text-xs">Enter your username or email. We will email you a link to set a new password.</p>
        </div>

        <?php if ($notice !== ''): ?>
        <div class="mb-4 p-4 rounded-2xl border text-sm font-semibold <?= $noticeType === 'success' ? 'bg-emerald-500/10 border-emerald-500/20 text-emerald-300' : 'bg-red-500/10 border-red-500/20 text-red-300' ?>">
            <?= htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') ?>
        </div>
        <?php endif; ?>

        <div class="card-bg p-8 rounded-[2rem] shadow-2xl">
            <form method="POST" action="forgot_password.php" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <div>
                    <label for="identifier" class="block text-[10px] uppercase tracking-[0.16em] font-black text-gray-500 mb-2">Username or Email</label>
                    <input type="text" id="identifier" name="identifier" required maxlength="100" autocomplete="username" class="form-input w-full rounded-2xl px-4 py-4 text-sm" placeholder="juan_delacruz or you@example.com">
                </div>
                <button type="submit" class="w-full bg-purple-600 hover:bg-purple-500 text-white font-black uppercase text-[11px] tracking-[0.2em] py-4 rounded-2xl transition">
                    <i class="fas fa-paper-plane mr-2"></i>Send Reset Link
                </button>
            </form>
            <p class="text-[11px] text-gray-500 mt-4 text-center">No email on your account? Ask the club administrator to reset your password.</p>

            <div class="mt-5 text-center">
                <a href="index.php" class="text-xs uppercase tracking-[0.16em] font-black text-gray-400 hover:text-white transition">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Login
                </a>
            </div>
        </div>
    </main>
</body>
</html>
`````

### New 5/8: `htdocs/reset_password.php`

`````php
<?php
declare(strict_types=1);

require_once __DIR__ . '/config/session.php';
arnisStartSession();
require_once __DIR__ . '/config/Database.php';
require_once __DIR__ . '/classes/AuditLog.php';
require_once __DIR__ . '/classes/PasswordReset.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

$token = isset($_REQUEST['token']) && is_string($_REQUEST['token']) ? trim($_REQUEST['token']) : '';
$error = '';
$valid = null;

try {
    $db = (new Database())->connect();
    $resets = new PasswordReset($db);
    $valid = $resets->findValid($token);

    if ($valid !== null && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $password = (string)($_POST['password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');

        if (!arnisVerifyCsrfToken(isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : null)) {
            $error = 'Your session expired. Please try again.';
        } elseif (strlen($password) < 8 || strlen($password) > 100) {
            $error = 'Password must be 8 to 100 characters.';
        } elseif (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            $error = 'Password must contain at least one letter and one number.';
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } elseif ($resets->complete($valid['reset_id'], $valid['user_id'], $password)) {
            $who = $db->prepare('SELECT full_name, username, role FROM users WHERE user_id = :id');
            $who->execute([':id' => $valid['user_id']]);
            $u = $who->fetch(PDO::FETCH_ASSOC) ?: [];
            AuditLog::recordForUser(
                $db,
                $valid['user_id'],
                (string)($u['full_name'] ?? ''),
                (string)($u['role'] ?? ''),
                'password_reset',
                'auth',
                $valid['user_id'],
                'Reset password by email link for ' . (string)($u['username'] ?? '')
            );
            header('Location: index.php?reset=1');
            exit;
        } else {
            $valid = null;
        }
    }
} catch (Throwable $e) {
    error_log('reset_password.php error: ' . $e->getMessage());
    $error = 'Password reset is temporarily unavailable. Please try again later.';
}

$csrfToken = arnisCsrfToken();
?>
<!DOCTYPE html>
<html lang="en" class="text-[13px]">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password | Arnis Connect</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" type="image/x-icon" href="arnis-favicon.ico">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,100..900;1,100..900&display=swap" rel="stylesheet">
    <style>
        :root { --dark-purple: #1d212a; --card-bg: #161a23; }
        body { font-family: 'Montserrat', sans-serif; background-color: var(--dark-purple); }
        .card-bg { background-color: var(--card-bg); border: 1px solid rgba(255,255,255,0.05); }
        .form-input { background: #1d212a; border: 1px solid transparent; transition: all 0.3s; color: white; }
        .form-input:focus { border-color: #a855f7; outline: none; box-shadow: 0 0 15px rgba(168, 85, 247, 0.1); }
    </style>
</head>
<body class="flex items-center justify-center min-h-screen p-4">
    <div class="fixed top-[-10%] left-[-10%] w-[40%] h-[40%] bg-purple-600/10 blur-[120px] rounded-full"></div>
    <div class="fixed bottom-[-10%] right-[-10%] w-[40%] h-[40%] bg-blue-600/10 blur-[120px] rounded-full"></div>

    <main class="relative z-10 w-full max-w-md">
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-purple-500/10 text-purple-400 border border-purple-500/20 mb-4 shadow-lg">
                <i class="fas fa-lock text-2xl"></i>
            </div>
            <h1 class="text-2xl font-black uppercase tracking-tighter italic text-white">Set New <span class="text-purple-500">Password</span></h1>
        </div>

        <?php if ($error !== ''): ?>
        <div class="mb-4 p-4 rounded-2xl border text-sm font-semibold bg-red-500/10 border-red-500/20 text-red-300">
            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
        </div>
        <?php endif; ?>

        <div class="card-bg p-8 rounded-[2rem] shadow-2xl">
            <?php if ($valid !== null): ?>
            <form method="POST" action="reset_password.php" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
                <div>
                    <label for="password" class="block text-[10px] uppercase tracking-[0.16em] font-black text-gray-500 mb-2">New Password</label>
                    <input type="password" id="password" name="password" required minlength="8" maxlength="100" autocomplete="new-password" class="form-input w-full rounded-2xl px-4 py-4 text-sm" placeholder="At least 8 characters, with a letter and a number">
                </div>
                <div>
                    <label for="confirm_password" class="block text-[10px] uppercase tracking-[0.16em] font-black text-gray-500 mb-2">Confirm Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" required minlength="8" maxlength="100" autocomplete="new-password" class="form-input w-full rounded-2xl px-4 py-4 text-sm">
                </div>
                <button type="submit" class="w-full bg-purple-600 hover:bg-purple-500 text-white font-black uppercase text-[11px] tracking-[0.2em] py-4 rounded-2xl transition">
                    <i class="fas fa-check mr-2"></i>Save New Password
                </button>
            </form>
            <?php else: ?>
            <p class="text-sm text-gray-300 leading-relaxed text-center mb-6">This reset link is invalid, already used, or expired. Request a new one.</p>
            <a href="forgot_password.php" class="block w-full text-center bg-purple-600 hover:bg-purple-500 text-white font-black uppercase text-[11px] tracking-[0.2em] py-4 rounded-2xl transition">
                Request New Link
            </a>
            <?php endif; ?>
            <div class="mt-5 text-center">
                <a href="index.php" class="text-xs uppercase tracking-[0.16em] font-black text-gray-400 hover:text-white transition">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Login
                </a>
            </div>
        </div>
    </main>
</body>
</html>
`````

### New 6/8: `htdocs/actions/process_private_training.php`

`````php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/AuditLog.php';
require_once __DIR__ . '/../classes/Mailer.php';
require_once __DIR__ . '/../classes/PrivateTrainings.php';

function ptRedirect(string $type, string $message): void
{
    $_SESSION['private_training_flash'] = ['type' => $type, 'message' => $message];
    header('Location: ../public/private_trainings.php');
    exit;
}

function ptNotify(PDO $db, ?string $email, string $name, string $subject, string $message): void
{
    if ($email === null || $email === '') {
        return;
    }
    try {
        (new Mailer($db))->sendNotice($email, $name, $subject, $message);
    } catch (Throwable $e) {
        error_log('private training notice failed: ' . $e->getMessage());
    }
}

function ptPeso(float $amount): string
{
    return 'PHP ' . number_format($amount, 2);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Invalid request method.');
}
arnisRequireCsrf(false);

$role = currentUserRole() ?? '';
$userId = (int)($_SESSION['user_id'] ?? 0);
$op = (string)($_POST['op'] ?? '');
$todayManila = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');

try {
    $db = (new Database())->connect();
    $pt = new PrivateTrainings($db);
} catch (Throwable $e) {
    error_log('process_private_training DB error: ' . $e->getMessage());
    ptRedirect('error', 'Private trainings are temporarily unavailable.');
}

$isAdminReviewer = in_array($role, ['SuperAdmin', 'Head Coach'], true);
$coachMemberId = in_array($role, ['Head Coach', 'Coach/Trainer'], true) ? (int)(getCurrentCoachMemberId($db) ?? 0) : 0;
$selfMemberId = $role === 'Member' ? resolveSessionMemberId($db) : 0;
$canRecordPayment = permissionValue('funds', 'write') === true;

$validTime = static fn(string $t): bool => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t) === 1;
$validDate = static function (string $d): bool {
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $d);
    return $dt !== false && $dt->format('Y-m-d') === $d;
};

if ($op === 'request') {
    if ($role !== 'Member' || !$pt->memberCanRequest($selfMemberId)) {
        ptRedirect('error', 'Only active Private or Mixed players can request private training.');
    }
    if ($pt->openCount($selfMemberId) >= PrivateTrainings::MAX_OPEN_PER_MEMBER) {
        ptRedirect('error', 'You already have ' . PrivateTrainings::MAX_OPEN_PER_MEMBER . ' open requests. Wait for a reply or cancel one first.');
    }
    $date = trim((string)($_POST['preferred_date'] ?? ''));
    $time = trim((string)($_POST['preferred_time'] ?? ''));
    $coachRaw = (int)($_POST['preferred_coach_id'] ?? 0);
    $notes = mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 500);

    if (!$validDate($date) || $date < $todayManila) {
        ptRedirect('error', 'Choose a preferred date from today onwards.');
    }
    if ($time !== '' && !$validTime($time)) {
        ptRedirect('error', 'Preferred time is invalid.');
    }
    if ($coachRaw > 0 && !$pt->isCoach($coachRaw)) {
        ptRedirect('error', 'The selected coach is not available.');
    }

    $id = $pt->create($selfMemberId, $userId, $date, $time !== '' ? $time : null, $coachRaw > 0 ? $coachRaw : null, $notes);
    AuditLog::record($db, 'create', 'private_trainings', $id, 'Requested private training for ' . $date);
    ptRedirect('success', 'Request sent. A coach will review it and confirm the schedule.');
}

$requestId = (int)($_POST['request_id'] ?? 0);
$request = $requestId > 0 ? $pt->find($requestId) : null;
if ($request === null) {
    ptRedirect('error', 'Request not found.');
}
$status = (string)$request['status'];
$isOwner = $role === 'Member' && $selfMemberId > 0 && (int)$request['member_id'] === $selfMemberId;
$coachMayReview = $coachMemberId > 0 && (
    (int)($request['coach_id'] ?? 0) === $coachMemberId
    || ($status === 'Pending' && in_array((int)($request['preferred_coach_id'] ?? 0), [0, $coachMemberId], true))
);
$canReview = $isAdminReviewer || $coachMayReview;
$memberEmail = (string)($request['member_email'] ?? '');
$memberName = (string)$request['member_name'];

try {
    switch ($op) {
        case 'approve':
            if (!$canReview || $status !== 'Pending') {
                ptRedirect('error', 'You cannot approve this request.');
            }
            $coachId = $isAdminReviewer ? (int)($_POST['coach_id'] ?? 0) : $coachMemberId;
            $date = trim((string)($_POST['session_date'] ?? ''));
            $start = trim((string)($_POST['start_time'] ?? ''));
            $end = trim((string)($_POST['end_time'] ?? ''));
            $location = mb_substr(trim((string)($_POST['location'] ?? '')), 0, 255);
            $feeRaw = trim((string)($_POST['fee_amount'] ?? ''));
            $note = mb_substr(trim((string)($_POST['decision_note'] ?? '')), 0, 255);

            if ($coachId <= 0 || !$pt->isCoach($coachId)) {
                ptRedirect('error', 'Select the coach who will handle the session.');
            }
            if (!$validDate($date) || $date < $todayManila) {
                ptRedirect('error', 'Session date must be today or later.');
            }
            if (!$validTime($start) || !$validTime($end) || $end <= $start) {
                ptRedirect('error', 'Enter a start time and an end time after it.');
            }
            if ($location === '') {
                ptRedirect('error', 'Location is required.');
            }
            if (!is_numeric($feeRaw) || (float)$feeRaw < 0 || (float)$feeRaw > 100000) {
                ptRedirect('error', 'Fee must be between 0 and 100,000.');
            }
            $fee = round((float)$feeRaw, 2);

            $newStatus = $pt->approve($request, $userId, $coachId, $date, $start, $end, $location, $fee, $note);
            AuditLog::record($db, 'approve', 'private_trainings', $requestId, "Approved private training for {$memberName} on {$date} at " . ptPeso($fee) . " ({$newStatus})");

            $when = date('M j, Y', strtotime($date)) . ' ' . date('g:i A', strtotime($start)) . ' to ' . date('g:i A', strtotime($end));
            if ($newStatus === 'Confirmed') {
                ptNotify($db, $memberEmail, $memberName, 'Private training confirmed',
                    "Your private training is confirmed for {$when} at {$location}. Fee: " . ptPeso($fee) . '.');
                ptRedirect('success', 'Approved and scheduled. The session now appears in Trainings.');
            }
            ptNotify($db, $memberEmail, $memberName, 'Private training: please confirm the fee',
                "A coach can train you on {$when} at {$location} for " . ptPeso($fee) . '. Open Private Trainings to accept or decline.');
            ptRedirect('success', 'Sent to the member to accept the ' . ptPeso($fee) . ' fee.');

        case 'reject':
            if (!$canReview || $status !== 'Pending') {
                ptRedirect('error', 'You cannot reject this request.');
            }
            $note = mb_substr(trim((string)($_POST['decision_note'] ?? '')), 0, 255);
            if ($note === '') {
                ptRedirect('error', 'Give the member a short reason.');
            }
            $pt->close($request, 'Rejected', $userId, $note);
            AuditLog::record($db, 'reject', 'private_trainings', $requestId, "Rejected private training for {$memberName}: {$note}");
            ptNotify($db, $memberEmail, $memberName, 'Private training request declined', "Your private training request was not approved. Reason: {$note}");
            ptRedirect('success', 'Request rejected.');

        case 'accept':
            if (!$isOwner || $status !== 'Awaiting Member') {
                ptRedirect('error', 'This request is not waiting for your answer.');
            }
            if ((string)$request['session_date'] < $todayManila) {
                $pt->close($request, 'Cancelled', $userId, 'Offer expired before it was accepted');
                ptRedirect('error', 'That session date has passed. Please send a new request.');
            }
            $pt->memberAccept($requestId, $userId);
            AuditLog::record($db, 'approve', 'private_trainings', $requestId, 'Member accepted private training fee ' . ptPeso((float)$request['fee_amount']));
            $coachEmail = $db->prepare('SELECT email, full_name FROM members WHERE member_id = :id');
            $coachEmail->execute([':id' => (int)$request['coach_id']]);
            $coach = $coachEmail->fetch(PDO::FETCH_ASSOC) ?: [];
            ptNotify($db, (string)($coach['email'] ?? ''), (string)($coach['full_name'] ?? 'Coach'), 'Private training confirmed',
                "{$memberName} accepted the private training on {$request['session_date']}.");
            ptRedirect('success', 'Confirmed. The session is now in your Trainings list.');

        case 'decline':
            if (!$isOwner || $status !== 'Awaiting Member') {
                ptRedirect('error', 'This request is not waiting for your answer.');
            }
            $pt->close($request, 'Declined', $userId, '');
            AuditLog::record($db, 'reject', 'private_trainings', $requestId, 'Member declined the private training fee');
            ptRedirect('success', 'Offer declined. You can send a new request any time.');

        case 'cancel':
            $memberMayCancel = $isOwner && in_array($status, PrivateTrainings::OPEN_STATUSES, true);
            $staffMayCancel = $canReview && (in_array($status, PrivateTrainings::OPEN_STATUSES, true)
                || ($status === 'Confirmed' && (string)$request['session_date'] >= $todayManila));
            if (!$memberMayCancel && !$staffMayCancel) {
                ptRedirect('error', 'This request can no longer be cancelled.');
            }
            if ($request['payment_status'] === 'Paid') {
                ptRedirect('error', 'This session is already paid. Settle the refund first, then void the Funds entry.');
            }
            $note = mb_substr(trim((string)($_POST['decision_note'] ?? '')), 0, 255);
            $pt->close($request, 'Cancelled', $userId, $note);
            AuditLog::record($db, 'status_change', 'private_trainings', $requestId, "Cancelled private training for {$memberName}" . ($note !== '' ? ": {$note}" : ''));
            if (!$isOwner) {
                ptNotify($db, $memberEmail, $memberName, 'Private training cancelled',
                    'Your private training was cancelled.' . ($note !== '' ? " Reason: {$note}" : ''));
            }
            ptRedirect('success', 'Request cancelled.');

        case 'mark_paid':
            if (!$canRecordPayment) {
                ptRedirect('error', 'Only the treasurer or administrator can record payments.');
            }
            if ($status !== 'Confirmed' || $request['payment_status'] !== 'Unpaid' || (float)$request['fee_amount'] <= 0) {
                ptRedirect('error', 'There is no unpaid fee on this session.');
            }
            $fundId = $pt->markPaid($request, $userId);
            AuditLog::record($db, 'create', 'funds', $fundId, 'Recorded private training fee ' . ptPeso((float)$request['fee_amount']) . " from {$memberName}");
            ptRedirect('success', 'Payment recorded and added to Funds as income.');
    }
} catch (Throwable $e) {
    error_log('process_private_training error: ' . $e->getMessage());
    $safe = $e instanceof RuntimeException && !$e instanceof PDOException;
    ptRedirect('error', $safe ? $e->getMessage() : 'Something went wrong. Please try again.');
}

ptRedirect('error', 'Unknown action.');
`````

### New 7/8: `htdocs/public/private_trainings.php`

`````php
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
`````

### New 8/8: `htdocs/public/attendance_report.php`

`````php
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
`````

## Part 2: Changed files (replace everything)

Open the existing file, choose Edit, press Ctrl+A, paste, and Save.

### Changed 1/19: `htdocs/classes/AuditLog.php`

`````php
<?php
declare(strict_types=1);

/**
 * Real, append-only audit trail.
 *
 * The previous "activity log" was a UNION of created_at/updated_at/voided_at
 * columns. It could not record logins, approvals, promotions, settings changes,
 * report exports, or the actor's role/IP, and it re-described the same row up to
 * three times. This class writes one intentional event per action.
 *
 * Fails soft when the audit_logs table has not been migrated yet, so a missing
 * migration never breaks a business action.
 */
class AuditLog
{
    private static bool $schemaChecked = false;

    /**
     * Older databases were created with an audit_logs table shaped
     * (id, target_table, target_id) that migration 003 skipped because the
     * table already existed. Upgrade it in place to the
     * (audit_id, record_id, meta_json) shape this class writes.
     */
    public static function ensureSchema(PDO $db): void
    {
        if (self::$schemaChecked) {
            return;
        }
        self::$schemaChecked = true;

        try {
            $cols = $db->query(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_logs'"
            )->fetchAll(PDO::FETCH_COLUMN);
            if ($cols === []) {
                return;
            }

            $changes = [];
            if (!in_array('audit_id', $cols, true) && in_array('id', $cols, true)) {
                $changes[] = 'CHANGE `id` `audit_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT';
            }
            if (!in_array('record_id', $cols, true)) {
                $changes[] = 'ADD `record_id` INT(11) DEFAULT NULL AFTER `module`';
            }
            if (!in_array('meta_json', $cols, true)) {
                $changes[] = 'ADD `meta_json` TEXT DEFAULT NULL AFTER `description`';
            }
            if ($changes !== []) {
                $db->exec('ALTER TABLE `audit_logs` ' . implode(', ', $changes));
            }
            if (in_array('target_id', $cols, true) && !in_array('record_id', $cols, true)) {
                $db->exec('UPDATE `audit_logs` SET `record_id` = `target_id` WHERE `record_id` IS NULL');
            }
        } catch (Throwable $e) {
            error_log('AuditLog::ensureSchema failed: ' . $e->getMessage());
        }
    }

    public static function record(
        PDO $db,
        string $action,
        string $module,
        ?int $recordId = null,
        string $description = '',
        array $meta = []
    ): void {
        self::ensureSchema($db);
        try {
            $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
            $actorName = trim((string)($_SESSION['full_name'] ?? ''));
            $actorRole = trim((string)($_SESSION['role'] ?? ''));

            if (($actorName === '' || $actorRole === '') && $userId !== null) {
                $lookup = $db->prepare('SELECT full_name, role FROM users WHERE user_id = :id LIMIT 1');
                $lookup->execute([':id' => $userId]);
                $row = $lookup->fetch(PDO::FETCH_ASSOC) ?: [];
                if ($actorName === '') {
                    $actorName = (string)($row['full_name'] ?? '');
                }
                if ($actorRole === '') {
                    $actorRole = (string)($row['role'] ?? '');
                }
            }

            if ($actorName === '') {
                $actorName = 'System';
            }

            $description = trim($description);
            if (mb_strlen($description) > 500) {
                $description = mb_substr($description, 0, 497) . '...';
            }

            $stmt = $db->prepare(
                'INSERT INTO audit_logs
                    (user_id, actor_name, actor_role, action, module, record_id,
                     description, meta_json, ip_address, user_agent, created_at)
                 VALUES
                    (:user_id, :actor_name, :actor_role, :action, :module, :record_id,
                     :description, :meta_json, :ip_address, :user_agent, NOW())'
            );
            $stmt->execute([
                ':user_id' => $userId,
                ':actor_name' => $actorName,
                ':actor_role' => $actorRole,
                ':action' => mb_substr($action, 0, 50),
                ':module' => mb_substr($module, 0, 50),
                ':record_id' => $recordId !== null && $recordId > 0 ? $recordId : null,
                ':description' => $description,
                ':meta_json' => $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':ip_address' => function_exists('arnisClientIp') ? arnisClientIp() : substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
                ':user_agent' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]);
        } catch (Throwable $e) {
            error_log('AuditLog::record skipped: ' . $e->getMessage());
        }
    }

    /**
     * Record an event for a specific account (used by login, where the session
     * has not been created yet).
     */
    public static function recordForUser(
        PDO $db,
        int $userId,
        string $actorName,
        string $actorRole,
        string $action,
        string $module,
        ?int $recordId,
        string $description
    ): void {
        self::ensureSchema($db);
        try {
            $stmt = $db->prepare(
                'INSERT INTO audit_logs
                    (user_id, actor_name, actor_role, action, module, record_id,
                     description, ip_address, user_agent, created_at)
                 VALUES
                    (:user_id, :actor_name, :actor_role, :action, :module, :record_id,
                     :description, :ip_address, :user_agent, NOW())'
            );
            $stmt->execute([
                ':user_id' => $userId > 0 ? $userId : null,
                ':actor_name' => $actorName !== '' ? $actorName : 'System',
                ':actor_role' => mb_substr($actorRole, 0, 50),
                ':action' => mb_substr($action, 0, 50),
                ':module' => mb_substr($module, 0, 50),
                ':record_id' => $recordId !== null && $recordId > 0 ? $recordId : null,
                ':description' => mb_substr($description, 0, 500),
                ':ip_address' => function_exists('arnisClientIp') ? arnisClientIp() : substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
                ':user_agent' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]);
        } catch (Throwable $e) {
            error_log('AuditLog::recordForUser skipped: ' . $e->getMessage());
        }
    }
}
`````

### Changed 2/19: `htdocs/classes/Mailer.php`

`````php
<?php
declare(strict_types=1);

require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/../phpmailer/src/Exception.php';
require_once __DIR__ . '/../phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/../phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class Mailer
{
    private PDO $conn;
    private Settings $settings;
    private bool $debug = false;

    public function __construct(PDO $db, bool $debug = false)
    {
        $this->conn = $db;
        $this->settings = new Settings($db);
        $this->debug = $debug;
    }

    public function notificationsEnabled(): bool
    {
        return $this->settings->enabled('email_notifications');
    }

    /**
     * $accountEmail = true for verification / password-reset mail, which must
     * go out even when general email notifications are switched off.
     */
    public function send(string $to, string $subject, string $htmlBody, ?string $replyTo = null, bool $accountEmail = false): bool
    {
        if (!$accountEmail && !$this->notificationsEnabled()) {
            return false;
        }

        $to = trim($to);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $org = $this->settings->get('organization_name', 'Arnis Connect');
        $fromEmail = trim($this->settings->get('from_email'));
        if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            $fromEmail = 'christpartosa9@gmail.com';
        }
        $fromName = trim($this->settings->get('from_name', $org));
        if ($fromName === '') {
            $fromName = $org;
        }

        $smtpUser = trim($this->settings->get('smtp_user'));
        $smtpPass = trim($this->settings->get('smtp_pass'));

        if ($smtpUser === '' || $smtpPass === '') {
            error_log('Mailer: SMTP login is not configured (Settings -> Email).');
            return false;
        }

        $subject = trim(preg_replace('/[\r\n]+/', ' ', $subject) ?? $subject);
        $subject = mb_substr($subject, 0, 180);

        $body = $this->logoHtml() . $this->wrapHtml($htmlBody, $org);

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = $this->settings->get('smtp_host', 'smtp-relay.brevo.com');
            $mail->SMTPAuth   = true;
            $mail->Username   = $smtpUser;
            $mail->Password   = $smtpPass;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = (int)$this->settings->get('smtp_port', '587');
            $mail->SMTPDebug  = $this->debug ? 2 : 0;

            $mail->setFrom($fromEmail, $fromName);
            if ($replyTo) {
                $mail->addReplyTo($replyTo);
            }
            $mail->addAddress($to);

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $body;
            $mail->AltBody = strip_tags($htmlBody);

            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log('Mailer SMTP error: ' . $mail->ErrorInfo);
            return false;
        }
    }

    private function logoHtml(): string
    {
        $logoPath = __DIR__ . '/../../arnis-logo.png';
        $logoUrl  = 'https://arnis-connect.great-site.net/arnis-logo.png';

        if (is_file($logoPath)) {
            $data = file_get_contents($logoPath);
            $base64 = base64_encode($data);
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $logoPath) ?: 'image/png';
            finfo_close($finfo);
            return '<div style="text-align:center;margin-bottom:20px;"><img src="data:' . $mime . ';base64,' . $base64 . '" alt="Arnis Connect" style="height:48px;"></div>';
        }

        return '<div style="text-align:center;margin-bottom:20px;"><img src="' . $logoUrl . '" alt="Arnis Connect" style="height:48px;"></div>';
    }

    private function wrapHtml(string $body, string $org): string
    {
        return '
        <div style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:24px;background:#1d212a;color:#fff;border-radius:12px;">
            ' . $body . '
            <hr style="border:none;border-top:1px solid #333;margin:24px 0;">
            <p style="font-size:12px;color:#888;margin:0;">© ' . htmlspecialchars($org) . '</p>
        </div>';
    }

    public function sendVerification(string $to, string $fullName, string $token): bool
    {
        $verifyUrl = 'https://arnis-connect.great-site.net/verify_email.php?token=' . urlencode($token);
        $body = '<p>Hello <strong>' . htmlspecialchars($fullName) . '</strong>,</p>'
            . '<p>Click the button below to verify your email and activate your account:</p>'
            . '<div style="text-align:center;margin:24px 0;">'
            . '<a href="' . htmlspecialchars($verifyUrl) . '" style="background:#a855f7;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:bold;display:inline-block;">Verify Email</a>'
            . '</div>'
            . '<p style="font-size:14px;color:#aaa;">If you did not sign up, ignore this email.</p>';
        return $this->send($to, 'Verify your Arnis Connect account', $body, null, true);
    }

    public function sendPasswordReset(string $to, string $fullName, string $token): bool
    {
        $resetUrl = 'https://arnis-connect.great-site.net/reset_password.php?token=' . urlencode($token);
        $body = '<p>Hello <strong>' . htmlspecialchars($fullName) . '</strong>,</p>'
            . '<p>We received a request to reset your Arnis Connect password. This link works once and expires in 1 hour:</p>'
            . '<div style="text-align:center;margin:24px 0;">'
            . '<a href="' . htmlspecialchars($resetUrl) . '" style="background:#a855f7;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:bold;display:inline-block;">Reset Password</a>'
            . '</div>'
            . '<p style="font-size:14px;color:#aaa;">If you did not ask for this, ignore this email. Your password stays the same.</p>';
        return $this->send($to, 'Reset your Arnis Connect password', $body, null, true);
    }

    /** Plain-text notice; honours the "email notifications" setting. */
    public function sendNotice(string $to, string $fullName, string $subject, string $message): bool
    {
        $body = '<p>Hello <strong>' . htmlspecialchars($fullName) . '</strong>,</p>'
            . '<p>' . nl2br(htmlspecialchars($message)) . '</p>'
            . '<p style="font-size:14px;color:#aaa;">Log in to Arnis Connect for details.</p>';
        return $this->send($to, $subject, $body);
    }
}
`````

### Changed 3/19: `htdocs/classes/Settings.php`

`````php
<?php
declare(strict_types=1);

/**
 * System settings backed by the `settings` table.
 *
 * Defaults are the safe values used when the table has not been migrated yet.
 * Callers should treat every value as a string and cast explicitly.
 */
class Settings
{
    private PDO $conn;

    /** @var array<string,string>|null */
    private static ?array $cache = null;

    public const DEFAULTS = [
        'organization_name' => 'Arnis Connect',
        'contact_email' => '',
        'from_email' => '',
        'from_name' => 'Arnis Connect',
        'allow_registration' => '1',
        'email_notifications' => '1',
        'face_match_threshold' => '0.60',
        'default_geofence_m' => '100',
        'checkin_open_minutes' => '30',
        'late_grace_minutes' => '15',
        'private_session_fee' => '500',
        'privacy_contact' => '',
        'smtp_host' => 'smtp-relay.brevo.com',
        'smtp_port' => '587',
        'smtp_secure' => 'tls',
        'smtp_user' => '',
        'smtp_pass' => '',
    ];

    public function __construct(PDO $db)
    {
        $this->conn = $db;
    }

    /** @return array<string,string> */
    public function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $values = self::DEFAULTS;
        try {
            $stmt = $this->conn->query('SELECT setting_key, setting_value FROM settings');
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $key = (string)($row['setting_key'] ?? '');
                if ($key !== '') {
                    $values[$key] = (string)($row['setting_value'] ?? '');
                }
            }
        } catch (Throwable $e) {
            error_log('Settings::all skipped: ' . $e->getMessage());
        }

        self::$cache = $values;
        return $values;
    }

    public function get(string $key, ?string $default = null): string
    {
        $all = $this->all();
        if (array_key_exists($key, $all)) {
            return $all[$key];
        }
        return $default ?? (self::DEFAULTS[$key] ?? '');
    }

    public function enabled(string $key): bool
    {
        return in_array(strtolower($this->get($key)), ['1', 'true', 'yes', 'on'], true);
    }

    public function set(string $key, string $value, int $updatedBy = 0): bool
    {
        $allowed = array_keys(self::DEFAULTS);
        if (!in_array($key, $allowed, true)) {
            return false;
        }

        try {
            $stmt = $this->conn->prepare(
                'INSERT INTO settings (setting_key, setting_value, updated_by, updated_at)
                 VALUES (:k, :v, :by, NOW())
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value),
                                         updated_by = VALUES(updated_by),
                                         updated_at = NOW()'
            );
            $ok = $stmt->execute([
                ':k' => $key,
                ':v' => $value,
                ':by' => $updatedBy > 0 ? $updatedBy : null,
            ]);
            self::$cache = null;
            return $ok;
        } catch (Throwable $e) {
            error_log('Settings::set failed: ' . $e->getMessage());
            return false;
        }
    }

    /** Convenience for pages that only have a PDO handle. */
    public static function value(PDO $db, string $key, ?string $default = null): string
    {
        return (new self($db))->get($key, $default);
    }

    public static function flag(PDO $db, string $key): bool
    {
        return (new self($db))->enabled($key);
    }
}
`````

### Changed 4/19: `htdocs/config/permissions.php`

`````php
<?php
declare(strict_types=1);

/*
 * SIX-ROLE RBAC MODEL — single source of truth.
 *
 * Keys are kept stable so users.role ENUM needs no risky migration:
 *   SuperAdmin    = Admin (full access; only role that manages accounts)
 *   Head Coach    = Head Coach (oversight of members / trainings / events)
 *   Coach/Trainer = Coach (own trainees / own sessions only)
 *   Officer       = Finance (funds + finance reporting; read-only elsewhere)
 *   Member        = Member / Player (own data only)
 *   Applicant     = Applicant (own registration only)
 *
 * Committee / Board Member are RETIRED. Any legacy rows resolve to `false`
 * (fail closed -> 403 + empty nav). Run the SQL below once:
 *   UPDATE users SET role='Member' WHERE role IN ('Committee','Board Member');
 *
 * SECURITY NOTES
 * - `hasPermission()` only answers "is this module granted at all". A scope
 *   string ('own', 'own_trainees', 'own_events') is NOT a full grant: every
 *   page/action must resolve it against the record. See includes/authorize.php
 *   canPerformAction() and the page-level guards.
 * - Only SuperAdmin holds users.write / users.delete === true, so only Admin
 *   can create accounts, change roles, deactivate/archive, or approve
 *   registration requests.
 * - Officer is deliberately limited to finance operations. Finance may READ
 *   members/trainings/events to reconcile payments, but may not create,
 *   edit, void, or take attendance.
 * - Reports and funds are visible to SuperAdmin + Officer only.
 * - Members/Applicants never receive the member directory (members.read=false);
 *   they reach their own profile through member_profile.php's self-service
 *   branch, which is authorized by users.member_id.
 */
return [
    'members' => [
        'SuperAdmin' => ['read' => true, 'write' => true, 'delete' => true],
        'Head Coach' => ['read' => true, 'write' => 'own_trainees', 'delete' => false],
        'Officer' => ['read' => true, 'write' => false, 'delete' => false],
        'Coach/Trainer' => ['read' => 'own_trainees', 'write' => 'own_trainees', 'delete' => false],
        'Member' => ['read' => false, 'write' => 'own', 'delete' => false],
        'Applicant' => ['read' => false, 'write' => false, 'delete' => false],
    ],
    'trainings' => [
        'SuperAdmin' => ['read' => true, 'write' => true, 'delete' => true],
        'Head Coach' => ['read' => true, 'write' => 'own', 'delete' => false],
        'Officer' => ['read' => true, 'write' => false, 'delete' => false],
        'Coach/Trainer' => ['read' => true, 'write' => 'own', 'delete' => false],
        'Member' => ['read' => 'own', 'write' => false, 'delete' => false],
        'Applicant' => ['read' => false, 'write' => false, 'delete' => false],
    ],
    'attendance' => [
        'SuperAdmin' => ['read' => true, 'write' => true, 'delete' => true],
        'Head Coach' => ['read' => 'own_trainees', 'write' => 'own_trainees', 'delete' => false],
        'Officer' => ['read' => false, 'write' => false, 'delete' => false],
        'Coach/Trainer' => ['read' => 'own_trainees', 'write' => 'own_trainees', 'delete' => false],
        'Member' => ['read' => 'own', 'write' => 'own', 'delete' => false],
        'Applicant' => ['read' => false, 'write' => false, 'delete' => false],
    ],
    'events' => [
        'SuperAdmin' => ['read' => true, 'write' => true, 'delete' => true],
        'Head Coach' => ['read' => true, 'write' => 'own', 'delete' => false],
        'Officer' => ['read' => true, 'write' => false, 'delete' => false],
        'Coach/Trainer' => ['read' => 'own', 'write' => 'own', 'delete' => false],
        'Member' => ['read' => true, 'write' => false, 'delete' => false],
        'Applicant' => ['read' => false, 'write' => false, 'delete' => false],
    ],
    'event_participants' => [
        'SuperAdmin' => ['read' => true, 'write' => true, 'delete' => true],
        'Head Coach' => ['read' => 'own_events', 'write' => 'own_events', 'delete' => false],
        'Officer' => ['read' => true, 'write' => false, 'delete' => false],
        'Coach/Trainer' => ['read' => 'own_events', 'write' => 'own_events', 'delete' => false],
        'Member' => ['read' => 'own', 'write' => false, 'delete' => false],
        'Applicant' => ['read' => false, 'write' => false, 'delete' => false],
    ],
    'event_documents' => [
        'SuperAdmin' => ['read' => true, 'write' => true, 'delete' => true],
        'Head Coach' => ['read' => 'own_events', 'write' => 'own_events', 'delete' => false],
        'Officer' => ['read' => true, 'write' => false, 'delete' => false],
        'Coach/Trainer' => ['read' => 'own_events', 'write' => 'own_events', 'delete' => false],
        'Member' => ['read' => 'own', 'write' => false, 'delete' => false],
        'Applicant' => ['read' => false, 'write' => false, 'delete' => false],
    ],
    'achievements' => [
        'SuperAdmin' => ['read' => true, 'write' => true, 'delete' => true],
        'Head Coach' => ['read' => 'own_trainees', 'write' => 'own_trainees', 'delete' => false],
        'Officer' => ['read' => false, 'write' => false, 'delete' => false],
        'Coach/Trainer' => ['read' => 'own_trainees', 'write' => 'own_trainees', 'delete' => false],
        'Member' => ['read' => 'own', 'write' => false, 'delete' => false],
        'Applicant' => ['read' => false, 'write' => false, 'delete' => false],
    ],
    'funds' => [
        'SuperAdmin' => ['read' => true, 'write' => true, 'delete' => true],
        'Head Coach' => ['read' => false, 'write' => false, 'delete' => false],
        'Officer' => ['read' => true, 'write' => true, 'delete' => true],
        'Coach/Trainer' => ['read' => false, 'write' => false, 'delete' => false],
        'Member' => ['read' => false, 'write' => false, 'delete' => false],
        'Applicant' => ['read' => false, 'write' => false, 'delete' => false],
    ],
    'users' => [
        'SuperAdmin' => ['read' => true, 'write' => true, 'delete' => true],
        'Head Coach' => ['read' => false, 'write' => false, 'delete' => false],
        'Officer' => ['read' => false, 'write' => false, 'delete' => false],
        'Coach/Trainer' => ['read' => false, 'write' => false, 'delete' => false],
        'Member' => ['read' => false, 'write' => false, 'delete' => false],
        'Applicant' => ['read' => false, 'write' => false, 'delete' => false],
    ],
    'reports' => [
        'SuperAdmin' => ['read' => true, 'write' => false, 'delete' => false],
        'Head Coach' => ['read' => false, 'write' => false, 'delete' => false],
        'Officer' => ['read' => true, 'write' => false, 'delete' => false],
        'Coach/Trainer' => ['read' => false, 'write' => false, 'delete' => false],
        'Member' => ['read' => false, 'write' => false, 'delete' => false],
        'Applicant' => ['read' => false, 'write' => false, 'delete' => false],
    ],
    'announcements' => [
        'SuperAdmin' => ['read' => true, 'write' => true, 'delete' => true],
        'Head Coach' => ['read' => true, 'write' => true, 'delete' => false],
        'Officer' => ['read' => true, 'write' => false, 'delete' => false],
        'Coach/Trainer' => ['read' => true, 'write' => false, 'delete' => false],
        'Member' => ['read' => true, 'write' => false, 'delete' => false],
        'Applicant' => ['read' => false, 'write' => false, 'delete' => false],
    ],
];
`````

### Changed 5/19: `htdocs/includes/sidebar.php`

`````php
<?php
/**
 * Sidebar navigation.
 *
 * Visibility is derived from config/permissions.php through includes/authorize.php
 * so the menu can never drift away from what the target page actually allows.
 * A null session role fails CLOSED (no items) instead of defaulting to a role.
 */
require_once __DIR__ . '/../includes/authorize.php';

$current_page = basename((string)($_SERVER['PHP_SELF'] ?? ''));

/*
 * No role fallback. The previous `$_SESSION['role'] ?? 'Coach/Trainer'` handed
 * the full Coach/Trainer navigation to any session whose role key was missing -
 * a missing-guard bug anywhere would become a UI leak instead of a blank page.
 */
$userRole = currentUserRole();
if ($userRole === null) {
    $userRole = '';
}

$userRoleLabel = '';
$roleConfig    = $permissions['__roles'] ?? null;
$rolesFile     = require __DIR__ . '/../config/roles.php';
if (isset($rolesFile[$userRole]['label'])) {
    $userRoleLabel = (string)$rolesFile[$userRole]['label'];
}

/*
 * Menu definition.
 *   module === null  -> visible to every authenticated role (Dashboard)
 *   otherwise -> visible when hasPermission($module, $action)
 *   highlights -> extra pages that should light up this menu entry
 */
$menu_items = [
    [
        'label'      => 'Dashboard',
        'file'       => 'dashboard.php',
        'icon'       => 'fas fa-dashboard',
        'module'     => null,
        'action'     => 'read',
        'highlights' => [],
    ],
    [
        'label'      => 'Members',
        'file'       => 'members_management.php',
        'icon'       => 'fas fa-users',
        'module'     => 'members',
        'action'     => 'read',
        'highlights' => [],
    ],
    [
        'label'      => 'Trainings',
        'file'       => 'trainings.php',
        'icon'       => 'fas fa-book-open',
        'module'     => 'trainings',
        'action'     => 'read',
        'highlights' => ['attendance_session.php'],
    ],
    [
        'label'      => 'Private Training',
        'file'       => 'private_trainings.php',
        'icon'       => 'fas fa-user-ninja',
        'module'     => null,
        'action'     => 'read',
        'roles'      => ['SuperAdmin', 'Head Coach', 'Coach/Trainer', 'Officer', 'Member'],
        'highlights' => [],
    ],
    [
        'label'      => 'Attendance Report',
        'file'       => 'attendance_report.php',
        'icon'       => 'fas fa-chart-line',
        'module'     => null,
        'action'     => 'read',
        'roles'      => ['SuperAdmin', 'Head Coach', 'Coach/Trainer', 'Member'],
        'highlights' => [],
    ],
    [
        'label'      => 'Events',
        'file'       => 'events.php',
        'icon'       => 'far fa-calendar-alt',
        'module'     => 'events',
        'action'     => 'read',
        'highlights' => ['event_detail.php'],
    ],
    [
        'label'      => 'Funds',
        'file'       => 'funds.php',
        'icon'       => 'fas fa-wallet',
        'module'     => 'funds',
        'action'     => 'read',
        'highlights' => [],
    ],
    [
        'label'      => 'Users',
        'file'       => 'users.php',
        'icon'       => 'fas fa-user',
        'module'     => 'users',
        'action'     => 'read',
        'highlights' => [],
    ],
    [
        'label'      => 'Announcements',
        'file'       => 'announcements.php',
        'icon'       => 'fas fa-bullhorn',
        'module'     => 'announcements',
        'action'     => 'read',
        'highlights' => [],
    ],
    [
        'label'      => 'Reports',
        'file'       => 'reports.php',
        'icon'       => 'fas fa-chart-bar',
        'module'     => 'reports',
        'action'     => 'read',
        'highlights' => [],
    ],
    [
        'label'      => 'Activity Log',
        'file'       => 'activity_log.php',
        'icon'       => 'fas fa-clipboard-list',
        'module'     => null,
        'action'     => 'read',
        'roles'      => ['SuperAdmin'],
        'highlights' => [],
    ],
    [
        'label'      => 'Settings',
        'file'       => 'settings.php',
        'icon'       => 'fas fa-gear',
        'module'     => null,
        'action'     => 'read',
        'roles'      => ['SuperAdmin'],
        'highlights' => [],
    ],
];

$visible_items = [];
foreach ($menu_items as $item) {
    if ($userRole === '') {
        break; // fail closed
    }

    if (isset($item['roles']) && (!is_array($item['roles']) || !in_array($userRole, $item['roles'], true))) {
        continue;
    }

    if ($item['module'] === null || hasPermission($item['module'], $item['action'])) {
        $visible_items[] = $item;
    }
}

/*
 * Members have no other route to their own member profile, which is where face
 * enrollment lives (the "View/Edit Profile" user-menu item is the account
 * modal). Surface it right under Dashboard. Prefer the linked member id.
 * Don't add My Profile to sidebar for Applicant.
 */
$selfMemberId = (isset($_SESSION['member_id']) && $_SESSION['member_id'] !== null && (int)$_SESSION['member_id'] > 0)
    ? (int)$_SESSION['member_id']
    : 0;
$sidebarRole = currentUserRole() ?? '';

if ($sidebarRole !== 'Applicant') {
    if ($selfMemberId > 0 || $sidebarRole === 'Member') {
        array_splice($visible_items, 1, 0, [[
            'label'      => 'My Profile',
            'file'       => $selfMemberId > 0 ? 'member_profile.php?id=' . $selfMemberId : 'member_profile.php',
            'icon'       => 'fas fa-id-badge',
            'highlights' => ['member_profile.php'],
        ]]);
    }
}

// Hide Members and Users from Applicant - keep only Dashboard for Applicant
$visible_items = array_values(array_filter($visible_items, function($item) use ($sidebarRole) {
    if ($sidebarRole === 'Applicant') {
        return $item['module'] === null;
    }
    return true;
}));

/*
 * member_profile.php is reachable from BOTH "Members" (someone else's
 * profile) and "My Profile" (your own). Previously both entries listed it in
 * `highlights`, so both lit up at once. Highlight exactly one based on whose
 * profile is open.
 */
$profileId = (isset($_GET['id']) && is_numeric($_GET['id'])) ? (int)$_GET['id'] : 0;
$isSelfProfilePage = ($current_page === 'member_profile.php'
    && $selfMemberId > 0
    && $profileId === $selfMemberId);

foreach ($visible_items as &$navItem) {
    if (($navItem['label'] ?? '') === 'My Profile') {
        $navItem['highlights'] = $isSelfProfilePage ? ['member_profile.php'] : [];
    } elseif (($navItem['label'] ?? '') === 'Members') {
        $navItem['highlights'] = ($current_page === 'member_profile.php' && !$isSelfProfilePage)
            ? ['member_profile.php']
            : [];
    }
}
unset($navItem);

?>

<aside class="w-64 bg-sidebar-dark pt-4 px-3 pb-0 flex flex-col h-screen sidebar" id="sidebar">

    <div class="flex items-center pb-3 mb-3">
        <img src="../arnis-logo.png" alt="Arnis Connect" class="w-50 h-auto">
    </div>

    <div class="bg-sidebar-cont rounded-t-3xl rounded-b-none flex flex-col flex-grow relative overflow-hidden">

        <nav class="p-2 flex-grow">
            <ul>
                <?php if (empty($visible_items)): ?>
                    <li class="px-3 py-4 text-[10px] text-gray-500 leading-relaxed">
                        Navigation unavailable. Please sign in again.
                    </li>
                <?php endif; ?>

                <?php foreach ($visible_items as $item):
                    $file  = (string)$item['file'];
                    $label = (string)$item['label'];
                    $icon  = (string)$item['icon'];

                    $highlightFiles = array_merge([$file], (array)$item['highlights']);
                    $is_active = in_array($current_page, $highlightFiles, true);

                    $active_classes = $is_active
                        ? 'text-active-link bg-active-link translate-x-2'
                        : 'text-light-gray';
                ?>
                    <li class="mb-3 relative">
                        <a href="<?= htmlspecialchars($file, ENT_QUOTES, 'UTF-8') ?>"
                           title="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>"
                           <?= $is_active ? 'aria-current="page"' : '' ?>
                           class="nav-link flex items-center p-3 rounded-lg hover:bg-gray-700/50 transition duration-200 <?= htmlspecialchars($active_classes, ENT_QUOTES, 'UTF-8') ?>">
                            <i class="<?= htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?> mr-4 w-5 text-center"></i>
                            <span class="sidebar-text"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </nav>

        <?php if ($userRoleLabel !== ''): ?>
            <div class="px-3 pb-4 sidebar-text">
                <div class="text-[9px] uppercase tracking-widest text-gray-500">Signed in as</div>
                <div class="text-[11px] font-bold text-gray-300 truncate" title="<?= htmlspecialchars($userRoleLabel, ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars($userRoleLabel, ENT_QUOTES, 'UTF-8') ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <button type="button" id="toggle-btn" class="toggle-btn" aria-label="Collapse sidebar">
        <i class="fas fa-chevron-left"></i>
    </button>
</aside>

<script>
(function () {
    'use strict';

    const STORAGE_KEY = 'arnis_sidebar_collapsed';

    const sidebar = document.getElementById('sidebar');
    const toggleBtn = document.getElementById('toggle-btn');

    if (!sidebar || !toggleBtn) {
        return;
    }

    const toggleIcon = toggleBtn.querySelector('i');

    const applyState = function (collapsed) {
        sidebar.classList.toggle('collapsed', collapsed);
        toggleBtn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');

        if (!toggleIcon) {
            return;
        }

        toggleIcon.classList.toggle('fa-chevron-left', !collapsed);
        toggleIcon.classList.toggle('fa-chevron-right', collapsed);
    };

    let stored = null;
    try {
        stored = window.localStorage.getItem(STORAGE_KEY);
    } catch (e) {
        stored = null;
    }
    applyState(stored === '1');

    toggleBtn.addEventListener('click', function () {
        const collapsed = sidebar.classList.toggle('collapsed');
        toggleBtn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');

        if (toggleIcon) {
            toggleIcon.classList.toggle('fa-chevron-left', !collapsed);
            toggleIcon.classList.toggle('fa-chevron-right', collapsed);
        }

        try {
            window.localStorage.setItem(STORAGE_KEY, collapsed ? '1' : '0');
        } catch (e) {
            /* storage unavailable - toggle still works for this page view */
        }
    });
})();
</script>
`````

### Changed 6/19: `htdocs/index.php`

`````php
<?php
require_once __DIR__ . '/config/session.php';
arnisStartSession();

if (isset($_SESSION['user_id'])) {
    header("Location: public/dashboard.php");
    exit;
}

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");
header("X-Content-Type-Options: nosniff");

$errorMessage = '';
$showResendLink = false;
$resetDone = isset($_GET['reset']) && $_GET['reset'] === '1';
if (isset($_GET['error']) && is_string($_GET['error'])) {
    switch ($_GET['error']) {
        case 'empty':
            $errorMessage = 'Please enter both username and password.';
            break;
        case 'invalid':
            $errorMessage = 'Invalid username/email or password.';
            break;
        case 'inactive':
            $errorMessage = 'This account is inactive. Please contact an administrator.';
            break;
        case 'unverified':
            $errorMessage = 'Please verify your email address before signing in.';
            $showResendLink = true;
            break;
        case 'timeout':
            $errorMessage = 'Your session expired due to inactivity. Please log in again.';
            break;
        case 'session':
            $errorMessage = 'Your session is no longer valid. Please log in again.';
            break;
        case 'security':
            $errorMessage = 'The security token is invalid or expired. Please try again.';
            break;
        case 'throttled':
            $errorMessage = 'Too many failed attempts. Please wait 90 seconds and try again.';
            break;
        case 'service':
            $errorMessage = 'Login is temporarily unavailable. Please try again later.';
            break;
    }
}
?>

<!DOCTYPE html>
<html lang="en" class="text-[13px]">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | Arnis Connect</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" type="image/x-icon" href="arnis-favicon.ico">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,100..900;1,100..900&display=swap" rel="stylesheet">
    <style>
        :root { --dark-purple: #1d212a; --card-bg: #161a23; }
        body { font-family: 'Montserrat', sans-serif; background-color: var(--dark-purple); }
        .card-bg { background-color: var(--card-bg); border: 1px solid rgba(255,255,255,0.05); }
        .login-input { background: #1d212a; border: 1px solid transparent; transition: all 0.3s; }
        .login-input:focus { border-color: #a855f7; outline: none; box-shadow: 0 0 15px rgba(168, 85, 247, 0.1); }
    </style>
</head>

<body class="flex items-center justify-center min-h-screen p-4 overflow-hidden">

    <div class="fixed top-[-10%] left-[-10%] w-[40%] h-[40%] bg-purple-600/10 blur-[120px] rounded-full"></div>
    <div class="fixed bottom-[-10%] right-[-10%] w-[40%] h-[40%] bg-blue-600/10 blur-[120px] rounded-full"></div>

    <main id="loginContent" class="w-full max-w-[400px] opacity-0 translate-y-8 transition-all duration-700 ease-out z-10">

        <?php if ($resetDone): ?>
            <div class="mb-4 p-3 rounded-xl bg-emerald-500/15 border border-emerald-400/40 text-emerald-300 text-[11px] font-bold text-center">
                <i class="fas fa-check-circle mr-2"></i> Your password was changed. Log in with your new password.
            </div>
        <?php endif; ?>
        <?php if ($errorMessage !== ''): ?>
            <div class="mb-4 p-3 rounded-xl bg-red-500/20 border border-red-400 text-red-300 text-[11px] font-bold text-center">
                <i class="fas fa-exclamation-triangle mr-2"></i> <?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <?php if ($showResendLink): ?>
            <div class="mb-4 text-center">
                <a href="resend_verification.php" class="text-[11px] uppercase tracking-[0.16em] font-black text-purple-400 hover:text-white transition">
                    <i class="fas fa-paper-plane mr-2"></i>Resend verification email
                </a>
            </div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-purple-500/10 text-purple-500 border border-purple-500/20 mb-4 shadow-lg shadow-purple-900/20">
                <i class="fas fa-shield-halved text-2xl"></i>
            </div>
            <h1 class="text-2xl font-black uppercase tracking-tighter italic text-white">Arnis <span class="text-purple-500">Connect</span></h1>
            <div class="flex items-center justify-center gap-2 mt-2">
                <span class="h-[1px] w-8 bg-gray-800"></span>
                <p class="text-[10px] text-gray-500 uppercase font-bold tracking-[0.2em]">Staff login and public member registration</p>
                <span class="h-[1px] w-8 bg-gray-800"></span>
            </div>
        </div>

        <div class="card-bg p-8 rounded-[2.5rem] shadow-2xl relative overflow-hidden">
            <div class="absolute top-0 left-0 w-full h-1 bg-gradient-to-r from-transparent via-purple-500 to-transparent opacity-50"></div>

            <form action="classes/process_login.php" method="POST" class="space-y-6">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(arnisCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">

                <div class="space-y-2">
                    <label class="text-[10px] text-gray-500 uppercase font-black tracking-widest ml-1">Username or Email</label>
                    <div class="relative">
                        <i class="fas fa-user absolute left-4 top-1/2 -translate-y-1/2 text-gray-600 text-xs"></i>
                        <input type="text" name="username" required
                            placeholder="Enter your username or email"
                            class="login-input w-full py-4 pl-12 pr-4 rounded-2xl text-white text-sm placeholder:text-gray-700">
                    </div>
                </div>

                <div class="space-y-2">
                    <div class="flex justify-between items-center ml-1">
                        <label class="text-[10px] text-gray-500 uppercase font-black tracking-widest">Password</label>
                        <a href="forgot_password.php" class="text-[10px] text-purple-400 hover:text-purple-300 uppercase font-black tracking-widest">Forgot password?</a>
                    </div>

                    <div class="relative">
                        <i class="fas fa-lock absolute left-4 top-1/2 -translate-y-1/2 text-gray-600 text-xs"></i>

                        <input
                            id="passwordInput"
                            type="password"
                            name="password"
                            required
                            placeholder="••••••••"
                            class="login-input w-full py-4 pl-12 pr-12 rounded-2xl text-white text-sm placeholder:text-gray-700"
                        >

                        <button
                            type="button"
                            onclick="togglePassword()"
                            class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-600 hover:text-gray-400 transition"
                        >
                            <i id="passwordIcon" class="fas fa-eye text-xs"></i>
                        </button>
                    </div>
                </div>

                <button type="submit"
                    class="w-full bg-purple-600 hover:bg-purple-500 text-white font-black uppercase text-[11px] tracking-[0.2em] py-5 rounded-2xl transition-all active:scale-95 shadow-lg shadow-purple-900/40 flex items-center justify-center gap-3">
                    Log In
                    <i class="fas fa-arrow-right text-[10px]"></i>
                </button>
            </form>
        </div>

        <div class="mt-6 rounded-2xl bg-white/[0.03] border border-white/5 p-4 flex flex-col sm:flex-row items-center justify-between gap-3">
            <div>
                <p class="text-[10px] uppercase tracking-[0.2em] font-black text-gray-500">New member applicant?</p>
                <p class="text-xs text-gray-400 mt-1">Register as a public or private player and wait for approval.</p>
            </div>
            <a href="register.php" class="px-4 py-3 rounded-2xl bg-white/5 hover:bg-purple-500 hover:text-white transition text-[11px] font-black uppercase tracking-[0.16em] text-purple-300">Register as Member</a>
        </div>

        <div class="mt-8 text-center space-y-4">
            <p class="text-gray-600 text-[9px] uppercase tracking-widest">
                &copy; 2026 Lapu-Lapu Arnis de Abanico. All rights reserved.
            </p>
        </div>
    </main>

    <script>
        window.addEventListener("load", () => {
            const content = document.getElementById("loginContent");
            content.classList.remove("opacity-0", "translate-y-8");
        });
    </script>

    <script>
        if (window.history && window.history.pushState) {
            window.history.pushState(null, null, window.location.href);
            window.onpopstate = function () {
                window.location.href = 'index.php';
            };
        }

        function togglePassword() {
            const input = document.getElementById('passwordInput');
            const icon = document.getElementById('passwordIcon');

            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
    </script>

</body>
</html>
/*
* Login Page
* @author: John Carlo M. Dela Cruz
* @date: 2023-11-28
* @version: 1.0
* @description: This is the login page of the system.
* @note: This is the login page of the system.
*/
`````

### Changed 7/19: `htdocs/actions/download_document.php`

`````php
<?php
declare(strict_types=1);

/*
 * EVENT DOCUMENT DOWNLOAD — public proxy for Cloudinary files.
 *
 * Fix revision:
 *  - The old version appended `?fl_attachment` to the Cloudinary URL and
 *    fetched with `follow_location => 0`. Cloudinary answers that with a
 *    redirect / non-2xx status, so EVERY receipt click died with
 *    {"success":false,"error":"Failed to retrieve the file from storage."}.
 *  - This version fetches the RAW stored URL, FOLLOWS redirects (max 5),
 *    and on any fetch failure falls back to a plain browser redirect to the
 *    Cloudinary URL instead of a JSON error — so a click always opens the
 *    file as long as the stored URL itself is valid.
 *  - RBAC is unchanged: event_documents.read scope ('own_events' / 'own')
 *    is enforced before any byte is sent.
 *  - JSON errors are now only returned for: bad id, no permission,
 *    missing/voided document, or a stored URL that is not https Cloudinary.
 */

require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';

/** Hard ceiling on a single proxied download, in bytes. */
const ARNIS_DOC_MAX_BYTES = 26214400; // 25 MB

/** Only these Cloudinary hosts may be fetched server-side (SSRF guard). */
const ARNIS_DOC_ALLOWED_HOST_SUFFIX = 'cloudinary.com';

function arnisDocJsonError(string $message, int $status = 400): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

/*
 * The stored value must be an https Cloudinary URL, max 255 chars
 * (event_documents.file_path is varchar(255)). Returns the URL untouched —
 * no query flags appended. Cloudinary honours `fl_attachment` only as a
 * delivery transformation in the path, never as a query string.
 */
function arnisDocValidateUrl(string $filePath): ?string
{
    $filePath = trim($filePath);
    if ($filePath === '' || mb_strlen($filePath) > 255) {
        return null;
    }

    $parts = parse_url($filePath);
    if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
        return null;
    }

    if (strtolower($parts['scheme']) !== 'https') {
        return null;
    }

    $host = strtolower($parts['host']);
    $suffix = ARNIS_DOC_ALLOWED_HOST_SUFFIX;
    if ($host !== $suffix && !str_ends_with($host, '.' . $suffix)) {
        return null;
    }

    return $filePath;
}

/** Safe attachment filename derived from the document type, not the URL. */
function arnisDocBuildFilename(int $documentId, string $fileType): string
{
    $extensionByType = [
        'Image' => 'jpg',
        'Video' => 'mp4',
        'Receipt' => 'pdf',
        'Note' => 'txt',
    ];
    $extension = $extensionByType[$fileType] ?? 'bin';
    return 'arnis_document_' . $documentId . '.' . $extension;
}

/*
 * Fetch the remote file. Returns ['body', 'content_type'] on success,
 * null on any failure (network blocked, non-2xx, oversize). cURL is
 * preferred (InfinityFree often disables allow_url_fopen); both paths
 * FOLLOW redirects because Cloudinary delivery URLs commonly 302.
 */
function arnisDocFetch(string $url): ?array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'ArnisConnect/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $size = function_exists('curl_getinfo') ? (int)curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD) : 0;
        curl_close($ch);

        if (!is_string($body) || $body === '' || $httpCode < 200 || $httpCode >= 300) {
            error_log('download_document: curl fetch failed HTTP ' . $httpCode . ' for ' . $url);
            return null;
        }
        if (($size > 0 && $size > ARNIS_DOC_MAX_BYTES) || strlen($body) > ARNIS_DOC_MAX_BYTES) {
            error_log('download_document: upstream file exceeds 25 MB for ' . $url);
            return null;
        }
        return ['body' => $body, 'content_type' => $contentType];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 20,
            'follow_location' => 1,
            'max_redirects' => 5,
            'ignore_errors' => true,
            'header' => "Accept: */*\r\nUser-Agent: ArnisConnect/1.0\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    if (!is_string($body) || $body === '') {
        error_log('download_document: fopen fetch failed for ' . $url);
        return null;
    }

    $status = 200;
    $contentType = '';
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $headerLine) {
            if (stripos($headerLine, 'HTTP/') === 0) {
                $status = (int)trim(substr($headerLine, 5, 3));
            } elseif (stripos($headerLine, 'Content-Type:') === 0) {
                $contentType = trim(substr($headerLine, 13));
            }
        }
    }
    if ($status < 200 || $status >= 300) {
        error_log('download_document: upstream status ' . $status . ' for ' . $url);
        return null;
    }
    if (strlen($body) > ARNIS_DOC_MAX_BYTES) {
        error_log('download_document: upstream file exceeds 25 MB for ' . $url);
        return null;
    }
    return ['body' => $body, 'content_type' => $contentType];
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    arnisDocJsonError('Invalid document ID');
}

$documentId = (int)$_GET['id'];
if ($documentId <= 0) {
    arnisDocJsonError('Invalid document ID');
}

try {
    $readPerm = permissionValue('event_documents', 'read');
    if ($readPerm === false) {
        arnisDocJsonError('You do not have permission to download event documents.', 403);
    }

    $db = (new Database())->connect();

    $stmt = $db->prepare(
        "SELECT d.document_id, d.event_id, d.file_path, d.file_type,
                e.event_name, e.created_by AS event_created_by, e.handler_id
         FROM event_documents d
         JOIN events e ON e.event_id = d.event_id
         WHERE d.document_id = :id
           AND d.is_voided = 0
           AND e.is_voided = 0
         LIMIT 1"
    );
    $stmt->execute([':id' => $documentId]);
    $document = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$document) {
        arnisDocJsonError('Document not found', 404);
    }

    /*
     * Scoped read permissions ('own_events' for Coach, 'own' for Member)
     * are enforced here rather than in the UI, otherwise the id is
     * guessable and any session could iterate document_id values.
     */
    if ($readPerm === 'own_events' || $readPerm === 'own') {
        $isParticipant = false;
        $viewerMemberId = function_exists('resolveSessionMemberId') ? resolveSessionMemberId($db) : (int)($_SESSION['member_id'] ?? 0);

        if ($viewerMemberId > 0) {
            $check = $db->prepare(
                "SELECT participant_id
                 FROM event_participants
                 WHERE event_id = :event_id
                   AND member_id = :member_id
                   AND is_voided = 0
                 LIMIT 1"
            );
            $check->execute([
                ':event_id' => (int)$document['event_id'],
                ':member_id' => $viewerMemberId,
            ]);
            $isParticipant = (bool)$check->fetch();
        }

        $isEventOwner = ((int)($document['event_created_by'] ?? 0) > 0
                && (int)$document['event_created_by'] === (int)($_SESSION['user_id'] ?? 0))
            || ($readPerm === 'own_events' && $viewerMemberId > 0
                && (int)($document['handler_id'] ?? 0) === $viewerMemberId);

        if (!$isParticipant && !$isEventOwner) {
            arnisDocJsonError('You do not have permission to download this document.', 403);
        }
    }

    $fileUrl = arnisDocValidateUrl((string)$document['file_path']);
    if ($fileUrl === null) {
        arnisDocJsonError('Stored file location is not a valid download source.', 422);
    }

    $fetched = arnisDocFetch($fileUrl);

    if ($fetched === null) {
        /*
         * Server-side fetch failed (common on InfinityFree: outbound HTTP
         * blocked or Cloudinary edge refused the proxy). The stored URL
         * itself is valid and permission-checked, so send the browser
         * straight to it instead of showing a JSON error.
         */
        header('Location: ' . $fileUrl);
        exit;
    }

    $fileType = (string)($document['file_type'] ?? '');
    $upstreamType = strtolower(trim(explode(';', $fetched['content_type'])[0]));

    if ($fileType === 'Image' && str_starts_with($upstreamType, 'image/')) {
        header('Content-Type: ' . $fetched['content_type']);
        header('Content-Disposition: inline; filename="'
            . arnisDocBuildFilename($documentId, $fileType) . '"');
    } else {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="'
            . arnisDocBuildFilename($documentId, $fileType) . '"');
    }
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . strlen($fetched['body']));
    header('Expires: 0');
    header('Cache-Control: private, no-store, must-revalidate, max-age=0');
    header('Pragma: private');

    echo $fetched['body'];
    exit;
} catch (PDOException $e) {
    error_log('download_document DB error: ' . $e->getMessage());
    arnisDocJsonError('Database error.', 500);
} catch (Throwable $e) {
    error_log('download_document error: ' . $e->getMessage());
    arnisDocJsonError('Failed to download document.', 500);
}
`````

### Changed 8/19: `htdocs/actions/export_backup.php`

`````php
<?php
declare(strict_types=1);

/*
 * FULL SYSTEM BACKUP DOWNLOAD — SuperAdmin only.
 * Dumps all 10 data tables to a JSON file the browser downloads.
 * Keep this file: it is what "Restore" feeds back into import_backup.php.
 * Pure PDO (no mysqldump — unavailable on InfinityFree).
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/PrivateTrainings.php';

const ARNIS_BACKUP_TABLES = [
    'users',
    'members',
    'rank_history',
    'rank_verification_history',
    'registration_requests',
    'trainings',
    'attendance',
    'attendance_checkins',
    'events',
    'event_participants',
    'event_documents',
    'achievements',
    'funds',
    'private_training_requests',
];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Invalid request method.');
}

if (!arnisVerifyCsrfToken($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid security token.');
}

if (!hasRole('SuperAdmin')) {
    http_response_code(403);
    exit('Access denied.');
}

try {
    $db = (new Database())->connect();
    PrivateTrainings::ensureSchema($db);

    $payload = [
        'app' => 'arnis-connect',
        'version' => 1,
        'exported_at' => date('Y-m-d H:i:s'),
        'exported_by' => (int)($_SESSION['user_id'] ?? 0),
        'tables' => [],
    ];

    foreach (ARNIS_BACKUP_TABLES as $table) {
        $rows = $db->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
        $payload['tables'][$table] = $rows ?: [];
    }

    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        throw new RuntimeException('Backup encoding failed.');
    }

    $filename = 'arnis-backup-' . date('Ymd-His') . '.json';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($json));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store, max-age=0');
    echo $json;
    exit;
} catch (Throwable $e) {
    error_log('export_backup error: ' . $e->getMessage());
    http_response_code(500);
    exit('Backup failed. Please try again.');
}
`````

### Changed 9/19: `htdocs/actions/import_backup.php`

`````php
<?php
declare(strict_types=1);

/*
 * RESTORE FROM BACKUP FILE — SuperAdmin only.
 * Accepts the JSON file produced by export_backup.php, validates its full
 * structure FIRST, then wipes all tables and re-inserts every row
 * (ids preserved, so member/training/event links survive).
 * 20 MB upload cap. Always download a fresh backup before restoring.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/PrivateTrainings.php';

const ARNIS_RESTORE_TABLES = [
    'users',
    'members',
    'rank_history',
    'rank_verification_history',
    'registration_requests',
    'trainings',
    'attendance',
    'attendance_checkins',
    'events',
    'event_participants',
    'event_documents',
    'achievements',
    'funds',
    'private_training_requests',
];

/* Tables that may be absent from older backup files without failing restore. */
const ARNIS_RESTORE_OPTIONAL_TABLES = [
    'rank_history',
    'rank_verification_history',
    'attendance_checkins',
    'private_training_requests',
];

const ARNIS_RESTORE_MAX_BYTES = 20971520; // 20 MB

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

if (!arnisVerifyCsrfToken($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
    exit;
}

if (!hasRole('SuperAdmin')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit;
}

try {
    if (!isset($_FILES['backup_file']) || !is_array($_FILES['backup_file'])
        || ($_FILES['backup_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No backup file uploaded.']);
        exit;
    }

    $tmpPath = (string)$_FILES['backup_file']['tmp_name'];
    $size = (int)$_FILES['backup_file']['size'];
    if ($size <= 0 || $size > ARNIS_RESTORE_MAX_BYTES) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Backup file must be 1 byte to 20 MB.']);
        exit;
    }

    $raw = file_get_contents($tmpPath);
    if ($raw === false || $raw === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Could not read the uploaded file.']);
        exit;
    }

    $data = json_decode($raw, true);
    if (!is_array($data) || ($data['app'] ?? '') !== 'arnis-connect'
        || !isset($data['tables']) || !is_array($data['tables'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Not a valid Arnis backup file.']);
        exit;
    }

    /* Validate everything BEFORE touching the database.
     * The optional tables are allowed to be missing so backups taken before
     * they existed still load. */
    foreach (ARNIS_RESTORE_TABLES as $table) {
        if (!array_key_exists($table, $data['tables'])) {
            if (in_array($table, ARNIS_RESTORE_OPTIONAL_TABLES, true)) {
                $data['tables'][$table] = [];
                continue;
            }
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Backup is missing table '{$table}'."]);
            exit;
        }
        if (!is_array($data['tables'][$table])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Backup table '{$table}' is corrupt."]);
            exit;
        }
        foreach ($data['tables'][$table] as $row) {
            if (!is_array($row)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => "Backup table '{$table}' is corrupt."]);
                exit;
            }
        }
    }

    $db = (new Database())->connect();
    PrivateTrainings::ensureSchema($db);
    $db->exec('SET FOREIGN_KEY_CHECKS = 0');

    try {
        /* Wipe first (TRUNCATE also resets AUTO_INCREMENT for clean ids). */
        foreach (ARNIS_RESTORE_TABLES as $table) {
            $db->exec("TRUNCATE TABLE `{$table}`");
        }

        $restored = [];
        foreach (ARNIS_RESTORE_TABLES as $table) {
            $rows = $data['tables'][$table];
            $count = 0;
            foreach ($rows as $row) {
                if (empty($row)) {
                    continue;
                }
                $columns = array_keys($row);
                $placeholders = array_map(static fn(string $c): string => ':' . $c, $columns);
                $sql = 'INSERT INTO `' . $table . '` (`'
                    . implode('`, `', $columns) . '`) VALUES ('
                    . implode(', ', $placeholders) . ')';
                $stmt = $db->prepare($sql);
                foreach ($row as $col => $value) {
                    $stmt->bindValue(':' . $col, $value);
                }
                $stmt->execute();
                $count++;
            }
            $restored[$table] = $count;
        }
    } finally {
        $db->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /* Your own session row may have been replaced — refresh it. */
    $refresh = $db->prepare('SELECT session_version, member_id FROM users WHERE user_id = :id LIMIT 1');
    $refresh->execute([':id' => (int)($_SESSION['user_id'] ?? 0)]);
    $me = $refresh->fetch(PDO::FETCH_ASSOC);
    if ($me) {
        $_SESSION['session_version'] = (int)$me['session_version'];
        $_SESSION['member_id'] = $me['member_id'] !== null ? (int)$me['member_id'] : null;
    }

    $total = array_sum($restored);
    echo json_encode([
        'success' => true,
        'message' => "Restore complete: {$total} records across " . count($restored) . ' tables.',
        'tables' => $restored,
    ]);
} catch (Throwable $e) {
    error_log('import_backup error: ' . $e->getMessage());
    try {
        (new Database())->connect()->exec('SET FOREIGN_KEY_CHECKS = 1');
    } catch (Throwable $ignored) {
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Restore failed. Your data may be incomplete — restore again from a good backup.']);
}
`````

### Changed 10/19: `htdocs/actions/process_add_user.php`

`````php
<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/Users.php';
require_once __DIR__ . '/../classes/Members.php';
require_once __DIR__ . '/../classes/AuditLog.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

if (!arnisVerifyCsrfToken($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
    exit;
}

/*
 * Account creation is administrator-only.
 *
 * NOTE: requirePermission() is NOT sufficient here. 'own' grants satisfy
 * hasPermission() (it only tests `!== false`), and Member/Applicant hold
 * users.write = 'own'. Without the strict check below, any Member could POST
 * role=SuperAdmin and mint an administrator account.
 */
if (permissionValue('users', 'write') !== true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit;
}

try {
    $db = (new Database())->connect();
    $usersModel = new Users($db);

    $fullName = trim((string)($_POST['full_name'] ?? ''));
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $role     = (string)($_POST['role'] ?? '');
    $email    = trim((string)($_POST['email'] ?? ''));
    $memberId = trim((string)($_POST['member_id'] ?? ''));
    $dob = trim((string)($_POST['dob'] ?? ''));
    $contactNumber = trim((string)($_POST['contact_number'] ?? ''));
    $playerCategory = (string)($_POST['player_category'] ?? 'Public');

    $roles = require __DIR__ . '/../config/roles.php';

    if ($fullName === '' || $username === '' || $password === '' || $role === '') {
        echo json_encode(['success' => false, 'message' => 'Please fill all required fields.']);
        exit;
    }

    if (!array_key_exists($role, $roles)) {
        echo json_encode(['success' => false, 'message' => 'Invalid role selected.']);
        exit;
    }

    /* Applicants self-register via Register as Member; admins must not
     * mint Applicant (or retired Committee / Board Member) accounts. */
    if (!in_array($role, ['SuperAdmin', 'Head Coach', 'Officer', 'Coach/Trainer', 'Member'], true)) {
        echo json_encode(['success' => false, 'message' => 'That role cannot be created here. Players register via the Register page.']);
        exit;
    }

    if (mb_strlen($fullName) > 100) {
        echo json_encode(['success' => false, 'message' => 'Full name must be 100 characters or fewer.']);
        exit;
    }

    if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
        echo json_encode(['success' => false, 'message' => 'Username may only contain letters, numbers, dot, underscore and hyphen (3-50 characters).']);
        exit;
    }

    if (strlen($password) < 6) {
        echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters.']);
        exit;
    }

    if ($email !== '') {
        if (mb_strlen($email) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'Enter a valid email address.']);
            exit;
        }
        if ($usersModel->getByEmail($email)) {
            echo json_encode(['success' => false, 'message' => 'That email address is already registered.']);
            exit;
        }
    }

    $memberRoles = ['Head Coach' => 'Head Coach', 'Coach/Trainer' => 'Coach', 'Member' => 'Player'];
    $memberIdValue = null;
    $createProfile = isset($memberRoles[$role]) && ($memberId === '' || $memberId === '0');
    if ($createProfile) {
        $dobObj = DateTime::createFromFormat('Y-m-d', $dob);
        if ($dob === '' || !$dobObj || $dobObj->format('Y-m-d') !== $dob || $dob > date('Y-m-d')) {
            echo json_encode(['success' => false, 'message' => 'Enter a valid date of birth for the member profile.']);
            exit;
        }
        if ($contactNumber === '' || mb_strlen($contactNumber) > 20) {
            echo json_encode(['success' => false, 'message' => 'Enter a contact number (up to 20 characters) for the member profile.']);
            exit;
        }
        if ($role !== 'Member' || !in_array($playerCategory, ['Public', 'Private', 'Mixed'], true)) {
            $playerCategory = 'Public';
        }
    }
    if ($memberId !== '' && $memberId !== '0') {
        if (!ctype_digit($memberId) || (int)$memberId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Linked member ID must be a positive whole number.']);
            exit;
        }
        $memberIdValue = (int)$memberId;

        if (!isset($memberRoles[$role])) {
            echo json_encode(['success' => false, 'message' => 'A member record can only be linked to a Head Coach, Coach/Trainer, or Member account.']);
            exit;
        }

        $memberCheck = $db->prepare('SELECT member_id FROM members WHERE member_id = ? AND is_voided = 0');
        $memberCheck->execute([$memberIdValue]);
        if (!$memberCheck->fetchColumn()) {
            echo json_encode(['success' => false, 'message' => 'Linked member ID does not exist.']);
            exit;
        }

        /* One active login per member: refuse to attach a second active
         * account to the same member record (prevents duplicate accounts). */
        $dupCheck = $db->prepare("SELECT user_id FROM users WHERE member_id = ? AND is_active = 1 LIMIT 1");
        $dupCheck->execute([$memberIdValue]);
        if ($dupCheck->fetchColumn()) {
            echo json_encode(['success' => false, 'message' => 'That member already has an active login account. Change the linked member or deactivate the existing account first.']);
            exit;
        }
    }

    if ($usersModel->getByUsername($username)) {
        echo json_encode(['success' => false, 'message' => 'Username already exists.']);
        exit;
    }

    $data = [
        'full_name'     => $fullName,
        'username'      => $username,
        'password_hash' => $usersModel->hashPassword($password),
        'role'          => $role,
        'email'         => $email !== '' ? $email : null,
        'member_id'     => $memberIdValue,
        'is_active'     => 1,
    ];

    $db->beginTransaction();
    try {
        if ($createProfile) {
            $data['member_id'] = (new Members($db))->createProfileForAccount([
                'full_name' => $fullName,
                'dob' => $dob,
                'email' => $email !== '' ? $email : null,
                'contact_number' => $contactNumber,
                'member_type' => $memberRoles[$role],
                'player_category' => $playerCategory,
                'training_preference' => $playerCategory === 'Private' ? 'Private' : 'Group',
                'status' => 'Active',
                'rank' => 'None',
            ], (int)($_SESSION['user_id'] ?? 0));
        }
        if (!$usersModel->create($data)) {
            throw new RuntimeException('User insert failed.');
        }
        $newUserId = (int)$db->lastInsertId();
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('process_add_user create error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to create user. Please try again.']);
        exit;
    }

    AuditLog::record(
        $db,
        'create',
        'users',
        $newUserId ?: null,
        'Created ' . $role . ' account "' . $username . '" for ' . $fullName
            . ($createProfile ? ' with member profile #' . (int)$data['member_id'] : '')
    );
    echo json_encode([
        'success' => true,
        'message' => $createProfile ? 'User and member profile created successfully.' : 'User created successfully.',
    ]);
} catch (Throwable $e) {
    error_log('process_add_user error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An unexpected error occurred. Please try again.']);
}
`````

### Changed 11/19: `htdocs/actions/process_attendance_checkin.php`

`````php
<?php
declare(strict_types=1);

// Arnis Connect operates on Philippine time (Asia/Manila); stored start/end
// times are local. Force the default timezone so time() and strtotime() compare
// apples-to-apples regardless of the server's system timezone.
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/Attendance.php';
require_once __DIR__ . '/../classes/Trainings.php';
require_once __DIR__ . '/../classes/Members.php';
require_once __DIR__ . '/../classes/Settings.php';
require_once __DIR__ . '/../includes/checkin_window.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Content-Type: application/json; charset=UTF-8');

function checkinError(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    checkinError(405, 'Method not allowed');
}

if (!isset($_POST['csrf_token']) || !arnisVerifyCsrfToken((string)$_POST['csrf_token'])) {
    checkinError(403, 'Invalid security token. Please reload the page and try again.');
}

$trainingId = (isset($_POST['training_id']) && is_numeric($_POST['training_id'])) ? (int)$_POST['training_id'] : 0;
if ($trainingId <= 0) {
    checkinError(400, 'Invalid training session.');
}

$memberId = (isset($_POST['member_id']) && is_numeric($_POST['member_id'])) ? (int)$_POST['member_id'] : 0;
if ($memberId <= 0) {
    checkinError(400, 'Invalid member ID.');
}

/* 
 * Permission: Members and Applicants can only check in themselves (attendance.write = 'own')
 * Coaches/Admins/Officers can check in anyone
 */
requirePermission('attendance', 'write');

// Get training to verify it exists and get geofence settings
try {
    $db = (new Database())->connect();

    /*
     * Scope check runs here because 'own' needs the resolved member id, which
     * may require the name fallback (resolveSessionMemberId) for accounts whose
     * users.member_id was never linked.
     */
    $writeScope = permissionValue('attendance', 'write');
    $isSelfCheckin = ($writeScope === 'own');
    $settings = new Settings($db);
    if ($writeScope !== true) {
        if ($writeScope === 'own') {
            $currentMemberId = function_exists('resolveSessionMemberId') ? resolveSessionMemberId($db) : 0;
            if ($currentMemberId <= 0 || $currentMemberId !== $memberId) {
                checkinError(403, 'You can only check in yourself.');
            }
        } elseif ($writeScope === 'own_trainees') {
            // Coach can check in their trainees
            if (!canPerformAction('attendance', 'write', ['member_id' => $memberId])) {
                checkinError(403, 'You can only check in your own trainees.');
            }
        }
    }

    $trainingsModel = new Trainings($db);
    $training = $trainingsModel->getById($trainingId);
    
    if (!$training) {
        checkinError(404, 'Training session not found or has been voided.');
    }
    
    $window = arnisCheckinWindow(
        (string)$training['training_date'],
        $training['start_time'] ?? null,
        $training['end_time'] ?? null,
        (int)$settings->get('checkin_open_minutes', '30'),
        (int)$settings->get('late_grace_minutes', '15')
    );
    if ($window['state'] === 'upcoming') {
        checkinError(400, 'Check-in is not open yet. It opens at '
            . arnisFormatManila('M d, Y h:i A', (int)$window['opens_at']) . '.');
    }
    if ($isSelfCheckin && $window['state'] === 'closed') {
        checkinError(400, 'Check-in for this session has closed.');
    }
    
    /*
     * A row may already exist for this (training, member): staff "Add People"
     * inserts every pick with status 'Absent' as a placeholder, and attendance
     * has UNIQUE(training_id, member_id). Only block a genuine duplicate
     * check-in (already Present/Late); an 'Absent' placeholder - or a soft
     * deleted row - is promoted below instead.
     */
    $attendanceModel = new Attendance($db);
    $existingAttendance = $db->prepare("
        SELECT attendance_id, status, is_voided FROM attendance 
        WHERE training_id = :training_id AND member_id = :member_id
        LIMIT 1
    ");
    $existingAttendance->execute([':training_id' => $trainingId, ':member_id' => $memberId]);
    $existing = $existingAttendance->fetch(PDO::FETCH_ASSOC);
    $existingAttendanceId = $existing ? (int)$existing['attendance_id'] : 0;

    if ($existingAttendanceId > 0
        && (int)($existing['is_voided'] ?? 0) === 0
        && in_array((string)($existing['status'] ?? ''), ['Present', 'Late'], true)) {
        checkinError(409, 'You are already checked in for this session. Current status: ' . $existing['status']);
    }

    // Members may only check in to sessions a coach has put them on.
    if ($isSelfCheckin && ($existingAttendanceId <= 0 || (int)($existing['is_voided'] ?? 0) !== 0)) {
        checkinError(403, 'You are not on the roster for this training session. Ask your coach to add you.');
    }
    
    // Verify member is eligible for this training (based on target_group and player_category)
    $memberModel = new Members($db);
    $member = $memberModel->getById($memberId);
    if (!$member) {
        checkinError(404, 'Member not found.');
    }
    
    // Check if member is active
    if ($member['status'] !== 'Active') {
        checkinError(403, 'Only active members can check in.');
    }
    
    // Verify member matches training target_group
    $targetGroup = $training['target_group'] ?? 'Mixed';
    $playerCategory = $member['player_category'] ?? 'Public';
    
    $eligible = false;
    if ($targetGroup === 'Mixed') {
        $eligible = true;
    } elseif ($targetGroup === 'Public' && in_array($playerCategory, ['Public', 'Mixed'], true)) {
        $eligible = true;
    } elseif ($targetGroup === 'Private' && in_array($playerCategory, ['Private', 'Mixed'], true)) {
        $eligible = true;
    }
    
    if (!$eligible) {
        checkinError(403, 'You are not eligible for this training session.');
    }
    
    // Face verification (if provided)
    $faceScore = null;
    $faceDescriptorInput = isset($_POST['face_descriptor']) ? trim((string)$_POST['face_descriptor']) : '';
    $storedDescriptor = trim((string)($member['face_descriptor'] ?? ''));

    if ($isSelfCheckin && $storedDescriptor === '') {
        checkinError(400, 'Enroll your face on My Profile before checking in.');
    }
    if ($isSelfCheckin && $faceDescriptorInput === '') {
        checkinError(400, 'Face verification required. Please scan your face to check in.');
    }

    if ($faceDescriptorInput !== '' && $storedDescriptor !== '') {
        // Verify the captured descriptor against the member's enrolled one.
        $inputDescriptor = json_decode($faceDescriptorInput, true);
        $stored = json_decode($storedDescriptor, true);

        if (!is_array($inputDescriptor) || !is_array($stored)
            || count($inputDescriptor) !== 128 || count($stored) !== 128) {
            checkinError(400, 'Could not read the face data. Please recapture your face and try again.');
        }

        // Euclidean distance between the two 128-d face descriptors.
        $distance = 0.0;
        for ($i = 0; $i < 128; $i++) {
            $diff = (float)$inputDescriptor[$i] - (float)$stored[$i];
            $distance += $diff * $diff;
        }
        $distance = sqrt($distance);

        // Maximum descriptor distance accepted (lower = stricter).
        $threshold = (float)$settings->get('face_match_threshold', '0.60');
        if ($threshold < 0.1 || $threshold > 1.0) {
            $threshold = 0.6;
        }

        if ($distance > $threshold) {
            checkinError(403, 'Face verification failed - the captured face does not match your enrolled face. Please face the camera in good lighting and try again.');
        }

        $faceScore = round(1 - ($distance / $threshold), 4); // similarity score 0-1
        if ($faceScore < 0) $faceScore = 0;
        if ($faceScore > 1) $faceScore = 1;
    } elseif ($storedDescriptor !== '') {
        // Member has enrolled face but didn't provide one
        checkinError(400, 'Face verification required. Please enroll your face first.');
    }
    // If no stored descriptor, allow manual check-in (fallback)
    
    // Geolocation verification (if provided)
    $distanceM = null;
    $userLat = isset($_POST['latitude']) && is_numeric($_POST['latitude']) ? (float)$_POST['latitude'] : null;
    $userLng = isset($_POST['longitude']) && is_numeric($_POST['longitude']) ? (float)$_POST['longitude'] : null;
    $venueLat = isset($training['venue_lat']) && $training['venue_lat'] !== null ? (float)$training['venue_lat'] : null;
    $venueLng = isset($training['venue_lng']) && $training['venue_lng'] !== null ? (float)$training['venue_lng'] : null;
    $geofenceM = isset($training['geofence_m']) && $training['geofence_m'] !== null
        ? (int)$training['geofence_m']
        : (int)$settings->get('default_geofence_m', '100');
    
    if ($userLat !== null && $userLng !== null && $venueLat !== null && $venueLng !== null) {
        // Haversine formula for distance calculation
        $earthRadius = 6371000; // meters
        $lat1 = deg2rad($userLat);
        $lon1 = deg2rad($userLng);
        $lat2 = deg2rad($venueLat);
        $lon2 = deg2rad($venueLng);
        
        $dLat = $lat2 - $lat1;
        $dLon = $lon2 - $lon1;
        
        $a = sin($dLat/2) * sin($dLat/2) + cos($lat1) * cos($lat2) * sin($dLon/2) * sin($dLon/2);
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        $distanceM = (int)round($earthRadius * $c);
        
        if ($distanceM > $geofenceM) {
            checkinError(403, "You are too far from the venue. Distance: {$distanceM}m, Allowed: {$geofenceM}m. Please move closer to the training location.");
        }
    } elseif ($venueLat !== null && $venueLng !== null) {
        // Venue has geofence but user didn't provide location
        checkinError(400, 'Location access required. Please enable GPS and try again.');
    }
    // If no venue coordinates, allow check-in without geofence check
    
    $status = $window['late'] ? 'Late' : 'Present';
    
    $method = ($faceDescriptorInput !== '' && $storedDescriptor !== '') ? 'FaceGPS' : 'Manual';
    $flagged = 0;
    $flagReason = null;
    
    // Flag if face match confidence is marginal but accepted
    if ($faceScore !== null && $faceScore < 0.5) {
        $flagged = 1;
        $flagReason = 'Low face match confidence: ' . round($faceScore * 100) . '%';
    }
    // Flag if distance is close to geofence boundary
    if ($distanceM !== null && $distanceM > $geofenceM * 0.8) {
        $flagged = 1;
        $flagReason = ($flagReason ? $flagReason . '; ' : '') . 'Near geofence boundary (' . $distanceM . 'm/' . $geofenceM . 'm)';
    }
    
    // Start transaction
    $db->beginTransaction();
    
    try {
        /*
         * Promote the coach-created 'Absent' placeholder (or reactivate a soft
         * deleted row) instead of INSERTing, which would violate the
         * UNIQUE(training_id, member_id) key. Only create a new row when the
         * member was never on the roster.
         */
        if ($existingAttendanceId > 0) {
            $promote = $db->prepare("
                UPDATE attendance
                SET status = :status,
                    remarks = :remarks,
                    updated_by = :updated_by,
                    updated_at = NOW(),
                    is_voided = 0,
                    voided_at = NULL,
                    voided_by = NULL,
                    void_reason = NULL
                WHERE attendance_id = :id
            ");
            $promote->execute([
                ':status'     => $status,
                ':remarks'    => 'Self check-in via ' . $method,
                ':updated_by' => (int)$_SESSION['user_id'],
                ':id'         => $existingAttendanceId,
            ]);
            $attendanceId = $existingAttendanceId;
        } else {
            $attendanceResult = $attendanceModel->create([
                'training_id' => $trainingId,
                'member_id' => $memberId,
                'status' => $status,
                'remarks' => 'Self check-in via ' . $method,
                'recorded_by' => (int)$_SESSION['user_id'],
            ]);

            if (!$attendanceResult) {
                throw new RuntimeException('Failed to create attendance record');
            }

            $attendanceId = (int)$db->lastInsertId();
        }
        
        // Create check-in record (upsert: attendance_checkins also has a
        // UNIQUE(training_id, member_id) key).
        $checkinStmt = $db->prepare("
            INSERT INTO attendance_checkins 
            (training_id, member_id, method, face_score, distance_m, status, flagged, flag_reason, recorded_by, created_at)
            VALUES (:training_id, :member_id, :method, :face_score, :distance_m, :status, :flagged, :flag_reason, :recorded_by, NOW())
            ON DUPLICATE KEY UPDATE
                method = VALUES(method),
                face_score = VALUES(face_score),
                distance_m = VALUES(distance_m),
                status = VALUES(status),
                flagged = VALUES(flagged),
                flag_reason = VALUES(flag_reason),
                recorded_by = VALUES(recorded_by),
                created_at = NOW()
        ");
        
        $checkinStmt->execute([
            ':training_id' => $trainingId,
            ':member_id' => $memberId,
            ':method' => $method,
            ':face_score' => $faceScore,
            ':distance_m' => $distanceM,
            ':status' => $status,
            ':flagged' => $flagged,
            ':flag_reason' => $flagReason,
            ':recorded_by' => (int)$_SESSION['user_id'],
        ]);
        
        $checkinId = (int)$db->lastInsertId();
        
        $db->commit();
        
        $message = 'Checked in as ' . $status;
        if ($method === 'FaceGPS') {
            $message .= ' (Face verified' . ($faceScore ? ', ' . round($faceScore * 100) . '% match' : '') . ')';
            if ($distanceM !== null) {
                $message .= ', ' . $distanceM . 'm from venue';
            }
        }
        
        echo json_encode([
            'success' => true,
            'message' => $message,
            'data' => [
                'attendance_id' => $attendanceId,
                'checkin_id' => $checkinId,
                'status' => $status,
                'method' => $method,
                'face_score' => $faceScore,
                'distance_m' => $distanceM,
                'flagged' => $flagged,
            ]
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('process_attendance_checkin error: ' . $e->getMessage());
        checkinError(500, 'Failed to process check-in. Please try again.');
    }
    
} catch (PDOException $e) {
    error_log('process_attendance_checkin DB error: ' . $e->getMessage());
    checkinError(500, 'Database error. Please try again.');
} catch (Throwable $e) {
    error_log('process_attendance_checkin error: ' . $e->getMessage());
    checkinError(500, 'An error occurred. Please try again.');
}
`````

### Changed 12/19: `htdocs/actions/process_edit_member.php`

`````php
<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/Members.php';
require_once __DIR__ . '/../classes/AuditLog.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

requirePermission('members', 'write');

arnisRequireCsrf(true);

$memberId = isset($_POST['member_id']) && is_numeric($_POST['member_id']) ? (int)$_POST['member_id'] : 0;
if ($memberId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid member ID.']);
    exit;
}

$validRanks = Members::RANK_ORDER;
/* Canonical THREE member positions. Legacy Trainer/Athlete values are
 * normalized (Trainer->Coach, Athlete->Player) for backward compatibility. */
$validCategories = ['Public', 'Private', 'Mixed'];
$validPreferences = ['Group', 'Private', 'Flexible'];
$validStatuses = ['Active', 'Inactive', 'On Leave'];

$fullName = trim((string)($_POST['full_name'] ?? ''));
$dob = trim((string)($_POST['dob'] ?? ''));
$contactNumber = trim((string)($_POST['contact_number'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$address = trim((string)($_POST['address'] ?? ''));
$heightRaw = $_POST['height'] ?? null;
$weightRaw = $_POST['weight'] ?? null;
$playerCategory = isset($_POST['player_category']) ? trim((string)$_POST['player_category']) : null;
$trainingPreference = isset($_POST['training_preference']) ? trim((string)$_POST['training_preference']) : null;
$affiliatedSchool = trim((string)($_POST['affiliated_school'] ?? ''));
$guardianName = trim((string)($_POST['guardian_name'] ?? ''));
$guardianContact = trim((string)($_POST['guardian_contact'] ?? ''));
    $cloudinaryUrl = trim((string)($_POST['cloudinary_url'] ?? ''));

    $sessionMemberId = isset($_SESSION['member_id']) && $_SESSION['member_id'] !== null
        ? (int)$_SESSION['member_id']
        : 0;
    $isSelf = $sessionMemberId > 0 && $sessionMemberId === $memberId;

    // Members editing themselves submit disabled read-only fields as empty;
    // fall back to the stored full name / DOB instead of rejecting.
    if (!$isSelf && ($fullName === '' || $dob === '')) {
        echo json_encode(['success' => false, 'message' => 'Full name and date of birth are required.']);
        exit;
    }

// Only validate DOB if it was actually posted (members self-editing have it disabled;
// the stored value will be applied after the DB fetch in the fallback block below).
if ($dob !== '') {
    $dobObj = DateTime::createFromFormat('Y-m-d', $dob);
    if (!$dobObj || $dobObj->format('Y-m-d') !== $dob || $dob > date('Y-m-d')) {
        echo json_encode(['success' => false, 'message' => 'Invalid date of birth.']);
        exit;
    }
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Invalid email format.']);
    exit;
}

if ($playerCategory !== null && !in_array($playerCategory, $validCategories, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid player category selected.']);
    exit;
}
if ($trainingPreference !== null && !in_array($trainingPreference, $validPreferences, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid training preference selected.']);
    exit;
}

$height = null;
if ($heightRaw !== null && $heightRaw !== '') {
    if (!is_numeric($heightRaw) || (float)$heightRaw <= 0 || (float)$heightRaw > 300) {
        echo json_encode(['success' => false, 'message' => 'Height must be a number between 1 and 300 cm.']);
        exit;
    }
    $height = (float)$heightRaw;
}

$weight = null;
if ($weightRaw !== null && $weightRaw !== '') {
    if (!is_numeric($weightRaw) || (float)$weightRaw <= 0 || (float)$weightRaw > 1000) {
        echo json_encode(['success' => false, 'message' => 'Weight must be a number between 1 and 1000 kg.']);
        exit;
    }
    $weight = (float)$weightRaw;
}

try {
    $db = (new Database())->connect();
    $membersModel = new Members($db);

    // Verify member exists and is not voided
    $existing = $membersModel->getById($memberId);
    if (!$existing) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Member not found.']);
        exit;
    }
    // Fields a form leaves out (disabled inputs are not submitted) keep their stored value.
    $playerCategory = $playerCategory ?? (string)($existing['player_category'] ?? 'Public');
    $trainingPreference = $trainingPreference ?? (string)($existing['training_preference'] ?? 'Group');

    $profileImg = $existing['profile_img'] ?? null;
    if ($cloudinaryUrl !== '') {
        $imgParts = parse_url($cloudinaryUrl);
        $imgHost = strtolower((string)($imgParts['host'] ?? ''));
        if (strtolower((string)($imgParts['scheme'] ?? '')) !== 'https'
            || ($imgHost !== 'res.cloudinary.com' && !str_ends_with($imgHost, '.res.cloudinary.com'))
            || strlen($cloudinaryUrl) > 255) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Only Cloudinary image URLs are accepted for the profile photo.']);
            exit;
        }
        $profileImg = $cloudinaryUrl;
    }

    $writeScope = permissionValue('members', 'write');
    $sessionMemberId = isset($_SESSION['member_id']) && $_SESSION['member_id'] !== null
        ? (int)$_SESSION['member_id']
        : 0;
    $isSelf = $sessionMemberId > 0 && $sessionMemberId === $memberId;
    if ($writeScope !== true) {
        if (!canPerformAction('members', 'write', ['member_id' => $memberId])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'You do not have permission to edit this member.']);
            exit;
        }
    }

    /*
     * PRIVILEGE BOUNDARY.
     *
     * A member may edit their own contact/profile details, but rank, status,
     * position and payment notes are staff-controlled:
     *   - Members self-editing: all four fields are frozen.
     *   - Coach / Head Coach editing a trainee: rank and payment notes are
     *     frozen; rank moves only through promote_member.php (next rank only).
     *   - SuperAdmin: may correct any field (an explicit admin override).
     *
     * Fields absent from the POST (because the UI disabled them) fall back to
     * the stored value instead of the old hard-coded defaults, so saving a
     * profile can never silently reset rank/status/position/payment notes.
     */
    $currentRank = (string)($existing['rank'] ?? 'None');
    $currentStatus = (string)($existing['status'] ?? 'Active');
    $currentType = Members::normalizePosition((string)($existing['member_type'] ?? 'Player'));
    $currentPaymentNotes = (string)($existing['payment_notes'] ?? '');

    $rank = isset($_POST['rank']) ? trim((string)$_POST['rank']) : $currentRank;
    $status = isset($_POST['status']) ? trim((string)$_POST['status']) : $currentStatus;
    $memberTypeRaw = isset($_POST['member_type']) ? (string)$_POST['member_type'] : $currentType;
    $memberType = Members::normalizePosition($memberTypeRaw);
    $paymentNotes = isset($_POST['payment_notes']) ? trim((string)$_POST['payment_notes']) : $currentPaymentNotes;

    if (!in_array($rank, $validRanks, true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid rank selected.']);
        exit;
    }
    if (!in_array($status, $validStatuses, true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid status selected.']);
        exit;
    }
    if (!in_array($memberTypeRaw, ['Head Coach', 'Coach', 'Player'], true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid member position selected.']);
        exit;
    }

    // Members editing themselves: full name, DOB and player category are read-only (ignored)
    if ($isSelf && $writeScope === 'own') {
        $fullName = (string)($existing['full_name'] ?? $fullName);
        $dob = (string)($existing['dob'] ?? $dob);
        $playerCategory = (string)($existing['player_category'] ?? $playerCategory);
    }

    $canManagePrivileged = (!$isSelf && $writeScope === true);
    $canManageStatus = (!$isSelf && in_array($writeScope, [true, 'own_trainees'], true));

    if (!$canManagePrivileged && $rank !== $currentRank) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Rank cannot be edited here. Use the next-rank promotion action.',
        ]);
        exit;
    }
    if (!$canManagePrivileged && $memberType !== $currentType) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Only the SuperAdmin can change a member\'s position.']);
        exit;
    }
    if (!$canManagePrivileged && $paymentNotes !== $currentPaymentNotes) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Only the SuperAdmin can change payment notes.']);
        exit;
    }
    $currentPlayerCategory = (string)($existing['player_category'] ?? 'Public');
    if (!$canManagePrivileged && $playerCategory !== $currentPlayerCategory) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Player category cannot be changed here.']);
        exit;
    }
    // Training preference is editable by members (self) and staff; no extra restriction needed.
    if (!$canManageStatus && $status !== $currentStatus) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'You cannot change your own or another member\'s status.']);
        exit;
    }

    // Only an admin correction may adjust payment notes; otherwise preserve.
    if ($canManagePrivileged) {
        if ($playerCategory === 'Public' && $paymentNotes === '') {
            $paymentNotes = 'Public player: free / school-based training.';
        } elseif ($playerCategory === 'Private' && $paymentNotes === '') {
            $paymentNotes = 'Private player: paid training arrangement.';
        } elseif ($playerCategory === 'Mixed' && $paymentNotes === '') {
            $paymentNotes = 'Mixed player: joins both public and private sessions.';
        }
    } else {
        $paymentNotes = $currentPaymentNotes;
    }

    $data = [
        'full_name' => $fullName,
        'dob' => $dob,
        'email' => $email !== '' ? $email : null,
        'contact_number' => $contactNumber !== '' ? $contactNumber : null,
        'address' => $address !== '' ? $address : null,
        'rank' => $canManagePrivileged ? $rank : $currentRank,
        'status' => $canManageStatus ? $status : $currentStatus,
        'height' => $height,
        'weight' => $weight,
        'member_type' => $canManagePrivileged ? $memberType : $currentType,
        'player_category' => $playerCategory,
        'training_preference' => $trainingPreference,
        'affiliated_school' => $affiliatedSchool !== '' ? $affiliatedSchool : null,
        'guardian_name' => $guardianName !== '' ? $guardianName : null,
        'guardian_contact' => $guardianContact !== '' ? $guardianContact : null,
        'payment_notes' => $paymentNotes !== '' ? $paymentNotes : null,
        'profile_img' => $profileImg,
    ];

    $result = $membersModel->update($memberId, $data, (int)$_SESSION['user_id']);

    if ($result['success'] ?? false) {
        /* Keep the linked login's role consistent with the member position
         * (Head Coach->Head Coach, Coach->Coach, Player->Member). Only an
         * admin reaches this point with a position change. */
        if ($canManagePrivileged) {
            $syncRole = Members::roleForPosition($memberType);
            $syncStmt = $db->prepare(
                'UPDATE users SET role = :role, session_version = session_version + 1
                 WHERE member_id = :mid AND role <> :role'
            );
            $syncStmt->execute([':role' => $syncRole, ':mid' => $memberId]);
        }

        /* Belt progression: an admin correction is journaled with who did it. */
        $oldRank = (string)($existing['rank'] ?? 'None');
        if ($oldRank !== $rank) {
            $membersModel->logRankChange(
                $memberId,
                $oldRank !== '' ? $oldRank : null,
                $rank,
                (int)($_SESSION['user_id'] ?? 0),
                'Administrative rank correction'
            );
        }

        AuditLog::record(
            $db,
            'update',
            'members',
            $memberId,
            'Updated member profile for ' . $fullName
        );

        echo json_encode(['success' => true, 'message' => 'Member updated successfully.']);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $result['error'] ?? 'Failed to update member.']);
    }
} catch (Throwable $e) {
    error_log('process_edit_member failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to update member. Please try again.']);
}
`````

### Changed 13/19: `htdocs/actions/process_event_checkin.php`

`````php
<?php
declare(strict_types=1);

// Arnis Connect operates on Philippine time (Asia/Manila); stored start/end
// times are local. Force the default timezone so time() and strtotime() compare
// apples-to-apples regardless of the server's system timezone.
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/Members.php';
require_once __DIR__ . '/../classes/AuditLog.php';
require_once __DIR__ . '/../classes/Settings.php';
require_once __DIR__ . '/../includes/checkin_window.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Content-Type: application/json; charset=UTF-8');

function eventCheckinError(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    eventCheckinError(405, 'Method not allowed');
}

if (!isset($_POST['csrf_token']) || !arnisVerifyCsrfToken((string)$_POST['csrf_token'])) {
    eventCheckinError(403, 'Invalid security token. Please reload the page and try again.');
}

$eventId = (isset($_POST['event_id']) && is_numeric($_POST['event_id'])) ? (int)$_POST['event_id'] : 0;
if ($eventId <= 0) {
    eventCheckinError(400, 'Invalid event.');
}

$memberId = (isset($_POST['member_id']) && is_numeric($_POST['member_id'])) ? (int)$_POST['member_id'] : 0;
if ($memberId <= 0) {
    eventCheckinError(400, 'Invalid member ID.');
}

// Self check-in: members may check themselves in (own participant)
$writeScope = permissionValue('event_participants', 'write');
$isSelfCheckin = false;
if ($writeScope !== true) {
    // resolve after DB connect below
} else {
    $isSelfCheckin = false;
}

try {
    $db = (new Database())->connect();

    $currentMemberId = function_exists('resolveSessionMemberId') ? resolveSessionMemberId($db) : 0;
    $isSelfCheckin = ($currentMemberId > 0 && $currentMemberId === $memberId);

    if ($writeScope === false) {
        // Members have no general write, but may self check-in
        if (!$isSelfCheckin) {
            eventCheckinError(403, 'Access denied.');
        }
    } elseif ($writeScope === 'own') {
        if (!$isSelfCheckin) {
            eventCheckinError(403, 'You can only check in yourself.');
        }
    } elseif ($writeScope === 'own_events') {
        if (!$isSelfCheckin) {
            $evStmt = $db->prepare('SELECT created_by, handler_id FROM events WHERE event_id = :id AND is_voided = 0 LIMIT 1');
            $evStmt->execute([':id' => $eventId]);
            $ev = $evStmt->fetch(PDO::FETCH_ASSOC);
            if (!$ev || !canPerformAction('event_participants', 'write', [
                'event_created_by' => (int)($ev['created_by'] ?? 0),
                'handler_id' => (int)($ev['handler_id'] ?? 0),
            ])) {
                eventCheckinError(403, 'You do not have permission to record attendance for this event.');
            }
        }
    }

    $evStmt = $db->prepare('SELECT event_id, event_name, event_date, start_time, end_time, location, venue_lat, venue_lng, geofence_m FROM events WHERE event_id = :id AND is_voided = 0 LIMIT 1');
    $evStmt->execute([':id' => $eventId]);
    $event = $evStmt->fetch(PDO::FETCH_ASSOC);
    if (!$event) {
        eventCheckinError(404, 'Event not found or has been voided.');
    }

    $settings = new Settings($db);
    $window = arnisCheckinWindow(
        (string)($event['event_date'] ?? ''),
        $event['start_time'] ?? null,
        $event['end_time'] ?? null,
        (int)$settings->get('checkin_open_minutes', '30'),
        (int)$settings->get('late_grace_minutes', '15')
    );
    if ($window['state'] === 'upcoming') {
        eventCheckinError(400, 'Check-in is not open yet. It opens at '
            . arnisFormatManila('M d, Y h:i A', (int)$window['opens_at']) . '.');
    }
    if ($isSelfCheckin && $window['state'] === 'closed') {
        eventCheckinError(400, 'Check-in for this event has closed.');
    }

    $pStmt = $db->prepare('SELECT participant_id, attendance_status FROM event_participants WHERE event_id = :eid AND member_id = :mid AND is_voided = 0 LIMIT 1');
    $pStmt->execute([':eid' => $eventId, ':mid' => $memberId]);
    $participant = $pStmt->fetch(PDO::FETCH_ASSOC);
    if (!$participant) {
        eventCheckinError(403, 'You are not registered as a participant in this event.');
    }

    $currentStatus = (string)($participant['attendance_status'] ?? '');
    if (in_array($currentStatus, ['Present', 'Late'], true)) {
        eventCheckinError(409, 'You are already checked in. Current status: ' . $currentStatus);
    }

    $memberModel = new Members($db);
    $member = $memberModel->getById($memberId);
    if (!$member) {
        eventCheckinError(404, 'Member not found.');
    }
    if (($member['status'] ?? '') !== 'Active') {
        eventCheckinError(403, 'Only active members can check in.');
    }

    $faceScore = null;
    $faceDescriptorInput = isset($_POST['face_descriptor']) ? trim((string)$_POST['face_descriptor']) : '';
    $storedDescriptor = trim((string)($member['face_descriptor'] ?? ''));

    if ($isSelfCheckin && $storedDescriptor === '') {
        eventCheckinError(400, 'Enroll your face on My Profile before checking in.');
    }
    if ($isSelfCheckin && $faceDescriptorInput === '') {
        eventCheckinError(400, 'Face verification required. Please scan your face to check in.');
    }

    if ($faceDescriptorInput !== '' && $storedDescriptor !== '') {
        $inputDescriptor = json_decode($faceDescriptorInput, true);
        $stored = json_decode($storedDescriptor, true);
        if (!is_array($inputDescriptor) || !is_array($stored) || count($inputDescriptor) !== 128 || count($stored) !== 128) {
            eventCheckinError(400, 'Could not read the face data. Please recapture your face and try again.');
        }
        $distance = 0.0;
        for ($i = 0; $i < 128; $i++) {
            $diff = (float)$inputDescriptor[$i] - (float)$stored[$i];
            $distance += $diff * $diff;
        }
        $distance = sqrt($distance);
        $threshold = (float)$settings->get('face_match_threshold', '0.60');
        if ($threshold < 0.1 || $threshold > 1.0) {
            $threshold = 0.6;
        }
        if ($distance > $threshold) {
            eventCheckinError(403, 'Face verification failed. Please face the camera in good lighting and try again.');
        }
        $faceScore = round(1 - ($distance / $threshold), 4);
        if ($faceScore < 0) $faceScore = 0;
        if ($faceScore > 1) $faceScore = 1;
    } elseif ($storedDescriptor !== '') {
        eventCheckinError(400, 'Face verification required. Please enroll your face first.');
    }

    // Geolocation + geofence enforcement (same as trainings)
    $distanceM = null;
    $userLat = isset($_POST['latitude']) && is_numeric($_POST['latitude']) ? (float)$_POST['latitude'] : null;
    $userLng = isset($_POST['longitude']) && is_numeric($_POST['longitude']) ? (float)$_POST['longitude'] : null;
    $venueLat = isset($event['venue_lat']) && $event['venue_lat'] !== null ? (float)$event['venue_lat'] : null;
    $venueLng = isset($event['venue_lng']) && $event['venue_lng'] !== null ? (float)$event['venue_lng'] : null;
    $geofenceM = isset($event['geofence_m']) && $event['geofence_m'] !== null
        ? (int)$event['geofence_m']
        : (int)$settings->get('default_geofence_m', '100');

    if ($userLat !== null && $userLng !== null && $venueLat !== null && $venueLng !== null) {
        $earthRadius = 6371000;
        $lat1 = deg2rad($userLat);
        $lon1 = deg2rad($userLng);
        $lat2 = deg2rad($venueLat);
        $lon2 = deg2rad($venueLng);
        $dLat = $lat2 - $lat1;
        $dLon = $lon2 - $lon1;
        $a = sin($dLat/2) * sin($dLat/2) + cos($lat1) * cos($lat2) * sin($dLon/2) * sin($dLon/2);
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        $distanceM = (int)round($earthRadius * $c);
        if ($distanceM > $geofenceM) {
            eventCheckinError(403, "You are too far from the venue. Distance: {$distanceM}m, Allowed: {$geofenceM}m. Please move closer to the event location.");
        }
    } elseif ($venueLat !== null && $venueLng !== null) {
        eventCheckinError(400, 'Location access required. Please enable GPS and try again.');
    }

    $status = $window['late'] ? 'Late' : 'Present';
    $method = ($faceDescriptorInput !== '' && $storedDescriptor !== '') ? 'FaceGPS' : 'Manual';
    $userId = (int)($_SESSION['user_id'] ?? 0);

    $upd = $db->prepare("
        UPDATE event_participants
        SET attendance_status = :status,
            attendance_remarks = :remarks,
            attendance_recorded_by = :user_id,
            attendance_recorded_at = NOW(),
            updated_by = :user_id,
            updated_at = NOW()
        WHERE participant_id = :pid AND is_voided = 0
    ");
    $upd->execute([
        ':status' => $status,
        ':remarks' => 'Self check-in via ' . $method . ($faceScore !== null ? ' (score ' . round($faceScore * 100) . '%)' : ''),
        ':user_id' => $userId,
        ':pid' => (int)$participant['participant_id'],
    ]);

    AuditLog::record(
        $db,
        'update',
        'event_participants',
        (int)$participant['participant_id'],
        'Self check-in as ' . $status . ' for event #' . $eventId . ' via ' . $method,
        ['event_id' => $eventId, 'member_id' => $memberId, 'status' => $status, 'method' => $method]
    );

    echo json_encode([
        'success' => true,
        'message' => 'Checked in as ' . $status . ($faceScore !== null ? ' (Face verified, ' . round($faceScore * 100) . '% match)' : ''),
        'data' => [
            'participant_id' => (int)$participant['participant_id'],
            'status' => $status,
            'method' => $method,
            'face_score' => $faceScore,
        ],
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    if ((string)($e->errorInfo[1] ?? '') === '1054') {
        eventCheckinError(400, 'Event attendance columns are not set up yet. Run Database/add_event_attendance.sql in phpMyAdmin.');
    }
    error_log('process_event_checkin DB error: ' . $e->getMessage());
    eventCheckinError(500, 'Database error. Please try again.');
} catch (Throwable $e) {
    error_log('process_event_checkin error: ' . $e->getMessage());
    eventCheckinError(500, 'An error occurred. Please try again.');
}
`````

### Changed 14/19: `htdocs/actions/reset_system.php`

`````php
<?php
declare(strict_types=1);

/*
 * FULL SYSTEM RESET — SuperAdmin only.
 * Wipes every test/experiment row but keeps ALL SuperAdmin login accounts
 * (role = 'SuperAdmin') so you can always sign back in.
 * Requires typing RESET in the confirm box + a valid CSRF token.
 * TRUNCATE resets AUTO_INCREMENT, so ids start clean at 1.
 * ALWAYS download a backup first — this cannot be undone.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/PrivateTrainings.php';

const ARNIS_RESET_TABLES = [
    'achievements',
    'attendance',
    'attendance_checkins',
    'event_documents',
    'event_participants',
    'funds',
    'private_training_requests',
    'events',
    'trainings',
    'rank_history',
    'rank_verification_history',
    'registration_requests',
    'members',
];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

if (!arnisVerifyCsrfToken($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
    exit;
}

if (!hasRole('SuperAdmin')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit;
}

try {
    $confirmation = trim((string)($_POST['confirmation'] ?? ''));
    if ($confirmation !== 'RESET') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Type RESET to confirm.']);
        exit;
    }

    $db = (new Database())->connect();
    PrivateTrainings::ensureSchema($db);

    $kept = (int)$db->query("SELECT COUNT(*) FROM users WHERE role = 'SuperAdmin'")->fetchColumn();
    if ($kept < 1) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No SuperAdmin account exists — reset blocked to avoid lockout.']);
        exit;
    }

    $db->exec('SET FOREIGN_KEY_CHECKS = 0');
    try {
        foreach (ARNIS_RESET_TABLES as $table) {
            $db->exec("TRUNCATE TABLE `{$table}`");
        }
        /* Keep every SuperAdmin login; remove all other accounts. */
        $deletedUsers = $db->exec("DELETE FROM users WHERE role <> 'SuperAdmin'");
    } finally {
        $db->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    error_log('System reset performed by user_id ' . (int)($_SESSION['user_id'] ?? 0)
        . " — removed {$deletedUsers} non-admin users, kept {$kept} SuperAdmin(s).");

    echo json_encode([
        'success' => true,
        'message' => "System wiped clean. {$kept} SuperAdmin login(s) kept — all members, trainings, events, funds and other users were removed.",
    ]);
} catch (Throwable $e) {
    error_log('reset_system error: ' . $e->getMessage());
    try {
        (new Database())->connect()->exec('SET FOREIGN_KEY_CHECKS = 1');
    } catch (Throwable $ignored) {
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Reset failed. Please try again.']);
}
`````

### Changed 15/19: `htdocs/actions/save_settings.php`

`````php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/Settings.php';
require_once __DIR__ . '/../classes/AuditLog.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

function settingsRedirect(string $type, string $message): void
{
    $_SESSION[$type === 'success' ? 'success' : 'error'] = $message;
    header('Location: ../public/settings.php');
    exit;
}

if (!hasRole('SuperAdmin')) {
    http_response_code(403);
    exit('Access Denied: Only the SuperAdmin may change system settings.');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    settingsRedirect('error', 'Method not allowed.');
}

arnisRequireCsrf();

try {
    $db = (new Database())->connect();
    $settings = new Settings($db);

    $current = $settings->all();
    $userId = (int)($_SESSION['user_id'] ?? 0);

    $organizationName = trim((string)($_POST['organization_name'] ?? ''));
    $contactEmail = trim((string)($_POST['contact_email'] ?? ''));
    $fromEmail = trim((string)($_POST['from_email'] ?? ''));
    $fromName = trim((string)($_POST['from_name'] ?? ''));
    $privacyContact = trim((string)($_POST['privacy_contact'] ?? ''));
    $smtpUser = trim((string)($_POST['smtp_user'] ?? ''));
    $smtpPass = trim((string)($_POST['smtp_pass'] ?? ''));
    $faceThresholdRaw = trim((string)($_POST['face_match_threshold'] ?? '0.60'));
    $geofenceRaw = trim((string)($_POST['default_geofence_m'] ?? '100'));
    $openBeforeRaw = trim((string)($_POST['checkin_open_minutes'] ?? '30'));
    $graceRaw = trim((string)($_POST['late_grace_minutes'] ?? '15'));
    $privateFeeRaw = trim((string)($_POST['private_session_fee'] ?? '500'));

    if ($organizationName === '' || mb_strlen($organizationName) > 100) {
        settingsRedirect('error', 'Organization name is required and must be 100 characters or fewer.');
    }
    if ($contactEmail !== '' && !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
        settingsRedirect('error', 'Contact email must be a valid email address.');
    }
    if ($fromEmail !== '' && !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        settingsRedirect('error', 'From email must be a valid email address.');
    }
    if (!is_numeric($faceThresholdRaw)) {
        settingsRedirect('error', 'Face match threshold must be a number between 0.10 and 1.00.');
    }
    $faceThreshold = round((float)$faceThresholdRaw, 2);
    if ($faceThreshold < 0.10 || $faceThreshold > 1.00) {
        settingsRedirect('error', 'Face match threshold must be between 0.10 and 1.00.');
    }
    if (!ctype_digit($geofenceRaw)) {
        settingsRedirect('error', 'Default geofence must be a whole number of metres.');
    }
    $geofence = (int)$geofenceRaw;
    if ($geofence < 5 || $geofence > 5000) {
        settingsRedirect('error', 'Default geofence must be between 5 and 5,000 metres.');
    }
    if (!ctype_digit($openBeforeRaw) || (int)$openBeforeRaw > 240) {
        settingsRedirect('error', 'Check-in opening time must be 0 to 240 minutes before the start.');
    }
    if (!ctype_digit($graceRaw) || (int)$graceRaw > 180) {
        settingsRedirect('error', 'Late grace period must be 0 to 180 minutes.');
    }
    if (!is_numeric($privateFeeRaw) || (float)$privateFeeRaw < 0 || (float)$privateFeeRaw > 100000) {
        settingsRedirect('error', 'Private session fee must be between 0 and 100,000.');
    }

    $values = [
        'organization_name' => mb_substr($organizationName, 0, 100),
        'contact_email' => mb_substr($contactEmail, 0, 100),
        'from_email' => mb_substr($fromEmail, 0, 100),
        'from_name' => mb_substr($fromName !== '' ? $fromName : $organizationName, 0, 100),
        'privacy_contact' => mb_substr($privacyContact, 0, 150),
        'face_match_threshold' => number_format($faceThreshold, 2, '.', ''),
        'default_geofence_m' => (string)$geofence,
        'checkin_open_minutes' => (string)(int)$openBeforeRaw,
        'late_grace_minutes' => (string)(int)$graceRaw,
        'private_session_fee' => number_format((float)$privateFeeRaw, 2, '.', ''),
        'allow_registration' => isset($_POST['allow_registration']) ? '1' : '0',
        'email_notifications' => isset($_POST['email_notifications']) ? '1' : '0',
        'smtp_user' => mb_substr($smtpUser, 0, 150),
    ];
    if ($smtpPass !== '') {
        $values['smtp_pass'] = mb_substr($smtpPass, 0, 255);
    }

    $changed = [];
    $failed = [];
    foreach ($values as $key => $value) {
        if ((string)($current[$key] ?? '') === $value) {
            continue;
        }
        if ($settings->set($key, $value, $userId)) {
            $changed[$key] = $value;
        } else {
            $failed[] = $key;
        }
    }

    if ($changed !== []) {
        AuditLog::record(
            $db,
            'settings_update',
            'settings',
            null,
            'Updated system settings: ' . implode(', ', array_keys($changed)),
            ['changed' => array_keys($changed)]
        );
    }

    if ($failed !== []) {
        settingsRedirect('error', 'Some settings could not be saved: ' . implode(', ', $failed) . '. Run migration 003 if the settings table is missing.');
    }

    settingsRedirect('success', $changed === [] ? 'No settings changed.' : 'System settings saved.');
} catch (Throwable $e) {
    error_log('save_settings.php error: ' . $e->getMessage());
    settingsRedirect('error', 'Settings could not be saved. Please try again.');
}
`````

### Changed 16/19: `htdocs/public/activity_log.php`

`````php
<?php
/**
 * Activity Log - real, append-only audit trail.
 *
 * Replaces the old UNION of created_at / updated_at / voided_at columns. That
 * feed re-described the same row up to three times and could not show logins,
 * approvals, promotions, settings changes, report exports, or the actor's
 * role/IP. AuditLog::record() writes one intentional event per action.
 *
 * RBAC: the audit trail is security-sensitive (it contains names, IPs, and
 * security events), so only the SuperAdmin may read it.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/AuditLog.php';

if (!hasRole('SuperAdmin')) {
    http_response_code(403);
    exit('Access Denied: You do not have permission to view the audit log.');
}

/* ---------------------------------------------------------------- filters */
$allowedModules = [
    'auth', 'members', 'events', 'event_participants', 'event_documents',
    'trainings', 'attendance', 'funds', 'achievements', 'users',
    'registration', 'reports', 'settings', 'announcements', 'private_trainings',
];

$allowedActions = [
    'create', 'update', 'void', 'restore', 'delete', 'login', 'logout',
    'login_failed', 'approve', 'reject', 'promote', 'status_change',
    'verify_email', 'resend_verification', 'settings_update',
    'announcement_create', 'announcement_archive', 'export',
    'password_reset_request', 'password_reset',
];

$filters = [
    'module' => '',
    'action' => '',
    'actor' => '',
    'start_date' => '',
    'end_date' => '',
];

$rawModule = $_GET['module'] ?? '';
if (is_string($rawModule) && in_array($rawModule, $allowedModules, true)) {
    $filters['module'] = $rawModule;
}

$rawAction = $_GET['action'] ?? '';
if (is_string($rawAction) && in_array($rawAction, $allowedActions, true)) {
    $filters['action'] = $rawAction;
}

$rawActor = $_GET['actor'] ?? '';
if (is_string($rawActor)) {
    $filters['actor'] = mb_substr(trim($rawActor), 0, 100);
}

foreach (['start_date', 'end_date'] as $key) {
    $raw = $_GET[$key] ?? '';
    if (is_string($raw) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($raw)) === 1) {
        $filters[$key] = trim($raw);
    }
}

if ($filters['start_date'] !== '' && $filters['end_date'] !== ''
    && $filters['start_date'] > $filters['end_date']) {
    [$filters['start_date'], $filters['end_date']] = [$filters['end_date'], $filters['start_date']];
}

$hasFilters = $filters['module'] !== '' || $filters['action'] !== ''
    || $filters['actor'] !== '' || $filters['start_date'] !== '' || $filters['end_date'] !== '';

/* ------------------------------------------------------- SQL construction */
$where = [];
$params = [];

if ($filters['module'] !== '') {
    $where[] = 'module = :module';
    $params[':module'] = $filters['module'];
}
if ($filters['action'] !== '') {
    $where[] = 'action = :action';
    $params[':action'] = $filters['action'];
}
if ($filters['actor'] !== '') {
    $where[] = 'actor_name LIKE :actor';
    $params[':actor'] = '%' . $filters['actor'] . '%';
}
if ($filters['start_date'] !== '') {
    $where[] = 'created_at >= :start_date';
    $params[':start_date'] = $filters['start_date'] . ' 00:00:00';
}
if ($filters['end_date'] !== '') {
    $where[] = 'created_at <= :end_date';
    $params[':end_date'] = $filters['end_date'] . ' 23:59:59';
}

$whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

/* ------------------------------------------------------- CSV export */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $db = (new Database())->connect();
    AuditLog::ensureSchema($db);
    $stmt = $db->prepare(
        'SELECT audit_id, actor_name, actor_role, action, module, record_id,
                description, ip_address, created_at
         FROM audit_logs' . $whereSql . '
         ORDER BY created_at DESC, audit_id DESC'
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="audit_log_' . date('Ymd_His') . '.csv"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Actor', 'Role', 'Action', 'Module', 'Record ID', 'Description', 'IP Address', 'Created At']);
    foreach ($rows as $row) {
        $line = [
            $row['audit_id'] ?? '',
            $row['actor_name'] ?? '',
            $row['actor_role'] ?? '',
            $row['action'] ?? '',
            $row['module'] ?? '',
            $row['record_id'] ?? '',
            $row['description'] ?? '',
            $row['ip_address'] ?? '',
            $row['created_at'] ?? '',
        ];
        // Neutralise spreadsheet formulas in user-supplied text.
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

/* ------------------------------------------------------- fetch + paginate */
$perPage       = 30;
$requestedPage = max(1, (int)($_GET['page'] ?? 1));
$page          = 1;
$totalPages    = 1;
$totalEvents   = 0;
$todayEvents   = 0;
$securityEvents = 0;
$uniqueActors  = 0;
$events        = [];
$offset        = 0;
$loadError     = null;
$auditReady    = true;

try {
    $db = (new Database())->connect();
    AuditLog::ensureSchema($db);

    $statsStmt = $db->prepare(
        "SELECT
            COUNT(*) AS total,
            COALESCE(SUM(CASE WHEN created_at >= CURDATE() THEN 1 ELSE 0 END), 0) AS today,
            COALESCE(SUM(CASE WHEN module = 'auth' THEN 1 ELSE 0 END), 0) AS security,
            COUNT(DISTINCT actor_name) AS actors
         FROM audit_logs" . $whereSql
    );
    $statsStmt->execute($params);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $totalEvents    = (int)($stats['total'] ?? 0);
    $todayEvents    = (int)($stats['today'] ?? 0);
    $securityEvents = (int)($stats['security'] ?? 0);
    $uniqueActors   = (int)($stats['actors'] ?? 0);

    $totalPages = max(1, (int)ceil($totalEvents / $perPage));
    $page       = min($requestedPage, $totalPages);
    $offset     = ($page - 1) * $perPage;

    if ($totalEvents > 0) {
        // LIMIT/OFFSET are ints derived from (int) casts, so interpolation is
        // safe and required for native prepared statements.
        $pageStmt = $db->prepare(
            'SELECT audit_id, actor_name, actor_role, action, module, record_id,
                    description, ip_address, created_at
             FROM audit_logs' . $whereSql . '
             ORDER BY created_at DESC, audit_id DESC
             LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset
        );
        $pageStmt->execute($params);
        $events = $pageStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Throwable $e) {
    error_log('activity_log.php query error: ' . $e->getMessage());
    $auditReady = false;
    $loadError = 'The audit log is not available right now. Please reload the page.';
}

/* ----------------------------------------------------------------- helpers */
function alE(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function alJsArg($value): string
{
    $json = json_encode(
        (string)$value,
        JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE
    );
    return htmlspecialchars((string)$json, ENT_QUOTES, 'UTF-8');
}

function alFormatDateTime($value): string
{
    if (!is_string($value)) {
        return '—';
    }
    $value = trim($value);
    if ($value === '' || str_starts_with($value, '0000-00-00')) {
        return '—';
    }
    $timestamp = strtotime($value);
    return $timestamp === false ? '—' : date('M d, Y g:i A', $timestamp);
}

function alModuleLabel(string $module): string
{
    $labels = [
        'auth' => 'Authentication',
        'members' => 'Members',
        'events' => 'Events',
        'event_participants' => 'Event Participants',
        'event_documents' => 'Event Documents',
        'trainings' => 'Trainings',
        'attendance' => 'Attendance',
        'funds' => 'Funds',
        'achievements' => 'Achievements',
        'users' => 'Users',
        'registration' => 'Registration',
        'reports' => 'Reports',
        'settings' => 'System Settings',
        'announcements' => 'Announcements',
        'private_trainings' => 'Private Training',
    ];
    return $labels[$module] ?? ($module !== '' ? ucwords(str_replace('_', ' ', $module)) : '—');
}

/** @return array{icon:string,badge:string,label:string} */
function alActionMeta(string $action): array
{
    return match ($action) {
        'create' => ['icon' => 'fa-plus', 'badge' => 'bg-green-500/10 text-green-400', 'label' => 'Created'],
        'update' => ['icon' => 'fa-pen', 'badge' => 'bg-yellow-500/10 text-yellow-400', 'label' => 'Updated'],
        'void' => ['icon' => 'fa-ban', 'badge' => 'bg-red-500/10 text-red-400', 'label' => 'Voided'],
        'restore' => ['icon' => 'fa-rotate-left', 'badge' => 'bg-emerald-500/10 text-emerald-400', 'label' => 'Restored'],
        'delete' => ['icon' => 'fa-trash', 'badge' => 'bg-red-500/10 text-red-400', 'label' => 'Deleted'],
        'login' => ['icon' => 'fa-right-to-bracket', 'badge' => 'bg-blue-500/10 text-blue-400', 'label' => 'Login'],
        'logout' => ['icon' => 'fa-right-from-bracket', 'badge' => 'bg-gray-500/10 text-gray-400', 'label' => 'Logout'],
        'login_failed' => ['icon' => 'fa-triangle-exclamation', 'badge' => 'bg-red-500/10 text-red-400', 'label' => 'Failed login'],
        'approve' => ['icon' => 'fa-check', 'badge' => 'bg-green-500/10 text-green-400', 'label' => 'Approved'],
        'reject' => ['icon' => 'fa-xmark', 'badge' => 'bg-red-500/10 text-red-400', 'label' => 'Rejected'],
        'promote' => ['icon' => 'fa-arrow-trend-up', 'badge' => 'bg-emerald-500/10 text-emerald-400', 'label' => 'Promoted'],
        'status_change' => ['icon' => 'fa-toggle-on', 'badge' => 'bg-blue-500/10 text-blue-400', 'label' => 'Status change'],
        'verify_email' => ['icon' => 'fa-envelope-circle-check', 'badge' => 'bg-emerald-500/10 text-emerald-400', 'label' => 'Email verified'],
        'resend_verification' => ['icon' => 'fa-envelope', 'badge' => 'bg-blue-500/10 text-blue-400', 'label' => 'Verification sent'],
        'settings_update' => ['icon' => 'fa-gear', 'badge' => 'bg-purple-500/10 text-purple-400', 'label' => 'Settings updated'],
        'announcement_create' => ['icon' => 'fa-bullhorn', 'badge' => 'bg-blue-500/10 text-blue-400', 'label' => 'Announcement'],
        'announcement_archive' => ['icon' => 'fa-box-archive', 'badge' => 'bg-gray-500/10 text-gray-400', 'label' => 'Archived'],
        'export' => ['icon' => 'fa-download', 'badge' => 'bg-cyan-500/10 text-cyan-400', 'label' => 'Exported'],
        default => ['icon' => 'fa-circle-info', 'badge' => 'bg-gray-500/10 text-gray-400', 'label' => ucwords(str_replace('_', ' ', $action))],
    };
}

function alPageUrl(int $page, array $filters): string
{
    $query = ['page' => $page];
    foreach (['module', 'action', 'actor', 'start_date', 'end_date'] as $key) {
        if (($filters[$key] ?? '') !== '') {
            $query[$key] = $filters[$key];
        }
    }
    return '?' . http_build_query($query);
}

function alCanRestore(string $module, string $action): bool
{
    $restorable = ['members', 'events', 'trainings', 'funds', 'achievements', 'event_documents', 'attendance'];
    return $action === 'void'
        && in_array($module, $restorable, true)
        && permissionValue($module, 'delete') === true;
}

$pageStart = $totalEvents === 0 ? 0 : ($offset + 1);
$pageEnd   = $totalEvents === 0 ? 0 : min($offset + $perPage, $totalEvents);
$csrfToken = arnisCsrfToken();
?>

<!DOCTYPE html>
<html lang="en" class="text-[13px]">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Log | Arnis Portal</title>
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

            <?php if ($loadError !== null): ?>
                <div class="bg-red-500/10 border border-red-500/30 text-red-400 rounded-2xl px-4 py-3 mb-6 text-xs sm:text-sm">
                    <i class="fas fa-exclamation-circle mr-2"></i><?= alE($loadError) ?>
                </div>
            <?php endif; ?>

            <div class="card-bg border-l-4 border-l-purple-500 rounded-2xl px-4 py-3 mb-6 text-xs text-gray-300">
                <i class="fas fa-shield-halved mr-2 text-purple-400"></i>
                Append-only audit trail. Every entry records who did what, when, and from which IP address. Entries cannot be edited or deleted from the application.
            </div>

            <!-- Stats Cards -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4 lg:gap-6 mb-6 lg:mb-8">
                <div class="card-bg p-4 sm:p-6 rounded-2xl sm:rounded-3xl text-center shadow-xl">
                    <i class="fas fa-clipboard-list text-xl sm:text-2xl mb-2 opacity-50"></i>
                    <div class="text-2xl sm:text-3xl lg:text-4xl font-bold"><?= $totalEvents ?></div>
                    <div class="text-[10px] sm:text-xs text-gray-400 uppercase tracking-widest mt-1">Matching Events</div>
                </div>
                <div class="card-bg p-4 sm:p-6 rounded-2xl sm:rounded-3xl text-center shadow-xl">
                    <i class="fas fa-calendar-day text-xl sm:text-2xl mb-2 text-blue-400 opacity-80"></i>
                    <div class="text-2xl sm:text-3xl lg:text-4xl font-bold"><?= $todayEvents ?></div>
                    <div class="text-[10px] sm:text-xs text-gray-400 uppercase tracking-widest mt-1">Today</div>
                </div>
                <div class="card-bg p-4 sm:p-6 rounded-2xl sm:rounded-3xl text-center shadow-xl">
                    <i class="fas fa-user-shield text-xl sm:text-2xl mb-2 text-yellow-400 opacity-80"></i>
                    <div class="text-2xl sm:text-3xl lg:text-4xl font-bold"><?= $securityEvents ?></div>
                    <div class="text-[10px] sm:text-xs text-gray-400 uppercase tracking-widest mt-1">Security Events</div>
                </div>
                <div class="card-bg p-4 sm:p-6 rounded-2xl sm:rounded-3xl text-center shadow-xl">
                    <i class="fas fa-users text-xl sm:text-2xl mb-2 text-green-400 opacity-80"></i>
                    <div class="text-2xl sm:text-3xl lg:text-4xl font-bold"><?= $uniqueActors ?></div>
                    <div class="text-[10px] sm:text-xs text-gray-400 uppercase tracking-widest mt-1">Unique Actors</div>
                </div>
            </div>

            <!-- Filters -->
            <div class="flex flex-col xl:flex-row justify-between items-stretch xl:items-center mb-6 gap-4">
                <form method="GET" id="activityFilterForm" class="flex flex-col sm:flex-row flex-wrap gap-2 sm:gap-3 flex-1">
                    <select name="module" class="card-bg border-none rounded-full px-4 sm:px-5 py-3 sm:py-4 text-xs sm:text-sm outline-none text-center w-full sm:w-44">
                        <option value="">All modules</option>
                        <?php foreach ($allowedModules as $module): ?>
                            <option value="<?= alE($module) ?>" <?= $filters['module'] === $module ? 'selected' : '' ?>><?= alE(alModuleLabel($module)) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <select name="action" class="card-bg border-none rounded-full px-4 sm:px-5 py-3 sm:py-4 text-xs sm:text-sm outline-none text-center w-full sm:w-40">
                        <option value="">All actions</option>
                        <?php foreach ($allowedActions as $action): ?>
                            <option value="<?= alE($action) ?>" <?= $filters['action'] === $action ? 'selected' : '' ?>><?= alE(alActionMeta($action)['label']) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <input type="text" name="actor" value="<?= alE($filters['actor']) ?>" maxlength="100" placeholder="Actor name" class="card-bg border-none rounded-full px-4 sm:px-5 py-3 sm:py-4 text-xs sm:text-sm outline-none text-center w-full sm:w-40">

                    <input type="date" name="start_date" value="<?= alE($filters['start_date']) ?>" aria-label="Start date" class="card-bg border-none rounded-full px-4 sm:px-5 py-3 sm:py-4 text-xs sm:text-sm outline-none text-center w-full sm:w-40">

                    <input type="date" name="end_date" value="<?= alE($filters['end_date']) ?>" aria-label="End date" class="card-bg border-none rounded-full px-4 sm:px-5 py-3 sm:py-4 text-xs sm:text-sm outline-none text-center w-full sm:w-40">

                    <div class="flex gap-2">
                        <button type="submit" class="flex-1 sm:flex-none bg-accent-purple hover:opacity-90 px-5 sm:px-6 py-4 sm:py-5 rounded-full font-bold text-xs sm:text-sm whitespace-nowrap transition">
                            <i class="fas fa-filter mr-1"></i> Apply
                        </button>
                        <?php if ($hasFilters): ?>
                        <a href="activity_log.php" class="flex-1 sm:flex-none bg-white/5 hover:bg-white/10 px-5 sm:px-6 py-4 sm:py-5 rounded-full font-bold text-xs sm:text-sm whitespace-nowrap transition text-center">
                            <i class="fas fa-redo mr-1"></i> Reset
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Log Table -->
            <div class="card-bg rounded-2xl sm:rounded-3xl overflow-hidden">
                <!-- Mobile cards -->
                <div class="block lg:hidden">
                    <div class="p-2 space-y-2">
                        <?php if (empty($events)): ?>
                            <div class="p-6 text-center text-gray-500">No audit events found.</div>
                        <?php else: ?>
                            <?php foreach ($events as $event):
                                $meta = alActionMeta((string)($event['action'] ?? ''));
                                $module = (string)($event['module'] ?? '');
                                $recordId = (int)($event['record_id'] ?? 0);
                                $canRestore = alCanRestore($module, (string)($event['action'] ?? ''));
                            ?>
                                <div class="bg-white/[0.02] rounded-xl p-4 border border-gray-800/50">
                                    <div class="flex items-center gap-2 mb-2">
                                        <span class="<?= $meta['badge'] ?> px-2 py-1 rounded-full text-[10px] font-bold uppercase">
                                            <i class="fas <?= $meta['icon'] ?> mr-1"></i><?= alE($meta['label']) ?>
                                        </span>
                                        <span class="bg-blue-500/10 text-blue-400 px-2 py-1 rounded-full text-[10px] font-semibold">
                                            <?= alE(alModuleLabel($module)) ?>
                                        </span>
                                    </div>
                                    <div class="text-sm font-semibold break-words mb-2"><?= alE((string)($event['description'] ?? '—')) ?></div>
                                    <div class="text-[10px] text-gray-400 space-y-1">
                                        <div><i class="fas fa-user w-4"></i> <?= alE((string)($event['actor_name'] ?? 'System')) ?> <span class="text-gray-600">(<?= alE((string)($event['actor_role'] ?? '—')) ?>)</span></div>
                                        <div><i class="fas fa-clock w-4"></i> <?= alE(alFormatDateTime($event['created_at'] ?? null)) ?></div>
                                        <div><i class="fas fa-network-wired w-4"></i> <?= alE((string)($event['ip_address'] ?? '—')) ?></div>
                                    </div>
                                    <?php if ($canRestore && $recordId > 0): ?>
                                    <button onclick="openRestoreModal(<?= alJsArg($module) ?>, <?= $recordId ?>, <?= alJsArg((string)($event['description'] ?? '')) ?>)"
                                            class="mt-3 w-full bg-green-500/10 text-green-400 text-center py-2 rounded-lg text-[10px] font-bold hover:bg-green-500 hover:text-white transition">
                                        <i class="fas fa-undo mr-1"></i> Restore
                                    </button>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Desktop table -->
                <div class="hidden lg:block overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead class="text-gray-400 text-xs uppercase tracking-widest border-b border-gray-700">
                            <tr>
                                <th class="p-4 lg:p-6">Date &amp; Time</th>
                                <th class="p-4 lg:p-6">Actor</th>
                                <th class="p-4 lg:p-6">Action</th>
                                <th class="p-4 lg:p-6">Module</th>
                                <th class="p-4 lg:p-6">Description</th>
                                <th class="p-4 lg:p-6">IP Address</th>
                                <th class="p-4 lg:p-6">Restore</th>
                            </tr>
                        </thead>
                        <tbody class="text-sm">
                            <?php if (empty($events)): ?>
                                <tr>
                                    <td colspan="7" class="p-6 text-center text-gray-500">No audit events found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($events as $event):
                                    $meta = alActionMeta((string)($event['action'] ?? ''));
                                    $module = (string)($event['module'] ?? '');
                                    $recordId = (int)($event['record_id'] ?? 0);
                                    $canRestore = alCanRestore($module, (string)($event['action'] ?? ''));
                                ?>
                                    <tr class="border-b border-gray-800 hover:bg-white/5 transition">
                                        <td class="p-4 lg:p-6 text-xs whitespace-nowrap"><?= alE(alFormatDateTime($event['created_at'] ?? null)) ?></td>
                                        <td class="p-4 lg:p-6">
                                            <div class="font-semibold"><?= alE((string)($event['actor_name'] ?? 'System')) ?></div>
                                            <div class="text-[10px] text-gray-500 uppercase"><?= alE((string)($event['actor_role'] ?? '—')) ?></div>
                                        </td>
                                        <td class="p-4 lg:p-6">
                                            <span class="<?= $meta['badge'] ?> px-3 py-1 rounded-full text-xs font-bold uppercase whitespace-nowrap">
                                                <i class="fas <?= $meta['icon'] ?> mr-1"></i><?= alE($meta['label']) ?>
                                            </span>
                                        </td>
                                        <td class="p-4 lg:p-6">
                                            <span class="px-2 py-1 rounded-full text-xs font-semibold bg-blue-500/10 text-blue-400 whitespace-nowrap">
                                                <?= alE(alModuleLabel($module)) ?>
                                            </span>
                                        </td>
                                        <td class="p-4 lg:p-6 text-xs max-w-md break-words"><?= alE((string)($event['description'] ?? '—')) ?></td>
                                        <td class="p-4 lg:p-6 text-xs text-gray-400 whitespace-nowrap"><?= alE((string)($event['ip_address'] ?? '—')) ?></td>
                                        <td class="p-4 lg:p-6">
                                            <?php if ($canRestore && $recordId > 0): ?>
                                                <button onclick="openRestoreModal(<?= alJsArg($module) ?>, <?= $recordId ?>, <?= alJsArg((string)($event['description'] ?? '')) ?>)"
                                                        class="bg-green-500/10 text-green-400 hover:bg-green-500 hover:text-white px-3 py-1 rounded-full text-xs font-bold transition whitespace-nowrap">
                                                    <i class="fas fa-undo mr-1"></i> Restore
                                                </button>
                                            <?php else: ?>
                                                <span class="text-gray-600 text-xs">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                <div class="px-4 py-4 border-t border-gray-800 flex justify-between items-center flex-wrap gap-3">
                    <div class="text-[10px] text-gray-500 uppercase">
                        Showing <?= $pageStart ?> to <?= $pageEnd ?> of <?= $totalEvents ?> events
                    </div>
                    <div class="flex gap-2">
                        <?php if ($page > 1): ?>
                        <a href="<?= alE(alPageUrl(1, $filters)) ?>" class="bg-white/5 hover:bg-white/10 px-3 py-2 rounded-lg text-[10px] font-bold transition">
                            <i class="fas fa-chevron-left mr-1"></i>First
                        </a>
                        <a href="<?= alE(alPageUrl($page - 1, $filters)) ?>" class="bg-white/5 hover:bg-white/10 px-3 py-2 rounded-lg text-[10px] font-bold transition">
                            <i class="fas fa-chevron-left mr-1"></i>Prev
                        </a>
                        <?php endif; ?>

                        <span class="px-3 py-2 text-[10px] font-bold text-gray-400">
                            Page <?= $page ?> of <?= $totalPages ?>
                        </span>

                        <?php if ($page < $totalPages): ?>
                        <a href="<?= alE(alPageUrl($page + 1, $filters)) ?>" class="bg-white/5 hover:bg-white/10 px-3 py-2 rounded-lg text-[10px] font-bold transition">
                            Next<i class="fas fa-chevron-right ml-1"></i>
                        </a>
                        <a href="<?= alE(alPageUrl($totalPages, $filters)) ?>" class="bg-white/5 hover:bg-white/10 px-3 py-2 rounded-lg text-[10px] font-bold transition">
                            Last<i class="fas fa-chevron-right ml-1"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Restore Confirmation Modal -->
    <div id="restoreConfirmationModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4">
        <div id="restoreConfirmationCard"
             class="card-bg w-full max-w-md rounded-3xl transform transition-all duration-300 ease-out scale-95 opacity-0 shadow-2xl">
            <div class="px-8 py-6 border-b border-gray-800 flex justify-between items-center">
                <h2 class="text-xl tracking-wide font-bold italic uppercase text-green-400">RESTORE RECORD</h2>
                <button type="button" onclick="closeRestoreModal()" class="text-gray-500 hover:text-white transition">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>

            <div class="p-8">
                <div class="flex items-center gap-4 mb-6">
                    <div class="flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full bg-green-500/10">
                        <i class="fas fa-undo text-green-400 text-lg"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-bold text-white mb-1">Restore Record</h3>
                        <p class="text-sm text-gray-400">Are you sure you want to restore this record?</p>
                    </div>
                </div>

                <div class="bg-white/5 rounded-xl p-4 mb-6 border border-gray-800/50">
                    <div class="text-sm text-gray-500 mb-1">Record Details</div>
                    <div class="text-sm font-bold text-white mb-2 break-words" id="restoreRecordName"></div>
                    <div class="text-xs text-gray-400" id="restoreRecordType"></div>
                </div>

                <div class="flex gap-4">
                    <button type="button" onclick="closeRestoreModal()"
                            class="flex-1 px-6 py-3 bg-white/5 rounded-3xl hover:bg-white/10 transition text-sm font-semibold">
                        CANCEL
                    </button>
                    <button type="button" id="confirmRestoreBtn" onclick="confirmRestore()"
                            class="flex-1 px-6 py-3 bg-green-600 rounded-3xl hover:bg-green-700 transition text-sm font-semibold shadow-lg shadow-green-900/20">
                        <i class="fas fa-undo mr-2"></i> YES, RESTORE
                    </button>
                </div>
            </div>
        </div>
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

document.addEventListener('DOMContentLoaded', function () {
    const filterForm = document.getElementById('activityFilterForm');
    if (!filterForm) {
        return;
    }

    const startDateInput = filterForm.querySelector('input[name="start_date"]');
    const endDateInput = filterForm.querySelector('input[name="end_date"]');

    filterForm.addEventListener('submit', function (e) {
        if (startDateInput && endDateInput && startDateInput.value && endDateInput.value) {
            if (new Date(startDateInput.value) > new Date(endDateInput.value)) {
                e.preventDefault();
                alToast('End date cannot be earlier than start date.', 'error');
            }
        }
    });
});

/* ------------------------------------------------------------------ toast */
function alToast(message, type) {
    let container = document.getElementById('toastContainer');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toastContainer';
        document.body.appendChild(container);
    }

    const toast = document.createElement('div');
    toast.className =
        'fixed top-6 left-1/2 -translate-x-1/2 px-6 py-4 rounded-xl shadow-2xl border flex items-center gap-3 ' +
        'transform transition-all duration-300 ease-out z-[9999] ' +
        (type === 'success'
            ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-400'
            : 'bg-amber-500/10 border-amber-500/30 text-amber-400');

    const icon = document.createElement('i');
    icon.className = type === 'success'
        ? 'fas fa-check-circle text-lg'
        : 'fas fa-exclamation-circle text-lg';

    const text = document.createElement('span');
    text.className = 'text-sm font-semibold';
    text.textContent = String(message == null ? '' : message);

    toast.appendChild(icon);
    toast.appendChild(text);
    container.appendChild(toast);

    setTimeout(function () {
        toast.remove();
    }, 3200);
}

/* ---------------------------------------------------------- restore modal */
const AL_CSRF_TOKEN = <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?>;

let currentRestoreTable = '';
let currentRestoreId = 0;

function openRestoreModal(table, id, recordName) {
    const numericId = parseInt(id, 10);
    if (!Number.isFinite(numericId) || numericId <= 0) {
        alToast('Invalid record reference.', 'error');
        return;
    }

    currentRestoreTable = String(table || '').toLowerCase();
    currentRestoreId = numericId;

    document.getElementById('restoreRecordName').textContent = String(recordName == null ? '' : recordName);
    document.getElementById('restoreRecordType').textContent =
        'Type: ' + currentRestoreTable.charAt(0).toUpperCase() + currentRestoreTable.slice(1).replace(/_/g, ' ');

    const modal = document.getElementById('restoreConfirmationModal');
    const card = document.getElementById('restoreConfirmationCard');

    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';

    requestAnimationFrame(function () {
        card.classList.remove('scale-95', 'opacity-0');
        card.classList.add('scale-100', 'opacity-100');
    });
}

function closeRestoreModal() {
    const modal = document.getElementById('restoreConfirmationModal');
    const card = document.getElementById('restoreConfirmationCard');

    card.classList.remove('scale-100', 'opacity-100');
    card.classList.add('scale-95', 'opacity-0');
    document.body.style.overflow = 'auto';

    setTimeout(function () {
        modal.classList.add('hidden');
        currentRestoreTable = '';
        currentRestoreId = 0;
    }, 300);
}

async function confirmRestore() {
    if (!currentRestoreTable || !currentRestoreId) {
        closeRestoreModal();
        return;
    }

    const restoreBtn = document.getElementById('confirmRestoreBtn');
    const originalHtml = restoreBtn.innerHTML;
    restoreBtn.disabled = true;
    restoreBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> RESTORING...';

    const formData = new FormData();
    formData.append('table', currentRestoreTable);
    formData.append('id', String(currentRestoreId));
    formData.append('csrf_token', AL_CSRF_TOKEN);

    try {
        const response = await fetch('../actions/restore_record.php', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        });

        const text = await response.text();
        let result = null;
        try {
            result = JSON.parse(text);
        } catch (err) {
            result = null;
        }

        if (result && result.success) {
            alToast(result.message || 'Record restored successfully.', 'success');
            closeRestoreModal();
            setTimeout(function () {
                window.location.reload();
            }, 900);
            return;
        }

        restoreBtn.disabled = false;
        restoreBtn.innerHTML = originalHtml;
        alToast((result && (result.error || result.message)) || 'Restore failed.', 'error');
    } catch (err) {
        restoreBtn.disabled = false;
        restoreBtn.innerHTML = originalHtml;
        alToast('Could not reach the server. Please try again.', 'error');
    }
}

document.addEventListener('keydown', function (e) {
    const modal = document.getElementById('restoreConfirmationModal');
    if (e.key === 'Escape' && modal && !modal.classList.contains('hidden')) {
        closeRestoreModal();
    }
});

document.addEventListener('click', function (event) {
    const modal = document.getElementById('restoreConfirmationModal');
    if (modal && event.target === modal) {
        closeRestoreModal();
    }
});
</script>
</html>
`````

### Changed 17/19: `htdocs/public/attendance_session.php`

`````php
<?php
require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/Trainings.php';
require_once __DIR__ . '/../classes/Attendance.php';
require_once __DIR__ . '/../classes/Settings.php';
require_once __DIR__ . '/../includes/checkin_window.php';

/*
 * config/permissions.php is the single RBAC source of truth. The inline matrix
 * that used to live here covered only 5 roles across 2 modules.
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

if (!canAccess('trainings', 'read')) {
    http_response_code(403);
    die('Access Denied: You do not have permission to view trainings.');
}

/*
 * Members reach this page only to self check-in with face + geolocation, never
 * to edit another athlete's record: $selfCheckinMode hides every staff control
 * and scopes the roster to the viewer's own row. Applicants have no
 * trainings.read, so they are already stopped by the gate above.
 */
$attendanceScope = permissionValue('attendance', 'read');
if ($attendanceScope === false) {
    http_response_code(403);
    die('Access Denied: You do not have permission to view this attendance session.');
}
$selfCheckinMode = ($attendanceScope === 'own');
/* Resolved after the DB connection below (session link, then name fallback). */
$viewerMemberId = 0;
/* Filled in below once the roster is loaded; drives the header + stats card. */
$ownAttendance = null;

/* Flash messages written by update_attendance.php / remove_from_training.php. */
$flashSuccess = $_SESSION['success'] ?? null;
$flashError = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);

/*
 * CRITICAL: this was passed to Trainings::getById(int $trainingId) straight
 * from $_GET, so ?id=abc or ?id[]=1 raised a TypeError and returned HTTP 500
 * with a blank page.
 */
$rawId = $_GET['id'] ?? null;
if (!is_string($rawId) || !is_numeric($rawId) || (int)$rawId <= 0) {
    $_SESSION['error'] = 'Invalid training session reference.';
    header('Location: trainings.php');
    exit;
}

$trainingId = (int)$rawId;

$requestedPage = (isset($_GET['page']) && is_numeric($_GET['page'])) ? max(1, (int)$_GET['page']) : 1;
$perPage = 10;

try {
    $db = (new Database())->connect();
    $trainingsModel = new Trainings($db);
    $attendanceModel = new Attendance($db);

    if ($selfCheckinMode) {
        $viewerMemberId = function_exists('resolveSessionMemberId') ? resolveSessionMemberId($db) : 0;
    }

    $training = $trainingsModel->getById($trainingId);
    if ($training === null) {
        // Render error inline instead of redirecting (redirect can fail if headers already sent)
        $trainingNotFound = true;
    } else {
        $trainingNotFound = false;
        $totalAttendance = (int)$attendanceModel->countByTrainingId($trainingId);
        $totalPages = $totalAttendance > 0 ? (int)ceil($totalAttendance / $perPage) : 1;

        /* ?page=999 used to render an empty roster labelled "Page 999 of 1". */
        $page = min($requestedPage, max(1, $totalPages));
        $offset = ($page - 1) * $perPage;

        $attendanceList = $attendanceModel->getByTrainingId($trainingId, $perPage, $offset);
        if (!is_array($attendanceList)) {
            $attendanceList = [];
        }

        $stats = $attendanceModel->getAttendanceStats($trainingId);
        if (!is_array($stats)) {
            $stats = [];
        }
        $presentCount = (int)($stats['present_count'] ?? 0);
        $lateCount = (int)($stats['late_count'] ?? 0);
        $absentCount = (int)($stats['absent_count'] ?? 0);

        $availableMembers = $attendanceModel->getMembersNotInTraining($trainingId);
        if (!is_array($availableMembers)) {
            $availableMembers = [];
        }

        /*
         * attendance.read = 'own' (Member): scope the roster to the viewer's
         * own record so no other athlete's attendance is exposed, and drop the
         * staff-only "eligible to add" list. attendance.read = 'own_trainees'
         * (Coach) keeps the full roster.
         */
        if ($selfCheckinMode) {
            $availableMembers = [];
            $ownRows = [];
            if ($viewerMemberId > 0) {
                foreach ($attendanceModel->getByTrainingId($trainingId) as $row) {
                    if ((int)($row['member_id'] ?? 0) === $viewerMemberId) {
                        $ownRows[] = $row;
                        break;
                    }
                }
            }
            $attendanceList = $ownRows;
            $ownAttendance = $ownRows[0] ?? null;
            if ($ownAttendance === null && ($training['training_type'] ?? '') === 'Private') {
                http_response_code(403);
                exit('Access Denied: This is another member\'s private session.');
            }
            $settingsModel = new Settings($db);
            $checkinWindow = arnisCheckinWindow(
                (string)($training['training_date'] ?? ''),
                $training['start_time'] ?? null,
                $training['end_time'] ?? null,
                (int)$settingsModel->get('checkin_open_minutes', '30'),
                (int)$settingsModel->get('late_grace_minutes', '15')
            );
            $totalAttendance = count($ownRows);
            $totalPages = 1;
            $page = 1;
            $offset = 0;
        }
    }
} catch (Throwable $e) {
    error_log('attendance_session.php error: ' . $e->getMessage());
    die('Error loading the training session. Please contact the administrator.');
}

// Default values when training not found
if (isset($trainingNotFound) && $trainingNotFound) {
    $totalAttendance = 0;
    $totalPages = 1;
    $page = 1;
    $offset = 0;
    $attendanceList = [];
    $presentCount = 0;
    $lateCount = 0;
    $absentCount = 0;
    $availableMembers = [];
    // Create a minimal training array for the "Back" link and page title
    $training = [
        'training_id' => $trainingId,
        'location' => 'Unknown',
        'training_type' => 'Regular',
        'target_group' => 'Mixed',
        'venue_type' => 'Custom',
        'fee_amount' => 0,
        'start_time' => null,
        'end_time' => null,
        'description' => '',
        'coach_name' => null,
        'coach_rank' => null,
        'coach_type' => null,
        'created_at' => null,
        'updated_at' => null,
    ];
}

/*
 * Marking + roster changes are staff-only (Admin/Officer full write, Coach
 * own trainees). Member holds attendance.write = 'own' (self check-in only,
 * via the upcoming face + GPS flow), which canAccess() alone reports as
 * granted — so the strict scope check below is what actually disables every
 * Present/Late/Absent/Add/Remove control for Members.
 */
$staffAttendanceWrite = in_array(permissionValue('attendance', 'write'), [true, 'own_trainees'], true);
$canWriteAttendance = $staffAttendanceWrite;
$canDeleteAttendance = canAccess('attendance', 'delete');
$canWriteTraining = canAccess('trainings', 'write');
$csrfToken = arnisCsrfToken();

/* as* prefix: this page includes modals that define their own helpers. */
function asE(?string $value, string $fallback = ''): string
{
    $value = trim((string)$value);
    return htmlspecialchars($value !== '' ? $value : $fallback, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function asFormatDate(?string $date, string $fallback = 'TBA'): string
{
    $date = trim((string)$date);
    if ($date === '' || !preg_match('/^(\d{4}-\d{2}-\d{2})/', $date, $m) || str_starts_with($m[1], '0000-00-00')) {
        return $fallback;
    }
    $parsed = DateTime::createFromFormat('!Y-m-d', $m[1]);
    $errors = DateTime::getLastErrors();
    if ($parsed === false || ($errors !== false && $errors['warning_count'] > 0)) {
        return $fallback;
    }
    return $parsed->format('M d, Y');
}

/** trainings.start_time / end_time are `time DEFAULT NULL`. */
function asFormatTime(?string $time, string $fallback = 'TBA'): string
{
    $time = trim((string)$time);
    if ($time === '') {
        return $fallback;
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
    return $timestamp === false ? $fallback : date('h:i A', $timestamp);
}

function asTypeClass(?string $type): string
{
    switch ($type) {
        case 'Private':
            return 'bg-blue-500/10 text-blue-400 border border-blue-500/20';
        case 'Custom':
            return 'bg-pink-500/10 text-pink-300 border border-pink-500/20';
        default:
            return 'bg-purple-500/10 text-purple-400 border border-purple-500/20';
    }
}

function asGroupClass(?string $group): string
{
    switch ($group) {
        case 'Private':
            return 'text-yellow-300';
        case 'Mixed':
            return 'text-pink-300';
        default:
            return 'text-green-300';
    }
}

function asInitials(?string $name): string
{
    $name = trim((string)$name);
    if ($name === '') {
        return '??';
    }
    $parts = preg_split('/\s+/', $name) ?: [];
    if (count($parts) >= 2) {
        return strtoupper(substr($parts[0], 0, 1) . substr($parts[1], 0, 1));
    }
    return strtoupper(substr($name, 0, 2));
}

/**
 * removeMember(trainingId, memberId, name)
 *
 * The old code used addslashes($full_name) inside onclick="...". addslashes
 * emits a backslash-quote, but the HTML parser terminates a double-quoted
 * attribute on a raw quote and ignores the backslash entirely, so a member name
 * containing " broke out of the attribute - stored XSS reachable through the
 * member form. json_encode with the HEX flags leaves nothing to break out with.
 */
function asRemoveOnclick(int $trainingId, int $memberId, ?string $name, bool $allowed): string
{
    if (!$allowed) {
        return 'return false;';
    }

    return 'removeMember(' . $trainingId . ', ' . $memberId . ', '
        . json_encode(trim((string)$name), JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP)
        . ')';
}

function asStatusOnclick(int $trainingId, int $memberId, string $status, bool $allowed): string
{
    if (!$allowed) {
        return 'return false;';
    }
    return 'updateAttendance(' . $trainingId . ', ' . $memberId . ', '
        . json_encode($status, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP)
        . ')';
}

$sessionPageLink = static function (int $target) use ($trainingId): string {
    return 'attendance_session.php?id=' . $trainingId . '&page=' . $target;
};
?>

<!DOCTYPE html>
<html lang="en" class="text-[13px]">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Training Details | Arnis Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" type="image/x-icon" href="../arnis-favicon.ico">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,100..900;1,100..900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
    <link rel="stylesheet" href="css/sidebar.css">
    <link rel="stylesheet" href="css/style.css">
    <style>
        #editMap { z-index: 10; }
        .leaflet-container { background: #1d212a; }
    </style>
</head>
<body class="text-white bg-dark-purple overflow-x-hidden">
    <div class="flex">
        <?php include_once __DIR__ . '/../includes/sidebar.php'; ?>

        <main id="page" class="flex-1 p-4 sm:p-6 lg:p-8 h-screen overflow-y-auto opacity-0 translate-x-8 transition-all duration-500 ease-out">
            <?php include_once __DIR__ . '/../includes/header.php'; ?>

            <?php if ($flashSuccess !== null || $flashError !== null): ?>
            <div class="mb-6 p-4 rounded-2xl border text-sm font-semibold <?= $flashError !== null ? 'bg-red-500/10 border-red-500/20 text-red-300' : 'bg-emerald-500/10 border-emerald-500/20 text-emerald-300' ?>">
                <i class="fas <?= $flashError !== null ? 'fa-exclamation-triangle' : 'fa-check-circle' ?> mr-2"></i>
                <?= asE($flashError !== null ? (string)$flashError : (string)$flashSuccess) ?>
            </div>
            <?php endif; ?>

            <?php if (isset($trainingNotFound) && $trainingNotFound): ?>
            <div class="mb-6 p-6 rounded-2xl border bg-red-500/10 border-red-500/20 text-red-300 text-center">
                <i class="fas fa-exclamation-triangle text-3xl mb-3"></i>
                <h2 class="text-lg font-bold mb-2">Training Session Not Found</h2>
                <p class="text-sm mb-4">The training session you're looking for (ID: #TRN-<?= str_pad((string)(int)$trainingId, 4, '0', STR_PAD_LEFT) ?>) does not exist or has been voided.</p>
                <a href="trainings.php" class="inline-flex items-center gap-2 px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-full text-sm font-bold transition">
                    <i class="fas fa-arrow-left"></i> Back to Trainings
                </a>
            </div>
            <?php endif; ?>

            <!-- Header -->
            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 sm:mb-8 gap-4">
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 sm:mb-8 gap-4 w-full">
                    <div class="flex items-center gap-3 sm:gap-4">
                        <a href="trainings.php" class="card-bg w-8 h-8 sm:w-10 sm:h-10 rounded-full flex items-center justify-center hover:bg-gray-800 transition flex-shrink-0">
                            <i class="fas fa-arrow-left text-xs sm:text-sm"></i>
                        </a>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-3">
                                <h1 class="text-lg sm:text-xl lg:text-2xl font-bold italic uppercase tracking-wider truncate">
                                    <?= asE($training['location'] ?? null, 'Training Session') ?>
                                </h1>
                                <span class="<?= asTypeClass($training['training_type'] ?? null) ?> px-2 sm:px-3 py-1 rounded-full text-[9px] sm:text-[10px] font-bold uppercase border w-fit">
                                    <?= asE(strtoupper(trim((string)($training['training_type'] ?? ''))), 'REGULAR') ?>
                                </span>
                            </div>
                            <p class="text-[9px] sm:text-[10px] text-gray-500 uppercase tracking-widest mt-1">Training ID: #TRN-<?= str_pad((string)(int)$trainingId, 4, '0', STR_PAD_LEFT) ?></p>
                        </div>
                    </div>

                    <?php if ($selfCheckinMode): ?>
                        <?php if (!(isset($trainingNotFound) && $trainingNotFound)): ?>
                            <?php if ($ownAttendance !== null && (string)($ownAttendance['status'] ?? '') !== 'Absent'): ?>
                            <span class="px-4 py-2 rounded-full text-[10px] font-bold uppercase tracking-wider flex items-center gap-2 whitespace-nowrap bg-emerald-500/15 border border-emerald-500/30 text-emerald-300">
                                <i class="fas fa-check-circle text-xs"></i>
                                Checked In: <?= asE((string)($ownAttendance['status'] ?? ''), 'Recorded') ?>
                            </span>
                            <?php elseif ($viewerMemberId > 0 && $ownAttendance === null): ?>
                            <span class="px-4 py-2 rounded-full text-[10px] font-bold uppercase tracking-wider flex items-center gap-2 whitespace-nowrap bg-gray-500/15 border border-gray-500/30 text-gray-300">
                                <i class="fas fa-user-slash text-xs"></i>
                                Not On Roster
                            </span>
                            <?php elseif ($viewerMemberId > 0 && ($checkinWindow['state'] ?? '') === 'closed'): ?>
                            <span class="px-4 py-2 rounded-full text-[10px] font-bold uppercase tracking-wider flex items-center gap-2 whitespace-nowrap bg-red-500/15 border border-red-500/30 text-red-300">
                                <i class="fas fa-circle-xmark text-xs"></i>
                                Absent
                            </span>
                            <?php elseif ($viewerMemberId > 0 && ($checkinWindow['state'] ?? '') === 'upcoming'): ?>
                            <span class="px-4 py-2 rounded-full text-[10px] font-bold uppercase tracking-wider flex items-center gap-2 whitespace-nowrap bg-blue-500/15 border border-blue-500/30 text-blue-300">
                                <i class="fas fa-clock text-xs"></i>
                                Check-in opens <?= asE(arnisFormatManila('M d, h:i A', (int)$checkinWindow['opens_at'])) ?>
                            </span>
                            <?php elseif ($viewerMemberId > 0): ?>
                            <button type="button"
                                    onclick="openCheckinModal(<?= (int)$trainingId ?>, <?= (int)$viewerMemberId ?>)"
                                    class="px-4 py-2 rounded-full text-[10px] font-bold uppercase tracking-wider transition flex items-center gap-2 whitespace-nowrap bg-gradient-to-r from-purple-600 to-pink-600 hover:from-purple-700 hover:to-pink-700 text-white shadow-lg shadow-purple-900/30 cursor-pointer">
                                <i class="fas fa-user-check text-xs"></i>
                                Check In: Face + GPS
                            </button>
                            <?php else: ?>
                            <span class="px-4 py-2 rounded-full text-[10px] font-bold uppercase tracking-wider flex items-center gap-2 whitespace-nowrap bg-amber-500/15 border border-amber-500/30 text-amber-300">
                                <i class="fas fa-link-slash text-xs"></i>
                                Account Not Linked
                            </span>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php else: ?>
                    <!-- Edit Button. modals/edit_training.php reads $training and
                         calls $trainingsModel->getCoaches() itself, so both
                         variables stay in scope here. -->
                    <button type="button" onclick="openEditTrainingModal()"
                            class="px-4 py-2 rounded-full text-[10px] font-bold uppercase tracking-wider transition flex items-center gap-2 whitespace-nowrap <?= $canWriteTraining ? 'bg-purple-600/20 hover:bg-purple-600/30 border border-purple-500/30 text-purple-300 hover:text-purple-200 cursor-pointer' : 'bg-gray-700/20 border border-gray-700/30 text-gray-600 cursor-not-allowed' ?>"
                            <?= $canWriteTraining ? '' : 'disabled' ?>
                            title="<?= $canWriteTraining ? 'Edit this training session' : 'No permission to edit trainings' ?>">
                        <i class="fas fa-edit text-xs"></i>
                        Edit Session
                    </button>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!isset($trainingNotFound) || !$trainingNotFound): ?>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 sm:gap-6 lg:gap-8">

                <!-- Session Info -->
                <div class="lg:col-span-1 space-y-4 sm:space-y-6">
                    <div class="card-bg p-6 sm:p-8 rounded-2xl sm:rounded-3xl">
                        <h3 class="text-gray-500 text-[10px] font-black uppercase tracking-[0.2em] mb-4 sm:mb-6">Session Info</h3>

                        <div class="space-y-4 sm:space-y-6">
                            <div class="flex items-center gap-3 sm:gap-4">
                                <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-lg sm:rounded-xl bg-blue-500/10 flex items-center justify-center text-blue-400 flex-shrink-0">
                                    <i class="fas fa-calendar-day text-xs sm:text-sm"></i>
                                </div>
                                <div>
                                    <div class="text-xs sm:text-sm font-bold"><?= asE(asFormatDate($training['training_date'] ?? null)) ?></div>
                                    <div class="text-[9px] sm:text-[10px] text-gray-500 uppercase font-medium">Date</div>
                                </div>
                            </div>

                            <div class="flex items-center gap-3 sm:gap-4">
                                <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-lg sm:rounded-xl bg-green-500/10 flex items-center justify-center text-green-400 flex-shrink-0">
                                    <i class="fas fa-clock text-xs sm:text-sm"></i>
                                </div>
                                <div>
                                    <div class="text-xs sm:text-sm font-bold">
                                        <?= asE(asFormatTime($training['start_time'] ?? null)) ?> - <?= asE(asFormatTime($training['end_time'] ?? null)) ?>
                                    </div>
                                    <div class="text-[9px] sm:text-[10px] text-gray-500 uppercase font-medium">Schedule</div>
                                </div>
                            </div>

                            <div class="flex items-center gap-3 sm:gap-4">
                                <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-lg sm:rounded-xl bg-orange-500/10 flex items-center justify-center text-orange-400 flex-shrink-0">
                                    <i class="fas fa-map-marker-alt text-xs sm:text-sm"></i>
                                </div>
                                <div>
                                    <div class="text-xs sm:text-sm font-bold"><?= asE($training['location'] ?? null, 'N/A') ?></div>
                                    <div class="text-[9px] sm:text-[10px] text-gray-500 uppercase font-medium">Location</div>
                                </div>
                            </div>

                            <div class="flex items-center gap-3 sm:gap-4">
                                <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-lg sm:rounded-xl bg-purple-500/10 flex items-center justify-center text-purple-400 flex-shrink-0">
                                    <i class="fas fa-user-tie text-xs sm:text-sm"></i>
                                </div>
                                <div>
                                    <!-- coach_name comes from a LEFT JOIN, so it is
                                         NULL whenever no coach is assigned. -->
                                    <div class="text-xs sm:text-sm font-bold"><?= asE($training['coach_name'] ?? null, 'Unassigned') ?></div>
                                    <div class="text-[9px] sm:text-[10px] text-gray-500 uppercase font-medium">Lead Coach</div>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-2">
                                <div class="bg-black/20 p-3 rounded-xl border border-gray-800">
                                    <div class="text-[9px] sm:text-[10px] text-gray-500 uppercase font-medium mb-1">Target Group</div>
                                    <div class="text-xs sm:text-sm font-bold <?= asGroupClass($training['target_group'] ?? null) ?>">
                                        <?= asE($training['target_group'] ?? null, 'Mixed') ?>
                                    </div>
                                </div>
                                <div class="bg-black/20 p-3 rounded-xl border border-gray-800">
                                    <div class="text-[9px] sm:text-[10px] text-gray-500 uppercase font-medium mb-1">Venue Type</div>
                                    <div class="text-xs sm:text-sm font-bold text-gray-200"><?= asE($training['venue_type'] ?? null, 'Custom') ?></div>
                                </div>
                                <div class="bg-black/20 p-3 rounded-xl border border-gray-800 sm:col-span-2">
                                    <div class="text-[9px] sm:text-[10px] text-gray-500 uppercase font-medium mb-1">Fee Setup</div>
                                    <div class="text-xs sm:text-sm font-bold text-blue-300">&#8369;<?= number_format((float)($training['fee_amount'] ?? 0), 2, '.', ',') ?></div>
                                    <div class="text-[9px] text-gray-500 mt-1">Public sessions are typically free. Private sessions can use a custom fee.</div>
                                </div>
                            </div>
                        </div>

                        <?php if (trim((string)($training['description'] ?? '')) !== ''): ?>
                            <div class="mt-6 sm:mt-8 pt-6 sm:pt-8 border-t border-gray-800">
                                <h4 class="text-[10px] sm:text-xs font-bold text-gray-400 uppercase tracking-widest mb-2 sm:mb-3">Training Notes</h4>
                                <div class="text-xs sm:text-sm text-gray-400 leading-relaxed italic bg-black/20 p-3 sm:p-4 rounded-xl sm:rounded-2xl border-l-2 border-purple-500">
                                    "<?= asE($training['description'] ?? null) ?>"
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Stats Card -->
                    <?php if ($selfCheckinMode): ?>
                    <?php
                    $ownStatus = $ownAttendance !== null ? (string)($ownAttendance['status'] ?? '') : '';
                    $ownStatusClass = $ownStatus === 'Present'
                        ? 'text-green-400'
                        : ($ownStatus === 'Late' ? 'text-orange-400' : 'text-red-400');
                    ?>
                    <div class="card-bg p-6 sm:p-8 rounded-2xl sm:rounded-3xl text-center">
                        <div class="text-[9px] sm:text-[10px] text-gray-500 uppercase tracking-[0.2em] mb-3">Your Attendance</div>
                        <?php if ($ownAttendance !== null && (string)($ownAttendance['status'] ?? '') !== 'Absent'): ?>
                            <div class="text-2xl font-bold <?= $ownStatusClass ?>"><?= asE($ownStatus, 'Recorded') ?></div>
                            <div class="text-[10px] text-gray-500 mt-2">Your recorded status for this session</div>
                        <?php elseif ($viewerMemberId > 0 && $ownAttendance === null): ?>
                            <div class="text-lg font-bold text-gray-400">Not On Roster</div>
                            <div class="text-[10px] text-gray-500 mt-2">Only members added to this session by a coach can check in.</div>
                        <?php elseif ($viewerMemberId > 0 && ($checkinWindow['state'] ?? '') === 'closed'): ?>
                            <div class="text-2xl font-bold text-red-400">Absent</div>
                            <div class="text-[10px] text-gray-500 mt-2">Check-in closed without a check-in from you.</div>
                        <?php elseif ($viewerMemberId > 0 && ($checkinWindow['state'] ?? '') === 'upcoming'): ?>
                            <div class="text-lg font-bold text-gray-400">Not Open Yet</div>
                            <div class="text-[10px] text-gray-500 mt-2">Check-in opens <?= asE(arnisFormatManila('M d, Y h:i A', (int)$checkinWindow['opens_at'])) ?> and closes <?= asE(arnisFormatManila('h:i A', (int)$checkinWindow['closes_at'])) ?>.</div>
                        <?php elseif ($viewerMemberId > 0): ?>
                            <div class="text-lg font-bold text-gray-400">Not Checked In Yet</div>
                            <div class="text-[10px] text-gray-500 mt-2">Check-in is open until <?= asE(arnisFormatManila('h:i A', (int)$checkinWindow['closes_at'])) ?>. Use "Check In: Face + GPS".</div>
                        <?php else: ?>
                            <div class="text-lg font-bold text-amber-300">Account Not Linked</div>
                            <div class="text-[10px] text-gray-500 mt-2">Your login is not linked to a member record. Ask an administrator to link it under Users &#8594; Edit User.</div>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <div class="card-bg p-4 sm:p-6 rounded-2xl sm:rounded-3xl flex justify-around text-center">
                        <div>
                            <div class="text-lg sm:text-xl font-bold text-green-400"><?= $presentCount ?></div>
                            <div class="text-[9px] sm:text-[10px] text-gray-500 uppercase">Present</div>
                        </div>
                        <div class="w-px h-6 sm:h-8 bg-gray-800 self-center"></div>
                        <div>
                            <div class="text-lg sm:text-xl font-bold text-orange-400"><?= $lateCount ?></div>
                            <div class="text-[9px] sm:text-[10px] text-gray-500 uppercase">Late</div>
                        </div>
                        <div class="w-px h-6 sm:h-8 bg-gray-800 self-center"></div>
                        <div>
                            <div class="text-lg sm:text-xl font-bold text-red-400"><?= $absentCount ?></div>
                            <div class="text-[9px] sm:text-[10px] text-gray-500 uppercase">Absent</div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Attendance List -->
                <div class="lg:col-span-2">
                    <div class="card-bg rounded-2xl sm:rounded-3xl overflow-hidden">
                    <div class="p-4 sm:p-6 border-b border-gray-800 bg-white/5">
                        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-4">
                            <div>
                                <h3 class="font-bold tracking-wide text-xs sm:text-sm uppercase"><?= $selfCheckinMode ? 'Your Attendance' : 'Attendance Roster' ?></h3>
                                <p class="text-[9px] sm:text-[10px] text-gray-500 mt-1">
                                    <?= $selfCheckinMode
                                        ? 'Your own record for this session'
                                        : 'Update status for each member • ' . count($availableMembers) . ' eligible to add' ?>
                                </p>
                            </div>
                            <?php if (!$selfCheckinMode): ?>
                            <button type="button" onclick="openAddPeopleModal()"
                                    class="text-white border border-gray-700 px-4 py-2 rounded-full text-[10px] font-bold uppercase tracking-wider transition whitespace-nowrap <?= $canWriteAttendance ? 'bg-white/5 hover:bg-white/10 cursor-pointer' : 'bg-gray-800 opacity-50 cursor-not-allowed' ?>"
                                    <?= $canWriteAttendance ? '' : 'disabled' ?>
                                    title="<?= $canWriteAttendance ? 'Add members to this session' : 'No permission to add members' ?>">
                                <i class="fas fa-user-plus mr-2 text-purple-400"></i> Add People
                            </button>
                            <?php endif; ?>
                        </div>

                        <!-- Search Bar -->
                        <div class="relative max-w-md">
                            <input type="search" id="attendanceSearch"
                                placeholder="Search by name or rank..."
                                autocomplete="off"
                                class="w-full bg-gray-900/60 border border-gray-700 rounded-xl pl-10 pr-4 py-2.5 text-sm text-white placeholder-gray-500 focus:outline-none focus:border-purple-500/50 transition">
                            <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-500 text-sm"></i>
                        </div>
                    </div>

                        <div class="p-2 max-h-[600px] overflow-y-auto" id="attendanceList">
                            <?php if (empty($attendanceList)): ?>
                                <div class="p-8 text-center text-gray-500">
                                    <i class="fas fa-user-check text-3xl mb-3 opacity-30"></i>
                                    <?php if ($selfCheckinMode): ?>
                                        <p class="text-sm">You have not checked in yet</p>
                                        <p class="text-xs text-gray-600 mt-1">Use the "Check In: Face + GPS" button at the top of this page to record your attendance.</p>
                                    <?php else: ?>
                                        <p class="text-sm">No members added yet</p>
                                        <p class="text-xs text-gray-600 mt-1">Click "Add People" to start taking attendance for the <?= asE(strtolower(trim((string)($training['target_group'] ?? 'mixed')))) ?: 'mixed' ?> roster.</p>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <?php foreach ($attendanceList as $attendance):
                                    $memberId = (int)($attendance['member_id'] ?? 0);
                                    $memberName = trim((string)($attendance['full_name'] ?? ''));
                                    $currentStatus = (string)($attendance['status'] ?? '');
                                    $checkinId = (int)($attendance['checkin_id'] ?? 0);
                                    $checkinMethod = (string)($attendance['checkin_method'] ?? '');
                                    $faceScore = $attendance['face_score'] !== null ? (float)$attendance['face_score'] : null;
                                    $distanceM = $attendance['distance_m'] !== null ? (int)$attendance['distance_m'] : null;
                                    $isFlagged = (int)($attendance['flagged'] ?? 0) === 1;
                                    $flagReason = trim((string)($attendance['flag_reason'] ?? ''));
                                    ?>
                                    <!-- data-name / data-rank drive the client-side
                                         filter. The previous implementation matched
                                         Tailwind class strings, which broke silently
                                         on any class reordering. -->
                                    <div data-attendance-row
                                         data-name="<?= asE(strtolower($memberName)) ?>"
                                         data-rank="<?= asE(strtolower(trim((string)($attendance['rank'] ?? '')))) ?>"
                                         class="flex flex-col sm:flex-row items-start sm:items-center justify-between p-3 sm:p-4 hover:bg-white/[0.02] rounded-xl sm:rounded-2xl transition border-b border-gray-800/50 last:border-0 gap-3 sm:gap-0">
                                        <div class="flex items-center gap-3 sm:gap-4 w-full sm:w-auto">
                                            <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-full bg-gradient-to-br from-purple-500/20 to-blue-500/20 flex items-center justify-center text-[10px] sm:text-xs font-bold border border-white/5 flex-shrink-0">
                                                <?= asE(asInitials($attendance['full_name'] ?? null), '??') ?>
                                            </div>
                                            <div>
                                                <div class="text-xs sm:text-sm font-bold tracking-wide"><?= asE($attendance['full_name'] ?? null, 'Unknown') ?></div>
                                                <div class="text-[9px] sm:text-[10px] text-gray-400 font-medium uppercase tracking-tighter"><?= asE($attendance['rank'] ?? null, 'None') ?></div>
                                                <div class="text-[9px] text-gray-500"><?= asE($attendance['player_category'] ?? null, 'Public') ?> Player</div>
                                                <?php if ($checkinId > 0): ?>
                                                <div class="flex flex-wrap items-center gap-1.5 mt-2">
                                                    <span class="px-2 py-0.5 rounded-full text-[8px] font-bold uppercase tracking-wider bg-blue-500/10 text-blue-300 border border-blue-500/20">
                                                        <?= $checkinMethod === 'Manual' ? 'Manual' : 'Face + GPS' ?>
                                                    </span>
                                                    <?php if ($faceScore !== null): ?>
                                                    <span class="px-2 py-0.5 rounded-full text-[8px] font-bold uppercase tracking-wider bg-purple-500/10 text-purple-300 border border-purple-500/20" title="Face match confidence">
                                                        Face <?= number_format($faceScore * 100, 1) ?>%
                                                    </span>
                                                    <?php endif; ?>
                                                    <?php if ($distanceM !== null): ?>
                                                    <span class="px-2 py-0.5 rounded-full text-[8px] font-bold uppercase tracking-wider bg-cyan-500/10 text-cyan-300 border border-cyan-500/20" title="Distance from the session venue">
                                                        <?= $distanceM ?> m
                                                    </span>
                                                    <?php endif; ?>
                                                    <?php if ($isFlagged): ?>
                                                    <span class="px-2 py-0.5 rounded-full text-[8px] font-bold uppercase tracking-wider bg-red-500/15 text-red-300 border border-red-500/30" title="<?= asE($flagReason !== '' ? $flagReason : 'Flagged check-in') ?>">
                                                        <i class="fas fa-flag mr-1"></i>Flagged<?= $flagReason !== '' ? ': ' . asE($flagReason) : '' ?>
                                                    </span>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if (!$selfCheckinMode && $canWriteAttendance): ?>
                                                <form method="POST" action="../actions/review_checkin.php" class="flex flex-wrap items-center gap-1.5 mt-2">
                                                    <input type="hidden" name="csrf_token" value="<?= asE($csrfToken) ?>">
                                                    <input type="hidden" name="checkin_id" value="<?= $checkinId ?>">
                                                    <input type="hidden" name="training_id" value="<?= (int)$trainingId ?>">
                                                    <input type="hidden" name="page" value="<?= (int)$page ?>">
                                                    <?php if ($isFlagged): ?>
                                                    <button type="submit" name="decision" value="clear" class="px-2.5 py-1 rounded-lg bg-emerald-500/10 text-emerald-300 border border-emerald-500/20 text-[8px] font-bold uppercase tracking-wider hover:bg-emerald-500 hover:text-white transition">
                                                        <i class="fas fa-check mr-1"></i>Clear flag
                                                    </button>
                                                    <button type="submit" name="decision" value="confirm" class="px-2.5 py-1 rounded-lg bg-red-500/10 text-red-300 border border-red-500/20 text-[8px] font-bold uppercase tracking-wider hover:bg-red-500 hover:text-white transition">
                                                        <i class="fas fa-flag mr-1"></i>Confirm flag
                                                    </button>
                                                    <?php else: ?>
                                                    <button type="submit" name="decision" value="flag" class="px-2.5 py-1 rounded-lg bg-white/5 text-gray-400 border border-gray-700 text-[8px] font-bold uppercase tracking-wider hover:bg-red-500 hover:text-white transition" title="Flag this check-in for review">
                                                        <i class="fas fa-flag mr-1"></i>Flag
                                                    </button>
                                                    <?php endif; ?>
                                                </form>
                                                <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <?php if ($selfCheckinMode): ?>
                                        <span class="px-3 py-1.5 rounded-full text-[10px] font-bold uppercase tracking-wider <?= $currentStatus === 'Present' ? 'bg-green-500/15 text-green-400 border border-green-500/20' : ($currentStatus === 'Late' ? 'bg-orange-500/15 text-orange-400 border border-orange-500/20' : 'bg-red-500/15 text-red-400 border border-red-500/20') ?>"><?= asE($currentStatus, 'Recorded') ?></span>
                                        <?php else: ?>
                                        <div class="flex items-center gap-1.5 sm:gap-2 w-full sm:w-auto justify-end sm:justify-start">
                                            <button type="button" onclick="<?= asE(asStatusOnclick($trainingId, $memberId, 'Present', $canWriteAttendance)) ?>"
                                                    class="w-8 h-8 sm:w-10 sm:h-10 rounded-lg sm:rounded-xl <?= $currentStatus === 'Present' ? 'bg-green-500 text-white shadow-lg shadow-green-900/40' : 'bg-gray-800/50 text-gray-500 hover:text-green-400' ?> flex items-center justify-center text-xs transition <?= $canWriteAttendance ? 'cursor-pointer' : 'opacity-50 cursor-not-allowed' ?>"
                                                    title="<?= $canWriteAttendance ? 'Mark Present' : 'No permission to update attendance' ?>"
                                                    <?= $canWriteAttendance ? '' : 'disabled' ?>>
                                                <i class="fas fa-check"></i>
                                            </button>
                                            <button type="button" onclick="<?= asE(asStatusOnclick($trainingId, $memberId, 'Late', $canWriteAttendance)) ?>"
                                                    class="w-8 h-8 sm:w-10 sm:h-10 rounded-lg sm:rounded-xl <?= $currentStatus === 'Late' ? 'bg-orange-500 text-white shadow-lg shadow-orange-900/40' : 'bg-gray-800/50 text-gray-500 hover:text-orange-400' ?> flex items-center justify-center text-xs transition <?= $canWriteAttendance ? 'cursor-pointer' : 'opacity-50 cursor-not-allowed' ?>"
                                                    title="<?= $canWriteAttendance ? 'Mark Late' : 'No permission to update attendance' ?>"
                                                    <?= $canWriteAttendance ? '' : 'disabled' ?>>
                                                <i class="fas fa-clock"></i>
                                            </button>
                                            <button type="button" onclick="<?= asE(asStatusOnclick($trainingId, $memberId, 'Absent', $canWriteAttendance)) ?>"
                                                    class="w-8 h-8 sm:w-10 sm:h-10 rounded-lg sm:rounded-xl <?= $currentStatus === 'Absent' ? 'bg-red-500 text-white shadow-lg shadow-red-900/40' : 'bg-gray-800/50 text-gray-500 hover:text-red-400' ?> flex items-center justify-center text-xs transition <?= $canWriteAttendance ? 'cursor-pointer' : 'opacity-50 cursor-not-allowed' ?>"
                                                    title="<?= $canWriteAttendance ? 'Mark Absent' : 'No permission to update attendance' ?>"
                                                    <?= $canWriteAttendance ? '' : 'disabled' ?>>
                                                <i class="fas fa-times"></i>
                                            </button>
                                            <button type="button" onclick="<?= asE(asRemoveOnclick($trainingId, $memberId, $attendance['full_name'] ?? null, $canDeleteAttendance)) ?>"
                                                    class="w-8 h-8 sm:w-10 sm:h-10 rounded-lg sm:rounded-xl bg-gray-800/50 flex items-center justify-center text-xs transition <?= $canDeleteAttendance ? 'text-gray-500 hover:text-yellow-400 cursor-pointer' : 'text-gray-700 opacity-50 cursor-not-allowed' ?>"
                                                    title="<?= $canDeleteAttendance ? 'Remove from session' : 'No permission to remove members' ?>"
                                                    <?= $canDeleteAttendance ? '' : 'disabled' ?>>
                                                <i class="fas fa-user-minus"></i>
                                            </button>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <!-- Pagination Controls -->
                        <?php if ($totalPages > 1): ?>
                        <div class="px-4 py-4 border-t border-gray-800 flex justify-between items-center flex-wrap gap-3">
                            <div class="text-[10px] text-gray-500 uppercase">
                                Showing <?= $totalAttendance > 0 ? $offset + 1 : 0 ?> to <?= min($offset + $perPage, $totalAttendance) ?> of <?= $totalAttendance ?> members
                            </div>
                            <div class="flex gap-2">
                                <?php if ($page > 1): ?>
                                <a href="<?= asE($sessionPageLink(1)) ?>" class="bg-white/5 hover:bg-white/10 px-3 py-2 rounded-lg text-[10px] font-bold transition">
                                    <i class="fas fa-chevron-left mr-1"></i>First
                                </a>
                                <a href="<?= asE($sessionPageLink($page - 1)) ?>" class="bg-white/5 hover:bg-white/10 px-3 py-2 rounded-lg text-[10px] font-bold transition">
                                    <i class="fas fa-chevron-left mr-1"></i>Prev
                                </a>
                                <?php endif; ?>

                                <span class="px-3 py-2 text-[10px] font-bold text-gray-400">
                                    Page <?= (int)$page ?> of <?= (int)$totalPages ?>
                                </span>

                                <?php if ($page < $totalPages): ?>
                                <a href="<?= asE($sessionPageLink($page + 1)) ?>" class="bg-white/5 hover:bg-white/10 px-3 py-2 rounded-lg text-[10px] font-bold transition">
                                    Next<i class="fas fa-chevron-right ml-1"></i>
                                </a>
                                <a href="<?= asE($sessionPageLink($totalPages)) ?>" class="bg-white/5 hover:bg-white/10 px-3 py-2 rounded-lg text-[10px] font-bold transition">
                                    Last<i class="fas fa-chevron-right ml-1"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="text-[9px] sm:text-[10px] text-gray-600 space-y-1 pl-2 mt-4">
                        <p>CREATED: <?= asE(asFormatDate($training['created_at'] ?? null)) ?></p>
                        <p>LAST UPDATE: <?= asE(asFormatDate($training['updated_at'] ?? null)) ?></p>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </main>
        <!-- Reveal <main> immediately after it closes. It used to be revealed by a script at the very
             end of the document, so any PHP fatal in a modal included below left <main> stuck at
             opacity-0 (a blank content area) even though the content had been rendered. -->
        <script>
        (function () {
            var page = document.getElementById('page');
            if (!page) { return; }
            var reveal = function () { page.classList.remove('opacity-0', 'translate-x-8'); };
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', reveal);
            } else {
                reveal();
            }
        })();
        </script>
    </div>

    <!-- face-api.js (vladmandic fork v1.7.12) powers the face-verified self check-in. -->
    <?php if ($selfCheckinMode): ?>
    <script src="https://cdn.jsdelivr.net/npm/@vladmandic/face-api@1.7.12/dist/face-api.min.js"></script>
    <?php endif; ?>
    <?php
    /*
     * The self check-in modal targets the signed-in member. By this point
     * $memberId holds the last roster row, so pass the viewer explicitly.
     */
    $checkinMemberId = $viewerMemberId;
    $checkinTraining = is_array($training ?? null) ? $training : [];
    $checkinTrainingId = $trainingId;
    $checkinCsrf = $csrfToken;
    ?>
    <!--
        Leaflet + the shared location picker power the Edit Session modal's
        venue map. These were previously missing here, which is why the map
        rendered blank on this page (L was undefined).
    -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <?php
    /*
     * Optional Google Places key for the venue map's search box
     * (config/google_maps_key.php). Empty string = free built-in search.
     */
    $arnisGoogleMapsKey = '';
    $arnisMapsKeyFile = __DIR__ . '/../config/google_maps_key.php';
    if (is_file($arnisMapsKeyFile)) {
        $arnisGoogleMapsKey = (string) (require $arnisMapsKeyFile);
    }
    ?>
    <script>window.ARNIS_GOOGLE_MAPS_KEY = <?= json_encode($arnisGoogleMapsKey, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
    <script src="js/location-picker.js?v=3"></script>
    <?php include_once __DIR__ . '/modals/add_to_training.php'; ?>
    <?php include_once __DIR__ . '/modals/edit_training.php'; ?>
    <?php include_once __DIR__ . '/modals/attendance_checkin.php'; ?>
    <?php include_once __DIR__ . '/modals/void_confirmation.php'; ?>
    <?php include_once __DIR__ . '/modals/user_menu.php'; ?>
    <?php include_once __DIR__ . '/modals/edit_profile_user.php'; ?>
</body>

<script>
const CSRF_TOKEN = <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const CURRENT_PAGE = <?= (int)$page ?>;

/** Builds and submits a one-shot POST form with the CSRF token attached. */
function postTo(action, fields) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = action;

    const payload = Object.assign({csrf_token: CSRF_TOKEN}, fields || {});
    Object.keys(payload).forEach(function (key) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = key;
        input.value = payload[key];
        form.appendChild(input);
    });

    document.body.appendChild(form);
    form.submit();
}

function updateAttendance(trainingId, memberId, status) {
    postTo('../actions/update_attendance.php', {
        training_id: trainingId,
        member_id: memberId,
        status: status,
        /* Preserve the roster page across the round-trip. */
        page: CURRENT_PAGE
    });
}

function removeMember(trainingId, memberId, memberName) {
    if (!confirm('Remove ' + memberName + ' from this session?')) {
        return;
    }

    postTo('../actions/remove_from_training.php', {
        training_id: trainingId,
        member_id: memberId,
        page: CURRENT_PAGE
    });
}

document.addEventListener('DOMContentLoaded', function () {
    const searchInput = document.getElementById('attendanceSearch');
    const attendanceContainer = document.getElementById('attendanceList');

    if (!searchInput || !attendanceContainer) return;

    let noResults = null;

    searchInput.addEventListener('input', function () {
        const term = this.value.toLowerCase().trim();
        const rows = attendanceContainer.querySelectorAll('[data-attendance-row]');

        let visibleCount = 0;

        rows.forEach(function (row) {
            const name = row.getAttribute('data-name') || '';
            const rank = row.getAttribute('data-rank') || '';
            const matches = term === '' || name.indexOf(term) !== -1 || rank.indexOf(term) !== -1;

            row.style.display = matches ? '' : 'none';
            if (matches) visibleCount++;
        });

        if (visibleCount === 0 && rows.length > 0) {
            if (!noResults) {
                noResults = document.createElement('div');
                noResults.id = 'no-results-message';
                noResults.className = 'p-8 text-center text-gray-500';
                noResults.textContent = 'No matching members found';
                attendanceContainer.appendChild(noResults);
            }
            noResults.classList.remove('hidden');
        } else if (noResults) {
            noResults.classList.add('hidden');
        }
    });
});
</script>
</html>
`````

### Changed 18/19: `htdocs/public/settings.php`

`````php
<?php
/**
 * System Settings - SuperAdmin only.
 *
 * Values live in the `settings` table and are read through classes/Settings.php.
 * The page is intentionally small: only settings the application actually
 * consumes are exposed. Unsupported "features" (SMS, ML, multi-organization)
 * are not offered.
 */
require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/Settings.php';

if (!hasRole('SuperAdmin')) {
    http_response_code(403);
    exit('Access Denied: Only the SuperAdmin may view system settings.');
}

$db = null;
$settingsReady = false;
$settings = Settings::DEFAULTS;
$loadError = '';

try {
    $db = (new Database())->connect();
    $settingsObject = new Settings($db);
    $settings = $settingsObject->all();
    $settingsReady = true;
    try {
        $db->query('SELECT 1 FROM settings LIMIT 1');
    } catch (Throwable $e) {
        $settingsReady = false;
    }
} catch (Throwable $e) {
    error_log('settings.php error: ' . $e->getMessage());
    $loadError = 'The settings page could not connect to the database.';
}

$flashSuccess = isset($_SESSION['success']) && is_string($_SESSION['success']) ? $_SESSION['success'] : '';
$flashError = isset($_SESSION['error']) && is_string($_SESSION['error']) ? $_SESSION['error'] : '';
unset($_SESSION['success'], $_SESSION['error']);

function stE(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$csrfToken = arnisCsrfToken();
?>
<!DOCTYPE html>
<html lang="en" class="text-[13px]">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings | Arnis Portal</title>
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

            <div class="mb-6">
                <div class="text-[10px] uppercase tracking-[0.2em] text-purple-400 font-black">Administration</div>
                <h1 class="text-2xl sm:text-3xl font-black uppercase italic tracking-tight">System Settings</h1>
                <p class="text-xs text-gray-400 mt-1">Only settings used by the application are shown. Changes are recorded in the audit log.</p>
            </div>

            <?php if ($flashSuccess !== ''): ?>
                <div class="bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 rounded-2xl px-4 py-3 mb-6 text-sm">
                    <i class="fas fa-check-circle mr-2"></i><?= stE($flashSuccess) ?>
                </div>
            <?php endif; ?>
            <?php if ($flashError !== ''): ?>
                <div class="bg-red-500/10 border border-red-500/30 text-red-300 rounded-2xl px-4 py-3 mb-6 text-sm">
                    <i class="fas fa-exclamation-circle mr-2"></i><?= stE($flashError) ?>
                </div>
            <?php endif; ?>
            <?php if ($loadError !== ''): ?>
                <div class="bg-red-500/10 border border-red-500/30 text-red-300 rounded-2xl px-4 py-3 mb-6 text-sm">
                    <i class="fas fa-exclamation-circle mr-2"></i><?= stE($loadError) ?>
                </div>
            <?php endif; ?>
            <?php if (!$settingsReady && $loadError === ''): ?>
                <div class="bg-yellow-500/10 border border-yellow-500/30 text-yellow-200 rounded-2xl px-4 py-3 mb-6 text-sm">
                    <i class="fas fa-triangle-exclamation mr-2"></i>
                    The <code>settings</code> table is missing. Run <code>database/migrations/003_security_privacy_audit_settings.sql</code> in phpMyAdmin before saving settings.
                </div>
            <?php endif; ?>

            <form method="POST" action="../actions/save_settings.php" class="space-y-6 max-w-4xl">
                <input type="hidden" name="csrf_token" value="<?= stE($csrfToken) ?>">

                <div class="card-bg rounded-3xl p-5 sm:p-6">
                    <h2 class="text-sm font-black uppercase tracking-widest text-purple-300 mb-4"><i class="fas fa-building mr-2"></i>Organization</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-[10px] uppercase tracking-[0.16em] font-black text-gray-500 mb-2">Organization Name</label>
                            <input type="text" name="organization_name" required maxlength="100" value="<?= stE((string)$settings['organization_name']) ?>" class="w-full bg-gray-900/60 border border-gray-700 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-purple-500/50">
                        </div>
                        <div>
                            <label class="block text-[10px] uppercase tracking-[0.16em] font-black text-gray-500 mb-2">Public Contact Email</label>
                            <input type="email" name="contact_email" maxlength="100" value="<?= stE((string)$settings['contact_email']) ?>" class="w-full bg-gray-900/60 border border-gray-700 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-purple-500/50" placeholder="club@example.com">
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-[10px] uppercase tracking-[0.16em] font-black text-gray-500 mb-2">Privacy Contact (shown in the registration notice)</label>
                            <input type="text" name="privacy_contact" maxlength="150" value="<?= stE((string)$settings['privacy_contact']) ?>" class="w-full bg-gray-900/60 border border-gray-700 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-purple-500/50" placeholder="Data protection officer name / email / phone">
                        </div>
                    </div>
                </div>

                <div class="card-bg rounded-3xl p-5 sm:p-6">
                    <h2 class="text-sm font-black uppercase tracking-widest text-purple-300 mb-4"><i class="fas fa-envelope mr-2"></i>Email</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-[10px] uppercase tracking-[0.16em] font-black text-gray-500 mb-2">From Email</label>
                            <input type="email" name="from_email" maxlength="100" value="<?= stE((string)$settings['from_email']) ?>" class="w-full bg-gray-900/60 border border-gray-700 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-purple-500/50" placeholder="no-reply@yourdomain.com">
                            <p class="text-[10px] text-gray-500 mt-1">Leave blank to use no-reply@&lt;your-host&gt;.</p>
                        </div>
                        <div>
                            <label class="block text-[10px] uppercase tracking-[0.16em] font-black text-gray-500 mb-2">From Name</label>
                            <input type="text" name="from_name" maxlength="100" value="<?= stE((string)$settings['from_name']) ?>" class="w-full bg-gray-900/60 border border-gray-700 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-purple-500/50">
                        </div>
                        <div>
                            <label class="block text-[10px] uppercase tracking-[0.16em] font-black text-gray-500 mb-2">SMTP Login (Brevo)</label>
                            <input type="text" name="smtp_user" maxlength="150" autocomplete="off" value="<?= stE((string)$settings['smtp_user']) ?>" class="w-full bg-gray-900/60 border border-gray-700 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-purple-500/50" placeholder="xxxxxxx@smtp-brevo.com">
                        </div>
                        <div>
                            <label class="block text-[10px] uppercase tracking-[0.16em] font-black text-gray-500 mb-2">SMTP Key</label>
                            <input type="password" name="smtp_pass" maxlength="255" autocomplete="new-password" value="" class="w-full bg-gray-900/60 border border-gray-700 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-purple-500/50" placeholder="<?= trim((string)$settings['smtp_pass']) !== '' ? 'Saved - leave blank to keep' : 'Paste your Brevo SMTP key' ?>">
                            <p class="text-[10px] text-gray-500 mt-1">Required for verification, password reset and notification emails.</p>
                        </div>
                        <label class="flex items-start gap-3 md:col-span-2 text-xs text-gray-300">
                            <input type="checkbox" name="email_notifications" value="1" <?= !empty($settings['email_notifications']) && $settings['email_notifications'] !== '0' ? 'checked' : '' ?> class="mt-0.5 w-4 h-4 rounded border-gray-600 bg-gray-800 text-purple-600 focus:ring-purple-500">
                            <span>Send event, training, and announcement emails. Requires working PHP mail on the host.</span>
                        </label>
                    </div>
                </div>

                <div class="card-bg rounded-3xl p-5 sm:p-6">
                    <h2 class="text-sm font-black uppercase tracking-widest text-purple-300 mb-4"><i class="fas fa-user-plus mr-2"></i>Registration &amp; Verification</h2>
                    <div class="space-y-4">
                        <label class="flex items-start gap-3 text-xs text-gray-300">
                            <input type="checkbox" name="allow_registration" value="1" <?= !empty($settings['allow_registration']) && $settings['allow_registration'] !== '0' ? 'checked' : '' ?> class="mt-0.5 w-4 h-4 rounded border-gray-600 bg-gray-800 text-purple-600 focus:ring-purple-500">
                            <span>Allow new member applications from the public registration page.</span>
                        </label>
                        <p class="flex items-start gap-3 text-xs text-gray-400">
                            <i class="fas fa-envelope-circle-check mt-0.5 text-purple-400"></i>
                            <span>Applicants must always verify their email address before they can sign in.</span>
                        </p>
                    </div>
                </div>

                <div class="card-bg rounded-3xl p-5 sm:p-6">
                    <h2 class="text-sm font-black uppercase tracking-widest text-purple-300 mb-4"><i class="fas fa-location-dot mr-2"></i>Attendance Defaults</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-[10px] uppercase tracking-[0.16em] font-black text-gray-500 mb-2">Face Match Threshold</label>
                            <input type="number" name="face_match_threshold" min="0.10" max="1.00" step="0.01" value="<?= stE((string)$settings['face_match_threshold']) ?>" class="w-full bg-gray-900/60 border border-gray-700 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-purple-500/50">
                            <p class="text-[10px] text-gray-500 mt-1">0.10 to 1.00. Lower is stricter; 0.60 is recommended.</p>
                        </div>
                        <div>
                            <label class="block text-[10px] uppercase tracking-[0.16em] font-black text-gray-500 mb-2">Default Geofence (metres)</label>
                            <input type="number" name="default_geofence_m" min="5" max="5000" step="1" value="<?= stE((string)$settings['default_geofence_m']) ?>" class="w-full bg-gray-900/60 border border-gray-700 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-purple-500/50">
                            <p class="text-[10px] text-gray-500 mt-1">Used as the starting value when creating a training session.</p>
                        </div>
                        <div>
                            <label class="block text-[10px] uppercase tracking-[0.16em] font-black text-gray-500 mb-2">Check-in Opens (minutes before start)</label>
                            <input type="number" name="checkin_open_minutes" min="0" max="240" step="1" value="<?= stE((string)$settings['checkin_open_minutes']) ?>" class="w-full bg-gray-900/60 border border-gray-700 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-purple-500/50">
                            <p class="text-[10px] text-gray-500 mt-1">Check-in closes when the session ends. Members who never check in stay Absent.</p>
                        </div>
                        <div>
                            <label class="block text-[10px] uppercase tracking-[0.16em] font-black text-gray-500 mb-2">Late After (minutes past start)</label>
                            <input type="number" name="late_grace_minutes" min="0" max="180" step="1" value="<?= stE((string)$settings['late_grace_minutes']) ?>" class="w-full bg-gray-900/60 border border-gray-700 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-purple-500/50">
                            <p class="text-[10px] text-gray-500 mt-1">Checking in after this grace period is recorded as Late.</p>
                        </div>
                        <div>
                            <label class="block text-[10px] uppercase tracking-[0.16em] font-black text-gray-500 mb-2">Standard Private Session Fee (PHP)</label>
                            <input type="number" name="private_session_fee" min="0" max="100000" step="0.01" value="<?= stE((string)$settings['private_session_fee']) ?>" class="w-full bg-gray-900/60 border border-gray-700 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-purple-500/50">
                            <p class="text-[10px] text-gray-500 mt-1">Pre-filled when a coach approves a private training request. Members agree to this fee when they request; a different fee needs the member's acceptance.</p>
                        </div>
                    </div>
                </div>

                <div class="flex flex-col sm:flex-row gap-3">
                    <button type="submit" <?= $settingsReady ? '' : 'disabled' ?> class="flex-1 bg-purple-600 hover:bg-purple-500 disabled:opacity-40 disabled:cursor-not-allowed text-white font-black uppercase text-[11px] tracking-[0.2em] py-4 rounded-2xl transition">
                        <i class="fas fa-floppy-disk mr-2"></i>Save Settings
                    </button>
                    <a href="dashboard.php" class="flex-1 text-center bg-white/5 hover:bg-white/10 text-gray-300 font-black uppercase text-[11px] tracking-[0.2em] py-4 rounded-2xl transition">Cancel</a>
                </div>
            </form>
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
`````

### Changed 19/19: `htdocs/public/modals/add_user.php`

`````php
<div id="addUserModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4 transition-opacity duration-300 opacity-0">
    <div id="addUserCard" class="card-bg w-full max-w-lg rounded-3xl max-h-[80vh] flex flex-col overflow-hidden shadow-2xl transform scale-95 opacity-0 transition-all duration-300 ease-out border border-gray-800">

        <div class="p-6 border-b border-gray-800 flex justify-between items-center bg-white/5">
            <div>
                <h2 class="text-xl tracking-wide font-bold italic uppercase text-purple-400">Create Account</h2>
                <p class="text-[10px] text-gray-500 uppercase tracking-widest">Register a new system user</p>
            </div>
            <button type="button" onclick="closeAddUserModal()" class="text-gray-500 hover:text-white transition"><i class="fas fa-times"></i></button>
        </div>

        <form id="addUserForm" class="p-8 space-y-5 flex-1 overflow-y-auto">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(arnisCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">

            <div>
                <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Full Name</label>
                <input type="text" name="full_name" required maxlength="100" placeholder="e.g. Maria Clara" autocomplete="off"
                       class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition text-white">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Username</label>
                    <input type="text" name="username" required maxlength="50" pattern="[A-Za-z0-9._-]{3,50}" placeholder="maria_clara" autocomplete="off"
                           class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition text-white">
                </div>
                <div>
                    <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Access Level</label>
                    <div class="relative">
                        <select name="role" required class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none appearance-none cursor-pointer text-white border border-transparent focus:border-purple-500 transition">
                            <option value="">Select role...</option>
                            <?php foreach ((array)($roles ?? require __DIR__ . '/../../config/roles.php') as $roleKey => $roleMeta):
                                /* Applicants self-register; process_add_user.php
                                 * rejects that role, so do not offer it here. */
                                if ((string)$roleKey === 'Applicant') {
                                    continue;
                                }
                            ?>
                                <option value="<?= htmlspecialchars((string)$roleKey, ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars((string)($roleMeta['label'] ?? $roleKey), ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <i class="fas fa-chevron-down absolute right-4 top-1/2 -translate-y-1/2 text-gray-600 pointer-events-none"></i>
                    </div>
                </div>
            </div>

            <div>
                <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Email (optional)</label>
                <input type="email" name="email" maxlength="100" placeholder="maria@arnisconnect.local" autocomplete="off"
                       class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition text-white">
            </div>

            <div id="addUserMemberFields" class="hidden space-y-5 rounded-2xl border border-purple-500/20 bg-purple-500/5 p-4">
                <p class="text-[10px] text-purple-300 uppercase font-bold tracking-widest">Member Profile</p>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Date of Birth</label>
                        <input type="date" name="dob" max="<?= htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>"
                               class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition text-white">
                    </div>
                    <div>
                        <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Contact Number</label>
                        <input type="text" name="contact_number" maxlength="20" placeholder="09XXXXXXXXX"
                               class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition text-white">
                    </div>
                </div>
                <div id="addUserCategoryField">
                    <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Player Category</label>
                    <select name="player_category" class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none appearance-none cursor-pointer text-white border border-transparent focus:border-purple-500 transition">
                        <option value="Public">Public (free group training)</option>
                        <option value="Private">Private (paid private training)</option>
                        <option value="Mixed">Mixed (both)</option>
                    </select>
                </div>
                <div>
                    <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Link Existing Member ID (optional)</label>
                    <input type="number" name="member_id" min="1" step="1" placeholder="Leave blank to create a new profile"
                           class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition text-white">
                    <p class="text-[9px] text-gray-600 mt-2 tracking-wider">
                        Only use this for a member record that already exists without a login.
                    </p>
                </div>
            </div>

            <div>
                <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Initial Password</label>
                <input type="password" name="password" required minlength="6" maxlength="100" placeholder="Minimum 6 characters" autocomplete="new-password"
                       class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition text-white">
            </div>

            <button type="submit" class="bg-[#bf46c7] px-8 py-4 rounded-full font-bold text-sm hover:opacity-90 transition shadow-lg shadow-purple-900/20 w-full">
                Register User
            </button>
        </form>
    </div>
</div>

<script>
function openAddUserModal() {
    const modal = document.getElementById('addUserModal');
    const card = document.getElementById('addUserCard');
    const form = document.getElementById('addUserForm');

    form.reset();
    document.body.style.overflow = 'hidden';

    modal.classList.remove('hidden');
    requestAnimationFrame(() => {
        modal.classList.add('opacity-100');
        card.classList.remove('scale-95', 'opacity-0');
        card.classList.add('scale-100', 'opacity-100');
    });
}

function closeAddUserModal() {
    const modal = document.getElementById('addUserModal');
    const card = document.getElementById('addUserCard');

    modal.classList.remove('opacity-100');
    card.classList.remove('scale-100', 'opacity-100');
    card.classList.add('scale-95', 'opacity-0');
    document.body.style.overflow = 'auto';

    setTimeout(() => {
        modal.classList.add('hidden');
    }, 300);
}

// Toast notification functions (textContent only - never innerHTML)
function showNotification(message, type = 'success') {
    const toastContainer = document.getElementById('toastContainer') || createToastContainer();

    const toast = document.createElement('div');
    toast.className =
        'fixed top-6 left-1/2 -translate-x-1/2 px-6 py-4 rounded-xl shadow-2xl border flex items-center gap-3 ' +
        'transform transition-all duration-300 ease-out z-[9999] ' +
        (type === 'success'
            ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-400'
            : 'bg-amber-500/10 border-amber-500/30 text-amber-400');

    const icon = document.createElement('i');
    icon.className = type === 'success'
        ? 'fas fa-check-circle text-lg'
        : 'fas fa-exclamation-circle text-lg';

    const text = document.createElement('span');
    text.className = 'text-sm font-semibold';
    text.textContent = String(message == null ? '' : message);

    toast.appendChild(icon);
    toast.appendChild(text);
    toastContainer.appendChild(toast);

    setTimeout(() => {
        toast.style.animation = 'slideOutTop 0.3s ease-in forwards';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

function createToastContainer() {
    const container = document.createElement('div');
    container.id = 'toastContainer';
    document.body.appendChild(container);

    const style = document.createElement('style');
    style.textContent =
        '@keyframes slideOutTop {' +
        '  to { transform: translateY(-400px); opacity: 0; }' +
        '}';
    document.head.appendChild(style);

    return container;
}

function syncAddUserMemberFields() {
    const form = document.getElementById('addUserForm');
    const role = form.elements['role'].value;
    const needsProfile = ['Head Coach', 'Coach/Trainer', 'Member'].includes(role);
    const linking = form.elements['member_id'].value.trim() !== '';
    document.getElementById('addUserMemberFields').classList.toggle('hidden', !needsProfile);
    document.getElementById('addUserCategoryField').classList.toggle('hidden', role !== 'Member');
    form.elements['dob'].required = needsProfile && !linking;
    form.elements['contact_number'].required = needsProfile && !linking;
}

// Handle form submission
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('addUserForm');
    if (!form) {
        return;
    }
    form.elements['role'].addEventListener('change', syncAddUserMemberFields);
    form.elements['member_id'].addEventListener('input', syncAddUserMemberFields);
    form.addEventListener('reset', () => setTimeout(syncAddUserMemberFields, 0));

    form.addEventListener('submit', function (e) {
        e.preventDefault();

        const submitBtn = form.querySelector('button[type="submit"]');
        const originalText = submitBtn.textContent;
        const formData = new FormData(form);

        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Creating...';

        fetch('../actions/process_add_user.php', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        })
        .then(response => response.text().then(text => ({ status: response.status, text })))
        .then(data => {
            let json = null;
            try {
                json = JSON.parse(data.text);
            } catch (err) {
                json = null;
            }

            if (json && json.success) {
                closeAddUserModal();
                form.reset();
                showNotification(json.message || 'User created successfully!', 'success');
                setTimeout(() => window.location.reload(), 1800);
            } else if (json) {
                showNotification('Error: ' + (json.message || 'Failed to create user.'), 'error');
                submitBtn.disabled = false;
                submitBtn.textContent = originalText;
            } else {
                showNotification('Error: Invalid response from server (HTTP ' + data.status + ')', 'error');
                submitBtn.disabled = false;
                submitBtn.textContent = originalText;
            }
        })
        .catch(() => {
            showNotification('Error: Could not connect to server', 'error');
            submitBtn.disabled = false;
            submitBtn.textContent = originalText;
        });
    });
});
</script>
`````

## Part 3: Small edits in 4 big files

These files are large, but only a few lines changed. Open each one, press **Ctrl+F** to find the line, and make the change shown.

### Edit 1/4: `htdocs/public/dashboard.php`

**a)** Find this line near the top:

`````php
require_once __DIR__ . '/../classes/Funds.php';
`````

Add this new line **right below it**:

`````php
require_once __DIR__ . '/../classes/AuditLog.php';
`````

**b)** Find this line (around line 535):

`````php
        $auditWhere = '';
`````

Add this new line **right above it**:

`````php
        AuditLog::ensureSchema($db);
`````

### Edit 2/4: `htdocs/public/members_management.php`

**a)** Find and **delete** these 3 lines (the Add Member button):

`````php
                    <button type="button" onclick="openMemberModal()" class="px-5 sm:px-6 py-4 rounded-full font-bold text-xs sm:text-sm whitespace-nowrap transition <?= $canModifyMembers ? 'bg-accent-purple hover:opacity-90 cursor-pointer' : 'bg-gray-600 opacity-50 cursor-not-allowed' ?>" <?= !$canModifyMembers ? 'disabled' : '' ?>>
                        + Add Member
                    </button>
`````

**b)** Find and **delete** this line near the bottom:

`````php
    <?php include_once "modals/add_member.php"; ?>
`````

### Edit 3/4: `htdocs/public/modals/edit_member.php`

Find these 3 lines:

`````php
                    <option value="Player">Player</option>
                    <option value="Athlete">Athlete</option>
                    <option value="Trainer">Trainer</option>
`````

Replace them with this 1 line:

`````php
                    <option value="Player">Member</option>
`````

### Edit 4/4: `htdocs/classes/Members.php`

Find this line (around line 478):

`````php
    public function create(array $data, int $createdBy): array
`````

Paste this **right above it**:

`````php
    /**
     * Insert only the member profile for a login the caller creates itself
     * (Users -> Add New User). The caller owns the transaction.
     */
    public function createProfileForAccount(array $data, int $createdBy): int
    {
        return (int)$this->insertMember($data, $createdBy)['member_id'];
    }

`````

## Part 4: Files to delete

Right-click each one in File Manager and delete it:

- `htdocs/test-email.php`
- `htdocs/dashboard_diagnostic.php`
- `htdocs/public/generate_report.php`
- `htdocs/public/modals/add_member.php`
- `htdocs/actions/update_training.php`
- `htdocs/actions/process_add_member.php`

## Part 5: After uploading

1. Open the site and log in as superadmin. If a page shows a blank screen or an error, the paste for that file was probably incomplete. Copy it again.
2. Go to **Settings -> Email**, paste your new Brevo SMTP login and key, and save.
3. Merge PR #4 on GitHub.

You don't need to run any SQL. The new tables create themselves the first time they're used.
