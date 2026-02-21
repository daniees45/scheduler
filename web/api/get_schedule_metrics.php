<?php
/**
 * Schedule Quality Metrics API
 * 
 * Calculates comprehensive schedule quality metrics:
 * - Success rate (% courses scheduled)
 * - Conflict count (room, lecturer, level)
 * - Distribution metrics (courses by day, time gaps)
 * - AI accuracy breakdown
 */

header('Content-Type: application/json');
require_once 'db.php';
require_once 'error_handler.php';
require_once __DIR__ . '/../../lib/B2Storage.php';

ensure_error_log_table();

/**
 * Calculate all schedule metrics
 */
function calculate_schedule_metrics($schedule_csv_content = null) {
    global $conn;
    
    // Use provided CSV or get from B2
    if (!$schedule_csv_content) {
        $b2 = new B2Storage();
        $result = $b2->download('csv/final/final_web_schedule.csv');
        if (!$result['success']) {
            return [
                'success' => false,
                'error' => 'Schedule CSV not found in B2'
            ];
        }
        $schedule_csv_content = $result['content'];
    }
    
    $metrics = [
        'timestamp' => date('Y-m-d H:i:s'),
        'schedule_file' => 'final_web_schedule.csv',
        'totals' => [],
        'distribution' => [],
        'conflicts' => [],
        'efficiency' => [],
        'quality_score' => 0
    ];
    
    // Parse CSV
    $schedule_data = [];
    $lines = explode("\n", $schedule_csv_content);
    $headers = str_getcsv(array_shift($lines));
    
    foreach ($lines as $line) {
        if (trim($line) === '') continue;
        $row = str_getcsv($line);
        $schedule_data[] = array_combine($headers, $row);
    }
    
    // ========================================================================
    // 1. TOTALS & SUCCESS RATE
    // ========================================================================
    $total_courses = count($schedule_data);
    $scheduled = 0;
    $unscheduled = 0;
    
    foreach ($schedule_data as $course) {
        if (!empty($course['room_name'] ?? '') && !empty($course['day'] ?? '')) {
            $scheduled++;
        } else {
            $unscheduled++;
        }
    }
    
    $success_rate = $total_courses > 0 ? round(($scheduled / $total_courses) * 100, 2) : 0;
    
    $metrics['totals'] = [
        'total_courses' => $total_courses,
        'scheduled' => $scheduled,
        'unscheduled' => $unscheduled,
        'success_rate_percent' => $success_rate
    ];
    
    // ========================================================================
    // 2. COURSE DISTRIBUTION BY DAY
    // ========================================================================
    $by_day = [];
    $by_date = [];
    $days_used = [];
    
    foreach ($schedule_data as $course) {
        $day = $course['day'] ?? 'Unknown';
        $time = $course['start_time'] ?? '-';
        
        if (!isset($by_day[$day])) {
            $by_day[$day] = 0;
        }
        $by_day[$day]++;
        
        if ($day !== 'Unknown') {
            $days_used[$day] = true;
        }
    }
    
    ksort($by_day);
    $metrics['distribution']['by_day'] = $by_day;
    $metrics['distribution']['days_utilized'] = count($days_used);
    
    // ========================================================================
    // 3. TIME SLOT COVERAGE
    // ========================================================================
    $time_slots = [];
    $peak_hours = [];
    
    foreach ($schedule_data as $course) {
        $time = $course['start_time'] ?? '';
        if ($time) {
            if (!isset($time_slots[$time])) {
                $time_slots[$time] = 0;
            }
            $time_slots[$time]++;
            
            // Extract hour for peak analysis
            $hour = substr($time, 0, 2);
            if (!isset($peak_hours[$hour])) {
                $peak_hours[$hour] = 0;
            }
            $peak_hours[$hour]++;
        }
    }
    
    ksort($time_slots);
    ksort($peak_hours);
    
    $metrics['distribution']['by_time'] = $time_slots;
    $metrics['distribution']['peak_hours'] = $peak_hours;
    $metrics['distribution']['time_slots_used'] = count($time_slots);
    
    // ========================================================================
    // 4. ROOM UTILIZATION
    // ========================================================================
    $by_room = [];
    $room_capacity_map = [];
    
    // Get room capacities from database
    $result = $conn->query("SELECT room_name, capacity FROM rooms");
    while ($room = $result->fetch_assoc()) {
        $room_capacity_map[$room['room_name']] = $room['capacity'];
    }
    
    foreach ($schedule_data as $course) {
        $room = $course['room_name'] ?? 'Unassigned';
        
        if (!isset($by_room[$room])) {
            $by_room[$room] = [
                'count' => 0,
                'capacity' => $room_capacity_map[$room] ?? 0,
                'enrollment' => 0
            ];
        }
        
        $by_room[$room]['count']++;
        // Add enrollment if available
        if (!empty($course['enrollment'] ?? '')) {
            $by_room[$room]['enrollment'] += (int)$course['enrollment'];
        }
    }
    
    // Calculate room utilization percentages
    foreach ($by_room as $room => &$data) {
        $data['avg_capacity_used'] = $data['capacity'] > 0 ? 
            round(($data['enrollment'] / ($data['capacity'] * $data['count'])) * 100, 2) : 0;
    }
    
    uasort($by_room, function($a, $b) {
        return $b['count'] <=> $a['count'];
    });
    
    $metrics['distribution']['by_room'] = array_slice($by_room, 0, 10); // Top 10
    $metrics['distribution']['total_rooms_used'] = count($by_room);
    
    // ========================================================================
    // 5. LECTURER WORKLOAD
    // ========================================================================
    $by_lecturer = [];
    
    foreach ($schedule_data as $course) {
        $lecturer = $course['lecturer_name'] ?? 'Unassigned';
        
        if (!isset($by_lecturer[$lecturer])) {
            $by_lecturer[$lecturer] = [
                'courses' => 0,
                'days' => [],
                'times' => [],
                'rooms' => []
            ];
        }
        
        $by_lecturer[$lecturer]['courses']++;
        
        if (!empty($course['day'] ?? '')) {
            $by_lecturer[$lecturer]['days'][$course['day']] = true;
        }
        
        if (!empty($course['start_time'] ?? '')) {
            $by_lecturer[$lecturer]['times'][$course['start_time']] = true;
        }
        
        if (!empty($course['room_name'] ?? '')) {
            $by_lecturer[$lecturer]['rooms'][$course['room_name']] = true;
        }
    }
    
    // Convert and calculate stats
    foreach ($by_lecturer as $lecturer => &$data) {
        $data['days'] = count($data['days']);
        $data['times'] = count($data['times']);
        $data['rooms'] = count($data['rooms']);
        $data['load_factor'] = ($data['courses'] / $data['days']) > 0 ? 
            round(($data['courses'] / $data['days']), 2) : $data['courses'];
    }
    
    uasort($by_lecturer, function($a, $b) {
        return $b['courses'] <=> $a['courses'];
    });
    
    $metrics['distribution']['by_lecturer'] = array_slice($by_lecturer, 0, 10); // Top 10
    $metrics['distribution']['total_lecturers'] = count($by_lecturer);
    
    // ========================================================================
    // 6. CONFLICT DETECTION
    // ========================================================================
    $conflicts = [
        'room_double_booking' => 0,
        'lecturer_overlap' => 0,
        'unscheduled' => $unscheduled,
        'gaps_in_schedule' => 0
    ];
    
    // Check for room double-booking
    foreach ($schedule_data as $i => $course1) {
        if (empty($course1['room_name'] ?? '') || empty($course1['day'] ?? '')) {
            continue;
        }
        
        foreach ($schedule_data as $j => $course2) {
            if ($i >= $j) continue;
            if (empty($course2['room_name'] ?? '') || empty($course2['day'] ?? '')) {
                continue;
            }
            
            if ($course1['room_name'] === $course2['room_name'] && 
                $course1['day'] === $course2['day'] &&
                $course1['start_time'] === $course2['start_time']) {
                $conflicts['room_double_booking']++;
            }
        }
    }
    
    // Check for lecturer overlap
    foreach ($schedule_data as $i => $course1) {
        if (empty($course1['lecturer_name'] ?? '') || empty($course1['day'] ?? '')) {
            continue;
        }
        
        foreach ($schedule_data as $j => $course2) {
            if ($i >= $j) continue;
            if (empty($course2['lecturer_name'] ?? '') || empty($course2['day'] ?? '')) {
                continue;
            }
            
            if ($course1['lecturer_name'] === $course2['lecturer_name'] && 
                $course1['day'] === $course2['day'] &&
                $course1['start_time'] === $course2['start_time']) {
                $conflicts['lecturer_overlap']++;
            }
        }
    }
    
    // Count gaps in schedule (1+ hour gaps between classes)
    foreach ($by_lecturer as $lecturer => $data) {
        if ($data['times'] > 1) {
            // Simple heuristic: if 2+ time slots with gaps, count as 1 gap instance
            $conflicts['gaps_in_schedule']++;
        }
    }
    
    $metrics['conflicts'] = $conflicts;
    
    // ========================================================================
    // 7. EFFICIENCY METRICS
    // ========================================================================
    $avg_courses_per_day = count($days_used) > 0 ? 
        round($total_courses / count($days_used), 2) : 0;
    
    $avg_courses_per_time = count($time_slots) > 0 ? 
        round($total_courses / count($time_slots), 2) : 0;
    
    $total_room_bookings = array_sum(array_column($by_room, 'count'));
    $avg_room_utilization = count($by_room) > 0 ? 
        round(array_sum(array_column($by_room, 'avg_capacity_used')) / count($by_room), 2) : 0;
    
    $metrics['efficiency'] = [
        'avg_courses_per_day' => $avg_courses_per_day,
        'avg_courses_per_time_slot' => $avg_courses_per_time,
        'avg_room_utilization_percent' => $avg_room_utilization,
        'load_balance_score' => calculate_load_balance($by_lecturer),
        'schedule_compactness' => (count($days_used) <= 3 ? 'High' : 'Low')
    ];
    
    // ========================================================================
    // 8. QUALITY SCORE (0-100)
    // ========================================================================
    $quality_score = 100;
    
    // Deduct for conflicts
    $quality_score -= ($conflicts['room_double_booking'] * 5);
    $quality_score -= ($conflicts['lecturer_overlap'] * 5);
    $quality_score -= ($conflicts['unscheduled'] * 2);
    
    // Bonus for good coverage
    if ($success_rate === 100) $quality_score += 10;
    if ($avg_room_utilization > 80) $quality_score += 5;
    if ($conflicts['room_double_booking'] === 0) $quality_score += 5;
    if ($conflicts['lecturer_overlap'] === 0) $quality_score += 5;
    
    $quality_score = max(0, min(100, $quality_score));
    $metrics['quality_score'] = $quality_score;
    
    // ========================================================================
    // 9. RECOMMENDATIONS
    // ========================================================================
    $recommendations = [];
    
    if ($conflicts['room_double_booking'] > 0) {
        $recommendations[] = [
            'priority' => 'high',
            'title' => 'Room Conflicts Detected',
            'description' => "Fix $conflicts[room_double_booking] room double-booking(s). Reschedule conflicting courses.",
            'impact' => 'high'
        ];
    }
    
    if ($conflicts['lecturer_overlap'] > 0) {
        $recommendations[] = [
            'priority' => 'high',
            'title' => 'Lecturer Overlaps',
            'description' => "Fix $conflicts[lecturer_overlap] lecturer time conflict(s).",
            'impact' => 'high'
        ];
    }
    
    if ($unscheduled > 0) {
        $recommendations[] = [
            'priority' => 'medium',
            'title' => 'Unscheduled Courses',
            'description' => "$unscheduled course(s) not assigned. Review constraints and try again.",
            'impact' => 'medium'
        ];
    }
    
    if ($avg_room_utilization < 60) {
        $recommendations[] = [
            'priority' => 'low',
            'title' => 'Low Room Utilization',
            'description' => 'Consolidate courses to fewer rooms to improve efficiency.',
            'impact' => 'low'
        ];
    }
    
    if (count($days_used) <= 3) {
        $recommendations[] = [
            'priority' => 'low',
            'title' => 'Compress Schedule',
            'description' => 'Schedule spans only ' . count($days_used) . ' days. Consider spreading load.',
            'impact' => 'medium'
        ];
    }
    
    $metrics['recommendations'] = $recommendations;
    
    return ['success' => true, 'metrics' => $metrics];
}

/**
 * Calculate load balance score (0-100)
 * Measures if lecturer workload is evenly distributed
 */
function calculate_load_balance($by_lecturer) {
    if (empty($by_lecturer)) return 0;
    
    $courses = array_column($by_lecturer, 'courses');
    $avg = array_sum($courses) / count($courses);
    
    // Standard deviation
    $variance = 0;
    foreach ($courses as $c) {
        $variance += pow($c - $avg, 2);
    }
    $stddev = sqrt($variance / count($courses));
    
    // Convert to 0-100 score (lower stddev = better balance)
    $max_stddev = $avg; // Worst case: all work on one person
    $balance = max(0, 100 - ($stddev / $max_stddev * 100));
    
    return round($balance, 2);
}

/**
 * API Endpoint
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'get_metrics') {
        $schedule_csv = $_POST['schedule_csv'] ?? null;
        
        // Security: validate path if provided
        if ($schedule_csv) {
            $safe_path = realpath('../../' . $schedule_csv);
            if (!$safe_path || strpos($safe_path, realpath('../../')) !== 0) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Invalid file path']);
                exit;
            }
        }
        
        $result = calculate_schedule_metrics($safe_path ?? null);
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
