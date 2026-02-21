<?php
/**
 * Webhook Dispatcher & Management
 * 
 * Manages webhooks for:
 * - Schedule generation events
 * - Conflict detection
 * - Version updates
 * - System alerts
 */

header('Content-Type: application/json');
require_once 'db.php';
require_once 'error_handler.php';

/**
 * Ensure webhook table exists
 */
function ensure_webhooks_table() {
    global $conn;
    
    $conn->query("CREATE TABLE IF NOT EXISTS webhooks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        url VARCHAR(500) NOT NULL,
        events JSON,
        api_key VARCHAR(255) UNIQUE,
        active BOOLEAN DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        last_triggered TIMESTAMP NULL,
        fail_count INT DEFAULT 0,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");
    
    $conn->query("CREATE TABLE IF NOT EXISTS webhook_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        webhook_id INT NOT NULL,
        event_type VARCHAR(100),
        payload JSON,
        status_code INT,
        response TEXT,
        triggered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (webhook_id) REFERENCES webhooks(id) ON DELETE CASCADE,
        INDEX idx_webhook (webhook_id),
        INDEX idx_event (event_type)
    )");
}

/**
 * Register a new webhook
 */
function register_webhook($user_id, $url, $events, $description = '') {
    global $conn;
    
    ensure_webhooks_table();
    
    // Validate URL
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return ['success' => false, 'error' => 'Invalid webhook URL'];
    }
    
    // Generate API key
    $api_key = bin2hex(random_bytes(32));
    
    // Ensure events is array
    if (is_string($events)) {
        $events = json_decode($events, true);
    }
    
    if (!is_array($events) || empty($events)) {
        $events = ['schedule_generated', 'conflicts_detected'];
    }
    
    // Verify endpoint is reachable (timeout 5s)
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Length: 0']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code === 0 || $http_code >= 500) {
        return ['success' => false, 'error' => 'Webhook endpoint not reachable (HTTP ' . $http_code . ')'];
    }
    
    // Store webhook
    $query = "INSERT INTO webhooks (user_id, url, events, api_key, description)
              VALUES (?, ?, ?, ?, ?)";
    
    $events_json = json_encode($events);
    $stmt = $conn->prepare($query);
    $stmt->bind_param('issss', $user_id, $url, $events_json, $api_key, $description);
    
    if ($stmt->execute()) {
        return [
            'success' => true,
            'webhook_id' => $conn->insert_id,
            'api_key' => $api_key
        ];
    }
    
    return ['success' => false, 'error' => 'Database error'];
}

/**
 * Get webhooks for user
 */
function get_user_webhooks($user_id) {
    global $conn;
    
    ensure_webhooks_table();
    
    $query = "SELECT id, url, events, active, created_at, last_triggered, fail_count
              FROM webhooks
              WHERE user_id = ?
              ORDER BY created_at DESC";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $webhooks = [];
    while ($row = $result->fetch_assoc()) {
        $row['events'] = json_decode($row['events'], true);
        $webhooks[] = $row;
    }
    
    return $webhooks;
}

/**
 * Delete a webhook
 */
function delete_webhook($webhook_id, $user_id) {
    global $conn;
    
    // Verify ownership
    $query = "DELETE FROM webhooks WHERE id = ? AND user_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('ii', $webhook_id, $user_id);
    
    return $stmt->execute() && $stmt->affected_rows > 0;
}

/**
 * Dispatch webhook event
 */
