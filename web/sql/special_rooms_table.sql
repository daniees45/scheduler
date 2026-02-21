-- SQL Schema for special_rooms table
-- This table manages pre-assigned course-to-room mappings with optional time/day constraints

CREATE TABLE IF NOT EXISTS special_rooms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    course_code VARCHAR(50) NOT NULL UNIQUE,
    room_name VARCHAR(100) NOT NULL,
    fixed_day VARCHAR(20) DEFAULT NULL,
    fixed_time VARCHAR(20) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_course (course_code),
    INDEX idx_room (room_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert sample data (PEAC 100 must use Basketball Court on Monday at 5pm)
INSERT INTO special_rooms (course_code, room_name, fixed_day, fixed_time) 
VALUES ('PEAC 100', 'B. Ball Court', 'Monday', '5:00pm')
ON DUPLICATE KEY UPDATE 
    room_name = VALUES(room_name),
    fixed_day = VALUES(fixed_day),
    fixed_time = VALUES(fixed_time);
