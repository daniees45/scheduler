<?php
/**
 * Room Utilization Analysis API
 * 
 * Analyzes:
 * - Room occupancy rates
 * - Peak hours by room
 * - Room capacity efficiency
 * - Underutilized rooms
 */

header('Content-Type: application/json');
require_once 'db.php';
require_once __DIR__ . '/../../lib/B2Storage.php';

/**
 * Analyze room utilization
 */
function analyze_room_utilization($schedule_csv_content = null) {
    global $conn;
    
    if (!$schedule_csv_content) {
        $b2 = new B2Storage();
        $result = $b2->download('csv/final/final_web_schedule.csv');
        if (!$result['success']) {
            return ['success' => false, 'error' => 'Schedule CSV not found in B2'];
        }
        $schedule_csv_content = $result['content'];
    }
    
    $analysis = [
        'timestamp' => date('Y-m-d H:i:s'),
        'rooms_analyzed' => 0,
        'overall_utilization' => 0,
        'room_details' => [],
        'peak_hours' => [],
        'utilization_by_day' => [],
        'bottlenecks' => []
    ];
    
    // Get room capacities
    $room_capacities = [];
    $result = $conn->query("SELECT room_name, capacity FROM rooms");
    while ($room = $result->fetch_assoc()) {
        $room_capacities[$room['room_name']] = $room['capacity'];
    }
    
    // Parse schedule CSV
    $room_usage = [];
    $peak_by_hour = [];
    $peak_by_day = [];
    
    $lines = explode("\n", $schedule_csv_content);
    $headers = str_getcsv(array_shift($lines));
    
    foreach ($lines as $line) {
        if (trim($line) === '') continue;
        $row = str_getcsv($line);
        $course = array_combine($headers, $row);
        $room = $course['room_name'] ?? 'Unassigned';
        
        if (empty($room) || $room === 'Unassigned') continue;
        
        $day = $course['day'] ?? '';
        $time = $course['start_time'] ?? '';
        $enrollment = (int)($course['enrollment'] ?? 30);
        
        if (!isset($room_usage[$room])) {
            $room_usage[$room] = [
                'courses' => 0,
                'total_enrollment' => 0,
                'capacity' => $room_capacities[$room] ?? 0,
                'hours_used' => [],
                'days_used' => [],
                'peak_enrollment' => 0,
                'avg_enrollment' => 0,
                'peak_hour' => '',
                'peak_day' => ''
            ];
        }
        
        $room_usage[$room]['courses']++;
        $room_usage[$room]['total_enrollment'] += $enrollment;
        $room_usage[$room]['peak_enrollment'] = max($room_usage[$room]['peak_enrollment'], $enrollment);
        
        if (!empty($time)) {
            $room_usage[$room]['hours_used'][$time] = true;
            if (!isset($peak_by_hour[$time])) {
                $peak_by_hour[$time] = 0;
            }
            $peak_by_hour[$time]++;
        }
        
        if (!empty($day)) {
            $room_usage[$room]['days_used'][$day] = true;
            if (!isset($peak_by_day[$day])) {
                $peak_by_day[$day] = 0;
            }
            $peak_by_day[$day]++;
        }
    }
    
    // Calculate statistics
    $total_utilization = 0;
    $room_count = 0;
    
    foreach ($room_usage as $room => &$data) {
        $data['hours_used'] = count($data['hours_used']);
        $data['days_used'] = count($data['days_used']);
        $data['avg_enrollment'] = $data['courses'] > 0 ? 
            round($data['total_enrollment'] / $data['courses'], 1) : 0;
        
        // Utilization = (avg enrollment / capacity) * 100
        $data['utilization_percent'] = $data['capacity'] > 0 ? 
            round(($data['avg_enrollment'] / $data['capacity']) * 100, 2) : 0;
        
        // Efficiency = (actual bookings / potential slots)
        // Assume room available 8 hours/day, 5 days/week = 40 slots
        $potential_slots = $data['days_used'] * $data['hours_used'] > 0 ? 
            $data['days_used'] * 8 : 40;
        $data['efficiency_percent'] = round(($data['courses'] / $potential_slots) * 100, 2);
        
        // Status
        if ($data['utilization_percent'] > 85) {
            $data['status'] = 'High Demand';
        } elseif ($data['utilization_percent'] > 60) {
            $data['status'] = 'Good Utilization';
        } elseif ($data['utilization_percent'] > 30) {
            $data['status'] = 'Moderate Usage';
        } else {
            $data['status'] = 'Underutilized';
        }
        
        $total_utilization += $data['utilization_percent'];
        $room_count++;
    }
    
    // Sort by utilization descending
    uasort($room_usage, function($a, $b) {
        return $b['utilization_percent'] <=> $a['utilization_percent'];
    });
    
    $analysis['rooms_analyzed'] = $room_count;
    $analysis['overall_utilization'] = $room_count > 0 ? 
        round($total_utilization / $room_count, 2) : 0;
    $analysis['room_details'] = $room_usage;
    
    // Peak hours
    arsort($peak_by_hour);
    $analysis['peak_hours'] = array_slice($peak_by_hour, 0, 5);
    
    // Peak days
    arsort($peak_by_day);
    $analysis['utilization_by_day'] = $peak_by_day;
    
    // Identify bottlenecks
    foreach ($room_usage as $room => $data) {
        if ($data['utilization_percent'] > 90) {
            $analysis['bottlenecks'][] = [
                'type' => 'High Demand Room',
                'room' => $room,
                'utilization' => $data['utilization_percent'] . '%',
                'recommendation' => "Consider adding capacity or spreading load to other rooms"
            ];
        } elseif ($data['utilization_percent'] < 20 && $data['capacity'] > 50) {
            $analysis['bottlenecks'][] = [
                'type' => 'Underutilized Large Room',
                'room' => $room,
                'capacity' => $data['capacity'],
                'utilization' => $data['utilization_percent'] . '%',
                'recommendation' => "Use for larger classes or special events"
            ];
        }
    }
    
    return ['success' => true, 'analysis' => $analysis];
}

/**
 * API Endpoint
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'analyze') {
        $schedule_csv = $_POST['schedule_csv'] ?? null;
        
        if ($schedule_csv) {
            $safe_path = realpath('../../' . $schedule_csv);
            if (!$safe_path || strpos($safe_path, realpath('../../')) !== 0) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Invalid file path']);
                exit;
            }
        }
        
        $result = analyze_room_utilization($safe_path ?? null);
        echo json_encode($result);
        
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
?>
