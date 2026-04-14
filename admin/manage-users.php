<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
requireAdmin();

$db = Database::getInstance()->getConnection();
$message = '';
$error = '';

// Handle user actions (activate, deactivate, delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $user_id = $_POST['user_id'] ?? 0;
    
    if ($action === 'toggle_status') {
        // Get current status
        $stmt = $db->prepare("SELECT is_active FROM users WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $current = $stmt->fetch();
        $new_status = $current['is_active'] ? 0 : 1;
        
        $stmt = $db->prepare("UPDATE users SET is_active = ? WHERE user_id = ?");
        if ($stmt->execute([$new_status, $user_id])) {
            $message = "User status updated successfully";
        } else {
            $error = "Failed to update user status";
        }
    } elseif ($action === 'delete' && $user_id != $_SESSION['userID']) {
        $stmt = $db->prepare("DELETE FROM users WHERE user_id = ? AND role != 'admin'");
        if ($stmt->execute([$user_id])) {
            $message = "User deleted successfully";
        } else {
            $error = "Failed to delete user";
        }
    }
}

// Get all non-admin users
$stmt = $db->prepare("
    SELECT * FROM users 
    WHERE role != 'admin' 
    ORDER BY created_at DESC
");
$stmt->execute();
$users = $stmt->fetchAll();

$base_url = '/reclaim-system/';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users - Admin Panel</title>
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
        }
        
        .btn-primary-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255,107,53,0.3);
            color: white;
        }
        
        .status-badge-active {
            background: #28a745;
            color: white;
            padding: 5px 12px;
            border-radius: 50px;
            font-size: 12px;
        }
        
        .status-badge-inactive {
            background: #dc3545;
            color: white;
            padding: 5px 12px;
            border-radius: 50px;
            font-size: 12px;
        }
        
        .role-badge {
            background: #FF6B35;
            color: white;
            padding: 5px 12px;
            border-radius: 50px;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <?php include 'sidebar.php'; ?>
            
            <div class="col-md-10 main-content">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2 class="fw-bold"><i class="fas fa-users me-2" style="color: #FF6B35;"></i> Manage Users</h2>
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
                
                <div class="card-modern card">
                    <div class="card-header">
                        <i class="fas fa-list me-2" style="color: #FF6B35;"></i> All Users
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover table-custom mb-0">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Role</th>
                                        <th>Status</th>
                                        <th>Joined</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(empty($users)): ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-4 text-muted">
                                                <i class="fas fa-users fa-2x mb-2 d-block"></i>
                                                No users found
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach($users as $user): ?>
                                        <tr>
                                            <td><?= $user['user_id'] ?></td>
                                            <td><?= htmlspecialchars($user['name']) ?></td>
                                            <td><?= htmlspecialchars($user['email']) ?></td>
                                            <td><span class="role-badge"><?= ucfirst(str_replace('_', ' ', $user['role'])) ?></span></td>
                                            <td>
                                                <span class="status-badge-<?= $user['is_active'] ? 'active' : 'inactive' ?>">
                                                    <?= $user['is_active'] ? 'Active' : 'Inactive' ?>
                                                </span>
                                            </td>
                                            <td><?= date('M d, Y', strtotime($user['created_at'])) ?></td>
                                            <td>
                                                <div class="d-flex gap-2">
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="user_id" value="<?= $user['user_id'] ?>">
                                                        <input type="hidden" name="action" value="toggle_status">
                                                        <button type="submit" class="btn btn-sm <?= $user['is_active'] ? 'btn-warning' : 'btn-success' ?>" onclick="return confirm('Toggle user status?')">
                                                            <i class="fas fa-<?= $user['is_active'] ? 'ban' : 'check' ?>"></i>
                                                            <?= $user['is_active'] ? 'Deactivate' : 'Activate' ?>
                                                        </button>
                                                    </form>
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="user_id" value="<?= $user['user_id'] ?>">
                                                        <input type="hidden" name="action" value="delete">
                                                        <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Delete this user? This action cannot be undone.')">
                                                            <i class="fas fa-trash"></i> Delete
                                                        </button>
                                                    </form>
                                                </div>
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
        </div>
    </div>
    
    <style>
        .btn-warning {
            background: #ffc107;
            border: none;
            color: #333;
        }
        .btn-success {
            background: #28a745;
            border: none;
        }
        .btn-danger {
            background: #dc3545;
            border: none;
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
    </style>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>