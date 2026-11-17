<?php
/**
 * InternHub — Email Delivery Service
 * MIT-ADT University Internship & Professional Platform
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$baseDir = dirname(__DIR__);
if (file_exists($baseDir . '/PHPMailer/src/Exception.php')) {
    require_once $baseDir . '/PHPMailer/src/Exception.php';
    require_once $baseDir . '/PHPMailer/src/PHPMailer.php';
    require_once $baseDir . '/PHPMailer/src/SMTP.php';
}

class MailService
{
    private static function getMailer(): ?PHPMailer
    {
        if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            return null;
        }

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = getenv('SMTP_HOST') ?: 'smtp.hostinger.com';
            $mail->SMTPAuth = true;
            $mail->Username = getenv('SMTP_USER') ?: 'noreply@mitinternship.online';
            $mail->Password = getenv('SMTP_PASS') ?: 'Wdcsi@2026';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port = (int)(getenv('SMTP_PORT') ?: 465);
            $mail->setFrom(
                getenv('SMTP_FROM_EMAIL') ?: 'noreply@mitinternship.online',
                getenv('SMTP_FROM_NAME') ?: 'MIT-ADT InternHub'
            );
            $mail->isHTML(true);
            $mail->Timeout = 10;
            return $mail;
        } catch (Exception $e) {
            return null;
        }
    }

    private static function logDevEmail(string $type, string $toEmail, string $subject, string $actionUrl): void
    {
        $logDir = dirname(__DIR__) . '/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        $logFile = $logDir . '/email_verification.log';
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[$timestamp] TYPE: $type | TO: $toEmail | SUBJECT: $subject | URL: $actionUrl\n";
        @file_put_contents($logFile, $logEntry, FILE_APPEND);
    }

    public static function sendStudentVerification(string $toEmail, string $name, string $verificationToken, string $baseUrl = ''): bool
    {
        if (empty($baseUrl)) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
            $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1:8000';
            $baseUrl = $protocol . $host;
        }
        $verifyUrl = rtrim($baseUrl, '/') . "/verify_email.php?token=" . urlencode($verificationToken) . "&type=student";
        self::logDevEmail('STUDENT_VERIFICATION', $toEmail, 'Verify Your InternHub Student Account', $verifyUrl);

        $subject = "Verify Your Student Account — InternHub MIT-ADT University";
        $html = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 24px; border: 1px solid #e2e8f0; border-radius: 12px; background: #ffffff;'>
            <div style='text-align: center; margin-bottom: 24px;'>
                <h2 style='color: #4f2d93; margin: 0;'>MIT-ADT University</h2>
                <p style='color: #64748b; font-size: 14px; margin: 4px 0 0 0;'>InternHub Professional Platform</p>
            </div>
            <p>Dear <strong>" . htmlspecialchars($name) . "</strong>,</p>
            <p>Thank you for registering your student account on InternHub. To activate your profile and access internship opportunities, please verify your email address by clicking the button below:</p>
            <div style='text-align: center; margin: 30px 0;'>
                <a href='" . htmlspecialchars($verifyUrl) . "' style='background: linear-gradient(135deg, #6d28d9, #4f2d93); color: #ffffff; text-decoration: none; padding: 12px 28px; border-radius: 8px; font-weight: bold; display: inline-block;'>Verify My Student Account</a>
            </div>
            <p style='font-size: 13px; color: #64748b;'>Or copy and paste this link into your browser:<br><a href='" . htmlspecialchars($verifyUrl) . "' style='color: #6d28d9; word-break: break-all;'>" . htmlspecialchars($verifyUrl) . "</a></p>
            <p style='font-size: 12px; color: #94a3b8; margin-top: 32px; border-top: 1px solid #f1f5f9; padding-top: 16px;'>This link is valid for 24 hours. If you did not create this account, please ignore this email.<br>&copy; 2026 MIT-ADT University, Pune.</p>
        </div>";

        return self::deliver($toEmail, $subject, $html);
    }

    public static function sendFacultyVerification(string $toEmail, string $name, string $verificationToken, string $baseUrl = ''): bool
    {
        if (empty($baseUrl)) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
            $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1:8000';
            $baseUrl = $protocol . $host;
        }
        $verifyUrl = rtrim($baseUrl, '/') . "/verify_email.php?token=" . urlencode($verificationToken) . "&type=faculty";
        self::logDevEmail('FACULTY_VERIFICATION', $toEmail, 'Verify Your Faculty Email', $verifyUrl);

        $subject = "Verify Your Official Faculty Email — InternHub MIT-ADT";
        $html = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 24px; border: 1px solid #e2e8f0; border-radius: 12px; background: #ffffff;'>
            <div style='text-align: center; margin-bottom: 24px;'>
                <h2 style='color: #4f2d93; margin: 0;'>MIT-ADT University</h2>
                <p style='color: #64748b; font-size: 14px; margin: 4px 0 0 0;'>InternHub Faculty Platform</p>
            </div>
            <p>Dear <strong>" . htmlspecialchars($name) . "</strong>,</p>
            <p>We received your faculty registration request for InternHub. Step 1 requires verifying your email ownership. Please click the button below:</p>
            <div style='text-align: center; margin: 30px 0;'>
                <a href='" . htmlspecialchars($verifyUrl) . "' style='background: linear-gradient(135deg, #6d28d9, #4f2d93); color: #ffffff; text-decoration: none; padding: 12px 28px; border-radius: 8px; font-weight: bold; display: inline-block;'>Verify Official Email</a>
            </div>
            <p style='font-size: 13px; color: #475569;'><strong>Next Step:</strong> Once your email is verified, your registration will be routed to the Central Administrator for institutional approval before workspace access is granted.</p>
            <p style='font-size: 12px; color: #94a3b8; margin-top: 32px; border-top: 1px solid #f1f5f9; padding-top: 16px;'>&copy; 2026 MIT-ADT University, Pune.</p>
        </div>";

        return self::deliver($toEmail, $subject, $html);
    }

    public static function sendFacultyDecision(string $toEmail, string $name, string $status, string $remarks = '', string $loginUrl = ''): bool
    {
        if (empty($loginUrl)) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
            $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1:8000';
            $loginUrl = $protocol . $host . '/login.php';
        }

        if ($status === 'approved') {
            $subject = "Faculty Account Approved — InternHub MIT-ADT University";
            $html = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 24px; border: 1px solid #e2e8f0; border-radius: 12px;'>
                <h3 style='color: #15803d;'>Faculty Registration Approved</h3>
                <p>Dear <strong>" . htmlspecialchars($name) . "</strong>,</p>
                <p>Your faculty account on InternHub has been reviewed and approved by the Administrator. You now have full access to student evaluations, reports, and internship workflows.</p>
                <div style='margin: 24px 0;'>
                    <a href='" . htmlspecialchars($loginUrl) . "' style='background: #6d28d9; color: #ffffff; text-decoration: none; padding: 10px 24px; border-radius: 6px; font-weight: bold; display: inline-block;'>Sign In to Faculty Portal</a>
                </div>
            </div>";
        } else {
            $subject = "Faculty Account Update — InternHub MIT-ADT University";
            $html = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 24px; border: 1px solid #e2e8f0; border-radius: 12px;'>
                <h3 style='color: #b91c1c;'>Faculty Registration Update</h3>
                <p>Dear <strong>" . htmlspecialchars($name) . "</strong>,</p>
                <p>Your faculty registration request was reviewed. Regrettably, it was not approved at this time." . 
                (!empty($remarks) ? "<br><strong>Remarks:</strong> " . htmlspecialchars($remarks) : "") . "</p>
                <p>If you believe this is in error, please contact the MIT-ADT Central Internship Administrator.</p>
            </div>";
        }

        self::logDevEmail('FACULTY_DECISION', $toEmail, $subject, $loginUrl);
        return self::deliver($toEmail, $subject, $html);
    }

    private static function deliver(string $toEmail, string $subject, string $htmlBody): bool
    {
        $mailer = self::getMailer();
        if (!$mailer) {
            return false;
        }

        try {
            $mailer->clearAddresses();
            $mailer->addAddress($toEmail);
            $mailer->Subject = $subject;
            $mailer->Body = $htmlBody;
            return $mailer->send();
        } catch (Exception $e) {
            // Safe logging without interrupting application execution
            error_log("MailService delivery failure to {$toEmail}: " . $e->getMessage());
            return false;
        }
    }
}
