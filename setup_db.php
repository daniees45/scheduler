<?php
require_once 'db_config.php';

// 1. Create Database
$conn->query("CREATE DATABASE IF NOT EXISTS vvu_scheduler");
$conn->select_db("vvu_scheduler");

// 2. Create Users Table (RBAC)
$conn->query("CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'lecturer', 'student') DEFAULT 'student'
)");

// 3. Create Courses Table
$conn->query("CREATE TABLE IF NOT EXISTS courses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    course_code VARCHAR(50),
    course_title VARCHAR(255),
    lecturer_name VARCHAR(255),
    semester INT,
    day VARCHAR(10),
    start_time VARCHAR(20),
    end_time VARCHAR(20),
    room_name VARCHAR(100),
    source_type VARCHAR(50),
    course_level INT,
    credit_hours FLOAT,
    timings VARCHAR(100)
)");

// 4. Create Lecturer Preferences Table
$conn->query("CREATE TABLE IF NOT EXISTS lecturer_preferences (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lecturer_name VARCHAR(255),
    preferred_day VARCHAR(10),
    preferred_start_time VARCHAR(20),
    preferred_end_time VARCHAR(20),
    priority INT DEFAULT 1
)");

// 5. Create Special Rooms Table (Course-to-Room Pre-assignments)
$conn->query("CREATE TABLE IF NOT EXISTS special_rooms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    course_code VARCHAR(50) NOT NULL UNIQUE,
    room_name VARCHAR(100) NOT NULL,
    fixed_day VARCHAR(20) DEFAULT NULL,
    fixed_time VARCHAR(20) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_course (course_code),
    INDEX idx_room (room_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Insert default special room assignment for PEAC 100
$conn->query("INSERT IGNORE INTO special_rooms (course_code, room_name, fixed_day, fixed_time) 
              VALUES ('PEAC 100', 'B. Ball Court', 'Monday', '5:00pm')");

// 6. Insert default admin if not exists (password: admin123)
$adminCheck = $conn->query("SELECT id FROM users WHERE username = 'admin'");
if ($adminCheck->num_rows == 0) {
    $pass = password_hash('admin123', PASSWORD_DEFAULT);
    $conn->query("INSERT INTO users (username, password, role) VALUES ('admin', '$pass', 'admin')");
}

echo "Database and all tables (Users, Courses, Preferences) setup successfully.";
$conn->close();
?>
