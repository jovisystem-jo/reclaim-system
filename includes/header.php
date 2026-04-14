<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Use direct absolute path - no dynamic calculation
$base_url = '/reclaim-system/';

// Get unread notification count and latest UNREAD notifications
$unread_count = 0;
$unreadNotifications = [];
if (isset($_SESSION['userID'])) {
    require_once __DIR__ . '/../config/database.php';
    $db = Database::getInstance()->getConnection();
    
    // Get unread count
    $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$_SESSION['userID']]);
    $unread_count = $stmt->fetchColumn();
    
    // Get ONLY LATEST 2 UNREAD notifications
    $stmt = $db->prepare("
        SELECT * FROM notifications 
        WHERE user_id = ? AND is_read = 0
        ORDER BY created_at DESC 
        LIMIT 2
    ");
    $stmt->execute([$_SESSION['userID']]);
    $unreadNotifications = $stmt->fetchAll();
}

// Helper function for time ago
function timeAgoShort($timestamp) {
    if (!$timestamp) return 'Never';
    $time_ago = strtotime($timestamp);
    $current_time = time();
    $time_difference = $current_time - $time_ago;
    
    if($time_difference < 60) return "Just now";
    if($time_difference < 3600) return floor($time_difference / 60) . " min ago";
    if($time_difference < 86400) return floor($time_difference / 3600) . " hours ago";
    if($time_difference < 604800) return floor($time_difference / 86400) . " days ago";
    return date('M d', $time_ago);
}

function getIconShort($type) {
    $icons = [
        'info' => 'fa-info-circle', 
        'success' => 'fa-check-circle', 
        'warning' => 'fa-exclamation-triangle', 
        'danger' => 'fa-times-circle'
    ];
    return $icons[$type] ?? 'fa-bell';
}

function getIconClassShort($type) {
    $classes = [
        'info' => 'info', 
        'success' => 'success', 
        'warning' => 'warning', 
        'danger' => 'danger'
    ];
    return $classes[$type] ?? 'info';
}

