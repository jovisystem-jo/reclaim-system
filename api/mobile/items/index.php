<?php
require_once __DIR__ . '/../bootstrap.php';

mobileApiRequireMethod(['GET']);

$auth = mobileApiAuthenticate($mobileApiDb);
$userId = (int) $auth['user']['user_id'];

$scope = ($_GET['scope'] ?? 'public') === 'mine' ? 'mine' : 'public';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(50, max(1, (int) ($_GET['per_page'] ?? 15)));
$offset = ($page - 1) * $perPage;
$query = trim((string) ($_GET['query'] ?? ''));
$category = trim((string) ($_GET['category'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));
$location = trim((string) ($_GET['location'] ?? ''));

$allowedStatuses = $scope === 'public'
    ? ['lost', 'found']
    : ['lost', 'found', 'returned', 'resolved'];

if ($status !== '' && !in_array($status, $allowedStatuses, true)) {
    mobileApiError('Invalid status filter.', 422, ['status' => 'Invalid status filter.'], 'validation_failed');
}

$fromSql = "
    FROM items i
    LEFT JOIN users reporter ON reporter.user_id = i.reported_by
    LEFT JOIN claim_requests claims ON claims.item_id = i.item_id
";
$where = [];
$params = [];

if ($scope === 'mine') {
    $where[] = 'i.reported_by = ?';
    $params[] = $userId;
} else {
    $where[] = "i.status IN ('lost', 'found')";
}

if ($query !== '') {
    $where[] = '(i.title LIKE ? OR i.description LIKE ? OR i.category LIKE ? OR i.brand LIKE ? OR i.color LIKE ? OR i.found_location LIKE ?)';
    for ($index = 0; $index < 6; $index++) {
        $params[] = '%' . $query . '%';
    }
}

if ($category !== '') {
    $where[] = 'i.category = ?';
    $params[] = $category;
}

if ($status !== '') {
    $where[] = 'i.status = ?';
    $params[] = $status;
}

if ($location !== '') {
    $where[] = '(i.found_location LIKE ? OR i.location LIKE ?)';
    $params[] = '%' . $location . '%';
    $params[] = '%' . $location . '%';
}

$whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

try {
    $countStmt = $mobileApiDb->prepare("SELECT COUNT(DISTINCT i.item_id) " . $fromSql . $whereSql);
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $sql = "
        SELECT
            i.*,
            reporter.name AS reporter_name,
            reporter.profile_image AS reporter_profile_image,
            COUNT(DISTINCT claims.claim_id) AS claim_count,
            MAX(CASE WHEN claims.claimant_id = ? THEN 1 ELSE 0 END) AS user_has_claimed
        " . $fromSql . $whereSql . "
        GROUP BY i.item_id
        ORDER BY i.reported_date DESC
        LIMIT ? OFFSET ?
    ";

    $stmt = $mobileApiDb->prepare($sql);
    $bindParams = array_merge([$userId], $params, [$perPage, $offset]);
    $stmt->execute($bindParams);
    $items = array_map('mobileApiItemPayload', $stmt->fetchAll(PDO::FETCH_ASSOC));

    $stats = null;
    if ($scope === 'mine') {
        $statsStmt = $mobileApiDb->prepare("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'lost' THEN 1 ELSE 0 END) AS lost_count,
                SUM(CASE WHEN status = 'found' THEN 1 ELSE 0 END) AS found_count,
                SUM(CASE WHEN status = 'returned' THEN 1 ELSE 0 END) AS returned_count
            FROM items
            WHERE reported_by = ?
        ");
        $statsStmt->execute([$userId]);
        $rawStats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $stats = [
            'total' => (int) ($rawStats['total'] ?? 0),
            'lost_count' => (int) ($rawStats['lost_count'] ?? 0),
            'found_count' => (int) ($rawStats['found_count'] ?? 0),
            'returned_count' => (int) ($rawStats['returned_count'] ?? 0),
        ];
    }

    mobileApiSuccess([
        'scope' => $scope,
        'items' => $items,
        'pagination' => mobileApiPagination($total, $page, $perPage),
        'stats' => $stats,
    ], 'Items loaded.');
} catch (PDOException $exception) {
    error_log('Mobile items index error: ' . $exception->getMessage());
    mobileApiError('Unable to load items.', 500, null, 'items_failed');
}
