<?php
// api/enrollment.php
// API for managing student course enrollments
header('Content-Type: application/json');
require_once 'db.php';

session_start();

// Check authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'] ?? '';

$has_enrollment_section = false;
$section_check = $conn->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'student_enrollments' AND COLUMN_NAME = 'section' LIMIT 1");
if ($section_check) {
    $section_check->execute();
    $section_check_res = $section_check->get_result();
    $has_enrollment_section = $section_check_res && $section_check_res->num_rows > 0;
}

// Only students can enroll
if ($role !== 'student') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Only students can enroll in courses']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// GET - Get student's enrollments
if ($method === 'GET' && $action === 'my_enrollments') {
    $stmt = $conn->prepare("
        SELECT 
            se.id as enrollment_id,
            se.semester,
            se.academic_year,
            se.created_at,
            c.id as course_id,
            c.course_code,
            c.course_title,
            c.level,
            c.department,
            c.semester as course_semester,
            l.name as lecturer_name
        FROM student_enrollments se
        JOIN courses c ON se.course_id = c.id
        LEFT JOIN lecturers l ON c.lecturer_id = l.id
        WHERE se.user_id = ?
        ORDER BY c.course_code
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $enrollments = [];
    while ($row = $result->fetch_assoc()) {
        $enrollments[] = $row;
    }
    
    echo json_encode([
        'status' => 'success',
        'data' => $enrollments,
        'count' => count($enrollments)
    ]);
    exit;
}

// GET - Get available courses for student's level
if ($method === 'GET' && $action === 'available_courses') {
    $level = $_SESSION['level'] ?? 0;
    $department = $_SESSION['department'] ?? '';
    
    if (empty($level)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Student level not set']);
        exit;
    }
    
    // Get enrolled course IDs
    $enrolled_ids = [];
    $stmt = $conn->prepare("SELECT course_id FROM student_enrollments WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $enrolled_ids[] = $row['course_id'];
    }
    
    // Build exclusion clause
    $exclusion = '';
    if (!empty($enrolled_ids)) {
        $placeholders = implode(',', array_fill(0, count($enrolled_ids), '?'));
        $exclusion = " AND c.id NOT IN ($placeholders)";
    }
    
    // Get available courses
    $sql = "
        SELECT 
            c.id,
            c.course_code,
            c.course_title,
            c.level,
            c.department,
            c.semester,
            c.credit_hours,
            l.name as lecturer_name
        FROM courses c
        LEFT JOIN lecturers l ON c.lecturer_id = l.id
        WHERE c.level = ?
        $exclusion
        ORDER BY c.course_code
    ";
    
    $stmt = $conn->prepare($sql);
    
    if (!empty($enrolled_ids)) {
        $types = 'i' . str_repeat('i', count($enrolled_ids));
        $params = array_merge([$level], $enrolled_ids);
        $stmt->bind_param($types, ...$params);
    } else {
        $stmt->bind_param("i", $level);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $courses = [];
    while ($row = $result->fetch_assoc()) {
        $courses[] = $row;
    }
    
    echo json_encode([
        'status' => 'success',
        'data' => $courses,
        'count' => count($courses)
    ]);
    exit;
}

// POST - Enroll in a course
if ($method === 'POST' && $action === 'enroll') {
    $input = json_decode(file_get_contents('php://input'), true);
    $course_id = intval($input['course_id'] ?? 0);
    $semester = $input['semester'] ?? '1';
    $selected_section = strtoupper(trim((string)($input['selected_section'] ?? '')));
    if ($selected_section !== '') {
        $selected_section = preg_replace('/^\s*SEC(?:TION)?\s*/i', '', $selected_section);
        $selected_section = preg_replace('/[^A-Z0-9]/', '', (string)$selected_section);
    }
    if ($selected_section === 'DEFAULT') {
        $selected_section = '';
    }
    
    if ($course_id <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid course ID']);
        exit;
    }
    
    // Check if course exists and is for student's level
    $level = $_SESSION['level'] ?? 0;
    $stmt = $conn->prepare("SELECT id, course_code, course_title, level FROM courses WHERE id = ? AND level = ?");
    $stmt->bind_param("ii", $course_id, $level);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Course not found or not for your level']);
        exit;
    }
    
    $course = $result->fetch_assoc();
    
    // Check if already enrolled
    $stmt = $conn->prepare("SELECT id FROM student_enrollments WHERE user_id = ? AND course_id = ?");
    $stmt->bind_param("ii", $user_id, $course_id);
    $stmt->execute();
    
    if ($stmt->get_result()->num_rows > 0) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Already enrolled in this course']);
        exit;
    }
    
    // Enroll
    if ($has_enrollment_section) {
        $stmt = $conn->prepare("INSERT INTO student_enrollments (user_id, course_id, section, semester) VALUES (?, ?, ?, ?)");
        $section_to_store = $selected_section !== '' ? $selected_section : null;
        $stmt->bind_param("iiss", $user_id, $course_id, $section_to_store, $semester);
    } else {
        $stmt = $conn->prepare("INSERT INTO student_enrollments (user_id, course_id, semester) VALUES (?, ?, ?)");
        $stmt->bind_param("iis", $user_id, $course_id, $semester);
    }
    
    if ($stmt->execute()) {
        echo json_encode([
            'status' => 'success',
            'message' => "Successfully enrolled in {$course['course_code']}",
            'course' => $course
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Failed to enroll: ' . $stmt->error]);
    }
    exit;
}

// DELETE - Unenroll from a course
if ($method === 'DELETE' || ($method === 'POST' && $action === 'unenroll')) {
    $course_id = intval($_GET['course_id'] ?? $_REQUEST['course_id'] ?? 0);
    
    if ($course_id <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid course ID']);
        exit;
    }
    
    $stmt = $conn->prepare("DELETE FROM student_enrollments WHERE user_id = ? AND course_id = ?");
    $stmt->bind_param("ii", $user_id, $course_id);
    
    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            echo json_encode(['status' => 'success', 'message' => 'Successfully unenrolled']);
        } else {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'Enrollment not found']);
        }
    } else {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Failed to unenroll: ' . $stmt->error]);
    }
    exit;
}

// Invalid action
http_response_code(400);
echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
?>
