<?php
require_once 'config/database.php';
require_once 'includes/header.php';
require_once 'includes/functions.php';

$db = Database::getInstance()->getConnection();
$search_results = [];
$search_query = '';
$total_results = 0;

// Get filter values
$search_query = $_GET['query'] ?? '';
$category = $_GET['category'] ?? '';
$status = $_GET['status'] ?? '';
$location = $_GET['location'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$item_title = $_GET['item_title'] ?? '';
$image_analysis_id = isset($_GET['image_analysis']) ? (int)$_GET['image_analysis'] : 0;

// Build the search query dynamically
$sql = "SELECT * FROM items WHERE 1=1";
$params = [];

// Handle image search results
if ($image_analysis_id > 0) {
    $stmt = $db->prepare("SELECT * FROM image_analysis WHERE analysis_id = ?");
    $stmt->execute([$image_analysis_id]);
    $analysis = $stmt->fetch();
    
    if ($analysis) {
        $labels = json_decode($analysis['labels'], true);
        if (is_array($labels) && !empty($labels)) {
            $search_query = implode(' ', $labels);
            // Build search conditions from detected labels
            $labelConditions = [];
            foreach ($labels as $label) {
                if (strlen($label) > 2) {
                    $labelConditions[] = "(title LIKE ? OR description LIKE ? OR category LIKE ? OR found_location LIKE ?)";
                    $search_term = "%$label%";
                    $params[] = $search_term;
                    $params[] = $search_term;
                    $params[] = $search_term;
                    $params[] = $search_term;
                }
            }
            if (!empty($labelConditions)) {
                $sql .= " AND (" . implode(' OR ', $labelConditions) . ")";
            }
        }
    }
}

// Regular text search
if (!empty($search_query) && $image_analysis_id == 0) {
    $sql .= " AND (title LIKE ? OR description LIKE ? OR category LIKE ? OR found_location LIKE ?)";
    $search_term = "%$search_query%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

if (!empty($item_title)) {
    $sql .= " AND title LIKE ?";
    $params[] = "%$item_title%";
}

if (!empty($category)) {
    $sql .= " AND category = ?";
    $params[] = $category;
}

if (!empty($status)) {
    $sql .= " AND status = ?";
    $params[] = $status;
}

if (!empty($location)) {
    $sql .= " AND (found_location LIKE ? OR location LIKE ?)";
    $params[] = "%$location%";
    $params[] = "%$location%";
}

if (!empty($date_from)) {
    $sql .= " AND DATE(date_found) >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $sql .= " AND DATE(date_found) <= ?";
    $params[] = $date_to;
}

$sql .= " ORDER BY reported_date DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$search_results = $stmt->fetchAll();
$total_results = count($search_results);

$has_filters = !empty($search_query) || !empty($category) || !empty($status) || !empty($location) || 
               !empty($date_from) || !empty($date_to) || !empty($item_title) || $image_analysis_id > 0;

// Log search only if there's a search query
if (isset($_SESSION['userID']) && !empty($search_query) && $image_analysis_id == 0) {
    $log_stmt = $db->prepare("INSERT INTO search_history (userID, search_term, results_count) VALUES (?, ?, ?)");
    $log_stmt->execute([$_SESSION['userID'], $search_query, $total_results]);
}

$base_url = '/reclaim-system/';

// Get categories for filter dropdown
$cat_stmt = $db->query("SELECT DISTINCT category FROM items WHERE category IS NOT NULL AND category != '' ORDER BY category");
$categories = $cat_stmt->fetchAll();

// Get locations for filter dropdown
$loc_stmt = $db->query("SELECT DISTINCT found_location FROM items WHERE found_location IS NOT NULL AND found_location != '' ORDER BY found_location LIMIT 20");
$locations = $loc_stmt->fetchAll();

// Get image analysis data if available
$image_analysis_data = null;
if ($image_analysis_id > 0) {
    $stmt = $db->prepare("SELECT * FROM image_analysis WHERE analysis_id = ?");
    $stmt->execute([$image_analysis_id]);
    $image_analysis_data = $stmt->fetch();
}
?>

<!-- Additional styles specific to search page -->
<style>
    .item-card {
        transition: transform 0.2s, box-shadow 0.2s;
    }
    .item-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    }
    .search-layout {
        row-gap: 24px;
    }
    .search-filters .card-body {
        max-height: calc(100vh - 140px);
        overflow-y: auto;
    }
    .search-filters .form-label {
        font-size: 0.78rem;
        letter-spacing: 0.04em;
        text-transform: uppercase;
    }
    .image-search-banner {
        margin-bottom: 20px;
    }
    .detected-labels {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 10px;
    }
    .detected-label {
        padding: 4px 12px;
    }
</style>

<main class="page-shell page-shell--compact">
<div class="container content-wrapper">
    <div class="row search-layout">
        <!-- Filters Sidebar -->
        <div class="col-lg-4 col-xl-3">
            <div class="card sticky-top search-filters" style="top: 20px; z-index: 100;">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="fas fa-filter"></i> Advanced Filters</h5>
                </div>
                <div class="card-body">
                    <form method="GET" action="<?= $base_url ?>search.php">
                        <!-- Main Search -->
                        <div class="mb-3">
                            <label class="form-label fw-bold">Keyword Search</label>
                            <input type="text" name="query" class="form-control" 
                                   placeholder="Search by title, description, category..."
                                   value="<?= htmlspecialchars($search_query) ?>">
                        </div>
                        
                        <hr>
                        
                        <!-- Item Title -->
                        <div class="mb-3">
                            <label class="form-label fw-bold">Item Title</label>
                            <input type="text" name="item_title" class="form-control" 
                                   placeholder="Specific item title..."
                                   value="<?= htmlspecialchars($item_title) ?>">
                        </div>
                        
                        <!-- Category -->
                        <div class="mb-3">
                            <label class="form-label fw-bold">Category</label>
                            <select name="category" class="form-select">
                                <option value="">All Categories</option>
                                <option value="Electronics" <?= $category == 'Electronics' ? 'selected' : '' ?>>📱 Electronics</option>
                                <option value="Documents" <?= $category == 'Documents' ? 'selected' : '' ?>>📄 Documents</option>
                                <option value="Accessories" <?= $category == 'Accessories' ? 'selected' : '' ?>>⌚ Accessories</option>
                                <option value="Clothing" <?= $category == 'Clothing' ? 'selected' : '' ?>>👕 Clothing</option>
                                <option value="Books" <?= $category == 'Books' ? 'selected' : '' ?>>📚 Books</option>
                                <option value="Wallet" <?= $category == 'Wallet' ? 'selected' : '' ?>>👛 Wallet/Purse</option>
                                <option value="Keys" <?= $category == 'Keys' ? 'selected' : '' ?>>🔑 Keys</option>
                                <option value="Bag" <?= $category == 'Bag' ? 'selected' : '' ?>>🎒 Bag/Backpack</option>
                                <option value="Jewelry" <?= $category == 'Jewelry' ? 'selected' : '' ?>>💍 Jewelry</option>
                                <option value="Others" <?= $category == 'Others' ? 'selected' : '' ?>>📦 Others</option>
                            </select>
                        </div>
                        
                        <!-- Status -->
                        <div class="mb-3">
                            <label class="form-label fw-bold">Status</label>
                            <select name="status" class="form-select">
                                <option value="">All (Lost & Found)</option>
                                <option value="lost" <?= $status == 'lost' ? 'selected' : '' ?>>❌ Lost</option>
                                <option value="found" <?= $status == 'found' ? 'selected' : '' ?>>✅ Found</option>
                            </select>
                        </div>
                        
                        <!-- Location -->
                        <div class="mb-3">
                            <label class="form-label fw-bold">Location</label>
                            <input type="text" name="location" class="form-control" 
                                   placeholder="e.g., Library, Cafeteria..."
                                   value="<?= htmlspecialchars($location) ?>">
                            <?php if(!empty($locations)): ?>
                                <small class="text-muted">Common: 
                                    <?php foreach(array_slice($locations, 0, 5) as $loc): ?>
                                        <span class="badge bg-light text-dark me-1"><?= htmlspecialchars($loc['found_location']) ?></span>
                                    <?php endforeach; ?>
                                </small>
                            <?php endif; ?>
                        </div>
                        
                        <hr>
                        
                        <!-- Date Range -->
                        <div class="mb-3">
                            <label class="form-label fw-bold">Date Range</label>
                            <div class="row">
                                <div class="col-6">
                                    <label class="small">From</label>
                                    <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($date_from) ?>">
                                </div>
                                <div class="col-6">
                                    <label class="small">To</label>
                                    <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($date_to) ?>">
                                </div>
                            </div>
                        </div>
                        
                        <hr>
                        
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search"></i> Apply Filters
                            </button>
                            <a href="<?= $base_url ?>search.php" class="btn btn-secondary">
                                <i class="fas fa-eraser"></i> Show All Items
                            </a>
                        </div>
                    </form>
                    
                    <hr>
                    
                    <!-- Image Search -->
                    <div class="text-center">
                        <p class="mb-2 fw-bold">Search by Image</p>
                        <p class="small text-muted">Upload a photo to find similar items</p>
                        <button onclick="document.getElementById('imageSearch').click()" class="btn btn-outline-primary w-100">
                            <i class="fas fa-camera"></i> Upload Image
                        </button>
                        <input type="file" id="imageSearch" accept="image/*" style="display: none;">
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Search Results -->
        <div class="col-lg-8 col-xl-9">
            <!-- Image Search Banner -->
            <?php if ($image_analysis_data): ?>
            <div class="image-search-banner">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <i class="fas fa-brain fa-2x me-3"></i>
                        <strong>AI Image Search Results</strong>
                    </div>
                    <a href="<?= $base_url ?>search.php" class="btn btn-sm btn-light">Clear Image Search</a>
                </div>
                <div class="detected-labels">
                    <span class="small">Detected:</span>
                    <?php 
                    $labels = json_decode($image_analysis_data['labels'], true);
                    if (is_array($labels)):
                        foreach($labels as $label): 
                    ?>
                        <span class="detected-label"><?= htmlspecialchars($label) ?></span>
                    <?php endforeach; endif; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="card">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-search"></i> Items List</h5>
                    <span class="badge bg-light text-dark"><?= $total_results ?> item(s) found</span>
                </div>
                <div class="card-body">
                    <!-- Active Filters Display -->
                    <?php if($has_filters): ?>
                        <?php 
                            $active_filters = array();
                            if(!empty($search_query)) $active_filters[] = "Keyword: " . htmlspecialchars($search_query);
                            if(!empty($item_title)) $active_filters[] = "Title: " . htmlspecialchars($item_title);
                            if(!empty($category)) $active_filters[] = "Category: " . htmlspecialchars($category);
                            if(!empty($status)) $active_filters[] = "Status: " . ucfirst($status);
                            if(!empty($location)) $active_filters[] = "Location: " . htmlspecialchars($location);
                            if(!empty($date_from)) $active_filters[] = "From: " . htmlspecialchars($date_from);
                            if(!empty($date_to)) $active_filters[] = "To: " . htmlspecialchars($date_to);
                            if($image_analysis_id > 0) $active_filters[] = "AI Image Search";
                        ?>
                        <?php if(!empty($active_filters)): ?>
                            <div class="mb-3">
                                <small class="text-muted">Active filters:</small>
                                <div class="mt-1">
                                    <?php foreach($active_filters as $filter): ?>
                                        <span class="badge bg-secondary me-1 mb-1"><?= $filter ?></span>
                                    <?php endforeach; ?>
                                    <a href="<?= $base_url ?>search.php" class="text-decoration-none ms-2">Clear all</a>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="alert alert-info mb-3">
                            <i class="fas fa-info-circle"></i> Showing all items. Use filters to narrow down your search.
                        </div>
                    <?php endif; ?>
                    
                    <?php if(empty($search_results)): ?>
                        <div class="alert alert-warning text-center py-5">
                            <i class="fas fa-box-open fa-3x mb-3 d-block"></i>
                            <h5>No items found</h5>
                            <p>There are no items in the database matching your criteria.</p>
                            <?php if(!$has_filters): ?>
                                <p>Start by reporting a lost or found item.</p>
                                <a href="<?= $base_url ?>user/report-item.php?type=lost" class="btn btn-danger me-2">Report Lost Item</a>
                                <a href="<?= $base_url ?>user/report-item.php?type=found" class="btn btn-success">Report Found Item</a>
                            <?php else: ?>
                                <a href="<?= $base_url ?>search.php" class="btn btn-primary">Show All Items</a>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="row">
                            <?php foreach($search_results as $item): ?>
                            <div class="col-md-6 mb-3">
                                <div class="card h-100 item-card">
                                    <div class="row g-0 h-100">
                                        <div class="col-md-4">
                                            <?php 
                                            $hasImage = !empty($item['image_url']) && imageFileExists($item['image_url']);
                                            $imageUrl = $hasImage ? getImageUrl($item['image_url'], $base_url) : '';
                                            ?>
                                            <?php if($hasImage): ?>
                                                <img src="<?= $imageUrl ?>" class="img-fluid rounded-start" style="height: 150px; width: 100%; object-fit: cover;">
                                            <?php else: ?>
                                                <div class="bg-light d-flex align-items-center justify-content-center" style="height: 150px;">
                                                    <i class="fas fa-box-open fa-3x" style="color: #f39c12;"></i>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="col-md-8">
                                            <div class="card-body">
                                                <h6 class="card-title fw-bold"><?= htmlspecialchars($item['title'] ?? $item['description']) ?></h6>
                                                <p class="card-text small">
                                                    <?= htmlspecialchars(substr($item['description'] ?? '', 0, 80)) ?>...
                                                </p>
                                                <p class="card-text">
                                                    <small class="text-muted d-block">
                                                        <i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($item['found_location'] ?? 'N/A') ?>
                                                    </small>
                                                    <small class="text-muted d-block">
                                                        <i class="fas fa-tag"></i> <?= htmlspecialchars($item['category'] ?? 'N/A') ?>
                                                    </small>
                                                    <?php if(!empty($item['date_found'])): ?>
                                                    <small class="text-muted d-block">
                                                        <i class="fas fa-calendar"></i> <?= date('M d, Y', strtotime($item['date_found'])) ?>
                                                    </small>
                                                    <?php endif; ?>
                                                </p>
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <span class="badge <?= ($item['status'] ?? '') == 'lost' ? 'bg-danger' : 'bg-success' ?>">
                                                        <?= ($item['status'] ?? '') == 'lost' ? '❌ Lost' : '✅ Found' ?>
                                                    </span>
                                                    <a href="<?= $base_url ?>item-details.php?id=<?= $item['item_id'] ?>" class="btn btn-sm btn-primary">
                                                        View Details <i class="fas fa-arrow-right"></i>
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
</main>

<script>
document.getElementById('imageSearch').addEventListener('change', function(e) {
    if(e.target.files.length > 0) {
        const formData = new FormData();
        formData.append('image', e.target.files[0]);
        formData.append('csrf_token', '<?= csrf_token() ?>'); // Security: protect upload endpoint from CSRF.
        
        const btn = document.querySelector('button[onclick*="imageSearch"]');
        const originalText = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Analyzing...';
        btn.disabled = true;
        
        fetch('<?= $base_url ?>api/search-by-image.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if(data.success) {
                window.location.href = '<?= $base_url ?>search.php?image_analysis=' + data.analysis_id;
            } else {
                alert('Image search failed: ' + (data.message || 'Please try again.'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Image search failed. Please try again.');
        })
        .finally(function() {
            btn.innerHTML = originalText;
            btn.disabled = false;
            document.getElementById('imageSearch').value = '';
        });
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>
