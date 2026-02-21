<?php
/**
 * Lecturer Workload Analysis API
 * 
 * Analyzes:
 * - Courses per lecturer
 * - Teaching load distribution
 * - Days/hours per lecturer
 * - Workload imbalance
 * - Overloaded lecturers
 */

header('Content-Type: application/json');
require_once 'db.php';
require_once __DIR__ . '/../../lib/B2Storage.php';

/**
 * Analyze lecturer workload
 */
function analyze_lecturer_workload($schedule_csv_content = null) {
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
        'lecturers_analyzed' => 0,
        'overall_balance_score' => 0,
        'average_load' => 0,
        'lecturer_details' => [],
        'workload_distribution' => [],
        'concerns' => [],
        'recommendations' => []
    ];
    
    // Parse schedule CSV
    $lecturer_data = [];
    $lines = explode("\n", $schedule_csv_content);
    $headers = str_getcsv(array_shift($lines));
    
    foreach ($lines as $line) {
        if (trim($line) === '') continue;
        $row = str_getcsv($line);
        $course = array_combine($headers, $row);
        $lecturer = $course['lecturer_name'] ?? 'Unassigned';
        
        if (empty($lecturer) || $lecturer === 'Unassigned') continue;
        
        if (!isset($lecturer_data[$lecturer])) {
            $lecturer_data[$lecturer] = [
                'courses' => 0,
                'credit_hours' => 0,
                'days' => [],
                'hours' => [],
                'rooms' => [],
                'departments' => [],
                'max_daily_load' => 0,
                'current_daily_load' => []
            ];
        }
        
        $lecturer_data[$lecturer]['courses']++;
        
        // Accumulate credit hours if available
        if (!empty($course['credit_hours'] ?? '')) {
            $lecturer_data[$lecturer]['credit_hours'] += (float)$course['credit_hours'];
        } else {
            $lecturer_data[$lecturer]['credit_hours'] += 3; // Default 3 credit hours
        }
        
        // Track days
        if (!empty($course['day'] ?? '')) {
            $day = $course['day'];
            $lecturer_data[$lecturer]['days'][$day] = true;
            
            // Track load per day
            if (!isset($lecturer_data[$lecturer]['current_daily_load'][$day])) {
                $lecturer_data[$lecturer]['current_daily_load'][$day] = 0;
            }
            $lecturer_data[$lecturer]['current_daily_load'][$day]++;
        }
        
        // Track teaching hours
        if (!empty($course['start_time'] ?? '')) {
            $hour = substr($course['start_time'], 0, 2);
            $lecturer_data[$lecturer]['hours'][$hour] = true;
        }
        
        // Track rooms
        if (!empty($course['room_name'] ?? '')) {
            $lecturer_data[$lecturer]['rooms'][$course['room_name']] = true;
        }
    }
    
    // Calculate stats
    $total_courses = 0;
    $workload_scores = [];
    
    foreach ($lecturer_data as $lecturer => &$data) {
        $data['days'] = count($data['days']);
        $data['hours'] = count($data['hours']);
        $data['rooms'] = count($data['rooms']);
        
        // Calculate max daily load
        $data['max_daily_load'] = max($data['current_daily_load']);
        $data['avg_daily_load'] = $data['days'] > 0 ? 
            round($data['courses'] / $data['days'], 2) : 0;
        
        // Workload score (based on courses per day)
        // Ideal: 2-3 courses per day
        if ($data['avg_daily_load'] >= 2 && $data['avg_daily_load'] <= 3) {
            $data['workload_assessment'] = 'Balanced';
            $data['workload_score'] = 100;
        } elseif ($data['avg_daily_load'] > 3 && $data['avg_daily_load'] <= 4) {
            $data['workload_assessment'] = 'Moderate Load';
            $data['workload_score'] = 80;
        } elseif ($data['avg_daily_load'] > 4) {
            $data['workload_assessment'] = 'Heavy Load';
            $data['workload_score'] = 60;
        } else {
            $data['workload_assessment'] = 'Light Load';
            $data['workload_score'] = 70;
        }
        
        $workload_scores[] = $data['workload_score'];
        $total_courses += $data['courses'];
        unset($data['current_daily_load']); // Remove temp array
    }
    
    // Sort by courses descending
    uasort($lecturer_data, function($a, $b) {
        return $b['courses'] <=> $a['courses'];
    });
    
    $analysis['lecturers_analyzed'] = count($lecturer_data);
    $analysis['average_load'] = count($lecturer_data) > 0 ? 
        round($total_courses / count($lecturer_data), 2) : 0;
    
    // Calculate balance score (how evenly distributed workload is)
    if (!empty($workload_scores)) {
        $avg_score = array_sum($workload_scores) / count($workload_scores);
        $variance = 0;
        foreach ($workload_scores as $score) {
            $variance += pow($score - $avg_score, 2);
        }
        $stddev = sqrt($variance / count($workload_scores));
        $analysis['overall_balance_score'] = round(100 - ($stddev / 10), 2); // Normalize
    }
    
    // Create workload distribution buckets
    $distribution = [
        'light' => 0,    // < 2 courses per day
        'balanced' => 0, // 2-3 courses per day
        'moderate' => 0, // 3-4 courses per day
        'heavy' => 0,    // > 4 courses per day
    ];
    
    foreach ($lecturer_data as $data) {
        if ($data['avg_daily_load'] < 2) $distribution['light']++;
        elseif ($data['avg_daily_load'] <= 3) $distribution['balanced']++;
        elseif ($data['avg_daily_load'] <= 4) $distribution['moderate']++;
        else $distribution['heavy']++;
    }
    
    $analysis['workload_distribution'] = $distribution;
    $analysis['lecturer_details'] = $lecturer_data;
    
    // ========================================================================
    // Identify concerns and recommendations
    // ========================================================================
    
    foreach ($lecturer_data as $lecturer => $data) {
        // Check for overload
        if ($data['avg_daily_load'] > 4) {
            $analysis['concerns'][] = [
                'type' => 'Overloaded Lecturer',
                'lecturer' => $lecturer,
                'courses' => $data['courses'],
                'avg_daily_load' => $data['avg_daily_load'],
                'severity' => 'high'
            ];
        }
        
        // Check for high daily peaks
        if ($data['max_daily_load'] >= 5) {
            $analysis['concerns'][] = [
                'type' => 'Peak Daily Load',
                'lecturer' => $lecturer,
                'peak_day_courses' => $data['max_daily_load'],
                'severity' => 'medium'
            ];
        }
        
        // Check for excessive room changes
        if ($data['rooms'] > $data['courses'] - 1) {
            $analysis['concerns'][] = [
                'type' => 'High Room Changes',
                'lecturer' => $lecturer,
                'courses' => $data['courses'],
                'rooms' => $data['rooms'],
                'severity' => 'low'
            ];
        }
    }
    
    // Suggestions
    if ($distribution['heavy'] > 0) {
        $analysis['recommendations'][] = [
            'priority' => 'high',
            'suggestion' => "Redistribute " . $distribution['heavy'] . " overloaded lecturer(s) to other staff",
            'impact' => 'Improve work-life balance and teaching quality'
        ];
    }
    
    if ($distribution['light'] > 0 && $distribution['heavy'] > 0) {
        $analysis['recommendations'][] = [
            'priority' => 'high',
            'suggestion' => "Balance workload: move courses from heavy to light-loaded lecturers",
            'impact' => 'Better utilization of staff resources'
        ];
    }
    
    if ($analysis['overall_balance_score'] < 70) {
        $analysis['recommendations'][] = [
            'priority' => 'medium',
            'suggestion' => "Consider constraints on lecturer availability to improve balance",
            'impact' => 'More equitable distribution'
        ];
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
        
        $result = analyze_lecturer_workload($safe_path ?? null);
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
