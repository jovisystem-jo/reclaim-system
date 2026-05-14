<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../../includes/notification.php';

mobileApiRequireMethod(['POST']);

$auth = mobileApiAuthenticate($mobileApiDb);
$userId = (int) $auth['user']['user_id'];
$input = mobileApiTrimmed(mobileApiRequestData());

$title = (string) ($input['title'] ?? '');
$category = (string) ($input['category'] ?? '');
$brand = (string) ($input['brand'] ?? '');
$brandOther = (string) ($input['brand_other'] ?? '');
$color = (string) ($input['color'] ?? '');
$colorOther = (string) ($input['color_other'] ?? '');
$description = (string) ($input['description'] ?? '');
$location = (string) ($input['location'] ?? '');
$dateOccurred = (string) ($input['date_occurred'] ?? '');
$timeOccurred = (string) ($input['time_occurred'] ?? '');
$deliveryOption = (string) ($input['delivery_option'] ?? '');
$deliveryLocationOther = (string) ($input['delivery_location_other'] ?? '');
$status = (string) ($input['status'] ?? 'lost');

if ($brand === 'Other' && $brandOther !== '') {
    $brand = $brandOther;
}

if ($color === 'Other' && $colorOther !== '') {
    $color = $colorOther;
}

$deliveryLocation = '';
if ($status === 'found') {
    if ($deliveryOption === 'Other (Please specify)' && $deliveryLocationOther !== '') {
        $deliveryLocation = $deliveryLocationOther;
    } elseif ($deliveryOption !== '' && $deliveryOption !== 'Other (Please specify)') {
        $deliveryLocation = $deliveryOption;
    }
}

$errors = [];

if (!in_array($status, ['lost', 'found'], true)) {
    $errors['status'] = 'Status must be lost or found.';
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

if (!empty($errors)) {
    mobileApiError('Please correct the highlighted fields.', 422, $errors, 'validation_failed');
}

$now = new DateTimeImmutable('now');
$submittedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $dateOccurred);
$dateErrors = DateTimeImmutable::getLastErrors();

if (!$submittedDate || !empty($dateErrors['warning_count']) || !empty($dateErrors['error_count'])) {
    mobileApiError('Please provide a valid date.', 422, ['date_occurred' => 'Please provide a valid date.'], 'validation_failed');
}

if ($submittedDate->format('Y-m-d') > $now->format('Y-m-d')) {
    mobileApiError('Date cannot be in the future.', 422, ['date_occurred' => 'Date cannot be in the future.'], 'validation_failed');
}

$dateTimeOccurred = $dateOccurred;
if ($timeOccurred !== '') {
    $submittedDateTime = DateTimeImmutable::createFromFormat('Y-m-d H:i', $dateOccurred . ' ' . $timeOccurred);
    $dateTimeErrors = DateTimeImmutable::getLastErrors();
    if (!$submittedDateTime || !empty($dateTimeErrors['warning_count']) || !empty($dateTimeErrors['error_count'])) {
        mobileApiError('Please provide a valid time.', 422, ['time_occurred' => 'Please provide a valid time.'], 'validation_failed');
    }

    if ($submittedDateTime > $now) {
        mobileApiError('Date and time cannot be in the future.', 422, ['time_occurred' => 'Date and time cannot be in the future.'], 'validation_failed');
    }

    $dateTimeOccurred = $dateOccurred . ' ' . $timeOccurred;
}

$imagePath = '';
if (isset($_FILES['image']) && ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    $upload = secure_image_upload($_FILES['image'], __DIR__ . '/../../../assets/uploads', 'assets/uploads');
    if (!$upload['success']) {
        mobileApiError($upload['message'] ?? 'Image upload failed.', 422, ['image' => $upload['message'] ?? 'Image upload failed.'], 'upload_failed');
    }
    $imagePath = (string) ($upload['path'] ?? '');
}

try {
    $mobileApiDb->beginTransaction();

    $stmt = $mobileApiDb->prepare("
        INSERT INTO items (
            title, description, category, brand, color, found_location, delivery_location,
            date_found, status, image_url, reported_by, user_id, reported_date
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $title,
        $description,
        $category,
        $brand,
        $color,
        $location,
        $deliveryLocation,
        $dateTimeOccurred,
        $status,
        $imagePath,
        $userId,
        $userId,
    ]);

    $itemId = (int) $mobileApiDb->lastInsertId();

    if ($status === 'lost') {
        $reportStmt = $mobileApiDb->prepare('INSERT INTO lost_reports (itemID, reporterID) VALUES (?, ?)');
        $reportStmt->execute([$itemId, $userId]);
    } else {
        $reportStmt = $mobileApiDb->prepare('INSERT INTO found_reports (itemID, reporterID, found_by) VALUES (?, ?, ?)');
        $reportStmt->execute([$itemId, $userId, (string) ($auth['user']['name'] ?? '')]);
    }

    $mobileApiDb->commit();

    $notification = new NotificationSystem();
    $notificationTitle = $status === 'lost' ? 'Lost Item Reported' : 'Found Item Reported';
    $notificationMessage = "You have successfully reported a {$status} item: '{$title}'.";
    if ($status === 'found' && $deliveryLocation !== '') {
        $notificationMessage .= "\n\nKeep at: {$deliveryLocation}";
    }
    $notification->send($userId, $notificationTitle, $notificationMessage, 'success');

    if ($status === 'lost') {
        $notification->notifySimilarFoundItemsForLostReport($itemId, $userId);
    }

    $stmt = $mobileApiDb->prepare('SELECT * FROM items WHERE item_id = ? LIMIT 1');
    $stmt->execute([$itemId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    mobileApiSuccess([
        'item' => mobileApiItemPayload($item),
    ], 'Item reported successfully.', 201);
} catch (PDOException $exception) {
    if ($mobileApiDb->inTransaction()) {
        $mobileApiDb->rollBack();
    }
    error_log('Mobile report item error: ' . $exception->getMessage());
    mobileApiError('Unable to report item right now.', 500, null, 'report_item_failed');
}
