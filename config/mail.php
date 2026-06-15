<?php
// Email configuration
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';

class MailConfig {
    private static $mailer;
    private static $lastError = '';

    private static function getEnvValue($name, $default = '') {
        if (class_exists('EnvLoader')) {
            EnvLoader::load(__DIR__ . '/../.env');
            $value = EnvLoader::get($name, null);
            if ($value !== null && $value !== false) {
                return $value;
            }
        }

        $value = getenv($name);
        return $value === false ? $default : $value;
    }

    private static function normalizeSmtpPassword($password) {
        $password = trim((string) $password);

        // Gmail app passwords are often copied as 4 groups with spaces.
        if (preg_match('/^[a-zA-Z0-9]{4}( [a-zA-Z0-9]{4}){3}$/', $password)) {
            return str_replace(' ', '', $password);
        }

        return $password;
    }
    
    public static function init() {
        self::$mailer = new PHPMailer(true);
        self::$lastError = '';
        
        // Enable debug for troubleshooting (remove after working)
        // self::$mailer->SMTPDebug = 2;
        
        // Server settings
        self::$mailer->isSMTP();
        self::$mailer->Host = 'smtp.gmail.com';
        self::$mailer->SMTPAuth = true;
        
        // Security: read SMTP credentials from environment variables, not source code.
        self::$mailer->Username = trim((string) self::getEnvValue('SMTP_USERNAME', ''));
        self::$mailer->Password = self::normalizeSmtpPassword(self::getEnvValue('SMTP_PASSWORD', ''));
        
        self::$mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        self::$mailer->Port = 587;
        
        // Set timeout to prevent hanging
        self::$mailer->Timeout = 30;
        
        $fromEmail = trim((string) self::getEnvValue('MAIL_FROM_EMAIL', ''));
        if ($fromEmail === '') {
            $fromEmail = self::$mailer->Username ?: 'noreply@reclaim.com';
        }

        $fromName = trim((string) self::getEnvValue('MAIL_FROM_NAME', 'Reclaim System'));
        if ($fromName === '') {
            $fromName = 'Reclaim System';
        }
        self::$mailer->setFrom($fromEmail, $fromName);
        return self::$mailer;
    }
    
    public static function sendNotification($to, $subject, $body) {
        try {
            $mail = self::init();
            if (empty($mail->Username) || empty($mail->Password)) {
                self::$lastError = 'SMTP credentials are not configured.';
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
            self::$lastError = trim((string) ($mail->ErrorInfo ?? $e->getMessage()));
            error_log("Email failed to $to: " . (self::$lastError !== '' ? self::$lastError : $e->getMessage()));
            return false;
        }
    }

    public static function getLastError() {
        return self::$lastError;
    }
    
    // Test function to verify configuration
    public static function testConnection() {
        try {
            $mail = self::init();
            if (empty($mail->Username) || empty($mail->Password)) {
                self::$lastError = 'SMTP credentials are not configured.';
                return false;
            }
            $mail->smtpConnect();
            $mail->smtpClose();
            return true;
        } catch (Exception $e) {
            self::$lastError = trim((string) ($mail->ErrorInfo ?? $e->getMessage()));
            return false;
        }
    }
}
?>
