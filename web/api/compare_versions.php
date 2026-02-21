<?php
/**
 * Schedule Version Comparison API
 * 
 * Compares:
 * - Different schedule versions
 * - Changes in course assignments
 * - Impact analysis (what changed in room/day/time/lecturer)
 * - Improvement metrics
 * - Approval workflow support
 */

header('Content-Type: application/json');
require_once 'db.php';
require_once 'error_handler.php';

/**
 * Get available schedule versions from backup table
 */
function get_available_versions() {
    global $conn;
    
    // Ensure backup table exists
    $result = $conn->query("SHOW TABLES LIKE 'schedule_backup'");
    if ($result->num_rows === 0) {
        return [];
    }
    
    $query = "SELECT id, version_name, created_at, quality_score, schedule_data 
              FROM schedule_backup 
              ORDER BY created_at DESC 
              LIMIT 20";
    
    $result = $conn->query($query);
    $versions = [];
    
    while ($row = $result->fetch_assoc()) {
        $versions[] = [
            'id' => $row['id'],
            'version_name' => $row['version_name'],
            'created_at' => $row['created_at'],
            'quality_score' => $row['quality_score'],
            'has_data' => !empty($row['schedule_data'])
        ];
    }
    
    return $versions;
}

/**
 * Parse schedule data (from CSV or JSON)
 */
function parse_schedule_data($data) {
    if (is_array($data)) {
        return $data;
    }
    
    $parsed = [];
    if (strpos($data, '{') === 0) {
        // JSON data
        $json = json_decode($data, true);
        if ($json && is_array($json)) {
            return $json;
        }
    }
    
    // CSV format
    $lines = explode("\n", $data);
    if (empty($lines)) return [];
    
    $headers = str_getcsv(trim($lines[0]));
    foreach (array_slice($lines, 1) as $line) {
        if (empty(trim($line))) continue;
        $row = str_getcsv(trim($line));
        $parsed[] = array_combine($headers, $row);
    }
    
    return $parsed;
}

/**
 * Compare two schedule versions
 */
