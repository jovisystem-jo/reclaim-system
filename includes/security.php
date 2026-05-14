<?php
/**
 * Shared security helpers.
 * These functions keep security checks consistent across the project.
 */

function loadEnvFile($path = null) {
    $path = $path ?: __DIR__ . '/../.env';

    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
            continue;
        }

        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value, " \t\n\r\0\x0B\"'");

        // Security: do not overwrite variables already set by Apache/Windows.
        if ($name !== '' && getenv($name) === false) {
            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

loadEnvFile();

function app_is_production() {
    $env = getenv('APP_ENV') ?: (defined('APP_ENV') ? APP_ENV : 'development');
    return strtolower((string) $env) === 'production';
}

function configureErrorHandling() {
    if (app_is_production()) {
        // In production, never show stack traces or database errors to users.
        error_reporting(0);
        ini_set('display_errors', '0');
    }
}

function secureSessionStart() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    // Harden cookies before the session starts.
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_secure', $isHttps ? '1' : '0');

    $savePath = (string) ini_get('session.save_path');
    $normalizedSavePath = trim(explode(';', $savePath)[0] ?? '');

    if ($normalizedSavePath === '' || !is_dir($normalizedSavePath) || !is_writable($normalizedSavePath)) {
        $fallbackPath = __DIR__ . '/../storage/sessions';
        if (!is_dir($fallbackPath)) {
            mkdir($fallbackPath, 0755, true);
        }
        if (is_dir($fallbackPath) && is_writable($fallbackPath)) {
            session_save_path($fallbackPath);
        }
    }

    session_start();
}

function csrf_token() {
    secureSessionStart();

    if (empty($_SESSION['csrf_token'])) {
        // Random token prevents forged POST/JSON requests from other sites.
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function verify_csrf_token($token) {
    secureSessionStart();
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function request_csrf_token($data = null) {
    $headers = function_exists('getallheaders') ? getallheaders() : [];

    return $_POST['csrf_token']
        ?? $_GET['csrf_token']
        ?? ($data['csrf_token'] ?? null)
        ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)
        ?? ($headers['X-CSRF-Token'] ?? $headers['x-csrf-token'] ?? null);
}

function require_csrf_token($data = null) {
    if (!verify_csrf_token(request_csrf_token($data))) {
        http_response_code(403);
        exit('Invalid security token. Please refresh the page and try again.');
    }
}

function json_request_body() {
    $data = json_decode(file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

function secure_image_upload($file, $uploadDir, $relativeDir, $maxBytes = 2097152) {
    if (!isset($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['success' => true, 'path' => ''];
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'Image upload failed. Please try again.'];
    }

    if (($file['size'] ?? 0) > $maxBytes) {
        return ['success' => false, 'message' => 'Image too large. Max 2MB.'];
    }

    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return ['success' => false, 'message' => 'Invalid upload request.'];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    // Validate by MIME type, not by the original filename supplied by the browser.
    $allowedTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    if (!isset($allowedTypes[$mimeType])) {
        return ['success' => false, 'message' => 'Invalid image format. Allowed: JPG, PNG'];
    }

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        return ['success' => false, 'message' => 'Upload folder is not available.'];
    }

    $uploadDirReal = realpath($uploadDir);
    if ($uploadDirReal === false) {
        return ['success' => false, 'message' => 'Upload folder is not available.'];
    }

    // Random filenames prevent traversal and hide original user filenames.
    $fileName = bin2hex(random_bytes(16)) . '.' . $allowedTypes[$mimeType];
    $targetPath = $uploadDirReal . DIRECTORY_SEPARATOR . $fileName;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        return ['success' => false, 'message' => 'Failed to save uploaded image.'];
    }

    return [
        'success' => true,
        'path' => rtrim($relativeDir, '/\\') . '/' . $fileName,
    ];
}

function delete_uploaded_file_safely($relativePath, $allowedDir) {
    if (empty($relativePath)) {
        return;
    }

    $baseReal = realpath($allowedDir);
    $targetReal = realpath(__DIR__ . '/../' . ltrim($relativePath, '/\\'));

    // Only delete files that resolve inside the upload folder.
    $insideUploadDir = $baseReal && $targetReal && strpos($targetReal, $baseReal . DIRECTORY_SEPARATOR) === 0;
    if ($insideUploadDir && is_file($targetReal)) {
        unlink($targetReal);
    }
}
?>
