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
if (isset($_FILES['proof_image']) && $_FILES['proof_image']['error'] === UPLOAD_ERR_OK) {
    $uploadDir = __DIR__ . '/../assets/uploads/proofs/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    $fileName = uniqid() . '_' . basename($_FILES['proof_image']['name']);
    $uploadFile = $uploadDir . $fileName;
    if (move_uploaded_file($_FILES['proof_image']['tmp_name'], $uploadFile)) {
        $proof_image_url = 'assets/uploads/proofs/' . $fileName;
    }
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