<?php
// as/api/auth.php
require_once 'auth_support.php';

auth_support_ensure_user_auth_columns($conn);

function auth_get_audit_columns(mysqli $conn): array {
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }

    $cached = [];
    $result = $conn->query("SHOW COLUMNS FROM audit_log");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $cached[$row['Field']] = true;
        }
    }
    return $cached;
}

function auth_audit_log(mysqli $conn, string $action, array $details = [], ?int $userId = null, ?string $username = null, string $status = 'info'): void {
    try {
        $cols = auth_get_audit_columns($conn);
        if (!$cols || (!isset($cols['action']) && !isset($cols['entity_type']))) {
            return;
        }

        $fields = [];
        $types = '';
        $values = [];

        if (isset($cols['user_id'])) {
            $fields[] = 'user_id';
            $types .= 'i';
            $values[] = $userId ?? 0;
        }
        if (isset($cols['username'])) {
            $fields[] = 'username';
            $types .= 's';
            $values[] = $username ?? 'SYSTEM';
        }
        if (isset($cols['action'])) {
            $fields[] = 'action';
            $types .= 's';
            $values[] = $action;
        }
        if (isset($cols['entity_type'])) {
            $fields[] = 'entity_type';
            $types .= 's';
            $values[] = $action;
        }
        if (isset($cols['status'])) {
            $fields[] = 'status';
            $types .= 's';
            $values[] = $status;
        }
        if (isset($cols['details'])) {
            $fields[] = 'details';
            $types .= 's';
            $values[] = json_encode($details, JSON_UNESCAPED_SLASHES);
        }
        if (isset($cols['ip_address'])) {
            $fields[] = 'ip_address';
            $types .= 's';
            $values[] = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        }
        if (isset($cols['user_agent'])) {
            $fields[] = 'user_agent';
            $types .= 's';
            $values[] = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? 'web-auth'), 0, 255);
        }

        if (empty($fields)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($fields), '?'));
        $sql = "INSERT INTO audit_log (" . implode(',', $fields) . ") VALUES ($placeholders)";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return;
        }
        $stmt->bind_param($types, ...$values);
        $stmt->execute();
        $stmt->close();
    }
    catch (Throwable $e) {
        // Never block auth flow because of logging failure.
    }
}

if (isset($_GET['logout'])) {
    $logoutUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    $logoutUsername = isset($_SESSION['username']) ? (string)$_SESSION['username'] : 'UNKNOWN';
    auth_audit_log($conn, 'LOGOUT', ['message' => 'User logged out'], $logoutUserId, $logoutUsername, 'success');
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
        if (isset($user['email_verified']) && (int)$user['email_verified'] !== 1) {
            header("Location: ../login.php?error=Please verify your email before signing in");
            exit;
        }

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['department'] = $user['department'];
        $_SESSION['level'] = $user['level'];
        $_SESSION['lecturer_id'] = $user['lecturer_id'];

        auth_audit_log(
            $conn,
            'LOGIN_SUCCESS',
            [
                'message' => 'User login successful',
                'role' => $user['role'] ?? null,
                'department' => $user['department'] ?? null,
            ],
            (int)$user['id'],
            (string)$user['username'],
            'success'
        );
        
        header("Location: ../dashboard.php");
        exit;
    } else {
        auth_audit_log(
            $conn,
            'LOGIN_FAILURE',
            ['message' => 'Invalid credentials', 'username_attempt' => $username],
            null,
            $username,
            'warning'
        );
        header("Location: ../login.php?error=Invalid credentials");
        exit;
    }
}
?>
