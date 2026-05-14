<?php
require_once __DIR__ . '/../bootstrap.php';

mobileApiRequireMethod(['POST']);

$auth = mobileApiAuthenticate($mobileApiDb);
$userId = (int) $auth['user']['user_id'];
$input = mobileApiTrimmed(mobileApiRequestData());
$notificationId = (int) ($input['notification_id'] ?? 0);

if ($notificationId <= 0) {
    mobileApiError('Notification ID is required.', 422, ['notification_id' => 'Notification ID is required.'], 'validation_failed');
}

try {
    $stmt = $mobileApiDb->prepare('UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?');
    $stmt->execute([$notificationId, $userId]);

    mobileApiSuccess(null, 'Notification marked as read.');
} catch (PDOException $exception) {
    error_log('Mobile mark notification read error: ' . $exception->getMessage());
    mobileApiError('Unable to update notification.', 500, null, 'notification_update_failed');
}
