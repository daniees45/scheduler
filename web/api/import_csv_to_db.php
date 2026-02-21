<?php
// web/api/import_csv_to_db.php
// Imports CSV data into the database (courses and sections tables)
session_start();
require_once 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    die(json_encode(["status" => "error", "message" => "Unauthorized"]));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$filename = $input['file'] ?? '';

if (!$filename) {
    echo json_encode(['status' => 'error', 'message' => 'No file specified.']);
    exit;
}

// Security: Validate filename
if (!preg_match('/^[a-zA-Z0-9_\-\.\/]+\.csv$/', $filename)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid filename format.']);
    exit;
}

$file_path = '../../' . $filename;

if (!file_exists($file_path)) {
    echo json_encode(['status' => 'error', 'message' => 'File not found.']);
    exit;
}

try {
    // Start transaction
    $conn->begin_transaction();
    
    $handle = fopen($file_path, "r");
    if (!$handle) {
        throw new Exception("Cannot open file: $filename");
    }
    
    // Read headers
    $headers = fgetcsv($handle, 1000, ",");
    if (!$headers) {
        throw new Exception("Invalid CSV format: No headers found.");
    }
    
    // Normalize headers (lowercase, trim spaces)
    $headers = array_map('strtolower', array_map('trim', $headers));
    
    // Determine CSV type based on headers
    $csv_type = 'unknown';
    if (in_array('course_code', $headers) && in_array('course_title', $headers)) {
        $csv_type = 'courses';
    } elseif (in_array('room_name', $headers)) {
        $csv_type = 'rooms';
    } elseif (in_array('lecturer_name', $headers) || (in_array('name', $headers) && count($headers) <= 10)) {
        $csv_type = 'lecturers';
    }
    
    $stats = [
        'courses' => 0,
        'sections' => 0,
        'rooms' => 0,
        'lecturers' => 0
    ];
    
    if ($csv_type === 'courses') {
        // Import courses and create sections
        
        // Cache lecturers for fast mapping
        $lecturers = [];
        $res = $conn->query("SELECT id, name FROM lecturers");
        while ($lr = $res->fetch_assoc()) {
            $lecturers[strtolower(trim($lr['name']))] = $lr['id'];
        }
        
        // Cache rooms
        $rooms = [];
        $res = $conn->query("SELECT id, room_name FROM rooms");
        while ($rr = $res->fetch_assoc()) {
            $rooms[strtolower(trim($rr['room_name']))] = $rr['id'];
        }
        
        // Prepare statements
        $course_stmt = $conn->prepare("INSERT INTO courses (course_code, course_title, semester, type, level, credit_hours) 
                                      VALUES (?, ?, ?, ?, ?, ?)
                                      ON DUPLICATE KEY UPDATE 
                                      course_title = VALUES(course_title),
                                      semester = VALUES(semester),
                                      type = VALUES(type),
                                      level = VALUES(level),
                                      credit_hours = VALUES(credit_hours)");
        
        $section_stmt = $conn->prepare("INSERT INTO sections (course_id, lecturer_id, room_id, assigned_day, assigned_time, schedule_time) 
                                        VALUES (?, ?, ?, ?, ?, ?)");
        
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            if (count($data) < 2) continue; // Skip empty rows
            
            // Map data to associative array
            $row = [];
            foreach ($headers as $i => $header) {
                $row[$header] = $data[$i] ?? '';
            }
            
            $code = trim($row['course_code'] ?? '');
            if (!$code) continue;
            
            $title = trim($row['course_title'] ?? '');
            $semester = trim($row['semester'] ?? '1');
            $type = trim($row['type'] ?? $row['source_type'] ?? 'Departmental');
            $level = intval($row['level'] ?? $row['course_level'] ?? 100);
            $credits = intval($row['credit_hours'] ?? 3);
            
            // Insert/update course
            $course_stmt->bind_param("ssssis", $code, $title, $semester, $type, $level, $credits);
            $course_stmt->execute();
            
            // Get course ID (either inserted or existing)
            if ($conn->insert_id > 0) {
                $course_id = $conn->insert_id;
                $stats['courses']++;
            } else {
                // Get existing course ID
                $res = $conn->query("SELECT id FROM courses WHERE course_code = '$code' LIMIT 1");
                if ($res && $r = $res->fetch_assoc()) {
                    $course_id = $r['id'];
                } else {
                    continue;
                }
            }
            
            // Create section if lecturer exists
            $lecturer_name = strtolower(trim($row['lecturer_name'] ?? ''));
            if ($lecturer_name && isset($lecturers[$lecturer_name])) {
                $lecturer_id = $lecturers[$lecturer_name];
                
                $room_name = strtolower(trim($row['room_name'] ?? ''));
                $room_id = ($room_name && isset($rooms[$room_name])) ? $rooms[$room_name] : null;
                
                $assigned_day = trim($row['day'] ?? $row['assigned_day'] ?? '');
                $assigned_time = trim($row['start_time'] ?? $row['assigned_time'] ?? '');
                $schedule_time = $assigned_day && $assigned_time ? "$assigned_day $assigned_time" : null;
                
                $section_stmt->bind_param("iiisss", $course_id, $lecturer_id, $room_id, $assigned_day, $assigned_time, $schedule_time);
                $section_stmt->execute();
                $stats['sections']++;
            }
        }
        
    } elseif ($csv_type === 'rooms') {
        // Import rooms
        $stmt = $conn->prepare("INSERT INTO rooms (room_name, capacity) VALUES (?, ?)
                               ON DUPLICATE KEY UPDATE capacity = VALUES(capacity)");
        
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            $row = [];
            foreach ($headers as $i => $header) {
                $row[$header] = $data[$i] ?? '';
            }
            
            $name = trim($row['room_name'] ?? $data[0] ?? '');
            $capacity = intval($row['capacity'] ?? $data[1] ?? 50);
            
            if ($name) {
                $stmt->bind_param("si", $name, $capacity);
                $stmt->execute();
                $stats['rooms']++;
            }
        }
        
    } elseif ($csv_type === 'lecturers') {
        // Import lecturers
        $stmt = $conn->prepare("INSERT INTO lecturers (name, availability_json) VALUES (?, ?)
                               ON DUPLICATE KEY UPDATE availability_json = VALUES(availability_json)");
        
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            $row = [];
            foreach ($headers as $i => $header) {
                $row[$header] = $data[$i] ?? '';
            }
            
            $name = trim($row['lecturer_name'] ?? $row['name'] ?? $data[0] ?? '');
            if (!$name) continue;
            
            // Build availability JSON from days
            $avail = [];
            $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'];
            foreach ($days as $idx => $day) {
                if (isset($row[$day]) && $row[$day] == 1) {
                    $avail[] = $idx;
                }
            }
            
            $json = json_encode($avail);
            $stmt->bind_param("ss", $name, $json);
            $stmt->execute();
            $stats['lecturers']++;
        }
    } else {
        throw new Exception("Unknown CSV type. Expected courses, rooms, or lecturers data.");
    }
    
    fclose($handle);
    
    // Commit transaction
    $conn->commit();
    
    // Update row count in csv_storage
    $stmt = $conn->prepare("UPDATE csv_storage SET row_count = ? WHERE filename = ?");
    $total_rows = array_sum($stats);
    $basename = basename($filename);
    $stmt->bind_param("is", $total_rows, $basename);
    $stmt->execute();
    
    // Auto-update departmental_courses.csv in B2 if we imported courses/sections
    $b2_update_result = null;
    if ($csv_type === 'courses' && $stats['sections'] > 0) {
        require_once __DIR__ . '/update_department_courses.php';
        $b2_update_result = update_department_courses_in_b2();
    }
    
    $response = [
        'status' => 'success',
        'message' => "Successfully imported from $basename",
        'csv_type' => $csv_type,
        'stats' => $stats
    ];
    
    if ($b2_update_result) {
        $response['b2_sync'] = $b2_update_result;
    }
    
    echo json_encode($response);
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode([
        'status' => 'error',
        'message' => 'Import failed: ' . $e->getMessage()
    ]);
}
?>
