<?php
// as/update_db_schema.php
require_once 'api/db.php';

echo "Starting Database Schema Update...\n";

function safeExecute($conn, $sql, $description) {
    try {
        if ($conn->query($sql)) {
            echo "[SUCCESS] $description\n";
        } else {
            // Check for duplicate column or constraint
            if (stripos($conn->error, "Duplicate column") !== false) {
                echo "[SKIPPED] $description (Column already exists)\n";
            } elseif (stripos($conn->error, "already exists") !== false) {
                echo "[SKIPPED] $description (Table/Key already exists)\n";
            } else {
                echo "[ERROR] $description: " . $conn->error . "\n";
            }
        }
    } catch (Exception $e) {
        if (stripos($e->getMessage(), "Duplicate column") !== false) {
            echo "[SKIPPED] $description (Column already exists)\n";
        } elseif (stripos($e->getMessage(), "already exists") !== false) {
             echo "[SKIPPED] $description (Table/Key already exists)\n";
        } else {
            echo "[ERROR] $description: " . $e->getMessage() . "\n";
        }
    }
}

// 1.1 Alter Users Table
// Run columns individually to avoid failure if one exists
safeExecute($conn, "ALTER TABLE users ADD COLUMN department VARCHAR(100) DEFAULT NULL COMMENT 'CS, Nursing, Theology, etc.'", "Add department to users");
safeExecute($conn, "ALTER TABLE users ADD COLUMN level INT DEFAULT NULL COMMENT 'For students: 100, 200, 300, 400'", "Add level to users");
safeExecute($conn, "ALTER TABLE users ADD COLUMN lecturer_id INT DEFAULT NULL COMMENT 'Link to lecturers table'", "Add lecturer_id to users");

// Add constraint
// We check if it exists by query or just try-catch (simpler here)
safeExecute($conn, "ALTER TABLE users ADD CONSTRAINT fk_user_lecturer FOREIGN KEY (lecturer_id) REFERENCES lecturers(id) ON DELETE SET NULL", "Add FK fk_user_lecturer");

// 1.2 Alter Lecturers Table
safeExecute($conn, "ALTER TABLE lecturers ADD COLUMN department VARCHAR(100) DEFAULT NULL COMMENT 'Department affiliation'", "Add department to lecturers");

// 1.3 Create Student Enrollments Table
$sql_enrollments = "CREATE TABLE IF NOT EXISTS student_enrollments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    course_id INT NOT NULL,
    semester ENUM('1', '2') NOT NULL,
    academic_year VARCHAR(10) DEFAULT '2025/2026',
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    UNIQUE KEY unique_enrollment (user_id, course_id, semester)
)";
safeExecute($conn, $sql_enrollments, "Create student_enrollments table");

echo "Database update process finished.\n";
?>
