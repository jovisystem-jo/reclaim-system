<?php
require_once __DIR__ . '/../bootstrap.php';

mobileApiRequireMethod(['POST']);

$auth = mobileApiAuthenticate($mobileApiDb);
$userId = (int) $auth['user']['user_id'];
$input = mobileApiTrimmed(mobileApiRequestData());
$claimId = (int) ($input['claim_id'] ?? 0);

if ($claimId <= 0) {
    mobileApiError('Claim ID is required.', 422, ['claim_id' => 'Claim ID is required.'], 'validation_failed');
}

try {
    $stmt = $mobileApiDb->prepare("
        SELECT c.*, i.item_id, i.status AS item_status
        FROM claim_requests c
        JOIN items i ON i.item_id = c.item_id
        WHERE c.claim_id = ? AND c.claimant_id = ? AND c.status = 'approved'
        LIMIT 1
    ");
    $stmt->execute([$claimId, $userId]);
    $claim = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$claim) {
        mobileApiError('Only approved claims can be completed.', 422, null, 'invalid_claim_state');
    }

    $mobileApiDb->beginTransaction();
    $itemStmt = $mobileApiDb->prepare("UPDATE items SET status = 'returned' WHERE item_id = ?");
    $itemStmt->execute([(int) $claim['item_id']]);

    $claimStmt = $mobileApiDb->prepare("UPDATE claim_requests SET status = 'completed' WHERE claim_id = ?");
    $claimStmt->execute([$claimId]);
    $mobileApiDb->commit();

    mobileApiSuccess(null, 'Item successfully reclaimed.');
} catch (PDOException $exception) {
    if ($mobileApiDb->inTransaction()) {
        $mobileApiDb->rollBack();
    }
    error_log('Mobile complete claim error: ' . $exception->getMessage());
    mobileApiError('Unable to complete reclaim.', 500, null, 'complete_claim_failed');
}
