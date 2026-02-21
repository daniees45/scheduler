<?php
// as/api/register_public.php
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $fullname = trim($_POST['fullname']);
    $role = $_POST['role'];
    $password = $_POST['password'];
    $department = trim($_POST['department'] ?? '');
    $level = !empty($_POST['level']) ? intval($_POST['level']) : null;
    $lecturer_id = ($role === 'lecturer' && !empty($_POST['lecturer_id'])) ? $_POST['lecturer_id'] : null;

    // Security: Prevent public registration of super_admin and lecturer
    if ($role === 'super_admin' || $role === 'lecturer') {
        die(json_encode(["status" => "error", "message" => "Registration for this role is restricted to administrators only."]));
    }

    if (empty($username) || empty($password) || empty($department)) {
        die(json_encode(["status" => "error", "message" => "Please fill all required fields."]));
    }

    // Students must have fullname and level
    if ($role === 'student' && (empty($fullname) || empty($level))) {
        die(json_encode(["status" => "error", "message" => "Students must provide name and level."]));
    }

    // Lecturers must have lecturer_id
    if ($role === 'lecturer' && empty($lecturer_id)) {
        die(json_encode(["status" => "error", "message" => "Please select your lecturer profile."]));
    }

    // Check if username exists
    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        die(json_encode(["status" => "error", "message" => "Username already exists."]));
    }

    // Hash password
    $hash = password_hash($password, PASSWORD_DEFAULT);
    
    try {
        $stmt = $conn->prepare("INSERT INTO users (username, full_name, role, password_hash, department, level, lecturer_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssis", $username, $fullname, $role, $hash, $department, $level, $lecturer_id);
        
        if ($stmt->execute()) {
            echo json_encode(["status" => "success", "message" => "Account created! You can now log in."]);
        } else {
            echo json_encode(["status" => "error", "message" => "Database error: " . $stmt->error]);
        }
    } catch (Exception $e) {
        echo json_encode(["status" => "error", "message" => "Server error: " . $e->getMessage()]);
    }
}
?>
