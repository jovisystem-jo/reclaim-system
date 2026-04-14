<?php
require_once 'config/database.php';
require_once 'includes/header.php';
require_once 'includes/functions.php';

$analysis_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($analysis_id == 0) {
    header('Location: search.php');
    exit();
}

$db = Database::getInstance()->getConnection();

// Get analysis data
$stmt = $db->prepare("SELECT * FROM image_analysis WHERE analysis_id = ?");
$stmt->execute([$analysis_id]);
$analysis = $stmt->fetch();

if (!$analysis) {
    header('Location: search.php');
    exit();
}

$labels = json_decode($analysis['labels'], true);
$image_url = $analysis['image_url'];

// Search for matching items
$searchTerms = [];
foreach ($labels as $label) {
    $searchTerms[] = "%$label%";
}

$sql = "SELECT * FROM items WHERE ";
$conditions = [];
$params = [];
foreach ($labels as $label) {
    $conditions[] = "(title LIKE ? OR description LIKE ? OR category LIKE ?)";
    $term = "%$label%";
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}
$sql .= implode(' OR ', $conditions);
$sql .= " ORDER BY reported_date DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$results = $stmt->fetchAll();

$base_url = '/reclaim-system/';
?>

<div class="container mt-4">
    <div class="card fade-in">
        <div class="card-header bg-primary text-white">
            <h5><i class="fas fa-search"></i> Image Search Results</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-4">
                    <div class="text-center">
                        <img src="<?= $image_url ?>" class="img-fluid rounded" style="max-height: 200px;">
                        <h6 class="mt-3">Detected Labels:</h6>
                        <div class="d-flex flex-wrap justify-content-center gap-2">
                            <?php foreach($labels as $label): ?>
                                <span class="badge bg-primary"><?= htmlspecialchars($label) ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="col-md-8">
                    <h5>Found <?= count($results) ?> matching item(s)</h5>
                    <?php if(empty($results)): ?>
                        <p class="text-muted">No items match the detected labels.</p>
                        <a href="search.php" class="btn btn-primary">Back to Search</a>
                    <?php else: ?>
                        <div class="row">
                            <?php foreach($results as $item): ?>
                            <div class="col-md-6 mb-3">
                                <div class="card h-100">
                                    <div class="row g-0">
                                        <div class="col-md-4">
                                            <?php 
                                            $hasImage = !empty($item['image_url']) && file_exists($item['image_url']);
                                            $imgUrl = $hasImage ? $base_url . $item['image_url'] : '';
                                            ?>
                                            <?php if($hasImage): ?>
                                                <img src="<?= $imgUrl ?>" class="img-fluid rounded-start" style="height: 100px; width: 100%; object-fit: cover;">
                                            <?php else: ?>
                                                <div class="bg-light d-flex align-items-center justify-content-center" style="height: 100px;">
                                                    <i class="fas fa-box-open fa-2x"></i>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="col-md-8">
                                            <div class="card-body p-2">
                                                <h6 class="card-title small"><?= htmlspecialchars($item['title'] ?? $item['description']) ?></h6>
                                                <span class="badge <?= $item['status'] == 'lost' ? 'bg-danger' : 'bg-success' ?>">
                                                    <?= ucfirst($item['status']) ?>
                                                </span>
                                                <a href="item-details.php?id=<?= $item['item_id'] ?>" class="btn btn-sm btn-primary mt-2 w-100">View Details</a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>