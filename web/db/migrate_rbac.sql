-- Phase 1: Database Schema Enhancements
USE vvu_scheduler;

-- 1.1 Alter Users Table
ALTER TABLE users 
ADD COLUMN department VARCHAR(100) DEFAULT NULL COMMENT 'CS, Nursing, Theology, etc.',
ADD COLUMN level INT DEFAULT NULL COMMENT 'For students: 100, 200, 300, 400',
ADD COLUMN lecturer_id INT DEFAULT NULL COMMENT 'Link to lecturers table';

-- Add foreign key constraint
ALTER TABLE users
ADD CONSTRAINT fk_user_lecturer 
FOREIGN KEY (lecturer_id) REFERENCES lecturers(id) ON DELETE SET NULL;

-- 1.2 Alter Lecturers Table
ALTER TABLE lecturers 
ADD COLUMN department VARCHAR(100) DEFAULT NULL COMMENT 'Department affiliation';

-- 1.3 Create Student Enrollments Table
CREATE TABLE IF NOT EXISTS student_enrollments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    course_id INT NOT NULL,
    semester ENUM('1', '2') NOT NULL,
    academic_year VARCHAR(10) DEFAULT '2025/2026',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    UNIQUE KEY unique_enrollment (user_id, course_id, semester)
);

-- 1.4 Update existing admin user with department
UPDATE users SET department = 'Administration' WHERE role = 'super_admin';
