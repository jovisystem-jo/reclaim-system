<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
requireAdmin();
require_once '../includes/notification.php';

$db = Database::getInstance()->getConnection();
$notification = new NotificationSystem();
$message = '';
$error = '';

// Handle sending bulk notification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'send_bulk') {
        $title = trim($_POST['title'] ?? '');
        $message_text = trim($_POST['message'] ?? '');
        $recipient_type = $_POST['recipient_type'] ?? 'all';
        $send_email = isset($_POST['send_email']) ? true : false;
        $type = $_POST['notification_type'] ?? 'info';
        
        if (empty($title) || empty($message_text)) {
            $error = 'Please fill in both title and message';
        } else {
            if ($recipient_type === 'all') {
                $count = $notification->sendToAll($title, $message_text, $type, null, $send_email);
            } elseif ($recipient_type === 'students') {
                $count = $notification->sendToAll($title, $message_text, $type, 'student', $send_email);
            } elseif ($recipient_type === 'staff') {
                $count = $notification->sendToAll($title, $message_text, $type, 'staff', $send_email);
            } elseif ($recipient_type === 'admins') {
                $count = $notification->sendToAdmins($title, $message_text, $type, $send_email);
            } else {
                $count = 0;
            }
            
            $message = "Notification sent to $count user(s)" . ($send_email ? " (with email)" : "");
        }
    } elseif ($action === 'mark_read') {
        $notification_id = $_POST['notification_id'] ?? 0;
        if ($notification_id) {
            $notification->markAsRead($notification_id, $_SESSION['userID']);
            $message = "Notification marked as read";
        }
    } elseif ($action === 'mark_all_read') {
        $notification->markAllAsRead($_SESSION['userID']);
        $message = "All notifications marked as read";
    } elseif ($action === 'delete') {
        $stmt = $db->prepare("DELETE FROM notifications WHERE notification_id = ? AND user_id = ?");
        $stmt->execute([$_POST['notification_id'], $_SESSION['userID']]);
        $message = "Notification deleted";
    }
}

// Get filter parameters
$filter = $_GET['filter'] ?? 'all';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Build query for admin's notifications
$sql = "SELECT * FROM notifications WHERE user_id = ?";
$params = [$_SESSION['userID']];

if ($filter === 'unread') {
    $sql .= " AND is_read = 0";
} elseif ($filter === 'read') {
    $sql .= " AND is_read = 1";
}

// Get total count
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

// Get email logs
$stmt = $db->prepare("
    SELECT * FROM email_logs 
    ORDER BY sent_at DESC 
    LIMIT 50
");
$stmt->execute();
$email_logs = $stmt->fetchAll();

// Get notification statistics
$stmt = $db->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) as unread,
        SUM(CASE WHEN type = 'info' THEN 1 ELSE 0 END) as info,
        SUM(CASE WHEN type = 'success' THEN 1 ELSE 0 END) as success,
        SUM(CASE WHEN type = 'warning' THEN 1 ELSE 0 END) as warning,
        SUM(CASE WHEN type = 'danger' THEN 1 ELSE 0 END) as danger
    FROM notifications 
    WHERE user_id = ?
");
$stmt->execute([$_SESSION['userID']]);
$stats = $stmt->fetch();

