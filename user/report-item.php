<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
require_once __DIR__ . '/../includes/notification.php';

// Determine report type from URL parameter
$type = isset($_GET['type']) && $_GET['type'] === 'found' ? 'found' : 'lost';

$db = Database::getInstance()->getConnection();
$notification = new NotificationSystem();
$error = '';
$success = '';

$base_url = '/reclaim-system/';

// Create uploads directory if not exists
$uploadDir = __DIR__ . '/../assets/uploads/';
if (!file_exists($uploadDir)) {
    // Security: avoid world-writable upload directories.
    mkdir($uploadDir, 0755, true);
}

// Delivery locations array
$delivery_locations = [
    'I keep it myself',
    'Security Office - Main Gate',
    'Security Office - North Gate',
    'Auxiliary Police and Security Office',
    'Other (Please specify)'
];

// Category-specific brands
$brands_by_category = [
    'Electronics' => [
        'Apple', 'Samsung', 'Sony', 'LG', 'Dell', 'HP', 'Lenovo', 'Asus', 'Acer', 'Microsoft',
        'Panasonic', 'Toshiba', 'Philips', 'Bose', 'JBL', 'Beats', 'Xiaomi', 'OnePlus', 'Google', 'Nintendo', 'Other'
    ],
    'Documents' => [
        'Passport', 'Driver License', 'Student ID', 'Work ID', 'Birth Certificate', 'Marriage Certificate',
        'Diploma', 'Degree', 'Transcript', 'Visa', 'Insurance Card', 'Bank Card', 'Credit Card', 'Other'
    ],
    'Accessories' => [
        'Apple', 'Samsung', 'Sony', 'Bose', 'JBL', 'Beats', 'Logitech', 'Razer', 'Corsair', 'SteelSeries',
        'Fossil', 'Casio', 'Garmin', 'Fitbit', 'Xiaomi', 'Anker', 'Belkin', 'Spigen', 'OtterBox', 'Other'
    ],
    'Clothing' => [
        'Nike', 'Adidas', 'Puma', 'Under Armour', 'H&M', 'Zara', 'Uniqlo', 'Levi\'s', 'Calvin Klein',
        'Tommy Hilfiger', 'Lacoste', 'Ralph Lauren', 'Gucci', 'Louis Vuitton', 'Versace', 'Other'
    ],
    'Books' => [
        'Oxford', 'Cambridge', 'Pearson', 'McGraw-Hill', 'Wiley', 'Penguin', 'HarperCollins', 'Simon & Schuster',
        'Random House', 'Scholastic', 'Elsevier', 'Springer', 'Taylor & Francis', 'Other'
    ],
    'Wallet' => [
        'Gucci', 'Louis Vuitton', 'Prada', 'Hermès', 'Coach', 'Michael Kors', 'Fossil', 'Calvin Klein',
        'Tommy Hilfiger', 'Polo Ralph Lauren', 'Secrid', 'Bellroy', 'Other'
    ],
    'Keys' => [
        'Toyota', 'Honda', 'BMW', 'Mercedes', 'Audi', 'Volkswagen', 'Ford', 'Hyundai', 'Kia', 'Mazda',
        'Nissan', 'Subaru', 'Volvo', 'Lexus', 'Tesla', 'Yale', 'Schlage', 'Master Lock', 'Other'
    ],
    'Bag' => [
        'Nike', 'Adidas', 'Puma', 'North Face', 'JanSport', 'Samsonite', 'Herschel', 'Fjällräven',
        'Gucci', 'Louis Vuitton', 'Prada', 'Coach', 'Michael Kors', 'Tumi', 'Other'
    ],
    'Jewelry' => [
        'Tiffany & Co.', 'Cartier', 'Pandora', 'Swarovski', 'David Yurman', 'Bvlgari', 'Van Cleef & Arpels',
        'Chanel', 'Dior', 'Gucci', 'Rolex', 'Omega', 'Seiko', 'Citizen', 'Casio', 'Other'
    ],
    'Household' => [
        'Other'
    ],
    'Others' => [
        'Generic', 'No Brand', 'Custom Made', 'Handmade', 'Vintage', 'Limited Edition', 'Other'
    ]
];

