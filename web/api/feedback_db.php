<?php
// feedback_db.php — Feedback DB migration and API
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');

$json_error = static function (int $status, string $message): void {
    http_response_code($status);
    echo json_encode(['status' => 'error', 'message' => $message]);
    exit;
};

// Create feedback and audit tables if not exist
$conn->query("CREATE TABLE IF NOT EXISTS student_feedback (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    course1 VARCHAR(32) NOT NULL,
    course2 VARCHAR(32) NOT NULL,
    semester VARCHAR(16) NOT NULL,
    timetable_file VARCHAR(255) DEFAULT NULL,
    reason TEXT,
    status ENUM('pending','resolved','ignored') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_feedback (user_id, course1, course2, semester)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

if ($conn->error) {
    $json_error(500, 'Failed to initialize feedback table: ' . $conn->error);
}

// Auto-migrate old tables to add timetable_file (only when missing)
$col_check = $conn->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                           WHERE TABLE_SCHEMA = DATABASE()
                             AND TABLE_NAME = 'student_feedback'
                             AND COLUMN_NAME = 'timetable_file'
                           LIMIT 1");
if ($col_check && $col_check->num_rows === 0) {
    $conn->query("ALTER TABLE student_feedback ADD COLUMN timetable_file VARCHAR(255) DEFAULT NULL");
    if ($conn->error) {
        $json_error(500, 'Failed to migrate feedback table: ' . $conn->error);
    }
}

$conn->query("CREATE TABLE IF NOT EXISTS audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    action VARCHAR(64) NOT NULL,
    details TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
if ($conn->error) {
    $json_error(500, 'Failed to initialize audit table: ' . $conn->error);
}

// API: Add feedback (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!isset($_SESSION['user_id'])) {
        $json_error(401, 'Not logged in');
    }
    // CSRF token check
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !$csrf_token || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
        $json_error(403, 'Invalid CSRF token');
    }
    $user_id = $_SESSION['user_id'];

    $action = trim((string)($_POST['action'] ?? ''));
    if ($action === 'update_status') {
        $role = (string)($_SESSION['role'] ?? '');
        if (!in_array($role, ['super_admin', 'faculty_admin'], true)) {
            $json_error(403, 'Forbidden');
        }

        $feedback_id = (int)($_POST['feedback_id'] ?? 0);
        $new_status = trim((string)($_POST['status'] ?? ''));
        $allowed_status = ['pending', 'resolved', 'ignored'];

        if ($feedback_id <= 0 || !in_array($new_status, $allowed_status, true)) {
            $json_error(400, 'Invalid feedback id or status');
        }

        $upd_stmt = $conn->prepare("UPDATE student_feedback SET status = ? WHERE id = ? LIMIT 1");
        if (!$upd_stmt) {
            $json_error(500, 'Failed to prepare update statement: ' . $conn->error);
        }

        $upd_stmt->bind_param('si', $new_status, $feedback_id);
        if (!$upd_stmt->execute()) {
            $json_error(500, 'Failed to update status: ' . $upd_stmt->error);
        }

        $conn->query("INSERT INTO audit_log (user_id, action, details) VALUES ($user_id, 'feedback_status_updated', 'Feedback #$feedback_id changed to $new_status')");

        echo json_encode([
            'status' => 'success',
            'message' => 'Feedback status updated.',
            'feedback_id' => $feedback_id,
            'new_status' => $new_status,
        ]);
        exit;
    }

    // Rate limit: max 5 feedbacks per 5min
    $rl_res = $conn->query("SELECT COUNT(*) as n FROM student_feedback WHERE user_id=$user_id AND created_at > (NOW() - INTERVAL 5 MINUTE)");
    if (!$rl_res) {
        $json_error(500, 'Rate limit check failed: ' . $conn->error);
    }
    $rl_row = $rl_res ? $rl_res->fetch_assoc() : ['n'=>0];
    if ($rl_row['n'] >= 5) {
        http_response_code(429);
        echo json_encode(['status'=>'error','message'=>'Rate limit exceeded. Please wait before submitting more feedback.']);
        // Audit log
        $conn->query("INSERT INTO audit_log (user_id, action, details) VALUES ($user_id, 'feedback_rate_limited', 'Too many feedbacks in 5min')");
        exit;
    }
    $course1 = trim($_POST['course1'] ?? '');
    $course2 = trim($_POST['course2'] ?? '');
    $semester = trim($_POST['semester'] ?? '');
    $timetable_file = trim($_POST['timetable_file'] ?? '');
    $reason = trim($_POST['reason'] ?? '');
    if (!$course1 || !$course2 || !$semester || !$timetable_file) {
        $json_error(400, 'Missing required fields, including timetable file.');
    }
    // Sanitize
    $course1 = substr(filter_var($course1, FILTER_SANITIZE_STRING), 0, 32);
    $course2 = substr(filter_var($course2, FILTER_SANITIZE_STRING), 0, 32);
    $semester = substr(filter_var($semester, FILTER_SANITIZE_STRING), 0, 16);
    $timetable_file = substr(filter_var($timetable_file, FILTER_SANITIZE_STRING), 0, 255);
    $reason = substr(filter_var($reason, FILTER_SANITIZE_STRING), 0, 512);
    // Deduplication
    $stmt = $conn->prepare("INSERT IGNORE INTO student_feedback (user_id, course1, course2, semester, timetable_file, reason) VALUES (?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        $json_error(500, 'Failed to prepare insert statement: ' . $conn->error);
    }
    $stmt->bind_param('isssss', $user_id, $course1, $course2, $semester, $timetable_file, $reason);
    if (!$stmt->execute()) {
        $json_error(500, 'Failed to store feedback: ' . $stmt->error);
    }
    if ($stmt->affected_rows > 0) {
        echo json_encode(['status'=>'success','message'=>'Feedback recorded']);
        $conn->query("INSERT INTO audit_log (user_id, action, details) VALUES ($user_id, 'feedback_submitted', 'Feedback for $course1/$course2 $semester')");
    } else {
        echo json_encode(['status'=>'duplicate','message'=>'Duplicate feedback or already submitted']);
        $conn->query("INSERT INTO audit_log (user_id, action, details) VALUES ($user_id, 'feedback_duplicate', 'Duplicate for $course1/$course2 $semester')");
    }
    exit;
}
// API: List feedback (GET, admin only)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (isset($_GET['mine']) && $_GET['mine'] && isset($_SESSION['user_id']) && isset($_SESSION['role']) && $_SESSION['role'] === 'student') {
        $uid = intval($_SESSION['user_id']);
        $res = $conn->query("SELECT * FROM student_feedback WHERE user_id=$uid ORDER BY created_at DESC LIMIT 100");
        if (!$res) {
            $json_error(500, 'Failed to load feedback: ' . $conn->error);
        }
        $rows = [];
        while ($row = $res->fetch_assoc()) $rows[] = $row;
        echo json_encode(['status'=>'success','data'=>$rows]);
        exit;
    }
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || !in_array($_SESSION['role'], ['super_admin','faculty_admin'])) {
        $json_error(403, 'Forbidden');
    }
    $res = $conn->query("SELECT * FROM student_feedback ORDER BY created_at DESC LIMIT 200");
    if (!$res) {
        $json_error(500, 'Failed to load feedback list: ' . $conn->error);
    }
    $rows = [];
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    echo json_encode(['status'=>'success','data'=>$rows]);
    exit;
}

// Fallback: Always return JSON error if nothing else matched
http_response_code(400);
echo json_encode(['status'=>'error','message'=>'Invalid request or insufficient permissions']);
exit;
