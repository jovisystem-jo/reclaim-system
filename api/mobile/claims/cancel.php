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
        SELECT *
        FROM claim_requests
        WHERE claim_id = ? AND claimant_id = ? AND status = 'pending'
        LIMIT 1
    ");
    $stmt->execute([$claimId, $userId]);
    $claim = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$claim) {
        mobileApiError('Only pending claims can be cancelled.', 422, null, 'invalid_claim_state');
    }

    $stmt = $mobileApiDb->prepare("UPDATE claim_requests SET status = 'cancelled' WHERE claim_id = ?");
    $stmt->execute([$claimId]);

    mobileApiSuccess(null, 'Claim cancelled successfully.');
} catch (PDOException $exception) {
    error_log('Mobile cancel claim error: ' . $exception->getMessage());
    mobileApiError('Unable to cancel claim.', 500, null, 'cancel_claim_failed');
}
