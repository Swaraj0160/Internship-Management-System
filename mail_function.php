<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/PHPMailer/src/Exception.php';
require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/src/SMTP.php';

/* EMAIL SWITCH */
$EMAIL_ENABLED = true;

function sendPortalMail($to, $subject, $message)
{
    global $EMAIL_ENABLED;
    static $smtp_working = true;

    if (!$EMAIL_ENABLED || !$smtp_working) {
        return false;
    }

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.hostinger.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'noreply@mitinternship.online';
        $mail->Password = 'Wdcsi@2026';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = 465;
        $mail->Timeout = 3; // short timeout to prevent PHP max execution time block

        $mail->setFrom(
            'noreply@mitinternship.online',
            'MIT Internship Portal'
        );

        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $message;

        $mail->send();
        return true;

    } catch (Exception $e) {
        // If SMTP server is unreachable or credentials fail, prevent hanging remaining batch
        $smtp_working = false;
        return false;
    }
}
?>