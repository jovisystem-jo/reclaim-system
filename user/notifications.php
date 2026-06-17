<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$userID = $_SESSION['userID'];

// Handle mark as read
if (isset($_GET['mark_read']) && is_numeric($_GET['mark_read'])) {
    require_csrf_token();
    $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?");
    $stmt->execute([$_GET['mark_read'], $userID]);
    header('Location: notifications.php');
    exit();
}

// Handle mark all as read
if (isset($_GET['mark_all'])) {
    require_csrf_token();
    $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
    $stmt->execute([$userID]);
    header('Location: notifications.php');
    exit();
}

// Handle delete notification
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    require_csrf_token();
    $stmt = $db->prepare("DELETE FROM notifications WHERE notification_id = ? AND user_id = ?");
    $stmt->execute([$_GET['delete'], $userID]);
    header('Location: notifications.php');
    exit();
}

// Get filter parameter
$filter = $_GET['filter'] ?? 'all';
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Get overall notification statistics for summary cards and filter counts
$stats_stmt = $db->prepare("
    SELECT
        COUNT(*) as total,
        SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) as unread,
        SUM(CASE WHEN is_read = 1 THEN 1 ELSE 0 END) as read_count
    FROM notifications
    WHERE user_id = ?
");
$stats_stmt->execute([$userID]);
$notification_stats = $stats_stmt->fetch() ?: [];
$overall_total = (int) ($notification_stats['total'] ?? 0);
$overall_unread = (int) ($notification_stats['unread'] ?? 0);
$overall_read = (int) ($notification_stats['read_count'] ?? 0);

// Build query based on filter
$sql = "SELECT * FROM notifications WHERE user_id = ?";
$params = [$userID];

if ($filter === 'unread') {
    $sql .= " AND is_read = 0";
} elseif ($filter === 'read') {
    $sql .= " AND is_read = 1";
}

// Get total count for pagination
$count_sql = str_replace("SELECT *", "SELECT COUNT(*)", $sql);
$stmt = $db->prepare($count_sql);
$stmt->execute($params);
$total_notifications = (int) $stmt->fetchColumn();
$total_pages = $total_notifications > 0 ? (int) ceil($total_notifications / $per_page) : 1;

if ($page > $total_pages) {
    $page = $total_pages;
    $offset = ($page - 1) * $per_page;
}

// Get notifications
$sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
$params[] = $per_page;
$params[] = $offset;
$stmt = $db->prepare($sql);
$stmt->execute($params);
$notifications = $stmt->fetchAll();

$empty_title = 'No notifications yet';
$empty_message = "You're all caught up. Updates about your reports, claims, and account activity will appear here.";

if ($filter === 'unread') {
    $empty_title = 'No unread notifications';
    $empty_message = $overall_total > 0
        ? 'Everything in your inbox has already been opened.'
        : "You're all caught up. New unread alerts will show up here.";
} elseif ($filter === 'read') {
    $empty_title = 'No read notifications';
    $empty_message = $overall_unread > 0
        ? 'You still have unread updates waiting for you.'
        : 'Read notifications will appear here after you open them.';
}

