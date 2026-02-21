<?php
// as/api/validate_data.php
header('Content-Type: application/json');
require_once 'db.php';

$issues = [];
$warnings = [];

try {
    // 1. Check Rooms with 0 Capacity
    $res = $conn->query("SELECT room_name FROM rooms WHERE capacity = 0 OR capacity IS NULL");
    while ($r = $res->fetch_assoc()) {
        $issues[] = "Room '{$r['room_name']}' has 0 capacity.";
    }

    // 2. Check Lecturers with No Availability
    $res = $conn->query("SELECT name, availability_json FROM lecturers");
    while ($l = $res->fetch_assoc()) {
        $avail = json_decode($l['availability_json'] ?? '[]', true);
        if (is_array($avail) && empty($avail)) { // Empty array means no availability if using index logic? Wait, logic is index of AVAILABLE days.
            // If avail is empty array [], it means NO days available.
            $issues[] = "Lecturer '{$l['name']}' has NO available days.";
        }
    }

    // 3. Check Duplicate Course Codes (if unique constraint missing)
    $res = $conn->query("SELECT course_code, COUNT(*) as c FROM courses GROUP BY course_code HAVING c > 1");
    while ($c = $res->fetch_assoc()) {
        $issues[] = "Duplicate Course Code detected: '{$c['course_code']}' appears {$c['c']} times.";
    }

    // 4. Check Sections without Lecturer
    $res = $conn->query("SELECT c.course_code FROM sections s JOIN courses c ON s.course_id = c.id WHERE s.lecturer_id IS NULL");
    while ($s = $res->fetch_assoc()) {
        $warnings[] = "Section for '{$s['course_code']}' has no assigned lecturer.";
    }

    // 5. Check required core scheduling data in active DB tables (not legacy csv_storage blobs)
    $room_count_res = $conn->query("SELECT COUNT(*) AS c FROM rooms");
    $room_count = ($room_count_res && ($row = $room_count_res->fetch_assoc())) ? (int)$row['c'] : 0;
    if ($room_count === 0) {
        $warnings[] = "Core data missing: no room records found in table 'rooms'.";
    }

    // departmental_courses.csv is normalized into courses + sections tables
    $course_count_res = $conn->query("SELECT COUNT(*) AS c FROM courses");
    $course_count = ($course_count_res && ($row = $course_count_res->fetch_assoc())) ? (int)$row['c'] : 0;

    $section_count_res = $conn->query("SELECT COUNT(*) AS c FROM sections");
    $section_count = ($section_count_res && ($row = $section_count_res->fetch_assoc())) ? (int)$row['c'] : 0;

    if ($course_count === 0 && $section_count === 0) {
        $warnings[] = "Core data missing: no departmental course records found in 'courses'/'sections' tables.";
    }
    
    echo json_encode([
        'status' => 'success',
        'issues' => $issues,
        'warnings' => $warnings,
        'message' => (empty($issues) && empty($warnings)) ? "Data integrity check passed! System ready." : "Found " . count($issues) . " issues and " . count($warnings) . " warnings."
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
