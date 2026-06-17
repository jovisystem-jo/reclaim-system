<?php
require_once __DIR__ . '/functions.php';

class AutomaticItemMatchService {
    private $db;
    private $pythonCommand;
    private $imageComparisonUnavailableReason = '';
    private static $dependencyIssueLogged = false;

    public function __construct(PDO $db) {
        $this->db = $db;

        if (class_exists('EnvLoader')) {
            EnvLoader::load();
        }

        $this->pythonCommand = $this->findPythonCommand();
    }

    public function findMatchesForItem($itemId) {
        $stmt = $this->db->prepare("
            SELECT
                item_id,
                user_id,
                reported_by,
                COALESCE(reported_by, user_id) AS owner_user_id,
                title,
                description,
                category,
                brand,
                color,
                location,
                found_location,
                delivery_location,
                date_found,
                status,
                image_url,
                image_tags,
                reported_date
            FROM items
            WHERE item_id = ? AND status IN ('lost', 'found')
            LIMIT 1
        ");
        $stmt->execute([(int) $itemId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            return [];
        }

        return $this->findMatchesForSourceItem($item);
    }

    public function findMatchesForSourceItem(array $sourceItem) {
        $sourceStatus = strtolower((string) ($sourceItem['status'] ?? ''));
        if (!in_array($sourceStatus, ['lost', 'found'], true)) {
            return [];
        }

        $sourceFamilies = $this->detectObjectFamiliesFromItem($sourceItem);
        if (empty($sourceFamilies)) {
            error_log('Automatic item matching skipped: unable to determine object family for item ' . (int) ($sourceItem['item_id'] ?? 0));
            return [];
        }

        $sourceTokens = $this->extractMeaningfulTokensFromItem($sourceItem, $sourceFamilies);
        $targetStatus = $sourceStatus === 'lost' ? 'found' : 'lost';

        $stmt = $this->db->prepare("
            SELECT
                item_id,
                user_id,
                reported_by,
                COALESCE(reported_by, user_id) AS owner_user_id,
                title,
                description,
                category,
                brand,
                color,
                location,
                found_location,
                delivery_location,
                date_found,
                status,
                image_url,
                image_tags,
                reported_date
            FROM items
            WHERE status = ? AND item_id <> ?
            ORDER BY reported_date DESC, item_id DESC
        ");
        $stmt->execute([$targetStatus, (int) ($sourceItem['item_id'] ?? 0)]);
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $matches = [];

        foreach ($candidates as $candidate) {
            $candidateFamilies = $this->detectObjectFamiliesFromItem($candidate);
            $sharedFamilies = array_values(array_intersect($sourceFamilies, $candidateFamilies));

            if (empty($sharedFamilies)) {
                continue;
            }

            $candidateTokens = $this->extractMeaningfulTokensFromItem($candidate, $candidateFamilies);
            $textScorePercent = round(calculateJaccardSimilarity($sourceTokens, $candidateTokens) * 100, 2);
            $sharedMeaningfulTokens = $this->getSharedMeaningfulTokens($sourceTokens, $candidateTokens);

            $imageMetrics = $this->calculateVisualSimilarityBetweenItems($sourceItem, $candidate);
            $imageScorePercent = round((float) ($imageMetrics['similarity'] ?? 0.0), 2);
            $combinedScorePercent = $this->calculateCombinedScore($textScorePercent, $imageScorePercent);

            if ($combinedScorePercent < 50.0) {
                continue;
            }

            if ($imageScorePercent <= 0.0 && empty($sharedMeaningfulTokens)) {
                continue;
            }

            $matches[] = [
                'item' => $candidate,
                'shared_families' => $sharedFamilies,
                'shared_tokens' => $sharedMeaningfulTokens,
                'text_score' => $this->normalizeStoredScore($textScorePercent),
                'image_score' => $this->normalizeStoredScore($imageScorePercent),
                'combined_score' => $this->normalizeStoredScore($combinedScorePercent),
                'text_score_percent' => $textScorePercent,
                'image_score_percent' => $imageScorePercent,
                'combined_score_percent' => $combinedScorePercent,
                'match_reason' => $this->buildMatchReason(
                    $sharedFamilies,
                    $sharedMeaningfulTokens,
                    $textScorePercent,
                    $imageScorePercent,
                    $combinedScorePercent
                ),
                'image_metrics' => $imageMetrics,
            ];
        }

        usort($matches, function ($left, $right) {
            $combinedComparison = ((float) ($right['combined_score_percent'] ?? 0)) <=> ((float) ($left['combined_score_percent'] ?? 0));
            if ($combinedComparison !== 0) {
                return $combinedComparison;
            }

            $imageComparison = ((float) ($right['image_score_percent'] ?? 0)) <=> ((float) ($left['image_score_percent'] ?? 0));
            if ($imageComparison !== 0) {
                return $imageComparison;
            }

            return strcmp((string) ($right['item']['reported_date'] ?? ''), (string) ($left['item']['reported_date'] ?? ''));
        });

        return $matches;
    }

    private function extractMeaningfulTokensFromItem(array $item, array $families) {
        $fields = ['title', 'description', 'category', 'brand', 'color', 'image_tags'];
        $segments = [];

        foreach ($fields as $field) {
            $value = normalizeTextMatchValue($item[$field] ?? '');
            if ($value === '' || in_array($value, $this->getIgnoredFieldValues(), true)) {
                continue;
            }

            $segments[] = $value;
        }

        $tokens = extractTextMatchTokens(implode(' ', $segments), $this->getGenericMatchStopWords());

        foreach ($families as $family) {
            $tokens[] = 'family_' . preg_replace('/[^a-z0-9]+/', '_', strtolower((string) $family));
        }

        return array_values(array_unique(array_filter($tokens)));
    }

    private function getSharedMeaningfulTokens(array $sourceTokens, array $candidateTokens) {
        $sharedTokens = array_values(array_intersect($sourceTokens, $candidateTokens));

        return array_values(array_filter($sharedTokens, function ($token) {
            return strpos((string) $token, 'family_') !== 0;
        }));
    }

    private function calculateCombinedScore($textScorePercent, $imageScorePercent) {
        $scores = [
            max(0.0, min(100.0, (float) $textScorePercent)),
            max(0.0, min(100.0, (float) $imageScorePercent)),
        ];

        return round(max($scores), 2);
    }

    private function normalizeStoredScore($percentScore) {
        $normalizedScore = max(0.0, min(0.999999, ((float) $percentScore) / 100));
        return round($normalizedScore, 6);
    }

    private function detectObjectFamiliesFromItem(array $item) {
        $segments = array_filter([
            (string) ($item['title'] ?? ''),
            (string) ($item['description'] ?? ''),
            (string) ($item['category'] ?? ''),
            (string) ($item['brand'] ?? ''),
            (string) ($item['image_tags'] ?? ''),
        ], function ($value) {
            return trim((string) $value) !== '';
        });

        $normalizedText = normalizeTextMatchValue(implode(' ', $segments));
        if ($normalizedText === '') {
            return [];
        }

        $families = [];

        foreach ($this->getObjectFamilyAliases() as $family => $aliases) {
            foreach ($aliases as $alias) {
                if ($this->phraseContainsAlias($normalizedText, $alias)) {
                    $families[$family] = true;
                    break;
                }
            }
        }

        return array_keys($families);
    }

    private function phraseContainsAlias($text, $alias) {
        $normalizedText = ' ' . normalizeTextMatchValue((string) $text) . ' ';
        $normalizedAlias = normalizeTextMatchValue((string) $alias);

        if ($normalizedAlias === '') {
            return false;
        }

        return strpos($normalizedText, ' ' . $normalizedAlias . ' ') !== false;
    }

    private function calculateVisualSimilarityBetweenItems(array $sourceItem, array $candidateItem) {
        $defaultMetrics = [
            'similarity' => 0.0,
            'orb_score' => 0.0,
            'histogram_score' => 0.0,
            'shape_score' => 0.0,
            'verified_matches' => 0,
            'keypoints_image1' => 0,
            'keypoints_image2' => 0,
        ];

        $sourceImagePath = $this->resolveProjectImagePath((string) ($sourceItem['image_url'] ?? ''));
        $candidateImagePath = $this->resolveProjectImagePath((string) ($candidateItem['image_url'] ?? ''));

        if ($sourceImagePath === null || $candidateImagePath === null) {
            return $defaultMetrics;
        }

        if ($this->pythonCommand === null) {
            $this->logDependencyIssueOnce(
                $this->imageComparisonUnavailableReason !== ''
                    ? $this->imageComparisonUnavailableReason
                    : 'Automatic item matching OpenCV skipped: Python/OpenCV runtime is unavailable.'
            );
            return $defaultMetrics;
        }

        $scriptPath = realpath(__DIR__ . '/../api/compare.py');
        if ($scriptPath === false || !is_file($scriptPath)) {
            error_log('Automatic item matching OpenCV skipped: compare.py was not found.');
            return $defaultMetrics;
        }

        try {
            $result = $this->runCommand(array_merge($this->pythonCommand, [$scriptPath, $sourceImagePath, $candidateImagePath]));
        } catch (Throwable $exception) {
            error_log('Automatic item matching compare.py execution failed: ' . $exception->getMessage());
            return $defaultMetrics;
        }

        $payload = $this->decodeComparatorPayload((string) ($result['stdout'] ?? ''), (string) ($result['stderr'] ?? ''));
        if (!is_array($payload)) {
            error_log('Automatic item matching compare.py returned invalid output.');
            return $defaultMetrics;
        }

        if (!empty($payload['error'])) {
            error_log('Automatic item matching compare.py error: ' . $payload['error']);
        }

        return [
            'similarity' => round(max(0.0, min(100.0, (float) ($payload['similarity'] ?? 0.0))), 2),
            'orb_score' => round(max(0.0, min(100.0, (float) ($payload['orb_score'] ?? 0.0))), 2),
            'histogram_score' => round(max(0.0, min(100.0, (float) ($payload['histogram_score'] ?? ($payload['hist_score'] ?? 0.0)))), 2),
            'shape_score' => round(max(0.0, min(100.0, (float) ($payload['shape_score'] ?? 0.0))), 2),
            'verified_matches' => max(0, (int) ($payload['verified_matches'] ?? 0)),
            'keypoints_image1' => max(0, (int) ($payload['keypoints_image1'] ?? ($payload['features1'] ?? 0))),
            'keypoints_image2' => max(0, (int) ($payload['keypoints_image2'] ?? ($payload['features2'] ?? 0))),
        ];
    }

    private function resolveProjectImagePath($relativePath) {
        $relativePath = trim((string) $relativePath);
        if ($relativePath === '') {
            return null;
        }

        $projectRoot = realpath(__DIR__ . '/..');
        if ($projectRoot === false) {
            return null;
        }

        $normalizedRelativePath = ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath), DIRECTORY_SEPARATOR);
        if ($normalizedRelativePath === '') {
            return null;
        }

        $candidatePath = $projectRoot . DIRECTORY_SEPARATOR . $normalizedRelativePath;
        $resolvedPath = realpath($candidatePath);

        if ($resolvedPath === false || !is_file($resolvedPath)) {
            return null;
        }

        $projectRootPrefix = rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (strpos($resolvedPath, $projectRootPrefix) !== 0 && $resolvedPath !== $projectRoot) {
            return null;
        }

        return $resolvedPath;
    }

    private function decodeComparatorPayload($stdout, $stderr) {
        foreach ([trim((string) $stdout), trim((string) $stderr), trim((string) $stdout . PHP_EOL . $stderr)] as $stream) {
            if ($stream === '') {
                continue;
            }

            $decoded = json_decode($stream, true);
            if (is_array($decoded)) {
                return $decoded;
            }

            $lines = preg_split('/\R+/', $stream) ?: [];
            foreach (array_reverse($lines) as $line) {
                $decoded = json_decode(trim((string) $line), true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        return null;
    }

    private function findPythonCommand() {
        if (!$this->canExecuteShellCommands()) {
            $this->imageComparisonUnavailableReason = 'Automatic item matching OpenCV skipped: shell execution is unavailable on this server.';
            return null;
        }

        $configuredPython = trim((string) getenv('PYTHON_PATH'));

        $candidates = array_merge(
            array_values(array_filter([
                $configuredPython !== '' ? [$configuredPython] : null,
                ['python'],
                ['python3'],
                ['python3.12'],
                ['python3.11'],
                ['python3.10'],
                ['python3.9'],
                ['py', '-3'],
                ['/usr/bin/python3'],
                ['/usr/local/bin/python3'],
                ['/usr/bin/python'],
                ['/usr/local/bin/python'],
                ['/opt/cpanel/ea-python312/root/usr/bin/python3'],
                ['/opt/cpanel/ea-python311/root/usr/bin/python3'],
                ['/opt/cpanel/ea-python39/root/usr/bin/python3'],
                ['C:\\Python39\\python.exe'],
                ['C:\\Python310\\python.exe'],
                ['C:\\Python311\\python.exe'],
                ['C:\\Python312\\python.exe'],
                ['C:\\Python313\\python.exe'],
                ['C:\\Python314\\python.exe'],
                ['C:\\Users\\' . getenv('USERNAME') . '\\AppData\\Local\\Programs\\Python\\Python312\\python.exe'],
            ])),
            $this->discoverWindowsPythonCandidates()
        );

        foreach ($candidates as $candidate) {
            if ($this->pythonSupportsImageComparison($candidate)) {
                return $candidate;
            }
        }

        $this->imageComparisonUnavailableReason = 'Automatic item matching OpenCV skipped: Python with OpenCV and NumPy could not be found.';
        return null;
    }

    private function discoverWindowsPythonCandidates() {
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

    private function pythonSupportsImageComparison(array $pythonCommand) {
        try {
            $result = $this->runCommand(
                array_merge($pythonCommand, ['-c', 'import cv2, numpy; print(12345)'])
            );
        } catch (Throwable $exception) {
            return false;
        }

        $combinedOutput = trim(((string) ($result['stdout'] ?? '')) . ' ' . ((string) ($result['stderr'] ?? '')));

        return (int) ($result['exit_code'] ?? 1) === 0
            && strpos($combinedOutput, '12345') !== false;
    }

    private function canExecuteShellCommands() {
        return function_exists('proc_open')
            && function_exists('proc_close')
            && function_exists('stream_get_contents')
            && function_exists('escapeshellarg');
    }

    private function runCommand(array $commandParts) {
        if (!$this->canExecuteShellCommands()) {
            throw new RuntimeException('Shell execution is unavailable.');
        }

        $command = implode(' ', array_map(function ($part) {
            return escapeshellarg((string) $part);
        }, $commandParts));

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $pipes = [];
        $process = @proc_open($command, $descriptors, $pipes, __DIR__);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start process.');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [
            'stdout' => (string) $stdout,
            'stderr' => (string) $stderr,
            'exit_code' => (int) $exitCode,
        ];
    }

    private function logDependencyIssueOnce($message) {
        $message = trim((string) $message);

        if ($message === '' || self::$dependencyIssueLogged) {
            return;
        }

        self::$dependencyIssueLogged = true;
        error_log($message);
    }

    private function buildMatchReason(array $sharedFamilies, array $sharedTokens, $textScorePercent, $imageScorePercent, $combinedScorePercent) {
        $reasonParts = [
            'Object family match: ' . implode(', ', $sharedFamilies),
            'Combined score: ' . $this->formatPercent($combinedScorePercent) . '%',
            'Text score: ' . $this->formatPercent($textScorePercent) . '%',
            'Image score: ' . $this->formatPercent($imageScorePercent) . '%',
        ];

        if (!empty($sharedTokens)) {
            $reasonParts[] = 'Shared terms: ' . implode(', ', array_slice($sharedTokens, 0, 6));
        }

        return implode(' | ', $reasonParts);
    }

    private function formatPercent($score) {
        $formatted = number_format((float) $score, 2, '.', '');
        return rtrim(rtrim($formatted, '0'), '.');
    }

    private function getIgnoredFieldValues() {
        return [
            'other',
            'generic',
            'no brand',
            'not specified',
            'n a',
            'na',
            'none',
            'null',
        ];
    }

    private function getGenericMatchStopWords() {
        return [
            'object',
            'item',
            'thing',
            'product',
            'container',
            'case',
            'label',
            'colour',
            'color',
            'black',
            'white',
            'red',
            'blue',
            'green',
            'yellow',
            'pink',
            'purple',
            'orange',
            'brown',
            'grey',
            'gray',
            'silver',
            'gold',
            'multicolor',
            'electronics',
            'documents',
            'accessories',
            'clothing',
            'books',
            'household',
            'others',
            'other',
        ];
    }

    private function getObjectFamilyAliases() {
        return [
            'bottle_household' => ['bottle', 'water bottle', 'flask', 'tumbler', 'thermos', 'vessel'],
            'wallet' => ['wallet', 'purse', 'card holder', 'cardholder', 'billfold', 'cash', 'money', 'currency'],
            'earbuds_audio' => ['earpod', 'earpods', 'earbud', 'earbuds', 'airpod', 'airpods', 'earphone', 'earphones', 'headphones', 'headphone', 'headset', 'charging case'],
            'phone' => ['phone', 'smartphone', 'mobile phone', 'cell phone', 'iphone', 'android phone'],
            'laptop' => ['laptop', 'notebook computer', 'macbook'],
            'charging_accessory' => ['charger', 'cable', 'adapter', 'powerbank', 'power bank'],
            'id_card' => ['student card', 'matric card', 'id card', 'identity card', 'student id'],
            'key' => ['key', 'keys', 'keychain', 'key chain', 'key ring', 'keyring', 'car key', 'house key'],
            'bag' => ['backpack', 'handbag', 'tote bag', 'sling bag', 'pouch', 'luggage', 'bag', 'briefcase'],
            'watch' => ['watch', 'wristwatch', 'smartwatch'],
            'eyewear' => ['glasses', 'spectacles', 'sunglasses', 'eyeglasses'],
            'book_document_storage' => ['book', 'textbook', 'notebook', 'folder', 'file', 'binder'],
            'jewelry' => ['bracelet', 'ring', 'necklace', 'earrings', 'earring', 'chain', 'pendant'],
            'clothing' => ['shirt', 'hoodie', 'jacket', 'pants', 'shoes', 'shoe', 'uniform', 'lab coat'],
        ];
    }
}
?>
