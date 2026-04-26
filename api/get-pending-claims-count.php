<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once '../config/database.php';
session_start();

// Check if admin is logged in
if (!isset($_SESSION['userID']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['count' => 0, 'error' => 'Unauthorized']);
    exit();
}

$db = Database::getInstance()->getConnection();

// Get pending claims count
$stmt = $db->prepare("SELECT COUNT(*) FROM claim_requests WHERE status = 'pending'");
$stmt->execute();
$count = $stmt->fetchColumn();

echo json_encode([
    'count' => (int)$count,
    'timestamp' => date('Y-m-d H:i:s')
]);
?>