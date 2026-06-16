<?php
// rest.php — Unified REST API for feedback and schedule
require_once __DIR__ . '/../config/bootstrap.php';
header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$segments = explode('/', $path);

// Require explicit secure token configuration and header-based auth only.
$configuredToken = trim((string)scheduler_config('api.token', ''));
if ($configuredToken === '' || $configuredToken === 'changeme' || strlen($configuredToken) < 20) {
    http_response_code(503);
    echo json_encode(['status' => 'error', 'message' => 'REST API token is not configured.']);
    exit;
}

$token = $_SERVER['HTTP_X_API_TOKEN'] ?? '';
if ($token === '' || !hash_equals($configuredToken, $token)) {
    http_response_code(401);
    echo json_encode(['status'=>'error','message'=>'Invalid API token']);
    exit;
}

// /api/rest.php/feedback (GET, POST)
if (isset($segments[2]) && $segments[2] === 'feedback') {
    if ($method === 'GET') {
        $uid = isset($_GET['user_id']) ? intval($_GET['user_id']) : null;
        $q = "SELECT * FROM student_feedback";
        if ($uid) $q .= " WHERE user_id=$uid";
        $q .= " ORDER BY created_at DESC LIMIT 200";
        $res = $conn->query($q);
        $rows = [];
        while ($row = $res->fetch_assoc()) $rows[] = $row;
        echo json_encode(['status'=>'success','data'=>$rows]);
        exit;
    }
    if ($method === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        $user_id = intval($data['user_id'] ?? 0);
        $course1 = trim($data['course1'] ?? '');
        $course2 = trim($data['course2'] ?? '');
        $semester = trim($data['semester'] ?? '');
        $reason = trim($data['reason'] ?? '');
        if (!$user_id || !$course1 || !$course2 || !$semester) {
            http_response_code(400);
            echo json_encode(['status'=>'error','message'=>'Missing required fields']);
            exit;
        }
        $course1 = substr(filter_var($course1, FILTER_SANITIZE_STRING), 0, 32);
        $course2 = substr(filter_var($course2, FILTER_SANITIZE_STRING), 0, 32);
        $semester = substr(filter_var($semester, FILTER_SANITIZE_STRING), 0, 16);
        $reason = substr(filter_var($reason, FILTER_SANITIZE_STRING), 0, 512);
        $stmt = $conn->prepare("INSERT IGNORE INTO student_feedback (user_id, course1, course2, semester, reason) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param('issss', $user_id, $course1, $course2, $semester, $reason);
        $stmt->execute();
        if ($stmt->affected_rows > 0) {
            echo json_encode(['status'=>'success','message'=>'Feedback recorded']);
        } else {
            echo json_encode(['status'=>'duplicate','message'=>'Duplicate feedback or already submitted']);
        }
        exit;
    }
}
// /api/rest.php/schedule (GET)
if (isset($segments[2]) && $segments[2] === 'schedule') {
    if ($method === 'GET') {
        $semester = trim($_GET['semester'] ?? '');
        $user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : null;
        $q = "SELECT * FROM schedules";
        $where = [];
        if ($semester) $where[] = "semester='" . $conn->real_escape_string($semester) . "'";
        if ($user_id) $where[] = "user_id=$user_id";
        if ($where) $q .= " WHERE " . implode(' AND ', $where);
        $q .= " ORDER BY created_at DESC LIMIT 100";
        $res = $conn->query($q);
        $rows = [];
        while ($row = $res->fetch_assoc()) $rows[] = $row;
        echo json_encode(['status'=>'success','data'=>$rows]);
        exit;
    }
}
// Not found
http_response_code(404);
echo json_encode(['status'=>'error','message'=>'Unknown endpoint']);
