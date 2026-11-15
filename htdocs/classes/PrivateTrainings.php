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
 * Payment is tracked per request. The member can upload a receipt photo
 * (GCash, Maya, bank, or any other transfer). Approving that receipt, or
 * marking the fee paid by hand, records Funds income.
 */
class PrivateTrainings
{
    public const OPEN_STATUSES = ['Pending', 'Awaiting Member'];
    public const MAX_OPEN_PER_MEMBER = 3;
    public const RECEIPT_METHODS = ['GCash', 'Maya', 'Bank', 'Other'];

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
               `receipt_path` varchar(255) DEFAULT NULL,
               `receipt_method` varchar(20) DEFAULT NULL,
               `receipt_submitted_at` datetime DEFAULT NULL,
               `receipt_note` varchar(255) DEFAULT NULL,
               `receipt_status` enum('None','Pending','Approved','Rejected') NOT NULL DEFAULT 'None',
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
        self::ensureReceiptColumns($db);
        self::$schemaReady = true;
    }

    /** Adds receipt columns on databases created before online payment. */
    private static function ensureReceiptColumns(PDO $db): void
    {
        try {
            $db->query(
                'SELECT receipt_path, receipt_method, receipt_submitted_at, receipt_note, receipt_status
                 FROM private_training_requests LIMIT 0'
            );
            return;
        } catch (PDOException $e) {
            // The table exists from an older install and is missing these columns.
        }

        $adds = [
            'ADD COLUMN `receipt_path` varchar(255) DEFAULT NULL',
            'ADD COLUMN `receipt_method` varchar(20) DEFAULT NULL',
            'ADD COLUMN `receipt_submitted_at` datetime DEFAULT NULL',
            'ADD COLUMN `receipt_note` varchar(255) DEFAULT NULL',
            "ADD COLUMN `receipt_status` enum('None','Pending','Approved','Rejected') NOT NULL DEFAULT 'None'",
        ];
        foreach ($adds as $add) {
            try {
                $db->exec('ALTER TABLE `private_training_requests` ' . $add);
            } catch (PDOException $e) {
                if (!str_contains($e->getMessage(), 'Duplicate column')) {
                    throw $e;
                }
            }
        }
    }

    public static function receiptDirectory(): string
    {
        return dirname(__DIR__) . '/uploads/receipts';
    }

    /** Resolve a stored receipt path, or null when it is missing or outside the receipts folder. */
    public static function receiptFile(?string $stored): ?string
    {
        $stored = trim((string)$stored);
        if ($stored === '') {
            return null;
        }
        $root = realpath(self::receiptDirectory());
        if ($root === false || !is_dir($root)) {
            return null;
        }
        $normalized = str_replace('\\', '/', $stored);
        $normalized = preg_replace('#^(?:\.\./)+#', '', $normalized) ?? $normalized;
        $normalized = preg_replace('#^uploads/receipts/#i', '', $normalized) ?? $normalized;
        $normalized = ltrim($normalized, '/');
        if ($normalized === '' || str_contains($normalized, '..')) {
            return null;
        }
        $candidate = realpath($root . DIRECTORY_SEPARATOR . $normalized);
        $rootPrefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if ($candidate === false || !str_starts_with($candidate, $rootPrefix) || !is_file($candidate)) {
            return null;
        }
        return $candidate;
    }

    public static function unlinkReceipt(?string $stored): void
    {
        $file = self::receiptFile($stored);
        if ($file !== null) {
            @unlink($file);
        }
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
            $method = trim((string)($request['receipt_method'] ?? ''));
            $notes = 'Private training request #' . $request['request_id'] . ' on ' . $request['session_date'];
            if ($method !== '') {
                $notes .= ' via ' . $method;
            }
            $this->db->prepare(
                "INSERT INTO funds (transaction_type, source_or_purpose, amount, transaction_date, notes, recorded_by)
                 VALUES ('Income', :src, :amt, CURDATE(), :notes, :u)"
            )->execute([
                ':src' => mb_substr('Private training fee - ' . $request['member_name'], 0, 255),
                ':amt' => (float)$request['fee_amount'],
                ':notes' => mb_substr($notes, 0, 255),
                ':u' => $actorUserId,
            ]);
            $fundId = (int)$this->db->lastInsertId();

            $upd = $this->db->prepare(
                "UPDATE private_training_requests
                 SET payment_status = 'Paid', paid_at = NOW(), fund_id = :f,
                     receipt_status = CASE WHEN receipt_status = 'Pending' THEN 'Approved' ELSE receipt_status END
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

    /** Member uploaded a payment photo for a confirmed, unpaid session. */
    public function saveReceipt(int $requestId, int $memberId, string $method, string $relativePath): ?string
    {
        if (!in_array($method, self::RECEIPT_METHODS, true)) {
            throw new RuntimeException('Choose how you paid: GCash, Maya, bank, or other.');
        }
        $previous = $this->db->prepare(
            "SELECT receipt_path FROM private_training_requests
             WHERE request_id = :id AND member_id = :mid AND status = 'Confirmed'
               AND payment_status = 'Unpaid' AND receipt_status <> 'Pending'
               AND fee_amount > 0"
        );
        $previous->execute([':id' => $requestId, ':mid' => $memberId]);
        $old = $previous->fetchColumn();
        if ($old === false) {
            throw new RuntimeException('This session is not waiting for a receipt.');
        }

        $upd = $this->db->prepare(
            "UPDATE private_training_requests
             SET receipt_path = :p, receipt_method = :m, receipt_submitted_at = NOW(),
                 receipt_status = 'Pending', receipt_note = NULL
             WHERE request_id = :id AND member_id = :mid AND status = 'Confirmed'
               AND payment_status = 'Unpaid' AND receipt_status <> 'Pending'"
        );
        $upd->execute([
            ':p' => $relativePath,
            ':m' => $method,
            ':id' => $requestId,
            ':mid' => $memberId,
        ]);
        if ($upd->rowCount() !== 1) {
            throw new RuntimeException('This session is not waiting for a receipt.');
        }
        return $old !== null && $old !== '' ? (string)$old : null;
    }

    public function rejectReceipt(int $requestId, string $note): void
    {
        $note = mb_substr(trim($note), 0, 255);
        if ($note === '') {
            throw new RuntimeException('Tell the member why the receipt was not accepted.');
        }
        $upd = $this->db->prepare(
            "UPDATE private_training_requests
             SET receipt_status = 'Rejected', receipt_note = :n
             WHERE request_id = :id AND status = 'Confirmed'
               AND payment_status = 'Unpaid' AND receipt_status = 'Pending'"
        );
        $upd->execute([':n' => $note, ':id' => $requestId]);
        if ($upd->rowCount() !== 1) {
            throw new RuntimeException('There is no receipt waiting for review.');
        }
    }
}
