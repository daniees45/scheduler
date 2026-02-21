-- Add department column to lecturers table
ALTER TABLE lecturers 
ADD COLUMN department VARCHAR(100) DEFAULT NULL AFTER email;

-- Optional: Add index for better search performance
ALTER TABLE lecturers 
ADD INDEX idx_department (department);
