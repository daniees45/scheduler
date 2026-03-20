<?php

header('Content-Type: application/json');

require_once 'db.php';
require_once __DIR__ . '/../includes/unified_schedule_service.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$role = (string)($_SESSION['role'] ?? 'guest');
if (!in_array($role, ['student', 'lecturer'], true)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
    exit;
}

$semester = isset($_GET['semester']) ? (string)$_GET['semester'] : (string)($_SESSION['semester'] ?? '1');
$saved_at = isset($_GET['saved_at']) ? (string)$_GET['saved_at'] : '';
$schedule_id = isset($_GET['schedule_id']) ? (int)$_GET['schedule_id'] : 0;

try {
    $payload = unified_schedule_fetch($conn, $_SESSION, [
        'semester' => $semester,
        'saved_at' => $saved_at,
        'schedule_id' => $schedule_id
    ]);

    echo json_encode([
        'status' => 'success',
        'data' => $payload
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
