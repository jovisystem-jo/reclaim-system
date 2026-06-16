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
    private static $lastTransport = '';
    private static $lastPreviewId = '';

    private static function isProductionEnvironment()
    {
        return strtolower(trim((string) self::getEnvValue('APP_ENV', 'development'))) === 'production';
    }

    private static function dependenciesAvailable()
    {
        if (!class_exists(PHPMailer::class)) {
            self::$lastError = 'Email dependencies are not installed on the server.';
            self::logDiagnostic('Email skipped: vendor/autoload.php or PHPMailer is unavailable.');
            return false;
        }

        return true;
    }

    private static function logDiagnostic($message)
    {
        $message = '[' . date('Y-m-d H:i:s') . '] ' . trim((string) $message);
        error_log($message);

        $logDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
        if (!is_dir($logDirectory) && !@mkdir($logDirectory, 0755, true) && !is_dir($logDirectory)) {
            return;
        }

        @file_put_contents(
            $logDirectory . DIRECTORY_SEPARATOR . 'mail.log',
            $message . PHP_EOL,
            FILE_APPEND
        );
    }

    private static function isWebRequest()
    {
        return PHP_SAPI !== 'cli';
    }

    private static function getEnvValue($name, $default = '')
    {
        try {
            EnvLoader::load(__DIR__ . '/../.env');
        } catch (\Throwable $exception) {
            self::logDiagnostic('Unable to load mail environment settings: ' . $exception->getMessage());
        }

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

    private static function resolveConfiguredMailer()
    {
        $configured = trim((string) self::getEnvValue('MAIL_MAILER', self::getEnvValue('MAIL_DRIVER', '')));
        if ($configured !== '') {
            return self::normalizeMailer($configured);
        }

        $smtpHost = trim((string) self::getEnvValue('SMTP_HOST', ''));
        $smtpUsername = trim((string) self::getEnvValue('SMTP_USERNAME', ''));
        if ($smtpHost !== '' || $smtpUsername !== '') {
            return 'smtp';
        }

        return self::isProductionEnvironment() ? 'mail' : 'smtp';
    }

    private static function shouldFallbackToMail()
    {
        return self::getEnvBoolean('MAIL_FALLBACK_TO_MAIL', self::isProductionEnvironment());
    }

    private static function shouldFallbackToSendmail()
    {
        return self::getEnvBoolean('MAIL_FALLBACK_TO_SENDMAIL', self::shouldFallbackToMail());
    }

    private static function shouldFallbackToPreview()
    {
        return self::getEnvBoolean('MAIL_FALLBACK_TO_PREVIEW', !self::isProductionEnvironment());
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

        if (in_array($normalized, ['auto', 'automatic'], true)) {
            return 'auto';
        }

        if (in_array($normalized, ['preview', 'file', 'log'], true)) {
            return 'preview';
        }

        if (in_array($normalized, ['mail', 'phpmail'], true)) {
            return 'mail';
        }

        if ($normalized === 'sendmail') {
            return 'sendmail';
        }

        return 'smtp';
    }

    private static function normalizeSmtpHost($host)
    {
        return strtolower(trim((string) $host));
    }

    private static function isGmailSmtpHost($host)
    {
        $normalized = self::normalizeSmtpHost($host);
        return in_array($normalized, ['smtp.gmail.com', 'smtp.googlemail.com'], true);
    }

    private static function formatSmtpProfileLabel(array $profile)
    {
        $host = $profile['host'] ?? 'smtp';
        $port = (int) ($profile['port'] ?? 0);
        $encryption = trim((string) ($profile['encryption'] ?? ''));

        if ($encryption === PHPMailer::ENCRYPTION_STARTTLS) {
            $encryption = 'tls';
        } elseif ($encryption === PHPMailer::ENCRYPTION_SMTPS) {
            $encryption = 'ssl';
        } elseif ($encryption === '') {
            $encryption = 'none';
        }

        return $host . ':' . $port . '/' . $encryption;
    }

    private static function detectAppHost()
    {
        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '')));
        $host = preg_replace('/:\d+$/', '', $host) ?? '';
        $host = preg_replace('/^www\./i', '', $host) ?? '';

        if ($host !== '') {
            return $host;
        }

        $appUrlHost = parse_url((string) self::getEnvValue('APP_URL', ''), PHP_URL_HOST);
        if (is_string($appUrlHost) && $appUrlHost !== '') {
            $appUrlHost = preg_replace('/^www\./i', '', $appUrlHost) ?? $appUrlHost;
            if ($appUrlHost !== '') {
                return $appUrlHost;
            }
        }

        $mailDomain = trim((string) self::getEnvValue('MAIL_DOMAIN', ''));
        if ($mailDomain !== '') {
            return preg_replace('/^www\./i', '', $mailDomain) ?? $mailDomain;
        }

        return '';
    }

    private static function isValidMailbox($email)
    {
        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL);
    }

    private static function resetLastState()
    {
        self::$lastError = '';
        self::$lastTransport = '';
        self::$lastPreviewId = '';
    }

    private static function previewDirectory()
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'mail-previews';
    }

    private static function writePreview($to, $subject, $body, &$errorMessage = '')
    {
        $previewDir = self::previewDirectory();

        if (!is_dir($previewDir) && !mkdir($previewDir, 0755, true) && !is_dir($previewDir)) {
            $errorMessage = 'Unable to create the local mail preview folder.';
            return false;
        }

        try {
            $previewId = gmdate('Ymd_His') . '_' . bin2hex(random_bytes(6));
        } catch (\Throwable $exception) {
            $errorMessage = 'Unable to generate the local mail preview identifier.';
            return false;
        }

        $smtpUsername = trim((string) self::getEnvValue('SMTP_USERNAME', ''));
        $payload = [
            'id' => $previewId,
            'transport' => 'preview',
            'created_at' => date('c'),
            'to' => $to,
            'subject' => $subject,
            'from_email' => self::buildDefaultFromEmail($smtpUsername),
            'from_name' => trim((string) self::getEnvValue('MAIL_FROM_NAME', self::getEnvValue('SMTP_FROM_NAME', 'Reclaim System'))),
            'html_body' => $body,
            'text_body' => strip_tags($body),
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $errorMessage = 'Unable to encode the local mail preview.';
            return false;
        }

        $previewPath = $previewDir . DIRECTORY_SEPARATOR . $previewId . '.json';
        if (file_put_contents($previewPath, $json) === false) {
            $errorMessage = 'Unable to save the local mail preview.';
            return false;
        }

        self::$lastTransport = 'preview';
        self::$lastPreviewId = $previewId;
        return true;
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

    private static function resolveSmtpTimeout($defaultTimeout)
    {
        $timeout = self::getEnvInt('SMTP_TIMEOUT', $defaultTimeout);
        if ($timeout <= 0) {
            $timeout = $defaultTimeout;
        }

        if (self::isWebRequest()) {
            $webCap = self::getEnvInt('SMTP_WEB_TIMEOUT_CAP', 12);
            if ($webCap > 0) {
                $timeout = min($timeout, max(5, $webCap));
            }
        }

        return max(5, $timeout);
    }

    private static function ensureRuntimeBudget($timeout)
    {
        if (!function_exists('set_time_limit')) {
            return;
        }

        $currentLimit = (int) ini_get('max_execution_time');
        if ($currentLimit === 0) {
            return;
        }

        $buffer = max(5, self::getEnvInt('SMTP_EXECUTION_BUFFER', 8));
        $desiredLimit = max($currentLimit, ((int) $timeout) + $buffer);
        @set_time_limit($desiredLimit);
    }

    private static function buildBaseSmtpProfile()
    {
        $host = trim((string) self::getEnvValue('SMTP_HOST', 'smtp.gmail.com'));
        $encryption = self::normalizeEncryption(
            self::getEnvValue('SMTP_ENCRYPTION', self::getEnvValue('SMTP_SECURE', 'tls'))
        );
        $port = self::getEnvInt(
            'SMTP_PORT',
            $encryption === PHPMailer::ENCRYPTION_SMTPS ? 465 : 587
        );

        return [
            'host' => $host,
            'auth' => self::getEnvBoolean('SMTP_AUTH', true),
            'username' => trim((string) self::getEnvValue('SMTP_USERNAME', '')),
            'password' => self::normalizeSmtpPassword(self::getEnvValue('SMTP_PASSWORD', '')),
            'encryption' => $encryption,
            'port' => $port,
            'auto_tls' => self::getEnvBoolean('SMTP_AUTO_TLS', $encryption !== ''),
            'allow_self_signed' => self::getEnvBoolean('SMTP_ALLOW_SELF_SIGNED', false),
            'helo' => trim((string) self::getEnvValue('SMTP_HELO_DOMAIN', '')),
            'timeout' => self::resolveSmtpTimeout(self::isProductionEnvironment() ? 8 : 30),
        ];
    }

    private static function uniqueSmtpProfiles(array $profiles)
    {
        $uniqueProfiles = [];
        $seen = [];

        foreach ($profiles as $profile) {
            $signature = implode('|', [
                self::normalizeSmtpHost($profile['host'] ?? ''),
                (string) ($profile['port'] ?? ''),
                (string) ($profile['encryption'] ?? ''),
                !empty($profile['auto_tls']) ? '1' : '0',
            ]);

            if (isset($seen[$signature])) {
                continue;
            }

            $seen[$signature] = true;
            $uniqueProfiles[] = $profile;
        }

        return $uniqueProfiles;
    }

    private static function buildSmtpProfiles()
    {
        $baseProfile = self::buildBaseSmtpProfile();
        $profiles = [$baseProfile];

        if (self::isGmailSmtpHost($baseProfile['host'])) {
            $profiles[] = array_merge($baseProfile, [
                'port' => 587,
                'encryption' => PHPMailer::ENCRYPTION_STARTTLS,
                'auto_tls' => true,
            ]);
            $profiles[] = array_merge($baseProfile, [
                'port' => 465,
                'encryption' => PHPMailer::ENCRYPTION_SMTPS,
                'auto_tls' => false,
            ]);
        }

        return self::uniqueSmtpProfiles($profiles);
    }

    private static function buildDefaultFromEmail($smtpUsername = '', $transport = 'smtp')
    {
        $configured = trim((string) self::getEnvValue('MAIL_FROM_EMAIL', self::getEnvValue('SMTP_FROM_EMAIL', '')));
        if ($configured !== '') {
            return $configured;
        }

        $localOverride = trim((string) self::getEnvValue('MAIL_LOCAL_FROM_EMAIL', ''));
        if (in_array($transport, ['mail', 'sendmail'], true) && self::isValidMailbox($localOverride)) {
            return $localOverride;
        }

        $host = self::detectAppHost();
        $domainBasedFrom = '';
        if ($host !== '' && self::isValidMailbox('noreply@' . $host)) {
            $domainBasedFrom = 'noreply@' . $host;
        }

        if (in_array($transport, ['mail', 'sendmail'], true) && $domainBasedFrom !== '') {
            return $domainBasedFrom;
        }

        if ($smtpUsername !== '' && self::isValidMailbox($smtpUsername)) {
            return $smtpUsername;
        }

        if ($domainBasedFrom !== '') {
            return $domainBasedFrom;
        }

        return 'noreply@localhost';
    }

    private static function buildReturnPathEmail($fromEmail)
    {
        $configured = trim((string) self::getEnvValue('MAIL_RETURN_PATH_EMAIL', ''));
        if (self::isValidMailbox($configured)) {
            return $configured;
        }

        if (self::isValidMailbox($fromEmail)) {
            return $fromEmail;
        }

        $host = self::detectAppHost();
        if ($host !== '' && self::isValidMailbox('mailer@' . $host)) {
            return 'mailer@' . $host;
        }

        return '';
    }

    private static function buildHostname()
    {
        $configured = trim((string) self::getEnvValue('MAIL_HOSTNAME', self::getEnvValue('SMTP_HELO_DOMAIN', '')));
        if ($configured !== '') {
            return $configured;
        }

        return self::detectAppHost();
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

    public static function init($transportOverride = null, array $smtpProfile = null)
    {
        if (!self::dependenciesAvailable()) {
            return null;
        }

        self::$lastError = '';
        $mailer = new PHPMailer(true);
        $mailer->CharSet = 'UTF-8';

        self::configureDebugOutput($mailer);

        $transport = $transportOverride !== null
            ? self::normalizeMailer($transportOverride)
            : self::resolveConfiguredMailer();
        if ($transport === 'auto') {
            $queue = self::buildTransportQueue($transport);
            foreach ($queue as $candidate) {
                if ($candidate !== 'preview') {
                    $transport = $candidate;
                    break;
                }
            }
        }
        $smtpUsername = '';

        if ($transport === 'mail') {
            $mailer->isMail();
        } elseif ($transport === 'sendmail') {
            $mailer->isSendmail();
        } else {
            $smtpProfile = $smtpProfile ?: self::buildBaseSmtpProfile();
            $smtpTimeout = max(5, (int) ($smtpProfile['timeout'] ?? self::resolveSmtpTimeout(self::isProductionEnvironment() ? 8 : 30)));
            self::ensureRuntimeBudget($smtpTimeout);
            $mailer->isSMTP();
            $mailer->Timeout = $smtpTimeout;
            $mailer->getSMTPInstance()->Timelimit = max(5, $smtpTimeout + 2);
            $mailer->Host = trim((string) ($smtpProfile['host'] ?? 'smtp.gmail.com'));
            $mailer->SMTPAuth = !empty($smtpProfile['auth']);
            $smtpUsername = trim((string) ($smtpProfile['username'] ?? ''));
            $mailer->Username = $smtpUsername;
            $mailer->Password = (string) ($smtpProfile['password'] ?? '');
            $mailer->SMTPSecure = (string) ($smtpProfile['encryption'] ?? '');
            $mailer->Port = max(1, (int) ($smtpProfile['port'] ?? ($mailer->SMTPSecure === PHPMailer::ENCRYPTION_SMTPS ? 465 : 587)));
            $mailer->SMTPAutoTLS = !empty($smtpProfile['auto_tls']);

            if (!empty($smtpProfile['allow_self_signed'])) {
                $mailer->SMTPOptions = [
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true,
                    ],
                ];
            }

            $heloDomain = trim((string) ($smtpProfile['helo'] ?? ''));
            if ($heloDomain !== '') {
                $mailer->Helo = $heloDomain;
            }
        }

        $fromEmail = self::buildDefaultFromEmail($smtpUsername, $transport);
        $fromName = trim((string) self::getEnvValue('MAIL_FROM_NAME', self::getEnvValue('SMTP_FROM_NAME', 'Reclaim System')));
        if ($fromName === '') {
            $fromName = 'Reclaim System';
        }

        $hostname = self::buildHostname();
        if ($hostname !== '') {
            $mailer->Hostname = $hostname;
        }

        $mailer->setFrom($fromEmail, $fromName);

        $returnPath = self::buildReturnPathEmail($fromEmail);
        if ($returnPath !== '') {
            $mailer->Sender = $returnPath;
        }

        $replyToEmail = trim((string) self::getEnvValue('MAIL_REPLY_TO_EMAIL', ''));
        if ($replyToEmail !== '' && filter_var($replyToEmail, FILTER_VALIDATE_EMAIL)) {
            $replyToName = trim((string) self::getEnvValue('MAIL_REPLY_TO_NAME', $fromName));
            $mailer->addReplyTo($replyToEmail, $replyToName !== '' ? $replyToName : $fromName);
        }

        return $mailer;
    }

    private static function sendWithTransport($transport, $to, $subject, $body, &$errorMessage = '')
    {
        if ($transport === 'preview') {
            return self::writePreview($to, $subject, $body, $errorMessage);
        }

        $smtpProfiles = $transport === 'smtp' ? self::buildSmtpProfiles() : [null];
        $attemptErrors = [];

        foreach ($smtpProfiles as $smtpProfile) {
            $mail = null;
            $attemptLabel = $transport;

            if (is_array($smtpProfile)) {
                $attemptLabel = self::formatSmtpProfileLabel($smtpProfile);
            }

            try {
                $mail = self::init($transport, $smtpProfile);
                if (!$mail) {
                    $profileError = self::$lastError;
                    if ($profileError !== '') {
                        $attemptErrors[] = $attemptLabel . ': ' . $profileError;
                    }
                    continue;
                }

                if ($mail->Mailer === 'smtp' && $mail->SMTPAuth && (empty($mail->Username) || empty($mail->Password))) {
                    $profileError = 'SMTP credentials are not configured.';
                    $attemptErrors[] = $attemptLabel . ': ' . $profileError;
                    continue;
                }

                $mail->clearAllRecipients();
                $mail->clearAttachments();
                $mail->Subject = $subject;
                $mail->isHTML(true);
                $mail->Body = $body;
                $mail->AltBody = strip_tags($body);
                $mail->addAddress($to);

                $sent = $mail->send();
                if ($sent) {
                    self::$lastTransport = $transport === 'smtp' ? $attemptLabel : $transport;
                    return true;
                }

                $profileError = trim((string) ($mail->ErrorInfo ?? 'Unknown mail delivery error.'));
                if ($profileError !== '') {
                    $attemptErrors[] = $attemptLabel . ': ' . $profileError;
                }
            } catch (\Throwable $exception) {
                $profileError = trim((string) (($mail && $mail->ErrorInfo !== '') ? $mail->ErrorInfo : $exception->getMessage()));
                if ($profileError !== '') {
                    $attemptErrors[] = $attemptLabel . ': ' . $profileError;
                }
                self::logDiagnostic('Mail attempt failed via ' . $attemptLabel . ' for ' . $to . ': ' . $profileError);
            }
        }

        $errorMessage = implode(' | ', $attemptErrors);
        return false;
    }

    private static function buildTransportQueue($preferredTransport)
    {
        $preferred = self::normalizeMailer($preferredTransport);

        if ($preferred === 'preview') {
            return ['preview'];
        }

        if ($preferred === 'auto') {
            $queue = self::isProductionEnvironment()
                ? ['mail', 'sendmail', 'smtp']
                : ['smtp', 'mail', 'sendmail'];
        } elseif ($preferred === 'smtp') {
            $queue = ['smtp'];
            if (self::shouldFallbackToMail()) {
                $queue[] = 'mail';
            }
            if (self::shouldFallbackToSendmail()) {
                $queue[] = 'sendmail';
            }
        } elseif ($preferred === 'sendmail') {
            $queue = ['sendmail'];
            if (self::shouldFallbackToMail()) {
                $queue[] = 'mail';
            }
        } else {
            $queue = ['mail'];
            if (self::shouldFallbackToSendmail()) {
                $queue[] = 'sendmail';
            }
        }

        if (self::shouldFallbackToPreview()) {
            $queue[] = 'preview';
        }

        return array_values(array_unique($queue));
    }

    public static function sendNotification($to, $subject, $body)
    {
        self::resetLastState();
        $preferredTransport = self::resolveConfiguredMailer();
        $transportQueue = self::buildTransportQueue($preferredTransport);
        $attemptErrors = [];

        foreach ($transportQueue as $index => $transport) {
            $transportError = '';

            if (self::sendWithTransport($transport, $to, $subject, $body, $transportError)) {
                self::$lastError = '';
                return true;
            }

            $transportError = trim((string) $transportError);
            if ($transportError !== '') {
                $attemptErrors[] = $transport . ': ' . $transportError;
            }

            if ($index < count($transportQueue) - 1) {
                self::logDiagnostic(
                    'Email delivery failed for ' . $to . ' via ' . $transport
                    . ($transportError !== '' ? ' (' . $transportError . ')' : '')
                    . ', trying next transport.'
                );
            }
        }

        self::$lastError = implode(' | ', $attemptErrors);
        if (self::$lastError === '') {
            self::$lastError = 'Unknown mail delivery error.';
        }

        self::logDiagnostic('Email failed to ' . $to . ': ' . self::$lastError);
        return false;
    }

    public static function getLastError()
    {
        return self::$lastError;
    }

    public static function getLastTransport()
    {
        return self::$lastTransport;
    }

    public static function getLastPreviewId()
    {
        return self::$lastPreviewId;
    }

    public static function getConfiguredMailer()
    {
        return self::resolveConfiguredMailer();
    }

    public static function testConnection()
    {
        self::resetLastState();
        $transportQueue = self::buildTransportQueue(self::resolveConfiguredMailer());
        if ($transportQueue === ['preview']) {
            self::$lastTransport = 'preview';
            return true;
        }

        $testTransport = 'smtp';
        foreach ($transportQueue as $candidate) {
            if ($candidate !== 'preview') {
                $testTransport = $candidate;
                break;
            }
        }

        try {
            $mail = self::init($testTransport);
            if (!$mail) {
                return false;
            }

            if ($mail->Mailer !== 'smtp') {
                self::$lastTransport = $testTransport;
                return true;
            }

            if ($mail->SMTPAuth && (empty($mail->Username) || empty($mail->Password))) {
                self::$lastError = 'SMTP credentials are not configured.';
                return false;
            }
        } catch (\Throwable $exception) {
            self::$lastError = trim((string) $exception->getMessage());
            return false;
        }

        $attemptErrors = [];
        foreach (self::buildSmtpProfiles() as $smtpProfile) {
            $profileLabel = self::formatSmtpProfileLabel($smtpProfile);

            try {
                $mail = self::init($testTransport, $smtpProfile);
                if (!$mail) {
                    if (self::$lastError !== '') {
                        $attemptErrors[] = $profileLabel . ': ' . self::$lastError;
                    }
                    continue;
                }

                $mail->smtpConnect();
                $mail->smtpClose();
                self::$lastTransport = $profileLabel;
                self::$lastError = '';
                return true;
            } catch (\Throwable $exception) {
                $profileError = trim((string) (($mail->ErrorInfo ?? '') !== '' ? $mail->ErrorInfo : $exception->getMessage()));
                if ($profileError !== '') {
                    $attemptErrors[] = $profileLabel . ': ' . $profileError;
                }
            }
        }

        if ($attemptErrors !== []) {
            self::$lastError = implode(' | ', $attemptErrors);
        }

        return false;
    }
}
?>
