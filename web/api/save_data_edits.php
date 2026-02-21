<?php
/**
 * API to save edited data (rooms, lecturers, courses)
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

$data = json_decode(file_get_contents('php://input'), true);
$action = $data['action'] ?? null;

if ($action === 'save_room') {
    $id = $data['id'] ?? null;
    $name = $data['name'] ?? '';
    $capacity = $data['capacity'] ?? 50;
    
    if (!$name) {
        echo json_encode(['status' => 'error', 'message' => 'Room name required']);
        exit;
    }
    
    if ($id) {
        // Update
        $stmt = $conn->prepare("UPDATE rooms SET room_name = ?, capacity = ? WHERE id = ?");
        $stmt->bind_param("sii", $name, $capacity, $id);
    } else {
        // Insert
        $stmt = $conn->prepare("INSERT INTO rooms (room_name, capacity) VALUES (?, ?)");
        $stmt->bind_param("si", $name, $capacity);
    }
    
    if ($stmt->execute()) {
        echo json_encode([
            'status' => 'success',
            'message' => 'Room saved successfully',
            'id' => $id || $conn->insert_id
        ]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to save room']);
    }
} 
elseif ($action === 'delete_room') {
    $id = $data['id'] ?? null;
    if (!$id) {
        echo json_encode(['status' => 'error', 'message' => 'Room ID required']);
        exit;
    }
    
    $stmt = $conn->prepare("DELETE FROM rooms WHERE id = ?");
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'Room deleted']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to delete room']);
    }
}
elseif ($action === 'save_lecturer') {
    $id = $data['id'] ?? null;
    $name = $data['name'] ?? '';
    $avail = $data['availability'] ?? [];
    
    if (!$name) {
        echo json_encode(['status' => 'error', 'message' => 'Lecturer name required']);
        exit;
    }
    
    $avail_json = json_encode($avail);
    
    if ($id) {
        // Update
        $stmt = $conn->prepare("UPDATE lecturers SET name = ?, availability_json = ? WHERE id = ?");
        $stmt->bind_param("ssi", $name, $avail_json, $id);
    } else {
        // Insert
        $stmt = $conn->prepare("INSERT INTO lecturers (name, availability_json) VALUES (?, ?)");
        $stmt->bind_param("ss", $name, $avail_json);
    }
    
    if ($stmt->execute()) {
        echo json_encode([
            'status' => 'success',
            'message' => 'Lecturer saved successfully',
            'id' => $id || $conn->insert_id
        ]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to save lecturer']);
    }
}
elseif ($action === 'save_course') {
    $id = $data['id'] ?? null;
    $code = $data['course_code'] ?? '';
    $title = $data['course_title'] ?? '';
    $semester = $data['semester'] ?? '1';
    $level = $data['level'] ?? 100;
    $credits = $data['credits'] ?? 3;
    
    if (!$code || !$title) {
        echo json_encode(['status' => 'error', 'message' => 'Course code and title required']);
        exit;
    }
    
    if ($id) {
        // Update
        $stmt = $conn->prepare("UPDATE courses SET course_code = ?, course_title = ?, semester = ?, level = ?, credit_hours = ? WHERE id = ?");
        $stmt->bind_param("ssssii", $code, $title, $semester, $level, $credits, $id);
    } else {
        // Insert
        $stmt = $conn->prepare("INSERT INTO courses (course_code, course_title, semester, type, level, credit_hours) VALUES (?, ?, ?, 'Departmental', ?, ?)");
        $stmt->bind_param("sssii", $code, $title, $semester, $level, $credits);
    }
    
    if ($stmt->execute()) {
        echo json_encode([
            'status' => 'success',
            'message' => 'Course saved successfully',
            'id' => $id || $conn->insert_id
        ]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to save course']);
    }
}
else {
    echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
}
