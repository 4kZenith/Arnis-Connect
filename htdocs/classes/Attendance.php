<?php
declare(strict_types=1);

class Attendance
{
    private PDO $conn;

    private const VALID_STATUSES = ['Present', 'Late', 'Absent'];

    public function __construct(PDO $db)
    {
        $this->conn = $db;
    }

    public function getByTrainingId(int $trainingId, ?int $limit = null, ?int $offset = null): array
    {
        $sql = "
            SELECT
                a.attendance_id,
                a.member_id,
                a.status,
                a.remarks,
                a.recorded_at,
                m.full_name,
                m.rank,
                m.profile_img,
                m.player_category,
                ac.checkin_id,
                ac.method AS checkin_method,
                ac.face_score,
                ac.distance_m,
                ac.flagged,
                ac.flag_reason,
                ac.created_at AS checkin_at
            FROM attendance a
            INNER JOIN members m ON a.member_id = m.member_id
            LEFT JOIN attendance_checkins ac
              ON ac.checkin_id = (
                    SELECT ac2.checkin_id
                    FROM attendance_checkins ac2
                    WHERE ac2.training_id = a.training_id
                      AND ac2.member_id = a.member_id
                    ORDER BY ac2.created_at DESC, ac2.checkin_id DESC
                    LIMIT 1
              )
            WHERE a.training_id = :training_id
              AND a.is_voided = 0
              AND m.is_voided = 0
            ORDER BY m.full_name ASC
        ";

        if ($limit !== null) {
            $sql .= " LIMIT :limit OFFSET :offset";
        }

        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':training_id', $trainingId, PDO::PARAM_INT);
        if ($limit !== null) {
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset ?? 0, PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countByTrainingId(int $trainingId): int
    {
        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) FROM attendance
             WHERE training_id = :training_id
               AND is_voided = 0"
        );
        $stmt->execute([':training_id' => $trainingId]);
        return (int) $stmt->fetchColumn();
    }

    public function getAttendanceStats(int $trainingId): array
    {
        $stmt = $this->conn->prepare("
            SELECT
                COUNT(CASE WHEN status = 'Present' THEN 1 END) AS present_count,
                COUNT(CASE WHEN status = 'Late' THEN 1 END) AS late_count,
                COUNT(CASE WHEN status = 'Absent' THEN 1 END) AS absent_count
            FROM attendance
            WHERE training_id = :training_id AND is_voided = 0
        ");
        $stmt->execute([':training_id' => $trainingId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['present_count' => 0, 'late_count' => 0, 'absent_count' => 0];
    }

    public function getMembersNotInTraining(int $trainingId): array
    {
        $stmt = $this->conn->prepare("
            SELECT
                m.member_id,
                m.full_name,
                m.rank,
                m.profile_img,
                m.player_category,
                m.affiliated_school,
                t.target_group
            FROM trainings t
            JOIN members m
              ON (
                    t.target_group = 'Mixed'
                    OR (t.target_group = 'Public' AND COALESCE(m.player_category, 'Public') IN ('Public', 'Mixed'))
                    OR (t.target_group = 'Private' AND COALESCE(m.player_category, 'Public') IN ('Private', 'Mixed'))
                 )
            WHERE t.training_id = :training_id
              AND m.status = 'Active'
              AND m.is_voided = 0
              AND m.member_id NOT IN (
                    SELECT member_id
                    FROM attendance
                    WHERE training_id = :attendance_training_id
                      AND is_voided = 0
              )
            ORDER BY
                CASE COALESCE(m.player_category, 'Public')
                    WHEN 'Private' THEN 1
                    WHEN 'Mixed' THEN 2
                    ELSE 3
                END,
                m.full_name ASC
        ");

        $stmt->execute([
            ':training_id' => $trainingId,
            ':attendance_training_id' => $trainingId,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create(array $data): bool
    {
        $status = (string)($data['status'] ?? 'Absent');
        if (!in_array($status, self::VALID_STATUSES, true)) {
            throw new InvalidArgumentException('Invalid attendance status.');
        }

        $stmt = $this->conn->prepare("
            INSERT INTO attendance (training_id, member_id, status, remarks, recorded_by)
            VALUES (:training_id, :member_id, :status, :remarks, :recorded_by)
        ");

        return $stmt->execute([
            ':training_id' => (int)$data['training_id'],
            ':member_id'   => (int)$data['member_id'],
            ':status'      => $status,
            ':remarks'     => $data['remarks'] ?? null,
            ':recorded_by' => (int)$data['recorded_by'],
        ]);
    }

    public function addMultipleMembers(int $trainingId, array $memberIds, int $recordedBy): bool
    {
        $memberIds = array_values(array_unique(array_map('intval', $memberIds)));
        $memberIds = array_filter($memberIds, static fn(int $id): bool => $id > 0);
        if (empty($memberIds)) {
            return false;
        }

        try {
            $this->conn->beginTransaction();

            $checkStmt = $this->conn->prepare(
                "SELECT member_id, is_voided FROM attendance
                 WHERE training_id = :training_id AND member_id = :member_id
                 LIMIT 1"
            );
            $reactivateStmt = $this->conn->prepare(
                "UPDATE attendance
                 SET is_voided = 0, voided_at = NULL, voided_by = NULL, void_reason = NULL,
                     status = 'Absent', updated_by = :recorded_by, updated_at = NOW()
                 WHERE training_id = :training_id AND member_id = :member_id"
            );
            $insertStmt = $this->conn->prepare(
                "INSERT INTO attendance (training_id, member_id, status, recorded_by)
                 VALUES (:training_id, :member_id, 'Absent', :recorded_by)"
            );

            foreach ($memberIds as $memberId) {
                $checkStmt->execute([':training_id' => $trainingId, ':member_id' => $memberId]);
                $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
                if ($existing) {
                    if ((int)$existing['is_voided'] === 1) {
                        $reactivateStmt->execute([
                            ':training_id' => $trainingId,
                            ':member_id'   => $memberId,
                            ':recorded_by' => $recordedBy,
                        ]);
                    }
                    continue;
                }
                $insertStmt->execute([
                    ':training_id' => $trainingId,
                    ':member_id'   => $memberId,
                    ':recorded_by' => $recordedBy,
                ]);
            }

            $this->conn->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log('Attendance::addMultipleMembers failed: ' . $e->getMessage());
            return false;
        }
    }

    public function updateStatus(int $attendanceId, string $status, ?string $remarks = null): bool
    {
        if (!in_array($status, self::VALID_STATUSES, true)) {
            throw new InvalidArgumentException('Invalid attendance status.');
        }

        $stmt = $this->conn->prepare("
            UPDATE attendance
            SET status = :status, remarks = :remarks
            WHERE attendance_id = :id AND is_voided = 0
        ");

        $stmt->execute([
            ':status'  => $status,
            ':remarks' => $remarks,
            ':id'      => $attendanceId,
        ]);
        return $stmt->rowCount() > 0;
    }

    public function updateByTrainingAndMember(int $trainingId, int $memberId, string $status, ?string $remarks = null, ?int $updatedBy = null): bool
    {
        if (!in_array($status, self::VALID_STATUSES, true)) {
            throw new InvalidArgumentException('Invalid attendance status.');
        }

        $stmt = $this->conn->prepare("
            UPDATE attendance
            SET status = :status, remarks = :remarks, updated_by = :updated_by
            WHERE training_id = :training_id
              AND member_id = :member_id
              AND is_voided = 0
        ");

        $stmt->execute([
            ':status'      => $status,
            ':remarks'     => $remarks,
            ':updated_by'  => $updatedBy,
            ':training_id' => $trainingId,
            ':member_id'   => $memberId,
        ]);
        return $stmt->rowCount() > 0;
    }

    public function void(int $attendanceId, int $voidedBy, ?string $reason = null): bool
    {
        $stmt = $this->conn->prepare("
            UPDATE attendance
            SET is_voided = 1, voided_at = NOW(), voided_by = :voided_by, void_reason = :reason
            WHERE attendance_id = :id AND is_voided = 0
        ");

        $stmt->execute([
            ':voided_by' => $voidedBy,
            ':reason'    => $reason,
            ':id'        => $attendanceId,
        ]);
        return $stmt->rowCount() > 0;
    }

    public function removeFromTraining(int $trainingId, int $memberId, ?int $voidedBy = null, ?string $reason = null): bool
    {
        $stmt = $this->conn->prepare("
            UPDATE attendance
            SET is_voided = 1,
                voided_at = NOW(),
                voided_by = :voided_by,
                void_reason = :reason
            WHERE training_id = :training_id AND member_id = :member_id AND is_voided = 0
        ");

        $stmt->execute([
            ':training_id' => $trainingId,
            ':member_id'   => $memberId,
            ':voided_by'   => $voidedBy,
            ':reason'      => $reason ?? 'Removed from training',
        ]);
        return $stmt->rowCount() > 0;
    }

    public function restore(int $attendanceId, int $restoredBy): bool
    {
        $stmt = $this->conn->prepare("
            UPDATE attendance
            SET
                is_voided = 0,
                voided_at = NULL,
                voided_by = NULL,
                void_reason = NULL,
                updated_at = NOW(),
                updated_by = :restored_by
            WHERE attendance_id = :id AND is_voided = 1
        ");

        $stmt->execute([
            ':restored_by' => $restoredBy,
            ':id'          => $attendanceId,
        ]);
        return $stmt->rowCount() > 0;
    }
}