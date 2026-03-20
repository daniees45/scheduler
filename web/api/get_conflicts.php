<?php
/**
 * Get Conflicts API
 * Returns detected scheduling conflicts with AI recommendations
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

/**
 * Detect conflicts from current schedule
 */
function detectConflicts($conn) {
    $conflicts = [];
    
    try {
        // Check for lecturer double-booking
        $query = "SELECT 
                    l.id, l.name,
                    c1.id as course1_id, c1.name as course1_name, c1.day as day1, c1.start_time as start1,
                    c2.id as course2_id, c2.name as course2_name, c2.day as day2, c2.start_time as start2
                  FROM lecturers l
                  JOIN courses c1 ON l.id = c1.lecturer_id
                  JOIN courses c2 ON l.id = c2.lecturer_id
                  WHERE c1.id < c2.id 
                  AND c1.day = c2.day
                  AND c1.start_time = c2.start_time
                  LIMIT 20";
        
        $result = $conn->query($query);
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $conflicts[] = [
                    'id' => md5($row['lecturer1_id'] . $row['day1'] . $row['start1']),
                    'type' => 'Lecturer Double-Booking',
                    'priority' => 'critical',
                    'description' => "Lecturer {$row['name']} booked for multiple classes at same time",
                    'involved_parties' => $row['name'],
                    'resource' => "{$row['course1_name']} + {$row['course2_name']}",
                    'time_slot' => "{$row['day1']} at {$row['start1']}",
                    'ai_recommendation' => 'Reschedule one course to a different time slot with no conflicts',
                    'status' => 'pending',
                    'detected_at' => date('Y-m-d H:i:s')
                ];
            }
        }
        
        // Check for room conflicts
        $query = "SELECT 
                    r.id, r.name,
                    c1.id as course1_id, c1.name as course1_name, 
                    c2.id as course2_id, c2.name as course2_name,
                    c1.day, c1.start_time
                  FROM rooms r
                  JOIN courses c1 ON r.id = c1.room_id
                  JOIN courses c2 ON r.id = c2.room_id
                  WHERE c1.id < c2.id
                  AND c1.day = c2.day
                  AND c1.start_time = c2.start_time
                  LIMIT 20";
        
        $result = $conn->query($query);
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $conflicts[] = [
                    'id' => md5($row['room_id'] . $row['day'] . $row['start_time']),
                    'type' => 'Room Conflict',
                    'priority' => 'critical',
                    'description' => "Room {$row['name']} double-booked for multiple courses",
                    'involved_parties' => $row['name'],
                    'resource' => "{$row['course1_name']} + {$row['course2_name']}",
                    'time_slot' => "{$row['day']} at {$row['start_time']}",
                    'ai_recommendation' => 'Allocate one course to an available room with sufficient capacity',
                    'status' => 'pending',
                    'detected_at' => date('Y-m-d H:i:s')
                ];
            }
        }
        
        // Check for capacity violations
        $query = "SELECT 
                    c.id, c.name, c.enrollment, r.name as room_name, r.capacity
                  FROM courses c
                  JOIN rooms r ON c.room_id = r.id
                  WHERE c.enrollment > r.capacity
                  LIMIT 20";
        
        $result = $conn->query($query);
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $deficit = $row['enrollment'] - $row['capacity'];
                $conflicts[] = [
                    'id' => md5($row['id'] . 'capacity'),
                    'type' => 'Capacity Exceeded',
                    'priority' => 'high',
                    'description' => "Course enrollment ({$row['enrollment']}) exceeds room capacity ({$row['capacity']})",
                    'involved_parties' => $row['name'],
                    'resource' => $row['room_name'],
                    'time_slot' => 'N/A',
                    'ai_recommendation' => "Allocate larger room or split course into multiple sections (need space for {$deficit} more students)",
                    'status' => 'pending',
                    'detected_at' => date('Y-m-d H:i:s')
                ];
            }
        }
        
        // Check for time slot violations (e.g., high-credit courses in restricted slots)
        $query = "SELECT 
                    c.id, c.name, c.credit_hours, c.day, c.start_time, r.name as room_name
                  FROM courses c
                  JOIN rooms r ON c.room_id = r.id
                  WHERE (c.credit_hours >= 2 AND c.start_time LIKE '%5:00%')
                  OR (c.credit_hours >= 2 AND c.start_time LIKE '%6:00%')
                  LIMIT 15";
        
        $result = $conn->query($query);
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $conflicts[] = [
                    'id' => md5($row['id'] . 'timeslot'),
                    'type' => 'Time Slot Violation',
                    'priority' => 'medium',
                    'description' => "{$row['credit_hours']} credit-hour course scheduled in restricted evening slot",
                    'involved_parties' => $row['name'],
                    'resource' => $row['room_name'],
                    'time_slot' => "{$row['day']} at {$row['start_time']}",
                    'ai_recommendation' => 'Reschedule course to morning or afternoon slot per institutional policy',
                    'status' => 'pending',
                    'detected_at' => date('Y-m-d H:i:s')
                ];
            }
        }
        
    } catch (Exception $e) {
        error_log("Conflict detection error: " . $e->getMessage());
    }
    
    return $conflicts;
}

// Main execution
try {
    $conflicts = detectConflicts($conn);
    
    echo json_encode([
        'status' => 'success',
        'timestamp' => date('Y-m-d H:i:s'),
        'conflicts_count' => count($conflicts),
        'conflicts' => $conflicts
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Failed to fetch conflicts: ' . $e->getMessage()
    ]);
}
?>
