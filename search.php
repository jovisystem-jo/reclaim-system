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
$image_label_tokens = [];

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
    $analysis = $stmt->fetch();

    if ($analysis) {
        // Try to decode as JSON first (new format)
        $extractedData = json_decode($analysis['extracted_text'], true);
        
        if (is_array($extractedData) && isset($extractedData[0]['item_id'])) {
            // New format: extracted_text contains JSON with match details
            foreach ($extractedData as $match) {
                if (isset($match['item_id']) && $match['item_id'] > 0) {
                    $image_match_ids[] = $match['item_id'];
                }
            }
        } else {
            // Old format: try labels
            $labels = json_decode($analysis['labels'], true);
            $rawMatchedIds = $analysis['matched_item_ids'] ?? '';
            $decodedMatchedIds = json_decode((string) $rawMatchedIds, true);

            if (is_array($decodedMatchedIds)) {
                foreach ($decodedMatchedIds as $matchedId) {
                    $matchedId = (int) $matchedId;
                    if ($matchedId > 0 && !in_array($matchedId, $image_match_ids, true)) {
                        $image_match_ids[] = $matchedId;
                    }
                }
            } elseif (is_string($rawMatchedIds) && trim($rawMatchedIds) !== '') {
                foreach (preg_split('/[\s,]+/', trim($rawMatchedIds)) ?: [] as $matchedId) {
                    $matchedId = (int) $matchedId;
                    if ($matchedId > 0 && !in_array($matchedId, $image_match_ids, true)) {
                        $image_match_ids[] = $matchedId;
                    }
                }
            }

            if (is_array($labels) && !empty($labels)) {
                $meaningfulLabels = array_values(array_filter(array_map('normalizeImageSearchLabel', $labels), 'isMeaningfulImageLabel'));
                $search_query = implode(' ', $meaningfulLabels);
                $image_label_tokens = extractTextMatchTokens($search_query);
            }
        }

        if (!empty($image_match_ids)) {
            $placeholders = implode(',', array_fill(0, count($image_match_ids), '?'));
            $sql .= " AND item_id IN ($placeholders)";
            $params = array_merge($params, $image_match_ids);
        }
    }
}

// Regular text search - ONLY if no image search is active
$is_text_search = !empty($search_query) && $image_analysis_id == 0;
if ($is_text_search) {
    $search_query_tokens = extractTextMatchTokens($search_query);

    if (!empty($search_query_tokens)) {
        $tokenConditions = [];
        foreach ($search_query_tokens as $token) {
            $search_term = '%' . $token . '%';
            $tokenConditions[] = "(title LIKE ? OR description LIKE ? OR category LIKE ? OR brand LIKE ? OR color LIKE ? OR found_location LIKE ?)";
            $params[] = $search_term;
            $params[] = $search_term;
            $params[] = $search_term;
            $params[] = $search_term;
            $params[] = $search_term;
            $params[] = $search_term;
        }

        $sql .= " AND (" . implode(' OR ', $tokenConditions) . ")";
    } else {
        $sql .= " AND (title LIKE ? OR description LIKE ? OR category LIKE ? OR found_location LIKE ?)";
        $search_term = "%$search_query%";
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
    }
}

if (!empty($item_title)) {
    $sql .= " AND title LIKE ?";
    $params[] = "%$item_title%";
}

if (!empty($category)) {
    $sql .= " AND category = ?";
    $params[] = $category;
}

if (!empty($status)) {
    $sql .= " AND status = ?";
    $params[] = $status;
}

if (!empty($location)) {
    $sql .= " AND (found_location LIKE ? OR location LIKE ?)";
    $params[] = "%$location%";
    $params[] = "%$location%";
}

if (!empty($date_from)) {
    $sql .= " AND DATE(date_found) >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $sql .= " AND DATE(date_found) <= ?";
    $params[] = $date_to;
}

if (!empty($image_match_ids)) {
    $orderPlaceholders = implode(',', array_fill(0, count($image_match_ids), '?'));
    $sql .= " ORDER BY FIELD(item_id, $orderPlaceholders)";
    $params = array_merge($params, $image_match_ids);
} else {
    $sql .= " ORDER BY reported_date DESC";
}

