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
?>