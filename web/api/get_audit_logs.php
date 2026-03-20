<?php
/**
 * Get Audit Logs API
 * Returns system-wide activity logs
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

try {
    // Fetch latest audit logs
    $query = "SELECT 
                al.id,
                al.user_id,
                COALESCE(u.full_name, u.username, al.username, 'System') as user_name,
                al.action,
                al.resource as entity_type,
                al.resource_id as entity_id,
                al.status,
                al.details,
                al.ip_address,
                al.user_agent,
                al.log_time
              FROM audit_log al
              LEFT JOIN users u ON al.user_id = u.id
              ORDER BY al.log_time DESC
              LIMIT 500";
    
    $result = $conn->query($query);
    
    if (!$result) {
        throw new Exception("Query error: " . $conn->error);
    }
    
    $logs = [];
    while ($row = $result->fetch_assoc()) {
        // Ensure details is valid JSON
        if ($row['details'] && $row['details'][0] !== '{' && $row['details'][0] !== '[') {
            $row['details'] = json_encode(['text' => $row['details']]);
        }
        $logs[] = $row;
    }
    
    echo json_encode([
        'status' => 'success',
        'timestamp' => date('Y-m-d H:i:s'),
        'logs_count' => count($logs),
        'logs' => $logs
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Failed to fetch audit logs: ' . $e->getMessage()
    ]);
}
?>
