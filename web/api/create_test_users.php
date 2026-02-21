<?php
// web/api/create_test_users.php
// Create temporary test users for QA (localhost only)

require_once 'db.php';

// Restrict to localhost for safety
$client_ip = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($client_ip, ['127.0.0.1', '::1'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
    exit;
}

header('Content-Type: application/json');

$users = [
    [
        'username' => 'admin_test',
        'full_name' => 'QA Super Admin',
        'role' => 'super_admin',
        'password' => 'Test@12345',
        'department' => 'General',
        'level' => null,
        'lecturer_id' => null,
    ],
    [
        'username' => 'faculty_test',
        'full_name' => 'QA Faculty Admin',
        'role' => 'faculty_admin',
        'password' => 'Test@12345',
        'department' => 'Theology',
        'level' => null,
        'lecturer_id' => null,
    ],
];

$created = [];
$skipped = [];
$errors = [];

foreach ($users as $u) {
    try {
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->bind_param("s", $u['username']);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $skipped[] = $u['username'];
            continue;
        }

        $hash = password_hash($u['password'], PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO users (username, full_name, role, password_hash, department, level, lecturer_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param(
            "sssssis",
            $u['username'],
            $u['full_name'],
            $u['role'],
            $hash,
            $u['department'],
            $u['level'],
            $u['lecturer_id']
        );

        if ($stmt->execute()) {
            $created[] = $u['username'];
        } else {
            $errors[] = $u['username'] . ': ' . $stmt->error;
        }
    } catch (Exception $e) {
        $errors[] = $u['username'] . ': ' . $e->getMessage();
    }
}

echo json_encode([
    'status' => empty($errors) ? 'success' : 'partial',
    'created' => $created,
    'skipped' => $skipped,
    'errors' => $errors,
    'credentials' => [
        'admin_test' => 'Test@12345',
        'faculty_test' => 'Test@12345'
    ]
]);
?>
