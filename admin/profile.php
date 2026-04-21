<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
requireAdmin();

$db = Database::getInstance()->getConnection();
$userID = $_SESSION['userID'];
$success = '';
$error = '';

$base_url = '/reclaim-system/';

// Get admin data
$stmt = $db->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$userID]);
$admin = $stmt->fetch();

// Get admin statistics
$stmt = $db->prepare("
    SELECT 
        (SELECT COUNT(*) FROM items) as total_items,
        (SELECT COUNT(*) FROM users WHERE role != 'admin') as total_users,
        (SELECT COUNT(*) FROM claim_requests WHERE status = 'pending') as pending_claims,
        (SELECT COUNT(*) FROM claim_requests WHERE status = 'approved') as approved_claims
");
$stmt->execute();
$stats = $stmt->fetch();

// Get recent admin activity (claims verified)
$stmt = $db->prepare("
    SELECT c.*, i.title as item_title, u.name as claimant_name
    FROM claim_requests c
    JOIN items i ON c.item_id = i.item_id
    JOIN users u ON c.claimant_id = u.user_id
    WHERE c.verified_by = ?
    ORDER BY c.verified_date DESC
    LIMIT 10
");
$stmt->execute([$userID]);
$verified_claims = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();

    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $bio = trim($_POST['bio'] ?? '');
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    
    if (empty($name)) {
        $error = 'Name is required';
    } else {
        try {
            $password_updated = false;
            // Update basic info
            $stmt = $db->prepare("UPDATE users SET name = ?, phone = ?, department = ? WHERE user_id = ?");
            $stmt->execute([$name, $phone, $department, $userID]);
            
            // Update password if provided
            if (!empty($current_password) && !empty($new_password)) {
                if (password_verify($current_password, $admin['password'])) {
                    if (strlen($new_password) >= 6) {
                        $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
                        $stmt = $db->prepare("UPDATE users SET password = ? WHERE user_id = ?");
                        $stmt->execute([$new_hash, $userID]);
                        $password_updated = true;
                        $success = 'Profile updated successfully! Password changed.';
                    } else {
                        $error = 'New password must be at least 6 characters';
                    }
                } else {
                    $error = 'Current password is incorrect';
                }
            } elseif (!empty($current_password) || !empty($new_password)) {
                $error = 'Please fill in both current and new password fields to change password';
            } else {
                $success = 'Profile updated successfully!';
            }
            
            // If no error, refresh admin data
            if (empty($error)) {
                $stmt = $db->prepare("SELECT * FROM users WHERE user_id = ?");
                $stmt->execute([$userID]);
                $admin = $stmt->fetch();
                $_SESSION['name'] = $admin['name'];
                
                if (!$password_updated && empty($success)) {
                    $success = 'Profile updated successfully!';
                }
            }
        } catch (PDOException $e) {
            error_log("Admin profile database error: " . $e->getMessage());
            $error = 'Unable to update profile. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Profile - Reclaim System</title>
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
        
        .profile-header {
            background: linear-gradient(135deg, #1A252F, #2C3E50);
            border-radius: 20px;
            padding: 30px;
            color: white;
            margin-bottom: 25px;
            position: relative;
            overflow: hidden;
        }
        
        .profile-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 60%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,107,53,0.1) 0%, rgba(255,107,53,0) 70%);
            transform: rotate(25deg);
        }
        
        .profile-avatar {
            width: 100px;
            height: 100px;
            background: rgba(255,107,53,0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            border: 3px solid #FF6B35;
        }
        
        .profile-avatar i {
            font-size: 50px;
            color: #FF6B35;
        }
        
        .stat-card-profile {
            background: white;
            border-radius: 15px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            transition: all 0.3s;
            height: 100%;
        }
        
        .stat-card-profile:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 30px rgba(0,0,0,0.12);
        }
        
        .stat-card-profile i {
            font-size: 2rem;
            margin-bottom: 10px;
        }
        
        .stat-card-profile h3 {
            font-size: 1.8rem;
            font-weight: 800;
            margin: 5px 0;
            color: #2C3E50;
        }
        
        .stat-card-profile p {
            color: #6c757d;
            margin: 0;
            font-size: 0.85rem;
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
            padding: 10px 25px;
            border-radius: 50px;
            transition: all 0.3s;
            color: white;
            text-decoration: none;
        }
        
        .btn-secondary-custom:hover {
            background: #5a6268;
            color: white;
        }
        
        .info-row {
            display: flex;
            padding: 12px 0;
            border-bottom: 1px solid #e9ecef;
        }
        
        .info-label {
            width: 130px;
            font-weight: 600;
            color: #2C3E50;
        }
        
        .info-value {
            flex: 1;
            color: #6c757d;
        }
        
        .activity-item {
            display: flex;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #e9ecef;
            transition: all 0.3s;
        }
        
        .activity-item:last-child {
            border-bottom: none;
        }
        
        .activity-item:hover {
            transform: translateX(5px);
        }
        
        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            flex-shrink: 0;
        }
        
        .activity-icon.approved {
            background: rgba(39,174,96,0.1);
            color: #27AE60;
        }
        
        .activity-icon.rejected {
            background: rgba(231,76,60,0.1);
            color: #E74C3C;
        }
        
        .form-control, .form-select {
            border-radius: 12px;
            padding: 12px 15px;
            border: 1px solid #e0e0e0;
            transition: all 0.3s;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: #FF6B35;
            box-shadow: 0 0 0 3px rgba(255,107,53,0.1);
        }
        
        .password-strength {
            height: 4px;
            background: #e0e0e0;
            border-radius: 4px;
            margin-top: 8px;
            overflow: hidden;
        }
        
        .password-strength-bar {
            height: 100%;
            width: 0%;
            transition: width 0.3s;
        }
        
        .strength-weak { background: #dc3545; width: 33%; }
        .strength-medium { background: #ffc107; width: 66%; }
        .strength-strong { background: #28a745; width: 100%; }
        
        .role-badge {
            background: #FF6B35;
            color: white;
            padding: 5px 15px;
            border-radius: 50px;
            font-size: 12px;
            font-weight: 600;
        }
        
        @media (max-width: 768px) {
            .info-row {
                flex-direction: column;
            }
            .info-label {
                width: 100%;
                margin-bottom: 5px;
            }
        }
    </style>
</head>
<body class="app-page admin-page">
    <div class="container-fluid">
        <div class="row">
            <?php include 'sidebar.php'; ?>
            
            <div class="col-md-10 main-content content-wrapper">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2 class="fw-bold"><i class="fas fa-user-shield me-2" style="color: #FF6B35;"></i> Admin Profile</h2>
                    <a href="dashboard.php" class="btn btn-secondary-custom">
                        <i class="fas fa-arrow-left me-2"></i> Back to Dashboard
                    </a>
                </div>
                
                <?php if($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i> <?= htmlspecialchars($error) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if($success): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i> <?= htmlspecialchars($success) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <!-- Profile Header -->
                <div class="profile-header">
                    <div class="row align-items-center">
                        <div class="col-md-3 text-center">
                            <div class="profile-avatar">
                                <i class="fas fa-user-shield"></i>
                            </div>
                            <span class="role-badge mt-2 d-inline-block">
                                <i class="fas fa-crown me-1"></i> Administrator
                            </span>
                        </div>
                        <div class="col-md-9">
                            <h2 class="mb-2"><?= htmlspecialchars($admin['name']) ?></h2>
                            <p class="mb-2">
                                <i class="fas fa-envelope me-2"></i> <?= htmlspecialchars($admin['email']) ?>
                            </p>
                            <p class="mb-2">
                                <i class="fas fa-phone me-2"></i> <?= htmlspecialchars($admin['phone'] ?? 'No phone number added') ?>
                            </p>
                            <p class="mb-0">
                                <i class="fas fa-calendar-alt me-2"></i> Admin since <?= date('F Y', strtotime($admin['created_at'])) ?>
                            </p>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <!-- Statistics Cards -->
                    <div class="col-lg-4 mb-4">
                        <div class="stat-card-profile">
                            <i class="fas fa-box" style="color: #FF6B35;"></i>
                            <h3><?= number_format($stats['total_items']) ?></h3>
                            <p>Total Items in System</p>
                        </div>
                    </div>
                    <div class="col-lg-4 mb-4">
                        <div class="stat-card-profile">
                            <i class="fas fa-users" style="color: #3498DB;"></i>
                            <h3><?= number_format($stats['total_users']) ?></h3>
                            <p>Total Users</p>
                        </div>
                    </div>
                    <div class="col-lg-4 mb-4">
                        <div class="stat-card-profile">
                            <i class="fas fa-clock" style="color: #F39C12;"></i>
                            <h3><?= number_format($stats['pending_claims']) ?></h3>
                            <p>Pending Claims</p>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <!-- Edit Profile Form -->
                    <div class="col-lg-7">
                        <div class="card-modern card">
                            <div class="card-header">
                                <i class="fas fa-edit me-2" style="color: #FF6B35;"></i> Edit Profile Information
                            </div>
                            <div class="card-body">
                                <form method="POST" action="" id="profileForm">
                                    <?= csrf_field() ?>
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Full Name</label>
                                        <input type="text" name="name" class="form-control" 
                                               value="<?= htmlspecialchars($admin['name']) ?>" required>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Email Address</label>
                                        <input type="email" class="form-control" 
                                               value="<?= htmlspecialchars($admin['email']) ?>" disabled>
                                        <small class="text-muted">Email cannot be changed. Contact system administrator if needed.</small>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Phone Number</label>
                                        <input type="tel" name="phone" class="form-control" 
                                               value="<?= htmlspecialchars($admin['phone'] ?? '') ?>"
                                               placeholder="+6012-3456789">
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Department</label>
                                        <input type="text" name="department" class="form-control" 
                                               value="<?= htmlspecialchars($admin['department'] ?? '') ?>"
                                               placeholder="e.g., IT Department, Administration">
                                    </div>
                                    
                                    <hr class="my-4">
                                    
                                    <h6 class="mb-3 fw-bold">
                                        <i class="fas fa-lock me-2" style="color: #FF6B35;"></i> Change Password
                                    </h6>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Current Password</label>
                                        <input type="password" name="current_password" class="form-control" id="currentPassword">
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">New Password</label>
                                        <input type="password" name="new_password" class="form-control" id="newPassword">
                                        <div class="password-strength">
                                            <div class="password-strength-bar" id="passwordStrengthBar"></div>
                                        </div>
                                        <small class="text-muted">Minimum 6 characters</small>
                                    </div>
                                    
                                    <div class="d-flex gap-3 mt-4">
                                        <button type="submit" class="btn btn-primary-custom">
                                            <i class="fas fa-save me-2"></i> Save Changes
                                        </button>
                                        <button type="button" class="btn btn-secondary-custom" onclick="window.location.reload()">
                                            <i class="fas fa-redo me-2"></i> Reset
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Recent Activity & Info -->
                    <div class="col-lg-5">
                        <!-- Account Information -->
                        <div class="card-modern card">
                            <div class="card-header">
                                <i class="fas fa-info-circle me-2" style="color: #FF6B35;"></i> Account Information
                            </div>
                            <div class="card-body">
                                <div class="info-row">
                                    <div class="info-label">User ID</div>
                                    <div class="info-value">#<?= $admin['user_id'] ?></div>
                                </div>
                                <div class="info-row">
                                    <div class="info-label">Role</div>
                                    <div class="info-value">
                                        <span class="role-badge" style="background: #FF6B35;">
                                            <i class="fas fa-shield-alt me-1"></i> Administrator
                                        </span>
                                    </div>
                                </div>
                                <div class="info-row">
                                    <div class="info-label">Account Status</div>
                                    <div class="info-value">
                                        <span class="badge bg-success">Active</span>
                                    </div>
                                </div>
                                <div class="info-row">
                                    <div class="info-label">Joined Date</div>
                                    <div class="info-value"><?= date('F d, Y', strtotime($admin['created_at'])) ?></div>
                                </div>
                                <?php if($admin['last_login']): ?>
                                <div class="info-row">
                                    <div class="info-label">Last Login</div>
                                    <div class="info-value"><?= date('F d, Y h:i A', strtotime($admin['last_login'])) ?></div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Recent Verification Activity -->
                        <div class="card-modern card mt-4">
                            <div class="card-header">
                                <i class="fas fa-history me-2" style="color: #FF6B35;"></i> Recent Verification Activity
                            </div>
                            <div class="card-body p-0">
                                <?php if(empty($verified_claims)): ?>
                                    <div class="text-center py-4 text-muted">
                                        <i class="fas fa-check-circle fa-2x mb-2 d-block"></i>
                                        No verification activity yet
                                    </div>
                                <?php else: ?>
                                    <?php foreach($verified_claims as $claim): ?>
                                        <div class="activity-item px-3">
                                            <div class="activity-icon <?= $claim['status'] ?>">
                                                <i class="fas fa-<?= $claim['status'] == 'approved' ? 'check' : 'times' ?>"></i>
                                            </div>
                                            <div class="flex-grow-1">
                                                <div>
                                                    <strong><?= htmlspecialchars($claim['claimant_name']) ?></strong>'s claim for
                                                    <strong>"<?= htmlspecialchars(substr($claim['item_title'], 0, 30)) ?>"</strong>
                                                    was <strong class="text-capitalize"><?= $claim['status'] ?></strong>
                                                </div>
                                                <small class="text-muted">
                                                    <i class="fas fa-clock me-1"></i> <?= date('M d, Y h:i A', strtotime($claim['verified_date'])) ?>
                                                </small>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Quick Actions -->
                        <div class="card-modern card mt-4">
                            <div class="card-header">
                                <i class="fas fa-bolt me-2" style="color: #FF6B35;"></i> Quick Actions
                            </div>
                            <div class="card-body">
                                <div class="row g-2">
                                    <div class="col-6">
                                        <a href="verify-claims.php" class="btn btn-primary-custom w-100">
                                            <i class="fas fa-check-double me-2"></i> Verify Claims
                                        </a>
                                    </div>
                                    <div class="col-6">
                                        <a href="manage-users.php" class="btn btn-primary-custom w-100">
                                            <i class="fas fa-users me-2"></i> Manage Users
                                        </a>
                                    </div>
                                    <div class="col-6">
                                        <a href="manage-items.php" class="btn btn-primary-custom w-100">
                                            <i class="fas fa-boxes me-2"></i> Manage Items
                                        </a>
                                    </div>
                                    <div class="col-6">
                                        <a href="reports.php" class="btn btn-primary-custom w-100">
                                            <i class="fas fa-chart-bar me-2"></i> View Reports
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Password strength checker
        document.getElementById('newPassword').addEventListener('input', function() {
            const password = this.value;
            const strengthBar = document.getElementById('passwordStrengthBar');
            
            if (password.length === 0) {
                strengthBar.style.width = '0%';
                strengthBar.className = 'password-strength-bar';
            } else if (password.length < 6) {
                strengthBar.style.width = '33%';
                strengthBar.className = 'password-strength-bar strength-weak';
            } else if (password.length < 10) {
                strengthBar.style.width = '66%';
                strengthBar.className = 'password-strength-bar strength-medium';
            } else {
                strengthBar.style.width = '100%';
                strengthBar.className = 'password-strength-bar strength-strong';
            }
        });
        
        // Form validation
        document.getElementById('profileForm').addEventListener('submit', function(e) {
            const currentPassword = document.getElementById('currentPassword').value;
            const newPassword = document.getElementById('newPassword').value;
            
            if ((currentPassword && !newPassword) || (!currentPassword && newPassword)) {
                e.preventDefault();
                alert('Please fill in both current and new password fields to change password');
            }
        });
    </script>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
