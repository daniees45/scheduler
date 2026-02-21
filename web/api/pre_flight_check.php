<?php
/**
 * Pre-Flight Feasibility Checks
 * 
 * Run BEFORE calling the AI scheduling engine
 * Returns feasibility score (0-100%)
 * Identifies constraint conflicts early
 * Prevents wasting time on impossible schedules
 */

header('Content-Type: application/json');
require_once 'db.php';
require_once 'error_handler.php';
require_once 'validate_csv.php';

ensure_error_log_table();

/**
 * Main pre-flight check function
 */
function run_pre_flight_checks($input_csv, $availability_csv, $rooms_csv) {
    global $conn;
    
    $checks = [];
    $warnings = [];
    $errors = [];
    $score = 100;
    
    // 1. File existence check
    $checks['files_exist'] = true;
    foreach ([$input_csv, $availability_csv, $rooms_csv] as $file) {
        if (!file_exists($file)) {
            $errors[] = "Required file not found: $file";
            $checks['files_exist'] = false;
            $score -= 20;
        }
    }
    
    if (!$checks['files_exist']) {
        return [
            'feasible' => false,
            'score' => max(0, $score),
            'errors' => $errors,
            'warnings' => $warnings,
            'checks' => $checks
        ];
    }
    
    // 2. CSV validation
    $courses_valid = validate_courses_csv($input_csv);
    $availability_valid = validate_lecturer_availability_csv($availability_csv);
    $rooms_valid = validate_rooms_csv($rooms_csv);
    
    $checks['csv_validation'] = [
        'courses' => $courses_valid['valid'],
        'availability' => $availability_valid['valid'],
        'rooms' => $rooms_valid['valid']
    ];
    
    if (!$courses_valid['valid']) {
        $errors[] = "Courses CSV validation failed: " . implode(', ', $courses_valid['errors'] ?? []);
        $score -= 25;
    }
    
    if (!$availability_valid['valid']) {
        $errors[] = "Lecturer availability CSV validation failed";
        $score -= 15;
    }
    
    if (!$rooms_valid['valid']) {
        $errors[] = "Rooms CSV validation failed";
        $score -= 20;
    }
    
    if (!empty($errors)) {
        return [
            'feasible' => false,
            'score' => max(0, $score),
            'errors' => $errors,
            'warnings' => $warnings,
            'checks' => $checks
        ];
    }
    
    // 3. Lecturer availability check
    $checks['lecturer_availability'] = check_lecturer_availability($availability_csv);
    if ($checks['lecturer_availability']['unavailable_count'] > 0) {
        $warnings[] = $checks['lecturer_availability']['unavailable_count'] . " lecturers have 0 available days";
        $score -= $checks['lecturer_availability']['unavailable_count'];
    }
    
    if ($checks['lecturer_availability']['min_available_days'] < 2) {
        $warnings[] = "Some lecturers have very limited availability (< 2 days)";
        $score -= 5;
    }
    
    // 4. Room capacity check
    $checks['room_capacity'] = check_room_capacity($input_csv, $rooms_csv);
    if (!$checks['room_capacity']['sufficient']) {
        $errors[] = "Insufficient total room capacity for enrollment sizes";
        $score -= 30;
    }
    
    // 5. Lecturer-to-course ratio
    $checks['lecturer_slots'] = check_lecturer_slots($input_csv, $availability_csv);
    if ($checks['lecturer_slots']['critical_lecturers'] > 0) {
        $errors[] = $checks['lecturer_slots']['critical_lecturers'] . " lecturer(s) assigned more courses than available slots";
        $score -= 20;
    }
    
    if ($checks['lecturer_slots']['tight_lecturers'] > 0) {
        $warnings[] = $checks['lecturer_slots']['tight_lecturers'] . " lecturer(s) have tight schedules (limited flexibility)";
        $score -= 10;
    }
    
    // 6. Level clash prediction (if level files exist)
    $checks['level_clash'] = check_level_conflicts($input_csv);
    if ($checks['level_clash']['conflicts'] > 0) {
        $warnings[] = "Potential level-based course conflicts detected: " . $checks['level_clash']['conflicts'];
        $score -= 5;
    }
    
    // 7. Time slot availability
    $checks['time_coverage'] = check_time_slot_coverage($input_csv);
    if ($checks['time_coverage']['coverage_percent'] < 40) {
        $warnings[] = "Limited time slot coverage. Many courses may not get preferred times.";
        $score -= 10;
    }
    
    // Determine feasibility
    $feasible = ($score >= 40) && (empty($errors));
    
    return [
        'feasible' => $feasible,
        'score' => max(0, $score),
        'errors' => $errors,
        'warnings' => $warnings,
        'checks' => $checks,
        'recommendation' => get_feasibility_recommendation($score, $errors, $warnings)
    ];
}

