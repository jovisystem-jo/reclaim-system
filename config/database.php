<?php
require_once __DIR__ . '/../includes/security.php';
if (file_exists(__DIR__ . '/env.php')) {
    require_once __DIR__ . '/env.php';
}

configureErrorHandling();

// Timezone — default to Malaysia Time (UTC+8); override via APP_TIMEZONE in .env
$appTimezone = getenv('APP_TIMEZONE') ?: 'Asia/Kuala_Lumpur';
if (@date_default_timezone_set($appTimezone) === false) {
    date_default_timezone_set('Asia/Kuala_Lumpur');
}

// Database configuration
$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbPort = getenv('DB_PORT') ?: '3306';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: '';
$dbName = getenv('DB_NAME') ?: 'lost_and_found_db';

define('DB_HOST', $dbHost);
define('DB_PORT', $dbPort);
define('DB_USER', $dbUser);
define('DB_PASS', $dbPass);
define('DB_NAME', $dbName);

class Database {
    private static $instance = null;
    private $connection;
    
    private function __construct() {
        try {
            $this->connection = new PDO(
                "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );

            // Sync MySQL session timezone with PHP
            $offset = (new DateTimeZone(date_default_timezone_get()))->getOffset(new DateTime('now', new DateTimeZone('UTC')));
            $sign   = $offset >= 0 ? '+' : '-';
            $abs    = abs($offset);
            $tzStr  = sprintf("%s%02d:%02d", $sign, intdiv($abs, 3600), ($abs % 3600) / 60);
            $this->connection->exec("SET time_zone = '{$tzStr}'"  );
        } catch(PDOException $e) {
            // Log the real error, but do not expose database details to users.
            error_log("Database connection failed: " . $e->getMessage());
            die("Database connection failed. Please try again later.");
        }
    }
    
    public static function getInstance() {
        if (self::$instance == null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->connection;
    }
}

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    secureSessionStart();
}
?>
