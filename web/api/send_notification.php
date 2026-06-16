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
        require_once __DIR__ . '/../../vendor/autoload.php';
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->SMTPAuth   = true;
            $gmailEmail = trim((string)(getenv('GMAIL_APP_EMAIL') ?: ''));
            $gmailAppPassword = trim((string)(getenv('GMAIL_APP_PASSWORD') ?: ''));

            if ($gmailEmail !== '' && $gmailAppPassword !== '') {
                $mail->Host = 'smtp.gmail.com';
                $mail->Username = $gmailEmail;
                $mail->Password = $gmailAppPassword;
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port = 587;
            } else {
                $mail->Host = getenv('SMTP_HOST') ?: 'smtp.mailtrap.io';
                $mail->Username = getenv('SMTP_USER') ?: '';
                $mail->Password = getenv('SMTP_PASS') ?: '';

                $port = (int)(getenv('SMTP_PORT') ?: 465);
                if ($port === 587) {
                    $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                } elseif ($port === 465) {
                    $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
                }
                $mail->Port = $port;
            }

            $mail->setFrom(getenv('SMTP_FROM') ?: ($gmailEmail !== '' ? $gmailEmail : 'noreply@vvuscheduler.local'), 'VVU Scheduler');
            $mail->addAddress($email);

            $mail->isHTML(true);
            $mail->Subject = $title;
            $mail->Body    = nl2br(htmlspecialchars($message));
            $mail->AltBody = $message;

            $mail->send();
            $mail_success = true;
        } catch (Exception $e) {
            error_log("Email sending failed in send_notification.php: {$mail->ErrorInfo}");
            $mail_success = false;
        }

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
