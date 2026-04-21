<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
requireAdmin();

$db = Database::getInstance()->getConnection();
$message = '';
$error = '';

// Define categories matching report-item.php
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
    'Household' => '🏠 Household',
    'Others' => '📦 Others'
];

// Handle item actions (delete, update status, update category)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();

    $action = $_POST['action'] ?? '';
    $item_id = (int) ($_POST['item_id'] ?? 0);
    
    if ($action === 'delete') {
        // Delete item
        $stmt = $db->prepare("DELETE FROM items WHERE item_id = ?");
        if ($stmt->execute([$item_id])) {
            $message = "Item deleted successfully";
        } else {
            $error = "Failed to delete item";
        }
    } elseif ($action === 'update_status') {
        $new_status = $_POST['status'] ?? '';
        $valid_statuses = ['lost', 'found', 'returned', 'resolved'];
        
        if (in_array($new_status, $valid_statuses)) {
            $stmt = $db->prepare("UPDATE items SET status = ? WHERE item_id = ?");
            if ($stmt->execute([$new_status, $item_id])) {
                $message = "Item status updated successfully";
            } else {
                $error = "Failed to update item status";
            }
        } else {
            $error = "Invalid status";
        }
    } elseif ($action === 'update_category') {
        $new_category = $_POST['category'] ?? '';
        
        if (array_key_exists($new_category, $categories_list)) {
            $stmt = $db->prepare("UPDATE items SET category = ? WHERE item_id = ?");
            if ($stmt->execute([$new_category, $item_id])) {
                $message = "Item category updated successfully";
            } else {
                $error = "Failed to update item category";
            }
        } else {
            $error = "Invalid category";
        }
    }
}

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$category_filter = $_GET['category'] ?? 'all';
$search_query = $_GET['search'] ?? '';

// Build query for items
$sql = "
    SELECT i.*, u.name as reporter_name, u.email as reporter_email
    FROM items i
    JOIN users u ON i.reported_by = u.user_id
    WHERE 1=1
";
$params = [];

if ($status_filter !== 'all') {
    $sql .= " AND i.status = ?";
    $params[] = $status_filter;
}

if ($category_filter !== 'all' && $category_filter !== '') {
    $sql .= " AND i.category = ?";
    $params[] = $category_filter;
}

