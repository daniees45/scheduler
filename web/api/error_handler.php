<?php
/**
 * Centralized Error Handler & Logger
 * 
 * Provides:
 * - Consistent error formatting
 * - File-based logging with rotation
 * - Database error logging (audit trail)
 * - Error recovery suggestions
 */

define('LOG_DIR', realpath(dirname(__FILE__) . '/../../logs'));
define('MAX_LOG_SIZE', 10 * 1024 * 1024); // 10MB

// Ensure logs directory exists
if (!is_dir(LOG_DIR)) {
    mkdir(LOG_DIR, 0755, true);
}

/**
 * Log an error to file and database
 */
function log_error($severity, $message, $file = '', $line = '', $context = '') {
    global $conn;
    
    $timestamp = date('Y-m-d H:i:s');
    $user_id = $_SESSION['user_id'] ?? null;
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    
    // Create log file path (daily rotation)
    $log_file = LOG_DIR . '/' . date('Y-m-d') . '_errors.log';
    
    // Check file size and rotate if needed
    if (file_exists($log_file) && filesize($log_file) > MAX_LOG_SIZE) {
        rename($log_file, $log_file . '.' . time());
    }
    
    // Format log entry
    $log_entry = sprintf(
        "[%s] [%s] [User: %s] [IP: %s] %s\n",
        $timestamp,
        $severity,
        $user_id ?? 'anonymous',
        $ip_address,
        $message
    );
    
    if ($file && $line) {
        $log_entry .= sprintf("  Location: %s:%s\n", $file, $line);
    }
    
    if ($context) {
        $log_entry .= sprintf("  Context: %s\n", $context);
        $log_entry .= str_repeat("-", 80) . "\n";
    }
    
    // Write to file
    @file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
    
    // Also log to database for audit trail (if connection available)
    if ($conn && isset($conn->connect_error) === false) {
        try {
            $msg_safe = $conn->real_escape_string($message);
            $context_safe = $conn->real_escape_string($context);
            
            $sql = "INSERT INTO error_log (severity, message, file, line, user_id, ip_address, context, created_at) 
                    VALUES ('$severity', '$msg_safe', '$file', '$line', " . ($user_id ? $user_id : 'NULL') . ", 
                    '$ip_address', '$context_safe', NOW())";
            
            $conn->query($sql);
        } catch (Exception $e) {
            // Silently fail if DB not available
        }
    }
}

/**
 * Generate user-friendly error message
 */
function get_error_message($error_code, $context = '') {
    $messages = [
        'CSV_INVALID_STRUCTURE' => 'CSV file has incorrect format. Please check column headers.',
        'CSV_INVALID_DATA' => 'CSV file contains invalid data. ' . $context,
        'DB_CONNECTION_ERROR' => 'Database connection failed. Please try again.',
        'FILE_NOT_FOUND' => 'File not found: ' . $context,
        'FILE_PERMISSION_ERROR' => 'Permission denied accessing file: ' . $context,
        'API_TIMEOUT' => 'AI Engine request timed out. Please try again.',
        'SCHEDULER_FAILED' => 'Schedule generation failed. ' . $context,
        'INVALID_PARAMETER' => 'Invalid parameter provided: ' . $context,
        'INSUFFICIENT_DATA' => 'Insufficient data to generate schedule. ' . $context,
        'UNKNOWN_ERROR' => 'An unexpected error occurred. Please contact support.'
    ];
    
    return $messages[$error_code] ?? $messages['UNKNOWN_ERROR'];
}

/**
 * Handle fatal errors during scheduling
 */
