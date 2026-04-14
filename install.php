<?php
echo "<h1>Reclaim System Installation</h1>";
echo "<pre>";

// Check PHP version
if (version_compare(PHP_VERSION, '7.4.0', '<')) {
    die("PHP 7.4 or higher is required. You have " . PHP_VERSION);
}
echo "✓ PHP version: " . PHP_VERSION . "\n";

// Check extensions
$required_extensions = ['pdo_mysql', 'mysqli', 'gd', 'fileinfo', 'json'];
foreach ($required_extensions as $ext) {
    if (extension_loaded($ext)) {
        echo "✓ $ext extension loaded\n";
    } else {
        echo "✗ $ext extension missing\n";
        $error = true;
    }
}

// Create database tables
require_once 'config/database.php';

try {
    $db = Database::getInstance()->getConnection();
    
    // Read SQL file
    $sql = file_get_contents('reclaim_system.sql');
    
    // Split SQL statements
    $statements = explode(';', $sql);
    
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if (!empty($statement)) {
            $db->exec($statement);
        }
    }
    
    echo "✓ Database tables created successfully\n";
    
    // Check if admin exists
    $stmt = $db->query("SELECT COUNT(*) FROM users WHERE role = 'admin'");
    if ($stmt->fetchColumn() == 0) {
        // Create default admin
        $default_password = password_hash('admin123', PASSWORD_DEFAULT);
        $stmt = $db->prepare("
            INSERT INTO users (username, name, email, password_hash, role, is_active) 
            VALUES (?, ?, ?, ?, 'admin', 1)
        ");
        $stmt->execute(['admin', 'Administrator', 'admin@reclaim.com', $default_password]);
        echo "✓ Default admin created (username: admin, password: admin123)\n";
    }
    
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage() . "\n");
}

// Create uploads directory
$directories = [
    'assets/uploads/',
    'assets/uploads/items/',
    'assets/uploads/proofs/',
    'assets/uploads/temp/'
];

foreach ($directories as $dir) {
    if (!file_exists($dir)) {
        mkdir($dir, 0777, true);
        echo "✓ Created directory: $dir\n";
    }
}

// Create .htaccess if not exists
if (!file_exists('.htaccess')) {
    copy('.htaccess.example', '.htaccess');
    echo "✓ Created .htaccess file\n";
}

// Create config file for environment
$env_content = '<?php
// Environment configuration
define("APP_NAME", "Reclaim System");
define("APP_URL", "http://localhost/reclaim-system");
define("APP_ENV", "development"); // development or production

// Timezone
date_default_timezone_set("UTC");

// Debug mode
if (APP_ENV === "development") {
    error_reporting(E_ALL);
    ini_set("display_errors", 1);
} else {
    error_reporting(0);
    ini_set("display_errors", 0);
}
?>';

file_put_contents('config/env.php', $env_content);
echo "✓ Created environment configuration\n";

echo "\n";
echo "========================================\n";
echo "Installation Complete!\n";
echo "========================================\n";
echo "\n";
echo "Next steps:\n";
echo "1. Configure your database in config/database.php\n";
echo "2. Configure email settings in config/mail.php\n";
echo "3. Set up Google Vision API credentials (for image search)\n";
echo "4. Access the website: " . $_SERVER['HTTP_HOST'] . "/reclaim-system/\n";
echo "\n";
echo "Default Admin Login:\n";
echo "Username: admin\n";
echo "Password: admin123\n";
echo "\n";
echo "IMPORTANT: Change the admin password after first login!\n";
echo "========================================\n";

echo "</pre>";
?>