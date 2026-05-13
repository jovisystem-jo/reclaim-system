<?php
echo "<h2>OpenCV Similarity Test</h2>";

$pythonPath = 'python'; // Adjust as needed

// Get two sample images from your database
require_once '../config/database.php';
$db = Database::getInstance()->getConnection();

$stmt = $db->prepare("SELECT image_url FROM items WHERE image_url IS NOT NULL AND image_url != '' LIMIT 2");
$stmt->execute();
$images = $stmt->fetchAll();

if (count($images) >= 2) {
    $img1 = __DIR__ . '/../' . $images[0]['image_url'];
    $img2 = __DIR__ . '/../' . $images[1]['image_url'];
    
    echo "Comparing:<br>";
    echo "Image 1: " . $img1 . "<br>";
    echo "Image 2: " . $img2 . "<br><br>";
    
    $scriptPath = __DIR__ . '/compare.py';
    $command = escapeshellcmd("\"$pythonPath\" \"$scriptPath\" \"$img1\" \"$img2\"");
    echo "Command: " . $command . "<br>";
    
    $output = shell_exec($command . ' 2>&1');
    echo "Similarity Score: " . $output . "%<br>";
    
    if (is_numeric(trim($output))) {
        echo "✅ OpenCV working correctly!";
    } else {
        echo "❌ OpenCV error: " . $output;
    }
} else {
    echo "Not enough images in database to test.";
}
?>