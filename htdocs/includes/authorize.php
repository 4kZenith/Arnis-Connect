<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$roles = require __DIR__ . '/../config/roles.php';
$permissions = require __DIR__ . '/../config/permissions.php';

if (!function_exists('currentUserRole')) {
    function currentUserRole(): ?string
    {
        return isset($_SESSION['role']) ? (string)$_SESSION['role'] : null;
    }
}

if (!function_exists('hasRole')) {
    function hasRole(string $role): bool
    {
        $current = currentUserRole();
        return $current !== null && $current === $role;
    }
}

if (!function_exists('hasAnyRole')) {
    function hasAnyRole(array $allowedRoles): bool
    {
        $current = currentUserRole();
        return $current !== null && in_array($current, $allowedRoles, true);
    }
}

if (!function_exists('permissionValue')) {
    function permissionValue(string $module, string $action = 'read')
    {
        global $permissions;
        $role = currentUserRole();
        if ($role === null) {
            return false;
        }
        return $permissions[$module][$role][$action] ?? false;
    }
}

if (!function_exists('getPermission')) {
    function getPermission(string $module, string $action = 'read')
    {
        return permissionValue($module, $action);
    }
}

if (!function_exists('hasPermission')) {
    function hasPermission(string $module, string $action = 'read'): bool
    {
        return permissionValue($module, $action) !== false;
    }
}

if (!function_exists('canAccess')) {
    function canAccess(string $module, string $action = 'read'): bool
    {
        return hasPermission($module, $action);
    }
}

/**
 * Resolve the current user's coach member_id (for Coach/Trainer roles).
 * Returns null if not a coach or cannot be resolved.
 */
