<?php
require_once __DIR__ . '/env.php';

class SmsConfig
{
    private static $lastError = '';
    private static $lastTransport = '';
    private static $lastPreviewId = '';

    private static function isProductionEnvironment()
    {
        return strtolower(trim((string) self::getEnvValue('APP_ENV', 'development'))) === 'production';
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
            $logDirectory . DIRECTORY_SEPARATOR . 'sms.log',
            $message . PHP_EOL,
            FILE_APPEND
        );
    }

    private static function getEnvValue($name, $default = '')
    {
        try {
            EnvLoader::load(__DIR__ . '/../.env');
        } catch (Throwable $exception) {
            self::logDiagnostic('Unable to load SMS environment settings: ' . $exception->getMessage());
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

    private static function normalizeProvider($provider)
    {
        $normalized = strtolower(trim((string) $provider));

        if (in_array($normalized, ['preview', 'file', 'log'], true)) {
            return 'preview';
        }

        if (in_array($normalized, ['off', 'none', 'disabled'], true)) {
            return 'disabled';
        }

        return 'twilio';
    }

    private static function resolveConfiguredProvider()
    {
        $configured = trim((string) self::getEnvValue('SMS_PROVIDER', ''));
        if ($configured !== '') {
            return self::normalizeProvider($configured);
        }

        $twilioSid = trim((string) self::getEnvValue('TWILIO_ACCOUNT_SID', self::getEnvValue('SMS_TWILIO_ACCOUNT_SID', '')));
        $twilioToken = trim((string) self::getEnvValue('TWILIO_AUTH_TOKEN', self::getEnvValue('SMS_TWILIO_AUTH_TOKEN', '')));
        $twilioFrom = trim((string) self::getEnvValue('TWILIO_FROM_NUMBER', self::getEnvValue('SMS_TWILIO_FROM_NUMBER', '')));
        $twilioMessagingServiceSid = trim((string) self::getEnvValue('TWILIO_MESSAGING_SERVICE_SID', self::getEnvValue('SMS_TWILIO_MESSAGING_SERVICE_SID', '')));

        if ($twilioSid !== '' && $twilioToken !== '' && ($twilioFrom !== '' || $twilioMessagingServiceSid !== '')) {
            return 'twilio';
        }

        return self::isProductionEnvironment() ? 'disabled' : 'preview';
    }

    private static function shouldFallbackToPreview()
    {
        return self::getEnvBoolean('SMS_FALLBACK_TO_PREVIEW', !self::isProductionEnvironment());
    }

    private static function previewDirectory()
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'sms-previews';
    }

    private static function resetLastState()
    {
        self::$lastError = '';
        self::$lastTransport = '';
        self::$lastPreviewId = '';
    }

    private static function writePreview($to, $body, &$errorMessage = '')
    {
        $previewDir = self::previewDirectory();

        if (!is_dir($previewDir) && !@mkdir($previewDir, 0755, true) && !is_dir($previewDir)) {
            $errorMessage = 'Unable to create the local SMS preview folder.';
            return false;
        }

        try {
            $previewId = gmdate('Ymd_His') . '_' . bin2hex(random_bytes(6));
        } catch (Throwable $exception) {
            $errorMessage = 'Unable to generate the local SMS preview identifier.';
            return false;
        }

        $payload = [
            'id' => $previewId,
            'transport' => 'preview',
            'created_at' => date('c'),
            'to' => $to,
            'body' => $body,
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $errorMessage = 'Unable to encode the local SMS preview.';
            return false;
        }

        $previewPath = $previewDir . DIRECTORY_SEPARATOR . $previewId . '.json';
        if (@file_put_contents($previewPath, $json) === false) {
            $errorMessage = 'Unable to save the local SMS preview.';
            return false;
        }

        self::$lastTransport = 'preview';
        self::$lastPreviewId = $previewId;
        return true;
    }

    private static function buildTransportQueue($preferredProvider)
    {
        $preferred = self::normalizeProvider($preferredProvider);

        if ($preferred === 'preview') {
            return ['preview'];
        }

        if ($preferred === 'disabled') {
            return self::shouldFallbackToPreview() ? ['preview'] : ['disabled'];
        }

        $queue = ['twilio'];
        if (self::shouldFallbackToPreview()) {
            $queue[] = 'preview';
        }

        return array_values(array_unique($queue));
    }

    private static function twilioEndpoint($accountSid)
    {
        $region = strtolower(trim((string) self::getEnvValue('TWILIO_REGION', self::getEnvValue('SMS_TWILIO_REGION', ''))));
        $host = 'api.twilio.com';

        if ($region === 'ie1') {
            $host = 'api.dublin.ie1.twilio.com';
        }

        return 'https://' . $host . '/2010-04-01/Accounts/' . rawurlencode($accountSid) . '/Messages.json';
    }

    private static function sendViaTwilio($to, $body, &$errorMessage = '')
    {
        if (!function_exists('curl_init')) {
            $errorMessage = 'cURL is not available on the server.';
            return false;
        }

        $accountSid = trim((string) self::getEnvValue('TWILIO_ACCOUNT_SID', self::getEnvValue('SMS_TWILIO_ACCOUNT_SID', '')));
        $authToken = trim((string) self::getEnvValue('TWILIO_AUTH_TOKEN', self::getEnvValue('SMS_TWILIO_AUTH_TOKEN', '')));
        $fromNumber = trim((string) self::getEnvValue('TWILIO_FROM_NUMBER', self::getEnvValue('SMS_TWILIO_FROM_NUMBER', '')));
        $messagingServiceSid = trim((string) self::getEnvValue('TWILIO_MESSAGING_SERVICE_SID', self::getEnvValue('SMS_TWILIO_MESSAGING_SERVICE_SID', '')));

        if ($accountSid === '' || $authToken === '') {
            $errorMessage = 'Twilio credentials are not configured.';
            return false;
        }

        if ($fromNumber === '' && $messagingServiceSid === '') {
            $errorMessage = 'Twilio sender configuration is missing.';
            return false;
        }

        $payload = [
            'To' => $to,
            'Body' => $body,
        ];

        if ($messagingServiceSid !== '') {
            $payload['MessagingServiceSid'] = $messagingServiceSid;
        } else {
            $payload['From'] = $fromNumber;
        }

        $curlHandle = curl_init(self::twilioEndpoint($accountSid));
        if ($curlHandle === false) {
            $errorMessage = 'Unable to initialize the SMS request.';
            return false;
        }

        curl_setopt_array($curlHandle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => max(5, (int) self::getEnvValue('SMS_TIMEOUT', 15)),
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $accountSid . ':' . $authToken,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
            ],
        ]);

        $rawResponse = curl_exec($curlHandle);
        $curlError = curl_error($curlHandle);
        $httpStatus = (int) curl_getinfo($curlHandle, CURLINFO_RESPONSE_CODE);
        curl_close($curlHandle);

        if ($rawResponse === false) {
            $errorMessage = $curlError !== '' ? $curlError : 'The SMS request failed before a response was received.';
            return false;
        }

        $responseData = json_decode($rawResponse, true);
        if ($httpStatus >= 200 && $httpStatus < 300) {
            self::$lastTransport = 'twilio';
            return true;
        }

        $twilioMessage = is_array($responseData) ? trim((string) ($responseData['message'] ?? '')) : '';
        $errorMessage = $twilioMessage !== '' ? $twilioMessage : ('Twilio returned HTTP ' . $httpStatus . '.');
        return false;
    }

    private static function sendWithProvider($provider, $to, $body, &$errorMessage = '')
    {
        if ($provider === 'preview') {
            return self::writePreview($to, $body, $errorMessage);
        }

        if ($provider === 'disabled') {
            $errorMessage = 'SMS delivery is not configured on this server.';
            return false;
        }

        return self::sendViaTwilio($to, $body, $errorMessage);
    }

    public static function sendMessage($to, $body)
    {
        self::resetLastState();

        $to = trim((string) $to);
        $body = trim((string) $body);

        if ($to === '' || $body === '') {
            self::$lastError = 'SMS destination and message body are required.';
            return false;
        }

        $preferredProvider = self::resolveConfiguredProvider();
        $transportQueue = self::buildTransportQueue($preferredProvider);
        $attemptErrors = [];

        foreach ($transportQueue as $index => $provider) {
            $providerError = '';

            if (self::sendWithProvider($provider, $to, $body, $providerError)) {
                self::$lastError = '';
                return true;
            }

            $providerError = trim((string) $providerError);
            if ($providerError !== '') {
                $attemptErrors[] = $provider . ': ' . $providerError;
            }

            if ($index < count($transportQueue) - 1) {
                self::logDiagnostic(
                    'SMS delivery failed for ' . $to . ' via ' . $provider
                    . ($providerError !== '' ? ' (' . $providerError . ')' : '')
                    . ', trying next transport.'
                );
            }
        }

        self::$lastError = implode(' | ', $attemptErrors);
        if (self::$lastError === '') {
            self::$lastError = 'Unknown SMS delivery error.';
        }

        self::logDiagnostic('SMS failed to ' . $to . ': ' . self::$lastError);
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

    public static function getConfiguredProvider()
    {
        return self::resolveConfiguredProvider();
    }
}
?>
