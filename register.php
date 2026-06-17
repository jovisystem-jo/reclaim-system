<?php
require_once 'config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    secureSessionStart();
}

if (isset($_SESSION['userID'])) {
    header('Location: index.php');
    exit();
}

$error = '';
$success = '';
$redirect = trim($_GET['redirect'] ?? '');

if ($redirect !== '') {
    $redirect = ltrim($redirect, '/');
    if (
        preg_match('/^(https?:|\/\/)/i', $redirect) ||
        strpos($redirect, '..') !== false ||
        !preg_match('/^[A-Za-z0-9_\/.-]+\.php(\?.*)?$/', $redirect)
    ) {
        $redirect = '';
    }
}

// Department options
$departments = [
    'Faculty of Civil Engineering and Built Environment (FKAAB)',
    'Faculty of Electric and Electronic Engineering (FKEE)',
    'Faculty of Mechanical and Manufacturing Engineering (FKMP)',
    'Faculty of Technical and Vocational Education (FPTV)',
    'Faculty of Technology Management and Business (FPTP)',
    'Faculty of Applied Science and Technology (FAST)',
    'Faculty of Science Computer and Information Technology (FSKTM)',
    'Faculty of Engineering Technology (FTK)'
];

const REGISTER_OTP_SESSION_KEY = 'pending_registration_otp';
const REGISTER_OTP_EXPIRY_SECONDS = 300;
const REGISTER_EMAIL_VERIFICATION_SESSION_KEY = 'register_email_verification';
const REGISTER_EMAIL_VERIFICATION_EXPIRY_SECONDS = 300;
const REGISTER_PHONE_VERIFICATION_SESSION_KEY = 'register_phone_verification';
const REGISTER_PHONE_VERIFICATION_EXPIRY_SECONDS = 300;
$isAjaxVerificationRequest = $_SERVER['REQUEST_METHOD'] === 'POST'
    && in_array(($_POST['form_action'] ?? ''), ['send_email_verification_only', 'send_phone_verification_only'], true)
    && strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

function register_json_response(array $payload, int $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

function register_log_issue($message) {
    $message = '[' . date('Y-m-d H:i:s') . '] ' . trim((string) $message);
    error_log($message);

    $logDirectory = __DIR__ . '/storage/logs';
    if (!is_dir($logDirectory) && !@mkdir($logDirectory, 0755, true) && !is_dir($logDirectory)) {
        return;
    }

    @file_put_contents($logDirectory . '/register.log', $message . PHP_EOL, FILE_APPEND);
}

if ($isAjaxVerificationRequest) {
    register_shutdown_function(static function (): void {
        $error = error_get_last();
        if (!is_array($error)) {
            return;
        }

        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
        if (!in_array($error['type'] ?? 0, $fatalTypes, true)) {
            return;
        }

        register_log_issue(
            'Fatal verification request error: '
            . ($error['message'] ?? 'Unknown error')
            . ' in '
            . ($error['file'] ?? 'unknown file')
            . ':'
            . ($error['line'] ?? 0)
        );

        if (!headers_sent()) {
            register_json_response([
                'success' => false,
                'message' => 'Unable to process the verification request right now. Please try again later.',
            ], 500);
        }
    });
}

function register_form_defaults() {
    return [
        'name' => '',
        'email' => '',
        'username' => '',
        'password' => '',
        'confirm_password' => '',
        'student_staff_id' => '',
        'department' => '',
        'phone' => '',
        'role' => 'student',
        'redirect' => '',
    ];
}

function register_pending_data() {
    $pending = $_SESSION[REGISTER_OTP_SESSION_KEY] ?? null;
    return is_array($pending) ? $pending : null;
}

function register_email_verification_data() {
    $pending = $_SESSION[REGISTER_EMAIL_VERIFICATION_SESSION_KEY] ?? null;
    return is_array($pending) ? $pending : null;
}

function register_phone_verification_data() {
    $pending = $_SESSION[REGISTER_PHONE_VERIFICATION_SESSION_KEY] ?? null;
    return is_array($pending) ? $pending : null;
}

function clear_register_pending_data() {
    unset($_SESSION[REGISTER_OTP_SESSION_KEY]);
}

function clear_register_email_verification_data() {
    unset($_SESSION[REGISTER_EMAIL_VERIFICATION_SESSION_KEY]);
}

function clear_register_phone_verification_data() {
    unset($_SESSION[REGISTER_PHONE_VERIFICATION_SESSION_KEY]);
}

function normalize_register_input(array $source) {
    return [
        'name' => trim($source['name'] ?? ''),
        'email' => trim($source['email'] ?? ''),
        'username' => trim($source['username'] ?? ''),
        'password' => (string) ($source['password'] ?? ''),
        'confirm_password' => (string) ($source['confirm_password'] ?? ''),
        'student_staff_id' => trim($source['student_staff_id'] ?? ''),
        'department' => trim($source['department'] ?? ''),
        'phone' => trim($source['phone'] ?? ''),
        'role' => trim($source['role'] ?? 'student'),
        'redirect' => trim($source['redirect'] ?? ''),
    ];
}

function register_form_draft(array $source) {
    $role = trim((string) ($source['role'] ?? 'student'));

    return [
        'name' => trim((string) ($source['name'] ?? '')),
        'email' => trim((string) ($source['email'] ?? '')),
        'username' => trim((string) ($source['username'] ?? '')),
        'student_staff_id' => trim((string) ($source['student_staff_id'] ?? '')),
        'department' => trim((string) ($source['department'] ?? '')),
        'phone' => trim((string) ($source['phone'] ?? '')),
        'role' => in_array($role, ['student', 'staff'], true) ? $role : 'student',
        'redirect' => trim((string) ($source['redirect'] ?? '')),
    ];
}

function merge_register_form_draft(array $formData, $draft) {
    if (!is_array($draft)) {
        return $formData;
    }

    return array_merge($formData, register_form_draft($draft));
}

function generate_register_otp() {
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function register_password_error($password) {
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

function mask_register_email($email) {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return $email;
    }

    [$local, $domain] = explode('@', $email, 2);
    $visible = strlen($local) <= 2 ? substr($local, 0, 1) : substr($local, 0, 2);
    return $visible . str_repeat('*', max(strlen($local) - strlen($visible), 1)) . '@' . $domain;
}

function register_env_flag($name, $default = false) {
    $value = getenv($name);
    if ($value === false || $value === null || $value === '') {
        return $default;
    }

    $normalized = strtolower(trim((string) $value));
    if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }

    if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
        return false;
    }

    return $default;
}

function register_allow_sms_code_preview() {
    return register_env_flag('SMS_EXPOSE_PREVIEW_CODE', !app_is_production());
}

function normalize_register_phone($phone) {
    $phone = trim((string) $phone);
    if ($phone === '') {
        return '';
    }

    $normalized = preg_replace('/[^\d+]/', '', $phone) ?? '';
    $normalized = preg_replace('/(?!^)\+/', '', $normalized) ?? '';

    if (strpos($normalized, '00') === 0) {
        $normalized = '+' . substr($normalized, 2);
    }

    if (strpos($normalized, '+') === 0) {
        $digits = preg_replace('/\D+/', '', substr($normalized, 1)) ?? '';
        if ($digits === '' || strlen($digits) < 8 || strlen($digits) > 15) {
            return '';
        }

        return '+' . $digits;
    }

    $digits = preg_replace('/\D+/', '', $normalized) ?? '';
    if ($digits === '') {
        return '';
    }

    if (strpos($digits, '60') === 0) {
        $digits = $digits;
    } elseif (strpos($digits, '0') === 0) {
        $digits = '60' . substr($digits, 1);
    } elseif (preg_match('/^1\d{8,9}$/', $digits)) {
        $digits = '60' . $digits;
    }

    if (strlen($digits) < 8 || strlen($digits) > 15) {
        return '';
    }

    return '+' . $digits;
}

function mask_register_phone($phone) {
    $normalized = normalize_register_phone($phone);
    if ($normalized === '') {
        return trim((string) $phone);
    }

    $prefix = substr($normalized, 0, 5);
    $suffix = substr($normalized, -3);
    $middleLength = max(strlen($normalized) - strlen($prefix) - strlen($suffix), 3);

    return $prefix . str_repeat('*', $middleLength) . $suffix;
}

function send_register_otp_email($email, $name, $otpCode) {
    try {
        require_once 'config/mail.php';

        $subject = 'Your Reclaim System verification code';
        $safeName = htmlspecialchars($name !== '' ? $name : 'User', ENT_QUOTES, 'UTF-8');
        $safeCode = htmlspecialchars($otpCode, ENT_QUOTES, 'UTF-8');
        $body = '
            <div style="font-family: Arial, sans-serif; max-width: 560px; margin: 0 auto; color: #1f2937;">
                <h2 style="margin-bottom: 16px; color: #111827;">Verify your email address</h2>
                <p style="margin-bottom: 16px;">Hi ' . $safeName . ',</p>
                <p style="margin-bottom: 16px;">
                    Use the verification code below to complete your Reclaim System registration:
                </p>
                <div style="margin: 24px 0; padding: 18px; text-align: center; background: #fff7ed; border: 1px solid #fed7aa; border-radius: 10px;">
                    <span style="font-size: 32px; letter-spacing: 8px; font-weight: 700; color: #ea580c;">' . $safeCode . '</span>
                </div>
                <p style="margin-bottom: 12px;">This code will expire in 5 minutes.</p>
                <p style="margin-bottom: 0;">If you did not request this registration, you can ignore this email.</p>
            </div>
        ';

        $result = MailConfig::sendNotification($email, $subject, $body);
        if (!$result) {
            register_log_issue('Registration OTP email failed for ' . $email . ': ' . MailConfig::getLastError());
        }

        return $result;
    } catch (Throwable $exception) {
        register_log_issue('Registration OTP email crashed for ' . $email . ': ' . $exception->getMessage());
        return false;
    }
}

