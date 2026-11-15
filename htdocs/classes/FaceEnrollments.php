<?php
declare(strict_types=1);

/**
 * A member enrolls one face for attendance.
 * A later capture is stored as a request and does not replace the face
 * check-in uses until a SuperAdmin or Head Coach approves it.
 */
class FaceEnrollments
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
        try {
            $db->query('SELECT face_photo FROM members LIMIT 0');
        } catch (PDOException $e) {
            $db->exec('ALTER TABLE `members` ADD COLUMN `face_photo` varchar(255) DEFAULT NULL');
        }

        $db->exec(
            "CREATE TABLE IF NOT EXISTS `face_reenroll_requests` (
               `request_id` int(11) NOT NULL AUTO_INCREMENT,
               `member_id` int(11) NOT NULL,
               `requested_by` int(11) DEFAULT NULL,
               `face_descriptor` text NOT NULL,
               `photo_path` varchar(255) NOT NULL,
               `status` enum('Pending','Approved','Declined','Cancelled') NOT NULL DEFAULT 'Pending',
               `review_note` varchar(255) DEFAULT NULL,
               `reviewed_by` int(11) DEFAULT NULL,
               `reviewed_at` datetime DEFAULT NULL,
               `created_at` datetime NOT NULL DEFAULT current_timestamp(),
               PRIMARY KEY (`request_id`),
               KEY `idx_face_reenroll_member` (`member_id`, `status`),
               CONSTRAINT `fk_face_reenroll_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`member_id`) ON DELETE CASCADE
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
        self::$schemaReady = true;
    }

    public static function directory(): string
    {
        return dirname(__DIR__) . '/uploads/faces';
    }

    public static function canReview(): bool
    {
        return function_exists('permissionValue') && permissionValue('members', 'write') === true;
    }

    /** @return list<float>|null */
    public static function parseDescriptor(string $json): ?array
    {
        $decoded = json_decode(trim($json), true);
        if (!is_array($decoded) || count($decoded) !== 128) {
            return null;
        }
        $values = [];
        foreach ($decoded as $value) {
            if (!is_numeric($value)) {
                return null;
            }
            $values[] = (float)$value;
        }
        return $values;
    }

    public static function storedFile(?string $stored): ?string
    {
        $stored = trim((string)$stored);
        if ($stored === '') {
            return null;
        }
        $root = realpath(self::directory());
        if ($root === false || !is_dir($root)) {
            return null;
        }
        $normalized = str_replace('\\', '/', $stored);
        $normalized = preg_replace('#^(?:\.\./)+#', '', $normalized) ?? $normalized;
        $normalized = preg_replace('#^uploads/faces/#i', '', $normalized) ?? $normalized;
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

    public static function unlinkStored(?string $stored): void
    {
        $file = self::storedFile($stored);
        if ($file !== null) {
            @unlink($file);
        }
    }

    public function find(int $requestId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT r.request_id, r.member_id, r.requested_by, r.face_descriptor, r.photo_path,
                    r.status, r.review_note, r.created_at, m.full_name AS member_name,
                    m.face_photo, m.profile_img, m.face_descriptor AS current_descriptor
             FROM face_reenroll_requests r
             JOIN members m ON m.member_id = r.member_id
             WHERE r.request_id = :id'
        );
        $stmt->execute([':id' => $requestId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function pendingForMember(int $memberId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT request_id, member_id, photo_path, status, created_at
             FROM face_reenroll_requests
             WHERE member_id = :id AND status = \'Pending\'
             ORDER BY request_id DESC
             LIMIT 1'
        );
        $stmt->execute([':id' => $memberId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return list<array<string, mixed>> */
    public function listPending(): array
    {
        $stmt = $this->db->query(
            'SELECT r.request_id, r.member_id, r.photo_path, r.created_at,
                    m.full_name AS member_name, m.face_photo, m.profile_img
             FROM face_reenroll_requests r
             JOIN members m ON m.member_id = r.member_id
             WHERE r.status = \'Pending\' AND m.is_voided = 0
             ORDER BY r.created_at ASC, r.request_id ASC
             LIMIT 50'
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function saveFirst(int $memberId, int $userId, string $descriptorJson, string $photoPath): void
    {
        $current = $this->db->prepare(
            'SELECT face_descriptor FROM members WHERE member_id = :id AND is_voided = 0 LIMIT 1'
        );
        $current->execute([':id' => $memberId]);
        $existing = $current->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            throw new RuntimeException('Member not found.');
        }
        if (trim((string)($existing['face_descriptor'] ?? '')) !== '') {
            throw new RuntimeException('This member already has a face on file. Send a change request instead.');
        }
        $stmt = $this->db->prepare(
            'UPDATE members
             SET face_descriptor = :descriptor, face_photo = :photo, updated_at = NOW(), updated_by = :uid
             WHERE member_id = :id AND is_voided = 0'
        );
        $stmt->execute([
            ':descriptor' => $descriptorJson,
            ':photo' => $photoPath,
            ':uid' => $userId > 0 ? $userId : null,
            ':id' => $memberId,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('The face could not be saved.');
        }
    }

    public function submitRequest(int $memberId, int $userId, string $descriptorJson, string $photoPath): int
    {
        if ($this->pendingForMember($memberId) !== null) {
            throw new RuntimeException('You already have a face change waiting for review.');
        }
        $current = $this->db->prepare(
            'SELECT face_descriptor FROM members WHERE member_id = :id AND is_voided = 0 LIMIT 1'
        );
        $current->execute([':id' => $memberId]);
        $existing = $current->fetch(PDO::FETCH_ASSOC);
        if (!$existing || trim((string)($existing['face_descriptor'] ?? '')) === '') {
            throw new RuntimeException('Enroll a face first. A change request is only for a face that is already on file.');
        }
        $stmt = $this->db->prepare(
            'INSERT INTO face_reenroll_requests (member_id, requested_by, face_descriptor, photo_path, status)
             VALUES (:mid, :uid, :descriptor, :photo, \'Pending\')'
        );
        $stmt->execute([
            ':mid' => $memberId,
            ':uid' => $userId > 0 ? $userId : null,
            ':descriptor' => $descriptorJson,
            ':photo' => $photoPath,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function cancel(int $requestId, int $memberId): void
    {
        $row = $this->find($requestId);
        if ($row === null || (int)$row['member_id'] !== $memberId || (string)$row['status'] !== 'Pending') {
            throw new RuntimeException('That face request is no longer waiting.');
        }
        $stmt = $this->db->prepare(
            'UPDATE face_reenroll_requests
             SET status = \'Cancelled\', photo_path = \'\', reviewed_at = NOW()
             WHERE request_id = :id AND member_id = :mid AND status = \'Pending\''
        );
        $stmt->execute([':id' => $requestId, ':mid' => $memberId]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('That face request is no longer waiting.');
        }
        self::unlinkStored((string)$row['photo_path']);
    }

    public function decline(int $requestId, int $reviewerId, string $note): array
    {
        $note = mb_substr(trim($note), 0, 255);
        if ($note === '') {
            throw new RuntimeException('Tell the member why the new face was declined.');
        }
        $row = $this->find($requestId);
        if ($row === null || (string)$row['status'] !== 'Pending') {
            throw new RuntimeException('That face request was already handled.');
        }
        $stmt = $this->db->prepare(
            'UPDATE face_reenroll_requests
             SET status = \'Declined\', review_note = :note, photo_path = \'\', reviewed_by = :uid, reviewed_at = NOW()
             WHERE request_id = :id AND status = \'Pending\''
        );
        $stmt->execute([':note' => $note, ':uid' => $reviewerId, ':id' => $requestId]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('That face request was already handled.');
        }
        self::unlinkStored((string)$row['photo_path']);
        return $row;
    }

    public function approve(int $requestId, int $reviewerId): array
    {
        $row = $this->find($requestId);
        if ($row === null || (string)$row['status'] !== 'Pending') {
            throw new RuntimeException('That face request was already handled.');
        }
        $source = self::storedFile((string)$row['photo_path']);
        if ($source === null) {
            throw new RuntimeException('The new face photo is missing, so it cannot be approved.');
        }
        $descriptor = self::parseDescriptor((string)$row['face_descriptor']);
        if ($descriptor === null) {
            throw new RuntimeException('The new face data is unreadable.');
        }
        $ext = strtolower(pathinfo($source, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $ext = 'jpg';
        }
        if ($ext === 'jpeg') {
            $ext = 'jpg';
        }
        $memberId = (int)$row['member_id'];
        $officialName = 'official_' . $memberId . '_' . $requestId . '.' . $ext;
        $dir = self::directory();
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('The new face photo could not be saved.');
        }
        $dest = $dir . DIRECTORY_SEPARATOR . $officialName;
        if (!copy($source, $dest)) {
            throw new RuntimeException('The new face photo could not be saved.');
        }
        $officialPath = 'uploads/faces/' . $officialName;
        $previousPhoto = (string)($row['face_photo'] ?? '');

        $this->db->beginTransaction();
        try {
            $update = $this->db->prepare(
                'UPDATE members
                 SET face_descriptor = :descriptor, face_photo = :photo, updated_at = NOW(), updated_by = :uid
                 WHERE member_id = :id AND is_voided = 0'
            );
            $update->execute([
                ':descriptor' => json_encode($descriptor),
                ':photo' => $officialPath,
                ':uid' => $reviewerId,
                ':id' => $memberId,
            ]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('The face on file could not be replaced.');
            }
            $mark = $this->db->prepare(
                'UPDATE face_reenroll_requests
                 SET status = \'Approved\', photo_path = \'\', reviewed_by = :uid, reviewed_at = NOW()
                 WHERE request_id = :id AND status = \'Pending\''
            );
            $mark->execute([':uid' => $reviewerId, ':id' => $requestId]);
            if ($mark->rowCount() !== 1) {
                throw new RuntimeException('That face request was already handled.');
            }
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            @unlink($dest);
            throw $e;
        }

        self::unlinkStored((string)$row['photo_path']);
        if ($previousPhoto !== '' && $previousPhoto !== $officialPath) {
            self::unlinkStored($previousPhoto);
        }
        return $row;
    }
}
