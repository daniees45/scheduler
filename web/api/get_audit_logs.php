<?php
/**
 * Get Audit Logs API
 * Returns system-wide activity logs
 */

session_start();
header('Content-Type: application/json');
require_once 'db.php';

function audit_column_exists(mysqli $conn, string $table, string $column): bool {
    $tableEsc = $conn->real_escape_string($table);
    $colEsc = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `{$tableEsc}` LIKE '{$colEsc}'");
    return $result && $result->num_rows > 0;
}

// Check authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

try {
        $hasUserId = audit_column_exists($conn, 'audit_log', 'user_id');
        $hasUsername = audit_column_exists($conn, 'audit_log', 'username');
        $hasAction = audit_column_exists($conn, 'audit_log', 'action');
        $hasResource = audit_column_exists($conn, 'audit_log', 'resource');
        $hasEntityType = audit_column_exists($conn, 'audit_log', 'entity_type');
        $hasResourceId = audit_column_exists($conn, 'audit_log', 'resource_id');
        $hasEntityId = audit_column_exists($conn, 'audit_log', 'entity_id');
        $hasStatus = audit_column_exists($conn, 'audit_log', 'status');
        $hasDetails = audit_column_exists($conn, 'audit_log', 'details');
        $hasIp = audit_column_exists($conn, 'audit_log', 'ip_address');
        $hasUa = audit_column_exists($conn, 'audit_log', 'user_agent');
        $hasLogTime = audit_column_exists($conn, 'audit_log', 'log_time');

        $userIdExpr = $hasUserId ? 'al.user_id' : 'NULL';
        $usernameExpr = $hasUsername ? 'al.username' : 'NULL';
        $actionExpr = $hasAction ? 'al.action' : ($hasEntityType ? 'al.entity_type' : "'EVENT'");
        $entityTypeExpr = $hasResource ? 'al.resource' : ($hasEntityType ? 'al.entity_type' : 'NULL');
        $entityIdExpr = $hasResourceId ? 'al.resource_id' : ($hasEntityId ? 'al.entity_id' : 'NULL');
        $statusExpr = $hasStatus ? 'al.status' : "'info'";
        $detailsExpr = $hasDetails ? 'al.details' : "''";
        $ipExpr = $hasIp ? 'al.ip_address' : "''";
        $uaExpr = $hasUa ? 'al.user_agent' : "''";
        $timeExpr = $hasLogTime ? 'al.log_time' : 'NOW()';

        $joinUsersClause = $hasUserId ? 'LEFT JOIN users u ON al.user_id = u.id' : 'LEFT JOIN users u ON 1=0';
        $orderByExpr = $hasLogTime ? 'al.log_time DESC' : 'al.id DESC';

    // Fetch latest audit logs
    $query = "SELECT 
                al.id,
                                {$userIdExpr} as user_id,
                                COALESCE(u.full_name, u.username, {$usernameExpr}, 'System') as user_name,
                                {$actionExpr} as action,
                                {$entityTypeExpr} as entity_type,
                                {$entityIdExpr} as entity_id,
                                {$statusExpr} as status,
                                {$detailsExpr} as details,
                                {$ipExpr} as ip_address,
                                {$uaExpr} as user_agent,
                                {$timeExpr} as log_time
              FROM audit_log al
                            {$joinUsersClause}
                            ORDER BY {$orderByExpr}
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
