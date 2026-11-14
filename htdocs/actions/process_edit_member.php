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

requirePermission('members', 'write');

arnisRequireCsrf(true);

$memberId = isset($_POST['member_id']) && is_numeric($_POST['member_id']) ? (int)$_POST['member_id'] : 0;
if ($memberId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid member ID.']);
    exit;
}

$validRanks = Members::RANK_ORDER;
/* Canonical THREE member positions. Legacy Trainer/Athlete values are
 * normalized (Trainer->Coach, Athlete->Player) for backward compatibility. */
$validCategories = ['Public', 'Private', 'Mixed'];
$validPreferences = ['Group', 'Private', 'Flexible'];
$validStatuses = ['Active', 'Inactive', 'On Leave'];

$fullName = trim((string)($_POST['full_name'] ?? ''));
$dob = trim((string)($_POST['dob'] ?? ''));
$contactNumber = trim((string)($_POST['contact_number'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$address = trim((string)($_POST['address'] ?? ''));
$heightRaw = $_POST['height'] ?? null;
$weightRaw = $_POST['weight'] ?? null;
$playerCategory = isset($_POST['player_category']) ? trim((string)$_POST['player_category']) : null;
$trainingPreference = isset($_POST['training_preference']) ? trim((string)$_POST['training_preference']) : null;
$affiliatedSchool = trim((string)($_POST['affiliated_school'] ?? ''));
$guardianName = trim((string)($_POST['guardian_name'] ?? ''));
$guardianContact = trim((string)($_POST['guardian_contact'] ?? ''));
    $cloudinaryUrl = trim((string)($_POST['cloudinary_url'] ?? ''));

    $sessionMemberId = isset($_SESSION['member_id']) && $_SESSION['member_id'] !== null
        ? (int)$_SESSION['member_id']
        : 0;
    $isSelf = $sessionMemberId > 0 && $sessionMemberId === $memberId;

    // Members editing themselves submit disabled read-only fields as empty;
    // fall back to the stored full name / DOB instead of rejecting.
    if (!$isSelf && ($fullName === '' || $dob === '')) {
        echo json_encode(['success' => false, 'message' => 'Full name and date of birth are required.']);
        exit;
    }

// Only validate DOB if it was actually posted (members self-editing have it disabled;
// the stored value will be applied after the DB fetch in the fallback block below).
if ($dob !== '') {
    $dobObj = DateTime::createFromFormat('Y-m-d', $dob);
    if (!$dobObj || $dobObj->format('Y-m-d') !== $dob || $dob > date('Y-m-d')) {
        echo json_encode(['success' => false, 'message' => 'Invalid date of birth.']);
        exit;
    }
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Invalid email format.']);
    exit;
}

if ($playerCategory !== null && !in_array($playerCategory, $validCategories, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid player category selected.']);
    exit;
}
if ($trainingPreference !== null && !in_array($trainingPreference, $validPreferences, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid training preference selected.']);
    exit;
}

$height = null;
if ($heightRaw !== null && $heightRaw !== '') {
    if (!is_numeric($heightRaw) || (float)$heightRaw <= 0 || (float)$heightRaw > 300) {
        echo json_encode(['success' => false, 'message' => 'Height must be a number between 1 and 300 cm.']);
        exit;
    }
    $height = (float)$heightRaw;
}

$weight = null;
if ($weightRaw !== null && $weightRaw !== '') {
    if (!is_numeric($weightRaw) || (float)$weightRaw <= 0 || (float)$weightRaw > 1000) {
        echo json_encode(['success' => false, 'message' => 'Weight must be a number between 1 and 1000 kg.']);
        exit;
    }
    $weight = (float)$weightRaw;
}

try {
    $db = (new Database())->connect();
    $membersModel = new Members($db);

    // Verify member exists and is not voided
    $existing = $membersModel->getById($memberId);
    if (!$existing) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Member not found.']);
        exit;
    }
    // Fields a form leaves out (disabled inputs are not submitted) keep their stored value.
    $playerCategory = $playerCategory ?? (string)($existing['player_category'] ?? 'Public');
    $trainingPreference = $trainingPreference ?? (string)($existing['training_preference'] ?? 'Group');

    $profileImg = $existing['profile_img'] ?? null;
    if ($cloudinaryUrl !== '') {
        $imgParts = parse_url($cloudinaryUrl);
        $imgHost = strtolower((string)($imgParts['host'] ?? ''));
        if (strtolower((string)($imgParts['scheme'] ?? '')) !== 'https'
            || ($imgHost !== 'res.cloudinary.com' && !str_ends_with($imgHost, '.res.cloudinary.com'))
            || strlen($cloudinaryUrl) > 255) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Only Cloudinary image URLs are accepted for the profile photo.']);
            exit;
        }
        $profileImg = $cloudinaryUrl;
    }

    $writeScope = permissionValue('members', 'write');
    $sessionMemberId = isset($_SESSION['member_id']) && $_SESSION['member_id'] !== null
        ? (int)$_SESSION['member_id']
        : 0;
    $isSelf = $sessionMemberId > 0 && $sessionMemberId === $memberId;
    if ($writeScope !== true) {
        if (!canPerformAction('members', 'write', ['member_id' => $memberId])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'You do not have permission to edit this member.']);
            exit;
        }
    }

    /*
     * PRIVILEGE BOUNDARY.
     *
     * A member may edit their own contact/profile details, but rank, status,
     * position and payment notes are staff-controlled:
     *   - Members self-editing: all four fields are frozen.
     *   - Coach / Head Coach editing a trainee: rank and payment notes are
     *     frozen; rank moves only through promote_member.php (next rank only).
     *   - SuperAdmin: may correct any field (an explicit admin override).
     *
     * Fields absent from the POST (because the UI disabled them) fall back to
     * the stored value instead of the old hard-coded defaults, so saving a
     * profile can never silently reset rank/status/position/payment notes.
     */
    $currentRank = (string)($existing['rank'] ?? 'None');
    $currentStatus = (string)($existing['status'] ?? 'Active');
    $currentType = Members::normalizePosition((string)($existing['member_type'] ?? 'Player'));
    $currentPaymentNotes = (string)($existing['payment_notes'] ?? '');

    $rank = isset($_POST['rank']) ? trim((string)$_POST['rank']) : $currentRank;
    $status = isset($_POST['status']) ? trim((string)$_POST['status']) : $currentStatus;
    $memberTypeRaw = isset($_POST['member_type']) ? (string)$_POST['member_type'] : $currentType;
    $memberType = Members::normalizePosition($memberTypeRaw);
    $paymentNotes = isset($_POST['payment_notes']) ? trim((string)$_POST['payment_notes']) : $currentPaymentNotes;

    if (!in_array($rank, $validRanks, true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid rank selected.']);
        exit;
    }
    if (!in_array($status, $validStatuses, true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid status selected.']);
        exit;
    }
    if (!in_array($memberTypeRaw, ['Head Coach', 'Coach', 'Player'], true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid member position selected.']);
        exit;
    }

    // Members editing themselves: full name, DOB and player category are read-only (ignored)
    if ($isSelf && $writeScope === 'own') {
        $fullName = (string)($existing['full_name'] ?? $fullName);
        $dob = (string)($existing['dob'] ?? $dob);
        $playerCategory = (string)($existing['player_category'] ?? $playerCategory);
    }

    $canManagePrivileged = (!$isSelf && $writeScope === true);
    $canManageStatus = (!$isSelf && in_array($writeScope, [true, 'own_trainees'], true));

    if (!$canManagePrivileged && $rank !== $currentRank) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Rank cannot be edited here. Use the next-rank promotion action.',
        ]);
        exit;
    }
    if (!$canManagePrivileged && $memberType !== $currentType) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Only the SuperAdmin can change a member\'s position.']);
        exit;
    }
    if (!$canManagePrivileged && $paymentNotes !== $currentPaymentNotes) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Only the SuperAdmin can change payment notes.']);
        exit;
    }
    $currentPlayerCategory = (string)($existing['player_category'] ?? 'Public');
    if (!$canManagePrivileged && $playerCategory !== $currentPlayerCategory) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Player category cannot be changed here.']);
        exit;
    }
    // Training preference is editable by members (self) and staff; no extra restriction needed.
    if (!$canManageStatus && $status !== $currentStatus) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'You cannot change your own or another member\'s status.']);
        exit;
    }

    // Only an admin correction may adjust payment notes; otherwise preserve.
    if ($canManagePrivileged) {
        if ($playerCategory === 'Public' && $paymentNotes === '') {
            $paymentNotes = 'Public player: free / school-based training.';
        } elseif ($playerCategory === 'Private' && $paymentNotes === '') {
            $paymentNotes = 'Private player: paid training arrangement.';
        } elseif ($playerCategory === 'Mixed' && $paymentNotes === '') {
            $paymentNotes = 'Mixed player: joins both public and private sessions.';
        }
    } else {
        $paymentNotes = $currentPaymentNotes;
    }

    $data = [
        'full_name' => $fullName,
        'dob' => $dob,
        'email' => $email !== '' ? $email : null,
        'contact_number' => $contactNumber !== '' ? $contactNumber : null,
        'address' => $address !== '' ? $address : null,
        'rank' => $canManagePrivileged ? $rank : $currentRank,
        'status' => $canManageStatus ? $status : $currentStatus,
        'height' => $height,
        'weight' => $weight,
        'member_type' => $canManagePrivileged ? $memberType : $currentType,
        'player_category' => $playerCategory,
        'training_preference' => $trainingPreference,
        'affiliated_school' => $affiliatedSchool !== '' ? $affiliatedSchool : null,
        'guardian_name' => $guardianName !== '' ? $guardianName : null,
        'guardian_contact' => $guardianContact !== '' ? $guardianContact : null,
        'payment_notes' => $paymentNotes !== '' ? $paymentNotes : null,
        'profile_img' => $profileImg,
    ];

    $result = $membersModel->update($memberId, $data, (int)$_SESSION['user_id']);

    if ($result['success'] ?? false) {
        /* Keep the linked login's role consistent with the member position
         * (Head Coach->Head Coach, Coach->Coach, Player->Member). Only an
         * admin reaches this point with a position change. */
        if ($canManagePrivileged) {
            $syncRole = Members::roleForPosition($memberType);
            $syncStmt = $db->prepare(
                'UPDATE users SET role = :role, session_version = session_version + 1
                 WHERE member_id = :mid AND role <> :role'
            );
            $syncStmt->execute([':role' => $syncRole, ':mid' => $memberId]);
        }

        /* Belt progression: an admin correction is journaled with who did it. */
        $oldRank = (string)($existing['rank'] ?? 'None');
        if ($oldRank !== $rank) {
            $membersModel->logRankChange(
                $memberId,
                $oldRank !== '' ? $oldRank : null,
                $rank,
                (int)($_SESSION['user_id'] ?? 0),
                'Administrative rank correction'
            );
        }

        AuditLog::record(
            $db,
            'update',
            'members',
            $memberId,
            'Updated member profile for ' . $fullName
        );

        echo json_encode(['success' => true, 'message' => 'Member updated successfully.']);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $result['error'] ?? 'Failed to update member.']);
    }
} catch (Throwable $e) {
    error_log('process_edit_member failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to update member. Please try again.']);
}
