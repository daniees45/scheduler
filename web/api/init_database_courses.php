<?php
// web/api/init_database_courses.php
// Loads courses and sections for a given department and semester from the DB for scheduling session
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_guard.php';

header('Content-Type: application/json');

require_http_methods(['POST', 'GET']);
require_authenticated_user();
require_admin_user(); // Only super_admin and faculty_admin can initialize scheduling sessions

$department = trim((string)($_POST['department'] ?? $_GET['department'] ?? ''));
if ($department === '') {
    echo json_encode(['success' => false, 'message' => 'No department specified.']);
    exit;
}

// semester can be '1' (first semester), '2' (second semester), or '3' (all semesters)
$semester = trim((string)($_POST['semester'] ?? $_GET['semester'] ?? '3'));

// Build Query
$query = "SELECT 
    c.id AS course_id,
    c.course_code, 
    c.course_title, 
    c.semester, 
    c.type, 
    c.level, 
    c.credit_hours,
    l.name as lecturer_name,
    s.assigned_day,
    s.assigned_time,
    r.room_name,
    s.section_name
FROM sections s
INNER JOIN courses c ON s.course_id = c.id
LEFT JOIN lecturers l ON s.lecturer_id = l.id
LEFT JOIN rooms r ON s.room_id = r.id
WHERE c.department = ?";

if ($semester === '1' || $semester === '2') {
    $query .= " AND c.semester = ?";
}

$stmt = $conn->prepare($query);

if ($semester === '1' || $semester === '2') {
    $stmt->bind_param('ss', $department, $semester);
} else {
    $stmt->bind_param('s', $department);
}

$stmt->execute();
$res = $stmt->get_result();

$courses = [];
while ($row = $res->fetch_assoc()) {
    $courses[] = $row;
}

if (empty($courses)) {
    $sem_label = ($semester === '1') ? 'First Semester' : (($semester === '2') ? 'Second Semester' : 'any semester');
    echo json_encode([
        'success' => false, 
        'message' => "No courses/sections found for department '$department' in $sem_label."
    ]);
    exit;
}

// Format the courses as a 2D CSV array matching the validator/scheduler requirements
$headers = [
    'course_code',
    'course_title',
    'lecturer_name',
    'semester',
    'day',
    'start_time',
    'end_time',
    'room_name',
    'type',
    'level',
    'credit_hours'
];

$csvData = [];
$csvData[] = $headers;

foreach ($courses as $row) {
    $title = $row['course_title'];
    $sec = trim($row['section_name'] ?? '');
    // Append section name to title if it's not already present
    if ($sec !== '' && strpos($title, $sec) === false && strpos($title, 'Sec') === false) {
        $title .= " [Sec " . $sec . "]";
    }
    
    $csvData[] = [
        $row['course_code'],
        $title,
        $row['lecturer_name'] ?? '',
        $row['semester'] ?? '1',
        $row['assigned_day'] ?? '',
        $row['assigned_time'] ?? '',
        '', // end_time
        $row['room_name'] ?? '',
        $row['type'] ?? 'Departmental',
        $row['level'] ?? '100',
        $row['credit_hours'] ?? '3'
    ];
}

// Generate unique session ID
$session_id = uniqid('dept_', true);
$filename = 'database_' . strtolower(str_replace([' ', '/', '\\'], '_', $department)) . '_sem' . $semester . '_courses.csv';

// Save to session so edit_csv.php and the generator can access it
$_SESSION['uploaded_csv'] = [
    'id' => $session_id,
    'filename' => $filename,
    'data' => $csvData,
    'uploaded_at' => date('Y-m-d H:i:s')
];

$_SESSION['ready_for_scheduling'] = [
    'id' => $session_id,
    'filename' => $filename,
    'data' => $csvData,
    'prepared_at' => date('Y-m-d H:i:s')
];

echo json_encode([
    'success' => true,
    'message' => 'Successfully loaded ' . count($courses) . ' courses from database.',
    'session_id' => $session_id,
    'row_count' => count($courses)
]);
exit;
