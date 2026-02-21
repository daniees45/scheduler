CREATE DATABASE IF NOT EXISTS vvu_scheduler;
USE vvu_scheduler;

-- Users Table
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('super_admin', 'faculty_admin', 'lecturer', 'student') NOT NULL,
    full_name VARCHAR(100),
    email VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Courses Table (Master list)
CREATE TABLE IF NOT EXISTS courses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    course_code VARCHAR(20) NOT NULL UNIQUE,
    course_title VARCHAR(200) NOT NULL,
    credit_hours INT DEFAULT 3,
    level INT NOT NULL, -- 100, 200, 300, 400
    semester ENUM('1', '2') NOT NULL,
    type ENUM('Departmental', 'General') DEFAULT 'Departmental',
    program VARCHAR(100) DEFAULT 'CS', -- CS, IT, BIS etc
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Rooms Table
CREATE TABLE IF NOT EXISTS rooms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    room_name VARCHAR(50) NOT NULL UNIQUE,
    capacity INT NOT NULL,
    type VARCHAR(50) DEFAULT 'Lecture',
    is_lab BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Lecturers Table
CREATE TABLE IF NOT EXISTS lecturers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    email VARCHAR(100),
    availability_json JSON, -- Stores {day: [slots]}
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Sections (The actual class instances being scheduled)
CREATE TABLE IF NOT EXISTS sections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    course_id INT,
    lecturer_id INT,
    room_id INT, -- Assigned room (can be null initially)
    assigned_day VARCHAR(15), -- Assigned by AI
    assigned_time VARCHAR(20), -- Assigned by AI
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    FOREIGN KEY (lecturer_id) REFERENCES lecturers(id) ON DELETE SET NULL,
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL
);

-- Schedules (Tracking generation history)
CREATE TABLE IF NOT EXISTS schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    generated_by INT,
    status ENUM('pending', 'completed', 'failed') DEFAULT 'pending',
    log_file VARCHAR(255),
    output_file VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (generated_by) REFERENCES users(id)
);

-- Default Super Admin (password: admin123)
-- Hash generated via standard bcrypt cost 10
INSERT IGNORE INTO users (username, password_hash, role, full_name, email) 
VALUES ('admin', '$2y$10$S6N4iILVWjkYvSGAmRjN1.zuwnpp/xJmEv1rswH0j/s7vkJV/YOp2', 'super_admin', 'System Admin', 'admin@vvu.edu.gh');


ALTER TABLE schedule_versions ADD COLUMN pdf_content LONGBLOB, ADD COLUMN user_id INT, ADD FOREIGN KEY (user_id) REFERENCES users(id);