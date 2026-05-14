<?php
require_once 'config/database.php';
require_once 'includes/password_reset.php';

if (session_status() === PHP_SESSION_NONE) {
    secureSessionStart();
}

$error = '';
$success = '';
$password = '';
$confirmPassword = '';
$email = trim($_GET['email'] ?? ($_POST['email'] ?? ''));
$token = trim($_GET['token'] ?? ($_POST['token'] ?? ''));
$redirect = trim($_GET['redirect'] ?? ($_POST['redirect'] ?? ''));
$showForm = false;
$user = null;

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

if ($email === '' || $token === '') {
    $error = 'This password reset link is incomplete or invalid.';
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $error = 'This password reset link is invalid.';
} else {
    try {
        $db = Database::getInstance()->getConnection();

        if (!password_reset_columns_available($db)) {
            $error = 'Password reset is not available right now. Please contact support.';
        } else {
            $stmt = $db->prepare('SELECT user_id, name, email, verification_token, token_expiry FROM users WHERE email = ? AND is_active = 1 LIMIT 1');
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            $storedTokenHash = (string) ($user['verification_token'] ?? '');
            $tokenExpiry = $user['token_expiry'] ?? null;
            $isExpired = !$tokenExpiry || strtotime($tokenExpiry) < time();
            $isTokenMatch = $storedTokenHash !== '' && hash_equals($storedTokenHash, password_reset_hash_token($token));

            if (!$user || $isExpired || !$isTokenMatch) {
                if ($user && $isExpired) {
                    $clearStmt = $db->prepare('UPDATE users SET verification_token = NULL, token_expiry = NULL WHERE user_id = ?');
                    $clearStmt->execute([$user['user_id']]);
                }
                $error = 'This password reset link is invalid or has expired.';
            } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
                require_csrf_token();

                $password = (string) ($_POST['password'] ?? '');
                $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

                if ($password === '' || $confirmPassword === '') {
                    $error = 'Please fill in all fields.';
                } elseif ($password !== $confirmPassword) {
                    $error = 'Passwords do not match.';
                } elseif (($passwordError = password_reset_password_error($password)) !== '') {
                    $error = $passwordError;
                } else {
                    $passwordHash = password_hash($password, PASSWORD_BCRYPT);

                    if ($passwordHash === false) {
                        $error = 'Unable to secure your password right now. Please try again.';
                    } else {
                        $updateStmt = $db->prepare('UPDATE users SET password = ?, verification_token = NULL, token_expiry = NULL WHERE user_id = ?');
                        $updateStmt->execute([$passwordHash, $user['user_id']]);

                        $loginUrl = 'login.php?reset=success';
                        if ($redirect !== '') {
                            $loginUrl .= '&redirect=' . urlencode($redirect);
                        }

                        header('Location: ' . $loginUrl);
                        exit();
                    }
                }

                $showForm = true;
            } else {
                $showForm = true;
            }
        }
    } catch (PDOException $e) {
        error_log('Reset password database error: ' . $e->getMessage());
        $error = 'Unable to reset your password right now. Please try again later.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - Reclaim System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .auth-card {
            max-width: 560px;
            margin: 0 auto;
        }
    </style>
</head>
<body class="auth-page">
    <main class="auth-shell">
        <div class="container content-wrapper">
            <div class="row justify-content-center">
                <div class="col-lg-5 col-md-7">
                    <div class="card fade-in auth-card">
                        <div class="card-header text-center">
                            <h3><i class="fas fa-unlock-alt"></i> Reset Password</h3>
                            <p class="mb-0">Choose a new password for your account</p>
                        </div>
                        <div class="card-body">
                            <?php if ($error): ?>
                                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                            <?php endif; ?>
                            <?php if ($success): ?>
                                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
                            <?php endif; ?>

                            <?php if ($showForm): ?>
                                <form method="POST" action="">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>">
                                    <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                                    <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">
                                    <div class="mb-3">
                                        <label for="password" class="form-label">New Password</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="fas fa-lock"></i></span>
                                            <input type="password" class="form-control" id="password" name="password" minlength="8" pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}" title="Password must be at least 8 characters and include uppercase, lowercase, number, and special character." autocomplete="new-password" required>
                                        </div>
                                        <small class="text-muted">Minimum 8 characters with uppercase, lowercase, number, and special character</small>
                                    </div>
                                    <div class="mb-3">
                                        <label for="confirm_password" class="form-label">Confirm New Password</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="fas fa-check-circle"></i></span>
                                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" autocomplete="new-password" required>
                                        </div>
                                    </div>
                                    <div class="d-grid">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-save"></i> Update Password
                                        </button>
                                    </div>
                                </form>
                            <?php else: ?>
                                <div class="text-center">
                                    <p><a href="forgot-password.php<?= $redirect !== '' ? '?redirect=' . urlencode($redirect) : '' ?>">Request a new reset link</a></p>
                                </div>
                            <?php endif; ?>

                            <div class="text-center mt-3">
                                <p><a href="login.php<?= $redirect !== '' ? '?redirect=' . urlencode($redirect) : '' ?>">Back to Login</a></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const password = document.getElementById('password');
        const confirmPassword = document.getElementById('confirm_password');

        function validatePasswordStrength() {
            if (!password) {
                return;
            }

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

        function validatePasswordMatch() {
            if (!password || !confirmPassword) {
                return;
            }

            validatePasswordStrength();

            if (password.value !== confirmPassword.value) {
                confirmPassword.setCustomValidity('Passwords do not match');
            } else {
                confirmPassword.setCustomValidity('');
            }
        }

        if (password && confirmPassword) {
            password.addEventListener('input', validatePasswordMatch);
            password.addEventListener('change', validatePasswordMatch);
            confirmPassword.addEventListener('keyup', validatePasswordMatch);
        }
    </script>
</body>
</html>
