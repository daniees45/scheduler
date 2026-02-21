<?php
// as/api/auth.php
require_once 'db.php';

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: ../login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    if (empty($username) || empty($password)) {
        header("Location: ../login.php?error=All fields are required");
        exit;
    }

    $stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['department'] = $user['department'];
        $_SESSION['level'] = $user['level'];
        $_SESSION['lecturer_id'] = $user['lecturer_id'];
        
        header("Location: ../dashboard.php");
        exit;
    } else {
        header("Location: ../login.php?error=Invalid credentials");
        exit;
    }
}
?>
