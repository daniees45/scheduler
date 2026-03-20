<?php
/**
 * Scan for Conflicts API
 * Triggers a fresh scan of the schedule for conflicts
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

// Check if user is admin
$user_role = $_SESSION['role'] ?? '';
if (!in_array($user_role, ['super_admin', 'faculty_admin'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Access denied. Admin privileges required.']);
    exit;
}

try {
    // Quick conflict detection from generated schedules
    $conflicts_found = 0;
    
    // Get the most recent schedule
    $query = "SELECT schedule_data FROM generated_schedules ORDER BY created_at DESC LIMIT 1";
    $result = $conn->query($query);
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $schedule_data = $row['schedule_data'];
        
        // If schedule data exists, parse it looking for conflicts
        if ($schedule_data) {
            // Parse CSV or JSON schedule data
            $lines = explode("\n", $schedule_data);
            $schedule_items = [];
            
            // Skip header and parse schedule
            for ($i = 1; $i < count($lines); $i++) {
                if (trim($lines[$i]) === '') continue;
                
                $parts = str_getcsv($lines[$i]);
                if (count($parts) >= 6) {
                    $schedule_items[] = [
                        'course' => $parts[0] ?? '',
                        'lecturer' => $parts[1] ?? '',
                        'day' => $parts[2] ?? '',
                        'time' => $parts[3] ?? '',
                        'room' => $parts[4] ?? '',
                        'level' => $parts[5] ?? ''
                    ];
                }
            }
            
            // Check for lecturer conflicts
            $lecturer_slots = [];
            foreach ($schedule_items as $item) {
                $key = $item['lecturer'] . '|' . $item['day'] . '|' . $item['time'];
                if (isset($lecturer_slots[$key])) {
                    $conflicts_found++;
                }
                $lecturer_slots[$key] = true;
            }
            
            // Check for room conflicts
            $room_slots = [];
            foreach ($schedule_items as $item) {
                if ($item['room']) {
                    $key = $item['room'] . '|' . $item['day'] . '|' . $item['time'];
                    if (isset($room_slots[$key])) {
                        $conflicts_found++;
                    }
                    $room_slots[$key] = true;
                }
            }
        }
    }
    
    // Log the scan action
    $user_id = $_SESSION['user_id'];
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $log_stmt = $conn->prepare("INSERT INTO audit_log (user_id, action, resource, status, details, ip_address) VALUES (?, 'SCAN_CONFLICTS', 'conflicts', 'success', ?, ?)");
    $details = json_encode(['conflicts_found' => $conflicts_found]);
    $log_stmt->bind_param('iss', $user_id, $details, $ip_address);
    $log_stmt->execute();
    
    // Return results
    echo json_encode([
        'status' => 'success',
        'message' => 'Conflict scan completed successfully',
        'conflicts_found' => $conflicts_found,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Scan failed: ' . $e->getMessage()
    ]);
}
?>
