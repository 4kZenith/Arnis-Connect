<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/Members.php';
require_once __DIR__ . '/../classes/AuditLog.php';
require_once __DIR__ . '/../classes/Mailer.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

/*
 * Only the SuperAdmin may approve or reject registration requests. The old
 * check allowed any unscoped member writer, which included the Finance
 * Officer; the requirement is SuperAdmin-only.
 */
if (!hasRole('SuperAdmin')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit;
}

arnisRequireCsrf(true);

$requestId = isset($_POST['request_id']) && is_numeric($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
$decision = strtolower(trim((string)($_POST['decision'] ?? '')));
$reviewNotes = trim((string)($_POST['review_notes'] ?? ''));

if ($requestId <= 0 || !in_array($decision, ['approve', 'reject'], true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid review request.']);
    exit;
}

if ($reviewNotes !== '' && mb_strlen($reviewNotes) > 255) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Review notes must not exceed 255 characters.']);
    exit;
}

try {
    $db = (new Database())->connect();

    /* Read the request before review so the audit entry and email still have
     * the applicant's name/email after the status changes. */
    $requestStmt = $db->prepare(
        'SELECT request_id, full_name, email, username, user_id, requested_rank
         FROM registration_requests
         WHERE request_id = :id
         LIMIT 1'
    );
    $requestStmt->execute([':id' => $requestId]);
    $request = $requestStmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($request)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Registration request not found.']);
        exit;
    }

    $model = new Members($db);
    $result = $model->reviewRegistrationRequest(
        $requestId,
        $decision,
        (int)$_SESSION['user_id'],
        $reviewNotes !== '' ? $reviewNotes : null
    );

    if (!($result['success'] ?? false)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $result['error'] ?? 'Failed to review request.']);
        exit;
    }

    $applicantName = trim((string)($request['full_name'] ?? 'Applicant'));
    $emailNote = '';

    AuditLog::record(
        $db,
        $decision === 'approve' ? 'approve' : 'reject',
        'registration',
        $requestId,
        ($decision === 'approve' ? 'Approved' : 'Rejected') . ' registration request for '
            . $applicantName . ($reviewNotes !== '' ? '. Notes: ' . $reviewNotes : ''),
        [
            'decision' => $decision,
            'username' => (string)($request['username'] ?? ''),
            'notes' => $reviewNotes,
        ]
    );

    /* Tell the applicant the outcome. Best effort: mail failures never undo an
     * approval or rejection that already committed. */
    $email = trim((string)($request['email'] ?? ''));
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        try {
            $mailer = new Mailer($db);
            if ($mailer->notificationsEnabled()) {
                $safeName = htmlspecialchars($applicantName, ENT_QUOTES, 'UTF-8');
                $safeNotes = $reviewNotes !== ''
                    ? '<p><strong>Reviewer notes:</strong> ' . nl2br(htmlspecialchars($reviewNotes, ENT_QUOTES, 'UTF-8')) . '</p>'
                    : '';

                if ($decision === 'approve') {
                    $subject = 'Your Arnis Connect membership was approved';
                    $body = '<p>Hello ' . $safeName . ',</p>'
                        . '<p>Your membership application has been <strong>approved</strong>. '
                        . 'You can now sign in with the username and password you registered with.</p>'
                        . $safeNotes
                        . '<p>Welcome to the club.</p>';
                } else {
                    $subject = 'Your Arnis Connect membership application';
                    $body = '<p>Hello ' . $safeName . ',</p>'
                        . '<p>We are sorry, but your membership application was <strong>not approved</strong> at this time. '
                        . 'Please contact the club administrator if you would like to discuss the decision.</p>'
                        . $safeNotes;
                }

                if (!$mailer->send($email, $subject, $body)) {
                    $emailNote = ' The decision email could not be sent.';
                }
            } else {
                $emailNote = ' Email notifications are disabled.';
            }
        } catch (Throwable $e) {
            error_log('Registration review email failed: ' . $e->getMessage());
            $emailNote = ' The decision email could not be sent.';
        }
    }

    echo json_encode([
        'success' => true,
        'message' => (string)$result['message'] . $emailNote,
    ]);
} catch (PDOException $e) {
    error_log('process_registration_review DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
} catch (Throwable $e) {
    error_log('process_registration_review error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to review request.']);
}
