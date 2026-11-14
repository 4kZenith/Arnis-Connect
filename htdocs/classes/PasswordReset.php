<?php
declare(strict_types=1);

/**
 * One-time, 1-hour password reset tokens sent by email.
 *
 * Only a SHA-256 hash of the token is stored, so a leaked database or backup
 * cannot be used to reset anyone's password.
 */
class PasswordReset
{
    public const TTL_SECONDS = 3600;
    public const MAX_REQUESTS_PER_HOUR = 3;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS `password_resets` (
               `reset_id` int(11) NOT NULL AUTO_INCREMENT,
               `user_id` int(11) NOT NULL,
               `token_hash` char(64) NOT NULL,
               `expires_at` datetime NOT NULL,
               `used_at` datetime DEFAULT NULL,
               `created_at` datetime NOT NULL DEFAULT current_timestamp(),
               PRIMARY KEY (`reset_id`),
               UNIQUE KEY `uq_password_resets_token` (`token_hash`),
               KEY `idx_password_resets_user` (`user_id`, `created_at`),
               CONSTRAINT `fk_password_resets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
        );
    }

    /** Returns the plain token to email, or null when the hourly limit is reached. */
    public function issue(int $userId): ?string
    {
        $recent = $this->db->prepare(
            'SELECT COUNT(*) FROM password_resets
             WHERE user_id = :uid AND created_at > (NOW() - INTERVAL 1 HOUR)'
        );
        $recent->execute([':uid' => $userId]);
        if ((int)$recent->fetchColumn() >= self::MAX_REQUESTS_PER_HOUR) {
            return null;
        }

        $token = bin2hex(random_bytes(32));
        $this->db->prepare(
            'INSERT INTO password_resets (user_id, token_hash, expires_at, created_at)
             VALUES (:uid, :hash, NOW() + INTERVAL ' . (int)self::TTL_SECONDS . ' SECOND, NOW())'
        )->execute([':uid' => $userId, ':hash' => hash('sha256', $token)]);

        return $token;
    }

    /** @return array{reset_id:int,user_id:int}|null */
    public function findValid(string $token): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }
        $stmt = $this->db->prepare(
            'SELECT r.reset_id, r.user_id
             FROM password_resets r
             JOIN users u ON u.user_id = r.user_id
             WHERE r.token_hash = :hash AND r.used_at IS NULL
               AND r.expires_at > NOW() AND u.is_active = 1
             LIMIT 1'
        );
        $stmt->execute([':hash' => hash('sha256', $token)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? ['reset_id' => (int)$row['reset_id'], 'user_id' => (int)$row['user_id']] : null;
    }

    /**
     * Sets the new password, signs the user out everywhere, and burns every
     * outstanding token for that user. Following the emailed link also proves
     * the address, so it is marked verified.
     */
    public function complete(int $resetId, int $userId, string $newPassword): bool
    {
        $this->db->beginTransaction();
        try {
            $burn = $this->db->prepare(
                'UPDATE password_resets SET used_at = NOW() WHERE reset_id = :rid AND used_at IS NULL'
            );
            $burn->execute([':rid' => $resetId]);
            if ($burn->rowCount() !== 1) {
                $this->db->rollBack();
                return false;
            }

            $this->db->prepare(
                'UPDATE users
                 SET password_hash = :hash, session_version = session_version + 1,
                     email_verified_at = COALESCE(email_verified_at, NOW()),
                     email_verification_token = NULL, email_verification_expires_at = NULL,
                     updated_at = NOW()
                 WHERE user_id = :uid'
            )->execute([':hash' => password_hash($newPassword, PASSWORD_DEFAULT), ':uid' => $userId]);

            $this->db->prepare(
                'UPDATE password_resets SET used_at = NOW() WHERE user_id = :uid AND used_at IS NULL'
            )->execute([':uid' => $userId]);

            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
