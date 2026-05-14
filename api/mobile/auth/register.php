<?php
require_once __DIR__ . '/../bootstrap.php';

mobileApiRequireMethod(['POST']);

$input = mobileApiTrimmed(mobileApiRequestData());

$name = (string) ($input['name'] ?? '');
$email = strtolower((string) ($input['email'] ?? ''));
$username = (string) ($input['username'] ?? '');
$password = (string) ($input['password'] ?? '');
$confirmPassword = (string) ($input['confirm_password'] ?? '');
$studentStaffId = (string) ($input['student_staff_id'] ?? '');
$department = (string) ($input['department'] ?? '');
$phone = (string) ($input['phone'] ?? '');
$deviceName = (string) ($input['device_name'] ?? 'Android App');

$errors = [];

if ($name === '') {
    $errors['name'] = 'Name is required.';
}

if ($email === '') {
    $errors['email'] = 'Email is required.';
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'Email must be valid.';
}

if ($username === '') {
    $errors['username'] = 'Username is required.';
}

if ($studentStaffId === '') {
    $errors['student_staff_id'] = 'Student ID is required.';
}

if ($department === '') {
    $errors['department'] = 'Department is required.';
}

$passwordError = mobileApiPasswordError($password);
if ($password === '') {
    $errors['password'] = 'Password is required.';
} elseif ($passwordError !== '') {
    $errors['password'] = $passwordError;
}

if ($confirmPassword === '') {
    $errors['confirm_password'] = 'Please confirm your password.';
} elseif ($password !== $confirmPassword) {
    $errors['confirm_password'] = 'Passwords do not match.';
}

if (!empty($errors)) {
    mobileApiError('Please correct the highlighted fields.', 422, $errors, 'validation_failed');
}

try {
    $stmt = $mobileApiDb->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ((int) $stmt->fetchColumn() > 0) {
        mobileApiError('Email is already registered.', 409, ['email' => 'Email is already registered.'], 'email_taken');
    }

    $stmt = $mobileApiDb->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
    $stmt->execute([$username]);
    if ((int) $stmt->fetchColumn() > 0) {
        mobileApiError('Username is already taken.', 409, ['username' => 'Username is already taken.'], 'username_taken');
    }

    $passwordHash = password_hash($password, PASSWORD_BCRYPT);
    if ($passwordHash === false) {
        mobileApiError('Unable to secure password.', 500, null, 'password_hash_failed');
    }

    $columns = ['name', 'email', 'username', 'password', 'role', 'student_staff_id', 'department', 'phone', 'is_active'];
    $values = [$name, $email, $username, $passwordHash, MOBILE_API_ALLOWED_ROLE, $studentStaffId, $department, $phone, 1];

    if (mobileApiUsersColumnExists($mobileApiDb, 'email_verified_at')) {
        $columns[] = 'email_verified_at';
        $values[] = date('Y-m-d H:i:s');
    }

    $sql = sprintf(
        'INSERT INTO users (%s) VALUES (%s)',
        implode(', ', $columns),
        implode(', ', array_fill(0, count($columns), '?'))
    );

    $stmt = $mobileApiDb->prepare($sql);
    $stmt->execute($values);

    $userId = (int) $mobileApiDb->lastInsertId();
    $stmt = $mobileApiDb->prepare('SELECT * FROM users WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    $token = mobileApiIssueToken($mobileApiDb, $userId, $deviceName);

    mobileApiSuccess([
        'token' => $token,
        'token_type' => 'Bearer',
        'expires_in' => MOBILE_API_TOKEN_TTL_SECONDS,
        'user' => mobileApiUserPayload($user ?: []),
    ], 'Registration successful.', 201);
} catch (PDOException $exception) {
    error_log('Mobile register error: ' . $exception->getMessage());
    mobileApiError('Unable to register account right now.', 500, null, 'register_failed');
}