function compare_versions($version_id1, $version_id2) {
    global $conn;
    
    // Fetch versions
    $query1 = "SELECT schedule_data, created_at FROM schedule_backup WHERE id = ?";
    $stmt = $conn->prepare($query1);
    $stmt->bind_param('i', $version_id1);
    $stmt->execute();
    $result1 = $stmt->get_result();
    $v1_row = $result1->fetch_assoc();
    
    $stmt = $conn->prepare($query1);
    $stmt->bind_param('i', $version_id2);
    $stmt->execute();
    $result2 = $stmt->get_result();
    $v2_row = $result2->fetch_assoc();
    
    if (!$v1_row || !$v2_row) {
        return ['success' => false, 'error' => 'One or both versions not found'];
    }
    
    $schedule1 = parse_schedule_data($v1_row['schedule_data']);
    $schedule2 = parse_schedule_data($v2_row['schedule_data']);
    
    // Build comparison
    $comparison = [
        'version_1' => ['id' => $version_id1, 'created_at' => $v1_row['created_at']],
        'version_2' => ['id' => $version_id2, 'created_at' => $v2_row['created_at']],
        'timestamp' => date('Y-m-d H:i:s'),
        'statistics' => [
            'version_1_courses' => count($schedule1),
            'version_2_courses' => count($schedule2),
            'courses_added' => 0,
            'courses_removed' => 0,
            'courses_modified' => 0
        ],
        'changes' => [
            'by_attribute' => [],
            'detailed_changes' => [],
            'by_course' => []
        ],
        'impact_analysis' => [],
        'quality_improvement' => 0
    ];
    
    // Create lookup maps
    $map1 = [];
    $map2 = [];
    
    if (!empty($schedule1)) {
        $key_fields = ['course_code', 'lecturer_name', 'section_number'];
        foreach ($schedule1 as $course) {
            $key = implode('_', array_intersect_key($course, array_flip($key_fields)));
            $map1[$key] = $course;
        }
    }
    
    if (!empty($schedule2)) {
        $key_fields = ['course_code', 'lecturer_name', 'section_number'];
        foreach ($schedule2 as $course) {
            $key = implode('_', array_intersect_key($course, array_flip($key_fields)));
            $map2[$key] = $course;
        }
    }
    
    $change_attributes = [
        'room_name' => 'Room',
        'day' => 'Day',
        'start_time' => 'Start Time',
        'end_time' => 'End Time',
        'lecturer_name' => 'Lecturer'
    ];
    
    // Find changes
    foreach ($map1 as $key => $course1) {
        if (isset($map2[$key])) {
            // Course exists in both - check for changes
            $course2 = $map2[$key];
            $course_changes = [];
            
            foreach ($change_attributes as $attr => $label) {
                if (($course1[$attr] ?? '') !== ($course2[$attr] ?? '')) {
                    $course_changes[] = [
                        'attribute' => $label,
                        'old_value' => $course1[$attr] ?? 'N/A',
                        'new_value' => $course2[$attr] ?? 'N/A'
                    ];
                    
                    // Track by attribute
                    if (!isset($comparison['changes']['by_attribute'][$attr])) {
                        $comparison['changes']['by_attribute'][$attr] = 0;
                    }
                    $comparison['changes']['by_attribute'][$attr]++;
                }
            }
            
            if (!empty($course_changes)) {
                $comparison['statistics']['courses_modified']++;
                $comparison['changes']['by_course'][] = [
                    'course' => $course1['course_code'] . ' (Sec ' . ($course1['section_number'] ?? '') . ')',
                    'lecturer' => $course1['lecturer_name'] ?? 'TBA',
                    'changes' => $course_changes
                ];
            }
        } else {
            // Course removed
            $comparison['statistics']['courses_removed']++;
            $comparison['changes']['detailed_changes'][] = [
                'type' => 'removed',
                'course' => $course1['course_code'],
                'section' => $course1['section_number'] ?? '',
                'lecturer' => $course1['lecturer_name'] ?? 'TBA'
            ];
        }
    }
    
    // Find added courses
    foreach ($map2 as $key => $course2) {
        if (!isset($map1[$key])) {
            $comparison['statistics']['courses_added']++;
            $comparison['changes']['detailed_changes'][] = [
                'type' => 'added',
                'course' => $course2['course_code'] ?? '',
                'section' => $course2['section_number'] ?? '',
                'lecturer' => $course2['lecturer_name'] ?? 'TBA'
            ];
        }
    }
    
    // ========================================================================
    // Impact Analysis
    // ========================================================================
    
    $rooms_changed = $comparison['changes']['by_attribute']['room_name'] ?? 0;
    $days_changed = $comparison['changes']['by_attribute']['day'] ?? 0;
    $times_changed = ($comparison['changes']['by_attribute']['start_time'] ?? 0) +
                     ($comparison['changes']['by_attribute']['end_time'] ?? 0);
    
    if ($rooms_changed > 0) {
        $comparison['impact_analysis'][] = [
            'category' => 'Room Changes',
            'count' => $rooms_changed,
            'impact' => 'May require student/instructor notifications',
            'severity' => 'medium'
        ];
    }
    
    if ($times_changed > 0) {
        $comparison['impact_analysis'][] = [
            'category' => 'Schedule Time Changes',
            'count' => $times_changed,
            'impact' => 'Instructors and students must adjust availability',
            'severity' => 'high'
        ];
    }
    
    if ($comparison['statistics']['courses_removed'] > 0) {
        $comparison['impact_analysis'][] = [
            'category' => 'Courses Removed',
            'count' => $comparison['statistics']['courses_removed'],
            'impact' => 'Courses no longer scheduled',
            'severity' => 'high'
        ];
    }
    
    if ($comparison['statistics']['courses_added'] > 0) {
        $comparison['impact_analysis'][] = [
            'category' => 'Courses Added',
            'count' => $comparison['statistics']['courses_added'],
            'impact' => 'New courses added to schedule',
            'severity' => 'medium'
        ];
    }
    
    // Calculate complexity score
    $total_changes = $comparison['statistics']['courses_modified'] + 
                     $comparison['statistics']['courses_added'] + 
                     $comparison['statistics']['courses_removed'];
    $total_courses = count($schedule2) > 0 ? count($schedule2) : 1;
    $change_percentage = ($total_changes / $total_courses) * 100;
    
    $comparison['statistics']['change_percentage'] = round($change_percentage, 1);
    $comparison['statistics']['total_changes'] = $total_changes;
    
    return ['success' => true, 'comparison' => $comparison];
}

/**
 * API Endpoint
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'get_versions') {
        $versions = get_available_versions();
        echo json_encode([
            'success' => true,
            'versions' => $versions,
            'count' => count($versions)
        ]);
        
    } elseif ($action === 'compare') {
        $version_id1 = (int)($_POST['version_id1'] ?? 0);
        $version_id2 = (int)($_POST['version_id2'] ?? 0);
        
        if ($version_id1 <= 0 || $version_id2 <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid version IDs']);
            exit;
        }
        
        $result = compare_versions($version_id1, $version_id2);
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