/**
 * Exam-specific pre-flight checks
 */
function run_exam_pre_flight_checks($input_csv, $rooms_csv) {
    $checks = [];
    $warnings = [];
    $errors = [];
    $score = 100;

    $checks['files_exist'] = true;
    foreach ([$input_csv, $rooms_csv] as $file) {
        if (!file_exists($file)) {
            $errors[] = "Required file not found: $file";
            $checks['files_exist'] = false;
            $score -= 25;
        }
    }

    if (!$checks['files_exist']) {
        return [
            'feasible' => false,
            'score' => max(0, $score),
            'errors' => $errors,
            'warnings' => $warnings,
            'checks' => $checks
        ];
    }

    $exam_valid = validate_exam_csv($input_csv);
    $rooms_valid = validate_rooms_csv($rooms_csv);

    $checks['csv_validation'] = [
        'exam_courses' => $exam_valid['valid'],
        'rooms' => $rooms_valid['valid']
    ];

    if (!$exam_valid['valid']) {
        $errors[] = "Exam CSV validation failed: " . implode(', ', $exam_valid['errors'] ?? [$exam_valid['error'] ?? 'Unknown error']);
        $score -= 30;
    }

    if (!$rooms_valid['valid']) {
        $errors[] = "Rooms CSV validation failed";
        $score -= 20;
    }

    if (!empty($errors)) {
        return [
            'feasible' => false,
            'score' => max(0, $score),
            'errors' => $errors,
            'warnings' => $warnings,
            'checks' => $checks
        ];
    }

    $checks['room_capacity'] = check_exam_room_capacity($input_csv, $rooms_csv);
    if (!$checks['room_capacity']['sufficient']) {
        $warnings[] = "Exam enrollment exceeds available capacity for some rooms";
        $score -= 15;
    }

    $feasible = ($score >= 40) && empty($errors);

    return [
        'feasible' => $feasible,
        'score' => max(0, $score),
        'errors' => $errors,
        'warnings' => $warnings,
        'checks' => $checks,
        'recommendation' => get_feasibility_recommendation($score, $errors, $warnings)
    ];
}

/**
 * Check lecturer availability
 */
function check_lecturer_availability($availability_csv) {
    $unavailable_count = 0;
    $min_available_days = 5;
    $lecturers_data = [];
    $unavailable_lecturers = [];
    
    $handle = fopen($availability_csv, 'r');
    $headers = fgetcsv($handle);
    
    while (($row = fgetcsv($handle)) !== FALSE) {
        $data = array_combine($headers, $row);
        $lecturer = trim($data['lecturer_name']);
        
        $available_days = 0;
        foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri'] as $day) {
            $available_days += (int)($data[$day] ?? 0);
        }
        
        if ($available_days === 0) {
            $unavailable_count++;
            $unavailable_lecturers[] = $lecturer;
        }
        
        if ($available_days < $min_available_days) {
            $min_available_days = $available_days;
        }
        
        $lecturers_data[$lecturer] = $available_days;
    }
    
    fclose($handle);
    
    return [
        'unavailable_count' => $unavailable_count,
        'unavailable_lecturers' => $unavailable_lecturers,
        'min_available_days' => $min_available_days,
        'total_lecturers' => count($lecturers_data)
    ];
}

/**
 * Check if room capacity is sufficient
 */
function check_room_capacity($courses_csv, $rooms_csv) {
    $total_room_capacity = 0;
    $total_enrollment = 0;
    $room_data = [];
    
    // Load room capacities
    $handle = fopen($rooms_csv, 'r');
    $headers = fgetcsv($handle);
    while (($row = fgetcsv($handle)) !== FALSE) {
        $data = array_combine($headers, $row);
        $room = trim($data['room_name']);
        $capacity = (int)($data['capacity'] ?? 0);
        $total_room_capacity += $capacity;
        $room_data[$room] = $capacity;
    }
    fclose($handle);
    
    // Check courses against room capacity
    $handle = fopen($courses_csv, 'r');
    $headers = fgetcsv($handle);
    while (($row = fgetcsv($handle)) !== FALSE) {
        $data = array_combine($headers, $row);
        $enrollment = (int)($data['enrollment'] ?? 30); // Default 30 if not specified
        $total_enrollment += $enrollment;
    }
    fclose($handle);
    
    return [
        'total_room_capacity' => $total_room_capacity,
        'total_enrollment' => $total_enrollment,
        'sufficient' => ($total_room_capacity >= $total_enrollment),
        'room_utilization' => $total_room_capacity > 0 ? 
            round(($total_enrollment / $total_room_capacity) * 100, 2) : 0
    ];
}

