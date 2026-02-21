<?php
/**
 * Notifications & Reminders API
 * Manages notifications, reminders, and alerts for schedules and commitments
 */
require_once 'db.php';

header('Content-Type: application/json');

session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'student';
$lecturer_id = (int)($_SESSION['lecturer_id'] ?? 0);
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

try {
    switch ($action) {
        case 'list':
            $unread_only = isset($_GET['unread_only']) && $_GET['unread_only'] === 'true';
            echo json_encode(listNotifications($user_id, $unread_only, $conn));
            break;
        
        case 'mark_read':
            $notification_id = $_POST['notification_id'] ?? 0;
            echo json_encode(markNotificationRead($user_id, $notification_id, $conn));
            break;
        
        case 'mark_all_read':
            echo json_encode(markAllNotificationsRead($user_id, $conn));
            break;
        
        case 'delete':
            $notification_id = $_POST['notification_id'] ?? 0;
            echo json_encode(deleteNotification($user_id, $notification_id, $conn));
            break;
        
        case 'get_reminder_settings':
            echo json_encode(getReminderSettings($user_id, $conn));
            break;
        
        case 'update_reminder_settings':
            $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            echo json_encode(updateReminderSettings($user_id, $data, $conn));
            break;
        
        case 'generate_reminders':
            echo json_encode(generateReminders($user_id, $conn, $user_role, $lecturer_id));
            break;
        
        case 'get_upcoming':
            $hours = $_GET['hours'] ?? 24;
            echo json_encode(getUpcomingReminders($user_id, $hours, $conn));
            break;
        
        case 'dismiss':
            $notification_id = $_POST['notification_id'] ?? 0;
            echo json_encode(dismissReminder($user_id, $notification_id, $conn));
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

// ============================================================================
// NOTIFICATION FUNCTIONS
// ============================================================================

function listNotifications($user_id, $unread_only, $conn) {
    $sql = "
        SELECT * FROM notifications
        WHERE user_id = ?
    ";
    
    if ($unread_only) {
        $sql .= " AND is_read = FALSE";
    }
    
    $sql .= " ORDER BY scheduled_time DESC, created_at DESC LIMIT 50";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $notifications = [];
    while ($row = $result->fetch_assoc()) {
        $notifications[] = $row;
    }
    
    // Get unread count
    $stmt = $conn->prepare("SELECT COUNT(*) as unread_count FROM notifications WHERE user_id = ? AND is_read = FALSE");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $unread_count = $stmt->get_result()->fetch_assoc()['unread_count'];
    
    return [
        'success' => true,
        'notifications' => $notifications,
        'unread_count' => intval($unread_count),
        'total_count' => count($notifications)
    ];
}

function markNotificationRead($user_id, $notification_id, $conn) {
    $stmt = $conn->prepare("
        UPDATE notifications 
        SET is_read = TRUE, read_at = NOW()
        WHERE id = ? AND user_id = ?
    ");
    $stmt->bind_param("ii", $notification_id, $user_id);
    
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        return ['success' => true, 'message' => 'Notification marked as read'];
    }
    
    return ['success' => false, 'error' => 'Notification not found'];
}

function markAllNotificationsRead($user_id, $conn) {
    $stmt = $conn->prepare("
        UPDATE notifications 
        SET is_read = TRUE, read_at = NOW()
        WHERE user_id = ? AND is_read = FALSE
    ");
    $stmt->bind_param("i", $user_id);
    
    if ($stmt->execute()) {
        $count = $stmt->affected_rows;
        return [
            'success' => true, 
            'message' => "Marked $count notifications as read",
            'count' => $count
        ];
    }
    
    return ['success' => false, 'error' => 'Failed to mark notifications'];
}

function deleteNotification($user_id, $notification_id, $conn) {
    $stmt = $conn->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $notification_id, $user_id);
    
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        return ['success' => true, 'message' => 'Notification deleted'];
    }
    
    return ['success' => false, 'error' => 'Notification not found'];
}

// ============================================================================
// REMINDER SETTINGS
// ============================================================================

function getReminderSettings($user_id, $conn) {
    $stmt = $conn->prepare("
        SELECT * FROM reminder_settings WHERE user_id = ?
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $settings = [];
    while ($row = $result->fetch_assoc()) {
        $settings[] = $row;
    }
    
    return [
        'success' => true,
        'settings' => $settings
    ];
}

function updateReminderSettings($user_id, $data, $conn) {
    if (empty($data['reminder_type'])) {
        return ['success' => false, 'error' => 'Reminder type required'];
    }
    
    $stmt = $conn->prepare("
        INSERT INTO reminder_settings 
        (user_id, reminder_type, enabled, minutes_before, delivery_method)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            enabled = VALUES(enabled),
            minutes_before = VALUES(minutes_before),
            delivery_method = VALUES(delivery_method)
    ");
    
    $enabled = isset($data['enabled']) ? ($data['enabled'] ? 1 : 0) : 1;
    $minutes = $data['minutes_before'] ?? 30;
    $delivery = $data['delivery_method'] ?? 'in_app';
    
    $stmt->bind_param("isiss",
        $user_id,
        $data['reminder_type'],
        $enabled,
        $minutes,
        $delivery
    );
    
    if ($stmt->execute()) {
        return ['success' => true, 'message' => 'Reminder settings updated'];
    }
    
    return ['success' => false, 'error' => 'Failed to update settings'];
}

// ============================================================================
// REMINDER GENERATION
// ============================================================================

function generateReminders($user_id, $conn, $user_role, $lecturer_id) {
    $generated = 0;
    
    // Get reminder settings
    $stmt = $conn->prepare("SELECT * FROM reminder_settings WHERE user_id = ? AND enabled = TRUE");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $settings_result = $stmt->get_result();
    
    $settings = [];
    while ($row = $settings_result->fetch_assoc()) {
        $settings[$row['reminder_type']] = $row;
    }
    
    // Generate course reminders
    if (isset($settings['course_reminder'])) {
        $generated += generateCourseReminders($user_id, $settings['course_reminder'], $conn, $user_role, $lecturer_id);
    }
    
    // Generate exam reminders
    if (isset($settings['exam_reminder'])) {
        $generated += generateExamReminders($user_id, $settings['exam_reminder'], $conn);
    }
    
    // Generate personal event reminders
    if (isset($settings['personal_event'])) {
        $generated += generatePersonalEventReminders($user_id, $settings['personal_event'], $conn);
    }
    
    return [
        'success' => true,
        'message' => "Generated $generated reminders",
        'count' => $generated
    ];
}

function generateCourseReminders($user_id, $settings, $conn, $user_role, $lecturer_id) {
    // Get scheduled classes based on user role
    if ($user_role === 'lecturer') {
        if ($lecturer_id <= 0) {
            return 0;
        }

        $stmt = $conn->prepare("
            SELECT DISTINCT c.course_code, c.course_title, s.assigned_day, s.assigned_time
            FROM sections s
            JOIN courses c ON s.course_id = c.id
            WHERE s.lecturer_id = ? AND s.assigned_day IS NOT NULL
        ");
        $stmt->bind_param("i", $lecturer_id);
    } else {
        $stmt = $conn->prepare("
            SELECT DISTINCT c.course_code, c.course_title, s.assigned_day, s.assigned_time
            FROM student_enrollments e
            JOIN courses c ON e.course_id = c.id
            JOIN sections s ON c.id = s.course_id
            WHERE e.user_id = ? AND s.assigned_day IS NOT NULL
        ");
        $stmt->bind_param("i", $user_id);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    $generated = 0;
    $minutes_before = $settings['minutes_before'];
    
    while ($row = $result->fetch_assoc()) {
        // Parse time
        if (preg_match('/(\d{1,2}:\d{2})/', $row['assigned_time'], $matches)) {
            $class_time = $matches[1];
            
            // Calculate next occurrence
            $next_occurrence = getNextOccurrence($row['assigned_day'], $class_time);
            
            if ($next_occurrence) {
                $reminder_time = date('Y-m-d H:i:s', strtotime($next_occurrence) - ($minutes_before * 60));
                
                // Check if reminder already exists
                $check_stmt = $conn->prepare("
                    SELECT id FROM notifications 
                    WHERE user_id = ? 
                    AND notification_type = 'reminder'
                    AND related_course_id = (SELECT id FROM courses WHERE course_code = ?)
                    AND scheduled_time = ?
                    AND is_sent = FALSE
                ");
                $check_stmt->bind_param("iss", $user_id, $row['course_code'], $reminder_time);
                $check_stmt->execute();
                
                if ($check_stmt->get_result()->num_rows === 0) {
                    // Create reminder
                    $label = ($user_role === 'lecturer') ? 'Lecture' : 'Class';
                    $title = "Upcoming {$label}: {$row['course_code']}";
                    $message = "{$row['course_title']} starts at {$class_time} on {$row['assigned_day']}";
                    
                    $insert_stmt = $conn->prepare("
                        INSERT INTO notifications 
                        (user_id, notification_type, title, message, related_course_id, 
                         scheduled_time, priority, delivery_method)
                        VALUES (?, 'reminder', ?, ?, 
                                (SELECT id FROM courses WHERE course_code = ?), 
                                ?, 'medium', ?)
                    ");
                    
                    $insert_stmt->bind_param("isssss",
                        $user_id,
                        $title,
                        $message,
                        $row['course_code'],
                        $reminder_time,
                        $settings['delivery_method']
                    );
                    
                    if ($insert_stmt->execute()) {
                        $generated++;
                    }
                }
            }
        }
    }
    
    return $generated;
}

function generateExamReminders($user_id, $settings, $conn) {
    // Get user's exams (from exam schedule)
    // This is a simplified version - would need proper exam schedule integration
    $generated = 0;
    
    // TODO: Integrate with exam schedule when available
    
    return $generated;
}

function generatePersonalEventReminders($user_id, $settings, $conn) {
    // Get upcoming personal events
    $stmt = $conn->prepare("
        SELECT id, title, day, start_time
        FROM personal_events
        WHERE user_id = ?
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $generated = 0;
    $minutes_before = $settings['minutes_before'];
    
    while ($row = $result->fetch_assoc()) {
        // Calculate next occurrence
        $next_occurrence = getNextOccurrence($row['day'], $row['start_time']);
        
        if ($next_occurrence) {
            $reminder_time = date('Y-m-d H:i:s', strtotime($next_occurrence) - ($minutes_before * 60));
            
            // Only create reminders for future events
            if (strtotime($reminder_time) > time()) {
                // Check if reminder already exists
                $check_stmt = $conn->prepare("
                    SELECT id FROM notifications 
                    WHERE user_id = ? 
                    AND notification_type = 'reminder'
                    AND related_event_id = ?
                    AND scheduled_time = ?
                    AND is_sent = FALSE
                ");
                $check_stmt->bind_param("iis", $user_id, $row['id'], $reminder_time);
                $check_stmt->execute();
                
                if ($check_stmt->get_result()->num_rows === 0) {
                    // Create reminder
                    $title = "Upcoming Event: {$row['title']}";
                    $message = "{$row['title']} starts at {$row['start_time']} on {$row['day']}";
                    
                    $insert_stmt = $conn->prepare("
                        INSERT INTO notifications 
                        (user_id, notification_type, title, message, related_event_id, 
                         scheduled_time, priority, delivery_method)
                        VALUES (?, 'reminder', ?, ?, ?, ?, 'medium', ?)
                    ");
                    
                    $insert_stmt->bind_param("ississ",
                        $user_id,
                        $title,
                        $message,
                        $row['id'],
                        $reminder_time,
                        $settings['delivery_method']
                    );
                    
                    if ($insert_stmt->execute()) {
                        $generated++;
                    }
                }
            }
        }
    }
    
    return $generated;
}

function getNextOccurrence($day_name, $time) {
    $days = ['Monday' => 1, 'Tuesday' => 2, 'Wednesday' => 3, 'Thursday' => 4, 
             'Friday' => 5, 'Saturday' => 6, 'Sunday' => 7];
    
    if (!isset($days[$day_name])) {
        return null;
    }
    
    $target_day = $days[$day_name];
    $current_day = date('N'); // 1 (Monday) to 7 (Sunday)
    
    $days_ahead = $target_day - $current_day;
    if ($days_ahead <= 0) {
        $days_ahead += 7; // Next week
    }
    
    $next_date = date('Y-m-d', strtotime("+$days_ahead days"));
    return "$next_date $time";
}

function getUpcomingReminders($user_id, $hours, $conn) {
    $until = date('Y-m-d H:i:s', strtotime("+$hours hours"));
    
    $stmt = $conn->prepare("
        SELECT * FROM notifications
        WHERE user_id = ? 
        AND scheduled_time <= ?
        AND scheduled_time >= NOW()
        AND is_sent = FALSE
        ORDER BY scheduled_time ASC
    ");
    $stmt->bind_param("is", $user_id, $until);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $reminders = [];
    while ($row = $result->fetch_assoc()) {
        $reminders[] = $row;
    }
    
    return [
        'success' => true,
        'reminders' => $reminders,
        'count' => count($reminders),
        'timeframe_hours' => $hours
    ];
}

function dismissReminder($user_id, $notification_id, $conn) {
    $stmt = $conn->prepare("
        UPDATE notifications 
        SET is_read = TRUE, read_at = NOW(), is_sent = TRUE, sent_at = NOW()
        WHERE id = ? AND user_id = ?
    ");
    $stmt->bind_param("ii", $notification_id, $user_id);
    
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        return ['success' => true, 'message' => 'Reminder dismissed'];
    }
    
    return ['success' => false, 'error' => 'Reminder not found'];
}