// Common colors
$common_colors = [
    'Black', 'White', 'Red', 'Blue', 'Green', 'Yellow', 'Purple', 'Pink',
    'Orange', 'Brown', 'Grey', 'Silver', 'Gold', 'Navy', 'Beige', 'Multicolor',
    'Other'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();

    $title = trim($_POST['title'] ?? '');
    $category = $_POST['category'] ?? '';
    $brand = $_POST['brand'] ?? '';
    $brand_other = trim($_POST['brand_other'] ?? '');
    $color = $_POST['color'] ?? '';
    $color_other = trim($_POST['color_other'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $location_found = trim($_POST['location'] ?? '');
    $date_occurred = $_POST['date_occurred'] ?? '';
    $time_occurred = $_POST['time_occurred'] ?? '';
    $delivery_option = $_POST['delivery_option'] ?? '';
    $delivery_location_other = trim($_POST['delivery_location_other'] ?? '');
    $status = $_POST['status'] ?? 'lost';
    
    // Process brand (handle "Other" option)
    if ($brand === 'Other' && !empty($brand_other)) {
        $brand = $brand_other;
    }
    
    // Process color (handle "Other" option)
    if ($color === 'Other' && !empty($color_other)) {
        $color = $color_other;
    }
    
    // Process delivery location (where owner can collect)
    $delivery_location = '';
    if ($status === 'found') {
        if ($delivery_option === 'Other (Please specify)' && !empty($delivery_location_other)) {
            $delivery_location = $delivery_location_other;
        } elseif (!empty($delivery_option) && $delivery_option !== 'Other (Please specify)') {
            $delivery_location = $delivery_option;
        }
    }
    
    // Handle image upload
    $image_url = '';
    if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
        // Security: validate real MIME type, size, and use a random server filename.
        $upload = secure_image_upload($_FILES['image'], $uploadDir, 'assets/uploads');
        if ($upload['success']) {
            $image_url = $upload['path'];
        } else {
            $error = $upload['message'];
        }
    }
    
    // Combine date and time
    $datetime_occurred = !empty($date_occurred) ? $date_occurred . (!empty($time_occurred) ? ' ' . $time_occurred : '') : null;
    
    // Validate required fields
    if ($type === 'lost') {
        // For lost items: location is required
        if (empty($title) || empty($category) || empty($description) || empty($location_found) || empty($date_occurred)) {
            $error = 'Please fill in all required fields (Title, Category, Description, Location, and Date)';
        }
    } else {
        // For found items: location is NOT required (removed)
        if (empty($title) || empty($category) || empty($description) || empty($date_occurred)) {
            $error = 'Please fill in all required fields (Title, Category, Description, and Date)';
        }
    }
    
    if (empty($error)) {
        try {
            // Insert into items table
            $stmt = $db->prepare("
                INSERT INTO items (title, description, category, brand, color, found_location, delivery_location, date_found, status, image_url, reported_by, user_id, reported_date) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            // For found items, location_found can be empty
            $location_value = ($type === 'lost') ? $location_found : '';
            
            if ($stmt->execute([$title, $description, $category, $brand, $color, $location_value, $delivery_location, $datetime_occurred, $status, $image_url, $_SESSION['userID'], $_SESSION['userID']])) {
                $itemID = $db->lastInsertId();
                
                // Send notification to user
                $notifTitle = $status == 'lost' ? "🔍 Lost Item Reported" : "📍 Found Item Reported";
                $notifMessage = "You have successfully reported a {$status} item: '{$title}'.";
                $notification->send($_SESSION['userID'], $notifTitle, $notifMessage, 'success');
                
                if ($status == 'lost') {
                    $stmt = $db->prepare("INSERT INTO lost_reports (itemID, reporterID) VALUES (?, ?)");
                    $stmt->execute([$itemID, $_SESSION['userID']]);
                    $success = 'Lost item reported successfully!';
                    if (!empty($image_url)) {
                        $success .= ' Image uploaded successfully.';
                    }
                } else {
                    $stmt = $db->prepare("INSERT INTO found_reports (itemID, reporterID, found_by) VALUES (?, ?, ?)");
                    $stmt->execute([$itemID, $_SESSION['userID'], $_SESSION['name']]);
                    $success = 'Found item reported successfully!';
                    if (!empty($image_url)) {
                        $success .= ' Image uploaded successfully.';
                    }
                    $success .= '<br><strong>🏢 Keep at:</strong> ' . htmlspecialchars($delivery_location);
                }
                
                $_POST = [];
            } else {
                $error = 'Failed to report item. Please try again.';
            }
        } catch (PDOException $e) {
            error_log("Report item database error: " . $e->getMessage());
            $error = 'Unable to save item. Please try again.';
        }
    }
}
if (!defined('RECLAIM_EMBEDDED_LAYOUT')) {
    define('RECLAIM_EMBEDDED_LAYOUT', true);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $type === 'lost' ? 'Report Lost Item' : 'Report Found Item' ?> - Reclaim System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $base_url ?>assets/css/style.css">
    <style>
        * { font-family: 'Inter', sans-serif; }
        .form-section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 15px;
            margin-bottom: 20px;
        }
        .form-section-title {
            font-weight: 700;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #FF6B35;
            display: inline-block;
            color: #2C3E50;
        }
        .required-field::after {
            content: '*';
            color: #dc3545;
            margin-left: 4px;
        }
        .form-label { font-weight: 500; color: #2C3E50; }
        .form-control, .form-select {
            border-radius: 12px;
            padding: 12px 15px;
            border: 1px solid #e0e0e0;
            transition: all 0.3s;
        }
        .form-control:focus, .form-select:focus {
            border-color: #FF6B35;
            box-shadow: 0 0 0 3px rgba(255,107,53,0.1);
        }
        .btn-primary {
            background: linear-gradient(135deg, #FF6B35, #E85D2C);
            border: none;
            padding: 12px;
            border-radius: 12px;
            font-weight: 600;
            transition: all 0.3s;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255,107,53,0.3);
        }
        .btn-secondary {
            background: #6c757d;
            border: none;
            padding: 12px;
            border-radius: 12px;
            font-weight: 600;
        }
        .card {
            border: none;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .card-header {
            background: linear-gradient(135deg, #FF6B35, #E85D2C);
            color: white;
            padding: 20px;
            border: none;
        }
        .card-header h4 { margin: 0; font-weight: 700; }
        .card-body { padding: 30px; }
        
        .image-upload-container {
            position: relative;
            width: 100%;
            min-height: 180px;
            border: 2px dashed #ccc;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s;
            overflow: hidden;
        }
        .image-upload-container:hover {
            background: #fff8f0;
            border-color: #FF6B35;
        }
        .image-upload-content {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 30px;
            text-align: center;
        }
        .image-upload-container.has-image {
            border: 2px solid #FF6B35;
            padding: 0;
        }
        .image-upload-container.has-image .image-upload-content {
            display: none;
        }
        .uploaded-image-preview {
            width: 100%;
            height: 100%;
            min-height: 180px;
            object-fit: cover;
            display: none;
        }
        .image-upload-container.has-image .uploaded-image-preview {
            display: block;
        }
        .remove-image-btn {
            position: absolute;
            top: 10px;
            right: 10px;
            background: rgba(220, 53, 69, 0.9);
            color: white;
            border: none;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            z-index: 10;
            transition: all 0.2s;
            display: none;
        }
        .remove-image-btn:hover {
            background: #dc3545;
            transform: scale(1.1);
        }
        .image-upload-container.has-image .remove-image-btn {
            display: flex;
        }
        .alert { border-radius: 12px; border: none; }
        .alert-success { background: linear-gradient(135deg, #d4fc79 0%, #96e6a1 100%); color: #1e7e34; }
        .alert-danger { background: #ffebee; color: #c62828; }
        .other-input {
            margin-top: 8px;
        }
        .brand-select {
            transition: all 0.3s;
        }
    </style>
</head>
<body class="app-page user-page">
    <?php include __DIR__ . '/../includes/header.php'; ?>
    
    <main class="page-shell page-shell--compact">
    <div class="container content-wrapper">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card fade-in">
                    <div class="card-header">
                        <h4><i class="fas <?= $type === 'lost' ? 'fa-frown' : 'fa-smile' ?> me-2"></i> 
                            <?= $type === 'lost' ? 'Report Lost Item' : 'Report Found Item' ?>
                        </h4>
                        <p class="mb-0 opacity-75">Please provide detailed information to help us reunite items with their owners</p>
                    </div>
                    <div class="card-body">
                        <?php if($error): ?>
                            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                        <?php endif; ?>
                        <?php if($success): ?>
                            <div class="alert alert-success"><?= $success ?> <a href="<?= $base_url ?>user/dashboard.php" class="alert-link">Go to Dashboard</a></div>
                        <?php endif; ?>
                        
                        <form method="POST" action="" enctype="multipart/form-data" id="reportForm">
                            <?= csrf_field() ?>
                            <input type="hidden" name="status" value="<?= $type ?>">
                            
                            <div class="form-section">
                                <h5 class="form-section-title"><i class="fas fa-tag me-2"></i>Item Information</h5>
                                
                                <div class="mb-3">
                                    <label class="form-label required-field">Item Title</label>
                                    <input type="text" name="title" class="form-control" required 
                                           placeholder="e.g., iPhone 14 Pro, Black Wallet, Student ID Card" 
                                           value="<?= htmlspecialchars($_POST['title'] ?? '') ?>">
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label required-field">Category</label>
                                        <select name="category" id="category" class="form-select" required>
                                            <option value="">Select Category</option>
                                            <option value="Electronics" <?= (($_POST['category'] ?? '') == 'Electronics') ? 'selected' : '' ?>>📱 Electronics</option>
                                            <option value="Documents" <?= (($_POST['category'] ?? '') == 'Documents') ? 'selected' : '' ?>>📄 Documents</option>
                                            <option value="Accessories" <?= (($_POST['category'] ?? '') == 'Accessories') ? 'selected' : '' ?>>⌚ Accessories</option>
                                            <option value="Clothing" <?= (($_POST['category'] ?? '') == 'Clothing') ? 'selected' : '' ?>>👕 Clothing</option>
                                            <option value="Books" <?= (($_POST['category'] ?? '') == 'Books') ? 'selected' : '' ?>>📚 Books</option>
                                            <option value="Wallet" <?= (($_POST['category'] ?? '') == 'Wallet') ? 'selected' : '' ?>>👛 Wallet/Purse</option>
                                            <option value="Keys" <?= (($_POST['category'] ?? '') == 'Keys') ? 'selected' : '' ?>>🔑 Keys</option>
                                            <option value="Bag" <?= (($_POST['category'] ?? '') == 'Bag') ? 'selected' : '' ?>>🎒 Bag/Backpack</option>
                                            <option value="Jewelry" <?= (($_POST['category'] ?? '') == 'Jewelry') ? 'selected' : '' ?>>💍 Jewelry</option>
                                            <option value="Household" <?= (($_POST['category'] ?? '') == 'Household') ? 'selected' : '' ?>>🏠 Household</option>
                                            <option value="Others" <?= (($_POST['category'] ?? '') == 'Others') ? 'selected' : '' ?>>📦 Others</option>
                                        </select>
                                    </div>
                                    
                                    <!-- Location Field - Only for Lost Items -->
                                    <?php if ($type === 'lost'): ?>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label required-field">Location Where Lost</label>
                                        <input type="text" name="location" class="form-control" required 
                                               placeholder="e.g., Library, G3, Cafeteria, etc." 
                                               value="<?= htmlspecialchars($_POST['location'] ?? '') ?>">
                                    </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Brand</label>
                                        <select name="brand" id="brand" class="form-select brand-select">
                                            <option value="">Select Brand (Optional)</option>
                                        </select>
                                        <div id="brand_other_div" class="other-input" style="display: none;">
                                            <input type="text" name="brand_other" class="form-control" 
                                                   placeholder="Please specify brand"
                                                   value="<?= htmlspecialchars($_POST['brand_other'] ?? '') ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Color</label>
                                        <select name="color" id="color" class="form-select">
                                            <option value="">Select Color (Optional)</option>
                                            <?php foreach($common_colors as $c): ?>
                                                <option value="<?= $c ?>" <?= (($_POST['color'] ?? '') == $c) ? 'selected' : '' ?>>
                                                    <?= $c ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div id="color_other_div" class="other-input" style="display: none;">
                                            <input type="text" name="color_other" class="form-control" 
                                                   placeholder="Please specify color"
                                                   value="<?= htmlspecialchars($_POST['color_other'] ?? '') ?>">
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label required-field">Detailed Description</label>
                                    <textarea name="description" class="form-control" rows="4" required 
                                              placeholder="Describe the item in detail - unique markings, features, contents, etc."><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                                </div>
                            </div>
                            
                            <div class="form-section">
                                <h5 class="form-section-title"><i class="fas fa-calendar-alt me-2"></i>Date & Time</h5>
                                
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label required-field">Date <?= $type === 'lost' ? 'Lost' : 'Found' ?></label>
                                        <input type="date" name="date_occurred" class="form-control" required 
                                               value="<?= htmlspecialchars($_POST['date_occurred'] ?? date('Y-m-d')) ?>">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Approximate Time</label>
                                        <input type="time" name="time_occurred" class="form-control" 
                                               value="<?= htmlspecialchars($_POST['time_occurred'] ?? '') ?>">
                                    </div>
                                </div>
                            </div>
                            
                            <?php if ($type === 'found'): ?>
                            <div class="form-section">
                                <h5 class="form-section-title"><i class="fas fa-map-marker-alt me-2"></i>Where can the owner collect the item?</h5>
                                
                                <div class="mb-3">
                                    <label class="form-label required-field">Item Current Location (Keep At)</label>
                                    <select name="delivery_option" id="delivery_option" class="form-select" required>
                                        <option value="">Select where the item is currently kept</option>
                                        <?php foreach ($delivery_locations as $location_option): ?>
                                            <option value="<?= htmlspecialchars($location_option) ?>" <?= (($_POST['delivery_option'] ?? '') == $location_option) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($location_option) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted">The owner will visit this location to claim the item</small>
                                </div>
                                
                                <div class="mb-3" id="other_location_div" style="display: none;">
                                    <label class="form-label">Please specify location</label>
                                    <input type="text" name="delivery_location_other" class="form-control" 
                                           placeholder="Enter the specific location where the item can be collected"
                                           value="<?= htmlspecialchars($_POST['delivery_location_other'] ?? '') ?>">
                                </div>
                                
                                <div class="alert alert-info mt-2">
                                    <i class="fas fa-info-circle"></i> 
                                    <strong>Note:</strong> The item will be kept at the selected location. The owner can collect it from there after verification.
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <div class="form-section">
                                <h5 class="form-section-title"><i class="fas fa-camera me-2"></i>Photo (Optional)</h5>
                                
                                <div class="image-upload-container" id="imageUploadContainer" onclick="document.getElementById('imageInput').click()">
                                    <div class="image-upload-content">
                                        <i class="fas fa-camera fa-3x" style="color: #FF6B35;"></i>
                                        <p class="mb-0 mt-2">Click to upload an image of the item</p>
                                        <small class="text-muted">Uploading a photo increases chances of recovery</small>
                                    </div>
                                    <img class="uploaded-image-preview" id="uploadedImagePreview" alt="Uploaded image">
                                    <button type="button" class="remove-image-btn" id="removeImageBtn" onclick="removeImage(event)">
                                        <i class="fas fa-times"></i>
                                    </button>
                                    <input type="file" id="imageInput" name="image" accept="image/*" style="display: none;">
                                </div>
                            </div>
                            
                            <div class="d-grid gap-2 mt-3">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="fas fa-paper-plane me-2"></i> Submit Report
                                </button>
                                <a href="<?= $base_url ?>user/dashboard.php" class="btn btn-secondary">
                                    <i class="fas fa-arrow-left me-2"></i> Back to Dashboard
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Category-brand mapping
        const brandsByCategory = <?= json_encode($brands_by_category) ?>;
        
        // Update brand dropdown based on selected category
        function updateBrands() {
            const category = document.getElementById('category').value;
            const brandSelect = document.getElementById('brand');
            const brandOtherDiv = document.getElementById('brand_other_div');
            
            // Clear current options
            brandSelect.innerHTML = '<option value="">Select Brand (Optional)</option>';
            
            if (category && brandsByCategory[category]) {
                const brands = brandsByCategory[category];
                brands.forEach(brand => {
                    const option = document.createElement('option');
                    option.value = brand;
                    option.textContent = brand;
                    brandSelect.appendChild(option);
                });
            }
            
            // Reset brand selection
            brandSelect.value = '';
            brandOtherDiv.style.display = 'none';
            document.querySelector('input[name="brand_other"]').value = '';
        }
        
        // Brand "Other" toggle
        const brandSelect = document.getElementById('brand');
        const brandOtherDiv = document.getElementById('brand_other_div');
        
        function toggleBrandOther() {
            if (brandSelect.value === 'Other') {
                brandOtherDiv.style.display = 'block';
                document.querySelector('input[name="brand_other"]').required = true;
            } else {
                brandOtherDiv.style.display = 'none';
                document.querySelector('input[name="brand_other"]').required = false;
            }
        }
        
        // Color "Other" toggle
        const colorSelect = document.getElementById('color');
        const colorOtherDiv = document.getElementById('color_other_div');
        
        function toggleColorOther() {
            if (colorSelect.value === 'Other') {
                colorOtherDiv.style.display = 'block';
            } else {
                colorOtherDiv.style.display = 'none';
            }
        }
        
        // Event listeners
        document.getElementById('category').addEventListener('change', function() {
            updateBrands();
        });
        
        brandSelect.addEventListener('change', toggleBrandOther);
        colorSelect.addEventListener('change', toggleColorOther);
        
        // Initialize on page load
        updateBrands();
        toggleColorOther();
        
        // Image upload functionality
        const imageUploadContainer = document.getElementById('imageUploadContainer');
        const imageInput = document.getElementById('imageInput');
        const uploadedImagePreview = document.getElementById('uploadedImagePreview');
        const removeImageBtn = document.getElementById('removeImageBtn');
        
        imageInput.addEventListener('change', function(e) {
            if(e.target.files.length > 0) {
                const file = e.target.files[0];
                const reader = new FileReader();
                
                reader.onload = function(event) {
                    uploadedImagePreview.src = event.target.result;
                    imageUploadContainer.classList.add('has-image');
                };
                
                reader.readAsDataURL(file);
            }
        });
        
        function removeImage(event) {
            event.stopPropagation();
            imageInput.value = '';
            uploadedImagePreview.src = '';
            imageUploadContainer.classList.remove('has-image');
        }
        
        removeImageBtn.addEventListener('click', removeImage);
        
        <?php if ($type === 'found'): ?>
        const deliverySelect = document.getElementById('delivery_option');
        const otherDiv = document.getElementById('other_location_div');
        
        function toggleOtherLocation() {
            if (deliverySelect.value === 'Other (Please specify)') {
                otherDiv.style.display = 'block';
                document.querySelector('input[name="delivery_location_other"]').required = true;
            } else {
                otherDiv.style.display = 'none';
                document.querySelector('input[name="delivery_location_other"]').required = false;
            }
        }
        
        deliverySelect.addEventListener('change', toggleOtherLocation);
        toggleOtherLocation();
        <?php endif; ?>
    </script>
    
    </main>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
