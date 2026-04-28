<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

// Allow both admin and authenticated users to view their own claim reports
$isAdmin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
$userID = $_SESSION['userID'] ?? 0;

// Get claim ID from URL
$claimId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($claimId == 0) {
    die("Invalid claim ID");
}

$db = Database::getInstance()->getConnection();

// First, check if claim exists and get basic info
if ($isAdmin) {
    // Admin can view any claim
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
} else {
    // Regular user can only view their own claims
    $stmt = $db->prepare("
        SELECT c.*, i.title as item_title, i.description as item_description, i.image_url,
               i.found_location, i.delivery_location, i.brand, i.color,
               u.name as claimant_name, u.email as claimant_email, u.phone as claimant_phone,
               u.student_staff_id as claimant_student_id, u.department as claimant_department,
               u.created_at as claimant_joined_date
        FROM claim_requests c
        JOIN items i ON c.item_id = i.item_id
        JOIN users u ON c.claimant_id = u.user_id
        WHERE c.claim_id = ? AND c.claimant_id = ?
    ");
    $stmt->execute([$claimId, $userID]);
}
$claim = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$claim) {
    die("Claim not found or you don't have permission to view it. Claim ID: " . $claimId);
}

// Get founder information
$stmt = $db->prepare("
    SELECT u.name, u.email, u.phone, u.student_staff_id, u.department
    FROM users u
    JOIN items i ON i.reported_by = u.user_id
    WHERE i.item_id = ?
");
$stmt->execute([$claim['item_id']]);
$founder = $stmt->fetch(PDO::FETCH_ASSOC);

// Get saved signature data from session (for admin) or from database
$admin_signature = $_SESSION['admin_signature'] ?? [
    'name' => $_SESSION['name'] ?? '',
    'id' => $_SESSION['userID'] ?? '',
    'position' => 'Administrator',
    'department' => 'Auxiliary Police and Security Office',
    'image' => '',
    'updated_at' => ''
];

// If claim is approved, try to get signature from claim data
if ($claim['status'] === 'approved' && !empty($claim['admin_signature'])) {
    $saved_signature = json_decode($claim['admin_signature'], true);
    if ($saved_signature && is_array($saved_signature)) {
        $admin_signature = array_merge($admin_signature, $saved_signature);
    }
}

$receipt_number = 'RCL-' . strtoupper(uniqid()) . '-' . $claimId;
$is_approved = $claim['status'] === 'approved';
$base_url = '/reclaim-system/';

// Determine back URL based on user role
if ($isAdmin) {
    $back_url = 'verify-claims.php';
} else {
    // For regular users, go back to my-claims.php
    $back_url = '../user/my-claims.php';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Official Claim Report - Reclaim System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            font-family: 'Inter', 'Segoe UI', Arial, sans-serif;
        }
        
        body {
            background: #e9ecef;
            padding: 30px;
        }
        
        /* Report Container */
        .report-container {
            max-width: 1000px;
            margin: 0 auto;
            background: white;
            box-shadow: 0 20px 40px rgba(0,0,0,0.15);
            border-radius: 4px;
            overflow: hidden;
        }
        
        /* Header Section */
        .report-header {
            background: #1a3a5c;
            color: white;
            padding: 35px 40px;
            text-align: center;
            border-bottom: 4px solid #c0392b;
        }
        .report-header h1 {
            margin: 0;
            font-size: 28px;
            font-weight: 700;
            letter-spacing: 1px;
        }
        .report-header p {
            margin: 8px 0 0;
            opacity: 0.85;
            font-size: 14px;
        }
        
        /* Report Body */
        .report-body {
            padding: 35px 40px;
        }
        
        /* Title Section */
        .report-title {
            text-align: center;
            margin-bottom: 35px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e0e0e0;
        }
        .report-title h2 {
            color: #1a3a5c;
            margin: 0;
            font-size: 22px;
            font-weight: 700;
            letter-spacing: 0.5px;
        }
        .receipt-number {
            text-align: center;
            font-size: 12px;
            color: #7f8c8d;
            margin-top: 8px;
            font-family: monospace;
        }
        
        /* Info Sections */
        .info-section {
            margin-bottom: 30px;
            border-bottom: 1px solid #ecf0f1;
            padding-bottom: 20px;
        }
        .info-section h3 {
            color: #2c3e50;
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 18px;
            padding-left: 12px;
            border-left: 4px solid #c0392b;
        }
        
        /* Info Grid */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px 30px;
        }
        .info-row {
            display: flex;
            align-items: flex-start;
        }
        .info-label {
            width: 140px;
            font-weight: 600;
            color: #2c3e50;
            font-size: 13px;
            flex-shrink: 0;
        }
        .info-value {
            flex: 1;
            color: #34495e;
            font-size: 13px;
            line-height: 1.5;
        }
        
        /* Status Badge */
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .status-pending {
            background: #f39c12;
            color: white;
        }
        .status-approved {
            background: #27ae60;
            color: white;
        }
        
        /* Description Box */
        .description-box {
            background: #f8f9fa;
            border: 1px solid #ecf0f1;
            border-radius: 4px;
            padding: 15px 18px;
            margin-top: 10px;
        }
        .description-box p {
            margin: 0;
            font-size: 13px;
            line-height: 1.6;
            color: #2c3e50;
        }
        
        /* Proof Image */
        .proof-image {
            max-width: 150px;
            border: 1px solid #e0e0e0;
            border-radius: 4px;
            padding: 4px;
            background: white;
        }
        
        /* Signature Section */
        .signature-section {
            margin-top: 30px;
            padding-top: 25px;
            border-top: 2px dashed #e0e0e0;
            display: flex;
            justify-content: flex-end;
        }
        .signature-card {
            text-align: center;
            width: 300px;
            border: 1px solid #e0e0e0;
            border-radius: 4px;
            padding: 20px;
            background: #fafbfc;
        }
        .signature-card h4 {
            font-size: 13px;
            color: #c0392b;
            margin-bottom: 15px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .signature-image {
            max-width: 180px;
            max-height: 55px;
            display: block;
            margin: 0 auto 10px;
            object-fit: contain;
        }
        .signature-line {
            width: 150px;
            height: 1px;
            background: #ccc;
            margin: 10px auto;
        }
        .signature-name {
            font-size: 13px;
            font-weight: 700;
            color: #2c3e50;
        }
        .signature-details {
            font-size: 11px;
            color: #7f8c8d;
            margin-top: 3px;
        }
        .approved-stamp {
            margin-top: 10px;
            color: #27ae60;
            font-weight: 700;
            font-size: 12px;
            text-transform: uppercase;
        }
        
        /* Footer */
        .footer {
            background: #f8f9fa;
            padding: 20px 40px;
            text-align: center;
            border-top: 1px solid #ecf0f1;
        }
        .footer p {
            margin: 0;
            font-size: 11px;
            color: #7f8c8d;
        }
        
        /* Button Container */
        .button-container {
            position: fixed;
            top: 20px;
            right: 20px;
            display: flex;
            gap: 12px;
            z-index: 1000;
        }
        .btn-action {
            border: none;
            padding: 10px 24px;
            border-radius: 4px;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-back {
            background: #6c757d;
            color: white;
        }
        .btn-back:hover {
            background: #5a6268;
            transform: translateY(-1px);
            color: white;
        }
        .btn-print {
            background: #c0392b;
            color: white;
        }
        .btn-print:hover {
            background: #a93226;
            transform: translateY(-1px);
            color: white;
        }
        
        /* Print Styles */
        @media print {
            .button-container {
                display: none !important;
            }
            body {
                background: white;
                padding: 0;
                margin: 0;
            }
            .report-container {
                box-shadow: none;
                margin: 0;
                border-radius: 0;
            }
            .report-header {
                background: #1a3a5c;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .report-body {
                padding: 20px;
            }
            .info-grid {
                grid-template-columns: 1fr;
                gap: 12px;
            }
            .info-row {
                flex-direction: column;
            }
            .info-label {
                width: auto;
                margin-bottom: 4px;
            }
            .signature-section {
                justify-content: center;
            }
            .button-container {
                position: static;
                justify-content: center;
                margin-bottom: 20px;
            }
            body {
                padding: 15px;
            }
        }
    </style>
</head>
<body>
    <!-- Button Container - Side by Side -->
    <div class="button-container">
        <a href="<?= $back_url ?>" class="btn-action btn-back">
            <i class="fas fa-arrow-left"></i> Back
        </a>
        <button class="btn-action btn-print" onclick="window.print()">
            <i class="fas fa-print"></i> Print / Save as PDF
        </button>
    </div>
    
    <div class="report-container">
        <!-- Header -->
        <div class="report-header">
            <h1><i class="fas fa-recycle"></i> RECLAIM SYSTEM</h1>
            <p>Official Claim Report | Lost and Found Management System</p>
        </div>
        
        <!-- Body -->
        <div class="report-body">
            <div class="report-title">
                <h2>CLAIM VERIFICATION REPORT</h2>
                <div class="receipt-number">Reference No: <?= $receipt_number ?></div>
            </div>
            
            <!-- Claim Information -->
            <div class="info-section">
                <h3>📋 CLAIM INFORMATION</h3>
                <div class="info-grid">
                    <div class="info-row">
                        <div class="info-label">Claim ID:</div>
                        <div class="info-value"><strong>#<?= $claimId ?></strong></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Status:</div>
                        <div class="info-value">
                            <span class="status-badge <?= $is_approved ? 'status-approved' : 'status-pending' ?>">
                                <?= $is_approved ? 'APPROVED' : 'PENDING VERIFICATION' ?>
                            </span>
                        </div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Report Date:</div>
                        <div class="info-value"><?= date('F d, Y') ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Report Time:</div>
                        <div class="info-value"><?= date('h:i A') ?></div>
                    </div>
                </div>
            </div>
            
            <!-- Claimant Information -->
            <div class="info-section">
                <h3>👤 CLAIMANT INFORMATION (Owner)</h3>
                <div class="info-grid">
                    <div class="info-row">
                        <div class="info-label">Full Name:</div>
                        <div class="info-value"><?= htmlspecialchars($claim['claimant_name']) ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Student/Staff ID:</div>
                        <div class="info-value"><?= htmlspecialchars($claim['claimant_student_id'] ?? 'Not provided') ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Email Address:</div>
                        <div class="info-value"><?= htmlspecialchars($claim['claimant_email']) ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Phone Number:</div>
                        <div class="info-value"><?= htmlspecialchars($claim['claimant_phone'] ?? 'Not provided') ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Department:</div>
                        <div class="info-value"><?= htmlspecialchars($claim['claimant_department'] ?? 'Not provided') ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Claim Submitted:</div>
                        <div class="info-value"><?= date('F d, Y \a\t h:i A', strtotime($claim['created_at'])) ?></div>
                    </div>
                </div>
            </div>
            
            <!-- Founder Information -->
            <div class="info-section">
                <h3>🔍 FOUNDER INFORMATION (Finder)</h3>
                <?php if($founder): ?>
                <div class="info-grid">
                    <div class="info-row">
                        <div class="info-label">Full Name:</div>
                        <div class="info-value"><?= htmlspecialchars($founder['name']) ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Student/Staff ID:</div>
                        <div class="info-value"><?= htmlspecialchars($founder['student_staff_id'] ?? 'Not provided') ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Email Address:</div>
                        <div class="info-value"><?= htmlspecialchars($founder['email']) ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Phone Number:</div>
                        <div class="info-value"><?= htmlspecialchars($founder['phone'] ?? 'Not provided') ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Department:</div>
                        <div class="info-value"><?= htmlspecialchars($founder['department'] ?? 'Not provided') ?></div>
                    </div>
                </div>
                <?php else: ?>
                <div class="description-box">
                    <p class="text-muted">Founder information not available</p>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Item Information -->
            <div class="info-section">
                <h3>📦 ITEM INFORMATION</h3>
                <div class="info-grid">
                    <div class="info-row">
                        <div class="info-label">Item ID:</div>
                        <div class="info-value">#<?= $claim['item_id'] ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Title:</div>
                        <div class="info-value"><strong><?= htmlspecialchars($claim['item_title'] ?? 'N/A') ?></strong></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Brand:</div>
                        <div class="info-value"><?= htmlspecialchars($claim['brand'] ?? 'N/A') ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Color:</div>
                        <div class="info-value"><?= htmlspecialchars($claim['color'] ?? 'N/A') ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Found at:</div>
                        <div class="info-value"><?= htmlspecialchars($claim['found_location'] ?? 'N/A') ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Collection Point:</div>
                        <div class="info-value"><?= htmlspecialchars($claim['delivery_location'] ?? 'N/A') ?></div>
                    </div>
                </div>
                <?php if(!empty($claim['image_url'])): ?>
                <div style="margin-top: 15px;">
                    <img src="<?= $base_url . $claim['image_url'] ?>" class="proof-image" alt="Item Image">
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Item Description -->
            <div class="info-section">
                <h3>📄 ITEM DESCRIPTION</h3>
                <div class="description-box">
                    <p><?= nl2br(htmlspecialchars($claim['item_description'] ?? 'No description provided')) ?></p>
                </div>
            </div>
            
            <!-- Claimant Description -->
            <div class="info-section">
                <h3>📝 CLAIMANT STATEMENT</h3>
                <div class="description-box">
                    <p><?= nl2br(htmlspecialchars($claim['claimant_description'] ?? 'No description provided')) ?></p>
                </div>
                <?php if(!empty($claim['proof_image_url'])): ?>
                <div style="margin-top: 15px;">
                    <span style="font-size: 12px; color: #7f8c8d; display: block; margin-bottom: 8px;">Proof of Ownership:</span>
                    <img src="<?= $base_url . $claim['proof_image_url'] ?>" class="proof-image" alt="Proof Image">
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Signature Section -->
            <div class="signature-section">
                <div class="signature-card">
                    <h4>ADMINISTRATOR'S SIGNATURE</h4>
                    <?php if (!empty($admin_signature['image'])): ?>
                        <img src="<?= $admin_signature['image'] ?>" class="signature-image" alt="Admin Signature">
                    <?php else: ?>
                        <div class="signature-line"></div>
                    <?php endif; ?>
                    <div class="signature-name"><?= htmlspecialchars($admin_signature['name'] ?? 'Administrator') ?></div>
                    <div class="signature-details">ID: <?= htmlspecialchars($admin_signature['id'] ?? 'N/A') ?></div>
                    <div class="signature-details">Position: <?= htmlspecialchars($admin_signature['position'] ?? 'Administrator') ?></div>
                    <div class="signature-details">Department: <?= htmlspecialchars($admin_signature['department'] ?? 'APSeM') ?></div>
                    <div class="signature-details">Date: <?= date('F d, Y') ?></div>
                    <?php if ($is_approved): ?>
                        <div class="approved-stamp">✓ APPROVED</div>
                    <?php else: ?>
                        <div class="approved-stamp" style="color: #f39c12;">⏳ PENDING</div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div style="margin-top: 25px; text-align: center; font-size: 11px; color: #95a5a6;">
                <p>This is a computer-generated document. Administrator's signature verifies the claim approval.</p>
                <p>Please present this document when collecting the item.</p>
            </div>
        </div>
        
        <!-- Footer -->
        <div class="footer">
            <p>&copy; <?= date('Y') ?> Reclaim System - Lost and Found Management. All rights reserved.</p>
            <p>This report serves as official documentation of the claim transaction.</p>
        </div>
    </div>
</body>
</html>