<?php
require_once __DIR__ . '/bootstrap.php';

mobileApiRequireMethod(['GET']);

$auth = mobileApiAuthenticate($mobileApiDb);
$userId = (int) $auth['user']['user_id'];

try {
    $stmt = $mobileApiDb->prepare("
        SELECT
            (SELECT COUNT(*) FROM items WHERE reported_by = ?) AS my_reports,
            (SELECT COUNT(*) FROM claim_requests WHERE claimant_id = ?) AS my_claims,
            (SELECT COUNT(*) FROM claim_requests WHERE claimant_id = ? AND status = 'approved') AS approved_claims,
            (SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0) AS unread_notifications
    ");
    $stmt->execute([$userId, $userId, $userId, $userId]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $stmt = $mobileApiDb->prepare("
        SELECT *
        FROM notifications
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$userId]);
    $notifications = array_map('mobileApiNotificationPayload', $stmt->fetchAll(PDO::FETCH_ASSOC));

    $stmt = $mobileApiDb->prepare("
        SELECT i.*, COUNT(c.claim_id) AS claim_count
        FROM items i
        LEFT JOIN claim_requests c ON c.item_id = i.item_id
        WHERE i.reported_by = ?
        GROUP BY i.item_id
        ORDER BY i.reported_date DESC
        LIMIT 5
    ");
    $stmt->execute([$userId]);
    $recentItems = array_map('mobileApiItemPayload', $stmt->fetchAll(PDO::FETCH_ASSOC));

    $stmt = $mobileApiDb->prepare("
        SELECT
            c.*,
            i.title AS item_title,
            i.description AS item_description,
            i.status AS item_status,
            i.category,
            i.found_location,
            i.image_url,
            u.name AS reporter_name
        FROM claim_requests c
        JOIN items i ON i.item_id = c.item_id
        LEFT JOIN users u ON u.user_id = i.reported_by
        WHERE c.claimant_id = ?
        ORDER BY c.created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$userId]);
    $recentClaims = array_map('mobileApiClaimPayload', $stmt->fetchAll(PDO::FETCH_ASSOC));

    mobileApiSuccess([
        'user' => mobileApiUserPayload($auth['user']),
        'stats' => [
            'my_reports' => (int) ($stats['my_reports'] ?? 0),
            'my_claims' => (int) ($stats['my_claims'] ?? 0),
            'approved_claims' => (int) ($stats['approved_claims'] ?? 0),
            'unread_notifications' => (int) ($stats['unread_notifications'] ?? 0),
        ],
        'recent_notifications' => $notifications,
        'recent_items' => $recentItems,
        'recent_claims' => $recentClaims,
    ], 'Dashboard loaded.');
} catch (PDOException $exception) {
    error_log('Mobile dashboard error: ' . $exception->getMessage());
    mobileApiError('Unable to load dashboard.', 500, null, 'dashboard_failed');
}
