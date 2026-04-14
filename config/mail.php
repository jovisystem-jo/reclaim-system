<?php
// Email configuration
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';

class MailConfig {
    private static $mailer;
    
    public static function init() {
        self::$mailer = new PHPMailer(true);
        
        // Server settings (configure for your email provider)
        self::$mailer->isSMTP();
        self::$mailer->Host = 'smtp.gmail.com'; // Your SMTP server
        self::$mailer->SMTPAuth = true;
        self::$mailer->Username = 'your-email@gmail.com';
        self::$mailer->Password = 'your-app-password';
        self::$mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        self::$mailer->Port = 587;
        
        self::$mailer->setFrom('noreply@reclaim.com', 'Reclaim System');
        return self::$mailer;
    }
    
    public static function sendNotification($to, $subject, $body) {
        try {
            $mail = self::init();
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->isHTML(true);
            $mail->Body = $body;
            $mail->AltBody = strip_tags($body);
            
            return $mail->send();
        } catch (Exception $e) {
            error_log("Email failed: " . $mail->ErrorInfo);
            return false;
        }
    }
}
?>