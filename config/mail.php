<?php
// Email configuration
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';

class MailConfig {
    private static $mailer;
    
    public static function init() {
        self::$mailer = new PHPMailer(true);
        
        // Enable debug for troubleshooting (remove after working)
        // self::$mailer->SMTPDebug = 2;
        
        // Server settings
        self::$mailer->isSMTP();
        self::$mailer->Host = 'smtp.gmail.com';
        self::$mailer->SMTPAuth = true;
        
        // Security: read SMTP credentials from environment variables, not source code.
        self::$mailer->Username = getenv('SMTP_USERNAME') ?: '';
        self::$mailer->Password = getenv('SMTP_PASSWORD') ?: '';
        
        self::$mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        self::$mailer->Port = 587;
        
        // Set timeout to prevent hanging
        self::$mailer->Timeout = 30;
        
        $fromEmail = getenv('MAIL_FROM_EMAIL') ?: (self::$mailer->Username ?: 'noreply@reclaim.com');
        $fromName = getenv('MAIL_FROM_NAME') ?: 'Reclaim System';
        self::$mailer->setFrom($fromEmail, $fromName);
        return self::$mailer;
    }
    
    public static function sendNotification($to, $subject, $body) {
        try {
            $mail = self::init();
            if (empty($mail->Username) || empty($mail->Password)) {
                error_log("Email skipped: SMTP_USERNAME or SMTP_PASSWORD is not configured.");
                return false;
            }
            $mail->clearAddresses();
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->isHTML(true);
            $mail->Body = $body;
            $mail->AltBody = strip_tags($body);
            
            return $mail->send();
        } catch (Exception $e) {
            error_log("Email failed to $to: " . ($mail->ErrorInfo ?? $e->getMessage()));
            return false;
        }
    }
    
    // Test function to verify configuration
    public static function testConnection() {
        try {
            $mail = self::init();
            if (empty($mail->Username) || empty($mail->Password)) {
                return false;
            }
            $mail->smtpConnect();
            $mail->smtpClose();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}
?>
