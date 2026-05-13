<?php
/**
 * Hybrid Smart Matching System - Search by Image
 * Combines: Visual Similarity (OpenCV) + Text Similarity (Title, Description, Category)
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/env.php';

// Load environment variables
EnvLoader::load();

header('Content-Type: application/json');

// Configuration
define('MAX_FILE_SIZE', 5 * 1024 * 1024);
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
define('WEIGHT_VISUAL', 0.5);      // 50% weight for visual similarity
define('WEIGHT_TITLE', 0.25);      // 25% weight for title matching
define('WEIGHT_DESCRIPTION', 0.15); // 15% weight for description matching
define('WEIGHT_CATEGORY', 0.10);    // 10% weight for category matching
define('SIMILARITY_THRESHOLD', 15);  // Minimum similarity to consider a match
define('CATEGORY_MATCH_BOOST', 15);  // Boost for same category items

// Get Imagga API credentials from .env file
$imaggaApiKey = EnvLoader::get('IMAGGA_API_KEY', '');
$imaggaApiSecret = EnvLoader::get('IMAGGA_API_SECRET', '');

// Ensure upload directories exist
$uploadDir = __DIR__ . '/../assets/uploads/temp/';
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

if (!isset($_FILES['image']) || $_FILES['image']['error'] === UPLOAD_ERR_NO_FILE) {
    echo json_encode(['success' => false, 'message' => 'No image file provided.']);
    exit();
}

// Validate CSRF token
if (function_exists('csrf_token') && function_exists('require_csrf_token')) {
    require_csrf_token();
}

// Validate file
$extension = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
if (!in_array($extension, ALLOWED_EXTENSIONS)) {
    echo json_encode(['success' => false, 'message' => 'Invalid file type. Allowed: ' . implode(', ', ALLOWED_EXTENSIONS)]);
    exit();
}

if ($_FILES['image']['size'] > MAX_FILE_SIZE) {
    echo json_encode(['success' => false, 'message' => 'File size exceeds 5MB limit.']);
    exit();
}

// Upload and validate image
$upload = secure_image_upload($_FILES['image'], $uploadDir, 'assets/uploads/temp');
if (!$upload['success']) {
    echo json_encode(['success' => false, 'message' => $upload['message']]);
    exit();
}

$uploadedImagePath = __DIR__ . '/../' . $upload['path'];

try {
    $db = Database::getInstance()->getConnection();
    
    // Get all active items with images
    $stmt = $db->prepare("
        SELECT item_id, title, description, category, image_url, status
        FROM items 
        WHERE status IN ('lost', 'found') 
        AND image_url IS NOT NULL 
        AND image_url != ''
        ORDER BY item_id
    ");
    $stmt->execute();
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($items)) {
        if (file_exists($uploadedImagePath)) {
            unlink($uploadedImagePath);
        }
        echo json_encode([
            'success' => true,
            'matches' => [],
            'message' => 'No items with images found in database.'
        ]);
        exit();
    }
    
    // Find Python path
    $pythonPath = findPythonPath();
    
    if (!$pythonPath) {
        echo json_encode([
            'success' => false,
            'message' => 'Python/OpenCV not configured. Please contact administrator.'
        ]);
        exit();
    }
    
    $results = [];
    
    // First pass: Calculate all scores
    foreach ($items as $item) {
        $itemImagePath = getFullImagePath($item['image_url']);
        
        if (!file_exists($itemImagePath)) {
            continue;
        }
        
        // Calculate visual similarity using Python/OpenCV
        $visualScore = calculateVisualSimilarity($uploadedImagePath, $itemImagePath, $pythonPath);
        
        // Calculate text-based similarities
        $titleScore = calculateTextSimilarity($uploadedImagePath, $item['title'], 'title');
        $descriptionScore = calculateTextSimilarity($uploadedImagePath, $item['description'], 'description');
        $categoryScore = calculateCategorySimilarity($uploadedImagePath, $item['category']);
        
        // Calculate weighted final score
        $finalScore = ($visualScore * WEIGHT_VISUAL) + 
                      ($titleScore * WEIGHT_TITLE) + 
                      ($descriptionScore * WEIGHT_DESCRIPTION) + 
                      ($categoryScore * WEIGHT_CATEGORY);
        
        $results[] = [
            'item_id' => $item['item_id'],
            'title' => $item['title'],
            'description' => $item['description'],
            'category' => $item['category'],
            'image_url' => $item['image_url'],
            'status' => $item['status'],
            'visual_score' => round($visualScore, 2),
            'title_score' => round($titleScore, 2),
            'description_score' => round($descriptionScore, 2),
            'category_score' => round($categoryScore, 2),
            'final_score' => round($finalScore, 2),
            'similarity_percentage' => round($finalScore, 2)
        ];
    }
    
    // Sort by final score to find the highest match
    usort($results, function($a, $b) {
        return $b['final_score'] <=> $a['final_score'];
    });
    
    // Get the highest match category
    $topMatchCategory = !empty($results) ? $results[0]['category'] : null;
    $topMatchScore = !empty($results) ? $results[0]['final_score'] : 0;
    
    // Second pass: Filter and boost based on category
    $filteredResults = [];
    foreach ($results as $result) {
        // Only include items that meet threshold OR have same category as top match
        if ($result['final_score'] >= SIMILARITY_THRESHOLD || 
            ($topMatchCategory && $result['category'] == $topMatchCategory)) {
            
            // Boost score for same category items
            if ($topMatchCategory && $result['category'] == $topMatchCategory && $result['item_id'] != $results[0]['item_id']) {
                $result['final_score'] = min(100, $result['final_score'] + CATEGORY_MATCH_BOOST);
                $result['similarity_percentage'] = $result['final_score'];
                $result['match_reason'] = "Same category as top match";
            } else {
                $result['match_reason'] = getMatchReason($result);
            }
            
            $result['match_level'] = getMatchLevel($result['final_score']);
            $filteredResults[] = $result;
        }
    }
    
    // Resort after boosting
    usort($filteredResults, function($a, $b) {
        return $b['final_score'] <=> $a['final_score'];
    });
    
    // Save to database
    $resultsJson = json_encode($filteredResults);
    $matchedIds = json_encode(array_column(array_slice($filteredResults, 0, 20), 'item_id'));
    $topCategory = $topMatchCategory ?? '';
    
    try {
        $stmt = $db->prepare("
            INSERT INTO image_analysis (image_url, extracted_text, labels, confidence_score, matched_item_ids, created_at) 
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $avgScore = count($filteredResults) > 0 ? array_sum(array_column($filteredResults, 'final_score')) / count($filteredResults) : 0;
        $stmt->execute([$upload['path'], $resultsJson, json_encode(['top_category' => $topCategory, 'top_score' => $topMatchScore]), $avgScore / 100, $matchedIds]);
    } catch (PDOException $e) {
        $stmt = $db->prepare("
            INSERT INTO image_analysis (image_url, extracted_text, labels, confidence_score, created_at) 
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$upload['path'], $resultsJson, json_encode(['top_category' => $topCategory, 'top_score' => $topMatchScore]), $avgScore / 100]);
    }
    $analysisId = $db->lastInsertId();
    
    // Clean up temp file
    if (file_exists($uploadedImagePath)) {
        unlink($uploadedImagePath);
    }
    
    echo json_encode([
        'success' => true,
        'analysis_id' => $analysisId,
        'matches' => $filteredResults,
        'total_matches' => count($filteredResults),
        'top_match_category' => $topMatchCategory,
        'top_match_score' => round($topMatchScore, 2),
        'message' => 'Found ' . count($filteredResults) . ' matching items.'
    ]);
    
} catch (Exception $e) {
    error_log("Image search error: " . $e->getMessage());
    if (isset($uploadedImagePath) && file_exists($uploadedImagePath)) {
        unlink($uploadedImagePath);
    }
    echo json_encode(['success' => false, 'message' => 'Processing error: ' . $e->getMessage()]);
}

/**
 * Calculate visual similarity using Python/OpenCV
 */