/**
 * Exam room capacity check
 */
function check_exam_room_capacity($courses_csv, $rooms_csv) {
    $total_room_capacity = 0;
    $total_enrollment = 0;

    $handle = fopen($rooms_csv, 'r');
    $headers = fgetcsv($handle);
    while (($row = fgetcsv($handle)) !== false) {
        $data = array_combine($headers, $row);
        $capacity = (int)($data['capacity'] ?? 0);
        $total_room_capacity += $capacity;
    }
    fclose($handle);

    $handle = fopen($courses_csv, 'r');
    $headers = fgetcsv($handle);
    $normalize = static function ($h) {
        return str_replace(' ', '_', strtolower(trim((string)$h)));
    };
    $headers = array_map($normalize, $headers);
    while (($row = fgetcsv($handle)) !== false) {
        $data = array_combine($headers, $row);
        $enrollment = 30;
        foreach (['no_of_students', 'number_of_students', 'num_students', 'student_count', 'students', 'enrollment'] as $key) {
            if (isset($data[$key]) && trim((string)$data[$key]) !== '') {
                $enrollment = (int)floatval($data[$key]);
                break;
            }
        }
        $total_enrollment += $enrollment;
    }
    fclose($handle);

    return [
        'total_room_capacity' => $total_room_capacity,
        'total_enrollment' => $total_enrollment,
        'sufficient' => ($total_room_capacity >= $total_enrollment),
        'room_utilization' => $total_room_capacity > 0 ?
            round(($total_enrollment / $total_room_capacity) * 100, 2) : 0
    ];
}


/**
 * Check lecturer slot ratio
 */
function check_lecturer_slots($courses_csv, $availability_csv) {
    global $conn;
    
    // Load lecturer availability
    $lecturer_slots = [];
    $handle = fopen($availability_csv, 'r');
    $headers = fgetcsv($handle);
    
    while (($row = fgetcsv($handle)) !== FALSE) {
        $data = array_combine($headers, $row);
        $lecturer = trim($data['lecturer_name']);
        $available_days = 0;
        
        foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri'] as $day) {
            $available_days += (int)($data[$day] ?? 0);
        }
        
        // Estimate available slots: each day has ~7 time slots
        $estimated_slots = $available_days * 7;
        $lecturer_slots[$lecturer] = $estimated_slots;
    }
    fclose($handle);
    
    // Count courses per lecturer
    $lecturer_courses = [];
    $handle = fopen($courses_csv, 'r');
    $headers = fgetcsv($handle);
    
    while (($row = fgetcsv($handle)) !== FALSE) {
        $data = array_combine($headers, $row);
        $lecturer = trim($data['lecturer_name']);
        
        if (!isset($lecturer_courses[$lecturer])) {
            $lecturer_courses[$lecturer] = 0;
        }
        $lecturer_courses[$lecturer]++;
    }
    fclose($handle);
    
    // Compare
    $critical_lecturers = 0;
    $tight_lecturers = 0;
    
    foreach ($lecturer_courses as $lecturer => $course_count) {
        $available_slots = $lecturer_slots[$lecturer] ?? 0;
        
        if ($course_count > $available_slots) {
            $critical_lecturers++;
        } elseif ($course_count > ($available_slots * 0.7)) {
            $tight_lecturers++;
        }
    }
    
    return [
        'total_lecturers' => count($lecturer_courses),
        'critical_lecturers' => $critical_lecturers,
        'tight_lecturers' => $tight_lecturers,
        'lecturer_distribution' => $lecturer_courses
    ];
}

/**
 * Check for level-based course conflicts
 */
function check_level_conflicts($courses_csv) {
    $conflicts = 0;
    $level_courses = [];
    
    $handle = fopen($courses_csv, 'r');
    $headers = fgetcsv($handle);
    
    while (($row = fgetcsv($handle)) !== FALSE) {
        $data = array_combine($headers, $row);
        $course_code = trim($data['course_code']);
        $level = substr($course_code, -3, 1); // Extract level digit from code
        
        if (!isset($level_courses[$level])) {
            $level_courses[$level] = [];
        }
        $level_courses[$level][] = $course_code;
    }
    fclose($handle);
    
    // Check for overlap: if same level has many different time slot requests
    foreach ($level_courses as $level => $courses) {
        if (count($courses) > 10 && count(array_unique($courses)) === count($courses)) {
            $conflicts++; // Indicator of potential conflicts
        }
    }
    
    return [
        'conflicts' => $conflicts,
        'levels_present' => count($level_courses),
        'courses_by_level' => $level_courses
    ];
}

/**
 * Check time slot coverage
 */
