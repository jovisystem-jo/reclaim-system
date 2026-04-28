<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
requireAdmin();

$db = Database::getInstance()->getConnection();

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$search = $_GET['search'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Build query for claim history
$sql = "
    SELECT c.*, 
           i.title as item_title, 
           i.description as item_description, 
           i.image_url,
           i.found_location, 
           i.delivery_location, 
           i.brand, 
           i.color,
           i.category,
           u.name as claimant_name, 
           u.email as claimant_email, 
           u.phone as claimant_phone,
           u.student_staff_id as claimant_student_id, 
           u.department as claimant_department,
           admin.name as verified_by_name
    FROM claim_requests c
    JOIN items i ON c.item_id = i.item_id
    JOIN users u ON c.claimant_id = u.user_id
    LEFT JOIN users admin ON c.verified_by = admin.user_id
    WHERE c.status IN ('approved', 'completed', 'rejected')
";

$params = [];

if ($status_filter !== 'all') {
    $sql .= " AND c.status = ?";
    $params[] = $status_filter;
}

if (!empty($search)) {
    $sql .= " AND (i.title LIKE ? OR u.name LIKE ? OR u.email LIKE ? OR c.claim_id LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

if (!empty($date_from)) {
    $sql .= " AND DATE(c.verified_date) >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $sql .= " AND DATE(c.verified_date) <= ?";
    $params[] = $date_to;
}

// Get total count for pagination
$count_sql = str_replace("SELECT c.*, i.title as item_title, i.description as item_description, i.image_url, i.found_location, i.delivery_location, i.brand, i.color, i.category, u.name as claimant_name, u.email as claimant_email, u.phone as claimant_phone, u.student_staff_id as claimant_student_id, u.department as claimant_department, admin.name as verified_by_name", "SELECT COUNT(*)", $sql);
$stmt = $db->prepare($count_sql);
$stmt->execute($params);
$total_records = $stmt->fetchColumn();
$total_pages = ceil($total_records / $per_page);

// Add pagination to main query
$sql .= " ORDER BY c.verified_date DESC LIMIT ? OFFSET ?";
$params[] = $per_page;
$params[] = $offset;

$stmt = $db->prepare($sql);
$stmt->execute($params);
$claims = $stmt->fetchAll();

// Get statistics
$stats_sql = "
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected
    FROM claim_requests
    WHERE status IN ('approved', 'completed', 'rejected')
";
$stats_stmt = $db->prepare($stats_sql);
$stats_stmt->execute();
$stats = $stats_stmt->fetch();

// Get this month's count
$this_month = date('Y-m');
$month_sql = "SELECT COUNT(*) FROM claim_requests WHERE status IN ('approved', 'completed', 'rejected') AND DATE_FORMAT(verified_date, '%Y-%m') = ?";
$month_stmt = $db->prepare($month_sql);
$month_stmt->execute([$this_month]);
$stats['this_month'] = $month_stmt->fetchColumn();

$base_url = '/reclaim-system/';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Claim History - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $base_url ?>assets/css/style.css">
    <style>
        * { font-family: 'Inter', sans-serif; }
        body { background: #f8f9fa; }
        .main-content { padding: 20px; min-height: 100vh; }
        
        /* Button Styles */
        .btn-primary-custom {
            background: linear-gradient(135deg, #FF6B35, #E85D2C);
            border: none;
            padding: 8px 20px;
            border-radius: 50px;
            transition: all 0.3s;
            color: white;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.85rem;
            font-weight: 500;
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
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.85rem;
            font-weight: 500;
        }
        .btn-secondary-custom:hover {
            background: #5a6268;
            transform: translateY(-2px);
            color: white;
        }
        .btn-outline-filter {
            background: transparent;
            border: 1px solid #dee2e6;
            padding: 6px 15px;
            border-radius: 8px;
            font-size: 0.8rem;
            transition: all 0.2s;
        }
        .btn-outline-filter:hover {
            background: #f8f9fa;
            border-color: #FF6B35;
        }
        .btn-outline-filter.active {
            background: #FF6B35;
            border-color: #FF6B35;
            color: white;
        }
        
        /* Stats Cards */
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 18px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            transition: transform 0.2s;
            height: 100%;
        }
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .stat-card i {
            font-size: 32px;
            margin-bottom: 8px;
        }
        .stat-card h3 {
            font-size: 28px;
            font-weight: 700;
            margin: 5px 0;
        }
        .stat-card p {
            color: #6c757d;
            margin: 0;
            font-size: 13px;
        }
        .stat-card.approved i { color: #28a745; }
        .stat-card.completed i { color: #17a2b8; }
        .stat-card.rejected i { color: #dc3545; }
        .stat-card.total i { color: #FF6B35; }
        .stat-card.month i { color: #6c757d; }
        
        /* Search Filter Bar */
        .filter-bar {
            background: white;
            border-radius: 15px;
            padding: 18px 20px;
            margin-bottom: 24px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        /* Claim History Card */
        .history-card {
            background: white;
            border-radius: 15px;
            margin-bottom: 16px;
            overflow: hidden;
            box-shadow: 0 2px 6px rgba(0,0,0,0.05);
            transition: all 0.2s;
        }
        .history-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .history-card-header {
            padding: 12px 20px;
            background: #fafbfc;
            border-bottom: 1px solid #eef2f6;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .history-card-body {
            padding: 15px 20px;
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }
        .history-item-image {
            width: 70px;
            height: 70px;
            object-fit: cover;
            border-radius: 10px;
            flex-shrink: 0;
        }
        .history-item-placeholder {
            width: 70px;
            height: 70px;
            background-color: #f8f9fa;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .history-item-placeholder i {
            font-size: 28px;
            color: #FF8C00;
        }
        .history-details {
            flex: 1;
            min-width: 200px;
        }
        .history-title {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 5px;
            color: #2C3E50;
        }
        .history-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-top: 8px;
        }
        .history-meta-item {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 0.75rem;
            color: #6c757d;
        }
        .history-meta-item i {
            width: 14px;
            color: #FF8C00;
        }
        .history-footer {
            padding: 10px 20px;
            background: #f8f9fa;
            border-top: 1px solid #eef2f6;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .status-approved {
            background: #D1FAE5;
            color: #059669;
        }
        .status-completed {
            background: #DBEAFE;
            color: #2563EB;
        }
        .status-rejected {
            background: #FEE2E2;
            color: #DC2626;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 20px;
        }
        .empty-state i {
            font-size: 64px;
            color: #dee2e6;
            margin-bottom: 16px;
        }
        .empty-state h4 {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 8px;
        }
        .empty-state p {
            color: #6c757d;
            margin-bottom: 20px;
        }
        
        /* Pagination */
        .pagination {
            margin-top: 20px;
            justify-content: center;
        }
        .pagination .page-link {
            border-radius: 8px;
            margin: 0 3px;
            color: #FF6B35;
        }
        .pagination .page-item.active .page-link {
            background: #FF6B35;
            border-color: #FF6B35;
            color: white;
        }
        .pagination .page-item.disabled .page-link {
            color: #6c757d;
        }
        
        /* Export Buttons */
        .export-buttons {
            display: flex;
            gap: 10px;
        }
        .btn-export {
            background: #28a745;
            color: white;
            padding: 6px 15px;
            border-radius: 8px;
            font-size: 0.75rem;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }
        .btn-export:hover {
            background: #218838;
            color: white;
        }
        .btn-export-excel {
            background: #28a745;
        }
        .btn-export-pdf {
            background: #dc3545;
        }
        .btn-export-pdf:hover {
            background: #c82333;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .history-card-body {
                flex-direction: column;
                align-items: center;
                text-align: center;
            }
            .history-meta {
                justify-content: center;
            }
            .history-footer {
                justify-content: center;
            }
            .filter-bar .row {
                gap: 10px;
            }
            .export-buttons {
                margin-top: 10px;
            }
        }
    </style>
</head>
<body class="app-page admin-page">
    <div class="container-fluid">
        <div class="row">
            <?php include 'sidebar.php'; ?>
            
            <div class="col-md-10 main-content content-wrapper">
                <!-- Page Header -->
                <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
                    <h2 style="font-size: 1.5rem; font-weight: 700; margin: 0;">
                        <i class="fas fa-history me-2" style="color: #FF6B35;"></i> Claim History
                    </h2>
                    <div class="d-flex gap-2 flex-wrap">
                        
                        <a href="verify-claims.php" class="btn btn-secondary-custom">
                            <i class="fas fa-arrow-left"></i> Back to Verify Claims
                        </a>
                    </div>
                </div>
                
                <!-- Statistics Cards -->
                <div class="row mb-4 g-3">
                    <div class="col-md-2 col-6">
                        <div class="stat-card total">
                            <i class="fas fa-chart-line"></i>
                            <h3><?= number_format($stats['total']) ?></h3>
                            <p>Total Claims</p>
                        </div>
                    </div>
                    <div class="col-md-2 col-6">
                        <div class="stat-card approved">
                            <i class="fas fa-check-circle"></i>
                            <h3><?= number_format($stats['approved']) ?></h3>
                            <p>Approved</p>
                        </div>
                    </div>
                    <div class="col-md-2 col-6">
                        <div class="stat-card completed">
                            <i class="fas fa-check-double"></i>
                            <h3><?= number_format($stats['completed']) ?></h3>
                            <p>Completed</p>
                        </div>
                    </div>
                    <div class="col-md-2 col-6">
                        <div class="stat-card rejected">
                            <i class="fas fa-times-circle"></i>
                            <h3><?= number_format($stats['rejected']) ?></h3>
                            <p>Rejected</p>
                        </div>
                    </div>
                    <div class="col-md-2 col-6">
                        <div class="stat-card month">
                            <i class="fas fa-calendar-month"></i>
                            <h3><?= number_format($stats['this_month']) ?></h3>
                            <p>This Month</p>
                        </div>
                    </div>
                </div>
                
                <!-- Filter Bar -->
                <div class="filter-bar">
                    <form method="GET" action="" class="row g-3 align-items-end">
                        <div class="col-md-2">
                            <label class="form-label fw-bold small">Status</label>
                            <select name="status" class="form-select form-select-sm">
                                <option value="all" <?= $status_filter == 'all' ? 'selected' : '' ?>>All Status</option>
                                <option value="approved" <?= $status_filter == 'approved' ? 'selected' : '' ?>>Approved</option>
                                <option value="completed" <?= $status_filter == 'completed' ? 'selected' : '' ?>>Completed</option>
                                <option value="rejected" <?= $status_filter == 'rejected' ? 'selected' : '' ?>>Rejected</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold small">Search</label>
                            <input type="text" name="search" class="form-control form-control-sm" 
                                   placeholder="Item, Claimant, Email, ID..." 
                                   value="<?= htmlspecialchars($search) ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-bold small">From Date</label>
                            <input type="date" name="date_from" class="form-control form-control-sm" 
                                   value="<?= htmlspecialchars($date_from) ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-bold small">To Date</label>
                            <input type="date" name="date_to" class="form-control form-control-sm" 
                                   value="<?= htmlspecialchars($date_to) ?>">
                        </div>
                        <div class="col-md-3">
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary-custom btn-sm w-100">
                                    <i class="fas fa-search"></i> Filter
                                </button>
                                <a href="claim-history.php" class="btn btn-secondary-custom btn-sm">
                                    <i class="fas fa-redo"></i> Reset
                                </a>
                            </div>
                        </div>
                    </form>
                </div>
                
                <!-- Results Summary -->
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <small class="text-muted">
                        <i class="fas fa-list me-1"></i> Showing <?= count($claims) ?> of <?= $total_records ?> records
                    </small>
                </div>
                
                <!-- Claims History List -->
                <?php if(empty($claims)): ?>
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <h4>No claim history found</h4>
                        <p>There are no processed claims matching your criteria.</p>
                        <a href="verify-claims.php" class="btn btn-primary-custom">
                            <i class="fas fa-check-double"></i> Go to Verify Claims
                        </a>
                    </div>
                <?php else: ?>
                    <?php foreach($claims as $claim): ?>
                    <div class="history-card">
                        <div class="history-card-header">
                            <div>
                                <strong>Claim #<?= $claim['claim_id'] ?></strong>
                                <small class="text-muted ms-2">
                                    <i class="fas fa-calendar-alt"></i> Submitted: <?= date('M d, Y h:i A', strtotime($claim['created_at'])) ?>
                                </small>
                            </div>
                            <div>
                                <?php if($claim['status'] == 'approved'): ?>
                                    <span class="status-badge status-approved"><i class="fas fa-check-circle"></i> Approved</span>
                                <?php elseif($claim['status'] == 'completed'): ?>
                                    <span class="status-badge status-completed"><i class="fas fa-check-double"></i> Completed</span>
                                <?php elseif($claim['status'] == 'rejected'): ?>
                                    <span class="status-badge status-rejected"><i class="fas fa-times-circle"></i> Rejected</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="history-card-body">
                            <?php 
                            $hasImage = !empty($claim['image_url']) && file_exists(__DIR__ . '/../' . $claim['image_url']);
                            $imageUrl = $hasImage ? $base_url . $claim['image_url'] : '';
                            ?>
                            <?php if($hasImage): ?>
                                <img src="<?= $imageUrl ?>" class="history-item-image" alt="Item image">
                            <?php else: ?>
                                <div class="history-item-placeholder">
                                    <i class="fas fa-box-open"></i>
                                </div>
                            <?php endif; ?>
                            
                            <div class="history-details">
                                <div class="history-title"><?= htmlspecialchars($claim['item_title']) ?></div>
                                <div class="history-meta">
                                    <div class="history-meta-item">
                                        <i class="fas fa-user"></i>
                                        <span>Claimant: <?= htmlspecialchars($claim['claimant_name']) ?></span>
                                    </div>
                                    <div class="history-meta-item">
                                        <i class="fas fa-tag"></i>
                                        <span><?= htmlspecialchars($claim['category'] ?? 'N/A') ?></span>
                                    </div>
                                    <div class="history-meta-item">
                                        <i class="fas fa-map-marker-alt"></i>
                                        <span><?= htmlspecialchars($claim['found_location'] ?? 'N/A') ?></span>
                                    </div>
                                    <div class="history-meta-item">
                                        <i class="fas fa-building"></i>
                                        <span>Keep at: <?= htmlspecialchars($claim['delivery_location'] ?? 'N/A') ?></span>
                                    </div>
                                </div>
                                <?php if($claim['verified_date']): ?>
                                <div class="history-meta mt-2">
                                    <div class="history-meta-item">
                                        <i class="fas fa-check-circle" style="color: #28a745;"></i>
                                        <span>Verified on: <?= date('F d, Y \a\t h:i A', strtotime($claim['verified_date'])) ?></span>
                                    </div>
                                    <?php if($claim['verified_by_name']): ?>
                                    <div class="history-meta-item">
                                        <i class="fas fa-user-shield"></i>
                                        <span>Verified by: <?= htmlspecialchars($claim['verified_by_name']) ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                                <?php if($claim['admin_notes']): ?>
                                <div class="mt-2 p-2 bg-light rounded small">
                                    <i class="fas fa-sticky-note me-1" style="color: #FF6B35;"></i>
                                    <strong>Admin Notes:</strong> <?= nl2br(htmlspecialchars(substr($claim['admin_notes'], 0, 100))) ?>
                                    <?php if(strlen($claim['admin_notes']) > 100): ?>...<?php endif; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="history-footer">
                            <a href="view-claim-report.php?id=<?= $claim['claim_id'] ?>" class="btn btn-primary-custom btn-action-compact" target="_blank">
                                <i class="fas fa-file-alt"></i> View Report
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    
                    <!-- Pagination -->
                    <?php if($total_pages > 1): ?>
                    <nav aria-label="Page navigation">
                        <ul class="pagination">
                            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="?page=<?= $page-1 ?>&status=<?= urlencode($status_filter) ?>&search=<?= urlencode($search) ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>">
                                    <i class="fas fa-chevron-left"></i> Previous
                                </a>
                            </li>
                            <?php for($i = 1; $i <= $total_pages; $i++): ?>
                                <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                    <a class="page-link" href="?page=<?= $i ?>&status=<?= urlencode($status_filter) ?>&search=<?= urlencode($search) ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>">
                                        <?= $i ?>
                                    </a>
                                </li>
                            <?php endfor; ?>
                            <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                                <a class="page-link" href="?page=<?= $page+1 ?>&status=<?= urlencode($status_filter) ?>&search=<?= urlencode($search) ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>">
                                    Next <i class="fas fa-chevron-right"></i>
                                </a>
                            </li>
                        </ul>
                    </nav>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>