<?php
declare(strict_types=1);

class Trainings
{
    private PDO $conn;

    public function __construct(PDO $db)
    {
        $this->conn = $db;
    }

    private function buildFilterClauses(array $filters, array &$params, string $trainingAlias = 't', string $memberAlias = 'm'): string
    {
        $whereConditions = ["{$trainingAlias}.is_voided = 0"];

        if (!empty($filters['search'])) {
            /* Plain LIKE with distinct placeholders (native prepares allow
             * each placeholder only ONCE per statement, so :search1/2/3).
             * No ESCAPE clause: on hosts running NO_BACKSLASH_ESCAPES
             * sql_mode, ESCAPE '\\' throws "ESCAPE string must be exactly
             * one character" and EVERY search white-pages with
             * "Error loading training sessions". A % or _ typed in the box
             * just acts as a wildcard, which is harmless for search. */
            $like = '%' . (string)$filters['search'] . '%';
            $whereConditions[] = "({$trainingAlias}.location LIKE :search1 OR {$trainingAlias}.description LIKE :search2 OR {$memberAlias}.full_name LIKE :search3)";
            $params[':search1'] = $like;
            $params[':search2'] = $like;
            $params[':search3'] = $like;
        }

        if (!empty($filters['type'])) {
            $whereConditions[] = "{$trainingAlias}.training_type = :type";
            $params[':type'] = $filters['type'];
        }

        if (!empty($filters['target_group'])) {
            $whereConditions[] = "{$trainingAlias}.target_group = :target_group";
            $params[':target_group'] = $filters['target_group'];
        }

        if (!empty($filters['start_date'])) {
            $whereConditions[] = "{$trainingAlias}.training_date >= :start_date";
            $params[':start_date'] = $filters['start_date'];
        }

        if (!empty($filters['end_date'])) {
            $whereConditions[] = "{$trainingAlias}.training_date <= :end_date";
            $params[':end_date'] = $filters['end_date'];
        }

        // Filter by member roster (for Member self-view: only trainings they attend)
        if (!empty($filters['member_id']) && is_numeric($filters['member_id'])) {
            $whereConditions[] = "EXISTS (SELECT 1 FROM attendance a WHERE a.training_id = {$trainingAlias}.training_id AND a.member_id = :member_id AND a.is_voided = 0)";
            $params[':member_id'] = (int)$filters['member_id'];
        }

        return implode(' AND ', $whereConditions);
    }

