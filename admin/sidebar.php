<div class="col-md-2 p-0 sidebar">
    <div class="admin-profile text-center pt-4 pb-3">
        <div class="profile-avatar mb-3">
            <i class="fas fa-user-shield"></i>
        </div>
        <h5 class="fw-bold mb-1"><?= htmlspecialchars($_SESSION['name']) ?></h5>
        <small class="opacity-75">
            <i class="fas fa-crown me-1"></i> Administrator
        </small>
        <div class="profile-info mt-2">
            <span class="badge admin-badge">
                <i class="fas fa-envelope me-1"></i> <?= htmlspecialchars($_SESSION['email'] ?? 'admin@reclaim.com') ?>
            </span>
        </div>
    </div>
    
    <hr class="mx-3 my-2" style="border-color: rgba(255,255,255,0.1);">
    
    <nav>
        <a href="dashboard.php" <?= basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'class="active"' : '' ?>>
            <i class="fas fa-tachometer-alt"></i> Dashboard
        </a>
        <a href="manage-users.php" <?= basename($_SERVER['PHP_SELF']) == 'manage-users.php' ? 'class="active"' : '' ?>>
            <i class="fas fa-users"></i> Manage Users
        </a>
        <a href="verify-claims.php" <?= basename($_SERVER['PHP_SELF']) == 'verify-claims.php' ? 'class="active"' : '' ?>>
            <i class="fas fa-check-double"></i> Verify Claims
        </a>
        <a href="manage-items.php" <?= basename($_SERVER['PHP_SELF']) == 'manage-items.php' ? 'class="active"' : '' ?>>
            <i class="fas fa-boxes"></i> Manage Items
        </a>
        <a href="reports.php" <?= basename($_SERVER['PHP_SELF']) == 'reports.php' ? 'class="active"' : '' ?>>
            <i class="fas fa-chart-bar"></i> Reports
        </a>
        <a href="notifications.php" <?= basename($_SERVER['PHP_SELF']) == 'notifications.php' ? 'class="active"' : '' ?>>
            <i class="fas fa-bell"></i> Notifications
            <?php
            require_once '../includes/notification.php';
            $notifSystem = new NotificationSystem();
            $unreadCount = $notifSystem->getUnreadCount($_SESSION['userID']);
            if ($unreadCount > 0): ?>
                <span class="badge bg-danger float-end"><?= $unreadCount ?></span>
            <?php endif; ?>
        </a>
        <a href="profile.php" <?= basename($_SERVER['PHP_SELF']) == 'profile.php' ? 'class="active"' : '' ?>>
            <i class="fas fa-user-circle"></i> My Profile
        </a>
        <hr class="mx-3 my-2" style="border-color: rgba(255,255,255,0.1);">
        <a href="../logout.php">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </nav>
</div>
