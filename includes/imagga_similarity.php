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

function imaggaApiSuccess(array $payload) {
    $statusType = strtolower((string)($payload['status']['type'] ?? $payload['result']['status']['type'] ?? ''));
    return $statusType === '' || $statusType === 'success';
}

function imaggaMultipartRequest($url, $apiKey, $apiSecret, array $fields, $method = 'POST', array $queryParams = []) {
    if ($apiKey === '' || $apiSecret === '') {
        return [
            'success' => false,
            'http_code' => 0,
            'error' => 'Imagga API credentials not configured.',
        ];
    }

    if (!empty($queryParams)) {
        $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($queryParams);
    }

    $ch = curl_init();
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
        return [
            'success' => false,
            'http_code' => $httpCode,
            'error' => $curlError !== '' ? $curlError : 'Imagga request failed.',
        ];
    }

    $decoded = json_decode($rawResponse, true);
    if (!is_array($decoded)) {
        return [
            'success' => false,
            'http_code' => $httpCode,
            'error' => 'Invalid JSON response from Imagga.',
            'raw_response' => $rawResponse,
        ];
    }

    if ($httpCode < 200 || $httpCode >= 300 || !imaggaApiSuccess($decoded)) {
        return [
            'success' => false,
            'http_code' => $httpCode,
            'error' => (string)($decoded['status']['text'] ?? $decoded['result']['status']['text'] ?? 'Imagga request failed.'),
            'response' => $decoded,
        ];
    }

    return [
        'success' => true,
        'http_code' => $httpCode,
        'response' => $decoded,
    ];
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
        return [
            'success' => false,
            'error' => 'Item image file not found for Imagga indexing.',
        ];
    }

    $payload = [
        'image' => curl_file_create($imagePath, mime_content_type($imagePath) ?: 'image/jpeg', basename($imagePath)),
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
        return [
            'success' => false,
            'error' => 'Search image file not found.',
        ];
    }

    $multipartImage = curl_file_create($imagePath, mime_content_type($imagePath) ?: 'image/jpeg', basename($imagePath));
    $preferredResponse = imaggaMultipartRequest(
        'https://api.imagga.com/v2/images-similarity/' . rawurlencode($collectionId),
        $apiKey,
        $apiSecret,
        ['image' => $multipartImage],
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

    $fallbackResponse = imaggaMultipartRequest(
        'https://api.imagga.com/v2/similar-images/categories/' . rawurlencode($config['categorizer_id']) . '/' . rawurlencode($collectionId),
        $apiKey,
        $apiSecret,
        ['image' => curl_file_create($imagePath, mime_content_type($imagePath) ?: 'image/jpeg', basename($imagePath))],
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
            'error' => 'Imagga visual similarity is not configured.',
        ];
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

    $matches = mapImaggaMatchesToItems($db, $entries, $options);
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
    ensureItemsImaggaColumn($db);

    $config = getImaggaSimilarityConfig();
    if ($config['api_key'] === '' || $config['api_secret'] === '' || $config['collection_id'] === '') {
        return [
            'success' => false,
            'skipped' => true,
            'error' => 'Imagga visual similarity is not configured.',
        ];
    }

    if ($relativeImagePath === null || $relativeImagePath === '') {
        return [
            'success' => false,
            'skipped' => true,
            'error' => 'Item does not have an image to index.',
        ];
    }

    $stmt = $db->prepare('SELECT imagga_image_id FROM items WHERE item_id = ?');
    $stmt->execute([(int)$itemId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    $imageId = $existing['imagga_image_id'] ?? '';
    if ($imageId === '') {
        $imageId = 'item-' . (int)$itemId;
    }

    $absoluteImagePath = __DIR__ . '/../' . ltrim((string)$relativeImagePath, '/\\');
    $indexResult = addImageToImaggaCollection(
        $absoluteImagePath,
        $config['collection_id'],
        $config['api_key'],
        $config['api_secret'],
        $imageId,
        $config['categorizer_id']
    );

    if (!$indexResult['success']) {
        return $indexResult;
    }

    $storedImageId = (string)($indexResult['image_id'] ?? $imageId);
    $updateStmt = $db->prepare('UPDATE items SET imagga_image_id = ? WHERE item_id = ?');
    $updateStmt->execute([$storedImageId, (int)$itemId]);

    return [
        'success' => true,
        'image_id' => $storedImageId,
        'train_success' => $indexResult['train_success'] ?? false,
    ];
}
