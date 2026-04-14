<div class="col-md-2 p-0 sidebar">
    <!-- Admin Profile Section -->
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
        </a>
            <?php
            // Get unread count for badge
                require_once '../includes/notification.php';
                $notifSystem = new NotificationSystem();
                $unreadCount = $notifSystem->getUnreadCount($_SESSION['userID']);
                    if ($unreadCount > 0): ?>
                <span class="badge bg-danger float-end" style="font-size: 10px;"><?= $unreadCount ?></span>
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

<style>
.sidebar {
    min-height: 100vh;
    background: linear-gradient(135deg, #1A252F, #2C3E50);
    color: white;
    position: sticky;
    top: 0;
}

/* Admin Profile Section */
.admin-profile {
    border-bottom: 1px solid rgba(255,255,255,0.1);
}

.profile-avatar {
    width: 70px;
    height: 70px;
    background: rgba(255,107,53,0.2);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto;
    transition: all 0.3s;
    border: 2px solid rgba(255,107,53,0.5);
}

.profile-avatar i {
    font-size: 32px;
    color: #FF6B35;
}

.admin-profile h5 {
    font-size: 1rem;
    margin-bottom: 5px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    padding: 0 10px;
}

.admin-profile small {
    font-size: 0.7rem;
    display: block;
    opacity: 0.7;
}

.profile-info .admin-badge {
    background: rgba(255,107,53,0.2);
    color: #FF6B35;
    font-size: 0.65rem;
    padding: 5px 10px;
    border-radius: 20px;
    font-weight: normal;
    display: inline-block;
    max-width: 90%;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.profile-info .admin-badge i {
    font-size: 0.65rem;
    margin-right: 3px;
}

.sidebar a {
    color: rgba(255,255,255,0.8);
    text-decoration: none;
    display: block;
    padding: 12px 20px;
    transition: all 0.3s;
    border-left: 3px solid transparent;
    font-size: 14px;
}

.sidebar a:hover,
.sidebar a.active {
    background: rgba(255,255,255,0.1);
    color: white;
    border-left-color: #FF6B35;
}

.sidebar i {
    margin-right: 10px;
    width: 20px;
}

.sidebar hr {
    margin: 10px 20px;
}

.main-content {
    padding: 20px;
    background: #f8f9fa;
    min-height: 100vh;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .admin-profile h5 {
        font-size: 0.85rem;
    }
    
    .profile-info .admin-badge {
        font-size: 0.55rem;
        padding: 3px 8px;
    }
    
    .profile-avatar {
        width: 50px;
        height: 50px;
    }
    
    .profile-avatar i {
        font-size: 24px;
    }
    
    .sidebar a {
        padding: 10px 15px;
        font-size: 12px;
    }
}
</style>