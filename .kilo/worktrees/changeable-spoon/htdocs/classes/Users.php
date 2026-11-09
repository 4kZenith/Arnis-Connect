<?php
declare(strict_types=1);

class Users
{
    private PDO $conn;

    /*
     * SIX-ROLE MODEL. The displayed roles are Admin / Head Coach / Coach /
     * Finance / Member / Applicant; the stored code keys stay stable.
     * Committee / Board Member are retired — any legacy rows must be moved to
     * Member via SQL:
     *   UPDATE users SET role='Member' WHERE role IN ('Committee','Board Member');
     * Applicant stays valid (existing accounts + self-edit), but admins
     * cannot CREATE Applicant accounts (see process_add_user.php).
     */
    public const VALID_ROLES = [
        'SuperAdmin',
        'Head Coach',
        'Coach/Trainer',
        'Officer',
        'Member',
        'Applicant',
    ];

    private const SAFE_COLUMNS = 'user_id, full_name, username, email, member_id, email_verified_at, mobile_verified_at, last_login_at, session_version, role, is_active, created_at, updated_at';

    public function __construct(PDO $db)
    {
        $this->conn = $db;
    }

    public function getAll(array $filters = []): array
    {
        $sql = "SELECT " . self::SAFE_COLUMNS . " FROM users WHERE 1=1";
        $params = [];

        if (!empty($filters['status'])) {
            $sql .= " AND is_active = :status";
            $params[':status'] = $filters['status'] === 'Active' ? 1 : 0;
        }

        if (!empty($filters['role'])) {
            $sql .= " AND role = :role";
            $params[':role'] = $filters['role'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (full_name LIKE :search1 OR username LIKE :search2 OR email LIKE :search3)";
            $params[':search1'] = "%{$filters['search']}%";
            $params[':search2'] = "%{$filters['search']}%";
            $params[':search3'] = "%{$filters['search']}%";
        }

        $sql .= " ORDER BY created_at DESC";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getById(int $userId): ?array
    {
        $stmt = $this->conn->prepare(
            "SELECT " . self::SAFE_COLUMNS . " FROM users WHERE user_id = :id LIMIT 1"
        );
        $stmt->execute([':id' => $userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function getByUsername(string $username): ?array
    {
        $stmt = $this->conn->prepare("SELECT * FROM users WHERE username = :username LIMIT 1");
        $stmt->execute([':username' => $username]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function getByEmail(string $email): ?array
    {
        $email = trim($email);
        if ($email === '') {
            return null;
        }
        $stmt = $this->conn->prepare("SELECT * FROM users WHERE email = :email LIMIT 1");
        $stmt->execute([':email' => $email]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function getStats(): array
    {
        $stmt = $this->conn->prepare(
            "SELECT role, COUNT(*) as count FROM users WHERE is_active = 1 GROUP BY role"
        );
        $stmt->execute();
        $roleStats = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $stmt = $this->conn->prepare("SELECT COUNT(*) as total FROM users WHERE is_active = 1");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $totalActive = (int)($row['total'] ?? 0);

        $stmt = $this->conn->prepare("SELECT COUNT(*) as total FROM users WHERE is_active = 0");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $totalDeactivated = (int)($row['total'] ?? 0);

        return [
            'total_active' => $totalActive,
            'total_deactivated' => $totalDeactivated,
            'by_role' => $roleStats,
        ];
    }

    public function create(array $data): bool
    {
        $fullName = trim((string)($data['full_name'] ?? ''));
        $username = trim((string)($data['username'] ?? ''));
        $role = (string)($data['role'] ?? '');

        if ($fullName === '' || $username === '' || empty($data['password_hash'])) {
            throw new InvalidArgumentException('Full name, username and password are required.');
        }
        if (!in_array($role, self::VALID_ROLES, true)) {
            throw new InvalidArgumentException('Invalid role selected.');
        }

        if ($this->getByUsername($username)) {
            throw new RuntimeException('Username already exists.');
        }

        $email = isset($data['email']) ? trim((string)$data['email']) : '';
        if ($email === '') {
            $email = null;
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid email address.');
        } elseif ($this->getByEmail($email)) {
            throw new RuntimeException('Email is already in use.');
        }

        $memberId = isset($data['member_id']) && $data['member_id'] !== '' && $data['member_id'] !== null
            ? (int)$data['member_id']
            : null;
        if ($memberId !== null && $memberId <= 0) {
            $memberId = null;
        }

        $stmt = $this->conn->prepare(
            "INSERT INTO users (full_name, username, email, member_id, password_hash, role, is_active)
             VALUES (:full_name, :username, :email, :member_id, :password_hash, :role, :is_active)"
        );

        return $stmt->execute([
            ':full_name' => $fullName,
            ':username' => $username,
            ':email' => $email,
            ':member_id' => $memberId,
            ':password_hash' => (string)$data['password_hash'],
            ':role' => $role,
            ':is_active' => (int)($data['is_active'] ?? 1),
        ]);
    }

    public function update(int $userId, array $data): bool
    {
        if (!empty($data['username'])) {
            $existingUser = $this->getByUsername((string)$data['username']);
            if ($existingUser && (int)$existingUser['user_id'] !== $userId) {
                throw new RuntimeException('Username already exists.');
            }
        }

        $updates = [];
        $params = [':id' => $userId];

        if (isset($data['full_name']) && trim((string)$data['full_name']) !== '') {
            $updates[] = 'full_name = :full_name';
            $params[':full_name'] = trim((string)$data['full_name']);
        }

        if (isset($data['username']) && trim((string)$data['username']) !== '') {
            $updates[] = 'username = :username';
            $params[':username'] = trim((string)$data['username']);
        }

        if (array_key_exists('email', $data)) {
            $email = trim((string)$data['email']);
            if ($email === '') {
                $updates[] = 'email = NULL';
            } else {
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new InvalidArgumentException('Invalid email address.');
                }
                $existing = $this->getByEmail($email);
                if ($existing && (int)$existing['user_id'] !== $userId) {
                    throw new RuntimeException('Email is already in use.');
                }
                $updates[] = 'email = :email';
                $params[':email'] = $email;
            }
        }

        if (array_key_exists('member_id', $data)) {
            if ($data['member_id'] === null || $data['member_id'] === '') {
                $updates[] = 'member_id = NULL';
            } else {
                $mid = (int)$data['member_id'];
                if ($mid <= 0) {
                    throw new InvalidArgumentException('Invalid member reference.');
                }
                $check = $this->conn->prepare('SELECT member_id FROM members WHERE member_id = :id LIMIT 1');
                $check->execute([':id' => $mid]);
                if (!$check->fetch()) {
                    throw new RuntimeException('Linked member not found.');
                }
                $updates[] = 'member_id = :member_id';
                $params[':member_id'] = $mid;
            }
        }

        if (!empty($data['password_hash'])) {
            $updates[] = 'password_hash = :password_hash';
            $params[':password_hash'] = (string)$data['password_hash'];
        }

        if (!empty($data['role'])) {
            if (!in_array($data['role'], self::VALID_ROLES, true)) {
                throw new InvalidArgumentException('Invalid role selected.');
            }
            $updates[] = 'role = :role';
            $params[':role'] = $data['role'];
        }

        if (isset($data['is_active'])) {
            $updates[] = 'is_active = :is_active';
            $params[':is_active'] = (int)$data['is_active'] ? 1 : 0;
        }

        if (empty($updates)) {
            return true;
        }

        $updates[] = 'session_version = session_version + 1';
        $sql = 'UPDATE users SET ' . implode(', ', $updates) . ' WHERE user_id = :id';
        $stmt = $this->conn->prepare($sql);
        return $stmt->execute($params);
    }

    public function deactivate(int $userId): bool
    {
        $stmt = $this->conn->prepare(
            "UPDATE users SET is_active = 0, session_version = session_version + 1 WHERE user_id = :id"
        );
        return $stmt->execute([':id' => $userId]);
    }

    public function activate(int $userId): bool
    {
        $stmt = $this->conn->prepare(
            "UPDATE users SET is_active = 1, session_version = session_version + 1 WHERE user_id = :id"
        );
        return $stmt->execute([':id' => $userId]);
    }

    public function countActiveSuperAdmins(): int
    {
        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) FROM users WHERE role = 'SuperAdmin' AND is_active = 1"
        );
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    /*
     * Hard delete. Callers must guard: not self, not the last active
     * SuperAdmin. Throws PDOException (23000) when the account still owns
     * records (members/trainings/events created_by) — catch it and offer
     * deactivation instead.
     */
    public function delete(int $userId): bool
    {
        $stmt = $this->conn->prepare('DELETE FROM users WHERE user_id = :id');
        $stmt->execute([':id' => $userId]);
        return $stmt->rowCount() > 0;
    }

    public function verifyPassword(string $plainPassword, string $passwordHash): bool
    {
        return password_verify($plainPassword, $passwordHash);
    }

    public function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }
}