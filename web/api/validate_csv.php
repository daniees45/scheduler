<?php
/**
 * CSV Data Validation Module
 * 
 * Validates CSV files for:
 * - Correct column headers
 * - No missing critical fields
 * - Data type correctness
 * - Referential integrity
 * - Uniqueness constraints
 */

header('Content-Type: application/json');
require_once 'db.php';

/**
 * Validate CSV structure and headers
 */
function validate_csv_structure($file_path, $expected_headers) {
    if (!file_exists($file_path)) {
        return ['valid' => false, 'error' => 'File not found: ' . $file_path];
    }
    
    $handle = fopen($file_path, 'r');
    if (!$handle) {
        return ['valid' => false, 'error' => 'Cannot open file for reading'];
    }
    
    $headers = fgetcsv($handle);
    fclose($handle);
    
    if (!$headers) {
        return ['valid' => false, 'error' => 'CSV file is empty or unreadable'];
    }
    
    // Normalize headers (trim whitespace, lowercase)
    $headers = array_map('trim', $headers);
    $expected = array_map('strtolower', $expected_headers);
    $actual = array_map('strtolower', $headers);
    
    // Check for missing required columns
    $missing = array_diff($expected, $actual);
    if (!empty($missing)) {
        return [
            'valid' => false, 
            'error' => 'Missing required columns: ' . implode(', ', $missing)
        ];
    }
    
    return ['valid' => true, 'headers' => $headers];
}

/**
 * Validate CSV data (courses)
 */
function validate_courses_csv($file_path) {
    global $conn;
    
    $required_headers = [
        'course_code', 'course_title', 'lecturer_name', 'semester', 
        'day', 'start_time', 'end_time', 'room_name'
    ];
    
    // Validate structure
    $struct_check = validate_csv_structure($file_path, $required_headers);
    if (!$struct_check['valid']) {
        return $struct_check;
    }
    
    $errors = [];
    $warnings = [];
    $row_num = 1;
    
    $handle = fopen($file_path, 'r');
    $headers = fgetcsv($handle);
    
    while (($row = fgetcsv($handle)) !== FALSE) {
        $row_num++;
        $data = array_combine($headers, $row);
        
        // Validate course_code (not empty)
        if (empty(trim($data['course_code'] ?? ''))) {
            $errors[] = "Row $row_num: course_code is empty";
            continue;
        }
        
        // Validate course_title
        if (empty(trim($data['course_title'] ?? ''))) {
            $errors[] = "Row $row_num: course_title is empty";
        }
        
        // Validate lecturer_name exists in database
        if (!empty(trim($data['lecturer_name'] ?? ''))) {
            $lecturer = trim($data['lecturer_name']);
            $result = $conn->query("SELECT id FROM lecturers WHERE name = '$lecturer' LIMIT 1");
            if (!$result || $result->num_rows === 0) {
                $warnings[] = "Row $row_num: Lecturer '$lecturer' not found in database";
            }
        } else {
            $errors[] = "Row $row_num: lecturer_name is empty";
        }
        
        // Validate room_name exists in database (if provided)
        if (!empty(trim($data['room_name'] ?? ''))) {
            $room = trim($data['room_name']);
            $result = $conn->query("SELECT id FROM rooms WHERE room_name = '$room' LIMIT 1");
            if (!$result || $result->num_rows === 0) {
                $warnings[] = "Row $row_num: Room '$room' not found in database (will be auto-assigned)";
            }
        }
        
        // Validate semester is numeric
        if (!empty($data['semester'] ?? '')) {
            if (!is_numeric($data['semester'])) {
                $errors[] = "Row $row_num: semester must be numeric";
            }
        }
        
        // Validate time format (HH:MM or empty)
        if (!empty($data['start_time'] ?? '')) {
            if (!preg_match('/^\d{1,2}:\d{2}$/', trim($data['start_time']))) {
                $errors[] = "Row $row_num: start_time must be in HH:MM format";
            }
        }
        
        if (!empty($data['end_time'] ?? '')) {
            if (!preg_match('/^\d{1,2}:\d{2}$/', trim($data['end_time']))) {
                $errors[] = "Row $row_num: end_time must be in HH:MM format";
            }
        }
        
        // Stop if too many errors
        if (count($errors) > 50) {
            $errors[] = "... (stopped after 50 errors)";
            break;
        }
    }
    
    fclose($handle);
    
    if (!empty($errors)) {
        return [
            'valid' => false,
            'errors' => $errors,
            'error_count' => count($errors)
        ];
    }
    
    return [
        'valid' => true,
        'warnings' => $warnings,
        'warning_count' => count($warnings),
        'message' => 'CSV validation passed'
    ];
}

/**
 * Validate lecturer availability CSV
 */
function validate_lecturer_availability_csv($file_path) {
    global $conn;
    
    $required_headers = ['lecturer_name', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri'];
    
    $struct_check = validate_csv_structure($file_path, $required_headers);
    if (!$struct_check['valid']) {
        return $struct_check;
    }
    
    $errors = [];
    $row_num = 1;
    
    $handle = fopen($file_path, 'r');
    $headers = fgetcsv($handle);
    
    while (($row = fgetcsv($handle)) !== FALSE) {
        $row_num++;
        $data = array_combine($headers, $row);
        
        // Validate lecturer exists
        if (empty(trim($data['lecturer_name'] ?? ''))) {
            $errors[] = "Row $row_num: lecturer_name is empty";
            continue;
        }
        
        $lecturer = trim($data['lecturer_name']);
        $result = $conn->query("SELECT id FROM lecturers WHERE name = '$lecturer' LIMIT 1");
        if (!$result || $result->num_rows === 0) {
            $errors[] = "Row $row_num: Lecturer '$lecturer' not found in database";
            continue;
        }
        
        // Validate availability values (0 or 1)
        foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri'] as $day) {
            $val = trim($data[$day] ?? '0');
            if ($val !== '0' && $val !== '1') {
                $errors[] = "Row $row_num, $day: Availability must be 0 or 1 (got: $val)";
            }
        }
        
        if (count($errors) > 50) {
            $errors[] = "... (stopped after 50 errors)";
            break;
        }
    }
    
    fclose($handle);
    
    if (!empty($errors)) {
        return [
            'valid' => false,
            'errors' => $errors,
            'error_count' => count($errors)
        ];
    }
    
    return ['valid' => true, 'message' => 'Lecturer availability validation passed'];
}