function dispatch_webhook($event_type, $payload, $webhook_id = null) {
    global $conn;
    
    ensure_webhooks_table();
    
    if ($webhook_id) {
        $query = "SELECT * FROM webhooks WHERE id = ? AND active = 1";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('i', $webhook_id);
    } else {
        $query = "SELECT * FROM webhooks WHERE active = 1 AND JSON_CONTAINS(events, JSON_QUOTE(?))";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('s', $event_type);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $dispatched = 0;
    $failed = 0;
    
    while ($webhook = $result->fetch_assoc()) {
        $success = send_webhook_request($webhook['id'], $webhook['url'], $event_type, $payload);
        
        if ($success) {
            $dispatched++;
            
            // Update last_triggered
            $update_query = "UPDATE webhooks SET last_triggered = NOW(), fail_count = 0 WHERE id = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param('i', $webhook['id']);
            $update_stmt->execute();
        } else {
            $failed++;
            
            // Increment fail count
            $update_query = "UPDATE webhooks SET fail_count = fail_count + 1 WHERE id = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param('i', $webhook['id']);
            $update_stmt->execute();
            
            // Deactivate after 10 failures
            $deactivate_query = "UPDATE webhooks SET active = 0 WHERE id = ? AND fail_count >= 10";
            $deactivate_stmt = $conn->prepare($deactivate_query);
            $deactivate_stmt->bind_param('i', $webhook['id']);
            $deactivate_stmt->execute();
        }
    }
    
    return [
        'event' => $event_type,
        'dispatched' => $dispatched,
        'failed' => $failed
    ];
}

/**
 * Send webhook request
 */
function send_webhook_request($webhook_id, $url, $event_type, $payload) {
    global $conn;
    
    $timestamp = date('Y-m-d H:i:s');
    $signature = hash_hmac('sha256', json_encode($payload) . $timestamp, 'webhook_secret');
    
    $headers = [
        'Content-Type: application/json',
        'X-Webhook-Event: ' . $event_type,
        'X-Webhook-Timestamp: ' . $timestamp,
        'X-Webhook-Signature: ' . $signature
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    // Log webhook dispatch
    $log_query = "INSERT INTO webhook_logs (webhook_id, event_type, payload, status_code, response)
                  VALUES (?, ?, ?, ?, ?)";
    
    $payload_json = json_encode($payload);
    $log_stmt = $conn->prepare($log_query);
    $log_stmt->bind_param('issss', $webhook_id, $event_type, $payload_json, $http_code, $response);
    $log_stmt->execute();
    
    return $http_code >= 200 && $http_code < 300;
}

/**
 * Get webhook logs
 */
function get_webhook_logs($webhook_id, $limit = 50) {
    global $conn;
    
    $query = "SELECT event_type, status_code, triggered_at
              FROM webhook_logs
              WHERE webhook_id = ?
              ORDER BY triggered_at DESC
              LIMIT ?";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param('ii', $webhook_id, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $logs = [];
    while ($row = $result->fetch_assoc()) {
        $logs[] = $row;
    }
    
    return $logs;
}

/**
 * API Endpoint
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'register' && isset($_POST['user_id'], $_POST['url'], $_POST['events'])) {
        $user_id = (int)$_POST['user_id'];
        $url = $_POST['url'];
        $events = $_POST['events'];
        $description = $_POST['description'] ?? '';
        
        $result = register_webhook($user_id, $url, $events, $description);
        echo json_encode($result);
        
    } elseif ($action === 'list' && isset($_POST['user_id'])) {
        $user_id = (int)$_POST['user_id'];
        $webhooks = get_user_webhooks($user_id);
        echo json_encode(['success' => true, 'webhooks' => $webhooks]);
        
    } elseif ($action === 'delete' && isset($_POST['webhook_id'], $_POST['user_id'])) {
        $webhook_id = (int)$_POST['webhook_id'];
        $user_id = (int)$_POST['user_id'];
        
        $success = delete_webhook($webhook_id, $user_id);
        echo json_encode(['success' => $success]);
        
    } elseif ($action === 'dispatch' && isset($_POST['event_type'], $_POST['payload'])) {
        $event = $_POST['event_type'];
        $payload = json_decode($_POST['payload'], true);
        
        if (!$payload) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid payload JSON']);
            exit;
        }
        
        $webhook_id = isset($_POST['webhook_id']) ? (int)$_POST['webhook_id'] : null;
        $result = dispatch_webhook($event, $payload, $webhook_id);
        echo json_encode(['success' => true, 'result' => $result]);
        
    } elseif ($action === 'logs' && isset($_POST['webhook_id'])) {
        $webhook_id = (int)$_POST['webhook_id'];
        $limit = (int)($_POST['limit'] ?? 50);
        
        $logs = get_webhook_logs($webhook_id, $limit);
        echo json_encode(['success' => true, 'logs' => $logs]);
        
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
?>
