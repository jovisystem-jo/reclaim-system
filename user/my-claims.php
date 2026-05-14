<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/claim_status.php';
requireLogin();

$db = Database::getInstance()->getConnection();
reclaimEnsureClaimStatusSchema($db);
$userID = $_SESSION['userID'];

// Handle Complete Reclaim Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_claim'])) {
    require_csrf_token();
    
    $claim_id = $_POST['claim_id'];
    
    // Verify claim belongs to user and is approved
    $stmt = $db->prepare("
        SELECT c.*, i.item_id, i.status as item_status 
        FROM claim_requests c
        JOIN items i ON c.item_id = i.item_id
        WHERE c.claim_id = ? AND c.claimant_id = ? AND c.status = 'approved'
    ");
    $stmt->execute([$claim_id, $userID]);
    $claim = $stmt->fetch();
    
    if ($claim) {
        // Update item status to returned
        $stmt = $db->prepare("UPDATE items SET status = 'returned' WHERE item_id = ?");
        $stmt->execute([$claim['item_id']]);
        
        // Update claim status to completed
        $stmt = $db->prepare("UPDATE claim_requests SET status = 'completed' WHERE claim_id = ?");
        $stmt->execute([$claim_id]);
        
        $_SESSION['success_message'] = "Item successfully reclaimed! Thank you for confirming. You can now view the claim report.";
    } else {
        $_SESSION['error_message'] = "Unable to complete reclaim. Please contact support.";
    }
    
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}

// Handle Cancel Claim Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_claim'])) {
    require_csrf_token();
    
    $claim_id = $_POST['claim_id'];
    
    // Verify claim belongs to user and is pending
    $stmt = $db->prepare("
        SELECT * FROM claim_requests 
        WHERE claim_id = ? AND claimant_id = ? AND status = 'pending'
    ");
    $stmt->execute([$claim_id, $userID]);
    $claim = $stmt->fetch();
    
    if ($claim) {
        $stmt = $db->prepare("UPDATE claim_requests SET status = 'cancelled' WHERE claim_id = ?");
        $stmt->execute([$claim_id]);
        
        $_SESSION['success_message'] = "Claim request has been cancelled.";
    } else {
        $_SESSION['error_message'] = "Unable to cancel claim. Claim may already be processed.";
    }
    
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}

// Get claims with more details
$stmt = $db->prepare("
    SELECT c.*, 
           i.item_id,
           i.title as item_title, 
           i.description as item_description, 
           i.status as item_status, 
           i.image_url,
           i.category,
           i.found_location,
           u.name as reporter_name
    FROM claim_requests c
    JOIN items i ON c.item_id = i.item_id
    LEFT JOIN users u ON i.reported_by = u.user_id
    WHERE c.claimant_id = ?
    ORDER BY c.created_at DESC
");
$stmt->execute([$userID]);
$claims = $stmt->fetchAll();

// Get statistics
$stats = [
    'total' => count($claims),
    'pending' => 0,
    'approved' => 0,
    'rejected' => 0,
    'completed' => 0,
    'cancelled' => 0
];

foreach ($claims as $claim) {
    switch ($claim['status']) {
        case 'pending': $stats['pending']++; break;
        case 'approved': $stats['approved']++; break;
        case 'rejected': $stats['rejected']++; break;
        case 'completed': $stats['completed']++; break;
        case 'cancelled': $stats['cancelled']++; break;
    }
}

$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);