$stmt = $db->prepare($sql);
$stmt->execute($params);
$search_results = $stmt->fetchAll();

// Load match scores from image analysis if available
$image_scores = [];
$highest_match = null;
$highest_category = null;
$highest_keywords = [];

if ($image_analysis_id > 0) {
    $stmt = $db->prepare("SELECT extracted_text FROM image_analysis WHERE analysis_id = ?");
    $stmt->execute([$image_analysis_id]);
    $analysis = $stmt->fetch();
    if ($analysis && !empty($analysis['extracted_text'])) {
        $scoresData = json_decode($analysis['extracted_text'], true);
        if (is_array($scoresData)) {
            foreach ($scoresData as $scoreItem) {
                if (isset($scoreItem['item_id'])) {
                    $image_scores[$scoreItem['item_id']] = $scoreItem;
                }
            }
        }
    }
    
    // Get the highest match (first item with highest score)
    if (!empty($image_scores)) {
        // Sort by final score to get highest
        uasort($image_scores, function($a, $b) {
            return $b['final_score'] <=> $a['final_score'];
        });
        
        $highest_match = reset($image_scores);
        if ($highest_match) {
            $highest_category = $highest_match['category'] ?? '';
            // Extract keywords from highest match title and description
            $highest_keywords = array_merge(
                explode(' ', strtolower($highest_match['title'] ?? '')),
                explode(' ', strtolower($highest_match['description'] ?? ''))
            );
            $highest_keywords = array_filter($highest_keywords, function($word) {
                return strlen($word) > 2;
            });
            $highest_keywords = array_unique($highest_keywords);
        }
    }
}

// Filter results to only show items matching the highest match's category or keywords
if ($image_analysis_id > 0 && $highest_match && !empty($search_results)) {
    $filtered_results = [];
    
    foreach ($search_results as $item) {
        $should_include = false;
        
        // Check if same category as highest match
        if (!empty($highest_category) && $item['category'] == $highest_category) {
            $should_include = true;
        }
        
        // Check if title contains keywords from highest match
        if (!$should_include && !empty($highest_keywords)) {
            $item_title_lower = strtolower($item['title'] ?? '');
            $item_desc_lower = strtolower($item['description'] ?? '');
            
            foreach ($highest_keywords as $keyword) {
                if (strpos($item_title_lower, $keyword) !== false || 
                    strpos($item_desc_lower, $keyword) !== false) {
                    $should_include = true;
                    break;
                }
            }
        }
        
        // Always include the highest match itself
        if ($item['item_id'] == $highest_match['item_id']) {
            $should_include = true;
        }
        
        if ($should_include) {
            $filtered_results[] = $item;
        }
    }
    
    $search_results = $filtered_results;
    $total_results = count($search_results);
    
    if (empty($search_results)) {
        $image_search_notice = 'No items found matching the category or keywords of the top match.';
    }
}

// Merge scores with search results
foreach ($search_results as &$item) {
    if (isset($image_scores[$item['item_id']])) {
        $item['visual_score'] = $image_scores[$item['item_id']]['visual_score'] ?? 0;
        $item['title_score'] = $image_scores[$item['item_id']]['title_score'] ?? 0;
        $item['description_score'] = $image_scores[$item['item_id']]['description_score'] ?? 0;
        $item['category_score'] = $image_scores[$item['item_id']]['category_score'] ?? 0;
        $item['similarity_percentage'] = $image_scores[$item['item_id']]['final_score'] ?? 0;
        $item['match_level'] = $image_scores[$item['item_id']]['match_level'] ?? getMatchLevel($item['similarity_percentage'] ?? 0);
        $item['match_reason'] = $image_scores[$item['item_id']]['match_reason'] ?? '';
        
        // Mark if this is the highest match
        if ($highest_match && $item['item_id'] == $highest_match['item_id']) {
            $item['is_highest_match'] = true;
        }
    }
}
unset($item);

