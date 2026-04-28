<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mail.php';

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
            // Save to database - using correct column names
            $stmt = $this->db->prepare("
                INSERT INTO notifications (user_id, title, message, type, is_read, created_at) 
                VALUES (?, ?, ?, ?, 0, NOW())
            ");
            $stmt->execute([$userId, $title, $message, $type]);
            $notificationId = $this->db->lastInsertId();
            
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
            // Get user email and notification preferences
            $stmt = $this->db->prepare("
                SELECT email, name, email_notifications, notification_email 
                FROM users WHERE user_id = ?
            ");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            
            if (!$user || !$user['email_notifications']) {
                return false;
            }
            
            $toEmail = $user['notification_email'] ?? $user['email'];
            $emailBody = $this->getEmailTemplate($user['name'], $title, $message, $type);
            $subject = "[Reclaim System] " . $title;
            
            $result = MailConfig::sendNotification($toEmail, $subject, $emailBody);
            
            // Log email
            $this->logEmail($toEmail, $user['name'], $subject, $message, $type, $result ? 'sent' : 'failed');
            
            return $result;
        } catch (Exception $e) {
            error_log("Email failed: " . $e->getMessage());
            return false;
        }
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
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'];
        return $protocol . $host . '/reclaim-system/';
    }
}
?>
