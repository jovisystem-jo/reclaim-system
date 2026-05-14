<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../../includes/notification.php';

mobileApiRequireMethod(['POST']);

$auth = mobileApiAuthenticate($mobileApiDb);
$userId = (int) $auth['user']['user_id'];
$input = mobileApiTrimmed(mobileApiRequestData());

$itemId = (int) ($input['item_id'] ?? 0);
$claimantDescription = (string) ($input['claimant_description'] ?? '');

$errors = [];
if ($itemId <= 0) {
    $errors['item_id'] = 'Item ID is required.';
}
if ($claimantDescription === '') {
    $errors['claimant_description'] = 'Claim description is required.';
}

if (!empty($errors)) {
    mobileApiError('Please correct the highlighted fields.', 422, $errors, 'validation_failed');
}

$proofImagePath = '';
if (isset($_FILES['proof_image']) && ($_FILES['proof_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    $upload = secure_image_upload($_FILES['proof_image'], __DIR__ . '/../../../assets/uploads/proofs', 'assets/uploads/proofs');
    if (!$upload['success']) {
        mobileApiError($upload['message'] ?? 'Proof image upload failed.', 422, ['proof_image' => $upload['message'] ?? 'Proof image upload failed.'], 'upload_failed');
    }
    $proofImagePath = (string) ($upload['path'] ?? '');
}

try {
    $stmt = $mobileApiDb->prepare('SELECT * FROM items WHERE item_id = ? LIMIT 1');
    $stmt->execute([$itemId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        mobileApiError('Item not found.', 404, null, 'item_not_found');
    }

    if ((int) ($item['reported_by'] ?? 0) === $userId) {
        mobileApiError('You cannot claim your own item.', 403, null, 'claim_own_item');
    }

    if (($item['status'] ?? '') !== 'found') {
        mobileApiError('Only found items can be claimed.', 422, null, 'invalid_item_status');
    }

    $stmt = $mobileApiDb->prepare('SELECT COUNT(*) FROM claim_requests WHERE item_id = ? AND claimant_id = ?');
    $stmt->execute([$itemId, $userId]);
    if ((int) $stmt->fetchColumn() > 0) {
        mobileApiError('You have already submitted a claim for this item.', 409, null, 'already_claimed');
    }

    $stmt = $mobileApiDb->prepare("
        INSERT INTO claim_requests (item_id, claimant_id, claimant_description, proof_image_url, status, created_at)
        VALUES (?, ?, ?, ?, 'pending', NOW())
    ");
    $stmt->execute([$itemId, $userId, $claimantDescription, $proofImagePath]);
    $claimId = (int) $mobileApiDb->lastInsertId();

    $notification = new NotificationSystem();
    $notification->newClaimSubmitted($claimId, $itemId, $userId);

    $claimStmt = $mobileApiDb->prepare("
        SELECT
            c.*,
            i.title AS item_title,
            i.description AS item_description,
            i.status AS item_status,
            i.image_url,
            i.category,
            i.found_location
        FROM claim_requests c
        JOIN items i ON i.item_id = c.item_id
        WHERE c.claim_id = ?
        LIMIT 1
    ");
    $claimStmt->execute([$claimId]);
    $claim = $claimStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    mobileApiSuccess([
        'claim' => mobileApiClaimPayload($claim),
    ], 'Claim submitted successfully.', 201);
} catch (PDOException $exception) {
    error_log('Mobile submit claim error: ' . $exception->getMessage());
    mobileApiError('Unable to submit claim.', 500, null, 'submit_claim_failed');
}
