<?php
require_once __DIR__ . '/bootstrap.php';

$method = mobileApiRequireMethod(['GET', 'POST']);
$auth = mobileApiAuthenticate($mobileApiDb);
$userId = (int) $auth['user']['user_id'];

try {
    if ($method === 'GET') {
        $stmt = $mobileApiDb->prepare('SELECT * FROM users WHERE user_id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: $auth['user'];

        $stmt = $mobileApiDb->prepare("
            SELECT
                (SELECT COUNT(*) FROM items WHERE reported_by = ?) AS total_reports,
                (SELECT COUNT(*) FROM claim_requests WHERE claimant_id = ?) AS total_claims,
                (SELECT COUNT(*) FROM claim_requests WHERE claimant_id = ? AND status = 'approved') AS approved_claims,
                (SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0) AS unread_notifications
        ");
        $stmt->execute([$userId, $userId, $userId, $userId]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $stmt = $mobileApiDb->prepare("
            (SELECT 'report' AS type, item_id AS reference_id, reported_date AS activity_date, 'Reported an item' AS action
             FROM items WHERE reported_by = ? LIMIT 5)
            UNION
            (SELECT 'claim' AS type, claim_id AS reference_id, created_at AS activity_date, 'Submitted a claim' AS action
             FROM claim_requests WHERE claimant_id = ? LIMIT 5)
            ORDER BY activity_date DESC
            LIMIT 5
        ");
        $stmt->execute([$userId, $userId]);
        $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

        mobileApiSuccess([
            'user' => mobileApiUserPayload($user),
            'stats' => [
                'total_reports' => (int) ($stats['total_reports'] ?? 0),
                'total_claims' => (int) ($stats['total_claims'] ?? 0),
                'approved_claims' => (int) ($stats['approved_claims'] ?? 0),
                'unread_notifications' => (int) ($stats['unread_notifications'] ?? 0),
            ],
            'activities' => $activities,
        ], 'Profile loaded.');
    }

    $input = mobileApiTrimmed(mobileApiRequestData());
    $name = (string) ($input['name'] ?? '');
    $phone = (string) ($input['phone'] ?? '');
    $department = (string) ($input['department'] ?? '');
    $studentStaffId = (string) ($input['student_staff_id'] ?? '');
    $currentPassword = (string) ($input['current_password'] ?? '');
    $newPassword = (string) ($input['new_password'] ?? '');
    $removeProfileImage = (int) ($input['remove_profile_image'] ?? 0) === 1;

    $errors = [];

    if ($name === '') {
        $errors['name'] = 'Name is required.';
    }

    if ($studentStaffId === '') {
        $errors['student_staff_id'] = 'Student ID is required.';
    }

    if ($department === '') {
        $errors['department'] = 'Department is required.';
    }

    if (($currentPassword === '') xor ($newPassword === '')) {
        $errors['password'] = 'Provide both current and new password to change your password.';
    }

    if ($newPassword !== '') {
        $passwordError = mobileApiPasswordError($newPassword);
        if ($passwordError !== '') {
            $errors['new_password'] = $passwordError;
        }
    }

    if (!empty($errors)) {
        mobileApiError('Please correct the highlighted fields.', 422, $errors, 'validation_failed');
    }

    $stmt = $mobileApiDb->prepare('SELECT * FROM users WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        mobileApiError('User not found.', 404, null, 'user_not_found');
    }

    $profileImagePath = $user['profile_image'] ?? null;

    if ($removeProfileImage && !empty($profileImagePath)) {
        delete_uploaded_file_safely((string) $profileImagePath, __DIR__ . '/../../assets/uploads/profiles');
        $profileImagePath = null;
    }

    if (isset($_FILES['profile_image']) && ($_FILES['profile_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $uploadDir = __DIR__ . '/../../assets/uploads/profiles';
        $upload = secure_image_upload($_FILES['profile_image'], $uploadDir, 'assets/uploads/profiles');

        if (!$upload['success']) {
            mobileApiError($upload['message'] ?? 'Profile image upload failed.', 422, ['profile_image' => $upload['message'] ?? 'Profile image upload failed.'], 'upload_failed');
        }

        if (!empty($profileImagePath)) {
            delete_uploaded_file_safely((string) $profileImagePath, __DIR__ . '/../../assets/uploads/profiles');
        }
        $profileImagePath = $upload['path'];
    }

    $mobileApiDb->beginTransaction();

    $updateStmt = $mobileApiDb->prepare("
        UPDATE users
        SET name = ?, phone = ?, department = ?, student_staff_id = ?, profile_image = ?
        WHERE user_id = ?
    ");
    $updateStmt->execute([$name, $phone, $department, $studentStaffId, $profileImagePath, $userId]);

    if ($currentPassword !== '' && $newPassword !== '') {
        if (!password_verify($currentPassword, (string) $user['password'])) {
            $mobileApiDb->rollBack();
            mobileApiError('Current password is incorrect.', 422, ['current_password' => 'Current password is incorrect.'], 'invalid_current_password');
        }

        $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
        if ($newHash === false) {
            $mobileApiDb->rollBack();
            mobileApiError('Unable to secure your new password.', 500, null, 'password_hash_failed');
        }

        $passwordStmt = $mobileApiDb->prepare('UPDATE users SET password = ? WHERE user_id = ?');
        $passwordStmt->execute([$newHash, $userId]);
    }

    $mobileApiDb->commit();

    $stmt = $mobileApiDb->prepare('SELECT * FROM users WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $updatedUser = $stmt->fetch(PDO::FETCH_ASSOC) ?: $user;

    mobileApiSuccess([
        'user' => mobileApiUserPayload($updatedUser),
    ], 'Profile updated successfully.');
} catch (PDOException $exception) {
    if ($mobileApiDb->inTransaction()) {
        $mobileApiDb->rollBack();
    }
    error_log('Mobile profile error: ' . $exception->getMessage());
    mobileApiError('Unable to process profile request.', 500, null, 'profile_failed');
}