if (!empty($search_query)) {
    $sql .= " AND (i.title LIKE ? OR i.description LIKE ? OR i.category LIKE ? OR i.found_location LIKE ?)";
    $search_term = "%$search_query%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

$sql .= " ORDER BY i.reported_date DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll();

// Get statistics
$stats = [];
$stats['total'] = $db->query("SELECT COUNT(*) FROM items")->fetchColumn();
$stats['lost'] = $db->query("SELECT COUNT(*) FROM items WHERE status = 'lost'")->fetchColumn();
$stats['found'] = $db->query("SELECT COUNT(*) FROM items WHERE status = 'found'")->fetchColumn();
$stats['returned'] = $db->query("SELECT COUNT(*) FROM items WHERE status = 'returned'")->fetchColumn();
$stats['resolved'] = $db->query("SELECT COUNT(*) FROM items WHERE status = 'resolved'")->fetchColumn();

$base_url = '/reclaim-system/';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Items - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $base_url ?>assets/css/style.css">
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
        
        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 20px;
            text-align: center;
            transition: all 0.3s;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            border: none;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 30px rgba(0,0,0,0.12);
        }
        
        .stat-card i {
            font-size: 2rem;
            margin-bottom: 10px;
        }
        
        .stat-card h3 {
            font-size: 1.8rem;
            font-weight: 800;
            margin: 5px 0;
        }
        
        .stat-card p {
            color: #6c757d;
            margin: 0;
            font-weight: 500;
            font-size: 0.9rem;
        }
        
        .stat-card.lost i { color: #dc3545; }
        .stat-card.found i { color: #28a745; }
        .stat-card.returned i { color: #17a2b8; }
        .stat-card.total i { color: #FF6B35; }
        
        .card-modern {
            border: none;
            border-radius: 20px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            margin-bottom: 20px;
        }
        
        .card-modern .card-header {
            background: white;
            border-bottom: 2px solid #FF6B35;
            padding: 15px 20px;
            border-radius: 20px 20px 0 0;
            font-weight: 600;
        }
        
        .filter-bar {
            background: white;
            border-radius: 15px;
            padding: 15px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .table-custom {
            margin-bottom: 0;
        }
        
        .table-custom th {
            border-top: none;
            font-weight: 600;
            color: #2C3E50;
            background: #f8f9fa;
        }
        
        .btn-primary-custom {
            background: linear-gradient(135deg, #FF6B35, #E85D2C);
            border: none;
            padding: 8px 20px;
            border-radius: 50px;
            transition: all 0.3s;
            color: white;
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
        }
        
        .btn-secondary-custom:hover {
            background: #5a6268;
            color: white;
        }
        
        .status-badge {
            padding: 5px 12px;
            border-radius: 50px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        .status-lost { background: #dc3545; color: white; }
        .status-found { background: #28a745; color: white; }
        .status-returned { background: #17a2b8; color: white; }
        .status-resolved { background: #6c757d; color: white; }
        
        .category-badge {
            padding: 5px 12px;
            border-radius: 50px;
            font-size: 11px;
            font-weight: 500;
            display: inline-block;
            background: #f0f2f5;
            color: #2C3E50;
        }
        
        .item-image-thumb {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: 10px;
        }
        
        .item-image-placeholder {
            width: 50px;
            height: 50px;
            background: #e9ecef;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #adb5bd;
        }
        
        .action-dropdown .dropdown-toggle::after {
            display: none;
        }
        
        .action-btn {
            background: transparent;
            border: none;
            color: #6c757d;
            cursor: pointer;
            padding: 5px 10px;
            border-radius: 10px;
            transition: all 0.2s;
        }
        
        .action-btn:hover {
            background: #f8f9fa;
            color: #FF6B35;
        }
        
        /* Category colors */
        .cat-electronics { background: #e3f2fd; color: #1565c0; }
        .cat-documents { background: #e8eaf6; color: #3949ab; }
        .cat-accessories { background: #f3e5f5; color: #7b1fa2; }
        .cat-clothing { background: #fce4ec; color: #c2185b; }
        .cat-books { background: #fff3e0; color: #e65100; }
        .cat-wallet { background: #e0f2f1; color: #00695c; }
        .cat-keys { background: #fff8e1; color: #f57f17; }
        .cat-bag { background: #efebe9; color: #4e342e; }
        .cat-jewelry { background: #f9fbe7; color: #827717; }
        .cat-others { background: #eceff1; color: #546e7a; }
    </style>
</head>
<body class="app-page admin-page">
    <div class="container-fluid">
        <div class="row">
            <?php include 'sidebar.php'; ?>
            
            <div class="col-md-10 main-content content-wrapper">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2 class="fw-bold"><i class="fas fa-boxes me-2" style="color: #FF6B35;"></i> Manage Items</h2>
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
                
                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-md-3 mb-3">
                        <div class="stat-card total">
                            <i class="fas fa-box"></i>
                            <h3><?= number_format($stats['total']) ?></h3>
                            <p>Total Items</p>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="stat-card lost">
                            <i class="fas fa-frown"></i>
                            <h3><?= number_format($stats['lost']) ?></h3>
                            <p>Lost Items</p>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="stat-card found">
                            <i class="fas fa-smile"></i>
                            <h3><?= number_format($stats['found']) ?></h3>
                            <p>Found Items</p>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="stat-card returned">
                            <i class="fas fa-handshake"></i>
                            <h3><?= number_format($stats['returned'] + $stats['resolved']) ?></h3>
                            <p>Returned/Resolved</p>
                        </div>
                    </div>
                </div>
                
                <!-- Filter Bar -->
                <div class="filter-bar">
                    <form method="GET" action="" class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-bold small">Search</label>
                            <input type="text" name="search" class="form-control" placeholder="Search by title, description..." value="<?= htmlspecialchars($search_query) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold small">Status</label>
                            <select name="status" class="form-select">
                                <option value="all" <?= $status_filter == 'all' ? 'selected' : '' ?>>All Status</option>
                                <option value="lost" <?= $status_filter == 'lost' ? 'selected' : '' ?>>Lost</option>
                                <option value="found" <?= $status_filter == 'found' ? 'selected' : '' ?>>Found</option>
                                <option value="returned" <?= $status_filter == 'returned' ? 'selected' : '' ?>>Returned</option>
                                <option value="resolved" <?= $status_filter == 'resolved' ? 'selected' : '' ?>>Resolved</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold small">Category</label>
                            <select name="category" class="form-select">
                                <option value="all" <?= $category_filter == 'all' ? 'selected' : '' ?>>All Categories</option>
                                <?php foreach($categories_list as $key => $value): ?>
                                    <option value="<?= $key ?>" <?= $category_filter == $key ? 'selected' : '' ?>>
                                        <?= $value ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary-custom w-100 me-2">
                                <i class="fas fa-filter me-2"></i> Filter
                            </button>
                            <a href="manage-items.php" class="btn btn-secondary-custom w-100">
                                <i class="fas fa-redo me-2"></i> Reset
                            </a>
                        </div>
                    </form>
                </div>
                
                <!-- Items Table -->
                <div class="card-modern card">
                    <div class="card-header">
                        <i class="fas fa-list me-2" style="color: #FF6B35;"></i> All Items
                        <span class="badge bg-secondary ms-2"><?= count($items) ?> items</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover table-custom mb-0">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Image</th>
                                        <th>Title</th>
                                        <th>Category</th>
                                        <th>Location</th>
                                        <th>Reported By</th>
                                        <th>Status</th>
                                        <th>Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(empty($items)): ?>
                                        <tr>
                                            <td colspan="9" class="text-center py-5 text-muted">
                                                <i class="fas fa-box-open fa-3x mb-3 d-block"></i>
                                                No items found
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach($items as $item): ?>
                                            <?php
                                            // Get category display name with icon
                                            $category_display = $categories_list[$item['category']] ?? $item['category'] ?? '📦 N/A';
                                            // Get category class for styling
                                            $category_class = 'cat-' . strtolower($item['category'] ?? 'others');
                                            ?>
                                        <tr>
                                            <td>#<?= $item['item_id'] ?></td>
                                            <td>
                                                <?php if(!empty($item['image_url']) && file_exists('../' . $item['image_url'])): ?>
                                                    <img src="<?= $base_url . $item['image_url'] ?>" class="item-image-thumb" alt="Item image">
                                                <?php else: ?>
                                                    <div class="item-image-placeholder">
                                                        <i class="fas fa-box-open"></i>
                                                    </div>
                                                <?php endif; ?>
                                              </td>
                                            <td>
                                                <strong><?= htmlspecialchars($item['title'] ?? 'Untitled') ?></strong><br>
                                                <small class="text-muted"><?= htmlspecialchars(substr($item['description'] ?? '', 0, 50)) ?>...</small>
                                              </td>
                                            <td>
                                                <span class="category-badge <?= $category_class ?>">
                                                    <?= $category_display ?>
                                                </span>
                                              </td>
                                            <td><?= htmlspecialchars($item['found_location'] ?? $item['location'] ?? 'N/A') ?></td>
                                            <td><?= htmlspecialchars($item['reporter_name'] ?? 'Unknown') ?></td>
                                            <td>
                                                <span class="status-badge status-<?= $item['status'] ?>">
                                                    <?= ucfirst($item['status']) ?>
                                                </span>
                                              </td>
                                            <td><?= date('M d, Y', strtotime($item['reported_date'])) ?></td>
                                            <td>
                                                <div class="dropdown">
                                                    <button class="action-btn dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                                        <i class="fas fa-ellipsis-v"></i>
                                                    </button>
                                                    <ul class="dropdown-menu dropdown-menu-end">
                                                        <li>
                                                            <a class="dropdown-item" href="<?= $base_url ?>item-details.php?id=<?= $item['item_id'] ?>" target="_blank">
                                                                <i class="fas fa-eye me-2"></i> View Details
                                                            </a>
                                                        </li>
                                                        <li><hr class="dropdown-divider"></li>
                                                        <li>
                                                            <form method="POST" style="display: inline;">
                                                                <?= csrf_field() ?>
                                                                <input type="hidden" name="item_id" value="<?= $item['item_id'] ?>">
                                                                <input type="hidden" name="action" value="update_category">
                                                                <select name="category" class="dropdown-item" onchange="this.form.submit()" style="background: none; border: none; width: 100%;">
                                                                    <option value="" disabled selected>Change Category</option>
                                                                    <?php foreach($categories_list as $key => $value): ?>
                                                                        <option value="<?= $key ?>" <?= $item['category'] == $key ? 'disabled' : '' ?>>
                                                                            <?= $value ?>
                                                                        </option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            </form>
                                                        </li>
                                                        <li><hr class="dropdown-divider"></li>
                                                        <li>
                                                            <form method="POST" style="display: inline;">
                                                                <?= csrf_field() ?>
                                                                <input type="hidden" name="item_id" value="<?= $item['item_id'] ?>">
                                                                <input type="hidden" name="action" value="update_status">
                                                                <select name="status" class="dropdown-item" onchange="this.form.submit()" style="background: none; border: none; width: 100%;">
                                                                    <option value="" disabled selected>Change Status</option>
                                                                    <option value="lost" <?= $item['status'] == 'lost' ? 'disabled' : '' ?>>Lost</option>
                                                                    <option value="found" <?= $item['status'] == 'found' ? 'disabled' : '' ?>>Found</option>
                                                                    <option value="returned" <?= $item['status'] == 'returned' ? 'disabled' : '' ?>>Returned</option>
                                                                    <option value="resolved" <?= $item['status'] == 'resolved' ? 'disabled' : '' ?>>Resolved</option>
                                                                </select>
                                                            </form>
                                                        </li>
                                                        <li><hr class="dropdown-divider"></li>
                                                        <li>
                                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this item permanently? This action cannot be undone.')">
                                                                <?= csrf_field() ?>
                                                                <input type="hidden" name="item_id" value="<?= $item['item_id'] ?>">
                                                                <input type="hidden" name="action" value="delete">
                                                                <button type="submit" class="dropdown-item text-danger">
                                                                    <i class="fas fa-trash-alt me-2"></i> Delete
                                                                </button>
                                                            </form>
                                                        </li>
                                                    </ul>
                                                </div>
                                              </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
