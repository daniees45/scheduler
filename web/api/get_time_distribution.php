<?php
/**
 * Time Distribution Analysis API
 * 
 * Analyzes:
 * - Peak hours and days
 * - Time slot utilization
 * - Load distribution across week
 * - Gaps and compression patterns
 * - Optimization opportunities
 */

header('Content-Type: application/json');
require_once 'db.php';
require_once __DIR__ . '/../../lib/B2Storage.php';

/**
 * Analyze time distribution
 */
function analyze_time_distribution($schedule_csv_content = null) {
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
        'total_courses' => 0,
        'days_in_use' => [],
        'hours_in_use' => [],
        'hourly_distribution' => [],
        'daily_distribution' => [],
        'peak_periods' => [],
        'compression_ratio' => 0.0,
        'efficiency_analysis' => [],
        'concerns' => [],
        'recommendations' => []
    ];
    
    // Parse schedule CSV
    $time_data = [
        'by_hour' => [],
        'by_day' => [],
        'by_day_hour' => [],
        'courses' => []
    ];
    
    $days_of_week = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
    $hours = [];
    
    // Initialize tracking
    foreach ($days_of_week as $day) {
        $time_data['by_day'][$day] = 0;
        $time_data['by_day_hour'][$day] = [];
    }
    
    $lines = explode("\n", $schedule_csv_content);
    $headers = str_getcsv(array_shift($lines));
    
    // Parse CSV
    foreach ($lines as $line) {
        if (trim($line) === '') continue;
        $row = str_getcsv($line);
        $course = array_combine($headers, $row);
        
        if (empty($course['day'] ?? '') || empty($course['start_time'] ?? '')) {
            continue;
        }
        
        $day = $course['day'];
        $start = $course['start_time'];
        $end = $course['end_time'] ?? '';
        
        // Extract hour
        $hour = substr($start, 0, 2);
        $hour = (int)$hour;
        
        // Track by hour
        if (!isset($time_data['by_hour'][$hour])) {
            $time_data['by_hour'][$hour] = 0;
        }
        $time_data['by_hour'][$hour]++;
        $hours[] = $hour;
        
        // Track by day
        $time_data['by_day'][$day]++;
        
        // Track by day-hour
        if (!isset($time_data['by_day_hour'][$day][$hour])) {
            $time_data['by_day_hour'][$day][$hour] = 0;
        }
        $time_data['by_day_hour'][$day][$hour]++;
        
        $analysis['total_courses']++;
        
        // Store course info for detailed analysis
        $time_data['courses'][] = [
            'name' => $course['course_code'] ?? '',
            'day' => $day,
            'start' => $start,
            'end' => $end,
            'duration' => $course['duration'] ?? '1'
        ];
    }
    
    // ========================================================================
    // Calculate distributions
    // ========================================================================
    
    // Hourly distribution
    $all_hours = range(6, 18); // Typical 6am - 6pm range
    foreach ($all_hours as $h) {
        $analysis['hourly_distribution'][$h] = $time_data['by_hour'][$h] ?? 0;
    }
    
    // Daily distribution
    foreach ($days_of_week as $day) {
        $analysis['daily_distribution'][$day] = $time_data['by_day'][$day] ?? 0;
    }
    
    // Days in use
    $analysis['days_in_use'] = array_filter($analysis['daily_distribution']);
    
    // Hours in use
    $analysis['hours_in_use'] = array_filter($analysis['hourly_distribution']);
    
    // ========================================================================
    // Peak period analysis
    // ========================================================================
    
    arsort($analysis['hourly_distribution']);
    $peak_hours = array_slice($analysis['hourly_distribution'], 0, 3, true);
    foreach ($peak_hours as $hour => $count) {
        $analysis['peak_periods'][] = [
            'hour' => sprintf('%02d:00', $hour),
            'courses' => $count,
            'percentage' => round(($count / $analysis['total_courses']) * 100, 1)
        ];
    }
    
    // ========================================================================
    // Compression Analysis
    // ========================================================================
    
    // How concentrated is the schedule?
    if (!empty($hours)) {
        $theoretical_max_hours = 13; // 6am to 6pm (typical)
        $actual_hours = count(array_unique($hours));
        $theoretical_max_days = 5; // Mon-Fri
        $actual_days = count(array_filter($analysis['daily_distribution']));
        
        // Compression ratio: how tight is everything packed?
        $time_compression = ($actual_hours / $theoretical_max_hours);
        $day_compression = ($actual_days / $theoretical_max_days);
        
        $analysis['compression_ratio'] = round(($time_compression + $day_compression) / 2, 2);
        
        $analysis['time_span'] = [
            'earliest_hour' => min($hours),
            'latest_hour' => max($hours),
            'span_hours' => max($hours) - min($hours) + 1,
            'days_used' => $actual_days,
            'days_total' => $theoretical_max_days,
        ];
    }
    
    // ========================================================================
    // Efficiency Analysis
    // ========================================================================
    
    // Peak hour load
    $max_concurrent_by_hour = [];
    foreach ($time_data['by_day_hour'] as $day => $hours_data) {
        foreach ($hours_data as $hour => $count) {
            if (!isset($max_concurrent_by_hour[$hour])) {
                $max_concurrent_by_hour[$hour] = 0;
            }
            $max_concurrent_by_hour[$hour] = max($max_concurrent_by_hour[$hour], $count);
        }
    }
    
    if (!empty($max_concurrent_by_hour)) {
        $max_load = max($max_concurrent_by_hour);
        $avg_load = array_sum($max_concurrent_by_hour) / count($max_concurrent_by_hour);
        
        $analysis['efficiency_analysis'] = [
            'peak_concurrent_courses' => $max_load,
            'average_concurrent_courses' => round($avg_load, 2),
            'load_balance_index' => ($avg_load > 0) ? round(($avg_load / $max_load) * 100, 1) : 0,
            'interpretation' => $avg_load / $max_load > 0.8 ? 'Good utilization' : 'Uneven distribution'
        ];
    }
    
    // ========================================================================
    // Gap Analysis
    // ========================================================================
    
    $gaps = [];
    $last_hour = null;
    $hours_sorted = sort($analysis['hours_in_use']);
    
    foreach (array_keys(array_filter($analysis['hourly_distribution'])) as $hour) {
        if ($last_hour !== null && $hour - $last_hour > 1) {
            $gaps[] = [
                'from' => $last_hour,
                'to' => $hour,
                'gap_hours' => $hour - $last_hour - 1
            ];
        }
        $last_hour = $hour;
    }
    
    // ========================================================================
    // Concerns and Recommendations
    // ========================================================================
    
    // Early morning or late evening classes
    if (isset($analysis['time_span']['earliest_hour']) && 
        $analysis['time_span']['earliest_hour'] < 7) {
        $analysis['concerns'][] = [
            'type' => 'Early Classes',
            'detail' => 'Courses starting before 7 AM',
            'earliest' => sprintf('%02d:00', $analysis['time_span']['earliest_hour']),
            'severity' => 'low'
        ];
    }
    
    if (isset($analysis['time_span']['latest_hour']) && 
        $analysis['time_span']['latest_hour'] > 17) {
        $analysis['concerns'][] = [
            'type' => 'Late Classes',
            'detail' => 'Courses extending beyond 5 PM',
            'latest' => sprintf('%02d:00', $analysis['time_span']['latest_hour']),
            'severity' => 'low'
        ];
    }
    
    // Heavy concentration
    if ($analysis['compression_ratio'] > 0.7) {
        $analysis['concerns'][] = [
            'type' => 'Compression',
            'detail' => 'Schedule is tightly compressed',
            'ratio' => $analysis['compression_ratio'],
            'severity' => 'medium',
            'implication' => 'Less flexibility, harder to reschedule'
        ];
    }
    
    // Uneven distribution
    if (isset($analysis['efficiency_analysis']['load_balance_index']) && 
        $analysis['efficiency_analysis']['load_balance_index'] < 60) {
        $analysis['concerns'][] = [
            'type' => 'Uneven Load',
            'detail' => 'Courses not evenly distributed across time slots',
            'balance_index' => $analysis['efficiency_analysis']['load_balance_index'],
            'severity' => 'medium'
        ];
    }
    
    // Check for off-peak usage
    $peak_usage = array_sum(array_slice($analysis['hourly_distribution'], 0, 3));
    $total_usage = array_sum($analysis['hourly_distribution']);
    $peak_percentage = ($total_usage > 0) ? ($peak_usage / $total_usage) * 100 : 0;
    
    if ($peak_percentage > 60) {
        $analysis['concerns'][] = [
            'type' => 'Peak Concentration',
            'detail' => 'Over 60% of courses in 3 peak hours',
            'percentage' => round($peak_percentage, 1),
            'severity' => 'medium'
        ];
    }
    
    // Recommendations
    if ($analysis['compression_ratio'] > 0.7) {
        $analysis['recommendations'][] = [
            'priority' => 'medium',
            'suggestion' => 'Spread courses across more days or hours to reduce compression',
            'benefit' => 'More flexible scheduling, easier maintenance'
        ];
    }
    
    if (!empty($gaps)) {
        $analysis['recommendations'][] = [
            'priority' => 'low',
            'suggestion' => 'Consider filling gaps in schedule for better efficiency',
            'gaps_found' => count($gaps),
            'benefit' => 'Reduce instructor travel time between campuses'
        ];
    }
    
    if ($peak_percentage > 60) {
        $analysis['recommendations'][] = [
            'priority' => 'medium',
            'suggestion' => 'Redistribute courses from peak hours to off-peak hours',
            'benefit' => 'Reduce classroom conflicts, improve facility utilization'
        ];
    }
    
    if ($analysis['compression_ratio'] < 0.3) {
        $analysis['recommendations'][] = [
            'priority' => 'low',
            'suggestion' => 'Schedule is very spread out; consider consolidating to save resources',
            'benefit' => 'Reduce facility needs during off-peak times'
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
        
        $result = analyze_time_distribution($safe_path ?? null);
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
