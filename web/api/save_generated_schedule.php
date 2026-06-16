<?php
// web/api/save_generated_schedule.php
// Save only GENERATED schedules to database (not input CSV)
require_once 'db.php';
require_once __DIR__ . '/auth_guard.php';

header('Content-Type: application/json');

require_http_methods('POST');
$user_id = require_authenticated_user();

function normalize_accuracy_value($value): ?string
{
    $num = (float)$value;
    if ($num < 0 || $num > 100) {
        return null;
    }

    $formatted = rtrim(rtrim(number_format($num, 2, '.', ''), '0'), '.');
    return $formatted . '%';
}

function normalize_accuracy_for_storage($raw): string
{
    if (!is_string($raw) && !is_numeric($raw)) {
        return '0%';
    }

    if (is_numeric($raw)) {
        return normalize_accuracy_value($raw) ?? '0%';
    }

    $text = trim((string)$raw);
    if ($text === '') {
        return '0%';
    }

    // Prefer explicit percentage forms first (most specific - requires % sign)
    if (preg_match('/(\d{1,3}(?:\.\d+)?)\s*%/', $text, $matches)) {
        return normalize_accuracy_value($matches[1]) ?? '0%';
    }
    
    // Then try forms with accuracy keyword
    if (preg_match('/(\d{1,3}(?:\.\d+)?)\s*%+\s*accuracy/i', $text, $matches)) {
        return normalize_accuracy_value($matches[1]) ?? '0%';
    }
    if (preg_match('/accuracy[^0-9]{0,5}(\d{1,3}(?:\.\d+)?)/i', $text, $matches)) {
        return normalize_accuracy_value($matches[1]) ?? '0%';
    }

    // Only allow plain numeric strings. Reject mixed strings like "48s".
    if (preg_match('/^\s*\d{1,3}(?:\.\d+)?\s*$/', $text)) {
        return normalize_accuracy_value($text) ?? '0%';
    }

    return '0%';
}

function lookup_accuracy_from_generation_log(mysqli $conn, string $schedule_name): string
{
    $base = pathinfo($schedule_name, PATHINFO_FILENAME);
    $search = '%' . $base . '%';
    $stmt = $conn->prepare("SELECT details FROM audit_log WHERE action IN ('SCHEDULE_GEN_SUCCESS','EXAM_GEN_SUCCESS','EXAM_COMBINED_SUCCESS') AND details LIKE ? ORDER BY log_time DESC LIMIT 1");
    if (!$stmt) {
        return '0%';
    }

    $stmt->bind_param('s', $search);
    $stmt->execute();
    $res = $stmt->get_result();
    if (!($row = $res->fetch_assoc())) {
        return '0%';
    }

    return normalize_accuracy_for_storage((string)($row['details'] ?? ''));
}

$input = json_decode(file_get_contents('php://input'), true);

function infer_schedule_type($scheduleName, $scheduleData, $explicitType = ''): string
{
    $explicitType = strtolower(trim((string)$explicitType));
    if (in_array($explicitType, ['class', 'exam'], true)) {
        return $explicitType;
    }

    $name = strtolower(trim((string)$scheduleName));
    if ($name !== '' && strpos($name, 'exam') !== false) {
        return 'exam';
    }

    $header = [];
    if (is_array($scheduleData) && !empty($scheduleData)) {
        $first = $scheduleData[0] ?? [];
        if (is_array($first)) {
            $isSequential = array_keys($first) === range(0, count($first) - 1);
            if ($isSequential) {
                $header = array_map(static function ($value) {
                    return strtolower(trim((string)$value));
                }, $first);
            } else {
                $header = array_map(static function ($value) {
                    return strtolower(trim((string)$value));
                }, array_keys($first));
            }
        }
    }

    foreach ($header as $column) {
        if ($column === 'invigilator' || $column === 'invigilator name') {
            return 'exam';
        }
    }

    return 'class';
}

$schedule_name = $input['schedule_name'] ?? 'Untitled Schedule';
$semester = $input['semester'] ?? '1';
$department = $input['department'] ?? '';
$accuracy = normalize_accuracy_for_storage($input['accuracy'] ?? '0%');
if ($accuracy === '0%') {
    $accuracy = lookup_accuracy_from_generation_log($conn, (string)$schedule_name);
}
$schedule_data = $input['schedule_data'] ?? [];
$schedule_type = infer_schedule_type($schedule_name, $schedule_data, $input['schedule_type'] ?? '');

$default_year = date('Y') . '/' . (date('Y') + 1);
$academic_year = trim((string)($input['academic_year'] ?? ''));
if (!preg_match('/^\d{4}\/\d{4}$/', $academic_year)) {
    $set_stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'current_academic_year' LIMIT 1");
    if ($set_stmt) {
        $set_stmt->execute();
        $set_res = $set_stmt->get_result();
        if ($set_res && ($set_row = $set_res->fetch_assoc())) {
            $academic_year = trim((string)($set_row['setting_value'] ?? ''));
        }
    }
}
if (!preg_match('/^\d{4}\/\d{4}$/', $academic_year)) {
    $academic_year = $default_year;
}

try {
    // Create generated_schedules table if not exists
    $conn->query("CREATE TABLE IF NOT EXISTS generated_schedules (
        id INT AUTO_INCREMENT PRIMARY KEY,
        schedule_name VARCHAR(255) NOT NULL,
        semester VARCHAR(10),
        academic_year VARCHAR(10) DEFAULT NULL,
        department VARCHAR(100),
        schedule_type VARCHAR(20) DEFAULT 'class',
        accuracy VARCHAR(20),
        schedule_data LONGTEXT,
        generated_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(generated_by),
        INDEX(created_at)
    )");

    $check_col = $conn->query("SHOW COLUMNS FROM generated_schedules LIKE 'academic_year'");
    if (!$check_col || $check_col->num_rows === 0) {
        $conn->query("ALTER TABLE generated_schedules ADD COLUMN academic_year VARCHAR(10) NULL AFTER semester");
    }

    $type_col = $conn->query("SHOW COLUMNS FROM generated_schedules LIKE 'schedule_type'");
    if (!$type_col || $type_col->num_rows === 0) {
        $conn->query("ALTER TABLE generated_schedules ADD COLUMN schedule_type VARCHAR(20) NOT NULL DEFAULT 'class' AFTER department");
    }
    
    // Save schedule
    $schedule_json = json_encode($schedule_data);
    $stmt = $conn->prepare("INSERT INTO generated_schedules 
        (schedule_name, semester, academic_year, department, schedule_type, accuracy, schedule_data, generated_by) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssssssi", $schedule_name, $semester, $academic_year, $department, $schedule_type, $accuracy, $schedule_json, $user_id);
    $stmt->execute();
    
    $schedule_id = $conn->insert_id;
    
    // Add to audit_log
    $username = $_SESSION['username'] ?? 'User';
    $action = 'SCHEDULE_COMMIT_DB';
    $details = "Schedule '{$schedule_name}' (Type: {$schedule_type}, Accuracy: {$accuracy}, Dept: {$department}) committed to database.";
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    
    $audit_stmt = $conn->prepare("INSERT INTO audit_log (user_id, username, action, status, details, ip_address) VALUES (?, ?, ?, 'success', ?, ?)");
    $audit_stmt->bind_param("issss", $user_id, $username, $action, $details, $ip);
    $audit_stmt->execute();

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
