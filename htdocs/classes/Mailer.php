<?php
declare(strict_types=1);

require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/../phpmailer/src/Exception.php';
require_once __DIR__ . '/../phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/../phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class Mailer
{
    private PDO $conn;
    private Settings $settings;
    private bool $debug = false;

    public function __construct(PDO $db, bool $debug = false)
    {
        $this->conn = $db;
        $this->settings = new Settings($db);
        $this->debug = $debug;
    }

    public function notificationsEnabled(): bool
    {
        return $this->settings->enabled('email_notifications');
    }

    /**
     * $accountEmail = true for verification / password-reset mail, which must
     * go out even when general email notifications are switched off.
     */
    public function send(string $to, string $subject, string $htmlBody, ?string $replyTo = null, bool $accountEmail = false): bool
    {
        if (!$accountEmail && !$this->notificationsEnabled()) {
            return false;
        }

        $to = trim($to);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $org = $this->settings->get('organization_name', 'Arnis Connect');
        $fromEmail = trim($this->settings->get('from_email'));
        if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            $fromEmail = 'christpartosa9@gmail.com';
        }
        $fromName = trim($this->settings->get('from_name', $org));
        if ($fromName === '') {
            $fromName = $org;
        }

        $smtpUser = trim($this->settings->get('smtp_user'));
        $smtpPass = trim($this->settings->get('smtp_pass'));

        if ($smtpUser === '' || $smtpPass === '') {
            error_log('Mailer: SMTP login is not configured (Settings -> Email).');
            return false;
        }

        $subject = trim(preg_replace('/[\r\n]+/', ' ', $subject) ?? $subject);
        $subject = mb_substr($subject, 0, 180);

        $body = $this->logoHtml() . $this->wrapHtml($htmlBody, $org);

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = $this->settings->get('smtp_host', 'smtp-relay.brevo.com');
            $mail->SMTPAuth   = true;
            $mail->Username   = $smtpUser;
            $mail->Password   = $smtpPass;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = (int)$this->settings->get('smtp_port', '587');
            $mail->SMTPDebug  = $this->debug ? 2 : 0;

            $mail->setFrom($fromEmail, $fromName);
            if ($replyTo) {
                $mail->addReplyTo($replyTo);
            }
            $mail->addAddress($to);

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $body;
            $mail->AltBody = strip_tags($htmlBody);

            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log('Mailer SMTP error: ' . $mail->ErrorInfo);
            return false;
        }
    }

    /**
     * One announcement to many people. Recipients are BCC'd so they cannot
     * see each other's addresses. Returns how many addresses were accepted.
     *
     * @param string[] $recipients
     */
    public function sendBulk(array $recipients, string $subject, string $htmlBody): int
    {
        if (!$this->notificationsEnabled()) {
            return 0;
        }

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
            $fromEmail = 'christpartosa9@gmail.com';
        }
        $fromName = trim($this->settings->get('from_name', $org));
        if ($fromName === '') {
            $fromName = $org;
        }
        $smtpUser = trim($this->settings->get('smtp_user'));
        $smtpPass = trim($this->settings->get('smtp_pass'));
        if ($smtpUser === '' || $smtpPass === '') {
            error_log('Mailer: SMTP login is not configured (Settings -> Email).');
            return 0;
        }

        $subject = mb_substr(trim(preg_replace('/[\r\n]+/', ' ', $subject) ?? $subject), 0, 180);
        $body = $this->logoHtml() . $this->wrapHtml($htmlBody, $org);

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = $this->settings->get('smtp_host', 'smtp-relay.brevo.com');
            $mail->SMTPAuth   = true;
            $mail->Username   = $smtpUser;
            $mail->Password   = $smtpPass;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = (int)$this->settings->get('smtp_port', '587');
            $mail->setFrom($fromEmail, $fromName);
            // Brevo needs a To address. The club inbox gets the copy; everyone else is BCC.
            $mail->addAddress($fromEmail, $fromName);
            foreach ($valid as $address) {
                if (strcasecmp($address, $fromEmail) !== 0) {
                    $mail->addBCC($address);
                }
            }
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $body;
            $mail->AltBody = strip_tags($htmlBody);
            $mail->send();
            return count($valid);
        } catch (Exception $e) {
            error_log('Mailer::sendBulk SMTP error: ' . $mail->ErrorInfo);
            return 0;
        }
    }

    private function logoHtml(): string
    {
        $logoPath = __DIR__ . '/../../arnis-logo.png';
        $logoUrl  = 'https://arnis-connect.great-site.net/arnis-logo.png';

        if (is_file($logoPath)) {
            $data = file_get_contents($logoPath);
            $base64 = base64_encode($data);
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $logoPath) ?: 'image/png';
            finfo_close($finfo);
            return '<div style="text-align:center;margin-bottom:20px;"><img src="data:' . $mime . ';base64,' . $base64 . '" alt="Arnis Connect" style="height:48px;"></div>';
        }

        return '<div style="text-align:center;margin-bottom:20px;"><img src="' . $logoUrl . '" alt="Arnis Connect" style="height:48px;"></div>';
    }

    private function wrapHtml(string $body, string $org): string
    {
        return '
        <div style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:24px;background:#1d212a;color:#fff;border-radius:12px;">
            ' . $body . '
            <hr style="border:none;border-top:1px solid #333;margin:24px 0;">
            <p style="font-size:12px;color:#888;margin:0;">© ' . htmlspecialchars($org) . '</p>
        </div>';
    }

    public function sendVerification(string $to, string $fullName, string $token): bool
    {
        $verifyUrl = 'https://arnis-connect.great-site.net/verify_email.php?token=' . urlencode($token);
        $body = '<p>Hello <strong>' . htmlspecialchars($fullName) . '</strong>,</p>'
            . '<p>Click the button below to verify your email and activate your account:</p>'
            . '<div style="text-align:center;margin:24px 0;">'
            . '<a href="' . htmlspecialchars($verifyUrl) . '" style="background:#a855f7;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:bold;display:inline-block;">Verify Email</a>'
            . '</div>'
            . '<p style="font-size:14px;color:#aaa;">If you did not sign up, ignore this email.</p>';
        return $this->send($to, 'Verify your Arnis Connect account', $body, null, true);
    }

    public function sendPasswordReset(string $to, string $fullName, string $token): bool
    {
        $resetUrl = 'https://arnis-connect.great-site.net/reset_password.php?token=' . urlencode($token);
        $body = '<p>Hello <strong>' . htmlspecialchars($fullName) . '</strong>,</p>'
            . '<p>We received a request to reset your Arnis Connect password. This link works once and expires in 1 hour:</p>'
            . '<div style="text-align:center;margin:24px 0;">'
            . '<a href="' . htmlspecialchars($resetUrl) . '" style="background:#a855f7;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:bold;display:inline-block;">Reset Password</a>'
            . '</div>'
            . '<p style="font-size:14px;color:#aaa;">If you did not ask for this, ignore this email. Your password stays the same.</p>';
        return $this->send($to, 'Reset your Arnis Connect password', $body, null, true);
    }

    /** Plain-text notice; honours the "email notifications" setting. */
    public function sendNotice(string $to, string $fullName, string $subject, string $message): bool
    {
        $body = '<p>Hello <strong>' . htmlspecialchars($fullName) . '</strong>,</p>'
            . '<p>' . nl2br(htmlspecialchars($message)) . '</p>'
            . '<p style="font-size:14px;color:#aaa;">Log in to Arnis Connect for details.</p>';
        return $this->send($to, $subject, $body);
    }

    public function sendTrainingNotification(string $to, string $fullName, array $training): bool
    {
        $date = (string)($training['training_date'] ?? '');
        $type = (string)($training['training_type'] ?? 'Training');
        $start = (string)($training['start_time'] ?? '');
        $end = (string)($training['end_time'] ?? '');
        $time = trim($start . ($end !== '' ? ' - ' . $end : ''));
        $location = trim((string)($training['location'] ?? ''));
        if ($location === '') {
            $location = 'To be announced';
        }
        $message = 'A ' . $type . ' training session is scheduled on ' . $date
            . ($time !== '' ? ' (' . $time . ')' : '')
            . ' at ' . $location . '.';
        return $this->sendNotice($to, $fullName, 'Training session: ' . ($date !== '' ? $date : $type), $message);
    }

    public function sendEventNotification(string $to, string $fullName, array $event): bool
    {
        $name = (string)($event['event_name'] ?? 'Event');
        $date = (string)($event['event_date'] ?? '');
        $type = (string)($event['event_type'] ?? 'Event');
        $location = trim((string)($event['location'] ?? ''));
        if ($location === '') {
            $location = 'To be announced';
        }
        $message = 'You are included in the ' . $type . ' event "' . $name . '"'
            . ($date !== '' ? ' on ' . $date : '')
            . ' at ' . $location . '.';
        return $this->sendNotice($to, $fullName, 'Event: ' . $name, $message);
    }

    public function notifyMember(int $memberId, string $subject, string $message): bool
    {
        $contact = $this->contactForMember($memberId);
        if ($contact === null) {
            return false;
        }
        return $this->sendNotice($contact['email'], $contact['name'], $subject, $message);
    }

    /** @return array{name:string,email:string}|null */
    public function contactForMember(int $memberId): ?array
    {
        $rows = $this->contactsForMembers([$memberId]);
        return $rows[0] ?? null;
    }

    /**
     * Member profile email, or the linked login email when the profile email is blank.
     *
     * @param int[] $memberIds
     * @return list<array{name:string,email:string}>
     */
    public function contactsForMembers(array $memberIds): array
    {
        $ids = [];
        foreach ($memberIds as $memberId) {
            $id = (int)$memberId;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        $ids = array_values($ids);
        if ($ids === []) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach ($ids as $i => $id) {
            $key = ':mid' . $i;
            $placeholders[] = $key;
            $params[$key] = $id;
        }
        $stmt = $this->conn->prepare(
            'SELECT m.full_name,
                    COALESCE(NULLIF(TRIM(m.email), \'\'), (
                        SELECT u.email FROM users u
                        WHERE u.member_id = m.member_id AND u.is_active = 1
                          AND u.email IS NOT NULL AND TRIM(u.email) <> \'\'
                        ORDER BY u.user_id ASC
                        LIMIT 1
                    )) AS email
             FROM members m
             WHERE m.is_voided = 0 AND m.member_id IN (' . implode(', ', $placeholders) . ')'
        );
        $stmt->execute($params);
        $contacts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $email = trim((string)($row['email'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $name = trim((string)($row['full_name'] ?? ''));
            $contacts[] = ['name' => $name !== '' ? $name : 'Member', 'email' => $email];
        }
        return $contacts;
    }

    /** @param int[] $excludeMemberIds @return list<string> */
    public function activeMemberEmails(string $targetGroup, array $excludeMemberIds = []): array
    {
        $sql = "SELECT member_id FROM members
                WHERE is_voided = 0 AND status = 'Active'";
        $params = [];
        if ($targetGroup === 'Public' || $targetGroup === 'Private') {
            $sql .= " AND player_category IN (:target_group, 'Mixed')";
            $params[':target_group'] = $targetGroup;
        }
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        $ids = [];
        $skip = [];
        foreach ($excludeMemberIds as $memberId) {
            $id = (int)$memberId;
            if ($id > 0) {
                $skip[$id] = true;
            }
        }
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $memberId) {
            $id = (int)$memberId;
            if (!isset($skip[$id])) {
                $ids[] = $id;
            }
        }
        $emails = [];
        foreach ($this->contactsForMembers($ids) as $contact) {
            $emails[] = $contact['email'];
        }
        return $emails;
    }

    /** @return list<string> */
    public function trainingRosterEmails(int $trainingId): array
    {
        $stmt = $this->conn->prepare(
            'SELECT member_id FROM attendance WHERE training_id = :id AND is_voided = 0'
        );
        $stmt->execute([':id' => $trainingId]);
        $emails = [];
        foreach ($this->contactsForMembers($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) as $contact) {
            $emails[] = $contact['email'];
        }
        return $emails;
    }

    /** @return list<string> */
    public function eventParticipantEmails(int $eventId): array
    {
        $stmt = $this->conn->prepare(
            'SELECT member_id FROM event_participants WHERE event_id = :id AND is_voided = 0'
        );
        $stmt->execute([':id' => $eventId]);
        $emails = [];
        foreach ($this->contactsForMembers($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) as $contact) {
            $emails[] = $contact['email'];
        }
        return $emails;
    }

    /** @param string[] $roles @return list<string> */
    public function emailsForRoles(array $roles): array
    {
        $roles = array_values(array_filter($roles, static fn($role): bool => is_string($role) && $role !== ''));
        if ($roles === []) {
            return [];
        }
        $placeholders = [];
        $params = [];
        foreach ($roles as $i => $role) {
            $key = ':role' . $i;
            $placeholders[] = $key;
            $params[$key] = $role;
        }
        $stmt = $this->conn->prepare(
            'SELECT email FROM users
             WHERE is_active = 1 AND email IS NOT NULL AND TRIM(email) <> \'\'
               AND role IN (' . implode(', ', $placeholders) . ')'
        );
        $stmt->execute($params);
        $emails = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $email) {
            $email = trim((string)$email);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $emails[] = $email;
            }
        }
        return $emails;
    }

    /** @param string[] $emails */
    public function sendPlainBulk(array $emails, string $subject, string $message): int
    {
        $html = '<p>' . nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8')) . '</p>'
            . '<p style="font-size:14px;color:#aaa;">Log in to Arnis Connect for details.</p>';
        return $this->sendBulk($emails, $subject, $html);
    }
}