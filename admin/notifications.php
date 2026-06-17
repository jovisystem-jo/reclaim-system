<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

$db = Database::getInstance()->getConnection();
$notification = null;
$message = '';
$error = '';
$page_load_error = '';
$notifications = [];
$email_logs = [];
$stats = [
    'total' => 0,
    'unread' => 0,
    'info' => 0,
    'success' => 0,
    'warning' => 0,
    'danger' => 0,
];
$total_notifications = 0;
$total_pages = 1;

try {
    require_once __DIR__ . '/../includes/notification.php';
    $notification = new NotificationSystem();
} catch (Throwable $e) {
    error_log('Admin notifications bootstrap failed: ' . $e->getMessage());
    $error = 'Notification tools are temporarily unavailable right now.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    require_csrf_token();

    $action = trim((string) ($_POST['action'] ?? ''));

    if (in_array($action, ['send_bulk', 'mark_read', 'mark_all_read'], true) && !$notification) {
        $error = 'Notification tools are temporarily unavailable right now.';
    } else {
        try {
            if ($action === 'send_bulk') {
                $title = trim((string) ($_POST['title'] ?? ''));
                $message_text = trim((string) ($_POST['message'] ?? ''));
                $recipient_type = trim((string) ($_POST['recipient_type'] ?? 'all'));
                $send_email = isset($_POST['send_email']);
                $type = trim((string) ($_POST['notification_type'] ?? 'info'));

                if ($title === '' || $message_text === '') {
                    $error = 'Please fill in both title and message';
                } else {
                    if ($recipient_type === 'all') {
                        $count = $notification->sendToAll($title, $message_text, $type, null, $send_email);
                    } elseif ($recipient_type === 'students') {
                        $count = $notification->sendToAll($title, $message_text, $type, 'student', $send_email);
                    } elseif ($recipient_type === 'staff') {
                        $count = $notification->sendToAll($title, $message_text, $type, 'staff', $send_email);
                    } elseif ($recipient_type === 'admins') {
                        $count = $notification->sendToAdmins($title, $message_text, $type, $send_email);
                    } else {
                        $count = 0;
                    }

                    $message = 'Notification sent to ' . (int) $count . ' user(s)' . ($send_email ? ' (with email)' : '');
                }
            } elseif ($action === 'mark_read') {
                $notification_id = (int) ($_POST['notification_id'] ?? 0);
                if ($notification_id > 0) {
                    $notification->markAsRead($notification_id, $_SESSION['userID']);
                    $message = 'Notification marked as read';
                }
            } elseif ($action === 'mark_all_read') {
                $notification->markAllAsRead($_SESSION['userID']);
                $message = 'All notifications marked as read';
            } elseif ($action === 'delete') {
                $notification_id = (int) ($_POST['notification_id'] ?? 0);
                if ($notification_id > 0) {
                    $stmt = $db->prepare('DELETE FROM notifications WHERE notification_id = ? AND user_id = ?');
                    $stmt->execute([$notification_id, $_SESSION['userID']]);
                    $message = 'Notification deleted';
                }
            } else {
                $error = 'Unknown notification action.';
            }
        } catch (Throwable $e) {
            error_log('Admin notifications action failed: ' . $e->getMessage());
            $error = 'Unable to update notifications right now.';
        }
    }
}

$filter = trim((string) ($_GET['filter'] ?? 'all'));
$allowedFilters = ['all', 'unread', 'read'];
if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'all';
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

$notificationsHasCreatedAt = reclaimTableColumnExists($db, 'notifications', 'created_at');
$notificationOrderBy = $notificationsHasCreatedAt
    ? 'created_at DESC, notification_id DESC'
    : 'notification_id DESC';
$emailLogsAvailable = reclaimTableColumnExists($db, 'email_logs', 'recipient_email');
$emailLogsHasSentAt = reclaimTableColumnExists($db, 'email_logs', 'sent_at');

