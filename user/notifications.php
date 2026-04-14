<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$userID = $_SESSION['userID'];

// Handle mark as read
if (isset($_GET['mark_read']) && is_numeric($_GET['mark_read'])) {
    $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?");
    $stmt->execute([$_GET['mark_read'], $userID]);
    header('Location: notifications.php');
    exit();
}

// Handle mark all as read
if (isset($_GET['mark_all'])) {
    $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
    $stmt->execute([$userID]);
    header('Location: notifications.php');
    exit();
}

// Handle delete notification
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $stmt = $db->prepare("DELETE FROM notifications WHERE notification_id = ? AND user_id = ?");
    $stmt->execute([$_GET['delete'], $userID]);
    header('Location: notifications.php');
    exit();
}

// Get filter parameter
$filter = $_GET['filter'] ?? 'all';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Build query based on filter
$sql = "SELECT * FROM notifications WHERE user_id = ?";
$params = [$userID];

if ($filter === 'unread') {
    $sql .= " AND is_read = 0";
} elseif ($filter === 'read') {
    $sql .= " AND is_read = 1";
}

// Get total count for pagination
$count_sql = str_replace("SELECT *", "SELECT COUNT(*)", $sql);
$stmt = $db->prepare($count_sql);
$stmt->execute($params);
$total_notifications = $stmt->fetchColumn();
$total_pages = ceil($total_notifications / $per_page);

// Get notifications
$sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
$params[] = $per_page;
$params[] = $offset;
$stmt = $db->prepare($sql);
$stmt->execute($params);
$notifications = $stmt->fetchAll();

