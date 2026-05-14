<?php
require_once __DIR__ . '/../bootstrap.php';

mobileApiRequireMethod(['POST']);

$input = mobileApiTrimmed(mobileApiRequestData());
$email = strtolower((string) ($input['email'] ?? ''));
$password = (string) ($input['password'] ?? '');
$deviceName = (string) ($input['device_name'] ?? 'Android App');

if ($email === '' || $password === '') {
    mobileApiError('Email and password are required.', 422, [
        'email' => $email === '' ? 'Email is required.' : null,
        'password' => $password === '' ? 'Password is required.' : null,
    ], 'validation_failed');
}

try {
    $stmt = $mobileApiDb->prepare('SELECT * FROM users WHERE email = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($password, (string) ($user['password'] ?? ''))) {
        mobileApiError('Invalid email or password.', 401, null, 'invalid_credentials');
    }

    if (($user['role'] ?? '') !== MOBILE_API_ALLOWED_ROLE) {
        mobileApiError('Only student user accounts can sign in to the mobile app.', 403, null, 'role_not_allowed');
    }

    $token = mobileApiIssueToken($mobileApiDb, (int) $user['user_id'], $deviceName);

    try {
        $touchUser = $mobileApiDb->prepare('UPDATE users SET last_login = NOW() WHERE user_id = ?');
        $touchUser->execute([(int) $user['user_id']]);
    } catch (PDOException $ignored) {
    }

    mobileApiSuccess([
        'token' => $token,
        'token_type' => 'Bearer',
        'expires_in' => MOBILE_API_TOKEN_TTL_SECONDS,
        'user' => mobileApiUserPayload($user),
    ], 'Login successful.');
} catch (PDOException $exception) {
    error_log('Mobile login error: ' . $exception->getMessage());
    mobileApiError('Unable to login right now.', 500, null, 'login_failed');
}
