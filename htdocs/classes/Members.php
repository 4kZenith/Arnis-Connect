<?php
declare(strict_types=1);

class Members
{
    /*
     * Bootstrap password applied by FINAL_REBUILT.sql / migration 002 ONLY to
     * the accounts they AUTO-CREATE (member.<id> / applicant.<id>). Existing
     * accounts — including the Admin login — are never re-keyed by the rebuild
     * or the migration; checked-in admin credentials are not modified.
     * The hash below is pre-computed bcrypt for "default123".
     */
    public const DEFAULT_PASSWORD = 'default123';
    private const DEFAULT_PASSWORD_HASH = '$2y$10$77Zpz3isc7D0XciyJetcluWonqguoqC3tUyVSVpoAHnNq05j8/vy6';

    private PDO $conn;

    public function __construct(PDO $db)
    {
        $this->conn = $db;
    }

    /*
     * ------------------------------------------------------------------
     *  POSITION <-> ROLE MAPPING  (enforced here, not just in dropdowns)
     * ------------------------------------------------------------------
     *  members.member_type (position)   users.role (login role)
     *    Head Coach                  ->  Head Coach
     *    Coach  (legacy Trainer)     ->  Coach/Trainer
     *    Player (anything else)      ->  Member
     *
     *  These helpers are the single place the mapping lives so member
     *  creation, member editing and user editing all stay consistent.
     */

    /** Map a member position to the canonical users.role code key. */
    public static function roleForPosition(string $memberType): string
    {
        return match ($memberType) {
            'Head Coach' => 'Head Coach',
            'Coach', 'Trainer' => 'Coach/Trainer',
            default => 'Member',
        };
    }

    /** Collapse any legacy position value to the canonical three. */
    public static function normalizePosition(string $memberType): string
    {
        return match ($memberType) {
            'Head Coach' => 'Head Coach',
            'Coach', 'Trainer' => 'Coach',
            default => 'Player',
        };
    }

    /**
     * Map a users.role code key back to a member position.
     * Returns null for roles that do not own a member position
     * (Admin / Finance / Applicant), so callers leave member_type untouched.
     */
    public static function positionForRole(string $role): ?string
    {
        return match ($role) {
            'Head Coach' => 'Head Coach',
            'Coach/Trainer' => 'Coach',
            'Member' => 'Player',
            default => null,
        };
    }

