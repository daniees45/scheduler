<?php
require_once 'db.php';

// Ensure session is started and user is student
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
$action = $_POST['action'] ?? '';

header('Content-Type: application/json');

if ($action === 'save_personal_activity') {
    $day = $_POST['day'] ?? '';
    $slot = $_POST['slot'] ?? '';
    $activity = $_POST['activity'] ?? '';

    if (empty($day) || empty($slot)) {
        echo json_encode(['success' => false, 'error' => 'Missing data']);
        exit;
    }

    $stmt = $conn->prepare("INSERT INTO personal_schedule (user_id, day, time_slot, activity) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE activity = ?");
    $stmt->bind_param("issss", $user_id, $day, $slot, $activity, $activity);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => $conn->error]);
    }
} 
elseif ($action === 'mark_course_completed') {
    $course_code = $_POST['course_code'] ?? '';
    if (empty($course_code)) {
        echo json_encode(['success' => false, 'error' => 'Missing course code']);
        exit;
    }

    $stmt = $conn->prepare("INSERT INTO student_course_status (user_id, course_code, status, completed_at) VALUES (?, ?, 'completed', NOW()) ON DUPLICATE KEY UPDATE status = 'completed', completed_at = NOW()");
    $stmt->bind_param("is", $user_id, $course_code);

    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => $conn->error]);
    }
}
elseif ($action === 'mark_course_required') {
    $course_code = $_POST['course_code'] ?? '';
    if (empty($course_code)) {
        echo json_encode(['success' => false, 'error' => 'Missing course code']);
        exit;
    }

    $stmt = $conn->prepare("UPDATE student_course_status SET status = 'required', completed_at = NULL WHERE user_id = ? AND course_code = ?");
    $stmt->bind_param("is", $user_id, $course_code);

    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => $conn->error]);
    }
}
else {
    echo json_encode(['success' => false, 'error' => 'Invalid action']);
}
?>