function calculateVisualSimilarity($img1, $img2, $pythonPath) {
    $scriptPath = __DIR__ . '/compare.py';
    
    if (!file_exists($scriptPath)) {
        error_log("compare.py not found at: " . $scriptPath);
        return 0;
    }
    
    if (!file_exists($img1)) {
        error_log("Source image not found: " . $img1);
        return 0;
    }
    
    if (!file_exists($img2)) {
        error_log("Target image not found: " . $img2);
        return 0;
    }
    
    $command = escapeshellcmd("\"$pythonPath\" \"$scriptPath\" \"$img1\" \"$img2\"");
    error_log("Executing: " . $command);
    
    $output = shell_exec($command . ' 2>&1');
    
    // Extract JSON from output
    $lines = explode("\n", trim($output));
    $jsonLine = '';
    foreach (array_reverse($lines) as $line) {
        if (strpos($line, '{') !== false && strpos($line, '}') !== false) {
            $jsonLine = $line;
            break;
        }
    }
    
    if (!empty($jsonLine)) {
        $result = json_decode($jsonLine, true);
        if ($result && isset($result['similarity'])) {
            error_log("Parsed similarity: " . $result['similarity'] . "%");
            return min(100, max(0, floatval($result['similarity'])));
        }
    }
    
    return 0;
}

