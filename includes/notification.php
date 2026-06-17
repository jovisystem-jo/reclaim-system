<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/functions.php';

class NotificationSystem {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * Send notification to a user (database + email)
     */
    public function send($userId, $title, $message, $type = 'info', $sendEmail = true) {
        try {
            $notificationId = $this->insertNotificationRecord($userId, $title, $message, $type);
            
            // Send email if enabled
            if ($sendEmail) {
                $this->sendEmail($userId, $title, $message, $type);
            }
            
            return $notificationId;
        } catch (PDOException $e) {
            error_log("Notification error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Send email notification to user
     */
    private function sendEmail($userId, $title, $message, $type) {
        try {
            if (class_exists('EnvLoader')) {
                EnvLoader::load();
            }

            $user = $this->getUserNotificationRecipient($userId);
            
            if (!$user || !$user['email_notifications']) {
                return false;
            }
            
            $toEmail = $this->resolveNotificationEmail($user);
            $emailBody = $this->getEmailTemplate($user['name'], $title, $message, $type);
            $subject = "[Reclaim System] " . $title;
            
            $result = MailConfig::sendNotification($toEmail, $subject, $emailBody);
            if (!$result) {
                error_log('Notification email failed for ' . $toEmail . ': ' . MailConfig::getLastError());
            }
            
            // Log email
            $this->logEmail($toEmail, $user['name'], $subject, $message, $type, $result ? 'sent' : 'failed');
            
            return $result;
        } catch (Exception $e) {
            error_log("Email failed: " . $e->getMessage());
            return false;
        }
    }

    private function insertNotificationRecord($userId, $title, $message, $type) {
        $stmt = $this->db->prepare("
            INSERT INTO notifications (user_id, title, message, type, is_read, created_at)
            VALUES (?, ?, ?, ?, 0, NOW())
        ");
        $stmt->execute([(int) $userId, $title, $message, $type]);

        return $this->db->lastInsertId();
    }

    private function getUserNotificationRecipient($userId) {
        $stmt = $this->db->prepare("
            SELECT user_id, email, name, email_notifications, notification_email
            FROM users
            WHERE user_id = ?
            LIMIT 1
        ");
        $stmt->execute([(int) $userId]);

        return $stmt->fetch();
    }

    private function resolveNotificationEmail(array $user) {
        $notificationEmail = trim((string) ($user['notification_email'] ?? ''));
        if ($notificationEmail !== '') {
            return $notificationEmail;
        }

        return trim((string) ($user['email'] ?? ''));
    }
    
    /**
     * Send notification to all users
     */
    public function sendToAll($title, $message, $type = 'info', $role = null, $sendEmail = true) {
        $query = "SELECT user_id FROM users WHERE is_active = 1";
        $params = [];
        
        if ($role) {
            $query .= " AND role = ?";
            $params[] = $role;
        }
        
        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        $users = $stmt->fetchAll();
        
        $count = 0;
        foreach ($users as $user) {
            if ($this->send($user['user_id'], $title, $message, $type, $sendEmail)) {
                $count++;
            }
        }
        
        return $count;
    }
    
    /**
     * Send notification to all admins
     */
    public function sendToAdmins($title, $message, $type = 'info', $sendEmail = true) {
        return $this->sendToAll($title, $message, $type, 'admin', $sendEmail);
    }
    
    /**
     * Mark notification as read
     */
    public function markAsRead($notificationId, $userId) {
        $stmt = $this->db->prepare("
            UPDATE notifications 
            SET is_read = 1 
            WHERE notification_id = ? AND user_id = ?
        ");
        return $stmt->execute([$notificationId, $userId]);
    }
    
    /**
     * Mark all notifications as read for a user
     */
    public function markAllAsRead($userId) {
        $stmt = $this->db->prepare("
            UPDATE notifications 
            SET is_read = 1 
            WHERE user_id = ? AND is_read = 0
        ");
        return $stmt->execute([$userId]);
    }
    
    /**
     * Get unread notification count
     */
    public function getUnreadCount($userId) {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM notifications 
            WHERE user_id = ? AND is_read = 0
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchColumn();
    }
    
    /**
     * Get all notifications for a user
     */
    public function getNotifications($userId, $limit = 50, $offset = 0) {
        $stmt = $this->db->prepare("
            SELECT * FROM notifications 
            WHERE user_id = ? 
            ORDER BY created_at DESC 
            LIMIT ? OFFSET ?
        ");
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    /**
     * Delete old notifications
     */
    public function deleteOldNotifications($days = 30) {
        $stmt = $this->db->prepare("
            DELETE FROM notifications 
            WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            AND is_read = 1
        ");
        return $stmt->execute([$days]);
    }
    
    /**
     * Trigger when new claim is submitted
     */
    public function newClaimSubmitted($claimId, $itemId, $claimantId) {
        // Get claim details
        $stmt = $this->db->prepare("
            SELECT i.title, i.item_id, u.name as claimant_name
            FROM claim_requests c
            JOIN items i ON c.item_id = i.item_id
            JOIN users u ON c.claimant_id = u.user_id
            WHERE c.claim_id = ?
        ");
        $stmt->execute([$claimId]);
        $data = $stmt->fetch();
        
        if ($data) {
            // Notify admins
            $title = "📋 New Claim Submitted";
            $message = "{$data['claimant_name']} has submitted a claim for item: '{$data['title']}'. Please review and verify the claim.";
            $this->sendToAdmins($title, $message, 'warning');
        }
    }
    
    /**
     * Trigger when claim is verified
     */
    public function claimVerified($claimId, $status, $adminNotes = '') {
        $stmt = $this->db->prepare("
            SELECT c.*, i.title as item_title, u.email, u.name, u.user_id
            FROM claim_requests c
            JOIN items i ON c.item_id = i.item_id
            JOIN users u ON c.claimant_id = u.user_id
            WHERE c.claim_id = ?
        ");
        $stmt->execute([$claimId]);
        $claim = $stmt->fetch();
        
        if ($claim) {
            $statusText = $status == 'approved' ? '✅ Approved' : '❌ Rejected';
            $title = "Claim $statusText";
            $message = "Your claim for '{$claim['item_title']}' has been $status.";
            if ($adminNotes) {
                $message .= "\n\n📝 Admin Notes: $adminNotes";
            }
            
            $this->send($claim['user_id'], $title, $message, $status == 'approved' ? 'success' : 'danger');
        }
    }
    
    /**
     * Trigger when new item is reported
     */
    public function newItemReported($itemId, $reporterId, $status) {
        $stmt = $this->db->prepare("SELECT title FROM items WHERE item_id = ?");
        $stmt->execute([$itemId]);
        $item = $stmt->fetch();
        
        if ($item) {
            $title = $status == 'lost' ? "🔍 Lost Item Reported" : "📍 Found Item Reported";
            $message = "You have successfully reported a {$status} item: '{$item['title']}'. Our team will review it.";
            $this->send($reporterId, $title, $message, 'success');
        }
    }

    /**
     * Notify a newly registered user that their account is ready.
     */
    public function registrationSuccessful($userId, array $user = []) {
        $role = strtolower(trim((string) ($user['role'] ?? 'user')));
        $roleLabel = in_array($role, ['student', 'staff', 'admin'], true)
            ? ucfirst($role)
            : 'User';

        $title = 'Welcome to Reclaim System';
        $message = "Your {$roleLabel} account has been created successfully.";
        $message .= "\n\nYou can now sign in and start reporting lost or found items, tracking claims, and receiving updates from the system.";

        return $this->send((int) $userId, $title, $message, 'success', true);
    }

    /**
     * Compare a newly reported item against open opposite-status items and notify the matched user.
     */
    public function processAutomaticItemMatches($itemId) {
        require_once __DIR__ . '/item_matcher.php';

        $matcher = new AutomaticItemMatchService($this->db);
        $matches = $matcher->findMatchesForItem((int) $itemId);

        if (empty($matches)) {
            return 0;
        }

        $sourceItem = $this->getMatchItemById((int) $itemId);
        if (!$sourceItem) {
            return 0;
        }

        $notificationCount = 0;

        foreach ($matches as $match) {
            $matchedItem = $match['item'] ?? null;
            if (!is_array($matchedItem) || empty($matchedItem['item_id'])) {
                continue;
            }

            $upsert = $this->upsertItemSimilarityMatch($sourceItem, $matchedItem, $match);
            if (!$upsert['saved']) {
                continue;
            }

            if ($upsert['is_new']) {
                if ($this->sendAutomaticMatchNotification($sourceItem, $matchedItem, $match)) {
                    $notificationCount++;
                }
            }
        }

        return $notificationCount;
    }

    private function getMatchItemById($itemId) {
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
            WHERE item_id = ?
            LIMIT 1
        ");
        $stmt->execute([(int) $itemId]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function upsertItemSimilarityMatch(array $sourceItem, array $matchedItem, array $match) {
        $sourceItemId = (int) ($sourceItem['item_id'] ?? 0);
        $matchedItemId = (int) ($matchedItem['item_id'] ?? 0);
        $existingPair = $this->findExistingItemSimilarityPair($sourceItemId, $matchedItemId);
        $isDirectPair = !$existingPair
            || (
                (int) ($existingPair['source_item_id'] ?? 0) === $sourceItemId
                && (int) ($existingPair['matched_item_id'] ?? 0) === $matchedItemId
            );

        $payload = [
            'source_item_id' => $sourceItemId,
            'matched_item_id' => $matchedItemId,
            'source_status' => $isDirectPair ? (string) ($sourceItem['status'] ?? '') : (string) ($matchedItem['status'] ?? ''),
            'matched_status' => $isDirectPair ? (string) ($matchedItem['status'] ?? '') : (string) ($sourceItem['status'] ?? ''),
            'text_score' => (float) ($match['text_score'] ?? 0.0),
            'image_score' => (float) ($match['image_score'] ?? 0.0),
            'combined_score' => (float) ($match['combined_score'] ?? 0.0),
            'match_reason' => (string) ($match['match_reason'] ?? ''),
        ];

        try {
            if ($existingPair) {
                $stmt = $this->db->prepare("
                    UPDATE item_similarity_matches
                    SET
                        source_status = ?,
                        matched_status = ?,
                        text_score = ?,
                        image_score = ?,
                        combined_score = ?,
                        match_reason = ?,
                        updated_at = NOW()
                    WHERE match_id = ?
                ");
                $stmt->execute([
                    $payload['source_status'],
                    $payload['matched_status'],
                    $payload['text_score'],
                    $payload['image_score'],
                    $payload['combined_score'],
                    $payload['match_reason'],
                    (int) $existingPair['match_id'],
                ]);

                return ['saved' => true, 'is_new' => false];
            }

            $stmt = $this->db->prepare("
                INSERT INTO item_similarity_matches (
                    source_item_id,
                    matched_item_id,
                    source_status,
                    matched_status,
                    text_score,
                    image_score,
                    combined_score,
                    match_reason,
                    created_at,
                    updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $stmt->execute([
                $payload['source_item_id'],
                $payload['matched_item_id'],
                $payload['source_status'],
                $payload['matched_status'],
                $payload['text_score'],
                $payload['image_score'],
                $payload['combined_score'],
                $payload['match_reason'],
            ]);

            return ['saved' => true, 'is_new' => true];
        } catch (PDOException $e) {
            error_log('Item similarity upsert failed: ' . $e->getMessage());
            return ['saved' => false, 'is_new' => false];
        }
    }

    private function findExistingItemSimilarityPair($sourceItemId, $matchedItemId) {
        $stmt = $this->db->prepare("
            SELECT match_id, source_item_id, matched_item_id
            FROM item_similarity_matches
            WHERE (source_item_id = ? AND matched_item_id = ?)
               OR (source_item_id = ? AND matched_item_id = ?)
            LIMIT 1
        ");
        $stmt->execute([
            (int) $sourceItemId,
            (int) $matchedItemId,
            (int) $matchedItemId,
            (int) $sourceItemId,
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function sendAutomaticMatchNotification(array $sourceItem, array $matchedItem, array $match) {
        $matchedUserId = (int) ($matchedItem['owner_user_id'] ?? $matchedItem['reported_by'] ?? $matchedItem['user_id'] ?? 0);
        if ($matchedUserId <= 0) {
            return false;
        }

        $similarityPercent = (float) ($match['combined_score_percent'] ?? 0.0);
        $title = 'Possible Match Found';
        $message = 'A similar item may match your report "' . (string) ($matchedItem['title'] ?? 'Untitled item') . '" with ' . $this->formatPercent($similarityPercent) . '% similarity.';

        try {
            $this->insertNotificationRecord($matchedUserId, $title, $message, 'match');
        } catch (PDOException $e) {
            error_log('Automatic match notification insert failed: ' . $e->getMessage());
            return false;
        }

        try {
            $this->sendAutomaticMatchEmail($matchedUserId, $sourceItem, $matchedItem, $similarityPercent);
        } catch (Throwable $e) {
            error_log('Automatic match email failed: ' . $e->getMessage());
        }

        return true;
    }

    private function sendAutomaticMatchEmail($userId, array $sourceItem, array $matchedItem, $similarityPercent) {
        if (class_exists('EnvLoader')) {
            EnvLoader::load();
        }

        $user = $this->getUserNotificationRecipient($userId);
        if (!$user || !$user['email_notifications']) {
            return false;
        }

        $toEmail = $this->resolveNotificationEmail($user);
        if ($toEmail === '') {
            return false;
        }

        $subject = 'Possible Match Found for Your Lost/Found Item';
        $emailBody = $this->getAutomaticMatchEmailTemplate($user, $sourceItem, $matchedItem, $similarityPercent);
        $result = MailConfig::sendNotification($toEmail, $subject, $emailBody);

        if (!$result) {
            error_log('Automatic match email delivery failed for user ' . (int) $userId . ' (' . $toEmail . '): ' . MailConfig::getLastError());
        }

        $this->logEmail(
            $toEmail,
            (string) ($user['name'] ?? ''),
            $subject,
            'Reported item: ' . (string) ($sourceItem['title'] ?? 'Untitled item')
                . ' | Matched item: ' . (string) ($matchedItem['title'] ?? 'Untitled item')
                . ' | Similarity: ' . $this->formatPercent($similarityPercent) . '%',
            'match',
            $result ? 'sent' : 'failed'
        );

        return $result;
    }

    private function getAutomaticMatchEmailTemplate(array $user, array $sourceItem, array $matchedItem, $similarityPercent) {
        $userName = htmlspecialchars((string) ($user['name'] ?? 'User'));
        $sourceTitle = htmlspecialchars((string) ($sourceItem['title'] ?? 'Untitled item'));
        $matchedTitle = htmlspecialchars((string) ($matchedItem['title'] ?? 'Untitled item'));
        $similarityText = htmlspecialchars($this->formatPercent($similarityPercent));
        $dashboardUrl = htmlspecialchars($this->getBaseUrl());

        return '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Possible Match Found</title>
            <style>
                body {
                    font-family: "Segoe UI", Arial, sans-serif;
                    background: #f5f7fb;
                    margin: 0;
                    padding: 24px 0;
                }
                .email-container {
                    max-width: 640px;
                    margin: 0 auto;
                    background: #ffffff;
                    border-radius: 18px;
                    overflow: hidden;
                    box-shadow: 0 12px 30px rgba(0,0,0,0.08);
                }
                .email-header {
                    background: linear-gradient(135deg, #FF6B35, #E85D2C);
                    color: #ffffff;
                    padding: 28px 32px;
                }
                .email-header h1 {
                    margin: 0;
                    font-size: 24px;
                }
                .email-body {
                    padding: 32px;
                    color: #334155;
                    line-height: 1.6;
                }
                .summary-card {
                    background: #f8fafc;
                    border: 1px solid #e2e8f0;
                    border-radius: 14px;
                    padding: 18px 20px;
                    margin: 24px 0;
                }
                .summary-card p {
                    margin: 8px 0;
                }
                .button {
                    display: inline-block;
                    margin-top: 8px;
                    background: #FF6B35;
                    color: #ffffff;
                    text-decoration: none;
                    padding: 12px 22px;
                    border-radius: 999px;
                    font-weight: 600;
                }
                .footer {
                    padding: 20px 32px 28px;
                    color: #64748b;
                    font-size: 12px;
                }
            </style>
        </head>
        <body>
            <div class="email-container">
                <div class="email-header">
                    <h1>Possible Match Found</h1>
                </div>
                <div class="email-body">
                    <p>Hello ' . $userName . ',</p>
                    <p>A possible match has been found for your item report.</p>
                    <div class="summary-card">
                        <p><strong>Reported item:</strong> ' . $sourceTitle . '</p>
                        <p><strong>Matched item:</strong> ' . $matchedTitle . '</p>
                        <p><strong>Similarity score:</strong> ' . $similarityText . '%</p>
                    </div>
                    <p>Please log in to the RECLAIM System to review the matched item details.</p>
                    <a href="' . $dashboardUrl . '" class="button">Open RECLAIM System</a>
                </div>
                <div class="footer">
                    Thank you,<br>
                    RECLAIM System
                </div>
            </div>
        </body>
        </html>
        ';
    }

    /**
     * Notify a lost-item reporter when similar open found items already exist
     */
    public function notifySimilarFoundItemsForLostReport($lostItemId, $userId, $limit = 3) {
        $stmt = $this->db->prepare("
            SELECT item_id, title, description, category, brand, color, found_location, date_found, image_url
            FROM items
            WHERE item_id = ? AND status = 'lost' AND user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$lostItemId, $userId]);
        $lostItem = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$lostItem) {
            return 0;
        }

        $matches = $this->findSimilarFoundItems($lostItem, $limit);
        if (empty($matches)) {
            return 0;
        }

        $matchCount = count($matches);
        $title = $matchCount === 1 ? 'Similar Found Item Detected' : 'Similar Found Items Detected';
        $messageLines = [
            "We found {$matchCount} similar found item" . ($matchCount === 1 ? '' : 's') . " for your lost report '{$lostItem['title']}'.",
            '',
            'Possible matches:'
        ];

        foreach ($matches as $index => $match) {
            $summaryParts = [];
            if (!empty($match['brand'])) {
                $summaryParts[] = 'Brand: ' . $match['brand'];
            }
            if (!empty($match['color'])) {
                $summaryParts[] = 'Color: ' . $match['color'];
            }
            if (!empty($match['found_location'])) {
                $summaryParts[] = 'Location: ' . $match['found_location'];
            }
            if (!empty($match['date_found'])) {
                $summaryParts[] = 'Date: ' . date('M d, Y', strtotime($match['date_found']));
            }

            $messageLines[] = ($index + 1) . '. ' . ($match['title'] ?: 'Untitled item') . ' (Item #' . $match['item_id'] . ')';
            if (!empty($summaryParts)) {
                $messageLines[] = '   ' . implode(' | ', $summaryParts);
            }
        }

        $messageLines[] = '';
        $messageLines[] = 'Please review these items in Search Items or in your reports dashboard.';

        $this->send($userId, $title, implode("\n", $messageLines), 'info');

        return $matchCount;
    }

    /**
     * Find the strongest open found-item matches for a lost report
     */
    private function findSimilarFoundItems($lostItem, $limit = 3) {
        $stmt = $this->db->prepare("
            SELECT item_id, title, description, category, brand, color, found_location, date_found, reported_date
            FROM items
            WHERE status = 'found' AND category = ?
            ORDER BY reported_date DESC
            LIMIT 75
        ");
        $stmt->execute([$lostItem['category']]);
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($candidates)) {
            return [];
        }

        $matchFields = ['title', 'description', 'brand', 'color', 'found_location'];
        $lostTokens = extractItemTextMatchTokens($lostItem, $matchFields);
        $imageScores = $this->getImaggaScoresForLostItem($lostItem, min(25, max(10, (int)$limit * 5)));
        $matches = [];

        foreach ($candidates as $candidate) {
            $candidateTokens = extractItemTextMatchTokens($candidate, $matchFields);
            $textScore = calculateJaccardSimilarity($lostTokens, $candidateTokens);
            $imageScore = (float)($imageScores[(int)($candidate['item_id'] ?? 0)] ?? 0.0);
            $combinedScore = $this->combineMatchScores($textScore, $imageScore);

            if (!$this->qualifiesAsSimilarFoundItem($textScore, $imageScore, $combinedScore)) {
                continue;
            }

            $candidate['_match_score'] = $combinedScore;
            $candidate['_text_similarity'] = $textScore;
            $candidate['_image_similarity'] = $imageScore;
            $matches[] = $candidate;
        }

        usort($matches, function ($left, $right) {
            $leftScore = (float)($left['_match_score'] ?? 0);
            $rightScore = (float)($right['_match_score'] ?? 0);

            if ($leftScore === $rightScore) {
                $leftImageScore = (float)($left['_image_similarity'] ?? 0);
                $rightImageScore = (float)($right['_image_similarity'] ?? 0);

                if ($leftImageScore !== $rightImageScore) {
                    return $rightImageScore <=> $leftImageScore;
                }

                return strcmp((string) ($right['reported_date'] ?? ''), (string) ($left['reported_date'] ?? ''));
            }

            return $rightScore <=> $leftScore;
        });

        return array_slice($matches, 0, $limit);
    }

    /**
     * Pull Imagga similarity scores for candidate found items when the lost report has an image.
     */
    private function getImaggaScoresForLostItem(array $lostItem, $limit = 15) {
        require_once __DIR__ . '/imagga_similarity.php';

        try {
            $imaggaMatches = findSimilarItemsWithImaggaForItem($this->db, $lostItem, [
                'statuses' => ['found'],
                'limit' => max(1, (int)$limit),
            ]);
        } catch (Throwable $e) {
            error_log('Imagga similar-item lookup failed for lost item ' . (int)($lostItem['item_id'] ?? 0) . ': ' . $e->getMessage());
            return [];
        }

        if (!$imaggaMatches['success']) {
            return [];
        }

        $scores = [];
        foreach ($imaggaMatches['matched_items'] as $match) {
            $itemId = (int)($match['item_id'] ?? 0);
            if ($itemId <= 0) {
                continue;
            }

            $scores[$itemId] = max((float)($scores[$itemId] ?? 0.0), (float)($match['similarity_score'] ?? 0.0));
        }

        return $scores;
    }

    /**
     * Blend text Jaccard similarity with Imagga image similarity when available.
     */
    private function combineMatchScores($textScore, $imageScore) {
        $textScore = max(0.0, min(1.0, (float)$textScore));
        $imageScore = max(0.0, min(1.0, (float)$imageScore));

        if ($imageScore > 0.0) {
            return round(($imageScore * 0.65) + ($textScore * 0.35), 6);
        }

        return round($textScore, 6);
    }

    /**
     * Keep only meaningful matches so notifications stay useful.
     */
    private function qualifiesAsSimilarFoundItem($textScore, $imageScore, $combinedScore) {
        return (float)$imageScore >= 0.45
            || (float)$textScore >= 0.18
            || (float)$combinedScore >= 0.25;
    }
    
    /**
     * Log email to database
     */
    private function logEmail($to, $toName, $subject, $message, $type, $status) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO email_logs (recipient_email, recipient_name, subject, message, type, status, sent_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$to, $toName, $subject, $message, $type, $status]);
        } catch (PDOException $e) {
            // Silent fail - don't break the email sending if logging fails
        }
    }

    private function formatPercent($score) {
        $formatted = number_format((float) $score, 2, '.', '');
        return rtrim(rtrim($formatted, '0'), '.');
    }
    
    /**
     * Get email template
     */
    private function getEmailTemplate($name, $title, $message, $type) {
        $typeColors = [
            'info' => '#3498DB',
            'success' => '#27AE60',
            'warning' => '#F39C12',
            'danger' => '#E74C3C'
        ];
        $color = $typeColors[$type] ?? '#FF6B35';
        
        $icon = $type == 'success' ? '✅' : ($type == 'warning' ? '⚠️' : ($type == 'danger' ? '❌' : 'ℹ️'));
        
        return '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>' . htmlspecialchars($title) . '</title>
            <style>
                body {
                    font-family: "Inter", "Segoe UI", Arial, sans-serif;
                    background-color: #f0f2f5;
                    margin: 0;
                    padding: 0;
                }
                .email-container {
                    max-width: 600px;
                    margin: 20px auto;
                    background: white;
                    border-radius: 20px;
                    overflow: hidden;
                    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
                }
                .email-header {
                    background: linear-gradient(135deg, #FF6B35, #E85D2C);
                    padding: 30px;
                    text-align: center;
                    color: white;
                }
                .email-header i {
                    font-size: 48px;
                    margin-bottom: 10px;
                }
                .email-header h1 {
                    margin: 0;
                    font-size: 24px;
                }
                .email-body {
                    padding: 30px;
                }
                .greeting {
                    font-size: 18px;
                    font-weight: 600;
                    margin-bottom: 20px;
                    color: #2C3E50;
                }
                .notification-badge {
                    display: inline-block;
                    background: ' . $color . ';
                    color: white;
                    padding: 5px 15px;
                    border-radius: 50px;
                    font-size: 12px;
                    font-weight: 600;
                    margin-bottom: 15px;
                }
                .message-content {
                    color: #6c757d;
                    line-height: 1.6;
                    margin-bottom: 25px;
                    padding: 15px;
                    background: #f8f9fa;
                    border-radius: 10px;
                }
                .button {
                    display: inline-block;
                    background: linear-gradient(135deg, #FF6B35, #E85D2C);
                    color: white;
                    text-decoration: none;
                    padding: 12px 30px;
                    border-radius: 50px;
                    font-weight: 600;
                    margin: 10px 0;
                }
                .footer {
                    background: #f8f9fa;
                    padding: 20px;
                    text-align: center;
                    font-size: 12px;
                    color: #999;
                }
                .divider {
                    height: 1px;
                    background: #e0e0e0;
                    margin: 20px 0;
                }
            </style>
        </head>
        <body>
            <div class="email-container">
                <div class="email-header">
                    <i class="fas fa-recycle"></i>
                    <h1>Reclaim System</h1>
                    <p>Lost & Found Management System</p>
                </div>
                <div class="email-body">
                    <div class="greeting">Hello ' . htmlspecialchars($name) . ',</div>
                    <div class="notification-badge">' . $icon . ' ' . ucfirst($type) . ' Notification</div>
                    <div class="message-content">
                        ' . nl2br(htmlspecialchars($message)) . '
                    </div>
                    <div style="text-align: center;">
                        <a href="' . $this->getBaseUrl() . '" class="button">Go to Dashboard</a>
                    </div>
                    <div class="divider"></div>
                    <div style="font-size: 13px; color: #999;">
                        <strong>Need help?</strong> Contact support at <a href="mailto:support@reclaim-system.com">support@reclaim-system.com</a>
                    </div>
                </div>
                <div class="footer">
                    &copy; ' . date('Y') . ' Reclaim System. All rights reserved.<br>
                    This is an automated message, please do not reply.
                </div>
            </div>
        </body>
        </html>
        ';
    }
    
    /**
     * Get base URL for links
     */
    private function getBaseUrl() {
        return app_base_url();
    }
}
?>
