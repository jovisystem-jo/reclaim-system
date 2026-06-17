<?php
require_once 'includes/security.php';
configureErrorHandling();

// Security: installer should only run from localhost.
$remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
if (!in_array($remoteAddr, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('Installer is only available from localhost.');
}

echo "<h1>Reclaim System Installation</h1>";
echo "<pre>";

// Check PHP version
if (version_compare(PHP_VERSION, '7.4.0', '<')) {
    die("PHP 7.4 or higher is required. You have " . PHP_VERSION);
}
echo "[OK] PHP version: " . PHP_VERSION . "\n";

// Check extensions
$required_extensions = ['pdo_mysql', 'mysqli', 'gd', 'fileinfo', 'json'];
foreach ($required_extensions as $ext) {
    if (extension_loaded($ext)) {
        echo "[OK] $ext extension loaded\n";
    } else {
        echo "[MISSING] $ext extension missing\n";
        $error = true;
    }
}

// Create database tables
require_once 'config/database.php';

try {
    $db = Database::getInstance()->getConnection();

    if (!file_exists('reclaim_system.sql')) {
        die("Database setup file reclaim_system.sql was not found.\n");
    }

    $sql = file_get_contents('reclaim_system.sql');
    $statements = explode(';', $sql);

    foreach ($statements as $statement) {
        $statement = trim($statement);
        if (!empty($statement)) {
            $db->exec($statement);
        }
    }

    echo "[OK] Database tables created successfully\n";

    // Check if admin exists
    $stmt = $db->query("SELECT COUNT(*) FROM users WHERE role = 'admin'");
    if ($stmt->fetchColumn() == 0) {
        // Security: do not create or print a default password.
        $adminPassword = getenv('INSTALL_ADMIN_PASSWORD') ?: '';
        if ($adminPassword !== '') {
            $adminHash = password_hash($adminPassword, PASSWORD_DEFAULT);
            $stmt = $db->prepare("
                INSERT INTO users (username, name, email, password, role, is_active)
                VALUES (?, ?, ?, ?, 'admin', 1)
            ");
            $stmt->execute(['admin', 'Administrator', 'admin@reclaim.com', $adminHash]);
            echo "[OK] Admin account created from INSTALL_ADMIN_PASSWORD\n";
        } else {
            echo "[OK] No default admin password created. Set INSTALL_ADMIN_PASSWORD to create the first admin.\n";
        }
    }
} catch (PDOException $e) {
    error_log("Install database error: " . $e->getMessage());
    die("Database setup failed. Check the server error log.\n");
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
        // Security: avoid world-writable upload directories.
        mkdir($dir, 0755, true);
        echo "[OK] Created directory: $dir\n";
    }
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
echo "[OK] Created environment configuration\n";

echo "\n";
echo "========================================\n";
echo "Installation Complete!\n";
echo "========================================\n";
echo "\n";
echo "Next steps:\n";
echo "1. Configure your database in config/database.php\n";
echo "2. Configure SMTP_USERNAME and SMTP_PASSWORD environment variables for email\n";
echo "3. Configure IMAGGA_API_KEY / IMAGGA_API_SECRET if image tagging is needed\n";
echo "4. Access the website: " . $_SERVER['HTTP_HOST'] . "/reclaim-system/\n";
echo "\n";
echo "Default admin credentials are not printed for security.\n";
echo "========================================\n";

echo "</pre>";
?>
