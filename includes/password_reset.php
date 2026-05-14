<?php
require_once __DIR__ . '/../config/mail.php';

const PASSWORD_RESET_TOKEN_EXPIRY_SECONDS = 3600;

function password_reset_users_column_exists(PDO $db, $columnName) {
    static $columnCache = [];
    $cacheKey = spl_object_id($db);

    if (!isset($columnCache[$cacheKey])) {
        $columnCache[$cacheKey] = [];
        foreach ($db->query('SHOW COLUMNS FROM users') as $column) {
            $columnCache[$cacheKey][$column['Field']] = true;
        }
    }

    return isset($columnCache[$cacheKey][$columnName]);
}

function password_reset_columns_available(PDO $db) {
    return password_reset_users_column_exists($db, 'verification_token')
        && password_reset_users_column_exists($db, 'token_expiry');
}

function password_reset_generate_token() {
    return bin2hex(random_bytes(32));
}

function password_reset_hash_token($token) {
    return hash('sha256', (string) $token);
}

function password_reset_password_error($password) {
    if (strlen($password) < 8) {
        return 'Password must be at least 8 characters long.';
    }

    if (!preg_match('/[A-Z]/', $password)) {
        return 'Password must include at least 1 uppercase letter.';
    }

    if (!preg_match('/[a-z]/', $password)) {
        return 'Password must include at least 1 lowercase letter.';
    }

    if (!preg_match('/\d/', $password)) {
        return 'Password must include at least 1 number.';
    }

    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        return 'Password must include at least 1 special character.';
    }

    return '';
}

function password_reset_build_url($path, array $params = []) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443);
    $scheme = $isHttps ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $basePath = ($scriptDir === '/' || $scriptDir === '.') ? '' : rtrim($scriptDir, '/');
    $url = $scheme . '://' . $host . $basePath . '/' . ltrim($path, '/');

    if ($params) {
        $url .= '?' . http_build_query($params);
    }

    return $url;
}

function password_reset_send_email($email, $name, $resetLink) {
    $safeName = htmlspecialchars($name !== '' ? $name : 'User', ENT_QUOTES, 'UTF-8');
    $safeLink = htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8');
    $expiryMinutes = (int) (PASSWORD_RESET_TOKEN_EXPIRY_SECONDS / 60);
    $subject = 'Reset your Reclaim System password';
    $body = '
        <div style="font-family: Arial, sans-serif; max-width: 560px; margin: 0 auto; color: #1f2937;">
            <h2 style="margin-bottom: 16px; color: #111827;">Password reset request</h2>
            <p style="margin-bottom: 16px;">Hi ' . $safeName . ',</p>
            <p style="margin-bottom: 16px;">
                We received a request to reset your Reclaim System password.
            </p>
            <p style="margin-bottom: 16px;">
                Click the button below to choose a new password:
            </p>
            <p style="margin: 24px 0;">
                <a href="' . $safeLink . '" style="display: inline-block; padding: 12px 20px; background: #f59e0b; color: #ffffff; text-decoration: none; border-radius: 8px; font-weight: 700;">Reset Password</a>
            </p>
            <p style="margin-bottom: 16px;">
                This link will expire in ' . $expiryMinutes . ' minutes.
            </p>
            <p style="margin-bottom: 16px;">
                If the button does not work, copy and paste this link into your browser:
            </p>
            <p style="word-break: break-all; margin-bottom: 16px;">' . $safeLink . '</p>
            <p style="margin-bottom: 0;">If you did not request a password reset, you can ignore this email.</p>
        </div>
    ';

    return MailConfig::sendNotification($email, $subject, $body);
}