$base_url = '/reclaim-system/';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            font-family: 'Inter', sans-serif;
        }
        
        body {
            background: #f0f2f5;
        }
        
        .main-content {
            padding: 20px;
            min-height: 100vh;
        }
        
        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 20px;
            text-align: center;
            transition: all 0.3s;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            border: none;
            height: 100%;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 30px rgba(0,0,0,0.12);
        }
        
        .stat-card i {
            font-size: 2rem;
            margin-bottom: 10px;
        }
        
        .stat-card h3 {
            font-size: 1.8rem;
            font-weight: 800;
            margin: 5px 0;
        }
        
        .stat-card p {
            color: #6c757d;
            margin: 0;
            font-size: 0.85rem;
        }
        
        .stat-card.total i { color: #FF6B35; }
        .stat-card.unread i { color: #F39C12; }
        .stat-card.email i { color: #3498DB; }
        
        .card-modern {
            border: none;
            border-radius: 20px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            margin-bottom: 20px;
        }
        
        .card-modern .card-header {
            background: white;
            border-bottom: 2px solid #FF6B35;
            padding: 15px 20px;
            border-radius: 20px 20px 0 0;
            font-weight: 600;
        }
        
        .btn-primary-custom {
            background: linear-gradient(135deg, #FF6B35, #E85D2C);
            border: none;
            padding: 10px 25px;
            border-radius: 50px;
            transition: all 0.3s;
            color: white;
        }
        
        .btn-primary-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255,107,53,0.3);
            color: white;
        }
        
        .btn-secondary-custom {
            background: #6c757d;
            border: none;
            padding: 8px 20px;
            border-radius: 50px;
            transition: all 0.3s;
            color: white;
            text-decoration: none;
        }
        
        .btn-secondary-custom:hover {
            background: #5a6268;
            color: white;
        }
        
        .notification-item {
            padding: 15px;
            border-bottom: 1px solid #e9ecef;
            transition: all 0.3s;
            cursor: pointer;
        }
        
        .notification-item:hover {
            background: #f8f9fa;
        }
        
        .notification-item.unread {
            background: #fff8f0;
            border-left: 3px solid #FF6B35;
        }
        
        .notification-icon {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
        }
        
        .notification-icon.info { background: #e3f2fd; color: #2196f3; }
        .notification-icon.success { background: #e8f5e9; color: #4caf50; }
        .notification-icon.warning { background: #fff3e0; color: #ff9800; }
        .notification-icon.danger { background: #ffebee; color: #f44336; }
        
        .filter-btn {
            margin-right: 10px;
            border-radius: 50px;
        }
        
        .filter-btn.active {
            background: #FF6B35;
            color: white;
            border-color: #FF6B35;
        }
        
        .email-log-item {
            padding: 10px 15px;
            border-bottom: 1px solid #e9ecef;
            font-size: 13px;
        }
        
        .email-status-sent { color: #28a745; }
        .email-status-failed { color: #dc3545; }
        
        .pagination {
            margin-top: 20px;
        }
        
        .pagination .page-link {
            border-radius: 10px;
            margin: 0 3px;
            color: #FF6B35;
        }
        
        .pagination .page-item.active .page-link {
            background: #FF6B35;
            border-color: #FF6B35;
            color: white;
        }
        
        .form-control, .form-select {
            border-radius: 12px;
            padding: 12px 15px;
            border: 1px solid #e0e0e0;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: #FF6B35;
            box-shadow: 0 0 0 3px rgba(255,107,53,0.1);
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <?php include 'sidebar.php'; ?>
            
            <div class="col-md-10 main-content">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2 class="fw-bold"><i class="fas fa-bell me-2" style="color: #FF6B35;"></i> Notifications</h2>
                    <a href="dashboard.php" class="btn btn-secondary-custom">
                        <i class="fas fa-arrow-left me-2"></i> Back to Dashboard
                    </a>
                </div>
                
                <?php if($message): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i> <?= $message ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i> <?= $error ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-md-4 mb-3">
                        <div class="stat-card total">
                            <i class="fas fa-bell"></i>
                            <h3><?= number_format($stats['total'] ?? 0) ?></h3>
                            <p>Total Notifications</p>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="stat-card unread">
                            <i class="fas fa-envelope"></i>
                            <h3><?= number_format($stats['unread'] ?? 0) ?></h3>
                            <p>Unread Notifications</p>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="stat-card email">
                            <i class="fas fa-envelope-open-text"></i>
                            <h3><?= number_format(count($email_logs)) ?></h3>
                            <p>Emails Sent</p>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <!-- Send Notification Panel -->
                    <div class="col-lg-5">
                        <div class="card-modern card">
                            <div class="card-header">
                                <i class="fas fa-paper-plane me-2" style="color: #FF6B35;"></i> Send Bulk Notification
                            </div>
                            <div class="card-body">
                                <form method="POST" action="">
                                    <input type="hidden" name="action" value="send_bulk">
                                    
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Notification Title</label>
                                        <input type="text" name="title" class="form-control" required placeholder="e.g., System Maintenance Notice">
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Message</label>
                                        <textarea name="message" class="form-control" rows="4" required placeholder="Enter your notification message here..."></textarea>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Recipient Type</label>
                                        <select name="recipient_type" class="form-select">
                                            <option value="all">All Users</option>
                                            <option value="students">Students Only</option>
                                            <option value="staff">Staff Only</option>
                                            <option value="admins">Admins Only</option>
                                        </select>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Notification Type</label>
                                        <select name="notification_type" class="form-select">
                                            <option value="info">ℹ️ Info (Blue)</option>
                                            <option value="success">✅ Success (Green)</option>
                                            <option value="warning">⚠️ Warning (Yellow)</option>
                                            <option value="danger">❌ Danger (Red)</option>
                                        </select>
                                    </div>
                                    
                                    <div class="mb-3 form-check">
                                        <input type="checkbox" name="send_email" class="form-check-input" id="sendEmail" checked>
                                        <label class="form-check-label" for="sendEmail">
                                            <i class="fas fa-envelope me-1"></i> Also send email notification
                                        </label>
                                        <small class="d-block text-muted">Users will receive both in-app notification and email (if enabled)</small>
                                    </div>
                                    
                                    <button type="submit" class="btn btn-primary-custom w-100">
                                        <i class="fas fa-paper-plane me-2"></i> Send Notification
                                    </button>
                                </form>
                            </div>
                        </div>
                        
                        <!-- Email Logs -->
                        <div class="card-modern card mt-4">
                            <div class="card-header">
                                <i class="fas fa-history me-2" style="color: #FF6B35;"></i> Recent Email Logs
                            </div>
                            <div class="card-body p-0">
                                <?php if(empty($email_logs)): ?>
                                    <div class="text-center py-4 text-muted">
                                        <i class="fas fa-envelope fa-2x mb-2 d-block"></i>
                                        No emails sent yet
                                    </div>
                                <?php else: ?>
                                    <?php foreach(array_slice($email_logs, 0, 10) as $log): ?>
                                        <div class="email-log-item">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div>
                                                    <strong><?= htmlspecialchars($log['subject']) ?></strong><br>
                                                    <small>To: <?= htmlspecialchars($log['recipient_email']) ?></small>
                                                </div>
                                                <span class="email-status-<?= $log['status'] ?>">
                                                    <i class="fas fa-<?= $log['status'] == 'sent' ? 'check-circle' : 'times-circle' ?>"></i>
                                                    <?= ucfirst($log['status']) ?>
                                                </span>
                                            </div>
                                            <small class="text-muted">
                                                <i class="fas fa-clock me-1"></i> <?= date('M d, Y h:i A', strtotime($log['sent_at'])) ?>
                                            </small>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Notifications List -->
                    <div class="col-lg-7">
                        <div class="card-modern card">
                            <div class="card-header">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span><i class="fas fa-list me-2" style="color: #FF6B35;"></i> Your Notifications</span>
                                    <div>
                                        <form method="POST" action="" style="display: inline;">
                                            <input type="hidden" name="action" value="mark_all_read">
                                            <button type="submit" class="btn btn-sm btn-outline-secondary">
                                                <i class="fas fa-check-double me-1"></i> Mark All Read
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body p-0">
                                <!-- Filter Tabs -->
                                <div class="p-3 border-bottom">
                                    <a href="?filter=all" class="btn btn-sm filter-btn <?= $filter == 'all' ? 'active btn-primary-custom' : 'btn-outline-secondary' ?>">All</a>
                                    <a href="?filter=unread" class="btn btn-sm filter-btn <?= $filter == 'unread' ? 'active btn-primary-custom' : 'btn-outline-secondary' ?>">Unread</a>
                                    <a href="?filter=read" class="btn btn-sm filter-btn <?= $filter == 'read' ? 'active btn-primary-custom' : 'btn-outline-secondary' ?>">Read</a>
                                </div>
                                
                                <?php if(empty($notifications)): ?>
                                    <div class="text-center py-5">
                                        <i class="fas fa-bell-slash fa-4x mb-3" style="color: #ccc;"></i>
                                        <h5>No notifications found</h5>
                                        <p class="text-muted">You'll see notifications here when there are updates</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach($notifications as $notif): ?>
                                        <div class="notification-item <?= $notif['is_read'] == 0 ? 'unread' : '' ?>">
                                            <div class="d-flex">
                                                <div class="notification-icon <?= $notif['type'] ?>">
                                                    <i class="fas fa-<?= $notif['type'] == 'info' ? 'info-circle' : ($notif['type'] == 'success' ? 'check-circle' : ($notif['type'] == 'warning' ? 'exclamation-triangle' : 'times-circle')) ?>"></i>
                                                </div>
                                                <div class="flex-grow-1">
                                                    <div class="d-flex justify-content-between align-items-start">
                                                        <div>
                                                            <strong><?= htmlspecialchars($notif['title'] ?? 'Notification') ?></strong>
                                                            <?php if($notif['is_read'] == 0): ?>
                                                                <span class="badge bg-primary ms-2">New</span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <small class="text-muted">
                                                            <?= time_ago($notif['created_at']) ?>
                                                        </small>
                                                    </div>
                                                    <p class="mb-2 text-muted mt-1"><?= nl2br(htmlspecialchars($notif['message'])) ?></p>
                                                    <div class="mt-2">
                                                        <?php if($notif['is_read'] == 0): ?>
                                                            <form method="POST" action="" style="display: inline;">
                                                                <input type="hidden" name="action" value="mark_read">
                                                                <input type="hidden" name="notification_id" value="<?= $notif['notification_id'] ?>">
                                                                <button type="submit" class="btn btn-sm btn-link text-decoration-none p-0 me-3">
                                                                    <i class="fas fa-check-circle"></i> Mark as read
                                                                </button>
                                                            </form>
                                                        <?php endif; ?>
                                                        <form method="POST" action="" style="display: inline;">
                                                            <input type="hidden" name="action" value="delete">
                                                            <input type="hidden" name="notification_id" value="<?= $notif['notification_id'] ?>">
                                                            <button type="submit" class="btn btn-sm btn-link text-danger text-decoration-none p-0" onclick="return confirm('Delete this notification?')">
                                                                <i class="fas fa-trash-alt"></i> Delete
                                                            </button>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                
                                <!-- Pagination -->
                                <?php if($total_pages > 1): ?>
                                <div class="p-3 border-top">
                                    <nav>
                                        <ul class="pagination justify-content-center mb-0">
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
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
    function time_ago(timestamp) {
        const date = new Date(timestamp);
        const now = new Date();
        const diff = Math.floor((now - date) / 1000);
        
        if (diff < 60) return 'Just now';
        if (diff < 3600) return Math.floor(diff / 60) + ' minutes ago';
        if (diff < 86400) return Math.floor(diff / 3600) + ' hours ago';
        if (diff < 604800) return Math.floor(diff / 86400) + ' days ago';
        return date.toLocaleDateString();
    }
    </script>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

<?php
function time_ago($timestamp) {
    if (!$timestamp) return 'Never';
    $time_ago = strtotime($timestamp);
    $current_time = time();
    $time_difference = $current_time - $time_ago;
    
    if($time_difference < 60) return "Just now";
    if($time_difference < 3600) return floor($time_difference / 60) . " minutes ago";
    if($time_difference < 86400) return floor($time_difference / 3600) . " hours ago";
    if($time_difference < 604800) return floor($time_difference / 86400) . " days ago";
    return date('M d, Y', $time_ago);
}
?>