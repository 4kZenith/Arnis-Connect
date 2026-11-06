<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/Members.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Content-Type: application/json; charset=UTF-8');

/** JSON responder: errors carry no database internals. */
function faceDescriptorError(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    faceDescriptorError(405, 'Method not allowed');
}

if (!isset($_POST['csrf_token']) || !arnisVerifyCsrfToken((string)$_POST['csrf_token'])) {
    faceDescriptorError(403, 'Invalid security token. Please reload the page and try again.');
}

// Explicitly block Applicants from face enrollment
$role = currentUserRole();
if ($role === 'Applicant') {
    faceDescriptorError(403, 'Access denied. Face enrollment is only available for approved members.');
}

$memberId = (isset($_POST['member_id']) && is_numeric($_POST['member_id'])) ? (int)$_POST['member_id'] : 0;
if ($memberId <= 0) {
    faceDescriptorError(400, 'Invalid member ID');
}

/*
 * Permission check: members.write
 * - SuperAdmin: full access (bypasses in canPerformAction)
 * - Officer: write=true (full)
 * - Coach/Trainer: write=own_trainees (can enroll their trainees)
 * - Member: write=own (can enroll self only)
 * - Applicant: write=own (can enroll self only, but typically no member_id linked yet)
 */
requirePermission('members', 'write');

try {
    $db = (new Database())->connect();
} catch (Throwable $e) {
    error_log('save_face_descriptor bootstrap DB failed: ' . $e->getMessage());
    faceDescriptorError(503, 'System temporarily unavailable. Please try again later.');
}

$writeScope = permissionValue('members', 'write');
if ($writeScope !== true) {
    /*
     * 'own' (Member/Applicant) must be enforced against the resolved member id
     * so accounts whose users.member_id is NULL but match a members.full_name
     * can still enroll their own face - matching member_profile.php.
     */
    if ($writeScope === 'own') {
        $selfMemberId = function_exists('resolveSessionMemberId') ? resolveSessionMemberId($db) : 0;
        if ($selfMemberId <= 0 || $selfMemberId !== $memberId) {
            faceDescriptorError(403, 'You can only enroll your own face.');
        }
    } elseif (!canPerformAction('members', 'write', ['member_id' => $memberId])) {
        faceDescriptorError(403, 'You do not have permission to update this member\'s face descriptor.');
    }
}

if (!isset($_POST['face_descriptor']) || !is_string($_POST['face_descriptor'])) {
    faceDescriptorError(400, 'Face descriptor is required.');
}

$descriptorJson = trim($_POST['face_descriptor']);
if ($descriptorJson === '') {
    faceDescriptorError(400, 'Face descriptor cannot be empty.');
}

/*
 * Validate descriptor: must be a JSON array of numbers (Float32Array serialized).
 * Typical length: 128 numbers for face-api.js face recognition model.
 */
$descriptorArray = json_decode($descriptorJson, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    faceDescriptorError(400, 'Face descriptor must be valid JSON.');
}
if (!is_array($descriptorArray)) {
    faceDescriptorError(400, 'Face descriptor must be a JSON array.');
}
if (empty($descriptorArray)) {
    faceDescriptorError(400, 'Face descriptor array cannot be empty.');
}

// Verify all elements are numbers
foreach ($descriptorArray as $value) {
    if (!is_numeric($value)) {
        faceDescriptorError(400, 'Face descriptor must contain only numeric values.');
    }
}

// Reasonable length check (face-api.js produces 128-dimension descriptors)
if (count($descriptorArray) < 64 || count($descriptorArray) > 256) {
    faceDescriptorError(400, 'Face descriptor length is invalid.');
}

// Re-encode to compact JSON for storage
$descriptorJsonCompact = json_encode($descriptorArray, JSON_UNESCAPED_UNICODE);
if ($descriptorJsonCompact === false) {
    faceDescriptorError(500, 'Failed to encode face descriptor.');
}

try {
    if (!isset($db) || !($db instanceof PDO)) {
        $db = (new Database())->connect();
    }
    $membersModel = new Members($db);

    $existing = $membersModel->getById($memberId);
    if (!$existing) {
        faceDescriptorError(404, 'Member not found');
    }

    // Update only the face_descriptor field
    $result = $membersModel->update($memberId, ['face_descriptor' => $descriptorJsonCompact], (int)$_SESSION['user_id']);

    if (!$result['success']) {
        faceDescriptorError(500, $result['error'] ?? 'Failed to save face descriptor.');
    }

    // Verify the save
    $verify = $membersModel->getById($memberId);
    if (!$verify || trim((string)($verify['face_descriptor'] ?? '')) !== $descriptorJsonCompact) {
        faceDescriptorError(500, 'Failed to verify face descriptor save.');
    }

    echo json_encode([
        'success' => true,
        'message' => 'Face descriptor saved successfully.',
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    error_log('save_face_descriptor DB error: ' . $e->getMessage());
    faceDescriptorError(500, 'Failed to save face descriptor.');
} catch (Throwable $e) {
    error_log('save_face_descriptor error: ' . $e->getMessage());
    faceDescriptorError(500, 'Failed to save face descriptor.');
}