if (!function_exists('getCurrentCoachMemberId')) {
    function getCurrentCoachMemberId(PDO $db): ?int
    {
        static $resolved = false;
        static $coachMemberId = null;

        if ($resolved) {
            return $coachMemberId;
        }
        $resolved = true;

        $sessionMemberId = $_SESSION['member_id'] ?? null;
        if ($sessionMemberId !== null && (int)$sessionMemberId > 0) {
            // Verify this member is actually a coach-type
            $stmt = $db->prepare("
                SELECT member_id FROM members
                WHERE member_id = :id AND is_voided = 0
                AND member_type IN ('Head Coach', 'Coach', 'Trainer')
                LIMIT 1
            ");
            $stmt->execute([':id' => (int)$sessionMemberId]);
            if ($stmt->fetch()) {
                $coachMemberId = (int)$sessionMemberId;
                return $coachMemberId;
            }
        }

        // Fallback: match by full_name (legacy)
        try {
            $stmt = $db->prepare("
                SELECT member_id
                FROM members
                WHERE is_voided = 0
                  AND member_type IN ('Head Coach', 'Coach', 'Trainer')
                  AND full_name = :full_name
                ORDER BY member_id ASC
                LIMIT 1
            ");
            $stmt->execute([':full_name' => (string)($_SESSION['full_name'] ?? '')]);
            $found = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($found) {
                $coachMemberId = (int)$found['member_id'];
            }
        } catch (Throwable $e) {
            error_log('Coach member lookup failed: ' . $e->getMessage());
        }

        return $coachMemberId;
    }
}

if (!function_exists('canPerformAction')) {
    function canPerformAction(string $module, string $action, ?array $ownerContext = null): bool
    {
        // SuperAdmin always has full access regardless of permission matrix
        if (hasRole('SuperAdmin')) {
            return true;
        }

        /*
         * Head Coach and Coach share the same ownership resolution machinery:
         * both hold scoped 'own' / 'own_trainees' / 'own_events' grants and are
         * resolved to a coach member_id. Treating them with one predicate keeps
         * a Head Coach from being silently denied access to their own work.
         */
        $isCoachRole = static function (): bool {
            return hasRole('Coach/Trainer') || hasRole('Head Coach');
        };

        $value = permissionValue($module, $action);
        if ($value === true) {
            return true; // Full access
        }
        if ($value === false) {
            return false; // No access
        }

        // Scoped permissions require ownership verification
        if ($ownerContext === null) {
            return false; // Fail closed: no context = cannot verify ownership
        }

        $currentUserId = (int)($_SESSION['user_id'] ?? 0);
        $currentMemberId = isset($_SESSION['member_id']) && $_SESSION['member_id'] !== null
            ? (int)$_SESSION['member_id']
            : null;
        $ownerUserId = isset($ownerContext['created_by']) ? (int)$ownerContext['created_by'] : 0;
        $ownerMemberId = isset($ownerContext['member_id']) ? (int)$ownerContext['member_id'] : null;
        $coachId = isset($ownerContext['coach_id']) ? (int)$ownerContext['coach_id'] : 0;
        $eventCreatedBy = isset($ownerContext['event_created_by']) ? (int)$ownerContext['event_created_by']
            : (isset($ownerContext['created_by']) ? (int)$ownerContext['created_by'] : 0);
        $eventHandlerId = isset($ownerContext['handler_id']) ? (int)$ownerContext['handler_id'] : 0;
        $trainingId = isset($ownerContext['training_id']) ? (int)$ownerContext['training_id'] : 0;
        $achievementId = isset($ownerContext['achievement_id']) ? (int)$ownerContext['achievement_id'] : 0;
        $documentId = isset($ownerContext['document_id']) ? (int)$ownerContext['document_id'] : 0;

        // Get coach member_id for Coach / Head Coach roles
        $coachMemberId = null;
        if ($isCoachRole()) {
            try {
                $db = (new Database())->connect();
                $coachMemberId = getCurrentCoachMemberId($db);
            } catch (Throwable $e) {
                error_log('Failed to resolve coach member_id: ' . $e->getMessage());
            }
        }

        // Event ownership is special: the creator OR the assigned handler owns it.
        if ($module === 'events' && in_array($value, ['own', 'own_events'], true)) {
            if ($eventCreatedBy > 0 && $eventCreatedBy === $currentUserId) {
                return true;
            }
            if ($eventHandlerId > 0) {
                $sessionMember = isset($_SESSION['member_id']) && $_SESSION['member_id'] !== null
                    ? (int)$_SESSION['member_id']
                    : (function_exists('resolveSessionMemberId') ? resolveSessionMemberId() : 0);
                if ($sessionMember > 0 && $eventHandlerId === $sessionMember) {
                    return true;
                }
            }
            return false;
        }

        switch ($value) {
            case 'own':
                // User owns the record if they created it (user_id match) ...
                if ($ownerUserId > 0 && $ownerUserId === $currentUserId) {
                    return true;
                }
                // ... or if the record IS them (member_id match against the
                // session's linked member). Without this, Member/Applicant
                // 'own' holders (e.g. editing their own profile picture)
                // fail every ownership check.
                if ($ownerMemberId !== null && $currentMemberId !== null
                    && $ownerMemberId > 0 && $ownerMemberId === $currentMemberId) {
                    return true;
                }
                return false;

            case 'own_trainees':
                // Coach / Head Coach can act on their own trainees/trainings
                if (!$isCoachRole() || $coachMemberId === null) {
                    return false;
                }
                switch ($module) {
                    case 'members':
                        // Coach can manage members who attend their trainings
                        if ($ownerMemberId === null) {
                            return false;
                        }
                        try {
                            $db = (new Database())->connect();
                            $stmt = $db->prepare("
                                SELECT 1 FROM attendance a
                                JOIN trainings t ON t.training_id = a.training_id
                                WHERE a.member_id = :member_id
                                  AND t.coach_id = :coach_id
                                  AND a.is_voided = 0
                                  AND t.is_voided = 0
                                LIMIT 1
                            ");
                            $stmt->execute([
                                ':member_id' => $ownerMemberId,
                                ':coach_id' => $coachMemberId,
                            ]);
                            return (bool)$stmt->fetch();
                        } catch (Throwable $e) {
                            return false;
                        }
                    case 'trainings':
                        // Coach can manage their own trainings
                        return $coachId > 0 && $coachId === $coachMemberId;
                    case 'achievements':
                        // Coach can manage achievements for their trainees
                        if ($ownerMemberId !== null) {
                            // Check by member_id (for create/update)
                            try {
                                $db = (new Database())->connect();
                                $stmt = $db->prepare("
                                    SELECT 1 FROM attendance a
                                    JOIN trainings t ON t.training_id = a.training_id
                                    WHERE a.member_id = :member_id
                                      AND t.coach_id = :coach_id
                                      AND a.is_voided = 0
                                      AND t.is_voided = 0
                                    LIMIT 1
                                ");
                                $stmt->execute([
                                    ':member_id' => $ownerMemberId,
                                    ':coach_id' => $coachMemberId,
                                ]);
                                return (bool)$stmt->fetch();
                            } catch (Throwable $e) {
                                return false;
                            }
                        } elseif ($achievementId > 0) {
                            // Check by achievement_id (for void)
                            try {
                                $db = (new Database())->connect();
                                $stmt = $db->prepare("
                                    SELECT 1 FROM achievements a
                                    JOIN attendance att ON att.member_id = a.member_id
                                    JOIN trainings t ON t.training_id = att.training_id
                                    WHERE a.achievement_id = :achievement_id
                                      AND t.coach_id = :coach_id
                                      AND a.is_voided = 0
                                      AND att.is_voided = 0
                                      AND t.is_voided = 0
                                    LIMIT 1
                                ");
                                $stmt->execute([
                                    ':achievement_id' => $achievementId,
                                    ':coach_id' => $coachMemberId,
                                ]);
                                return (bool)$stmt->fetch();
                            } catch (Throwable $e) {
                                return false;
                            }
                        }
                        return false;
                    case 'attendance':
                        // For attendance delete, check if the training belongs to this coach
                        if ($trainingId > 0) {
                            try {
                                $db = (new Database())->connect();
                                $stmt = $db->prepare("
                                    SELECT 1 FROM trainings
                                    WHERE training_id = :training_id
                                      AND coach_id = :coach_id
                                      AND is_voided = 0
                                    LIMIT 1
                                ");
                                $stmt->execute([
                                    ':training_id' => $trainingId,
                                    ':coach_id' => $coachMemberId,
                                ]);
                                return (bool)$stmt->fetch();
                            } catch (Throwable $e) {
                                return false;
                            }
                        }
                        return false;
                    default:
                        return false;
                }

            case 'own_events':
                // Coach / Head Coach can act on events they created OR handle.
                if (!$isCoachRole()) {
                    return false;
                }
                if ($eventCreatedBy > 0 && $eventCreatedBy === $currentUserId) {
                    return true;
                }
                if ($eventHandlerId > 0) {
                    $sessionMember = isset($_SESSION['member_id']) && $_SESSION['member_id'] !== null
                        ? (int)$_SESSION['member_id']
                        : (function_exists('resolveSessionMemberId') ? resolveSessionMemberId() : 0);
                    return $sessionMember > 0 && $eventHandlerId === $sessionMember;
                }
                return false;

            case 'limited':
                // Officer limited delete: can only void records they created
                if (hasRole('Officer') && $module === 'members') {
                    return $ownerUserId > 0 && $ownerUserId === $currentUserId;
                }
                // Committee limited read/write: already filtered at query level
                if (hasRole('Committee')) {
                    return true; // Query-level filtering handles the rest
                }
                return false;

            default:
                return false;
        }
    }
}

if (!function_exists('resolveSessionMemberId')) {
    /**
     * The members.member_id behind the current login.
     *
     * users.member_id is the canonical link (auth.php puts it in the session),
     * but accounts created before linking existed have it NULL. For those we
     * fall back to an exact full_name match, the same convention dashboard.php
     * already uses. Returns 0 when nothing can be resolved. Pass $db to enable
     * the fallback; without it only the session link is used.
     */
    function resolveSessionMemberId(?PDO $db = null): int
    {
        static $resolved = false;
        static $memberId = 0;

        if ($resolved) {
            return $memberId;
        }

        if (isset($_SESSION['member_id']) && $_SESSION['member_id'] !== null && (int)$_SESSION['member_id'] > 0) {
            $memberId = (int)$_SESSION['member_id'];
            $resolved = true;
            return $memberId;
        }

        if ($db === null) {
            return 0; // do not cache: a later call may supply a connection
        }

        try {
            $stmt = $db->prepare(
                "SELECT member_id FROM members
                 WHERE is_voided = 0 AND full_name = :name
                 ORDER BY member_id ASC LIMIT 1"
            );
            $stmt->execute([':name' => (string)($_SESSION['full_name'] ?? '')]);
            $found = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($found) {
                $memberId = (int)$found['member_id'];
            }
        } catch (Throwable $e) {
            error_log('resolveSessionMemberId failed: ' . $e->getMessage());
        }

        $resolved = true;
        return $memberId;
    }
}

if (!function_exists('requirePermission')) {
    function requirePermission(string $module, string $action = 'read'): void
    {
        if (!hasPermission($module, $action)) {
            http_response_code(403);
            exit('Access denied.');
        }
    }
}

if (!function_exists('requireRole')) {
    function requireRole(string $requiredRole): void
    {
        if (!hasRole($requiredRole)) {
            http_response_code(403);
            exit('Access denied.');
        }
    }
}

if (!function_exists('requireAnyRole')) {
    function requireAnyRole(array $allowedRoles): void
    {
        if (!hasAnyRole($allowedRoles)) {
            http_response_code(403);
            exit('Access denied.');
        }
    }
}