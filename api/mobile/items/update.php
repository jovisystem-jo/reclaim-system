<?php
require_once __DIR__ . '/../bootstrap.php';

mobileApiRequireMethod(['POST']);

$auth = mobileApiAuthenticate($mobileApiDb);
$userId = (int) $auth['user']['user_id'];
$input = mobileApiTrimmed(mobileApiRequestData());

$itemId = (int) ($input['item_id'] ?? 0);
$title = (string) ($input['title'] ?? '');
$category = (string) ($input['category'] ?? '');
$description = (string) ($input['description'] ?? '');
$location = (string) ($input['location'] ?? '');
$dateOccurred = (string) ($input['date_occurred'] ?? '');
$status = (string) ($input['status'] ?? '');
$brand = (string) ($input['brand'] ?? '');
$color = (string) ($input['color'] ?? '');
$deliveryLocation = (string) ($input['delivery_location'] ?? '');
$removeImage = (int) ($input['remove_image'] ?? 0) === 1;

$errors = [];

if ($itemId <= 0) {
    $errors['item_id'] = 'Item ID is required.';
}
if ($title === '') {
    $errors['title'] = 'Title is required.';
}
if ($category === '') {
    $errors['category'] = 'Category is required.';
}
if ($description === '') {
    $errors['description'] = 'Description is required.';
}
if ($location === '') {
    $errors['location'] = 'Location is required.';
}
if ($dateOccurred === '') {
    $errors['date_occurred'] = 'Date is required.';
}
if (!in_array($status, ['lost', 'found', 'returned', 'resolved'], true)) {
    $errors['status'] = 'Invalid status.';
}

if (!empty($errors)) {
    mobileApiError('Please correct the highlighted fields.', 422, $errors, 'validation_failed');
}

try {
    $stmt = $mobileApiDb->prepare('SELECT * FROM items WHERE item_id = ? AND reported_by = ? LIMIT 1');
    $stmt->execute([$itemId, $userId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        mobileApiError('You do not have permission to update this item.', 403, null, 'forbidden');
    }

    $imagePath = $item['image_url'] ?? '';

    if ($removeImage && !empty($imagePath)) {
        delete_uploaded_file_safely((string) $imagePath, __DIR__ . '/../../../assets/uploads');
        $imagePath = '';
    }

    if (isset($_FILES['image']) && ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $upload = secure_image_upload($_FILES['image'], __DIR__ . '/../../../assets/uploads', 'assets/uploads');
        if (!$upload['success']) {
            mobileApiError($upload['message'] ?? 'Image upload failed.', 422, ['image' => $upload['message'] ?? 'Image upload failed.'], 'upload_failed');
        }

        if (!empty($imagePath)) {
            delete_uploaded_file_safely((string) $imagePath, __DIR__ . '/../../../assets/uploads');
        }
        $imagePath = (string) ($upload['path'] ?? '');
    }

    $stmt = $mobileApiDb->prepare("
        UPDATE items
        SET title = ?, category = ?, description = ?, found_location = ?, date_found = ?, status = ?, brand = ?, color = ?, delivery_location = ?, image_url = ?
        WHERE item_id = ? AND reported_by = ?
    ");
    $stmt->execute([
        $title,
        $category,
        $description,
        $location,
        $dateOccurred,
        $status,
        $brand,
        $color,
        $deliveryLocation,
        $imagePath,
        $itemId,
        $userId,
    ]);

    $stmt = $mobileApiDb->prepare('SELECT * FROM items WHERE item_id = ? LIMIT 1');
    $stmt->execute([$itemId]);
    $updatedItem = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    mobileApiSuccess([
        'item' => mobileApiItemPayload($updatedItem),
    ], 'Item updated successfully.');
} catch (PDOException $exception) {
    error_log('Mobile update item error: ' . $exception->getMessage());
    mobileApiError('Unable to update item.', 500, null, 'update_item_failed');
}
