<?php
require_once 'includes/security.php';
secureSessionStart();

// Check for account deletion message
$account_deleted_message = '';
if (isset($_SESSION['account_deleted'])) {
    $account_deleted_message = $_SESSION['account_deleted'];
    unset($_SESSION['account_deleted']);
}

// Check for delete error
$delete_error = '';
if (isset($_SESSION['delete_error'])) {
    $delete_error = $_SESSION['delete_error'];
    unset($_SESSION['delete_error']);
}

require_once 'config/database.php';
require_once 'includes/functions.php';

$db = Database::getInstance()->getConnection();

// Fetch recent items
$stmt = $db->prepare("SELECT * FROM items ORDER BY reported_date DESC LIMIT 6");
$stmt->execute();
$recent_items = $stmt->fetchAll();

// Fetch statistics
$stats = [];
$stats['total_lost'] = $db->query("SELECT COUNT(*) FROM items WHERE status = 'lost'")->fetchColumn();
$stats['total_found'] = $db->query("SELECT COUNT(*) FROM items WHERE status = 'found'")->fetchColumn();
$stats['total_claimed'] = $db->query("SELECT COUNT(*) FROM claim_requests WHERE status = 'approved'")->fetchColumn();
$stats['total_returned'] = $db->query("SELECT COUNT(*) FROM items WHERE status = 'returned'")->fetchColumn();

