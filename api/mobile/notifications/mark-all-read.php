<?php
require_once __DIR__ . '/../bootstrap.php';

mobileApiRequireMethod(['POST']);

$auth = mobileApiAuthenticate($mobileApiDb);
$userId = (int) $auth['user']['user_id'];

try {
    $stmt = $mobileApiDb->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?');
    $stmt->execute([$userId]);

    mobileApiSuccess(null, 'All notifications marked as read.');
} catch (PDOException $exception) {
    error_log('Mobile mark all notifications read error: ' . $exception->getMessage());
    mobileApiError('Unable to update notifications.', 500, null, 'notification_update_failed');
}
