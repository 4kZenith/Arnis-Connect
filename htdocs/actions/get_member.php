<?php
declare(strict_types=1);

require_once "../includes/authorize.php";
require_once "../config/Database.php";
require_once "../classes/Members.php";

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid member ID']);
    exit;
}

$memberId = (int) $_GET['id'];
if ($memberId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid member ID']);
    exit;
}

/*
 * This endpoint returns PII (email, contact, address, guardian, payment
 * notes). It previously had no authorization at all, so any signed-in Member
 * could enumerate /actions/get_member.php?id=1..N.
 *
 * Allowed:
 *   - the member reading their own record (session member_id match)
 *   - a full member reader (SuperAdmin / Head Coach)
 *   - a coach whose trainee this is (own_trainees resolved per record)
 */
$sessionMemberId = isset($_SESSION['member_id']) && $_SESSION['member_id'] !== null
    ? (int)$_SESSION['member_id']
    : 0;
$isSelf = $sessionMemberId > 0 && $sessionMemberId === $memberId;
$readScope = permissionValue('members', 'read');

$allowed = $isSelf || $readScope === true;
if (!$allowed && $readScope === 'own_trainees') {
    $allowed = canPerformAction('members', 'read', ['member_id' => $memberId]);
}
if (!$allowed) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied.']);
    exit;
}

$db = (new Database())->connect();
$membersModel = new Members($db);
$member = $membersModel->getById($memberId);

if (!$member) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Member not found']);
    exit;
}

echo json_encode([
    'success' => true,
    'data' => [
        'member_id' => $member['member_id'],
        'full_name' => $member['full_name'],
        'dob' => $member['dob'],
        'email' => $member['email'] ?? '',
        'rank' => $member['rank'],
        'member_type' => $member['member_type'],
        'player_category' => $member['player_category'] ?? 'Public',
        'training_preference' => $member['training_preference'] ?? 'Group',
        'contact_number' => $member['contact_number'],
        'address' => $member['address'],
        'height' => $member['height'],
        'weight' => $member['weight'],
        'status' => $member['status'],
        'affiliated_school' => $member['affiliated_school'] ?? '',
        'guardian_name' => $member['guardian_name'] ?? '',
        'guardian_contact' => $member['guardian_contact'] ?? '',
        'payment_notes' => $member['payment_notes'] ?? '',
        'profile_img' => $member['profile_img']
    ]
]);
