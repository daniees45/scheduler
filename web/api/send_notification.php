<?php
/**
 * Email Notification Service
 * 
 * Sends notifications to users for:
 * - Schedule generation completion
 * - Conflict detection alerts
 * - Assignment changes
 * - System alerts
 */

header('Content-Type: application/json');
require_once 'db.php';
require_once 'error_handler.php';

/**
 * Get user email preferences
 */
function get_email_preferences($user_id) {
    global $conn;
    
    // Ensure table exists
    $conn->query("CREATE TABLE IF NOT EXISTS email_preferences (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        notify_schedule_ready BOOLEAN DEFAULT 1,
        notify_conflicts BOOLEAN DEFAULT 1,
        notify_assignments BOOLEAN DEFAULT 1,
        notification_email VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE KEY unique_user (user_id)
    )");
    
    $query = "SELECT * FROM email_preferences WHERE user_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    
    // Create default preferences
    $email = get_user_email($user_id);
    $default_prefs = [
        'user_id' => $user_id,
        'notify_schedule_ready' => 1,
        'notify_conflicts' => 1,
        'notify_assignments' => 1,
        'notification_email' => $email
    ];
    
    set_email_preferences($user_id, $default_prefs);
    return $default_prefs;
}

/**
 * Update email preferences
 */
function set_email_preferences($user_id, $preferences) {
    global $conn;
    
    $query = "INSERT INTO email_preferences 
              (user_id, notify_schedule_ready, notify_conflicts, notify_assignments, notification_email)
              VALUES (?, ?, ?, ?, ?)
              ON DUPLICATE KEY UPDATE
              notify_schedule_ready = ?,
              notify_conflicts = ?,
              notify_assignments = ?,
              notification_email = ?";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param('iiisiiis',
        $user_id,
        $preferences['notify_schedule_ready'] ?? 1,
        $preferences['notify_conflicts'] ?? 1,
        $preferences['notify_assignments'] ?? 1,
        $preferences['notification_email'] ?? '',
        $preferences['notify_schedule_ready'] ?? 1,
        $preferences['notify_conflicts'] ?? 1,
        $preferences['notify_assignments'] ?? 1,
        $preferences['notification_email'] ?? ''
    );
    
    return $stmt->execute();
}

/**
 * Get user email from database
 */
function get_user_email($user_id) {
    global $conn;
    
    $query = "SELECT email FROM users WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['email'] ?? '';
    }
    
    return '';
}

/**
 * Send email notification
 */
