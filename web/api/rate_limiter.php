<?php
/**
 * Rate Limiting API
 * 
 * Prevents API abuse by:
 * - Tracking request count per user
 * - Enforcing per-minute/per-hour limits
 * - Blocking excessive requests
 * - Providing rate limit headers
 */

header('Content-Type: application/json');
require_once 'db.php';
require_once 'error_handler.php';

// Default limits
define('RATE_LIMIT_PER_MINUTE', 30);
define('RATE_LIMIT_PER_HOUR', 500);
define('RATE_LIMIT_WINDOW', 60); // seconds

/**
 * Ensure rate limit table exists
 */
function ensure_rate_limit_table() {
    global $conn;
    
    $conn->query("CREATE TABLE IF NOT EXISTS api_rate_limit (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT,
        endpoint VARCHAR(100),
        request_count INT DEFAULT 0,
        window_start TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        window_reset TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        total_requests_hour INT DEFAULT 0,
        hour_reset TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        blocked_until TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE KEY unique_user_endpoint (user_id, endpoint),
        INDEX idx_user (user_id),
        INDEX idx_endpoint (endpoint)
    )");
}

/**
 * Check if request is allowed
 */
function check_rate_limit($user_id, $endpoint = 'default') {
    global $conn;
    
    ensure_rate_limit_table();
    
    $now = time();
    $minute_ago = $now - RATE_LIMIT_WINDOW;
    $hour_ago = $now - 3600;
    
    // Get or create rate limit record
    $query = "SELECT * FROM api_rate_limit 
              WHERE user_id = ? AND endpoint = ?";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param('is', $user_id, $endpoint);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        // Create new record
        $insert_query = "INSERT INTO api_rate_limit (user_id, endpoint, request_count, total_requests_hour)
                         VALUES (?, ?, 1, 1)";
        
        $insert_stmt = $conn->prepare($insert_query);
        $insert_stmt->bind_param('is', $user_id, $endpoint);
        $insert_stmt->execute();
        
        return [
            'allowed' => true,
            'requests_remaining_minute' => RATE_LIMIT_PER_MINUTE - 1,
            'requests_remaining_hour' => RATE_LIMIT_PER_HOUR - 1,
            'reset_time' => date('Y-m-d H:i:s', $now + RATE_LIMIT_WINDOW)
        ];
    }
    
    $record = $result->fetch_assoc();
    $window_start = strtotime($record['window_start']);
    $hour_start = strtotime($record['hour_reset']);
    
    // Reset minute window if expired
    if ($now - $window_start > RATE_LIMIT_WINDOW) {
        $reset_minute = "UPDATE api_rate_limit 
                        SET request_count = 1, window_start = NOW()
                        WHERE user_id = ? AND endpoint = ?";
        
        $reset_stmt = $conn->prepare($reset_minute);
        $reset_stmt->bind_param('is', $user_id, $endpoint);
        $reset_stmt->execute();
        
        $requests_minute = 1;
        $reset_minute_time = $now + RATE_LIMIT_WINDOW;
    } else {
        $requests_minute = $record['request_count'];
        $reset_minute_time = $window_start + RATE_LIMIT_WINDOW;
    }
    
    // Reset hour window if expired
    if ($now - $hour_start > 3600) {
        $reset_hour = "UPDATE api_rate_limit 
                      SET total_requests_hour = 1, hour_reset = NOW()
                      WHERE user_id = ? AND endpoint = ?";
        
        $reset_stmt = $conn->prepare($reset_hour);
        $reset_stmt->bind_param('is', $user_id, $endpoint);
        $reset_stmt->execute();
        
        $requests_hour = 1;
        $reset_hour_time = $now + 3600;
    } else {
        $requests_hour = $record['total_requests_hour'];
        $reset_hour_time = $hour_start + 3600;
    }
    
    // Check if blocked
    if ($record['blocked_until'] && strtotime($record['blocked_until']) > $now) {
        return [
            'allowed' => false,
            'reason' => 'Rate limit exceeded',
            'blocked_until' => $record['blocked_until'],
            'requests_remaining_minute' => 0,
            'requests_remaining_hour' => 0
        ];
    }
    
    // Check limits
    if ($requests_minute >= RATE_LIMIT_PER_MINUTE) {
        // Block for 5 minutes
        $block_until = date('Y-m-d H:i:s', $now + 300);
        
        $block_query = "UPDATE api_rate_limit 
                       SET blocked_until = ? 
                       WHERE user_id = ? AND endpoint = ?";
        
        $block_stmt = $conn->prepare($block_query);
        $block_stmt->bind_param('sis', $block_until, $user_id, $endpoint);
        $block_stmt->execute();
        
        log_rate_limit_violation($user_id, $endpoint, 'minute');
        
        return [
            'allowed' => false,
            'reason' => 'Minute rate limit exceeded (' . RATE_LIMIT_PER_MINUTE . ' requests)',
            'blocked_until' => $block_until,
            'requests_remaining_minute' => 0,
            'requests_remaining_hour' => max(0, RATE_LIMIT_PER_HOUR - $requests_hour)
        ];
    }
    
    if ($requests_hour >= RATE_LIMIT_PER_HOUR) {
        // Block for 10 minutes
        $block_until = date('Y-m-d H:i:s', $now + 600);
        
        $block_query = "UPDATE api_rate_limit 
                       SET blocked_until = ? 
                       WHERE user_id = ? AND endpoint = ?";
        
        $block_stmt = $conn->prepare($block_query);
        $block_stmt->bind_param('sis', $block_until, $user_id, $endpoint);
        $block_stmt->execute();
        
        log_rate_limit_violation($user_id, $endpoint, 'hour');
        
        return [
            'allowed' => false,
            'reason' => 'Hourly rate limit exceeded (' . RATE_LIMIT_PER_HOUR . ' requests)',
            'blocked_until' => $block_until,
            'requests_remaining_minute' => 0,
            'requests_remaining_hour' => 0
        ];
    }
    
    // Increment counters
    $increment_query = "UPDATE api_rate_limit 
                      SET request_count = request_count + 1,
                          total_requests_hour = total_requests_hour + 1
                      WHERE user_id = ? AND endpoint = ?";
    
    $increment_stmt = $conn->prepare($increment_query);
    $increment_stmt->bind_param('is', $user_id, $endpoint);
    $increment_stmt->execute();
    
    return [
        'allowed' => true,
        'requests_remaining_minute' => max(0, RATE_LIMIT_PER_MINUTE - $requests_minute - 1),
        'requests_remaining_hour' => max(0, RATE_LIMIT_PER_HOUR - $requests_hour - 1),
        'reset_time_minute' => date('Y-m-d H:i:s', $reset_minute_time),
        'reset_time_hour' => date('Y-m-d H:i:s', $reset_hour_time)
    ];
}

