<?php
function isLoggedIn() {
    return isset($_SESSION['userID']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ' . app_url_path('login.php'));
        exit();
    }
}

function requireAdmin() {
    requireLogin();
    if ($_SESSION['role'] !== 'admin') {
        header('Location: ' . app_url_path('index.php'));
        exit();
    }
}

function checkAuth() {
    if (!isLoggedIn()) {
        header('Location: ' . app_url_path('login.php'));
        exit();
    }
}

function hasRole($role) {
    return isset($_SESSION['role']) && $_SESSION['role'] === $role;
}
?>
