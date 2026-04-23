<?php
// Use absolute paths
define('BASE_PATH', __DIR__);
require_once BASE_PATH . '/includes/security.php';
configureErrorHandling();
secureSessionStart();
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/header.php';
require_once BASE_PATH . '/includes/functions.php';

// Get the ID from URL
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// If no ID, redirect to search
if ($id == 0) {
    header('Location: /reclaim-system/search.php');
    exit();
}

$db = Database::getInstance()->getConnection();

// Get item details with reporter profile image
$stmt = $db->prepare("
    SELECT i.*, u.name as reporter_name, u.profile_image as reporter_profile_image, u.user_id as reporter_user_id
    FROM items i
    LEFT JOIN users u ON i.reported_by = u.user_id
    WHERE i.item_id = ?
");
$stmt->execute([$id]);
$item = $stmt->fetch(PDO::FETCH_ASSOC);

// If item not found, redirect to search
if (!$item) {
    header('Location: /reclaim-system/search.php');
    exit();
}

// Get claim count for this item
$stmt = $db->prepare("SELECT COUNT(*) FROM claim_requests WHERE item_id = ? AND status = 'approved'");
$stmt->execute([$id]);
$claim_count = $stmt->fetchColumn();

// Check if current user has already claimed this item
$user_has_claimed = false;
if(isset($_SESSION['userID'])) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM claim_requests WHERE item_id = ? AND claimant_id = ?");
    $stmt->execute([$id, $_SESSION['userID']]);
    $user_has_claimed = $stmt->fetchColumn() > 0;
}

// Helper function to get profile image URL
function getReporterProfileImageUrl($imagePath, $base_url) {
    if (!empty($imagePath) && file_exists($_SERVER['DOCUMENT_ROOT'] . '/reclaim-system/' . $imagePath)) {
        return $base_url . $imagePath;
    }
    return '';
}

// Format date with time function
function formatDateTime($dateTime) {
    if (!$dateTime) return 'Not specified';
    return date('F d, Y \a\t h:i A', strtotime($dateTime));
}

function formatDateOnly($dateTime) {
    if (!$dateTime) return 'Not specified';
    return date('F d, Y', strtotime($dateTime));
}

function formatTimeOnly($dateTime) {
    if (!$dateTime) return 'Not specified';
    return date('h:i A', strtotime($dateTime));
}

$base_url = '/reclaim-system/';

// For debugging - you can remove this after testing
$profileImagePath = $item['reporter_profile_image'] ?? '';
$profileImageUrl = getReporterProfileImageUrl($profileImagePath, $base_url);
?>

