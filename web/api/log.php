<?php
// web/api/log.php
// Receives log data from the AI engine and inserts into MySQL audit_log

ini_set('display_errors', '0');
header('Content-Type: application/json');
require_once 'db.php';

function log_get_audit_columns(mysqli $conn): array {
    $columns = [];
    $result = $conn->query("SHOW COLUMNS FROM audit_log");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $columns[$row['Field']] = true;
        }
    }
    return $columns;
}

// Accept JSON payload
$json = file_get_contents('php://input');
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

    $cols = log_get_audit_columns($conn);
    if (!$cols || (!isset($cols['action']) && !isset($cols['entity_type']))) {
        throw new Exception('audit_log schema missing action/entity_type columns');
    }

    $fields = [];
    $types = '';
    $values = [];

    if (isset($cols['username'])) {
        $fields[] = 'username';
        $types .= 's';
        $values[] = 'AI_SYSTEM';
    }
    if (isset($cols['action'])) {
        $fields[] = 'action';
        $types .= 's';
        $values[] = $action;
    }
    if (isset($cols['entity_type'])) {
        $fields[] = 'entity_type';
        $types .= 's';
        $values[] = $action;
    }
    if (isset($cols['resource'])) {
        $fields[] = 'resource';
        $types .= 's';
        $values[] = (string)$source;
    }
    if (isset($cols['status'])) {
        $fields[] = 'status';
        $types .= 's';
        $values[] = $status;
    }
    if (isset($cols['details'])) {
        $fields[] = 'details';
        $types .= 's';
        $values[] = $details !== '' ? $details : ($metadata ?: '{}');
    }
    if (isset($cols['ip_address'])) {
        $fields[] = 'ip_address';
        $types .= 's';
        $values[] = $ip;
    }
    if (isset($cols['user_agent'])) {
        $fields[] = 'user_agent';
        $types .= 's';
        $values[] = $ua;
    }

    $placeholders = implode(',', array_fill(0, count($fields), '?'));
    $stmt = $conn->prepare("INSERT INTO audit_log (" . implode(',', $fields) . ") VALUES ($placeholders)");
    if (!$stmt) {
        throw new Exception($conn->error);
    }
    $stmt->bind_param($types, ...$values);

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