    public function getAll(array $filters = []): array
    {
        $params = [];
        $whereClause = $this->buildFilterClauses($filters, $params);

        $sql = "
            SELECT
                t.training_id,
                t.training_date,
                t.training_type,
                t.target_group,
                t.venue_type,
                t.fee_amount,
                t.start_time,
                t.end_time,
                t.location,
                t.description,
                t.coach_id,
                m.full_name as coach_name,
                m.rank as coach_rank,
                m.member_type as coach_type
            FROM trainings t
            LEFT JOIN members m ON t.coach_id = m.member_id
            WHERE $whereClause
            ORDER BY t.training_date DESC, t.start_time DESC
        ";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getById(int $trainingId): ?array
    {
        $stmt = $this->conn->prepare(
            "SELECT t.*, m.full_name as coach_name, m.rank as coach_rank, m.member_type as coach_type
             FROM trainings t
             LEFT JOIN members m ON t.coach_id = m.member_id
             WHERE t.training_id = :id AND t.is_voided = 0
             LIMIT 1"
        );
        $stmt->execute([':id' => $trainingId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getStats(array $filters = []): array
    {
        return $this->getStatsOptimized($filters);
    }

    public function create(array $data, ?int $createdBy = null): bool
    {
        $stmt = $this->conn->prepare(
            "INSERT INTO trainings
             (training_date, training_type, target_group, venue_type, fee_amount,
              start_time, end_time, location, description, coach_id, created_by,
              venue_lat, venue_lng, geofence_m)
             VALUES
             (:training_date, :training_type, :target_group, :venue_type, :fee_amount,
              :start_time, :end_time, :location, :description, :coach_id, :created_by,
              :venue_lat, :venue_lng, :geofence_m)"
        );

        return (bool)$stmt->execute([
            ':training_date' => $data['training_date'],
            ':training_type' => $data['training_type'],
            ':target_group' => $data['target_group'] ?? 'Mixed',
            ':venue_type' => $data['venue_type'] ?? 'Custom',
            ':fee_amount' => isset($data['fee_amount']) && $data['fee_amount'] !== '' ? (float)$data['fee_amount'] : 0,
            ':start_time' => $data['start_time'],
            ':end_time' => $data['end_time'],
            ':location' => $data['location'] ?? null,
            ':description' => $data['description'] ?? null,
            ':coach_id' => (int)$data['coach_id'],
            ':created_by' => $createdBy,
            ':venue_lat' => isset($data['venue_lat']) && $data['venue_lat'] !== '' ? (float)$data['venue_lat'] : null,
            ':venue_lng' => isset($data['venue_lng']) && $data['venue_lng'] !== '' ? (float)$data['venue_lng'] : null,
            ':geofence_m' => isset($data['geofence_m']) && $data['geofence_m'] !== '' ? (int)$data['geofence_m'] : 100,
        ]);
    }

    public function update(int $trainingId, array $data): bool
    {
        $stmt = $this->conn->prepare(
            "UPDATE trainings
             SET training_date = :training_date,
                 training_type = :training_type,
                 target_group = :target_group,
                 venue_type = :venue_type,
                 fee_amount = :fee_amount,
                 start_time = :start_time,
                 end_time = :end_time,
                 location = :location,
                 description = :description,
                 coach_id = :coach_id,
                 venue_lat = :venue_lat,
                 venue_lng = :venue_lng,
                 geofence_m = :geofence_m,
                 updated_at = NOW(),
                 updated_by = :updated_by
             WHERE training_id = :id AND is_voided = 0"
        );

        $stmt->execute([
            ':training_date' => $data['training_date'],
            ':training_type' => $data['training_type'] ?? 'Regular',
            ':target_group' => $data['target_group'] ?? 'Mixed',
            ':venue_type' => $data['venue_type'] ?? 'Custom',
            ':fee_amount' => isset($data['fee_amount']) && $data['fee_amount'] !== '' ? (float)$data['fee_amount'] : 0,
            ':start_time' => $data['start_time'],
            ':end_time' => $data['end_time'],
            ':location' => $data['location'] ?? null,
            ':description' => $data['description'] ?? null,
            ':coach_id' => (int)$data['coach_id'],
            ':venue_lat' => isset($data['venue_lat']) && $data['venue_lat'] !== '' ? (float)$data['venue_lat'] : null,
            ':venue_lng' => isset($data['venue_lng']) && $data['venue_lng'] !== '' ? (float)$data['venue_lng'] : null,
            ':geofence_m' => isset($data['geofence_m']) && $data['geofence_m'] !== '' ? (int)$data['geofence_m'] : 100,
            ':updated_by' => $data['updated_by'] ?? null,
            ':id' => $trainingId,
        ]);
        return $stmt->rowCount() > 0;
    }

    public function void(int $trainingId, int $voidedBy, ?string $reason = null): bool
    {
        $stmt = $this->conn->prepare(
            "UPDATE trainings
             SET is_voided = 1, voided_at = NOW(), voided_by = :voided_by, void_reason = :reason
             WHERE training_id = :id AND is_voided = 0"
        );
        $stmt->execute([
            ':voided_by' => $voidedBy,
            ':reason' => $reason,
            ':id' => $trainingId,
        ]);
        return $stmt->rowCount() > 0;
    }

    public function getVoided(array $filters = []): array
    {
        $sql = "
            SELECT t.training_id, t.training_date, t.training_type, t.target_group,
                   t.venue_type, t.fee_amount, t.start_time, t.end_time, t.location,
                   t.description, t.voided_at, t.void_reason,
                   m.full_name as coach_name, u.full_name as voided_by_name
            FROM trainings t
            LEFT JOIN members m ON t.coach_id = m.member_id
            LEFT JOIN users u ON t.voided_by = u.user_id
            WHERE t.is_voided = 1
        ";

        $params = [];
        if (!empty($filters['search'])) {
            $sql .= " AND (t.location LIKE :search1 OR m.full_name LIKE :search2)";
            $params[':search1'] = '%' . $filters['search'] . '%';
            $params[':search2'] = '%' . $filters['search'] . '%';
        }
        if (!empty($filters['start_date'])) {
            $sql .= " AND t.training_date >= :start_date";
            $params[':start_date'] = $filters['start_date'];
        }
        if (!empty($filters['end_date'])) {
            $sql .= " AND t.training_date <= :end_date";
            $params[':end_date'] = $filters['end_date'];
        }
        $sql .= " ORDER BY t.voided_at DESC";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getCoaches(): array
    {
        $stmt = $this->conn->prepare(
            "SELECT member_id AS coach_id, full_name, rank, member_type, player_category
             FROM members
             WHERE member_type IN ('Head Coach', 'Coach', 'Trainer')
               AND status = 'Active'
               AND is_voided = 0
             ORDER BY CASE member_type
                        WHEN 'Head Coach' THEN 1
                        WHEN 'Coach' THEN 2
                        WHEN 'Trainer' THEN 3
                        ELSE 4 END, full_name ASC"
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function restore(int $trainingId, int $restoredBy): bool
    {
        $stmt = $this->conn->prepare(
            "UPDATE trainings
             SET is_voided = 0, voided_at = NULL, voided_by = NULL, void_reason = NULL,
                 updated_at = NOW(), updated_by = :restored_by
             WHERE training_id = :id AND is_voided = 1"
        );
        $stmt->execute([
            ':restored_by' => $restoredBy,
            ':id' => $trainingId,
        ]);
        return $stmt->rowCount() > 0;
    }

    public function getAllPaginated(array $filters = [], int $page = 1, int $perPage = 10): array
    {
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));

        $params = [];
        $whereClause = $this->buildFilterClauses($filters, $params);

        $countSql = "SELECT COUNT(*) as total FROM trainings t
                     LEFT JOIN members m ON t.coach_id = m.member_id
                     WHERE $whereClause";
        $stmt = $this->conn->prepare($countSql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $totalTrainings = (int)($row['total'] ?? 0);

        $offset = ($page - 1) * $perPage;
        $totalPages = (int)ceil(max(1, $totalTrainings) / max(1, $perPage));

        $sql = "SELECT t.training_id, t.training_date, t.training_type, t.target_group,
                       t.venue_type, t.fee_amount, t.start_time, t.end_time, t.location,
                       t.description, t.coach_id, t.venue_lat, t.venue_lng, t.geofence_m,
                       m.full_name as coach_name, m.rank as coach_rank, m.member_type as coach_type
                FROM trainings t
                LEFT JOIN members m ON t.coach_id = m.member_id
                WHERE $whereClause
                ORDER BY t.training_date DESC, t.start_time DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $this->conn->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();

        return [
            'trainings' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total' => $totalTrainings,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => $totalPages,
        ];
    }

    public function getStatsOptimized(array $filters = []): array
    {
        $today = date('Y-m-d');

        $params = [
            ':today_upcoming' => $today,
            ':today_completed' => $today,
        ];

        $whereClause = $this->buildFilterClauses($filters, $params);

        $sql = "SELECT COUNT(*) AS total,
                       SUM(CASE WHEN t.training_date >= :today_upcoming THEN 1 ELSE 0 END) AS upcoming,
                       SUM(CASE WHEN t.training_date < :today_completed THEN 1 ELSE 0 END) AS completed,
                       SUM(CASE WHEN t.target_group = 'Public' THEN 1 ELSE 0 END) AS public_sessions,
                       SUM(CASE WHEN t.target_group = 'Private' THEN 1 ELSE 0 END) AS private_sessions
                FROM trainings t
                LEFT JOIN members m ON t.coach_id = m.member_id
                WHERE $whereClause";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        $activeStats = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $cancelledConditions = ["t.is_voided = 1"];
        $cancelledParams = [];

        if (!empty($filters['search'])) {
            /* Same no-ESCAPE rule as buildFilterClauses() above. */
            $likeCancelled = '%' . (string)$filters['search'] . '%';
            $cancelledConditions[] = "(t.location LIKE :cancelled_search1 OR t.description LIKE :cancelled_search2 OR m.full_name LIKE :cancelled_search3)";
            $cancelledParams[':cancelled_search1'] = $likeCancelled;
            $cancelledParams[':cancelled_search2'] = $likeCancelled;
            $cancelledParams[':cancelled_search3'] = $likeCancelled;
        }
        if (!empty($filters['type'])) {
            $cancelledConditions[] = "t.training_type = :cancelled_type";
            $cancelledParams[':cancelled_type'] = $filters['type'];
        }
        if (!empty($filters['target_group'])) {
            $cancelledConditions[] = "t.target_group = :cancelled_target_group";
            $cancelledParams[':cancelled_target_group'] = $filters['target_group'];
        }
        if (!empty($filters['start_date'])) {
            $cancelledConditions[] = "t.training_date >= :cancelled_start_date";
            $cancelledParams[':cancelled_start_date'] = $filters['start_date'];
        }
        if (!empty($filters['end_date'])) {
            $cancelledConditions[] = "t.training_date <= :cancelled_end_date";
            $cancelledParams[':cancelled_end_date'] = $filters['end_date'];
        }

        $cancelledSql = "SELECT COUNT(*) AS cancelled FROM trainings t
                         LEFT JOIN members m ON t.coach_id = m.member_id
                         WHERE " . implode(' AND ', $cancelledConditions);

        $stmt = $this->conn->prepare($cancelledSql);
        $stmt->execute($cancelledParams);
        $cancelledRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'total' => (int)($activeStats['total'] ?? 0),
            'upcoming' => (int)($activeStats['upcoming'] ?? 0),
            'completed' => (int)($activeStats['completed'] ?? 0),
            'public_sessions' => (int)($activeStats['public_sessions'] ?? 0),
            'private_sessions' => (int)($activeStats['private_sessions'] ?? 0),
            'cancelled' => (int)($cancelledRow['cancelled'] ?? 0),
        ];
    }
}