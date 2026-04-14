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
        .report-card:hover {
            transform: translateY(-5px);
        }
        .report-card i {
            font-size: 48px;
            margin-bottom: 15px;
        }
        .report-card h3 {
            margin-bottom: 15px;
        }
        .report-card .btn-report {
            background: white;
            color: #FF8C00;
            border: none;
            padding: 10px 30px;
            border-radius: 50px;
            font-weight: bold;
            margin-top: 15px;
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
    </style>
</head>
<body>
    <?php include __DIR__ . '/../includes/header.php'; ?>
    
    <div class="container mt-4">
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
        <div class="row mb-4">
            <?php if(empty($recent_items)): ?>
                <div class="col-12">
                    <div class="alert alert-info text-center">
                        <i class="fas fa-info-circle"></i> You haven't reported any items yet.
                        <a href="<?= $base_url ?>user/report-item.php" class="alert-link">Click here to report your first item</a>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach($recent_items as $item): ?>
                <div class="col-md-4 mb-3">
                    <div class="card h-100">
                        <?php if($item['image_url']): ?>
                            <img src="<?= $base_url . $item['image_url'] ?>" class="card-img-top" alt="Item image" style="height: 150px; object-fit: cover;">
                        <?php else: ?>
                            <div class="card-img-top bg-light d-flex align-items-center justify-content-center" style="height: 150px;">
                                <i class="fas fa-box-open fa-3x" style="color: #FF8C00;"></i>
                            </div>
                        <?php endif; ?>
                        <div class="card-body">
                            <h6 class="card-title"><?= htmlspecialchars(substr($item['description'], 0, 50)) ?>...</h6>
                            <p class="card-text small">
                                <i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($item['found_location'] ?? $item['location']) ?><br>
                                <i class="fas fa-calendar"></i> <?= date('M d, Y', strtotime($item['reported_date'])) ?>
                            </p>
                            <span class="badge bg-<?= $item['status'] == 'lost' ? 'danger' : 'success' ?>">
                                <?= ucfirst($item['status']) ?>
                            </span>
                        </div>
                        <div class="card-footer bg-transparent">
                            <a href="<?= $base_url ?>item-details.php?id=<?= $item['item_id'] ?>" class="btn btn-sm btn-outline-primary">View Details</a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="row">
            <!-- Recent Activity - FIXED: Use correct column names -->
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
            
            <!-- Quick Navigation -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="fas fa-compass"></i> Quick Navigation</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6 mb-2">
                                <a href="<?= $base_url ?>search.php" class="btn btn-outline-primary w-100 text-start">
                                    <i class="fas fa-search"></i> Search for Items
                                </a>
                            </div>
                            <div class="col-md-6 mb-2">
                                <a href="<?= $base_url ?>user/my-claims.php" class="btn btn-outline-primary w-100 text-start">
                                    <i class="fas fa-file-alt"></i> View My Claims
                                </a>
                            </div>
                            <div class="col-md-6 mb-2">
                                <a href="<?= $base_url ?>user/user-profile.php" class="btn btn-outline-primary w-100 text-start">
                                    <i class="fas fa-user"></i> My Profile
                                </a>
                            </div>
                            <div class="col-md-6 mb-2">
                                <a href="<?= $base_url ?>user/dashboard.php" class="btn btn-outline-primary w-100 text-start">
                                    <i class="fas fa-tachometer-alt"></i> Dashboard
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>

<?php
function time_ago($timestamp) {
    $time_ago = strtotime($timestamp);
    $current_time = time();
    $time_difference = $current_time - $time_ago;
    $seconds = $time_difference;
    
    $minutes = round($seconds / 60);
    $hours = round($seconds / 3600);
    $days = round($seconds / 86400);
    $weeks = round($seconds / 604800);
    $months = round($seconds / 2629440);
    $years = round($seconds / 31553280);
    
    if($seconds <= 60) return "Just now";
    else if($minutes <= 60) return ($minutes == 1) ? "1 minute ago" : "$minutes minutes ago";
    else if($hours <= 24) return ($hours == 1) ? "1 hour ago" : "$hours hours ago";
    else if($days <= 7) return ($days == 1) ? "yesterday" : "$days days ago";
    else if($weeks <= 4.3) return ($weeks == 1) ? "1 week ago" : "$weeks weeks ago";
    else if($months <= 12) return ($months == 1) ? "1 month ago" : "$months months ago";
    else return ($years == 1) ? "1 year ago" : "$years years ago";
}
?>