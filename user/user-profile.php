<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$userID = $_SESSION['userID'];
$success = '';
$error = '';

$base_url = '/reclaim-system/';

// Get user data
$stmt = $db->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$userID]);
$user = $stmt->fetch();

// Get user statistics
$stmt = $db->prepare("
    SELECT 
        (SELECT COUNT(*) FROM items WHERE reported_by = ?) as total_reports,
        (SELECT COUNT(*) FROM claim_requests WHERE claimant_id = ?) as total_claims,
        (SELECT COUNT(*) FROM claim_requests WHERE claimant_id = ? AND status = 'approved') as approved_claims,
        (SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0) as unread_notifications
");
$stmt->execute([$userID, $userID, $userID, $userID]);
$stats = $stmt->fetch();

// Get recent activity
$stmt = $db->prepare("
    (SELECT 'report' as type, item_id, reported_date as date, 'Reported an item' as action FROM items WHERE reported_by = ? LIMIT 5)
    UNION
    (SELECT 'claim' as type, claim_id, created_at as date, 'Submitted a claim' as action FROM claim_requests WHERE claimant_id = ? LIMIT 5)
    ORDER BY date DESC LIMIT 5
");
$stmt->execute([$userID, $userID]);
$activities = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $student_staff_id = trim($_POST['student_staff_id'] ?? '');
    $bio = trim($_POST['bio'] ?? '');
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    
    if (empty($name)) {
        $error = 'Name is required';
    } else {
        try {
            // Update basic info
            $stmt = $db->prepare("UPDATE users SET name = ?, phone = ?, department = ?, student_staff_id = ? WHERE user_id = ?");
            $stmt->execute([$name, $phone, $department, $student_staff_id, $userID]);
            
            // Update password if provided
            $password_updated = false;
            if (!empty($current_password) && !empty($new_password)) {
                if (password_verify($current_password, $user['password'])) {
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
            
            // If no error, refresh user data
            if (empty($error)) {
                $stmt = $db->prepare("SELECT * FROM users WHERE user_id = ?");
                $stmt->execute([$userID]);
                $user = $stmt->fetch();
                $_SESSION['name'] = $user['name'];
                
                if (!$password_updated && empty($success)) {
                    $success = 'Profile updated successfully!';
                }
            }
        } catch (PDOException $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - Reclaim System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="<?= $base_url ?>assets/css/style.css">
    <style>
        .profile-header {
            background: linear-gradient(135deg, var(--primary-orange), var(--dark-orange));
            border-radius: 15px;
            padding: 30px;
            color: white;
            margin-bottom: 30px;
            position: relative;
            overflow: hidden;
        }
        
        .profile-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, rgba(255,255,255,0) 70%);
            transform: rotate(45deg);
        }
        
        .profile-avatar {
            width: 120px;
            height: 120px;
            background: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        
        .profile-avatar i {
            font-size: 60px;
            color: var(--primary-orange);
        }
        
        .stat-card-profile {
            background: white;
            border-radius: 15px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            transition: transform 0.3s, box-shadow 0.3s;
            margin-bottom: 20px;
        }
        
        .stat-card-profile:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
        }
        
        .stat-card-profile i {
            font-size: 40px;
            margin-bottom: 10px;
        }
        
        .stat-card-profile h3 {
            font-size: 28px;
            margin: 10px 0;
            color: #333;
        }
        
        .stat-card-profile p {
            color: #666;
            margin: 0;
            font-size: 14px;
        }
        
        .info-card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            margin-bottom: 20px;
            transition: transform 0.2s;
        }
        
        .info-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.12);
        }
        
        .info-card .card-header {
            background: white;
            border-bottom: 2px solid var(--primary-orange);
            padding: 15px 20px;
            border-radius: 15px 15px 0 0;
        }
        
        .info-card .card-header h5 {
            margin: 0;
            color: var(--dark-orange);
        }
        
        .activity-timeline {
            position: relative;
            padding-left: 30px;
        }
        
        .activity-timeline::before {
            content: '';
            position: absolute;
            left: 10px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: linear-gradient(to bottom, var(--primary-orange), var(--dark-orange));
        }
        
        .activity-item {
            position: relative;
            margin-bottom: 20px;
            padding: 10px 15px;
            background: #f8f9fa;
            border-radius: 10px;
            transition: all 0.3s;
        }
        
        .activity-item:hover {
            background: #e9ecef;
            transform: translateX(5px);
        }
        
        .activity-item::before {
            content: '';
            position: absolute;
            left: -25px;
            top: 15px;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: var(--primary-orange);
            border: 2px solid white;
            box-shadow: 0 0 0 2px var(--primary-orange);
        }
        
        .activity-icon {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-right: 10px;
        }
        
        .form-label {
            font-weight: 500;
            color: #555;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-orange), var(--dark-orange));
            border: none;
            padding: 10px 25px;
            transition: all 0.3s;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255, 140, 0, 0.3);
        }
        
        .btn-outline-primary {
            border-color: var(--primary-orange);
            color: var(--primary-orange);
        }
        
        .btn-outline-primary:hover {
            background: var(--primary-orange);
            border-color: var(--primary-orange);
        }
        
        .profile-section {
            animation: fadeInUp 0.5s ease-out;
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .edit-icon {
            position: absolute;
            top: 20px;
            right: 20px;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            width: 35px;
            height: 35px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .edit-icon:hover {
            background: rgba(255,255,255,0.3);
            transform: scale(1.1);
        }
        
        .member-since {
            font-size: 12px;
            opacity: 0.8;
        }
        
        .badge-role {
            background: rgba(255,255,255,0.2);
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 12px;
        }
        
        .password-strength {
            height: 3px;
            background: #e0e0e0;
            border-radius: 3px;
            margin-top: 5px;
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
        
        .required-field::after {
            content: '*';
            color: red;
            margin-left: 4px;
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../includes/header.php'; ?>
    
    <div class="container mt-4">
        <!-- Profile Header -->
        <div class="profile-header fade-in">
            <div class="row align-items-center">
                <div class="col-md-3 text-center">
                    <div class="profile-avatar">
                        <i class="fas fa-user-circle"></i>
                    </div>
                </div>
                <div class="col-md-9">
                    <h2 class="mb-2"><?= htmlspecialchars($user['name']) ?></h2>
                    <p class="mb-2">
                        <span class="badge-role">
                            <i class="fas <?= $user['role'] == 'student' ? 'fa-user-graduate' : ($user['role'] == 'staff' ? 'fa-chalkboard-teacher' : 'fa-shield-alt') ?>"></i> 
                            <?= $user['role'] == 'student' ? 'Student' : ($user['role'] == 'staff' ? 'Staff' : ucfirst($user['role'])) ?>
                        </span>
                        <span class="badge-role ms-2">
                            <i class="fas fa-id-card"></i> ID: <?= htmlspecialchars($user['student_staff_id'] ?? 'Not set') ?>
                        </span>
                    </p>    
                    <p class="mb-1">
                        <i class="fas fa-envelope me-2"></i> <?= htmlspecialchars($user['email']) ?>
                    </p>
                    <p class="mb-1">
                        <i class="fas fa-phone me-2"></i> <?= htmlspecialchars($user['phone'] ?? 'No phone number added') ?>
                    </p>
                    <p class="member-since mb-0">
                        <i class="fas fa-calendar-alt me-1"></i> Member since <?= date('F Y', strtotime($user['created_at'])) ?>
                    </p>
                </div>
            </div>
        </div>
        
        <div class="row">
            <!-- Left Column - Statistics & Activity -->
            <div class="col-lg-4">
                <!-- Statistics Cards -->
                <div class="row">
                    <div class="col-6">
                        <div class="stat-card-profile">
                            <i class="fas fa-clipboard-list" style="color: var(--primary-orange);"></i>
                            <h3><?= $stats['total_reports'] ?></h3>
                            <p>Items Reported</p>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="stat-card-profile">
                            <i class="fas fa-hand-paper" style="color: var(--primary-orange);"></i>
                            <h3><?= $stats['total_claims'] ?></h3>
                            <p>Claims Made</p>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="stat-card-profile">
                            <i class="fas fa-check-circle" style="color: #28a745;"></i>
                            <h3><?= $stats['approved_claims'] ?></h3>
                            <p>Approved Claims</p>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="stat-card-profile">
                            <i class="fas fa-bell" style="color: #ffc107;"></i>
                            <h3><?= $stats['unread_notifications'] ?></h3>
                            <p>Notifications</p>
                        </div>
                    </div>
                </div>
                
                <!-- Recent Activity -->
                <div class="info-card">
                    <div class="card-header">
                        <h5><i class="fas fa-history me-2" style="color: var(--primary-orange);"></i> Recent Activity</h5>
                    </div>
                    <div class="card-body">
                        <?php if(empty($activities)): ?>
                            <p class="text-muted text-center py-3">No recent activity</p>
                        <?php else: ?>
                            <div class="activity-timeline">
                                <?php foreach($activities as $activity): ?>
                                    <div class="activity-item">
                                        <div class="d-flex align-items-center">
                                            <div class="activity-icon <?= $activity['type'] == 'report' ? 'bg-danger bg-opacity-10' : 'bg-success bg-opacity-10' ?>">
                                                <i class="fas fa-<?= $activity['type'] == 'report' ? 'flag-checkered' : 'file-alt' ?>" 
                                                   style="color: <?= $activity['type'] == 'report' ? '#dc3545' : '#28a745' ?>"></i>
                                            </div>
                                            <div class="flex-grow-1">
                                                <strong><?= htmlspecialchars($activity['action']) ?></strong>
                                                <br>
                                                <small class="text-muted"><?= time_ago($activity['date']) ?></small>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Quick Actions -->
                <div class="info-card">
                    <div class="card-header">
                        <h5><i class="fas fa-bolt me-2" style="color: var(--primary-orange);"></i> Quick Actions</h5>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="<?= $base_url ?>user/report-item.php?type=lost" class="btn btn-outline-primary">
                                <i class="fas fa-frown"></i> Report Lost Item
                            </a>
                            <a href="<?= $base_url ?>user/report-item.php?type=found" class="btn btn-outline-primary">
                                <i class="fas fa-smile"></i> Report Found Item
                            </a>
                            <a href="<?= $base_url ?>search.php" class="btn btn-outline-primary">
                                <i class="fas fa-search"></i> Search Items
                            </a>
                            <a href="<?= $base_url ?>user/my-claims.php" class="btn btn-outline-primary">
                                <i class="fas fa-file-alt"></i> View My Claims
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Right Column - Edit Profile Form -->
            <div class="col-lg-8">
                <div class="info-card profile-section">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5><i class="fas fa-edit me-2" style="color: var(--primary-orange);"></i> Edit Profile Information</h5>
                        <i class="fas fa-user-edit text-muted"></i>
                    </div>
                    <div class="card-body">
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
                        
                        <form method="POST" action="" id="profileForm">
                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <label class="form-label required-field">
                                        <i class="fas fa-user me-1 text-muted"></i> Full Name
                                    </label>
                                    <input type="text" name="name" class="form-control" 
                                           value="<?= htmlspecialchars($user['name']) ?>" required>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">
                                        <i class="fas fa-id-card me-1 text-muted"></i> Student/Staff ID
                                    </label>
                                    <input type="text" name="student_staff_id" class="form-control" 
                                           value="<?= htmlspecialchars($user['student_staff_id'] ?? '') ?>"
                                           placeholder="e.g., STU12345 or STAFF001">
                                    <small class="text-muted">Optional - helps verify your identity</small>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">
                                        <i class="fas fa-phone me-1 text-muted"></i> Phone Number
                                    </label>
                                    <input type="tel" name="phone" class="form-control" 
                                           value="<?= htmlspecialchars($user['phone'] ?? '') ?>"
                                           placeholder="+6012-3456789">
                                </div>
                                
                                <div class="col-md-12 mb-3">
                                    <label class="form-label">
                                        <i class="fas fa-building me-1 text-muted"></i> Department
                                    </label>
                                    <input type="text" name="department" class="form-control" 
                                           value="<?= htmlspecialchars($user['department'] ?? '') ?>"
                                           placeholder="e.g., Computer Science, Human Resources">
                                </div>
                                
                                <div class="col-md-12 mb-3">
                                    <label class="form-label">
                                        <i class="fas fa-envelope me-1 text-muted"></i> Email Address
                                    </label>
                                    <input type="email" class="form-control" 
                                           value="<?= htmlspecialchars($user['email']) ?>" disabled>
                                    <small class="text-muted">Email cannot be changed. Contact admin for assistance.</small>
                                </div>
                            </div>
                            
                            <hr class="my-4">
                            
                            <h6 class="mb-3">
                                <i class="fas fa-lock me-2" style="color: var(--primary-orange);"></i> Change Password
                            </h6>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Current Password</label>
                                    <input type="password" name="current_password" class="form-control" id="currentPassword">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">New Password</label>
                                    <input type="password" name="new_password" class="form-control" id="newPassword">
                                    <div class="password-strength">
                                        <div class="password-strength-bar" id="passwordStrengthBar"></div>
                                    </div>
                                    <small class="text-muted">Minimum 6 characters</small>
                                </div>
                            </div>
                            
                            <div class="d-flex gap-2 mt-3">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save me-2"></i> Save Changes
                                </button>
                                <a href="<?= $base_url ?>user/dashboard.php" class="btn btn-secondary">
                                    <i class="fas fa-arrow-left me-2"></i> Back to Dashboard
                                </a>
                                <button type="button" class="btn btn-outline-danger ms-auto" onclick="confirmDelete()">
                                    <i class="fas fa-trash-alt me-2"></i> Delete Account
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Account Security Info -->
                <div class="info-card">
                    <div class="card-header">
                        <h5><i class="fas fa-shield-alt me-2" style="color: var(--primary-orange);"></i> Account Security</h5>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-md-4">
                                <i class="fas fa-check-circle fa-2x text-success mb-2"></i>
                                <p class="mb-0"><strong>SSL Secure</strong></p>
                                <small class="text-muted">Connection is encrypted</small>
                            </div>
                            <div class="col-md-4">
                                <i class="fas fa-history fa-2x text-info mb-2"></i>
                                <p class="mb-0"><strong>Last Login</strong></p>
                                <small class="text-muted"><?= $user['last_login'] ?? 'Not recorded' ?></small>
                            </div>
                            <div class="col-md-4">
                                <i class="fas fa-database fa-2x text-warning mb-2"></i>
                                <p class="mb-0"><strong>Data Protected</strong></p>
                                <small class="text-muted">GDPR Compliant</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Delete Account Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" style="background: #dc3545; color: white;">
                    <h5 class="modal-title"><i class="fas fa-exclamation-triangle"></i> Delete Account</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-danger"><strong>Warning!</strong> This action cannot be undone.</p>
                    <p>Deleting your account will permanently remove:</p>
                    <ul>
                        <li>Your profile information</li>
                        <li>All items you've reported</li>
                        <li>All claim requests you've made</li>
                        <li>Your search history and notifications</li>
                    </ul>
                    <div class="alert alert-warning">
                        <i class="fas fa-info-circle"></i> Items you've reported will be orphaned and may be removed.
                    </div>
                    <label class="form-label">Type <strong>DELETE</strong> to confirm:</label>
                    <input type="text" id="deleteConfirm" class="form-control" placeholder="DELETE">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" onclick="deleteAccount()">Permanently Delete Account</button>
                </div>
            </div>
        </div>
    </div>
    
    <?php include __DIR__ . '/../includes/footer.php'; ?>
    
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
    
    // Delete account confirmation
    function confirmDelete() {
        const modal = new bootstrap.Modal(document.getElementById('deleteModal'));
        modal.show();
    }
    
    function deleteAccount() {
    const confirmText = document.getElementById('deleteConfirm').value;
    if (confirmText === 'DELETE') {
        if (confirm('⚠️ WARNING: This action is permanent and cannot be undone!\n\nAll your data including reports, claims, and profile information will be permanently removed.\n\nAre you absolutely sure you want to delete your account?')) {
            // Show loading indicator
            const deleteBtn = document.querySelector('#deleteModal .btn-danger');
            const originalText = deleteBtn.innerHTML;
            deleteBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';
            deleteBtn.disabled = true;
            
            // Redirect to delete endpoint
            window.location.href = '<?= $base_url ?>api/delete-account.php';
        }
    } else {
        alert('Please type DELETE to confirm account deletion');
    }
}
    
    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(function(alert) {
            setTimeout(function() {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            }, 5000);
        });
    }, 3000);
    </script>
</body>
</html>

<?php
function time_ago($timestamp) {
    if (!$timestamp) return 'Never';
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