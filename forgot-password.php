<?php
require_once 'config/database.php';
require_once 'includes/password_reset.php';

if (session_status() === PHP_SESSION_NONE) {
    secureSessionStart();
}

if (isset($_SESSION['userID'])) {
    if (($_SESSION['role'] ?? '') === 'admin') {
        header('Location: admin/dashboard.php');
    } else {
        header('Location: user/dashboard.php');
    }
    exit();
}

$error = '';
$success = '';
$email = '';
$redirect = trim($_GET['redirect'] ?? ($_POST['redirect'] ?? ''));

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();

    $email = trim($_POST['email'] ?? '');

    if ($email === '') {
        $error = 'Please enter your email address.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        try {
            $db = Database::getInstance()->getConnection();

            if (!password_reset_columns_available($db)) {
                $error = 'Password reset is not available right now. Please contact support.';
            } else {
                $stmt = $db->prepare('SELECT user_id, name, email FROM users WHERE email = ? AND is_active = 1 LIMIT 1');
                $stmt->execute([$email]);
                $user = $stmt->fetch();

                if ($user) {
                    $token = password_reset_generate_token();
                    $tokenHash = password_reset_hash_token($token);
                    $expiresAt = date('Y-m-d H:i:s', time() + PASSWORD_RESET_TOKEN_EXPIRY_SECONDS);
                    $resetLinkParams = [
                        'email' => $user['email'],
                        'token' => $token,
                    ];
                    if ($redirect !== '') {
                        $resetLinkParams['redirect'] = $redirect;
                    }
                    $resetLink = password_reset_build_url('reset-password.php', $resetLinkParams);

                    $updateStmt = $db->prepare('UPDATE users SET verification_token = ?, token_expiry = ? WHERE user_id = ?');
                    $updateStmt->execute([$tokenHash, $expiresAt, $user['user_id']]);

                    if (!password_reset_send_email($user['email'], $user['name'], $resetLink)) {
                        error_log('Password reset email failed for: ' . $user['email']);
                    }
                }

                $success = 'If an active account with that email exists, a password reset link has been sent.';
                $email = '';
            }
        } catch (PDOException $e) {
            error_log('Forgot password database error: ' . $e->getMessage());
            $error = 'Unable to process your request right now. Please try again later.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Reclaim System</title>
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
                            <h3><i class="fas fa-key"></i> Forgot Password</h3>
                            <p class="mb-0">Enter your email to receive a reset link</p>
                        </div>
                        <div class="card-body">
                            <?php if ($error): ?>
                                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                            <?php endif; ?>
                            <?php if ($success): ?>
                                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
                            <?php endif; ?>

                            <form method="POST" action="">
                                <?= csrf_field() ?>
                                <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">
                                <div class="mb-3">
                                    <label for="email" class="form-label">Email Address</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                                        <input type="email" class="form-control" id="email" name="email" value="<?= htmlspecialchars($email) ?>" required>
                                    </div>
                                </div>
                                <div class="d-grid">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-paper-plane"></i> Send Reset Link
                                    </button>
                                </div>
                            </form>

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
</body>
</html>