$base_url = app_base_path();
if (!defined('RECLAIM_EMBEDDED_LAYOUT')) {
    define('RECLAIM_EMBEDDED_LAYOUT', true);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - Reclaim System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $base_url ?>assets/css/style.css">
    <style>
        .notifications-page .content-wrapper {
            margin-top: 20px;
        }

        .notifications-page-shell {
            padding-bottom: 36px;
        }

        .notification-overview {
            margin-bottom: 18px;
        }

        .notification-hero {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 20px;
            flex-wrap: wrap;
            margin-bottom: 16px;
            padding: 24px 26px;
            border-radius: 28px;
            background:
                radial-gradient(circle at top right, rgba(255, 215, 0, 0.18), transparent 34%),
                linear-gradient(145deg, rgba(255, 255, 255, 0.96), rgba(255, 248, 238, 0.94));
            border: 1px solid rgba(255, 140, 0, 0.16);
            box-shadow: 0 22px 50px rgba(31, 41, 51, 0.10);
        }

        .notification-hero-copy {
            max-width: 680px;
        }

        .notification-hero-copy .eyebrow {
            margin-bottom: 10px;
        }

        .notification-hero-title {
            margin: 0 0 8px;
            font-size: clamp(1.7rem, 1.4vw + 1.1rem, 2.3rem);
            font-weight: 800;
            letter-spacing: -0.02em;
        }

        .notification-hero-subtitle {
            margin: 0;
            max-width: 580px;
            font-size: 0.92rem;
            line-height: 1.65;
            color: #5f6c7b;
        }

        .notification-hero-actions {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 10px;
            flex-wrap: wrap;
        }

        .notification-pill-btn,
        .notification-secondary-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 42px;
            padding: 0 18px;
            border-radius: 999px;
            font-size: 0.82rem;
            font-weight: 700;
            text-align: center;
            transition: transform 0.2s ease, box-shadow 0.2s ease, background-color 0.2s ease;
        }

        .notification-pill-btn {
            background: linear-gradient(135deg, #ff9f1a, #ff7c0a);
            border: none;
            color: #fff;
            box-shadow: 0 12px 22px rgba(255, 124, 10, 0.22);
        }

        .notification-pill-btn:hover {
            color: #fff;
            transform: translateY(-1px);
            box-shadow: 0 15px 28px rgba(255, 124, 10, 0.26);
        }

        .notification-secondary-btn {
            border: 1px solid rgba(255, 140, 0, 0.22);
            background: rgba(255, 255, 255, 0.82);
            color: #d96b00;
        }

        .notification-secondary-btn:hover {
            color: #b85b00;
            background: #fff7ef;
        }

        .notification-stats-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
        }

        .notification-stat-card {
            position: relative;
            padding: 18px 18px 16px;
            border-radius: 22px;
            border: 1px solid rgba(255, 140, 0, 0.12);
            background: rgba(255, 255, 255, 0.88);
            box-shadow: 0 12px 24px rgba(31, 41, 51, 0.08);
            overflow: hidden;
        }

        .notification-stat-card::after {
            content: "";
            position: absolute;
            inset: auto -10px -18px auto;
            width: 82px;
            height: 82px;
            border-radius: 50%;
            background: rgba(255, 140, 0, 0.08);
        }

        .notification-stat-label {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 10px;
            color: #7c8794;
            font-size: 0.74rem;
            font-weight: 700;
            letter-spacing: 0.02em;
            text-transform: uppercase;
        }

        .notification-stat-value {
            position: relative;
            z-index: 1;
            margin: 0;
            font-size: clamp(1.5rem, 0.9vw + 1.2rem, 2rem);
            font-weight: 800;
            color: #1f2933;
        }

        body.notifications-page .notifications-panel {
            overflow: hidden;
            border: 1px solid rgba(255, 140, 0, 0.12);
            border-radius: 22px;
            background: rgba(255, 255, 255, 0.96);
            box-shadow: 0 16px 32px rgba(31, 41, 51, 0.1);
        }

        body.notifications-page .notifications-panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            flex-wrap: wrap;
            padding: 18px 20px 15px;
            border-bottom: 1px solid rgba(31, 41, 51, 0.06);
            background: linear-gradient(180deg, rgba(255, 248, 238, 0.9), rgba(255, 255, 255, 0.96));
        }

        body.notifications-page .notifications-panel-title {
            display: flex;
            align-items: center;
            gap: 11px;
            min-width: 0;
        }

        body.notifications-page .notifications-panel-title > div {
            min-width: 0;
        }

        body.notifications-page .notifications-panel-icon {
            width: 42px;
            height: 42px;
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            background: linear-gradient(135deg, rgba(255, 159, 26, 0.18), rgba(255, 124, 10, 0.16));
            color: #ff7c0a;
            font-size: 1rem;
        }

        body.notifications-page .notifications-panel-title h4 {
            margin: 0 0 3px;
            font-size: 1.02rem;
            font-weight: 800;
            color: #18253d;
        }

        body.notifications-page .notifications-panel-title p {
            margin: 0;
            color: #6f7c89;
            font-size: 0.76rem;
        }

        body.notifications-page .notification-header-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        body.notifications-page .notification-chip {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 30px;
            padding: 0 12px;
            border-radius: 999px;
            background: rgba(255, 140, 0, 0.1);
            color: #d86a00;
            font-size: 0.72rem;
            font-weight: 700;
        }

        body.notifications-page .notification-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            padding: 12px 20px;
            border-bottom: 1px solid rgba(31, 41, 51, 0.06);
            background: rgba(255, 252, 247, 0.78);
        }

        body.notifications-page .notification-filter-group {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        body.notifications-page .filter-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            min-height: 34px;
            padding: 0 14px;
            border-radius: 999px;
            border: 1px solid rgba(255, 140, 0, 0.16);
            background: #fff;
            color: #687483;
            font-size: 0.75rem;
            font-weight: 700;
            text-decoration: none;
            transition: background-color 0.2s ease, color 0.2s ease, border-color 0.2s ease, transform 0.2s ease;
        }

        body.notifications-page .filter-btn:hover {
            color: #cf6800;
            border-color: rgba(255, 140, 0, 0.28);
            background: #fff7ef;
            transform: translateY(-1px);
        }

        body.notifications-page .filter-btn.active {
            border-color: #ff8c00;
            background: linear-gradient(135deg, #ff9f1a, #ff7c0a);
            color: #fff;
            box-shadow: 0 10px 20px rgba(255, 124, 10, 0.18);
        }

        body.notifications-page .filter-btn-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 22px;
            height: 22px;
            padding: 0 6px;
            border-radius: 999px;
            background: rgba(31, 41, 51, 0.08);
            color: inherit;
            font-size: 0.68rem;
            font-weight: 800;
        }

        body.notifications-page .filter-btn.active .filter-btn-count {
            background: rgba(255, 255, 255, 0.18);
        }

        body.notifications-page .notification-filter-note {
            color: #7c8794;
            font-size: 0.75rem;
            font-weight: 600;
        }

        body.notifications-page .notification-stream {
            padding: 8px 10px 0;
        }

        body.notifications-page .notification-page-item {
            display: block;
            margin-bottom: 8px;
            padding: 14px 15px;
            border: 1px solid rgba(31, 41, 51, 0.06);
            border-left: 4px solid transparent;
            border-radius: 18px;
            background: #fff;
            transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease, background-color 0.2s ease;
        }

        body.notifications-page .notification-page-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 24px rgba(31, 41, 51, 0.08);
        }

        body.notifications-page .notification-page-item.unread {
            border-left-color: #ff8c00;
            background: linear-gradient(135deg, rgba(255, 248, 240, 0.98), rgba(255, 255, 255, 1));
        }

        body.notifications-page .notification-page-main {
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }

        body.notifications-page .notification-icon-page {
            width: 40px;
            height: 40px;
            margin-right: 0;
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            box-shadow: 0 8px 18px rgba(17, 24, 39, 0.08);
        }

        body.notifications-page .notification-page-content {
            flex: 1;
            min-width: 0;
        }

        body.notifications-page .notification-page-topline {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 6px;
        }

        body.notifications-page .notification-title-wrap {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        body.notifications-page .notification-title {
            margin: 0;
            font-size: 0.9rem;
            font-weight: 700;
            color: #24313d;
        }

        body.notifications-page .notification-title-wrap .badge {
            border-radius: 999px;
            padding: 4px 7px;
            font-size: 0.66rem;
            font-weight: 700;
        }

        body.notifications-page .notification-message {
            margin: 0;
            color: #667281;
            font-size: 0.79rem;
            line-height: 1.55;
        }

        body.notifications-page .notification-time {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            color: #8a94a0;
            font-size: 0.71rem;
            white-space: nowrap;
        }

        body.notifications-page .notification-actions {
            display: flex;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
            margin-top: 12px;
        }

        body.notifications-page .mark-read-btn,
        body.notifications-page .delete-notif {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 0;
            border: none;
            background: none;
            font-size: 0.74rem;
            font-weight: 700;
            text-decoration: none;
        }

        body.notifications-page .mark-read-btn {
            color: #239253;
        }

        body.notifications-page .mark-read-btn:hover {
            color: #167a40;
        }

        body.notifications-page .delete-notif {
            color: #d64545;
        }

        body.notifications-page .delete-notif:hover {
            color: #b43131;
        }

        body.notifications-page .notification-empty {
            padding: 44px 22px;
            text-align: center;
        }

        body.notifications-page .notification-empty-icon {
            width: 74px;
            height: 74px;
            margin: 0 auto 16px;
            border-radius: 22px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, rgba(255, 159, 26, 0.14), rgba(255, 124, 10, 0.10));
            color: #2f3742;
            font-size: 1.9rem;
        }

        body.notifications-page .notification-empty h5 {
            margin-bottom: 8px;
            font-size: 1.08rem;
            font-weight: 800;
            color: #18253d;
        }

        body.notifications-page .notification-empty p {
            max-width: 460px;
            margin: 0 auto 18px;
            color: #7c8794;
            font-size: 0.82rem;
            line-height: 1.65;
        }

        body.notifications-page .notification-empty-actions {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        body.notifications-page .notification-pagination-wrap {
            padding: 14px 18px 18px;
        }

        body.notifications-page .pagination {
            margin: 0;
        }

        body.notifications-page .pagination .page-link {
            border: 1px solid rgba(255, 140, 0, 0.16);
            border-radius: 10px;
            margin: 0 3px;
            color: #d96b00;
            background: #fff;
            box-shadow: none;
        }

        body.notifications-page .pagination .page-item.active .page-link {
            color: #fff;
            background: #ff8c00;
            border-color: #ff8c00;
        }

        body.notifications-page .pagination .page-item.disabled .page-link {
            color: #b9c1ca;
            background: #f7f8fa;
        }

        @media (max-width: 991.98px) {
            .notification-stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 767.98px) {
            .notification-hero,
            .notifications-panel-header,
            .notification-toolbar {
                padding-left: 18px;
                padding-right: 18px;
            }

            .notification-stats-grid {
                grid-template-columns: 1fr;
            }

            .notification-page-item {
                padding: 16px;
                border-radius: 18px;
            }

            .notification-page-topline {
                flex-direction: column;
                align-items: flex-start;
            }

            .notification-actions {
                gap: 12px;
            }
        }

        @media (max-width: 575.98px) {
            .notifications-page .content-wrapper {
                margin-top: 14px;
            }

            .notification-hero {
                padding: 18px 16px;
                border-radius: 22px;
            }

            .notification-pill-btn,
            .notification-secondary-btn,
            .notification-action-btn {
                width: 100%;
            }

            .notification-hero-actions,
            .notification-empty-actions {
                width: 100%;
            }

            .filter-btn {
                width: 100%;
                justify-content: space-between;
            }

            .notification-page-main {
                flex-direction: column;
            }
        }
    </style>
</head>
<body class="app-page user-page notifications-page">
    <?php include __DIR__ . '/../includes/header.php'; ?>

    <main class="page-shell notifications-page-shell">
        <div class="container content-wrapper">
            <section class="notification-overview fade-in">
                <div class="notification-hero">
                    <div class="notification-hero-copy">
                        <span class="eyebrow"><i class="fas fa-bell"></i> Notification center</span>
                        <h1 class="notification-hero-title">Stay on top of every update</h1>
                        <p class="notification-hero-subtitle">
                            Review claim activity, report interactions, and account messages in one clean inbox designed to match the quick notification preview from the header.
                        </p>
                    </div>
                    <div class="notification-hero-actions">
                        <a href="<?= $base_url ?>user/dashboard.php" class="notification-secondary-btn">
                            <i class="fas fa-arrow-left"></i> Back to Dashboard
                        </a>
                        <?php if ($overall_unread > 0): ?>
                        <a href="?mark_all=1&csrf_token=<?= urlencode(csrf_token()) ?>" class="notification-pill-btn" onclick="return confirm('Mark all notifications as read?')">
                            <i class="fas fa-check-double"></i> Mark all as read
                        </a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="notification-stats-grid">
                    <div class="notification-stat-card">
                        <div class="notification-stat-label"><i class="fas fa-inbox"></i> Total</div>
                        <p class="notification-stat-value"><?= number_format($overall_total) ?></p>
                    </div>
                    <div class="notification-stat-card">
                        <div class="notification-stat-label"><i class="fas fa-envelope"></i> Unread</div>
                        <p class="notification-stat-value"><?= number_format($overall_unread) ?></p>
                    </div>
                    <div class="notification-stat-card">
                        <div class="notification-stat-label"><i class="fas fa-envelope-open"></i> Read</div>
                        <p class="notification-stat-value"><?= number_format($overall_read) ?></p>
                    </div>
                </div>
            </section>

            <section class="notifications-panel fade-in">
                <div class="notifications-panel-header">
                    <div class="notifications-panel-title">
                        <span class="notifications-panel-icon">
                            <i class="fas fa-bell"></i>
                        </span>
                        <div>
                            <h4>Notifications</h4>
                            <p><?= number_format($total_notifications) ?> item<?= $total_notifications === 1 ? '' : 's' ?> in this view</p>
                        </div>
                    </div>
                    <div class="notification-header-actions">
                        <?php if ($overall_unread > 0): ?>
                        <span class="notification-chip">
                            <i class="fas fa-circle me-2" style="font-size: 7px;"></i><?= number_format($overall_unread) ?> unread
                        </span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="notification-toolbar">
                    <div class="notification-filter-group">
                        <a class="filter-btn <?= $filter === 'all' ? 'active' : '' ?>" href="?filter=all">
                            <i class="fas fa-list"></i>
                            <span>All</span>
                            <span class="filter-btn-count"><?= number_format($overall_total) ?></span>
                        </a>
                        <a class="filter-btn <?= $filter === 'unread' ? 'active' : '' ?>" href="?filter=unread">
                            <i class="fas fa-envelope"></i>
                            <span>Unread</span>
                            <span class="filter-btn-count"><?= number_format($overall_unread) ?></span>
                        </a>
                        <a class="filter-btn <?= $filter === 'read' ? 'active' : '' ?>" href="?filter=read">
                            <i class="fas fa-envelope-open"></i>
                            <span>Read</span>
                            <span class="filter-btn-count"><?= number_format($overall_read) ?></span>
                        </a>
                    </div>
                    <div class="notification-filter-note">Latest activity appears first</div>
                </div>

                <?php if (empty($notifications)): ?>
                    <div class="notification-empty">
                        <div class="notification-empty-icon">
                            <i class="fas fa-bell-slash"></i>
                        </div>
                        <h5><?= htmlspecialchars($empty_title) ?></h5>
                        <p><?= htmlspecialchars($empty_message) ?></p>
                        <div class="notification-empty-actions">
                            <?php if ($filter !== 'all'): ?>
                            <a href="?filter=all" class="notification-pill-btn">
                                <i class="fas fa-list"></i> View all notifications
                            </a>
                            <?php endif; ?>
                            <a href="<?= $base_url ?>user/dashboard.php" class="notification-secondary-btn">
                                <i class="fas fa-home"></i> Go to Dashboard
                            </a>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="notification-stream">
                        <?php foreach ($notifications as $notification): ?>
                            <article class="notification-page-item <?= $notification['is_read'] == 0 ? 'unread' : '' ?>">
                                <div class="notification-page-main">
                                    <div class="notification-icon-page <?= getIconClass($notification['type']) ?>">
                                        <i class="fas <?= getIcon($notification['type']) ?>"></i>
                                    </div>
                                    <div class="notification-page-content">
                                        <div class="notification-page-topline">
                                            <div class="notification-title-wrap">
                                                <h5 class="notification-title"><?= htmlspecialchars($notification['title'] ?? 'Notification') ?></h5>
                                                <?php if ($notification['is_read'] == 0): ?>
                                                    <span class="badge bg-primary">New</span>
                                                <?php endif; ?>
                                            </div>
                                            <span class="notification-time">
                                                <i class="far fa-clock"></i>
                                                <?= timeAgo($notification['created_at']) ?>
                                            </span>
                                        </div>
                                        <p class="notification-message"><?= nl2br(htmlspecialchars($notification['message'])) ?></p>
                                        <div class="notification-actions">
                                            <?php if ($notification['is_read'] == 0): ?>
                                                <a href="?mark_read=<?= $notification['notification_id'] ?>&csrf_token=<?= urlencode(csrf_token()) ?>" class="mark-read-btn">
                                                    <i class="fas fa-check-circle"></i> Mark as read
                                                </a>
                                            <?php endif; ?>
                                            <a href="?delete=<?= $notification['notification_id'] ?>&csrf_token=<?= urlencode(csrf_token()) ?>" class="delete-notif" onclick="return confirm('Delete this notification?')">
                                                <i class="fas fa-trash-alt"></i> Delete
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($total_pages > 1): ?>
                    <div class="notification-pagination-wrap">
                        <nav>
                            <ul class="pagination justify-content-center">
                                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                    <a class="page-link" href="?filter=<?= $filter ?>&page=<?= $page - 1 ?>">Previous</a>
                                </li>
                                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                    <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                        <a class="page-link" href="?filter=<?= $filter ?>&page=<?= $i ?>"><?= $i ?></a>
                                    </li>
                                <?php endfor; ?>
                                <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                                    <a class="page-link" href="?filter=<?= $filter ?>&page=<?= $page + 1 ?>">Next</a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        </div>
    </main>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>

<?php
function timeAgo($timestamp) {
    if (!$timestamp) {
        return 'Never';
    }

    $time_ago = strtotime($timestamp);
    $current_time = time();
    $time_difference = $current_time - $time_ago;

    if ($time_difference < 60) {
        return 'Just now';
    }

    if ($time_difference < 3600) {
        $minutes = floor($time_difference / 60);
        return $minutes . ' minute' . ($minutes > 1 ? 's' : '') . ' ago';
    }

    if ($time_difference < 86400) {
        $hours = floor($time_difference / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    }

    if ($time_difference < 604800) {
        $days = floor($time_difference / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    }

    return date('M d, Y', $time_ago);
}

function getIcon($type) {
    $icons = [
        'info' => 'fa-info-circle',
        'success' => 'fa-check-circle',
        'warning' => 'fa-exclamation-triangle',
        'danger' => 'fa-times-circle',
    ];

    return $icons[$type] ?? 'fa-bell';
}

function getIconClass($type) {
    $classes = [
        'info' => 'info',
        'success' => 'success',
        'warning' => 'warning',
        'danger' => 'danger',
    ];

    return $classes[$type] ?? 'info';
}
?>
