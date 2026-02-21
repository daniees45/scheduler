-- Personal Scheduler Enhancement Schema
-- Adds tables for priorities, goals, productivity tracking, notifications, and reminders

USE vvu_scheduler;

-- User Priorities & Goals
CREATE TABLE IF NOT EXISTS user_priorities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    priority_name VARCHAR(100) NOT NULL,
    priority_level ENUM('high', 'medium', 'low') DEFAULT 'medium',
    category VARCHAR(50) DEFAULT 'general', -- study, work, personal, health, etc.
    description TEXT,
    target_hours_per_week DECIMAL(5,2) DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_active (user_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- User Goals (SMART goals)
CREATE TABLE IF NOT EXISTS user_goals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    goal_title VARCHAR(200) NOT NULL,
    description TEXT,
    category VARCHAR(50) DEFAULT 'general',
    target_completion_date DATE,
    status ENUM('active', 'completed', 'paused', 'cancelled') DEFAULT 'active',
    priority_level ENUM('high', 'medium', 'low') DEFAULT 'medium',
    progress_percentage INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_status (user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Productivity Log (tracks task completion and effectiveness)
CREATE TABLE IF NOT EXISTS productivity_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    task_name VARCHAR(200) NOT NULL,
    task_category VARCHAR(50) DEFAULT 'general',
    day VARCHAR(20) NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    duration_minutes INT,
    quality_rating INT DEFAULT 3, -- 1-5 scale
    completion_status ENUM('completed', 'partial', 'skipped') DEFAULT 'completed',
    productivity_score DECIMAL(5,2), -- calculated score
    notes TEXT,
    logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_day (user_id, day),
    INDEX idx_user_logged (user_id, logged_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Task Preferences (learned from Q-learning)
CREATE TABLE IF NOT EXISTS task_preferences (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    task_category VARCHAR(50) NOT NULL,
    preferred_day VARCHAR(20),
    preferred_time_start TIME,
    preferred_time_end TIME,
    preference_score DECIMAL(5,2) DEFAULT 0,
    times_accepted INT DEFAULT 0,
    times_rejected INT DEFAULT 0,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_category_time (user_id, task_category, preferred_day, preferred_time_start),
    INDEX idx_user_category (user_id, task_category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Notifications & Reminders
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    notification_type VARCHAR(50) NOT NULL, -- reminder, conflict, suggestion, achievement
    title VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    related_event_id INT DEFAULT NULL, -- links to personal_events.id
    related_course_id INT DEFAULT NULL, -- links to courses.id
    scheduled_time DATETIME NOT NULL,
    is_sent BOOLEAN DEFAULT FALSE,
    sent_at DATETIME DEFAULT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    read_at DATETIME DEFAULT NULL,
    priority ENUM('high', 'medium', 'low') DEFAULT 'medium',
    delivery_method VARCHAR(50) DEFAULT 'in_app', -- in_app, email, sms
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_scheduled (user_id, scheduled_time, is_sent),
    INDEX idx_user_unread (user_id, is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Reminders Configuration
CREATE TABLE IF NOT EXISTS reminder_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    reminder_type VARCHAR(50) NOT NULL, -- course_reminder, exam_reminder, personal_event, study_session
    enabled BOOLEAN DEFAULT TRUE,
    minutes_before INT DEFAULT 30, -- notify N minutes before
    delivery_method VARCHAR(50) DEFAULT 'in_app',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_type (user_id, reminder_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Schedule Suggestions (AI-generated recommendations)
CREATE TABLE IF NOT EXISTS schedule_suggestions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    suggestion_type VARCHAR(50) NOT NULL, -- study_slot, task_slot, break_time
    day VARCHAR(20) NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    duration_minutes INT,
    priority_score DECIMAL(5,2) DEFAULT 0,
    productivity_score DECIMAL(5,2) DEFAULT 0,
    reason TEXT, -- explanation for suggestion
    status ENUM('pending', 'accepted', 'rejected', 'expired') DEFAULT 'pending',
    suggested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    responded_at TIMESTAMP DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_status (user_id, status),
    INDEX idx_user_suggested (user_id, suggested_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Productivity Analytics Cache (pre-computed metrics)
CREATE TABLE IF NOT EXISTS productivity_metrics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    metric_date DATE NOT NULL,
    total_productive_hours DECIMAL(5,2) DEFAULT 0,
    task_completion_rate DECIMAL(5,2) DEFAULT 0,
    average_quality_rating DECIMAL(3,2) DEFAULT 0,
    most_productive_day VARCHAR(20),
    most_productive_hour INT,
    category_breakdown JSON, -- {study: 5.5, work: 3.0, personal: 2.5}
    computed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_date (user_id, metric_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert default reminder settings for existing users
INSERT INTO reminder_settings (user_id, reminder_type, enabled, minutes_before)
SELECT id, 'course_reminder', TRUE, 30 FROM users WHERE role = 'student'
ON DUPLICATE KEY UPDATE enabled = enabled;

INSERT INTO reminder_settings (user_id, reminder_type, enabled, minutes_before)
SELECT id, 'exam_reminder', TRUE, 1440 FROM users WHERE role = 'student'
ON DUPLICATE KEY UPDATE enabled = enabled;

INSERT INTO reminder_settings (user_id, reminder_type, enabled, minutes_before)
SELECT id, 'personal_event', TRUE, 15 FROM users WHERE role = 'student'
ON DUPLICATE KEY UPDATE enabled = enabled;
