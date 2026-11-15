<?php
declare(strict_types=1);

require_once __DIR__ . '/Members.php';

/**
 * A member asks for the next belt and attaches a note, a certificate, or both.
 * A coach or administrator approves or declines. Approval advances exactly one rank.
 */
class RankPromotions
{
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
            "CREATE TABLE IF NOT EXISTS `rank_promotion_requests` (
               `request_id` int(11) NOT NULL AUTO_INCREMENT,
               `member_id` int(11) NOT NULL,
               `requested_by` int(11) DEFAULT NULL,
               `current_rank` varchar(80) NOT NULL,
               `requested_rank` varchar(80) NOT NULL,
               `message` varchar(500) DEFAULT NULL,
               `proof_path` varchar(255) DEFAULT NULL,
               `status` enum('Pending','Approved','Declined','Cancelled') NOT NULL DEFAULT 'Pending',
               `review_note` varchar(255) DEFAULT NULL,
               `reviewed_by` int(11) DEFAULT NULL,
               `reviewed_at` datetime DEFAULT NULL,
               `created_at` datetime NOT NULL DEFAULT current_timestamp(),
               `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
               PRIMARY KEY (`request_id`),
               KEY `idx_rpr_member` (`member_id`, `status`),
               CONSTRAINT `fk_rpr_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`member_id`) ON DELETE CASCADE
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
        self::$schemaReady = true;
    }

    public static function proofDirectory(): string
    {
        return dirname(__DIR__) . '/uploads/rank_promotions';
    }

    public static function proofFile(?string $stored): ?string
    {
        $stored = trim((string)$stored);
        if ($stored === '') {
            return null;
        }
        $root = realpath(self::proofDirectory());
        if ($root === false || !is_dir($root)) {
            return null;
        }
        $normalized = str_replace('\\', '/', $stored);
        $normalized = preg_replace('#^(?:\.\./)+#', '', $normalized) ?? $normalized;
        $normalized = preg_replace('#^uploads/rank_promotions/#i', '', $normalized) ?? $normalized;
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

    public static function unlinkProof(?string $stored): void
    {
        $file = self::proofFile($stored);
        if ($file !== null) {
            @unlink($file);
        }
    }

    public function find(int $requestId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT r.*, m.full_name AS member_name, m.rank AS member_rank
             FROM rank_promotion_requests r
             JOIN members m ON m.member_id = r.member_id
             WHERE r.request_id = :id"
        );
        $stmt->execute([':id' => $requestId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function latestForMember(int $memberId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM rank_promotion_requests
             WHERE member_id = :id
             ORDER BY request_id DESC LIMIT 1"
        );
        $stmt->execute([':id' => $memberId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function pendingForMember(int $memberId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM rank_promotion_requests
             WHERE member_id = :id AND status = 'Pending'
             ORDER BY request_id DESC LIMIT 1"
        );
        $stmt->execute([':id' => $memberId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return list<array<string, mixed>> */
    public function listPending(): array
    {
        $stmt = $this->db->query(
            "SELECT r.*, m.full_name AS member_name, m.rank AS member_rank
             FROM rank_promotion_requests r
             JOIN members m ON m.member_id = r.member_id
             WHERE r.status = 'Pending' AND m.is_voided = 0
             ORDER BY r.created_at ASC, r.request_id ASC
             LIMIT 50"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function submit(int $memberId, int $userId, string $currentRank, string $requestedRank, string $message, ?string $proofPath): int
    {
        if ($this->pendingForMember($memberId) !== null) {
            throw new RuntimeException('You already have a promotion request waiting for review.');
        }
        $stmt = $this->db->prepare(
            "INSERT INTO rank_promotion_requests
             (member_id, requested_by, current_rank, requested_rank, message, proof_path, status)
             VALUES (:mid, :uid, :cur, :next, :msg, :proof, 'Pending')"
        );
        $stmt->execute([
            ':mid' => $memberId,
            ':uid' => $userId > 0 ? $userId : null,
            ':cur' => $currentRank,
            ':next' => $requestedRank,
            ':msg' => $message !== '' ? $message : null,
            ':proof' => $proofPath,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function cancel(int $requestId, int $memberId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE rank_promotion_requests
             SET status = 'Cancelled', reviewed_at = NOW()
             WHERE request_id = :id AND member_id = :mid AND status = 'Pending'"
        );
        $stmt->execute([':id' => $requestId, ':mid' => $memberId]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('That request is no longer waiting.');
        }
    }

    public function decline(int $requestId, int $reviewerId, string $note): void
    {
        $note = mb_substr(trim($note), 0, 255);
        if ($note === '') {
            throw new RuntimeException('Tell the member why the request was declined.');
        }
        $stmt = $this->db->prepare(
            "UPDATE rank_promotion_requests
             SET status = 'Declined', review_note = :note, reviewed_by = :uid, reviewed_at = NOW()
             WHERE request_id = :id AND status = 'Pending'"
        );
        $stmt->execute([':note' => $note, ':uid' => $reviewerId, ':id' => $requestId]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('That request was already handled.');
        }
    }

    public function markApproved(int $requestId, int $reviewerId, string $note): void
    {
        $stmt = $this->db->prepare(
            "UPDATE rank_promotion_requests
             SET status = 'Approved', review_note = :note, reviewed_by = :uid, reviewed_at = NOW()
             WHERE request_id = :id AND status = 'Pending'"
        );
        $stmt->execute([
            ':note' => $note !== '' ? mb_substr($note, 0, 255) : null,
            ':uid' => $reviewerId,
            ':id' => $requestId,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('That request was already handled.');
        }
    }
}