try {
    $countSql = 'SELECT COUNT(*) FROM notifications WHERE user_id = ?';
    $countParams = [(int) $_SESSION['userID']];

    if ($filter === 'unread') {
        $countSql .= ' AND is_read = 0';
    } elseif ($filter === 'read') {
        $countSql .= ' AND is_read = 1';
    }

    $stmt = $db->prepare($countSql);
    $stmt->execute($countParams);
    $total_notifications = (int) $stmt->fetchColumn();
    $total_pages = max(1, (int) ceil($total_notifications / $per_page));

    $listSql = 'SELECT * FROM notifications WHERE user_id = ?';
    $listParams = [(int) $_SESSION['userID']];

    if ($filter === 'unread') {
        $listSql .= ' AND is_read = 0';
    } elseif ($filter === 'read') {
        $listSql .= ' AND is_read = 1';
    }

    $listSql .= " ORDER BY {$notificationOrderBy} LIMIT ? OFFSET ?";
    $listParams[] = $per_page;
    $listParams[] = $offset;

    $stmt = $db->prepare($listSql);
    foreach ($listParams as $index => $value) {
        $stmt->bindValue($index + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($emailLogsAvailable) {
        $emailLogsSql = 'SELECT * FROM email_logs';
        if ($emailLogsHasSentAt) {
            $emailLogsSql .= ' ORDER BY sent_at DESC';
        }
        $emailLogsSql .= ' LIMIT 50';

        $stmt = $db->prepare($emailLogsSql);
        $stmt->execute();
        $email_logs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $stmt = $db->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) AS unread,
            SUM(CASE WHEN type = 'info' THEN 1 ELSE 0 END) AS info,
            SUM(CASE WHEN type = 'success' THEN 1 ELSE 0 END) AS success,
            SUM(CASE WHEN type = 'warning' THEN 1 ELSE 0 END) AS warning,
            SUM(CASE WHEN type = 'danger' THEN 1 ELSE 0 END) AS danger
        FROM notifications
        WHERE user_id = ?
    ");
    $stmt->execute([(int) $_SESSION['userID']]);
    $stats = array_merge($stats, $stmt->fetch(PDO::FETCH_ASSOC) ?: []);
} catch (PDOException $e) {
    error_log('Admin notifications data load failed: ' . $e->getMessage());
    $page_load_error = 'Some notification data could not be loaded right now.';
}

$base_url = app_base_path();
$notificationsAvailable = $notification !== null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $base_url ?>assets/css/style.css">
    <style>
        * { font-family: 'Inter', sans-serif; }
        body { background: #f0f2f5; }
        .main-content { padding: 20px; min-height: 100vh; }

        /* ── Page header ── */
        .notif-page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 22px;
        }

        .notif-page-header h2 {
            margin: 0;
            font-size: 1.55rem;
            font-weight: 800;
            color: #1f2933;
        }

        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            min-height: 38px;
            padding: 0 18px;
            border-radius: 999px;
            border: 1px solid rgba(255, 140, 0, 0.22);
            background: rgba(255, 255, 255, 0.92);
            color: #d96b00;
            font-size: 0.82rem;
            font-weight: 700;
            text-decoration: none;
            transition: background-color 0.2s ease, color 0.2s ease;
        }

        .btn-back:hover { background: #fff7ef; color: #b85b00; }

        /* ── Stat cards ── */
        .notif-stat-card {
            position: relative;
            padding: 18px 18px 16px;
            border-radius: 22px;
            border: 1px solid rgba(255, 140, 0, 0.12);
            background: rgba(255, 255, 255, 0.95);
            box-shadow: 0 12px 24px rgba(31, 41, 51, 0.08);
            overflow: hidden;
            height: 100%;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .notif-stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 18px 32px rgba(31, 41, 51, 0.12);
        }

        .notif-stat-card::after {
            content: "";
            position: absolute;
            inset: auto -10px -18px auto;
            width: 82px;
            height: 82px;
            border-radius: 50%;
            background: rgba(255, 140, 0, 0.07);
        }

        .notif-stat-label {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 10px;
            color: #7c8794;
            font-size: 0.73rem;
            font-weight: 700;
            letter-spacing: 0.02em;
            text-transform: uppercase;
        }

        .notif-stat-value {
            position: relative;
            z-index: 1;
            margin: 0;
            font-size: clamp(1.5rem, 0.9vw + 1.2rem, 2rem);
            font-weight: 800;
            color: #1f2933;
        }

        /* ── Panel cards ── */
        .notif-panel {
            overflow: hidden;
            border: 1px solid rgba(255, 140, 0, 0.12);
            border-radius: 22px;
            background: rgba(255, 255, 255, 0.97);
            box-shadow: 0 16px 32px rgba(31, 41, 51, 0.09);
            margin-bottom: 20px;
        }

        .notif-panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            padding: 15px 20px 13px;
            border-bottom: 1px solid rgba(31, 41, 51, 0.06);
            background: linear-gradient(180deg, rgba(255, 248, 238, 0.9), rgba(255, 255, 255, 0.96));
        }

        .notif-panel-title {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .notif-panel-icon {
            width: 40px;
            height: 40px;
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            background: linear-gradient(135deg, rgba(255, 159, 26, 0.18), rgba(255, 124, 10, 0.16));
            color: #ff7c0a;
            font-size: 1rem;
        }

        .notif-panel-title h4 { margin: 0 0 2px; font-size: 1rem; font-weight: 800; color: #18253d; }
        .notif-panel-title p  { margin: 0; color: #6f7c89; font-size: 0.74rem; }

        .notif-unread-chip {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            min-height: 28px;
            padding: 0 11px;
            border-radius: 999px;
            background: rgba(255, 140, 0, 0.1);
            color: #d86a00;
            font-size: 0.7rem;
            font-weight: 700;
        }

        /* ── Toolbar & filter buttons ── */
        .notif-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            padding: 10px 20px;
            border-bottom: 1px solid rgba(31, 41, 51, 0.06);
            background: rgba(255, 252, 247, 0.78);
        }

        .notif-filter-group { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }

        .notif-filter-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            min-height: 32px;
            padding: 0 13px;
            border-radius: 999px;
            border: 1px solid rgba(255, 140, 0, 0.16);
            background: #fff;
            color: #687483;
            font-size: 0.74rem;
            font-weight: 700;
            text-decoration: none;
            transition: background-color 0.2s ease, color 0.2s ease, border-color 0.2s ease, transform 0.2s ease;
        }

        .notif-filter-btn:hover {
            color: #cf6800;
            border-color: rgba(255, 140, 0, 0.28);
            background: #fff7ef;
            transform: translateY(-1px);
        }

        .notif-filter-btn.active {
            border-color: #ff8c00;
            background: linear-gradient(135deg, #ff9f1a, #ff7c0a);
            color: #fff;
            box-shadow: 0 8px 18px rgba(255, 124, 10, 0.18);
        }

        .notif-filter-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 20px;
            height: 20px;
            padding: 0 5px;
            border-radius: 999px;
            background: rgba(31, 41, 51, 0.08);
            font-size: 0.67rem;
            font-weight: 800;
            color: inherit;
        }

        .notif-filter-btn.active .notif-filter-count { background: rgba(255, 255, 255, 0.2); }

        .notif-mark-all-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            min-height: 30px;
            padding: 0 13px;
            border-radius: 999px;
            border: 1px solid rgba(255, 140, 0, 0.22);
            background: rgba(255, 255, 255, 0.85);
            color: #d96b00;
            font-size: 0.72rem;
            font-weight: 700;
            cursor: pointer;
            transition: background-color 0.2s ease, color 0.2s ease;
        }

        .notif-mark-all-btn:hover:not(:disabled) { background: #fff7ef; color: #b85b00; }
        .notif-mark-all-btn:disabled { opacity: 0.5; cursor: not-allowed; }

        /* ── Notification items ── */
        .notif-stream { padding: 8px 10px 2px; }

        .notif-item {
            display: block;
            margin-bottom: 8px;
            padding: 13px 14px;
            border: 1px solid rgba(31, 41, 51, 0.06);
            border-left: 4px solid transparent;
            border-radius: 16px;
            background: #fff;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .notif-item:hover { transform: translateY(-2px); box-shadow: 0 10px 22px rgba(31, 41, 51, 0.08); }

        .notif-item.unread {
            border-left-color: #ff8c00;
            background: linear-gradient(135deg, rgba(255, 248, 240, 0.98), #fff);
        }

        .notif-item-main { display: flex; align-items: flex-start; gap: 11px; }

        .notif-icon {
            width: 38px;
            height: 38px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 0.9rem;
        }

        .notif-icon.info    { background: rgba(59,130,246,0.12);  color: #2563eb; }
        .notif-icon.success { background: rgba(34,197,94,0.12);   color: #16a34a; }
        .notif-icon.warning { background: rgba(251,191,36,0.14);  color: #b45309; }
        .notif-icon.danger  { background: rgba(239,68,68,0.12);   color: #dc2626; }

        .notif-item-content { flex: 1; min-width: 0; }

        .notif-item-topline {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 4px;
        }

        .notif-title-wrap { display: flex; align-items: center; gap: 7px; flex-wrap: wrap; }

        .notif-title { margin: 0; font-size: 0.88rem; font-weight: 700; color: #1f2933; }

        .notif-time {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            color: #8a94a0;
            font-size: 0.7rem;
            white-space: nowrap;
        }

        .notif-message { margin: 0; color: #667281; font-size: 0.78rem; line-height: 1.55; }

        .notif-item-actions { display: flex; align-items: center; gap: 12px; margin-top: 9px; }

        .notif-action-link {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 0;
            border: none;
            background: none;
            font-size: 0.72rem;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
        }

        .notif-action-link.mark-read       { color: #239253; }
        .notif-action-link.mark-read:hover { color: #167a40; }
        .notif-action-link.delete          { color: #d64545; }
        .notif-action-link.delete:hover    { color: #b43131; }

        /* ── Empty state ── */
        .notif-empty { padding: 40px 20px; text-align: center; }

        .notif-empty-icon {
            width: 66px;
            height: 66px;
            margin: 0 auto 14px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, rgba(255, 159, 26, 0.14), rgba(255, 124, 10, 0.10));
            color: #2f3742;
            font-size: 1.75rem;
        }

        .notif-empty h5 { font-size: 1rem; font-weight: 800; color: #18253d; margin-bottom: 6px; }
        .notif-empty p  { max-width: 380px; margin: 0 auto; color: #7c8794; font-size: 0.79rem; line-height: 1.6; }

        /* ── Bulk send form ── */
        .form-control, .form-select {
            border-radius: 12px;
            padding: 10px 14px;
            border: 1px solid #e0e7ef;
            font-size: 0.85rem;
        }

        .form-control:focus, .form-select:focus {
            border-color: #ff8c00;
            box-shadow: 0 0 0 3px rgba(255, 140, 0, 0.1);
        }

        .btn-send {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            min-height: 44px;
            border-radius: 999px;
            border: none;
            background: linear-gradient(135deg, #ff9f1a, #ff7c0a);
            color: #fff;
            font-size: 0.85rem;
            font-weight: 700;
            box-shadow: 0 10px 20px rgba(255, 124, 10, 0.2);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            cursor: pointer;
        }

        .btn-send:hover:not(:disabled) {
            transform: translateY(-1px);
            box-shadow: 0 14px 26px rgba(255, 124, 10, 0.26);
            color: #fff;
        }

        .btn-send:disabled { opacity: 0.55; cursor: not-allowed; }

        /* ── Email logs ── */
        .email-log-item {
            padding: 10px 16px;
            border-bottom: 1px solid rgba(31, 41, 51, 0.06);
            font-size: 0.78rem;
        }

        .email-log-item:last-child { border-bottom: none; }
        .email-status-sent   { color: #16a34a; font-weight: 700; }
        .email-status-failed { color: #dc2626; font-weight: 700; }

        /* ── Pagination ── */
        .notif-pagination { padding: 12px 16px 14px; border-top: 1px solid rgba(31, 41, 51, 0.06); }
        .notif-pagination .pagination { margin: 0; }

        .notif-pagination .page-link {
            border: 1px solid rgba(255, 140, 0, 0.16);
            border-radius: 10px;
            margin: 0 3px;
            color: #d96b00;
            background: #fff;
            font-size: 0.8rem;
        }

        .notif-pagination .page-item.active .page-link  { color: #fff; background: #ff8c00; border-color: #ff8c00; }
        .notif-pagination .page-item.disabled .page-link { color: #b9c1ca; background: #f7f8fa; }
    </style>
</head>
<body class="app-page admin-page">
    <div class="container-fluid">
        <div class="row">
            <?php include __DIR__ . '/sidebar.php'; ?>

            <div class="col-md-10 main-content content-wrapper">
                <div class="notif-page-header">
                    <h2><i class="fas fa-bell me-2" style="color:#ff8c00;"></i> Notifications</h2>
                    <a href="dashboard.php" class="btn-back">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                </div>

                <?php if ($message): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i> <?= htmlspecialchars($message) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i> <?= htmlspecialchars($error) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($page_load_error): ?>
                    <div class="alert alert-warning alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i> <?= htmlspecialchars($page_load_error) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="row mb-4">
                    <div class="col-md-4 mb-3">
                        <div class="notif-stat-card">
                            <div class="notif-stat-label"><i class="fas fa-inbox"></i> Total</div>
                            <p class="notif-stat-value"><?= number_format((int) ($stats['total'] ?? 0)) ?></p>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="notif-stat-card">
                            <div class="notif-stat-label"><i class="fas fa-envelope"></i> Unread</div>
                            <p class="notif-stat-value"><?= number_format((int) ($stats['unread'] ?? 0)) ?></p>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="notif-stat-card">
                            <div class="notif-stat-label"><i class="fas fa-paper-plane"></i> Emails Sent</div>
                            <p class="notif-stat-value"><?= number_format(count($email_logs)) ?></p>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- Left: Bulk send + email logs -->
                    <div class="col-lg-5">
                        <div class="notif-panel">
                            <div class="notif-panel-header">
                                <div class="notif-panel-title">
                                    <span class="notif-panel-icon"><i class="fas fa-paper-plane"></i></span>
                                    <div>
                                        <h4>Send Bulk Notification</h4>
                                        <p>Broadcast a message to users</p>
                                    </div>
                                </div>
                            </div>
                            <div class="p-3">
                                <?php if (!$notificationsAvailable): ?>
                                    <div class="alert alert-warning">
                                        <i class="fas fa-tools me-2"></i> Bulk notification tools are temporarily unavailable.
                                    </div>
                                <?php endif; ?>
                                <form method="POST" action="">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="send_bulk">
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold" style="font-size:0.83rem;">Notification Title</label>
                                        <input type="text" name="title" class="form-control" required placeholder="e.g., System Maintenance Notice">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold" style="font-size:0.83rem;">Message</label>
                                        <textarea name="message" class="form-control" rows="4" required placeholder="Enter your notification message here..."></textarea>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold" style="font-size:0.83rem;">Recipient Type</label>
                                        <select name="recipient_type" class="form-select">
                                            <option value="all">All Users</option>
                                            <option value="students">Students Only</option>
                                            <option value="staff">Staff Only</option>
                                            <option value="admins">Admins Only</option>
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold" style="font-size:0.83rem;">Notification Type</label>
                                        <select name="notification_type" class="form-select">
                                            <option value="info">Info (Blue)</option>
                                            <option value="success">Success (Green)</option>
                                            <option value="warning">Warning (Yellow)</option>
                                            <option value="danger">Danger (Red)</option>
                                        </select>
                                    </div>
                                    <div class="mb-3 form-check">
                                        <input type="checkbox" name="send_email" class="form-check-input" id="sendEmail" checked>
                                        <label class="form-check-label" for="sendEmail" style="font-size:0.83rem;">
                                            <i class="fas fa-envelope me-1"></i> Also send email notification
                                        </label>
                                        <small class="d-block text-muted mt-1" style="font-size:0.75rem;">Users will receive both in-app and email notification (if enabled)</small>
                                    </div>
                                    <button type="submit" class="btn-send" <?= !$notificationsAvailable ? 'disabled' : '' ?>>
                                        <i class="fas fa-paper-plane"></i> Send Notification
                                    </button>
                                </form>
                            </div>
                        </div>

                        <div class="notif-panel">
                            <div class="notif-panel-header">
                                <div class="notif-panel-title">
                                    <span class="notif-panel-icon"><i class="fas fa-history"></i></span>
                                    <div>
                                        <h4>Recent Email Logs</h4>
                                        <p>Last <?= min(count($email_logs), 10) ?> sent emails</p>
                                    </div>
                                </div>
                            </div>
                            <?php if (empty($email_logs)): ?>
                                <div class="notif-empty" style="padding:28px 20px;">
                                    <div class="notif-empty-icon" style="width:52px;height:52px;font-size:1.4rem;"><i class="fas fa-envelope"></i></div>
                                    <h5 style="font-size:0.9rem;">No emails sent yet</h5>
                                    <p>Email activity will appear here.</p>
                                </div>
                            <?php else: ?>
                                <?php foreach (array_slice($email_logs, 0, 10) as $log): ?>
                                    <?php $emailStatus = ($log['status'] ?? '') === 'sent' ? 'sent' : 'failed'; ?>
                                    <div class="email-log-item">
                                        <div class="d-flex justify-content-between align-items-start gap-2">
                                            <div style="min-width:0;">
                                                <strong style="font-size:0.79rem;"><?= htmlspecialchars((string) ($log['subject'] ?? 'Notification email')) ?></strong><br>
                                                <span class="text-muted" style="font-size:0.73rem;">To: <?= htmlspecialchars((string) ($log['recipient_email'] ?? 'Unknown recipient')) ?></span>
                                            </div>
                                            <span class="email-status-<?= $emailStatus ?>" style="white-space:nowrap;">
                                                <i class="fas fa-<?= $emailStatus === 'sent' ? 'check-circle' : 'times-circle' ?>"></i>
                                                <?= htmlspecialchars(ucfirst($emailStatus)) ?>
                                            </span>
                                        </div>
                                        <div class="text-muted mt-1" style="font-size:0.72rem;">
                                            <i class="fas fa-clock me-1"></i><?= htmlspecialchars(reclaimFormatDate($log['sent_at'] ?? null, 'M d, Y h:i A', 'Time unavailable')) ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Right: Notification list -->
                    <div class="col-lg-7">
                        <div class="notif-panel">
                            <div class="notif-panel-header">
                                <div class="notif-panel-title">
                                    <span class="notif-panel-icon"><i class="fas fa-bell"></i></span>
                                    <div>
                                        <h4>Your Notifications</h4>
                                        <p><?= number_format($total_notifications) ?> item<?= $total_notifications === 1 ? '' : 's' ?> in this view</p>
                                    </div>
                                </div>
                                <?php if ((int) ($stats['unread'] ?? 0) > 0): ?>
                                    <span class="notif-unread-chip">
                                        <i class="fas fa-circle" style="font-size:7px;"></i>
                                        <?= number_format((int) $stats['unread']) ?> unread
                                    </span>
                                <?php endif; ?>
                            </div>

                            <div class="notif-toolbar">
                                <div class="notif-filter-group">
                                    <a href="?filter=all" class="notif-filter-btn <?= $filter === 'all' ? 'active' : '' ?>">
                                        <i class="fas fa-list"></i>
                                        <span>All</span>
                                        <span class="notif-filter-count"><?= number_format((int) ($stats['total'] ?? 0)) ?></span>
                                    </a>
                                    <a href="?filter=unread" class="notif-filter-btn <?= $filter === 'unread' ? 'active' : '' ?>">
                                        <i class="fas fa-envelope"></i>
                                        <span>Unread</span>
                                        <span class="notif-filter-count"><?= number_format((int) ($stats['unread'] ?? 0)) ?></span>
                                    </a>
                                    <a href="?filter=read" class="notif-filter-btn <?= $filter === 'read' ? 'active' : '' ?>">
                                        <i class="fas fa-envelope-open"></i>
                                        <span>Read</span>
                                        <span class="notif-filter-count"><?= number_format(max(0, (int) ($stats['total'] ?? 0) - (int) ($stats['unread'] ?? 0))) ?></span>
                                    </a>
                                </div>
                                <form method="POST" action="">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="mark_all_read">
                                    <button type="submit" class="notif-mark-all-btn" <?= !$notificationsAvailable ? 'disabled' : '' ?>>
                                        <i class="fas fa-check-double"></i> Mark All Read
                                    </button>
                                </form>
                            </div>

                            <?php if (empty($notifications)): ?>
                                <div class="notif-empty">
                                    <div class="notif-empty-icon"><i class="fas fa-bell-slash"></i></div>
                                    <h5>No notifications found</h5>
                                    <p>You'll see notifications here when there are updates.</p>
                                </div>
                            <?php else: ?>
                                <div class="notif-stream">
                                    <?php foreach ($notifications as $notif): ?>
                                        <?php
                                        $notifType = in_array($notif['type'] ?? '', ['info', 'success', 'warning', 'danger'], true)
                                            ? $notif['type'] : 'info';
                                        $iconMap = ['info' => 'fa-info-circle', 'success' => 'fa-check-circle', 'warning' => 'fa-exclamation-triangle', 'danger' => 'fa-times-circle'];
                                        $notifIcon = $iconMap[$notifType];
                                        $isUnread = (int) ($notif['is_read'] ?? 0) === 0;
                                        ?>
                                        <article class="notif-item <?= $isUnread ? 'unread' : '' ?>">
                                            <div class="notif-item-main">
                                                <div class="notif-icon <?= $notifType ?>">
                                                    <i class="fas <?= $notifIcon ?>"></i>
                                                </div>
                                                <div class="notif-item-content">
                                                    <div class="notif-item-topline">
                                                        <div class="notif-title-wrap">
                                                            <h5 class="notif-title"><?= htmlspecialchars((string) ($notif['title'] ?? 'Notification')) ?></h5>
                                                            <?php if ($isUnread): ?>
                                                                <span class="badge bg-primary" style="border-radius:999px;padding:3px 7px;font-size:0.65rem;">New</span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <span class="notif-time">
                                                            <i class="far fa-clock"></i>
                                                            <?= htmlspecialchars(time_ago($notif['created_at'] ?? null)) ?>
                                                        </span>
                                                    </div>
                                                    <p class="notif-message"><?= nl2br(htmlspecialchars((string) ($notif['message'] ?? ''))) ?></p>
                                                    <div class="notif-item-actions">
                                                        <?php if ($isUnread): ?>
                                                            <form method="POST" action="" style="display:inline;">
                                                                <?= csrf_field() ?>
                                                                <input type="hidden" name="action" value="mark_read">
                                                                <input type="hidden" name="notification_id" value="<?= (int) ($notif['notification_id'] ?? 0) ?>">
                                                                <button type="submit" class="notif-action-link mark-read" <?= !$notificationsAvailable ? 'disabled' : '' ?>>
                                                                    <i class="fas fa-check-circle"></i> Mark as read
                                                                </button>
                                                            </form>
                                                        <?php endif; ?>
                                                        <form method="POST" action="" style="display:inline;">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="action" value="delete">
                                                            <input type="hidden" name="notification_id" value="<?= (int) ($notif['notification_id'] ?? 0) ?>">
                                                            <button type="submit" class="notif-action-link delete" onclick="return confirm('Delete this notification?')">
                                                                <i class="fas fa-trash-alt"></i> Delete
                                                            </button>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>
                                        </article>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($total_pages > 1): ?>
                                <div class="notif-pagination">
                                    <nav>
                                        <ul class="pagination justify-content-center mb-0">
                                            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                                <a class="page-link" href="?filter=<?= urlencode($filter) ?>&page=<?= $page - 1 ?>">Previous</a>
                                            </li>
                                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                                    <a class="page-link" href="?filter=<?= urlencode($filter) ?>&page=<?= $i ?>"><?= $i ?></a>
                                                </li>
                                            <?php endfor; ?>
                                            <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                                                <a class="page-link" href="?filter=<?= urlencode($filter) ?>&page=<?= $page + 1 ?>">Next</a>
                                            </li>
                                        </ul>
                                    </nav>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

<?php
if (!function_exists('time_ago')) {
    function time_ago($timestamp) {
        $timestamp = trim((string) $timestamp);
        if ($timestamp === '') {
            return 'Never';
        }

        $time_ago = strtotime($timestamp);
        if ($time_ago === false || $time_ago <= 0) {
            return 'Never';
        }

        $current_time = time();
        $time_difference = $current_time - $time_ago;

        if ($time_difference < 60) {
            return 'Just now';
        }
        if ($time_difference < 3600) {
            return floor($time_difference / 60) . ' minutes ago';
        }
        if ($time_difference < 86400) {
            return floor($time_difference / 3600) . ' hours ago';
        }
        if ($time_difference < 604800) {
            return floor($time_difference / 86400) . ' days ago';
        }

        return date('M d, Y', $time_ago);
    }
}
?>
