<?php
// as/api/update_db.php
// Imports AI-generated CSV back into MySQL

require_once 'db.php';
require_once __DIR__ . '/../../lib/B2Storage.php';

$b2 = new B2Storage();

$chosen_file = $_GET['file'] ?? $_POST['file'] ?? 'csv/final/final_web_schedule.csv';

// Ensure the path is correct (handle both basename and relative path)
if (strpos($chosen_file, 'csv/final/') === false && strpos($chosen_file, '/') === false) {
    $chosen_file = 'csv/final/' . $chosen_file;
}

$csv_path = '../../' . $chosen_file;
$temp_path = null;

// Try local first
if (!file_exists($csv_path)) {
    // Try B2
    $result = $b2->download($chosen_file);
    if ($result['success']) {
        // Use a temporary file for parsing
        $temp_path = tempnam(sys_get_temp_dir(), 'sched_');
        file_put_contents($temp_path, $result['content']);
        $csv_path = $temp_path;
        error_log("update_db: Using B2 source for $chosen_file");
    } else {
        die(json_encode(["status" => "error", "message" => "Schedule CSV not found locally or in B2: $chosen_file"]));
    }
}

try {
    $handle = fopen($csv_path, 'r');
    if (!$handle) {
        throw new Exception("Could not open CSV file: " . basename($csv_path));
    }
    $headers = fgetcsv($handle); // Skip header

    // Prepare lookups for speed
    $courses = [];
    $res = $conn->query("SELECT id, course_code FROM courses");
    while($c = $res->fetch_assoc()) {
        $courses[strtoupper(trim($c['course_code']))] = $c['id'];
    }

    $lecturers = [];
    $res = $conn->query("SELECT id, name FROM lecturers");
    while($l = $res->fetch_assoc()) {
        $lecturers[trim($l['name'])] = $l['id'];
    }

    $rooms = [];
    $res = $conn->query("SELECT id, room_name FROM rooms");
    while($r = $res->fetch_assoc()) {
        $rooms[trim($r['room_name'])] = $r['id'];
    }

    // Prepare update statement
    $stmt = $conn->prepare("UPDATE sections SET room_id = ?, assigned_day = ?, assigned_time = ? WHERE course_id = ? AND lecturer_id = ?");

    $count = 0;
    while (($row = fgetcsv($handle, 1000, ",")) !== FALSE) {
        // columns: Course Code, Course Title, Credit Hrs, Lecturer Name, Room Name, Day, Time
        $code = strtoupper(trim($row[0] ?? ''));
        $lecturer = trim($row[3] ?? '');
        $room = trim($row[4] ?? '');
        $day = $row[5] ?? '';
        $time = $row[6] ?? '';

        $c_id = $courses[$code] ?? null;
        $l_id = $lecturers[$lecturer] ?? null;
        $r_id = $rooms[$room] ?? null;

        if ($c_id && $l_id && $r_id) {
            // room_id (i), assigned_day (s), assigned_time (s), course_id (i), lecturer_id (i)
            $stmt->bind_param("issii", $r_id, $day, $time, $c_id, $l_id);
            $stmt->execute();
            $count++;
        }
    }
    fclose($handle);
    
    // Clean up temp file if created
    if ($temp_path && file_exists($temp_path)) {
        @unlink($temp_path);
    }

    echo json_encode(["status" => "success", "message" => "Imported $count sections to database."]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
