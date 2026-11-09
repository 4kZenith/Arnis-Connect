<?php
require 'phpmailer/src/Exception.php';
require 'phpmailer/src/PHPMailer.php';
require 'phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$mail = new PHPMailer(true);

try {
    $mail->SMTPDebug = 2; // 1 = errors, 2 = verbose
    $mail->isSMTP();
    $mail->Host       = 'smtp-relay.brevo.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'c1c474001@smtp-brevo.com';   // from Brevo SMTP settings
    $mail->Password   = 'xsmtpsib-2f66661c357f3388bd32426c67a5284239058a2242bc1ddbe68dfff4e2752b1d-L1PinV0LmS6LwKQX'; // from Brevo SMTP settings
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;

    $mail->setFrom('christpartosa9@gmail.com', 'Arnis Connect');
    $mail->addAddress('christpartosa9@gmail.com', 'Your Name');

    $mail->isHTML(true);
    $mail->Subject = 'Arnis Connect SMTP Test';
    $mail->Body    = '<h1>It works!</h1><p>Brevo SMTP via PHPMailer on InfinityFree.</p>';
    $mail->AltBody = 'It works! Brevo SMTP via PHPMailer on InfinityFree.';

    $mail->send();
    echo '<h3 style="color:green">Message sent!</h3>';
} catch (Exception $e) {
    echo '<h3 style="color:red">Mailer Error: ' . $mail->ErrorInfo . '</h3>';
}