$base_url = '/reclaim-system/';
if (!defined('RECLAIM_EMBEDDED_LAYOUT')) {
    define('RECLAIM_EMBEDDED_LAYOUT', true);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Claims - Reclaim System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="<?= $base_url ?>assets/css/style.css">
    <style>
        .content-wrapper {
            margin-top: 20px;
        }
        
        /* Stats Cards */
        .stat-card-claim {
            background: white;
            border-radius: 15px;
            padding: 15px;
            text-align: center;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            transition: transform 0.3s;
            margin-bottom: 20px;
        }
        .stat-card-claim:hover {
            transform: translateY(-3px);
        }
        .stat-card-claim i {
            font-size: 28px;
            margin-bottom: 8px;
        }
        .stat-card-claim h3 {
            font-size: 24px;
            margin: 5px 0;
            color: #333;
        }
        .stat-card-claim p {
            color: #666;
            margin: 0;
            font-size: 12px;
        }
        
        /* Claim Cards */
        .claim-card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            transition: transform 0.2s, box-shadow 0.2s;
            margin-bottom: 20px;
            overflow: hidden;
        }
        .claim-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.12);
        }
        .claim-card-header {
            padding: 12px 20px;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .claim-card-body {
            padding: 20px;
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }
        .claim-image {
            width: 100px;
            height: 100px;
            object-fit: cover;
            border-radius: 10px;
            flex-shrink: 0;
        }
        .claim-placeholder {
            width: 100px;
            height: 100px;
            background-color: #f8f9fa;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .claim-placeholder i {
            font-size: 40px;
            color: #FF8C00;
        }
        .claim-details {
            flex: 1;
            min-width: 200px;
        }
        .claim-details h5 {
            margin-bottom: 10px;
            color: #2C3E50;
        }
        .claim-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-top: 10px;
        }
        .claim-meta-item {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 13px;
            color: #6c757d;
        }
        .claim-meta-item i {
            width: 16px;
            color: #FF8C00;
        }
        .claim-footer {
            padding: 12px 20px;
            background-color: #f8f9fa;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            flex-wrap: wrap;
        }
        .badge-status {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-pending { background-color: #ffc107; color: #333; }
        .badge-approved { background-color: #28a745; color: white; }
        .badge-rejected { background-color: #dc3545; color: white; }
        .badge-completed { background-color: #17a2b8; color: white; }
        .badge-cancelled { background-color: #6c757d; color: white; }
        
        .claims-btn,
        .btn-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-align: center;
            line-height: 1.2;
            font-weight: 600;
            border-radius: 10px;
        }
        .claims-btn {
            min-height: 42px;
            padding: 0 18px;
            font-size: 0.85rem;
        }
        .claims-btn-search {
            min-height: 38px;
            padding: 0 16px;
            font-size: 0.82rem;
        }
        .btn-action {
            min-height: 38px;
            min-width: 168px;
            padding: 0 16px;
            font-size: 0.82rem;
        }
        .content-wrapper .btn i,
        .modal .btn i {
            line-height: 1;
        }
        
        /* Message when no claims */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }
        .empty-state-icon {
            font-size: 80px;
            color: #dee2e6;
            margin-bottom: 20px;
        }
        .empty-state h4 {
            color: #495057;
            margin-bottom: 10px;
        }
        .empty-state p {
            color: #6c757d;
            margin-bottom: 20px;
        }
        
        /* Complete Reclaim Modal */
        .complete-modal-header {
            background: linear-gradient(135deg, #27AE60, #1e8449);
            color: white;
        }
        .complete-modal-body {
            padding: 20px;
        }
        .complete-modal-question {
            font-size: 1.15rem;
            color: #4f6274;
        }
        .complete-reminder-section {
            max-height: 0;
            overflow: hidden;
            opacity: 0;
            transform: translateY(-8px);
            transition: max-height 0.35s ease, opacity 0.25s ease, transform 0.25s ease, margin-top 0.35s ease;
            margin-top: 0;
        }
        .complete-reminder-section.is-visible {
            max-height: 700px;
            opacity: 1;
            transform: translateY(0);
            margin-top: 18px;
        }
        .reminder-list {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin: 0;
        }
        .reminder-list ul {
            margin: 10px 0 0 20px;
        }
        .reminder-list > ul {
            display: none;
        }
        .reminder-list li {
            margin-bottom: 8px;
            font-size: 14px;
        }
        .clean-reminders ul {
            margin: 10px 0 0 20px;
        }
        .complete-reminder-intro {
            font-size: 0.92rem;
            color: #6c757d;
            margin-bottom: 12px;
        }
        .complete-acknowledgement {
            margin-top: 14px;
            padding: 12px 14px;
            background: #fff;
            border: 1px solid #e9ecef;
            border-radius: 10px;
        }
        .complete-acknowledgement .form-check-label {
            font-size: 0.92rem;
            color: #495057;
        }
        .complete-modal-footer {
            gap: 10px;
        }
        .complete-modal-footer form {
            margin: 0;
        }
        
        @media (max-width: 768px) {
            .claim-card-body {
                flex-direction: column;
                align-items: center;
                text-align: center;
            }
            .claim-meta {
                justify-content: center;
            }
            .claim-footer {
                justify-content: center;
            }
            .claim-footer .btn-action {
                width: 100%;
                min-width: 0;
            }
        }
    </style>
</head>
<body class="app-page user-page">
    <?php include __DIR__ . '/../includes/header.php'; ?>
    
    <main class="page-shell page-shell--compact">
    <div class="container content-wrapper">
        <!-- Page Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-file-alt" style="color: #FF8C00;"></i> My Claim Requests</h2>
            <a href="<?= $base_url ?>search.php" class="btn btn-primary claims-btn claims-btn-search">
                <i class="fas fa-search"></i> Search Items
            </a>
        </div>
        
        <!-- Success/Error Messages -->
        <?php if($success_message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i> <?= htmlspecialchars($success_message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if($error_message): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i> <?= htmlspecialchars($error_message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- Statistics Cards -->
        <?php if(!empty($claims)): ?>
        <div class="row mb-4">
            <div class="col-md-2 col-6">
                <div class="stat-card-claim">
                    <i class="fas fa-chart-line" style="color: #FF8C00;"></i>
                    <h3><?= $stats['total'] ?></h3>
                    <p>Total Claims</p>
                </div>
            </div>
            <div class="col-md-2 col-6">
                <div class="stat-card-claim">
                    <i class="fas fa-clock" style="color: #ffc107;"></i>
                    <h3><?= $stats['pending'] ?></h3>
                    <p>Pending</p>
                </div>
            </div>
            <div class="col-md-2 col-6">
                <div class="stat-card-claim">
                    <i class="fas fa-check-circle" style="color: #28a745;"></i>
                    <h3><?= $stats['approved'] ?></h3>
                    <p>Approved</p>
                </div>
            </div>
            <div class="col-md-2 col-6">
                <div class="stat-card-claim">
                    <i class="fas fa-times-circle" style="color: #dc3545;"></i>
                    <h3><?= $stats['rejected'] ?></h3>
                    <p>Rejected</p>
                </div>
            </div>
            <div class="col-md-2 col-6">
                <div class="stat-card-claim">
                    <i class="fas fa-handshake" style="color: #17a2b8;"></i>
                    <h3><?= $stats['completed'] ?></h3>
                    <p>Completed</p>
                </div>
            </div>
            <div class="col-md-2 col-6">
                <div class="stat-card-claim">
                    <i class="fas fa-ban" style="color: #6c757d;"></i>
                    <h3><?= $stats['cancelled'] ?></h3>
                    <p>Cancelled</p>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Claims List -->
        <?php if(empty($claims)): ?>
            <div class="card fade-in">
                <div class="empty-state">
                    <i class="fas fa-inbox empty-state-icon"></i>
                    <h4>No claims submitted yet</h4>
                    <p>Search for lost or found items and submit a claim to get started.</p>
                    <a href="<?= $base_url ?>search.php" class="btn btn-primary claims-btn claims-btn-search">
                        <i class="fas fa-search me-2"></i> Search for Items
                    </a>
                </div>
            </div>
        <?php else: ?>
            <div class="row">
                <?php foreach($claims as $claim): ?>
                <div class="col-12">
                    <div class="claim-card">
                        <div class="claim-card-header">
                            <div>
                                <strong>Claim #<?= $claim['claim_id'] ?></strong>
                                <small class="text-muted ms-2">
                                    <i class="fas fa-calendar-alt"></i> <?= date('F d, Y \a\t h:i A', strtotime($claim['created_at'])) ?>
                                </small>
                            </div>
                            <span class="badge-status badge-<?= $claim['status'] ?>">
                                <?php 
                                    $statusLabels = [
                                        'pending' => '⏳ Pending',
                                        'approved' => '✅ Approved',
                                        'rejected' => '❌ Rejected',
                                        'completed' => '🎉 Completed',
                                        'cancelled' => '🚫 Cancelled'
                                    ];
                                    echo $statusLabels[$claim['status']] ?? ucfirst($claim['status']);
                                ?>
                            </span>
                        </div>
                        
                               <div class="claim-card-body">
                            <?php 
                            $hasImage = !empty($claim['image_url']) && file_exists(__DIR__ . '/../' . $claim['image_url']);
                            $imageUrl = $hasImage ? $base_url . $claim['image_url'] : '';
                            ?>
                            <?php if($hasImage): ?>
                                <img src="<?= $imageUrl ?>" class="claim-image" alt="Item image">
                            <?php else: ?>
                                <div class="claim-placeholder">
                                    <i class="fas fa-box-open"></i>
                                </div>
                            <?php endif; ?>
                            
                            <div class="claim-details">
                                <h5><?= htmlspecialchars($claim['item_title']) ?></h5>
                                <p class="text-muted small mb-2"><?= htmlspecialchars(substr($claim['item_description'] ?? '', 0, 100)) ?>...</p>
                                
                                <div class="claim-meta">
                                    <div class="claim-meta-item">
                                        <i class="fas fa-tag"></i>
                                        <span><?= htmlspecialchars($claim['category'] ?? 'N/A') ?></span>
                                    </div>
                                    <div class="claim-meta-item">
                                        <i class="fas fa-map-marker-alt"></i>
                                        <span><?= htmlspecialchars($claim['found_location'] ?? 'Not specified') ?></span>
                                    </div>
                                    <div class="claim-meta-item">
                                        <i class="fas fa-user"></i>
                                        <span>Reported by: <?= htmlspecialchars($claim['reporter_name'] ?? 'Anonymous') ?></span>
                                    </div>
                                </div>
                                
                                <?php if($claim['claimant_description']): ?>
                                    <div class="mt-2 p-2 bg-light rounded small">
                                        <i class="fas fa-comment-dots me-1" style="color: #FF8C00;"></i>
                                        <strong>Your note:</strong> <?= htmlspecialchars(substr($claim['claimant_description'], 0, 100)) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="claim-footer">
                            <!-- View Item Details - Always visible -->
                            <a href="<?= $base_url ?>item-details.php?id=<?= $claim['item_id'] ?>" class="btn btn-info btn-action">
                                <i class="fas fa-box"></i> View Item Details
                            </a>
                            
                            <!-- View Claim Report - ONLY for Completed claims (after user confirms reclaim) -->
                            <?php if($claim['status'] == 'completed'): ?>
                                <a href="<?= $base_url ?>admin/view-claim-report.php?id=<?= $claim['claim_id'] ?>" class="btn btn-primary btn-action">
                                    <i class="fas fa-file-pdf"></i> View Claim Report
                                </a>
                            <?php endif; ?>
                            
                            <!-- Cancel Claim - Only for Pending claims -->
                            <?php if($claim['status'] == 'pending'): ?>
                                <button onclick="openCancelModal(<?= $claim['claim_id'] ?>)" class="btn btn-danger btn-action">
                                    <i class="fas fa-times"></i> Cancel Claim
                                </button>
                            <?php endif; ?>
                            
                            <!-- Confirm Reclaim - Only for Approved claims (not yet completed) -->
                            <?php if($claim['status'] == 'approved'): ?>
                                <button onclick="openCompleteModal(<?= $claim['claim_id'] ?>)" class="btn btn-success btn-action">
                                    <i class="fas fa-handshake"></i> Confirm Reclaim
                                </button>
                            <?php endif; ?>
                            
                            <!-- View Returned Item - Only for Completed claims -->
                            <?php if($claim['status'] == 'completed'): ?>
                                <a href="<?= $base_url ?>item-details.php?id=<?= $claim['item_id'] ?>" class="btn btn-secondary btn-action">
                                    <i class="fas fa-box-open"></i> View Returned Item
                                </a>
                            <?php endif; ?>
                            
                            <!-- Disabled buttons for Rejected and Cancelled -->
                            <?php if($claim['status'] == 'rejected'): ?>
                                <button class="btn btn-secondary btn-action" disabled>
                                    <i class="fas fa-times-circle"></i> Claim Rejected
                                </button>
                            <?php endif; ?>
                            
                            <?php if($claim['status'] == 'cancelled'): ?>
                                <button class="btn btn-secondary btn-action" disabled>
                                    <i class="fas fa-ban"></i> Claim Cancelled
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    </main>
    
    <!-- Complete Reclaim Modal with Staged Confirmation -->
    <div class="modal fade" id="completeModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header complete-modal-header">
                    <h5 class="modal-title"><i class="fas fa-handshake me-2"></i> Confirm Reclaim</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="complete-modal-body">
                    <div class="text-center mb-3">
                        <i class="fas fa-check-circle fa-4x" style="color: #27AE60;"></i>
                    </div>
                    <p class="text-center mb-0 complete-modal-question"><strong>Have you received your item?</strong></p>
                    
                    <div id="completeReminderSection" class="complete-reminder-section">
                        <div class="reminder-list">
                            <p class="complete-reminder-intro">Please review these reminders before finishing your reclaim confirmation.</p>
                            <p class="mb-2"><i class="fas fa-info-circle me-2" style="color: #FF6B35;"></i> <strong>Important Reminders:</strong></p>
                            <div class="clean-reminders">
                                <ul>
                                    <li>After confirmation, your claim will be marked as <strong>COMPLETED</strong>.</li>
                                    <li>You will receive a notification confirming the successful reclaim.</li>
                                    <li>The item status will be updated to <strong>RETURNED</strong>.</li>
                                    <li>You will be able to <strong>view the claim report</strong> after confirmation.</li>
                                    <li>The "Confirm Reclaim" button will be replaced with "View Claim Report".</li>
                                    <li>This action <strong>cannot be undone</strong>.</li>
                                </ul>
                            </div>
                        <ul>
                            <li>✓ After confirmation, your claim will be marked as <strong>COMPLETED</strong></li>
                            <li>✓ You will receive a notification confirming the successful reclaim</li>
                            <li>✓ The item status will be updated to <strong>RETURNED</strong></li>
                            <li>✓ You will be able to <strong>view the claim report</strong> after confirmation</li>
                            <li>✓ The "Confirm Reclaim" button will be replaced with "View Claim Report"</li>
                            <li>✓ This action <strong>cannot be undone</strong></li>
                        </ul>
                            <div class="form-check complete-acknowledgement">
                                <input class="form-check-input" type="checkbox" id="completeAcknowledgement">
                                <label class="form-check-label" for="completeAcknowledgement">
                                    I understand that this will complete my claim and cannot be undone.
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer complete-modal-footer">
                    <button type="button" class="btn btn-secondary claims-btn" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success claims-btn" id="revealRemindersBtn">
                        <i class="fas fa-check me-2"></i> Yes, I have received the item
                    </button>
                    <form method="POST" action="">
                        <?= csrf_field() ?>
                        <input type="hidden" name="complete_claim" value="1">
                        <input type="hidden" name="claim_id" id="complete_claim_id">
                        <button type="submit" class="btn btn-success claims-btn d-none" id="finalConfirmBtn" disabled>
                            <i class="fas fa-check-double me-2"></i> Confirm Reclaim
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Cancel Claim Modal -->
    <div class="modal fade" id="cancelModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header" style="background: #dc3545; color: white;">
                    <h5 class="modal-title"><i class="fas fa-exclamation-triangle"></i> Cancel Claim Request</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to cancel this claim request?</p>
                    <p class="text-muted small">This action cannot be undone. You can submit a new claim later if needed.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary claims-btn" data-bs-dismiss="modal">Close</button>
                    <form method="POST" action="" style="display: inline;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="cancel_claim" value="1">
                        <input type="hidden" name="claim_id" id="cancel_claim_id">
                        <button type="submit" class="btn btn-danger claims-btn">Yes, Cancel Claim</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <script>
    const completeModalElement = document.getElementById('completeModal');
    const revealRemindersBtn = document.getElementById('revealRemindersBtn');
    const reminderSection = document.getElementById('completeReminderSection');
    const acknowledgementCheckbox = document.getElementById('completeAcknowledgement');
    const finalConfirmBtn = document.getElementById('finalConfirmBtn');
    const completeClaimIdInput = document.getElementById('complete_claim_id');

    function resetCompleteModalState() {
        if (reminderSection) {
            reminderSection.classList.remove('is-visible');
        }
        if (acknowledgementCheckbox) {
            acknowledgementCheckbox.checked = false;
        }
        if (finalConfirmBtn) {
            finalConfirmBtn.disabled = true;
            finalConfirmBtn.classList.add('d-none');
        }
        if (revealRemindersBtn) {
            revealRemindersBtn.classList.remove('d-none');
        }
    }
    
    function openCompleteModal(claimId) {
        if (completeClaimIdInput) {
            completeClaimIdInput.value = claimId;
        }
        resetCompleteModalState();
        const modal = new bootstrap.Modal(completeModalElement);
        modal.show();
    }
    
    function openCancelModal(claimId) {
        document.getElementById('cancel_claim_id').value = claimId;
        const modal = new bootstrap.Modal(document.getElementById('cancelModal'));
        modal.show();
    }

    revealRemindersBtn.addEventListener('click', function() {
        reminderSection.classList.add('is-visible');
        revealRemindersBtn.classList.add('d-none');
        finalConfirmBtn.classList.remove('d-none');
        finalConfirmBtn.disabled = !acknowledgementCheckbox.checked;

        window.setTimeout(function() {
            reminderSection.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }, 150);
    });

    acknowledgementCheckbox.addEventListener('change', function() {
        finalConfirmBtn.disabled = !this.checked;
    });

    completeModalElement.addEventListener('hidden.bs.modal', function() {
        if (completeClaimIdInput) {
            completeClaimIdInput.value = '';
        }
        resetCompleteModalState();
    });
    
    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(function(alert) {
            setTimeout(function() {
                const bsAlert = new bootstrap.Alert(alert);
                if (bsAlert) bsAlert.close();
            }, 5000);
        });
    }, 3000);
    </script>
    
    <?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
