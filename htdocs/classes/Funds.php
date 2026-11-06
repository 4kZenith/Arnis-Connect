<?php
declare(strict_types=1);

class Funds
{
    private PDO $conn;

    public function __construct(PDO $db)
    {
        $this->conn = $db;
    }

    public function getAll(array $filters = [], int $perPage = 25, int $page = 1): array
    {
        $perPage = min(100, max(1, $perPage));
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $sql = "SELECT f.fund_id, f.transaction_type, f.source_or_purpose, f.amount,
                       f.transaction_date, f.notes, f.related_event_id, f.recorded_by,
                       e.event_name, u.full_name as recorded_by_name
                FROM funds f
                LEFT JOIN events e ON f.related_event_id = e.event_id
                LEFT JOIN users u ON f.recorded_by = u.user_id
                WHERE f.is_voided = 0";

        $params = [];

        if (!empty($filters['type'])) {
            $sql .= " AND f.transaction_type = :type";
            $params[':type'] = $filters['type'];
        }

        if (!empty($filters['month'])) {
            $sql .= " AND DATE_FORMAT(f.transaction_date, '%Y-%m') = :month";
            $params[':month'] = $filters['month'];
        }

        $sql .= " ORDER BY f.transaction_date DESC LIMIT :limit OFFSET :offset";

        $stmt = $this->conn->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAllWithoutPagination(array $filters = []): array
    {
        $sql = "SELECT f.fund_id, f.transaction_type, f.source_or_purpose, f.amount,
                       f.transaction_date, f.notes, f.related_event_id, f.recorded_by,
                       e.event_name, u.full_name as recorded_by_name
                FROM funds f
                LEFT JOIN events e ON f.related_event_id = e.event_id
                LEFT JOIN users u ON f.recorded_by = u.user_id
                WHERE f.is_voided = 0";

        $params = [];

        if (!empty($filters['type'])) {
            $sql .= " AND f.transaction_type = :type";
            $params[':type'] = $filters['type'];
        }

        if (!empty($filters['month'])) {
            $sql .= " AND DATE_FORMAT(f.transaction_date, '%Y-%m') = :month";
            $params[':month'] = $filters['month'];
        }

        $sql .= " ORDER BY f.transaction_date DESC LIMIT 500";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getCount(array $filters = []): int
    {
        $sql = "SELECT COUNT(*) as total FROM funds f WHERE f.is_voided = 0";
        $params = [];

        if (!empty($filters['type'])) {
            $sql .= " AND f.transaction_type = :type";
            $params[':type'] = $filters['type'];
        }

        if (!empty($filters['month'])) {
            $sql .= " AND DATE_FORMAT(f.transaction_date, '%Y-%m') = :month";
            $params[':month'] = $filters['month'];
        }

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($row['total'] ?? 0);
    }

    public function getStats(array $filters = []): array
    {
        $month = !empty($filters['month']) && preg_match('/^\d{4}-\d{2}$/', (string)$filters['month'])
            ? (string)$filters['month']
            : date('Y-m');
        $type = !empty($filters['type']) && in_array($filters['type'], ['Income', 'Expense'], true)
            ? (string)$filters['type']
            : '';

        $stmt = $this->conn->prepare(
            "SELECT COALESCE(SUM(amount), 0) as total FROM funds
             WHERE transaction_type = 'Income' AND is_voided = 0
             AND DATE_FORMAT(transaction_date, '%Y-%m') = :month"
        );
        $stmt->execute([':month' => $month]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $totalIncome = (float)($row['total'] ?? 0);

        $stmt = $this->conn->prepare(
            "SELECT COALESCE(SUM(amount), 0) as total FROM funds
             WHERE transaction_type = 'Expense' AND is_voided = 0
             AND DATE_FORMAT(transaction_date, '%Y-%m') = :month"
        );
        $stmt->execute([':month' => $month]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $totalExpenses = (float)($row['total'] ?? 0);

        if ($type === 'Income') {
            $totalExpenses = 0.0;
        } elseif ($type === 'Expense') {
            $totalIncome = 0.0;
        }

        $stmt = $this->conn->prepare(
            "SELECT COALESCE(SUM(CASE WHEN transaction_type = 'Income' THEN amount ELSE 0 END), 0) as income,
                    COALESCE(SUM(CASE WHEN transaction_type = 'Expense' THEN amount ELSE 0 END), 0) as expenses
             FROM funds WHERE is_voided = 0"
        );
        $stmt->execute();
        $balance = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['income' => 0, 'expenses' => 0];
        $currentBalance = (float)$balance['income'] - (float)$balance['expenses'];

        return [
            'balance' => $currentBalance,
            'income' => $totalIncome,
            'expenses' => $totalExpenses,
            'month' => $month,
        ];
    }

    public function getById(int $fundId): ?array
    {
        $stmt = $this->conn->prepare(
            "SELECT * FROM funds WHERE fund_id = :id AND is_voided = 0 LIMIT 1"
        );
        $stmt->execute([':id' => $fundId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function create(array $data): bool
    {
        $stmt = $this->conn->prepare(
            "INSERT INTO funds
             (transaction_type, source_or_purpose, amount, transaction_date, notes,
              related_event_id, recorded_by, created_at)
             VALUES
             (:transaction_type, :source_or_purpose, :amount, :transaction_date, :notes,
              :related_event_id, :recorded_by, NOW())"
        );

        return (bool)$stmt->execute([
            ':transaction_type' => $data['transaction_type'],
            ':source_or_purpose' => $data['source_or_purpose'],
            ':amount' => $data['amount'],
            ':transaction_date' => $data['transaction_date'],
            ':notes' => $data['notes'] ?? null,
            ':related_event_id' => $data['related_event_id'] ?? null,
            ':recorded_by' => (int)$data['recorded_by'],
        ]);
    }

    public function update(int $fundId, array $data): bool
    {
        $stmt = $this->conn->prepare(
            "UPDATE funds
             SET transaction_type = :transaction_type,
                 source_or_purpose = :source_or_purpose,
                 amount = :amount,
                 transaction_date = :transaction_date,
                 notes = :notes,
                 related_event_id = :related_event_id,
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE fund_id = :id AND is_voided = 0"
        );

        $stmt->execute([
            ':transaction_type' => $data['transaction_type'],
            ':source_or_purpose' => $data['source_or_purpose'],
            ':amount' => $data['amount'],
            ':transaction_date' => $data['transaction_date'],
            ':notes' => $data['notes'] ?? null,
            ':related_event_id' => $data['related_event_id'] ?? null,
            ':updated_by' => $data['updated_by'] ?? null,
            ':id' => $fundId,
        ]);
        return $stmt->rowCount() > 0;
    }

    public function getVoided(array $filters = [], int $perPage = 25, int $page = 1): array
    {
        $perPage = min(100, max(1, $perPage));
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $sql = "SELECT f.fund_id, f.transaction_type, f.source_or_purpose, f.amount,
                       f.transaction_date, f.notes, f.voided_at, f.void_reason,
                       e.event_name, u.full_name as voided_by_name
                FROM funds f
                LEFT JOIN events e ON f.related_event_id = e.event_id
                LEFT JOIN users u ON f.voided_by = u.user_id
                WHERE f.is_voided = 1";

        $params = [];

        if (!empty($filters['type'])) {
            $sql .= " AND f.transaction_type = :type";
            $params[':type'] = $filters['type'];
        }

        if (!empty($filters['start_date'])) {
            $sql .= " AND f.voided_at >= :start_date";
            $params[':start_date'] = $filters['start_date'] . ' 00:00:00';
        }

        if (!empty($filters['end_date'])) {
            $sql .= " AND f.voided_at <= :end_date";
            $params[':end_date'] = $filters['end_date'] . ' 23:59:59';
        }

        $sql .= " ORDER BY f.voided_at DESC LIMIT :limit OFFSET :offset";

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
        $sql = "SELECT COUNT(*) as total FROM funds f WHERE f.is_voided = 1";
        $params = [];

        if (!empty($filters['type'])) {
            $sql .= " AND f.transaction_type = :type";
            $params[':type'] = $filters['type'];
        }

        if (!empty($filters['start_date'])) {
            $sql .= " AND f.voided_at >= :start_date";
            $params[':start_date'] = $filters['start_date'] . ' 00:00:00';
        }

        if (!empty($filters['end_date'])) {
            $sql .= " AND f.voided_at <= :end_date";
            $params[':end_date'] = $filters['end_date'] . ' 23:59:59';
        }

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($row['total'] ?? 0);
    }

    public function void(int $fundId, int $voidedBy, string $reason): bool
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Void reason is required.');
        }
        $stmt = $this->conn->prepare(
            "UPDATE funds
             SET is_voided = 1, voided_at = NOW(), voided_by = :voided_by, void_reason = :reason
             WHERE fund_id = :id AND is_voided = 0"
        );
        $stmt->execute([
            ':voided_by' => $voidedBy,
            ':reason' => $reason,
            ':id' => $fundId,
        ]);
        return $stmt->rowCount() > 0;
    }

    public function restore(int $fundId, int $restoredBy): bool
    {
        $stmt = $this->conn->prepare(
            "UPDATE funds
             SET is_voided = 0, voided_at = NULL, voided_by = NULL, void_reason = NULL,
                 updated_at = NOW(), updated_by = :restored_by
             WHERE fund_id = :id AND is_voided = 1"
        );
        $stmt->execute([
            ':restored_by' => $restoredBy,
            ':id' => $fundId,
        ]);
        return $stmt->rowCount() > 0;
    }
}