// Re-sort by similarity score
if ($image_analysis_id > 0 && !empty($image_scores)) {
    usort($search_results, function($a, $b) {
        $scoreA = $a['similarity_percentage'] ?? 0;
        $scoreB = $b['similarity_percentage'] ?? 0;
        return $scoreB <=> $scoreA;
    });
}

// ============================================================================
// TEXT SEARCH MATCH SCORING & VISUAL INDICATORS
// ============================================================================
// Calculate match scores for text-based searches (keyword, category, location, etc.)
$is_any_text_filter = !empty($search_query) || !empty($item_title) || !empty($category) || 
                       !empty($location) || !empty($status) || !empty($date_from) || !empty($date_to);

if ($image_analysis_id == 0 && $is_any_text_filter && !empty($search_results)) {
    // Collect all active filter criteria for scoring
    $active_criteria = [];
    $filter_keywords = [];
    
    // Build search keywords from query and item_title
    $filter_keywords = array_merge(
        !empty($search_query) ? extractTextMatchTokens($search_query) : [],
        !empty($item_title) ? extractTextMatchTokens($item_title) : []
    );
    
    // Add category filter as keyword
    if (!empty($category)) {
        $active_criteria['category'] = strtolower(trim($category));
    }
    
    // Add location filter keywords
    if (!empty($location)) {
        $location_keywords = extractTextMatchTokens($location);
        $filter_keywords = array_merge($filter_keywords, $location_keywords);
        $active_criteria['location'] = strtolower(trim($location));
    }
    
    // Add status filter
    if (!empty($status)) {
        $active_criteria['status'] = strtolower(trim($status));
    }
    
    // Add date range criteria
    if (!empty($date_from) || !empty($date_to)) {
        $active_criteria['date_range'] = true;
    }
    
    // Remove duplicate keywords
    $filter_keywords = array_unique($filter_keywords);
    $filter_keywords = array_filter($filter_keywords, function($word) {
        return strlen($word) > 2;
    });
    
    // Calculate match score for each result
    foreach ($search_results as &$item) {
        $match_score = 0;
        $match_reasons = [];
        $match_details = [];
        
        // 1. Keyword match scoring (title + description + category)
        if (!empty($filter_keywords)) {
            $item_text = strtolower(
                ($item['title'] ?? '') . ' ' . 
                ($item['description'] ?? '') . ' ' . 
                ($item['category'] ?? '')
            );
            
            $matched_keywords = 0;
            foreach ($filter_keywords as $keyword) {
                if (strpos($item_text, $keyword) !== false) {
                    $matched_keywords++;
                    $match_reasons[] = "Contains keyword: $keyword";
                }
            }
            
            if ($matched_keywords > 0) {
                $keyword_score = min(100, ($matched_keywords / count($filter_keywords)) * 100);
                $match_score += $keyword_score * 0.5; // 50% weight for keywords
                $match_details['keyword_score'] = round($keyword_score);
                $match_details['matched_keywords'] = $matched_keywords;
                $match_details['total_keywords'] = count($filter_keywords);
            }
        }
        
        // 2. Title exact/partial match boost
        if (!empty($item_title) || !empty($search_query)) {
            $title_lower = strtolower($item['title'] ?? '');
            $search_terms = array_merge(
                !empty($item_title) ? [$item_title] : [],
                !empty($search_query) ? extractTextMatchTokens($search_query) : []
            );
            
            $title_boost = 0;
            foreach ($search_terms as $term) {
                $term_lower = strtolower($term);
                if (strpos($title_lower, $term_lower) !== false) {
                    $title_boost = max($title_boost, 30);
                    $match_reasons[] = "Title matches: $term";
                }
            }
            $match_score += $title_boost;
            $match_details['title_boost'] = $title_boost;
        }
        
        // 3. Category match (30 points)
        if (!empty($category) && strtolower($item['category'] ?? '') == strtolower($category)) {
            $match_score += 30;
            $match_reasons[] = "Category matches: $category";
            $match_details['category_match'] = 30;
        }
        
        // 4. Location match (20 points)
        if (!empty($location)) {
            $item_location = strtolower($item['found_location'] ?? $item['location'] ?? '');
            $search_location = strtolower($location);
            if (strpos($item_location, $search_location) !== false) {
                $match_score += 20;
                $match_reasons[] = "Location contains: $location";
                $match_details['location_match'] = 20;
            }
        }
        
        // 5. Status match (15 points)
        if (!empty($status) && strtolower($item['status'] ?? '') == strtolower($status)) {
            $match_score += 15;
            $match_reasons[] = "Status matches: $status";
            $match_details['status_match'] = 15;
        }
        
        // 6. Date range match (15 points)
        if (!empty($date_from) || !empty($date_to)) {
            $item_date = $item['date_found'] ?? $item['reported_date'] ?? '';
            if (!empty($item_date)) {
                $date_match = true;
                if (!empty($date_from) && $item_date < $date_from) $date_match = false;
                if (!empty($date_to) && $item_date > $date_to) $date_match = false;
                
                if ($date_match) {
                    $match_score += 15;
                    $match_reasons[] = "Within selected date range";
                    $match_details['date_match'] = 15;
                }
            }
        }
        
        // Normalize final score to 0-100
        $item['similarity_percentage'] = min(100, max(0, $match_score));
        $item['match_level'] = getMatchLevel($item['similarity_percentage']);
        $item['match_reason'] = !empty($match_reasons) ? implode('; ', array_slice($match_reasons, 0, 3)) : 'Matches your search criteria';
        $item['match_details'] = $match_details;
        
        // Mark if this is the best match (highest score)
        $item['_text_match_score'] = $item['similarity_percentage'];
    }
    unset($item);
    
    // Sort by match score (highest first), then by reported date
    usort($search_results, function($a, $b) {
        $scoreA = $a['similarity_percentage'] ?? 0;
        $scoreB = $b['similarity_percentage'] ?? 0;
        
        if ($scoreA === $scoreB) {
            $dateA = strtotime($a['reported_date'] ?? $a['date_found'] ?? '0');
            $dateB = strtotime($b['reported_date'] ?? $b['date_found'] ?? '0');
            return $dateB <=> $dateA;
        }
        
        return $scoreB <=> $scoreA;
    });
    
    // Mark the top result as "Best Match"
    if (!empty($search_results)) {
        $search_results[0]['is_best_text_match'] = true;
    }
}