/**
 * Log rate limit violations
 */
function log_rate_limit_violation($user_id, $endpoint, $window_type) {
    global $conn;
    
    $conn->query("CREATE TABLE IF NOT EXISTS rate_limit_violations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT,
        endpoint VARCHAR(100),
        window_type VARCHAR(10),
        violation_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");
    
    $query = "INSERT INTO rate_limit_violations (user_id, endpoint, window_type)
              VALUES (?, ?, ?)";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param('iss', $user_id, $endpoint, $window_type);
    $stmt->execute();
}

/**
 * Get rate limit status for user
 */
function get_rate_limit_status($user_id) {
    global $conn;
    
    ensure_rate_limit_table();
    
    $query = "SELECT endpoint, request_count, total_requests_hour, blocked_until, window_start, hour_reset
              FROM api_rate_limit
              WHERE user_id = ?
              ORDER BY endpoint";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $status = [];
    while ($row = $result->fetch_assoc()) {
        $now = time();
        $window_start = strtotime($row['window_start']);
        $hour_start = strtotime($row['hour_reset']);
        
        $status[] = [
            'endpoint' => $row['endpoint'],
            'requests_this_minute' => (($now - $window_start) > RATE_LIMIT_WINDOW) ? 0 : $row['request_count'],
            'requests_this_hour' => (($now - $hour_start) > 3600) ? 0 : $row['total_requests_hour'],
            'is_blocked' => $row['blocked_until'] && strtotime($row['blocked_until']) > $now,
            'blocked_until' => $row['blocked_until']
        ];
    }
    
    return $status;
}

/**
 * Reset rate limits for user (admin only)
 */
function reset_user_limits($user_id) {
    global $conn;
    
    $query = "UPDATE api_rate_limit 
              SET request_count = 0, 
                  total_requests_hour = 0,
                  blocked_until = NULL,
                  window_start = NOW(),
                  hour_reset = NOW()
              WHERE user_id = ?";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param('i', $user_id);
    
    return $stmt->execute();
}

/**
 * API Endpoint
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'check' && isset($_POST['user_id'])) {
        $user_id = (int)$_POST['user_id'];
        $endpoint = $_POST['endpoint'] ?? 'default';
        
        $result = check_rate_limit($user_id, $endpoint);
        
        // Send rate limit headers
        header('X-RateLimit-Limit-Minute: ' . RATE_LIMIT_PER_MINUTE);
        header('X-RateLimit-Limit-Hour: ' . RATE_LIMIT_PER_HOUR);
        header('X-RateLimit-Remaining-Minute: ' . $result['requests_remaining_minute']);
        header('X-RateLimit-Remaining-Hour: ' . $result['requests_remaining_hour']);
        
        if (!$result['allowed']) {
            http_response_code(429); // Too Many Requests
        }
        
        echo json_encode($result);
        
    } elseif ($action === 'status' && isset($_POST['user_id'])) {
        $user_id = (int)$_POST['user_id'];
        $status = get_rate_limit_status($user_id);
        echo json_encode(['success' => true, 'status' => $status]);
        
    } elseif ($action === 'reset' && isset($_POST['user_id'])) {
        // Verify admin role (should be checked in actual implementation)
        $user_id = (int)$_POST['user_id'];
        $success = reset_user_limits($user_id);
        echo json_encode(['success' => $success]);
        
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
?>
