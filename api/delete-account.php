<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['userID'])) {
    header('Location: /reclaim-system/login.php');
    exit();
}

$db = Database::getInstance()->getConnection();
$userID = $_SESSION['userID'];

try {
    // Start transaction
    $db->beginTransaction();
    
    // First, get user info for logging (optional)
    $stmt = $db->prepare("SELECT email, name FROM users WHERE user_id = ?");
    $stmt->execute([$userID]);
    $user = $stmt->fetch();
    
    if (!$user) {
        throw new Exception('User not found');
    }
    
    // Delete user's notifications first (foreign key constraint)
    $stmt = $db->prepare("DELETE FROM notifications WHERE user_id = ?");
    $stmt->execute([$userID]);
    
    // Delete user's claim requests
    $stmt = $db->prepare("DELETE FROM claim_requests WHERE claimant_id = ?");
    $stmt->execute([$userID]);
    
    // Delete user's search history
    $stmt = $db->prepare("DELETE FROM search_history WHERE userID = ?");
    $stmt->execute([$userID]);
    
    // Update items reported by user - set reported_by to NULL instead of deleting
    $stmt = $db->prepare("UPDATE items SET reported_by = NULL WHERE reported_by = ?");
    $stmt->execute([$userID]);
    
    // Delete user's lost reports
    $stmt = $db->prepare("DELETE FROM lost_reports WHERE reporterID = ?");
    $stmt->execute([$userID]);
    
    // Delete user's found reports
    $stmt = $db->prepare("DELETE FROM found_reports WHERE reporterID = ?");
    $stmt->execute([$userID]);
    
    // Finally, delete the user
    $stmt = $db->prepare("DELETE FROM users WHERE user_id = ?");
    $stmt->execute([$userID]);
    
    // Commit transaction
    $db->commit();
    
    // Clear session
    $_SESSION = array();
    
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    session_destroy();
    
    // Set success message in session for display on index page
    session_start();
    $_SESSION['account_deleted'] = 'Your account has been successfully deleted. We are sorry to see you go!';
    
    // Redirect to index page
    header('Location: /reclaim-system/index.php');
    exit();
    
} catch (Exception $e) {
    // Rollback transaction on error
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    
    // Log error (optional)
    error_log("Account deletion failed for user ID $userID: " . $e->getMessage());
    
    // Set error message and redirect back to profile
    $_SESSION['delete_error'] = 'Failed to delete account. Please try again or contact support.';
    header('Location: /reclaim-system/user/user-profile.php');
    exit();
}
?>