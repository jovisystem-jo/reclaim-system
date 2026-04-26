<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$userID = $_SESSION['userID'];

// Get user statistics - FIXED: Use correct column names
$stmt = $db->prepare("
    SELECT
        (SELECT COUNT(*) FROM items WHERE reported_by = ?) as my_reports,
        (SELECT COUNT(*) FROM claim_requests WHERE claimant_id = ?) as my_claims,
        (SELECT COUNT(*) FROM claim_requests WHERE claimant_id = ? AND status = 'approved') as approved_claims
");
$stmt->execute([$userID, $userID, $userID]);
$stats = $stmt->fetch();

// Get recent notifications - FIXED: Use user_id instead of userID
$stmt = $db->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
$stmt->execute([$userID]);
$notifications = $stmt->fetchAll();

// Get unread count - FIXED: Use user_id instead of userID
$stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$stmt->execute([$userID]);
$unread_count = $stmt->fetchColumn();

// Get recent items reported by user
$stmt = $db->prepare("SELECT * FROM items WHERE reported_by = ? ORDER BY reported_date DESC LIMIT 5");
$stmt->execute([$userID]);
$recent_items = $stmt->fetchAll();

$base_url = '/reclaim-system/';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Dashboard - Reclaim System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="<?= $base_url ?>assets/css/style.css">
    <style>
        .report-card {
            background: linear-gradient(135deg, #FFD700 0%, #FF8C00 100%);
            border-radius: 20px;
            padding: 30px;
            text-align: center;
            color: white;
            margin-bottom: 30px;
            transition: transform 0.3s;
        }
        .dashboard-content {
            padding-top: 18px;
        }
        .report-card:hover {
            transform: translateY(-5px);
        }

        .content-wrapper {
            margin-top: 20px; /* adjust: 20px–40px */
        }
        .report-card i {
            font-size: 48px;
            margin-bottom: 15px;
        }
        .report-card h3 {
            margin-bottom: 15px;
        }
        .report-card p {
            color: #fff;
        }
        .report-card .btn-report {
            background: white;
            color: #FF8C00;
            border: none;
            padding: 10px 30px;
            border-radius: 50px;
            font-weight: bold;
            margin-top: 15px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-align: center;
        }
        .report-card .btn-report i {
            font-size: 1rem;
            margin-bottom: 0;
            line-height: 1;
        }
        .report-card .btn-report:hover {
            transform: scale(1.05);
            background: #f8f9fa;
        }
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            transition: transform 0.3s;
        }
        .stat-card:hover {
            transform: translateY(-3px);
        }
        .stat-card i {
            font-size: 36px;
            color: #FF8C00;
            margin-bottom: 10px;
        }
        .stat-card h3 {
            font-size: 28px;
            margin: 10px 0;
            color: #333;
        }
        .stat-card p {
            color: #666;
            margin: 0;
        }
        .section-title {
            border-left: 5px solid #FF8C00;
            padding-left: 15px;
            margin: 30px 0 20px 0;
            color: #333;
        }

        /* Item Card Styles - Matching index.php */
        .item-card {
            transition: transform 0.2s, box-shadow 0.2s;
            border-radius: 12px;
            overflow: hidden;
        }
        .item-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        .item-card-image {
            height: 180px;
            width: 100%;
            object-fit: cover;
        }
        .item-card-placeholder {
            height: 180px;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: #f8f9fa;
        }
        .item-card .card-body {
            padding: 15px;
        }
        /* Fix for badge alignment */
        .item-card .d-flex {
            display: flex !important;
            justify-content: space-between !important;
            align-items: flex-start !important;
            width: 100%;
        }
        .item-card .card-title {
            flex: 1;
            word-break: break-word;
            padding-right: 10px;
            margin-bottom: 0;
            font-size: 0.9rem;
            font-weight: 600;
            line-height: 1.4;
        }
        .status-badge {
            flex-shrink: 0;
            white-space: nowrap;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
            color: white;
        }

        .status-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        min-width: 60px;
        padding: 3px 8px;
        border-radius: 20px;
        white-space: nowrap;
        text-align: center;
        font-size: 0.75rem !important;
        font-weight: 500;
        line-height: 1.2;
        color: white;
        }

        .badge-lost { background-color: #dc3545; }
        .badge-found { background-color: #28a745; }
        .badge-returned { background-color: #17a2b8; }
        .item-meta {
            margin-top: 10px;
        }
        .item-meta-row {
            display: flex;
            align-items: center;
            margin-bottom: 6px;
        }
        .item-meta-row i {
            width: 18px;
            font-size: 11px;
            color: #FF8C00;
        }
        .item-meta-row span {
            font-size: 12px;
            color: #6c757d;
        }
        .card-footer {
            background-color: transparent;
            border-top: 1px solid #e9ecef;
            padding: 12px 15px;
        }
        .card-footer .btn {
            width: 100%;
            font-size: 12px;
            padding: 6px 12px;
        }
        .quick-nav-buttons .btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            text-align: center;
        }
        .quick-nav-buttons .btn i {
            line-height: 1;
        }

        /* Notification Styles */
        .notification-list {
            max-height: 400px;
            overflow-y: auto;
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
        .notification-icon-sm {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-right: 12px;
            flex-shrink: 0;
        }
        .notification-icon-sm.info { background: #e3f2fd; color: #2196f3; }
        .notification-icon-sm.success { background: #e8f5e9; color: #4caf50; }
        .notification-icon-sm.warning { background: #fff3e0; color: #ff9800; }
        .notification-icon-sm.danger { background: #ffebee; color: #f44336; }
        .notification-content-sm {
            flex: 1;
            min-width: 0;
        }
        .notification-title-sm {
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 3px;
            color: #333;
        }
        .notification-message-sm {
            font-size: 12px;
            color: #666;
            margin-bottom: 3px;
        }
        .notification-time-sm {
            font-size: 11px;
            color: #999;
        }
        .view-all-link {
            display: block;
            text-align: center;
            padding: 10px;
            background: #f8f9fa;
            color: #FF8C00;
            text-decoration: none;
            font-weight: 600;
            font-size: 13px;
            border-top: 1px solid #e0e0e0;
        }
        .view-all-link:hover {
            background: #e9ecef;
            text-decoration: underline;
        }
        .no-notifications {
            padding: 40px 20px;
            text-align: center;
            color: #999;
        }
        .no-notifications i {
            font-size: 48px;
            margin-bottom: 10px;
            opacity: 0.5;
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../includes/header.php'; ?>

    <main class="page-shell page-shell--compact">
    <div class="container content-wrapper dashboard-content">
        <!-- Welcome Banner -->
        <div class="alert alert-success fade-in" style="background: linear-gradient(135deg, #d4fc79 0%, #96e6a1 100%); border: none;">
            <h4 class="mb-2"><i class="fas fa-smile-wink"></i> Welcome back, <?= htmlspecialchars($_SESSION['name']) ?>!</h4>
            <p class="mb-0">Thank you for helping reunite people with their lost belongings.</p>
        </div>

        <!-- Main Report Section -->
        <div class="row mb-5">
            <div class="col-md-6">
                <div class="report-card">
                    <i class="fas fa-frown"></i>
                    <h3>I Lost an Item</h3>
                    <p>Report a lost item and get help finding it</p>
                    <a href="<?= $base_url ?>user/report-item.php?type=lost" class="btn btn-report">
                        <i class="fas fa-plus-circle"></i> Report Lost Item
                    </a>
                </div>
            </div>
            <div class="col-md-6">
                <div class="report-card">
                    <i class="fas fa-smile"></i>
                    <h3>I Found an Item</h3>
                    <p>Report a found item and help someone reclaim it</p>
                    <a href="<?= $base_url ?>user/report-item.php?type=found" class="btn btn-report">
                        <i class="fas fa-plus-circle"></i> Report Found Item
                    </a>
                </div>
            </div>
        </div>

        <!-- Statistics Section -->
        <h4 class="section-title"><i class="fas fa-chart-line"></i> Your Activity Statistics</h4>
        <div class="row mb-5">
            <div class="col-md-4 mb-3">
                <div class="stat-card">
                    <i class="fas fa-clipboard-list"></i>
                    <h3><?= $stats['my_reports'] ?></h3>
                    <p>Items Reported</p>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="stat-card">
                    <i class="fas fa-hand-paper"></i>
                    <h3><?= $stats['my_claims'] ?></h3>
                    <p>Claims Submitted</p>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="stat-card">
                    <i class="fas fa-check-circle"></i>
                    <h3><?= $stats['approved_claims'] ?></h3>
                    <p>Approved Claims</p>
                </div>
            </div>
        </div>

        <!-- Recent Items Reported -->
        <h4 class="section-title"><i class="fas fa-history"></i> Recently Reported Items</h4>
        <div class="row g-4 mb-4">
            <?php if(empty($recent_items)): ?>
                <div class="col-12">
                    <div class="alert alert-info text-center">
                        <i class="fas fa-info-circle"></i> You haven't reported any items yet.
                        <a href="<?= $base_url ?>user/report-item.php" class="alert-link">Click here to report your first item</a>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach($recent_items as $item): ?>
                <div class="col-md-6 col-xl-4">
                    <div class="card item-card h-100">
                        <?php
                        $hasImage = !empty($item['image_url']) && file_exists(__DIR__ . '/../' . $item['image_url']);
                        $imageUrl = $hasImage ? $base_url . $item['image_url'] : '';
                        ?>
                        <?php if($hasImage): ?>
                            <img src="<?= $imageUrl ?>" class="item-card-image" alt="Item image">
                        <?php else: ?>
                            <div class="item-card-placeholder">
                                <i class="fas fa-box-open fa-4x" style="color: #FF8C00;"></i>
                            </div>
                        <?php endif; ?>
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start">
                                <h6 class="card-title"><?= htmlspecialchars(substr($item['title'] ?? $item['description'], 0, 60)) ?>...</h6>
                                <span class="status-badge <?= ($item['status'] ?? 'found') == 'lost' ? 'badge-lost' : (($item['status'] ?? 'found') == 'returned' ? 'badge-returned' : 'badge-found') ?>">
                                    <?= ucfirst($item['status'] ?? 'found') ?>
                                </span>
                            </div>
                            <div class="item-meta">
                                <div class="item-meta-row">
                                    <span><?= htmlspecialchars($item['found_location'] ?? $item['location'] ?? 'N/A') ?></span>
                                </div>
                                <div class="item-meta-row">
                                    <i class="fas fa-tag"></i>
                                    <span><?= htmlspecialchars($item['category'] ?? 'N/A') ?></span>
                                </div>
                                <div class="item-meta-row">
                                    <i class="fas fa-calendar"></i>
                                    <span><?= date('M d, Y', strtotime($item['reported_date'])) ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="card-footer">
                            <a href="<?= $base_url ?>item-details.php?id=<?= $item['item_id'] ?>" class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-eye me-1"></i> View Details
                            </a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="row">
            <!-- Recent Activity -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="fas fa-history"></i> Recent Activity</h5>
                    </div>
                    <div class="card-body">
                        <?php
                        $stmt = $db->prepare("
                            (SELECT 'report' as type, item_id, reported_date as date FROM items WHERE reported_by = ?)
                            UNION
                            (SELECT 'claim' as type, claim_id, created_at as date FROM claim_requests WHERE claimant_id = ?)
                            ORDER BY date DESC LIMIT 10
                        ");
                        $stmt->execute([$userID, $userID]);
                        $activities = $stmt->fetchAll();
                        ?>

                        <?php if(empty($activities)): ?>
                            <p class="text-muted text-center py-3">No recent activity</p>
                        <?php else: ?>
                            <ul class="list-unstyled">
                                <?php foreach($activities as $activity): ?>
                                    <li class="mb-2 pb-2 border-bottom">
                                        <i class="fas fa-<?= $activity['type'] == 'report' ? 'flag-checkered' : 'file-alt' ?> me-2" style="color: #FF8C00;"></i>
                                        <strong><?= ucfirst($activity['type']) ?></strong> submitted
                                        <small class="text-muted float-end"><?= time_ago($activity['date']) ?></small>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Recent Notifications -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5><i class="fas fa-bell"></i> Recent Notifications</h5>
                        <?php if($unread_count > 0): ?>
                            <span class="badge bg-danger"><?= $unread_count ?> new</span>
                        <?php endif; ?>
                    </div>
                    <div class="card-body p-0">
                        <?php if(empty($notifications)): ?>
                            <div class="no-notifications">
                                <i class="fas fa-bell-slash"></i>
                                <p>No notifications yet</p>
                                <small>You'll see updates here when someone interacts with your reports</small>
                            </div>
                        <?php else: ?>
                            <div class="notification-list">
                                <?php foreach($notifications as $notif): ?>
                                    <div class="notification-item <?= $notif['is_read'] == 0 ? 'unread' : '' ?>" onclick="window.location.href='<?= $base_url ?>user/notifications.php'">
                                        <div class="d-flex">
                                            <div class="notification-icon-sm <?= $notif['type'] ?>">
                                                <i class="fas <?= $notif['type'] == 'info' ? 'fa-info-circle' : ($notif['type'] == 'success' ? 'fa-check-circle' : ($notif['type'] == 'warning' ? 'fa-exclamation-triangle' : 'fa-times-circle')) ?>"></i>
                                            </div>
                                            <div class="notification-content-sm">
                                                <div class="notification-title-sm"><?= htmlspecialchars($notif['title'] ?? 'Notification') ?></div>
                                                <div class="notification-message-sm"><?= htmlspecialchars(substr($notif['message'], 0, 80)) ?><?= strlen($notif['message']) > 80 ? '...' : '' ?></div>
                                                <div class="notification-time-sm">
                                                    <i class="far fa-clock me-1"></i><?= time_ago($notif['created_at']) ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <a href="<?= $base_url ?>user/notifications.php" class="view-all-link">
                                <i class="fas fa-arrow-right me-1"></i> View all notifications
                                <?php if($unread_count > 0): ?>
                                    <span class="badge bg-danger ms-1"><?= $unread_count ?> new</span>
                                <?php endif; ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mt-4">
            <!-- Quick Navigation -->
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="fas fa-compass"></i> Quick Navigation</h5>
                    </div>
                    <div class="card-body">
                        <div class="row quick-nav-buttons">
                            <div class="col-md-3 mb-2">
                                <a href="<?= $base_url ?>search.php" class="btn btn-outline-primary w-100 text-center">
                                    <i class="fas fa-search"></i> Search for Items
                                </a>
                            </div>
                            <div class="col-md-3 mb-2">
                                <a href="<?= $base_url ?>user/my-claims.php" class="btn btn-outline-primary w-100 text-center">
                                    <i class="fas fa-file-alt"></i> View My Claims
                                </a>
                            </div>
                            <div class="col-md-3 mb-2">
                                <a href="<?= $base_url ?>user/user-profile.php" class="btn btn-outline-primary w-100 text-center">
                                    <i class="fas fa-user"></i> My Profile
                                </a>
                            </div>
                            <div class="col-md-3 mb-2">
                                <a href="<?= $base_url ?>user/dashboard.php" class="btn btn-outline-primary w-100 text-center">
                                    <i class="fas fa-tachometer-alt"></i> Dashboard
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    </main>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
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
