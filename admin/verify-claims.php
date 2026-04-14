<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
requireAdmin();

$db = Database::getInstance()->getConnection();
$message = '';
$error = '';

// Handle claim verification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['claim_id'])) {
    $claimId = $_POST['claim_id'];
    $action = $_POST['action'];
    $admin_notes = $_POST['admin_notes'] ?? '';
    
    $status = $action === 'approve' ? 'approved' : 'rejected';
    
    $stmt = $db->prepare("
        UPDATE claim_requests 
        SET status = ?, admin_notes = ?, verified_by = ?, verified_date = NOW() 
        WHERE claim_id = ?
    ");
    
    if ($stmt->execute([$status, $admin_notes, $_SESSION['userID'], $claimId])) {
        // Get claim details for notification
        $stmt = $db->prepare("
            SELECT c.*, u.email, u.name, u.user_id, i.title, i.description 
            FROM claim_requests c
            JOIN users u ON c.claimant_id = u.user_id
            JOIN items i ON c.item_id = i.item_id
            WHERE c.claim_id = ?
        ");
        $stmt->execute([$claimId]);
        $claim = $stmt->fetch();
        
        // Send notification to claimant
        $notificationTitle = $status === 'approved' ? 'Claim Approved!' : 'Claim Rejected';
        $notificationType = $status === 'approved' ? 'success' : 'danger';
        $notificationMessage = "Your claim for '" . htmlspecialchars($claim['title'] ?? $claim['description']) . "' has been $status. " . ($admin_notes ? " Notes: $admin_notes" : "");
        
        $stmt = $db->prepare("
            INSERT INTO notifications (user_id, title, message, type, is_read, created_at) 
            VALUES (?, ?, ?, ?, 0, NOW())
        ");
        $stmt->execute([$claim['user_id'], $notificationTitle, $notificationMessage, $notificationType]);
        
        $message = "Claim $status successfully";
    } else {
        $error = "Failed to update claim";
    }
}

// Get claim ID from URL for single view
$claim_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Get pending claims or specific claim
if ($claim_id > 0) {
    $stmt = $db->prepare("
        SELECT c.*, i.title as item_title, i.description as item_description, i.image_url, 
               u.name as claimant_name, u.email as claimant_email, u.phone as claimant_phone
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
               u.name as claimant_name, u.email as claimant_email, u.phone as claimant_phone
        FROM claim_requests c
        JOIN items i ON c.item_id = i.item_id
        JOIN users u ON c.claimant_id = u.user_id
        WHERE c.status = 'pending'
        ORDER BY c.created_at ASC
    ");
    $stmt->execute();
    $pending_claims = $stmt->fetchAll();
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
        
        .claim-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            margin-bottom: 25px;
            overflow: hidden;
        }
        
        .claim-card-header {
            background: linear-gradient(135deg, #FF6B35, #E85D2C);
            color: white;
            padding: 15px 20px;
        }
        
        .claim-card-body {
            padding: 20px;
        }
        
        .info-section {
            background: #f8f9fa;
            border-radius: 15px;
            padding: 15px;
            margin-bottom: 15px;
        }
        
        .info-section h6 {
            color: #FF6B35;
            font-weight: 700;
            margin-bottom: 15px;
        }
        
        .proof-image {
            max-width: 200px;
            border-radius: 10px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
        }
        
        .btn-approve {
            background: linear-gradient(135deg, #27AE60, #1e8449);
            border: none;
            padding: 10px 30px;
            border-radius: 50px;
            color: white;
        }
        
        .btn-reject {
            background: linear-gradient(135deg, #E74C3C, #c0392b);
            border: none;
            padding: 10px 30px;
            border-radius: 50px;
            color: white;
        }
        
        .btn-approve:hover, .btn-reject:hover {
            transform: translateY(-2px);
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
        }
        
        .btn-secondary-custom:hover {
            background: #5a6268;
            color: white;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <?php include 'sidebar.php'; ?>
            
            <div class="col-md-10 main-content">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2 class="fw-bold"><i class="fas fa-check-double me-2" style="color: #FF6B35;"></i> Verify Claims</h2>
                    <a href="dashboard.php" class="btn btn-secondary-custom">
                        <i class="fas fa-arrow-left me-2"></i> Back to Dashboard
                    </a>
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
                
                <?php if(empty($pending_claims)): ?>
                    <div class="card text-center py-5">
                        <div class="card-body">
                            <i class="fas fa-check-circle fa-4x mb-3" style="color: #27AE60;"></i>
                            <h4>No pending claims to verify</h4>
                            <p class="text-muted">All claims have been processed. Check back later for new claims.</p>
                            <a href="dashboard.php" class="btn btn-primary-custom">Go to Dashboard</a>
                        </div>
                    </div>
                <?php else: ?>
                    <?php foreach($pending_claims as $claim): ?>
                    <div class="claim-card">
                        <div class="claim-card-header">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">
                                    <i class="fas fa-file-alt me-2"></i> Claim #<?= $claim['claim_id'] ?>
                                </h5>
                                <span class="badge bg-warning text-dark">
                                    <i class="fas fa-clock me-1"></i> Pending
                                </span>
                            </div>
                            <small>Submitted on <?= date('F d, Y \a\t h:i A', strtotime($claim['created_at'])) ?></small>
                        </div>
                        <div class="claim-card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="info-section">
                                        <h6><i class="fas fa-user me-2"></i> Claimant Information</h6>
                                        <p class="mb-1"><strong>Name:</strong> <?= htmlspecialchars($claim['claimant_name']) ?></p>
                                        <p class="mb-1"><strong>Email:</strong> <?= htmlspecialchars($claim['claimant_email']) ?></p>
                                        <?php if(!empty($claim['claimant_phone'])): ?>
                                            <p class="mb-0"><strong>Phone:</strong> <?= htmlspecialchars($claim['claimant_phone']) ?></p>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="info-section">
                                        <h6><i class="fas fa-align-left me-2"></i> Claimant Description</h6>
                                        <p class="mb-0"><?= nl2br(htmlspecialchars($claim['claimant_description'] ?? 'No description provided')) ?></p>
                                    </div>
                                    
                                    <?php if(!empty($claim['proof_image_url'])): ?>
                                        <div class="info-section">
                                            <h6><i class="fas fa-camera me-2"></i> Proof of Ownership</h6>
                                            <img src="<?= $base_url . $claim['proof_image_url'] ?>" class="proof-image" alt="Proof image">
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="info-section">
                                        <h6><i class="fas fa-box me-2"></i> Item Information</h6>
                                        <p class="mb-1"><strong>Title:</strong> <?= htmlspecialchars($claim['item_title'] ?? 'N/A') ?></p>
                                        <p class="mb-1"><strong>Description:</strong> <?= htmlspecialchars(substr($claim['item_description'] ?? '', 0, 100)) ?></p>
                                    </div>
                                    
                                    <?php if(!empty($claim['image_url'])): ?>
                                        <div class="info-section">
                                            <h6><i class="fas fa-image me-2"></i> Item Photo</h6>
                                            <img src="<?= $base_url . $claim['image_url'] ?>" class="proof-image" alt="Item image">
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <hr>
                            
                            <form method="POST" action="" class="mt-3">
                                <input type="hidden" name="claim_id" value="<?= $claim['claim_id'] ?>">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Admin Notes (Optional)</label>
                                    <textarea name="admin_notes" class="form-control" rows="2" placeholder="Add verification notes or reason for rejection..."></textarea>
                                </div>
                                <div class="d-flex gap-3">
                                    <button type="submit" name="action" value="approve" class="btn btn-approve" onclick="return confirm('Approve this claim? This will notify the claimant.')">
                                        <i class="fas fa-check me-2"></i> Approve Claim
                                    </button>
                                    <button type="submit" name="action" value="reject" class="btn btn-reject" onclick="return confirm('Reject this claim? This action cannot be undone.')">
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
    
    <style>
        .btn-primary-custom {
            background: linear-gradient(135deg, #FF6B35, #E85D2C);
            border: none;
            padding: 10px 25px;
            border-radius: 50px;
            transition: all 0.3s;
            color: white;
            text-decoration: none;
            display: inline-block;
        }
        .btn-primary-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255,107,53,0.3);
            color: white;
        }
    </style>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>