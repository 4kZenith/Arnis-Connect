<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/AuditLog.php';
require_once __DIR__ . '/../classes/Mailer.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

function announcementRedirect(string $type, string $message): void
{
    $_SESSION[$type === 'success' ? 'success' : 'error'] = $message;
    header('Location: ../public/announcements.php');
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    announcementRedirect('error', 'Method not allowed.');
}

arnisRequireCsrf();

if (permissionValue('announcements', 'write') !== true) {
    http_response_code(403);
    exit('Access Denied: You do not have permission to manage announcements.');
}

$action = isset($_POST['action']) && is_string($_POST['action']) ? strtolower(trim($_POST['action'])) : 'create';

try {
    $db = (new Database())->connect();

    if ($action === 'archive') {
        if (permissionValue('announcements', 'delete') !== true) {
            announcementRedirect('error', 'Only the SuperAdmin may archive announcements.');
        }

        $announcementId = isset($_POST['announcement_id']) && is_numeric($_POST['announcement_id'])
            ? (int)$_POST['announcement_id'] : 0;
        if ($announcementId <= 0) {
            announcementRedirect('error', 'Invalid announcement.');
        }

        $stmt = $db->prepare('UPDATE announcements SET is_archived = 1 WHERE announcement_id = :id AND is_archived = 0');
        $stmt->execute([':id' => $announcementId]);
        if ($stmt->rowCount() === 0) {
            announcementRedirect('error', 'That announcement was not found or is already archived.');
        }

        AuditLog::record(
            $db,
            'announcement_archive',
            'announcements',
            $announcementId,
            'Archived announcement #' . $announcementId
        );
        announcementRedirect('success', 'Announcement archived.');
    }

    $title = trim((string)($_POST['title'] ?? ''));
    $body = trim((string)($_POST['body'] ?? ''));
    $audience = (string)($_POST['audience'] ?? 'All');
    $sendEmail = isset($_POST['send_email']) && (int)$_POST['send_email'] === 1;
    $allowedAudiences = ['All', 'Members', 'Coaches', 'Officers'];

    if ($title === '' || mb_strlen($title) > 150) {
        announcementRedirect('error', 'Title is required and must be 150 characters or fewer.');
    }
    if ($body === '' || mb_strlen($body) > 5000) {
        announcementRedirect('error', 'Message is required and must be 5,000 characters or fewer.');
    }
    if (!in_array($audience, $allowedAudiences, true)) {
        announcementRedirect('error', 'Invalid audience.');
    }

    $stmt = $db->prepare(
        'INSERT INTO announcements (title, body, audience, created_by)
         VALUES (:title, :body, :audience, :created_by)'
    );
    $stmt->execute([
        ':title' => $title,
        ':body' => $body,
        ':audience' => $audience,
        ':created_by' => (int)($_SESSION['user_id'] ?? 0) ?: null,
    ]);
    $announcementId = (int)$db->lastInsertId();

    AuditLog::record(
        $db,
        'announcement_create',
        'announcements',
        $announcementId,
        'Posted announcement "' . $title . '" to ' . $audience,
        ['audience' => $audience]
    );

    $recipients = 0;
    $emailNote = '';
    if ($sendEmail) {
        $mailer = new Mailer($db);
        if (!$mailer->notificationsEnabled()) {
            $emailNote = ' Email notifications are disabled in System Settings.';
        } else {
            $roleFilter = match ($audience) {
                'Members' => ['Member'],
                'Coaches' => ['Head Coach', 'Coach/Trainer'],
                'Officers' => ['Officer'],
                default => ['SuperAdmin', 'Head Coach', 'Officer', 'Coach/Trainer', 'Member'],
            };
            $placeholders = implode(',', array_fill(0, count($roleFilter), '?'));
            $recipientStmt = $db->prepare(
                "SELECT full_name, email
                 FROM users
                 WHERE is_active = 1
                   AND email IS NOT NULL AND email <> ''
                   AND role IN ($placeholders)
                 ORDER BY full_name ASC"
            );
            $recipientStmt->execute($roleFilter);
            $emails = [];
            foreach ($recipientStmt->fetchAll(PDO::FETCH_ASSOC) as $recipient) {
                $emails[] = (string)$recipient['email'];
            }

            if ($emails !== []) {
                $safeBody = htmlspecialchars($body, ENT_QUOTES, 'UTF-8');
                $html = '<p>Hello,</p>'
                    . '<h2>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h2>'
                    . '<div>' . nl2br($safeBody) . '</div>'
                    . '<p style="color:#6b7280;font-size:12px;">This announcement was posted in Arnis Connect.</p>';
                $recipients = $mailer->sendBulk($emails, 'Announcement: ' . $title, $html);
                if ($recipients > 0) {
                    $mark = $db->prepare('UPDATE announcements SET email_sent_at = NOW() WHERE announcement_id = :id');
                    $mark->execute([':id' => $announcementId]);
                    $emailNote = ' Email sent to ' . $recipients . ' recipient' . ($recipients === 1 ? '' : 's') . '.';
                } else {
                    $emailNote = ' The email could not be sent. Check the mail configuration.';
                }
            } else {
                $emailNote = ' No active users with a valid email matched this audience.';
            }
        }
    }

    announcementRedirect('success', 'Announcement posted.' . $emailNote);
} catch (Throwable $e) {
    error_log('process_announcement.php error: ' . $e->getMessage());
    announcementRedirect('error', 'The announcement could not be saved. Run migration 003 if the announcements table is missing.');
}
