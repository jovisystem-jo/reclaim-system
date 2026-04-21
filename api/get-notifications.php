<?php
require_once __DIR__ . '/../config/database.php';

secureSessionStart();
if (!isset($_SESSION['userID'])) {
    echo json_encode([]);
    exit();
}

$db = Database::getInstance()->getConnection();
$stmt = $db->prepare("
    SELECT * FROM notifications 
    WHERE user_id = ? 
    ORDER BY created_at DESC 
    LIMIT 20
");
$stmt->execute([$_SESSION['userID']]);
$notifications = $stmt->fetchAll();

foreach ($notifications as &$notif) {
    $timestamp = strtotime($notif['created_at']);
    $now = time();
    $diff = $now - $timestamp;
    
    if ($diff < 60) {
        $notif['time_ago'] = 'Just now';
    } elseif ($diff < 3600) {
        $notif['time_ago'] = floor($diff / 60) . ' minutes ago';
    } elseif ($diff < 86400) {
        $notif['time_ago'] = floor($diff / 3600) . ' hours ago';
    } else {
        $notif['time_ago'] = date('M d, Y', $timestamp);
    }
}

echo json_encode($notifications);
?>
