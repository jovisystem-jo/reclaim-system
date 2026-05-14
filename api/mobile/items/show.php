<?php
require_once __DIR__ . '/../bootstrap.php';

mobileApiRequireMethod(['GET']);

$auth = mobileApiAuthenticate($mobileApiDb);
$userId = (int) $auth['user']['user_id'];
$itemId = (int) ($_GET['id'] ?? 0);

if ($itemId <= 0) {
    mobileApiError('Item ID is required.', 422, ['id' => 'Item ID is required.'], 'validation_failed');
}

try {
    $stmt = $mobileApiDb->prepare("
        SELECT
            i.*,
            reporter.name AS reporter_name,
            reporter.profile_image AS reporter_profile_image,
            COUNT(DISTINCT claims.claim_id) AS claim_count,
            MAX(CASE WHEN claims.claimant_id = ? THEN 1 ELSE 0 END) AS user_has_claimed
        FROM items i
        LEFT JOIN users reporter ON reporter.user_id = i.reported_by
        LEFT JOIN claim_requests claims ON claims.item_id = i.item_id
        WHERE i.item_id = ?
        GROUP BY i.item_id
        LIMIT 1
    ");
    $stmt->execute([$userId, $itemId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        mobileApiError('Item not found.', 404, null, 'item_not_found');
    }

    $referenceTokens = extractItemTextMatchTokens($item, ['title', 'description', 'brand', 'color', 'found_location']);
    $similarStmt = $mobileApiDb->prepare("
        SELECT *
        FROM items
        WHERE category = ? AND item_id != ? AND status != 'returned'
        ORDER BY reported_date DESC
        LIMIT 20
    ");
    $similarStmt->execute([$item['category'], $itemId]);
    $similarItems = $similarStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($similarItems as &$similarItem) {
        $similarItem['_similarity_score'] = calculateJaccardSimilarity(
            $referenceTokens,
            extractItemTextMatchTokens($similarItem, ['title', 'description', 'brand', 'color', 'found_location'])
        );
    }
    unset($similarItem);

    usort($similarItems, static function ($left, $right) {
        $leftScore = (float) ($left['_similarity_score'] ?? 0);
        $rightScore = (float) ($right['_similarity_score'] ?? 0);

        if ($leftScore === $rightScore) {
            return strcmp((string) ($right['reported_date'] ?? ''), (string) ($left['reported_date'] ?? ''));
        }

        return $rightScore <=> $leftScore;
    });

    $similarItems = array_slice($similarItems, 0, 3);

    $payload = mobileApiItemPayload($item);
    $payload['can_claim'] = ($item['status'] ?? '') === 'found'
        && !(bool) ($payload['user_has_claimed'] ?? false)
        && (int) ($item['reported_by'] ?? 0) !== $userId;
    $payload['reported_by_current_user'] = (int) ($item['reported_by'] ?? 0) === $userId;
    $payload['similar_items'] = array_map('mobileApiItemPayload', $similarItems);

    mobileApiSuccess([
        'item' => $payload,
    ], 'Item loaded.');
} catch (PDOException $exception) {
    error_log('Mobile item show error: ' . $exception->getMessage());
    mobileApiError('Unable to load item.', 500, null, 'item_failed');
}