    public function getAllPaginated(
        array $filters = [],
        int $page = 1,
        int $perPage = 10,
        string $userPermission = 'all',
        ?int $coachMemberId = null
    ): array {
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));

        $whereConditions = ["m.is_voided = 0"];
        $params = [];

        if (!empty($filters['status'])) {
            $whereConditions[] = "m.status = :status";
            $params[':status'] = $filters['status'];
        }

        if (!empty($filters['rank'])) {
            $whereConditions[] = "m.rank = :rank";
            $params[':rank'] = $filters['rank'];
        }

        if (!empty($filters['member_type'])) {
            $whereConditions[] = "m.member_type = :member_type";
            $params[':member_type'] = $filters['member_type'];
        }

        if (!empty($filters['player_category'])) {
            $whereConditions[] = "m.player_category = :player_category";
            $params[':player_category'] = $filters['player_category'];
        }

        if (!empty($filters['search'])) {
            $whereConditions[] = "(m.full_name LIKE :search1 OR m.member_id LIKE :search2 OR m.contact_number LIKE :search3 OR m.affiliated_school LIKE :search4 OR m.email LIKE :search5)";
            $params[':search1'] = '%' . $filters['search'] . '%';
            $params[':search2'] = '%' . $filters['search'] . '%';
            $params[':search3'] = '%' . $filters['search'] . '%';
            $params[':search4'] = '%' . $filters['search'] . '%';
            $params[':search5'] = '%' . $filters['search'] . '%';
        }

        if ($userPermission === 'limited') {
            /*
             * 'limited' = a Coach/Trainer may only see members they actually
             * train. Previously this branch only hid non-Active members, which
             * still exposed every other member's PII. Without a resolved coach
             * id we fail closed rather than fall back to the full directory.
             */
            $whereConditions[] = "m.status = 'Active'";
            if ($coachMemberId !== null && $coachMemberId > 0) {
                $whereConditions[] = "EXISTS (
                    SELECT 1
                    FROM attendance a
                    JOIN trainings t ON t.training_id = a.training_id
                    WHERE a.member_id = m.member_id
                      AND t.coach_id = :scope_coach
                      AND a.is_voided = 0
                      AND t.is_voided = 0
                )";
                $params[':scope_coach'] = $coachMemberId;
            } else {
                $whereConditions[] = "1 = 0";
            }
        }

        $whereClause = implode(' AND ', $whereConditions);

        $countSql = "SELECT COUNT(*) as total FROM members m WHERE $whereClause";
        $stmt = $this->conn->prepare($countSql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $totalMembers = (int)($row['total'] ?? 0);

        $offset = ($page - 1) * $perPage;
        $totalPages = (int)ceil(max(1, $totalMembers) / max(1, $perPage));

        $sql = "
            SELECT
                m.member_id,
                m.full_name,
                m.dob,
                m.email,
                TIMESTAMPDIFF(YEAR, m.dob, CURDATE()) as age,
                m.rank,
                m.contact_number,
                m.address,
                m.status,
                m.height,
                m.weight,
                m.member_type,
                m.player_category,
                m.training_preference,
                m.affiliated_school,
                m.guardian_name,
                m.guardian_contact,
                m.payment_notes,
                m.profile_img,
                m.created_at
            FROM members m
            WHERE $whereClause
            ORDER BY m.created_at DESC, m.full_name ASC
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $this->conn->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();

        return [
            'members' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total' => $totalMembers,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => $totalPages,
        ];
    }

    public function getStats(array $filters = [], string $userPermission = 'all', ?int $coachMemberId = null): array
    {
        $whereConditions = ["is_voided = 0"];
        $params = [];

        if (!empty($filters['search'])) {
            $whereConditions[] = "(full_name LIKE :search1 OR member_id LIKE :search2 OR contact_number LIKE :search3 OR affiliated_school LIKE :search4 OR email LIKE :search5)";
            $params[':search1'] = '%' . $filters['search'] . '%';
            $params[':search2'] = '%' . $filters['search'] . '%';
            $params[':search3'] = '%' . $filters['search'] . '%';
            $params[':search4'] = '%' . $filters['search'] . '%';
            $params[':search5'] = '%' . $filters['search'] . '%';
        }

        if (!empty($filters['rank'])) {
            $whereConditions[] = "rank = :rank";
            $params[':rank'] = $filters['rank'];
        }

        if (!empty($filters['member_type'])) {
            $whereConditions[] = "member_type = :member_type";
            $params[':member_type'] = $filters['member_type'];
        }

        if (!empty($filters['player_category'])) {
            $whereConditions[] = "player_category = :player_category";
            $params[':player_category'] = $filters['player_category'];
        }

        if (!empty($filters['status'])) {
            $whereConditions[] = "status = :status";
            $params[':status'] = $filters['status'];
        }

        if ($userPermission === 'limited') {
            $whereConditions[] = "status = 'Active'";
            if ($coachMemberId !== null && $coachMemberId > 0) {
                $whereConditions[] = "EXISTS (
                    SELECT 1
                    FROM attendance a
                    JOIN trainings t ON t.training_id = a.training_id
                    WHERE a.member_id = members.member_id
                      AND t.coach_id = :scope_coach
                      AND a.is_voided = 0
                      AND t.is_voided = 0
                )";
                $params[':scope_coach'] = $coachMemberId;
            } else {
                $whereConditions[] = "1 = 0";
            }
        }

        $whereClause = implode(' AND ', $whereConditions);

        $sql = "
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN status = 'On Leave' THEN 1 ELSE 0 END) as on_leave,
                SUM(CASE WHEN status = 'Inactive' THEN 1 ELSE 0 END) as inactive,
                SUM(CASE WHEN player_category = 'Public' THEN 1 ELSE 0 END) as public_count,
                SUM(CASE WHEN player_category = 'Private' THEN 1 ELSE 0 END) as private_count,
                SUM(CASE WHEN player_category = 'Mixed' THEN 1 ELSE 0 END) as mixed_count
            FROM members
            WHERE $whereClause
        ";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    public function getAll(array $filters = []): array
    {
        return $this->getAllPaginated($filters, 1, 100000)['members'];
    }

    public function getById(int $memberId): ?array
    {
        $stmt = $this->conn->prepare(
            "SELECT * FROM members WHERE member_id = :id AND is_voided = 0 LIMIT 1"
        );
        $stmt->execute([':id' => $memberId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result) {
            $result['age'] = $this->calculateAge($result['dob'] ?? null);
        }

        return $result ?: null;
    }

    private function calculateAge(?string $dob): ?int
    {
        if (!$dob || $dob === '0000-00-00') {
            return null;
        }

        try {
            $birthDate = new DateTime($dob);
            $today = new DateTime('today');
            return $birthDate->diff($today)->y;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function insertMember(array $data, int $createdBy): array
    {
        $profileImgUrl = null;
        if (!empty($data['cloudinary_url'])) {
            $profileImgUrl = (string)$data['cloudinary_url'];
        } elseif (!empty($data['profile_img'])) {
            $profileImgUrl = (string)$data['profile_img'];
        }

        $heightRaw = $data['height'] ?? null;
        $weightRaw = $data['weight'] ?? null;

        $stmt = $this->conn->prepare(
            "INSERT INTO members (
                full_name, dob, email, contact_number, address, rank, status,
                height, weight, member_type, player_category, training_preference,
                affiliated_school, guardian_name, guardian_contact, payment_notes,
                profile_img, created_at, created_by
            ) VALUES (
                :full_name, :dob, :email, :contact, :address, :rank, :status,
                :height, :weight, :member_type, :player_category, :training_preference,
                :affiliated_school, :guardian_name, :guardian_contact, :payment_notes,
                :profile_img, NOW(), :created_by
            )"
        );

        $stmt->execute([
            ':full_name' => $data['full_name'],
            ':dob' => !empty($data['dob']) ? $data['dob'] : null,
            ':email' => !empty($data['email']) ? $data['email'] : null,
            ':contact' => $data['contact_number'] ?? null,
            ':address' => $data['address'] ?? null,
            ':rank' => $data['rank'] ?? 'None',
            ':status' => $data['status'] ?? 'Active',
            ':height' => ($heightRaw !== null && $heightRaw !== '') ? (float)$heightRaw : null,
            ':weight' => ($weightRaw !== null && $weightRaw !== '') ? (float)$weightRaw : null,
            ':member_type' => $data['member_type'] ?? 'Player',
            ':player_category' => $data['player_category'] ?? 'Public',
            ':training_preference' => $data['training_preference'] ?? 'Group',
            ':affiliated_school' => $data['affiliated_school'] ?? null,
            ':guardian_name' => $data['guardian_name'] ?? null,
            ':guardian_contact' => $data['guardian_contact'] ?? null,
            ':payment_notes' => $data['payment_notes'] ?? null,
            ':profile_img' => $profileImgUrl,
            ':created_by' => $createdBy,
        ]);

        return [
            'success' => true,
            'member_id' => (int)$this->conn->lastInsertId(),
            'profile_img' => $profileImgUrl,
        ];
    }

    /*
     * DATA INTEGRITY: every member must own a login account with credentials.
     * Called inside the member create/approve transaction; it is a no-op when
     * the member already has a linked user (e.g. an approved applicant whose
     * Applicant login is promoted to Member).
     *
     * Optional $data keys: username, password (plain), email, member_type,
     * status. A missing username falls back to "member.<id>".
     *
     * A password is REQUIRED for a brand-new account: the admin/ applicant
     * chooses it. When none is supplied (e.g. a legacy approved request that
     * predates logins) a random one is generated and returned to the caller so
     * it can be shown once. The shared rebuild password ("default123") is
     * never applied to accounts created at run time.
     *
     * @return string|null The generated temporary password, or null when the
     *                     caller supplied one.
     */
    private function ensureLinkedUser(int $memberId, array $data, int $actor): ?string
    {
        if ($memberId <= 0) {
            return null;
        }

        $existing = $this->conn->prepare('SELECT user_id FROM users WHERE member_id = :member_id LIMIT 1');
        $existing->execute([':member_id' => $memberId]);
        if ($existing->fetchColumn()) {
            return null;
        }

        $fullName = trim((string)($data['full_name'] ?? ''));
        if ($fullName === '') {
            $fullName = 'Member ' . $memberId;
        }

        $username = trim((string)($data['username'] ?? ''));
        if ($username === '') {
            $username = 'member.' . $memberId;
        }
        $username = $this->uniqueUsername($username, $memberId);

        // Never reuse an e-mail already owned by another login (UNIQUE key).
        $email = isset($data['email']) ? trim((string)$data['email']) : '';
        if ($email !== '') {
            $check = $this->conn->prepare('SELECT user_id FROM users WHERE email = :email LIMIT 1');
            $check->execute([':email' => $email]);
            if ($check->fetchColumn()) {
                $email = '';
            }
        }
        $email = ($email !== '') ? $email : null;

        $generatedPassword = null;
        $plainPassword = (string)($data['password'] ?? '');
        if ($plainPassword === '') {
            /* Never fall back to the shared rebuild password for a run-time
             * account; mint a random one and let the caller surface it. */
            $generatedPassword = $this->randomPassword();
            $plainPassword = $generatedPassword;
        }
        $passwordHash = password_hash($plainPassword, PASSWORD_DEFAULT);

        $memberType = (string)($data['member_type'] ?? 'Player');
        $role = self::roleForPosition($memberType);
        $isActive = ((string)($data['status'] ?? 'Active') === 'Inactive') ? 0 : 1;

        $insert = $this->conn->prepare(
            'INSERT INTO users (full_name, username, email, member_id, password_hash, role, is_active)
             VALUES (:full_name, :username, :email, :member_id, :password_hash, :role, :is_active)'
        );
        $insert->execute([
            ':full_name' => $fullName,
            ':username' => $username,
            ':email' => $email,
            ':member_id' => $memberId,
            ':password_hash' => $passwordHash,
            ':role' => $role,
            ':is_active' => $isActive,
        ]);

        return $generatedPassword;
    }

    private function randomPassword(int $length = 12): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $max = strlen($alphabet) - 1;
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }
        return $out;
    }

    private function uniqueUsername(string $base, int $memberId): string
    {
        $clean = preg_replace('/[^A-Za-z0-9._-]/', '', $base);
        if ($clean === null || $clean === '') {
            $clean = 'member.' . $memberId;
        }
        $candidate = substr($clean, 0, 50);
        $suffix = 0;

        while (true) {
            $check = $this->conn->prepare('SELECT user_id FROM users WHERE username = :username LIMIT 1');
            $check->execute([':username' => $candidate]);
            if (!$check->fetchColumn()) {
                return $candidate;
            }
            $suffix++;
            $tail = '.' . $suffix;
            $candidate = substr($clean, 0, 50 - strlen($tail)) . $tail;
        }
    }

    public function create(array $data, int $createdBy): array
    {
        try {
            $this->conn->beginTransaction();
            $result = $this->insertMember($data, $createdBy);
            $this->ensureLinkedUser((int)$result['member_id'], $data, $createdBy);
            $this->conn->commit();
            return $result;
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log('Arnis Connect member creation failed: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => 'The member record could not be created.',
            ];
        }
    }

    public function update(int $memberId, array $data, int $updatedBy)
    {
        try {
            $this->conn->beginTransaction();

            $currentMember = $this->getById($memberId);
            if (!$currentMember) {
                throw new RuntimeException('Member not found');
            }

            $updateFields = [];
            $params = [':id' => $memberId, ':updated_by' => $updatedBy];

            $fieldsToCheck = [
                'full_name', 'dob', 'email', 'contact_number', 'address', 'rank',
                'status', 'height', 'weight', 'member_type', 'player_category',
                'training_preference', 'affiliated_school', 'guardian_name',
                'guardian_contact', 'payment_notes', 'profile_img',
                'face_descriptor',
            ];

            foreach ($fieldsToCheck as $field) {
                if (array_key_exists($field, $data)) {
                    $updateFields[] = "$field = :$field";
                    $params[":$field"] = $data[$field];
                }
            }

            if (empty($updateFields)) {
                if ($this->conn->inTransaction()) {
                    $this->conn->rollBack();
                }
                return ['success' => false, 'error' => 'No data to update'];
            }

            $updateFields[] = 'updated_at = NOW()';
            $updateFields[] = 'updated_by = :updated_by';

            $sql = "UPDATE members SET " . implode(', ', $updateFields) . " WHERE member_id = :id AND is_voided = 0";
            $stmt = $this->conn->prepare($sql);
            $success = $stmt->execute($params);

            if (!$success) {
                throw new RuntimeException('Failed to update member');
            }

            $this->conn->commit();
            return [
                'success' => true,
                'profile_img' => $data['profile_img'] ?? $currentMember['profile_img'],
            ];
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function getVoided(array $filters = []): array
    {
        $sql = "
            SELECT
                m.member_id,
                m.full_name,
                m.rank,
                m.status,
                m.player_category,
                m.is_voided,
                m.voided_at,
                m.void_reason,
                u.full_name as voided_by_name
            FROM members m
            LEFT JOIN users u ON m.voided_by = u.user_id
            WHERE m.is_voided = 1
        ";

        $params = [];
        if (!empty($filters['search'])) {
            $sql .= " AND m.full_name LIKE :search";
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        $sql .= " ORDER BY m.voided_at DESC";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function void(int $memberId, int $voidedBy, ?string $reason = null): bool
    {
        $stmt = $this->conn->prepare(
            "UPDATE members
             SET is_voided = 1, voided_at = NOW(), voided_by = :voided_by, void_reason = :reason
             WHERE member_id = :id AND is_voided = 0"
        );
        $stmt->execute([
            ':voided_by' => $voidedBy,
            ':reason' => $reason,
            ':id' => $memberId,
        ]);
        return $stmt->rowCount() > 0;
    }

    public function restore(int $memberId, int $restoredBy): bool
    {
        $stmt = $this->conn->prepare(
            "UPDATE members
             SET is_voided = 0, voided_at = NULL, voided_by = NULL, void_reason = NULL,
                 updated_at = NOW(), updated_by = :restored_by
             WHERE member_id = :id AND is_voided = 1"
        );
        $stmt->execute([
            ':restored_by' => $restoredBy,
            ':id' => $memberId,
        ]);
        return $stmt->rowCount() > 0;
    }

    public function countPendingRegistrationRequests(): int
    {
        $stmt = $this->conn->query("SELECT COUNT(*) FROM registration_requests WHERE status = 'Pending'");
        return (int)$stmt->fetchColumn();
    }

    /*
     * BELT LADDER — canonical promotion order (matches the members.rank ENUM
     * and the rank dropdowns). 'None' (beginner) → White → … → Black → instructional
     * degrees → master degrees. nextRankAfter() returns null at the top.
     */
    public const RANK_ORDER = [
        'None',
        'I. White Belt',
        'II. Yellow Belt',
        'III. Green Belt',
        'IV. Blue Belt',
        'V. Brown Belt',
        'VI. Black Belt',
        '1st Degree- Junior Instructor',
        '2nd Degree- Junior Instructor',
        '3rd Degree- Instructor',
        '4th Degree- Instructor',
        '5th Degree- Senior Instructor',
        '6th Degree- Senior Instructor',
        '7th Degree- Junior Master',
        '8th Degree- Master',
        '9th Degree- Senior Master',
        'VII. Red Belt',
        '10th Degree- Grandmaster',
        '11th Degree- Senior Grandmaster',
        '12th Degree- Superior Grandmaster',
    ];

    /** Guideline sessions at one belt before a promotion review. Coach decides. */
    public const SESSIONS_PER_BELT_GUIDELINE = 12;

    public static function nextRankAfter(string $rank): ?string
    {
        $i = array_search($rank, self::RANK_ORDER, true);
        if ($i === false || $i >= count(self::RANK_ORDER) - 1) {
            return null;
        }
        return self::RANK_ORDER[$i + 1];
    }

    /*
     * BELT PROGRESSION — every rank change is appended to rank_history so a
     * member's journey (White Belt → … → Black Belt → degrees) is auditable:
     * who promoted them and when. Table is created by the Oct 2026 migration;
     * both methods fail soft (log + continue) when it is missing so member
     * create/update never breaks on an unmigrated database.
     */
    public function logRankChange(int $memberId, ?string $oldRank, string $newRank, int $changedBy, ?string $notes = null): void
    {
        try {
            $stmt = $this->conn->prepare(
                "INSERT INTO rank_history (member_id, old_rank, new_rank, promoted_by, notes, promoted_at)
                 VALUES (:member_id, :old_rank, :new_rank, :promoted_by, :notes, NOW())"
            );
            $stmt->execute([
                ':member_id' => $memberId,
                ':old_rank' => $oldRank,
                ':new_rank' => $newRank,
                ':promoted_by' => $changedBy > 0 ? $changedBy : null,
                ':notes' => $notes,
            ]);
        } catch (Throwable $e) {
            error_log('logRankChange skipped: ' . $e->getMessage());
        }
    }

    public function getRankHistory(int $memberId): array
    {
        try {
            $stmt = $this->conn->prepare(
                "SELECT h.history_id, h.old_rank, h.new_rank, h.notes, h.promoted_at,
                        u.full_name AS promoted_by_name
                 FROM rank_history h
                 LEFT JOIN users u ON u.user_id = h.promoted_by
                 WHERE h.member_id = :id
                 ORDER BY h.promoted_at DESC, h.history_id DESC"
            );
            $stmt->execute([':id' => $memberId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('getRankHistory skipped: ' . $e->getMessage());
            return [];
        }
    }

    public function getPendingRegistrationRequests(int $limit = 25): array
    {
        $stmt = $this->conn->prepare(
            "SELECT * FROM registration_requests
             WHERE status = 'Pending'
             ORDER BY submitted_at ASC
             LIMIT :limit"
        );
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getRegistrationRequestById(int $requestId): ?array
    {
        $stmt = $this->conn->prepare("SELECT * FROM registration_requests WHERE request_id = :id LIMIT 1");
        $stmt->execute([':id' => $requestId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function reviewRegistrationRequest(int $requestId, string $decision, int $reviewedBy, ?string $reviewNotes = null): array
    {
        try {
            $this->conn->beginTransaction();

            $stmt = $this->conn->prepare("SELECT * FROM registration_requests WHERE request_id = :id LIMIT 1 FOR UPDATE");
            $stmt->execute([':id' => $requestId]);
            $request = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$request) {
                throw new RuntimeException('Registration request not found.');
            }

            if (($request['status'] ?? 'Pending') !== 'Pending') {
                throw new RuntimeException('This registration request has already been reviewed.');
            }

            $memberId = null;
            $tempPassword = null;
            if ($decision === 'approve') {
                $memberData = [
                    'full_name' => $request['full_name'],
                    'dob' => $request['dob'],
                    'email' => $request['email'] ?? null,
                    'contact_number' => $request['contact_number'] ?? null,
                    'address' => $request['address'] ?? null,
                    'rank' => $request['requested_rank'] ?? 'None',
                    'status' => 'Active',
                    'height' => null,
                    'weight' => null,
                    'member_type' => 'Player',
                    'player_category' => $request['player_category'] ?? 'Public',
                    'training_preference' => $request['training_preference'] ?? 'Group',
                    'affiliated_school' => $request['affiliated_school'] ?? null,
                    'guardian_name' => $request['guardian_name'] ?? null,
                    'guardian_contact' => $request['guardian_contact'] ?? null,
                    'payment_notes' => $request['payment_notes'] ?? null,
                    'profile_img' => null,
                    'username' => $request['username'] ?? '',
                ];

                $create = $this->insertMember($memberData, $reviewedBy);
                $memberId = (int)$create['member_id'];
                $status = 'Approved';
            } elseif ($decision === 'reject') {
                $status = 'Rejected';
            } else {
                throw new RuntimeException('Invalid review decision.');
            }

            $stmt = $this->conn->prepare(
                "UPDATE registration_requests
                 SET status = :status, reviewed_at = NOW(), reviewed_by = :reviewed_by,
                     review_notes = :review_notes, member_id = :member_id
                 WHERE request_id = :request_id AND status = 'Pending'"
            );
            $stmt->execute([
                ':status' => $status,
                ':reviewed_by' => $reviewedBy,
                ':review_notes' => $reviewNotes,
                ':member_id' => $memberId,
                ':request_id' => $requestId,
            ]);

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('The registration request could not be updated.');
            }

            /* Linked applicant login: approval promotes the Applicant account
             * to Member and links the new member_id; rejection disables the
             * login. The session_version bump signs them out so the new role
             * and link apply on their next sign-in. Old requests (pre-Oct
             * 2026) have no user_id and are skipped safely. */
            $linkedUserId = isset($request['user_id']) && $request['user_id'] !== null
                ? (int)$request['user_id']
                : 0;
            if ($linkedUserId > 0) {
                if ($status === 'Approved') {
                    $promote = $this->conn->prepare(
                        "UPDATE users
                         SET role = 'Member', member_id = :member_id,
                             session_version = session_version + 1, updated_at = NOW()
                         WHERE user_id = :user_id AND role = 'Applicant'"
                    );
                    $promote->execute([':member_id' => $memberId, ':user_id' => $linkedUserId]);
                } else {
                    $disable = $this->conn->prepare(
                        "UPDATE users
                         SET is_active = 0, session_version = session_version + 1, updated_at = NOW()
                         WHERE user_id = :user_id AND role = 'Applicant'"
                    );
                    $disable->execute([':user_id' => $linkedUserId]);
                }
            } elseif ($status === 'Approved' && $memberId !== null && $memberId > 0) {
                /* Legacy request with no linked login: guarantee the new member
                 * still gets an account. A random password is generated (never
                 * the shared default) and reported back so the reviewer can
                 * hand it to the applicant. */
                $tempPassword = $this->ensureLinkedUser((int)$memberId, $memberData, $reviewedBy);
            }

            $this->conn->commit();

            $message = $status === 'Approved'
                ? 'Registration approved: member created and login activated as Member.'
                : 'Registration request rejected (applicant login disabled).';
            if (!empty($tempPassword)) {
                $message .= ' Temporary login password: ' . $tempPassword
                    . ' — share it with the applicant and ask them to change it.';
            }

            return [
                'success' => true,
                'message' => $message,
            ];
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log('Arnis Connect registration review failed: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}