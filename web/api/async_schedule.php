<?php
// async_schedule.php — Trigger async schedule generation (demo: background job)
require_once __DIR__ . '/../config/bootstrap.php';
header('Content-Type: application/json');

session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin','faculty_admin'])) {
    http_response_code(403);
    echo json_encode(['status'=>'error','message'=>'Forbidden']);
    exit;
}
$semester = trim($_POST['semester'] ?? '');
if (!$semester) {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'Missing semester']);
    exit;
}
// Simulate background job (in production, use queue/cron)
file_put_contents(__DIR__ . '/../../cache/schedule_job_' . $semester . '.txt', json_encode([
    'semester' => $semester,
    'requested_by' => $_SESSION['user_id'],
    'requested_at' => date('c')
]));
// Audit log
$conn->query("INSERT INTO audit_log (user_id, action, details) VALUES (" . intval($_SESSION['user_id']) . ", 'schedule_async_requested', 'Semester: $semester')");
echo json_encode(['status'=>'success','message'=>'Schedule generation started in background']);
