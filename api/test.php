<?php
/**
 * Test Image Similarity Comparison Tool
 * Upload two images to compare their visual similarity using OpenCV
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$result = null;
$error = null;
$img1_path = null;
$img2_path = null;

// Create temp directory if not exists
$tempDir = __DIR__ . '/../assets/uploads/temp/';
if (!file_exists($tempDir)) {
    mkdir($tempDir, 0755, true);
}

// Handle image upload and comparison
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_FILES['image1']) && isset($_FILES['image2'])) {
        
        // Function to validate and save uploaded image
        function saveUploadedImage($file, $prefix) {
            global $tempDir;
            
            if ($file['error'] !== UPLOAD_ERR_OK) {
                return ['error' => "Upload failed for $prefix: " . $file['error']];
            }
            
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            
            if (!in_array($extension, $allowed)) {
                return ['error' => "Invalid file type for $prefix. Allowed: " . implode(', ', $allowed)];
            }
            
            if ($file['size'] > 5 * 1024 * 1024) {
                return ['error' => "File too large for $prefix. Max 5MB."];
            }
            
            $filename = $prefix . '_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
            $targetPath = $tempDir . $filename;
            
            if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                return ['success' => true, 'path' => $targetPath, 'filename' => $filename];
            }
            
            return ['error' => "Failed to save $prefix"];
        }
        
        // Save both images
        $result1 = saveUploadedImage($_FILES['image1'], 'img1');
        $result2 = saveUploadedImage($_FILES['image2'], 'img2');
        
        if (isset($result1['error'])) {
            $error = $result1['error'];
        } elseif (isset($result2['error'])) {
            $error = $result2['error'];
        } else {
            $img1_path = $result1['path'];
            $img2_path = $result2['path'];
            
            // Find Python path
            $pythonPaths = ['python', 'python3', 'C:\\Python312\\python.exe', 'C:\\Python311\\python.exe', 'C:\\Python310\\python.exe'];
            $pythonPath = null;
            foreach ($pythonPaths as $path) {
                $test = shell_exec($path . ' --version 2>&1');
                if ($test && strpos($test, 'Python') !== false) {
                    $pythonPath = $path;
                    break;
                }
            }
            
            if (!$pythonPath) {
                $error = "Python not found. Please install Python.";
            } else {
                // Run comparison
                $scriptPath = __DIR__ . '/compare.py';
                $command = escapeshellcmd("\"$pythonPath\" \"$scriptPath\" \"$img1_path\" \"$img2_path\"");
                $output = shell_exec($command . ' 2>&1');
                
                // Parse JSON result
                $lines = explode("\n", trim($output));
                $jsonLine = '';
                foreach (array_reverse($lines) as $line) {
                    if (strpos($line, '{') !== false && strpos($line, '}') !== false) {
                        $jsonLine = $line;
                        break;
                    }
                }
                
                $result = json_decode($jsonLine, true);
                if (!$result) {
                    $error = "Failed to parse similarity result. Raw output: " . htmlspecialchars(substr($output, 0, 500));
                }
            }
        }
    }
}

// Get Python info for display
$pythonInfo = null;
$opencvInfo = null;
$pythonPaths = ['python', 'python3', 'C:\\Python312\\python.exe', 'C:\\Python311\\python.exe', 'C:\\Python310\\python.exe'];
$pythonPath = null;
foreach ($pythonPaths as $path) {
    $test = shell_exec($path . ' --version 2>&1');
    if ($test && strpos($test, 'Python') !== false) {
        $pythonPath = $path;
        $pythonInfo = trim($test);
        $opencvCheck = shell_exec("\"$pythonPath\" -c \"import cv2; print(cv2.__version__)\" 2>&1");
        if ($opencvCheck && strpos($opencvCheck, 'No module') === false) {
            $opencvInfo = trim($opencvCheck);
        }
        break;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Image Similarity Test - OpenCV</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 40px;
            font-family: 'Inter', sans-serif;
        }
        .test-container {
            max-width: 1200px;
            margin: 0 auto;
        }
        .card {
            border: none;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            overflow: hidden;
            margin-bottom: 30px;
        }
        .card-header {
            background: linear-gradient(135deg, #FF6B35, #E85D2C);
            color: white;
            padding: 20px;
            font-weight: 700;
            border: none;
        }
        .upload-area {
            border: 2px dashed #dee2e6;
            border-radius: 15px;
            padding: 30px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            min-height: 250px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        .upload-area:hover {
            border-color: #FF6B35;
            background: #fff8f0;
        }
        .upload-area.has-image {
            border-color: #28a745;
            background: #f0fff4;
        }
        .image-preview {
            max-width: 100%;
            max-height: 200px;
            border-radius: 10px;
            margin-top: 10px;
        }
        .similarity-score {
            font-size: 48px;
            font-weight: 800;
            text-align: center;
            padding: 20px;
        }
        .score-high {
            color: #28a745;
        }
        .score-medium {
            color: #ffc107;
        }
        .score-low {
            color: #dc3545;
        }
        .result-card {
            background: #f8f9fa;
            border-radius: 15px;
            padding: 20px;
            margin-top: 20px;
        }
        .metric-bar {
            height: 8px;
            background: #e9ecef;
            border-radius: 4px;
            overflow: hidden;
            margin: 10px 0;
        }
        .metric-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 0.5s ease;
        }
        .btn-compare {
            background: linear-gradient(135deg, #FF6B35, #E85D2C);
            border: none;
            padding: 12px 30px;
            font-weight: 600;
            width: 100%;
        }
        .btn-compare:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255,107,53,0.3);
        }
        .info-badge {
            background: #e9ecef;
            padding: 5px 10px;
            border-radius: 10px;
            font-size: 12px;
            display: inline-block;
            margin-right: 10px;
        }
        .system-status {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="test-container">
        <!-- Header -->
        <div class="text-center mb-4">
            <h1 class="text-white"><i class="fas fa-chart-line me-2"></i> Image Similarity Test</h1>
            <p class="text-white-50">Upload two images to compare their visual similarity using OpenCV</p>
        </div>

        <!-- System Status -->
        <div class="system-status">
            <div class="row">
                <div class="col-md-6">
                    <i class="fas fa-code-branch me-2"></i> <strong>Python:</strong>
                    <?php if ($pythonInfo): ?>
                        <span class="text-success">✓ <?= htmlspecialchars($pythonInfo) ?></span>
                    <?php else: ?>
                        <span class="text-danger">✗ Not found</span>
                    <?php endif; ?>
                </div>
                <div class="col-md-6">
                    <i class="fas fa-camera me-2"></i> <strong>OpenCV:</strong>
                    <?php if ($opencvInfo): ?>
                        <span class="text-success">✓ <?= htmlspecialchars($opencvInfo) ?></span>
                    <?php else: ?>
                        <span class="text-danger">✗ Not installed (pip install opencv-python)</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Upload Form -->
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-upload me-2"></i> Upload Images for Comparison
                    </div>
                    <div class="card-body">
                        <form method="POST" enctype="multipart/form-data" id="compareForm">
                            <div class="row">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Image 1</label>
                                    <div class="upload-area" id="uploadArea1" onclick="document.getElementById('image1').click()">
                                        <i class="fas fa-cloud-upload-alt fa-3x text-muted mb-2"></i>
                                        <p class="mb-0">Click to upload image</p>
                                        <small class="text-muted">JPG, PNG, GIF, WEBP (Max 5MB)</small>
                                        <div id="preview1"></div>
                                    </div>
                                    <input type="file" name="image1" id="image1" accept="image/*" style="display: none;" onchange="previewImage(this, 'preview1', 'uploadArea1')">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Image 2</label>
                                    <div class="upload-area" id="uploadArea2" onclick="document.getElementById('image2').click()">
                                        <i class="fas fa-cloud-upload-alt fa-3x text-muted mb-2"></i>
                                        <p class="mb-0">Click to upload image</p>
                                        <small class="text-muted">JPG, PNG, GIF, WEBP (Max 5MB)</small>
                                        <div id="preview2"></div>
                                    </div>
                                    <input type="file" name="image2" id="image2" accept="image/*" style="display: none;" onchange="previewImage(this, 'preview2', 'uploadArea2')">
                                </div>
                            </div>
                            <div class="row mt-4">
                                <div class="col-md-12">
                                    <button type="submit" class="btn btn-compare">
                                        <i class="fas fa-chart-simple me-2"></i> Compare Images
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Results -->
        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle me-2"></i> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($result && $img1_path && $img2_path): ?>
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-chart-bar me-2"></i> Comparison Results
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-5 text-center">
                            <h6>Image 1</h6>
                            <img src="<?= str_replace(__DIR__ . '/../', '/reclaim-system/', $img1_path) ?>" class="img-fluid rounded" style="max-height: 200px;">
                        </div>
                        <div class="col-md-2 text-center">
                            <div class="similarity-score <?= $result['similarity'] >= 70 ? 'score-high' : ($result['similarity'] >= 40 ? 'score-medium' : 'score-low') ?>">
                                <?= $result['similarity'] ?>%
                            </div>
                            <div class="mt-2">
                                <?php if ($result['similarity'] >= 70): ?>
                                    <span class="badge bg-success">High Match</span>
                                <?php elseif ($result['similarity'] >= 40): ?>
                                    <span class="badge bg-warning">Medium Match</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">Low Match</span>
                                <?php endif; ?>
                            </div>
                            <i class="fas fa-arrows-left-right fa-2x text-muted mt-3"></i>
                        </div>
                        <div class="col-md-5 text-center">
                            <h6>Image 2</h6>
                            <img src="<?= str_replace(__DIR__ . '/../', '/reclaim-system/', $img2_path) ?>" class="img-fluid rounded" style="max-height: 200px;">
                        </div>
                    </div>

                    <hr>

                    <div class="result-card">
                        <h6><i class="fas fa-chart-line me-2"></i> Detailed Metrics</h6>
                        
                        <div class="mb-3">
                            <div class="d-flex justify-content-between">
                                <span>Overall Similarity</span>
                                <strong><?= $result['similarity'] ?>%</strong>
                            </div>
                            <div class="metric-bar">
                                <div class="metric-fill <?= $result['similarity'] >= 70 ? 'bg-success' : ($result['similarity'] >= 40 ? 'bg-warning' : 'bg-danger') ?>" 
                                     style="width: <?= $result['similarity'] ?>%"></div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <div class="d-flex justify-content-between">
                                <span>ORB Feature Matching</span>
                                <strong><?= $result['orb_score'] ?>%</strong>
                            </div>
                            <div class="metric-bar">
                                <div class="metric-fill bg-info" style="width: <?= $result['orb_score'] ?>%"></div>
                            </div>
                            <small class="text-muted">Measures shape, texture, and structural similarity</small>
                        </div>

                        <div class="mb-3">
                            <div class="d-flex justify-content-between">
                                <span>Color Histogram Matching</span>
                                <strong><?= $result['hist_score'] ?>%</strong>
                            </div>
                            <div class="metric-bar">
                                <div class="metric-fill bg-secondary" style="width: <?= $result['hist_score'] ?>%"></div>
                            </div>
                            <small class="text-muted">Measures color distribution similarity</small>
                        </div>

                        <div class="row mt-3">
                            <div class="col-md-6">
                                <div class="info-badge">
                                    <i class="fas fa-star"></i> Features in Image 1: <?= $result['features1'] ?>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="info-badge">
                                    <i class="fas fa-star"></i> Features in Image 2: <?= $result['features2'] ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-3 text-center">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Interpretation:</strong>
                            <?php if ($result['similarity'] >= 70): ?>
                                ✓ High similarity - These images appear to show the same or very similar items.
                            <?php elseif ($result['similarity'] >= 40): ?>
                                ⚠ Medium similarity - These images share some visual features but may be different items.
                            <?php else: ?>
                                ✗ Low similarity - These images show different items with little visual commonality.
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Example Images Section -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-images me-2"></i> Test with Example Images
            </div>
            <div class="card-body">
                <p class="text-muted">Download these sample images to test the similarity comparison:</p>
                <div class="row">
                    <div class="col-md-4 text-center">
                        <i class="fas fa-bottle-water fa-3x mb-2" style="color: #FF6B35;"></i>
                        <p>Bottle Sample</p>
                        <button class="btn btn-sm btn-outline-secondary" onclick="alert('Save this page and upload similar bottle images')">
                            <i class="fas fa-download"></i> Info
                        </button>
                    </div>
                    <div class="col-md-4 text-center">
                        <i class="fas fa-mobile-alt fa-3x mb-2" style="color: #FF6B35;"></i>
                        <p>Phone Sample</p>
                        <button class="btn btn-sm btn-outline-secondary" onclick="alert('Upload two different phone images to compare')">
                            <i class="fas fa-download"></i> Info
                        </button>
                    </div>
                    <div class="col-md-4 text-center">
                        <i class="fas fa-wallet fa-3x mb-2" style="color: #FF6B35;"></i>
                        <p>Wallet Sample</p>
                        <button class="btn btn-sm btn-outline-secondary" onclick="alert('Upload two different wallet images to compare')">
                            <i class="fas fa-download"></i> Info
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function previewImage(input, previewId, uploadAreaId) {
            const preview = document.getElementById(previewId);
            const uploadArea = document.getElementById(uploadAreaId);
            
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                
                reader.onload = function(e) {
                    preview.innerHTML = `<img src="${e.target.result}" class="image-preview" alt="Preview">`;
                    uploadArea.classList.add('has-image');
                }
                
                reader.readAsDataURL(input.files[0]);
            }
        }
    </script>
</body>
</html>