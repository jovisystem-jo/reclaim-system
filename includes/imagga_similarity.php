<?php

function getImaggaSimilarityConfig() {
    return [
        'api_key' => getenv('IMAGGA_API_KEY') ?: '',
        'api_secret' => getenv('IMAGGA_API_SECRET') ?: '',
        'collection_id' => getenv('IMAGGA_SIMILARITY_COLLECTION_ID') ?: 'reclaim-items',
        'categorizer_id' => getenv('IMAGGA_SIMILARITY_CATEGORIZER') ?: 'general_v3',
        'max_results' => 5,
    ];
}

function imaggaFailureResult($error, array $extra = []) {
    return array_merge([
        'success' => false,
        'http_code' => 0,
        'error' => (string)$error,
    ], $extra);
}

function imaggaDependenciesAvailable() {
    static $status = null;

    if ($status !== null) {
        return $status;
    }

    $requiredFunctions = [
        'curl_init',
        'curl_setopt',
        'curl_exec',
        'curl_close',
        'curl_getinfo',
        'curl_error',
        'curl_file_create',
    ];

    foreach ($requiredFunctions as $functionName) {
        if (!function_exists($functionName)) {
            $status = [
                'available' => false,
                'error' => 'Imagga integration is unavailable because PHP cURL support is not enabled on this server.',
            ];
            return $status;
        }
    }

    $status = [
        'available' => true,
        'error' => '',
    ];

    return $status;
}

function imaggaResolveImageMimeType($imagePath) {
    if (function_exists('mime_content_type')) {
        $mimeType = @mime_content_type($imagePath);
        if (is_string($mimeType) && trim($mimeType) !== '') {
            return $mimeType;
        }
    }

    if (function_exists('finfo_open') && function_exists('finfo_file') && function_exists('finfo_close')) {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mimeType = @finfo_file($finfo, $imagePath);
            @finfo_close($finfo);

            if (is_string($mimeType) && trim($mimeType) !== '') {
                return $mimeType;
            }
        }
    }

    $extension = strtolower((string)pathinfo($imagePath, PATHINFO_EXTENSION));
    $mimeTypesByExtension = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
    ];

    return $mimeTypesByExtension[$extension] ?? 'application/octet-stream';
}

function imaggaCreateMultipartImageField($imagePath) {
    if (!is_file($imagePath)) {
        return imaggaFailureResult('Item image file not found for Imagga processing.');
    }

    $dependencyStatus = imaggaDependenciesAvailable();
    if (empty($dependencyStatus['available'])) {
        return imaggaFailureResult($dependencyStatus['error'], ['skipped' => true]);
    }

    try {
        return [
            'success' => true,
            'file' => curl_file_create(
                $imagePath,
                imaggaResolveImageMimeType($imagePath),
                basename($imagePath)
            ),
        ];
    } catch (Throwable $e) {
        return imaggaFailureResult(
            'Unable to prepare the uploaded image for Imagga processing.',
            ['skipped' => true]
        );
    }
}

function imaggaApiSuccess(array $payload) {
    $statusType = strtolower((string)($payload['status']['type'] ?? $payload['result']['status']['type'] ?? ''));
    return $statusType === '' || $statusType === 'success';
}

function imaggaMultipartRequest($url, $apiKey, $apiSecret, array $fields, $method = 'POST', array $queryParams = []) {
    if ($apiKey === '' || $apiSecret === '') {
        return imaggaFailureResult('Imagga API credentials not configured.', ['skipped' => true]);
    }

    $dependencyStatus = imaggaDependenciesAvailable();
    if (empty($dependencyStatus['available'])) {
        return imaggaFailureResult($dependencyStatus['error'], ['skipped' => true]);
    }

    if (!empty($queryParams)) {
        $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($queryParams);
    }

    $ch = curl_init();
    if ($ch === false) {
        return imaggaFailureResult('Unable to initialize the Imagga request.', ['skipped' => true]);
    }

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_USERPWD, $apiKey . ':' . $apiSecret);
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));

    if (strtoupper($method) !== 'GET') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    }

    $rawResponse = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($rawResponse === false) {
        return imaggaFailureResult(
            $curlError !== '' ? $curlError : 'Imagga request failed.',
            ['http_code' => $httpCode]
        );
    }

    $decoded = json_decode($rawResponse, true);
    if (!is_array($decoded)) {
        return imaggaFailureResult('Invalid JSON response from Imagga.', [
            'http_code' => $httpCode,
            'raw_response' => $rawResponse,
        ]);
    }

    if ($httpCode < 200 || $httpCode >= 300 || !imaggaApiSuccess($decoded)) {
        return imaggaFailureResult(
            (string)($decoded['status']['text'] ?? $decoded['result']['status']['text'] ?? 'Imagga request failed.'),
            [
                'http_code' => $httpCode,
                'response' => $decoded,
            ]
        );
    }

    return [
        'success' => true,
        'http_code' => $httpCode,
        'response' => $decoded,
    ];
}