function send_register_otp_sms($phone, $name, $otpCode) {
    try {
        require_once 'config/sms.php';

        $safeName = trim((string) $name);
        $message = 'Reclaim System verification code: ' . $otpCode . '. ';
        if ($safeName !== '') {
            $message .= 'Hi ' . $safeName . ', ';
        }
        $message .= 'use this code within 5 minutes to verify your phone number.';

        $sent = SmsConfig::sendMessage($phone, $message);
        $transport = SmsConfig::getLastTransport();
        $previewAllowed = register_allow_sms_code_preview();
        $usedPreviewTransport = $sent && $transport === 'preview';
        $shouldExposeCode = $previewAllowed && ($usedPreviewTransport || !$sent);

        if ($usedPreviewTransport && !$previewAllowed) {
            register_log_issue('Registration OTP SMS used preview transport for ' . $phone . ' but preview exposure is disabled.');
            return [
                'success' => false,
                'show_preview_code' => false,
                'temporary_code' => '',
                'transport' => $transport,
                'error' => 'SMS delivery is not configured right now. Please contact the administrator.',
            ];
        }

        if (!$sent && !$previewAllowed) {
            register_log_issue('Registration OTP SMS failed for ' . $phone . ': ' . SmsConfig::getLastError());
        }

        return [
            'success' => $sent || $shouldExposeCode,
            'show_preview_code' => $shouldExposeCode,
            'temporary_code' => $shouldExposeCode ? $otpCode : '',
            'transport' => $transport,
            'error' => SmsConfig::getLastError(),
        ];
    } catch (Throwable $exception) {
        register_log_issue('Registration OTP SMS crashed for ' . $phone . ': ' . $exception->getMessage());

        if (!register_allow_sms_code_preview()) {
            return [
                'success' => false,
                'show_preview_code' => false,
                'temporary_code' => '',
                'transport' => '',
                'error' => $exception->getMessage(),
            ];
        }

        return [
            'success' => true,
            'show_preview_code' => true,
            'temporary_code' => $otpCode,
            'transport' => '',
            'error' => $exception->getMessage(),
        ];
    }
}

function stage_register_email_verification($email, $otpCode, array $draft = []) {
    $_SESSION[REGISTER_EMAIL_VERIFICATION_SESSION_KEY] = [
        'email' => $email,
        'otp_hash' => hash('sha256', $otpCode),
        'expires_at' => time() + REGISTER_EMAIL_VERIFICATION_EXPIRY_SECONDS,
        'verified_at' => null,
        'draft' => register_form_draft(array_merge($draft, ['email' => $email])),
    ];

    return $_SESSION[REGISTER_EMAIL_VERIFICATION_SESSION_KEY];
}

function stage_register_phone_verification($phone, $otpCode, array $draft = []) {
    $_SESSION[REGISTER_PHONE_VERIFICATION_SESSION_KEY] = [
        'phone' => $phone,
        'otp_hash' => hash('sha256', $otpCode),
        'expires_at' => time() + REGISTER_PHONE_VERIFICATION_EXPIRY_SECONDS,
        'verified_at' => null,
        'draft' => register_form_draft(array_merge($draft, ['phone' => $phone])),
    ];

    return $_SESSION[REGISTER_PHONE_VERIFICATION_SESSION_KEY];
}

function register_email_is_verified($email) {
    $verification = register_email_verification_data();
    return is_array($verification)
        && !empty($verification['verified_at'])
        && isset($verification['email'])
        && strcasecmp((string) $verification['email'], trim((string) $email)) === 0;
}

function mark_register_email_verified() {
    if (!isset($_SESSION[REGISTER_EMAIL_VERIFICATION_SESSION_KEY]) || !is_array($_SESSION[REGISTER_EMAIL_VERIFICATION_SESSION_KEY])) {
        return;
    }

    $_SESSION[REGISTER_EMAIL_VERIFICATION_SESSION_KEY]['verified_at'] = time();
    unset($_SESSION[REGISTER_EMAIL_VERIFICATION_SESSION_KEY]['otp_hash']);
    unset($_SESSION[REGISTER_EMAIL_VERIFICATION_SESSION_KEY]['expires_at']);
}

function register_phone_is_verified($phone) {
    $verification = register_phone_verification_data();
    $normalizedPhone = normalize_register_phone($phone);

    return is_array($verification)
        && !empty($verification['verified_at'])
        && isset($verification['phone'])
        && $normalizedPhone !== ''
        && normalize_register_phone((string) $verification['phone']) === $normalizedPhone;
}

function mark_register_phone_verified() {
    if (!isset($_SESSION[REGISTER_PHONE_VERIFICATION_SESSION_KEY]) || !is_array($_SESSION[REGISTER_PHONE_VERIFICATION_SESSION_KEY])) {
        return;
    }

    $_SESSION[REGISTER_PHONE_VERIFICATION_SESSION_KEY]['verified_at'] = time();
    unset($_SESSION[REGISTER_PHONE_VERIFICATION_SESSION_KEY]['otp_hash']);
    unset($_SESSION[REGISTER_PHONE_VERIFICATION_SESSION_KEY]['expires_at']);
}

function update_register_email_verification_draft(array $draft) {
    if (!isset($_SESSION[REGISTER_EMAIL_VERIFICATION_SESSION_KEY]) || !is_array($_SESSION[REGISTER_EMAIL_VERIFICATION_SESSION_KEY])) {
        return;
    }

    $_SESSION[REGISTER_EMAIL_VERIFICATION_SESSION_KEY]['draft'] = register_form_draft($draft);

    if (!empty($draft['email'])) {
        $_SESSION[REGISTER_EMAIL_VERIFICATION_SESSION_KEY]['email'] = trim((string) $draft['email']);
    }
}

function update_register_phone_verification_draft(array $draft) {
    if (!isset($_SESSION[REGISTER_PHONE_VERIFICATION_SESSION_KEY]) || !is_array($_SESSION[REGISTER_PHONE_VERIFICATION_SESSION_KEY])) {
        return;
    }

    $_SESSION[REGISTER_PHONE_VERIFICATION_SESSION_KEY]['draft'] = register_form_draft($draft);

    if (!empty($draft['phone'])) {
        $normalizedPhone = normalize_register_phone($draft['phone']);
        if ($normalizedPhone !== '') {
            $_SESSION[REGISTER_PHONE_VERIFICATION_SESSION_KEY]['phone'] = $normalizedPhone;
        }
    }
}

function stage_register_pending_data(array $input, $role, $passwordHash, $otpCode) {
    $_SESSION[REGISTER_OTP_SESSION_KEY] = [
        'name' => $input['name'],
        'email' => $input['email'],
        'username' => $input['username'],
        'password_hash' => $passwordHash,
        'role' => $role,
        'student_staff_id' => $input['student_staff_id'],
        'department' => $input['department'],
        'phone' => $input['phone'],
        'redirect' => $input['redirect'],
        'otp_hash' => hash('sha256', $otpCode),
        'expires_at' => time() + REGISTER_OTP_EXPIRY_SECONDS,
    ];

    return $_SESSION[REGISTER_OTP_SESSION_KEY];
}

function register_development_otp_message($otpCode) {
    return 'Email delivery is unavailable in development mode. Use the temporary verification code below.';
}

function register_sms_preview_message($otpCode) {
    return 'SMS delivery preview is active. Use the temporary verification code below.';
}

function users_table_has_column(PDO $db, $columnName) {
    static $columns = null;

    if ($columns === null) {
        $columns = [];
        foreach ($db->query('SHOW COLUMNS FROM users') as $column) {
            $columns[$column['Field']] = true;
        }
    }

    return isset($columns[$columnName]);
}

function create_verified_user(PDO $db, array $registrationData) {
    $columns = ['name', 'email', 'username', 'password', 'role', 'student_staff_id', 'department', 'phone', 'is_active'];
    $values = [
        $registrationData['name'],
        $registrationData['email'],
        $registrationData['username'],
        $registrationData['password_hash'],
        $registrationData['role'],
        $registrationData['student_staff_id'],
        $registrationData['department'],
        $registrationData['phone'],
        1,
    ];

    if (users_table_has_column($db, 'email_verified_at')) {
        $columns[] = 'email_verified_at';
        $values[] = date('Y-m-d H:i:s');
    }

    if (users_table_has_column($db, 'phone_verified_at') && !empty($registrationData['phone'])) {
        $columns[] = 'phone_verified_at';
        $values[] = date('Y-m-d H:i:s');
    }

    if (users_table_has_column($db, 'verification_token')) {
        $columns[] = 'verification_token';
        $values[] = null;
    }

    if (users_table_has_column($db, 'token_expiry')) {
        $columns[] = 'token_expiry';
        $values[] = null;
    }

    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $sql = sprintf(
        'INSERT INTO users (%s) VALUES (%s)',
        implode(', ', $columns),
        $placeholders
    );

    $stmt = $db->prepare($sql);
    if (!$stmt->execute($values)) {
        return false;
    }

    return (int) $db->lastInsertId();
}

function send_register_success_notification($userId, array $registrationData) {
    if ((int) $userId <= 0) {
        return false;
    }

    require_once __DIR__ . '/includes/notification.php';

    try {
        $notification = new NotificationSystem();
        return $notification->registrationSuccessful((int) $userId, [
            'role' => $registrationData['role'] ?? 'user',
        ]);
    } catch (Throwable $e) {
        error_log('Registration success notification failed for user ' . (int) $userId . ': ' . $e->getMessage());
        return false;
    }
}

function stage_register_success_notice(array $registrationData) {
    $role = strtolower(trim((string) ($registrationData['role'] ?? 'user')));
    $roleLabel = in_array($role, ['student', 'staff', 'admin'], true)
        ? ucfirst($role)
        : 'User';

    $_SESSION['registration_success_notice'] = [
        'title' => 'Registration Successful',
        'message' => "Your {$roleLabel} account has been created successfully. Welcome to Reclaim System.",
        'type' => 'success',
    ];
}

$formData = register_form_defaults();
$pendingRegistration = register_pending_data();
$emailVerificationSession = register_email_verification_data();
$phoneVerificationSession = register_phone_verification_data();
$showVerificationModal = false;
$verificationError = '';
$verificationSuccess = '';
$verificationSuccessType = 'success';
$verificationTarget = 'email';
$verificationDestination = '';
$developmentVerificationCode = '';

if ($emailVerificationSession && empty($emailVerificationSession['verified_at']) && (($emailVerificationSession['expires_at'] ?? 0) < time())) {
    clear_register_email_verification_data();
    $emailVerificationSession = null;
}

if ($phoneVerificationSession && empty($phoneVerificationSession['verified_at']) && (($phoneVerificationSession['expires_at'] ?? 0) < time())) {
    clear_register_phone_verification_data();
    $phoneVerificationSession = null;
}

// A normal page refresh should start from a clean registration form.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && ($pendingRegistration || $emailVerificationSession || $phoneVerificationSession)) {
    clear_register_pending_data();
    clear_register_email_verification_data();
    clear_register_phone_verification_data();
    $pendingRegistration = null;
    $emailVerificationSession = null;
    $phoneVerificationSession = null;
}

