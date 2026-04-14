<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$userID = $_SESSION['userID'];

// FIXED: Use correct column names from your database
$stmt = $db->prepare("
    SELECT c.*, i.description as item_description, i.status as item_status, i.image_url
    FROM claim_requests c
    JOIN items i ON c.item_id = i.item_id
    WHERE c.claimant_id = ?
    ORDER BY c.created_at DESC
");
$stmt->execute([$userID]);
$claims = $stmt->fetchAll();

$base_url = '/reclaim-system/';
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
</head>
<body>
    <?php include __DIR__ . '/../includes/header.php'; ?>
    
    <div class="container mt-4">
        <div class="card fade-in">
            <div class="card-header">
                <h4><i class="fas fa-file-alt"></i> My Claim Requests</h4>
            </div>
            <div class="card-body">
                <?php if(empty($claims)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-inbox fa-4x mb-3" style="color: var(--primary-orange);"></i>
                        <h5>No claims submitted yet</h5>
                        <p>Search for items and submit a claim to get started.</p>
                        <a href="<?= $base_url ?>search.php" class="btn btn-primary">Search Items</a>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Claim ID</th>
                                    <th>Item</th>
                                    <th>Claim Date</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($claims as $claim): ?>
                                <tr>
                                    <!-- FIXED: Use claim_id instead of claimID -->
                                    <td>#<?= $claim['claim_id'] ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars($claim['item_description']) ?></strong>
                                    </td
                                    <!-- FIXED: Use created_at instead of claim_date -->
                                    <td><?= date('M d, Y', strtotime($claim['created_at'])) ?></td>
                                    <td>
                                        <?php
                                        $badgeClass = [
                                            'pending' => 'warning',
                                            'approved' => 'success',
                                            'rejected' => 'danger'
                                        ][$claim['status']] ?? 'secondary';
                                        ?>
                                        <span class="badge bg-<?= $badgeClass ?>">
                                            <?= ucfirst($claim['status']) ?>
                                        </span>
                                    </td
                                    <td>
                                        <!-- FIXED: Use claim_id instead of claimID -->
                                        <button onclick="viewClaim(<?= $claim['claim_id'] ?>)" class="btn btn-sm btn-info">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                        <?php if($claim['status'] == 'approved'): ?>
                                            <button onclick="completeReclaim(<?= $claim['claim_id'] ?>)" class="btn btn-sm btn-success">
                                                <i class="fas fa-handshake"></i> Complete Reclaim
                                            </button>
                                        <?php endif; ?>
                                    </td
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                         </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>
    function viewClaim(claimId) {
        window.location.href = '<?= $base_url ?>user/claim-details.php?id=' + claimId;
    }
    
    function completeReclaim(claimId) {
        if(confirm('Have you received your item? This action cannot be undone.')) {
            window.location.href = '<?= $base_url ?>user/complete-reclaim.php?id=' + claimId;
        }
    }
    </script>
    
    <?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>