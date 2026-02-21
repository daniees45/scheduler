<?php
// web/api/generated_schedules.php
header('Content-Type: application/json');
require_once 'db.php';

try {
    $res = $conn->query("SELECT id, schedule_name, semester, department, accuracy, created_at FROM generated_schedules ORDER BY created_at DESC");
    $schedules = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    echo json_encode(['status' => 'success', 'schedules' => $schedules]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>