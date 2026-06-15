<?php
require_once __DIR__ . '/env.php';

use PHPMailer\PHPMailer\PHPMailer;

$vendorAutoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($vendorAutoload)) {
    require_once $vendorAutoload;
}

class MailConfig
{
    private static $lastError = '';

    private static function dependenciesAvailable()
    {
        if (!class_exists(PHPMailer::class)) {
            self::$lastError = 'Email dependencies are not installed on the server.';
            error_log('Email skipped: vendor/autoload.php or PHPMailer is unavailable.');
            return false;
        }

        return true;
    }

    private static function getEnvValue($name, $default = '')
    {
        EnvLoader::load(__DIR__ . '/../.env');

        $value = EnvLoader::get($name, null);
        if ($value !== null && $value !== false) {
            return $value;
        }

        $value = getenv($name);
        return $value === false ? $default : $value;
    }

    private static function getEnvBoolean($name, $default = false)
    {
        $value = self::getEnvValue($name, null);
        if ($value === null || $value === '') {
            return $default;
        }

        $normalized = strtolower(trim((string) $value));
        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }

        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        return $default;
    }

    private static function getEnvInt($name, $default)
    {
        $value = self::getEnvValue($name, '');
        if ($value === '' || !is_numeric($value)) {
            return $default;
        }

        return (int) $value;
    }

    private static function normalizeSmtpPassword($password)
    {
        $password = trim((string) $password);

        if (preg_match('/^[a-zA-Z0-9]{4}( [a-zA-Z0-9]{4}){3}$/', $password)) {
            return str_replace(' ', '', $password);
        }

        return $password;
    }

    private static function normalizeMailer($mailer)
    {
        $normalized = strtolower(trim((string) $mailer));

        if (in_array($normalized, ['mail', 'phpmail'], true)) {
            return 'mail';
        }

        if ($normalized === 'sendmail') {
            return 'sendmail';
        }

        return 'smtp';
    }

    private static function normalizeEncryption($encryption)
    {
        $normalized = strtolower(trim((string) $encryption));

        if (in_array($normalized, ['', 'none', 'null', 'false', 'off'], true)) {
            return '';
        }

        if (in_array($normalized, ['ssl', 'smtps'], true)) {
            return PHPMailer::ENCRYPTION_SMTPS;
        }

        return PHPMailer::ENCRYPTION_STARTTLS;
    }

    private static function buildDefaultFromEmail($smtpUsername = '')
    {
        $configured = trim((string) self::getEnvValue('MAIL_FROM_EMAIL', self::getEnvValue('SMTP_FROM_EMAIL', '')));
        if ($configured !== '') {
            return $configured;
        }

        if ($smtpUsername !== '' && filter_var($smtpUsername, FILTER_VALIDATE_EMAIL)) {
            return $smtpUsername;
        }

        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $host = preg_replace('/:\d+$/', '', $host) ?? '';
        $host = preg_replace('/^www\./i', '', $host) ?? '';

        if ($host !== '' && filter_var('noreply@' . $host, FILTER_VALIDATE_EMAIL)) {
            return 'noreply@' . $host;
        }

        return 'noreply@localhost';
    }

    private static function configureDebugOutput($mailer)
    {
        $debugLevel = self::getEnvInt('SMTP_DEBUG', 0);
        if ($debugLevel <= 0) {
            return;
        }

        $mailer->SMTPDebug = $debugLevel;
        $mailer->Debugoutput = static function ($message, $level) {
            error_log('SMTP debug [' . $level . ']: ' . trim((string) $message));
        };
    }

    public static function init()
    {
        if (!self::dependenciesAvailable()) {
            return null;
        }

        self::$lastError = '';
        $mailer = new PHPMailer(true);
        $mailer->CharSet = 'UTF-8';
        $mailer->Timeout = self::getEnvInt('SMTP_TIMEOUT', 30);

        self::configureDebugOutput($mailer);

        $transport = self::normalizeMailer(self::getEnvValue('MAIL_MAILER', self::getEnvValue('MAIL_DRIVER', 'smtp')));
        $smtpUsername = '';

        if ($transport === 'mail') {
            $mailer->isMail();
        } elseif ($transport === 'sendmail') {
            $mailer->isSendmail();
        } else {
            $mailer->isSMTP();
            $mailer->Host = trim((string) self::getEnvValue('SMTP_HOST', 'smtp.gmail.com'));
            $mailer->SMTPAuth = self::getEnvBoolean('SMTP_AUTH', true);
            $smtpUsername = trim((string) self::getEnvValue('SMTP_USERNAME', ''));
            $mailer->Username = $smtpUsername;
            $mailer->Password = self::normalizeSmtpPassword(self::getEnvValue('SMTP_PASSWORD', ''));

            $mailer->SMTPSecure = self::normalizeEncryption(
                self::getEnvValue('SMTP_ENCRYPTION', self::getEnvValue('SMTP_SECURE', 'tls'))
            );
            $mailer->Port = self::getEnvInt(
                'SMTP_PORT',
                $mailer->SMTPSecure === PHPMailer::ENCRYPTION_SMTPS ? 465 : 587
            );
            $mailer->SMTPAutoTLS = self::getEnvBoolean('SMTP_AUTO_TLS', $mailer->SMTPSecure !== '');

            if (self::getEnvBoolean('SMTP_ALLOW_SELF_SIGNED', false)) {
                $mailer->SMTPOptions = [
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true,
                    ],
                ];
            }

            $heloDomain = trim((string) self::getEnvValue('SMTP_HELO_DOMAIN', ''));
            if ($heloDomain !== '') {
                $mailer->Helo = $heloDomain;
            }
        }

        $fromEmail = self::buildDefaultFromEmail($smtpUsername);
        $fromName = trim((string) self::getEnvValue('MAIL_FROM_NAME', self::getEnvValue('SMTP_FROM_NAME', 'Reclaim System')));
        if ($fromName === '') {
            $fromName = 'Reclaim System';
        }

        $mailer->setFrom($fromEmail, $fromName);

        $replyToEmail = trim((string) self::getEnvValue('MAIL_REPLY_TO_EMAIL', ''));
        if ($replyToEmail !== '' && filter_var($replyToEmail, FILTER_VALIDATE_EMAIL)) {
            $replyToName = trim((string) self::getEnvValue('MAIL_REPLY_TO_NAME', $fromName));
            $mailer->addReplyTo($replyToEmail, $replyToName !== '' ? $replyToName : $fromName);
        }

        return $mailer;
    }

    public static function sendNotification($to, $subject, $body)
    {
        try {
            $mail = self::init();
            if (!$mail) {
                return false;
            }

            if ($mail->Mailer === 'smtp' && $mail->SMTPAuth && (empty($mail->Username) || empty($mail->Password))) {
                self::$lastError = 'SMTP credentials are not configured.';
                error_log('Email skipped: SMTP_USERNAME or SMTP_PASSWORD is not configured.');
                return false;
            }

            $mail->clearAllRecipients();
            $mail->clearAttachments();
            $mail->Subject = $subject;
            $mail->isHTML(true);
            $mail->Body = $body;
            $mail->AltBody = strip_tags($body);
            $mail->addAddress($to);

            return $mail->send();
        } catch (\Throwable $exception) {
            self::$lastError = trim((string) (($mail->ErrorInfo ?? '') !== '' ? $mail->ErrorInfo : $exception->getMessage()));
            error_log('Email failed to ' . $to . ': ' . (self::$lastError !== '' ? self::$lastError : $exception->getMessage()));
            return false;
        }
    }

    public static function getLastError()
    {
        return self::$lastError;
    }

    public static function testConnection()
    {
        try {
            $mail = self::init();
            if (!$mail) {
                return false;
            }

            if ($mail->Mailer !== 'smtp') {
                return true;
            }

            if ($mail->SMTPAuth && (empty($mail->Username) || empty($mail->Password))) {
                self::$lastError = 'SMTP credentials are not configured.';
                return false;
            }

            $mail->smtpConnect();
            $mail->smtpClose();
            return true;
        } catch (\Throwable $exception) {
            self::$lastError = trim((string) (($mail->ErrorInfo ?? '') !== '' ? $mail->ErrorInfo : $exception->getMessage()));
            return false;
        }
    }
}
?>
