<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/mobile_api.php';

configureErrorHandling();

header('Content-Type: application/json; charset=utf-8');

try {
    $mobileApiDb = Database::getInstance()->getConnection();
    mobileApiEnsureSchema($mobileApiDb);
} catch (Throwable $exception) {
    error_log('Mobile API bootstrap error: ' . $exception->getMessage());
    mobileApiError('Unable to initialize mobile API.', 500, null, 'bootstrap_failed');
}
