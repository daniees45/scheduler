<?php
/**
 * Modular Settings API
 * Handles saving Profile, Security, Notifications, AI, and Admin settings.
 */
session_start();
header('Content-Type: application/json');
require_once 'db.php';
require_once __DIR__ . '/ai_learning.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

function has_column(mysqli $conn, string $table, string $column): bool {
    static $cache = [];
    $key = $table . '.' . $column;
    if (isset($cache[$key])) return $cache[$key];

    $tableEsc = $conn->real_escape_string($table);
    $colEsc = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `{$tableEsc}` LIKE '{$colEsc}'");
    $cache[$key] = ($res && $res->num_rows > 0);
    return $cache[$key];
}

function normalize_hex_color($hex, $fallback = '#2563eb') {
    $hex = trim((string)$hex);
    if (!preg_match('/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $hex)) {
        return $fallback;
    }
    if (strlen($hex) === 4) {
        return '#'
            . $hex[1] . $hex[1]
            . $hex[2] . $hex[2]
            . $hex[3] . $hex[3];
    }
    return strtolower($hex);
}

function adjust_hex_brightness($hex, $steps) {
    $hex = normalize_hex_color($hex);
    $hex = ltrim($hex, '#');
    $steps = max(-255, min(255, (int)$steps));

    $r = max(0, min(255, hexdec(substr($hex, 0, 2)) + $steps));
    $g = max(0, min(255, hexdec(substr($hex, 2, 2)) + $steps));
    $b = max(0, min(255, hexdec(substr($hex, 4, 2)) + $steps));

    return sprintf('#%02x%02x%02x', $r, $g, $b);
}

$isMultipart = isset($_SERVER['CONTENT_TYPE']) && stripos($_SERVER['CONTENT_TYPE'], 'multipart/form-data') !== false;
$data = $isMultipart ? null : json_decode(file_get_contents('php://input'), true);

if ($isMultipart) {
    $type = $_POST['type'] ?? null;
    $payload = $_POST;
} else {
    if (!$data || !isset($data['type'])) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid request data']);
        exit;
    }
    $type = $data['type'];
    $payload = $data['payload'] ?? [];
}

function log_setting_change($conn, $user_id, $type, $old, $new) {
    $stmt = $conn->prepare("INSERT INTO settings_log (user_id, setting_type, old_value, new_value) VALUES (?, ?, ?, ?)");
    $old_str = is_array($old) ? json_encode($old) : (string)$old;
    $new_str = is_array($new) ? json_encode($new) : (string)$new;
    $stmt->bind_param("isss", $user_id, $type, $old_str, $new_str);
    $stmt->execute();
}

