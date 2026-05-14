<?php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/claim_status.php';

const MOBILE_API_ALLOWED_ROLE = 'student';
const MOBILE_API_TOKEN_TTL_SECONDS = 2592000;

function mobileApiEnsureSchema(PDO $db): void
{
    static $ensured = false;

    if ($ensured) {
        return;
    }

    reclaimEnsureClaimStatusSchema($db);

    $db->exec("
        CREATE TABLE IF NOT EXISTS mobile_api_tokens (
            token_id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token_hash CHAR(64) NOT NULL UNIQUE,
            device_name VARCHAR(190) NULL,
            user_agent VARCHAR(255) NULL,
            last_used_at DATETIME NULL,
            expires_at DATETIME NOT NULL,
            revoked_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_mobile_api_tokens_user (user_id),
            INDEX idx_mobile_api_tokens_expires (expires_at),
            CONSTRAINT fk_mobile_api_tokens_user
                FOREIGN KEY (user_id) REFERENCES users(user_id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $ensured = true;
}

function mobileApiUsersColumnExists(PDO $db, string $columnName): bool
{
    static $columnsByConnection = [];
    $cacheKey = spl_object_id($db);

    if (!isset($columnsByConnection[$cacheKey])) {
        $columnsByConnection[$cacheKey] = [];
        foreach ($db->query('SHOW COLUMNS FROM users') as $column) {
            $columnsByConnection[$cacheKey][$column['Field']] = true;
        }
    }

    return isset($columnsByConnection[$cacheKey][$columnName]);
}

function mobileApiProjectBasePath(): string
{
    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $marker = '/api/mobile/';
    $position = strpos($scriptName, $marker);

    if ($position !== false) {
        $basePath = substr($scriptName, 0, $position);
        return rtrim($basePath, '/') . '/';
    }

    return '/';
}

function mobileApiOrigin(): string
{
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443)
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

    $scheme = $isHttps ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    return $scheme . '://' . $host;
}

function mobileApiProjectBaseUrl(): string
{
    return rtrim(mobileApiOrigin(), '/') . mobileApiProjectBasePath();
}

function mobileApiFileUrl(?string $path): ?string
{
    if (empty($path)) {
        return null;
    }

    return getImageUrl($path, mobileApiProjectBaseUrl());
}

function mobileApiJson(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit();
}

function mobileApiSuccess($data = null, string $message = 'OK', int $status = 200): never
{
    $payload = [
        'success' => true,
        'message' => $message,
    ];

    if ($data !== null) {
        $payload['data'] = $data;
    }

    mobileApiJson($payload, $status);
}

function mobileApiError(string $message, int $status = 400, ?array $errors = null, ?string $errorCode = null): never
{
    $payload = [
        'success' => false,
        'message' => $message,
    ];

    if ($errorCode !== null) {
        $payload['error_code'] = $errorCode;
    }

    if ($errors !== null) {
        $payload['errors'] = $errors;
    }

    mobileApiJson($payload, $status);
}

function mobileApiRequireMethod(array $allowedMethods): string
{
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $allowedMethods = array_map('strtoupper', $allowedMethods);

    if (!in_array($method, $allowedMethods, true)) {
        header('Allow: ' . implode(', ', $allowedMethods));
        mobileApiError('Method not allowed.', 405, null, 'method_not_allowed');
    }

    return $method;
}

function mobileApiRequestData(): array
{
    if (!empty($_POST)) {
        return $_POST;
    }

    $rawBody = file_get_contents('php://input');
    if ($rawBody === false || trim($rawBody) === '') {
        return [];
    }

    $decoded = json_decode($rawBody, true);
    return is_array($decoded) ? $decoded : [];
}

function mobileApiTrimmed(array $data): array
{
    $trimmed = [];

    foreach ($data as $key => $value) {
        $trimmed[$key] = is_string($value) ? trim($value) : $value;
    }

    return $trimmed;
}

function mobileApiBearerToken(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

    if ($header === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }

    if (!preg_match('/Bearer\s+(.+)/i', (string) $header, $matches)) {
        return null;
    }

    return trim($matches[1]);
}

function mobileApiTokenHash(string $token): string
{
    return hash('sha256', $token);
}

function mobileApiIssueToken(PDO $db, int $userId, string $deviceName = ''): string
{
    mobileApiEnsureSchema($db);

    $plainToken = 'rma_' . bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', time() + MOBILE_API_TOKEN_TTL_SECONDS);
    $userAgent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

    $stmt = $db->prepare("
        INSERT INTO mobile_api_tokens (user_id, token_hash, device_name, user_agent, last_used_at, expires_at)
        VALUES (?, ?, ?, ?, NOW(), ?)
    ");
    $stmt->execute([
        $userId,
        mobileApiTokenHash($plainToken),
        $deviceName !== '' ? $deviceName : null,
        $userAgent !== '' ? $userAgent : null,
        $expiresAt,
    ]);

    return $plainToken;
}

function mobileApiRevokeToken(PDO $db, string $plainToken): void
{
    mobileApiEnsureSchema($db);

    $stmt = $db->prepare("
        UPDATE mobile_api_tokens
        SET revoked_at = NOW()
        WHERE token_hash = ? AND revoked_at IS NULL
    ");
    $stmt->execute([mobileApiTokenHash($plainToken)]);
}

function mobileApiAuthenticate(PDO $db, array $allowedRoles = [MOBILE_API_ALLOWED_ROLE]): array
{
    mobileApiEnsureSchema($db);

    $plainToken = mobileApiBearerToken();
    if ($plainToken === null || $plainToken === '') {
        mobileApiError('Authentication token is required.', 401, null, 'missing_token');
    }

    $tokenHash = mobileApiTokenHash($plainToken);
    $stmt = $db->prepare("
        SELECT
            t.token_id,
            t.user_id AS token_user_id,
            t.expires_at,
            t.revoked_at,
            u.*
        FROM mobile_api_tokens t
        JOIN users u ON u.user_id = t.user_id
        WHERE t.token_hash = ?
        LIMIT 1
    ");
    $stmt->execute([$tokenHash]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$record) {
        mobileApiError('Authentication token is invalid.', 401, null, 'invalid_token');
    }

    if (!empty($record['revoked_at'])) {
        mobileApiError('Authentication token has been revoked.', 401, null, 'revoked_token');
    }

    if (strtotime((string) $record['expires_at']) < time()) {
        mobileApiError('Authentication token has expired.', 401, null, 'expired_token');
    }

    if (!(bool) ($record['is_active'] ?? true)) {
        mobileApiError('Your account is inactive.', 403, null, 'inactive_account');
    }

    if (!in_array((string) $record['role'], $allowedRoles, true)) {
        mobileApiError('This mobile app is available only for student user accounts.', 403, null, 'role_not_allowed');
    }

    $touchStmt = $db->prepare("UPDATE mobile_api_tokens SET last_used_at = NOW() WHERE token_id = ?");
    $touchStmt->execute([$record['token_id']]);

    return [
        'user' => $record,
        'token' => $plainToken,
        'token_id' => (int) $record['token_id'],
    ];
}

function mobileApiPasswordError(string $password): string
{
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

function mobileApiNotificationPayload(array $notification): array
{
    return [
        'id' => (int) ($notification['notification_id'] ?? 0),
        'title' => (string) ($notification['title'] ?? ''),
        'message' => (string) ($notification['message'] ?? ''),
        'type' => (string) ($notification['type'] ?? 'info'),
        'is_read' => (bool) ($notification['is_read'] ?? false),
        'created_at' => $notification['created_at'] ?? null,
        'time_ago' => !empty($notification['created_at']) ? timeAgo($notification['created_at']) : null,
    ];
}

function mobileApiUserPayload(array $user): array
{
    return [
        'id' => (int) ($user['user_id'] ?? 0),
        'name' => (string) ($user['name'] ?? ''),
        'email' => (string) ($user['email'] ?? ''),
        'username' => (string) ($user['username'] ?? ''),
        'role' => (string) ($user['role'] ?? ''),
        'student_staff_id' => (string) ($user['student_staff_id'] ?? ''),
        'department' => (string) ($user['department'] ?? ''),
        'phone' => (string) ($user['phone'] ?? ''),
        'profile_image_url' => mobileApiFileUrl($user['profile_image'] ?? null),
        'profile_image_path' => !empty($user['profile_image']) ? (string) $user['profile_image'] : null,
        'email_verified' => !empty($user['email_verified_at']),
        'created_at' => $user['created_at'] ?? null,
        'last_login' => $user['last_login'] ?? null,
    ];
}

function mobileApiItemPayload(array $item): array
{
    return [
        'id' => (int) ($item['item_id'] ?? 0),
        'title' => (string) ($item['title'] ?? ''),
        'description' => (string) ($item['description'] ?? ''),
        'category' => (string) ($item['category'] ?? ''),
        'brand' => (string) ($item['brand'] ?? ''),
        'color' => (string) ($item['color'] ?? ''),
        'status' => (string) ($item['status'] ?? ''),
        'location' => (string) ($item['location'] ?? ''),
        'found_location' => (string) ($item['found_location'] ?? ''),
        'delivery_location' => (string) ($item['delivery_location'] ?? ''),
        'date_found' => $item['date_found'] ?? null,
        'reported_date' => $item['reported_date'] ?? ($item['created_at'] ?? null),
        'image_url' => mobileApiFileUrl($item['image_url'] ?? null),
        'image_path' => !empty($item['image_url']) ? (string) $item['image_url'] : null,
        'reported_by' => isset($item['reported_by']) ? (int) $item['reported_by'] : null,
        'reporter_name' => $item['reporter_name'] ?? null,
        'reporter_profile_image_url' => mobileApiFileUrl($item['reporter_profile_image'] ?? null),
        'claim_count' => isset($item['claim_count']) ? (int) $item['claim_count'] : null,
        'user_has_claimed' => isset($item['user_has_claimed']) ? (bool) $item['user_has_claimed'] : null,
    ];
}

function mobileApiClaimPayload(array $claim): array
{
    return [
        'id' => (int) ($claim['claim_id'] ?? 0),
        'item_id' => (int) ($claim['item_id'] ?? 0),
        'claimant_id' => (int) ($claim['claimant_id'] ?? 0),
        'status' => (string) ($claim['status'] ?? ''),
        'claimant_description' => (string) ($claim['claimant_description'] ?? ''),
        'admin_notes' => (string) ($claim['admin_notes'] ?? ''),
        'proof_image_url' => mobileApiFileUrl($claim['proof_image_url'] ?? ($claim['proof_imageURL'] ?? null)),
        'created_at' => $claim['created_at'] ?? null,
        'verified_date' => $claim['verified_date'] ?? null,
        'item' => [
            'id' => (int) ($claim['item_id'] ?? 0),
            'title' => (string) ($claim['item_title'] ?? ''),
            'description' => (string) ($claim['item_description'] ?? ''),
            'status' => (string) ($claim['item_status'] ?? ''),
            'category' => (string) ($claim['category'] ?? ''),
            'found_location' => (string) ($claim['found_location'] ?? ''),
            'image_url' => mobileApiFileUrl($claim['image_url'] ?? null),
            'reporter_name' => $claim['reporter_name'] ?? null,
        ],
    ];
}

function mobileApiPagination(int $total, int $page, int $perPage): array
{
    $totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 1;

    return [
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'total_pages' => max($totalPages, 1),
    ];
}

function mobileApiDepartments(): array
{
    return [
        'Faculty of Civil Engineering and Built Environment (FKAAB)',
        'Faculty of Electric and Electronic Engineering (FKEE)',
        'Faculty of Mechanical and Manufacturing Engineering (FKMP)',
        'Faculty of Technical and Vocational Education (FPTV)',
        'Faculty of Technology Management and Business (FPTP)',
        'Faculty of Applied Science and Technology (FAST)',
        'Faculty of Science Computer and Information Technology (FSKTM)',
        'Faculty of Engineering Technology (FTK)',
    ];
}

function mobileApiCategories(): array
{
    return [
        'Electronics',
        'Documents',
        'Accessories',
        'Clothing',
        'Books',
        'Wallet',
        'Keys',
        'Bag',
        'Jewelry',
        'Others',
    ];
}

function mobileApiColors(): array
{
    return [
        'Black', 'White', 'Red', 'Blue', 'Green', 'Yellow', 'Purple', 'Pink',
        'Orange', 'Brown', 'Grey', 'Silver', 'Gold', 'Navy', 'Beige', 'Multicolor', 'Other',
    ];
}

function mobileApiDeliveryLocations(): array
{
    return [
        'I keep it myself',
        'Security Office - Main Gate',
        'Security Office - North Gate',
        'Auxiliary Police and Security Office',
        'Other (Please specify)',
    ];
}

function mobileApiBrandsByCategory(): array
{
    return [
        'Electronics' => [
            'Apple', 'Samsung', 'Sony', 'LG', 'Dell', 'HP', 'Lenovo', 'Asus', 'Acer', 'Microsoft',
            'Panasonic', 'Toshiba', 'Philips', 'Bose', 'JBL', 'Beats', 'Xiaomi', 'OnePlus', 'Google', 'Nintendo', 'Other',
        ],
        'Documents' => [
            'Passport', 'Driver License', 'Student ID', 'Work ID', 'Birth Certificate', 'Marriage Certificate',
            'Diploma', 'Degree', 'Transcript', 'Visa', 'Insurance Card', 'Bank Card', 'Credit Card', 'Other',
        ],
        'Accessories' => [
            'Apple', 'Samsung', 'Sony', 'Bose', 'JBL', 'Beats', 'Logitech', 'Razer', 'Corsair', 'SteelSeries',
            'Fossil', 'Casio', 'Garmin', 'Fitbit', 'Xiaomi', 'Anker', 'Belkin', 'Spigen', 'OtterBox', 'Other',
        ],
        'Clothing' => [
            'Nike', 'Adidas', 'Puma', 'Under Armour', 'H&M', 'Zara', 'Uniqlo', 'Levi\'s', 'Calvin Klein',
            'Tommy Hilfiger', 'Lacoste', 'Ralph Lauren', 'Gucci', 'Louis Vuitton', 'Versace', 'Other',
        ],
        'Books' => [
            'Oxford', 'Cambridge', 'Pearson', 'McGraw-Hill', 'Wiley', 'Penguin', 'HarperCollins', 'Simon & Schuster',
            'Random House', 'Scholastic', 'Elsevier', 'Springer', 'Taylor & Francis', 'Other',
        ],
        'Wallet' => [
            'Gucci', 'Louis Vuitton', 'Prada', 'Hermes', 'Coach', 'Michael Kors', 'Fossil', 'Calvin Klein',
            'Tommy Hilfiger', 'Polo Ralph Lauren', 'Secrid', 'Bellroy', 'Other',
        ],
        'Keys' => [
            'Toyota', 'Honda', 'BMW', 'Mercedes', 'Audi', 'Volkswagen', 'Ford', 'Hyundai', 'Kia', 'Mazda',
            'Nissan', 'Subaru', 'Volvo', 'Lexus', 'Tesla', 'Yale', 'Schlage', 'Master Lock', 'Other',
        ],
        'Bag' => [
            'Nike', 'Adidas', 'Puma', 'North Face', 'JanSport', 'Samsonite', 'Herschel', 'Fjallraven',
            'Gucci', 'Louis Vuitton', 'Prada', 'Coach', 'Michael Kors', 'Tumi', 'Other',
        ],
        'Jewelry' => [
            'Tiffany & Co.', 'Cartier', 'Pandora', 'Swarovski', 'David Yurman', 'Bvlgari', 'Van Cleef & Arpels',
            'Chanel', 'Dior', 'Gucci', 'Rolex', 'Omega', 'Seiko', 'Citizen', 'Casio', 'Other',
        ],
        'Others' => ['Generic', 'No Brand', 'Custom Made', 'Handmade', 'Vintage', 'Limited Edition', 'Other'],
    ];
}
