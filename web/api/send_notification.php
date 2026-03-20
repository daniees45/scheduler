<?php
// web/api/send_notification.php
header('Content-Type: application/json');
require_once 'db.php';

$data = json_decode(file_get_contents('php://input'), true);
if (!$data)
    $data = $_POST;

$type = $data['notification_type'] ?? 'info';
$title = $data['title'] ?? 'System Notification';
$message = $data['message'] ?? '';
$user_id = $data['user_id'] ?? 1; // Default to admin for systems alerts
$email = $data['email'] ?? null;
$priority = $data['priority'] ?? 'medium';

if (empty($message)) {
    echo json_encode(['status' => 'error', 'message' => 'Notification message is required.']);
    exit;
}

// 1. Log to Database for In-App View
try {
    $stmt = $conn->prepare("
        INSERT INTO notifications 
        (user_id, notification_type, title, message, scheduled_time, priority, delivery_method)
        VALUES (?, ?, ?, ?, NOW(), ?, ?)
    ");
    $delivery_method = $email ? 'email' : 'in_app';
    $stmt->bind_param("isssss", $user_id, $type, $title, $message, $priority, $delivery_method);
    $stmt->execute();
    $notification_id = $conn->insert_id;

    $results = ['db' => 'success', 'notification_id' => $notification_id];

    // 2. Dispatch Email if requested
    if ($email) {
        $mail_success = simulate_email_send($email, $title, $message);
        $results['email'] = $mail_success ? 'sent' : 'failed';

        if ($mail_success) {
            $conn->query("UPDATE notifications SET is_sent = TRUE, sent_at = NOW() WHERE id = $notification_id");
        }
    }

    echo json_encode(['status' => 'success', 'details' => $results]);

}
catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

/**
 * Simulation function for email delivery.
 * In a real environment, replace with PHP mail() or PHPMailer.
 */
function simulate_email_send($to, $subject, $body)
{
    $log_file = __DIR__ . '/../../logs/email_notifications.log';
    $log_dir = dirname($log_file);
    if (!is_dir($log_dir))
        mkdir($log_dir, 0777, true);

    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[{$timestamp}] To: {$to} | Subject: {$subject}\nContent: {$body}\n" . str_repeat("-", 40) . "\n";

    // Simulate latency
    // usleep(200000); 

    // Log to file as simulation of "Sending"
    return file_put_contents($log_file, $log_entry, FILE_APPEND) !== false;
}