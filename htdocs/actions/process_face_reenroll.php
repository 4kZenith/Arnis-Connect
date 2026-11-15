<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/AuditLog.php';
require_once __DIR__ . '/../classes/FaceEnrollments.php';
require_once __DIR__ . '/../classes/Mailer.php';

function faceRequestRedirect(string $type, string $message, int $memberId): void
{
    $_SESSION['face_reenroll_flash'] = ['type' => $type, 'message' => $message];
    $back = (string)($_POST['return_to'] ?? '');
    if ($back === 'members') {
        header('Location: ../public/members_management.php');
    } elseif ($memberId > 0) {
        header('Location: ../public/member_profile.php?id=' . $memberId);
    } else {
        header('Location: ../public/dashboard.php');
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Invalid request method.');
}

if (!arnisVerifyCsrfToken(isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : null)) {
    faceRequestRedirect('error', 'Invalid security token. Refresh the page and try again.', 0);
}

$op = (string)($_POST['op'] ?? '');
$requestId = (isset($_POST['request_id']) && is_numeric($_POST['request_id'])) ? (int)$_POST['request_id'] : 0;
if ($requestId <= 0) {
    faceRequestRedirect('error', 'That face request could not be found.', 0);
}

try {
    $db = (new Database())->connect();
    $faces = new FaceEnrollments($db);
    $row = $faces->find($requestId);
    if ($row === null) {
        faceRequestRedirect('error', 'That face request could not be found.', 0);
    }
    $memberId = (int)$row['member_id'];
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $selfMemberId = function_exists('resolveSessionMemberId') ? resolveSessionMemberId($db) : 0;

    if ($op === 'cancel') {
        if ($selfMemberId <= 0 || $selfMemberId !== $memberId) {
            faceRequestRedirect('error', 'You can only cancel your own face request.', $memberId);
        }
        $faces->cancel($requestId, $memberId);
        AuditLog::record($db, 'update', 'members', $memberId, 'Cancelled a face change request. The face on file was not changed.');
        faceRequestRedirect('success', 'The face change request was cancelled. Attendance still uses your current face.', $memberId);
    }

    if (!FaceEnrollments::canReview()) {
        faceRequestRedirect('error', 'Only a SuperAdmin or Head Coach can review a face change.', $memberId);
    }
    if ($selfMemberId > 0 && $selfMemberId === $memberId) {
        faceRequestRedirect('error', 'Another admin has to review your own face change.', $memberId);
    }

    if ($op === 'approve') {
        $faces->approve($requestId, $userId);
        AuditLog::record(
            $db,
            'update',
            'members',
            $memberId,
            'Approved a face change for ' . (string)$row['member_name'] . '. Attendance now uses the new face.'
        );
        try {
            (new Mailer($db))->notifyMember(
                $memberId,
                'Face change approved',
                'Your new face photo was approved. Attendance now uses that face.'
            );
        } catch (Throwable $mailError) {
            error_log('face approval email skipped: ' . $mailError->getMessage());
        }
        faceRequestRedirect('success', 'Approved. Attendance for ' . (string)$row['member_name'] . ' now uses the new face.', $memberId);
    }

    if ($op === 'decline') {
        $faces->decline($requestId, $userId, (string)($_POST['review_note'] ?? ''));
        AuditLog::record(
            $db,
            'update',
            'members',
            $memberId,
            'Declined a face change for ' . (string)$row['member_name'] . '. Attendance still uses the previous face.'
        );
        $declineNote = trim((string)($_POST['review_note'] ?? ''));
        try {
            (new Mailer($db))->notifyMember(
                $memberId,
                'Face change declined',
                'Your face change was declined' . ($declineNote !== '' ? '. Reason: ' . $declineNote : '')
                . '. Attendance still uses your current face.'
            );
        } catch (Throwable $mailError) {
            error_log('face decline email skipped: ' . $mailError->getMessage());
        }
        faceRequestRedirect('success', 'Declined. Attendance for ' . (string)$row['member_name'] . ' still uses the previous face.', $memberId);
    }

    faceRequestRedirect('error', 'That action is not available.', $memberId);
} catch (RuntimeException $e) {
    $memberId = isset($memberId) ? $memberId : 0;
    faceRequestRedirect('error', $e->getMessage(), $memberId);
} catch (Throwable $e) {
    error_log('process_face_reenroll error: ' . $e->getMessage());
    faceRequestRedirect('error', 'The face request could not be updated. Please try again.', isset($memberId) ? $memberId : 0);
}
