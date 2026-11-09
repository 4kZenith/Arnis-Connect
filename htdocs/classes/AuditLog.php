<?php
declare(strict_types=1);

/**
 * Real, append-only audit trail.
 *
 * The previous "activity log" was a UNION of created_at/updated_at/voided_at
 * columns. It could not record logins, approvals, promotions, settings changes,
 * report exports, or the actor's role/IP, and it re-described the same row up to
 * three times. This class writes one intentional event per action.
 *
 * Fails soft when the audit_logs table has not been migrated yet, so a missing
 * migration never breaks a business action.
 */
class AuditLog
{
    public static function record(
        PDO $db,
        string $action,
        string $module,
        ?int $recordId = null,
        string $description = '',
        array $meta = []
    ): void {
        try {
            $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
            $actorName = trim((string)($_SESSION['full_name'] ?? ''));
            $actorRole = trim((string)($_SESSION['role'] ?? ''));

            if (($actorName === '' || $actorRole === '') && $userId !== null) {
                $lookup = $db->prepare('SELECT full_name, role FROM users WHERE user_id = :id LIMIT 1');
                $lookup->execute([':id' => $userId]);
                $row = $lookup->fetch(PDO::FETCH_ASSOC) ?: [];
                if ($actorName === '') {
                    $actorName = (string)($row['full_name'] ?? '');
                }
                if ($actorRole === '') {
                    $actorRole = (string)($row['role'] ?? '');
                }
            }

            if ($actorName === '') {
                $actorName = 'System';
            }

            $description = trim($description);
            if (mb_strlen($description) > 500) {
                $description = mb_substr($description, 0, 497) . '...';
            }

            $stmt = $db->prepare(
                'INSERT INTO audit_logs
                    (user_id, actor_name, actor_role, action, module, record_id,
                     description, meta_json, ip_address, user_agent, created_at)
                 VALUES
                    (:user_id, :actor_name, :actor_role, :action, :module, :record_id,
                     :description, :meta_json, :ip_address, :user_agent, NOW())'
            );
            $stmt->execute([
                ':user_id' => $userId,
                ':actor_name' => $actorName,
                ':actor_role' => $actorRole,
                ':action' => mb_substr($action, 0, 50),
                ':module' => mb_substr($module, 0, 50),
                ':record_id' => $recordId !== null && $recordId > 0 ? $recordId : null,
                ':description' => $description,
                ':meta_json' => $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':ip_address' => function_exists('arnisClientIp') ? arnisClientIp() : substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
                ':user_agent' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]);
        } catch (Throwable $e) {
            error_log('AuditLog::record skipped: ' . $e->getMessage());
        }
    }

    /**
     * Record an event for a specific account (used by login, where the session
     * has not been created yet).
     */
    public static function recordForUser(
        PDO $db,
        int $userId,
        string $actorName,
        string $actorRole,
        string $action,
        string $module,
        ?int $recordId,
        string $description
    ): void {
        try {
            $stmt = $db->prepare(
                'INSERT INTO audit_logs
                    (user_id, actor_name, actor_role, action, module, record_id,
                     description, ip_address, user_agent, created_at)
                 VALUES
                    (:user_id, :actor_name, :actor_role, :action, :module, :record_id,
                     :description, :ip_address, :user_agent, NOW())'
            );
            $stmt->execute([
                ':user_id' => $userId > 0 ? $userId : null,
                ':actor_name' => $actorName !== '' ? $actorName : 'System',
                ':actor_role' => mb_substr($actorRole, 0, 50),
                ':action' => mb_substr($action, 0, 50),
                ':module' => mb_substr($module, 0, 50),
                ':record_id' => $recordId !== null && $recordId > 0 ? $recordId : null,
                ':description' => mb_substr($description, 0, 500),
                ':ip_address' => function_exists('arnisClientIp') ? arnisClientIp() : substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
                ':user_agent' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]);
        } catch (Throwable $e) {
            error_log('AuditLog::recordForUser skipped: ' . $e->getMessage());
        }
    }
}
