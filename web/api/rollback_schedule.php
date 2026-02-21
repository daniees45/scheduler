<?php
/**
 * Schedule Rollback System
 * 
 * Allows recovery from:
 * - Bad schedule generation
 * - Accidental database updates
 * - System failures
 * 
 * Maintains backup of:
 * - Previous schedule state
 * - Before/after version comparison
 */

header('Content-Type: application/json');
require_once 'db.php';
require_once 'error_handler.php';

// Ensure admin only
if (($_SESSION['user_role'] ?? null) !== 'super_admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied - admin only']);
    exit;
}

/**
 * Create backup of current schedule state
 */
function create_schedule_backup($backup_name = '', $description = '') {
    global $conn;
    
    $name = $backup_name ?: 'backup_' . date('Y-m-d_H-i-s');
    $desc = $description ?: 'Automatic backup before generation';
    $timestamp = date('Y-m-d H:i:s');
    
    // Backup current sections table
    $sql = "INSERT INTO schedule_backup (name, description, created_at, data_json)
            SELECT '$name', '$desc', NOW(), 
                   JSON_ARRAYAGG(JSON_OBJECT(
                       'course_id', course_id,
                       'lecturer_id', lecturer_id,
                       'room_id', room_id,
                       'assigned_day', assigned_day,
                       'assigned_time', assigned_time
                   ))
            FROM sections";
    
    if ($conn->query($sql)) {
        $backup_id = $conn->insert_id;
        log_error('INFO', "Schedule backup created: $name (ID: $backup_id)", __FILE__, __LINE__);
        return ['success' => true, 'backup_id' => $backup_id, 'name' => $name];
    } else {
        log_error('ERROR', 'Failed to create schedule backup', __FILE__, __LINE__, $conn->error);
        return ['success' => false, 'error' => 'Backup creation failed'];
    }
}

/**
 * Restore schedule from backup
 */
function restore_schedule_from_backup($backup_id) {
    global $conn;
    
    // Verify backup exists
    $check = $conn->query("SELECT id, name, data_json FROM schedule_backup WHERE id = $backup_id LIMIT 1");
    if (!$check || $check->num_rows === 0) {
        log_error('ERROR', "Backup not found: ID $backup_id", __FILE__, __LINE__);
        return ['success' => false, 'error' => 'Backup not found'];
    }
    
    $backup = $check->fetch_assoc();
    $backup_data = json_decode($backup['data_json'], true);
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Clear current sections
        $conn->query("TRUNCATE TABLE sections");
        
        // Restore from backup
        if (is_array($backup_data)) {
            foreach ($backup_data as $section) {
                $course_id = (int)$section['course_id'];
                $lecturer_id = $section['lecturer_id'] ? (int)$section['lecturer_id'] : 'NULL';
                $room_id = $section['room_id'] ? (int)$section['room_id'] : 'NULL';
                $day = $conn->real_escape_string($section['assigned_day'] ?? '');
                $time = $conn->real_escape_string($section['assigned_time'] ?? '');
                
                $sql = "INSERT INTO sections (course_id, lecturer_id, room_id, assigned_day, assigned_time) 
                        VALUES ($course_id, $lecturer_id, $room_id, '$day', '$time')";
                
                if (!$conn->query($sql)) {
                    throw new Exception("Failed to restore section: " . $conn->error);
                }
            }
        }
        
        // Commit transaction
        $conn->commit();
        
        log_error('INFO', "Schedule restored from backup: {$backup['name']} (ID: $backup_id)", __FILE__, __LINE__);
        
        return [
            'success' => true,
            'message' => 'Schedule restored successfully',
            'backup_name' => $backup['name'],
            'sections_restored' => count($backup_data ?? [])
        ];
        
    } catch (Exception $e) {
        $conn->rollback();
        log_error('ERROR', 'Schedule restore failed', __FILE__, __LINE__, $e->getMessage());
        return ['success' => false, 'error' => 'Restore failed: ' . $e->getMessage()];
    }
}

/**
 * Get backup history
 */
function get_backup_history($limit = 20) {
    global $conn;
    
    $sql = "SELECT id, name, description, created_at, 
                   JSON_LENGTH(data_json) as sections_count
            FROM schedule_backup 
            ORDER BY created_at DESC 
            LIMIT $limit";
    
    $result = $conn->query($sql);
    if (!$result) {
        return ['success' => false, 'error' => $conn->error];
    }
    
    $backups = [];
    while ($row = $result->fetch_assoc()) {
        $row['created_at_human'] = date('M d, Y H:i', strtotime($row['created_at']));
        $backups[] = $row;
    }
    
    return ['success' => true, 'backups' => $backups, 'count' => count($backups)];
}

/**
 * Delete old backups to free space
 */
