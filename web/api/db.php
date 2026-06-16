<?php
// as/api/db.php
require_once __DIR__ . '/../../config/bootstrap.php';

$host = (string)scheduler_config('database.host', '127.0.0.1');
$db = (string)scheduler_config('database.name', 'vvu_scheduler');
$user = (string)scheduler_config('database.user', 'root');
$pass = (string)scheduler_config('database.pass', '');
$charset = (string)scheduler_config('database.charset', 'utf8mb4');
$port = (int)scheduler_config('database.port', 3307);
$socket = scheduler_config('database.socket', null);

// Create connection
try {
    $socketPath = is_string($socket) && trim($socket) !== '' ? $socket : null;
    $conn = new mysqli($host, $user, $pass, $db, $port, $socketPath);
}
catch (mysqli_sql_exception $e) {
    $fallbackHosts = [];

    if ($socket && $host !== '127.0.0.1') {
        $fallbackHosts[] = ['127.0.0.1', $port, null];
    }
    if ($host !== 'localhost') {
        $fallbackHosts[] = ['localhost', $port, $socket];
    }

    $lastException = $e;

    foreach ($fallbackHosts as [$fallbackHost, $fallbackPort, $fallbackSocket]) {
        try {
            $socketPath = is_string($fallbackSocket) && trim((string)$fallbackSocket) !== '' ? $fallbackSocket : null;
            $conn = new mysqli($fallbackHost, $user, $pass, $db, (int)$fallbackPort, $socketPath);
            $lastException = null;
            break;
        }
        catch (mysqli_sql_exception $fallbackException) {
            $lastException = $fallbackException;
        }
    }

    if ($lastException instanceof mysqli_sql_exception) {
        error_log("DB Connection Failed: " . $lastException->getMessage());
        if (basename($_SERVER['PHP_SELF']) == 'login.php' || basename($_SERVER['PHP_SELF']) == 'index.php') {
            die("<div style='padding: 20px; color: red; text-align: center; font-family: sans-serif;'>
                    <h2>System Maintenance</h2>
                    <p>The database service is currently unavailable. Please check configuration.</p>
                 </div>");
        }

        $GLOBALS['db_connection_error'] = $lastException->getMessage();
        if (ob_get_level() == 0) ob_start();
        exit;
    }
}
// Check connection (legacy check)
if ($conn->connect_error) {
    error_log("DB Connection Failed (legacy check): " . $conn->connect_error);
    die("Database connection failed.");
}

// Set charset
$conn->set_charset($charset);

/**
 * Ensure special_rooms table exists before any query that depends on it.
 */
function ensure_special_rooms_table(mysqli $conn): bool
{
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

/**
 * Trigger background synchronization to B2.
 */
function trigger_b2_sync(): void
{
    try {
        $configuredBase = scheduler_web_callback_base_url();
        if ($configuredBase) {
            $url = scheduler_url_join($configuredBase, 'api/sync.php');
        } else {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $path = dirname($_SERVER['PHP_SELF']);
            if (strpos($path, '/api') !== false) {
                $url = "$protocol://$host" . dirname($path) . "/api/sync.php";
            }
            else {
                $url = "$protocol://$host$path/api/sync.php";
            }
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_exec($ch);
        curl_close($ch);
    }
    catch (Exception $e) {
        error_log("Cloud Sync Trigger Failed: " . $e->getMessage());
    }
}

// Start Session globally for auth
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>