function check_time_slot_coverage($courses_csv) {
    $specified_slots = 0;
    $total_courses = 0;
    
    $handle = fopen($courses_csv, 'r');
    $headers = fgetcsv($handle);
    
    while (($row = fgetcsv($handle)) !== FALSE) {
        $total_courses++;
        $data = array_combine($headers, $row);
        
        if (!empty(trim($data['start_time'] ?? '')) && !empty(trim($data['end_time'] ?? ''))) {
            $specified_slots++;
        }
    }
    fclose($handle);
    
    $coverage = $total_courses > 0 ? round(($specified_slots / $total_courses) * 100) : 0;
    
    return [
        'total_courses' => $total_courses,
        'with_assigned_times' => $specified_slots,
        'coverage_percent' => $coverage
    ];
}

/**
 * Generate recommendation based on feasibility score
 */
function get_feasibility_recommendation($score, $errors, $warnings) {
    if (!empty($errors)) {
        return [
            'status' => 'BLOCKED',
            'message' => 'Cannot generate schedule due to critical errors',
            'action' => 'Fix errors listed above and try again',
            'severity' => 'error'
        ];
    }
    
    if ($score >= 85) {
        return [
            'status' => 'READY',
            'message' => 'Data looks good! Ready to generate schedule',
            'action' => 'Click "Generate" to proceed',
            'severity' => 'success'
        ];
    } elseif ($score >= 60) {
        return [
            'status' => 'CAUTION',
            'message' => 'Schedule may take longer or have suboptimal results',
            'issue' => implode('; ', $warnings),
            'action' => 'Review warnings. You can proceed, but results may not be ideal',
            'severity' => 'warning'
        ];
    } else {
        return [
            'status' => 'HIGH_RISK',
            'message' => 'Significant constraint issues detected',
            'action' => 'Review all warnings and errors; success unlikely without changes',
            'severity' => 'error'
        ];
    }
}

/**
 * API Endpoint
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);

    $action = $_POST['action'] ?? ($json['action'] ?? 'check');
    $exam_mode = isset($_POST['exam_mode']) ? (bool)$_POST['exam_mode'] : (bool)($json['exam_mode'] ?? false);

    if ($action === 'exam_check' || $exam_mode) {
        $csv_content = $json['csv_content'] ?? null;
        $csv_filename = $json['csv_filename'] ?? 'exam_input.csv';

        $temp_dir = realpath(__DIR__ . '/../temp');
        if (!$temp_dir) {
            $temp_dir = __DIR__ . '/../temp';
            if (!is_dir($temp_dir)) {
                mkdir($temp_dir, 0755, true);
            }
        }

        $safe_name = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', basename($csv_filename));
        $temp_path = rtrim($temp_dir, '/\\') . '/' . uniqid('exam_input_', true) . '_' . $safe_name;

        if (is_array($csv_content)) {
            $handle = fopen($temp_path, 'w');
            foreach ($csv_content as $row) {
                if (is_array($row)) {
                    fputcsv($handle, $row);
                }
            }
            fclose($handle);
        } elseif (is_string($csv_content) && $csv_content !== '') {
            file_put_contents($temp_path, $csv_content);
        }

        $rooms_csv = file_exists(__DIR__ . '/../csv/general/exam_rooms.csv')
            ? __DIR__ . '/../csv/general/exam_rooms.csv'
            : __DIR__ . '/../csv/general/rooms.csv';

        $result = run_exam_pre_flight_checks($temp_path, $rooms_csv);
        echo json_encode($result);
        exit;
    }

    if ($action === 'check') {
        $courses_csv = $_POST['courses_csv'] ?? 'csv/department/departmental_courses.csv';
        $availability_csv = $_POST['availability_csv'] ?? 'csv/general/lecturer_availability.csv';
        $rooms_csv = $_POST['rooms_csv'] ?? 'csv/general/rooms.csv';

        // Resolve paths
        $base_path = realpath('../../');
        $courses_path = $base_path . '/' . $courses_csv;
        $availability_path = $base_path . '/' . $availability_csv;
        $rooms_path = $base_path . '/' . $rooms_csv;

        // Security check
        foreach ([$courses_path, $availability_path, $rooms_path] as $path) {
            if (!$path || strpos($path, $base_path) !== 0 || !file_exists($path)) {
                http_response_code(403);
                echo json_encode(['error' => 'Invalid file path']);
                exit;
            }
        }

        $result = run_pre_flight_checks($courses_path, $availability_path, $rooms_path);

        // Log the check
        log_error('INFO', 'Pre-flight check completed', __FILE__, __LINE__, 
                 'score=' . $result['score'] . ', feasible=' . ($result['feasible'] ? 'true' : 'false'));

        echo json_encode($result);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
?>
