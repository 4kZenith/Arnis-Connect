<?php
declare(strict_types=1);

class Events
{
    private PDO $conn;

    private const MAX_PARTICIPANTS = 500;

    public function __construct(PDO $db)
    {
        $this->conn = $db;
    }

    public function getAll(array $filters = [], int $perPage = 25, int $page = 1, array $userRestrictions = []): array
    {
        $perPage = min(100, max(1, $perPage));
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $participantJoin = '';
        if (!empty($userRestrictions['participant_member_id'])) {
            $participantJoin = " LEFT JOIN event_participants ep ON ep.event_id = events.event_id AND ep.is_voided = 0";
        }

        $sql = "SELECT DISTINCT event_id, event_name, event_type, event_date, location, description,
                       target_player_category, expected_participants, created_at, created_by,
                       handler_id, previous_handler_id, needs_handler
                FROM events
                $participantJoin
                WHERE events.is_voided = 0";

        $params = [];

        if (!empty($userRestrictions['created_by'])) {
            $sql .= " AND created_by = :created_by";
            $params[':created_by'] = (int)$userRestrictions['created_by'];
        }

        if (!empty($userRestrictions['participant_member_id'])) {
            $sql .= " AND ep.member_id = :participant_member_id";
            $params[':participant_member_id'] = (int)$userRestrictions['participant_member_id'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (event_name LIKE :search1 OR location LIKE :search2 OR description LIKE :search3)";
            $params[':search1'] = '%' . $filters['search'] . '%';
            $params[':search2'] = '%' . $filters['search'] . '%';
            $params[':search3'] = '%' . $filters['search'] . '%';
        }

        if (!empty($filters['type'])) {
            $sql .= " AND event_type = :type";
            $params[':type'] = $filters['type'];
        }

        if (!empty($filters['start_date'])) {
            $sql .= " AND event_date >= :start_date";
            $params[':start_date'] = $filters['start_date'];
        }

        if (!empty($filters['end_date'])) {
            $sql .= " AND event_date <= :end_date";
            $params[':end_date'] = $filters['end_date'];
        }

        $sql .= " ORDER BY event_date DESC LIMIT :limit OFFSET :offset";

        $stmt = $this->conn->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getCount(array $filters = [], array $userRestrictions = []): int
    {
        $sql = "SELECT COUNT(DISTINCT events.event_id) as total FROM events";
        $params = [];

        $participantJoin = '';
        if (!empty($userRestrictions['participant_member_id'])) {
            $participantJoin = " LEFT JOIN event_participants ep ON ep.event_id = events.event_id AND ep.is_voided = 0";
        }

        $sql = "SELECT COUNT(DISTINCT events.event_id) as total FROM events
                $participantJoin
                WHERE events.is_voided = 0";
        $params = [];

        if (!empty($userRestrictions['created_by'])) {
            $sql .= " AND created_by = :created_by";
            $params[':created_by'] = (int)$userRestrictions['created_by'];
        }

        if (!empty($userRestrictions['participant_member_id'])) {
            $sql .= " AND ep.member_id = :participant_member_id";
            $params[':participant_member_id'] = (int)$userRestrictions['participant_member_id'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (event_name LIKE :search1 OR location LIKE :search2 OR description LIKE :search3)";
            $params[':search1'] = '%' . $filters['search'] . '%';
            $params[':search2'] = '%' . $filters['search'] . '%';
            $params[':search3'] = '%' . $filters['search'] . '%';
        }

        if (!empty($filters['type'])) {
            $sql .= " AND event_type = :type";
            $params[':type'] = $filters['type'];
        }

        if (!empty($filters['start_date'])) {
            $sql .= " AND event_date >= :start_date";
            $params[':start_date'] = $filters['start_date'];
        }

        if (!empty($filters['end_date'])) {
            $sql .= " AND event_date <= :end_date";
            $params[':end_date'] = $filters['end_date'];
        }

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($row['total'] ?? 0);
    }

    public function getStats(array $filters = [], array $userRestrictions = []): array
    {
        $baseConditions = ["is_voided = 0"];
        $params = [];
        $participantJoin = '';

        if (!empty($userRestrictions['participant_member_id'])) {
            $participantJoin = " LEFT JOIN event_participants ep ON ep.event_id = events.event_id AND ep.is_voided = 0";
            $baseConditions[] = "ep.member_id = :participant_member_id";
            $params[':participant_member_id'] = (int)$userRestrictions['participant_member_id'];
        }

        if (!empty($userRestrictions['created_by'])) {
            $baseConditions[] = "created_by = :created_by";
            $params[':created_by'] = (int)$userRestrictions['created_by'];
        }

        if (!empty($filters['search'])) {
            $baseConditions[] = "(event_name LIKE :search1 OR location LIKE :search2 OR description LIKE :search3)";
            $params[':search1'] = '%' . $filters['search'] . '%';
            $params[':search2'] = '%' . $filters['search'] . '%';
            $params[':search3'] = '%' . $filters['search'] . '%';
        }

        if (!empty($filters['start_date'])) {
            $baseConditions[] = "event_date >= :start_date";
            $params[':start_date'] = $filters['start_date'];
        }

        if (!empty($filters['end_date'])) {
            $baseConditions[] = "event_date <= :end_date";
            $params[':end_date'] = $filters['end_date'];
        }

        $baseWhere = 'WHERE ' . implode(' AND ', $baseConditions);

        $counts = [];
        foreach (['Tournament', 'Performance', 'Outreach'] as $type) {
            $stmt = $this->conn->prepare(
                "SELECT COUNT(*) as count FROM events $participantJoin $baseWhere AND event_type = :stat_type"
            );
            $bound = $params;
            $bound[':stat_type'] = $type;
            $stmt->execute($bound);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $counts[$type] = (int)($row['count'] ?? 0);
        }

        $tournaments = $counts['Tournament'];
        $performances = $counts['Performance'];
        $outreach = $counts['Outreach'];

        if (!empty($filters['type'])) {
            switch ($filters['type']) {
                case 'Tournament':
                    return ['tournaments' => $tournaments, 'performances' => 0, 'outreach' => 0, 'total' => $tournaments];
                case 'Performance':
                    return ['tournaments' => 0, 'performances' => $performances, 'outreach' => 0, 'total' => $performances];
                case 'Outreach':
                    return ['tournaments' => 0, 'performances' => 0, 'outreach' => $outreach, 'total' => $outreach];
            }
        }

        return [
            'tournaments' => $tournaments,
            'performances' => $performances,
            'outreach' => $outreach,
            'total' => $tournaments + $performances + $outreach,
        ];
    }

    public function getById(int $eventId): ?array
    {
        $stmt = $this->conn->prepare(
            "SELECT e.*, hm.full_name AS handler_name, hm.member_type AS handler_type
             FROM events e
             LEFT JOIN members hm ON hm.member_id = e.handler_id
             WHERE e.event_id = :id AND e.is_voided = 0
             LIMIT 1"
        );
        $stmt->execute([':id' => $eventId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /** Active Head Coaches / Coaches eligible to handle an event. */
    public function getEligibleHandlers(): array
    {
        $stmt = $this->conn->query(
            "SELECT member_id, full_name, member_type
             FROM members
             WHERE is_voided = 0 AND status = 'Active'
               AND member_type IN ('Head Coach','Coach')
             ORDER BY full_name ASC"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function create(array $data): bool
    {
        $stmt = $this->conn->prepare(
            "INSERT INTO events
             (event_name, event_type, event_date, location, description, target_player_category,
              expected_participants, created_by, handler_id, needs_handler)
             VALUES
             (:event_name, :event_type, :event_date, :location, :description, :target_player_category,
              :expected_participants, :created_by, :handler_id, :needs_handler)"
        );

        $handlerId = isset($data['handler_id']) && (int)$data['handler_id'] > 0
            ? (int)$data['handler_id']
            : null;

        return (bool)$stmt->execute([
            ':event_name' => $data['event_name'],
            ':event_type' => $data['event_type'],
            ':event_date' => $data['event_date'],
            ':location' => $data['location'] ?? null,
            ':description' => $data['description'] ?? null,
            ':target_player_category' => $data['target_player_category'] ?? 'Mixed',
            ':expected_participants' => $this->normalizeParticipants($data['expected_participants'] ?? 8),
            ':created_by' => $data['created_by'] ?? null,
            ':handler_id' => $handlerId,
            ':needs_handler' => $handlerId === null ? 1 : 0,
        ]);
    }

    public function update(int $eventId, array $data): bool
    {
        $stmt = $this->conn->prepare(
            "UPDATE events
             SET event_name = :event_name,
                 event_type = :event_type,
                 event_date = :event_date,
                 location = :location,
                 description = :description,
                 target_player_category = :target_player_category,
                 expected_participants = :expected_participants,
                 handler_id = :handler_id,
                 previous_handler_id = :previous_handler_id,
                 needs_handler = :needs_handler,
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE event_id = :id AND is_voided = 0"
        );

        $handlerId = isset($data['handler_id']) && (int)$data['handler_id'] > 0
            ? (int)$data['handler_id']
            : null;
        $previousHandlerId = isset($data['previous_handler_id']) && (int)$data['previous_handler_id'] > 0
            ? (int)$data['previous_handler_id']
            : null;

        $stmt->execute([
            ':event_name' => $data['event_name'],
            ':event_type' => $data['event_type'],
            ':event_date' => $data['event_date'],
            ':location' => $data['location'] ?? null,
            ':description' => $data['description'] ?? null,
            ':target_player_category' => $data['target_player_category'] ?? 'Mixed',
            ':expected_participants' => $this->normalizeParticipants($data['expected_participants'] ?? 8),
            ':handler_id' => $handlerId,
            ':previous_handler_id' => $previousHandlerId,
            ':needs_handler' => $handlerId === null ? 1 : 0,
            ':updated_by' => $data['updated_by'] ?? null,
            ':id' => $eventId,
        ]);
        return $stmt->rowCount() > 0;
    }

    private function normalizeParticipants(mixed $value): int
    {
        $n = (int)$value;
        if ($n < 1) {
            return 8;
        }
        return min(self::MAX_PARTICIPANTS, $n);
    }

    public function getVoided(array $filters = [], int $perPage = 25, int $page = 1): array
    {
        $perPage = min(100, max(1, $perPage));
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $sql = "SELECT e.event_id, e.event_name, e.event_type, e.event_date, e.location,
                       e.description, e.voided_at, e.void_reason, u.full_name as voided_by_name
                FROM events e
                LEFT JOIN users u ON e.voided_by = u.user_id
                WHERE e.is_voided = 1";

        $params = [];

        if (!empty($filters['search'])) {
            $sql .= " AND (e.event_name LIKE :search1 OR e.location LIKE :search2)";
            $params[':search1'] = '%' . $filters['search'] . '%';
            $params[':search2'] = '%' . $filters['search'] . '%';
        }

        if (!empty($filters['start_date'])) {
            $sql .= " AND e.voided_at >= :start_date";
            $params[':start_date'] = $filters['start_date'] . ' 00:00:00';
        }

        if (!empty($filters['end_date'])) {
            $sql .= " AND e.voided_at <= :end_date";
            $params[':end_date'] = $filters['end_date'] . ' 23:59:59';
        }

        if (!empty($filters['type'])) {
            $sql .= " AND e.event_type = :type";
            $params[':type'] = $filters['type'];
        }

        $sql .= " ORDER BY e.voided_at DESC LIMIT :limit OFFSET :offset";

        $stmt = $this->conn->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getVoidedCount(array $filters = []): int
    {
        $sql = "SELECT COUNT(*) as total FROM events e WHERE e.is_voided = 1";
        $params = [];

        if (!empty($filters['search'])) {
            $sql .= " AND (e.event_name LIKE :search1 OR e.location LIKE :search2)";
            $params[':search1'] = '%' . $filters['search'] . '%';
            $params[':search2'] = '%' . $filters['search'] . '%';
        }

        if (!empty($filters['start_date'])) {
            $sql .= " AND e.voided_at >= :start_date";
            $params[':start_date'] = $filters['start_date'] . ' 00:00:00';
        }

        if (!empty($filters['end_date'])) {
            $sql .= " AND e.voided_at <= :end_date";
            $params[':end_date'] = $filters['end_date'] . ' 23:59:59';
        }

        if (!empty($filters['type'])) {
            $sql .= " AND e.event_type = :type";
            $params[':type'] = $filters['type'];
        }

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($row['total'] ?? 0);
    }

    public function void(int $eventId, int $voidedBy, ?string $reason = null): bool
    {
        $stmt = $this->conn->prepare(
            "UPDATE events
             SET is_voided = 1, voided_at = NOW(), voided_by = :voided_by, void_reason = :reason
             WHERE event_id = :id AND is_voided = 0"
        );
        $stmt->execute([
            ':voided_by' => $voidedBy,
            ':reason' => $reason,
            ':id' => $eventId,
        ]);
        return $stmt->rowCount() > 0;
    }

    public function restore(int $eventId, int $restoredBy): bool
    {
        $stmt = $this->conn->prepare(
            "UPDATE events
             SET is_voided = 0, voided_at = NULL, voided_by = NULL, void_reason = NULL,
                 updated_at = NOW(), updated_by = :restored_by
             WHERE event_id = :id AND is_voided = 1"
        );
        $stmt->execute([
            ':restored_by' => $restoredBy,
            ':id' => $eventId,
        ]);
        return $stmt->rowCount() > 0;
    }
}