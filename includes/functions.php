<?php
/**
 * Helper functions for the Reclaim System
 */

/**
 * Get correct image URL for display
 */
function getImageUrl($imagePath, $baseUrl) {
    if (empty($imagePath)) {
        return null;
    }
    
    // If it's already a full URL
    if (filter_var($imagePath, FILTER_VALIDATE_URL)) {
        return $imagePath;
    }
    
    // Remove any leading slashes
    $imagePath = ltrim($imagePath, '/');
    
    // If it already has assets/uploads/
    if (strpos($imagePath, 'assets/uploads/') === 0) {
        return $baseUrl . $imagePath;
    }
    
    // If it starts with uploads/
    if (strpos($imagePath, 'uploads/') === 0) {
        return $baseUrl . 'assets/' . $imagePath;
    }
    
    // Default: assume it's just a filename in assets/uploads/
    return $baseUrl . 'assets/uploads/' . $imagePath;
}

/**
 * Check if image file actually exists on server
 */
function imageFileExists($imagePath) {
    if (empty($imagePath)) {
        return false;
    }
    
    // Remove any leading slashes
    $imagePath = ltrim($imagePath, '/');
    
    // Try different possible paths
    $pathsToCheck = [
        __DIR__ . '/../' . $imagePath,
        __DIR__ . '/../assets/uploads/' . basename($imagePath),
    ];
    
    foreach ($pathsToCheck as $path) {
        if (file_exists($path)) {
            return true;
        }
    }
    
    return false;
}

/**
 * Get image HTML with proper handling
 */
function getImageHtml($imagePath, $baseUrl, $alt = 'Item image', $class = '', $style = '') {
    if (empty($imagePath) || !imageFileExists($imagePath)) {
        return '<div class="image-placeholder ' . $class . '" style="' . $style . '">
                    <i class="fas fa-box-open fa-3x"></i>
                </div>';
    }
    
    $url = getImageUrl($imagePath, $baseUrl);
    return '<img src="' . $url . '" alt="' . htmlspecialchars($alt) . '" class="' . $class . '" style="' . $style . '">';
}

/**
 * Time ago function
 */
function timeAgo($timestamp) {
    if (!$timestamp) return 'Never';
    $time_ago = strtotime($timestamp);
    $current_time = time();
    $time_difference = $current_time - $time_ago;
    
    if($time_difference < 60) return "Just now";
    if($time_difference < 3600) return floor($time_difference / 60) . " minutes ago";
    if($time_difference < 86400) return floor($time_difference / 3600) . " hours ago";
    if($time_difference < 604800) return floor($time_difference / 86400) . " days ago";
    return date('M d, Y', $time_ago);
}

/**
 * Normalize an image-derived search label into a lowercase keyword.
 */
function normalizeImageSearchLabel($label) {
    $label = strtolower(trim((string) $label));
    $label = preg_replace('/[^a-z0-9\s]+/', ' ', $label);
    $label = preg_replace('/\s+/', ' ', $label);
    return trim($label);
}

/**
 * Filter out weak fallback labels such as random hashes or camera filenames.
 */
function isMeaningfulImageLabel($label) {
    $label = normalizeImageSearchLabel($label);

    if ($label === '' || strlen(str_replace(' ', '', $label)) < 3) {
        return false;
    }

    if (preg_match('/^\d+$/', $label)) {
        return false;
    }

    if (preg_match('/^[a-f0-9]{8,}$/', str_replace(' ', '', $label))) {
        return false;
    }

    $weakLabels = [
        'img', 'image', 'photo', 'picture', 'pic', 'upload', 'camera',
        'scan', 'screenshot', 'item', 'file', 'document'
    ];

    return !in_array($label, $weakLabels, true);
}

function hasMeaningfulImageLabels($labels) {
    if (!is_array($labels)) {
        return false;
    }

    foreach ($labels as $label) {
        if (isMeaningfulImageLabel($label)) {
            return true;
        }
    }

    return false;
}

/**
 * Build a best-effort keyword list when remote image tagging is unavailable.
 */
function extractImageSearchLabelsFromFilename($originalName) {
    $baseName = pathinfo((string) $originalName, PATHINFO_FILENAME);
    $tokens = preg_split('/[^a-z0-9]+/i', strtolower($baseName)) ?: [];
    $labels = [];

    foreach ($tokens as $token) {
        if (isMeaningfulImageLabel($token)) {
            $labels[] = $token;
        }
    }

    $keywordMap = [
        'electronics' => ['laptop', 'macbook', 'notebook', 'phone', 'iphone', 'android', 'samsung', 'tablet', 'ipad', 'charger', 'airpods', 'earbuds', 'headphones'],
        'documents' => ['passport', 'license', 'id', 'student', 'document', 'certificate', 'card'],
        'wallet' => ['wallet', 'purse', 'billfold'],
        'keys' => ['key', 'keys', 'keychain', 'carkey'],
        'bag' => ['bag', 'backpack', 'handbag', 'tote', 'luggage'],
        'accessories' => ['watch', 'glasses', 'spectacles', 'bracelet'],
        'books' => ['book', 'notebook', 'journal'],
        'clothing' => ['shirt', 'jacket', 'hoodie', 'cap', 'hat', 'shoe'],
        'jewelry' => ['ring', 'necklace', 'earring', 'pendant'],
    ];

    foreach ($keywordMap as $mappedLabel => $needles) {
        foreach ($tokens as $token) {
            if (in_array($token, $needles, true)) {
                $labels[] = $mappedLabel;
                break;
            }
        }
    }

    $labels = array_values(array_unique(array_map('normalizeImageSearchLabel', $labels)));

    return !empty($labels) ? $labels : ['item'];
}
?>
