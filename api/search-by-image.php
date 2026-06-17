<?php
/**
 * Search items by image using:
 * - OpenCV ORB + RANSAC + HSV histogram comparison
 * - One-time Imagga tag extraction for the uploaded image
 * - Imagga tag similarity against item text
 * - Jaccard similarity on uploaded tags vs item keywords
 * - Category validation
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/env.php';

EnvLoader::load();

header('Content-Type: application/json');

const MAX_FILE_SIZE = 5242880; // 5 MB
const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png'];
const IMAGGA_MIN_CONFIDENCE = 40.0;
const IMAGGA_TAG_CACHE_TTL = 2592000; // 30 days
const IMAGGA_CONNECT_TIMEOUT_SECONDS = 4;
const IMAGGA_TOTAL_TIMEOUT_SECONDS = 8;
const PYTHON_DISCOVERY_TIMEOUT_SECONDS = 2.0;
const PYTHON_DEPENDENCY_TIMEOUT_SECONDS = 2.0;
const PYTHON_COMPARE_TIMEOUT_SECONDS = 4.0;
const IMAGE_WEIGHT = 0.70;
const IMAGGA_WEIGHT = 0.15;
const JACCARD_WEIGHT = 0.10;
const CATEGORY_WEIGHT = 0.05;
const HIGH_CONFIDENCE_IMAGE_THRESHOLD = 75.0;
const HIGH_CONFIDENCE_IMAGGA_THRESHOLD = 70.0;
const HIGH_CONFIDENCE_BOOST = 10.0;
const MAX_RETURNED_MATCHES = 10;
const MIN_MATCH_SCORE = 45.0;
const MIN_DISPLAY_IMAGE_SCORE = 35.0;
const MIN_VISUAL_SCORE = 60.0;
const MIN_VISUAL_VERIFIED_MATCHES = 6;
const MIN_SEMANTIC_IMAGGA_SCORE = 60.0;
const MIN_SEMANTIC_JACCARD_SCORE = 12.0;
const MAX_SEARCHABLE_ITEMS = 60;
const MAX_PROCESSING_SECONDS = 20.0;

final class JsonResponseException extends RuntimeException
{
    public array $payload;
    public int $statusCode;

    public function __construct(array $payload, int $statusCode = 200)
    {
        parent::__construct('JSON response generated.');
        $this->payload = $payload;
        $this->statusCode = $statusCode;
    }
}

$requestStartedAt = microtime(true);
@set_time_limit(25);

$uploadDir = __DIR__ . '/../assets/uploads/temp/';
$uploadedRelativePath = null;
$uploadedImagePath = null;
$responsePayload = null;
$responseStatus = 200;
$responseSent = false;

register_shutdown_function(static function () use (&$responseSent, &$uploadedRelativePath, $uploadDir): void {
    if ($responseSent) {
        return;
    }

    $error = error_get_last();
    if (!is_array($error)) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
    if (!in_array($error['type'] ?? 0, $fatalTypes, true)) {
        return;
    }

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
    }

    $message = 'Unable to process the uploaded image right now. Please try again later.';
    if (stripos((string) ($error['message'] ?? ''), 'Maximum execution time') !== false) {
        $message = 'Image search timed out on the server. Please try a smaller image or try again in a moment.';
    }

    echo json_encode(
        ['success' => false, 'message' => $message],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if (!empty($uploadedRelativePath)) {
        deleteTemporaryUpload($uploadedRelativePath, $uploadDir);
    }

    $responseSent = true;
});

try {
    ensureDirectory($uploadDir);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respondJson(['success' => false, 'message' => 'Invalid request method.'], 405);
    }

    if (!isset($_FILES['image']) || ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        respondJson(['success' => false, 'message' => 'No image file provided.'], 400);
    }

    if (function_exists('require_csrf_token')) {
        require_csrf_token();
    }

    $extension = strtolower(pathinfo((string) ($_FILES['image']['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($extension, ALLOWED_EXTENSIONS, true)) {
        respondJson([
            'success' => false,
            'message' => 'Invalid file type. Allowed: ' . implode(', ', ALLOWED_EXTENSIONS),
        ], 422);
    }

    if ((int) ($_FILES['image']['size'] ?? 0) > MAX_FILE_SIZE) {
        respondJson(['success' => false, 'message' => 'File size exceeds 5MB limit.'], 422);
    }

    $upload = secure_image_upload($_FILES['image'], $uploadDir, 'assets/uploads/temp', MAX_FILE_SIZE);
    if (!($upload['success'] ?? false) || empty($upload['path'])) {
        respondJson([
            'success' => false,
            'message' => $upload['message'] ?? 'Failed to upload image.',
        ], 422);
    }

    $uploadedRelativePath = (string) $upload['path'];
    $uploadedImagePath = getFullImagePath($uploadedRelativePath);

    if ($uploadedImagePath === null || !is_file($uploadedImagePath)) {
        throw new RuntimeException('Uploaded image could not be resolved for processing.');
    }

    $db = Database::getInstance()->getConnection();
    $items = fetchSearchableItems($db);

    if (empty($items)) {
        respondJson([
            'success' => true,
            'analysis_id' => null,
            'uploaded_tags' => [],
            'matches' => [],
            'total_matches' => 0,
            'message' => 'No active lost or found items found in the database.',
        ]);
    }

    $warnings = [];
    $uploadedTags = getImaggaTags($uploadedImagePath);
    if (empty($uploadedTags)) {
        $uploadedTags = deriveUploadedTagsFromFilename((string) ($_FILES['image']['name'] ?? ''));
        if (!empty($uploadedTags)) {
            $warnings[] = 'Live tag extraction was unavailable, so the uploaded filename was used as a lightweight fallback.';
        }
    }
    $informativeUploadedTags = filterInformativeUploadedTags($uploadedTags);
    $uploadedObjectFamilies = detectObjectFamiliesFromTags($informativeUploadedTags);
    $pythonCommand = findPythonCommand();
    $opencvServiceUrl = trim((string) EnvLoader::get('OPENCV_SERVICE_URL', ''));

    if ($pythonCommand === null && $opencvServiceUrl === '') {
        $warnings[] = canRunManagedSubprocesses()
            ? 'Python/OpenCV comparison is unavailable; image similarity scores were set to 0.'
            : 'Python/OpenCV comparison is disabled on this hosting environment because PHP cannot manage subprocess timeouts safely.';
    }

    $results = [];
    $comparableItemCount = 0;
    $processingBudgetExceeded = false;

    foreach ($items as $item) {
        if (hasExceededProcessingBudget($requestStartedAt)) {
            $processingBudgetExceeded = true;
            break;
        }

        $itemImagePath = getFullImagePath((string) ($item['image_url'] ?? ''));
        if ($itemImagePath === null || !is_file($itemImagePath)) {
            continue;
        }

        $comparableItemCount++;

        $itemText = buildItemSearchText($item);
        $itemKeywords = generateItemKeywords($item);
        $itemObjectFamilies = detectObjectFamiliesFromItem($item);
        $objectFamilyDetected = !empty($uploadedObjectFamilies);
        $sameObjectFamily = $objectFamilyDetected && hasCompatibleObjectFamily($uploadedObjectFamilies, $itemObjectFamilies);

        // Strict object-family gate:
        // If the uploaded image clearly detects a specific object type, never allow a different item type
        // to pass just because OpenCV gives a moderate visual score or because of generic tags.
        if (!empty($uploadedObjectFamilies) && !$sameObjectFamily) {
            continue;
        }

        $matchedTags = getMatchedTags($informativeUploadedTags, $itemKeywords, $itemText);

        $imageMetrics = createEmptyImageMetrics();

        if (
            ($pythonCommand !== null || $opencvServiceUrl !== '')
            && !hasExceededProcessingBudget($requestStartedAt, 3.0)
        ) {
            $imageMetrics = calculateVisualSimilarity($uploadedImagePath, $itemImagePath, $pythonCommand ?? []);
        }

        $imageScore = round(clampPercent($imageMetrics['similarity'] ?? 0), 2);
        $imaggaScore = round(calculateImaggaSimilarity($informativeUploadedTags, $itemKeywords, $itemText), 2);
        $jaccardScore = round(calculateJaccardSimilarity($informativeUploadedTags, $itemKeywords), 2);
        $categoryScore = round(calculateCategoryScore($informativeUploadedTags, (string) ($item['category'] ?? '')), 2);
        $objectFamilyScore = $sameObjectFamily ? 100.0 : 0.0;
        $finalScore = round(calculateFinalScore($imageScore, $imaggaScore, $jaccardScore, $categoryScore, $imageMetrics), 2);
        if ($objectFamilyDetected && $sameObjectFamily) {
            $finalScore = max(
                $finalScore,
                calculateObjectFamilyConfidenceFloor($imageScore, $imaggaScore, $jaccardScore, $categoryScore, $matchedTags)
            );
        }
        $displayScore = round(calculateDisplayedSimilarityScore($imageScore, $finalScore, $imaggaScore, $jaccardScore, $categoryScore), 2);

        $results[] = [
            'item_id' => (int) $item['item_id'],
            'title' => (string) ($item['title'] ?? ''),
            'description' => (string) ($item['description'] ?? ''),
            'category' => (string) ($item['category'] ?? ''),
            'color' => (string) ($item['color'] ?? ''),
            'status' => (string) ($item['status'] ?? ''),
            'image_url' => (string) ($item['image_url'] ?? ''),
            // Keep image_score as the raw visual similarity so later filtering
            // can rely on the actual ORB/color/shape output.
            'image_score' => $imageScore,
            'imagga_score' => $imaggaScore,
            'jaccard_score' => $jaccardScore,
            'category_score' => $categoryScore,
            'object_family_score' => $objectFamilyScore,
            'uploaded_object_families' => array_values($uploadedObjectFamilies),
            'item_object_families' => array_values($itemObjectFamilies),
            'family_allowed' => $objectFamilyDetected ? $sameObjectFamily : true,
            'final_score' => $finalScore,
            'match_level' => getMatchLevel($displayScore),
            'orb_score' => round(clampPercent($imageMetrics['orb_score'] ?? 0), 2),
            'histogram_score' => round(clampPercent($imageMetrics['histogram_score'] ?? 0), 2),
            'shape_score' => round(clampPercent($imageMetrics['shape_score'] ?? 0), 2),
            'verified_matches' => max(0, (int) ($imageMetrics['verified_matches'] ?? 0)),
            'keypoints_image1' => max(0, (int) ($imageMetrics['keypoints_image1'] ?? 0)),
            'keypoints_image2' => max(0, (int) ($imageMetrics['keypoints_image2'] ?? 0)),
            'matched_tags' => $matchedTags,
            'match_reason' => buildMatchReason($imageScore, $imaggaScore, $jaccardScore, $categoryScore, $matchedTags, $imageMetrics),
            // Compatibility fields used by the existing search UI.
            'visual_score' => $imageScore,
            'similarity_percentage' => $displayScore,
        ];
    }

    if ($comparableItemCount === 0) {
        respondJson([
            'success' => true,
            'analysis_id' => null,
            'uploaded_tags' => $uploadedTags,
            'matches' => [],
            'total_matches' => 0,
            'message' => 'No active lost or found items with stored images were found in the database.',
        ]);
    }

    if ($processingBudgetExceeded) {
        $warnings[] = 'Image search reached the shared-hosting time budget, so only the items checked so far were compared.';
    }

    sortImageSearchResults($results);

    if (empty($uploadedObjectFamilies)) {
        $inferredObjectFamilies = inferUploadedObjectFamiliesFromResults($results);
        if (!empty($inferredObjectFamilies)) {
            $uploadedObjectFamilies = $inferredObjectFamilies;
            $results = applyInferredObjectFamiliesToResults($results, $uploadedObjectFamilies);
            sortImageSearchResults($results);
        }
    }

    $relevantResults = array_values(array_filter($results, 'isRelevantMatch'));
    if (count($relevantResults) > MAX_RETURNED_MATCHES) {
        $relevantResults = array_slice($relevantResults, 0, MAX_RETURNED_MATCHES);
    }

    $analysisId = saveImageAnalysisRecord($db, $uploadedRelativePath, $uploadedTags, $relevantResults);
    $topMatch = $relevantResults[0] ?? null;

    $response = [
        'success' => true,
        'analysis_id' => $analysisId,
        'uploaded_tags' => $uploadedTags,
        'uploaded_object_families' => array_values($uploadedObjectFamilies),
        'matches' => $relevantResults,
        'total_matches' => count($relevantResults),
        'top_match_category' => $topMatch['category'] ?? null,
        'top_match_score' => isset($topMatch['similarity_percentage']) ? round((float) $topMatch['similarity_percentage'], 2) : 0.0,
        'message' => !empty($relevantResults)
            ? (count($relevantResults) > 1
                ? 'Found confident matches for the uploaded image.'
                : 'Found the best confident match for the uploaded image.')
            : 'No confident match was found for the uploaded image.',
    ];

    if (!empty($warnings)) {
        $response['warnings'] = $warnings;
    }

    respondJson($response);
} catch (JsonResponseException $responseException) {
    $responsePayload = $responseException->payload;
    $responseStatus = $responseException->statusCode;
} catch (Throwable $exception) {
    error_log('Image search error: ' . $exception->getMessage());
    $responsePayload = [
        'success' => false,
        'message' => 'Unable to process the uploaded image right now. Please try again later.',
    ];
    $responseStatus = 500;
} finally {
    if (!empty($uploadedRelativePath)) {
        deleteTemporaryUpload($uploadedRelativePath, $uploadDir);
    }
}

if ($responsePayload === null) {
    $responsePayload = [
        'success' => false,
        'message' => 'No response was generated.',
    ];
    $responseStatus = 500;
}

http_response_code($responseStatus);
echo safeJsonEncode($responsePayload);
$responseSent = true;

function fetchSearchableItems(PDO $db): array
{
    $statement = $db->prepare("
        SELECT item_id, title, description, category, brand, color, image_tags, image_url, status, reported_date, date_found
        FROM items
        WHERE status IN ('lost', 'found')
          AND image_url IS NOT NULL
          AND image_url <> ''
        ORDER BY COALESCE(date_found, reported_date) DESC, item_id DESC
        LIMIT " . MAX_SEARCHABLE_ITEMS . "
    ");
    $statement->execute();

    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function getImaggaTags($imagePath): array
{
    static $requestCache = [];

    if (!is_string($imagePath) || $imagePath === '' || !is_file($imagePath)) {
        return [];
    }

    $imageHash = hash_file('sha256', $imagePath);
    if ($imageHash === false) {
        return [];
    }

    if (isset($requestCache[$imageHash])) {
        return $requestCache[$imageHash];
    }

    $cacheFile = getImaggaCacheFilePath($imageHash);
    $cachedTags = readCachedImaggaTags($cacheFile);
    if ($cachedTags !== null) {
        $requestCache[$imageHash] = $cachedTags;
        return $cachedTags;
    }

    $apiKey = trim((string) EnvLoader::get('IMAGGA_API_KEY', ''));
    $apiSecret = trim((string) EnvLoader::get('IMAGGA_API_SECRET', ''));

    if ($apiKey === '' || $apiSecret === '') {
        error_log('Imagga credentials are not configured.');
        $requestCache[$imageHash] = [];
        return [];
    }

    if (!function_exists('curl_init') || !function_exists('curl_file_create')) {
        error_log('cURL extension is required for Imagga tag extraction.');
        $requestCache[$imageHash] = [];
        return [];
    }

    $mimeType = detectMimeType($imagePath);
    $multipartFile = curl_file_create($imagePath, $mimeType, basename($imagePath));

    $curlHandle = curl_init('https://api.imagga.com/v2/tags');
    curl_setopt_array($curlHandle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => ['image' => $multipartFile],
        CURLOPT_USERPWD => $apiKey . ':' . $apiSecret,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_CONNECTTIMEOUT => IMAGGA_CONNECT_TIMEOUT_SECONDS,
        CURLOPT_TIMEOUT => IMAGGA_TOTAL_TIMEOUT_SECONDS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_NOSIGNAL => true,
    ]);

    $rawResponse = curl_exec($curlHandle);
    $httpCode = (int) curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curlHandle);
    curl_close($curlHandle);

    if ($rawResponse === false) {
        error_log('Imagga request failed: ' . ($curlError !== '' ? $curlError : 'Unknown cURL error.'));
        $requestCache[$imageHash] = [];
        return [];
    }

    $payload = json_decode($rawResponse, true);
    if (!is_array($payload)) {
        error_log('Invalid Imagga JSON response.');
        $requestCache[$imageHash] = [];
        return [];
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        $statusText = $payload['status']['text'] ?? $payload['result']['status']['text'] ?? 'Unexpected Imagga error.';
        error_log('Imagga HTTP error: ' . $httpCode . ' - ' . $statusText);
        $requestCache[$imageHash] = [];
        return [];
    }

    $tags = [];
    foreach (($payload['result']['tags'] ?? []) as $entry) {
        $tagName = normalizePhrase((string) ($entry['tag']['en'] ?? $entry['tag'] ?? ''));
        $confidence = (float) ($entry['confidence'] ?? 0);

        if ($tagName === '' || $confidence <= IMAGGA_MIN_CONFIDENCE) {
            continue;
        }

        if (!isset($tags[$tagName]) || $confidence > $tags[$tagName]) {
            $tags[$tagName] = $confidence;
        }
    }

    arsort($tags, SORT_NUMERIC);
    $normalizedTags = array_keys($tags);

    writeCachedImaggaTags($cacheFile, $normalizedTags);
    $requestCache[$imageHash] = $normalizedTags;

    return $normalizedTags;
}


function getObjectFamilyAliases(): array
{
    return [
        'phone' => ['phone', 'mobile phone', 'smartphone', 'cell phone', 'iphone', 'android phone', 'handphone'],
        'laptop' => ['laptop', 'notebook computer', 'computer', 'macbook'],
        'tablet' => ['tablet', 'ipad', 'tab'],
        'earbuds' => ['earbud', 'earbuds', 'earpod', 'earpods', 'airpod', 'airpods', 'earphone', 'earphones', 'headphone', 'headphones', 'headset', 'earpiece', 'earplug', 'wireless earbuds', 'bluetooth earbuds', 'true wireless', 'tws', 'charging case', 'earbud case', 'audio device'],
        'charger_powerbank' => ['charger', 'phone charger', 'laptop charger', 'adapter', 'power adapter', 'charging brick', 'powerbank', 'power bank', 'battery pack'],
        'cable' => ['cable', 'usb cable', 'charging cable', 'wire', 'cord', 'type c cable', 'lightning cable'],
        'mouse_keyboard' => ['mouse', 'computer mouse', 'keyboard', 'wireless mouse', 'gaming mouse'],
        'calculator' => ['calculator', 'scientific calculator'],
        'camera' => ['camera', 'webcam', 'digital camera'],

        'student_id_card' => ['student card', 'student id', 'matric card', 'matriculation card', 'id card', 'identity card', 'staff card', 'access card', 'badge card'],
        'passport' => ['passport'],
        'license' => ['license', 'licence', 'driving license', 'driver license'],
        'certificate_document' => ['certificate', 'document', 'paper', 'form', 'receipt', 'letter', 'assignment', 'worksheet'],
        'bank_card' => ['bank card', 'debit card', 'credit card', 'atm card', 'payment card'],

        'watch' => ['watch', 'wristwatch', 'smartwatch', 'digital watch'],
        'glasses' => ['glasses', 'spectacles', 'eyeglasses', 'sunglasses', 'shades'],
        'belt' => ['belt'],
        'cap_hat' => ['cap', 'hat', 'beanie'],
        'umbrella' => ['umbrella'],
        'lanyard_badge' => ['lanyard', 'badge holder', 'card holder strap'],
        'hair_accessory' => ['hair clip', 'hair tie', 'scrunchie'],

        'shirt_top' => ['shirt', 't shirt', 'tshirt', 'top', 'blouse'],
        'jacket_hoodie' => ['jacket', 'hoodie', 'sweater', 'coat'],
        'pants' => ['pants', 'jeans', 'trousers', 'shorts'],
        'shoes' => ['shoe', 'shoes', 'sneaker', 'sneakers', 'slipper', 'slippers', 'sandal', 'sandals'],
        'uniform_labcoat' => ['uniform', 'lab coat', 'labcoat'],
        'scarf_socks' => ['scarf', 'socks', 'sock'],

        'book' => ['book', 'textbook', 'notebook', 'journal', 'diary'],
        'folder_file' => ['folder', 'file', 'binder', 'document file'],
        'pencil_case' => ['pencil case', 'pen case', 'stationery case'],
        'stationery' => ['pen', 'pencil', 'marker', 'highlighter', 'ruler', 'eraser', 'stationery'],

        'wallet' => ['wallet', 'purse', 'billfold', 'card holder', 'cardholder', 'card wallet', 'card case', 'card sleeve', 'coin purse', 'money', 'cash', 'currency', 'banknote', 'paper money'],

        'keys' => ['key', 'keys', 'keychain', 'key chain', 'keyring', 'key ring', 'car key', 'house key', 'locker key'],

        'backpack_bag' => ['bag', 'backpack', 'school bag', 'laptop bag', 'handbag', 'tote bag', 'sling bag', 'shoulder bag', 'pouch', 'briefcase'],
        'luggage' => ['luggage', 'suitcase', 'duffel bag', 'travel bag'],

        'bracelet' => ['bracelet', 'bangle'],
        'ring' => ['ring'],
        'necklace' => ['necklace', 'chain', 'pendant'],
        'earrings' => ['earring', 'earrings'],
        'brooch_anklet' => ['brooch', 'anklet'],

        'bottle' => ['bottle', 'water bottle', 'drink bottle', 'plastic bottle', 'flask', 'thermo flask', 'tumbler', 'coffee tumbler', 'thermos', 'hydro flask', 'vessel'],
        'cup_mug' => ['cup', 'mug', 'glass'],
        'food_container' => ['lunch box', 'lunchbox', 'food container', 'tupperware', 'container box'],

        'sports_item' => ['ball', 'football', 'basketball', 'racket', 'racquet', 'sports item'],
        'medicine' => ['medicine', 'medication', 'pill', 'inhaler'],
        'toy' => ['toy', 'doll', 'plush'],
        'helmet' => ['helmet'],
        'comb_brush' => ['comb', 'brush', 'hairbrush'],
        'makeup_sanitizer' => ['makeup', 'lipstick', 'sanitizer', 'hand sanitizer'],
    ];
}

function getGenericObjectWords(): array
{
    return [
        'object' => true,
        'item' => true,
        'thing' => true,
        'stuff' => true,
        'container' => true,
        'case' => true,
        'bag' => true,
        'purse' => true,
        'pouch' => true,
        'accessory' => true,
        'jewelry' => true,
        'jewellery' => true,
        'gem' => true,
        'jewel' => true,
        'ornament' => true,
        '3d' => true,
        'label' => true,
        'product' => true,
        'plastic' => true,
        'metal' => true,
        'black' => true,
        'white' => true,
        'red' => true,
        'blue' => true,
        'green' => true,
        'yellow' => true,
        'brown' => true,
        'gray' => true,
        'grey' => true,
    ];
}

function filterInformativeUploadedTags(array $tags): array
{
    $genericWords = getGenericObjectWords();
    $informativeTags = [];

    foreach (normalizeTagList($tags) as $tag) {
        if ($tag === '' || isset($genericWords[$tag])) {
            continue;
        }

        $tokens = tokenizePhrase($tag);
        if (empty($tokens)) {
            continue;
        }

        $hasInformativeToken = false;
        foreach ($tokens as $token) {
            if (!isset($genericWords[$token])) {
                $hasInformativeToken = true;
                break;
            }
        }

        if ($hasInformativeToken) {
            $informativeTags[] = $tag;
        }
    }

    return array_values(array_unique($informativeTags));
}

function detectObjectFamiliesFromTags(array $tags): array
{
    return detectObjectFamiliesFromText(implode(' ', normalizeTagList($tags)), true);
}

function detectObjectFamiliesFromItem(array $item): array
{
    return detectObjectFamiliesFromText(implode(' ', array_filter([
        (string) ($item['title'] ?? ''),
        (string) ($item['description'] ?? ''),
        (string) ($item['category'] ?? ''),
        (string) ($item['brand'] ?? ''),
        (string) ($item['color'] ?? ''),
        (string) ($item['image_tags'] ?? ''),
    ], static fn($value): bool => trim((string) $value) !== '')), false);
}

function detectObjectFamiliesFromText(string $text, bool $fromUploadedTags = false): array
{
    $normalizedText = normalizePhrase($text);
    if ($normalizedText === '') {
        return [];
    }

    $families = [];
    $genericWords = getGenericObjectWords();

    foreach (getObjectFamilyAliases() as $family => $aliases) {
        foreach ($aliases as $alias) {
            $normalizedAlias = normalizePhrase($alias);
            if ($normalizedAlias === '') {
                continue;
            }

            if ($fromUploadedTags && isset($genericWords[$normalizedAlias])) {
                continue;
            }

            if (phraseContainsAlias($normalizedText, $normalizedAlias)) {
                $families[$family] = true;
                break;
            }
        }
    }

    return array_keys($families);
}

function phraseContainsAlias(string $normalizedText, string $normalizedAlias): bool
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

function hasCompatibleObjectFamily(array $uploadedFamilies, array $itemFamilies): bool
{
    if (empty($uploadedFamilies)) {
        return true;
    }

    if (empty($itemFamilies)) {
        return false;
    }

    return count(array_intersect($uploadedFamilies, $itemFamilies)) > 0;
}

function calculateObjectFamilyConfidenceFloor(
    float $imageScore,
    float $imaggaScore,
    float $jaccardScore,
    float $categoryScore,
    array $matchedTags
): float {
    if ($imaggaScore >= 60.0 || $jaccardScore >= 20.0 || $imageScore >= 60.0) {
        return 78.0;
    }

    if ($imaggaScore >= 35.0 || $jaccardScore >= 10.0 || $categoryScore >= 100.0 || !empty($matchedTags)) {
        return 65.0;
    }

    if ($imageScore >= 30.0) {
        return 55.0;
    }

    return 0.0;
}

function calculateJaccardSimilarity(array $uploadedTags, array $itemKeywords): float
{
    $uploadedKeywordSet = array_fill_keys(expandUploadedTagKeywords($uploadedTags), true);
    $itemKeywordSet = array_fill_keys(normalizeKeywordArray($itemKeywords), true);

    if (empty($uploadedKeywordSet) || empty($itemKeywordSet)) {
        return 0.0;
    }

    $intersection = count(array_intersect_key($uploadedKeywordSet, $itemKeywordSet));
    $union = count($uploadedKeywordSet + $itemKeywordSet);

    if ($union === 0) {
        return 0.0;
    }

    return clampPercent(($intersection / $union) * 100.0);
}

function calculateCategoryScore($uploadedTags, $itemCategory): float
{
    $normalizedCategory = normalizePhrase((string) $itemCategory);
    if ($normalizedCategory === '' || empty($uploadedTags)) {
        return 0.0;
    }

    $normalizedTags = normalizeTagList($uploadedTags);
    $tagKeywordLookup = array_fill_keys(expandUploadedTagKeywords($normalizedTags), true);
    $categoryAliases = getCategoryAliases($normalizedCategory);

    foreach ($categoryAliases as $alias) {
        $normalizedAlias = normalizePhrase($alias);
        if ($normalizedAlias === '') {
            continue;
        }

        if (in_array($normalizedAlias, $normalizedTags, true)) {
            return 100.0;
        }

        $aliasCollapsed = str_replace(' ', '', $normalizedAlias);
        foreach ($normalizedTags as $tag) {
            if (str_replace(' ', '', $tag) === $aliasCollapsed) {
                return 100.0;
            }
        }

        foreach (tokenizePhrase($normalizedAlias) as $token) {
            if (isset($tagKeywordLookup[$token])) {
                return 100.0;
            }
        }
    }

    return 0.0;
}

function calculateImaggaSimilarity(array $uploadedTags, array $itemKeywords, string $itemText): float
{
    $normalizedTags = normalizeTagList($uploadedTags);
    if (empty($normalizedTags)) {
        return 0.0;
    }

    $matchedCount = 0;
    foreach ($normalizedTags as $tag) {
        if (tagMatchesItem($tag, $itemKeywords, $itemText)) {
            $matchedCount++;
        }
    }

    return clampPercent(($matchedCount / count($normalizedTags)) * 100.0);
}

function generateItemKeywords(array $item): array
{
    $combinedText = implode(' ', array_filter([
        (string) ($item['title'] ?? ''),
        (string) ($item['description'] ?? ''),
        (string) ($item['category'] ?? ''),
        (string) ($item['brand'] ?? ''),
        (string) ($item['color'] ?? ''),
        (string) ($item['image_tags'] ?? ''),
    ], static fn($value): bool => trim((string) $value) !== ''));

    return tokenizePhrase($combinedText);
}

function buildItemSearchText(array $item): string
{
    return normalizePhrase(implode(' ', array_filter([
        (string) ($item['title'] ?? ''),
        (string) ($item['description'] ?? ''),
        (string) ($item['category'] ?? ''),
        (string) ($item['brand'] ?? ''),
        (string) ($item['color'] ?? ''),
        (string) ($item['image_tags'] ?? ''),
    ], static fn($value): bool => trim((string) $value) !== '')));
}

function getMatchedTags(array $uploadedTags, array $itemKeywords, string $itemText): array
{
    $matched = [];
    foreach (normalizeTagList($uploadedTags) as $tag) {
        if (tagMatchesItem($tag, $itemKeywords, $itemText)) {
            $matched[] = $tag;
        }
    }

    return array_values(array_unique($matched));
}

function tagMatchesItem(string $tag, array $itemKeywords, string $itemText): bool
{
    $normalizedTag = normalizePhrase($tag);
    if ($normalizedTag === '') {
        return false;
    }

    $normalizedItemKeywords = normalizeKeywordArray($itemKeywords);
    $keywordLookup = array_fill_keys($normalizedItemKeywords, true);
    $normalizedText = normalizePhrase($itemText);
    $collapsedText = str_replace(' ', '', $normalizedText);
    $collapsedTag = str_replace(' ', '', $normalizedTag);

    if ($normalizedText !== '' && strpos(' ' . $normalizedText . ' ', ' ' . $normalizedTag . ' ') !== false) {
        return true;
    }

    if ($collapsedText !== '' && $collapsedTag !== '' && strpos($collapsedText, $collapsedTag) !== false) {
        return true;
    }

    $tagFamilies = detectObjectFamiliesFromText($normalizedTag, true);
    if (!empty($tagFamilies)) {
        $itemFamilies = detectObjectFamiliesFromText($normalizedText, false);
        if (!empty(array_intersect($tagFamilies, $itemFamilies))) {
            return true;
        }
    }

    $tagTokens = tokenizePhrase($normalizedTag);
    if (empty($tagTokens)) {
        return false;
    }

    foreach ($tagTokens as $token) {
        if (!isset($keywordLookup[$token])) {
            return false;
        }
    }

    return true;
}

function calculateFinalScore(
    float $imageScore,
    float $imaggaScore,
    float $jaccardScore,
    float $categoryScore,
    array $imageMetrics = []
): float
{
    // When OpenCV is unavailable (imageScore ~0), use semantic-only weights so
    // tag/keyword/category matches can still surface results.
    if ($imageScore < 5.0) {
        $semanticScore = ($imaggaScore * 0.55)
            + ($jaccardScore * 0.30)
            + ($categoryScore * 0.15);
        $semanticScore = max(
            $semanticScore,
            calculateSemanticConfidenceFloor($imaggaScore, $jaccardScore, $categoryScore)
        );
        return clampPercent($semanticScore);
    }

    $finalScore = ($imageScore * IMAGE_WEIGHT)
        + ($imaggaScore * IMAGGA_WEIGHT)
        + ($jaccardScore * JACCARD_WEIGHT)
        + ($categoryScore * CATEGORY_WEIGHT);

    if ($imageScore >= HIGH_CONFIDENCE_IMAGE_THRESHOLD && $imaggaScore >= HIGH_CONFIDENCE_IMAGGA_THRESHOLD) {
        $finalScore += HIGH_CONFIDENCE_BOOST;
    }

    $hasModerateVisualSupport = hasModerateDatabaseImageMatch($imageScore, $imageMetrics);
    $hasStrongVisualSupport = hasStrongDatabaseImageMatch($imageScore, $imageMetrics);

    $finalScore = max(
        $finalScore,
        calculateVisualConfidenceFloor($imageScore, $imageMetrics),
        $hasModerateVisualSupport
            ? calculateSemanticConfidenceFloor($imaggaScore, $jaccardScore, $categoryScore)
            : 0.0
    );

    if (!$hasModerateVisualSupport) {
        $finalScore = min($finalScore, max($imageScore, 55.0));
    } elseif (!$hasStrongVisualSupport && $imageScore < 55.0) {
        $finalScore = min($finalScore, max($imageScore, 59.0));
    }

    return clampPercent($finalScore);
}

function hasModerateDatabaseImageMatch(float $imageScore, array $imageMetrics): bool
{
    $verifiedMatches = max(0, (int) ($imageMetrics['verified_matches'] ?? 0));
    $orbScore = clampPercent((float) ($imageMetrics['orb_score'] ?? 0));
    $histogramScore = clampPercent((float) ($imageMetrics['histogram_score'] ?? 0));
    $shapeScore = clampPercent((float) ($imageMetrics['shape_score'] ?? 0));

    return (
        ($imageScore >= 35.0 && $verifiedMatches >= 5 && $orbScore >= 20.0 && $histogramScore >= 35.0)
        || ($imageScore >= 45.0 && $histogramScore >= 55.0 && $shapeScore >= 60.0)
        || ($imageScore >= 50.0 && $verifiedMatches >= 4 && $orbScore >= 25.0 && $histogramScore >= 30.0 && $shapeScore >= 65.0)
    );
}

function hasStrongDatabaseImageMatch(float $imageScore, array $imageMetrics): bool
{
    $verifiedMatches = max(0, (int) ($imageMetrics['verified_matches'] ?? 0));
    $orbScore = clampPercent((float) ($imageMetrics['orb_score'] ?? 0));
    $histogramScore = clampPercent((float) ($imageMetrics['histogram_score'] ?? 0));
    $shapeScore = clampPercent((float) ($imageMetrics['shape_score'] ?? 0));

    return (
        ($imageScore >= 70.0 && $verifiedMatches >= 5 && $orbScore >= 30.0 && $histogramScore >= 35.0)
        || (
            $imageScore >= MIN_VISUAL_SCORE
            && $verifiedMatches >= MIN_VISUAL_VERIFIED_MATCHES
            && $orbScore >= 25.0
            && ($histogramScore >= 40.0 || $shapeScore >= 55.0)
        )
        || ($imageScore >= 55.0 && $verifiedMatches >= 5 && $orbScore >= 40.0 && $histogramScore >= 45.0)
        || ($imageScore >= 55.0 && $verifiedMatches >= 8 && $orbScore >= 25.0 && $histogramScore >= 50.0)
        || ($imageScore >= 65.0 && $histogramScore >= 65.0 && $shapeScore >= 65.0)
    );
}

function calculateVisualConfidenceFloor(float $imageScore, array $imageMetrics): float
{
    $verifiedMatches = max(0, (int) ($imageMetrics['verified_matches'] ?? 0));
    $orbScore = clampPercent((float) ($imageMetrics['orb_score'] ?? 0));

    if ($imageScore >= 95.0 && $verifiedMatches >= 12) {
        return 96.0;
    }

    if ($imageScore >= 85.0 && $verifiedMatches >= 10) {
        return 88.0;
    }

    if ($imageScore >= 75.0 && $verifiedMatches >= 8 && $orbScore >= 45.0) {
        return 78.0;
    }

    if ($imageScore >= 65.0 && $verifiedMatches >= 6 && $orbScore >= 35.0) {
        return 62.0;
    }

    if ($imageScore >= 55.0 && $verifiedMatches >= 5 && $orbScore >= 40.0) {
        return 58.0;
    }

    return 0.0;
}

function calculateSemanticConfidenceFloor(float $imaggaScore, float $jaccardScore, float $categoryScore): float
{
    if ($imaggaScore >= 80.0 && $jaccardScore >= 20.0) {
        return 85.0;
    }

    if ($imaggaScore >= 65.0 && ($jaccardScore >= 12.0 || $categoryScore >= 100.0)) {
        return 72.0;
    }

    if ($imaggaScore >= 45.0 && ($jaccardScore >= 15.0 || $categoryScore >= 100.0)) {
        return 60.0;
    }

    if ($imaggaScore >= 35.0 && ($jaccardScore >= 10.0 || $categoryScore >= 100.0)) {
        return 50.0;
    }

    if ($categoryScore >= 100.0 && $jaccardScore >= 10.0) {
        return 45.0;
    }

    if ($jaccardScore >= 20.0) {
        return 45.0;
    }

    return 0.0;
}

function isRelevantMatch(array $result): bool
{
    if (array_key_exists('family_allowed', $result) && !$result['family_allowed']) {
        return false;
    }

    $finalScore    = clampPercent((float) ($result['final_score'] ?? 0));
    $imageScore    = clampPercent((float) ($result['visual_score'] ?? $result['image_score'] ?? 0));
    $imaggaScore   = clampPercent((float) ($result['imagga_score'] ?? 0));
    $jaccardScore  = clampPercent((float) ($result['jaccard_score'] ?? 0));
    $categoryScore = clampPercent((float) ($result['category_score'] ?? 0));
    $orbScore      = clampPercent((float) ($result['orb_score'] ?? 0));
    $histogramScore = clampPercent((float) ($result['histogram_score'] ?? 0));
    $shapeScore    = clampPercent((float) ($result['shape_score'] ?? 0));
    $verifiedMatches = max(0, (int) ($result['verified_matches'] ?? 0));
    $matchedTags   = $result['matched_tags'] ?? [];
    $sameObjectFamily = !empty($result['family_allowed']);

    $hasModerateVisualEvidence  = hasModerateDatabaseImageMatch($imageScore, $result);
    $hasStrongVisualEvidence    = hasStrongDatabaseImageMatch($imageScore, $result);
    $hasVerifiedVisualEvidence  = $verifiedMatches >= 5 && $orbScore >= 20.0 && $imageScore >= 35.0 && $histogramScore >= 35.0;
    $hasStrongColorShapeAgreement = $imageScore >= 35.0 && $histogramScore >= 60.0 && $shapeScore >= 65.0;

    // --- Visual paths (OpenCV available) ---
    if ($hasStrongVisualEvidence) {
        return true;
    }

    if ($hasModerateVisualEvidence && $finalScore >= MIN_MATCH_SCORE) {
        return true;
    }

    if ($hasVerifiedVisualEvidence || $hasStrongColorShapeAgreement) {
        return $finalScore >= MIN_MATCH_SCORE;
    }

    // --- Semantic paths (tags, keywords, color, category, title, description) ---
    // Imagga tags strongly match item keywords/description
    if ($imaggaScore >= 40.0 && ($jaccardScore >= 10.0 || $categoryScore >= 100.0 || count($matchedTags) >= 2)) {
        return true;
    }

    // Category confirmed + keyword overlap from title/description/tags
    if ($categoryScore >= 100.0 && $jaccardScore >= 8.0) {
        return true;
    }

    // Strong keyword overlap from title, description, tags
    if ($jaccardScore >= 20.0) {
        return true;
    }

    // Same object type + any semantic signal (tag, keyword, or category)
    if ($sameObjectFamily && ($imaggaScore >= 25.0 || $jaccardScore >= 8.0 || !empty($matchedTags))) {
        return true;
    }

    // Good semantic score overall
    if ($finalScore >= MIN_MATCH_SCORE && ($imaggaScore >= 25.0 || $jaccardScore >= 10.0)) {
        return true;
    }

    return false;
}

function getMatchLevel(float $score): string
{
    if ($score >= 85.0) {
        return 'Highly Matched';
    }

    if ($score >= 70.0) {
        return 'Very Likely Match';
    }

    if ($score >= 50.0) {
        return 'Possible Match';
    }

    if ($score >= 30.0) {
        return 'Low Match';
    }

    return 'Potential Match';
}

function buildMatchReason(
    float $imageScore,
    float $imaggaScore,
    float $jaccardScore,
    float $categoryScore,
    array $matchedTags,
    array $imageMetrics = []
): string {
    $reasons = [];
    $orbScore = clampPercent((float) ($imageMetrics['orb_score'] ?? 0));
    $histogramScore = clampPercent((float) ($imageMetrics['histogram_score'] ?? 0));
    $shapeScore = clampPercent((float) ($imageMetrics['shape_score'] ?? 0));
    $verifiedMatches = max(0, (int) ($imageMetrics['verified_matches'] ?? 0));

    if ($imageScore >= HIGH_CONFIDENCE_IMAGE_THRESHOLD) {
        $reasons[] = 'strong OpenCV visual verification';
    } elseif ($imageScore >= 50.0) {
        $reasons[] = 'moderate visual similarity';
    }

    if ($orbScore >= 55.0) {
        $reasons[] = 'ORB keypoints align strongly';
    }

    if ($histogramScore >= 70.0) {
        $reasons[] = 'color profile matches closely';
    }

    if ($shapeScore >= 70.0) {
        $reasons[] = 'shape silhouette matches closely';
    }

    if ($verifiedMatches >= 8) {
        $reasons[] = 'multiple verified feature matches';
    }

    if ($imaggaScore >= HIGH_CONFIDENCE_IMAGGA_THRESHOLD) {
        $reasons[] = 'Imagga tags align closely with the item text';
    } elseif ($imaggaScore >= 40.0) {
        $reasons[] = 'some Imagga tags align with the item text';
    }

    if ($jaccardScore >= 30.0) {
        $reasons[] = 'keyword overlap detected';
    }

    if ($categoryScore >= 100.0) {
        $reasons[] = 'detected tags confirm the category';
    }

    if (!empty($matchedTags)) {
        $reasons[] = 'matched tags: ' . implode(', ', array_slice($matchedTags, 0, 4));
    }

    return !empty($reasons)
        ? implode('; ', array_values(array_unique($reasons)))
        : 'Potential match based on the available visual and semantic signals.';
}

function calculateDisplayedSimilarityScore(
    float $imageScore,
    float $finalScore = 0.0,
    float $imaggaScore = 0.0,
    float $jaccardScore = 0.0,
    float $categoryScore = 0.0
): float
{
    $imageScore = clampPercent($imageScore);
    $finalScore = clampPercent($finalScore);
    $imaggaScore = clampPercent($imaggaScore);
    $jaccardScore = clampPercent($jaccardScore);
    $categoryScore = clampPercent($categoryScore);

    if ($finalScore <= 0.0) {
        return $imageScore;
    }

    $displayScore = max(
        $imageScore,
        ($finalScore * 0.85) + ($imageScore * 0.15)
    );

    if (
        $imageScore >= 65.0
        && $imaggaScore >= 65.0
        && ($jaccardScore >= 10.0 || $categoryScore >= 100.0)
    ) {
        $displayScore = max($displayScore, 80.0);
    }

    if ($imageScore >= 82.0 && $imaggaScore >= 72.0 && ($jaccardScore >= 15.0 || $categoryScore >= 100.0)) {
        $displayScore = max($displayScore, 88.0);
    }

    return clampPercent($displayScore);
}

function sortImageSearchResults(array &$results): void
{
    usort($results, static function (array $left, array $right): int {
        $visualComparison = ($right['visual_score'] <=> $left['visual_score']);
        if ($visualComparison !== 0) {
            return $visualComparison;
        }

        $scoreComparison = ($right['final_score'] <=> $left['final_score']);
        if ($scoreComparison !== 0) {
            return $scoreComparison;
        }

        $imaggaComparison = ($right['imagga_score'] <=> $left['imagga_score']);
        if ($imaggaComparison !== 0) {
            return $imaggaComparison;
        }

        return ((int) $left['item_id']) <=> ((int) $right['item_id']);
    });
}

function inferUploadedObjectFamiliesFromResults(array $results): array
{
    $topResult = $results[0] ?? null;
    if (!is_array($topResult)) {
        return [];
    }

    $topVisualScore = clampPercent((float) ($topResult['visual_score'] ?? 0));
    $topVerifiedMatches = max(0, (int) ($topResult['verified_matches'] ?? 0));
    $nextVisualScore = clampPercent((float) (($results[1]['visual_score'] ?? 0)));

    if ($topVisualScore < 55.0 || $topVerifiedMatches < 5) {
        return [];
    }

    if ($topVisualScore < 70.0 && ($topVisualScore - $nextVisualScore) < 12.0) {
        return [];
    }

    $titleText = trim((string) ($topResult['title'] ?? ''));
    $detailText = trim(implode(' ', array_filter([
        (string) ($topResult['title'] ?? ''),
        (string) ($topResult['description'] ?? ''),
    ], static fn($value): bool => trim((string) $value) !== '')));

    $families = detectObjectFamiliesFromText($titleText, false);
    if (empty($families)) {
        $families = detectObjectFamiliesFromText($detailText, false);
    }

    if (empty($families)) {
        $families = array_values(array_filter(
            (array) ($topResult['item_object_families'] ?? []),
            static fn($value): bool => trim((string) $value) !== ''
        ));
    }

    return array_values(array_unique($families));
}

function applyInferredObjectFamiliesToResults(array $results, array $uploadedObjectFamilies): array
{
    foreach ($results as &$result) {
        $itemFamilies = array_values(array_filter(
            (array) ($result['item_object_families'] ?? []),
            static fn($value): bool => trim((string) $value) !== ''
        ));
        $sameObjectFamily = hasCompatibleObjectFamily($uploadedObjectFamilies, $itemFamilies);

        $result['uploaded_object_families'] = array_values($uploadedObjectFamilies);
        $result['family_allowed'] = $sameObjectFamily;
        $result['object_family_score'] = $sameObjectFamily ? 100.0 : 0.0;

        if (!$sameObjectFamily) {
            continue;
        }

        $adjustedFinalScore = max(
            clampPercent((float) ($result['final_score'] ?? 0)),
            calculateObjectFamilyConfidenceFloor(
                clampPercent((float) ($result['visual_score'] ?? 0)),
                clampPercent((float) ($result['imagga_score'] ?? 0)),
                clampPercent((float) ($result['jaccard_score'] ?? 0)),
                clampPercent((float) ($result['category_score'] ?? 0)),
                (array) ($result['matched_tags'] ?? [])
            )
        );

        $result['final_score'] = round($adjustedFinalScore, 2);
        $result['similarity_percentage'] = round(calculateDisplayedSimilarityScore(
            clampPercent((float) ($result['visual_score'] ?? $result['image_score'] ?? 0)),
            clampPercent((float) ($result['final_score'] ?? 0)),
            clampPercent((float) ($result['imagga_score'] ?? 0)),
            clampPercent((float) ($result['jaccard_score'] ?? 0)),
            clampPercent((float) ($result['category_score'] ?? 0))
        ), 2);
        $result['match_level'] = getMatchLevel((float) $result['similarity_percentage']);
    }
    unset($result);

    return $results;
}

function calculateVisualSimilarity(string $imageOne, string $imageTwo, array $pythonCommand): array
{
    $defaultMetrics = createEmptyImageMetrics();

    // Try remote OpenCV microservice first if configured
    $serviceUrl = trim((string) EnvLoader::get('OPENCV_SERVICE_URL', ''));
    if ($serviceUrl !== '') {
        $result = calculateVisualSimilarityRemote($imageOne, $imageTwo, $serviceUrl);
        if ($result !== null) {
            return $result;
        }
    }

    // Fall back to local Python if available
    $scriptPath = __DIR__ . '/compare.py';

    if (!is_file($scriptPath) || !is_file($imageOne) || !is_file($imageTwo)) {
        return $defaultMetrics;
    }

    try {
        $result = runCommand(array_merge($pythonCommand, [$scriptPath, $imageOne, $imageTwo]), PYTHON_COMPARE_TIMEOUT_SECONDS);
    } catch (Throwable $exception) {
        error_log('Failed to execute compare.py: ' . $exception->getMessage());
        return $defaultMetrics;
    }

    $payload = decodeComparatorPayload($result['stdout'] ?? '', $result['stderr'] ?? '');
    if (!is_array($payload)) {
        error_log('compare.py returned invalid output.');
        return $defaultMetrics;
    }

    if (!empty($payload['error'])) {
        error_log('compare.py error: ' . $payload['error']);
    }

    return [
        'similarity' => clampPercent((float) ($payload['similarity'] ?? 0)),
        'orb_score' => clampPercent((float) ($payload['orb_score'] ?? 0)),
        'histogram_score' => clampPercent((float) ($payload['histogram_score'] ?? ($payload['hist_score'] ?? 0))),
        'shape_score' => clampPercent((float) ($payload['shape_score'] ?? 0)),
        'verified_matches' => max(0, (int) ($payload['verified_matches'] ?? 0)),
        'keypoints_image1' => max(0, (int) ($payload['keypoints_image1'] ?? ($payload['features1'] ?? 0))),
        'keypoints_image2' => max(0, (int) ($payload['keypoints_image2'] ?? ($payload['features2'] ?? 0))),
    ];
}

function calculateVisualSimilarityRemote(string $imageOne, string $imageTwo, string $serviceUrl): ?array
{
    if (!function_exists('curl_init')) {
        return null;
    }

    $appUrl = rtrim((string) EnvLoader::get('APP_URL', ''), '/');
    if ($appUrl === '') {
        return null;
    }

    $toPublicUrl = static function (string $absPath) use ($appUrl): ?string {
        $root = realpath(__DIR__ . '/..');
        if ($root === false) {
            return null;
        }
        $rel = str_replace('\\', '/', substr($absPath, strlen($root)));
        return $appUrl . '/' . ltrim($rel, '/');
    };

    $url1 = $toPublicUrl($imageOne);
    $url2 = $toPublicUrl($imageTwo);
    if ($url1 === null || $url2 === null) {
        return null;
    }

    $apiKey = trim((string) EnvLoader::get('OPENCV_SERVICE_API_KEY', ''));
    $endpoint = rtrim($serviceUrl, '/') . '/compare';

    $ch = curl_init($endpoint);
    $headers = ['Content-Type: multipart/form-data'];
    if ($apiKey !== '') {
        $headers[] = 'X-API-Key: ' . $apiKey;
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => ['image1_url' => $url1, 'image2_url' => $url2],
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_NOSIGNAL       => true,
    ]);

    $raw = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $httpCode < 200 || $httpCode >= 300) {
        error_log('OpenCV service error: ' . ($curlError ?: "HTTP $httpCode"));
        return null;
    }

    $payload = json_decode($raw, true);
    if (!is_array($payload) || !empty($payload['error'])) {
        error_log('OpenCV service returned error: ' . ($payload['error'] ?? 'invalid response'));
        return null;
    }

    return [
        'similarity'       => clampPercent((float) ($payload['similarity'] ?? 0)),
        'orb_score'        => clampPercent((float) ($payload['orb_score'] ?? 0)),
        'histogram_score'  => clampPercent((float) ($payload['histogram_score'] ?? ($payload['hist_score'] ?? 0))),
        'shape_score'      => clampPercent((float) ($payload['shape_score'] ?? 0)),
        'verified_matches' => max(0, (int) ($payload['verified_matches'] ?? 0)),
        'keypoints_image1' => max(0, (int) ($payload['keypoints_image1'] ?? ($payload['features1'] ?? 0))),
        'keypoints_image2' => max(0, (int) ($payload['keypoints_image2'] ?? ($payload['features2'] ?? 0))),
    ];
}

function createEmptyImageMetrics(): array
{
    return [
        'similarity' => 0.0,
        'orb_score' => 0.0,
        'histogram_score' => 0.0,
        'shape_score' => 0.0,
        'verified_matches' => 0,
        'keypoints_image1' => 0,
        'keypoints_image2' => 0,
    ];
}

function decodeComparatorPayload(string $stdout, string $stderr): ?array
{
    foreach ([$stdout, $stderr, $stdout . PHP_EOL . $stderr] as $stream) {
        $stream = trim($stream);
        if ($stream === '') {
            continue;
        }

        $decoded = json_decode($stream, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $lines = preg_split('/\R+/', $stream) ?: [];
        foreach (array_reverse($lines) as $line) {
            $decoded = json_decode(trim($line), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
    }

    return null;
}

function findPythonCommand(): ?array
{
    static $resolved = false;
    static $command = null;

    if ($resolved) {
        return $command;
    }

    $resolved = true;

    if (!canRunManagedSubprocesses()) {
        return null;
    }

    $configuredPython = trim((string) EnvLoader::get('PYTHON_PATH', ''));

    $candidates = array_merge(
        array_values(array_filter([
            $configuredPython !== '' ? [$configuredPython] : null,
            ['python'],
            ['python3'],
            ['py', '-3'],
            ['C:\\Python39\\python.exe'],
            ['C:\\Python310\\python.exe'],
            ['C:\\Python311\\python.exe'],
            ['C:\\Python312\\python.exe'],
            ['C:\\Python313\\python.exe'],
            ['C:\\Python314\\python.exe'],
            ['C:\\Users\\' . getenv('USERNAME') . '\\AppData\\Local\\Programs\\Python\\Python312\\python.exe'],
        ])),
        discoverWindowsPythonCommands()
    );

    foreach ($candidates as $candidate) {
        try {
            $result = runCommand(array_merge($candidate, ['--version']), PYTHON_DISCOVERY_TIMEOUT_SECONDS);
        } catch (Throwable $exception) {
            continue;
        }

        $combinedOutput = trim(($result['stdout'] ?? '') . ' ' . ($result['stderr'] ?? ''));
        if (($result['exit_code'] ?? 1) !== 0 || stripos($combinedOutput, 'Python') === false) {
            continue;
        }

        if (!pythonSupportsImageComparison($candidate)) {
            continue;
        }

        $command = $candidate;
        return $command;
    }

    return null;
}

function discoverWindowsPythonCommands(): array
{
    $localAppData = rtrim((string) getenv('LOCALAPPDATA'), '\\/');
    $userProfile = rtrim((string) getenv('USERPROFILE'), '\\/');

    $patterns = array_filter([
        $localAppData !== '' ? $localAppData . '\\Programs\\Python\\Python3*\\python.exe' : null,
        $localAppData !== '' ? $localAppData . '\\Python\\pythoncore-*\\python.exe' : null,
        $userProfile !== '' ? $userProfile . '\\AppData\\Local\\Programs\\Python\\Python3*\\python.exe' : null,
        $userProfile !== '' ? $userProfile . '\\AppData\\Local\\Python\\pythoncore-*\\python.exe' : null,
    ]);

    $commands = [];
    $seen = [];

    foreach ($patterns as $pattern) {
        $matches = glob($pattern) ?: [];
        rsort($matches, SORT_NATURAL);

        foreach ($matches as $match) {
            $normalized = strtolower((string) $match);
            if ($normalized === '' || isset($seen[$normalized])) {
                continue;
            }

            $seen[$normalized] = true;
            $commands[] = [$match];
        }
    }

    return $commands;
}

function pythonSupportsImageComparison(array $pythonCommand): bool
{
    try {
        $result = runCommand(
            array_merge($pythonCommand, ['-c', 'import cv2, numpy; print(12345)']),
            PYTHON_DEPENDENCY_TIMEOUT_SECONDS
        );
    } catch (Throwable $exception) {
        return false;
    }

    if (($result['exit_code'] ?? 1) !== 0) {
        return false;
    }

    $combinedOutput = trim(($result['stdout'] ?? '') . ' ' . ($result['stderr'] ?? ''));

    return strpos($combinedOutput, '12345') !== false;
}

function canRunManagedSubprocesses(): bool
{
    return function_exists('proc_open')
        && function_exists('proc_get_status')
        && function_exists('proc_close')
        && function_exists('proc_terminate')
        && function_exists('stream_select');
}

function runCommand(array $commandParts, float $timeoutSeconds = 5.0): array
{
    $command = implode(' ', array_map(static function ($part): string {
        return escapeshellarg((string) $part);
    }, $commandParts));

    if (canRunManagedSubprocesses()) {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $pipes = [];
        $process = @proc_open($command, $descriptors, $pipes, __DIR__);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start process: ' . $command);
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $startedAt = microtime(true);
        $timedOut = false;

        while (true) {
            $status = proc_get_status($process);
            $running = (bool) ($status['running'] ?? false);

            $readStreams = [];
            if (is_resource($pipes[1]) && !feof($pipes[1])) {
                $readStreams[] = $pipes[1];
            }
            if (is_resource($pipes[2]) && !feof($pipes[2])) {
                $readStreams[] = $pipes[2];
            }

            if (!empty($readStreams)) {
                $write = null;
                $except = null;
                @stream_select($readStreams, $write, $except, 0, 200000);

                foreach ($readStreams as $stream) {
                    $chunk = stream_get_contents($stream);
                    if ($chunk === false || $chunk === '') {
                        continue;
                    }

                    if ($stream === $pipes[1]) {
                        $stdout .= $chunk;
                    } else {
                        $stderr .= $chunk;
                    }
                }
            } elseif ($running) {
                usleep(100000);
            }

            if (!$running) {
                break;
            }

            if ($timeoutSeconds > 0 && (microtime(true) - $startedAt) >= $timeoutSeconds) {
                $timedOut = true;
                @proc_terminate($process);

                $statusAfterTerminate = proc_get_status($process);
                if (!empty($statusAfterTerminate['running'])) {
                    @proc_terminate($process, 9);
                }
                break;
            }
        }

        $stdout .= stream_get_contents($pipes[1]) ?: '';
        $stderr .= stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($timedOut) {
            throw new RuntimeException('Command timed out after ' . $timeoutSeconds . ' seconds: ' . $command);
        }

        return [
            'command' => $command,
            'stdout' => (string) $stdout,
            'stderr' => (string) $stderr,
            'exit_code' => (int) $exitCode,
        ];
    }

    throw new RuntimeException('Managed subprocess execution is unavailable on this host.');
}

function saveImageAnalysisRecord(PDO $db, string $imagePath, array $uploadedTags, array $results): string
{
    $resultsJson = safeJsonEncode($results);
    $tagsJson = safeJsonEncode(array_values($uploadedTags));
    $matchedIdsJson = safeJsonEncode(array_map('intval', array_column($results, 'item_id')));
    $confidenceScore = !empty($results) ? round(((float) $results[0]['final_score']) / 100, 4) : 0.0;

    try {
        $statement = $db->prepare("
            INSERT INTO image_analysis (image_url, extracted_text, labels, confidence_score, matched_item_ids, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $statement->execute([$imagePath, $resultsJson, $tagsJson, $confidenceScore, $matchedIdsJson]);
    } catch (PDOException $exception) {
        $statement = $db->prepare("
            INSERT INTO image_analysis (image_url, extracted_text, labels, confidence_score, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $statement->execute([$imagePath, $resultsJson, $tagsJson, $confidenceScore]);
    }

    return (string) $db->lastInsertId();
}

function getFullImagePath(string $relativePath): ?string
{
    $relativePath = ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath), DIRECTORY_SEPARATOR);
    if ($relativePath === '') {
        return null;
    }

    $projectRoot = realpath(__DIR__ . '/..');
    if ($projectRoot === false) {
        return null;
    }

    $candidatePath = $projectRoot . DIRECTORY_SEPARATOR . $relativePath;
    $resolvedPath = realpath($candidatePath);

    if ($resolvedPath !== false) {
        $projectRootPrefix = rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (strpos($resolvedPath, $projectRootPrefix) !== 0 && $resolvedPath !== $projectRoot) {
            return null;
        }

        return is_file($resolvedPath) ? $resolvedPath : null;
    }

    return is_file($candidatePath) ? $candidatePath : null;
}

function normalizeTagList(array $tags): array
{
    $normalized = [];
    foreach ($tags as $tag) {
        $value = normalizePhrase((string) $tag);
        if ($value !== '') {
            $normalized[$value] = true;
        }
    }

    return array_keys($normalized);
}

function expandUploadedTagKeywords(array $tags): array
{
    $keywords = [];
    foreach (normalizeTagList($tags) as $tag) {
        foreach (tokenizePhrase($tag) as $token) {
            $keywords[$token] = true;
        }

        $collapsed = normalizeKeywordToken(str_replace(' ', '', $tag));
        if ($collapsed !== '') {
            $keywords[$collapsed] = true;
        }

        foreach (detectObjectFamiliesFromText($tag, true) as $family) {
            $canonicalAlias = getCanonicalObjectFamilyAlias($family);
            if ($canonicalAlias === '') {
                continue;
            }

            foreach (tokenizePhrase($canonicalAlias) as $token) {
                $keywords[$token] = true;
            }

            $collapsedCanonicalAlias = normalizeKeywordToken(str_replace(' ', '', $canonicalAlias));
            if ($collapsedCanonicalAlias !== '') {
                $keywords[$collapsedCanonicalAlias] = true;
            }
        }
    }

    return array_keys($keywords);
}

function getCanonicalObjectFamilyAlias(string $family): string
{
    $aliases = getObjectFamilyAliases();
    if (!isset($aliases[$family]) || !is_array($aliases[$family])) {
        return '';
    }

    foreach ($aliases[$family] as $alias) {
        $normalizedAlias = normalizePhrase((string) $alias);
        if ($normalizedAlias !== '') {
            return $normalizedAlias;
        }
    }

    return '';
}

function normalizeKeywordArray(array $keywords): array
{
    $normalized = [];
    foreach ($keywords as $keyword) {
        $value = normalizeKeywordToken((string) $keyword);
        if ($value !== '') {
            $normalized[$value] = true;
        }
    }

    return array_keys($normalized);
}

function tokenizePhrase(string $text): array
{
    $normalizedText = normalizePhrase($text);
    if ($normalizedText === '') {
        return [];
    }

    $tokens = [];
    foreach (preg_split('/\s+/', $normalizedText) ?: [] as $token) {
        $normalizedToken = normalizeKeywordToken($token);
        if ($normalizedToken !== '') {
            $tokens[$normalizedToken] = true;
        }
    }

    return array_keys($tokens);
}

function normalizePhrase(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/i', ' ', $value) ?? '';
    $value = preg_replace('/\s+/', ' ', $value) ?? '';
    return trim($value);
}

function normalizeKeywordToken(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/i', '', $value) ?? '';

    if ($value === '') {
        return '';
    }

    if (strlen($value) > 4 && str_ends_with($value, 'ies')) {
        return substr($value, 0, -3) . 'y';
    }

    if (strlen($value) > 3
        && str_ends_with($value, 's')
        && !str_ends_with($value, 'ss')
        && !str_ends_with($value, 'us')
        && !str_ends_with($value, 'is')
        && !str_ends_with($value, 'sses')) {
        return substr($value, 0, -1);
    }

    return $value;
}

function getCategoryAliases(string $category): array
{
    $normalizedCategory = normalizePhrase($category);
    $aliases = [
        'electronics' => ['electronics', 'electronic', 'phone', 'smartphone', 'mobile', 'laptop', 'tablet', 'charger', 'powerbank', 'earbud', 'earphone', 'headphone', 'headset', 'earpod', 'airpod', 'audio device', 'tws'],
        'documents' => ['documents', 'document', 'card', 'id', 'student id', 'passport', 'license', 'certificate'],
        'accessories' => ['accessories', 'accessory', 'watch', 'glasses', 'sunglasses', 'belt', 'cap', 'hat'],
        'clothing' => ['clothing', 'clothes', 'shirt', 'jacket', 'hoodie', 'pants', 'jeans', 'dress', 'shoe'],
        'books' => ['books', 'book', 'notebook', 'journal', 'textbook', 'stationery', 'pencil case'],
        'wallet' => ['wallet', 'purse', 'cardholder', 'card holder', 'billfold'],
        'keys' => ['keys', 'key', 'keychain', 'keyring', 'key ring'],
        'bag' => ['bag', 'backpack', 'handbag', 'tote', 'luggage', 'sling'],
        'jewelry' => ['jewelry', 'jewellery', 'bracelet', 'necklace', 'ring', 'earring', 'pendant'],
        'household' => ['household', 'bottle', 'cup', 'mug', 'container', 'flask', 'tumbler', 'umbrella'],
    ];

    $categoryAliases = $aliases[$normalizedCategory] ?? [$normalizedCategory];
    $categoryAliases[] = $normalizedCategory;

    return array_values(array_unique(array_filter(array_map('normalizePhrase', $categoryAliases))));
}

function clampPercent(float $value): float
{
    return max(0.0, min(100.0, $value));
}

function detectMimeType(string $filePath): string
{
    if (!function_exists('finfo_open') || !defined('FILEINFO_MIME_TYPE')) {
        return 'image/jpeg';
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) {
        return 'image/jpeg';
    }

    $mimeType = finfo_file($finfo, $filePath);
    finfo_close($finfo);

    return is_string($mimeType) && $mimeType !== '' ? $mimeType : 'image/jpeg';
}

function getImaggaCacheFilePath(string $imageHash): ?string
{
    $cacheDirectory = __DIR__ . '/../storage/cache/imagga-tags';
    try {
        ensureDirectory($cacheDirectory);
    } catch (Throwable $exception) {
        error_log('Unable to initialize Imagga cache directory: ' . $exception->getMessage());
        return null;
    }

    return $cacheDirectory . DIRECTORY_SEPARATOR . $imageHash . '.json';
}

function readCachedImaggaTags(?string $cacheFile): ?array
{
    if ($cacheFile === null || !is_file($cacheFile)) {
        return null;
    }

    $modifiedTime = @filemtime($cacheFile);
    if ($modifiedTime === false || (time() - $modifiedTime) > IMAGGA_TAG_CACHE_TTL) {
        return null;
    }

    $payload = json_decode((string) @file_get_contents($cacheFile), true);
    if (!is_array($payload) || !isset($payload['tags']) || !is_array($payload['tags'])) {
        return null;
    }

    return normalizeTagList($payload['tags']);
}

function writeCachedImaggaTags(?string $cacheFile, array $tags): void
{
    if ($cacheFile === null) {
        return;
    }

    $payload = [
        'cached_at' => date(DATE_ATOM),
        'tags' => array_values(normalizeTagList($tags)),
    ];

    @file_put_contents($cacheFile, safeJsonEncode($payload), LOCK_EX);
}

function ensureDirectory(string $directory): void
{
    if (is_dir($directory)) {
        return;
    }

    if (!@mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create required directory: ' . $directory);
    }
}

function safeJsonEncode($value): string
{
    $encoded = json_encode(
        $value,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($encoded === false) {
        throw new RuntimeException('Failed to encode JSON payload.');
    }

    return $encoded;
}

function deriveUploadedTagsFromFilename(string $originalName): array
{
    $baseName = pathinfo($originalName, PATHINFO_FILENAME);
    $baseName = strtolower(trim($baseName));
    if ($baseName === '') {
        return [];
    }

    $tokens = preg_split('/[^a-z0-9]+/i', $baseName) ?: [];
    $tags = [];

    foreach ($tokens as $token) {
        $token = normalizePhrase($token);
        if ($token !== '' && strlen(str_replace(' ', '', $token)) >= 3) {
            $tags[$token] = true;
        }
    }

    return array_keys($tags);
}

function hasExceededProcessingBudget(float $startedAt, float $reserveSeconds = 0.0): bool
{
    return (microtime(true) - $startedAt) >= max(0.0, MAX_PROCESSING_SECONDS - $reserveSeconds);
}

function respondJson(array $payload, int $statusCode = 200): void
{
    throw new JsonResponseException($payload, $statusCode);
}

function deleteTemporaryUpload(string $relativePath, string $uploadDir): void
{
    if (function_exists('delete_uploaded_file_safely')) {
        delete_uploaded_file_safely($relativePath, $uploadDir);
        return;
    }

    $resolvedPath = getFullImagePath($relativePath);
    if ($resolvedPath !== null && is_file($resolvedPath)) {
        @unlink($resolvedPath);
    }
}
?>
