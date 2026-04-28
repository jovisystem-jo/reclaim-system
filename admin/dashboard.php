<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$db = Database::getInstance()->getConnection();

// Get comprehensive statistics
$stats = [];
$stats['total_users'] = $db->query("SELECT COUNT(*) FROM users WHERE role != 'admin'")->fetchColumn();
$stats['total_items'] = $db->query("SELECT COUNT(*) FROM items")->fetchColumn();
$stats['pending_claims'] = $db->query("SELECT COUNT(*) FROM claim_requests WHERE status = 'pending'")->fetchColumn();
$stats['completed_claims'] = $db->query("SELECT COUNT(*) FROM claim_requests WHERE status = 'approved'")->fetchColumn();
$stats['rejected_claims'] = $db->query("SELECT COUNT(*) FROM claim_requests WHERE status = 'rejected'")->fetchColumn();

// Get recent claims
$stmt = $db->prepare("
    SELECT c.*, i.title as item_title, i.description as item_description, u.name as claimant_name, u.email as claimant_email
    FROM claim_requests c
    JOIN items i ON c.item_id = i.item_id
    JOIN users u ON c.claimant_id = u.user_id
    WHERE c.status = 'pending'
    ORDER BY c.created_at DESC
    LIMIT 10
");
$stmt->execute();
$pending_claims = $stmt->fetchAll();

// Get recent items
$stmt = $db->prepare("
    SELECT i.*, u.name as reporter_name
    FROM items i
    JOIN users u ON i.reported_by = u.user_id
    ORDER BY i.reported_date DESC
    LIMIT 5
");
$stmt->execute();
$recent_items = $stmt->fetchAll();

// Get recent users
$stmt = $db->prepare("
    SELECT * FROM users 
    WHERE role != 'admin' 
    ORDER BY created_at DESC 
    LIMIT 5
");
$stmt->execute();
$recent_users = $stmt->fetchAll();

$base_url = '/reclaim-system/';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Reclaim System</title>
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
        
        .main-content {
            padding: 20px;
            min-height: 100vh;
        }
        
        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 25px;
            text-align: center;
            transition: all 0.3s;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            border: none;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 30px rgba(0,0,0,0.12);
        }
        
        .stat-card i {
            font-size: 2.5rem;
            margin-bottom: 15px;
        }
        
        .stat-card h3 {
            font-size: 2rem;
            font-weight: 800;
            margin: 10px 0 5px;
        }
        
        .stat-card p {
            color: #6c757d;
            margin: 0;
            font-weight: 500;
        }
        
        .stat-card.primary i { color: #FF6B35; }
        .stat-card.success i { color: #27AE60; }
        .stat-card.warning i { color: #F39C12; }
        .stat-card.info i { color: #3498DB; }
        .stat-card.danger i { color: #E74C3C; }
        
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
        
        .table-custom {
            margin-bottom: 0;
        }
        
        .table-custom th {
            border-top: none;
            font-weight: 600;
            color: #2C3E50;
        }
        
        .btn-primary-custom {
            background: linear-gradient(135deg, #FF6B35, #E85D2C);
            border: none;
            padding: 8px 20px;
            border-radius: 50px;
            transition: all 0.3s;
            color: white;
            text-decoration: none;
            display: inline-block;
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
        
        .welcome-banner {
            background: linear-gradient(135deg, #FF6B35, #E85D2C);
            border-radius: 20px;
            padding: 25px 30px;
            color: white;
            margin-bottom: 25px;
        }
        
        .welcome-banner h2 {
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .welcome-banner p {
            color: #FFFFFF;
        }
        
        .status-badge {
            padding: 5px 12px;
            border-radius: 50px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-lost { background: #dc3545; color: white; }
        .status-found { background: #28a745; color: white; }
        .status-returned { background: #17a2b8; color: white; }
        .status-resolved { background: #6c757d; color: white; }
        .status-pending { background: #ffc107; color: #333; }
        .status-approved { background: #28a745; color: white; }
        .status-rejected { background: #dc3545; color: white; }
    </style>
</head>
<body class="app-page admin-page">
    <div class="container-fluid">
        <div class="row">
            <?php include 'sidebar.php'; ?>
            
            <!-- Main Content -->
            <div class="col-md-10 main-content content-wrapper">
                <!-- Welcome Banner -->
                <div class="welcome-banner">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h2>Welcome back, <?= htmlspecialchars($_SESSION['name']) ?>!</h2>
                            <p class="mb-0 opacity-75">Here's what's happening with your lost and found system today.</p>
                        </div>
                        <div>
                            <i class="fas fa-chart-line fa-3x opacity-50"></i>
                        </div>
                    </div>
                </div>
                
                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-md-3 mb-3">
                        <div class="stat-card primary">
                            <i class="fas fa-users"></i>
                            <h3><?= number_format($stats['total_users']) ?></h3>
                            <p>Total Users</p>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="stat-card info">
                            <i class="fas fa-box"></i>
                            <h3><?= number_format($stats['total_items']) ?></h3>
                            <p>Total Items</p>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="stat-card warning">
                            <i class="fas fa-clock"></i>
                            <h3><?= number_format($stats['pending_claims']) ?></h3>
                            <p>Pending Claims</p>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="stat-card success">
                            <i class="fas fa-check-circle"></i>
                            <h3><?= number_format($stats['completed_claims']) ?></h3>
                            <p>Approved Claims</p>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <!-- Pending Claims Table -->
                    <div class="col-lg-7">
                        <div class="card-modern card">
                            <div class="card-header">
                                <i class="fas fa-clock me-2" style="color: #FF6B35;"></i> Pending Verification Claims
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover table-custom mb-0">
                                        <thead>
                                            <tr>
                                                <th>ID</th>
                                                <th>Claimant</th>
                                                <th>Item</th>
                                                <th>Date</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if(empty($pending_claims)): ?>
                                                <tr>
                                                    <td colspan="5" class="text-center py-4 text-muted">
                                                        <i class="fas fa-check-circle fa-2x mb-2 d-block"></i>
                                                        No pending claims
                                                    </td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach($pending_claims as $claim): ?>
                                                <tr>
                                                    <td>#<?= $claim['claim_id'] ?></td>
                                                    <td><?= htmlspecialchars($claim['claimant_name']) ?></td>
                                                    <td><?= htmlspecialchars(substr($claim['item_title'] ?? $claim['item_description'], 0, 30)) ?></td>
                                                    <td><?= date('M d, Y', strtotime($claim['created_at'])) ?></td>
                                                    <td>
                                                        <a href="verify-claims.php?id=<?= $claim['claim_id'] ?>" class="btn btn-primary-custom btn-sm">
                                                            <i class="fas fa-check"></i> Verify
                                                        </a>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Recent Items -->
                    <div class="col-lg-5">
                        <div class="card-modern card">
                            <div class="card-header">
                                <i class="fas fa-box me-2" style="color: #FF6B35;"></i> Recent Items
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover table-custom mb-0">
                                        <thead>
                                            <tr>
                                                <th>Item</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if(empty($recent_items)): ?>
                                                <tr>
                                                    <td colspan="2" class="text-center py-4 text-muted">No items yet</td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach($recent_items as $item): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars(substr($item['title'] ?? $item['description'], 0, 30)) ?></td>
                                                    <td>
                                                        <span class="status-badge <?= $item['status'] == 'lost' ? 'status-lost' : ($item['status'] == 'returned' ? 'status-returned' : ($item['status'] == 'resolved' ? 'status-resolved' : 'status-found')) ?>">
                                                            <?= ucfirst($item['status']) ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Recent Users -->
                        <div class="card-modern card mt-4">
                            <div class="card-header">
                                <i class="fas fa-user-plus me-2" style="color: #FF6B35;"></i> New Users
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover table-custom mb-0">
                                        <thead>
                                            <tr>
                                                <th>Name</th>
                                                <th>Email</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if(empty($recent_users)): ?>
                                                <tr>
                                                    <td colspan="2" class="text-center py-4 text-muted">No users yet</td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach($recent_users as $user): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($user['name']) ?></td>
                                                    <td><?= htmlspecialchars($user['email']) ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
