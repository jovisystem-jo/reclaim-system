<?php
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

// ============================================
// REPLACE THESE WITH YOUR OWN IMAGGA API KEYS
// Get your free keys at: https://imagga.com/auth/signup
// ============================================
$imaggaApiKey = 'acc_f184e290529d97a';      // Replace with your API Key
$imaggaApiSecret = 'c32c7e5d5191f1691959aff7acf3ccc0'; // Replace with your API Secret

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['image'])) {
    $uploadDir = __DIR__ . '/../assets/uploads/temp/';
    
    // Create temp directory if not exists
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    
    $fileName = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', basename($_FILES['image']['name']));
    $uploadFile = $uploadDir . $fileName;
    
    // Validate file type
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $_FILES['image']['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mimeType, $allowedTypes)) {
        echo json_encode(['success' => false, 'message' => 'Invalid image format. Allowed: JPG, PNG, GIF, WEBP']);
        exit();
    }
    
    // Validate file size (max 5MB)
    if ($_FILES['image']['size'] > 5 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'Image too large. Max 5MB']);
        exit();
    }
    
    if (move_uploaded_file($_FILES['image']['tmp_name'], $uploadFile)) {
        try {
            // Read image file
            $imageData = file_get_contents($uploadFile);
            $base64Image = base64_encode($imageData);
            
            // Call Imagga API for tagging
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, 'https://api.imagga.com/v2/tags');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                'image_base64' => $base64Image
            ]));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Basic ' . base64_encode($imaggaApiKey . ':' . $imaggaApiSecret)
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode !== 200) {
                // Fallback to filename-based keywords if API fails
                $filename = pathinfo($fileName, PATHINFO_FILENAME);
                $keywords = preg_split('/[_\-\.]/', strtolower($filename));
                $commonItems = ['laptop', 'phone', 'wallet', 'keys', 'bag', 'book', 'id', 'card', 'glasses', 'watch'];
                $extractedLabels = [];
                foreach ($keywords as $keyword) {
                    if (in_array($keyword, $commonItems) || strlen($keyword) > 3) {
                        $extractedLabels[] = $keyword;
                    }
                }
                if (empty($extractedLabels)) {
                    $extractedLabels = ['item'];
                }
            } else {
                $result = json_decode($response, true);
                $tags = $result['result']['tags'] ?? [];
                
                $extractedLabels = [];
                foreach ($tags as $tag) {
                    if ($tag['confidence'] > 30) { // Only include tags with >30% confidence
                        $extractedLabels[] = $tag['tag']['en'];
                    }
                }
                
                if (empty($extractedLabels)) {
                    $extractedLabels = ['item'];
                }
            }
            
            // Save analysis to database
            $db = Database::getInstance()->getConnection();
            $labelsJson = json_encode($extractedLabels);
            
            // Create image_analysis table if not exists
            try {
                $stmt = $db->prepare("
                    INSERT INTO image_analysis (image_url, extracted_text, labels, confidence_score, created_at) 
                    VALUES (?, ?, ?, 0.8, NOW())
                ");
                $stmt->execute([$uploadFile, implode(', ', $extractedLabels), $labelsJson]);
                $analysisId = $db->lastInsertId();
            } catch (PDOException $e) {
                // Create table if not exists
                $db->exec("
                    CREATE TABLE IF NOT EXISTS `image_analysis` (
                        `analysis_id` int(11) NOT NULL AUTO_INCREMENT,
                        `image_url` varchar(500) DEFAULT NULL,
                        `extracted_text` text DEFAULT NULL,
                        `labels` text DEFAULT NULL,
                        `confidence_score` decimal(5,2) DEFAULT NULL,
                        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                        PRIMARY KEY (`analysis_id`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
                ");
                
                $stmt = $db->prepare("
                    INSERT INTO image_analysis (image_url, extracted_text, labels, confidence_score, created_at) 
                    VALUES (?, ?, ?, 0.8, NOW())
                ");
                $stmt->execute([$uploadFile, implode(', ', $extractedLabels), $labelsJson]);
                $analysisId = $db->lastInsertId();
            }
            
            echo json_encode([
                'success' => true,
                'analysis_id' => $analysisId,
                'labels' => $extractedLabels,
                'message' => 'Image analyzed successfully! Found ' . count($extractedLabels) . ' labels.'
            ]);
            
        } catch (Exception $e) {
            echo json_encode([
                'success' => false, 
                'message' => 'Image recognition error: ' . $e->getMessage()
            ]);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to upload image']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
}
?>