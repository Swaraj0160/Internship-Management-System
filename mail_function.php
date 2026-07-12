<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

/* EMAIL SWITCH */

$EMAIL_ENABLED = true;

function sendPortalMail($to, $subject, $message)
{
    global $EMAIL_ENABLED;

    if(!$EMAIL_ENABLED)
    {
        return true;
    }

    $mail = new PHPMailer(true);

    try {

        $mail->isSMTP();

        // Hostinger SMTP settings
        $mail->Host = 'smtp.hostinger.com';
        $mail->SMTPAuth = true;

        // Your mailbox
        $mail->Username = 'noreply@mitinternship.online';

        // Mailbox password (IMPORTANT)
        $mail->Password = 'Wdcsi@2026';

        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;

        $mail->Port = 465;

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

        return false;
    }
}
?>