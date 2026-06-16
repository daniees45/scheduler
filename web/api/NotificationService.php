<?php
/**
 * Advanced Notification System
 * Supports Email, SMS, and In-App notifications with user preferences
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../../vendor/autoload.php';

class NotificationService {
    
    private $conn;
    private $config;
    
    // Channel types
    const CHANNEL_EMAIL = 'email';
    const CHANNEL_SMS = 'sms';
    const CHANNEL_IN_APP = 'in_app';
    
    // Notification types
    const TYPE_SCHEDULE_GENERATED = 'schedule_generated';
    const TYPE_CONFLICT_DETECTED = 'conflict_detected';
    const TYPE_SCHEDULE_RELEASED = 'schedule_released';
    const TYPE_REMINDER = 'reminder';
    const TYPE_SYSTEM_ALERT = 'system_alert';
    
    public function __construct(mysqli $conn, array $config = []) {
        $this->conn = $conn;
        $this->config = array_merge($this->getDefaultConfig(), $config);
        $this->ensureNotificationTables();
    }
    
    /**
     * Get default configuration
     */
    private function getDefaultConfig() {
        $gmailEmail = getenv('GMAIL_APP_EMAIL') ?: '';
        $gmailAppPassword = getenv('GMAIL_APP_PASSWORD') ?: '';
        $useGmail = $gmailEmail !== '' && $gmailAppPassword !== '';

        return [
            'smtp_host' => $useGmail ? 'smtp.gmail.com' : (getenv('SMTP_HOST') ?: 'smtp.mailtrap.io'),
            'smtp_port' => $useGmail ? 587 : (getenv('SMTP_PORT') ?: 465),
            'smtp_user' => $useGmail ? $gmailEmail : (getenv('SMTP_USER') ?: ''),
            'smtp_pass' => $useGmail ? $gmailAppPassword : (getenv('SMTP_PASS') ?: ''),
            'smtp_from' => getenv('SMTP_FROM') ?: ($useGmail ? $gmailEmail : 'noreply@vvuscheduler.local'),
            'sms_api_key' => getenv('SMS_API_KEY') ?: '',
            'sms_provider' => getenv('SMS_PROVIDER') ?: 'twilio',
            'enable_email' => getenv('ENABLE_EMAIL_NOTIFICATIONS') !== false ? filter_var(getenv('ENABLE_EMAIL_NOTIFICATIONS'), FILTER_VALIDATE_BOOLEAN) : true,
            'enable_sms' => getenv('ENABLE_SMS_NOTIFICATIONS') !== false ? filter_var(getenv('ENABLE_SMS_NOTIFICATIONS'), FILTER_VALIDATE_BOOLEAN) : false,
            'enable_in_app' => true
        ];
    }
    
    /**
     * Ensure notification tables exist
     */
    private function ensureNotificationTables() {
        try {
            // Notification preferences
            $sql_prefs = "CREATE TABLE IF NOT EXISTS notification_preferences (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                channel_type VARCHAR(50),
                notification_type VARCHAR(100),
                enabled BOOLEAN DEFAULT TRUE,
                lead_time_hours INT DEFAULT 24,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_pref (user_id, channel_type, notification_type),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )";
            
            $this->conn->query($sql_prefs);
            
            // Notification history
            $sql_hist = "CREATE TABLE IF NOT EXISTS notification_history (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                notification_type VARCHAR(100),
                channel_type VARCHAR(50),
                subject VARCHAR(255),
                message TEXT,
                recipient VARCHAR(255),
                status ENUM('pending', 'sent', 'failed', 'bounced') DEFAULT 'pending',
                attempts INT DEFAULT 0,
                last_attempt_at TIMESTAMP NULL,
                error_message TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_user_status (user_id, status),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )";
            
            $this->conn->query($sql_hist);
            
            // User contact info
            $sql_contact = "CREATE TABLE IF NOT EXISTS user_contact_info (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                phone VARCHAR(20),
                secondary_email VARCHAR(255),
                verified_phone BOOLEAN DEFAULT FALSE,
                verified_secondary_email BOOLEAN DEFAULT FALSE,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_user (user_id),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )";
            
            $this->conn->query($sql_contact);
            
        } catch (Exception $e) {
            error_log("Notification table creation error: " . $e->getMessage());
        }
    }
    
    /**
     * Send notification to user through multiple channels
     */
    public function sendNotification($user_id, $type, $subject, $message, $data = []) {
        $user_id = intval($user_id);
        $notification_id = $this->createNotificationRecord($user_id, $type, $subject, $message);
        
        // Get user preferences
        $preferences = $this->getUserPreferences($user_id, $type);
        
        $results = [];
        
        // Send through enabled channels
        foreach ($preferences as $pref) {
            if (!$pref['enabled']) continue;
            
            $channel = $pref['channel_type'];
            
            try {
                switch ($channel) {
                    case self::CHANNEL_EMAIL:
                        $results[$channel] = $this->sendEmailNotification($user_id, $subject, $message, $notification_id);
                        break;
                    case self::CHANNEL_SMS:
                        $results[$channel] = $this->sendSMSNotification($user_id, $message, $notification_id);
                        break;
                    case self::CHANNEL_IN_APP:
                        $results[$channel] = $this->sendInAppNotification($user_id, $subject, $message, $notification_id);
                        break;
                }
            } catch (Exception $e) {
                error_log("Notification error ($channel): " . $e->getMessage());
                $results[$channel] = ['success' => false, 'error' => $e->getMessage()];
            }
        }
        
        return [
            'notification_id' => $notification_id,
            'user_id' => $user_id,
            'type' => $type,
            'results' => $results
        ];
    }
    
    /**
     * Send email notification using PHPMailer
     */
    private function sendEmailNotification($user_id, $subject, $message, $notification_id) {
        try {
            // Get user email
            $query = "SELECT email FROM users WHERE id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            
            if (!$user || !filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
                throw new \Exception('Invalid email address');
            }
            
            $html_message = "
            <html><body>
                <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                    <div style='background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0;'>
                        <h1 style='margin: 0;'>VVU Scheduler Notification</h1>
                    </div>
                    <div style='padding: 20px; background: #f9f9f9;'>
                        <h2>$subject</h2>
                        <p>$message</p>
                        <div style='text-align: center; margin-top: 30px;'>
                            <a href='https://scheduler.vvu.local/' style='background: #667eea; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px;'>View in Dashboard</a>
                        </div>
                    </div>
                    <div style='background: #f0f0f0; padding: 10px; text-align: center; font-size: 12px; color: #666; border-radius: 0 0 8px 8px;'>
                        <p>Do not reply to this email. <a href='https://scheduler.vvu.local/reminder_settings.php'>Manage notification preferences</a></p>
                    </div>
                </div>
            </body></html>";
            
            $mail = new PHPMailer(true);
            
            try {
                // Server settings
                $mail->isSMTP();
                $mail->Host       = $this->config['smtp_host'];
                $mail->SMTPAuth   = true;
                $mail->Username   = $this->config['smtp_user'];
                $mail->Password   = $this->config['smtp_pass'];
                // Use STARTTLS if port is 587, else SSL for 465, or none for 25.
                if ($this->config['smtp_port'] == 587) {
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                } elseif ($this->config['smtp_port'] == 465) {
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                }
                $mail->Port       = $this->config['smtp_port'];

                // Recipients
                $mail->setFrom($this->config['smtp_from'], 'VVU Scheduler');
                $mail->addAddress($user['email']);

                // Content
                $mail->isHTML(true);
                $mail->Subject = $subject;
                $mail->Body    = $html_message;
                $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html_message));

                $success = $mail->send();
                
                $this->logNotificationAttempt($notification_id, self::CHANNEL_EMAIL, $user['email'], 'sent');
                return ['success' => true];
            } catch (Exception $e) {
                // PHPMailer Exception
                throw new \Exception("Mailer Error: {$mail->ErrorInfo}");
            }
            
        } catch (\Exception $e) {
            $error_message = $e->getMessage();
            error_log("Email notification error: " . $error_message);
            $this->logNotificationAttempt($notification_id, self::CHANNEL_EMAIL, $user['email'] ?? 'unknown', 'failed', $error_message);
            return ['success' => false, 'error' => $error_message];
        }
    }
    
    /**
     * Send SMS notification
     */
    private function sendSMSNotification($user_id, $message, $notification_id) {
        try {
            // Get user phone
            $query = "SELECT phone FROM user_contact_info WHERE user_id = ? AND verified_phone = TRUE";
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $contact = $result->fetch_assoc();
            
            if (!$contact || !$contact['phone']) {
                throw new Exception('No verified phone number');
            }
            
            $phone = $contact['phone'];
            
            // Truncate message for SMS
            $sms_message = substr($message, 0, 160);
            
            // Send via configured provider (Twilio, AWS SNS, etc.)
            $success = $this->sendViaSMSProvider($phone, $sms_message);
            
            if ($success) {
                $this->logNotificationAttempt($notification_id, self::CHANNEL_SMS, $phone, 'sent');
            } else {
                $this->logNotificationAttempt($notification_id, self::CHANNEL_SMS, $phone, 'failed', 'SMS provider error');
            }
            
            return ['success' => $success];
            
        } catch (Exception $e) {
            error_log("SMS notification error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Send in-app notification
     */
    private function sendInAppNotification($user_id, $subject, $message, $notification_id) {
        try {
            // Insert into notification history with in_app channel
            $this->logNotificationAttempt($notification_id, self::CHANNEL_IN_APP, 'in_app', 'sent');
            return ['success' => true];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Send via SMS provider (implement for your chosen provider)
     */
    private function sendViaSMSProvider($phone, $message) {
        try {
            if ($this->config['sms_provider'] === 'twilio') {
                // Example Twilio implementation
                // Note: Requires Twilio SDK
                return ['success' => false, 'note' => 'Twilio integration not configured'];
            } elseif ($this->config['sms_provider'] === 'aws') {
                // Example AWS SNS implementation
                return ['success' => false, 'note' => 'AWS SNS integration not configured'];
            }
            
            // Default: log but don't actually send (for demo)
            error_log("SMS Provider: {$this->config['sms_provider']} - To: $phone - Message: $message");
            return true;
            
        } catch (Exception $e) {
            error_log("SMS provider error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Create notification record
     */
    private function createNotificationRecord($user_id, $type, $subject, $message) {
        try {
            $query = "INSERT INTO notification_history (user_id, notification_type, subject, message, status) 
                      VALUES (?, ?, ?, ?, 'pending')";
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param('isss', $user_id, $type, $subject, $message);
            $stmt->execute();
            
            return $this->conn->insert_id;
        } catch (Exception $e) {
            error_log("Create notification record error: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Log notification attempt
     */
    private function logNotificationAttempt($notification_id, $channel, $recipient, $status, $error = null) {
        try {
            $query = "UPDATE notification_history SET channel_type = ?, recipient = ?, status = ?, 
                      error_message = ?, attempts = attempts + 1, last_attempt_at = NOW()
                      WHERE id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param('ssssi', $channel, $recipient, $status, $error, $notification_id);
            $stmt->execute();
        } catch (Exception $e) {
            error_log("Log notification attempt error: " . $e->getMessage());
        }
    }
    
    /**
     * Get user notification preferences
     */
    private function getUserPreferences($user_id, $type) {
        try {
            $query = "SELECT * FROM notification_preferences 
                      WHERE user_id = ? AND (notification_type = ? OR notification_type IS NULL)
                      AND enabled = TRUE";
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param('is', $user_id, $type);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $prefs = [];
            while ($row = $result->fetch_assoc()) {
                $prefs[] = $row;
            }
            
            // If no preferences, create defaults
            if (empty($prefs)) {
                $this->setDefaultPreferences($user_id, $type);
                return $this->getUserPreferences($user_id, $type);
            }
            
            return $prefs;
        } catch (Exception $e) {
            error_log("Get preferences error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Set default notification preferences for user
     */
    private function setDefaultPreferences($user_id, $type) {
        try {
            $defaults = [
                [self::CHANNEL_IN_APP, $type, 1, 0],
                [self::CHANNEL_EMAIL, $type, 1, 24],
            ];
            
            foreach ($defaults as $pref) {
                $query = "INSERT IGNORE INTO notification_preferences 
                          (user_id, channel_type, notification_type, enabled, lead_time_hours) 
                          VALUES (?, ?, ?, ?, ?)";
                $stmt = $this->conn->prepare($query);
                $stmt->bind_param('issii', $user_id, $pref[0], $pref[1], $pref[2], $pref[3]);
                $stmt->execute();
            }
        } catch (Exception $e) {
            error_log("Set default preferences error: " . $e->getMessage());
        }
    }
    
    /**
     * Get user notifications (for dashboard)
     */
    public function getUserNotifications($user_id, $limit = 20) {
        try {
            $query = "SELECT * FROM notification_history 
                      WHERE user_id = ? 
                      ORDER BY created_at DESC 
                      LIMIT ?";
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param('ii', $user_id, $limit);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $notifications = [];
            while ($row = $result->fetch_assoc()) {
                $notifications[] = $row;
            }
            
            return $notifications;
        } catch (Exception $e) {
            error_log("Get notifications error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Save user contact information
     */
    public function saveContactInfo($user_id, $phone, $secondary_email) {
        try {
            $query = "INSERT INTO user_contact_info (user_id, phone, secondary_email) 
                      VALUES (?, ?, ?)
                      ON DUPLICATE KEY UPDATE phone = ?, secondary_email = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param('issss', $user_id, $phone, $secondary_email, $phone, $secondary_email);
            return $stmt->execute();
        } catch (Exception $e) {
            error_log("Save contact info error: " . $e->getMessage());
            return false;
        }
    }
}

// Helper function for easy usage
function send_notification($user_id, $type, $subject, $message) {
    global $conn;
    if (!isset($conn)) {
        return ['success' => false, 'error' => 'Database connection not available'];
    }
    
    $service = new NotificationService($conn);
    return $service->sendNotification($user_id, $type, $subject, $message);
}
?>