// If using old text similarity calculation (keep for backward compatibility)
if (!empty($search_query_tokens) && $image_analysis_id == 0 && empty($filter_keywords)) {
    foreach ($search_results as &$item) {
        $item['_text_match_score'] = calculateJaccardSimilarity($search_query_tokens, extractItemTextMatchTokens($item));
    }
    unset($item);

    $search_results = array_values(array_filter($search_results, static function ($item) {
        return (float)($item['_text_match_score'] ?? 0.0) > 0.0;
    }));

    usort($search_results, static function ($left, $right) {
        $leftScore = (float)($left['_text_match_score'] ?? 0.0);
        $rightScore = (float)($right['_text_match_score'] ?? 0.0);

        if ($leftScore === $rightScore) {
            return strcmp((string)($right['reported_date'] ?? ''), (string)($left['reported_date'] ?? ''));
        }

        return $rightScore <=> $leftScore;
    });
    
    // Mark best match for Jaccard similarity
    if (!empty($search_results)) {
        $search_results[0]['is_best_text_match'] = true;
    }
}

if ($image_analysis_id > 0 && empty($image_match_ids) && !empty($image_label_tokens)) {
    foreach ($search_results as &$item) {
        $item['_image_label_match_score'] = calculateJaccardSimilarity($image_label_tokens, extractItemTextMatchTokens($item));
    }
    unset($item);

    $search_results = array_values(array_filter($search_results, static function ($item) {
        return (float)($item['_image_label_match_score'] ?? 0.0) > 0.0;
    }));

    usort($search_results, static function ($left, $right) {
        $leftScore = (float)($left['_image_label_match_score'] ?? 0.0);
        $rightScore = (float)($right['_image_label_match_score'] ?? 0.0);

        if ($leftScore === $rightScore) {
            return strcmp((string)($right['reported_date'] ?? ''), (string)($left['reported_date'] ?? ''));
        }

        return $rightScore <=> $leftScore;
    });
}

