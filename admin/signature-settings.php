<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
requireAdmin();

$db = Database::getInstance()->getConnection();
$message = '';
$error = '';

// Create signature uploads directory if not exists
$signatureDir = __DIR__ . '/../assets/uploads/signatures/';
if (!file_exists($signatureDir)) {
    mkdir($signatureDir, 0755, true);
}

// Handle signature save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_signature'])) {
    require_csrf_token();
    
    $signature_name = trim($_POST['signature_name'] ?? '');
    $signature_id = trim($_POST['signature_id'] ?? '');
    $signature_position = trim($_POST['signature_position'] ?? '');
    $signature_department = trim($_POST['signature_department'] ?? '');
    
    if (empty($signature_name) || empty($signature_id)) {
        $error = 'Name and Staff ID are required fields.';
    } else {
        // Handle signature image upload
        $signature_image = $_SESSION['admin_signature']['image'] ?? '';
        
        if (isset($_FILES['signature_image']) && $_FILES['signature_image']['error'] === UPLOAD_ERR_OK) {
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $extension = strtolower(pathinfo($_FILES['signature_image']['name'], PATHINFO_EXTENSION));
            $max_size = 2 * 1024 * 1024; // 2MB
            
            if ($_FILES['signature_image']['size'] > $max_size) {
                $error = 'Signature image must be less than 2MB.';
            } elseif (in_array($extension, $allowed)) {
                // Delete old signature if exists
                if (!empty($signature_image) && file_exists(__DIR__ . '/../' . $signature_image)) {
                    unlink(__DIR__ . '/../' . $signature_image);
                }
                
                $filename = 'admin_signature_' . $_SESSION['userID'] . '_' . time() . '.' . $extension;
                $target_path = $signatureDir . $filename;
                
                if (move_uploaded_file($_FILES['signature_image']['tmp_name'], $target_path)) {
                    $signature_image = '/reclaim-system/assets/uploads/signatures/' . $filename;
                } else {
                    $error = 'Failed to upload signature image.';
                }
            } else {
                $error = 'Invalid file type. Allowed: JPG, PNG, GIF, WEBP';
            }
        }
        
        // Remove signature image if checkbox is checked
        if (isset($_POST['remove_signature']) && $_POST['remove_signature'] == '1') {
            if (!empty($signature_image) && file_exists(__DIR__ . '/../' . $signature_image)) {
                unlink(__DIR__ . '/../' . $signature_image);
            }
            $signature_image = '';
        }
        
        if (empty($error)) {
            // Save signature data to session
            $_SESSION['admin_signature'] = [
                'name' => $signature_name,
                'id' => $signature_id,
                'position' => $signature_position,
                'department' => $signature_department,
                'image' => $signature_image,
                'updated_at' => date('Y-m-d H:i:s')
            ];
            
            $message = "Signature settings saved successfully!";
        }
    }
}

// Get saved signature data
$admin_signature = $_SESSION['admin_signature'] ?? [
    'name' => $_SESSION['name'] ?? '',
    'id' => $_SESSION['userID'] ?? '',
    'position' => 'Administrator',
    'department' => 'Auxiliary Police and Security Office',
    'image' => '',
    'updated_at' => ''
];

