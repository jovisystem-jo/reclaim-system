<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$userID = $_SESSION['userID'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . '/reclaim-system/');
    exit();
}

$postData = $_POST;
require_csrf_token($postData);

$item_id = $_POST['item_id'] ?? 0;
$claimant_description = $_POST['claimant_description'] ?? '';

if (!$item_id || empty($claimant_description)) {
    header('Location: ' . '/reclaim-system/item-details.php?id=' . $item_id . '&error=missing_fields');
    exit();
}

// Check if user already claimed this item
$stmt = $db->prepare("SELECT COUNT(*) FROM claim_requests WHERE item_id = ? AND claimant_id = ?");
$stmt->execute([$item_id, $userID]);
if ($stmt->fetchColumn() > 0) {
    header('Location: ' . '/reclaim-system/item-details.php?id=' . $item_id . '&error=already_claimed');
    exit();
}

// Handle proof image upload
$proof_image_url = '';
if (isset($_FILES['proof_image']) && $_FILES['proof_image']['error'] !== UPLOAD_ERR_NO_FILE) {
    $uploadDir = __DIR__ . '/../assets/uploads/proofs/';
    // Security: proof images are validated by MIME type and saved with random names.
    $upload = secure_image_upload($_FILES['proof_image'], $uploadDir, 'assets/uploads/proofs');
    if (!$upload['success']) {
        header('Location: ' . '/reclaim-system/item-details.php?id=' . $item_id . '&error=invalid_upload');
        exit();
    }
    $proof_image_url = $upload['path'];
}

// Insert claim request
$stmt = $db->prepare("
    INSERT INTO claim_requests (item_id, claimant_id, claimant_description, proof_image_url, status, created_at)
    VALUES (?, ?, ?, ?, 'pending', NOW())
");
$stmt->execute([$item_id, $userID, $claimant_description, $proof_image_url]);

header('Location: ' . '/reclaim-system/item-details.php?id=' . $item_id . '&success=claim_submitted');
exit();
?>