$total_results = count($search_results);

$has_filters = !empty($search_query) || !empty($category) || !empty($status) || !empty($location) ||
               !empty($date_from) || !empty($date_to) || !empty($item_title) || $image_analysis_id > 0;

// Determine if this is a text-based filter search (for UI messaging)
$is_text_filter_search = $image_analysis_id == 0 && $has_filters;

// Log search only if there's a search query and no image search
if (isset($_SESSION['userID']) && !empty($search_query) && $image_analysis_id == 0) {
    $log_stmt = $db->prepare("INSERT INTO search_history (userID, search_term, results_count) VALUES (?, ?, ?)");
    $log_stmt->execute([$_SESSION['userID'], $search_query, $total_results]);
}

$base_url = '/reclaim-system/';

// Get categories for filter dropdown
$cat_stmt = $db->query("SELECT DISTINCT category FROM items WHERE status IN ('lost', 'found') AND category IS NOT NULL AND category != '' ORDER BY category");
$categories = $cat_stmt->fetchAll();

// Get locations for filter dropdown
$loc_stmt = $db->query("SELECT DISTINCT found_location FROM items WHERE status IN ('lost', 'found') AND found_location IS NOT NULL AND found_location != '' ORDER BY found_location LIMIT 20");
$locations = $loc_stmt->fetchAll();

// Get image analysis data if available
$image_analysis_data = null;
if ($image_analysis_id > 0) {
    $stmt = $db->prepare("SELECT * FROM image_analysis WHERE analysis_id = ?");
    $stmt->execute([$image_analysis_id]);
    $image_analysis_data = $stmt->fetch();
}

