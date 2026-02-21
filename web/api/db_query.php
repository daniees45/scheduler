<?php
/**
 * API to query database records (rooms, lecturers, courses)
 */

session_start();
header('Content-Type: application/json');
require_once 'db.php';

// Access control
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

$type = $_GET['type'] ?? null;

if ($type === 'rooms') {
    $result = $conn->query("SELECT id, room_name, capacity FROM rooms ORDER BY room_name");
    $rooms = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rooms[] = $row;
        }
    }
    echo json_encode(['status' => 'success', 'rooms' => $rooms]);
}
elseif ($type === 'lecturers') {
    $result = $conn->query("SELECT id, name, availability_json FROM lecturers ORDER BY name");
    $lecturers = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $lecturers[] = $row;
        }
    }
    echo json_encode(['status' => 'success', 'lecturers' => $lecturers]);
}
elseif ($type === 'courses') {
    $result = $conn->query("SELECT id, course_code, course_title, semester, level FROM courses ORDER BY course_code");
    $courses = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $courses[] = $row;
        }
    }
    echo json_encode(['status' => 'success', 'courses' => $courses]);
}
else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid type']);
}