/**
 * Validate rooms CSV
 */
function validate_rooms_csv($file_path) {
    $required_headers = ['room_name', 'capacity'];
    
    $struct_check = validate_csv_structure($file_path, $required_headers);
    if (!$struct_check['valid']) {
        return $struct_check;
    }
    function validate_exam_csv($file_path) {
        if (!file_exists($file_path)) {
            return ['valid' => false, 'error' => 'File not found: ' . $file_path];
        }

        $handle = fopen($file_path, 'r');
        if (!$handle) {
            return ['valid' => false, 'error' => 'Cannot open file for reading'];
        }

        $headers = fgetcsv($handle);
        if (!$headers) {
            fclose($handle);
            return ['valid' => false, 'error' => 'CSV file is empty or unreadable'];
        }

        $normalize = static function ($h) {
            $h = strtolower(trim((string)$h));
            $h = str_replace(' ', '_', $h);
            return $h;
        };

        $normalized = array_map($normalize, $headers);
        $required = ['course_code', 'course_title'];
        $missing = array_diff($required, $normalized);
        if (!empty($missing)) {
            fclose($handle);
            return ['valid' => false, 'error' => 'Missing required columns: ' . implode(', ', $missing)];
        }

        $errors = [];
        $row_num = 1;
        while (($row = fgetcsv($handle)) !== false) {
            $row_num++;
            $data = array_combine($normalized, $row);
            if (empty(trim($data['course_code'] ?? ''))) {
                $errors[] = "Row $row_num: course_code is empty";
            }
            if (empty(trim($data['course_title'] ?? ''))) {
                $errors[] = "Row $row_num: course_title is empty";
            }
            if (count($errors) > 50) {
                $errors[] = "... (stopped after 50 errors)";
                break;
            }
        }
        fclose($handle);

        if (!empty($errors)) {
            return [
                'valid' => false,
                'errors' => $errors,
                'error_count' => count($errors)
            ];
        }

        return ['valid' => true, 'message' => 'Exam CSV validation passed'];
    }
    
    $errors = [];
    $row_num = 1;
    
    $handle = fopen($file_path, 'r');
    $headers = fgetcsv($handle);
    
    $seen_rooms = [];
    
    while (($row = fgetcsv($handle)) !== FALSE) {
        $row_num++;
        $data = array_combine($headers, $row);
        
        // Validate room_name
        if (empty(trim($data['room_name'] ?? ''))) {
            $errors[] = "Row $row_num: room_name is empty";
            continue;
        }
        
        $room = trim($data['room_name']);
        
        // Check for duplicates
        if (in_array($room, $seen_rooms)) {
            $errors[] = "Row $row_num: Duplicate room_name '$room'";
        }
        $seen_rooms[] = $room;
        
        // Validate capacity is numeric and positive
        $capacity = trim($data['capacity'] ?? '');
        if (empty($capacity)) {
            $errors[] = "Row $row_num: capacity is empty";
        } elseif (!is_numeric($capacity) || $capacity <= 0) {
            $errors[] = "Row $row_num: capacity must be a positive number (got: $capacity)";
        }
        
        if (count($errors) > 50) {
            $errors[] = "... (stopped after 50 errors)";
            break;
        }
    }
    
    fclose($handle);
    
    if (!empty($errors)) {
        return [
            'valid' => false,
            'errors' => $errors,
            'error_count' => count($errors)
        ];
    }
    
    return ['valid' => true, 'message' => 'Rooms CSV validation passed'];
}

/**
 * API Endpoint: Validate uploaded file
 */
/**
 * API Endpoint: Validate uploaded file
 * Only execute if called directly (not included)
 */
if (basename(__FILE__) == basename($_SERVER["SCRIPT_FILENAME"])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        $file_type = $_POST['file_type'] ?? 'courses';
        
        if ($action === 'validate') {
            $file = $_POST['file_path'] ?? '';
            
            if (empty($file)) {
                http_response_code(400);
                echo json_encode(['valid' => false, 'error' => 'No file specified']);
                exit;
            }
            
            // Security: validate path is within project directory
            $safe_path = realpath('../../' . $file);
            if (!$safe_path || strpos($safe_path, realpath('../../')) !== 0) {
                http_response_code(403);
                echo json_encode(['valid' => false, 'error' => 'Invalid file path']);
                exit;
            }
            
            $result = [];
            
            if ($file_type === 'courses') {
                $result = validate_courses_csv($safe_path);
            } elseif ($file_type === 'availability') {
                $result = validate_lecturer_availability_csv($safe_path);
            } elseif ($file_type === 'rooms') {
                $result = validate_rooms_csv($safe_path);
            } else {
                http_response_code(400);
                echo json_encode(['valid' => false, 'error' => 'Unknown file type']);
                exit;
            }
            
            echo json_encode($result);
        } else {
            // Only output error if we are sure this is a request meant for this file
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action']);
        }
    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
    }
}
?>
