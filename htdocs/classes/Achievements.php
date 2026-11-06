<?php
declare(strict_types=1);

class Achievements
{
    private PDO $conn;

    public function __construct(PDO $db)
    {
        $this->conn = $db;
    }

    public function restore(int $achievementId, int $restoredBy): bool
    {
        // NOTE: achievements table has no updated_at/updated_by columns
        // (see if0_42800263_arnis_db.sql). $restoredBy is accepted for a
        // consistent restore($id, $userId) signature across models but is
        // intentionally not stored. Audit trail lives in voided_by/void_reason
        // which are cleared below.
        unset($restoredBy);

        $stmt = $this->conn->prepare(
            "UPDATE achievements
             SET is_voided = 0, voided_at = NULL, voided_by = NULL, void_reason = NULL
             WHERE achievement_id = :id AND is_voided = 1"
        );
        $stmt->execute([':id' => $achievementId]);
        return $stmt->rowCount() > 0;
    }

    public function void(int $achievementId, int $voidedBy, ?string $reason = null): bool
    {
        $stmt = $this->conn->prepare(
            "UPDATE achievements
             SET is_voided = 1, voided_at = NOW(), voided_by = :voided_by, void_reason = :reason
             WHERE achievement_id = :id AND is_voided = 0"
        );
        $stmt->execute([
            ':voided_by' => $voidedBy,
            ':reason' => $reason,
            ':id' => $achievementId,
        ]);
        return $stmt->rowCount() > 0;
    }
}