try {
    switch ($type) {
        case 'profile':
            // Ensure record exists
            $conn->query("INSERT IGNORE INTO user_settings (user_id) VALUES ($user_id)");

            $phone = $payload['phone'] ?? '';
            $timezone = $payload['timezone'] ?? 'UTC';
            $language = $payload['language'] ?? 'en';
            $week_start = $payload['week_start'] ?? 'Monday';
            $time_format = $payload['time_format'] ?? '12';
            
            $stmt = $conn->prepare("UPDATE user_settings SET phone = ?, timezone = ?, language = ?, week_start = ?, time_format = ? WHERE user_id = ?");
            $stmt->bind_param("sssssi", $phone, $timezone, $language, $week_start, $time_format, $user_id);
            $stmt->execute();
            
            // Also update main users table if full_name or email provided
            if (isset($payload['full_name']) && isset($payload['email'])) {
                $stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ? WHERE id = ?");
                $stmt->bind_param("ssi", $payload['full_name'], $payload['email'], $user_id);
                $stmt->execute();
                $_SESSION['full_name'] = $payload['full_name'];
            }
            break;

        case 'security':
            $password = trim((string)($payload['password'] ?? ''));
            $confirm_password = trim((string)($payload['confirm_password'] ?? ''));
            if ($password === '') {
                throw new Exception("Password is required");
            }
            if ($password !== $confirm_password) {
                throw new Exception("Password and confirm password do not match");
            }
            if (strlen($password) < 8) {
                throw new Exception("Password must be at least 8 characters");
            }
            if (isset($payload['password'])) {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                $stmt->bind_param("si", $hash, $user_id);
                $stmt->execute();
            }
            break;
        case 'notifications':
            $conn->query("INSERT IGNORE INTO notification_settings (user_id) VALUES ($user_id)");
            $email_toggles = isset($payload['email_toggles']) ? intval($payload['email_toggles']) : 1;
            $in_app_toggles = isset($payload['in_app_toggles']) ? intval($payload['in_app_toggles']) : 1;
            $quiet_hours_enabled = isset($payload['quiet_hours_enabled']) ? intval($payload['quiet_hours_enabled']) : 0;
            $quiet_hours_start = $payload['quiet_hours_start'] ?? '22:00';
            $quiet_hours_end = $payload['quiet_hours_end'] ?? '07:00';

            $stmt = $conn->prepare("UPDATE notification_settings SET email_toggles = ?, in_app_toggles = ?, quiet_hours_enabled = ?, quiet_hours_start = ?, quiet_hours_end = ? WHERE user_id = ?");
            $stmt->bind_param("iiissi",
                $email_toggles, $in_app_toggles, $quiet_hours_enabled,
                $quiet_hours_start, $quiet_hours_end, $user_id
            );
            $stmt->execute();
            break;

        case 'privacy':
            $conn->query("INSERT IGNORE INTO user_settings (user_id) VALUES ($user_id)");

            $visibility = strtolower(trim((string)($payload['visibility'] ?? 'faculty')));
            if (!in_array($visibility, ['public', 'faculty', 'private'], true)) {
                $visibility = 'faculty';
            }
            $analytics_opt_in = isset($payload['analytics_opt_in']) ? intval($payload['analytics_opt_in']) : 1;

            $stmt = $conn->prepare("UPDATE user_settings SET profile_visibility = ?, analytics_opt_in = ? WHERE user_id = ?");
            $stmt->bind_param("sii", $visibility, $analytics_opt_in, $user_id);
            $stmt->execute();
            break;

        case 'student':
            if ($role !== 'student') throw new Exception("Unauthorized student access");
            ai_learning_ensure_schema($conn);
            $conn->query("INSERT IGNORE INTO user_settings (user_id) VALUES ($user_id)");
            $productivity_pref = $payload['productivity_pref'] ?? 'Detailed';
            if (!in_array($productivity_pref, ['Detailed', 'Basic', 'Off'], true)) {
                $productivity_pref = 'Detailed';
            }
            $auto_suggest_free = isset($payload['auto_suggest_free']) ? intval($payload['auto_suggest_free']) : 1;
            $stmt = $conn->prepare("UPDATE user_settings SET productivity_pref = ?, auto_suggest_free = ? WHERE user_id = ?");
            $stmt->bind_param("sii", $productivity_pref, $auto_suggest_free, $user_id);
            $stmt->execute();
            ai_learning_sync_user_profile($conn, $user_id);
            break;

        case 'admin':
            if ($role !== 'faculty_admin' && $role !== 'super_admin') throw new Exception("Unauthorized admin access");

            $roleEsc = $conn->real_escape_string($role);
            $conn->query("INSERT IGNORE INTO admin_settings (user_id, role, settings_json) VALUES ($user_id, '$roleEsc', '{}')");

            $current_json = '{}';
            $res = $conn->query("SELECT settings_json FROM admin_settings WHERE user_id = $user_id LIMIT 1");
            if ($res && $row = $res->fetch_assoc()) {
                $current_json = $row['settings_json'] ?: '{}';
            }
            $decoded = json_decode($current_json, true);
            if (!is_array($decoded)) $decoded = [];

            $default_priority = $payload['default_priority'] ?? 'Medium';
            if (!in_array($default_priority, ['High', 'Medium', 'Low'], true)) {
                $default_priority = 'Medium';
            }
            $decoded['default_priority'] = $default_priority;
            $new_json = json_encode($decoded);

            $stmt = $conn->prepare("UPDATE admin_settings SET role = ?, settings_json = ? WHERE user_id = ?");
            $stmt->bind_param("ssi", $role, $new_json, $user_id);
            $stmt->execute();
            break;

        case 'ai':
            $conn->query("INSERT IGNORE INTO ai_settings (user_id) VALUES ($user_id)");
            $stmt = $conn->prepare("UPDATE ai_settings SET suggestion_intensity = ?, preferred_work_start = ?, preferred_work_end = ?, max_daily_workload = ?, accept_learning_toggle = ? WHERE user_id = ?");
            $stmt->bind_param("ssssii", $payload['intensity'], $payload['work_start'], $payload['work_end'], $payload['max_load'], $payload['learning_toggle'], $user_id);
            $stmt->execute();
            ai_learning_sync_user_profile($conn, $user_id);
            break;

        case 'branding':
            if ($role !== 'super_admin' && $role !== 'faculty_admin') throw new Exception("Unauthorized branding access");
            
            $title = $payload['title'] ?? $_POST['title'] ?? '';
            $color = normalize_hex_color($payload['color'] ?? $_POST['color'] ?? '', '#2563eb');
            $bg_color = normalize_hex_color($payload['bg_color'] ?? $_POST['bg_color'] ?? '#0f172a', '#0f172a');
            $secondary_color = normalize_hex_color(
                $payload['secondary_color'] ?? $_POST['secondary_color'] ?? '',
                adjust_hex_brightness($color, 32)
            );
            $color_strength = (int)($payload['color_strength'] ?? $_POST['color_strength'] ?? 100);
            $color_strength = max(50, min(150, $color_strength));
            $text_color = normalize_hex_color($payload['text_color'] ?? $_POST['text_color'] ?? '#f8fafc', '#f8fafc');
            $icon = $payload['icon'] ?? $_POST['icon'] ?? '';
            
            $logo_path = $payload['logo'] ?? $_POST['logo'] ?? null;

            // Handle Logo Upload
            if (isset($_FILES['logo_file']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
                $target_dir = "../assets/img/branding/";
                if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
                
                $file_ext = pathinfo($_FILES['logo_file']['name'], PATHINFO_EXTENSION);
                $allowed_ext = ['png', 'jpg', 'jpeg', 'svg', 'webp', 'gif'];
                if (!in_array(strtolower($file_ext), $allowed_ext, true)) {
                    throw new Exception("Invalid logo file type. Allowed: png, jpg, jpeg, svg, webp, gif");
                }
                $file_name = "logo_" . time() . "." . $file_ext;
                $target_file = $target_dir . $file_name;
                
                if (move_uploaded_file($_FILES['logo_file']['tmp_name'], $target_file)) {
                    $logo_path = "assets/img/branding/" . $file_name;
                }
            }

            if (!has_column($conn, 'branding_settings', 'site_secondary_color')) {
                @$conn->query("ALTER TABLE branding_settings ADD COLUMN site_secondary_color VARCHAR(7) NULL AFTER site_color");
            }
            if (!has_column($conn, 'branding_settings', 'site_color_strength')) {
                @$conn->query("ALTER TABLE branding_settings ADD COLUMN site_color_strength TINYINT UNSIGNED NOT NULL DEFAULT 100 AFTER site_secondary_color");
            }
            if (!has_column($conn, 'branding_settings', 'site_text_color')) {
                @$conn->query("ALTER TABLE branding_settings ADD COLUMN site_text_color VARCHAR(7) NULL AFTER site_color_strength");
            }

            if (has_column($conn, 'branding_settings', 'site_secondary_color') && has_column($conn, 'branding_settings', 'site_color_strength') && has_column($conn, 'branding_settings', 'site_text_color')) {
                $conn->query("INSERT IGNORE INTO branding_settings (id, site_title, site_color, site_secondary_color, site_color_strength, site_text_color, site_bg_color, site_logo, site_icon, updated_by) VALUES (1, 'VVU Scheduler AI', '#2563eb', '#3f83f8', 100, '#f8fafc', '#0f172a', NULL, NULL, $user_id)");
                $stmt = $conn->prepare("UPDATE branding_settings SET site_title = ?, site_color = ?, site_secondary_color = ?, site_color_strength = ?, site_text_color = ?, site_bg_color = ?, site_logo = ?, site_icon = ?, updated_by = ? WHERE id = 1");
                $stmt->bind_param("sssissssi", $title, $color, $secondary_color, $color_strength, $text_color, $bg_color, $logo_path, $icon, $user_id);
            } elseif (has_column($conn, 'branding_settings', 'site_secondary_color')) {
                $conn->query("INSERT IGNORE INTO branding_settings (id, site_title, site_color, site_secondary_color, site_bg_color, site_logo, site_icon, updated_by) VALUES (1, 'VVU Scheduler AI', '#2563eb', '#3f83f8', '#0f172a', NULL, NULL, $user_id)");
                $stmt = $conn->prepare("UPDATE branding_settings SET site_title = ?, site_color = ?, site_secondary_color = ?, site_bg_color = ?, site_logo = ?, site_icon = ?, updated_by = ? WHERE id = 1");
                $stmt->bind_param("ssssssi", $title, $color, $secondary_color, $bg_color, $logo_path, $icon, $user_id);
            } else {
                $conn->query("INSERT IGNORE INTO branding_settings (id, site_title, site_color, site_bg_color, site_logo, site_icon, updated_by) VALUES (1, 'VVU Scheduler AI', '#2563eb', '#0f172a', NULL, NULL, $user_id)");
                $stmt = $conn->prepare("UPDATE branding_settings SET site_title = ?, site_color = ?, site_bg_color = ?, site_logo = ?, site_icon = ?, updated_by = ? WHERE id = 1");
                $stmt->bind_param("sssssi", $title, $color, $bg_color, $logo_path, $icon, $user_id);
            }
            $stmt->execute();
            break;

        case 'system':
            if ($role !== 'faculty_admin' && $role !== 'super_admin') throw new Exception("Unauthorized access");
            
            $semester = $payload['current_semester'] ?? '1';
            $start = $payload['semester_start_date'] ?? date('Y-m-d');
            $end = $payload['semester_end_date'] ?? date('Y-m-d');
            $academic_year = trim((string)($payload['current_academic_year'] ?? ''));
            if (!preg_match('/^\d{4}\/\d{4}$/', $academic_year)) {
                $y = (int)date('Y');
                $academic_year = $y . '/' . ($y + 1);
            }

            $data_to_save = [
                'current_semester' => $semester,
                'current_academic_year' => $academic_year,
                'semester_start_date' => $start,
                'semester_end_date' => $end
            ];

            foreach ($data_to_save as $key => $val) {
                $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->bind_param("sss", $key, $val, $val);
                $stmt->execute();
            }
            break;

        default:
            throw new Exception("Unknown setting type: $type");
    }

    echo json_encode(['status' => 'success', 'message' => 'Settings saved successfully']);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
