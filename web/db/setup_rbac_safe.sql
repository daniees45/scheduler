-- Quick RBAC Schema Setup for VVU Scheduler
-- Safe to run multiple times (uses IF NOT EXISTS)

USE vvu_scheduler;

-- Check and add department column to users
SET @dbname = 'vvu_scheduler';
SET @tablename = 'users';
SET @columnname = 'department';
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE (table_name = @tablename)
     AND (table_schema = @dbname)
     AND (column_name = @columnname)
  ) > 0,
  "SELECT 'Column department exists' AS msg;",
  "ALTER TABLE users ADD COLUMN department VARCHAR(100) DEFAULT NULL COMMENT 'CS, Nursing, Theology, etc.';"
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Check and add level column to users
SET @columnname = 'level';
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE (table_name = @tablename)
     AND (table_schema = @dbname)
     AND (column_name = @columnname)
  ) > 0,
  "SELECT 'Column level exists' AS msg;",
  "ALTER TABLE users ADD COLUMN level INT DEFAULT NULL COMMENT 'For students: 100, 200, 300, 400';"
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Check and add lecturer_id column to users
SET @columnname = 'lecturer_id';
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE (table_name = @tablename)
     AND (table_schema = @dbname)
     AND (column_name = @columnname)
  ) > 0,
  "SELECT 'Column lecturer_id exists' AS msg;",
  "ALTER TABLE users ADD COLUMN lecturer_id INT DEFAULT NULL COMMENT 'Link to lecturers table';"
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add foreign key if not exists (with error suppression)
-- Note: This might fail if constraint already exists, which is fine
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
   WHERE CONSTRAINT_NAME = 'fk_user_lecturer'
     AND TABLE_SCHEMA = @dbname
     AND TABLE_NAME = 'users'
  ) > 0,
  "SELECT 'Foreign key fk_user_lecturer exists' AS msg;",
  "ALTER TABLE users ADD CONSTRAINT fk_user_lecturer FOREIGN KEY (lecturer_id) REFERENCES lecturers(id) ON DELETE SET NULL;"
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add department to lecturers table
SET @tablename = 'lecturers';
SET @columnname = 'department';
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE (table_name = @tablename)
     AND (table_schema = @dbname)
     AND (column_name = @columnname)
  ) > 0,
  "SELECT 'Column department in lecturers exists' AS msg;",
  "ALTER TABLE lecturers ADD COLUMN department VARCHAR(100) DEFAULT NULL COMMENT 'Department affiliation';"
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Create student_enrollments table if not exists
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

-- Update existing admin users with department
UPDATE users SET department = 'Administration' WHERE role = 'super_admin' AND department IS NULL;

-- Show final status
SELECT 'RBAC Schema Setup Complete!' AS Status;

-- Show table structure
DESCRIBE users;
DESCRIBE student_enrollments;

SELECT 
    COUNT(*) as total_users,
    SUM(CASE WHEN role = 'student' THEN 1 ELSE 0 END) as students,
    SUM(CASE WHEN role = 'lecturer' THEN 1 ELSE 0 END) as lecturers,
    SUM(CASE WHEN role IN ('super_admin', 'faculty_admin') THEN 1 ELSE 0 END) as admins
FROM users;
