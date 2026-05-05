<?php

function reclaimEmptyAdminSignature(): array
{
    return [
        'name' => '',
        'id' => '',
        'position' => 'Administrator',
        'department' => 'Auxiliary Police and Security Office',
        'image' => '',
        'image_path' => '',
        'updated_at' => ''
    ];
}

function reclaimDefaultAdminSignature(): array
{
    $defaults = reclaimEmptyAdminSignature();
    $defaults['name'] = $_SESSION['name'] ?? '';
    $defaults['id'] = isset($_SESSION['userID']) ? (string) $_SESSION['userID'] : '';

    return $defaults;
}

function reclaimEnsureAdminSignatureTable(PDO $db): void
{
    static $ensured = false;

    if ($ensured) {
        return;
    }

    $db->exec("
        CREATE TABLE IF NOT EXISTS admin_signatures (
            admin_user_id INT NOT NULL PRIMARY KEY,
            signature_name VARCHAR(255) NOT NULL,
            signature_staff_id VARCHAR(100) NOT NULL,
            signature_position VARCHAR(255) DEFAULT NULL,
            signature_department VARCHAR(255) DEFAULT NULL,
            signature_image VARCHAR(255) DEFAULT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $ensured = true;
}

function reclaimNormalizeSignatureImagePath(?string $path): string
{
    $path = trim((string) $path);

    if ($path === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }

    $path = str_replace('\\', '/', $path);
    $path = preg_replace('#^/+#', '', $path);
    $path = preg_replace('#^reclaim-system/#i', '', $path);

    return $path;
}

function reclaimSignatureImageUrl(?string $path, string $baseUrl = '/reclaim-system/'): string
{
    $path = reclaimNormalizeSignatureImagePath($path);

    if ($path === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }

    return rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
}

function reclaimSignatureImageFilesystemPath(?string $path): string
{
    $path = reclaimNormalizeSignatureImagePath($path);

    if ($path === '' || preg_match('#^https?://#i', $path)) {
        return '';
    }

    return dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
}

function reclaimDeleteSignatureImage(?string $path): void
{
    $filesystemPath = reclaimSignatureImageFilesystemPath($path);

    if ($filesystemPath !== '' && is_file($filesystemPath)) {
        @unlink($filesystemPath);
    }
}

function reclaimGetAdminSignature(PDO $db, int $adminUserId, string $baseUrl = '/reclaim-system/'): array
{
    $defaults = reclaimDefaultAdminSignature();

    if ($adminUserId <= 0) {
        return $defaults;
    }

    reclaimEnsureAdminSignatureTable($db);

    $stmt = $db->prepare("
        SELECT signature_name, signature_staff_id, signature_position, signature_department, signature_image, updated_at
        FROM admin_signatures
        WHERE admin_user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$adminUserId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        if (isset($_SESSION['admin_signature']) && is_array($_SESSION['admin_signature'])) {
            $sessionSignature = array_merge($defaults, $_SESSION['admin_signature']);
            $sessionSignature['image_path'] = reclaimNormalizeSignatureImagePath($sessionSignature['image'] ?? '');
            $sessionSignature['image'] = reclaimSignatureImageUrl($sessionSignature['image_path'], $baseUrl);

            return $sessionSignature;
        }

        return $defaults;
    }

    $signature = [
        'name' => $row['signature_name'] ?: $defaults['name'],
        'id' => $row['signature_staff_id'] ?: $defaults['id'],
        'position' => $row['signature_position'] ?: $defaults['position'],
        'department' => $row['signature_department'] ?: $defaults['department'],
        'image_path' => reclaimNormalizeSignatureImagePath($row['signature_image'] ?? ''),
        'updated_at' => $row['updated_at'] ?? ''
    ];
    $signature['image'] = reclaimSignatureImageUrl($signature['image_path'], $baseUrl);

    $_SESSION['admin_signature'] = $signature;

    return $signature;
}

function reclaimSaveAdminSignature(PDO $db, int $adminUserId, array $signatureData, string $baseUrl = '/reclaim-system/'): array
{
    reclaimEnsureAdminSignatureTable($db);

    $storedImagePath = reclaimNormalizeSignatureImagePath($signatureData['image'] ?? '');

    $stmt = $db->prepare("
        INSERT INTO admin_signatures (
            admin_user_id,
            signature_name,
            signature_staff_id,
            signature_position,
            signature_department,
            signature_image
        ) VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            signature_name = VALUES(signature_name),
            signature_staff_id = VALUES(signature_staff_id),
            signature_position = VALUES(signature_position),
            signature_department = VALUES(signature_department),
            signature_image = VALUES(signature_image)
    ");
    $stmt->execute([
        $adminUserId,
        $signatureData['name'],
        $signatureData['id'],
        $signatureData['position'] ?? null,
        $signatureData['department'] ?? null,
        $storedImagePath !== '' ? $storedImagePath : null
    ]);

    return reclaimGetAdminSignature($db, $adminUserId, $baseUrl);
}
