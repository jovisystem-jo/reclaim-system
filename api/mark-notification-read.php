<?php
require_once __DIR__ . '/../config/database.php';

secureSessionStart();
if (!isset($_SESSION['userID'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

$data = json_request_body();
require_csrf_token($data);

$notification_id = $data['notification_id'] ?? 0;

if (!$notification_id) {
    echo json_encode(['success' => false, 'error' => 'Notification ID required']);
    exit();
}

$db = Database::getInstance()->getConnection();

// Verify notification belongs to user
$stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?");
$result = $stmt->execute([$notification_id, $_SESSION['userID']]);

echo json_encode(['success' => $result]);
?>
