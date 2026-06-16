<?php
header('Content-Type: application/json');
require_once 'auth_support.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    auth_support_ensure_user_auth_columns($conn);

    $username = trim((string)($_POST['username'] ?? ''));
    $fullname = trim((string)($_POST['fullname'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $role = (string)($_POST['role'] ?? 'student');
    $password = (string)($_POST['password'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $level = !empty($_POST['level']) ? intval($_POST['level']) : null;
    $lecturer_id = ($role === 'lecturer' && !empty($_POST['lecturer_id'])) ? $_POST['lecturer_id'] : null;

    // Security: Prevent public registration of super_admin and lecturer
    if ($role === 'super_admin' || $role === 'lecturer') {
        die(json_encode(["status" => "error", "message" => "Registration for this role is restricted to administrators only."]));
    }

    if (empty($username) || empty($password) || empty($department) || empty($email)) {
        die(json_encode(["status" => "error", "message" => "Please fill all required fields."]));
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        die(json_encode(["status" => "error", "message" => "Please provide a valid email address."]));
    }

    if (strlen($password) < 6) {
        die(json_encode(["status" => "error", "message" => "Password must be at least 6 characters."]));
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

    // Check if email is already used
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        die(json_encode(["status" => "error", "message" => "Email already exists."]));
    }

    // Hash password and generate verification code
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $code = auth_support_generate_code(6);
    $codeHash = auth_support_hash_code($code);
    $minutes = 15;
    $expiresAt = date('Y-m-d H:i:s', time() + ($minutes * 60));
    
    try {
        $conn->begin_transaction();

        $stmt = $conn->prepare("INSERT INTO users (username, full_name, role, password_hash, email, email_verified, verification_code_hash, verification_code_expires_at, department, level, lecturer_id) VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssssssis", $username, $fullname, $role, $hash, $email, $codeHash, $expiresAt, $department, $level, $lecturer_id);

        if (!$stmt->execute()) {
            $conn->rollback();
            echo json_encode(["status" => "error", "message" => "Database error: " . $stmt->error]);
            exit;
        }

        $mailResult = auth_support_send_confirmation_code($email, $fullname, $code, $minutes);
        if (!$mailResult['success']) {
            $conn->rollback();
            error_log('Registration confirmation email failed: ' . (string)$mailResult['error']);
            echo json_encode(["status" => "error", "message" => "Unable to send confirmation code now. Please try again later."]);
            exit;
        }

        $conn->commit();
        echo json_encode([
            "status" => "success",
            "message" => "Account created. We sent a confirmation code to your email.",
            "verify_required" => true,
            "username" => $username,
            "email" => $email
        ]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(["status" => "error", "message" => "Server error: " . $e->getMessage()]);
    }
}
?>
