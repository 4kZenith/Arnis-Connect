<?php
declare(strict_types=1);

require_once __DIR__ . '/Settings.php';

/**
 * Thin, dependency-free mail transport.
 *
 * Uses PHP mail(). On shared hosting that is the only transport available
 * without Composer/SMTP credentials; every send is logged so a missing mail
 * configuration is visible in the PHP error log instead of silently failing.
 *
 * Set from_email/from_name in System Settings. If from_email is empty the
 * transport falls back to no-reply@<host>, which shared hosts usually accept.
 */
class Mailer
{
    private PDO $conn;
    private Settings $settings;

    public function __construct(PDO $db)
    {
        $this->conn = $db;
        $this->settings = new Settings($db);
    }

    public function notificationsEnabled(): bool
    {
        return $this->settings->enabled('email_notifications');
    }

    /**
     * @return bool true when the transport accepted the message.
     */
    public function send(string $to, string $subject, string $htmlBody, ?string $replyTo = null): bool
    {
        $to = trim($to);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $org = $this->settings->get('organization_name', 'Arnis Connect');
        $fromEmail = trim($this->settings->get('from_email'));
        if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
            $host = preg_replace('/[^A-Za-z0-9.-]/', '', $host) ?: 'localhost';
            $fromEmail = 'no-reply@' . $host;
        }
        $fromName = trim($this->settings->get('from_name', $org));
        if ($fromName === '') {
            $fromName = $org;
        }

        $subject = trim(preg_replace('/[\r\n]+/', ' ', $subject) ?? $subject);
        $subject = mb_substr($subject, 0, 180);

