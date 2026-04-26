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

// Handle report view/print
if (isset($_GET['view_report']) && isset($_GET['claim_id'])) {
    $claimId = (int)$_GET['claim_id'];
    
    // Get claim details
    $stmt = $db->prepare("
        SELECT c.*, i.title as item_title, i.description as item_description, i.image_url,
               i.found_location, i.delivery_location, i.brand, i.color,
               u.name as claimant_name, u.email as claimant_email, u.phone as claimant_phone,
               u.student_staff_id as claimant_student_id, u.department as claimant_department,
               u.created_at as claimant_joined_date
        FROM claim_requests c
        JOIN items i ON c.item_id = i.item_id
        JOIN users u ON c.claimant_id = u.user_id
        WHERE c.claim_id = ?
    ");
    $stmt->execute([$claimId]);
    $claim = $stmt->fetch();
    
    if ($claim) {
        // Get founder information
        $stmt = $db->prepare("
            SELECT u.name, u.email, u.phone, u.student_staff_id, u.department
            FROM users u
            JOIN items i ON i.reported_by = u.user_id
            WHERE i.item_id = ?
        ");
        $stmt->execute([$claim['item_id']]);
        $founder = $stmt->fetch();
        
        // Display printable report
        displayReport($claim, $founder, $claimId, $admin_signature);
        exit();
    }
}

// Handle claim verification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['claim_id']) && isset($_POST['action'])) {
    require_csrf_token();

    $claimId = (int) $_POST['claim_id'];
    $action = $_POST['action'] ?? '';
    $admin_notes = $_POST['admin_notes'] ?? '';
    
    // Security: only known actions may change claim state.
    if (!in_array($action, ['approve', 'reject'], true)) {
        $error = "Invalid claim action";
    }

    $status = $action === 'approve' ? 'approved' : 'rejected';
    
    // Build signature data from session
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
            
            // Get claim details for notification
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
                // Notify claimant
                $claimantTitle = "✅ Claim Approved!";
                $claimantMessage = "Congratulations! Your claim for '{$claim['item_title']}' has been approved.\n\n";
                $claimantMessage .= "📍 Item can be collected at: {$claim['delivery_location']}\n";
                $claimantMessage .= "📅 Please bring your ID for verification.\n\n";
                if ($admin_notes) {
                    $claimantMessage .= "📝 Admin Notes: $admin_notes\n\n";
                }
                $claimantMessage .= "Thank you for using Reclaim System!";
                $notification->send($claim['claimant_id'], $claimantTitle, $claimantMessage, 'success');
                
                // Notify founder
                $founderTitle = "📦 Your Found Item Has Been Claimed!";
                $founderMessage = "Good news! The item you reported as found has been claimed by the rightful owner.\n\n";
                $founderMessage .= "📌 Item: {$claim['item_title']}\n";
                $founderMessage .= "👤 Claimed by: {$claim['claimant_name']}\n";
                $founderMessage .= "📍 Item was kept at: {$claim['delivery_location']}\n\n";
                $founderMessage .= "Thank you for your honesty! 🎉";
                $notification->send($claim['founder_id'], $founderTitle, $founderMessage, 'success');
                
                // Update item status
                $stmt = $db->prepare("UPDATE items SET status = 'returned' WHERE item_id = ?");
                $stmt->execute([$claim['item_id']]);
            } else {
                // Notify claimant only
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

// Function to display printable report with signature
function displayReport($claim, $founder, $claimId, $admin_signature) {
    $receipt_number = 'RCL-' . strtoupper(uniqid()) . '-' . $claimId;
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Claim Report - Reclaim System</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
        <style>
            * { font-family: 'Inter', 'Segoe UI', Arial, sans-serif; }
            body { background: #f5f5f5; padding: 20px; }
            .report-container { max-width: 900px; margin: 0 auto; background: white; border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); overflow: hidden; }
            .report-header { background: linear-gradient(135deg, #FF6B35, #E85D2C); color: white; padding: 30px; text-align: center; }
            .report-header h1 { margin: 0; font-size: 28px; }
            .report-body { padding: 30px; }
            .report-title { text-align: center; margin-bottom: 30px; }
            .report-title h2 { color: #2C3E50; margin: 0; }
            .receipt-number { text-align: center; font-size: 12px; color: #999; margin-top: 5px; }
            .info-section { margin-bottom: 25px; border-bottom: 1px solid #e0e0e0; padding-bottom: 15px; }
            .info-section h3 { color: #FF6B35; font-size: 16px; margin-bottom: 15px; border-left: 3px solid #FF6B35; padding-left: 10px; }
            .info-row { display: flex; margin-bottom: 8px; font-size: 13px; }
            .info-label { width: 160px; font-weight: bold; color: #555; }
            .info-value { flex: 1; color: #333; }
            .status-badge { display: inline-block; background: #F39C12; color: white; padding: 5px 15px; border-radius: 50px; font-size: 12px; font-weight: bold; }
            .signature-section { margin-top: 30px; padding-top: 20px; border-top: 1px dashed #ccc; display: flex; justify-content: flex-end; }
            .signature-card { text-align: center; width: 280px; border: 1px solid #e0e0e0; border-radius: 10px; padding: 15px; background: #fafafa; }
            .signature-image { max-width: 180px; max-height: 60px; margin-bottom: 10px; object-fit: contain; }
            .signature-line { width: 150px; height: 1px; background: #ccc; margin: 10px auto; }
            .signature-name { font-size: 12px; font-weight: bold; color: #333; }
            .signature-details { font-size: 11px; color: #666; margin-top: 3px; }
            .footer { background: #f8f9fa; padding: 20px; text-align: center; font-size: 12px; color: #999; }
            .btn-print { position: fixed; top: 20px; right: 20px; background: linear-gradient(135deg, #FF6B35, #E85D2C); color: white; border: none; padding: 10px 25px; border-radius: 50px; cursor: pointer; z-index: 1000; font-weight: 600; }
            @media print { .btn-print, .no-print { display: none !important; } body { background: white; padding: 0; } .report-container { box-shadow: none; margin: 0; } }
        </style>
    </head>
    <body>
        <button class="btn-print no-print" onclick="window.print()"><i class="fas fa-print me-2"></i> Print / Save as PDF</button>
        
        <div class="report-container">
            <div class="report-header">
                <h1><i class="fas fa-recycle"></i> Reclaim System</h1>
                <p>Official Claim Report</p>
            </div>
            <div class="report-body">
                <div class="report-title">
                    <h2>CLAIM DETAILS</h2>
                    <div class="receipt-number">Report No: <?= $receipt_number ?></div>
                </div>
                
                <div class="info-section">
                    <h3>📋 Claim Information</h3>
                    <div class="info-row"><div class="info-label">Claim ID:</div><div class="info-value">#<?= $claimId ?></div></div>
                    <div class="info-row"><div class="info-label">Status:</div><div class="info-value"><span class="status-badge">PENDING VERIFICATION</span></div></div>
                    <div class="info-row"><div class="info-label">Report Date:</div><div class="info-value"><?= date('F d, Y') ?></div></div>
                    <div class="info-row"><div class="info-label">Report Time:</div><div class="info-value"><?= date('h:i A') ?></div></div>
                </div>
                
                <div class="info-section">
                    <h3>👤 Claimant Information (Owner)</h3>
                    <div class="info-row"><div class="info-label">Full Name:</div><div class="info-value"><?= htmlspecialchars($claim['claimant_name']) ?></div></div>
                    <div class="info-row"><div class="info-label">Student/Staff ID:</div><div class="info-value"><?= htmlspecialchars($claim['claimant_student_id'] ?? 'Not provided') ?></div></div>
                    <div class="info-row"><div class="info-label">Email:</div><div class="info-value"><?= htmlspecialchars($claim['claimant_email']) ?></div></div>
                    <div class="info-row"><div class="info-label">Phone:</div><div class="info-value"><?= htmlspecialchars($claim['claimant_phone'] ?? 'Not provided') ?></div></div>
                    <div class="info-row"><div class="info-label">Department:</div><div class="info-value"><?= htmlspecialchars($claim['claimant_department'] ?? 'Not provided') ?></div></div>
                </div>
                
                <div class="info-section">
                    <h3>🔍 Founder Information (Finder)</h3>
                    <div class="info-row"><div class="info-label">Full Name:</div><div class="info-value"><?= htmlspecialchars($founder['name'] ?? 'N/A') ?></div></div>
                    <div class="info-row"><div class="info-label">Student/Staff ID:</div><div class="info-value"><?= htmlspecialchars($founder['student_staff_id'] ?? 'Not provided') ?></div></div>
                    <div class="info-row"><div class="info-label">Email:</div><div class="info-value"><?= htmlspecialchars($founder['email'] ?? 'N/A') ?></div></div>
                    <div class="info-row"><div class="info-label">Phone:</div><div class="info-value"><?= htmlspecialchars($founder['phone'] ?? 'Not provided') ?></div></div>
                    <div class="info-row"><div class="info-label">Department:</div><div class="info-value"><?= htmlspecialchars($founder['department'] ?? 'Not provided') ?></div></div>
                </div>
                
                <div class="info-section">
                    <h3>📦 Item Information</h3>
                    <div class="info-row"><div class="info-label">Item ID:</div><div class="info-value">#<?= $claim['item_id'] ?></div></div>
                    <div class="info-row"><div class="info-label">Title:</div><div class="info-value"><?= htmlspecialchars($claim['item_title'] ?? 'N/A') ?></div></div>
                    <div class="info-row"><div class="info-label">Brand:</div><div class="info-value"><?= htmlspecialchars($claim['brand'] ?? 'N/A') ?></div></div>
                    <div class="info-row"><div class="info-label">Color:</div><div class="info-value"><?= htmlspecialchars($claim['color'] ?? 'N/A') ?></div></div>
                    <div class="info-row"><div class="info-label">Found at:</div><div class="info-value"><?= htmlspecialchars($claim['found_location'] ?? 'N/A') ?></div></div>
                    <div class="info-row"><div class="info-label">Collection Point:</div><div class="info-value"><?= htmlspecialchars($claim['delivery_location'] ?? 'N/A') ?></div></div>
                    <div class="info-row"><div class="info-label">Description:</div><div class="info-value"><?= nl2br(htmlspecialchars(substr($claim['item_description'] ?? '', 0, 200))) ?>...</div></div>
                </div>
                
                <div class="info-section">
                    <h3>📝 Claimant Description</h3>
                    <div class="info-row"><div class="info-value"><?= nl2br(htmlspecialchars($claim['claimant_description'] ?? 'No description provided')) ?></div></div>
                </div>
                
                <!-- SIGNATURE SECTION -->
                <div class="signature-section">
                    <div class="signature-card">
                        <h4 style="font-size: 14px; margin-bottom: 10px; color: #FF6B35;">Admin Signature</h4>
                        <?php if (!empty($admin_signature['image'])): ?>
                            <img src="<?= $admin_signature['image'] ?>" class="signature-image" alt="Admin Signature">
                        <?php else: ?>
                            <div class="signature-line"></div>
                        <?php endif; ?>
                        <div class="signature-name"><?= htmlspecialchars($admin_signature['name'] ?? $_SESSION['name'] ?? 'Administrator') ?></div>
                        <div class="signature-details">ID: <?= htmlspecialchars($admin_signature['id'] ?? $_SESSION['userID'] ?? 'N/A') ?></div>
                        <div class="signature-details">Position: <?= htmlspecialchars($admin_signature['position'] ?? 'Administrator') ?></div>
                        <div class="signature-details">Department: <?= htmlspecialchars($admin_signature['department'] ?? 'APSeM') ?></div>
                        <div class="signature-details">Date: <?= date('F d, Y') ?></div>
                    </div>
                </div>
                
                <div style="margin-top: 20px; text-align: center; font-size: 11px; color: #999;">
                    <p>This is a computer-generated document. Admin signature verifies the claim approval.</p>
                </div>
            </div>
            <div class="footer">
                <p>&copy; <?= date('Y') ?> Reclaim System. All rights reserved.</p>
                <p>This report serves as official documentation of the claim.</p>
            </div>
        </div>
    </body>
    </html>
    <?php
}

// Get claim ID from URL for single view
$claim_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Get pending claims or specific claim
if ($claim_id > 0) {
    $stmt = $db->prepare("
        SELECT c.*, i.title as item_title, i.description as item_description, i.image_url, 
               i.found_location, i.delivery_location, i.brand, i.color,
               u.name as claimant_name, u.email as claimant_email, u.phone as claimant_phone,
               u.student_staff_id as claimant_student_id, u.department as claimant_department,
               u.created_at as claimant_joined_date
        FROM claim_requests c
        JOIN items i ON c.item_id = i.item_id
        JOIN users u ON c.claimant_id = u.user_id
        WHERE c.claim_id = ?
    ");
    $stmt->execute([$claim_id]);
    $pending_claims = $stmt->fetchAll();
} else {
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
}

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
        body { background: #f0f2f5; }
        .main-content { padding: 20px; min-height: 100vh; }
        
        .btn-primary-custom {
            background: linear-gradient(135deg, #FF6B35, #E85D2C);
            border: none;
            padding: 8px 20px;
            border-radius: 50px;
            transition: all 0.3s;
            color: white;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
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
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-secondary-custom:hover {
            background: #5a6268;
            color: white;
        }
        
        .claim-card { background: white; border-radius: 20px; box-shadow: 0 5px 15px rgba(0,0,0,0.08); margin-bottom: 25px; overflow: hidden; }
        .claim-card-header { background: linear-gradient(135deg, #FF6B35, #E85D2C); color: white; padding: 15px 20px; }
        .claim-card-header small { color: white; }
        .claim-card-body { padding: 20px; }
        
        .info-section { background: #f8f9fa; border-radius: 15px; padding: 15px; margin-bottom: 15px; }
        .info-section h6 { color: #FF6B35; font-weight: 700; margin-bottom: 15px; border-bottom: 1px solid #e0e0e0; padding-bottom: 8px; }
        .proof-image { max-width: 200px; border-radius: 10px; box-shadow: 0 3px 10px rgba(0,0,0,0.1); }
        
        .btn-approve { background: linear-gradient(135deg, #27AE60, #1e8449); border: none; padding: 10px 30px; border-radius: 50px; color: white; }
        .btn-approve:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(39,174,96,0.3); }
        .btn-reject { background: linear-gradient(135deg, #E74C3C, #c0392b); border: none; padding: 10px 30px; border-radius: 50px; color: white; }
        .btn-reject:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(231,76,60,0.3); }
        .btn-report { background: linear-gradient(135deg, #3498DB, #2980B9); border: none; padding: 8px 20px; border-radius: 50px; color: white; text-decoration: none; display: inline-block; }
        .btn-report:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(52,152,219,0.3); color: white; }
        
        .info-row { display: flex; margin-bottom: 8px; font-size: 14px; }
        .info-label { width: 140px; font-weight: 600; color: #555; }
        .info-value { flex: 1; color: #333; }
        .divider { height: 1px; background: #e0e0e0; margin: 20px 0; }
        
        .current-signature-info {
            background: #e8f8f5;
            border-radius: 15px;
            padding: 15px 20px;
            margin-bottom: 20px;
            border-left: 4px solid #27AE60;
        }
        .signature-mini-preview {
            max-height: 40px;
            margin-top: 5px;
        }
    </style>
</head>
<body class="app-page admin-page">
    <div class="container-fluid">
        <div class="row">
            <?php include 'sidebar.php'; ?>
            
            <div class="col-md-10 main-content content-wrapper">
                <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
                    <h2 class="fw-bold"><i class="fas fa-check-double me-2" style="color: #FF6B35;"></i> Verify Claims</h2>
                    <div class="d-flex gap-2">
                        <a href="signature-settings.php" class="btn btn-primary-custom">
                            <i class="fas fa-signature me-2"></i> Signature Settings
                        </a>
                        <a href="dashboard.php" class="btn btn-secondary-custom">
                            <i class="fas fa-arrow-left me-2"></i> Back to Dashboard
                        </a>
                    </div>
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
                
                <!-- Current Signature Display (Read-only) -->
                <div class="current-signature-info">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div>
                            <i class="fas fa-signature me-2" style="color: #FF6B35;"></i>
                            <strong>Current Admin Signature:</strong>
                            <?php if (!empty($admin_signature['name'])): ?>
                                <?= htmlspecialchars($admin_signature['name']) ?> 
                                (ID: <?= htmlspecialchars($admin_signature['id']) ?>)
                            <?php else: ?>
                                <span class="text-muted">Not configured</span>
                            <?php endif; ?>
                        </div>
                        <a href="signature-settings.php" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-edit"></i> Edit Signature
                        </a>
                    </div>
                    <?php if (!empty($admin_signature['image'])): ?>
                        <div class="mt-2">
                            <img src="<?= $admin_signature['image'] ?>" alt="Signature" class="signature-mini-preview" style="max-height: 40px;">
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($admin_signature['position']) || !empty($admin_signature['department'])): ?>
                        <div class="small text-muted mt-1">
                            <?= htmlspecialchars($admin_signature['position']) ?>
                            <?php if (!empty($admin_signature['position']) && !empty($admin_signature['department'])): ?> | <?php endif; ?>
                            <?= htmlspecialchars($admin_signature['department']) ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <?php if(empty($pending_claims)): ?>
                    <div class="card text-center py-5">
                        <div class="card-body">
                            <i class="fas fa-check-circle fa-4x mb-3" style="color: #27AE60;"></i>
                            <h4>No pending claims to verify</h4>
                            <a href="dashboard.php" class="btn btn-primary-custom">Go to Dashboard</a>
                        </div>
                    </div>
                <?php else: ?>
                    <?php foreach($pending_claims as $claim): 
                        $founder = $founder_info[$claim['item_id']] ?? null;
                    ?>
                    <div class="claim-card">
                        <div class="claim-card-header">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="mb-0"><i class="fas fa-file-alt me-2"></i> Claim #<?= $claim['claim_id'] ?></h5>
                                <span class="badge bg-warning text-dark">Pending</span>
                            </div>
                            <small>Submitted on <?= date('F d, Y \a\t h:i A', strtotime($claim['created_at'])) ?></small>
                        </div>
                        <div class="claim-card-body">
                            <!-- Report Button -->
                            <div class="mb-3 text-end">
                                <a href="?view_report=1&claim_id=<?= $claim['claim_id'] ?>" class="btn btn-report" target="_blank">
                                    <i class="fas fa-file-alt me-2"></i> View / Print Report
                                </a>
                            </div>
                            
                            <!-- CLAIMANT SECTION -->
                            <h5 class="mb-3"><i class="fas fa-user-check me-2" style="color: #FF6B35;"></i> Claimant Information</h5>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="info-section">
                                        <h6>Personal Details</h6>
                                        <div class="info-row"><div class="info-label">Name:</div><div class="info-value"><?= htmlspecialchars($claim['claimant_name']) ?></div></div>
                                        <div class="info-row"><div class="info-label">Student/Staff ID:</div><div class="info-value"><?= htmlspecialchars($claim['claimant_student_id'] ?? 'Not provided') ?></div></div>
                                        <div class="info-row"><div class="info-label">Email:</div><div class="info-value"><?= htmlspecialchars($claim['claimant_email']) ?></div></div>
                                        <div class="info-row"><div class="info-label">Phone:</div><div class="info-value"><?= htmlspecialchars($claim['claimant_phone'] ?? 'Not provided') ?></div></div>
                                        <div class="info-row"><div class="info-label">Department:</div><div class="info-value"><?= htmlspecialchars($claim['claimant_department'] ?? 'Not provided') ?></div></div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="info-section">
                                        <h6>Claim Details</h6>
                                        <div class="info-row"><div class="info-label">Claim Date:</div><div class="info-value"><?= date('F d, Y', strtotime($claim['created_at'])) ?></div></div>
                                        <div class="info-row"><div class <div class="info-row"><div class="info-label">Claim Time:</div><div class="info-value"><?= date('h:i A', strtotime($claim['created_at'])) ?></div></div>
                                    </div>
                                    <div class="info-section">
                                        <h6>Description</h6>
                                        <p class="mb-0"><?= nl2br(htmlspecialchars($claim['claimant_description'] ?? 'No description')) ?></p>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if(!empty($claim['proof_image_url'])): ?>
                            <div class="info-section">
                                <h6>Proof of Ownership</h6>
                                <img src="<?= $base_url . $claim['proof_image_url'] ?>" class="proof-image">
                            </div>
                            <?php endif; ?>
                            
                            <div class="divider"></div>
                            
                            <!-- FOUNDER SECTION -->
                            <h5 class="mb-3"><i class="fas fa-hand-peace me-2" style="color: #FF6B35;"></i> Founder Information</h5>
                            <?php if($founder): ?>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="info-section">
                                        <h6>Personal Details</h6>
                                        <div class="info-row"><div class="info-label">Name:</div><div class="info-value"><?= htmlspecialchars($founder['name']) ?></div></div>
                                        <div class="info-row"><div class="info-label">Student/Staff ID:</div><div class="info-value"><?= htmlspecialchars($founder['student_staff_id'] ?? 'Not provided') ?></div></div>
                                        <div class="info-row"><div class="info-label">Email:</div><div class="info-value"><?= htmlspecialchars($founder['email']) ?></div></div>
                                        <div class="info-row"><div class="info-label">Phone:</div><div class="info-value"><?= htmlspecialchars($founder['phone'] ?? 'Not provided') ?></div></div>
                                        <div class="info-row"><div class="info-label">Department:</div><div class="info-value"><?= htmlspecialchars($founder['department'] ?? 'Not provided') ?></div></div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="info-section">
                                        <h6>Item Information</h6>
                                        <div class="info-row"><div class="info-label">Title:</div><div class="info-value"><?= htmlspecialchars($claim['item_title'] ?? 'N/A') ?></div></div>
                                        <div class="info-row"><div class="info-label">Brand:</div><div class="info-value"><?= htmlspecialchars($claim['brand'] ?? 'N/A') ?></div></div>
                                        <div class="info-row"><div class="info-label">Color:</div><div class="info-value"><?= htmlspecialchars($claim['color'] ?? 'N/A') ?></div></div>
                                        <div class="info-row"><div class="info-label">Found at:</div><div class="info-value"><?= htmlspecialchars($claim['found_location'] ?? 'N/A') ?></div></div>
                                        <div class="info-row"><div class="info-label">Keep at:</div><div class="info-value"><?= htmlspecialchars($claim['delivery_location'] ?? 'N/A') ?></div></div>
                                    </div>
                                </div>
                            </div>
                            <?php if(!empty($claim['image_url'])): ?>
                            <div class="info-section">
                                <h6>Item Photo</h6>
                                <img src="<?= $base_url . $claim['image_url'] ?>" class="proof-image">
                            </div>
                            <?php endif; ?>
                            <?php else: ?>
                            <p class="text-muted">Founder information not available</p>
                            <?php endif; ?>
                            
                            <div class="divider"></div>
                            
                            <!-- ADMIN SIGNATURE SECTION - Display current signature -->
                            <h5 class="mb-3"><i class="fas fa-signature me-2" style="color: #FF6B35;"></i> Admin Signature (Will be applied)</h5>
                            <div class="info-section">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="info-row"><div class="info-label">Name:</div><div class="info-value"><?= htmlspecialchars($admin_signature['name']) ?></div></div>
                                        <div class="info-row"><div class="info-label">Staff ID:</div><div class="info-value"><?= htmlspecialchars($admin_signature['id']) ?></div></div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="info-row"><div class="info-label">Position:</div><div class="info-value"><?= htmlspecialchars($admin_signature['position']) ?></div></div>
                                        <div class="info-row"><div class="info-label">Department:</div><div class="info-value"><?= htmlspecialchars($admin_signature['department']) ?></div></div>
                                    </div>
                                </div>
                                <?php if (!empty($admin_signature['image'])): ?>
                                    <div class="mt-2">
                                        <img src="<?= $admin_signature['image'] ?>" alt="Signature" style="max-height: 50px;">
                                    </div>
                                <?php else: ?>
                                    <div class="text-muted small mt-1">(Text signature - no image uploaded)</div>
                                <?php endif; ?>
                                <div class="small text-muted mt-2">
                                    <i class="fas fa-calendar-alt me-1"></i> Date: <?= date('F d, Y') ?>
                                </div>
                            </div>
                            
                            <div class="divider"></div>
                            
                            <!-- ACTION BUTTONS -->
                            <form method="POST" action="">
                                <?= csrf_field() ?>
                                <input type="hidden" name="claim_id" value="<?= $claim['claim_id'] ?>">
                                <input type="hidden" name="action" id="action_<?= $claim['claim_id'] ?>" value="">
                                
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Admin Notes</label>
                                    <textarea name="admin_notes" class="form-control" rows="2" placeholder="Add verification notes..."></textarea>
                                </div>
                                
                                <div class="d-flex gap-3">
                                    <button type="button" class="btn btn-approve" onclick="setActionAndSubmit(<?= $claim['claim_id'] ?>, 'approve')">
                                        <i class="fas fa-check me-2"></i> Approve Claim
                                    </button>
                                    <button type="button" class="btn btn-reject" onclick="setActionAndSubmit(<?= $claim['claim_id'] ?>, 'reject')">
                                        <i class="fas fa-times me-2"></i> Reject Claim
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
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>