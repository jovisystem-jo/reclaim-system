<?php
require_once __DIR__ . '/../bootstrap.php';

mobileApiRequireMethod(['GET']);

$auth = mobileApiAuthenticate($mobileApiDb);
$userId = (int) $auth['user']['user_id'];
$filter = trim((string) ($_GET['filter'] ?? 'all'));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(50, max(1, (int) ($_GET['per_page'] ?? 20)));
$offset = ($page - 1) * $perPage;

if (!in_array($filter, ['all', 'read', 'unread'], true)) {
    mobileApiError('Invalid notification filter.', 422, ['filter' => 'Invalid notification filter.'], 'validation_failed');
}

$where = 'WHERE user_id = ?';
$params = [$userId];

if ($filter === 'read') {
    $where .= ' AND is_read = 1';
} elseif ($filter === 'unread') {
    $where .= ' AND is_read = 0';
}

try {
    $countStmt = $mobileApiDb->prepare("SELECT COUNT(*) FROM notifications $where");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $stmt = $mobileApiDb->prepare("
        SELECT *
        FROM notifications
        $where
        ORDER BY created_at DESC
        LIMIT ? OFFSET ?
    ");
    $bindParams = array_merge($params, [$perPage, $offset]);
    $stmt->execute($bindParams);
    $notifications = array_map('mobileApiNotificationPayload', $stmt->fetchAll(PDO::FETCH_ASSOC));

    $unreadStmt = $mobileApiDb->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $unreadStmt->execute([$userId]);
    $unreadCount = (int) $unreadStmt->fetchColumn();

    mobileApiSuccess([
        'notifications' => $notifications,
        'pagination' => mobileApiPagination($total, $page, $perPage),
        'unread_count' => $unreadCount,
    ], 'Notifications loaded.');
} catch (PDOException $exception) {
    error_log('Mobile notifications error: ' . $exception->getMessage());
    mobileApiError('Unable to load notifications.', 500, null, 'notifications_failed');
}
