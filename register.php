<?php
require_once 'config/database.php';
require_once 'config/mail.php';

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
        str_contains($redirect, '..') ||
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

function clear_register_pending_data() {
    unset($_SESSION[REGISTER_OTP_SESSION_KEY]);
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

function send_register_otp_email($email, $name, $otpCode) {
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

    return MailConfig::sendNotification($email, $subject, $body);
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
    return $stmt->execute($values);
}

$formData = register_form_defaults();
$pendingRegistration = register_pending_data();
$showVerificationModal = false;
$verificationError = '';
$verificationSuccess = '';
$verificationEmail = '';

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
        $verificationEmail = mask_register_email($pendingRegistration['email'] ?? '');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();

    $formAction = $_POST['form_action'] ?? 'start_registration';
    $db = Database::getInstance()->getConnection();

    if ($formAction === 'verify_otp') {
        $pendingRegistration = register_pending_data();

        if (!$pendingRegistration) {
            $error = 'Verification session expired. Please register again.';
        } elseif (($pendingRegistration['expires_at'] ?? 0) < time()) {
            clear_register_pending_data();
            $error = 'Verification code expired. Please register again.';
        } else {
            $submittedCode = trim($_POST['verification_code'] ?? '');
            $showVerificationModal = true;
            $verificationEmail = mask_register_email($pendingRegistration['email'] ?? '');
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
                        if (create_verified_user($db, $pendingRegistration)) {
                            clear_register_pending_data();
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
    } elseif ($formAction === 'resend_otp') {
        $pendingRegistration = register_pending_data();

        if (!$pendingRegistration) {
            $error = 'Verification session expired. Please register again.';
        } elseif (($pendingRegistration['expires_at'] ?? 0) < time()) {
            clear_register_pending_data();
            $error = 'Verification code expired. Please register again.';
        } else {
            $showVerificationModal = true;
            $verificationEmail = mask_register_email($pendingRegistration['email'] ?? '');
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
                    if (send_register_otp_email($pendingRegistration['email'], $pendingRegistration['name'], $newOtp)) {
                        $_SESSION[REGISTER_OTP_SESSION_KEY]['otp_hash'] = hash('sha256', $newOtp);
                        $_SESSION[REGISTER_OTP_SESSION_KEY]['expires_at'] = time() + REGISTER_OTP_EXPIRY_SECONDS;
                        $verificationSuccess = 'A new verification code has been sent to your email.';
                    } else {
                        $verificationError = 'Unable to resend the verification code right now. Please try again.';
                    }
                }
            }
        }
    } else {
        $input = normalize_register_input($_POST);
        $requested_role = $input['role'];
        $role = in_array($requested_role, ['student', 'staff'], true) ? $requested_role : 'student';
        $input['role'] = $role;
        $input['redirect'] = $redirect !== '' ? $redirect : $input['redirect'];
        $formData = array_merge($formData, $input);

        if (empty($input['name']) || empty($input['email']) || empty($input['username']) || empty($input['password'])) {
            $error = 'Please fill in all required fields';
        } elseif ($input['password'] !== $input['confirm_password']) {
            $error = 'Passwords do not match';
        } elseif (($passwordError = register_password_error($input['password'])) !== '') {
            $error = $passwordError;
        } elseif (!filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid email format';
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
                    $otpCode = generate_register_otp();
                    $passwordHash = password_hash($input['password'], PASSWORD_BCRYPT);
                    if ($passwordHash === false) {
                        $error = 'Unable to secure your password right now. Please try again.';
                    } elseif (send_register_otp_email($input['email'], $input['name'], $otpCode)) {
                        session_regenerate_id(true);
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
                        $pendingRegistration = $_SESSION[REGISTER_OTP_SESSION_KEY];
                        $showVerificationModal = true;
                        $verificationEmail = mask_register_email($input['email']);
                        $success = 'A verification code has been sent to your email. Enter it below to complete registration.';
                    } else {
                        $error = 'Unable to send verification code right now. Please try again later.';
                    }
                }
            }
        }
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
                                    <input type="email" class="form-control" id="email" name="email" value="<?= htmlspecialchars($formData['email']) ?>" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="phone" class="form-label">Phone Number</label>
                                    <input type="tel" class="form-control" id="phone" name="phone" value="<?= htmlspecialchars($formData['phone']) ?>">
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="password" class="form-label required-field">Password</label>
                                    <input type="password" class="form-control" id="password" name="password" minlength="8" pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}" title="Password must be at least 8 characters and include uppercase, lowercase, number, and special character." required>
                                    <small class="text-muted">Minimum 8 characters with uppercase, lowercase, number, and special character</small>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="confirm_password" class="form-label required-field">Confirm Password</label>
                                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
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
                                <button type="submit" class="btn btn-primary">
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
                    <h5 class="modal-title" id="emailVerificationModalLabel"><i class="fas fa-envelope-open-text"></i> Verify Your Email</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-3">
                        Enter the 6-digit verification code sent to
                        <strong><?= htmlspecialchars($verificationEmail) ?></strong>.
                    </p>
                    <p class="text-muted small mb-3">The code expires in 5 minutes.</p>

                    <?php if($verificationError): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($verificationError) ?></div>
                    <?php endif; ?>
                    <?php if($verificationSuccess): ?>
                        <div class="alert alert-success"><?= htmlspecialchars($verificationSuccess) ?></div>
                    <?php endif; ?>

                    <form method="POST" action="" id="verificationForm">
                        <?= csrf_field() ?>
                        <input type="hidden" name="form_action" value="verify_otp">
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
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-check-circle"></i> Verify & Complete Registration
                            </button>
                        </div>
                    </form>

                    <form method="POST" action="" class="mt-3 text-center">
                        <?= csrf_field() ?>
                        <input type="hidden" name="form_action" value="resend_otp">
                        <button type="submit" class="btn btn-link text-decoration-none">Resend Code</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Role selection function
        function selectRole(role) {
            // Update hidden input
            document.getElementById('roleInput').value = role;
            
            // Update UI for role cards
            document.querySelectorAll('.role-card').forEach(card => {
                card.classList.remove('selected');
            });
            document.querySelector(`.role-card[data-role="${role}"]`).classList.add('selected');
            
            // Update labels based on role
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
        
        // Set initial selected role based on POST data or default
        const initialRole = '<?= htmlspecialchars($formData['role'], ENT_QUOTES, 'UTF-8') ?>';
        selectRole(initialRole);
        
        // Password confirmation validation
        const password = document.getElementById('password');
        const confirmPassword = document.getElementById('confirm_password');

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
        
        // Form validation before submit
        document.getElementById('registerForm').addEventListener('submit', function(e) {
            const role = document.getElementById('roleInput').value;
            const studentStaffId = document.getElementById('student_staff_id').value;

            validatePassword();
            
            if (!studentStaffId.trim()) {
                e.preventDefault();
                alert(role === 'student' ? 'Please enter your Student ID' : 'Please enter your Staff ID');
                return false;
            }
        });

        <?php if ($showVerificationModal): ?>
        const emailVerificationModal = document.getElementById('emailVerificationModal');
        if (emailVerificationModal) {
            const verificationModal = new bootstrap.Modal(emailVerificationModal);
            verificationModal.show();

            emailVerificationModal.addEventListener('shown.bs.modal', function () {
                const verificationCodeInput = document.getElementById('verification_code');
                if (verificationCodeInput) {
                    verificationCodeInput.focus();
                }
            }, { once: true });
        }
        <?php endif; ?>
    </script>
</body>
</html>
