<?php
// as/api/db.php
$host = '127.0.0.1';
$db   = 'vvu_scheduler';
$user = 'root';
$pass = ''; // Default XAMPP password
$charset = 'utf8mb4';

// Create connection
try {
    // Standardize on localhost for socket connection (XAMPP default)
    $conn = new mysqli('localhost', $user, $pass, $db);
} catch (mysqli_sql_exception $e) {
    // Fallback to 127.0.0.1 if localhost fails
    try {
        $conn = new mysqli('127.0.0.1', $user, $pass, $db);
    } catch (mysqli_sql_exception $e2) {
        error_log("DB Connection Failed: " . $e2->getMessage());
        // Handle gracefully for HTML pages
        if (basename($_SERVER['PHP_SELF']) == 'login.php' || basename($_SERVER['PHP_SELF']) == 'index.php') {
            die("<div style='padding: 20px; color: red; text-align: center; font-family: sans-serif;'>
                    <h2>System Maintenance</h2>
                    <p>The database service is currently unavailable. Please check configuration.</p>
                    <p><small>" . htmlspecialchars($e2->getMessage()) . "</small></p>
                 </div>");
        }
        
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['error' => 'Database connection failed: ' . $e2->getMessage()]);
        exit;
    }
}
// Check connection (legacy check)
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set charset
$conn->set_charset($charset);

/**
 * Ensure special_rooms table exists before any query that depends on it.
 */
function ensure_special_rooms_table(mysqli $conn): bool {
    $sql = "CREATE TABLE IF NOT EXISTS special_rooms (
        id INT AUTO_INCREMENT PRIMARY KEY,
        course_code VARCHAR(50) NOT NULL UNIQUE,
        room_name VARCHAR(100) NOT NULL,
        fixed_day VARCHAR(20) DEFAULT NULL,
        fixed_time VARCHAR(20) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_course (course_code),
        INDEX idx_room (room_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    return (bool)$conn->query($sql);
}

// Start Session globally for auth
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
