<?php
/**
 * Resolve Conflict API
 * Handles conflict resolution actions
 */

session_start();
header('Content-Type: application/json');
require_once 'db.php';

// Check authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

// Ensure table exists for conflict tracking
function ensureConflictTable($conn) {
    $sql = "CREATE TABLE IF NOT EXISTS conflict_resolutions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        conflict_id VARCHAR(255),
        admin_id INT NOT NULL,
        action VARCHAR(50),
        status VARCHAR(50),
        resolution_details TEXT,
        resolved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_conflict (conflict_id)
    )";
    
    $conn->query($sql);
}

// Main execution
try {
    ensureConflictTable($conn);
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['conflict_id']) || !isset($input['action'])) {
        throw new Exception('Missing required parameters');
    }
    
    $conflict_id = trim($input['conflict_id']);
    $action = trim($input['action']);
    $status = isset($input['status']) ? trim($input['status']) : 'pending';
    $admin_id = intval($_SESSION['user_id']);
    
    // Validate action
    if (!in_array($action, ['accepted', 'rejected', 'pending_revision'])) {
        throw new Exception('Invalid action');
    }
    
    // Log the resolution
    $query = "INSERT INTO conflict_resolutions (conflict_id, admin_id, action, status, resolution_details) 
              VALUES (?, ?, ?, ?, ?)";
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    $details = json_encode($input);
    $stmt->bind_param('sisss', $conflict_id, $admin_id, $action, $status, $details);
    
    if (!$stmt->execute()) {
        throw new Exception("Execute failed: " . $stmt->error);
    }
    
    // Log to audit trail
    $audit_query = "INSERT INTO audit_log (user_id, action, details, ip_address) 
                    VALUES (?, ?, ?, ?)";
    $audit_stmt = $conn->prepare($audit_query);
    if ($audit_stmt) {
        $action_type = 'CONFLICT_' . strtoupper($action);
        $details_json = json_encode(['conflict_id' => $conflict_id, 'status' => $status]);
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $audit_stmt->bind_param('isss', $admin_id, $action_type, $details_json, $ip);
        $audit_stmt->execute();
    }
    
    echo json_encode([
        'status' => 'success',
        'message' => 'Conflict resolution recorded',
        'conflict_id' => $conflict_id,
        'action_taken' => $action,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
?>
