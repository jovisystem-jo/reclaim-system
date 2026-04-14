<?php
require_once '../config/database.php';

session_start();
if (!isset($_SESSION['userID'])) {
    echo json_encode(['count' => 0]);
    exit();
}

$db = Database::getInstance()->getConnection();
$stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$stmt->execute([$_SESSION['userID']]);
$count = $stmt->fetchColumn();

echo json_encode(['count' => $count]);
?>