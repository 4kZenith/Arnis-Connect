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
    announcementEnsureTable($db);

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

    $emailNote = '';
    if ($sendEmail) {
        try {
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
                if ($audience === 'All' || $audience === 'Members') {
                    $emails = array_merge($emails, $mailer->activeMemberEmails('Mixed'));
                } elseif ($audience === 'Coaches') {
                    $coachIds = $db->query(
                        "SELECT member_id FROM members
                         WHERE is_voided = 0 AND status = 'Active'
                           AND member_type IN ('Head Coach', 'Coach')"
                    )->fetchAll(PDO::FETCH_COLUMN) ?: [];
                    foreach ($mailer->contactsForMembers($coachIds) as $contact) {
                        $emails[] = $contact['email'];
                    }
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
                        $emailNote = ' The announcement was saved, but the email could not be sent.';
                    }
                } else {
                    $emailNote = ' No active users with a valid email matched this audience.';
                }
            }
        } catch (Throwable $mailError) {
            error_log('process_announcement email skipped: ' . $mailError->getMessage());
            $emailNote = ' The announcement was saved, but the email could not be sent.';
        }
    }

    announcementRedirect('success', 'Announcement posted.' . $emailNote);
} catch (Throwable $e) {
    error_log('process_announcement.php error: ' . $e->getMessage());
    announcementRedirect('error', 'The announcement could not be saved. Please try again.');
}

/** Creates the table, and repairs it if a partial import left out auto-increment. */
function announcementEnsureTable(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS `announcements` (
           `announcement_id` int(11) NOT NULL AUTO_INCREMENT,
           `title` varchar(150) NOT NULL,
           `body` text NOT NULL,
           `audience` enum('All','Members','Coaches','Officers') NOT NULL DEFAULT 'All',
           `created_by` int(11) DEFAULT NULL,
           `created_at` datetime NOT NULL DEFAULT current_timestamp(),
           `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
           `is_archived` tinyint(1) NOT NULL DEFAULT 0,
           `email_sent_at` datetime DEFAULT NULL,
           PRIMARY KEY (`announcement_id`)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $column = $db->query("SHOW COLUMNS FROM announcements LIKE 'announcement_id'")->fetch(PDO::FETCH_ASSOC);
    if (!$column || stripos((string)($column['Extra'] ?? ''), 'auto_increment') !== false) {
        return;
    }
    $primary = $db->query("SHOW INDEX FROM announcements WHERE Key_name = 'PRIMARY'")->fetch(PDO::FETCH_ASSOC);
    if (!$primary) {
        $db->exec('ALTER TABLE announcements ADD PRIMARY KEY (announcement_id)');
    }
    $db->exec('ALTER TABLE announcements MODIFY announcement_id int(11) NOT NULL AUTO_INCREMENT');
}