// Helper function for match level
function getMatchLevel($score) {
    if ($score >= 70) return 'Very Likely Match';
    if ($score >= 45) return 'Possible Match';
    if ($score >= 25) return 'Low Match';
    return 'Potential Match';
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
<?php if ($image_analysis_data): ?>
<div class="image-search-banner">
    <div class="search-banner-head">
        <div class="search-banner-summary">
            <span class="search-banner-icon" aria-hidden="true">
                <i class="fas fa-brain"></i>
            </span>
            <div class="search-banner-copy">
                <div class="search-banner-title-row">
                    <strong class="search-banner-title">AI Image Search Results</strong>
                    <span class="ai-badge">AI-Powered</span>
                </div>
                <div class="detected-labels">
                    <span class="search-banner-meta-label">Detected:</span>
                    <?php
                    $labels = json_decode($image_analysis_data['labels'], true);
                    if (is_array($labels) && !empty($labels)):
                        foreach($labels as $label):
                    ?>
                        <span class="detected-label"><?= htmlspecialchars($label) ?></span>
                    <?php endforeach; else: ?>
                        <span class="detected-label">Visual similarity matches</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <a href="<?= $base_url ?>search.php" class="btn btn-sm btn-light search-banner-action" onclick="clearImageSearch()">
            <i class="fas fa-times"></i> Clear Image Search
        </a>
    </div>
    <?php 
    // Get top match category from analysis
    $analysisData = json_decode($image_analysis_data['labels'], true);
    $topCategory = is_array($analysisData) && isset($analysisData['top_category']) ? $analysisData['top_category'] : '';
    if ($topCategory): 
    ?>
    <div class="alert alert-info mt-2 mb-0">
        <i class="fas fa-info-circle me-2"></i>
        Showing items matching <strong><?= htmlspecialchars($topCategory) ?></strong> category (based on top match)
    </div>
    <?php endif; ?>
    <?php if ($image_search_notice !== ''): ?>
        <p class="small text-muted mt-2 mb-0"><?= htmlspecialchars($image_search_notice) ?></p>
    <?php endif; ?>
</div>
<?php endif; ?>

            <!-- Text Search Banner -->
            <?php if ($is_text_filter_search && !empty($search_results) && $image_analysis_id == 0): ?>
            <div class="image-search-banner image-search-banner--text">
                <div class="search-banner-head">
                    <div class="search-banner-summary">
                        <span class="search-banner-icon" aria-hidden="true">
                            <i class="fas fa-search"></i>
                        </span>
                        <div class="search-banner-copy">
                            <div class="search-banner-title-row">
                                <strong class="search-banner-title">Text Filter Search Results</strong>
                                <span class="text-match-badge"><i class="fas fa-filter"></i> Filtered Search</span>
                            </div>
                            <div class="detected-labels">
                                <span class="search-banner-meta-label">Active filters:</span>
                                <?php if(!empty($search_query)): ?>
                                    <span class="filter-tag"><i class="fas fa-keyboard"></i> Keyword: <?= htmlspecialchars(substr($search_query, 0, 30)) ?></span>
                                <?php endif; ?>
                                <?php if(!empty($item_title)): ?>
                                    <span class="filter-tag"><i class="fas fa-heading"></i> Title: <?= htmlspecialchars($item_title) ?></span>
                                <?php endif; ?>
                                <?php if(!empty($category)): ?>
                                    <span class="filter-tag"><i class="fas fa-tag"></i> Category: <?= htmlspecialchars($category) ?></span>
                                <?php endif; ?>
                                <?php if(!empty($status)): ?>
                                    <span class="filter-tag"><i class="fas fa-flag"></i> Status: <?= htmlspecialchars($status) ?></span>
                                <?php endif; ?>
                                <?php if(!empty($location)): ?>
                                    <span class="filter-tag"><i class="fas fa-map-marker-alt"></i> Location: <?= htmlspecialchars($location) ?></span>
                                <?php endif; ?>
                                <?php if(!empty($date_from) || !empty($date_to)): ?>
                                    <span class="filter-tag"><i class="fas fa-calendar"></i> Date: <?= $date_from ?: 'any' ?> to <?= $date_to ?: 'any' ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <a href="<?= $base_url ?>search.php" class="btn btn-sm btn-outline-secondary search-banner-action" onclick="clearAllFilters()">
                        <i class="fas fa-times"></i> Clear All Filters
                    </a>
                </div>
                <div class="alert alert-warning mt-2 mb-0" style="background: #fff8e7; border-color: #ffd699;">
                    <i class="fas fa-chart-line me-2" style="color: #FF8C00;"></i>
                    Results are ranked by match relevance. Items with higher match scores appear first.
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Filter Info Banner for Image Search -->
            <?php if ($image_analysis_id > 0 && $highest_match && !empty($search_results)): ?>
            <div class="filter-info-banner">
                <i class="fas fa-filter"></i>
                <strong>Smart Filtering Active:</strong> Showing only items that match the category "<strong><?= htmlspecialchars($highest_category) ?></strong>" or contain similar keywords to the top match "<strong><?= htmlspecialchars(substr($highest_match['title'] ?? '', 0, 40)) ?></strong>".
                <a href="<?= $base_url ?>search.php?image_analysis=<?= $image_analysis_id ?>" class="float-end text-decoration-none">Show All</a>
            </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-search"></i> Items List</h5>
                    <span class="badge bg-light text-dark"><?= $total_results ?> item(s) found</span>
                </div>
                <div class="card-body">
                    <!-- Active Filters Display -->
                    <?php if($has_filters): ?>
                        <?php
                            $active_filters = array();
                            if(!empty($search_query)) $active_filters[] = "Keyword: " . htmlspecialchars($search_query);
                            if(!empty($item_title)) $active_filters[] = "Title: " . htmlspecialchars($item_title);
                            if(!empty($category)) $active_filters[] = "Category: " . htmlspecialchars($category);
                            if(!empty($status)) $active_filters[] = "Status: " . ucfirst($status);
                            if(!empty($location)) $active_filters[] = "Location: " . htmlspecialchars($location);
                            if(!empty($date_from)) $active_filters[] = "From: " . htmlspecialchars($date_from);
                            if(!empty($date_to)) $active_filters[] = "To: " . htmlspecialchars($date_to);
                            if($image_analysis_id > 0) $active_filters[] = "AI Image Search";
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
                    <?php else: ?>
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
                            <?php $index = 0; foreach($search_results as $item): ?>
                            <div class="col-md-6 col-xl-4">
                                <div class="card item-card h-100 
                                    <?= isset($item['is_highest_match']) && $item['is_highest_match'] ? 'highest-match' : '' ?> 
                                    <?= isset($item['is_best_text_match']) && $item['is_best_text_match'] ? 'best-text-match' : '' ?>">
                                    <!-- Top Match Badge -->
                                    <?php if (isset($item['is_highest_match']) && $item['is_highest_match']): ?>
                                        <div class="top-match-badge">
                                            <i class="fas fa-crown me-1"></i> Best Match
                                        </div>
                                    <?php elseif (isset($item['is_best_text_match']) && $item['is_best_text_match']): ?>
                                        <div class="top-match-badge" style="background: linear-gradient(135deg, #FF8C00, #FF5722); color: white;">
                                            <i class="fas fa-trophy me-1"></i> Top Match
                                        </div>
                                    <?php elseif ($index == 0 && $image_analysis_id > 0 && isset($item['similarity_percentage']) && $item['similarity_percentage'] > 0): ?>
                                        <div class="top-match-badge">
                                            <i class="fas fa-trophy me-1"></i> Top Match
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php
                                    $hasImage = !empty($item['image_url']) && imageFileExists($item['image_url']);
                                    $imageUrl = $hasImage ? getImageUrl($item['image_url'], $base_url) : '';
                                    ?>
                                    <?php if($hasImage): ?>
                                        <img src="<?= $imageUrl ?>" class="card-img-top item-card-image" alt="Item image">
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
                                        
                                        <!-- Similarity Score Section - Show for BOTH image search AND text filter search -->
                                        <?php if ((($image_analysis_id > 0 || $is_text_filter_search) && isset($item['similarity_percentage']) && $item['similarity_percentage'] > 0) || 
                                                  (isset($item['_text_match_score']) && $item['_text_match_score'] > 0)): 
                                            $match_score = $item['similarity_percentage'] ?? ($item['_text_match_score'] * 100 ?? 0);
                                            $score_type = $image_analysis_id > 0 ? 'AI Similarity' : 'Relevance Score';
                                        ?>
                                        <div class="similarity-container">
                                            <div class="similarity-label">
                                                <span><i class="fas fa-chart-line"></i> <?= $score_type ?></span>
                                                <span class="similarity-percentage"><?= round($match_score) ?>%</span>
                                            </div>
                                            <div class="progress-bar-custom">
                                                <div class="progress-fill <?= $match_score >= 70 ? 'progress-high' : ($match_score >= 45 ? 'progress-medium' : ($match_score >= 25 ? 'progress-low' : 'progress-potential')) ?>" 
                                                     style="width: <?= $match_score ?>%"></div>
                                            </div>
                                            
                                            <!-- Score Breakdown for Text Search -->
                                            <?php if ($is_text_filter_search && isset($item['match_details']) && !empty($item['match_details'])): ?>
                                            <div class="score-breakdown">
                                                <?php if (isset($item['match_details']['keyword_score']) && $item['match_details']['keyword_score'] > 0): ?>
                                                    <span class="score-item keyword"><i class="fas fa-keyboard"></i> Keyword: <?= round($item['match_details']['keyword_score']) ?>%</span>
                                                <?php endif; ?>
                                                <?php if (isset($item['match_details']['title_boost']) && $item['match_details']['title_boost'] > 0): ?>
                                                    <span class="score-item title"><i class="fas fa-heading"></i> Title: +<?= $item['match_details']['title_boost'] ?></span>
                                                <?php endif; ?>
                                                <?php if (isset($item['match_details']['category_match']) && $item['match_details']['category_match'] > 0): ?>
                                                    <span class="score-item cat"><i class="fas fa-tag"></i> Category: +<?= $item['match_details']['category_match'] ?></span>
                                                <?php endif; ?>
                                                <?php if (isset($item['match_details']['location_match']) && $item['match_details']['location_match'] > 0): ?>
                                                    <span class="score-item location"><i class="fas fa-map-marker-alt"></i> Location: +<?= $item['match_details']['location_match'] ?></span>
                                                <?php endif; ?>
                                                <?php if (isset($item['match_details']['status_match']) && $item['match_details']['status_match'] > 0): ?>
                                                    <span class="score-item status"><i class="fas fa-flag"></i> Status: +<?= $item['match_details']['status_match'] ?></span>
                                                <?php endif; ?>
                                                <?php if (isset($item['match_details']['date_match']) && $item['match_details']['date_match'] > 0): ?>
                                                    <span class="score-item date"><i class="fas fa-calendar"></i> Date: +<?= $item['match_details']['date_match'] ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <!-- Score Breakdown for Image Search -->
                                            <?php if ($image_analysis_id > 0 && isset($item['visual_score']) && $item['visual_score'] > 0): ?>
                                            <div class="score-breakdown">
                                                <?php if (isset($item['visual_score']) && $item['visual_score'] > 0): ?>
                                                    <span class="score-item visual"><i class="fas fa-image"></i> Visual: <?= round($item['visual_score']) ?>%</span>
                                                <?php endif; ?>
                                                <?php if (isset($item['title_score']) && $item['title_score'] > 0): ?>
                                                    <span class="score-item title"><i class="fas fa-heading"></i> Title: <?= round($item['title_score']) ?>%</span>
                                                <?php endif; ?>
                                                <?php if (isset($item['description_score']) && $item['description_score'] > 0): ?>
                                                    <span class="score-item desc"><i class="fas fa-align-left"></i> Desc: <?= round($item['description_score']) ?>%</span>
                                                <?php endif; ?>
                                                <?php if (isset($item['category_score']) && $item['category_score'] > 0): ?>
                                                    <span class="score-item cat"><i class="fas fa-tag"></i> Category: <?= round($item['category_score']) ?>%</span>
                                                <?php endif; ?>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <!-- Match Reason -->
                                            <?php if (isset($item['match_reason']) && !empty($item['match_reason'])): ?>
                                                <div class="match-reason">
                                                    <i class="fas fa-info-circle me-1"></i> 
                                                    <?= htmlspecialchars($item['match_reason']) ?>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <div class="mt-2">
                                                <span class="match-badge <?= $match_score >= 70 ? 'match-high' : ($match_score >= 45 ? 'match-medium' : ($match_score >= 25 ? 'match-low' : 'match-potential')) ?>">
                                                    <?= isset($item['match_level']) ? $item['match_level'] : getMatchLevel($match_score) ?>
                                                </span>
                                                <?php if (isset($item['is_highest_match']) && $item['is_highest_match']): ?>
                                                    <span class="ai-badge"><i class="fas fa-star"></i> Best Match</span>
                                                <?php elseif (isset($item['is_best_text_match']) && $item['is_best_text_match']): ?>
                                                    <span class="text-match-badge"><i class="fas fa-star"></i> Top Match</span>
                                                <?php elseif ($index < 3 && ($image_analysis_id > 0 || $is_text_filter_search)): ?>
                                                    <span class="ai-badge"><i class="fas fa-star"></i> Recommended</span>
                                                <?php endif; ?>
                                                
                                                <!-- Show matched keywords count for text search -->
                                                <?php if ($is_text_filter_search && isset($item['match_details']['matched_keywords']) && $item['match_details']['matched_keywords'] > 0): ?>
                                                    <span class="matching-badge">
                                                        <i class="fas fa-check-circle"></i> <?= $item['match_details']['matched_keywords'] ?>/<?= $item['match_details']['total_keywords'] ?> keywords
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <!-- Show matching criteria note for related items in image search -->
                                        <?php if ($image_analysis_id > 0 && $highest_match && $item['item_id'] != $highest_match['item_id']): ?>
                                            <div class="match-reason mt-2" style="background: #e8f5e9;">
                                                <i class="fas fa-link me-1"></i>
                                                Related to: <strong><?= htmlspecialchars(substr($highest_match['title'] ?? '', 0, 30)) ?></strong>
                                                <?php if ($item['category'] == $highest_category): ?>
                                                    <span class="badge bg-info ms-1">Same Category</span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
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
                            <?php $index++; endforeach; ?>
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
