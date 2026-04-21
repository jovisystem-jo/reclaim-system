<?php
require_once 'config/database.php';
require_once 'includes/auth.php';
requireAdmin();
require_once 'config/mail.php';

if (app_is_production()) {
    // Security: disable diagnostic pages in production.
    http_response_code(404);
    exit('Not found');
}

echo "<h1>Email Configuration Test</h1>";

// Test 1: Check if PHPMailer is installed
$vendorAutoload = __DIR__ . '/vendor/autoload.php';
echo "<p>PHPMailer installed: " . (file_exists($vendorAutoload) ? '✅ Yes' : '❌ No') . "</p>";

// Test 2: Check SMTP connection
echo "<h3>Testing SMTP Connection...</h3>";
try {
    $testResult = MailConfig::testConnection();
    if ($testResult) {
        echo "<p style='color:green'>✅ SMTP connection successful!</p>";
    } else {
        echo "<p style='color:red'>❌ SMTP connection failed. Check your credentials.</p>";
    }
} catch (Exception $e) {
    error_log("Email test error: " . $e->getMessage());
    echo "<p style='color:red'>Email test failed. Check the server error log.</p>";
}

// Test 3: Send a test email
echo "<h3>Sending Test Email...</h3>";
$testEmail = 'your-test-email@gmail.com'; // Change to your email
$subject = 'Test Email from Reclaim System';
$body = '<h1>Test</h1><p>This is a test email to verify configuration.</p>';

$result = MailConfig::sendNotification($testEmail, $subject, $body);

if ($result) {
    echo "<p style='color:green'>✅ Test email sent to $testEmail!</p>";
} else {
    echo "<p style='color:red'>❌ Failed to send test email. Check error logs.</p>";
}

// Test 4: Show email logs
$db = Database::getInstance()->getConnection();
$stmt = $db->prepare("SELECT * FROM email_logs ORDER BY log_id DESC LIMIT 5");
$stmt->execute();
$logs = $stmt->fetchAll();

echo "<h3>Recent Email Logs</h3>";
if (empty($logs)) {
    echo "<p>No email logs found.</p>";
} else {
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>To</th><th>Subject</th><th>Status</th><th>Time</th></tr>";
    foreach ($logs as $log) {
        $color = $log['status'] == 'sent' ? 'green' : 'red';
        echo "<tr>";
        echo "<td>{$log['log_id']}</td>";
        echo "<td>" . htmlspecialchars($log['recipient_email']) . "</td>";
        echo "<td>" . htmlspecialchars($log['subject']) . "</td>";
        echo "<td style='color:$color'>{$log['status']}</td>";
        echo "<td>{$log['sent_at']}</td>";
        echo "</tr>";
    }
    echo "</table>";
}
?>
