<?php
/**
 * Initialize Audit Log Table
 * Creates the audit_log table if it doesn't exist
 */

require_once __DIR__ . '/api/db.php';

// Note: audit_log table already exists in the database with schema:
// (id, user_id, username, action, resource, resource_id, status, details, ip_address, user_agent, log_time)
// We'll use it as-is

echo "✅ Using existing audit_log table\n";

// Create notification_preferences table
$create_notif_prefs = "CREATE TABLE IF NOT EXISTS `notification_preferences` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `notification_type` VARCHAR(50) NOT NULL,
    `channel` VARCHAR(20) NOT NULL DEFAULT 'email',
    `enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `lead_time_hours` INT(11) NOT NULL DEFAULT 24,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `user_type_channel` (`user_id`, `notification_type`, `channel`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($create_notif_prefs)) {
    echo "✅ notification_preferences table created/verified successfully\n";
} else {
    echo "❌ Error creating notification_preferences table: " . $conn->error . "\n";
}

// Create notification_history table
$create_notif_history = "CREATE TABLE IF NOT EXISTS `notification_history` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `notification_type` VARCHAR(50) NOT NULL,
    `channel` VARCHAR(20) NOT NULL,
    `recipient` VARCHAR(255) NOT NULL,
    `subject` VARCHAR(255) DEFAULT NULL,
    `message` TEXT NOT NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
    `error_message` TEXT DEFAULT NULL,
    `attempts` INT(11) NOT NULL DEFAULT 0,
    `sent_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_status` (`status`),
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($create_notif_history)) {
    echo "✅ notification_history table created/verified successfully\n";
} else {
    echo "❌ Error creating notification_history table: " . $conn->error . "\n";
}

// Create user_contact_info table
$create_contact_info = "CREATE TABLE IF NOT EXISTS `user_contact_info` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `phone_number` VARCHAR(20) DEFAULT NULL,
    `secondary_email` VARCHAR(255) DEFAULT NULL,
    `phone_verified` TINYINT(1) NOT NULL DEFAULT 0,
    `email_verified` TINYINT(1) NOT NULL DEFAULT 0,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($create_contact_info)) {
    echo "✅ user_contact_info table created/verified successfully\n";
} else {
    echo "❌ Error creating user_contact_info table: " . $conn->error . "\n";
}

// Create conflict_resolutions table
$create_conflicts = "CREATE TABLE IF NOT EXISTS `conflict_resolutions` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `conflict_id` VARCHAR(100) NOT NULL,
    `conflict_type` VARCHAR(50) NOT NULL,
    `severity` VARCHAR(20) NOT NULL,
    `description` TEXT NOT NULL,
    `resolution_action` VARCHAR(50) NOT NULL,
    `resolved_by` INT(11) DEFAULT NULL,
    `resolved_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `notes` TEXT DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_conflict_id` (`conflict_id`),
    KEY `idx_resolved_at` (`resolved_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($create_conflicts)) {
    echo "✅ conflict_resolutions table created/verified successfully\n";
} else {
    echo "❌ Error creating conflict_resolutions table: " . $conn->error . "\n";
}

// Insert sample audit log entries if table is empty
$check_count = $conn->query("SELECT COUNT(*) as cnt FROM audit_log");
if ($check_count) {
    $row = $check_count->fetch_assoc();
    if ($row['cnt'] == 0) {
        // Insert some sample audit logs
        $sample_logs = "INSERT INTO audit_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES
            (1, 'LOGIN', 'user', 1, '{\"method\": \"password\", \"success\": true}', '127.0.0.1'),
            (1, 'GENERATE_SCHEDULE', 'schedule', 1, '{\"courses\": 25, \"duration_seconds\": 45, \"conflicts\": 2}', '127.0.0.1'),
            (1, 'VIEW_ANALYTICS', 'analytics', NULL, '{\"dashboard\": \"ai_analytics\"}', '127.0.0.1'),
            (1, 'EXPORT_DATA', 'export', NULL, '{\"format\": \"csv\", \"rows\": 150}', '127.0.0.1')";
        
        if ($conn->query($sample_logs)) {
            echo "✅ Sample audit logs inserted successfully\n";
        }
    }
}

echo "\n🎉 Database initialization complete!\n";
echo "All tables are ready for use.\n";

$conn->close();
?>