$base_url = '/reclaim-system/';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - Reclaim System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $base_url ?>assets/css/style.css">
    <style>
        * {
            font-family: 'Inter', sans-serif;
        }
        
        body {
            background: #f0f2f5;
        }
        
        .notification-page-item {
            transition: all 0.2s;
            border-left: 3px solid transparent;
        }
        .notification-page-item:hover {
            background: #f8f9fa;
            transform: translateX(2px);
        }
        .notification-page-item.unread {
            background: #fff8f0;
            border-left-color: #FF8C00;
        }
        .notification-icon-page {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
        }
        .notification-icon-page.info { background: #e3f2fd; color: #2196f3; }
        .notification-icon-page.success { background: #e8f5e9; color: #4caf50; }
        .notification-icon-page.warning { background: #fff3e0; color: #ff9800; }
        .notification-icon-page.danger { background: #ffebee; color: #f44336; }
        .filter-btn {
            border-radius: 50px;
            padding: 8px 20px;
            margin-right: 10px;
            text-decoration: none;
            display: inline-block;
            border: 1px solid #ddd;
            color: #666;
            transition: all 0.3s;
        }
        .filter-btn.active {
            background: #FF8C00;
            color: white;
            border-color: #FF8C00;
        }
        .filter-btn.active:hover {
            background: #e67e00;
            border-color: #e67e00;
            color: white;
        }
        .filter-btn:hover {
            background: #f5f5f5;
            color: #333;
        }
        .delete-notif {
            color: #dc3545;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 14px;
        }
        .delete-notif:hover {
            text-decoration: underline;
        }
        .mark-read-btn {
            color: #28a745;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 14px;
        }
        .mark-read-btn:hover {
            text-decoration: underline;
        }
        .pagination {
            margin-top: 20px;
        }
        .pagination .page-link {
            border-radius: 10px;
            margin: 0 3px;
            color: #FF8C00;
        }
        .pagination .page-item.active .page-link {
            background: #FF8C00;
            border-color: #FF8C00;
            color: white;
        }
        .card {
            border: none;
            border-radius: 20px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
        }
        .card-header {
            background: white;
            border-bottom: 2px solid #FF8C00;
            border-radius: 20px 20px 0 0;
            padding: 15px 20px;
        }
        .btn-outline-primary {
            border-color: #FF8C00;
            color: #FF8C00;
        }
        .btn-outline-primary:hover {
            background: #FF8C00;
            border-color: #FF8C00;
        }
        .notification-title {
            font-size: 15px;
        }
        .notification-message {
            font-size: 13px;
            line-height: 1.5;
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../includes/header.php'; ?>
    
    <div class="container mt-4">
        <div class="card fade-in">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h4 class="mb-0"><i class="fas fa-bell me-2" style="color: #FF8C00;"></i> Notifications</h4>
                <div>
                    <?php if($total_notifications > 0): ?>
                    <a href="?mark_all=1" class="btn btn-sm btn-outline-primary me-2" onclick="return confirm('Mark all notifications as read?')">
                        <i class="fas fa-check-double"></i> Mark all as read
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <!-- Filter Tabs -->
                <div class="mb-4">
                    <a class="filter-btn <?= $filter == 'all' ? 'active' : '' ?>" href="?filter=all">
                        <i class="fas fa-list"></i> All 
                        <?php if($total_notifications > 0): ?>
                        <span class="badge bg-secondary ms-1"><?= $total_notifications ?></span>
                        <?php endif; ?>
                    </a>
                    <a class="filter-btn <?= $filter == 'unread' ? 'active' : '' ?>" href="?filter=unread">
                        <i class="fas fa-envelope"></i> Unread
                    </a>
                    <a class="filter-btn <?= $filter == 'read' ? 'active' : '' ?>" href="?filter=read">
                        <i class="fas fa-envelope-open"></i> Read
                    </a>
                </div>
                
                <?php if(empty($notifications)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-bell-slash fa-4x mb-3" style="color: #ccc;"></i>
                        <h5>No notifications found</h5>
                        <p class="text-muted">You'll see notifications here when someone interacts with your reports</p>
                        <a href="<?= $base_url ?>user/dashboard.php" class="btn btn-primary">Go to Dashboard</a>
                    </div>
                <?php else: ?>
                    <div class="list-group">
                        <?php foreach($notifications as $notification): ?>
                            <div class="list-group-item notification-page-item <?= $notification['is_read'] == 0 ? 'unread' : '' ?>">
                                <div class="d-flex align-items-start">
                                    <div class="notification-icon-page <?= getIconClass($notification['type']) ?>">
                                        <i class="fas <?= getIcon($notification['type']) ?>"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <strong class="notification-title"><?= htmlspecialchars($notification['title'] ?? 'Notification') ?></strong>
                                                <?php if($notification['is_read'] == 0): ?>
                                                    <span class="badge bg-primary ms-2">New</span>
                                                <?php endif; ?>
                                            </div>
                                            <small class="text-muted">
                                                <?= timeAgo($notification['created_at']) ?>
                                            </small>
                                        </div>
                                        <p class="mb-2 text-muted mt-1 notification-message"><?= nl2br(htmlspecialchars($notification['message'])) ?></p>
                                        <div class="mt-2">
                                            <?php if($notification['is_read'] == 0): ?>
                                                <a href="?mark_read=<?= $notification['notification_id'] ?>" class="mark-read-btn btn btn-sm btn-link text-decoration-none p-0 me-3">
                                                    <i class="fas fa-check-circle"></i> Mark as read
                                                </a>
                                            <?php endif; ?>
                                            <a href="?delete=<?= $notification['notification_id'] ?>" class="delete-notif btn btn-sm btn-link text-decoration-none p-0" onclick="return confirm('Delete this notification?')">
                                                <i class="fas fa-trash-alt"></i> Delete
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- Pagination -->
                    <?php if($total_pages > 1): ?>
                    <nav class="mt-4">
                        <ul class="pagination justify-content-center">
                            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="?filter=<?= $filter ?>&page=<?= $page-1 ?>">Previous</a>
                            </li>
                            <?php for($i = 1; $i <= $total_pages; $i++): ?>
                                <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                    <a class="page-link" href="?filter=<?= $filter ?>&page=<?= $i ?>"><?= $i ?></a>
                                </li>
                            <?php endfor; ?>
                            <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                                <a class="page-link" href="?filter=<?= $filter ?>&page=<?= $page+1 ?>">Next</a>
                            </li>
                        </ul>
                    </nav>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>

<?php
// Helper functions
function timeAgo($timestamp) {
    if (!$timestamp) return 'Never';
    $time_ago = strtotime($timestamp);
    $current_time = time();
    $time_difference = $current_time - $time_ago;
    
    if($time_difference < 60) {
        return "Just now";
    } elseif($time_difference < 3600) {
        $minutes = floor($time_difference / 60);
        return $minutes . " minute" . ($minutes > 1 ? "s" : "") . " ago";
    } elseif($time_difference < 86400) {
        $hours = floor($time_difference / 3600);
        return $hours . " hour" . ($hours > 1 ? "s" : "") . " ago";
    } elseif($time_difference < 604800) {
        $days = floor($time_difference / 86400);
        return $days . " day" . ($days > 1 ? "s" : "") . " ago";
    } else {
        return date('M d, Y', $time_ago);
    }
}

function getIcon($type) {
    $icons = [
        'info' => 'fa-info-circle', 
        'success' => 'fa-check-circle', 
        'warning' => 'fa-exclamation-triangle', 
        'danger' => 'fa-times-circle'
    ];
    return $icons[$type] ?? 'fa-bell';
}

function getIconClass($type) {
    $classes = [
        'info' => 'info', 
        'success' => 'success', 
        'warning' => 'warning', 
        'danger' => 'danger'
    ];
    return $classes[$type] ?? 'info';
}
?>