$base_url = '/reclaim-system/';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Signature Settings - Reclaim System</title>
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
        
        .signature-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            overflow: hidden;
            margin-bottom: 20px;
        }
        .card-header-custom {
            background: linear-gradient(135deg, #FF6B35, #E85D2C);
            color: white;
            padding: 20px;
            border: none;
        }
        .card-header-custom h4 {
            margin: 0;
            font-weight: 700;
        }
        .card-header-custom p {
            margin: 5px 0 0;
            opacity: 0.9;
            font-size: 14px;
        }
        .card-body-custom {
            padding: 30px;
        }
        
        .signature-preview {
            background: #f8f9fa;
            border-radius: 15px;
            padding: 20px;
            text-align: center;
            border: 2px dashed #dee2e6;
        }
        .signature-img {
            max-width: 250px;
            max-height: 100px;
            display: block;
            margin: 0 auto 15px;
            margin-bottom: 15px;
        }
        .signature-line {
            width: 200px;
            height: 1px;
            background: #ccc;
            margin: 10px auto;
        }
        .info-text {
            font-size: 13px;
            color: #6c757d;
        }
        .btn-save {
            background: linear-gradient(135deg, #27AE60, #1e8449);
            border: none;
            padding: 12px 30px;
            border-radius: 50px;
            color: white;
            font-weight: 600;
            transition: all 0.3s;
        }
        .btn-save:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(39,174,96,0.3);
            color: white;
        }
        .current-signature {
            background: #e8f8f5;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .required-field::after {
            content: '*';
            color: #dc3545;
            margin-left: 4px;
        }
        .step-circle {
            width: 60px;
            height: 60px;
            background: #f8f9fa;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .step-circle i {
            font-size: 28px;
            color: #FF6B35;
        }
    </style>
</head>
<body class="app-page admin-page">
    <div class="container-fluid">
        <div class="row">
            <?php include 'sidebar.php'; ?>
            
            <div class="col-md-10 main-content content-wrapper">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2 class="fw-bold"><i class="fas fa-signature me-2" style="color: #FF6B35;"></i> Admin Signature Settings</h2>
                    <a href="verify-claims.php" class="btn btn-secondary-custom">
                        <i class="fas fa-arrow-left me-2"></i> Back to Verify Claims
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
                
                <div class="row">
                    <div class="col-lg-7">
                        <div class="signature-card">
                            <div class="card-header-custom">
                                <h4><i class="fas fa-edit me-2"></i> Configure Your Digital Signature</h4>
                                <p>This signature will be used on all claim approval documents</p>
                            </div>
                            <div class="card-body-custom">
                                <form method="POST" action="" enctype="multipart/form-data">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="save_signature" value="1">
                                    
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label fw-bold required-field">Full Name</label>
                                            <input type="text" name="signature_name" class="form-control" 
                                                   value="<?= htmlspecialchars($admin_signature['name']) ?>" required>
                                        </div>
                                        
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label fw-bold required-field">Staff ID / Admin ID</label>
                                            <input type="text" name="signature_id" class="form-control" 
                                                   value="<?= htmlspecialchars($admin_signature['id']) ?>" required>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label fw-bold">Position / Title</label>
                                            <input type="text" name="signature_position" class="form-control" 
                                                   value="<?= htmlspecialchars($admin_signature['position']) ?>">
                                            <small class="text-muted">e.g., Head of Security, Administrator</small>
                                        </div>
                                        
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label fw-bold">Department</label>
                                            <input type="text" name="signature_department" class="form-control" 
                                                   value="<?= htmlspecialchars($admin_signature['department']) ?>">
                                            <small class="text-muted">e.g., Auxiliary Police and Security Office</small>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Signature Image (Optional)</label>
                                        <input type="file" name="signature_image" class="form-control" accept="image/*">
                                        <small class="text-muted">
                                            <i class="fas fa-info-circle me-1"></i> 
                                            Upload an image of your handwritten signature. Allowed: JPG, PNG, GIF, WEBP. Max 2MB.
                                            Leave empty to use text signature.
                                        </small>
                                    </div>
                                    
                                    <?php if (!empty($admin_signature['image'])): ?>
                                    <div class="mb-3 form-check">
                                        <input type="checkbox" name="remove_signature" value="1" class="form-check-input" id="removeSignature">
                                        <label class="form-check-label text-danger" for="removeSignature">
                                            <i class="fas fa-trash-alt me-1"></i> Remove current signature image
                                        </label>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <hr class="my-4">
                                    
                                    <button type="submit" class="btn btn-save">
                                        <i class="fas fa-save me-2"></i> Save Signature Settings
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-5">
                        <div class="signature-card">
                            <div class="card-header-custom">
                                <h4><i class="fas fa-eye me-2"></i> Current Signature Preview</h4>
                                <p>How your signature will appear on documents</p>
                            </div>
                            <div class="card-body-custom">
                                <div class="current-signature">
                                    <h6 class="fw-bold mb-3 text-center">Digital Signature</h6>
                                    <div class="signature-preview">
                                        <?php if (!empty($admin_signature['image'])): ?>
                                            <img src="<?= $admin_signature['image'] ?>" class="signature-img" alt="Signature">
                                        <?php else: ?>
                                            <div class="signature-line"></div>
                                        <?php endif; ?>
                                        <div class="fw-bold mt-2"><?= htmlspecialchars($admin_signature['name']) ?></div>
                                        <div class="info-text">ID: <?= htmlspecialchars($admin_signature['id']) ?></div>
                                        <?php if (!empty($admin_signature['position'])): ?>
                                            <div class="info-text"><?= htmlspecialchars($admin_signature['position']) ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($admin_signature['department'])): ?>
                                            <div class="info-text"><?= htmlspecialchars($admin_signature['department']) ?></div>
                                        <?php endif; ?>
                                        <div class="info-text mt-2">
                                            <i class="far fa-calendar-alt me-1"></i> Date: <?= date('F d, Y') ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <?php if (!empty($admin_signature['updated_at'])): ?>
                                    <div class="text-center mt-3">
                                        <small class="text-muted">
                                            <i class="fas fa-clock me-1"></i> 
                                            Last updated: <?= date('F d, Y h:i A', strtotime($admin_signature['updated_at'])) ?>
                                        </small>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="alert alert-info mt-3">
                                    <i class="fas fa-info-circle me-2"></i>
                                    <strong>Note:</strong> Once saved, this signature will be automatically applied to all future claim approvals. You can update it anytime.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="signature-card mt-4">
                    <div class="card-header-custom">
                        <h4><i class="fas fa-question-circle me-2"></i> How It Works</h4>
                    </div>
                    <div class="card-body-custom">
                        <div class="row text-center">
                            <div class="col-md-4 mb-3">
                                <div class="step-circle">
                                    <i class="fas fa-upload fa-2x"></i>
                                </div>
                                <h6 class="mt-2">1. Upload Signature</h6>
                                <small class="text-muted">Upload your handwritten signature image</small>
                            </div>
                            <div class="col-md-4 mb-3">
                                <div class="step-circle">
                                    <i class="fas fa-pen-fancy fa-2x"></i>
                                </div>
                                <h6 class="mt-2">2. Fill Details</h6>
                                <small class="text-muted">Enter your name, ID, position, department</small>
                            </div>
                            <div class="col-md-4 mb-3">
                                <div class="step-circle">
                                    <i class="fas fa-check-circle fa-2x"></i>
                                </div>
                                <h6 class="mt-2">3. Auto-Apply</h6>
                                <small class="text-muted">Signature automatically appears on claim approvals</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