/**
 * Calculate text similarity between uploaded image name and item title
 */
function calculateTextSimilarity($imagePath, $itemText, $fieldType) {
    if (empty($itemText)) {
        return 0;
    }
    
    // Extract keywords from uploaded image filename
    $filename = basename($imagePath);
    $filename = strtolower(pathinfo($filename, PATHINFO_FILENAME));
    $filename = preg_replace('/[_-]+/', ' ', $filename);
    
    // Extract keywords from item text
    $itemText = strtolower($itemText);
    
    // Common keywords for matching
    $keywords = [
        'phone', 'iphone', 'samsung', 'xiaomi', 'huawei', 'oppo', 'vivo',
        'laptop', 'macbook', 'dell', 'hp', 'lenovo', 'asus', 'acer',
        'wallet', 'purse', 'cardholder', 'leather',
        'keys', 'keychain', 'keyring',
        'bag', 'backpack', 'handbag', 'tote', 'sling',
        'bottle', 'water', 'flask', 'tumbler', 'hydroflask',
        'watch', 'smartwatch', 'apple watch', 'garmin', 'casio',
        'earphone', 'headphone', 'airpods', 'earbuds', 'beats',
        'charger', 'cable', 'adapter', 'powerbank', 'power bank',
        'book', 'notebook', 'textbook', 'journal', 'novel',
        'id card', 'student id', 'staff id', 'access card', 'matric',
        'umbrella', 'glasses', 'sunglasses', 'pen', 'pencil case',
        'bracelet', 'necklace', 'ring', 'jewelry', 'pendant',
        'powerbank', 'pineng', 'anker', 'xiaomi'
    ];
    
    $score = 0;
    $matchedKeywords = [];
    
    foreach ($keywords as $keyword) {
        $searchPattern = str_replace(' ', '', $keyword);
        
        // Check if keyword exists in filename or item text
        if (strpos($filename, $searchPattern) !== false || 
            strpos($filename, str_replace(' ', '_', $keyword)) !== false) {
            if (strpos($itemText, $searchPattern) !== false || 
                strpos($itemText, str_replace(' ', '', $keyword)) !== false) {
                $score += 25;
                $matchedKeywords[] = $keyword;
            }
        }
    }
    
    // Calculate Jaccard similarity on words
    $filenameWords = explode(' ', $filename);
    $itemWords = explode(' ', $itemText);
    
    $intersection = count(array_intersect($filenameWords, $itemWords));
    $union = count(array_unique(array_merge($filenameWords, $itemWords)));
    
    $jaccardScore = $union > 0 ? ($intersection / $union) * 100 : 0;
    
    // Combine scores
    $finalScore = min(100, ($score + $jaccardScore) / 2);
    
    // Boost for field type
    if ($fieldType == 'title') {
        $finalScore = $finalScore * 1.2;
    }
    
    return min(100, $finalScore);
}

