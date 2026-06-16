<?php
require_once 'db.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'lecturer') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

$user_id = (int)$_SESSION['user_id'];
$action = $_POST['action'] ?? '';

if ($action !== 'save_personal_activity') {
    echo json_encode(['success' => false, 'error' => 'Invalid action']);
    exit;
}

$day = trim((string)($_POST['day'] ?? ''));
$slot = trim((string)($_POST['slot'] ?? ''));
$activity = trim((string)($_POST['activity'] ?? ''));

if ($day === '' || $slot === '') {
    echo json_encode(['success' => false, 'error' => 'Missing data']);
    exit;
}

$stmt = $conn->prepare("INSERT INTO personal_schedule (user_id, day, time_slot, activity) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE activity = VALUES(activity)");
$stmt->bind_param('isss', $user_id, $day, $slot, $activity);

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => $conn->error]);
}
?>