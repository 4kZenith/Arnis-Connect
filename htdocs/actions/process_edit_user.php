<?php
declare(strict_types=1);

/*
 * UPDATE USER ACCOUNT — replaces the old file that mistakenly performed a
 * hard DELETE. Handles both entry points:
 *   - Admin "Edit User" modal (public/modals/edit_user.php):
 *       posts user_id, full_name, username, email, member_id, role,
 *       new_password, confirm_password. SuperAdmin may change role and link.
 *   - Self-service "Edit Profile" modal (edit_profile_user.php /
 *       edit_profile_user_page.php):
 *       posts user_id, full_name, username, role (read-only), password,
 *       current_password. Role / member link are ignored for non-admins and
 *       a password change requires the current password.
 *
 * Session invalidation: Users::update() bumps session_version. When editing
 * your own account the local session is refreshed afterwards so you are not
 * logged out.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/Users.php';
require_once __DIR__ . '/../classes/Members.php';
require_once __DIR__ . '/../classes/AuditLog.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

if (!arnisVerifyCsrfToken($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
    exit;
}

try {
    $currentUserId = (int)($_SESSION['user_id'] ?? 0);
    if ($currentUserId <= 0) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Not signed in.']);
        exit;
    }

    $targetId = isset($_POST['user_id']) && is_numeric($_POST['user_id'])
        ? (int)$_POST['user_id']
        : $currentUserId;
    if ($targetId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid user reference.']);
        exit;
    }

    $isSelf = ($targetId === $currentUserId);
    $isSuperAdmin = hasRole('SuperAdmin');
    $writePerm = permissionValue('users', 'write');

    if ($isSelf) {
        /*
         * Every authenticated user may maintain their own profile and change
         * their own password. Role and member-link changes are gated to the
         * SuperAdmin below, so a Member's 'users.write = false' must not block
         * self-service profile edits (that would leave them unable to correct
         * their own name or password).
         */
    } elseif ($writePerm !== true) {
        // Only SuperAdmin may modify another account.
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied.']);
        exit;
    }

    $db = (new Database())->connect();
    $usersModel = new Users($db);

    $stmt = $db->prepare(
        'SELECT user_id, full_name, username, email, member_id, role, is_active, password_hash
         FROM users WHERE user_id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $targetId]);
    $target = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$target) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'User not found.']);
        exit;
    }

    $data = [];

    // ----- Profile fields (everyone may edit their own; admins anyone) -----
    $fullName = trim((string)($_POST['full_name'] ?? ''));
    if ($fullName !== '') {
        if (mb_strlen($fullName) > 100) {
            throw new InvalidArgumentException('Full name must be 100 characters or fewer.');
        }
        $data['full_name'] = $fullName;
    }

    $username = trim((string)($_POST['username'] ?? ''));
    if ($username !== '') {
        if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
            throw new InvalidArgumentException('Username may only contain letters, numbers, dot, underscore and hyphen (3-50 characters).');
        }
        $data['username'] = $username;
    }

    if (array_key_exists('email', $_POST)) {
        $data['email'] = trim((string)($_POST['email'] ?? ''));
    }

    // ----- Role change: SuperAdmin only, never removing the last admin -----
    if ($isSuperAdmin && isset($_POST['role'])) {
        $newRole = (string)$_POST['role'];
        if ($newRole !== '') {
            if (!in_array($newRole, Users::VALID_ROLES, true)) {
                throw new InvalidArgumentException('Invalid role selected.');
            }
            if ($newRole !== $target['role']) {
                // Super Administrator role is constant and cannot be changed
                if ($target['role'] === 'SuperAdmin') {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'The Super Administrator role cannot be changed.']);
                    exit;
                }
                if ($target['role'] === 'SuperAdmin'
                    && (int)$target['is_active'] === 1
                    && $newRole !== 'SuperAdmin'
                    && $usersModel->countActiveSuperAdmins() <= 1) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'You cannot remove the last active SuperAdmin.']);
                    exit;
                }
                // Do not allow converting a user that is linked to a member
                // record into a non-member role (Admin/Officer/SuperAdmin/
                // Applicant). Such a change leaves the member row in place
                // and causes inconsistent state (e.g. a "Finance officer" still
                // taking attendance). Unlink the member first.
                $memberRoles = ['Head Coach', 'Coach/Trainer', 'Member'];
                if ($target['member_id'] !== null
                    && !in_array($newRole, $memberRoles, true)) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'This user is linked to a member record. Unlink the member before changing the role to a non-member role.']);
                    exit;
                }
                $data['role'] = $newRole;
            }
        }
    }

    // ----- Member link: SuperAdmin only -----
    if ($isSuperAdmin && array_key_exists('member_id', $_POST)) {
        $rawMember = trim((string)($_POST['member_id'] ?? ''));
        if ($rawMember !== '') {
            $effectiveRole = isset($data['role']) ? (string)$data['role'] : (string)$target['role'];
            $memberRoles = ['Head Coach', 'Coach/Trainer', 'Member'];
            if (!in_array($effectiveRole, $memberRoles, true)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'A member record can only be linked to a Head Coach, Coach/Trainer, or Member account.']);
                exit;
            }
        }
        $data['member_id'] = ($rawMember === '') ? null : (int)$rawMember;
    }

    // ----- Password changes -----
    $adminNew = (string)($_POST['new_password'] ?? '');
    $adminConfirm = (string)($_POST['confirm_password'] ?? '');
    $selfNew = (string)($_POST['password'] ?? '');
    $selfCurrent = (string)($_POST['current_password'] ?? '');

    if ($adminNew !== '' || $adminConfirm !== '') {
        if (!$isSuperAdmin) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Access denied.']);
            exit;
        }
        if ($adminNew !== $adminConfirm) {
            throw new InvalidArgumentException('New passwords do not match.');
        }
        if (strlen($adminNew) < 6) {
            throw new InvalidArgumentException('New password must be at least 6 characters.');
        }
        $data['password_hash'] = $usersModel->hashPassword($adminNew);
    } elseif ($selfNew !== '') {
        if (strlen($selfNew) < 6) {
            throw new InvalidArgumentException('New password must be at least 6 characters.');
        }
        $storedHash = (string)($target['password_hash'] ?? '');
        if ($selfCurrent === '' || !$usersModel->verifyPassword($selfCurrent, $storedHash)) {
            throw new InvalidArgumentException('Current password is incorrect.');
        }
        $data['password_hash'] = $usersModel->hashPassword($selfNew);
    }

    $updated = $usersModel->update($targetId, $data);

    if (!$updated) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to update user.']);
        exit;
    }

    /*
     * Keep the linked member's POSITION consistent with the new login role so
     * the Users page and Members page can never disagree:
     *   Head Coach -> Head Coach, Coach/Trainer -> Coach, Member -> Player.
     * Admin / Finance / Applicant have no member position, so the member
     * record is left untouched for those roles. Admin-only (role edits are
     * already gated to SuperAdmin above).
     */
    if ($isSuperAdmin && isset($data['role'])) {
        $newPosition = Members::positionForRole((string)$data['role']);
        $effectiveMember = array_key_exists('member_id', $data)
            ? (int)($data['member_id'] ?? 0)
            : (int)($target['member_id'] ?? 0);
        if ($newPosition !== null && $effectiveMember > 0) {
            $sync = $db->prepare(
                'UPDATE members SET member_type = :pos, updated_at = NOW()
                 WHERE member_id = :mid AND member_type <> :pos'
            );
            $sync->execute([':pos' => $newPosition, ':mid' => $effectiveMember]);
        }
    }

    /* Record what changed, never the password itself. */
    $changedFields = array_keys($data);
    $isPasswordChange = in_array('password_hash', $changedFields, true);
    $changedFields = array_values(array_diff($changedFields, ['password_hash']));
    $changeSummary = $changedFields === [] ? 'profile' : implode(', ', $changedFields);
    if ($isPasswordChange) {
        $changeSummary .= ($changeSummary !== '' ? ', ' : '') . 'password';
    }
    AuditLog::record(
        $db,
        'update',
        'users',
        $targetId,
        ($isSelf ? 'Updated own account' : 'Updated account "' . (string)$target['username'] . '"')
            . ' (' . $changeSummary . ')',
        ['changed' => $changedFields, 'password_changed' => $isPasswordChange]
    );

    // Keep the current session valid (and accurate) when editing yourself.
    if ($isSelf) {        $fresh = $usersModel->getById($targetId);
        if ($fresh) {
            $_SESSION['full_name'] = (string)$fresh['full_name'];
            $_SESSION['username'] = (string)$fresh['username'];
            $_SESSION['role'] = (string)$fresh['role'];
            $_SESSION['member_id'] = $fresh['member_id'] !== null ? (int)$fresh['member_id'] : null;
            $_SESSION['session_version'] = (int)$fresh['session_version'];
        }
    }

    echo json_encode(['success' => true, 'message' => 'User updated successfully.']);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'That username or email is already in use.']);
        exit;
    }
    error_log('process_edit_user DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An unexpected error occurred. Please try again.']);
} catch (InvalidArgumentException | RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('process_edit_user error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An unexpected error occurred. Please try again.']);
}