function send_email_notification($user_id, $notification_type, $subject, $body, $html_body = null) {
    global $conn;
    
    // Check preferences
    $prefs = get_email_preferences($user_id);
    
    $type_map = [
        'schedule_ready' => 'notify_schedule_ready',
        'conflict_alert' => 'notify_conflicts',
        'assignment_change' => 'notify_assignments'
    ];
    
    $pref_key = $type_map[$notification_type] ?? 'notify_schedule_ready';
    
    if (!$prefs[$pref_key]) {
        return ['success' => false, 'reason' => 'User has disabled this notification type'];
    }
    
    $recipient = $prefs['notification_email'];
    if (empty($recipient)) {
        return ['success' => false, 'reason' => 'No notification email configured'];
    }
    
    // Ensure notification log table exists
    $conn->query("CREATE TABLE IF NOT EXISTS notification_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        recipient_email VARCHAR(255),
        notification_type VARCHAR(100),
        subject VARCHAR(255),
        status VARCHAR(50),
        sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");
    
    // Prepare email
    $from = 'noreply@aischeduler.local';
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: " . (!empty($html_body) ? 'text/html' : 'text/plain') . "; charset=UTF-8\r\n";
    $headers .= "From: " . $from . "\r\n";
    $headers .= "Reply-To: admin@aischeduler.local\r\n";
    
    $message = $html_body ?? $body;
    
    // Attempt to send
    $success = mail($recipient, $subject, $message, $headers);
    
    // Log attempt
    $status = $success ? 'sent' : 'failed';
    $log_query = "INSERT INTO notification_log 
                  (user_id, recipient_email, notification_type, subject, status)
                  VALUES (?, ?, ?, ?, ?)";
    
    $log_stmt = $conn->prepare($log_query);
    $log_stmt->bind_param('issss', $user_id, $recipient, $notification_type, $subject, $status);
    $log_stmt->execute();
    
    return [
        'success' => $success,
        'recipient' => $recipient,
        'status' => $status
    ];
}

/**
 * Notify admins of schedule completion
 */
function notify_schedule_complete($schedule_name, $quality_score, $course_count) {
    global $conn;
    
    // Get all admins
    $query = "SELECT id FROM users WHERE role = 'admin'";
    $result = $conn->query($query);
    
    $notifications_sent = 0;
    
    while ($row = $result->fetch_assoc()) {
        $admin_id = $row['id'];
        
        $subject = "Schedule Generated: " . htmlspecialchars($schedule_name);
        $body = "Schedule generation completed successfully.\n\n" .
                "Schedule: " . htmlspecialchars($schedule_name) . "\n" .
                "Courses Scheduled: " . $course_count . "\n" .
                "Quality Score: " . round($quality_score, 1) . "%\n" .
                "Time: " . date('Y-m-d H:i:s') . "\n\n" .
                "View details: " . get_base_url() . "/analytics_dashboard.php";
        
        $html = "<h2>Schedule Generated</h2>" .
                "<p><strong>Schedule:</strong> " . htmlspecialchars($schedule_name) . "</p>" .
                "<p><strong>Courses:</strong> " . $course_count . "</p>" .
                "<p><strong>Quality Score:</strong> <span style='color: " . 
                ($quality_score >= 80 ? 'green' : 'orange') . ";'>" . 
                round($quality_score, 1) . "%</span></p>" .
                "<p><a href='" . get_base_url() . "/analytics_dashboard.php'>View Analytics</a></p>";
        
        $result_send = send_email_notification($admin_id, 'schedule_ready', $subject, $body, $html);
        if ($result_send['success']) {
            $notifications_sent++;
        }
    }
    
    return ['notifications_sent' => $notifications_sent];
}

/**
 * Notify of conflicts detected
 */
function notify_conflicts_detected($conflict_count, $conflict_details) {
    global $conn;
    
    // Get admins
    $query = "SELECT id FROM users WHERE role = 'admin'";
    $result = $conn->query($query);
    
    $notifications_sent = 0;
    
    while ($row = $result->fetch_assoc()) {
        $admin_id = $row['id'];
        
        $subject = "Alert: " . $conflict_count . " Schedule Conflicts Detected";
        $body = "Scheduling conflicts have been detected.\n\n" .
                "Conflict Count: " . $conflict_count . "\n" .
                "Time: " . date('Y-m-d H:i:s') . "\n\n" .
                "Details:\n" . $conflict_details . "\n\n" .
                "Review and take action: " . get_base_url() . "/analytics_dashboard.php";
        
        $result_send = send_email_notification($admin_id, 'conflict_alert', $subject, $body);
        if ($result_send['success']) {
            $notifications_sent++;
        }
    }
    
    return ['notifications_sent' => $notifications_sent];
}

/**
 * Get base URL
 */
function get_base_url() {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    return $protocol . '://' . $_SERVER['HTTP_HOST'];
}

/**
 * API Endpoint
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'get_preferences' && isset($_POST['user_id'])) {
        $user_id = (int)$_POST['user_id'];
        $prefs = get_email_preferences($user_id);
        echo json_encode(['success' => true, 'preferences' => $prefs]);
        
    } elseif ($action === 'set_preferences' && isset($_POST['user_id'])) {
        $user_id = (int)$_POST['user_id'];
        
        $preferences = [
            'notify_schedule_ready' => isset($_POST['notify_schedule_ready']) ? 1 : 0,
            'notify_conflicts' => isset($_POST['notify_conflicts']) ? 1 : 0,
            'notify_assignments' => isset($_POST['notify_assignments']) ? 1 : 0,
            'notification_email' => $_POST['notification_email'] ?? ''
        ];
        
        $success = set_email_preferences($user_id, $preferences);
        echo json_encode(['success' => $success]);
        
    } elseif ($action === 'send_notification') {
        $user_id = (int)($_POST['user_id'] ?? 0);
        $type = $_POST['type'] ?? 'schedule_ready';
        $subject = $_POST['subject'] ?? 'Notification';
        $body = $_POST['body'] ?? '';
        
        if ($user_id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid user ID']);
            exit;
        }
        
        $result = send_email_notification($user_id, $type, $subject, $body);
        echo json_encode($result);
        
    } elseif ($action === 'notify_schedule_complete') {
        $name = $_POST['schedule_name'] ?? 'Generated Schedule';
        $quality = (float)($_POST['quality_score'] ?? 0);
        $courses = (int)($_POST['course_count'] ?? 0);
        
        $result = notify_schedule_complete($name, $quality, $courses);
        echo json_encode(['success' => true, 'result' => $result]);
        
    } elseif ($action === 'notify_conflicts') {
        $count = (int)($_POST['conflict_count'] ?? 0);
        $details = $_POST['conflict_details'] ?? '';
        
        $result = notify_conflicts_detected($count, $details);
        echo json_encode(['success' => true, 'result' => $result]);
        
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
?>
