<?php
// web/api/log.php
// Receives log data from the AI engine and inserts into MySQL audit_log

header('Content-Type: application/json');
require_once 'db.php';

// Accept JSON payload
$json = file_get_contents('php://temp'); // Try to read from input stream
if (empty($json)) {
    $json = file_get_contents('php://input');
}
$data = json_decode($json, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Invalid JSON payload"]);
    exit;
}

$action = $data['action'] ?? 'AI_EVENT';
$details = $data['details'] ?? '';
$status = $data['status'] ?? 'info';
$metadata = json_encode($data['metadata'] ?? []);
$source = $data['source'] ?? 'AI_ENGINE';
$ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$ua = "AI Engine Pipeline";

try {
    // Ensure audit_log table exists (safety check)
    $conn->query("CREATE TABLE IF NOT EXISTS audit_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT DEFAULT NULL,
        username VARCHAR(255),
        action VARCHAR(100),
        resource VARCHAR(255),
        resource_id INT DEFAULT NULL,
        status VARCHAR(50),
        details TEXT,
        ip_address VARCHAR(45),
        user_agent VARCHAR(255),
        log_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $stmt = $conn->prepare("INSERT INTO audit_log (username, action, status, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?)");
    $username = "AI_SYSTEM";
    $stmt->bind_param("ssssss", $username, $action, $status, $details, $ip, $ua);

    if ($stmt->execute()) {
        echo json_encode(["status" => "success", "message" => "Log recorded"]);
    }
    else {
        throw new Exception($stmt->error);
    }
}
catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
