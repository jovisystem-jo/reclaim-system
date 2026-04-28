<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
requireAdmin();
require_once '../includes/notification.php';

$db = Database::getInstance()->getConnection();
$notification = new NotificationSystem();
$message = '';
$error = '';

// Get saved signature data from session
$admin_signature = $_SESSION['admin_signature'] ?? [
    'name' => $_SESSION['name'] ?? '',
    'id' => $_SESSION['userID'] ?? '',
    'position' => 'Administrator',
    'department' => 'Auxiliary Police and Security Office',
    'image' => '',
    'updated_at' => ''
];

// Handle claim verification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['claim_id']) && isset($_POST['action'])) {
    require_csrf_token();

    $claimId = (int) $_POST['claim_id'];
    $action = $_POST['action'] ?? '';
    $admin_notes = $_POST['admin_notes'] ?? '';
    
    if (!in_array($action, ['approve', 'reject'], true)) {
        $error = "Invalid claim action";
    }

    $status = $action === 'approve' ? 'approved' : 'rejected';
    
    $signature_data = json_encode([
        'name' => $admin_signature['name'],
        'id' => $admin_signature['id'],
        'position' => $admin_signature['position'],
        'department' => $admin_signature['department'],
        'date' => date('F d, Y h:i A'),
        'image' => $admin_signature['image'] ?? ''
    ]);
    
    if (empty($error)) {
        $stmt = $db->prepare("
            UPDATE claim_requests 
            SET status = ?, admin_notes = ?, verified_by = ?, verified_date = NOW(),
                admin_signature = ?, agreement_confirmed = 1, item_condition = 'complete'
            WHERE claim_id = ?
        ");
        
        if ($stmt->execute([$status, $admin_notes, $_SESSION['userID'], $signature_data, $claimId])) {
            $stmt = $db->prepare("
                SELECT c.*, i.title as item_title, i.delivery_location,
                       u_claimant.name as claimant_name, u_claimant.user_id as claimant_id,
                       u_founder.name as founder_name, u_founder.user_id as founder_id
                FROM claim_requests c
                JOIN items i ON c.item_id = i.item_id
                JOIN users u_claimant ON c.claimant_id = u_claimant.user_id
                JOIN users u_founder ON i.reported_by = u_founder.user_id
                WHERE c.claim_id = ?
            ");
            $stmt->execute([$claimId]);
            $claim = $stmt->fetch();
            
            if ($status === 'approved') {
                $claimantTitle = "✅ Claim Approved!";
                $claimantMessage = "Congratulations! Your claim for '{$claim['item_title']}' has been approved.\n\n";
                $claimantMessage .= "📍 Item can be collected at: {$claim['delivery_location']}\n";
                $claimantMessage .= "📅 Please bring your ID for verification.\n\n";
                if ($admin_notes) {
                    $claimantMessage .= "📝 Admin Notes: $admin_notes\n\n";
                }
                $claimantMessage .= "Thank you for using Reclaim System!";
                $notification->send($claim['claimant_id'], $claimantTitle, $claimantMessage, 'success');
                
                $founderTitle = "📦 Your Found Item Has Been Claimed!";
                $founderMessage = "Good news! The item you reported as found has been claimed by the rightful owner.\n\n";
                $founderMessage .= "📌 Item: {$claim['item_title']}\n";
                $founderMessage .= "👤 Claimed by: {$claim['claimant_name']}\n";
                $founderMessage .= "📍 Item was kept at: {$claim['delivery_location']}\n\n";
                $founderMessage .= "Thank you for your honesty! 🎉";
                $notification->send($claim['founder_id'], $founderTitle, $founderMessage, 'success');
                
                $stmt = $db->prepare("UPDATE items SET status = 'returned' WHERE item_id = ?");
                $stmt->execute([$claim['item_id']]);
            } else {
                $claimantTitle = "❌ Claim Rejected";
                $claimantMessage = "Your claim for '{$claim['item_title']}' has been rejected.\n\n";
                if ($admin_notes) {
                    $claimantMessage .= "📝 Reason: $admin_notes\n\n";
                }
                $claimantMessage .= "Contact support if you have questions.";
                $notification->send($claim['claimant_id'], $claimantTitle, $claimantMessage, 'danger');
            }
            
            $message = "Claim $status successfully.";
        } else {
            $error = "Failed to update claim";
        }
    }
}

// Get pending claims
$stmt = $db->prepare("
    SELECT c.*, i.title as item_title, i.description as item_description, i.image_url,
           i.found_location, i.delivery_location, i.brand, i.color,
           u.name as claimant_name, u.email as claimant_email, u.phone as claimant_phone,
           u.student_staff_id as claimant_student_id, u.department as claimant_department,
           u.created_at as claimant_joined_date
    FROM claim_requests c
    JOIN items i ON c.item_id = i.item_id
    JOIN users u ON c.claimant_id = u.user_id
    WHERE c.status = 'pending'
    ORDER BY c.created_at ASC
");
$stmt->execute();
$pending_claims = $stmt->fetchAll();

// Get founder information for each claim
$founder_info = [];
if (!empty($pending_claims)) {
    foreach ($pending_claims as $claim) {
        $stmt = $db->prepare("
            SELECT u.name, u.email, u.phone, u.student_staff_id, u.department
            FROM users u
            JOIN items i ON i.reported_by = u.user_id
            WHERE i.item_id = ?
        ");
        $stmt->execute([$claim['item_id']]);
        $founder_info[$claim['item_id']] = $stmt->fetch();
    }
}

$base_url = '/reclaim-system/';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Claims - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $base_url ?>assets/css/style.css">
    <style>
        * { font-family: 'Inter', sans-serif; }
        body { background: #f8f9fa; }
        .main-content { padding: 20px; min-height: 100vh; }
        
        /* Header Action Buttons */
        .action-header-buttons {
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
        }
        .btn-primary-custom,
        .btn-secondary-custom {
            border: none;
            padding: 8px 20px;
            border-radius: 50px;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-size: 0.85rem;
            font-weight: 500;
            line-height: 1.2;
            white-space: nowrap;
        }
        .btn-primary-custom {
            background: linear-gradient(135deg, #FF6B35, #E85D2C);
            color: white;
        }
        .btn-primary-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255,107,53,0.3);
            color: white;
        }
        .btn-secondary-custom {
            background: #6c757d;
            color: white;
        }
        .btn-secondary-custom:hover {
            background: #5a6268;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(108,117,125,0.2);
            color: white;
        }
        .btn-action-compact {
            padding: 6px 16px;
            font-size: 0.78rem;
            min-height: 34px;
        }
        .btn-action-compact i {
            font-size: 0.8rem;
        }
        
        /* Claim Card */
        .claim-card {
            background: white;
            border-radius: 20px;
            margin-bottom: 24px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            transition: box-shadow 0.2s;
        }
        .claim-card:hover {
            box-shadow: 0 4px 16px rgba(0,0,0,0.1);
        }
        
        /* Card Header */
        .card-header-custom {
            padding: 16px 24px;
            background: #fafbfc;
            border-bottom: 1px solid #eef2f6;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }
        .claim-title-section {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .claim-badge {
            background: #FEF3E2;
            color: #FF6B35;
            padding: 4px 10px;
            border-radius: 50px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        .claim-number {
            font-size: 0.9rem;
            font-weight: 600;
            color: #2C3E50;
        }
        .claim-number i {
            color: #FF6B35;
            margin-right: 6px;
        }
        .status-pending {
            background: #FEF3C7;
            color: #D97706;
            padding: 4px 12px;
            border-radius: 50px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        .submission-date {
            font-size: 0.75rem;
            color: #8A93A5;
        }
        .submission-date i {
            margin-right: 4px;
        }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        
        /* Card Body */
        .card-body-custom {
            padding: 20px 24px;
        }
        
        /* Info Grid Layout */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px 32px;
            margin-bottom: 20px;
        }
        @media (max-width: 768px) {
            .info-grid { grid-template-columns: 1fr; }
        }
        
        .info-field {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .info-label {
            font-size: 0.7rem;
            font-weight: 600;
            color: #8A93A5;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .info-value {
            font-size: 0.85rem;
            font-weight: 500;
            color: #1F2A3E;
        }
        .info-value-small {
            font-size: 0.8rem;
            font-weight: 400;
            color: #4A5568;
        }
        
        /* Description Section */
        .description-section {
            background: #F8FAFC;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 20px;
        }
        .description-label {
            font-size: 0.7rem;
            font-weight: 600;
            color: #8A93A5;
            text-transform: uppercase;
            margin-bottom: 8px;
        }
        .description-text {
            font-size: 0.85rem;
            line-height: 1.5;
            color: #4A5568;
            margin: 0;
        }
        
        /* Proof Image */
        .proof-image {
            max-width: 120px;
            border-radius: 10px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.1);
        }
        
        /* Divider */
        .divider-light {
            height: 1px;
            background: #EEF2F6;
            margin: 20px 0;
        }
        
        /* Signature Box */
        .signature-box {
            background: #F8FAFC;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 20px;
        }
        .signature-header {
            font-size: 0.7rem;
            font-weight: 600;
            color: #8A93A5;
            text-transform: uppercase;
            margin-bottom: 12px;
        }
        .signature-content {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            align-items: center;
        }
        .signature-details-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            flex: 1;
        }
        .signature-detail {
            min-width: 120px;
        }
        .signature-detail-label {
            font-size: 0.65rem;
            color: #8A93A5;
        }
        .signature-detail-value {
            font-size: 0.8rem;
            font-weight: 500;
            color: #1F2A3E;
        }
        .signature-image {
            max-height: 50px;
        }
        .signature-empty {
            font-size: 0.7rem;
            color: #8A93A5;
            font-style: italic;
        }
        
        /* Admin Notes */
        .admin-notes-label {
            font-size: 0.7rem;
            font-weight: 600;
            color: #8A93A5;
            text-transform: uppercase;
            margin-bottom: 6px;
        }
        .admin-notes-textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #E2E8F0;
            border-radius: 10px;
            font-size: 0.8rem;
            transition: all 0.2s;
        }
        .admin-notes-textarea:focus {
            outline: none;
            border-color: #FF6B35;
            box-shadow: 0 0 0 3px rgba(255,107,53,0.1);
        }
        
        /* Action Footer */
        .action-footer {
            margin-top: 20px;
            padding-top: 16px;
            border-top: 1px solid #EEF2F6;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }
        .btn-approve {
            background: linear-gradient(135deg, #27AE60, #1e8449);
            border: none;
            padding: 8px 24px;
            border-radius: 50px;
            color: white;
            font-weight: 600;
            font-size: 0.8rem;
            transition: all 0.2s;
        }
        .btn-approve:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(39,174,96,0.3);
        }
        .btn-reject {
            background: linear-gradient(135deg, #E74C3C, #c0392b);
            border: none;
            padding: 8px 24px;
            border-radius: 50px;
            color: white;
            font-weight: 600;
            font-size: 0.8rem;
            transition: all 0.2s;
        }
        .btn-reject:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(231,76,60,0.3);
        }
        
        /* Current Signature Bar */
        .signature-bar {
            background: #E8F8F5;
            border-radius: 12px;
            padding: 12px 20px;
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }
        .signature-bar-content {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .signature-bar-text {
            font-size: 0.85rem;
            color: #2C3E50;
        }
        .signature-bar-text strong {
            color: #1e8449;
        }
        .signature-bar-preview {
            max-height: 35px;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 20px;
        }
        .empty-state-icon {
            font-size: 48px;
            color: #27AE60;
            margin-bottom: 16px;
        }
        .empty-state-title {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 8px;
        }
        .empty-state-text {
            color: #8A93A5;
            margin-bottom: 20px;
        }
        
        /* Section Title */
        .section-title {
            font-size: 1rem;
            font-weight: 600;
            color: #1F2A3E;
            margin-bottom: 16px;
        }
        .section-title i {
            color: #FF6B35;
            margin-right: 8px;
        }
    </style>
</head>
<body class="app-page admin-page">
    <div class="container-fluid">
        <div class="row">
            <?php include 'sidebar.php'; ?>
            
            <div class="col-md-10 main-content content-wrapper">
                <!-- Page Header -->
                <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
                    <h2 style="font-size: 1.5rem; font-weight: 700; margin: 0;">
                        <i class="fas fa-check-double me-2" style="color: #FF6B35;"></i> Verify Claims
                    </h2>
                    <div class="action-header-buttons">
                        <a href="claim-history.php" class="btn btn-secondary-custom">
                            <i class="fas fa-history"></i> History
                        </a>
                        <a href="dashboard.php" class="btn btn-secondary-custom">
                            <i class="fas fa-arrow-left"></i> Back
                        </a>
                    </div>
                </div>
                
                <!-- Alert Messages -->
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
                
                <!-- Current Signature Bar -->
                <div class="signature-bar">
                    <div class="signature-bar-content">
                        <i class="fas fa-signature" style="color: #FF6B35;"></i>
                        <span class="signature-bar-text">
                            <strong>Current Admin Signature:</strong>
                            <?php if (!empty($admin_signature['name'])): ?>
                                <?= htmlspecialchars($admin_signature['name']) ?> (ID: <?= htmlspecialchars($admin_signature['id']) ?>)
                            <?php else: ?>
                                <span class="text-muted">Not configured</span>
                            <?php endif; ?>
                        </span>
                        <?php if (!empty($admin_signature['image'])): ?>
                            <img src="<?= $admin_signature['image'] ?>" class="signature-bar-preview">
                        <?php endif; ?>
                    </div>
                    <a href="signature-settings.php" class="btn btn-primary-custom btn-action-compact">
                        <i class="fas fa-edit"></i> Edit
                    </a>
                </div>
                
                <?php if(empty($pending_claims)): ?>
                    <!-- Empty State -->
                    <div class="empty-state">
                        <div class="empty-state-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <h4 class="empty-state-title">No pending claims</h4>
                        <p class="empty-state-text">All claims have been verified. Check back later for new claims.</p>
                        <a href="claim-history.php" class="btn btn-secondary-custom">
                            <i class="fas fa-history"></i> View Claim History
                        </a>
                    </div>
                <?php else: ?>
                    <!-- Claims List -->
                    <?php foreach($pending_claims as $claim): 
                        $founder = $founder_info[$claim['item_id']] ?? null;
                    ?>
                    <div class="claim-card">
                        <!-- Card Header -->
                        <div class="card-header-custom">
                            <div class="claim-title-section">
                                <span class="claim-badge"><i class="fas fa-file-alt"></i> CLAIM</span>
                                <span class="claim-number">#<?= $claim['claim_id'] ?></span>
                                <span class="status-pending"><i class="fas fa-clock"></i> Pending Verification</span>
                            </div>
                            <div class="action-buttons">
                                <a href="view-claim-report.php?id=<?= $claim['claim_id'] ?>" class="btn btn-primary-custom btn-action-compact" target="_blank">
                                    <i class="fas fa-file-alt"></i> View Report
                                </a>
                            </div>
                        </div>
                        
                        <!-- Card Body -->
                        <div class="card-body-custom">
                            <!-- Claimant Info -->
                            <h6 class="section-title"><i class="fas fa-user-check"></i> Claimant Information</h6>
                            <div class="info-grid">
                                <div class="info-field">
                                    <span class="info-label">Full Name</span>
                                    <span class="info-value"><?= htmlspecialchars($claim['claimant_name']) ?></span>
                                </div>
                                <div class="info-field">
                                    <span class="info-label">Student/Staff ID</span>
                                    <span class="info-value"><?= htmlspecialchars($claim['claimant_student_id'] ?? 'Not provided') ?></span>
                                </div>
                                <div class="info-field">
                                    <span class="info-label">Email Address</span>
                                    <span class="info-value info-value-small"><?= htmlspecialchars($claim['claimant_email']) ?></span>
                                </div>
                                <div class="info-field">
                                    <span class="info-label">Phone Number</span>
                                    <span class="info-value"><?= htmlspecialchars($claim['claimant_phone'] ?? 'Not provided') ?></span>
                                </div>
                                <div class="info-field">
                                    <span class="info-label">Department</span>
                                    <span class="info-value info-value-small"><?= htmlspecialchars($claim['claimant_department'] ?? 'Not provided') ?></span>
                                </div>
                                <div class="info-field">
                                    <span class="info-label">Claim Submitted</span>
                                    <span class="info-value"><?= date('F d, Y \a\t h:i A', strtotime($claim['created_at'])) ?></span>
                                </div>
                            </div>
                            
                            <!-- Claimant Description -->
                            <div class="description-section">
                                <div class="description-label">Claimant Description</div>
                                <p class="description-text"><?= nl2br(htmlspecialchars($claim['claimant_description'] ?? 'No description provided')) ?></p>
                            </div>
                            
                            <?php if(!empty($claim['proof_image_url'])): ?>
                            <div class="mb-3">
                                <span class="info-label" style="display: block; margin-bottom: 8px;">Proof of Ownership</span>
                                <img src="<?= $base_url . $claim['proof_image_url'] ?>" class="proof-image">
                            </div>
                            <?php endif; ?>
                            
                            <div class="divider-light"></div>
                            
                            <!-- Founder Information -->
                            <h6 class="section-title"><i class="fas fa-hand-peace"></i> Founder Information</h6>
                            <?php if($founder): ?>
                            <div class="info-grid">
                                <div class="info-field">
                                    <span class="info-label">Full Name</span>
                                    <span class="info-value"><?= htmlspecialchars($founder['name']) ?></span>
                                </div>
                                <div class="info-field">
                                    <span class="info-label">Student/Staff ID</span>
                                    <span class="info-value"><?= htmlspecialchars($founder['student_staff_id'] ?? 'Not provided') ?></span>
                                </div>
                                <div class="info-field">
                                    <span class="info-label">Email Address</span>
                                    <span class="info-value info-value-small"><?= htmlspecialchars($founder['email']) ?></span>
                                </div>
                                <div class="info-field">
                                    <span class="info-label">Phone Number</span>
                                    <span class="info-value"><?= htmlspecialchars($founder['phone'] ?? 'Not provided') ?></span>
                                </div>
                                <div class="info-field">
                                    <span class="info-label">Department</span>
                                    <span class="info-value info-value-small"><?= htmlspecialchars($founder['department'] ?? 'Not provided') ?></span>
                                </div>
                            </div>
                            <?php else: ?>
                            <p class="text-muted" style="font-size: 0.8rem;">Founder information not available</p>
                            <?php endif; ?>
                            
                            <div class="divider-light"></div>
                            
                            <!-- Item Information -->
                            <h6 class="section-title"><i class="fas fa-box"></i> Item Information</h6>
                            <div class="info-grid">
                                <div class="info-field">
                                    <span class="info-label">Item Title</span>
                                    <span class="info-value"><?= htmlspecialchars($claim['item_title'] ?? 'N/A') ?></span>
                                </div>
                                <div class="info-field">
                                    <span class="info-label">Brand</span>
                                    <span class="info-value"><?= htmlspecialchars($claim['brand'] ?? 'N/A') ?></span>
                                </div>
                                <div class="info-field">
                                    <span class="info-label">Color</span>
                                    <span class="info-value"><?= htmlspecialchars($claim['color'] ?? 'N/A') ?></span>
                                </div>
                                <div class="info-field">
                                    <span class="info-label">Found at</span>
                                    <span class="info-value"><?= htmlspecialchars($claim['found_location'] ?? 'N/A') ?></span>
                                </div>
                                <div class="info-field">
                                    <span class="info-label">Collection Point</span>
                                    <span class="info-value"><?= htmlspecialchars($claim['delivery_location'] ?? 'N/A') ?></span>
                                </div>
                            </div>
                            <?php if(!empty($claim['image_url'])): ?>
                            <div class="mt-2">
                                <img src="<?= $base_url . $claim['image_url'] ?>" class="proof-image">
                            </div>
                            <?php endif; ?>
                            
                            <div class="divider-light"></div>
                            
                            <!-- Admin Signature -->
                            <h6 class="section-title"><i class="fas fa-signature"></i> Admin Signature (Will be applied)</h6>
                            <div class="signature-box">
                                <div class="signature-content">
                                    <div class="signature-details-grid">
                                        <div class="signature-detail">
                                            <div class="signature-detail-label">Name</div>
                                            <div class="signature-detail-value"><?= htmlspecialchars($admin_signature['name']) ?></div>
                                        </div>
                                        <div class="signature-detail">
                                            <div class="signature-detail-label">Staff ID</div>
                                            <div class="signature-detail-value"><?= htmlspecialchars($admin_signature['id']) ?></div>
                                        </div>
                                        <div class="signature-detail">
                                            <div class="signature-detail-label">Position</div>
                                            <div class="signature-detail-value"><?= htmlspecialchars($admin_signature['position']) ?></div>
                                        </div>
                                        <div class="signature-detail">
                                            <div class="signature-detail-label">Department</div>
                                            <div class="signature-detail-value"><?= htmlspecialchars($admin_signature['department']) ?></div>
                                        </div>
                                        <div class="signature-detail">
                                            <div class="signature-detail-label">Date</div>
                                            <div class="signature-detail-value"><?= date('F d, Y') ?></div>
                                        </div>
                                    </div>
                                    <?php if (!empty($admin_signature['image'])): ?>
                                        <img src="<?= $admin_signature['image'] ?>" class="signature-image">
                                    <?php else: ?>
                                        <span class="signature-empty">No signature image uploaded</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Action Form -->
                            <form method="POST" action="">
                                <?= csrf_field() ?>
                                <input type="hidden" name="claim_id" value="<?= $claim['claim_id'] ?>">
                                <input type="hidden" name="action" id="action_<?= $claim['claim_id'] ?>" value="">
                                
                                <div class="admin-notes-label">Admin Notes <span class="text-muted fw-normal">(Optional)</span></div>
                                <textarea name="admin_notes" class="admin-notes-textarea" rows="2" placeholder="Add verification notes... These will be visible to both parties."></textarea>
                                
                                <div class="action-footer">
                                    <button type="button" class="btn-reject" onclick="setActionAndSubmit(<?= $claim['claim_id'] ?>, 'reject')">
                                        <i class="fas fa-times me-1"></i> Reject
                                    </button>
                                    <button type="button" class="btn-approve" onclick="setActionAndSubmit(<?= $claim['claim_id'] ?>, 'approve')">
                                        <i class="fas fa-check me-1"></i> Approve Claim
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>
        function setActionAndSubmit(claimId, action) {
            const actionInput = document.getElementById(`action_${claimId}`);
            if (actionInput) {
                actionInput.value = action;
            }
            
            if (action === 'approve') {
                if (confirm('Approve this claim?\n\nThis will:\n✓ Notify both parties\n✓ Record your digital signature\n✓ Mark the item as returned\n\nThis action cannot be undone.')) {
                    document.querySelector(`form input[name="claim_id"][value="${claimId}"]`).closest('form').submit();
                }
            } else {
                if (confirm('Reject this claim?\n\nThis will notify the claimant and this action cannot be undone.')) {
                    document.querySelector(`form input[name="claim_id"][value="${claimId}"]`).closest('form').submit();
                }
            }
        }
        
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
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
