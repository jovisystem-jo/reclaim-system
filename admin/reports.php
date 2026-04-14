<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
requireAdmin();

$db = Database::getInstance()->getConnection();
$message = '';
$error = '';

// Get date range for reports
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$report_type = $_GET['report_type'] ?? 'overview';

// Fetch statistics for different reports
// 1. Overview Statistics
$total_users = $db->query("SELECT COUNT(*) FROM users WHERE role != 'admin'")->fetchColumn();
$total_items = $db->query("SELECT COUNT(*) FROM items")->fetchColumn();
$total_claims = $db->query("SELECT COUNT(*) FROM claim_requests")->fetchColumn();
$pending_claims = $db->query("SELECT COUNT(*) FROM claim_requests WHERE status = 'pending'")->fetchColumn();
$approved_claims = $db->query("SELECT COUNT(*) FROM claim_requests WHERE status = 'approved'")->fetchColumn();
$rejected_claims = $db->query("SELECT COUNT(*) FROM claim_requests WHERE status = 'rejected'")->fetchColumn();

// 2. Items by Status
$items_by_status = [];
$statuses = ['lost', 'found', 'returned', 'resolved'];
foreach ($statuses as $status) {
    $items_by_status[$status] = $db->query("SELECT COUNT(*) FROM items WHERE status = '$status'")->fetchColumn();
}