/**
 * Calculate category similarity
 */
function calculateCategorySimilarity($imagePath, $itemCategory) {
    if (empty($itemCategory)) {
        return 0;
    }
    
    $filename = basename($imagePath);
    $filename = strtolower(pathinfo($filename, PATHINFO_FILENAME));
    
    $categoryMapping = [
        'Electronics' => ['phone', 'laptop', 'charger', 'earphone', 'headphone', 'powerbank', 'tablet', 'ipad', 'macbook', 'earbuds'],
        'Documents' => ['id card', 'student id', 'passport', 'license', 'certificate', 'card', 'matric'],
        'Accessories' => ['watch', 'glasses', 'sunglasses', 'belt', 'hat', 'cap'],
        'Clothing' => ['shirt', 'jacket', 'hoodie', 'pants', 'jeans', 'dress'],
        'Books' => ['book', 'notebook', 'textbook', 'journal', 'novel', 'magazine'],
        'Wallet' => ['wallet', 'purse', 'cardholder', 'money'],
        'Keys' => ['key', 'keys', 'keychain', 'keyring'],
        'Bag' => ['bag', 'backpack', 'handbag', 'tote', 'luggage', 'sling'],
        'Jewelry' => ['bracelet', 'necklace', 'ring', 'earring', 'pendant'],
        'Household' => ['bottle', 'cup', 'mug', 'container', 'flask', 'water bottle']
    ];
    
    $score = 0;
    
    foreach ($categoryMapping as $category => $keywords) {
        if ($category == $itemCategory) {
            foreach ($keywords as $keyword) {
                if (strpos($filename, $keyword) !== false) {
                    $score = 100;
                    break 2;
                }
            }
            // Partial match based on common words
            $score = 50;
            break;
        }
    }
    
    return $score;
}

/**
 * Get match reason based on scores
 */
function getMatchReason($item) {
    $reasons = [];
    
    if ($item['visual_score'] >= 50) {
        $reasons[] = "visually similar";
    }
    if ($item['title_score'] >= 40) {
        $reasons[] = "similar title";
    }
    if ($item['description_score'] >= 30) {
        $reasons[] = "matching description";
    }
    if ($item['category_score'] >= 50) {
        $reasons[] = "same category";
    }
    
    if (empty($reasons)) {
        return "potential match";
    }
    
    return implode(" and ", $reasons);
}

/**
 * Get match level based on score
 */
function getMatchLevel($score) {
    if ($score >= 70) return 'Very Likely Match';
    if ($score >= 45) return 'Possible Match';
    if ($score >= 25) return 'Low Match';
    return 'Potential Match';
}

/**
 * Get full server path for image
 */
function getFullImagePath($relativePath) {
    return realpath(__DIR__ . '/../' . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
}

/**
 * Find Python executable path
 */
function findPythonPath() {
    // Check if Python path is cached in session
    if (isset($_SESSION['python_path'])) {
        return $_SESSION['python_path'];
    }
    
    $pythonPaths = [
        'python',
        'python3',
        'C:\\Python39\\python.exe',
        'C:\\Python310\\python.exe',
        'C:\\Python311\\python.exe',
        'C:\\Python312\\python.exe',
        'C:\\Python313\\python.exe',
        'C:\\Python314\\python.exe',
        'C:\\Users\\' . getenv('USERNAME') . '\\AppData\\Local\\Programs\\Python\\Python312\\python.exe',
        '/usr/bin/python3',
        '/usr/local/bin/python3'
    ];
    
    foreach ($pythonPaths as $path) {
        $testCommand = escapeshellcmd($path . ' --version');
        $testOutput = shell_exec($testCommand . ' 2>&1');
        if ($testOutput !== null && strpos($testOutput, 'Python') !== false) {
            $_SESSION['python_path'] = $path;
            return $path;
        }
    }
    
    return null;
}
?>