function cleanup_old_backups($days_to_keep = 30) {
    global $conn;
    
    $cutoff = date('Y-m-d H:i:s', strtotime("-$days_to_keep days"));
    
    $result = $conn->query("DELETE FROM schedule_backup WHERE created_at < '$cutoff'");
    
    if ($result) {
        $deleted = $conn->affected_rows;
        log_error('INFO', "Deleted $deleted old backups", __FILE__, __LINE__);
        return ['success' => true, 'deleted' => $deleted];
    } else {
        log_error('ERROR', 'Backup cleanup failed', __FILE__, __LINE__, $conn->error);
        return ['success' => false, 'error' => 'Cleanup failed'];
    }
}

/**
 * Compare two backups/schedules
 */
function compare_schedules($backup_id_1, $backup_id_2 = null) {
    global $conn;
    
    // Get first backup
    $check1 = $conn->query("SELECT id, name, data_json FROM schedule_backup WHERE id = $backup_id_1 LIMIT 1");
    if (!$check1 || $check1->num_rows === 0) {
        return ['success' => false, 'error' => 'First backup not found'];
    }
    $backup1 = $check1->fetch_assoc();
    $data1 = json_decode($backup1['data_json'], true) ?? [];
    
    // Get second backup or current schedule
    $data2 = [];
    $backup2_name = 'Current Schedule';
    
    if ($backup_id_2) {
        $check2 = $conn->query("SELECT id, name, data_json FROM schedule_backup WHERE id = $backup_id_2 LIMIT 1");
        if (!$check2 || $check2->num_rows === 0) {
            return ['success' => false, 'error' => 'Second backup not found'];
        }
        $backup2 = $check2->fetch_assoc();
        $data2 = json_decode($backup2['data_json'], true) ?? [];
        $backup2_name = $backup2['name'];
    } else {
        // Get current schedule from database
        $result = $conn->query("SELECT course_id, lecturer_id, room_id, assigned_day, assigned_time FROM sections");
        while ($row = $result->fetch_assoc()) {
            $data2[] = $row;
        }
    }
    
    // Compare
    $changes = [
        'added' => [],
        'removed' => [],
        'modified' => [],
        'unchanged' => 0
    ];
    
    // Create lookup maps
    $map1 = [];
    foreach ($data1 as $section) {
        $key = $section['course_id'] . '_' . ($section['lecturer_id'] ?? '0');
        $map1[$key] = $section;
    }
    
    $map2 = [];
    foreach ($data2 as $section) {
        $key = $section['course_id'] . '_' . ($section['lecturer_id'] ?? '0');
        $map2[$key] = $section;
    }
    
    // Find changes
    foreach ($map1 as $key => $section1) {
        if (!isset($map2[$key])) {
            $changes['removed'][] = $section1;
        } elseif ($map2[$key] !== $section1) {
            $changes['modified'][] = [
                'course_id' => $section1['course_id'],
                'before' => $section1,
                'after' => $map2[$key]
            ];
        } else {
            $changes['unchanged']++;
        }
    }
    
    foreach ($map2 as $key => $section2) {
        if (!isset($map1[$key])) {
            $changes['added'][] = $section2;
        }
    }
    
    return [
        'success' => true,
        'backup1' => $backup1['name'],
        'backup2' => $backup2_name,
        'changes' => $changes,
        'summary' => [
            'added' => count($changes['added']),
            'removed' => count($changes['removed']),
            'modified' => count($changes['modified']),
            'unchanged' => $changes['unchanged']
        ]
    ];
}

/**
 * Create schedule_backup table if needed
 */
function ensure_backup_table() {
    global $conn;
    
    $result = $conn->query("SHOW TABLES LIKE 'schedule_backup'");
    if ($result && $result->num_rows > 0) {
        return true;
    }
    
    $sql = "CREATE TABLE IF NOT EXISTS schedule_backup (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        description TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        data_json LONGTEXT,
        backup_size INT COMMENT 'Size in bytes',
        INDEX idx_created_at (created_at),
        INDEX idx_name (name)
    )";
    
    return $conn->query($sql);
}

/**
 * API Endpoints
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    ensure_backup_table();
    
    if ($action === 'create_backup') {
        $name = $_POST['name'] ?? '';
        $description = $_POST['description'] ?? '';
        $result = create_schedule_backup($name, $description);
        echo json_encode($result);
        
    } elseif ($action === 'restore') {
        $backup_id = (int)($_POST['backup_id'] ?? 0);
        if ($backup_id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid backup ID']);
            exit;
        }
        $result = restore_schedule_from_backup($backup_id);
        echo json_encode($result);
        
    } elseif ($action === 'get_history') {
        $limit = (int)($_POST['limit'] ?? 20);
        $result = get_backup_history($limit);
        echo json_encode($result);
        
    } elseif ($action === 'cleanup') {
        $days = (int)($_POST['days_to_keep'] ?? 30);
        $result = cleanup_old_backups($days);
        echo json_encode($result);
        
    } elseif ($action === 'compare') {
        $backup_id_1 = (int)($_POST['backup_id_1'] ?? 0);
        $backup_id_2 = (int)($_POST['backup_id_2'] ?? 0);
        if ($backup_id_1 <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid backup ID']);
            exit;
        }
        $result = compare_schedules($backup_id_1, $backup_id_2 > 0 ? $backup_id_2 : null);
        echo json_encode($result);
        
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
?>
