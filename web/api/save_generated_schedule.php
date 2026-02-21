<?php
// web/api/save_generated_schedule.php
// Save only GENERATED schedules to database (not input CSV)
session_start();
require_once 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    die(json_encode(["status" => "error", "message" => "Unauthorized"]));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$schedule_name = $input['schedule_name'] ?? 'Untitled Schedule';
$semester = $input['semester'] ?? '1';
$department = $input['department'] ?? '';
$accuracy = $input['accuracy'] ?? '0%';
$schedule_data = $input['schedule_data'] ?? [];

try {
    // Create generated_schedules table if not exists
    $conn->query("CREATE TABLE IF NOT EXISTS generated_schedules (
        id INT AUTO_INCREMENT PRIMARY KEY,
        schedule_name VARCHAR(255) NOT NULL,
        semester VARCHAR(10),
        department VARCHAR(100),
        accuracy VARCHAR(20),
        schedule_data LONGTEXT,
        generated_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(generated_by),
        INDEX(created_at)
    )");
    
    // Save schedule
    $schedule_json = json_encode($schedule_data);
    $user_id = $_SESSION['user_id'];
    
    $stmt = $conn->prepare("INSERT INTO generated_schedules 
        (schedule_name, semester, department, accuracy, schedule_data, generated_by) 
        VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssssi", $schedule_name, $semester, $department, $accuracy, $schedule_json, $user_id);
    $stmt->execute();
    
    $schedule_id = $conn->insert_id;
    
    echo json_encode([
        'status' => 'success',
        'message' => 'Schedule saved successfully.',
        'schedule_id' => $schedule_id
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Failed to save schedule: ' . $e->getMessage()
    ]);
}
?>
