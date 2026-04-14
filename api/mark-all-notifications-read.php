<?php
require_once __DIR__ . '/../config/database.php';

session_start();
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

$db = Database::getInstance()->getConnection();

$stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
$result = $stmt->execute([$_SESSION['userID']]);

echo json_encode(['success' => $result]);
?>