// Determine current path for active menu
$current_page = basename($_SERVER['PHP_SELF']);
$current_dir = basename(dirname($_SERVER['PHP_SELF']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RECLAIM - Lost & Found</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="<?= $base_url ?>assets/css/style.css">
    <style>
        /* Notification Dropdown Styles */
        .notification-dropdown {
            width: 380px;
            max-height: 500px;
            overflow-y: auto;
            padding: 0;
        }
        .notification-header {
            background: linear-gradient(135deg, #FF8C00, #FF6B00);
            color: white;
            padding: 12px 15px;
            position: sticky;
            top: 0;
            z-index: 1;
        }
        .notification-header h6 {
            margin: 0;
            font-weight: 600;
        }
        .notification-header small {
            opacity: 0.9;
            cursor: pointer;
        }
        .notification-header small:hover {
            text-decoration: underline;
        }
        .notification-item {
            padding: 12px 15px;
            border-bottom: 1px solid #e0e0e0;
            transition: background 0.2s;
            cursor: pointer;
        }
        .notification-item:hover {
            background: #f8f9fa;
        }
        .notification-item.unread {
            background: #fff8f0;
            border-left: 3px solid #FF8C00;
        }
        .notification-item.unread:hover {
            background: #fff0e0;
        }
        .notification-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-right: 12px;
            flex-shrink: 0;
        }
        .notification-icon.info { background: #e3f2fd; color: #2196f3; }
        .notification-icon.success { background: #e8f5e9; color: #4caf50; }
        .notification-icon.warning { background: #fff3e0; color: #ff9800; }
        .notification-icon.danger { background: #ffebee; color: #f44336; }
        .notification-content {
            flex: 1;
            min-width: 0;
        }
        .notification-title {
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 3px;
            color: #333;
        }
        .notification-message {
            font-size: 12px;
            color: #666;
            margin-bottom: 3px;
            word-wrap: break-word;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .notification-time {
            font-size: 11px;
            color: #999;
        }
        .notification-footer {
            padding: 10px 15px;
            text-align: center;
            background: #f8f9fa;
            position: sticky;
            bottom: 0;
            border-top: 1px solid #e0e0e0;
        }
        .notification-footer a {
            color: #FF8C00;
            text-decoration: none;
            font-size: 12px;
            font-weight: 600;
        }
        .notification-footer a:hover {
            text-decoration: underline;
        }
        .no-notifications {
            padding: 30px 20px;
            text-align: center;
            color: #999;
        }
        .no-notifications i {
            font-size: 48px;
            margin-bottom: 10px;
            opacity: 0.5;
        }
        .notification-badge {
            position: absolute;
            top: -5px;
            right: -8px;
            background: #dc3545;
            color: white;
            border-radius: 50%;
            padding: 2px 6px;
            font-size: 10px;
            font-weight: bold;
            min-width: 18px;
            text-align: center;
        }
        .nav-link.notification-link {
            position: relative;
        }
        .dropdown-header-info {
            font-size: 11px;
            opacity: 0.8;
            margin-top: 2px;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg sticky-top">
        <div class="container">
            <a class="navbar-brand" href="<?= $base_url ?>">
                <i class="fas fa-recycle"></i> RECLAIM
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="<?= $base_url ?>">Home</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= $base_url ?>search.php">Search</a>
                    </li>
                    <?php if(isset($_SESSION['userID'])): ?>
                        <?php if($_SESSION['role'] == 'admin'): ?>
                            <li class="nav-item">
                                <a class="nav-link" href="<?= $base_url ?>admin/dashboard.php">Admin Panel</a>
                            </li>
                        <?php else: ?>
                            <li class="nav-item">
                                <a class="nav-link" href="<?= $base_url ?>user/dashboard.php">Dashboard</a>
                            </li>
                        <?php endif; ?>
                        
                        <!-- Notifications Dropdown - ONLY SHOW UNREAD NOTIFICATIONS -->
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle notification-link" href="#" id="notificationDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="fas fa-bell"></i>
                                <?php if($unread_count > 0): ?>
                                    <span class="notification-badge" id="notificationBadge"><?= $unread_count ?></span>
                                <?php endif; ?>
                            </a>
                            <div class="dropdown-menu dropdown-menu-end notification-dropdown" aria-labelledby="notificationDropdown" id="notificationMenu">
                                <div class="notification-header d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6><i class="fas fa-bell me-2"></i>Notifications</h6>
                                        <?php if($unread_count > 0): ?>
                                            <div class="dropdown-header-info"><?= $unread_count ?> unread</div>
                                        <?php endif; ?>
                                    </div>
                                    <?php if($unread_count > 0): ?>
                                        <small class="text-white" id="markAllReadBtn" style="cursor: pointer;">Mark all as read</small>
                                    <?php endif; ?>
                                </div>
                                <div id="notificationsList">
                                    <?php if(empty($unreadNotifications)): ?>
                                        <div class="no-notifications">
                                            <i class="fas fa-bell-slash"></i>
                                            <p>No new notifications</p>
                                            <small>You're all caught up!</small>
                                        </div>
                                    <?php else: ?>
                                        <?php foreach($unreadNotifications as $notif): ?>
                                            <div class="notification-item unread" data-id="<?= $notif['notification_id'] ?>">
                                                <div class="d-flex">
                                                    <div class="notification-icon <?= getIconClassShort($notif['type']) ?>">
                                                        <i class="fas <?= getIconShort($notif['type']) ?>"></i>
                                                    </div>
                                                    <div class="notification-content">
                                                        <div class="notification-title"><?= htmlspecialchars($notif['title'] ?? 'Notification') ?></div>
                                                        <div class="notification-message"><?= htmlspecialchars(substr($notif['message'], 0, 80)) ?><?= strlen($notif['message']) > 80 ? '...' : '' ?></div>
                                                        <div class="notification-time">
                                                            <i class="far fa-clock me-1"></i><?= timeAgoShort($notif['created_at']) ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- View All Notifications Button - ALWAYS SHOWN -->
                                <div class="notification-footer">
                                    <a href="<?= $base_url ?>user/notifications.php">
                                        <i class="fas fa-bell me-1"></i> View all notifications
                                        <?php if($unread_count > 0): ?>
                                            <span class="badge bg-danger ms-1" style="font-size: 10px;"><?= $unread_count ?> new</span>
                                        <?php endif; ?>
                                    </a>
                                </div>
                            </div>
                        </li>
                        
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="fas fa-user-circle"></i> <?= htmlspecialchars($_SESSION['name']) ?>
                            </a>
                            <ul class="dropdown-menu" aria-labelledby="userDropdown">
                                <li><a class="dropdown-item" href="<?= $base_url ?>user/user-profile.php"><i class="fas fa-user"></i> Profile</a></li>
                                <li><a class="dropdown-item" href="<?= $base_url ?>user/my-claims.php"><i class="fas fa-file-alt"></i> My Claims</a></li>
                                <li><a class="dropdown-item" href="<?= $base_url ?>user/my-report-item.php"><i class="fas fa-clipboard-list"></i> My Reports</a></li>
                                <li><a class="dropdown-item" href="<?= $base_url ?>user/notifications.php"><i class="fas fa-bell"></i> Notifications</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="<?= $base_url ?>logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                            </ul>
                        </li>
                    <?php else: ?>
                        <li class="nav-item">
                            <a class="nav-link" href="<?= $base_url ?>login.php">Login</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="<?= $base_url ?>register.php">Register</a>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>

    <script>
    const baseUrl = '<?= $base_url ?>';
    
    // Function to mark notification as read and redirect
    function markAsReadAndRedirect(notificationId) {
        fetch(baseUrl + 'api/mark-notification-read.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ notification_id: notificationId })
        })
        .then(response => response.json())
        .then(data => {
            window.location.href = baseUrl + 'user/notifications.php';
        })
        .catch(error => {
            console.error('Error marking notification as read:', error);
            window.location.href = baseUrl + 'user/notifications.php';
        });
    }
    
    // Function to mark all notifications as read
    function markAllAsRead() {
        fetch(baseUrl + 'api/mark-all-notifications-read.php', {
            method: 'POST'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            }
        })
        .catch(error => console.error('Error marking all notifications as read:', error));
    }
    
    // Function to update notification count badge
    function updateNotificationCount() {
        fetch(baseUrl + 'api/get-notification-count.php')
            .then(response => response.json())
            .then(data => {
                const badge = document.getElementById('notificationBadge');
                const markAllBtn = document.getElementById('markAllReadBtn');
                
                if (data.count > 0) {
                    if (badge) {
                        badge.textContent = data.count;
                        badge.style.display = 'inline-block';
                    }
                    if (markAllBtn) markAllBtn.style.display = 'block';
                } else {
                    if (badge) badge.style.display = 'none';
                    if (markAllBtn) markAllBtn.style.display = 'none';
                }
            })
            .catch(error => console.error('Error updating notification count:', error));
    }
    
    // Event listeners when DOM is loaded
    document.addEventListener('DOMContentLoaded', function() {
        // Mark all as read button
        const markAllBtn = document.getElementById('markAllReadBtn');
        if (markAllBtn) {
            markAllBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                if (confirm('Mark all notifications as read?')) {
                    markAllAsRead();
                }
            });
        }
        
        // Make notification items clickable - redirect to notifications page
        document.querySelectorAll('.notification-item').forEach(item => {
            item.addEventListener('click', function(e) {
                // Don't trigger if clicking on the view all link or mark all button
                if (e.target.closest('.notification-footer')) return;
                if (e.target.closest('#markAllReadBtn')) return;
                
                const notificationId = this.getAttribute('data-id');
                if (notificationId) {
                    markAsReadAndRedirect(notificationId);
                } else {
                    window.location.href = baseUrl + 'user/notifications.php';
                }
            });
        });
        
        // Auto-refresh notification count every 30 seconds
        setInterval(updateNotificationCount, 30000);
    });
    </script>
</body>
</html>