<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/AuditLog.php';
require_once __DIR__ . '/../classes/FaceEnrollments.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Content-Type: application/json; charset=UTF-8');

function faceDescriptorError(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function faceStoreSnapshot(int $memberId, bool $request): string
{
    if (!isset($_FILES['face_photo']) || !is_array($_FILES['face_photo'])) {
        throw new RuntimeException('A photo of this face is required.');
    }
    $file = $_FILES['face_photo'];
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        throw new RuntimeException('That photo is too large. Use a picture under 5 MB.');
    }
    if ($error !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || $file['tmp_name'] === '') {
        throw new RuntimeException('A photo of this face is required.');
    }
    if ((int)$file['size'] <= 0 || (int)$file['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('That photo is too large. Use a picture under 5 MB.');
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : false;
    if ($finfo) {
        finfo_close($finfo);
    }
    $exts = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!is_string($mime) || !isset($exts[$mime])) {
        throw new RuntimeException('The face photo must be a JPG, PNG, or WEBP image.');
    }
    $info = @getimagesize($file['tmp_name']);
    if ($info === false || (int)($info[0] ?? 0) < 1 || (int)($info[1] ?? 0) < 1) {
        throw new RuntimeException('That file is not a readable photo.');
    }

    $folder = $request ? 'requests' : '';
    $dir = FaceEnrollments::directory() . ($folder !== '' ? DIRECTORY_SEPARATOR . $folder : '');
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('The face photo could not be saved. Please try again.');
    }
    $filename = ($request ? 'request_' : 'official_')
        . $memberId . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $exts[$mime];
    $dest = $dir . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new RuntimeException('The face photo could not be saved. Please try again.');
    }
    return 'uploads/faces/' . ($folder !== '' ? $folder . '/' : '') . $filename;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    faceDescriptorError(405, 'Method not allowed');
}

if (!isset($_POST['csrf_token']) || !arnisVerifyCsrfToken((string)$_POST['csrf_token'])) {
    faceDescriptorError(403, 'Invalid security token. Please reload the page and try again.');
}

if (currentUserRole() === 'Applicant') {
    faceDescriptorError(403, 'Face enrollment is only available for approved members.');
}

$memberId = (isset($_POST['member_id']) && is_numeric($_POST['member_id'])) ? (int)$_POST['member_id'] : 0;
if ($memberId <= 0) {
    faceDescriptorError(400, 'Invalid member ID');
}

if (!isset($_POST['face_descriptor']) || !is_string($_POST['face_descriptor'])) {
    faceDescriptorError(400, 'Face descriptor is required.');
}
$descriptor = FaceEnrollments::parseDescriptor($_POST['face_descriptor']);
if ($descriptor === null) {
    faceDescriptorError(400, 'Face descriptor must be the 128 numbers from the camera capture.');
}
$descriptorJson = json_encode($descriptor);
if ($descriptorJson === false) {
    faceDescriptorError(500, 'Failed to encode face descriptor.');
}

try {
    $db = (new Database())->connect();
    $selfMemberId = function_exists('resolveSessionMemberId') ? resolveSessionMemberId($db) : 0;
    if ($selfMemberId <= 0 || $selfMemberId !== $memberId) {
        faceDescriptorError(403, 'You can only enroll your own face.');
    }

    $faces = new FaceEnrollments($db);
    $existing = $db->prepare('SELECT face_descriptor FROM members WHERE member_id = :id AND is_voided = 0 LIMIT 1');
    $existing->execute([':id' => $memberId]);
    $member = $existing->fetch(PDO::FETCH_ASSOC);
    if (!$member) {
        faceDescriptorError(404, 'Member not found');
    }

    $alreadyEnrolled = trim((string)($member['face_descriptor'] ?? '')) !== '';
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $photoPath = faceStoreSnapshot($memberId, $alreadyEnrolled);

    if (!$alreadyEnrolled) {
        try {
            $faces->saveFirst($memberId, $userId, $descriptorJson, $photoPath);
        } catch (Throwable $e) {
            FaceEnrollments::unlinkStored($photoPath);
            throw $e;
        }
        AuditLog::record($db, 'create', 'members', $memberId, 'Enrolled a face for attendance');
        echo json_encode([
            'success' => true,
            'mode' => 'enrolled',
            'message' => 'Your face is enrolled. Attendance will use this face. A later change needs admin approval.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $faces->submitRequest($memberId, $userId, $descriptorJson, $photoPath);
    } catch (Throwable $e) {
        FaceEnrollments::unlinkStored($photoPath);
        throw $e;
    }
    AuditLog::record(
        $db,
        'update',
        'members',
        $memberId,
        'Requested a face change. Attendance still uses the face already on file.'
    );
    echo json_encode([
        'success' => true,
        'mode' => 'requested',
        'message' => 'Request sent. Your current face stays on attendance until an admin compares the new photo and approves it.',
    ], JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $e) {
    faceDescriptorError(400, $e->getMessage());
} catch (Throwable $e) {
    error_log('save_face_descriptor error: ' . $e->getMessage());
    faceDescriptorError(500, 'The face could not be saved. Please try again.');
}
