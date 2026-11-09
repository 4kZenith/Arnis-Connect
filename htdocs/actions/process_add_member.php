<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/Members.php';
require_once __DIR__ . '/../classes/AuditLog.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

arnisRequireCsrf(true);

$currentRole = (string)($_SESSION['role'] ?? '');

/*
 * Creating a member also mints a login account. Only users with full
 * members.write permission may add members.
 */
if (permissionValue('members', 'write') !== true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit;
}

if ($currentRole === 'Member' || $currentRole === 'Applicant') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit;
}

$full_name = trim((string)($_POST['full_name'] ?? ''));
$dob = trim((string)($_POST['dob'] ?? ''));
$contact_number = trim((string)($_POST['contact_number'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$address = trim((string)($_POST['address'] ?? ''));
$rank = trim((string)($_POST['rank'] ?? 'None'));
$status = trim((string)($_POST['status'] ?? 'Active'));
$height = $_POST['height'] ?? null;
$weight = $_POST['weight'] ?? null;
$member_type = trim((string)($_POST['member_type'] ?? 'Player'));
$player_category = trim((string)($_POST['player_category'] ?? 'Public'));
$training_preference = trim((string)($_POST['training_preference'] ?? 'Group'));
$affiliated_school = trim((string)($_POST['affiliated_school'] ?? ''));
$guardian_name = trim((string)($_POST['guardian_name'] ?? ''));
$guardian_contact = trim((string)($_POST['guardian_contact'] ?? ''));
$payment_notes = trim((string)($_POST['payment_notes'] ?? ''));
$cloudinary_url = trim((string)($_POST['cloudinary_url'] ?? ''));
$username = trim((string)($_POST['username'] ?? ''));
$password = (string)($_POST['password'] ?? '');

if ($full_name === '' || $dob === '') {
    echo json_encode(['success' => false, 'message' => 'Full name and date of birth are required.']);
    exit;
}

if (!strtotime($dob)) {
    echo json_encode(['success' => false, 'message' => 'Invalid date of birth.']);
    exit;
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Invalid email format.']);
    exit;
}

$allowedMemberTypes = ['Player', 'Athlete', 'Trainer', 'Coach', 'Head Coach'];
if (!in_array($member_type, $allowedMemberTypes, true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid member position.']);
    exit;
}

$currentRole = (string)($_SESSION['role'] ?? '');

// Only SuperAdmin may create Head Coach / Coach accounts
if (in_array($member_type, ['Head Coach', 'Coach'], true) && $currentRole !== 'SuperAdmin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Only the SuperAdmin may create Head Coach or Coach accounts.']);
    exit;
}

// Officer may create Members only (Player/Athlete are considered members)
if ($currentRole === 'Officer' && !in_array($member_type, ['Player', 'Athlete'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Officer may create Members only.']);
    exit;
}

if (!in_array($status, ['Active', 'On Leave', 'Inactive'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid member status.']);
    exit;
}

if (!in_array($player_category, ['Public', 'Private', 'Mixed'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid player category.']);
    exit;
}

if (!in_array($training_preference, ['Group', 'Private', 'Flexible'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid training preference.']);
    exit;
}

/* Login account for the member. The username is optional (defaults to
 * member.<id>), but the password is REQUIRED: new accounts must never
 * silently receive the shared rebuild password (default123). */
if ($username !== '' && !preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
    echo json_encode(['success' => false, 'message' => 'Username may only contain letters, numbers, dot, underscore and hyphen (3-50 characters).']);
    exit;
}

if ($password === '') {
    echo json_encode(['success' => false, 'message' => 'A login password is required for the member account.']);
    exit;
}

if (strlen($password) < 6) {
    echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters.']);
    exit;
}

if ($player_category === 'Public' && $payment_notes === '') {
    $payment_notes = 'No training fee - public/school-based player.';
} elseif ($player_category === 'Private' && $payment_notes === '') {
    $payment_notes = 'Paid/private training arrangement.';
}

$db = (new Database())->connect();
$membersModel = new Members($db);

$data = [
    'full_name' => $full_name,
    'dob' => $dob,
    'email' => $email ?: null,
    'contact_number' => $contact_number ?: null,
    'address' => $address ?: null,
    'rank' => $rank,
    'status' => $status,
    'height' => $height,
    'weight' => $weight,
    'member_type' => $member_type,
    'player_category' => $player_category,
    'training_preference' => $training_preference,
    'affiliated_school' => $affiliated_school ?: null,
    'guardian_name' => $guardian_name ?: null,
    'guardian_contact' => $guardian_contact ?: null,
    'payment_notes' => $payment_notes ?: null,
    'cloudinary_url' => $cloudinary_url ?: null,
    'username' => $username,
    'password' => $password,
];

$result = $membersModel->create($data, (int)$_SESSION['user_id']);

if ($result['success'] ?? false) {
    AuditLog::record(
        $db,
        'create',
        'members',
        (int)($result['member_id'] ?? 0) ?: null,
        'Created member ' . $full_name . ' (' . $member_type . ')'
    );
    echo json_encode(['success' => true, 'message' => 'Member added successfully with a linked login account.']);
} else {
    echo json_encode(['success' => false, 'message' => $result['error'] ?? 'Failed to add member.']);
}
 