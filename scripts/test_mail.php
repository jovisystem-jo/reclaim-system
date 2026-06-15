<?php
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

error_reporting(E_ALL);
ini_set('display_errors', '1');
@set_time_limit(30);
while (ob_get_level() > 0) {
    ob_end_flush();
}
ob_implicit_flush(true);

require_once __DIR__ . '/../config/mail.php';

$recipient = $argv[1] ?? '';
if ($recipient === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php scripts/test_mail.php you@example.com\n");
    exit(1);
}

fwrite(STDOUT, "Preparing mail configuration...\n");
$mailer = MailConfig::init();
if (!$mailer) {
    $error = MailConfig::getLastError();
    fwrite(STDERR, "Mailer initialization failed: " . ($error !== '' ? $error : 'Unknown error') . "\n");
    exit(1);
}

fwrite(STDOUT, "Mailer: {$mailer->Mailer}\n");
fwrite(STDOUT, "From: {$mailer->From}\n");
if ($mailer->Mailer === 'smtp') {
    fwrite(STDOUT, "SMTP host: {$mailer->Host}\n");
    fwrite(STDOUT, "SMTP port: {$mailer->Port}\n");
    fwrite(STDOUT, "SMTP auth: " . ($mailer->SMTPAuth ? 'on' : 'off') . "\n");
    fwrite(STDOUT, "SMTP user: {$mailer->Username}\n");
}
fwrite(STDOUT, "Sending test message to {$recipient}...\n");

$subject = 'Reclaim System mail test';
$body = '
    <div style="font-family: Arial, sans-serif; max-width: 560px; margin: 0 auto; color: #1f2937;">
        <h2 style="margin-bottom: 16px; color: #111827;">Mail test successful</h2>
        <p style="margin-bottom: 16px;">This is a test email from Reclaim System.</p>
        <p style="margin-bottom: 0;">If you received this message, the current mail configuration is working.</p>
    </div>
';

if (MailConfig::sendNotification($recipient, $subject, $body)) {
    fwrite(STDOUT, "Mail sent successfully to {$recipient}\n");
    exit(0);
}

$error = MailConfig::getLastError();
fwrite(STDERR, "Mail send failed: " . ($error !== '' ? $error : 'Unknown error') . "\n");
exit(1);
