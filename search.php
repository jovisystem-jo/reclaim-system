<?php
require_once 'config/database.php';
require_once 'includes/header.php';
require_once 'includes/functions.php';

$db = Database::getInstance()->getConnection();
$search_results = [];
$search_query = '';
$total_results = 0;
$visible_statuses = ['lost', 'found'];
$image_search_notice = '';
$image_match_ids = [];
$search_query_tokens = [];
$image_scores = [];
$highest_match = null;
$highest_match_category = '';
$image_analysis_data = null;
$is_image_search = false;
$direct_image_match_count = 0;
$related_category_item_count = 0;

const IMAGE_CATEGORY_EXPANSION_THRESHOLD = 55.0;
const MIN_IMAGE_RESULT_SCORE = 45.0;
const ENABLE_IMAGE_CATEGORY_SUGGESTIONS = false;

// Get filter values
$search_query = $_GET['query'] ?? '';
$category = $_GET['category'] ?? '';
$status = $_GET['status'] ?? '';
$location = $_GET['location'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$item_title = $_GET['item_title'] ?? '';
$image_analysis_id = isset($_GET['image_analysis']) ? (int)$_GET['image_analysis'] : 0;

// Server-side date validation - prevent future dates
$today = date('Y-m-d');
if (!empty($date_from) && $date_from > $today) {
    $date_from = '';
}
if (!empty($date_to) && $date_to > $today) {
    $date_to = '';
}
// Ensure date_from is not after date_to
if (!empty($date_from) && !empty($date_to) && $date_from > $date_to) {
    $temp = $date_from;
    $date_from = $date_to;
    $date_to = $temp;
}

if (!in_array($status, $visible_statuses, true)) {
    $status = '';
}

// Build the search query dynamically
$sql = "SELECT * FROM items WHERE status IN ('lost', 'found')";
$params = [];

// Handle image search results
if ($image_analysis_id > 0) {
    $stmt = $db->prepare("SELECT * FROM image_analysis WHERE analysis_id = ?");
    $stmt->execute([$image_analysis_id]);
    $image_analysis_data = $stmt->fetch();

    if ($image_analysis_data) {
        $is_image_search = true;
        $extractedData = json_decode($image_analysis_data['extracted_text'], true);
        if (is_array($extractedData) && !empty($extractedData)) {
            foreach ($extractedData as $match) {
                if (!is_array($match) || !isset($match['item_id'])) {
                    continue;
                }

                $itemId = (int) $match['item_id'];
                if ($itemId <= 0) {
                    continue;
                }

                if (!isConfidentStoredImageMatch($match)) {
                    continue;
                }

                $match['visual_score'] = (float) ($match['visual_score'] ?? $match['image_score'] ?? 0);
                $match['image_score'] = $match['visual_score'];
                $match['similarity_percentage'] = calculateStoredDisplaySimilarityScore($match);
                $match['match_level'] = getMatchLevel($match['similarity_percentage']);
                $image_scores[$itemId] = $match;
            }
        }

        if (!empty($image_scores)) {
            uasort($image_scores, static function ($left, $right) {
                $leftImageScore = (float) ($left['visual_score'] ?? $left['image_score'] ?? $left['similarity_percentage'] ?? 0);
                $rightImageScore = (float) ($right['visual_score'] ?? $right['image_score'] ?? $right['similarity_percentage'] ?? 0);

                if ($rightImageScore !== $leftImageScore) {
                    return $rightImageScore <=> $leftImageScore;
                }

                return ((float) ($right['final_score'] ?? 0)) <=> ((float) ($left['final_score'] ?? 0));
            });

            $image_match_ids = array_values(array_map('intval', array_keys($image_scores)));
            $direct_image_match_count = count($image_match_ids);
            $highest_match = reset($image_scores) ?: null;
            if ($highest_match && isset($highest_match['item_id'])) {
                if (trim((string) ($highest_match['color'] ?? '')) === '') {
                    $highestColorStmt = $db->prepare("SELECT color FROM items WHERE item_id = ? LIMIT 1");
                    $highestColorStmt->execute([(int) $highest_match['item_id']]);
                    $highestColorRow = $highestColorStmt->fetch();
                    if (is_array($highestColorRow)) {
                        $highest_match['color'] = (string) ($highestColorRow['color'] ?? '');
                    }
                }

                $highest_match_category = trim((string) ($highest_match['category'] ?? ''));
                $image_search_notice = $direct_image_match_count > 1
                    ? 'Showing AI matches ranked by similarity for the uploaded image.'
                    : 'Showing the best AI match for the uploaded image.';
            }
        }
        if (empty($image_match_ids) && $image_search_notice === '') {
            $image_search_notice = 'No confident AI match was found for this upload.';
        }
    }

    if (!empty($image_match_ids)) {
        $placeholders = implode(',', array_fill(0, count($image_match_ids), '?'));
        $sql .= " AND item_id IN ($placeholders)";
        $params = array_merge($params, $image_match_ids);
    } elseif ($image_analysis_data) {
        $sql .= " AND 1 = 0";
    }
}

$has_standard_filters = !empty($search_query) || !empty($category) || !empty($status) || !empty($location) ||
    !empty($date_from) || !empty($date_to) || !empty($item_title);

if ($is_image_search) {
    $search_query = '';
    $category = '';
    $status = '';
    $location = '';
    $date_from = '';
    $date_to = '';
    $item_title = '';
}

// Regular text search - ONLY if no image search is active
$is_text_search = !empty($search_query) && !$is_image_search;
if ($is_text_search) {
    $search_query_tokens = extractTextMatchTokens($search_query);

    if (!empty($search_query_tokens)) {
        $tokenConditions = [];
        foreach ($search_query_tokens as $token) {
            $search_term = '%' . $token . '%';
            $tokenConditions[] = "(title LIKE ? OR description LIKE ? OR category LIKE ? OR brand LIKE ? OR color LIKE ? OR found_location LIKE ? OR location LIKE ? OR delivery_location LIKE ? OR image_tags LIKE ?)";
            $params[] = $search_term;
            $params[] = $search_term;
            $params[] = $search_term;
            $params[] = $search_term;
            $params[] = $search_term;
            $params[] = $search_term;
            $params[] = $search_term;
            $params[] = $search_term;
            $params[] = $search_term;
        }

        $sql .= " AND (" . implode(' OR ', $tokenConditions) . ")";
    } else {
        $sql .= " AND (title LIKE ? OR description LIKE ? OR category LIKE ? OR brand LIKE ? OR color LIKE ? OR found_location LIKE ? OR location LIKE ? OR delivery_location LIKE ? OR image_tags LIKE ?)";
        $search_term = "%$search_query%";
        for ($i = 0; $i < 9; $i++) {
            $params[] = $search_term;
        }
    }
}

if (!$is_image_search && !empty($item_title)) {
    $sql .= " AND (title LIKE ? OR description LIKE ?)";
    $params[] = "%$item_title%";
    $params[] = "%$item_title%";
}

if (!$is_image_search && !empty($category)) {
    $sql .= " AND category = ?";
    $params[] = $category;
}

if (!$is_image_search && !empty($status)) {
    $sql .= " AND status = ?";
    $params[] = $status;
}

if (!$is_image_search && !empty($location)) {
    $sql .= " AND (found_location LIKE ? OR location LIKE ? OR delivery_location LIKE ?)";
    $params[] = "%$location%";
    $params[] = "%$location%";
    $params[] = "%$location%";
}

if (!$is_image_search && !empty($date_from)) {
    $sql .= " AND DATE(COALESCE(date_found, reported_date)) >= ?";
    $params[] = $date_from;
}

if (!$is_image_search && !empty($date_to)) {
    $sql .= " AND DATE(COALESCE(date_found, reported_date)) <= ?";
    $params[] = $date_to;
}

if (!empty($image_match_ids)) {
    $orderPlaceholders = implode(',', array_fill(0, count($image_match_ids), '?'));
    $sql .= " ORDER BY FIELD(item_id, $orderPlaceholders)";
    $params = array_merge($params, $image_match_ids);
} else {
    $sql .= " ORDER BY COALESCE(date_found, reported_date) DESC";
}

$stmt = $db->prepare($sql);
$stmt->execute($params);
$search_results = $stmt->fetchAll();

if (!empty($search_results)) {
    $deduped_results = [];
    foreach ($search_results as $item) {
        $deduped_results[(int) ($item['item_id'] ?? 0)] = $item;
    }
    $search_results = array_values($deduped_results);
}

if (
    ENABLE_IMAGE_CATEGORY_SUGGESTIONS
    &&
    $is_image_search
    && $highest_match
    && $highest_match_category !== ''
    && ((float) ($highest_match['similarity_percentage'] ?? $highest_match['image_score'] ?? 0)) > IMAGE_CATEGORY_EXPANSION_THRESHOLD
) {
    $relatedStmt = $db->prepare("
        SELECT *
        FROM items
        WHERE status IN ('lost', 'found')
          AND category = ?
          AND item_id != ?
        ORDER BY COALESCE(date_found, reported_date) DESC
        LIMIT 12
    ");
    $relatedStmt->execute([
        $highest_match_category,
        (int) ($highest_match['item_id'] ?? 0),
    ]);
    $relatedItems = $relatedStmt->fetchAll();

    foreach ($relatedItems as $relatedItem) {
        $relatedItemId = (int) ($relatedItem['item_id'] ?? 0);
        if ($relatedItemId <= 0) {
            continue;
        }

        $alreadyShown = false;
        foreach ($search_results as $existingItem) {
            if ((int) ($existingItem['item_id'] ?? 0) === $relatedItemId) {
                $alreadyShown = true;
                break;
            }
        }

        if ($alreadyShown) {
            continue;
        }

        if (!colorsAreCompatible(
            (string) ($highest_match['color'] ?? ''),
            (string) ($relatedItem['color'] ?? '')
        )) {
            continue;
        }

        $relatedItem['is_related_category_item'] = true;
        $relatedItem['similarity_percentage'] = calculateRelatedCategoryScore($highest_match, $relatedItem);
        $relatedItem['match_level'] = 'Same Category Suggestion';
        $relatedItem['match_source_label'] = 'Same Category';
        $relatedItem['match_reason'] = 'Shown because it shares the same category as the top AI match.';
        $search_results[] = $relatedItem;
        $related_category_item_count++;
    }

    if ($direct_image_match_count > 1 && $related_category_item_count > 0) {
        $image_search_notice = "Showing {$direct_image_match_count} AI matches with scored results and {$related_category_item_count} other item(s) from the same category.";
    } elseif ($direct_image_match_count > 1) {
        $image_search_notice = "Showing {$direct_image_match_count} AI matches ranked by similarity for the uploaded image.";
    } elseif ($related_category_item_count > 0) {
        $image_search_notice = "Showing the best AI match and {$related_category_item_count} other item(s) from the same category.";
    }
}

// Merge scores with search results
foreach ($search_results as &$item) {
    if (isset($image_scores[$item['item_id']])) {
        $item['visual_score'] = $image_scores[$item['item_id']]['visual_score']
            ?? $image_scores[$item['item_id']]['image_score']
            ?? 0;
        $item['image_score'] = $image_scores[$item['item_id']]['visual_score']
            ?? $image_scores[$item['item_id']]['image_score']
            ?? 0;
        $item['imagga_score'] = $image_scores[$item['item_id']]['imagga_score'] ?? 0;
        $item['jaccard_score'] = $image_scores[$item['item_id']]['jaccard_score'] ?? 0;
        $item['category_score'] = $image_scores[$item['item_id']]['category_score'] ?? 0;
        $item['orb_score'] = $image_scores[$item['item_id']]['orb_score'] ?? 0;
        $item['histogram_score'] = $image_scores[$item['item_id']]['histogram_score'] ?? 0;
        $item['shape_score'] = $image_scores[$item['item_id']]['shape_score'] ?? 0;
        $item['verified_matches'] = $image_scores[$item['item_id']]['verified_matches'] ?? 0;
        $item['matched_tags'] = $image_scores[$item['item_id']]['matched_tags'] ?? [];
        $item['similarity_percentage'] = $image_scores[$item['item_id']]['similarity_percentage']
            ?? $image_scores[$item['item_id']]['visual_score']
            ?? $image_scores[$item['item_id']]['image_score']
            ?? 0;
        $item['match_level'] = $image_scores[$item['item_id']]['match_level'] ?? getMatchLevel($item['similarity_percentage'] ?? 0);
        $item['match_source_label'] = 'AI Match';
        $item['match_reason'] = $image_scores[$item['item_id']]['match_reason'] ?? '';
        
        // Mark if this is the highest match
        if ($highest_match && $item['item_id'] == $highest_match['item_id']) {
            $item['is_highest_match'] = true;
        }
    }
}
unset($item);

// Re-sort by similarity score
if ($is_image_search && !empty($image_scores)) {
    usort($search_results, function($a, $b) {
        $aIsRelated = !empty($a['is_related_category_item']);
        $bIsRelated = !empty($b['is_related_category_item']);

        if ($aIsRelated !== $bIsRelated) {
            return $aIsRelated <=> $bIsRelated;
        }

        $scoreA = $a['similarity_percentage'] ?? 0;
        $scoreB = $b['similarity_percentage'] ?? 0;
        return $scoreB <=> $scoreA;
    });
}

// ============================================================================
// NORMAL FILTER / KEYWORD SEARCH
// ============================================================================
// Important: normal keyword/category/status/location/date filters should only
// display normal item details. Ranking badges, similarity percentages, and
// score breakdowns are reserved for Search by Image only.

if (!$is_image_search) {
    foreach ($search_results as &$normalItem) {
        unset(
            $normalItem['similarity_percentage'],
            $normalItem['match_level'],
            $normalItem['match_reason'],
            $normalItem['match_details'],
            $normalItem['_text_match_score'],
            $normalItem['is_best_text_match'],
            $normalItem['is_highest_match']
        );
    }
    unset($normalItem);
}

$total_results = count($search_results);

$has_filters = $has_standard_filters || $is_image_search;

// Determine if this is a text-based filter search (for UI messaging)
$is_text_filter_search = !$is_image_search && $has_standard_filters;

// Log search only if there's a search query and no image search
if (isset($_SESSION['userID']) && !empty($search_query) && !$is_image_search) {
    $log_stmt = $db->prepare("INSERT INTO search_history (userID, search_term, results_count) VALUES (?, ?, ?)");
    $log_stmt->execute([$_SESSION['userID'], $search_query, $total_results]);
}

$base_url = app_base_path();

// Get categories for filter dropdown
$cat_stmt = $db->query("SELECT DISTINCT category FROM items WHERE status IN ('lost', 'found') AND category IS NOT NULL AND category != '' ORDER BY category");
$categories = $cat_stmt->fetchAll();

// Get locations for filter dropdown
$loc_stmt = $db->query("SELECT DISTINCT found_location FROM items WHERE status IN ('lost', 'found') AND found_location IS NOT NULL AND found_location != '' ORDER BY found_location LIMIT 20");
$locations = $loc_stmt->fetchAll();

// Helper function for match level
function isConfidentStoredImageMatch(array $match) {
    if (array_key_exists('family_allowed', $match) && !$match['family_allowed']) {
        return false;
    }

    $finalScore    = (float) ($match['final_score'] ?? $match['similarity_percentage'] ?? 0);
    $imageScore    = (float) ($match['visual_score'] ?? $match['image_score'] ?? 0);
    $imaggaScore   = (float) ($match['imagga_score'] ?? 0);
    $jaccardScore  = (float) ($match['jaccard_score'] ?? 0);
    $categoryScore = (float) ($match['category_score'] ?? 0);
    $orbScore      = (float) ($match['orb_score'] ?? 0);
    $histogramScore = (float) ($match['histogram_score'] ?? 0);
    $shapeScore    = (float) ($match['shape_score'] ?? 0);
    $verifiedMatches = (int) ($match['verified_matches'] ?? 0);
    $matchedTags   = $match['matched_tags'] ?? [];
    $sameObjectFamily = !empty($match['family_allowed']);

    $hasVerifiedVisualEvidence    = $verifiedMatches >= 5 && $orbScore >= 15 && $imageScore >= 25;
    $hasStrongColorShapeAgreement = $imageScore >= 35 && $histogramScore >= 55 && $shapeScore >= 60;
    $strongVisual = (
        ($imageScore >= 70 && $verifiedMatches >= 5 && $orbScore >= 30)
        || ($imageScore >= 60 && $verifiedMatches >= 6 && $orbScore >= 25)
        || ($imageScore >= 55 && $verifiedMatches >= 5 && $orbScore >= 40)
        || ($imageScore >= 55 && $verifiedMatches >= 8 && $orbScore >= 25)
    );

    // Visual paths
    if ($strongVisual) return true;
    if ($hasVerifiedVisualEvidence || $hasStrongColorShapeAgreement) {
        return $finalScore >= 45;
    }

    // Semantic paths — work without OpenCV
    if ($imaggaScore >= 40 && ($jaccardScore >= 10 || $categoryScore >= 100 || count($matchedTags) >= 2)) {
        return true;
    }
    if ($categoryScore >= 100 && $jaccardScore >= 8) {
        return true;
    }
    if ($jaccardScore >= 20) {
        return true;
    }
    if ($sameObjectFamily && ($imaggaScore >= 25 || $jaccardScore >= 8 || !empty($matchedTags))) {
        return true;
    }
    if ($finalScore >= 45 && ($imaggaScore >= 25 || $jaccardScore >= 10)) {
        return true;
    }

    return false;
}

function getMatchLevel($score) {
    if ($score >= 85) return 'Highly Matched';
    if ($score >= 70) return 'Very Likely Match';
    if ($score >= 50) return 'Possible Match';
    if ($score >= 30) return 'Low Match';
    return 'Potential Match';
}

function calculateStoredDisplaySimilarityScore(array $match): float
{
    $imageScore    = (float) ($match['visual_score'] ?? $match['image_score'] ?? 0);
    $finalScore    = (float) ($match['final_score'] ?? 0);
    $imaggaScore   = (float) ($match['imagga_score'] ?? 0);
    $jaccardScore  = (float) ($match['jaccard_score'] ?? 0);
    $categoryScore = (float) ($match['category_score'] ?? 0);

    // When OpenCV is unavailable, derive display score from semantic signals
    if ($imageScore < 5.0) {
        $semanticScore = ($imaggaScore * 0.55) + ($jaccardScore * 0.30) + ($categoryScore * 0.15);
        return max(0.0, min(100.0, max($semanticScore, $finalScore)));
    }

    return max(0.0, min(100.0, max($imageScore, ($finalScore * 0.85) + ($imageScore * 0.15))));
}

function calculateRelatedCategoryScore(array $referenceMatch, array $candidateItem): float
{
    $score = 16.0;

    $referenceCategory = trim(strtolower((string) ($referenceMatch['category'] ?? '')));
    $candidateCategory = trim(strtolower((string) ($candidateItem['category'] ?? '')));
    if ($referenceCategory !== '' && $referenceCategory === $candidateCategory) {
        $score += 10.0;
    }

    if (colorsAreCompatible(
        (string) ($referenceMatch['color'] ?? ''),
        (string) ($candidateItem['color'] ?? '')
    )) {
        $score += 8.0;
    }

    $referenceTokens = extractTextMatchTokens(
        trim((string) (($referenceMatch['title'] ?? '') . ' ' . ($referenceMatch['description'] ?? '')))
    );
    $candidateTokens = extractTextMatchTokens(
        trim((string) (($candidateItem['title'] ?? '') . ' ' . ($candidateItem['description'] ?? '')))
    );

    if (!empty($referenceTokens) && !empty($candidateTokens)) {
        $sharedTokenCount = count(array_intersect($referenceTokens, $candidateTokens));
        $score += min(6.0, $sharedTokenCount * 2.0);
    }

    $referenceConfidence = (float) ($referenceMatch['final_score'] ?? $referenceMatch['similarity_percentage'] ?? 0);
    if ($referenceConfidence >= 70) {
        $score += 4.0;
    }

    return round(min(44.0, $score), 1);
}

function colorsAreCompatible(string $referenceColor, string $candidateColor): bool
{
    $referenceFamily = resolveColorFamily($referenceColor);
    if ($referenceFamily === '') {
        return true;
    }

    $candidateFamily = resolveColorFamily($candidateColor);
    if ($candidateFamily === '') {
        return false;
    }

    return $referenceFamily === $candidateFamily;
}

function resolveColorFamily(string $color): string
{
    $normalizedColor = normalizeColorPhrase($color);
    if ($normalizedColor === '') {
        return '';
    }

    foreach (getColorFamilyAliases() as $family => $aliases) {
        foreach ($aliases as $alias) {
            $normalizedAlias = normalizeColorPhrase($alias);
            if ($normalizedAlias !== '' && colorPhraseContains($normalizedColor, $normalizedAlias)) {
                return $family;
            }
        }
    }

    return '';
}

function getColorFamilyAliases(): array
{
    return [
        'black' => ['black', 'jet black', 'charcoal', 'dark black'],
        'white' => ['white', 'off white', 'ivory', 'cream'],
        'gray' => ['gray', 'grey', 'ash', 'slate', 'light gray', 'light grey'],
        'red' => ['red', 'maroon', 'burgundy', 'crimson'],
        'pink' => ['pink', 'rose', 'fuchsia', 'magenta'],
        'orange' => ['orange', 'coral', 'peach'],
        'yellow' => ['yellow', 'mustard'],
        'green' => ['green', 'olive', 'lime', 'mint', 'emerald'],
        'blue' => ['blue', 'navy', 'sky blue', 'light blue', 'royal blue', 'cyan', 'teal', 'turquoise'],
        'purple' => ['purple', 'violet', 'lavender', 'lilac'],
        'brown' => ['brown', 'tan', 'beige', 'khaki', 'camel'],
        'gold' => ['gold', 'golden'],
        'silver' => ['silver', 'metallic silver'],
    ];
}

function normalizeColorPhrase(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/i', ' ', $value) ?? '';
    $value = preg_replace('/\s+/', ' ', $value) ?? '';
    return trim($value);
}

function colorPhraseContains(string $normalizedText, string $normalizedAlias): bool
{
    if ($normalizedText === '' || $normalizedAlias === '') {
        return false;
    }

    if (strpos(' ' . $normalizedText . ' ', ' ' . $normalizedAlias . ' ') !== false) {
        return true;
    }

    $collapsedText = str_replace(' ', '', $normalizedText);
    $collapsedAlias = str_replace(' ', '', $normalizedAlias);

    return $collapsedAlias !== '' && strpos($collapsedText, $collapsedAlias) !== false;
}
?>

<!-- Additional styles specific to search page -->
<style>
    .item-card {
        transition: transform 0.2s;
        margin-bottom: 20px;
        position: relative;
        overflow: hidden;
    }

    .content-wrapper {
        margin-top: 20px;
    }

    .item-card:hover {
        transform: translateY(-5px);
    }

    .status-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        min-width: 60px;
        padding: 3px 8px;
        border-radius: 20px;
        white-space: nowrap;
        text-align: center;
        font-size: 0.75rem !important;
        font-weight: 500;
        line-height: 1.2;
        color: white;
    }
    
    .badge-lost { background-color: #dc3545; }
    .badge-found { background-color: #28a745; }
    .badge-returned { background-color: #17a2b8; }
    
    .item-card-image,
    .item-card-placeholder {
        width: 100%;
        height: 180px;
    }
    .item-card-image {
        object-fit: cover;
    }
    .item-card-placeholder {
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .item-card .card-text i {
        color: #FF8C00;
    }
    .action-buttons {
        display: flex;
        gap: 5px;
        justify-content: flex-end;
    }
    .action-buttons .btn-sm {
        padding: 4px 8px;
        font-size: 12px;
    }
    .search-layout {
        row-gap: 24px;
    }
    .search-filters .card-body {
        max-height: calc(100vh - 140px);
        overflow-y: auto;
    }
    .search-filters .form-label {
        font-size: 0.78rem;
        letter-spacing: 0.04em;
        text-transform: uppercase;
    }
    .image-search-banner {
        margin-bottom: 20px;
        padding: 18px 20px;
        border-radius: 18px;
        background: linear-gradient(135deg, #eef6ff, #f8fbff);
        border: 1px solid #d9e8ff;
        border-left: 4px solid #4f8ff7;
        box-shadow: 0 12px 24px rgba(15, 23, 42, 0.06);
    }
    .image-search-banner--text {
        background: linear-gradient(135deg, #fff8e7, #fff3e0);
        border-color: #ffd7ad;
        border-left-color: #FF8C00;
    }
    .search-banner-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 16px;
        flex-wrap: wrap;
    }
    .search-banner-summary {
        display: flex;
        align-items: flex-start;
        gap: 14px;
        flex: 1 1 420px;
        min-width: 0;
    }
    .search-banner-icon {
        width: 52px;
        height: 52px;
        border-radius: 16px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        background: rgba(79, 143, 247, 0.12);
        color: #3b82f6;
    }
    .image-search-banner--text .search-banner-icon {
        background: rgba(255, 140, 0, 0.12);
        color: #FF8C00;
    }
    .search-banner-icon i {
        font-size: 1.4rem;
        line-height: 1;
    }
    .search-banner-copy {
        display: flex;
        flex-direction: column;
        gap: 10px;
        flex: 1;
        min-width: 0;
    }
    .search-banner-title-row {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }
    .search-banner-title {
        margin: 0;
        font-size: 1.2rem;
        line-height: 1.3;
        color: #0f172a;
    }
    .search-banner-meta-label {
        font-size: 0.8rem;
        font-weight: 600;
        color: #64748b;
    }
    .search-banner-action {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        white-space: nowrap;
        border-radius: 999px;
        padding: 0.55rem 0.95rem;
        font-weight: 600;
    }
    .detected-labels {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 8px;
        margin-top: 0;
    }
    .detected-label {
        display: inline-flex;
        align-items: center;
        padding: 4px 12px;
        border-radius: 999px;
        background: rgba(79, 143, 247, 0.12);
        color: #1d4ed8;
        font-size: 0.78rem;
        font-weight: 600;
    }
    .search-filters .d-grid .btn {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        text-align: center;
    }
    .search-filters .d-grid .btn i {
        line-height: 1;
    }
    .search-filters .image-search-section {
        background: #f8f9fa;
        border-radius: 10px;
        padding: 15px;
        margin-bottom: 20px;
        border: 1px solid #e9ecef;
    }
    .search-filters .image-search-section hr {
        margin: 15px 0;
    }
    
    /* Date input styling */
    input[type="date"] {
        cursor: pointer;
    }
    input[type="date"]:invalid {
        border-color: #dc3545;
    }
    .date-note {
        font-size: 0.7rem;
        color: #6c757d;
        margin-top: 4px;
    }
    
    /* Similarity Score Styles */
    .similarity-container {
        margin-top: 10px;
        padding-top: 8px;
        border-top: 1px solid #e9ecef;
    }
    .similarity-label {
        font-size: 0.7rem;
        font-weight: 600;
        color: #6c757d;
        margin-bottom: 5px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .similarity-percentage {
        font-size: 0.85rem;
        font-weight: 700;
        color: #FF6B35;
    }
    .match-source-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        margin-top: 8px;
        margin-bottom: 4px;
        flex-wrap: wrap;
    }
    .match-source-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 10px;
        border-radius: 999px;
        font-size: 0.68rem;
        font-weight: 700;
        letter-spacing: 0.01em;
    }
    .match-source-badge.ai-match {
        background: rgba(29, 78, 216, 0.12);
        color: #1d4ed8;
    }
    .match-source-badge.related-match {
        background: rgba(255, 140, 0, 0.16);
        color: #c96a00;
    }
    .match-level-text {
        font-size: 0.72rem;
        font-weight: 600;
        color: #6c757d;
    }
    .progress-bar-custom {
        height: 6px;
        background: #e9ecef;
        border-radius: 3px;
        overflow: hidden;
        margin-top: 5px;
    }
    .progress-fill {
        height: 100%;
        border-radius: 3px;
        transition: width 0.5s ease;
    }
    .progress-high {
        background: linear-gradient(90deg, #28a745, #20c997);
    }
    .progress-medium {
        background: linear-gradient(90deg, #ffc107, #fd7e14);
    }
    .progress-low {
        background: linear-gradient(90deg, #17a2b8, #6f42c1);
    }
    .progress-potential {
        background: linear-gradient(90deg, #6c757d, #495057);
    }
    .match-badge {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 12px;
        font-size: 0.65rem;
        font-weight: 600;
        margin-left: 8px;
    }
    .match-high {
        background: #d4edda;
        color: #155724;
    }
    .match-medium {
        background: #fff3cd;
        color: #856404;
    }
    .match-low {
        background: #d1ecf1;
        color: #0c5460;
    }
    .match-potential {
        background: #e2e3e5;
        color: #383d41;
    }
    
    /* AI Badge */
    .ai-badge {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 2px 8px;
        border-radius: 12px;
        font-size: 0.6rem;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }
    
    /* Text Match Badge (for regular search) */
    .text-match-badge {
        background: linear-gradient(135deg, #FF8C00, #FF5722);
        color: white;
        padding: 2px 8px;
        border-radius: 12px;
        font-size: 0.6rem;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }
    
    /* Top Match Badge */
    .top-match-badge {
        position: absolute;
        top: 10px;
        right: 10px;
        background: linear-gradient(135deg, #FFD700, #FFA500);
        color: #333;
        padding: 2px 10px;
        border-radius: 20px;
        font-size: 0.7rem;
        font-weight: bold;
        z-index: 10;
        box-shadow: 0 2px 5px rgba(0,0,0,0.2);
    }
    
    /* Highest Match Indicator */
    .highest-match {
        border: 2px solid #FFD700;
        box-shadow: 0 0 10px rgba(255,215,0,0.3);
    }
    
    /* Best Text Match Indicator */
    .best-text-match {
        border: 2px solid #FF8C00;
        box-shadow: 0 0 10px rgba(255,140,0,0.3);
    }
    
    /* Score Breakdown */
    .score-breakdown {
        display: flex;
        gap: 6px;
        margin-top: 8px;
        font-size: 0.6rem;
        flex-wrap: wrap;
    }
    .score-item {
        background: #e9ecef;
        padding: 2px 6px;
        border-radius: 4px;
        display: inline-flex;
        align-items: center;
        gap: 3px;
    }
    .score-item.visual { background: #e3f2fd; color: #1565c0; }
    .score-item.title { background: #e8f5e9; color: #2e7d32; }
    .score-item.desc { background: #fff3e0; color: #e65100; }
    .score-item.cat { background: #f3e5f5; color: #6a1b9a; }
    .score-item.keyword { background: #e0f2fe; color: #0369a1; }
    .score-item.location { background: #fce7f3; color: #be185d; }
    .score-item.status { background: #fef3c7; color: #b45309; }
    .score-item.date { background: #d1fae5; color: #065f46; }
    
    /* Match Reason */
    .match-reason {
        font-size: 0.65rem;
        color: #6c757d;
        margin-top: 6px;
        padding: 4px 6px;
        background: #f8f9fa;
        border-radius: 6px;
    }
    
    /* Filter Info Banner */
    .filter-info-banner {
        background: #e3f2fd;
        border-left: 4px solid #2196f3;
        padding: 10px 15px;
        margin-bottom: 15px;
        border-radius: 8px;
        font-size: 0.8rem;
    }
    .filter-info-banner i {
        color: #2196f3;
        margin-right: 8px;
    }
    
    /* Highlighted search terms */
    .search-highlight {
        background-color: #FFEB3B;
        padding: 0 2px;
        border-radius: 3px;
        font-weight: 500;
        color: #333;
    }
    
    /* Filter tag styling */
    .filter-tag {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        background: #e9ecef;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 0.75rem;
    }
    .filter-tag i {
        font-size: 0.7rem;
        color: #6c757d;
    }
    @media (max-width: 767.98px) {
        .image-search-banner {
            padding: 16px;
        }
        .search-banner-summary {
            gap: 12px;
        }
        .search-banner-icon {
            width: 44px;
            height: 44px;
            border-radius: 14px;
        }
        .search-banner-icon i {
            font-size: 1.2rem;
        }
        .search-banner-title {
            font-size: 1.05rem;
        }
        .search-banner-action {
            width: 100%;
        }
    }
    
    /* Matching keyword indicator */
    .matching-badge {
        font-size: 0.6rem;
        background: #e8f5e9;
        color: #2e7d32;
        padding: 2px 6px;
        border-radius: 10px;
        margin-left: 5px;
    }
</style>

<main class="page-shell page-shell--compact">
<div class="container content-wrapper">
    <div class="row search-layout">
        <!-- Filters Sidebar -->
        <div class="col-lg-4 col-xl-3">
            <div class="card sticky-top search-filters" style="top: 20px; z-index: 100;">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="fas fa-filter"></i> Advanced Filters</h5>
                </div>
                <div class="card-body">
                    <!-- Image Search - Moved to TOP -->
                    <div class="image-search-section">
                        <div class="text-center">
                            <p class="mb-2 fw-bold"><i class="fas fa-camera"></i> Search by Image</p>
                            <p class="small text-muted">Upload a photo to find similar items</p>
                            <button onclick="document.getElementById('imageSearch').click()" class="btn btn-outline-primary w-100">
                                <i class="fas fa-upload"></i> Upload Image
                            </button>
                            <input type="file" id="imageSearch" accept="image/*" style="display: none;">
                        </div>
                    </div>
                    
                    <hr>
                    
                    <form method="GET" action="<?= $base_url ?>search.php" id="searchForm">
                        <!-- Main Search - Keyword Search -->
                        <div class="mb-3">
                            <label class="form-label fw-bold">Keyword Search</label>
                            <input type="text" name="query" class="form-control"
                                   placeholder="Search by title, description, category..."
                                   value="<?= htmlspecialchars($search_query) ?>">
                        </div>

                        

                        <!-- Category -->
                        <div class="mb-3">
                            <label class="form-label fw-bold">Category</label>
                            <select name="category" class="form-select">
                                <option value="">All Categories</option>
                                <option value="Electronics" <?= $category == 'Electronics' ? 'selected' : '' ?>>📱 Electronics</option>
                                <option value="Documents" <?= $category == 'Documents' ? 'selected' : '' ?>>📄 Documents</option>
                                <option value="Accessories" <?= $category == 'Accessories' ? 'selected' : '' ?>>⌚ Accessories</option>
                                <option value="Clothing" <?= $category == 'Clothing' ? 'selected' : '' ?>>👕 Clothing</option>
                                <option value="Books" <?= $category == 'Books' ? 'selected' : '' ?>>📚 Books</option>
                                <option value="Wallet" <?= $category == 'Wallet' ? 'selected' : '' ?>>👛 Wallet/Purse</option>
                                <option value="Keys" <?= $category == 'Keys' ? 'selected' : '' ?>>🔑 Keys</option>
                                <option value="Bag" <?= $category == 'Bag' ? 'selected' : '' ?>>🎒 Bag/Backpack</option>
                                <option value="Jewelry" <?= $category == 'Jewelry' ? 'selected' : '' ?>>💍 Jewelry</option>
                                <option value="Others" <?= $category == 'Others' ? 'selected' : '' ?>>📦 Others</option>
                            </select>
                        </div>

                        <!-- Status -->
                        <div class="mb-3">
                            <label class="form-label fw-bold">Status</label>
                            <select name="status" class="form-select">
                                <option value="">All (Lost & Found)</option>
                                <option value="lost" <?= $status == 'lost' ? 'selected' : '' ?>>❌ Lost</option>
                                <option value="found" <?= $status == 'found' ? 'selected' : '' ?>>✅ Found</option>
                            </select>
                        </div>

                        <!-- Location -->
                        <div class="mb-3">
                            <label class="form-label fw-bold">Location</label>
                            <input type="text" name="location" class="form-control"
                                   placeholder="e.g., Library, Cafeteria..."
                                   value="<?= htmlspecialchars($location) ?>">
                            <?php if(!empty($locations)): ?>
                                <small class="text-muted">Common:
                                    <?php foreach(array_slice($locations, 0, 5) as $loc): ?>
                                        <span class="badge bg-light text-dark me-1"><?= htmlspecialchars($loc['found_location']) ?></span>
                                    <?php endforeach; ?>
                                </small>
                            <?php endif; ?>
                        </div>

                        <hr>

                        <!-- Date Range with Future Date Prevention -->
                        <div class="mb-3">
                            <label class="form-label fw-bold">Date Range</label>
                            <div class="row">
                                <div class="col-6">
                                    <label class="small">From</label>
                                    <input type="date" name="date_from" class="form-control date-filter" 
                                           value="<?= htmlspecialchars($date_from) ?>"
                                           max="<?= date('Y-m-d') ?>">
                                </div>
                                <div class="col-6">
                                    <label class="small">To</label>
                                    <input type="date" name="date_to" class="form-control date-filter" 
                                           value="<?= htmlspecialchars($date_to) ?>"
                                           max="<?= date('Y-m-d') ?>">
                                </div>
                            </div>
                            <div class="date-note">
                                <i class="fas fa-calendar-alt me-1"></i> Cannot select future dates
                            </div>
                        </div>

                        <hr>

                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search"></i> Apply Filters
                            </button>
                            <a href="<?= $base_url ?>search.php" class="btn btn-secondary" onclick="clearAllFilters()">
                                <i class="fas fa-eraser"></i> Show All Items
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Search Results -->
        <div class="col-lg-8 col-xl-9">
            <!-- Image Search Banner -->
<?php if ($is_image_search || ($image_analysis_id > 0 && $image_analysis_data)): ?>
<div class="image-search-banner">
    <div class="search-banner-head">
        <div class="search-banner-summary">
            <span class="search-banner-icon" aria-hidden="true">
                <i class="fas fa-brain"></i>
            </span>
            <div class="search-banner-copy">
                <strong class="search-banner-title">AI Image Search</strong>
                <p class="mb-0 text-muted">
                    <?= htmlspecialchars($image_search_notice !== '' ? $image_search_notice : 'Showing the best AI match for the uploaded image.') ?>
                </p>
            </div>
        </div>
        <a href="<?= $base_url ?>search.php" class="btn btn-sm btn-light search-banner-action" onclick="clearImageSearch()">
            <i class="fas fa-times"></i> Clear Image Search
        </a>
    </div>
</div>
<?php endif; ?>

            <div class="card">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-search"></i> Items List</h5>
                    <span class="badge bg-light text-dark"><?= $total_results ?> item(s) found</span>
                </div>
                <div class="card-body">
                    <!-- Active Filters Display -->
                    <?php if($has_filters && !$is_image_search): ?>
                        <?php
                            $active_filters = array();
                            if(!empty($search_query)) $active_filters[] = "Keyword: " . htmlspecialchars($search_query);
                            if(!empty($item_title)) $active_filters[] = "Title: " . htmlspecialchars($item_title);
                            if(!empty($category)) $active_filters[] = "Category: " . htmlspecialchars($category);
                            if(!empty($status)) $active_filters[] = "Status: " . ucfirst($status);
                            if(!empty($location)) $active_filters[] = "Location: " . htmlspecialchars($location);
                            if(!empty($date_from)) $active_filters[] = "From: " . htmlspecialchars($date_from);
                            if(!empty($date_to)) $active_filters[] = "To: " . htmlspecialchars($date_to);
                        ?>
                        <?php if(!empty($active_filters)): ?>
                            <div class="mb-3">
                                <small class="text-muted">Active filters:</small>
                                <div class="mt-1">
                                    <?php foreach($active_filters as $filter): ?>
                                        <span class="badge bg-secondary me-1 mb-1"><?= $filter ?></span>
                                    <?php endforeach; ?>
                                    <a href="<?= $base_url ?>search.php" class="text-decoration-none ms-2">Clear all</a>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php elseif(!$is_image_search): ?>
                        <div class="alert alert-info mb-3">
                            <i class="fas fa-info-circle"></i> Showing all items. Use filters to narrow down your search.
                        </div>
                    <?php endif; ?>

                    <?php if(empty($search_results)): ?>
                        <div class="alert alert-warning text-center py-5">
                            <i class="fas fa-box-open fa-3x mb-3 d-block"></i>
                            <h5>No items found</h5>
                            <p>There are no items in the database matching your criteria.</p>
                            <?php if(!$has_filters): ?>
                                <p>Start by reporting a lost or found item.</p>
                                <a href="<?= $base_url ?>user/report-item.php?type=lost" class="btn btn-danger me-2">Report Lost Item</a>
                                <a href="<?= $base_url ?>user/report-item.php?type=found" class="btn btn-success">Report Found Item</a>
                            <?php else: ?>
                                <a href="<?= $base_url ?>search.php" class="btn btn-primary">Show All Items</a>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="row">
                            <?php foreach($search_results as $item): ?>
                            <div class="col-md-6 col-xl-4">
                                <div class="card item-card h-100 
                                    <?= $is_image_search && isset($item['is_highest_match']) && $item['is_highest_match'] ? 'highest-match' : '' ?> 
                                    ">
                                    <!-- Best Match Badge - Search by Image only -->
                                    <?php if ($is_image_search && isset($item['is_highest_match']) && $item['is_highest_match']): ?>
                                        <div class="top-match-badge">
                                            <i class="fas fa-crown me-1"></i> Best Match
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php
                                    $imageUrl = getImageUrl($item['image_url'] ?? '', $base_url);
                                    $hasImage = !empty($imageUrl);
                                    ?>
                                    <?php if($hasImage): ?>
                                        <div class="position-relative">
                                            <img
                                                src="<?= htmlspecialchars($imageUrl) ?>"
                                                class="card-img-top item-card-image"
                                                alt="Item image"
                                                loading="lazy"
                                                onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                                            >
                                            <div class="card-img-top bg-light item-card-placeholder" style="display: none;">
                                                <i class="fas fa-box-open fa-4x" style="color: #FF8C00;"></i>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div class="card-img-top bg-light item-card-placeholder">
                                            <i class="fas fa-box-open fa-4x" style="color: #FF8C00;"></i>
                                        </div>
                                    <?php endif; ?>
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <h6 class="card-title mb-0">
                                                <?php 
                                                $display_title = htmlspecialchars(substr($item['title'] ?? $item['description'], 0, 60));
                                                // Highlight search terms in title for text search
                                                if ($is_text_filter_search && !empty($search_query)) {
                                                    $keywords = array_filter(explode(' ', preg_quote($search_query, '/')), function($w) { return strlen($w) > 2; });
                                                    foreach ($keywords as $keyword) {
                                                        $pattern = '/(' . preg_quote($keyword, '/') . ')/i';
                                                        $display_title = preg_replace($pattern, '<span class="search-highlight">$1</span>', $display_title);
                                                    }
                                                }
                                                echo $display_title;
                                                ?>
                                            </h6>
                                            <span class="status-badge badge-<?= htmlspecialchars($item['status'] ?? 'found') ?>">
                                                <?= ucfirst($item['status'] ?? 'found') ?>
                                            </span>
                                        </div>
                                        
                                        <!-- Item Basic Info -->
                                        <p class="card-text small text-muted mb-2">
                                            <i class="fas fa-map-marker-alt"></i>
                                            <?php 
                                            $display_location = htmlspecialchars($item['found_location'] ?? $item['location'] ?? 'N/A');
                                            // Highlight location if matching filter
                                            if ($is_text_filter_search && !empty($location)) {
                                                $display_location = preg_replace('/(' . preg_quote($location, '/') . ')/i', '<span class="search-highlight">$1</span>', $display_location);
                                            }
                                            echo $display_location;
                                            ?><br>
                                            <i class="fas fa-tag"></i> 
                                            <?php 
                                            $display_category = htmlspecialchars($item['category'] ?? 'N/A');
                                            if ($is_text_filter_search && !empty($category) && strtolower($item['category'] ?? '') == strtolower($category)) {
                                                $display_category = '<span class="search-highlight">' . $display_category . '</span>';
                                            }
                                            echo $display_category;
                                            ?><br>
                                            <?php if(!empty($item['date_found'])): ?>
                                                <i class="fas fa-calendar"></i> <?= date('M d, Y', strtotime($item['date_found'])) ?>
                                            <?php elseif(!empty($item['reported_date'])): ?>
                                                <i class="fas fa-calendar"></i> <?= date('M d, Y', strtotime($item['reported_date'])) ?>
                                            <?php endif; ?>
                                        </p>
                                        
                                        <!-- AI Similarity - Search by Image only -->
                                        <?php if ($is_image_search && isset($item['similarity_percentage']) && $item['similarity_percentage'] > 0):
                                            $match_score = (float) $item['similarity_percentage'];
                                            $is_related_match = !empty($item['is_related_category_item']);
                                            $match_source_label = $item['match_source_label'] ?? ($is_related_match ? 'Same Category' : 'AI Match');
                                            $match_level_text = $item['match_level'] ?? getMatchLevel($match_score);
                                        ?>
                                        <div class="similarity-container">
                                            <div class="match-source-row">
                                                <span class="match-source-badge <?= $is_related_match ? 'related-match' : 'ai-match' ?>">
                                                    <i class="fas <?= $is_related_match ? 'fa-layer-group' : 'fa-brain' ?>"></i>
                                                    <?= htmlspecialchars($match_source_label) ?>
                                                </span>
                                                <span class="match-level-text"><?= htmlspecialchars($match_level_text) ?></span>
                                            </div>
                                            <div class="similarity-label">
                                                <span><i class="fas fa-chart-line"></i> <?= $is_related_match ? 'Match Score' : 'AI Similarity' ?></span>
                                                <span class="similarity-percentage"><?= round($match_score) ?>%</span>
                                            </div>
                                            <div class="progress-bar-custom">
                                                <div class="progress-fill <?= $match_score >= 70 ? 'progress-high' : ($match_score >= 45 ? 'progress-medium' : ($match_score >= 25 ? 'progress-low' : 'progress-potential')) ?>"
                                                     style="width: <?= min(100, max(0, $match_score)) ?>%"></div>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <!-- Show matching criteria note for related items in image search -->
                                    </div>

                                    <div class="card-footer bg-transparent">
                                        <div class="action-buttons">
                                            <a href="<?= $base_url ?>item-details.php?id=<?= $item['item_id'] ?>" class="btn btn-sm btn-outline-primary">
                                                <i class="fas fa-eye"></i> View Details
                                            </a>
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
</main>

<script>
// Handle image search upload
document.getElementById('imageSearch').addEventListener('change', function(e) {
    if(e.target.files.length > 0) {
        const formData = new FormData();
        formData.append('image', e.target.files[0]);
        formData.append('csrf_token', '<?= csrf_token() ?>');

        const btn = document.querySelector('button[onclick*="imageSearch"]');
        const originalText = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Analyzing...';
        btn.disabled = true;

        fetch('<?= $base_url ?>api/search-by-image.php', {
            method: 'POST',
            body: formData
        })
        .then(async response => {
            const text = await response.text();
            let data;

            try {
                data = JSON.parse(text);
            } catch (error) {
                throw new Error(text || 'Unexpected server response.');
            }

            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Please try again.');
            }

            return data;
        })
        .then(data => {
            // Clear any existing search query parameter and replace with new image analysis
            const url = new URL(window.location.href);
            url.searchParams.delete('query');
            url.searchParams.delete('item_title');
            url.searchParams.delete('category');
            url.searchParams.delete('status');
            url.searchParams.delete('location');
            url.searchParams.delete('date_from');
            url.searchParams.delete('date_to');
            url.searchParams.set('image_analysis', data.analysis_id);
            window.location.href = url.toString();
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Image search failed: ' + (error.message || 'Please try again.'));
        })
        .finally(function() {
            btn.innerHTML = originalText;
            btn.disabled = false;
            document.getElementById('imageSearch').value = '';
        });
    }
});

// Date validation - prevent future dates
document.addEventListener('DOMContentLoaded', function() {
    const today = new Date().toISOString().split('T')[0];
    
    // Get all date inputs
    const dateInputs = document.querySelectorAll('input[type="date"]');
    
    dateInputs.forEach(input => {
        // Set max attribute to today
        input.setAttribute('max', today);
        
        // Add validation on change
        input.addEventListener('change', function() {
            if (this.value > today) {
                alert('Cannot select future dates. Please select a date on or before ' + today);
                this.value = '';
            }
        });
    });
});

// Validate form submission
document.getElementById('searchForm')?.addEventListener('submit', function(e) {
    const today = new Date().toISOString().split('T')[0];
    const dateFrom = document.querySelector('input[name="date_from"]');
    const dateTo = document.querySelector('input[name="date_to"]');
    
    if (dateFrom && dateFrom.value && dateFrom.value > today) {
        e.preventDefault();
        alert('Cannot select future date in "From" field. Please select a valid date.');
        dateFrom.value = '';
        return false;
    }
    
    if (dateTo && dateTo.value && dateTo.value > today) {
        e.preventDefault();
        alert('Cannot select future date in "To" field. Please select a valid date.');
        dateTo.value = '';
        return false;
    }
    
    // Validate that "From" date is not after "To" date
    if (dateFrom && dateTo && dateFrom.value && dateTo.value) {
        if (dateFrom.value > dateTo.value) {
            e.preventDefault();
            alert('"From" date cannot be later than "To" date. Please adjust your date range.');
            return false;
        }
    }
});

// Clear image search function
function clearImageSearch() {
    const url = new URL(window.location.href);
    url.searchParams.delete('image_analysis');
    window.location.href = url.toString();
    return false;
}

// Clear all filters function
function clearAllFilters() {
    window.location.href = '<?= $base_url ?>search.php';
    return false;
}
</script>

<?php require_once 'includes/footer.php'; ?>
