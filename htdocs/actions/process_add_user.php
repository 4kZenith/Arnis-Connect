<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/Users.php';
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

/*
 * Account creation is administrator-only.
 *
 * NOTE: requirePermission() is NOT sufficient here. 'own' grants satisfy
 * hasPermission() (it only tests `!== false`), and Member/Applicant hold
 * users.write = 'own'. Without the strict check below, any Member could POST
 * role=SuperAdmin and mint an administrator account.
 */
if (permissionValue('users', 'write') !== true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit;
}

try {
    $db = (new Database())->connect();
    $usersModel = new Users($db);

    $fullName = trim((string)($_POST['full_name'] ?? ''));
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $role     = (string)($_POST['role'] ?? '');
    $email    = trim((string)($_POST['email'] ?? ''));
    $memberId = trim((string)($_POST['member_id'] ?? ''));

    $roles = require __DIR__ . '/../config/roles.php';

    if ($fullName === '' || $username === '' || $password === '' || $role === '') {
        echo json_encode(['success' => false, 'message' => 'Please fill all required fields.']);
        exit;
    }

    if (!array_key_exists($role, $roles)) {
        echo json_encode(['success' => false, 'message' => 'Invalid role selected.']);
        exit;
    }

    /* Applicants self-register via Register as Member; admins must not
     * mint Applicant (or retired Committee / Board Member) accounts. */
    if (!in_array($role, ['SuperAdmin', 'Head Coach', 'Officer', 'Coach/Trainer', 'Member'], true)) {
        echo json_encode(['success' => false, 'message' => 'That role cannot be created here. Players register via the Register page.']);
        exit;
    }

    if (mb_strlen($fullName) > 100) {
        echo json_encode(['success' => false, 'message' => 'Full name must be 100 characters or fewer.']);
        exit;
    }

    if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
        echo json_encode(['success' => false, 'message' => 'Username may only contain letters, numbers, dot, underscore and hyphen (3-50 characters).']);
        exit;
    }

    if (strlen($password) < 6) {
        echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters.']);
        exit;
    }

    if ($email !== '') {
        if (mb_strlen($email) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'Enter a valid email address.']);
            exit;
        }
        if ($usersModel->getByEmail($email)) {
            echo json_encode(['success' => false, 'message' => 'That email address is already registered.']);
            exit;
        }
    }

    $memberIdValue = null;
    if ($memberId !== '' && $memberId !== '0') {
        if (!ctype_digit($memberId) || (int)$memberId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Linked member ID must be a positive whole number.']);
            exit;
        }
        $memberIdValue = (int)$memberId;

        $memberRoles = ['Head Coach', 'Coach/Trainer', 'Member'];
        if (!in_array($role, $memberRoles, true)) {
            echo json_encode(['success' => false, 'message' => 'A member record can only be linked to a Head Coach, Coach/Trainer, or Member account.']);
            exit;
        }

        $memberCheck = $db->prepare('SELECT member_id FROM members WHERE member_id = ? AND is_voided = 0');
        $memberCheck->execute([$memberIdValue]);
        if (!$memberCheck->fetchColumn()) {
            echo json_encode(['success' => false, 'message' => 'Linked member ID does not exist.']);
            exit;
        }

        /* One active login per member: refuse to attach a second active
         * account to the same member record (prevents duplicate accounts). */
        $dupCheck = $db->prepare("SELECT user_id FROM users WHERE member_id = ? AND is_active = 1 LIMIT 1");
        $dupCheck->execute([$memberIdValue]);
        if ($dupCheck->fetchColumn()) {
            echo json_encode(['success' => false, 'message' => 'That member already has an active login account. Change the linked member or deactivate the existing account first.']);
            exit;
        }
    }

    if ($usersModel->getByUsername($username)) {
        echo json_encode(['success' => false, 'message' => 'Username already exists.']);
        exit;
    }

    $data = [
        'full_name'     => $fullName,
        'username'      => $username,
        'password_hash' => $usersModel->hashPassword($password),
        'role'          => $role,
        'email'         => $email !== '' ? $email : null,
        'member_id'     => $memberIdValue,
        'is_active'     => 1,
    ];

    if ($usersModel->create($data)) {
        AuditLog::record(
            $db,
            'create',
            'users',
            (int)$db->lastInsertId() ?: null,
            'Created ' . $role . ' account "' . $username . '" for ' . $fullName
        );
        echo json_encode(['success' => true, 'message' => 'User created successfully.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to create user. Please try again.']);
    }
} catch (Throwable $e) {
    error_log('process_add_user error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An unexpected error occurred. Please try again.']);
}