function handle_scheduling_error($error_type, $error_message, $recovery_action = null) {
    log_error('ERROR', 'Scheduling failed: ' . $error_message, '', '', 'error_type=' . $error_type);
    
    $response = [
        'success' => false,
        'error' => $error_message,
        'error_type' => $error_type,
        'recovery_suggestions' => get_recovery_suggestions($error_type),
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    if ($recovery_action) {
        $response['next_action'] = $recovery_action;
    }
    
    return $response;
}

/**
 * Suggest recovery action based on error type
 */
function get_recovery_suggestions($error_type) {
    $suggestions = [
        'CSV_INVALID_STRUCTURE' => [
            'Check that your CSV file has headers: course_code, course_title, lecturer_name, semester, day, start_time, end_time, room_name',
            'Use the Sample CSV from the Data Management page as a template',
            'Ensure all required columns are present'
        ],
        'CSV_INVALID_DATA' => [
            'Verify all course codes are non-empty',
            'Ensure all lecturers exist in the Lecturer Management page',
            'Check that room names match rooms in the Room Management page',
            'Ensure dates are in the correct format'
        ],
        'DB_CONNECTION_ERROR' => [
            'Ensure MySQL database is running',
            'Check your connection settings in api/db.php',
            'Verify the database user has correct permissions'
        ],
        'API_TIMEOUT' => [
            'The AI engine took too long to respond',
            'Try with fewer courses or simpler constraints',
            'Check that the Flask API server is running on port 5000',
            'Check server logs for performance issues'
        ],
        'SCHEDULER_FAILED' => [
            'Check the pre-flight validation for constraint conflicts',
            'Verify lecturer availability is realistic',
            'Ensure there are enough rooms for the courses',
            'Check that special room assignments are valid'
        ],
        'INSUFFICIENT_DATA' => [
            'Ensure you have uploaded courses data',
            'Verify lecturer availability data exists',
            'Check that classrooms are defined'
        ]
    ];
    
    return $suggestions[$error_type] ?? [
        'Contact the system administrator for assistance',
        'Review the system logs for detailed error information'
    ];
}

/**
 * Create error_log table if it doesn't exist
 */
function ensure_error_log_table() {
    global $conn;
    
    if (!$conn) return false;
    
    // Check if table exists
    $result = $conn->query("SHOW TABLES LIKE 'error_log'");
    if ($result && $result->num_rows > 0) {
        return true;
    }
    
    // Create table
    $sql = "CREATE TABLE IF NOT EXISTS error_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        severity VARCHAR(20),
        message TEXT,
        file VARCHAR(255),
        line INT,
        user_id INT,
        ip_address VARCHAR(45),
        context TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_severity (severity),
        INDEX idx_user_id (user_id),
        INDEX idx_created_at (created_at)
    )";
    
    return $conn->query($sql);
}

/**
 * Clean old error logs (older than 30 days)
 */
function cleanup_error_logs() {
    global $conn;
    
    // Clean database logs
    if ($conn) {
        $cutoff_date = date('Y-m-d H:i:s', strtotime('-30 days'));
        $conn->query("DELETE FROM error_log WHERE created_at < '$cutoff_date'");
    }
    
    // Clean file logs
    $log_files = glob(LOG_DIR . '/*.log');
    $cutoff_time = strtotime('-30 days');
    
    foreach ($log_files as $file) {
        if (filemtime($file) < $cutoff_time) {
            @unlink($file);
        }
    }
}

/**
 * Get recent errors for debugging
 */
function get_recent_errors($limit = 20, $severity = null) {
    global $conn;
    
    if (!$conn) return [];
    
    $sql = "SELECT * FROM error_log ";
    
    if ($severity) {
        $sql .= "WHERE severity = '$severity' ";
    }
    
    $sql .= "ORDER BY created_at DESC LIMIT $limit";
    
    $result = $conn->query($sql);
    if (!$result) return [];
    
    $errors = [];
    while ($row = $result->fetch_assoc()) {
        $errors[] = $row;
    }
    
    return $errors;
}

/**
 * API Endpoint: Get error logs for admin viewing
 */
/**
 * API Endpoint: Get error logs for admin viewing
 * Only execute if called directly
 */
if (basename(__FILE__) == basename($_SERVER["SCRIPT_FILENAME"])) {
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
        require_once 'db.php';
        
        $action = $_GET['action'];
        
        if ($action === 'get_logs') {
            // Require admin role
            if (($_SESSION['user_role'] ?? null) !== 'super_admin') {
                http_response_code(403);
                echo json_encode(['error' => 'Access denied']);
                exit;
            }
            
            $limit = (int)($_GET['limit'] ?? 20);
            $severity = $_GET['severity'] ?? null;
            
            ensure_error_log_table();
            $logs = get_recent_errors($limit, $severity);
            
            echo json_encode(['success' => true, 'logs' => $logs, 'count' => count($logs)]);
            
        } elseif ($action === 'cleanup') {
            // Require admin role
            if (($_SESSION['user_role'] ?? null) !== 'super_admin') {
                http_response_code(403);
                echo json_encode(['error' => 'Access denied']);
                exit;
            }
            
            cleanup_error_logs();
            echo json_encode(['success' => true, 'message' => 'Error logs cleaned']);
            
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action']);
        }
    }
}
?>