<div class="container content-wrapper">
    <nav aria-label="breadcrumb" class="fade-in">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= $base_url ?>index.php">Home</a></li>
            <li class="breadcrumb-item"><a href="<?= $base_url ?>search.php">Search Items</a></li>
            <li class="breadcrumb-item active" aria-current="page">Item Details</li>
        </ol>
    </nav>

    <div class="row">
        <!-- Left Column - Image Gallery, Reporter Info & Similar Items -->
        <div class="col-lg-5 mb-4">
            <!-- Image Gallery -->
            <div class="card border-0 shadow-sm fade-in">
                <div class="card-body p-0">
                    <?php 
                    $hasImage = !empty($item['image_url']) && imageFileExists($item['image_url']);
                    $imageUrl = $hasImage ? getImageUrl($item['image_url'], $base_url) : '';
                    ?>
                    
                    <?php if ($hasImage): ?>
                        <div class="image-gallery">
                            <img src="<?= $imageUrl ?>" class="img-fluid rounded-top" alt="Item image" style="width: 100%; height: 400px; object-fit: cover;">
                        </div>
                    <?php else: ?>
                        <div class="bg-light d-flex align-items-center justify-content-center rounded-top" style="height: 400px;">
                            <div class="text-center">
                                <i class="fas fa-box-open fa-6x" style="color: #FF6B35;"></i>
                                <p class="mt-3 text-muted">No image available</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Reporter Info Card with Profile Picture -->
            <?php if($item['reported_by']): ?>
            <div class="card border-0 shadow-sm mt-4 fade-in">
                <div class="card-header bg-white border-0 pt-3">
                    <h5 class="mb-0"><i class="fas fa-user-circle" style="color: #FF6B35;"></i> Reported By</h5>
                </div>
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <?php 
                        $reporterProfileImage = getReporterProfileImageUrl($item['reporter_profile_image'] ?? '', $base_url);
                        ?>
                        <?php if (!empty($reporterProfileImage)): ?>
                            <div class="rounded-circle overflow-hidden" style="width: 50px; height: 50px; background-color: #f8f9fa; flex-shrink: 0;">
                                <img src="<?= $reporterProfileImage ?>" alt="Profile" style="width: 100%; height: 100%; object-fit: cover;">
                            </div>
                        <?php else: ?>
                            <div class="rounded-circle bg-light d-flex align-items-center justify-content-center" style="width: 50px; height: 50px; flex-shrink: 0;">
                                <i class="fas fa-user fa-2x" style="color: #FF6B35;"></i>
                            </div>
                        <?php endif; ?>
                        <div class="ms-3">
                            <h6 class="mb-0"><?= htmlspecialchars($item['reporter_name'] ?? 'Anonymous') ?></h6>
                            <small class="text-muted">Reporter ID: #<?= $item['reported_by'] ?></small>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Similar Items Card -->
            <div class="card border-0 shadow-sm mt-4 fade-in">
                <div class="card-header bg-white border-0 pt-3">
                    <h5 class="mb-0"><i class="fas fa-clone" style="color: #FF6B35;"></i> Similar Items</h5>
                </div>
                <div class="card-body">
                    <?php
                    $stmt = $db->prepare("
                        SELECT * FROM items 
                        WHERE category = ? AND item_id != ? 
                        ORDER BY reported_date DESC LIMIT 3
                    ");
                    $stmt->execute([$item['category'], $id]);
                    $similar_items = $stmt->fetchAll();
                    ?>
                    
                    <?php if(empty($similar_items)): ?>
                        <p class="text-muted text-center mb-0">No similar items found.</p>
                    <?php else: ?>
                        <?php foreach($similar_items as $similar): ?>
                            <div class="d-flex align-items-center mb-3 pb-2 border-bottom">
                                <?php 
                                $hasSimImage = !empty($similar['image_url']) && imageFileExists($similar['image_url']);
                                $simImageUrl = $hasSimImage ? getImageUrl($similar['image_url'], $base_url) : '';
                                ?>
                                <div class="flex-shrink-0 me-3">
                                    <?php if($hasSimImage): ?>
                                        <img src="<?= $simImageUrl ?>" alt="Similar item" style="width: 60px; height: 60px; object-fit: cover; border-radius: 8px;">
                                    <?php else: ?>
                                        <div class="bg-light d-flex align-items-center justify-content-center" style="width: 60px; height: 60px; border-radius: 8px;">
                                            <i class="fas fa-box-open fa-2x" style="color: #FF6B35;"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="flex-grow-1">
                                    <h6 class="mb-1 small fw-bold"><?= htmlspecialchars(substr($similar['title'] ?? $similar['description'], 0, 40)) ?></h6>
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="badge <?= $similar['status'] == 'lost' ? 'bg-danger' : 'bg-success' ?>">
                                            <?= ucfirst($similar['status']) ?>
                                        </span>
                                        <small class="text-muted">
                                            <i class="fas fa-clock"></i> <?= timeAgo($similar['reported_date']) ?>
                                        </small>
                                    </div>
                                </div>
                                <a href="<?= $base_url ?>item-details.php?id=<?= $similar['item_id'] ?>" class="btn btn-sm btn-outline-primary ms-2">
                                    View
                                </a>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Right Column - Item Details -->
        <div class="col-lg-7 mb-4">
            <!-- Status Banner -->
            <div class="alert <?= $item['status'] == 'lost' ? 'alert-danger' : 'alert-success' ?> border-0 shadow-sm fade-in">
                <div class="d-flex align-items-center">
                    <i class="fas fa-<?= $item['status'] == 'lost' ? 'frown' : 'smile' ?> fa-2x me-3"></i>
                    <div>
                        <h5 class="mb-0">This item is reported as <strong><?= strtoupper($item['status']) ?></strong></h5>
                        <p class="mb-0 small"><?= $item['status'] == 'lost' ? 'Someone lost this item and is looking for it.' : 'This item has been found and is waiting to be claimed.' ?></p>
                    </div>
                </div>
            </div>
            
            <!-- Main Item Card -->
            <div class="card border-0 shadow-sm fade-in">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <h1 class="h2 mb-0" style="color: #E85D2C;"><?= htmlspecialchars($item['title'] ?? $item['description']) ?></h1>
                        <span class="badge <?= $item['status'] == 'lost' ? 'bg-danger' : 'bg-success' ?> p-2">
                            <i class="fas fa-<?= $item['status'] == 'lost' ? 'times-circle' : 'check-circle' ?> me-1"></i>
                            <?= strtoupper($item['status']) ?>
                        </span>
                    </div>
                    
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <div class="bg-light rounded p-3">
                                <small class="text-muted d-block"><i class="fas fa-tag me-1"></i> Category</small>
                                <strong><?= htmlspecialchars($item['category'] ?? 'Not specified') ?></strong>
                            </div>
                        </div>
                        
                        <!-- Brand -->
                        <div class="col-md-6">
                            <div class="bg-light rounded p-3">
                                <small class="text-muted d-block"><i class="fas fa-trademark me-1"></i> Brand</small>
                                <strong><?= htmlspecialchars($item['brand'] ?? 'Not specified') ?></strong>
                            </div>
                        </div>
                        
                        <!-- Color -->
                        <div class="col-md-6">
                            <div class="bg-light rounded p-3">
                                <small class="text-muted d-block"><i class="fas fa-palette me-1"></i> Color</small>
                                <strong><?= htmlspecialchars($item['color'] ?? 'Not specified') ?></strong>
                            </div>
                        </div>
                        
                        <!-- Location Found/Lost - ONLY VISIBLE TO ADMINISTRATORS -->
                        <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin' && !empty($item['found_location'])): ?>
                        <div class="col-md-6">
                            <div class="bg-light rounded p-3">
                                <small class="text-muted d-block"><i class="fas fa-map-marker-alt me-1"></i>
                                    <?= $item['status'] == 'lost' ? 'Location Lost' : 'Location Found' ?>
                                </small>
                                <strong><?= htmlspecialchars($item['found_location']) ?></strong>
                                <br><small class="text-muted">(Visible to administrators only)</small>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Keep At / Collection Point (Only for Found Items - visible to everyone) -->
                        <?php if ($item['status'] == 'found' && !empty($item['delivery_location'])): ?>
                        <div class="col-md-6">
                            <div class="bg-light rounded p-3">
                                <small class="text-muted d-block"><i class="fas fa-building me-1"></i> Keep At (Collection Point)</small>
                                <strong><?= htmlspecialchars($item['delivery_location']) ?></strong>
                                <br><small class="text-muted">Owner can collect from here</small>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Date Lost/Found -->
                        <div class="col-md-6">
                            <div class="bg-light rounded p-3">
                                <small class="text-muted d-block"><i class="fas fa-calendar me-1"></i> 
                                    <?= $item['status'] == 'lost' ? 'Date Lost' : 'Date Found' ?>
                                </small>
                                <strong>
                                    <?php 
                                    if (!empty($item['date_found'])) {
                                        echo formatDateOnly($item['date_found']);
                                    } else {
                                        echo 'Not specified';
                                    }
                                    ?>
                                </strong>
                                <?php if (!empty($item['date_found'])): ?>
                                    <br><small class="text-muted">(<?= timeAgo($item['date_found']) ?>)</small>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Time Lost/Found -->
                        <div class="col-md-6">
                            <div class="bg-light rounded p-3">
                                <small class="text-muted d-block"><i class="fas fa-clock me-1"></i> 
                                    <?= $item['status'] == 'lost' ? 'Time Lost' : 'Time Found' ?>
                                </small>
                                <strong>
                                    <?php 
                                    if (!empty($item['date_found'])) {
                                        echo formatTimeOnly($item['date_found']);
                                    } else {
                                        echo 'Not specified';
                                    }
                                    ?>
                                </strong>
                            </div>
                        </div>
                        
                        <!-- Date Reported -->
                        <div class="col-md-6">
                            <div class="bg-light rounded p-3">
                                <small class="text-muted d-block"><i class="fas fa-calendar-alt me-1"></i> Date Reported</small>
                                <strong><?= date('F d, Y', strtotime($item['reported_date'] ?? $item['created_at'])) ?></strong>
                                <br><small class="text-muted">(<?= timeAgo($item['reported_date'] ?? $item['created_at']) ?>)</small>
                            </div>
                        </div>
                        
                        <!-- Time Reported -->
                        <div class="col-md-6">
                            <div class="bg-light rounded p-3">
                                <small class="text-muted d-block"><i class="fas fa-clock me-1"></i> Time Reported</small>
                                <strong><?= date('h:i A', strtotime($item['reported_date'] ?? $item['created_at'])) ?></strong>
                            </div>
                        </div>
                        
                        <!-- Total Claims -->
                        <div class="col-md-6">
                            <div class="bg-light rounded p-3">
                                <small class="text-muted d-block"><i class="fas fa-hand-paper me-1"></i> Total Claims</small>
                                <strong><?= $claim_count ?> claim(s) submitted</strong>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Description Section -->
                    <div class="mb-4">
                        <h5 class="border-bottom pb-2" style="border-color: #FF6B35 !important;">
                            <i class="fas fa-align-left" style="color: #FF6B35;"></i> Detailed Description
                        </h5>
                        <div class="mt-3">
                            <?= nl2br(htmlspecialchars($item['description'] ?? 'No description provided')) ?>
                        </div>
                    </div>
                    
                    <!-- Action Buttons -->
                    <div class="action-buttons mt-4">
                        <?php if(isset($_SESSION['userID'])): ?>
                            <?php if($user_has_claimed): ?>
                                <div class="alert alert-warning mb-3">
                                    <i class="fas fa-clock me-2"></i>
                                    You have already submitted a claim for this item. Please wait for admin approval.
                                </div>
                                <a href="<?= $base_url ?>user/my-claims.php" class="btn btn-outline-primary w-100">
                                    <i class="fas fa-eye"></i> View My Claims
                                </a>
                            <?php elseif($item['reported_by'] == $_SESSION['userID']): ?>
                                <div class="alert alert-info mb-3">
                                    <i class="fas fa-info-circle me-2"></i>
                                    You reported this item. You cannot claim your own reported item.
                                </div>
                                <a href="<?= $base_url ?>user/my-report-item.php" class="btn btn-outline-primary w-100">
                                    <i class="fas fa-clipboard-list"></i> View My Reports
                                </a>
                            <?php else: ?>
                                <button onclick="openClaimModal(<?= $item['item_id'] ?>)" class="btn btn-primary btn-lg w-100 mb-2">
                                    <i class="fas fa-hand-paper"></i> Claim This Item
                                </button>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="alert alert-info mb-3">
                                <i class="fas fa-sign-in-alt me-2"></i>
                                Please login to claim this item.
                            </div>
                            <a href="<?= $base_url ?>login.php?redirect=item-details.php?id=<?= $item['item_id'] ?>" class="btn btn-primary btn-lg w-100">
                                <i class="fas fa-sign-in-alt"></i> Login to Claim
                            </a>
                        <?php endif; ?>
                        
                        <div class="row mt-3 g-2">
                            <div class="col-6">
                                <button onclick="window.location.href='<?= $base_url ?>search.php'" class="btn btn-outline-secondary w-100">
                                    <i class="fas fa-search"></i> Search More Items
                                </button>
                            </div>
                            <div class="col-6">
                                <button onclick="shareItem()" class="btn btn-outline-primary w-100">
                                    <i class="fas fa-share-alt"></i> Share Item
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Timeline Information Card -->
            <div class="card border-0 shadow-sm mt-4 fade-in">
                <div class="card-header bg-white border-0 pt-3">
                    <h5 class="mb-0"><i class="fas fa-info-circle" style="color: #FF6B35;"></i> Timeline Information</h5>
                </div>
                <div class="card-body">
                    <div class="timeline">
                        <div class="timeline-item">
                            <div class="timeline-icon bg-danger">
                                <i class="fas fa-calendar-alt"></i>
                            </div>
                            <div class="timeline-content">
                                <h6><?= $item['status'] == 'lost' ? 'Item Lost' : 'Item Found' ?></h6>
                                <p class="mb-0">
                                    <strong><?= formatDateTime($item['date_found']) ?></strong>
                                    <span class="text-muted">(<?= timeAgo($item['date_found']) ?>)</span>
                                </p>
                                <!-- Location in timeline - ONLY VISIBLE TO ADMINISTRATORS -->
                                <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin' && $item['status'] == 'lost' && !empty($item['found_location'])): ?>
                                    <small class="text-muted">Location: <?= htmlspecialchars($item['found_location']) ?></small>
                                <?php endif; ?>
                                <?php if ($item['status'] == 'found' && !empty($item['delivery_location'])): ?>
                                    <small class="text-muted">Keep at: <?= htmlspecialchars($item['delivery_location']) ?></small>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="timeline-item">
                            <div class="timeline-icon bg-success">
                                <i class="fas fa-flag-checkered"></i>
                            </div>
                            <div class="timeline-content">
                                <h6>Reported to System</h6>
                                <p class="mb-0">
                                    <strong><?= formatDateTime($item['reported_date'] ?? $item['created_at']) ?></strong>
                                    <span class="text-muted">(<?= timeAgo($item['reported_date'] ?? $item['created_at']) ?>)</span>
                                </p>
                                <small class="text-muted">Reported by: <?= htmlspecialchars($item['reporter_name'] ?? 'Anonymous') ?></small>
                            </div>
                        </div>
                        <?php if($claim_count > 0): ?>
                        <div class="timeline-item">
                            <div class="timeline-icon bg-warning">
                                <i class="fas fa-hand-paper"></i>
                            </div>
                            <div class="timeline-content">
                                <h6>Claims Submitted</h6>
                                <p class="mb-0">
                                    <strong><?= $claim_count ?> claim(s)</strong> have been submitted for this item
                                </p>
                                <small class="text-muted">Awaiting verification</small>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Timeline CSS -->
<style>
    .timeline {
        position: relative;
        padding-left: 30px;
    }
    
    .timeline::before {
        content: '';
        position: absolute;
        left: 15px;
        top: 0;
        bottom: 0;
        width: 2px;
        background: linear-gradient(to bottom, #FF6B35, #E85D2C, #ddd);
    }
    
    .timeline-item {
        position: relative;
        margin-bottom: 25px;
        display: flex;
        align-items: flex-start;
    }
    
    .timeline-item:last-child {
        margin-bottom: 0;
    }
    
    .timeline-icon {
        position: absolute;
        left: -30px;
        width: 36px;
        height: 36px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        z-index: 1;
    }
    
    .timeline-icon.bg-danger { background: #dc3545; }
    .timeline-icon.bg-success { background: #28a745; }
    .timeline-icon.bg-warning { background: #ffc107; color: #333; }
    .timeline-icon.bg-info { background: #17a2b8; }
    
    .timeline-content {
        flex: 1;
        padding-left: 20px;
        padding-bottom: 10px;
    }
    
    .timeline-content h6 {
        margin-bottom: 5px;
        font-weight: 700;
        color: #2C3E50;
    }
    
    .timeline-content p {
        margin-bottom: 5px;
    }
</style>

<!-- Claim Modal -->
<div class="modal fade" id="claimModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background: linear-gradient(135deg, #FF6B35, #E85D2C); color: white;">
                <h5 class="modal-title"><i class="fas fa-hand-paper"></i> Submit Claim Request</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form action="<?= $base_url ?>user/submit-claim.php" method="POST" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <div class="modal-body">
                    <input type="hidden" name="item_id" id="claim_item_id">
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> 
                        Please provide detailed information to verify your ownership of this item.
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label required-field">Why do you believe this is your item?</label>
                        <textarea name="claimant_description" class="form-control" rows="4" required 
                                  placeholder="Describe unique features, markings, contents, or any proof that this item belongs to you..."></textarea>
                        <small class="text-muted">Be as specific as possible. Include details that only the true owner would know.</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Proof of Ownership (Optional but recommended)</label>
                        <input type="file" name="proof_image" class="form-control" accept="image/*">
                        <small class="text-muted">Upload receipts, photos, or any document proving ownership</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Your Contact Number</label>
                        <input type="text" name="contact_number" class="form-control" placeholder="For verification purposes">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Submit Claim Request</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
    .required-field::after { content: '*'; color: red; margin-left: 4px; }
    .image-gallery { position: relative; overflow: hidden; border-radius: 10px 10px 0 0; }
    .fade-in { animation: fadeIn 0.5s ease-in; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
    .card { transition: transform 0.2s, box-shadow 0.2s; }
    .card:hover { box-shadow: 0 10px 30px rgba(0,0,0,0.1) !important; }
    .btn-primary { background: linear-gradient(135deg, #FF6B35, #E85D2C); border: none; transition: transform 0.2s; }
    .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(255, 107, 53, 0.3); }
    .bg-light { background-color: #f8f9fa !important; }
    .alert-info { background-color: #e3f2fd; border-color: #b3e5fc; color: #01579b; }
</style>

<script>
function openClaimModal(itemId) {
    document.getElementById('claim_item_id').value = itemId;
    var modal = new bootstrap.Modal(document.getElementById('claimModal'));
    modal.show();
}

function shareItem() {
    if (navigator.share) {
        navigator.share({
            title: 'Check out this item',
            text: 'Found an item that might be yours?',
            url: window.location.href
        }).catch(() => console.log('Sharing cancelled'));
    } else {
        navigator.clipboard.writeText(window.location.href);
        alert('Link copied to clipboard!');
    }
}
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>