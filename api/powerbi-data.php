<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../config/database.php';
require_once '../includes/auth.php';

$db = Database::getInstance()->getConnection();

// Get data type from request
$data_type = $_GET['type'] ?? 'overview';
$format = $_GET['format'] ?? 'json';

// Function to get data based on type
function getOverviewData($db) {
    $total_users = $db->query("SELECT COUNT(*) FROM users WHERE role != 'admin'")->fetchColumn();
    $total_items = $db->query("SELECT COUNT(*) FROM items")->fetchColumn();
    $total_claims = $db->query("SELECT COUNT(*) FROM claim_requests")->fetchColumn();
    $pending_claims = $db->query("SELECT COUNT(*) FROM claim_requests WHERE status = 'pending'")->fetchColumn();
    $approved_claims = $db->query("SELECT COUNT(*) FROM claim_requests WHERE status = 'approved'")->fetchColumn();
    $rejected_claims = $db->query("SELECT COUNT(*) FROM claim_requests WHERE status = 'rejected'")->fetchColumn();
    
    return [
        'total_users' => (int)$total_users,
        'total_items' => (int)$total_items,
        'total_claims' => (int)$total_claims,
        'pending_claims' => (int)$pending_claims,
        'approved_claims' => (int)$approved_claims,
        'rejected_claims' => (int)$rejected_claims,
        'timestamp' => date('Y-m-d H:i:s')
    ];
}

function getItemsByStatus($db) {
    $statuses = ['lost', 'found', 'returned', 'resolved'];
    $data = [];
    foreach ($statuses as $status) {
        $count = $db->query("SELECT COUNT(*) FROM items WHERE status = '$status'")->fetchColumn();
        $data[] = ['status' => $status, 'count' => (int)$count];
    }
    return $data;
}

function getItemsByCategory($db) {
    $stmt = $db->query("
        SELECT category, COUNT(*) as count 
        FROM items 
        WHERE category IS NOT NULL AND category != ''
        GROUP BY category 
        ORDER BY count DESC
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getClaimsByMonth($db) {
    $stmt = $db->prepare("
        SELECT 
            DATE_FORMAT(created_at, '%Y-%m') as month,
            COUNT(*) as total,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected
        FROM claim_requests
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ORDER BY month ASC
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getItemsByMonth($db) {
    $stmt = $db->prepare("
        SELECT 
            DATE_FORMAT(reported_date, '%Y-%m') as month,
            COUNT(*) as total,
            SUM(CASE WHEN status = 'lost' THEN 1 ELSE 0 END) as lost,
            SUM(CASE WHEN status = 'found' THEN 1 ELSE 0 END) as found
        FROM items
        WHERE reported_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(reported_date, '%Y-%m')
        ORDER BY month ASC
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getTopReporters($db, $limit = 10) {
    $stmt = $db->prepare("
        SELECT u.name, u.email, u.department, COUNT(i.item_id) as items_reported
        FROM users u
        JOIN items i ON u.user_id = i.reported_by
        WHERE u.role != 'admin'
        GROUP BY u.user_id
        ORDER BY items_reported DESC
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getUserRegistrations($db) {
    $stmt = $db->prepare("
        SELECT 
            DATE_FORMAT(created_at, '%Y-%m') as month,
            COUNT(*) as new_users,
            SUM(CASE WHEN role = 'student' THEN 1 ELSE 0 END) as students,
            SUM(CASE WHEN role = 'staff' THEN 1 ELSE 0 END) as staff
        FROM users
        WHERE role != 'admin'
        AND created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ORDER BY month ASC
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getAllItems($db) {
    $stmt = $db->prepare("
        SELECT 
            i.item_id,
            i.title,
            i.description,
            i.category,
            i.status,
            i.date_found,
            i.reported_date,
            u.name as reporter_name,
            u.email as reporter_email,
            u.department
        FROM items i
        JOIN users u ON i.reported_by = u.user_id
        ORDER BY i.reported_date DESC
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getAllClaims($db) {
    $stmt = $db->prepare("
        SELECT 
            c.claim_id,
            i.title as item_title,
            u.name as claimant_name,
            u.email as claimant_email,
            c.status,
            c.created_at,
            c.admin_notes
        FROM claim_requests c
        JOIN items i ON c.item_id = i.item_id
        JOIN users u ON c.claimant_id = u.user_id
        ORDER BY c.created_at DESC
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get data based on type
switch ($data_type) {
    case 'overview':
        $data = getOverviewData($db);
        break;
    case 'items_by_status':
        $data = getItemsByStatus($db);
        break;
    case 'items_by_category':
        $data = getItemsByCategory($db);
        break;
    case 'claims_by_month':
        $data = getClaimsByMonth($db);
        break;
    case 'items_by_month':
        $data = getItemsByMonth($db);
        break;
    case 'top_reporters':
        $data = getTopReporters($db);
        break;
    case 'user_registrations':
        $data = getUserRegistrations($db);
        break;
    case 'all_items':
        $data = getAllItems($db);
        break;
    case 'all_claims':
        $data = getAllClaims($db);
        break;
    case 'full_report':
        $data = [
            'overview' => getOverviewData($db),
            'items_by_status' => getItemsByStatus($db),
            'items_by_category' => getItemsByCategory($db),
            'claims_by_month' => getClaimsByMonth($db),
            'items_by_month' => getItemsByMonth($db),
            'top_reporters' => getTopReporters($db),
            'user_registrations' => getUserRegistrations($db),
            'timestamp' => date('Y-m-d H:i:s')
        ];
        break;
    default:
        $data = ['error' => 'Invalid data type'];
        http_response_code(400);
}

// Output data
if ($format === 'csv') {
    // Output as CSV
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="reclaim_report_' . $data_type . '_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    if (!empty($data) && is_array($data)) {
        // Add headers
        fputcsv($output, array_keys($data[0] ?? $data));
        
        // Add data rows
        if (isset($data[0])) {
            foreach ($data as $row) {
                fputcsv($output, (array)$row);
            }
        } else {
            fputcsv($output, (array)$data);
        }
    }
    fclose($output);
} else {
    // Output as JSON
    echo json_encode($data, JSON_PRETTY_PRINT);
}
?>