function imaggaIsInformativeTag($tagName) {
    $normalized = strtolower(trim((string) $tagName));
    if ($normalized === '') {
        return false;
    }

    $genericTags = [
        '3d' => true,
        'art' => true,
        'bag' => true,
        'container' => true,
        'device' => true,
        'electronic equipment' => true,
        'envelope' => true,
        'equipment' => true,
        'gem' => true,
        'graphic' => true,
        'hand' => true,
        'hour' => true,
        'hour hand' => true,
        'indicator' => true,
        'luggage and bags' => true,
        'memory' => true,
        'minute' => true,
        'minute hand' => true,
        'modem' => true,
        'object' => true,
        'pointer' => true,
        'product' => true,
        'purse' => true,
        'radio' => true,
        'technology' => true,
        'time' => true,
        'vessel' => true,
    ];

    return !isset($genericTags[$normalized]);
}

function imaggaNormalizeText($value) {
    $value = strtolower(trim((string) $value));
    $value = preg_replace('/[^a-z0-9]+/i', ' ', $value) ?? '';
    $value = preg_replace('/\s+/', ' ', $value) ?? '';
    return trim($value);
}

function imaggaNormalizeToken($value) {
    $value = strtolower(trim((string) $value));
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

function imaggaTokenizeText($value) {
    $normalized = imaggaNormalizeText($value);
    if ($normalized === '') {
        return [];
    }

    $tokens = [];
    foreach (preg_split('/\s+/', $normalized) ?: [] as $token) {
        $token = imaggaNormalizeToken($token);
        if ($token === '' || strlen($token) < 2) {
            continue;
        }

        $tokens[$token] = true;
    }

    return array_keys($tokens);
}

function imaggaPhraseContainsAlias($text, $alias) {
    $normalizedText = imaggaNormalizeText($text);
    $normalizedAlias = imaggaNormalizeText($alias);

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

function imaggaGetObjectFamilyAliases() {
    return [
        'bottle_household' => ['water bottle', 'bottle', 'flask', 'tumbler', 'thermos', 'hydro flask'],
        'wallet' => ['wallet', 'purse', 'card holder', 'cardholder', 'billfold'],
        'watch' => ['watch', 'wristwatch', 'clock', 'smartwatch'],
        'earbuds_audio' => ['wireless earbuds', 'earbuds', 'earpod', 'earpods', 'airpod', 'airpods', 'earphone', 'earphones', 'charging case', 'headset', 'headphones'],
        'powerbank_charger' => ['powerbank', 'power bank', 'portable charger', 'charger', 'battery pack'],
        'pencil_case' => ['pencil case', 'pencil pouch', 'stationery pouch', 'zipper pouch', 'pouch'],
        'bracelet' => ['bracelet', 'bangle', 'wristband'],
        'bag' => ['bag', 'handbag', 'tote bag', 'backpack', 'purse'],
        'phone' => ['phone', 'smartphone', 'mobile phone', 'iphone', 'android phone'],
        'key' => ['key', 'keys', 'keychain', 'key ring'],
    ];
}

function imaggaGetCategoryAliases($category) {
    $normalizedCategory = imaggaNormalizeText($category);
    $aliases = [
        'electronics' => ['earbuds', 'earpod', 'airpods', 'powerbank', 'portable charger', 'charger', 'phone'],
        'wallet' => ['wallet', 'purse', 'card holder'],
        'jewelry' => ['bracelet', 'bangle', 'ring', 'necklace'],
        'household' => ['water bottle', 'bottle', 'flask', 'tumbler'],
        'books' => ['pencil case', 'pencil pouch', 'stationery pouch', 'pouch'],
        'bag' => ['bag', 'handbag', 'wallet', 'purse'],
        'accessories' => ['watch', 'wristwatch', 'bracelet', 'glasses'],
    ];

    $categoryAliases = $aliases[$normalizedCategory] ?? [];
    if ($normalizedCategory !== '' && !in_array($normalizedCategory, [
        'electronics',
        'accessories',
        'books',
        'household',
        'others',
        'other',
    ], true)) {
        $categoryAliases[] = $normalizedCategory;
    }

    $normalizedAliases = [];
    foreach ($categoryAliases as $alias) {
        $normalizedAlias = imaggaNormalizeText($alias);
        if ($normalizedAlias !== '') {
            $normalizedAliases[$normalizedAlias] = true;
        }
    }

    return array_keys($normalizedAliases);
}

function imaggaDetectObjectFamiliesFromText($text) {
    $normalizedText = imaggaNormalizeText($text);
    if ($normalizedText === '') {
        return [];
    }

    $families = [];
    foreach (imaggaGetObjectFamilyAliases() as $family => $aliases) {
        foreach ($aliases as $alias) {
            if (imaggaPhraseContainsAlias($normalizedText, $alias)) {
                $families[$family] = true;
                break;
            }
        }
    }

    return array_keys($families);
}

function imaggaBuildContextTags(array $itemContext) {
    $title = imaggaNormalizeText($itemContext['title'] ?? '');
    $description = imaggaNormalizeText($itemContext['description'] ?? '');
    $category = imaggaNormalizeText($itemContext['category'] ?? '');
    $brand = imaggaNormalizeText($itemContext['brand'] ?? '');
    $color = imaggaNormalizeText($itemContext['color'] ?? '');
    $contextText = trim(implode(' ', array_filter([$title, $description, $category, $brand, $color])));
    $specificContextText = trim(implode(' ', array_filter([$title, $description, $brand])));

    if ($contextText === '') {
        return [];
    }

    $tags = [];
    $families = imaggaDetectObjectFamiliesFromText($specificContextText);
    if (empty($families)) {
        $families = imaggaDetectObjectFamiliesFromText($contextText);
    }
    $aliasesByFamily = imaggaGetObjectFamilyAliases();

    foreach ($families as $family) {
        $aliases = $aliasesByFamily[$family] ?? [];
        if (empty($aliases)) {
            continue;
        }

        $tags[imaggaNormalizeText($aliases[0])] = true;

        $matchedAliasCount = 0;
        foreach ($aliases as $alias) {
            $normalizedAlias = imaggaNormalizeText($alias);
            if ($normalizedAlias === '') {
                continue;
            }

            if (imaggaPhraseContainsAlias($title, $normalizedAlias) || imaggaPhraseContainsAlias($description, $normalizedAlias)) {
                $tags[$normalizedAlias] = true;
                $matchedAliasCount++;
            }

            if ($matchedAliasCount >= 2) {
                break;
            }
        }
    }

    foreach (imaggaGetCategoryAliases($category) as $alias) {
        $aliasFamilies = imaggaDetectObjectFamiliesFromText($alias);
        if (!empty($families)) {
            if (empty($aliasFamilies) || empty(array_intersect($families, $aliasFamilies))) {
                continue;
            }
        }

        $tags[$alias] = true;
    }

    if (empty($tags) && $title !== '') {
        $titleTokens = imaggaTokenizeText($title);
        if (!empty($titleTokens)) {
            $fallbackTag = trim(implode(' ', array_slice($titleTokens, 0, min(3, count($titleTokens)))));
            if ($fallbackTag !== '') {
                $tags[$fallbackTag] = true;
            }
        }
    }

    return array_slice(array_keys($tags), 0, 6);
}

function imaggaFilterTagsForItemContext(array $tags, array $itemContext) {
    $title = imaggaNormalizeText($itemContext['title'] ?? '');
    $description = imaggaNormalizeText($itemContext['description'] ?? '');
    $category = imaggaNormalizeText($itemContext['category'] ?? '');
    $brand = imaggaNormalizeText($itemContext['brand'] ?? '');
    $color = imaggaNormalizeText($itemContext['color'] ?? '');
    $contextText = trim(implode(' ', array_filter([$title, $description, $category, $brand, $color])));
    $specificContextText = trim(implode(' ', array_filter([$title, $description, $brand])));
    $contextTags = imaggaBuildContextTags($itemContext);

    $normalizedRawTags = [];
    foreach ($tags as $tag) {
        $normalizedTag = imaggaNormalizeText($tag);
        if ($normalizedTag !== '') {
            $normalizedRawTags[$normalizedTag] = true;
        }
    }

    if ($contextText === '') {
        return array_slice(array_keys($normalizedRawTags), 0, 6);
    }

    $contextFamilies = imaggaDetectObjectFamiliesFromText($specificContextText);
    if (empty($contextFamilies)) {
        $contextFamilies = imaggaDetectObjectFamiliesFromText($contextText);
    }
    $contextTokens = imaggaTokenizeText($contextText);
    $contextTokenLookup = array_fill_keys($contextTokens, true);
    $categoryAliases = imaggaGetCategoryAliases($category);
    $filteredRawTags = [];

    foreach (array_keys($normalizedRawTags) as $tag) {
        $tagFamilies = imaggaDetectObjectFamiliesFromText($tag);
        if (!empty($contextFamilies) && !empty($tagFamilies) && empty(array_intersect($contextFamilies, $tagFamilies))) {
            continue;
        }

        $score = 0;

        if (imaggaPhraseContainsAlias($contextText, $tag)) {
            $score += 4;
        }

        foreach ($contextTags as $contextTag) {
            if (imaggaPhraseContainsAlias($contextTag, $tag) || imaggaPhraseContainsAlias($tag, $contextTag)) {
                $score += 3;
                break;
            }
        }

        if (!empty($tagFamilies) && !empty(array_intersect($contextFamilies, $tagFamilies))) {
            $score += 3;
        }

        foreach ($categoryAliases as $alias) {
            if (imaggaPhraseContainsAlias($alias, $tag) || imaggaPhraseContainsAlias($tag, $alias)) {
                $score += 2;
                break;
            }
        }

        $tagTokens = imaggaTokenizeText($tag);
        $matchedTokenCount = 0;
        foreach ($tagTokens as $token) {
            if (isset($contextTokenLookup[$token])) {
                $matchedTokenCount++;
            }
        }

        if (!empty($tagTokens) && $matchedTokenCount === count($tagTokens)) {
            $score += 2;
        } elseif ($matchedTokenCount > 0) {
            $score += 1;
        }

        if ($score >= 2) {
            $filteredRawTags[$tag] = true;
        }
    }

    $finalTags = [];
    foreach ($contextTags as $contextTag) {
        $finalTags[$contextTag] = true;
    }
    foreach (array_keys($filteredRawTags) as $tag) {
        $finalTags[$tag] = true;
    }

    if (empty($finalTags)) {
        foreach (array_keys($normalizedRawTags) as $tag) {
            $finalTags[$tag] = true;
            if (count($finalTags) >= 3) {
                break;
            }
        }
    }

    return array_slice(array_keys($finalTags), 0, 6);
}

function extractImaggaTagsForImage($imagePath, $apiKey = null, $apiSecret = null, $minConfidence = 35.0, $limit = 12) {
    $config = getImaggaSimilarityConfig();
    $apiKey = trim((string) ($apiKey ?? $config['api_key']));
    $apiSecret = trim((string) ($apiSecret ?? $config['api_secret']));

    if (!is_file($imagePath)) {
        return imaggaFailureResult('Item image file not found for Imagga tag extraction.', ['skipped' => true]);
    }

    if ($apiKey === '' || $apiSecret === '') {
        return imaggaFailureResult('Imagga API credentials not configured.', ['skipped' => true]);
    }

    $imageField = imaggaCreateMultipartImageField($imagePath);
    if (!$imageField['success']) {
        return $imageField;
    }

    $response = imaggaMultipartRequest(
        'https://api.imagga.com/v2/tags',
        $apiKey,
        $apiSecret,
        ['image' => $imageField['file']],
        'POST'
    );

    if (!$response['success']) {
        return $response;
    }

    $tagMap = [];
    foreach (($response['response']['result']['tags'] ?? []) as $entry) {
        $tagName = trim((string) ($entry['tag']['en'] ?? $entry['tag'] ?? ''));
        $confidence = (float) ($entry['confidence'] ?? 0);

        if ($tagName === '' || $confidence < (float) $minConfidence || !imaggaIsInformativeTag($tagName)) {
            continue;
        }

        if (!isset($tagMap[$tagName]) || $confidence > $tagMap[$tagName]) {
            $tagMap[$tagName] = round($confidence, 2);
        }
    }

    arsort($tagMap, SORT_NUMERIC);
    if ((int) $limit > 0) {
        $tagMap = array_slice($tagMap, 0, (int) $limit, true);
    }

    return [
        'success' => true,
        'tags' => array_keys($tagMap),
        'tag_confidences' => $tagMap,
        'response' => $response['response'],
    ];
}

function ensureItemsImageTagsColumn(PDO $db) {
    static $columnEnsured = false;

    if ($columnEnsured) {
        return;
    }

    try {
        $db->query('SELECT image_tags FROM items LIMIT 1');
    } catch (PDOException $e) {
        if ($e->getCode() !== '42S22') {
            throw $e;
        }

        $db->exec("ALTER TABLE items ADD COLUMN image_tags TEXT DEFAULT NULL");
    }

    $columnEnsured = true;
}

function trainImaggaCollection($collectionId, $apiKey, $apiSecret, $categorizerId = null) {
    $config = getImaggaSimilarityConfig();
    $categorizerId = $categorizerId ?: $config['categorizer_id'];

    return imaggaMultipartRequest(
        'https://api.imagga.com/v2/similar-images/categories/' . rawurlencode($categorizerId) . '/' . rawurlencode($collectionId),
        $apiKey,
        $apiSecret,
        [],
        'PUT'
    );
}

function addImageToImaggaCollection($imagePath, $collectionId, $apiKey, $apiSecret, $imageId = null, $categorizerId = null) {
    $config = getImaggaSimilarityConfig();
    $categorizerId = $categorizerId ?: $config['categorizer_id'];
    $imageId = $imageId ?: ('item-' . bin2hex(random_bytes(8)));

    if (!is_file($imagePath)) {
        return imaggaFailureResult('Item image file not found for Imagga indexing.');
    }

    $imageField = imaggaCreateMultipartImageField($imagePath);
    if (!$imageField['success']) {
        return $imageField;
    }

    $payload = [
        'image' => $imageField['file'],
        'save_index' => $collectionId,
        'save_id' => $imageId,
    ];

    $indexResponse = imaggaMultipartRequest(
        'https://api.imagga.com/v2/categories/' . rawurlencode($categorizerId),
        $apiKey,
        $apiSecret,
        $payload,
        'POST'
    );

    if (!$indexResponse['success']) {
        return $indexResponse;
    }

    $trainResponse = trainImaggaCollection($collectionId, $apiKey, $apiSecret, $categorizerId);

    return [
        'success' => true,
        'image_id' => (string)($indexResponse['response']['result']['image_id'] ?? $indexResponse['response']['result']['id'] ?? $imageId),
        'index_response' => $indexResponse['response'],
        'train_response' => $trainResponse['response'] ?? null,
        'train_success' => $trainResponse['success'] ?? false,
    ];
}

function searchSimilarImages($imagePath, $collectionId, $apiKey, $apiSecret) {
    $config = getImaggaSimilarityConfig();

    if (!is_file($imagePath)) {
        return imaggaFailureResult('Search image file not found.');
    }

    $multipartImage = imaggaCreateMultipartImageField($imagePath);
    if (!$multipartImage['success']) {
        return $multipartImage;
    }

    $preferredResponse = imaggaMultipartRequest(
        'https://api.imagga.com/v2/images-similarity/' . rawurlencode($collectionId),
        $apiKey,
        $apiSecret,
        ['image' => $multipartImage['file']],
        'POST',
        ['count' => $config['max_results']]
    );

    if ($preferredResponse['success']) {
        return [
            'success' => true,
            'response' => $preferredResponse['response'],
            'endpoint' => 'images-similarity',
        ];
    }

    $fallbackImage = imaggaCreateMultipartImageField($imagePath);
    if (!$fallbackImage['success']) {
        return $fallbackImage;
    }

    $fallbackResponse = imaggaMultipartRequest(
        'https://api.imagga.com/v2/similar-images/categories/' . rawurlencode($config['categorizer_id']) . '/' . rawurlencode($collectionId),
        $apiKey,
        $apiSecret,
        ['image' => $fallbackImage['file']],
        'POST',
        ['count' => $config['max_results']]
    );

    if ($fallbackResponse['success']) {
        return [
            'success' => true,
            'response' => $fallbackResponse['response'],
            'endpoint' => 'similar-images-categories',
            'preferred_error' => $preferredResponse['error'] ?? null,
        ];
    }

    return [
        'success' => false,
        'error' => $preferredResponse['error'] ?? $fallbackResponse['error'] ?? 'Visual similarity search failed.',
        'preferred_response' => $preferredResponse['response'] ?? null,
        'fallback_response' => $fallbackResponse['response'] ?? null,
    ];
}

function removeImageFromImaggaCollection($collectionId, $imageId, $apiKey, $apiSecret, $categorizerId = null) {
    $config = getImaggaSimilarityConfig();
    $categorizerId = $categorizerId ?: $config['categorizer_id'];

    if ($imageId === null || $imageId === '') {
        return [
            'success' => true,
        ];
    }

    $deleteResponse = imaggaMultipartRequest(
        'https://api.imagga.com/v2/similar-images/categories/' . rawurlencode($categorizerId) . '/' . rawurlencode($collectionId) . '/' . rawurlencode($imageId),
        $apiKey,
        $apiSecret,
        [],
        'DELETE'
    );

    if (!$deleteResponse['success']) {
        return $deleteResponse;
    }

    $trainResponse = trainImaggaCollection($collectionId, $apiKey, $apiSecret, $categorizerId);

    return [
        'success' => true,
        'train_response' => $trainResponse['response'] ?? null,
        'train_success' => $trainResponse['success'] ?? false,
    ];
}

function ensureItemsImaggaColumn(PDO $db) {
    static $columnEnsured = false;

    if ($columnEnsured) {
        return;
    }

    try {
        $db->query('SELECT imagga_image_id FROM items LIMIT 1');
    } catch (PDOException $e) {
        if ($e->getCode() !== '42S22') {
            throw $e;
        }

        $db->exec("ALTER TABLE items ADD COLUMN imagga_image_id VARCHAR(255) DEFAULT NULL");
    }

    $columnEnsured = true;
}

function extractImaggaMatchEntries(array $response) {
    $candidates = [];

    foreach ([
        $response['result']['images'] ?? null,
        $response['result']['items'] ?? null,
        $response['images'] ?? null,
        $response['items'] ?? null,
    ] as $entries) {
        if (is_array($entries)) {
            $candidates = $entries;
            break;
        }
    }

    $normalized = [];
    foreach ($candidates as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $imageId = (string)($entry['image_id'] ?? $entry['id'] ?? $entry['save_id'] ?? $entry['entry_id'] ?? '');
        if ($imageId === '') {
            continue;
        }

        $distance = $entry['distance'] ?? null;
        if ($distance === null && isset($entry['similarity'])) {
            $distance = 1 - (float)$entry['similarity'];
        }
        if ($distance === null && isset($entry['score'])) {
            $distance = 1 - (float)$entry['score'];
        }

        $distance = max(0.0, (float)$distance);
        $similarity = max(0.0, min(1.0, 1 - $distance));

        $normalized[] = [
            'image_id' => $imageId,
            'distance' => $distance,
            'similarity_score' => round($similarity, 6),
            'raw' => $entry,
        ];
    }

    usort($normalized, static function ($left, $right) {
        return $right['similarity_score'] <=> $left['similarity_score'];
    });

    return $normalized;
}

function mapImaggaMatchesToItems(PDO $db, array $entries, array $options = []) {
    ensureItemsImaggaColumn($db);

    if (empty($entries)) {
        return [
            'matched_items' => [],
            'matched_item_ids' => [],
            'similarity_score' => 0.0,
        ];
    }

    $imageIds = array_values(array_unique(array_filter(array_map(static function ($entry) {
        return (string)($entry['image_id'] ?? '');
    }, $entries))));

    if (empty($imageIds)) {
        return [
            'matched_items' => [],
            'matched_item_ids' => [],
            'similarity_score' => 0.0,
        ];
    }

    $statuses = array_values(array_filter(array_map('strval', $options['statuses'] ?? ['lost', 'found'])));
    $excludeItemIds = array_values(array_filter(array_map('intval', $options['exclude_item_ids'] ?? [])));
    $limit = max(1, (int)($options['limit'] ?? count($imageIds)));
    $params = $imageIds;
    $conditions = ['imagga_image_id IN (' . implode(',', array_fill(0, count($imageIds), '?')) . ')'];

    if (!empty($statuses)) {
        $conditions[] = 'status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')';
        $params = array_merge($params, $statuses);
    }

    if (!empty($options['category'])) {
        $conditions[] = 'category = ?';
        $params[] = (string)$options['category'];
    }

    if (!empty($excludeItemIds)) {
        $conditions[] = 'item_id NOT IN (' . implode(',', array_fill(0, count($excludeItemIds), '?')) . ')';
        $params = array_merge($params, $excludeItemIds);
    }

    $sql = 'SELECT * FROM items WHERE ' . implode(' AND ', $conditions);
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $itemsByImageId = [];
    foreach ($items as $item) {
        $imageId = (string)($item['imagga_image_id'] ?? '');
        if ($imageId !== '') {
            $itemsByImageId[$imageId] = $item;
        }
    }

    $matchedItems = [];
    $matchedItemIds = [];
    foreach ($entries as $entry) {
        $imageId = (string)($entry['image_id'] ?? '');
        if ($imageId === '' || !isset($itemsByImageId[$imageId])) {
            continue;
        }

        $item = $itemsByImageId[$imageId];
        $item['similarity_score'] = (float)$entry['similarity_score'];
        $item['imagga_distance'] = (float)$entry['distance'];
        $item['imagga_image_id'] = $imageId;
        $matchedItems[] = $item;
        $matchedItemIds[] = (int)$item['item_id'];

        if (count($matchedItems) >= $limit) {
            break;
        }
    }

    return [
        'matched_items' => $matchedItems,
        'matched_item_ids' => array_values(array_unique($matchedItemIds)),
        'similarity_score' => (float)($matchedItems[0]['similarity_score'] ?? 0.0),
    ];
}

function findSimilarItemsWithImaggaForImage(PDO $db, $imagePath, array $options = []) {
    $config = getImaggaSimilarityConfig();
    if ($config['api_key'] === '' || $config['api_secret'] === '' || $config['collection_id'] === '') {
        return [
            'success' => false,
            'skipped' => true,
            'error' => 'Imagga visual similarity is not configured.',
        ];
    }

    $dependencyStatus = imaggaDependenciesAvailable();
    if (empty($dependencyStatus['available'])) {
        return imaggaFailureResult($dependencyStatus['error'], ['skipped' => true]);
    }

    $similarityResponse = searchSimilarImages($imagePath, $config['collection_id'], $config['api_key'], $config['api_secret']);
    if (!$similarityResponse['success']) {
        return $similarityResponse;
    }

    $entries = extractImaggaMatchEntries($similarityResponse['response']);
    if (empty($entries)) {
        return [
            'success' => false,
            'error' => 'No similarity results were returned by Imagga.',
            'response' => $similarityResponse['response'],
        ];
    }

    try {
        $matches = mapImaggaMatchesToItems($db, $entries, $options);
    } catch (PDOException $e) {
        error_log('Imagga local match mapping failed: ' . $e->getMessage());
        return imaggaFailureResult('Unable to map Imagga results to local items.');
    }

    if (empty($matches['matched_items'])) {
        return [
            'success' => false,
            'error' => 'Similarity results could not be mapped to local items.',
            'response' => $similarityResponse['response'],
        ];
    }

    return [
        'success' => true,
        'matched_items' => $matches['matched_items'],
        'matched_item_ids' => $matches['matched_item_ids'],
        'similarity_score' => $matches['similarity_score'],
        'api_used' => 'imagga-visual-similarity',
        'endpoint' => $similarityResponse['endpoint'] ?? 'visual-similarity',
    ];
}

function findSimilarItemsWithImaggaForItem(PDO $db, array $item, array $options = []) {
    $relativeImagePath = (string)($item['image_url'] ?? '');
    if ($relativeImagePath === '') {
        return [
            'success' => false,
            'skipped' => true,
            'error' => 'Item does not have an image to analyze.',
        ];
    }

    $absoluteImagePath = __DIR__ . '/../' . ltrim($relativeImagePath, '/\\');
    if (!is_file($absoluteImagePath)) {
        return [
            'success' => false,
            'skipped' => true,
            'error' => 'Item image file not found for Imagga analysis.',
        ];
    }

    if (!array_key_exists('exclude_item_ids', $options) && isset($item['item_id'])) {
        $options['exclude_item_ids'] = [(int)$item['item_id']];
    }

    if (!array_key_exists('category', $options) && !empty($item['category'])) {
        $options['category'] = (string)$item['category'];
    }

    return findSimilarItemsWithImaggaForImage($db, $absoluteImagePath, $options);
}

function syncItemImageToImaggaCollection(PDO $db, $itemId, $relativeImagePath) {
    $config = getImaggaSimilarityConfig();

    if ($relativeImagePath === null || $relativeImagePath === '') {
        return [
            'success' => false,
            'skipped' => true,
            'error' => 'Item does not have an image to index.',
        ];
    }

    $dependencyStatus = imaggaDependenciesAvailable();
    if (empty($dependencyStatus['available'])) {
        return imaggaFailureResult($dependencyStatus['error'], ['skipped' => true]);
    }

    $absoluteImagePath = __DIR__ . '/../' . ltrim((string)$relativeImagePath, '/\\');
    if (!is_file($absoluteImagePath)) {
        return imaggaFailureResult('Item image file not found for Imagga indexing.', ['skipped' => true]);
    }

    $storedTags = [];
    $tagsSynced = false;
    $itemContext = [
        'title' => '',
        'description' => '',
        'category' => '',
        'brand' => '',
        'color' => '',
        'imagga_image_id' => '',
    ];

    try {
        ensureItemsImaggaColumn($db);
        ensureItemsImageTagsColumn($db);

        $stmt = $db->prepare('
            SELECT imagga_image_id, title, description, category, brand, color
            FROM items
            WHERE item_id = ?
            LIMIT 1
        ');
        $stmt->execute([(int)$itemId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!empty($existing)) {
            $itemContext = array_merge($itemContext, $existing);
        }
    } catch (PDOException $e) {
        error_log('Imagga column preparation failed for item ' . (int)$itemId . ': ' . $e->getMessage());
        return imaggaFailureResult('Unable to prepare local item image indexing.');
    }

    $storedTags = imaggaBuildContextTags($itemContext);

    if ($config['api_key'] !== '' && $config['api_secret'] !== '') {
        $tagResult = extractImaggaTagsForImage($absoluteImagePath, $config['api_key'], $config['api_secret']);
        if (!empty($tagResult['success'])) {
            $storedTags = imaggaFilterTagsForItemContext((array) ($tagResult['tags'] ?? []), $itemContext);
        } elseif (empty($tagResult['skipped'])) {
            error_log('Imagga tag extraction failed for item ' . (int) $itemId . ': ' . ($tagResult['error'] ?? 'Unknown error'));
        }
    }

    if (!empty($storedTags)) {
        try {
            $tagStatement = $db->prepare('UPDATE items SET image_tags = ? WHERE item_id = ?');
            $tagStatement->execute([implode(', ', $storedTags), (int) $itemId]);
            $tagsSynced = true;
        } catch (PDOException $e) {
            error_log('Imagga tag update failed for item ' . (int) $itemId . ': ' . $e->getMessage());
        }
    }

    if ($config['api_key'] === '' || $config['api_secret'] === '' || $config['collection_id'] === '') {
        return [
            'success' => $tagsSynced,
            'skipped' => !$tagsSynced,
            'error' => $tagsSynced ? '' : 'Imagga visual similarity is not configured.',
            'tags' => $storedTags,
            'tags_synced' => $tagsSynced,
            'train_success' => false,
        ];
    }

    $imageId = $itemContext['imagga_image_id'] ?? '';
    if ($imageId === '') {
        $imageId = 'item-' . (int)$itemId;
    }

    $indexResult = addImageToImaggaCollection(
        $absoluteImagePath,
        $config['collection_id'],
        $config['api_key'],
        $config['api_secret'],
        $imageId,
        $config['categorizer_id']
    );

    if (!$indexResult['success']) {
        $indexResult['tags'] = $storedTags;
        $indexResult['tags_synced'] = $tagsSynced;
        return $indexResult;
    }

    $storedImageId = (string)($indexResult['image_id'] ?? $imageId);
    try {
        $updateStmt = $db->prepare('UPDATE items SET imagga_image_id = ? WHERE item_id = ?');
        $updateStmt->execute([$storedImageId, (int)$itemId]);
    } catch (PDOException $e) {
        error_log('Imagga image ID update failed for item ' . (int)$itemId . ': ' . $e->getMessage());
        return imaggaFailureResult('Unable to save the Imagga image index reference.');
    }

    return [
        'success' => true,
        'image_id' => $storedImageId,
        'tags' => $storedTags,
        'tags_synced' => $tagsSynced,
        'train_success' => $indexResult['train_success'] ?? false,
    ];
}
