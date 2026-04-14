<?php
use Google\Cloud\Vision\V1\ImageAnnotatorClient;

echo "<h1>Google Cloud Vision API Test</h1>";

// Check if key file exists
$keyFile = __DIR__ . '/config/google-cloud-key.json';
echo "<p>Key file path: " . $keyFile . "</p>";
echo "<p>Key file exists: " . (file_exists($keyFile) ? '✅ Yes' : '❌ No') . "</p>";

if (file_exists($keyFile)) {
    // Read the key file content to verify it's valid JSON
    $content = file_get_contents($keyFile);
    $json = json_decode($content, true);
    
    if ($json) {
        echo "<p>✅ JSON is valid</p>";
        echo "<p>Project ID from key file: " . ($json['project_id'] ?? 'Not found') . "</p>";
        echo "<p>Client Email: " . ($json['client_email'] ?? 'Not found') . "</p>";
        echo "<p>Private Key ID: " . ($json['private_key_id'] ?? 'Not found') . "</p>";
    } else {
        echo "<p>❌ Invalid JSON in key file</p>";
    }
}

// Check if composer autoload exists
$vendorAutoload = __DIR__ . '/vendor/autoload.php';
echo "<p>Vendor autoload exists: " . (file_exists($vendorAutoload) ? '✅ Yes' : '❌ No') . "</p>";

if (file_exists($vendorAutoload)) {
    require_once $vendorAutoload;
    
    try {
        $vision = new ImageAnnotatorClient([
            'keyFilePath' => $keyFile
        ]);
        
        echo "<p style='color:green'>✅ Vision client created successfully!</p>";
        $vision->close();
        
    } catch (Exception $e) {
        echo "<p style='color:red'>❌ Error: " . $e->getMessage() . "</p>";
    }
} else {
    echo "<p style='color:red'>❌ Please run: composer require google/cloud-vision</p>";
}
?>