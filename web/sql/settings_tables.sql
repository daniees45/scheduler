-- Settings Tables for VVU Scheduler

USE vvu_scheduler;

-- 1. Core User Settings (Profile & General)
CREATE TABLE IF NOT EXISTS user_settings (
    user_id INT PRIMARY KEY,
    phone VARCHAR(20),
    photo_url VARCHAR(255),
    timezone VARCHAR(50) DEFAULT 'UTC',
    language VARCHAR(10) DEFAULT 'en',
    week_start ENUM('Sunday', 'Monday') DEFAULT 'Monday',
    time_format ENUM('12', '24') DEFAULT '12',
    profile_visibility ENUM('public', 'private', 'faculty') DEFAULT 'faculty',
    analytics_opt_in BOOLEAN DEFAULT TRUE,
    productivity_pref ENUM('Detailed', 'Basic', 'Off') DEFAULT 'Detailed',
    auto_suggest_free BOOLEAN DEFAULT TRUE,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Notification Settings
CREATE TABLE IF NOT EXISTS notification_settings (
    user_id INT PRIMARY KEY,
    class_reminders BOOLEAN DEFAULT TRUE,
    exam_reminders BOOLEAN DEFAULT TRUE,
    personal_event_reminders BOOLEAN DEFAULT TRUE,
    email_toggles BOOLEAN DEFAULT TRUE,
    in_app_toggles BOOLEAN DEFAULT TRUE,
    quiet_hours_enabled BOOLEAN DEFAULT FALSE,
    quiet_hours_start TIME DEFAULT '22:00:00',
    quiet_hours_end TIME DEFAULT '07:00:00',
    reminder_lead_time INT DEFAULT 15, -- minutes
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. AI Preferences
CREATE TABLE IF NOT EXISTS ai_settings (
    user_id INT PRIMARY KEY,
    suggestion_intensity ENUM('Low', 'Medium', 'High') DEFAULT 'Medium',
    preferred_work_start TIME DEFAULT '08:00:00',
    preferred_work_end TIME DEFAULT '17:00:00',
    max_daily_workload INT DEFAULT 8, -- hours
    accept_learning_toggle BOOLEAN DEFAULT TRUE,
    priority_goals_json JSON, -- For student defaults
    free_time_auto_suggest BOOLEAN DEFAULT TRUE,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Admin & Role-Specific Settings
CREATE TABLE IF NOT EXISTS admin_settings (
    user_id INT PRIMARY KEY,
    role ENUM('super_admin', 'faculty_admin', 'lecturer', 'student'),
    settings_json JSON, -- Stores role-specific fields (RBAC, API keys, Algorithm weights, etc.)
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Global Branding (Super Admin only)
CREATE TABLE IF NOT EXISTS branding_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    site_title VARCHAR(100) DEFAULT 'VVU Scheduler',
    site_color VARCHAR(10) DEFAULT '#2563eb', -- Default Blue
    site_logo VARCHAR(255),
    site_icon VARCHAR(255),
    updated_by INT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. Settings Change Log
CREATE TABLE IF NOT EXISTS settings_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    setting_type VARCHAR(50),
    old_value TEXT,
    new_value TEXT,
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Initialize Default Branding
INSERT IGNORE INTO branding_settings (id, site_title, site_color) 
VALUES (1, 'VVU Scheduler AI', '#2563eb');
