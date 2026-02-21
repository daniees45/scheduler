<?php
/**
 * WebSocket Progress Tracking Server
 * 
 * Provides real-time progress updates during schedule generation:
 * - Phase tracking (validation → AI processing → import)
 * - Percentage completion
 * - Status messages
 * - Error notifications
 * - Client connection management
 */

header('Content-Type: application/json');
require_once 'db.php';

/**
 * Initialize progress tracking
 */
function init_progress_session($session_id) {
    global $conn;
    
    // Create progress tracking table if needed
    $conn->query("CREATE TABLE IF NOT EXISTS generation_progress (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_id VARCHAR(100) UNIQUE,
        user_id INT,
        started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        current_phase VARCHAR(100),
        percent_complete INT DEFAULT 0,
        status_message TEXT,
        error_message TEXT,
        last_update TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_session (session_id)
    )");
    
    $user_id = $_SESSION['user_id'] ?? 0;
    
    $query = "INSERT INTO generation_progress (session_id, user_id, current_phase, percent_complete)
              VALUES (?, ?, ?, ?)
              ON DUPLICATE KEY UPDATE
              current_phase = ?, percent_complete = ?";
    
    $stmt = $conn->prepare($query);
    $phase = 'initializing';
    $percent = 0;
    $stmt->bind_param('sisssi', $session_id, $user_id, $phase, $percent, $phase, $percent);
    
    return $stmt->execute();
}

/**
 * Update progress
 */
function update_progress($session_id, $phase, $percent, $message = '') {
    global $conn;
    
    $query = "UPDATE generation_progress 
              SET current_phase = ?, percent_complete = ?, status_message = ?, last_update = NOW()
              WHERE session_id = ?";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param('siss', $phase, $percent, $message, $session_id);
    
    return $stmt->execute();
}

/**
 * Update error
 */
function update_error($session_id, $error_message) {
    global $conn;
    
    $query = "UPDATE generation_progress 
              SET error_message = ?, percent_complete = -1, last_update = NOW()
              WHERE session_id = ?";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param('ss', $error_message, $session_id);
    
    return $stmt->execute();
}

/**
 * Get current progress
 */
function get_progress($session_id) {
    global $conn;
    
    $query = "SELECT * FROM generation_progress WHERE session_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('s', $session_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        return null;
    }
    
    return $result->fetch_assoc();
}

/**
 * Clean up old progress records
 */
function cleanup_old_progress($max_age_hours = 24) {
    global $conn;
    
    $query = "DELETE FROM generation_progress 
              WHERE last_update < DATE_SUB(NOW(), INTERVAL ? HOUR)";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param('i', $max_age_hours);
    
    return $stmt->execute();
}

/**
 * Simulate Server-Sent Events (SSE) endpoint
 */
function handle_sse_request() {
    $session_id = $_GET['session_id'] ?? '';
    
    if (empty($session_id)) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing session_id']);
        return;
    }
    
    // Set SSE headers
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('Access-Control-Allow-Origin: *');
    
    // Send initial connection message
    echo "event: connected\n";
    echo "data: " . json_encode(['message' => 'Connected to progress stream']) . "\n\n";
    flush();
    
    // Keep connection open for 5 minutes max
    $start_time = time();
    $max_duration = 300;
    $last_percent = -1;
    
    while (time() - $start_time < $max_duration) {
        $progress = get_progress($session_id);
        
        if ($progress) {
            // Send update if progress changed
            if ($progress['percent_complete'] !== $last_percent) {
                echo "event: progress\n";
                echo "data: " . json_encode($progress) . "\n\n";
                flush();
                
                $last_percent = $progress['percent_complete'];
                
                // Check if done or error
                if ($progress['percent_complete'] >= 100) {
                    echo "event: completed\n";
                    echo "data: " . json_encode(['message' => 'Generation completed']) . "\n\n";
                    flush();
                    break;
                }
                
                if (!empty($progress['error_message'])) {
                    echo "event: error\n";
                    echo "data: " . json_encode(['error' => $progress['error_message']]) . "\n\n";
                    flush();
                    break;
                }
            }
        }
        
        // Sleep to avoid excessive checking
        sleep(1);
        
        // Keep connection alive
        echo ": keep-alive\n\n";
        flush();
    }
    
    echo "event: closed\n";
    echo "data: " . json_encode(['message' => 'Connection closed']) . "\n\n";
    flush();
}

/**
 * API Endpoint (HTTP requests)
 */
$request_method = $_SERVER['REQUEST_METHOD'];

if ($request_method === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'init' && isset($_POST['session_id'])) {
        $session_id = $_POST['session_id'];
        $success = init_progress_session($session_id);
        echo json_encode(['success' => $success, 'session_id' => $session_id]);
        
    } elseif ($action === 'update' && isset($_POST['session_id'])) {
        $session_id = $_POST['session_id'];
        $phase = $_POST['phase'] ?? '';
        $percent = (int)($_POST['percent'] ?? 0);
        $message = $_POST['message'] ?? '';
        
        $success = update_progress($session_id, $phase, $percent, $message);
        echo json_encode(['success' => $success]);
        
    } elseif ($action === 'error' && isset($_POST['session_id'])) {
        $session_id = $_POST['session_id'];
        $error = $_POST['error'] ?? 'Unknown error';
        
        $success = update_error($session_id, $error);
        echo json_encode(['success' => $success]);
        
    } elseif ($action === 'get' && isset($_POST['session_id'])) {
        $session_id = $_POST['session_id'];
        $progress = get_progress($session_id);
        
        if ($progress) {
            echo json_encode(['success' => true, 'progress' => $progress]);
        } else {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Progress session not found']);
        }
        
    } elseif ($action === 'cleanup') {
        $max_age = (int)($_POST['max_age_hours'] ?? 24);
        cleanup_old_progress($max_age);
        echo json_encode(['success' => true, 'message' => 'Old progress records cleaned']);
        
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
    }
    
} elseif ($request_method === 'GET' && isset($_GET['sse'])) {
    handle_sse_request();
    
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
?>