if ($pendingRegistration) {
    if (($pendingRegistration['expires_at'] ?? 0) < time()) {
        clear_register_pending_data();
        $pendingRegistration = null;
    } else {
        $formData = array_merge($formData, [
            'name' => $pendingRegistration['name'] ?? '',
            'email' => $pendingRegistration['email'] ?? '',
            'username' => $pendingRegistration['username'] ?? '',
            'student_staff_id' => $pendingRegistration['student_staff_id'] ?? '',
            'department' => $pendingRegistration['department'] ?? '',
            'phone' => $pendingRegistration['phone'] ?? '',
            'role' => $pendingRegistration['role'] ?? 'student',
            'redirect' => $pendingRegistration['redirect'] ?? '',
        ]);
        $showVerificationModal = true;
        $verificationTarget = 'email';
        $verificationDestination = mask_register_email($pendingRegistration['email'] ?? '');
    }
}

if ($emailVerificationSession && !$pendingRegistration) {
    $formData = merge_register_form_draft($formData, $emailVerificationSession['draft'] ?? []);
}

if ($phoneVerificationSession && !$pendingRegistration) {
    $formData = merge_register_form_draft($formData, $phoneVerificationSession['draft'] ?? []);
}

$emailIsVerified = register_email_is_verified($formData['email']);
$phoneIsVerified = register_phone_is_verified($formData['phone']);
$verificationModalIsRegistrationFlow = $pendingRegistration !== null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $formAction = $_POST['form_action'] ?? 'start_registration';
        $isAjaxVerification = $isAjaxVerificationRequest;
        $requestedVerificationTarget = in_array(($_POST['verification_target'] ?? ''), ['email', 'phone'], true)
            ? (string) $_POST['verification_target']
            : 'email';

        if ($isAjaxVerification) {
            if (!verify_csrf_token(request_csrf_token())) {
                register_json_response([
                    'success' => false,
                    'message' => 'Invalid security token. Please refresh the page and try again.',
                ], 403);
            }
        } else {
            require_csrf_token();
        }

        $db = Database::getInstance()->getConnection();

        if ($formAction === 'verify_otp') {
            $pendingRegistration = register_pending_data();
            $emailVerificationSession = register_email_verification_data();
            $phoneVerificationSession = register_phone_verification_data();

            if ($pendingRegistration) {
                if (($pendingRegistration['expires_at'] ?? 0) < time()) {
                    clear_register_pending_data();
                    $error = 'Verification code expired. Please register again.';
                } else {
                    $submittedCode = trim((string) ($_POST['verification_code'] ?? ''));
                    $showVerificationModal = true;
                    $verificationTarget = 'email';
                    $verificationDestination = mask_register_email($pendingRegistration['email'] ?? '');
                    $formData = array_merge($formData, [
                        'name' => $pendingRegistration['name'] ?? '',
                        'email' => $pendingRegistration['email'] ?? '',
                        'username' => $pendingRegistration['username'] ?? '',
                        'student_staff_id' => $pendingRegistration['student_staff_id'] ?? '',
                        'department' => $pendingRegistration['department'] ?? '',
                        'phone' => $pendingRegistration['phone'] ?? '',
                        'role' => $pendingRegistration['role'] ?? 'student',
                        'redirect' => $pendingRegistration['redirect'] ?? '',
                    ]);

                    if (empty($pendingRegistration['password_hash']) || !is_string($pendingRegistration['password_hash'])) {
                        clear_register_pending_data();
                        $verificationError = 'Verification session is incomplete. Please register again.';
                        $showVerificationModal = false;
                    } elseif (!preg_match('/^\d{6}$/', $submittedCode)) {
                        $verificationError = 'Please enter the 6-digit verification code.';
                    } elseif (!hash_equals($pendingRegistration['otp_hash'] ?? '', hash('sha256', $submittedCode))) {
                        $verificationError = 'Incorrect verification code. Please try again.';
                    } else {
                        $stmt = $db->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
                        $stmt->execute([$pendingRegistration['email']]);
                        if ($stmt->fetchColumn() > 0) {
                            clear_register_pending_data();
                            $error = 'Email already registered. Please login instead.';
                            $showVerificationModal = false;
                        } else {
                            $stmt = $db->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
                            $stmt->execute([$pendingRegistration['username']]);
                            if ($stmt->fetchColumn() > 0) {
                                clear_register_pending_data();
                                $error = 'Username already taken. Please register again with a different username.';
                                $showVerificationModal = false;
                            } else {
                                $newUserId = create_verified_user($db, $pendingRegistration);
                                if ($newUserId) {
                                    send_register_success_notification($newUserId, $pendingRegistration);
                                    stage_register_success_notice($pendingRegistration);
                                    clear_register_pending_data();
                                    clear_register_email_verification_data();
                                    clear_register_phone_verification_data();
                                    session_regenerate_id(true);
                                    $loginUrl = 'login.php';
                                    $redirectTarget = $pendingRegistration['redirect'] ?? $redirect;
                                    if ($redirectTarget !== '') {
                                        $loginUrl .= '?redirect=' . urlencode($redirectTarget);
                                    }
                                    header('Location: ' . $loginUrl);
                                    exit();
                                }

                                $verificationError = 'Registration failed. Please try again.';
                            }
                        }
                    }
                }
            } elseif ($requestedVerificationTarget === 'phone') {
                if (!$phoneVerificationSession) {
                    $error = 'Verification session expired. Please verify your phone number again.';
                } elseif (($phoneVerificationSession['expires_at'] ?? 0) < time()) {
                    clear_register_phone_verification_data();
                    $error = 'Verification code expired. Please verify your phone number again.';
                } else {
                    $submittedCode = trim((string) ($_POST['verification_code'] ?? ''));
                    $showVerificationModal = true;
                    $verificationTarget = 'phone';
                    $verificationDestination = mask_register_phone($phoneVerificationSession['phone'] ?? '');
                    $formData = merge_register_form_draft($formData, $phoneVerificationSession['draft'] ?? []);
                    $formData['phone'] = $phoneVerificationSession['phone'] ?? $formData['phone'];

                    if (!preg_match('/^\d{6}$/', $submittedCode)) {
                        $verificationError = 'Please enter the 6-digit verification code.';
                    } elseif (!hash_equals($phoneVerificationSession['otp_hash'] ?? '', hash('sha256', $submittedCode))) {
                        $verificationError = 'Incorrect verification code. Please try again.';
                    } else {
                        mark_register_phone_verified();
                        $phoneVerificationSession = register_phone_verification_data();
                        $formData = merge_register_form_draft($formData, $phoneVerificationSession['draft'] ?? []);
                        $formData['phone'] = $phoneVerificationSession['phone'] ?? $formData['phone'];
                        $success = 'Phone number verified successfully. You can continue with registration.';
                        $showVerificationModal = false;
                        $verificationError = '';
                        $verificationSuccess = '';
                    }
                }
            } elseif ($emailVerificationSession) {
                if (($emailVerificationSession['expires_at'] ?? 0) < time()) {
                    clear_register_email_verification_data();
                    $error = 'Verification code expired. Please verify your email again.';
                } else {
                    $submittedCode = trim((string) ($_POST['verification_code'] ?? ''));
                    $showVerificationModal = true;
                    $verificationTarget = 'email';
                    $verificationDestination = mask_register_email($emailVerificationSession['email'] ?? '');
                    $formData = merge_register_form_draft($formData, $emailVerificationSession['draft'] ?? []);
                    $formData['email'] = $emailVerificationSession['email'] ?? $formData['email'];

                    if (!preg_match('/^\d{6}$/', $submittedCode)) {
                        $verificationError = 'Please enter the 6-digit verification code.';
                    } elseif (!hash_equals($emailVerificationSession['otp_hash'] ?? '', hash('sha256', $submittedCode))) {
                        $verificationError = 'Incorrect verification code. Please try again.';
                    } else {
                        mark_register_email_verified();
                        $emailVerificationSession = register_email_verification_data();
                        $formData = merge_register_form_draft($formData, $emailVerificationSession['draft'] ?? []);
                        $formData['email'] = $emailVerificationSession['email'] ?? $formData['email'];
                        $success = 'Email verified successfully. You can continue with registration.';
                        $showVerificationModal = false;
                        $verificationError = '';
                        $verificationSuccess = '';
                    }
                }
            } else {
                $error = 'Verification session expired. Please register again.';
            }
        } elseif ($formAction === 'resend_otp') {
            $pendingRegistration = register_pending_data();
            $emailVerificationSession = register_email_verification_data();
            $phoneVerificationSession = register_phone_verification_data();

            if ($pendingRegistration) {
                if (($pendingRegistration['expires_at'] ?? 0) < time()) {
                    clear_register_pending_data();
                    $error = 'Verification code expired. Please register again.';
                } else {
                    $showVerificationModal = true;
                    $verificationTarget = 'email';
                    $verificationDestination = mask_register_email($pendingRegistration['email'] ?? '');
                    $formData = array_merge($formData, [
                        'name' => $pendingRegistration['name'] ?? '',
                        'email' => $pendingRegistration['email'] ?? '',
                        'username' => $pendingRegistration['username'] ?? '',
                        'student_staff_id' => $pendingRegistration['student_staff_id'] ?? '',
                        'department' => $pendingRegistration['department'] ?? '',
                        'phone' => $pendingRegistration['phone'] ?? '',
                        'role' => $pendingRegistration['role'] ?? 'student',
                        'redirect' => $pendingRegistration['redirect'] ?? '',
                    ]);

                    $stmt = $db->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
                    $stmt->execute([$pendingRegistration['email']]);
                    if ($stmt->fetchColumn() > 0) {
                        clear_register_pending_data();
                        $error = 'Email already registered. Please login instead.';
                        $showVerificationModal = false;
                    } else {
                        $stmt = $db->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
                        $stmt->execute([$pendingRegistration['username']]);
                        if ($stmt->fetchColumn() > 0) {
                            clear_register_pending_data();
                            $error = 'Username already taken. Please register again with a different username.';
                            $showVerificationModal = false;
                        } else {
                            $newOtp = generate_register_otp();
                            $otpSent = send_register_otp_email($pendingRegistration['email'], $pendingRegistration['name'], $newOtp);

                            if ($otpSent || !app_is_production()) {
                                $_SESSION[REGISTER_OTP_SESSION_KEY]['otp_hash'] = hash('sha256', $newOtp);
                                $_SESSION[REGISTER_OTP_SESSION_KEY]['expires_at'] = time() + REGISTER_OTP_EXPIRY_SECONDS;
                                $verificationSuccess = 'A new verification code has been sent to your email.';
                                $verificationSuccessType = 'success';

                                if (!$otpSent) {
                                    $verificationSuccess = register_development_otp_message($newOtp);
                                    $verificationSuccessType = 'warning';
                                    $developmentVerificationCode = $newOtp;
                                }
                            } else {
                                $verificationError = 'Unable to resend the verification code right now. Please try again.';
                            }
                        }
                    }
                }
            } elseif ($requestedVerificationTarget === 'phone') {
                $phoneToVerify = normalize_register_phone((string) ($phoneVerificationSession['phone'] ?? ''));

                if ($phoneToVerify === '') {
                    clear_register_phone_verification_data();
                    $error = 'Verification session expired. Please verify your phone number again.';
                } else {
                    $showVerificationModal = true;
                    $verificationTarget = 'phone';
                    $verificationDestination = mask_register_phone($phoneToVerify);
                    $formData = merge_register_form_draft($formData, $phoneVerificationSession['draft'] ?? []);
                    $formData['phone'] = $phoneToVerify;

                    $newOtp = generate_register_otp();
                    $smsResult = send_register_otp_sms($phoneToVerify, $formData['name'] ?: 'User', $newOtp);

                    if ($smsResult['success']) {
                        $draft = $phoneVerificationSession['draft'] ?? $formData;
                        $draft['phone'] = $phoneToVerify;
                        stage_register_phone_verification($phoneToVerify, $newOtp, $draft);
                        $phoneVerificationSession = register_phone_verification_data();
                        $verificationSuccess = 'A new verification code has been sent to your phone number.';
                        $verificationSuccessType = 'success';
                        $developmentVerificationCode = '';

                        if (!empty($smsResult['show_preview_code'])) {
                            $verificationSuccess = register_sms_preview_message($newOtp);
                            $verificationSuccessType = 'warning';
                            $developmentVerificationCode = (string) ($smsResult['temporary_code'] ?? '');
                        }
                    } else {
                        $verificationError = 'Unable to resend the SMS verification code right now. Please try again.';
                    }
                }
            } elseif ($emailVerificationSession) {
                $emailToVerify = trim((string) ($emailVerificationSession['email'] ?? ''));

                if ($emailToVerify === '') {
                    clear_register_email_verification_data();
                    $error = 'Verification session expired. Please verify your email again.';
                } else {
                    $showVerificationModal = true;
                    $verificationTarget = 'email';
                    $verificationDestination = mask_register_email($emailToVerify);
                    $formData = merge_register_form_draft($formData, $emailVerificationSession['draft'] ?? []);
                    $formData['email'] = $emailToVerify;

                    $stmt = $db->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
                    $stmt->execute([$emailToVerify]);
                    if ($stmt->fetchColumn() > 0) {
                        clear_register_email_verification_data();
                        $error = 'Email already registered. Please login instead.';
                        $showVerificationModal = false;
                    } else {
                        $newOtp = generate_register_otp();
                        $otpSent = send_register_otp_email($emailToVerify, $formData['name'] ?: 'User', $newOtp);

                        if ($otpSent || !app_is_production()) {
                            stage_register_email_verification($emailToVerify, $newOtp, $emailVerificationSession['draft'] ?? $formData);
                            $emailVerificationSession = register_email_verification_data();
                            $verificationSuccess = 'A new verification code has been sent to your email.';
                            $verificationSuccessType = 'success';
                            $developmentVerificationCode = '';

                            if (!$otpSent) {
                                $verificationSuccess = register_development_otp_message($newOtp);
                                $verificationSuccessType = 'warning';
                                $developmentVerificationCode = $newOtp;
                            }
                        } else {
                            $verificationError = 'Unable to resend the verification code right now. Please try again.';
                        }
                    }
                }
            } else {
                $error = 'Verification session expired. Please register again.';
            }
        } elseif ($formAction === 'send_email_verification_only') {
            $input = normalize_register_input($_POST);
            $requestedRole = $input['role'];
            $input['role'] = in_array($requestedRole, ['student', 'staff'], true) ? $requestedRole : 'student';
            $input['redirect'] = $redirect !== '' ? $redirect : $input['redirect'];
            $formData = array_merge($formData, $input);
            clear_register_pending_data();
            $pendingRegistration = null;

            $verificationEmailInput = trim((string) ($_POST['verification_email'] ?? $input['email']));
            $response = [
                'success' => false,
                'message' => '',
                'status_type' => 'success',
                'masked_email' => $verificationEmailInput !== '' ? mask_register_email($verificationEmailInput) : '',
                'masked_destination' => $verificationEmailInput !== '' ? mask_register_email($verificationEmailInput) : '',
                'already_verified' => false,
                'show_modal' => false,
                'temporary_code' => '',
                'verification_target' => 'email',
            ];

            if ($verificationEmailInput === '') {
                $response['message'] = 'Please enter your email address first.';
            } elseif (!filter_var($verificationEmailInput, FILTER_VALIDATE_EMAIL)) {
                $response['message'] = 'Please enter a valid email address.';
            } else {
                $stmt = $db->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
                $stmt->execute([$verificationEmailInput]);
                if ($stmt->fetchColumn() > 0) {
                    $response['message'] = 'Email already registered. Please login instead.';
                } elseif (register_email_is_verified($verificationEmailInput)) {
                    update_register_email_verification_draft(array_merge($input, ['email' => $verificationEmailInput]));
                    $response['success'] = true;
                    $response['already_verified'] = true;
                    $response['message'] = 'This email is already verified. You can continue with registration.';
                } else {
                    $otpCode = generate_register_otp();
                    $otpSent = send_register_otp_email($verificationEmailInput, $input['name'] !== '' ? $input['name'] : 'User', $otpCode);

                    if ($otpSent || !app_is_production()) {
                        stage_register_email_verification($verificationEmailInput, $otpCode, $input);
                        $emailVerificationSession = register_email_verification_data();
                        $response['success'] = true;
                        $response['show_modal'] = true;
                        $response['message'] = 'A verification code has been sent to your email.';

                        if (!$otpSent) {
                            $response['message'] = register_development_otp_message($otpCode);
                            $response['status_type'] = 'warning';
                            $response['temporary_code'] = $otpCode;
                        }
                    } else {
                        $response['message'] = 'Unable to send verification code right now. Please try again later.';
                    }
                }
            }

            if ($isAjaxVerification) {
                register_json_response($response);
            }

            if ($response['success']) {
                $success = $response['message'];
                $verificationSuccess = $response['show_modal'] ? $response['message'] : '';
                $verificationSuccessType = $response['status_type'] ?? 'success';
                $verificationTarget = 'email';
                $verificationDestination = $response['masked_destination'];
                $showVerificationModal = !empty($response['show_modal']);
                $developmentVerificationCode = (string) ($response['temporary_code'] ?? '');
            } else {
                $error = $response['message'];
            }
        } elseif ($formAction === 'send_phone_verification_only') {
            $input = normalize_register_input($_POST);
            $requestedRole = $input['role'];
            $input['role'] = in_array($requestedRole, ['student', 'staff'], true) ? $requestedRole : 'student';
            $input['redirect'] = $redirect !== '' ? $redirect : $input['redirect'];
            $formData = array_merge($formData, $input);
            clear_register_pending_data();
            $pendingRegistration = null;

            $verificationPhoneInput = normalize_register_phone((string) ($_POST['verification_phone'] ?? $input['phone']));
            if ($verificationPhoneInput !== '') {
                $input['phone'] = $verificationPhoneInput;
                $formData['phone'] = $verificationPhoneInput;
            }

            $response = [
                'success' => false,
                'message' => '',
                'status_type' => 'success',
                'masked_phone' => $verificationPhoneInput !== '' ? mask_register_phone($verificationPhoneInput) : '',
                'masked_destination' => $verificationPhoneInput !== '' ? mask_register_phone($verificationPhoneInput) : '',
                'normalized_phone' => $verificationPhoneInput,
                'already_verified' => false,
                'show_modal' => false,
                'temporary_code' => '',
                'verification_target' => 'phone',
            ];

            if (trim((string) ($_POST['verification_phone'] ?? $input['phone'])) === '') {
                $response['message'] = 'Please enter your phone number first.';
            } elseif ($verificationPhoneInput === '') {
                $response['message'] = 'Please enter a valid Malaysian or international mobile number.';
            } elseif (register_phone_is_verified($verificationPhoneInput)) {
                update_register_phone_verification_draft(array_merge($input, ['phone' => $verificationPhoneInput]));
                $response['success'] = true;
                $response['already_verified'] = true;
                $response['message'] = 'This phone number is already verified. You can continue with registration.';
            } else {
                $otpCode = generate_register_otp();
                $smsResult = send_register_otp_sms($verificationPhoneInput, $input['name'] !== '' ? $input['name'] : 'User', $otpCode);

                if ($smsResult['success']) {
                    stage_register_phone_verification($verificationPhoneInput, $otpCode, $input);
                    $phoneVerificationSession = register_phone_verification_data();
                    $response['success'] = true;
                    $response['show_modal'] = true;
                    $response['message'] = 'A verification code has been sent to your phone number.';

                    if (!empty($smsResult['show_preview_code'])) {
                        $response['message'] = register_sms_preview_message($otpCode);
                        $response['status_type'] = 'warning';
                        $response['temporary_code'] = (string) ($smsResult['temporary_code'] ?? $otpCode);
                    }
                } else {
                    $response['message'] = 'Unable to send the SMS verification code right now. Please try again later.';
                }
            }

            if ($isAjaxVerification) {
                register_json_response($response);
            }

            if ($response['success']) {
                $success = $response['message'];
                $verificationSuccess = $response['show_modal'] ? $response['message'] : '';
                $verificationSuccessType = $response['status_type'] ?? 'success';
                $verificationTarget = 'phone';
                $verificationDestination = $response['masked_destination'];
                $showVerificationModal = !empty($response['show_modal']);
                $developmentVerificationCode = (string) ($response['temporary_code'] ?? '');
            } else {
                $error = $response['message'];
            }
        } else {
        $input = normalize_register_input($_POST);
        $requested_role = $input['role'];
        $role = in_array($requested_role, ['student', 'staff'], true) ? $requested_role : 'student';
        $input['role'] = $role;
        $input['redirect'] = $redirect !== '' ? $redirect : $input['redirect'];
        $formData = array_merge($formData, $input);
        $normalizedPhone = normalize_register_phone($input['phone']);

        if ($normalizedPhone !== '') {
            $input['phone'] = $normalizedPhone;
            $formData['phone'] = $normalizedPhone;
        }

        if (empty($input['name']) || empty($input['email']) || empty($input['username']) || empty($input['password']) || empty($input['phone'])) {
            $error = 'Please fill in all required fields';
        } elseif ($input['password'] !== $input['confirm_password']) {
            $error = 'Passwords do not match';
        } elseif (($passwordError = register_password_error($input['password'])) !== '') {
            $error = $passwordError;
        } elseif (!filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid email format';
        } elseif ($normalizedPhone === '') {
            $error = 'Please enter a valid Malaysian or international mobile number.';
        } elseif (empty($input['student_staff_id'])) {
            $error = $role === 'student' ? 'Please enter your Student ID' : 'Please enter your Staff ID';
        } elseif (empty($input['department'])) {
            $error = 'Please select your department';
        } else {
            $stmt = $db->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
            $stmt->execute([$input['email']]);
            if ($stmt->fetchColumn() > 0) {
                $error = 'Email already registered';
            } else {
                $stmt = $db->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
                $stmt->execute([$input['username']]);
                if ($stmt->fetchColumn() > 0) {
                    $error = 'Username already taken';
                } else {
                    $passwordHash = password_hash($input['password'], PASSWORD_BCRYPT);

                    if ($passwordHash === false) {
                        $error = 'Unable to secure your password right now. Please try again.';
                    } elseif (!register_email_is_verified($input['email'])) {
                        clear_register_pending_data();
                        $pendingRegistration = null;
                        $error = 'Please verify your email first using the Verify Email button.';
                    } elseif (!register_phone_is_verified($input['phone'])) {
                        clear_register_pending_data();
                        $pendingRegistration = null;
                        $error = 'Please verify your phone number first using the Verify Phone button.';
                    } else {
                        $registrationData = $input;
                        $registrationData['password_hash'] = $passwordHash;

                        $newUserId = create_verified_user($db, $registrationData);
                        if ($newUserId) {
                            send_register_success_notification($newUserId, $registrationData);
                            stage_register_success_notice($registrationData);
                            clear_register_pending_data();
                            clear_register_email_verification_data();
                            clear_register_phone_verification_data();
                            session_regenerate_id(true);
                            $loginUrl = 'login.php';
                            $redirectTarget = $input['redirect'] ?? $redirect;
                            if ($redirectTarget !== '') {
                                $loginUrl .= '?redirect=' . urlencode($redirectTarget);
                            }
                            header('Location: ' . $loginUrl);
                            exit();
                        }

                        $error = 'Registration failed. Please try again.';
                    }
                }
            }
        }
    }

        $pendingRegistration = register_pending_data();
        $emailVerificationSession = register_email_verification_data();
        $phoneVerificationSession = register_phone_verification_data();
        $emailIsVerified = register_email_is_verified($formData['email']);
        $phoneIsVerified = register_phone_is_verified($formData['phone']);
        $verificationModalIsRegistrationFlow = $pendingRegistration !== null;
    } catch (Throwable $exception) {
        register_log_issue(
            'Registration request failed'
            . ($isAjaxVerificationRequest ? ' during AJAX verification' : '')
            . ': ' . $exception->getMessage()
            . ' in ' . $exception->getFile() . ':' . $exception->getLine()
        );

        if ($isAjaxVerificationRequest) {
            register_json_response([
                'success' => false,
                'message' => 'Unable to process the verification request right now. Please try again later.',
            ], 500);
        }

        $error = 'Unable to process your request right now. Please try again later.';
        $showVerificationModal = false;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Reclaim System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .role-card {
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            padding: 15px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
            height: 100%;
        }
        .role-card:hover {
            border-color: #FF8C00;
            background: #fff8f0;
        }
        .role-card.selected {
            border-color: #FF8C00;
            background: linear-gradient(135deg, #fff8f0, #fff0e0);
            box-shadow: 0 5px 15px rgba(255, 140, 0, 0.2);
        }
        .role-card i {
            font-size: 48px;
            margin-bottom: 10px;
            color: #FF8C00;
        }
        .role-card h6 {
            margin-bottom: 5px;
            font-weight: 600;
        }
        .role-card p {
            font-size: 12px;
            color: #666;
            margin: 0;
        }
        .role-input {
            display: none;
        }
        .required-field::after {
            content: '*';
            color: red;
            margin-left: 4px;
        }
        .email-verify-group .form-control {
            border-top-right-radius: 0;
            border-bottom-right-radius: 0;
        }
        .email-verify-group .btn {
            border-top-left-radius: 0;
            border-bottom-left-radius: 0;
            white-space: nowrap;
            font-weight: 600;
        }
        .email-verification-hint {
            display: block;
            margin-top: 6px;
            font-size: 13px;
        }
        .password-field-group .form-control {
            border-top-right-radius: 0;
            border-bottom-right-radius: 0;
        }
        .password-field-group .btn {
            border-top-left-radius: 0;
            border-bottom-left-radius: 0;
            min-width: 52px;
        }
        .verification-code-preview {
            margin-bottom: 16px;
            padding: 18px;
            text-align: center;
            background: #fff7ed;
            border: 1px solid #fed7aa;
            border-radius: 12px;
        }
        .verification-code-preview-label {
            display: block;
            margin-bottom: 8px;
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #9a3412;
        }
        .verification-code-preview-value {
            display: block;
            font-size: 30px;
            font-weight: 700;
            letter-spacing: 0.35em;
            color: #ea580c;
        }
    </style>
</head>
<body class="auth-page">
    <main class="auth-shell">
        <div class="container content-wrapper">
            <div class="row justify-content-center">
                <div class="col-lg-8 col-md-10">
                <div class="card fade-in auth-card">
                    <div class="card-header text-center">
                        <h3><i class="fas fa-user-plus"></i> Create Account</h3>
                        <p>Join Reclaim System today</p>
                    </div>
                    <div class="card-body">
                        <?php if($error): ?>
                            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                        <?php endif; ?>
                        <?php if($success): ?>
                            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
                        <?php endif; ?>
                        <div id="emailVerificationFeedback" class="d-none"></div>
                        <div id="phoneVerificationFeedback" class="d-none"></div>
                        
                        <form method="POST" action="" id="registerForm">
                            <?= csrf_field() ?>
                            <input type="hidden" name="form_action" value="start_registration">
                            <!-- Role Selection Section -->
                            <div class="mb-4">
                                <label class="form-label required-field">I am a:</label>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <div class="role-card" data-role="student" onclick="selectRole('student')">
                                            <i class="fas fa-user-graduate"></i>
                                            <h6>Student</h6>
                                            <p>Currently enrolled student</p>
                                        </div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <div class="role-card" data-role="staff" onclick="selectRole('staff')">
                                            <i class="fas fa-chalkboard-teacher"></i>
                                            <h6>Staff</h6>
                                            <p>Faculty or administrative staff</p>
                                        </div>
                                    </div>
                                </div>
                                <input type="hidden" name="role" id="roleInput" value="<?= htmlspecialchars($formData['role']) ?>">
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="name" class="form-label required-field">Full Name</label>
                                    <input type="text" class="form-control" id="name" name="name" value="<?= htmlspecialchars($formData['name']) ?>" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="username" class="form-label required-field">Username</label>
                                    <input type="text" class="form-control" id="username" name="username" value="<?= htmlspecialchars($formData['username']) ?>" required>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="email" class="form-label required-field">Email</label>
                                    <div class="input-group email-verify-group">
                                        <input type="email" class="form-control" id="email" name="email" value="<?= htmlspecialchars($formData['email']) ?>" required>
                                        <button type="button" class="btn <?= $emailIsVerified ? 'btn-success' : 'btn-outline-warning' ?>" id="verifyEmailBtn">
                                            <i class="fas fa-envelope-open-text me-1"></i> Verify Email
                                        </button>
                                    </div>
                                    <small
                                        class="email-verification-hint <?= $emailIsVerified ? 'text-success' : 'text-muted' ?>"
                                        id="emailVerificationHint"
                                    >
                                        <?= $emailIsVerified
                                            ? 'This email is verified for this registration session.'
                                            : 'Enter your email first, then click Verify Email.' ?>
                                    </small>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="phone" class="form-label required-field">Phone Number</label>
                                    <div class="input-group email-verify-group">
                                        <input
                                            type="tel"
                                            class="form-control"
                                            id="phone"
                                            name="phone"
                                            value="<?= htmlspecialchars($formData['phone']) ?>"
                                            placeholder="e.g., 0123456789 or +60123456789"
                                            required
                                        >
                                        <button type="button" class="btn <?= $phoneIsVerified ? 'btn-success' : 'btn-outline-warning' ?>" id="verifyPhoneBtn">
                                            <i class="fas fa-sms me-1"></i> Verify Phone
                                        </button>
                                    </div>
                                    <small
                                        class="email-verification-hint <?= $phoneIsVerified ? 'text-success' : 'text-muted' ?>"
                                        id="phoneVerificationHint"
                                    >
                                        <?= $phoneIsVerified
                                            ? 'This phone number is verified for this registration session.'
                                            : 'Enter your phone number first, then click Verify Phone.' ?>
                                    </small>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="password" class="form-label required-field">Password</label>
                                    <div class="input-group password-field-group">
                                        <input type="password" class="form-control" id="password" name="password" minlength="8" pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}" title="Password must be at least 8 characters and include uppercase, lowercase, number, and special character." required<?= ($emailIsVerified && $phoneIsVerified) ? '' : ' disabled' ?>>
                                        <button
                                            type="button"
                                            class="btn btn-outline-secondary password-toggle"
                                            data-target="password"
                                            aria-label="Show password"
                                            title="Show password"
                                            <?= ($emailIsVerified && $phoneIsVerified) ? '' : 'disabled' ?>
                                        >
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                    <small class="text-muted" id="passwordHelpText"><?= ($emailIsVerified && $phoneIsVerified) ? 'Minimum 8 characters with uppercase, lowercase, number, and special character' : 'Verify your email and phone first, then create your password.' ?></small>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="confirm_password" class="form-label required-field">Confirm Password</label>
                                    <div class="input-group password-field-group">
                                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required<?= ($emailIsVerified && $phoneIsVerified) ? '' : ' disabled' ?>>
                                        <button
                                            type="button"
                                            class="btn btn-outline-secondary password-toggle"
                                            data-target="confirm_password"
                                            aria-label="Show password"
                                            title="Show password"
                                            <?= ($emailIsVerified && $phoneIsVerified) ? '' : 'disabled' ?>
                                        >
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="student_staff_id" class="form-label required-field" id="idLabel">Student ID</label>
                                    <input type="text" class="form-control" id="student_staff_id" name="student_staff_id" 
                                           value="<?= htmlspecialchars($formData['student_staff_id']) ?>"
                                           placeholder="e.g., A123456">
                                    <small class="text-muted" id="idHelpText">Enter your matriculation/student ID number</small>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="department" class="form-label required-field">Faculty / Department</label>
                                    <select class="form-control" id="department" name="department" required>
                                        <option value="">-- Select Faculty --</option>
                                        <?php foreach($departments as $dept): ?>
                                            <option value="<?= htmlspecialchars($dept) ?>" <?= ($formData['department'] == $dept) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($dept) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary" id="registerSubmitBtn"<?= ($emailIsVerified && $phoneIsVerified) ? '' : ' disabled' ?>>
                                    <i class="fas fa-user-plus"></i> Register
                                </button>
                            </div>
                        </form>
                        
                        <div class="text-center mt-3">
                            <p>Already have an account? <a href="login.php<?= $redirect !== '' ? '?redirect=' . urlencode($redirect) : '' ?>">Login here</a></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        </div>
    </main>

    <div class="modal fade" id="emailVerificationModal" tabindex="-1" aria-labelledby="emailVerificationModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="emailVerificationModalLabel"><i class="fas fa-shield-alt"></i> Verify Your Contact</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-3" id="verificationModalDescription">
                        Enter the 6-digit verification code sent to
                        <strong id="verificationEmailText"><?= htmlspecialchars($verificationDestination) ?></strong><span id="verificationModalSuffix">.</span>
                    </p>
                    <p class="text-muted small mb-3">The code expires in 5 minutes.</p>

                    <?php if($verificationError): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($verificationError) ?></div>
                    <?php endif; ?>
                    <div
                        id="verificationStatusFeedback"
                        class="<?= $verificationSuccess !== '' ? 'alert alert-' . ($verificationSuccessType === 'warning' ? 'warning' : 'success') : 'd-none' ?>"
                    ><?= $verificationSuccess !== '' ? htmlspecialchars($verificationSuccess) : '' ?></div>

                    <div
                        id="developmentOtpPreview"
                        class="verification-code-preview<?= $developmentVerificationCode !== '' ? '' : ' d-none' ?>"
                    >
                        <span class="verification-code-preview-label">Temporary Verification Code</span>
                        <strong class="verification-code-preview-value" id="developmentOtpCode"><?= htmlspecialchars($developmentVerificationCode) ?></strong>
                    </div>

                    <form method="POST" action="" id="verificationForm">
                        <?= csrf_field() ?>
                        <input type="hidden" name="form_action" value="verify_otp">
                        <input type="hidden" name="verification_target" id="verificationTargetInput" value="<?= htmlspecialchars($verificationTarget) ?>">
                        <div class="mb-3">
                            <label for="verification_code" class="form-label required-field">Verification Code</label>
                            <input
                                type="text"
                                class="form-control"
                                id="verification_code"
                                name="verification_code"
                                inputmode="numeric"
                                pattern="\d{6}"
                                maxlength="6"
                                autocomplete="one-time-code"
                                placeholder="Enter 6-digit code"
                                required
                            >
                        </div>
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary" id="verificationSubmitBtn">
                                <i class="fas fa-check-circle"></i> Verify & Complete Registration
                            </button>
                        </div>
                    </form>

                    <form method="POST" action="" class="mt-3 text-center">
                        <?= csrf_field() ?>
                        <input type="hidden" name="form_action" value="resend_otp">
                        <input type="hidden" name="verification_target" id="resendVerificationTargetInput" value="<?= htmlspecialchars($verificationTarget) ?>">
                        <button type="submit" class="btn btn-link text-decoration-none">Resend Code</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function selectRole(role) {
            document.getElementById('roleInput').value = role;

            document.querySelectorAll('.role-card').forEach(card => {
                card.classList.remove('selected');
            });
            document.querySelector(`.role-card[data-role="${role}"]`).classList.add('selected');

            const idLabel = document.getElementById('idLabel');
            const idHelpText = document.getElementById('idHelpText');
            const studentStaffId = document.getElementById('student_staff_id');

            if (role === 'student') {
                idLabel.innerHTML = 'Student ID';
                idHelpText.innerHTML = 'Enter your matriculation/student ID number';
                studentStaffId.placeholder = 'e.g., A123456 or D202312345';
            } else {
                idLabel.innerHTML = 'Staff ID';
                idHelpText.innerHTML = 'Enter your staff ID number';
                studentStaffId.placeholder = 'e.g., STF001 or EMP12345';
            }
        }

        const initialRole = '<?= htmlspecialchars($formData['role'], ENT_QUOTES, 'UTF-8') ?>';
        selectRole(initialRole);

        const password = document.getElementById('password');
        const confirmPassword = document.getElementById('confirm_password');
        const passwordHelpText = document.getElementById('passwordHelpText');
        const registerSubmitBtn = document.getElementById('registerSubmitBtn');
        const passwordToggleButtons = document.querySelectorAll('.password-toggle');
        const registerForm = document.getElementById('registerForm');
        const verifyEmailBtn = document.getElementById('verifyEmailBtn');
        const verifyPhoneBtn = document.getElementById('verifyPhoneBtn');
        const emailInput = document.getElementById('email');
        const phoneInput = document.getElementById('phone');
        const emailVerificationModal = document.getElementById('emailVerificationModal');
        const emailVerificationModalLabel = document.getElementById('emailVerificationModalLabel');
        const verificationEmailText = document.getElementById('verificationEmailText');
        const verificationModalDescription = document.getElementById('verificationModalDescription');
        const verificationModalSuffix = document.getElementById('verificationModalSuffix');
        const verificationSubmitBtn = document.getElementById('verificationSubmitBtn');
        const verificationStatusFeedback = document.getElementById('verificationStatusFeedback');
        const developmentOtpPreview = document.getElementById('developmentOtpPreview');
        const developmentOtpCode = document.getElementById('developmentOtpCode');
        const emailVerificationFeedback = document.getElementById('emailVerificationFeedback');
        const phoneVerificationFeedback = document.getElementById('phoneVerificationFeedback');
        const emailVerificationHint = document.getElementById('emailVerificationHint');
        const phoneVerificationHint = document.getElementById('phoneVerificationHint');
        const csrfTokenInput = registerForm.querySelector('input[name="csrf_token"]');
        const verificationTargetInput = document.getElementById('verificationTargetInput');
        const resendVerificationTargetInput = document.getElementById('resendVerificationTargetInput');
        const hasPendingRegistration = <?= $pendingRegistration ? 'true' : 'false' ?>;
        let hasPendingEmailVerification = <?= ($emailVerificationSession && empty($emailVerificationSession['verified_at'])) ? 'true' : 'false' ?>;
        let hasPendingPhoneVerification = <?= ($phoneVerificationSession && empty($phoneVerificationSession['verified_at'])) ? 'true' : 'false' ?>;
        let pendingVerificationEmail = <?= json_encode((string) ($emailVerificationSession['email'] ?? '')) ?>;
        let pendingVerificationPhone = <?= json_encode((string) ($phoneVerificationSession['phone'] ?? '')) ?>;
        let verifiedEmail = <?= json_encode((string) ($emailIsVerified ? $formData['email'] : '')) ?>;
        let verifiedPhone = <?= json_encode((string) ($phoneIsVerified ? $formData['phone'] : '')) ?>;
        const initialModalTarget = <?= json_encode((string) $verificationTarget) ?>;
        const initialModalDestination = <?= json_encode((string) $verificationDestination) ?>;
        const initialDevelopmentCode = <?= json_encode((string) $developmentVerificationCode) ?>;
        const redirectTarget = <?= json_encode($redirect) ?>;
        const modalInstance = emailVerificationModal ? bootstrap.Modal.getOrCreateInstance(emailVerificationModal) : null;

        function syncPasswordToggle(button, input) {
            const icon = button ? button.querySelector('i') : null;
            if (!button || !input || !icon) {
                return;
            }

            const isVisible = input.type === 'text';
            icon.className = isVisible ? 'fas fa-eye-slash' : 'fas fa-eye';
            button.setAttribute('aria-label', isVisible ? 'Hide password' : 'Show password');
            button.setAttribute('title', isVisible ? 'Hide password' : 'Show password');
        }

        passwordToggleButtons.forEach(function(button) {
            const input = document.getElementById(button.dataset.target || '');
            if (!input) {
                return;
            }

            syncPasswordToggle(button, input);

            button.addEventListener('click', function() {
                if (button.disabled || input.disabled) {
                    return;
                }

                input.type = input.type === 'password' ? 'text' : 'password';
                syncPasswordToggle(button, input);
            });
        });

        function normalizeEmailValue(value) {
            return String(value || '').trim().toLowerCase();
        }

        function normalizePhoneValue(value) {
            let phone = String(value || '').trim();
            if (phone === '') {
                return '';
            }

            phone = phone.replace(/[^\d+]/g, '').replace(/(?!^)\+/g, '');

            if (phone.startsWith('00')) {
                phone = `+${phone.slice(2)}`;
            }

            if (phone.startsWith('+')) {
                const digits = phone.slice(1).replace(/\D+/g, '');
                if (digits.length < 8 || digits.length > 15) {
                    return '';
                }

                return `+${digits}`;
            }

            let digits = phone.replace(/\D+/g, '');
            if (digits === '') {
                return '';
            }

            if (digits.startsWith('60')) {
                digits = digits;
            } else if (digits.startsWith('0')) {
                digits = `60${digits.slice(1)}`;
            } else if (/^1\d{8,9}$/.test(digits)) {
                digits = `60${digits}`;
            }

            if (digits.length < 8 || digits.length > 15) {
                return '';
            }

            return `+${digits}`;
        }

        function showFeedback(element, type, message) {
            if (!element) {
                return;
            }

            const normalizedMessage = String(message || '').trim();
            if (normalizedMessage === '') {
                element.className = 'd-none';
                element.textContent = '';
                return;
            }

            element.className = `alert alert-${type}`;
            element.textContent = normalizedMessage;
        }

        function showEmailVerificationFeedback(type, message) {
            showFeedback(emailVerificationFeedback, type, message);
        }

        function showPhoneVerificationFeedback(type, message) {
            showFeedback(phoneVerificationFeedback, type, message);
        }

        function isEmailVerifiedForCurrentInput() {
            const currentEmail = normalizeEmailValue(emailInput ? emailInput.value : '');
            const verifiedEmailNormalized = normalizeEmailValue(verifiedEmail);
            return currentEmail !== '' && verifiedEmailNormalized !== '' && currentEmail === verifiedEmailNormalized;
        }

        function isPhoneVerifiedForCurrentInput() {
            const currentPhone = normalizePhoneValue(phoneInput ? phoneInput.value : '');
            const verifiedPhoneNormalized = normalizePhoneValue(verifiedPhone);
            return currentPhone !== '' && verifiedPhoneNormalized !== '' && currentPhone === verifiedPhoneNormalized;
        }

        function validatePasswordStrength() {
            const value = password.value;
            let message = '';

            if (value && value.length < 8) {
                message = 'Password must be at least 8 characters long.';
            } else if (value && !/[A-Z]/.test(value)) {
                message = 'Password must include at least 1 uppercase letter.';
            } else if (value && !/[a-z]/.test(value)) {
                message = 'Password must include at least 1 lowercase letter.';
            } else if (value && !/\d/.test(value)) {
                message = 'Password must include at least 1 number.';
            } else if (value && !/[^A-Za-z0-9]/.test(value)) {
                message = 'Password must include at least 1 special character.';
            }

            password.setCustomValidity(message);
        }
        
        function validatePassword() {
            validatePasswordStrength();

            if (password.value !== confirmPassword.value) {
                confirmPassword.setCustomValidity('Passwords do not match');
            } else {
                confirmPassword.setCustomValidity('');
            }
        }
        
        password.addEventListener('input', validatePassword);
        password.addEventListener('change', validatePassword);
        confirmPassword.addEventListener('keyup', validatePassword);

        function setVerificationStatus(type, message) {
            if (!verificationStatusFeedback) {
                return;
            }

            const normalizedMessage = String(message || '').trim();
            if (normalizedMessage === '') {
                verificationStatusFeedback.className = 'd-none';
                verificationStatusFeedback.textContent = '';
                return;
            }

            verificationStatusFeedback.className = `alert alert-${type || 'success'}`;
            verificationStatusFeedback.textContent = normalizedMessage;
        }

        function setDevelopmentOtpPreview(code) {
            if (!developmentOtpPreview || !developmentOtpCode) {
                return;
            }

            const normalizedCode = String(code || '').trim();
            if (normalizedCode === '') {
                developmentOtpPreview.classList.add('d-none');
                developmentOtpCode.textContent = '';
                return;
            }

            developmentOtpCode.textContent = normalizedCode;
            developmentOtpPreview.classList.remove('d-none');
        }

        function setPasswordFieldsLocked(locked) {
            if (password) {
                password.disabled = locked;
                password.type = 'password';
                if (locked) {
                    password.value = '';
                    password.setCustomValidity('');
                }
            }

            if (confirmPassword) {
                confirmPassword.disabled = locked;
                confirmPassword.type = 'password';
                if (locked) {
                    confirmPassword.value = '';
                    confirmPassword.setCustomValidity('');
                }
            }

            passwordToggleButtons.forEach(function(button) {
                const input = document.getElementById(button.dataset.target || '');
                if (!input) {
                    return;
                }

                button.disabled = locked;
                syncPasswordToggle(button, input);
            });

            if (registerSubmitBtn) {
                registerSubmitBtn.disabled = locked;
            }

            if (passwordHelpText) {
                passwordHelpText.textContent = locked
                    ? 'Verify your email and phone first, then create your password.'
                    : 'Minimum 8 characters with uppercase, lowercase, number, and special character';
            }
        }

        function syncRegistrationAccess() {
            setPasswordFieldsLocked(!(isEmailVerifiedForCurrentInput() && isPhoneVerifiedForCurrentInput()));
        }

        function setVerificationModalMode(target, isRegistrationFlow, maskedDestination) {
            const normalizedTarget = target === 'phone' ? 'phone' : 'email';
            const destinationLabel = normalizedTarget === 'phone' ? 'phone number' : 'email address';
            const destinationIcon = normalizedTarget === 'phone' ? 'mobile-alt' : 'envelope-open-text';

            if (emailVerificationModalLabel) {
                emailVerificationModalLabel.innerHTML = `<i class="fas fa-${destinationIcon}"></i> Verify Your ${normalizedTarget === 'phone' ? 'Phone' : 'Email'}`;
            }

            if (verificationEmailText) {
                verificationEmailText.textContent = maskedDestination || '';
            }

            if (verificationModalSuffix) {
                verificationModalSuffix.textContent = isRegistrationFlow ? '.' : ` to verify this ${destinationLabel}.`;
            }

            if (verificationSubmitBtn) {
                if (isRegistrationFlow) {
                    verificationSubmitBtn.innerHTML = '<i class="fas fa-check-circle"></i> Verify & Complete Registration';
                } else {
                    verificationSubmitBtn.innerHTML = normalizedTarget === 'phone'
                        ? '<i class="fas fa-check-circle"></i> Verify Phone'
                        : '<i class="fas fa-check-circle"></i> Verify Email';
                }
            }

            if (verificationTargetInput) {
                verificationTargetInput.value = normalizedTarget;
            }

            if (resendVerificationTargetInput) {
                resendVerificationTargetInput.value = normalizedTarget;
            }
        }

        function syncVerifyEmailUi() {
            if (!verifyEmailBtn || !emailInput || !emailVerificationHint) {
                return;
            }

            const currentEmail = normalizeEmailValue(emailInput.value);
            const verifiedEmailNormalized = normalizeEmailValue(verifiedEmail);
            const pendingEmailNormalized = normalizeEmailValue(pendingVerificationEmail);
            const isVerifiedForCurrentEmail = currentEmail !== '' && verifiedEmailNormalized !== '' && currentEmail === verifiedEmailNormalized;
            const hasPendingOtpForCurrentEmail = currentEmail !== '' && hasPendingEmailVerification && pendingEmailNormalized !== '' && currentEmail === pendingEmailNormalized;
            const canRequestVerification = currentEmail !== '' && emailInput.checkValidity();

            verifyEmailBtn.classList.remove('btn-outline-warning', 'btn-outline-secondary', 'btn-success');
            verifyEmailBtn.disabled = !canRequestVerification;

            if (isVerifiedForCurrentEmail) {
                verifyEmailBtn.classList.add('btn-success');
                emailVerificationHint.className = 'email-verification-hint text-success';
                emailVerificationHint.textContent = 'This email is verified for this registration session.';
            } else if (currentEmail === '') {
                verifyEmailBtn.classList.add('btn-outline-secondary');
                emailVerificationHint.className = 'email-verification-hint text-muted';
                emailVerificationHint.textContent = 'Enter your email first, then click Verify Email.';
            } else if (!emailInput.checkValidity()) {
                verifyEmailBtn.classList.add('btn-outline-secondary');
                emailVerificationHint.className = 'email-verification-hint text-muted';
                emailVerificationHint.textContent = 'Enter a valid email address before verifying.';
            } else if (hasPendingOtpForCurrentEmail) {
                verifyEmailBtn.classList.add('btn-outline-secondary');
                emailVerificationHint.className = 'email-verification-hint text-warning';
                emailVerificationHint.textContent = 'A verification code is already waiting for this email. Click Verify Email to continue.';
            } else {
                verifyEmailBtn.classList.add('btn-outline-warning');
                emailVerificationHint.className = 'email-verification-hint text-muted';
                emailVerificationHint.textContent = 'Use Verify Email to confirm this address before registering.';
            }

            syncRegistrationAccess();
        }

        function syncVerifyPhoneUi() {
            if (!verifyPhoneBtn || !phoneInput || !phoneVerificationHint) {
                return;
            }

            const currentPhone = normalizePhoneValue(phoneInput.value);
            const verifiedPhoneNormalized = normalizePhoneValue(verifiedPhone);
            const pendingPhoneNormalized = normalizePhoneValue(pendingVerificationPhone);
            const isVerifiedForCurrentPhone = currentPhone !== '' && verifiedPhoneNormalized !== '' && currentPhone === verifiedPhoneNormalized;
            const hasPendingOtpForCurrentPhone = currentPhone !== '' && hasPendingPhoneVerification && pendingPhoneNormalized !== '' && currentPhone === pendingPhoneNormalized;
            const canRequestVerification = currentPhone !== '' && normalizePhoneValue(phoneInput.value) !== '';

            verifyPhoneBtn.classList.remove('btn-outline-warning', 'btn-outline-secondary', 'btn-success');
            verifyPhoneBtn.disabled = !canRequestVerification;

            if (isVerifiedForCurrentPhone) {
                verifyPhoneBtn.classList.add('btn-success');
                phoneVerificationHint.className = 'email-verification-hint text-success';
                phoneVerificationHint.textContent = 'This phone number is verified for this registration session.';
            } else if (phoneInput.value.trim() === '') {
                verifyPhoneBtn.classList.add('btn-outline-secondary');
                phoneVerificationHint.className = 'email-verification-hint text-muted';
                phoneVerificationHint.textContent = 'Enter your phone number first, then click Verify Phone.';
            } else if (!canRequestVerification) {
                verifyPhoneBtn.classList.add('btn-outline-secondary');
                phoneVerificationHint.className = 'email-verification-hint text-muted';
                phoneVerificationHint.textContent = 'Enter a valid Malaysian or international mobile number before verifying.';
            } else if (hasPendingOtpForCurrentPhone) {
                verifyPhoneBtn.classList.add('btn-outline-secondary');
                phoneVerificationHint.className = 'email-verification-hint text-warning';
                phoneVerificationHint.textContent = 'A verification code is already waiting for this phone number. Click Verify Phone to continue.';
            } else {
                verifyPhoneBtn.classList.add('btn-outline-warning');
                phoneVerificationHint.className = 'email-verification-hint text-muted';
                phoneVerificationHint.textContent = 'Use Verify Phone to confirm this number before registering.';
            }

            syncRegistrationAccess();
        }

        function openVerificationModal(target, maskedDestination, isRegistrationFlow) {
            if (!modalInstance) {
                return;
            }

            const verificationCodeInput = document.getElementById('verification_code');
            if (verificationCodeInput) {
                verificationCodeInput.value = '';
            }

            setVerificationModalMode(target, isRegistrationFlow, maskedDestination);
            modalInstance.show();
        }

        if (verifyEmailBtn && registerForm && emailInput) {
            verifyEmailBtn.addEventListener('click', async function() {
                const currentEmail = emailInput.value.trim();
                const normalizedCurrentEmail = normalizeEmailValue(currentEmail);
                const pendingEmail = normalizeEmailValue(pendingVerificationEmail);
                const normalizedVerifiedEmail = normalizeEmailValue(verifiedEmail);

                if (currentEmail === '') {
                    emailInput.reportValidity();
                    emailInput.focus();
                    return;
                }

                if (!emailInput.checkValidity()) {
                    emailInput.reportValidity();
                    emailInput.focus();
                    return;
                }

                if (normalizedVerifiedEmail !== '' && normalizedCurrentEmail === normalizedVerifiedEmail) {
                    showEmailVerificationFeedback('success', 'This email is already verified. You can continue with registration.');
                    setVerificationStatus('success', '');
                    setDevelopmentOtpPreview('');
                    syncVerifyEmailUi();
                    return;
                }

                if (hasPendingEmailVerification && pendingEmail !== '' && normalizedCurrentEmail === pendingEmail) {
                    openVerificationModal('email', currentEmail, hasPendingRegistration);
                    showEmailVerificationFeedback('info', 'Continue by entering the verification code for this email.');
                    return;
                }

                const originalButtonHtml = verifyEmailBtn.innerHTML;
                verifyEmailBtn.disabled = true;
                verifyEmailBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Sending...';

                try {
                    const payload = new URLSearchParams();
                    payload.set('csrf_token', csrfTokenInput ? csrfTokenInput.value : '');
                    payload.set('form_action', 'send_email_verification_only');
                    payload.set('verification_email', currentEmail);
                    payload.set('email', currentEmail);
                    payload.set('name', document.getElementById('name')?.value.trim() || '');
                    payload.set('username', document.getElementById('username')?.value.trim() || '');
                    payload.set('student_staff_id', document.getElementById('student_staff_id')?.value.trim() || '');
                    payload.set('department', document.getElementById('department')?.value || '');
                    payload.set('phone', document.getElementById('phone')?.value.trim() || '');
                    payload.set('role', document.getElementById('roleInput')?.value || 'student');
                    payload.set('redirect', <?= json_encode($redirect) ?>);

                    const response = await fetch(registerForm.getAttribute('action') || window.location.href, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: payload.toString()
                    });

                    const responseText = await response.text();
                    let result;

                    try {
                        result = JSON.parse(responseText);
                    } catch (error) {
                        throw new Error(responseText || 'Unexpected server response.');
                    }

                    if (!response.ok || !result.success) {
                        throw new Error(result.message || 'Unable to send verification code right now. Please try again later.');
                    }

                    if (verificationEmailText && result.masked_email) {
                        verificationEmailText.textContent = result.masked_email;
                    }

                    showEmailVerificationFeedback('success', result.message);
                    setVerificationStatus(result.status_type || 'success', result.message || '');
                    setDevelopmentOtpPreview(result.temporary_code || '');

                    if (result.already_verified) {
                        verifiedEmail = currentEmail;
                        hasPendingEmailVerification = false;
                        pendingVerificationEmail = '';
                        syncVerifyEmailUi();
                        return;
                    }

                    hasPendingEmailVerification = !!result.show_modal;
                    pendingVerificationEmail = currentEmail;
                    syncVerifyEmailUi();

                    if (result.show_modal) {
                        openVerificationModal('email', result.masked_destination || result.masked_email || currentEmail, false);
                    }
                } catch (error) {
                    showEmailVerificationFeedback('danger', error.message || 'Unable to send verification code right now. Please try again later.');
                } finally {
                    verifyEmailBtn.disabled = false;
                    verifyEmailBtn.innerHTML = originalButtonHtml;
                    syncVerifyEmailUi();
                }
            });

            emailInput.addEventListener('input', function() {
                const currentEmail = normalizeEmailValue(emailInput.value);
                const pendingEmail = normalizeEmailValue(pendingVerificationEmail);

                if (currentEmail === '' || (pendingEmail !== '' && currentEmail !== pendingEmail)) {
                    hasPendingEmailVerification = false;
                    pendingVerificationEmail = '';
                    showEmailVerificationFeedback('secondary', '');
                    setVerificationStatus('success', '');
                    setDevelopmentOtpPreview('');

                    if (!hasPendingRegistration && modalInstance) {
                        modalInstance.hide();
                    }
                }

                if (currentEmail === '' || currentEmail !== normalizeEmailValue(verifiedEmail)) {
                    setVerificationStatus('success', '');
                    setDevelopmentOtpPreview('');
                }

                syncVerifyEmailUi();
            });
        }

        if (verifyPhoneBtn && registerForm && phoneInput) {
            verifyPhoneBtn.addEventListener('click', async function() {
                const currentPhoneRaw = phoneInput.value.trim();
                const currentPhone = normalizePhoneValue(currentPhoneRaw);
                const pendingPhone = normalizePhoneValue(pendingVerificationPhone);
                const normalizedVerifiedPhone = normalizePhoneValue(verifiedPhone);

                if (currentPhoneRaw === '') {
                    phoneInput.reportValidity();
                    phoneInput.focus();
                    return;
                }

                if (currentPhone === '') {
                    showPhoneVerificationFeedback('warning', 'Enter a valid Malaysian or international mobile number before verifying.');
                    phoneInput.focus();
                    return;
                }

                if (normalizedVerifiedPhone !== '' && currentPhone === normalizedVerifiedPhone) {
                    showPhoneVerificationFeedback('success', 'This phone number is already verified. You can continue with registration.');
                    setVerificationStatus('success', '');
                    setDevelopmentOtpPreview('');
                    syncVerifyPhoneUi();
                    return;
                }

                if (hasPendingPhoneVerification && pendingPhone !== '' && currentPhone === pendingPhone) {
                    openVerificationModal('phone', currentPhone, false);
                    showPhoneVerificationFeedback('info', 'Continue by entering the verification code for this phone number.');
                    return;
                }

                const originalButtonHtml = verifyPhoneBtn.innerHTML;
                verifyPhoneBtn.disabled = true;
                verifyPhoneBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Sending...';

                try {
                    const payload = new URLSearchParams();
                    payload.set('csrf_token', csrfTokenInput ? csrfTokenInput.value : '');
                    payload.set('form_action', 'send_phone_verification_only');
                    payload.set('verification_phone', currentPhoneRaw);
                    payload.set('email', emailInput ? emailInput.value.trim() : '');
                    payload.set('name', document.getElementById('name')?.value.trim() || '');
                    payload.set('username', document.getElementById('username')?.value.trim() || '');
                    payload.set('student_staff_id', document.getElementById('student_staff_id')?.value.trim() || '');
                    payload.set('department', document.getElementById('department')?.value || '');
                    payload.set('phone', currentPhoneRaw);
                    payload.set('role', document.getElementById('roleInput')?.value || 'student');
                    payload.set('redirect', redirectTarget);

                    const response = await fetch(registerForm.getAttribute('action') || window.location.href, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: payload.toString()
                    });

                    const responseText = await response.text();
                    let result;

                    try {
                        result = JSON.parse(responseText);
                    } catch (error) {
                        throw new Error(responseText || 'Unexpected server response.');
                    }

                    if (!response.ok || !result.success) {
                        throw new Error(result.message || 'Unable to send the SMS verification code right now. Please try again later.');
                    }

                    if (result.normalized_phone && phoneInput) {
                        phoneInput.value = result.normalized_phone;
                    }

                    showPhoneVerificationFeedback('success', result.message);
                    setVerificationStatus(result.status_type || 'success', result.message || '');
                    setDevelopmentOtpPreview(result.temporary_code || '');

                    if (result.already_verified) {
                        verifiedPhone = result.normalized_phone || currentPhone;
                        hasPendingPhoneVerification = false;
                        pendingVerificationPhone = '';
                        syncVerifyPhoneUi();
                        return;
                    }

                    hasPendingPhoneVerification = !!result.show_modal;
                    pendingVerificationPhone = result.normalized_phone || currentPhone;
                    syncVerifyPhoneUi();

                    if (result.show_modal) {
                        openVerificationModal('phone', result.masked_destination || result.masked_phone || (result.normalized_phone || currentPhone), false);
                    }
                } catch (error) {
                    showPhoneVerificationFeedback('danger', error.message || 'Unable to send the SMS verification code right now. Please try again later.');
                } finally {
                    verifyPhoneBtn.disabled = false;
                    verifyPhoneBtn.innerHTML = originalButtonHtml;
                    syncVerifyPhoneUi();
                }
            });

            phoneInput.addEventListener('input', function() {
                const currentPhone = normalizePhoneValue(phoneInput.value);
                const pendingPhone = normalizePhoneValue(pendingVerificationPhone);

                if (phoneInput.value.trim() === '' || (pendingPhone !== '' && currentPhone !== pendingPhone)) {
                    hasPendingPhoneVerification = false;
                    pendingVerificationPhone = '';
                    showPhoneVerificationFeedback('secondary', '');
                    setVerificationStatus('success', '');
                    setDevelopmentOtpPreview('');

                    if (!hasPendingRegistration && modalInstance) {
                        modalInstance.hide();
                    }
                }

                if (currentPhone === '' || currentPhone !== normalizePhoneValue(verifiedPhone)) {
                    setVerificationStatus('success', '');
                    setDevelopmentOtpPreview('');
                }

                syncVerifyPhoneUi();
            });
        }

        registerForm.addEventListener('submit', function(e) {
            const role = document.getElementById('roleInput').value;
            const studentStaffId = document.getElementById('student_staff_id').value;
            const currentEmail = normalizeEmailValue(emailInput ? emailInput.value : '');
            const currentPhone = normalizePhoneValue(phoneInput ? phoneInput.value : '');

            if (!isEmailVerifiedForCurrentInput()) {
                e.preventDefault();
                showEmailVerificationFeedback('warning', 'Please verify your email first using the Verify Email button.');
                emailInput.focus();
                return false;
            }

            if (!isPhoneVerifiedForCurrentInput()) {
                e.preventDefault();
                showPhoneVerificationFeedback('warning', 'Please verify your phone number first using the Verify Phone button.');
                phoneInput.focus();
                return false;
            }

            if (phoneInput && currentPhone !== '') {
                phoneInput.value = currentPhone;
            }

            validatePassword();

            if (!studentStaffId.trim()) {
                e.preventDefault();
                alert(role === 'student' ? 'Please enter your Student ID' : 'Please enter your Staff ID');
                return false;
            }
        });

        if (emailVerificationModal) {
            emailVerificationModal.addEventListener('shown.bs.modal', function () {
                const verificationCodeInput = document.getElementById('verification_code');
                if (verificationCodeInput) {
                    verificationCodeInput.focus();
                }
            });
        }

        syncVerifyEmailUi();
        syncVerifyPhoneUi();
        setDevelopmentOtpPreview(initialDevelopmentCode);

        <?php if ($showVerificationModal): ?>
        if (modalInstance) {
            openVerificationModal(initialModalTarget, initialModalDestination, <?= $verificationModalIsRegistrationFlow ? 'true' : 'false' ?>);
        }
        <?php endif; ?>
    </script>
</body>
</html>
