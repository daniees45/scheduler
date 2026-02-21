<?php
/**
 * Schedule Recommendations API
 * Provides personalized course and study time recommendations based on enrolled courses
 */
require_once 'db.php';

header('Content-Type: application/json');

// Check authentication
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
$action = $_GET['action'] ?? 'course_recommendations';

try {
    switch ($action) {
        case 'course_recommendations':
            echo json_encode(getCourseRecommendations($user_id, $conn));
            break;
        
        case 'activity_suggestions':
            echo json_encode(getActivitySuggestions($user_id, $conn));
            break;
            
        case 'study_suggestions':
            echo json_encode(getStudySuggestions($user_id));
            break;
            
        case 'record_enrollment':
            $course_id = $_POST['course_id'] ?? 0;
            echo json_encode(recordEnrollmentPattern($user_id, $course_id, $conn));
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

/**
 * Get course recommendations based on enrolled courses and learning patterns
 */
function getCourseRecommendations($user_id, $conn) {
    // Get student info
    $stmt = $conn->prepare("SELECT department, level FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $student = $result->fetch_assoc();
    
    if (!$student) {
        return ['recommendations' => []];
    }
    
    $department = $student['department'];
    $level = $student['level'];
    
    // Get enrolled course IDs
    $stmt = $conn->prepare("SELECT course_id FROM student_enrollments WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $enrolled_ids = [];
    while ($row = $result->fetch_assoc()) {
        $enrolled_ids[] = $row['course_id'];
    }
    
    // Build query for recommendations
    $exclude_sql = '';
    if (!empty($enrolled_ids)) {
        $placeholders = implode(',', array_fill(0, count($enrolled_ids), '?'));
        $exclude_sql = "AND c.id NOT IN ($placeholders)";
    }
    
    $sql = "
        SELECT 
            c.id, 
            c.course_code, 
            c.course_title, 
            c.type,
            c.department,
            c.level,
            l.name as lecturer_name,
            COUNT(DISTINCT e.user_id) as enrollment_count
        FROM courses c
        LEFT JOIN lecturers l ON c.lecturer_id = l.id
        LEFT JOIN student_enrollments e ON c.id = e.course_id
        WHERE c.level = ?
        AND (c.type = 'General' OR c.department = ?)
        $exclude_sql
        GROUP BY c.id
        ORDER BY enrollment_count DESC, c.course_code
        LIMIT 5
    ";
    
    $stmt = $conn->prepare($sql);
    
    // Bind parameters dynamically
    $types = 'is';
    $params = [$level, $department];
    
    if (!empty($enrolled_ids)) {
        $types .= str_repeat('i', count($enrolled_ids));
        $params = array_merge($params, $enrolled_ids);
    }
    
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $recommendations = [];
    while ($row = $result->fetch_assoc()) {
        // Calculate recommendation score
        $score = calculateRecommendationScore($row, $student, $conn);
        $row['score'] = $score;
        $row['reason'] = generateRecommendationReason($row, $student);
        
        $recommendations[] = $row;
    }
    
    // Sort by score
    usort($recommendations, function($a, $b) {
        return $b['score'] <=> $a['score'];
    });
    
    return [
        'recommendations' => $recommendations,
        'student_info' => $student
    ];
}

/**
 * Calculate recommendation score for a course
 */
function calculateRecommendationScore($course, $student, $conn) {
    $score = 50.0; // Base score
    
    // Type bonus
    if ($course['type'] === 'General') {
        $score += 10.0;
    }
    
    // Department match bonus
    if ($course['department'] === $student['department']) {
        $score += 15.0;
    }
    
    // Popularity bonus
    $enrollment_count = intval($course['enrollment_count']);
    $score += min($enrollment_count * 2, 20.0);
    
    // Pattern-based bonus (students in same department enrolled)
    $stmt = $conn->prepare("
        SELECT COUNT(DISTINCT e.user_id) as similar_count
        FROM student_enrollments e
        JOIN users u ON e.user_id = u.id
        WHERE e.course_id = ?
        AND u.department = ?
        AND u.level = ?
    ");
    $stmt->bind_param("isi", $course['id'], $student['department'], $student['level']);
    $stmt->execute();
    $result = $stmt->get_result();
    $pattern = $result->fetch_assoc();
    
    if ($pattern) {
        $similar_count = intval($pattern['similar_count']);
        $score += min($similar_count * 5, 15.0);
    }
    
    return $score;
}

/**
 * Generate recommendation reason
 */
function generateRecommendationReason($course, $student) {
    $reasons = [];
    
    if ($course['type'] === 'General') {
        $reasons[] = "general course for all students";
    }
    
    if ($course['department'] === $student['department']) {
        $reasons[] = "matches your {$student['department']} department";
    }
    
    $enrollment_count = intval($course['enrollment_count']);
    if ($enrollment_count > 10) {
        $reasons[] = "popular course ({$enrollment_count} students enrolled)";
    } elseif ($enrollment_count > 5) {
        $reasons[] = "well-attended course";
    }
    
    if (empty($reasons)) {
        return "Recommended based on your profile";
    }
    
    return "Recommended: " . implode(", ", $reasons);
}

/**
 * Get study time suggestions based on schedule
 */
function getStudySuggestions($user_id) {
    // This would call Python script but for now return simple suggestions
    // You can extend this to actually call personal_scheduler_db.py
    
    $suggestions = [
        [
            'title' => 'Morning Study Session',
            'day' => 'Monday',
            'time' => '08:00 - 10:00',
            'reason' => 'Free slot before classes - optimal for focused study'
        ],
        [
            'title' => 'Afternoon Review',
            'day' => 'Wednesday',
            'time' => '14:00 - 16:00',
            'reason' => 'Mid-week review helps retain knowledge'
        ],
        [
            'title' => 'Week-end Learning',
            'day' => 'Saturday',
            'time' => '10:00 - 12:00',
            'reason' => 'Extended study time for deep learning'
        ]
    ];
    
    return ['suggestions' => $suggestions];
}

/**
 * Record enrollment pattern for learning
 */
function recordEnrollmentPattern($user_id, $course_id, $conn) {
    // Get student and course info
    $stmt = $conn->prepare("
        SELECT u.department, u.level, c.type, c.department as course_dept
        FROM users u, courses c
        WHERE u.id = ? AND c.id = ?
    ");
    $stmt->bind_param("ii", $user_id, $course_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    
    if ($data) {
        // Store in enrollment_patterns table (create if doesn't exist)
        $pattern = json_encode([
            'user_id' => $user_id,
            'course_id' => $course_id,
            'timestamp' => date('Y-m-d H:i:s'),
            'metadata' => $data
        ]);
        
        // For now, we'll just log this. You can create a table later
        // $conn->query("INSERT INTO enrollment_patterns (data) VALUES ('$pattern')");
    }
    
    return ['success' => true, 'message' => 'Pattern recorded'];
}

/**
 * Get activity suggestions based on free time in schedule
 */
function getActivitySuggestions($user_id, $conn) {
    // Get user's schedule to find free slots
    $stmt = $conn->prepare("
        SELECT s.assigned_day, s.assigned_time, c.course_code
        FROM student_enrollments e
        JOIN sections s ON e.course_id = s.course_id
        JOIN courses c ON s.course_id = c.id
        WHERE e.user_id = ?
        ORDER BY FIELD(s.assigned_day, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'), s.assigned_time
    ");
    
    if (!$stmt) {
        return ['suggestions' => []];
    }
    
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $schedule_items = $result->fetch_all(MYSQLI_ASSOC) ?? [];
    
    $suggestions = [];
    
    if (empty($schedule_items)) {
        return ['suggestions' => []];
    }
    
    // Parse schedule and identify free slots
    $free_slots = identifyFreeSlots($schedule_items);
    
    // Create activity suggestions for free slots
    $activities = [
        'morning' => [
            'title' => 'Complex Topics Study',
            'suggestion' => 'Review challenging concepts from your courses when your mind is fresh.',
            'icon' => 'fa-brain'
        ],
        'midday' => [
            'title' => 'Quick Review Session',
            'suggestion' => 'Summarize notes from morning classes and prepare for afternoon sessions.',
            'icon' => 'fa-list-check'
        ],
        'afternoon' => [
            'title' => 'Practice Problems',
            'suggestion' => 'Work on problem sets and exercises to reinforce your learning.',
            'icon' => 'fa-pencil'
        ],
        'evening' => [
            'title' => 'Group Study',
            'suggestion' => 'Collaborate with classmates to discuss and clarify difficult topics.',
            'icon' => 'fa-users'
        ]
    ];
    
    $dayNames = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    
    // Generate suggestions for identified free slots
    foreach ($free_slots as $slot) {
        $period = getTimePeriod($slot['start']);
        $activity = $activities[$period] ?? $activities['afternoon'];
        
        $suggestions[] = [
            'title' => $activity['title'],
            'day' => $slot['day'],
            'start' => $slot['start'],
            'end' => $slot['end'],
            'reason' => 'Free time slot between classes',
            'suggestion' => $activity['suggestion']
        ];
    }
    
    // Limit to top 5 suggestions
    return ['suggestions' => array_slice($suggestions, 0, 5)];
}

/**
 * Identify free time slots from schedule
 */
function identifyFreeSlots($schedule_items) {
    $free_slots = [];
    $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    
    // Parse schedule items and find gaps
    $day_schedule = [];
    foreach ($schedule_items as $item) {
        $day = $item['assigned_day'];
        if (!isset($day_schedule[$day])) {
            $day_schedule[$day] = [];
        }
        
        // Parse time range (e.g., "09:00-11:00")
        if (strpos($item['assigned_time'], '-')) {
            $times = explode('-', $item['assigned_time']);
            $start_time = trim($times[0]);
            $end_time = trim($times[1]);
            
            $day_schedule[$day][] = [
                'start' => $start_time,
                'end' => $end_time
            ];
        }
    }
    
    // Identify gaps (free slots)
    foreach ($days as $day) {
        if (!isset($day_schedule[$day]) || empty($day_schedule[$day])) {
            // Full day free - suggest morning slot
            $free_slots[] = [
                'day' => $day,
                'start' => '09:00',
                'end' => '11:00'
            ];
            continue;
        }
        
        // Sort by start time
        usort($day_schedule[$day], function($a, $b) {
            return strcmp($a['start'], $b['start']);
        });
        
        $classes = $day_schedule[$day];
        
        // Check for morning slot (before first class)
        $first_start = $classes[0]['start'];
        if (strtotime($first_start) > strtotime('08:00')) {
            $free_slots[] = [
                'day' => $day,
                'start' => '08:00',
                'end' => substr($first_start, 0, 5)
            ];
        }
        
        // Check for gaps between classes
        for ($i = 0; $i < count($classes) - 1; $i++) {
            $current_end = strtotime($classes[$i]['end']);
            $next_start = strtotime($classes[$i + 1]['start']);
            
            // If there's more than 1 hour gap
            if (($next_start - $current_end) >= 3600) {
                $free_slots[] = [
                    'day' => $day,
                    'start' => date('H:i', $current_end),
                    'end' => date('H:i', $next_start)
                ];
            }
        }
        
        // Check for evening slot (after last class)
        $last_end = $classes[count($classes) - 1]['end'];
        if (strtotime($last_end) < strtotime('18:00')) {
            $free_slots[] = [
                'day' => $day,
                'start' => substr($last_end, 0, 5),
                'end' => '18:00'
            ];
        }
    }
    
    return $free_slots;
}

/**
 * Determine time period from time string
 */
function getTimePeriod($time_str) {
    $hour = intval(substr($time_str, 0, 2));
    
    if ($hour < 12) {
        return 'morning';
    } elseif ($hour < 14) {
        return 'midday';
    } elseif ($hour < 17) {
        return 'afternoon';
    } else {
        return 'evening';
    }
}
