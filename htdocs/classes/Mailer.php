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
}