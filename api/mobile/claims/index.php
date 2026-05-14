<?php
require_once __DIR__ . '/../bootstrap.php';

mobileApiRequireMethod(['GET']);

$auth = mobileApiAuthenticate($mobileApiDb);
$userId = (int) $auth['user']['user_id'];

try {
    $stmt = $mobileApiDb->prepare("
        SELECT
            c.*,
            i.title AS item_title,
            i.description AS item_description,
            i.status AS item_status,
            i.image_url,
            i.category,
            i.found_location,
            reporter.name AS reporter_name
        FROM claim_requests c
        JOIN items i ON i.item_id = c.item_id
        LEFT JOIN users reporter ON reporter.user_id = i.reported_by
        WHERE c.claimant_id = ?
        ORDER BY c.created_at DESC
    ");
    $stmt->execute([$userId]);
    $claims = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stats = [
        'total' => count($claims),
        'pending' => 0,
        'approved' => 0,
        'rejected' => 0,
        'completed' => 0,
        'cancelled' => 0,
    ];

    foreach ($claims as $claim) {
        $status = (string) ($claim['status'] ?? '');
        if (isset($stats[$status])) {
            $stats[$status]++;
        }
    }

    mobileApiSuccess([
        'stats' => $stats,
        'claims' => array_map('mobileApiClaimPayload', $claims),
    ], 'Claims loaded.');
} catch (PDOException $exception) {
    error_log('Mobile claims index error: ' . $exception->getMessage());
    mobileApiError('Unable to load claims.', 500, null, 'claims_failed');
}
