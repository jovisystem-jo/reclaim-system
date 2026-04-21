<?php
require_once __DIR__ . '/../config/database.php';
secureSessionStart();

header('Content-Type: application/json');

if (!isset($_SESSION['userID'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

$data = json_request_body();
require_csrf_token($data);

$claim_id = $data['claim_id'] ?? 0;
$status = $data['status'] ?? '';

if (!$claim_id || !in_array($status, ['approved', 'rejected'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit();
}

try {
    $db = Database::getInstance()->getConnection();
    
    // Check if user owns the item
    $stmt = $db->prepare("
        SELECT i.reported_by FROM claim_requests c
        JOIN items i ON c.item_id = i.item_id
        WHERE c.claim_id = ?
    ");
    $stmt->execute([$claim_id]);
    $result = $stmt->fetch();
    
    if (!$result || $result['reported_by'] != $_SESSION['userID']) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    }
    
    // Update claim status
    $stmt = $db->prepare("UPDATE claim_requests SET status = ? WHERE claim_id = ?");
    $stmt->execute([$status, $claim_id]);
    
    // If approved, also update item status to claimed
    if ($status == 'approved') {
        $stmt = $db->prepare("UPDATE items SET status = 'claimed' WHERE item_id = (SELECT item_id FROM claim_requests WHERE claim_id = ?)");
        $stmt->execute([$claim_id]);
    }
    
    echo json_encode(['success' => true]);
    
} catch (PDOException $e) {
    error_log("Claim status update failed: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Unable to update claim status']);
}
?>