// Get recent claims for activity feed
$stmt = $db->prepare("
    SELECT c.*, i.title as item_title, u.name as claimant_name 
    FROM claim_requests c
    JOIN items i ON c.item_id = i.item_id
    JOIN users u ON c.claimant_id = u.user_id
    WHERE c.status = 'approved'
    ORDER BY c.created_at DESC LIMIT 5
");
$stmt->execute();
$recent_claims = $stmt->fetchAll();

$base_url = '/reclaim-system/';

// Include header AFTER all PHP processing (no output before this point)
require_once 'includes/header.php';
?>

<style>
    :root {
        --primary: #FF6B35;
        --primary-dark: #E85D2C;
        --primary-light: #FF8C5A;
        --secondary: #2C3E50;
        --success: #27AE60;
        --info: #3498DB;
        --warning: #F39C12;
        --danger: #E74C3C;
        --dark: #1A252F;
        --light: #F8F9FA;
        --gray: #6C757D;
    }

    .hero-section {
        position: relative;
        background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
        border-radius: 30px;
        padding: 60px 40px;
        margin-top: 20px;
        overflow: hidden;
        box-shadow: 0 20px 40px rgba(0,0,0,0.1);
    }

    .hero-section::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -30%;
        width: 80%;
        height: 200%;
        background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, rgba(255,255,255,0) 70%);
        transform: rotate(25deg);
        pointer-events: none;
    }

    .hero-section::after {
        content: '';
        position: absolute;
        bottom: -30%;
        left: -20%;
        width: 60%;
        height: 150%;
        background: radial-gradient(circle, rgba(255,255,255,0.08) 0%, rgba(255,255,255,0) 70%);
        transform: rotate(-15deg);
        pointer-events: none;
    }

    .hero-title {
        font-size: 3.5rem;
        font-weight: 800;
        margin-bottom: 20px;
        text-shadow: 2px 2px 4px rgba(0,0,0,0.1);
        position: relative;
        z-index: 1;
    }

    .hero-subtitle {
        font-size: 1.2rem;
        color: #fff;
        opacity: 0.95;
        margin-bottom: 30px;
        position: relative;
        z-index: 1;
    }

    .search-box-modern {
        position: relative;
        z-index: 1;
        background: white;
        border-radius: 60px;
        padding: 8px;
        box-shadow: 0 15px 35px rgba(0,0,0,0.2);
        transition: transform 0.3s, box-shadow 0.3s;
    }

    .search-box-modern:hover {
        transform: translateY(-2px);
        box-shadow: 0 20px 40px rgba(0,0,0,0.25);
    }

    .search-box-modern input {
        border: none;
        padding: 18px 25px;
        border-radius: 60px;
        font-size: 1rem;
        background: transparent;
        flex: 1;
    }

    .search-box-modern input:focus {
        outline: none;
        box-shadow: none;
    }

    .search-box-modern button {
        background: linear-gradient(135deg, var(--primary), var(--primary-dark));
        border: none;
        padding: 12px 35px;
        border-radius: 50px;
        font-weight: 600;
        transition: all 0.3s;
        color: white;
    }

    .search-box-modern button:hover {
        transform: scale(1.02);
        box-shadow: 0 5px 15px rgba(255,107,53,0.4);
    }

    .stat-card-modern {
        background: white;
        border-radius: 20px;
        padding: 25px;
        text-align: center;
        transition: all 0.3s;
        border: none;
        box-shadow: 0 5px 20px rgba(0,0,0,0.08);
        position: relative;
        overflow: hidden;
    }

    .stat-card-modern::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: linear-gradient(90deg, var(--primary), var(--primary-light));
    }

    .stat-card-modern:hover {
        transform: translateY(-5px);
        box-shadow: 0 15px 35px rgba(0,0,0,0.12);
    }

    .stat-icon {
        width: 70px;
        height: 70px;
        background: linear-gradient(135deg, rgba(255,107,53,0.1), rgba(255,107,53,0.05));
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 15px;
    }

    .stat-icon i { font-size: 32px; color: var(--primary); }
    .stat-icon-lost i { color: #dc3545; }
    .stat-icon-found i { color: #27ae60; }
    .stat-icon-claimed i { color: #3498db; }
    .stat-icon-returned i { color: #27ae60; }
    .stat-number { font-size: 2.5rem; font-weight: 800; color: var(--dark); margin: 10px 0 5px; }
    .stat-label { color: var(--gray); font-weight: 500; margin: 0; }

    .section-header { text-align: center; margin-bottom: 50px; }
    .section-badge {
        display: inline-block;
        background: linear-gradient(135deg, rgba(255,107,53,0.1), rgba(255,107,53,0.05));
        color: var(--primary);
        padding: 8px 20px;
        border-radius: 50px;
        font-size: 0.9rem;
        font-weight: 600;
        margin-bottom: 15px;
    }
    .section-title { font-size: 2.5rem; font-weight: 800; color: var(--dark); margin-bottom: 15px; }
    .section-subtitle { color: var(--gray); font-size: 1.1rem; max-width: 600px; margin: 0 auto; }

    /* Item Card Styles */
    .item-card {
        transition: transform 0.2s, box-shadow 0.2s;
        border-radius: 12px;
        overflow: hidden;
    }
    .item-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 25px rgba(0,0,0,0.1);
    }
    .item-card-image {
        height: 180px;
        width: 100%;
        object-fit: cover;
    }
    .item-card-placeholder {
        height: 180px;
        display: flex;
        align-items: center;
        justify-content: center;
        background-color: #f8f9fa;
    }
    .item-card .card-body {
        padding: 15px;
    }
    /* Fix for badge alignment */
    .item-card .d-flex {
        display: flex !important;
        justify-content: space-between !important;
        align-items: flex-start !important;
        width: 100%;
    }
    .item-card .card-title {
        flex: 1;
        word-break: break-word;
        padding-right: 10px;
        margin-bottom: 0;
        font-size: 0.9rem;
        font-weight: 800;
        line-height: 1.4;
    }
    .status-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    min-width: 35px;
    padding: 1px 3px;
    border-radius: 8px;
    white-space: nowrap;
    text-align: center;
    font-size: 0.75rem !important;
    font-weight: 500;
    line-height: 1.2;
    color: white;
    }

    .badge-lost { background-color: #dc3545; }
    .badge-found { background-color: #28a745; }
    .badge-returned { background-color: #17a2b8; }
    
    .item-meta {
        margin-top: 10px;
    }
    .item-meta-row span {
    font-size: 12px;
    color: #6c757d;
    font-weight: 700;
    }
    .item-meta-row i {
        width: 18px;
        font-size: 11px;
        color: #FF8C00;
    }
    .item-meta-row span {
        font-size: 12px;
        color: #6c757d;
    }
    .card-footer {
        background-color: transparent;
        border-top: 1px solid #e9ecef;
        padding: 12px 15px;
    }
    .card-footer .btn {
        width: 100%;
        font-size: 12px;
        padding: 6px 12px;
    }

    .step-card {
        text-align: center;
        padding: 30px;
        background: white;
        border-radius: 20px;
        transition: all 0.3s;
        box-shadow: 0 5px 20px rgba(0,0,0,0.05);
        height: 100%;
    }

    .step-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 15px 35px rgba(0,0,0,0.1);
    }

    .step-number {
        width: 60px;
        height: 60px;
        background: linear-gradient(135deg, var(--primary), var(--primary-dark));
        color: white;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
        font-weight: 700;
        margin: 0 auto 20px;
    }

    .step-icon { font-size: 48px; color: var(--primary); margin-bottom: 20px; }
    .step-title { font-size: 1.3rem; font-weight: 700; margin-bottom: 10px; color: var(--dark); }
    .step-desc { color: var(--gray); font-size: 0.9rem; }

    .activity-feed {
        background: white;
        border-radius: 20px;
        padding: 25px;
        box-shadow: 0 5px 20px rgba(0,0,0,0.08);
    }

    .activity-item {
        display: flex;
        align-items: center;
        padding: 15px 0;
        border-bottom: 1px solid #e9ecef;
        transition: all 0.3s;
    }

    .activity-item:last-child { border-bottom: none; }
    .activity-item:hover { transform: translateX(5px); }

    .activity-icon {
        width: 45px;
        height: 45px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-right: 15px;
        flex-shrink: 0;
    }

    .activity-icon.success { background: rgba(39,174,96,0.1); color: var(--success); }
    .activity-icon.warning { background: rgba(243,156,18,0.1); color: var(--warning); }

    .activity-content { flex: 1; }
    .activity-text { font-weight: 500; margin-bottom: 3px; }
    .activity-time { font-size: 0.75rem; color: var(--gray); }

    .cta-section {
        background: linear-gradient(135deg, var(--secondary), var(--dark));
        border-radius: 30px;
        padding: 60px 40px;
        text-align: center;
        position: relative;
        overflow: hidden;
    }

    .cta-section::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -20%;
        width: 60%;
        height: 200%;
        background: radial-gradient(circle, rgba(255,255,255,0.05) 0%, rgba(255,255,255,0) 70%);
        transform: rotate(25deg);
    }

    .cta-title { font-size: 2rem; font-weight: 800; margin-bottom: 15px; }

    .cta-section,
    .cta-section h3,
    .cta-section p,
    .cta-section small,
    .cta-section i {
        color: #fff;
    }

    .cta-buttons .btn {
        margin: 0 10px;
        padding: 12px 30px;
        border-radius: 50px;
        font-weight: 600;
        transition: all 0.3s;
    }

    .cta-buttons .btn-primary {
        background: var(--primary);
        border: none;
    }

    .cta-buttons .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 25px rgba(255,107,53,0.4);
        background: var(--primary-dark);
    }

    .cta-buttons .btn-outline-light:hover { transform: translateY(-2px); }

    @keyframes fadeInUp {
        from { opacity: 0; transform: translateY(30px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .fade-in-up { animation: fadeInUp 0.6s ease-out; }

    @media (max-width: 768px) {
        .hero-title { font-size: 2rem; }
        .section-title { font-size: 1.8rem; }
        .stat-number { font-size: 1.8rem; }
        .cta-buttons .btn { margin: 10px; display: block; }
        .hero-section { padding: 40px 25px; }
        .search-box-modern { flex-direction: column; border-radius: 30px; }
        .search-box-modern input { width: 100%; text-align: center; }
        .search-box-modern button { width: 100%; margin-top: 10px; }
    }
</style>

<?php if($account_deleted_message): ?>
    <div class="alert alert-success alert-dismissible fade show m-3" role="alert">
        <i class="fas fa-check-circle me-2"></i> <?= htmlspecialchars($account_deleted_message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if($delete_error): ?>
    <div class="alert alert-danger alert-dismissible fade show m-3" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i> <?= htmlspecialchars($delete_error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="container content-wrapper">
    <div class="hero-section text-white fade-in-up">
        <div class="row align-items-center">
            <div class="col-lg-7">
                <h1 class="hero-title">Lost Something?<br>We'll Help You Find It</h1>
                <p class="hero-subtitle">Our smart lost and found system connects people with their missing belongings. Report lost items, search found items, or help someone reunite with their valuables.</p>
                
                <div class="search-box-modern d-flex">
                    <form action="search.php" method="GET" class="d-flex w-100">
                        <input type="text" name="query" class="flex-grow-1" placeholder="Search for lost or found items... (e.g., 'laptop', 'wallet', 'ID card')" required>
                        <button type="submit">
                            <i class="fas fa-search me-2"></i> Search
                        </button>
                    </form>
                </div>
                
               
            </div>
            <div class="col-lg-5 d-none d-lg-block">
                <div class="text-center">
                    <i class="fas fa-hand-holding-heart" style="font-size: 200px; opacity: 0.2;"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row mt-5 g-4">
        <div class="col-md-3 col-sm-6">
            <div class="stat-card-modern fade-in-up" style="animation-delay: 0.1s">
                <div class="stat-icon stat-icon-lost"><i class="fas fa-frown"></i></div>
                <div class="stat-number"><?= number_format($stats['total_lost']) ?></div>
                <p class="stat-label">Items Reported Lost</p>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="stat-card-modern fade-in-up" style="animation-delay: 0.2s">
                <div class="stat-icon stat-icon-found"><i class="fas fa-smile"></i></div>
                <div class="stat-number"><?= number_format($stats['total_found']) ?></div>
                <p class="stat-label">Items Found</p>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="stat-card-modern fade-in-up" style="animation-delay: 0.3s">
                <div class="stat-icon stat-icon-claimed"><i class="fas fa-hand-paper"></i></div>
                <div class="stat-number"><?= number_format($stats['total_claimed']) ?></div>
                <p class="stat-label">Claims Processed</p>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="stat-card-modern fade-in-up" style="animation-delay: 0.4s">
                <div class="stat-icon stat-icon-returned"><i class="fas fa-check-circle"></i></div>
                <div class="stat-number"><?= number_format($stats['total_returned']) ?></div>
                <p class="stat-label">Successfully Returned</p>
            </div>
        </div>
    </div>
    
    <div class="mt-5">
        <div class="section-header">
            <div class="section-badge"><i class="fas fa-clock me-2"></i> Latest Updates</div>
            <h2 class="section-title">Recently Reported Items</h2>
            <p class="section-subtitle">Check out the most recent lost and found items reported by our community</p>
        </div>
        
        <div class="row g-4">
            <?php if(empty($recent_items)): ?>
                <div class="col-12">
                    <div class="text-center py-5">
                        <i class="fas fa-box-open fa-4x mb-3" style="color: var(--primary); opacity: 0.5;"></i>
                        <h5>No items reported yet</h5>
                        <p class="text-muted">Be the first to report a lost or found item!</p>
                        <a href="<?= $base_url ?>user/report-item.php?type=lost" class="btn btn-primary me-2">Report Lost Item</a>
                        <a href="<?= $base_url ?>user/report-item.php?type=found" class="btn btn-success">Report Found Item</a>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach($recent_items as $item): ?>
                    <div class="col-md-6 col-xl-4 fade-in-up">
                        <div class="card item-card h-100">
                            <?php
                            $hasImage = !empty($item['image_url']) && imageFileExists($item['image_url']);
                            $imageUrl = $hasImage ? getImageUrl($item['image_url'], $base_url) : '';
                            ?>
                            <?php if($hasImage): ?>
                                <img src="<?= $imageUrl ?>" class="item-card-image" alt="Item image">
                            <?php else: ?>
                                <div class="item-card-placeholder">
                                    <i class="fas fa-box-open fa-4x" style="color: #FF8C00;"></i>
                                </div>
                            <?php endif; ?>
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <h6 class="card-title"><?= htmlspecialchars(substr($item['title'] ?? $item['description'], 0, 60)) ?>...</h6>
                                    <span class="status-badge <?= ($item['status'] ?? 'found') == 'lost' ? 'badge-lost' : (($item['status'] ?? 'found') == 'returned' ? 'badge-returned' : 'badge-found') ?>">
                                        <?= ucfirst($item['status'] ?? 'found') ?>
                                    </span>
                                </div>
                                <div class="item-meta">
                                    <div class="item-meta-row">
                                        <span><?= htmlspecialchars($item['found_location'] ?? $item['location'] ?? 'Not specified') ?></span>
                                    </div>
                                    <div class="item-meta-row">
                                        <i class="fas fa-tag"></i>
                                        <span><?= htmlspecialchars($item['category'] ?? 'N/A') ?></span>
                                    </div>
                                    <div class="item-meta-row">
                                        <i class="fas fa-calendar"></i>
                                        <span><?= date('M d, Y', strtotime($item['reported_date'])) ?></span>
                                    </div>
                                </div>
                            </div>
                            <div class="card-footer">
                                <a href="<?= $base_url ?>item-details.php?id=<?= $item['item_id'] ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-eye me-1"></i> View Details
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <?php if(!empty($recent_items) && count($recent_items) >= 6): ?>
            <div class="text-center mt-4">
                <a href="<?= $base_url ?>search.php" class="btn btn-outline-primary btn-lg">
                    View All Items <i class="fas fa-arrow-right ms-2"></i>
                </a>
            </div>
        <?php endif; ?>
    </div>
    
    <div class="mt-5 pt-4">
        <div class="section-header">
            <div class="section-badge"><i class="fas fa-question-circle me-2"></i> How It Works</div>
            <h2 class="section-title">Simple 4-Step Process</h2>
            <p class="section-subtitle">Getting your lost items back or helping others find theirs is easy with Reclaim</p>
        </div>
        
        <div class="row g-4">
            <div class="col-md-3">
                <div class="step-card">
                    <div class="step-number">1</div>
                    <i class="fas fa-flag-checkered step-icon"></i>
                    <h5 class="step-title">Report Item</h5>
                    <p class="step-desc">Report lost or found items with details, location, and photos to help with identification</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="step-card">
                    <div class="step-number">2</div>
                    <i class="fas fa-search step-icon"></i>
                    <h5 class="step-title">Search & Match</h5>
                    <p class="step-desc">Use our smart search by keywords, categories, or even upload an image to find matches</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="step-card">
                    <div class="step-number">3</div>
                    <i class="fas fa-file-signature step-icon"></i>
                    <h5 class="step-title">Submit Claim</h5>
                    <p class="step-desc">Found a match? Submit a claim request with proof of ownership for verification</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="step-card">
                    <div class="step-number">4</div>
                    <i class="fas fa-handshake step-icon"></i>
                    <h5 class="step-title">Reclaim Item</h5>
                    <p class="step-desc">Get verified and reclaim your item from the designated collection point</p>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row mt-5 g-4">
        <div class="col-lg-6">
            <div class="activity-feed">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="mb-0 fw-bold">
                        <i class="fas fa-history me-2" style="color: var(--primary);"></i> Recent Success Stories
                    </h5>
                    <span class="badge bg-success">Happy Returns</span>
                </div>
                
                <?php if(empty($recent_claims)): ?>
                    <div class="text-center py-4">
                        <i class="fas fa-trophy fa-3x mb-2" style="color: var(--primary); opacity: 0.3;"></i>
                        <p class="text-muted mb-0">No successful reclaims yet. Be the first!</p>
                    </div>
                <?php else: ?>
                    <?php foreach($recent_claims as $claim): ?>
                        <div class="activity-item">
                            <div class="activity-icon success">
                                <i class="fas fa-check-circle"></i>
                            </div>
                            <div class="activity-content">
                                <div class="activity-text">
                                    <strong><?= htmlspecialchars($claim['claimant_name']) ?></strong> successfully claimed 
                                    <strong>"<?= htmlspecialchars(substr($claim['item_title'], 0, 40)) ?>"</strong>
                                </div>
                                <div class="activity-time">
                                    <i class="fas fa-calendar-alt me-1"></i> <?= date('M d, Y', strtotime($claim['created_at'])) ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                
                <div class="mt-3 pt-2 text-center">
                    <a href="<?= $base_url ?>search.php" class="text-decoration-none">
                        View all items <i class="fas fa-arrow-right ms-1"></i>
                    </a>
                </div>
            </div>
        </div>
        
        <div class="col-lg-6">
            <div class="cta-section text-white">
                <div class="position-relative" style="z-index: 1;">
                    <i class="fas fa-hand-holding-heart fa-3x mb-3"></i>
                    <h3 class="cta-title">Ready to Report an Item?</h3>
                    <p class="mb-4 opacity-75">Whether you've lost something or found something, our community is here to help.</p>
                    <div class="cta-buttons">
                        <a href="<?= $base_url ?>user/report-item.php?type=lost" class="btn btn-primary">
                            <i class="fas fa-frown me-2"></i> I Lost an Item
                        </a>
                        <a href="<?= $base_url ?>user/report-item.php?type=found" class="btn btn-outline-light">
                            <i class="fas fa-smile me-2"></i> I Found an Item
                        </a>
                    </div>
                    <?php if(!isset($_SESSION['userID'])): ?>
                        <div class="mt-4">
                            <small class="opacity-75">
                                <i class="fas fa-user-plus me-1"></i> 
                                <a href="<?= $base_url ?>register.php" class="text-white">Create an account</a> to track your reports
                            </small>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row mt-5 mb-4">
        <div class="col-12">
            <div class="text-center">
                <p class="text-muted mb-3">
                    <i class="fas fa-shield-alt me-2" style="color: var(--primary);"></i> Trusted by students and staff
                </p>
                <div class="d-flex justify-content-center gap-4 flex-wrap">
                    <span class="text-muted"><i class="fas fa-check-circle text-success me-1"></i> 100% Free</span>
                    <span class="text-muted"><i class="fas fa-lock text-success me-1"></i> Secure & Private</span>
                    <span class="text-muted"><i class="fas fa-clock text-success me-1"></i> 24/7 Access</span>
                    <span class="text-muted"><i class="fas fa-headset text-success me-1"></i> Support Available</span>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function claimItem(itemId) {
    if(confirm('Do you want to claim this item? You will need to provide proof of ownership.')) {
        window.location.href = '<?= $base_url ?>user/submit-claim.php?item_id=' + itemId;
    }
}

document.getElementById('imageUpload').addEventListener('change', function(e) {
    if(e.target.files.length > 0) {
        const formData = new FormData();
        formData.append('image', e.target.files[0]);
        formData.append('csrf_token', '<?= csrf_token() ?>');
        
        const btn = document.querySelector('.search-box-modern button');
        const originalText = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
        btn.disabled = true;
        
        fetch('<?= $base_url ?>api/search-by-image.php', {
            method: 'POST',
            body: formData
        })
        .then(async response => {
            const text = await response.text();
            let data;

            try {
                data = JSON.parse(text);
            } catch (error) {
                throw new Error(text || 'Unexpected server response.');
            }

            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Please try again.');
            }

            return data;
        })
        .then(data => {
            window.location.href = '<?= $base_url ?>search.php?image_analysis=' + data.analysis_id;
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Image search failed: ' + (error.message || 'Please try again.'));
        })
        .finally(() => {
            btn.innerHTML = originalText;
            btn.disabled = false;
            document.getElementById('imageUpload').value = '';
        });
    }
});

setTimeout(function() {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(function(alert) {
        setTimeout(function() {
            const bsAlert = new bootstrap.Alert(alert);
            if(bsAlert) bsAlert.close();
        }, 5000);
    });
}, 3000);
</script>

<?php require_once 'includes/footer.php'; ?>