        $body = $this->wrapHtml($htmlBody, $org);

        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $this->encodeName($fromName) . ' <' . $fromEmail . '>',
            'Reply-To: ' . ($replyTo !== null && filter_var($replyTo, FILTER_VALIDATE_EMAIL) ? $replyTo : $fromEmail),
            'X-Mailer: ArnisConnect',
        ];

        $ok = false;
        try {
            $ok = @mail($to, $subject, $body, implode("\r\n", $headers));
        } catch (Throwable $e) {
            error_log('Mailer::send failed: ' . $e->getMessage());
        }

        if (!$ok) {
            error_log('Mailer::send rejected message to ' . $to . ' (subject: ' . $subject . '). Check PHP mail configuration.');
        }
        return $ok;
    }

    /**
     * Send one message to many recipients using BCC (avoids leaking the list).
     *
     * @param string[] $recipients
     */
    public function sendBulk(array $recipients, string $subject, string $htmlBody): int
    {
        $valid = [];
        foreach ($recipients as $recipient) {
            $recipient = trim((string)$recipient);
            if ($recipient !== '' && filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                $valid[strtolower($recipient)] = $recipient;
            }
        }
        if ($valid === []) {
            return 0;
        }

        $org = $this->settings->get('organization_name', 'Arnis Connect');
        $fromEmail = trim($this->settings->get('from_email'));
        if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            $host = preg_replace('/[^A-Za-z0-9.-]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost';
            $fromEmail = 'no-reply@' . $host;
        }
        $fromName = trim($this->settings->get('from_name', $org)) ?: $org;
        $subject = mb_substr(trim(preg_replace('/[\r\n]+/', ' ', $subject) ?? $subject), 0, 180);
        $body = $this->wrapHtml($htmlBody, $org);

        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $this->encodeName($fromName) . ' <' . $fromEmail . '>',
            'Bcc: ' . implode(', ', array_values($valid)),
            'X-Mailer: ArnisConnect',
        ];

        try {
            $ok = @mail($fromEmail, $subject, $body, implode("\r\n", $headers));
        } catch (Throwable $e) {
            error_log('Mailer::sendBulk failed: ' . $e->getMessage());
            $ok = false;
        }

        if (!$ok) {
            error_log('Mailer::sendBulk rejected message to ' . count($valid) . ' recipients.');
            return 0;
        }
        return count($valid);
    }

    public function sendVerification(string $to, string $name, string $token): bool
    {
        $link = arnisAppUrl('verify_email.php?token=' . urlencode($token));
        $safeName = htmlspecialchars($name !== '' ? $name : 'there', ENT_QUOTES, 'UTF-8');
        $safeLink = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');

        return $this->send(
            $to,
            'Verify your Arnis Connect email address',
            '<p>Hello ' . $safeName . ',</p>'
            . '<p>Thank you for registering with Arnis Connect. Confirm your email address to activate your applicant account.</p>'
            . '<p><a href="' . $safeLink . '" style="display:inline-block;padding:12px 20px;background:#7c3aed;color:#fff;text-decoration:none;border-radius:8px;">Verify email</a></p>'
            . '<p>This link expires in 24 hours. If you did not create this account, you can ignore this message.</p>'
            . '<p style="color:#6b7280;font-size:12px;">' . $safeLink . '</p>'
        );
    }

    public function sendEventNotification(string $to, string $name, array $event): bool
    {
        $safeName = htmlspecialchars($name !== '' ? $name : 'member', ENT_QUOTES, 'UTF-8');
        $eventName = htmlspecialchars((string)($event['event_name'] ?? 'Event'), ENT_QUOTES, 'UTF-8');
        $date = htmlspecialchars((string)($event['event_date'] ?? ''), ENT_QUOTES, 'UTF-8');
        $location = htmlspecialchars((string)($event['location'] ?? 'To be announced'), ENT_QUOTES, 'UTF-8');
        $type = htmlspecialchars((string)($event['event_type'] ?? ''), ENT_QUOTES, 'UTF-8');

        return $this->send(
            $to,
            'New event: ' . $eventName,
            '<p>Hello ' . $safeName . ',</p>'
            . '<p>A new <strong>' . $type . '</strong> event has been posted:</p>'
            . '<p style="font-size:18px;font-weight:bold;">' . $eventName . '</p>'
            . '<p>Date: ' . $date . '<br>Location: ' . $location . '</p>'
            . '<p>Sign in to Arnis Connect to view details and participants.</p>'
        );
    }

    public function sendTrainingNotification(string $to, string $name, array $training): bool
    {
        $safeName = htmlspecialchars($name !== '' ? $name : 'member', ENT_QUOTES, 'UTF-8');
        $date = htmlspecialchars((string)($training['training_date'] ?? ''), ENT_QUOTES, 'UTF-8');
        $location = htmlspecialchars((string)($training['location'] ?? 'To be announced'), ENT_QUOTES, 'UTF-8');
        $type = htmlspecialchars((string)($training['training_type'] ?? 'Regular'), ENT_QUOTES, 'UTF-8');
        $start = htmlspecialchars((string)($training['start_time'] ?? ''), ENT_QUOTES, 'UTF-8');
        $end = htmlspecialchars((string)($training['end_time'] ?? ''), ENT_QUOTES, 'UTF-8');
        $time = trim($start . ($start !== '' && $end !== '' ? ' - ' : '') . $end);

        return $this->send(
            $to,
            'New training session: ' . $date,
            '<p>Hello ' . $safeName . ',</p>'
            . '<p>A new <strong>' . $type . '</strong> training session has been scheduled.</p>'
            . '<p>Date: ' . $date . '<br>Time: ' . ($time !== '' ? $time : 'To be announced') . '<br>Location: ' . $location . '</p>'
            . '<p>Sign in to Arnis Connect for the full session details.</p>'
        );
    }

    public function sendAnnouncement(string $to, string $name, string $title, string $body): bool
    {
        $safeName = htmlspecialchars($name !== '' ? $name : 'member', ENT_QUOTES, 'UTF-8');
        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $safeBody = nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'));

        return $this->send(
            $to,
            'Announcement: ' . $title,
            '<p>Hello ' . $safeName . ',</p>'
            . '<h2>' . $safeTitle . '</h2>'
            . '<div>' . $safeBody . '</div>'
        );
    }

    private function wrapHtml(string $inner, string $org): string
    {
        $safeOrg = htmlspecialchars($org, ENT_QUOTES, 'UTF-8');
        return '<!doctype html><html><body style="font-family:Arial,sans-serif;line-height:1.6;color:#111827;max-width:640px;margin:0 auto;padding:24px;">'
            . $inner
            . '<hr style="border:none;border-top:1px solid #e5e7eb;margin:24px 0;">'
            . '<p style="color:#6b7280;font-size:12px;">' . $safeOrg . ' &middot; This message was sent by the Arnis Connect system.</p>'
            . '</body></html>';
    }

    private function encodeName(string $name): string
    {
        if (function_exists('mb_encode_mimeheader')) {
            return mb_encode_mimeheader($name, 'UTF-8', 'B', "\r\n");
        }
        return $name;
    }
}
