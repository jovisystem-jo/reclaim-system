<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
require_once __DIR__ . '/../includes/functions.php';

$db = Database::getInstance()->getConnection();
$userID = $_SESSION['userID'];

// Handle Update Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_item'])) {
    require_csrf_token();

    $item_id = $_POST['item_id'];
    $title = trim($_POST['title'] ?? '');
    $category = $_POST['category'] ?? '';
    $description = trim($_POST['description'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $date_occurred = $_POST['date_occurred'] ?? '';
    $status = $_POST['status'] ?? '';
    
    // Verify item belongs to user
    $stmt = $db->prepare("SELECT image_url FROM items WHERE item_id = ? AND reported_by = ?");
    $stmt->execute([$item_id, $userID]);
    $item = $stmt->fetch();
    
    if ($item) {
        // Handle image upload if new image is provided
        $image_url = $item['image_url'];
        
        if (isset($_FILES['edit_image']) && $_FILES['edit_image']['error'] !== UPLOAD_ERR_NO_FILE) {
            $uploadDir = __DIR__ . '/../assets/uploads/';
            // Security: validate image content and store with a random filename.
            $upload = secure_image_upload($_FILES['edit_image'], $uploadDir, 'assets/uploads');
            if ($upload['success'] && !empty($upload['path'])) {
                delete_uploaded_file_safely($item['image_url'], __DIR__ . '/../assets/uploads/');
                $image_url = $upload['path'];
            } elseif (!$upload['success']) {
                $_SESSION['error_message'] = $upload['message'];
                header('Location: ' . $_SERVER['PHP_SELF']);
                exit();
            }
        }
        
        // Update item
        $stmt = $db->prepare("
            UPDATE items 
            SET title = ?, category = ?, description = ?, found_location = ?, date_found = ?, status = ?, image_url = ?
            WHERE item_id = ? AND reported_by = ?
        ");
        
        if ($stmt->execute([$title, $category, $description, $location, $date_occurred, $status, $image_url, $item_id, $userID])) {
            $_SESSION['success_message'] = "Item updated successfully!";
        } else {
            $_SESSION['error_message'] = "Failed to update item.";
        }
    } else {
        $_SESSION['error_message'] = "You don't have permission to edit this item.";
    }
    
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$category_filter = $_GET['category'] ?? 'all';

// Build query for user's reported items
$sql = "
    SELECT i.*, 
           COUNT(DISTINCT c.claim_id) as claim_count,
           MAX(c.status) as claim_status
    FROM items i
    LEFT JOIN claim_requests c ON i.item_id = c.item_id
    WHERE i.reported_by = ?
";

$params = [$userID];

if ($status_filter !== 'all') {
    $sql .= " AND i.status = ?";
    $params[] = $status_filter;
}

if ($category_filter !== 'all') {
    $sql .= " AND i.category = ?";
    $params[] = $category_filter;
}

$sql .= " GROUP BY i.item_id ORDER BY i.reported_date DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$reported_items = $stmt->fetchAll();

// Get statistics
$stmt = $db->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'lost' THEN 1 ELSE 0 END) as lost_count,
        SUM(CASE WHEN status = 'found' THEN 1 ELSE 0 END) as found_count,
        SUM(CASE WHEN status = 'returned' THEN 1 ELSE 0 END) as returned_count
    FROM items 
    WHERE reported_by = ?
");
$stmt->execute([$userID]);
$stats = $stmt->fetch();

// Get unique categories for filter
$stmt = $db->prepare("SELECT DISTINCT category FROM items WHERE reported_by = ? AND category IS NOT NULL AND category != ''");
$stmt->execute([$userID]);
$categories = $stmt->fetchAll();

// Categories list for edit form
$categories_list = [
    'Electronics' => '📱 Electronics',
    'Documents' => '📄 Documents',
    'Accessories' => '⌚ Accessories',
    'Clothing' => '👕 Clothing',
    'Books' => '📚 Books',
    'Wallet' => '👛 Wallet/Purse',
    'Keys' => '🔑 Keys',
    'Bag' => '🎒 Bag/Backpack',
    'Jewelry' => '💍 Jewelry',
    'Others' => '📦 Others'
];

$base_url = '/reclaim-system/';

// Display success/error messages
$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);
if (!defined('RECLAIM_EMBEDDED_LAYOUT')) {
    define('RECLAIM_EMBEDDED_LAYOUT', true);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Reported Items - Reclaim System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $base_url ?>assets/css/style.css">
    <style>
        * { font-family: 'Inter', sans-serif; }
        .stats-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            transition: transform 0.3s;
            margin-bottom: 20px;
        }
        .stats-card:hover { transform: translateY(-3px); }
        .stats-card i { font-size: 36px; margin-bottom: 10px; }
        .stats-card h3 { font-size: 28px; margin: 10px 0; color: #333; }
        .stats-card p { color: #666; margin: 0; }
        .filter-bar {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .item-card { transition: transform 0.2s; margin-bottom: 20px; }
        .item-card:hover { transform: translateY(-5px); }
        .badge-lost { background-color: #dc3545; }
        .badge-found { background-color: #28a745; }
        .badge-returned { background-color: #17a2b8; }
        .status-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }
        .action-buttons {
            display: flex;
            gap: 5px;
            justify-content: flex-end;
        }
        .action-buttons .btn-sm {
            padding: 4px 8px;
            font-size: 12px;
        }
        .modal-header {
            background: linear-gradient(135deg, #FF6B35, #E85D2C);
            color: white;
        }
        .modal-header .btn-close {
            filter: brightness(0) invert(1);
        }
        .edit-image-preview {
            max-width: 150px;
            margin-top: 10px;
            border-radius: 10px;
        }
    </style>
</head>
<body class="app-page user-page">
    <?php include __DIR__ . '/../includes/header.php'; ?>
    
    <main class="page-shell page-shell--compact">
    <div class="container content-wrapper">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-clipboard-list"></i> My Reported Items</h2>
            <a href="<?= $base_url ?>user/report-item.php" class="btn btn-primary">
                <i class="fas fa-plus-circle"></i> Report New Item
            </a>
        </div>

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

        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stats-card">
                    <i class="fas fa-chart-line" style="color: #FF8C00;"></i>
                    <h3><?= $stats['total'] ?? 0 ?></h3>
                    <p>Total Items Reported</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <i class="fas fa-frown" style="color: #dc3545;"></i>
                    <h3><?= $stats['lost_count'] ?? 0 ?></h3>
                    <p>Lost Items</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <i class="fas fa-smile" style="color: #28a745;"></i>
                    <h3><?= $stats['found_count'] ?? 0 ?></h3>
                    <p>Found Items</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <i class="fas fa-check-circle" style="color: #17a2b8;"></i>
                    <h3><?= $stats['returned_count'] ?? 0 ?></h3>
                    <p>Returned Items</p>
                </div>
            </div>
        </div>

        <div class="filter-bar">
            <form method="GET" action="" class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Filter by Status</label>
                    <select name="status" class="form-control">
                        <option value="all" <?= $status_filter == 'all' ? 'selected' : '' ?>>All Status</option>
                        <option value="lost" <?= $status_filter == 'lost' ? 'selected' : '' ?>>Lost</option>
                        <option value="found" <?= $status_filter == 'found' ? 'selected' : '' ?>>Found</option>
                        <option value="returned" <?= $status_filter == 'returned' ? 'selected' : '' ?>>Returned</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Filter by Category</label>
                    <select name="category" class="form-control">
                        <option value="all" <?= $category_filter == 'all' ? 'selected' : '' ?>>All Categories</option>
                        <?php foreach($categories as $cat): ?>
                            <option value="<?= htmlspecialchars($cat['category']) ?>" <?= $category_filter == $cat['category'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat['category']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-filter"></i> Apply Filters
                    </button>
                    <a href="<?= $base_url ?>user/my-report-item.php" class="btn btn-secondary w-100 ms-2">
                        <i class="fas fa-redo"></i> Reset
                    </a>
                </div>
            </form>
        </div>

        <?php if(empty($reported_items)): ?>
            <div class="card">
                <div class="card-body text-center py-5">
                    <i class="fas fa-inbox fa-4x mb-3" style="color: #FF8C00;"></i>
                    <h5>No items reported yet</h5>
                    <p>You haven't reported any lost or found items.</p>
                    <a href="<?= $base_url ?>user/report-item.php" class="btn btn-primary">
                        <i class="fas fa-plus-circle"></i> Report Your First Item
                    </a>
                </div>
            </div>
        <?php else: ?>
            <div class="row">
                <?php foreach($reported_items as $item): ?>
                <div class="col-md-6 col-lg-4">
                    <div class="card item-card h-100">
                        <?php 
                        $hasImage = !empty($item['image_url']) && imageFileExists($item['image_url']);
                        $imageUrl = $hasImage ? getImageUrl($item['image_url'], $base_url) : '';
                        ?>
                        <?php if($hasImage): ?>
                            <img src="<?= $imageUrl ?>" class="card-img-top" alt="Item image" style="height: 180px; object-fit: cover;">
                        <?php else: ?>
                            <div class="card-img-top bg-light d-flex align-items-center justify-content-center" style="height: 180px;">
                                <i class="fas fa-box-open fa-4x" style="color: #FF8C00;"></i>
                            </div>
                        <?php endif; ?>
                        
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <h6 class="card-title mb-0"><?= htmlspecialchars(substr($item['title'] ?? $item['description'], 0, 60)) ?>...</h6>
                                <span class="status-badge badge-<?= $item['status'] ?>">
                                    <?= ucfirst($item['status']) ?>
                                </span>
                            </div>
                            
                            <p class="card-text small text-muted mb-2">
                                <i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($item['found_location'] ?? $item['location']) ?><br>
                                <i class="fas fa-tag"></i> <?= htmlspecialchars($item['category']) ?><br>
                                <i class="fas fa-calendar"></i> <?= date('M d, Y', strtotime($item['reported_date'])) ?>
                            </p>
                            
                            <?php if($item['claim_count'] > 0): ?>
                                <div class="alert alert-info alert-sm py-1 mb-2">
                                    <small><i class="fas fa-hand-paper"></i> <?= $item['claim_count'] ?> claim(s) received</small>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="card-footer bg-transparent">
                            <div class="action-buttons">
                                <button onclick="openEditModal(<?= htmlspecialchars(json_encode($item)) ?>)" class="btn btn-sm btn-warning">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <a href="<?= $base_url ?>item-details.php?id=<?= $item['item_id'] ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-eye"></i> View
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Edit Modal -->
    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i> Edit Item</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="update_item" value="1">
                    <input type="hidden" name="item_id" id="edit_item_id">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label required-field">Item Title</label>
                                <input type="text" name="title" id="edit_title" class="form-control" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label required-field">Category</label>
                                <select name="category" id="edit_category" class="form-control" required>
                                    <option value="">Select Category</option>
                                    <?php foreach($categories_list as $key => $value): ?>
                                        <option value="<?= $key ?>"><?= $value ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label required-field">Description</label>
                            <textarea name="description" id="edit_description" class="form-control" rows="3" required></textarea>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label required-field">Location</label>
                                <input type="text" name="location" id="edit_location" class="form-control" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label required-field">Date</label>
                                <input type="date" name="date_occurred" id="edit_date" class="form-control" required>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select name="status" id="edit_status" class="form-control">
                                <option value="lost">Lost</option>
                                <option value="found">Found</option>
                                <option value="returned">Returned</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Current Image</label>
                            <div id="edit_current_image"></div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Upload New Image (Optional)</label>
                            <input type="file" name="edit_image" id="edit_image" class="form-control" accept="image/*">
                            <div id="edit_image_preview" class="mt-2"></div>
                            <small class="text-muted">Leave empty to keep current image</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function openEditModal(item) {
            document.getElementById('edit_item_id').value = item.item_id;
            document.getElementById('edit_title').value = item.title || '';
            document.getElementById('edit_category').value = item.category || '';
            document.getElementById('edit_description').value = item.description || '';
            document.getElementById('edit_location').value = item.found_location || item.location || '';
            document.getElementById('edit_date').value = item.date_found || '';
            document.getElementById('edit_status').value = item.status || 'lost';
            
            // Display current image
            const currentImageDiv = document.getElementById('edit_current_image');
            if (item.image_url && item.image_url !== '') {
                const imageUrl = '<?= $base_url ?>' + item.image_url;
                currentImageDiv.innerHTML = `<img src="${imageUrl}" class="edit-image-preview" alt="Current image"><br><small class="text-muted">Current image</small>`;
            } else {
                currentImageDiv.innerHTML = '<p class="text-muted">No current image</p>';
            }
            
            // Clear preview
            document.getElementById('edit_image_preview').innerHTML = '';
            document.getElementById('edit_image').value = '';
            
            new bootstrap.Modal(document.getElementById('editModal')).show();
        }
        
        // Image preview for edit modal
        document.getElementById('edit_image').addEventListener('change', function(e) {
            const previewDiv = document.getElementById('edit_image_preview');
            if (e.target.files.length > 0) {
                const reader = new FileReader();
                reader.onload = function(event) {
                    previewDiv.innerHTML = `<img src="${event.target.result}" class="edit-image-preview" alt="New image preview"><br><small>New image preview</small>`;
                };
                reader.readAsDataURL(e.target.files[0]);
            } else {
                previewDiv.innerHTML = '';
            }
        });
        
        function markAsResolved(itemId) {
            if(confirm('Mark this item as resolved? This will update the status to returned.')) {
                fetch('<?= $base_url ?>api/mark-resolved.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({item_id: itemId})
                })
                .then(response => response.json())
                .then(data => {
                    if(data.success) {
                        location.reload();
                    } else {
                        alert('Failed to mark as resolved. Please try again.');
                    }
                });
            }
        }
    </script>

    </main>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
