<?php
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
    $dob = trim((string)($_POST['dob'] ?? ''));
    $contactNumber = trim((string)($_POST['contact_number'] ?? ''));
    $playerCategory = (string)($_POST['player_category'] ?? 'Public');

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

    $memberRoles = ['Head Coach' => 'Head Coach', 'Coach/Trainer' => 'Coach', 'Member' => 'Player'];
    $memberIdValue = null;
    $createProfile = isset($memberRoles[$role]) && ($memberId === '' || $memberId === '0');
    if ($createProfile) {
        $dobObj = DateTime::createFromFormat('Y-m-d', $dob);
        if ($dob === '' || !$dobObj || $dobObj->format('Y-m-d') !== $dob || $dob > date('Y-m-d')) {
            echo json_encode(['success' => false, 'message' => 'Enter a valid date of birth for the member profile.']);
            exit;
        }
        if ($contactNumber === '' || mb_strlen($contactNumber) > 20) {
            echo json_encode(['success' => false, 'message' => 'Enter a contact number (up to 20 characters) for the member profile.']);
            exit;
        }
        if ($role !== 'Member' || !in_array($playerCategory, ['Public', 'Private', 'Mixed'], true)) {
            $playerCategory = 'Public';
        }
    }
    if ($memberId !== '' && $memberId !== '0') {
        if (!ctype_digit($memberId) || (int)$memberId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Linked member ID must be a positive whole number.']);
            exit;
        }
        $memberIdValue = (int)$memberId;

        if (!isset($memberRoles[$role])) {
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

    $db->beginTransaction();
    try {
        if ($createProfile) {
            $data['member_id'] = (new Members($db))->createProfileForAccount([
                'full_name' => $fullName,
                'dob' => $dob,
                'email' => $email !== '' ? $email : null,
                'contact_number' => $contactNumber,
                'member_type' => $memberRoles[$role],
                'player_category' => $playerCategory,
                'training_preference' => $playerCategory === 'Private' ? 'Private' : 'Group',
                'status' => 'Active',
                'rank' => 'None',
            ], (int)($_SESSION['user_id'] ?? 0));
        }
        if (!$usersModel->create($data)) {
            throw new RuntimeException('User insert failed.');
        }
        $newUserId = (int)$db->lastInsertId();
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('process_add_user create error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to create user. Please try again.']);
        exit;
    }

    AuditLog::record(
        $db,
        'create',
        'users',
        $newUserId ?: null,
        'Created ' . $role . ' account "' . $username . '" for ' . $fullName
            . ($createProfile ? ' with member profile #' . (int)$data['member_id'] : '')
    );
    echo json_encode([
        'success' => true,
        'message' => $createProfile ? 'User and member profile created successfully.' : 'User created successfully.',
    ]);
} catch (Throwable $e) {
    error_log('process_add_user error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An unexpected error occurred. Please try again.']);
}