// 3. Items by Category
$stmt = $db->query("
    SELECT category, COUNT(*) as count 
    FROM items 
    WHERE category IS NOT NULL AND category != ''
    GROUP BY category 
    ORDER BY count DESC
");
$items_by_category = $stmt->fetchAll();

// 4. Claims by Month (Last 12 months)
$stmt = $db->prepare("
    SELECT 
        DATE_FORMAT(created_at, '%Y-%m') as month,
        COUNT(*) as total,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected
    FROM claim_requests
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
    ORDER BY month DESC
");
$stmt->execute();
$claims_by_month = $stmt->fetchAll();

// 5. Items by Month (Last 12 months)
$stmt = $db->prepare("
    SELECT 
        DATE_FORMAT(reported_date, '%Y-%m') as month,
        COUNT(*) as total,
        SUM(CASE WHEN status = 'lost' THEN 1 ELSE 0 END) as lost,
        SUM(CASE WHEN status = 'found' THEN 1 ELSE 0 END) as found
    FROM items
    WHERE reported_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(reported_date, '%Y-%m')
    ORDER BY month DESC
");
$stmt->execute();
$items_by_month = $stmt->fetchAll();

// 6. Top Reporters
$stmt = $db->prepare("
    SELECT u.name, u.email, COUNT(i.item_id) as items_reported
    FROM users u
    JOIN items i ON u.user_id = i.reported_by
    WHERE u.role != 'admin'
    GROUP BY u.user_id
    ORDER BY items_reported DESC
    LIMIT 10
");
$stmt->execute();
$top_reporters = $stmt->fetchAll();

// 7. Recent Activity
$stmt = $db->prepare("
    (SELECT 'item' as type, i.item_id as id, i.title as name, i.status, i.reported_date as date, u.name as user_name
     FROM items i
     JOIN users u ON i.reported_by = u.user_id
     ORDER BY i.reported_date DESC LIMIT 10)
    UNION ALL
    (SELECT 'claim' as type, c.claim_id as id, i.title as name, c.status, c.created_at as date, u.name as user_name
     FROM claim_requests c
     JOIN items i ON c.item_id = i.item_id
     JOIN users u ON c.claimant_id = u.user_id
     ORDER BY c.created_at DESC LIMIT 10)
    ORDER BY date DESC
    LIMIT 20
");
$stmt->execute();
$recent_activity = $stmt->fetchAll();

// 8. User Registration Stats (Last 12 months)
$stmt = $db->prepare("
    SELECT 
        DATE_FORMAT(created_at, '%Y-%m') as month,
        COUNT(*) as new_users
    FROM users
    WHERE role != 'admin'
    AND created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
    ORDER BY month DESC
");
$stmt->execute();
$user_registrations = $stmt->fetchAll();

// Calculate success rate
$success_rate = $total_claims > 0 ? round(($approved_claims / $total_claims) * 100, 1) : 0;

$base_url = '/reclaim-system/';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
            font-size: 2rem;
            font-weight: 800;
            margin: 5px 0;
        }
        
        .stat-card p {
            color: #6c757d;
            margin: 0;
            font-weight: 500;
            font-size: 0.9rem;
        }
        
        .stat-card.users i { color: #FF6B35; }
        .stat-card.items i { color: #3498DB; }
        .stat-card.claims i { color: #9B59B6; }
        .stat-card.pending i { color: #F39C12; }
        .stat-card.approved i { color: #27AE60; }
        .stat-card.rejected i { color: #E74C3C; }
        .stat-card.rate i { color: #1ABC9C; }
        
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
            padding: 8px 20px;
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
        
        .status-badge {
            padding: 5px 12px;
            border-radius: 50px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }
        .status-lost { background: #dc3545; color: white; }
        .status-found { background: #28a745; color: white; }
        .status-pending { background: #ffc107; color: #333; }
        .status-approved { background: #28a745; color: white; }
        .status-rejected { background: #dc3545; color: white; }
        
        .category-badge {
            padding: 5px 12px;
            border-radius: 50px;
            font-size: 11px;
            font-weight: 500;
            display: inline-block;
            background: #f0f2f5;
            color: #2C3E50;
        }
        
        .chart-container {
            position: relative;
            height: 300px;
            margin: 20px 0;
        }
        
        .export-buttons {
            margin-bottom: 20px;
        }
        
        .export-btn {
            padding: 8px 15px;
            border-radius: 10px;
            font-size: 13px;
            margin-right: 10px;
        }
        
        .table-custom th {
            background: #f8f9fa;
            font-weight: 600;
            color: #2C3E50;
        }
        
        .activity-item {
            padding: 12px 0;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            align-items: center;
        }
        
        .activity-item:last-child {
            border-bottom: none;
        }
        
        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
        }
        
        .activity-icon.item {
            background: rgba(52,152,219,0.1);
            color: #3498DB;
        }
        
        .activity-icon.claim {
            background: rgba(155,89,182,0.1);
            color: #9B59B6;
        }
        
        .progress-bar-custom {
            height: 8px;
            border-radius: 10px;
            background: #e9ecef;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            border-radius: 10px;
            background: linear-gradient(90deg, #FF6B35, #E85D2C);
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <?php include 'sidebar.php'; ?>
            
            <div class="col-md-10 main-content">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2 class="fw-bold"><i class="fas fa-chart-bar me-2" style="color: #FF6B35;"></i> Reports & Analytics</h2>
                    <div class="export-buttons">
                        <button onclick="exportToPDF()" class="btn btn-primary-custom export-btn">
                            <i class="fas fa-file-pdf me-2"></i> Export PDF
                        </button>
                        <button onclick="exportToCSV()" class="btn btn-secondary-custom export-btn">
                            <i class="fas fa-file-excel me-2"></i> Export CSV
                        </button>
                    </div>
                </div>
                
                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-md-3 mb-3">
                        <div class="stat-card users">
                            <i class="fas fa-users"></i>
                            <h3><?= number_format($total_users) ?></h3>
                            <p>Total Users</p>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="stat-card items">
                            <i class="fas fa-box"></i>
                            <h3><?= number_format($total_items) ?></h3>
                            <p>Total Items</p>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="stat-card claims">
                            <i class="fas fa-file-alt"></i>
                            <h3><?= number_format($total_claims) ?></h3>
                            <p>Total Claims</p>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="stat-card rate">
                            <i class="fas fa-percent"></i>
                            <h3><?= $success_rate ?>%</h3>
                            <p>Success Rate</p>
                        </div>
                    </div>
                </div>
                
                <div class="row mb-4">
                    <div class="col-md-4 mb-3">
                        <div class="stat-card pending">
                            <i class="fas fa-clock"></i>
                            <h3><?= number_format($pending_claims) ?></h3>
                            <p>Pending Claims</p>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="stat-card approved">
                            <i class="fas fa-check-circle"></i>
                            <h3><?= number_format($approved_claims) ?></h3>
                            <p>Approved Claims</p>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="stat-card rejected">
                            <i class="fas fa-times-circle"></i>
                            <h3><?= number_format($rejected_claims) ?></h3>
                            <p>Rejected Claims</p>
                        </div>
                    </div>
                </div>
                
                <!-- Charts Row -->
                <div class="row">
                    <div class="col-lg-6">
                        <div class="card-modern card">
                            <div class="card-header">
                                <i class="fas fa-chart-pie me-2" style="color: #FF6B35;"></i> Items by Status
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="statusChart"></canvas>
                                </div>
                                <div class="mt-3">
                                    <?php foreach($items_by_status as $status => $count): ?>
                                        <?php $percentage = $total_items > 0 ? round(($count / $total_items) * 100, 1) : 0; ?>
                                        <div class="mb-2">
                                            <div class="d-flex justify-content-between mb-1">
                                                <span class="text-capitalize"><?= $status ?></span>
                                                <span><?= $count ?> (<?= $percentage ?>%)</span>
                                            </div>
                                            <div class="progress-bar-custom">
                                                <div class="progress-fill" style="width: <?= $percentage ?>%"></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-6">
                        <div class="card-modern card">
                            <div class="card-header">
                                <i class="fas fa-chart-pie me-2" style="color: #FF6B35;"></i> Items by Category
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="categoryChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Monthly Trends -->
                <div class="row">
                    <div class="col-lg-6">
                        <div class="card-modern card">
                            <div class="card-header">
                                <i class="fas fa-chart-line me-2" style="color: #FF6B35;"></i> Claims Trend (Last 12 Months)
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="claimsTrendChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-6">
                        <div class="card-modern card">
                            <div class="card-header">
                                <i class="fas fa-chart-line me-2" style="color: #FF6B35;"></i> Items Trend (Last 12 Months)
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="itemsTrendChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Top Reporters -->
                <div class="row">
                    <div class="col-lg-6">
                        <div class="card-modern card">
                            <div class="card-header">
                                <i class="fas fa-trophy me-2" style="color: #FF6B35;"></i> Top Reporters
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover table-custom mb-0">
                                        <thead>
                                            <tr>
                                                <th>Rank</th>
                                                <th>Name</th>
                                                <th>Email</th>
                                                <th>Items Reported</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if(empty($top_reporters)): ?>
                                                <tr>
                                                    <td colspan="4" class="text-center py-4 text-muted">No data available</td>
                                                </tr>
                                            <?php else: ?>
                                                <?php $rank = 1; foreach($top_reporters as $reporter): ?>
                                                <tr>
                                                    <td>
                                                        <?php if($rank == 1): ?>
                                                            <i class="fas fa-crown text-warning"></i>
                                                        <?php elseif($rank == 2): ?>
                                                            <i class="fas fa-medal text-secondary"></i>
                                                        <?php elseif($rank == 3): ?>
                                                            <i class="fas fa-medal text-bronze" style="color: #cd7f32;"></i>
                                                        <?php else: ?>
                                                            <?= $rank ?>
                                                        <?php endif; ?>
                                                     </td
                                                    <td><?= htmlspecialchars($reporter['name']) ?></td>
                                                    <td><?= htmlspecialchars($reporter['email']) ?></td>
                                                    <td><span class="badge bg-primary"><?= $reporter['items_reported'] ?></span></td>
                                                </tr>
                                                <?php $rank++; endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- User Registration Trend -->
                    <div class="col-lg-6">
                        <div class="card-modern card">
                            <div class="card-header">
                                <i class="fas fa-user-plus me-2" style="color: #FF6B35;"></i> New Users (Last 12 Months)
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="usersTrendChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Recent Activity -->
                <div class="card-modern card">
                    <div class="card-header">
                        <i class="fas fa-history me-2" style="color: #FF6B35;"></i> Recent Activity
                    </div>
                    <div class="card-body">
                        <?php if(empty($recent_activity)): ?>
                            <p class="text-muted text-center py-3">No recent activity</p>
                        <?php else: ?>
                            <?php foreach(array_slice($recent_activity, 0, 15) as $activity): ?>
                                <div class="activity-item">
                                    <div class="activity-icon <?= $activity['type'] ?>">
                                        <i class="fas fa-<?= $activity['type'] == 'item' ? 'box' : 'file-alt' ?>"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div>
                                            <strong><?= htmlspecialchars($activity['user_name']) ?></strong>
                                            <?= $activity['type'] == 'item' ? 'reported an item' : 'submitted a claim for' ?>
                                            <strong>"<?= htmlspecialchars(substr($activity['name'], 0, 40)) ?>"</strong>
                                        </div>
                                        <small class="text-muted">
                                            <i class="fas fa-clock me-1"></i> <?= date('M d, Y h:i A', strtotime($activity['date'])) ?>
                                            <span class="status-badge status-<?= $activity['status'] ?> ms-2"><?= ucfirst($activity['status']) ?></span>
                                        </small>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Items by Status Chart (Pie)
        const statusCtx = document.getElementById('statusChart').getContext('2d');
        new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: ['Lost', 'Found', 'Returned', 'Resolved'],
                datasets: [{
                    data: [
                        <?= $items_by_status['lost'] ?>,
                        <?= $items_by_status['found'] ?>,
                        <?= $items_by_status['returned'] ?>,
                        <?= $items_by_status['resolved'] ?>
                    ],
                    backgroundColor: ['#dc3545', '#28a745', '#17a2b8', '#6c757d'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
        
        // Items by Category Chart (Bar)
        const categoryCtx = document.getElementById('categoryChart').getContext('2d');
        const categoryLabels = <?= json_encode(array_column($items_by_category, 'category')) ?>;
        const categoryData = <?= json_encode(array_column($items_by_category, 'count')) ?>;
        new Chart(categoryCtx, {
            type: 'bar',
            data: {
                labels: categoryLabels,
                datasets: [{
                    label: 'Number of Items',
                    data: categoryData,
                    backgroundColor: '#FF6B35',
                    borderRadius: 10
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { stepSize: 1 }
                    }
                }
            }
        });
        
        // Claims Trend Chart (Line)
        const claimsCtx = document.getElementById('claimsTrendChart').getContext('2d');
        const claimsMonths = <?= json_encode(array_column($claims_by_month, 'month')) ?>;
        const claimsTotal = <?= json_encode(array_column($claims_by_month, 'total')) ?>;
        const claimsApproved = <?= json_encode(array_column($claims_by_month, 'approved')) ?>;
        const claimsRejected = <?= json_encode(array_column($claims_by_month, 'rejected')) ?>;
        new Chart(claimsCtx, {
            type: 'line',
            data: {
                labels: claimsMonths,
                datasets: [
                    {
                        label: 'Total Claims',
                        data: claimsTotal,
                        borderColor: '#9B59B6',
                        backgroundColor: 'rgba(155,89,182,0.1)',
                        tension: 0.4,
                        fill: true
                    },
                    {
                        label: 'Approved',
                        data: claimsApproved,
                        borderColor: '#27AE60',
                        backgroundColor: 'rgba(39,174,96,0.1)',
                        tension: 0.4,
                        fill: true
                    },
                    {
                        label: 'Rejected',
                        data: claimsRejected,
                        borderColor: '#E74C3C',
                        backgroundColor: 'rgba(231,76,60,0.1)',
                        tension: 0.4,
                        fill: true
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });
        
        // Items Trend Chart
        const itemsCtx = document.getElementById('itemsTrendChart').getContext('2d');
        const itemsMonths = <?= json_encode(array_column($items_by_month, 'month')) ?>;
        const itemsTotal = <?= json_encode(array_column($items_by_month, 'total')) ?>;
        const itemsLost = <?= json_encode(array_column($items_by_month, 'lost')) ?>;
        const itemsFound = <?= json_encode(array_column($items_by_month, 'found')) ?>;
        new Chart(itemsCtx, {
            type: 'line',
            data: {
                labels: itemsMonths,
                datasets: [
                    {
                        label: 'Total Items',
                        data: itemsTotal,
                        borderColor: '#3498DB',
                        backgroundColor: 'rgba(52,152,219,0.1)',
                        tension: 0.4,
                        fill: true
                    },
                    {
                        label: 'Lost',
                        data: itemsLost,
                        borderColor: '#dc3545',
                        backgroundColor: 'rgba(220,53,69,0.1)',
                        tension: 0.4,
                        fill: true
                    },
                    {
                        label: 'Found',
                        data: itemsFound,
                        borderColor: '#28a745',
                        backgroundColor: 'rgba(40,167,69,0.1)',
                        tension: 0.4,
                        fill: true
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });
        
        // Users Trend Chart
        const usersCtx = document.getElementById('usersTrendChart').getContext('2d');
        const usersMonths = <?= json_encode(array_column($user_registrations, 'month')) ?>;
        const usersData = <?= json_encode(array_column($user_registrations, 'new_users')) ?>;
        new Chart(usersCtx, {
            type: 'bar',
            data: {
                labels: usersMonths,
                datasets: [{
                    label: 'New Users',
                    data: usersData,
                    backgroundColor: '#FF6B35',
                    borderRadius: 10
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { stepSize: 1 }
                    }
                }
            }
        });
        
        function exportToPDF() {
            window.print();
        }
        
        function exportToCSV() {
            // Simple CSV export of recent activity
            let csv = "Type,Item/Claim Name,User,Status,Date\n";
            <?php foreach($recent_activity as $activity): ?>
                csv += `<?= $activity['type'] ?>,<?= addslashes($activity['name']) ?>,<?= addslashes($activity['user_name']) ?>,<?= $activity['status'] ?>,<?= $activity['date'] ?>\n`;
            <?php endforeach; ?>
            
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `reports_<?= date('Y-m-d') ?>.csv`;
            a.click();
            URL.revokeObjectURL(url);
        }
    </script>
    
    <style>
        @media print {
            .sidebar, .export-buttons, .btn, .navbar, .action-buttons {
                display: none !important;
            }
            .main-content {
                margin-left: 0 !important;
                padding: 0 !important;
            }
            .col-md-10 {
                width: 100% !important;
            }
            body {
                background: white;
            }
        }
    </style>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>