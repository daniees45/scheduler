<?php
// web/api/update_schedule_row.php
header('Content-Type: application/json');
require_once 'db.php';
require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/../../lib/B2Storage.php';

require_http_methods('POST');
require_admin_user();

$data = json_decode(file_get_contents('php://input'), true);
$course_code = $data['course_code'] ?? null;
$action = $data['action'] ?? null;
$new_value = $data['new_value'] ?? null;

if (!$course_code || !$new_value) {
    echo json_encode(['status' => 'error', 'message' => 'Missing required parameters.']);
    exit;
}

// 1. Get latest schedule
$res = $conn->query("SELECT id, schedule_data FROM generated_schedules ORDER BY created_at DESC LIMIT 1");
if (!$res || $res->num_rows === 0) {
    echo json_encode(['status' => 'error', 'message' => 'No active schedule found to update.']);
    exit;
}
$row = $res->fetch_assoc();
$schedule_id = $row['id'];
$schedule_items = json_decode($row['schedule_data'], true);

// 2. Apply update
$updated = false;
$day_map = [
    'Monday' => 'M', 'Tuesday' => 'T', 'Wednesday' => 'W', 'Thursday' => 'TH', 'Friday' => 'F'
];

foreach ($schedule_items as &$item) {
    // Check if course matches
    // Python usually uses Course Code or Display Name
    $item_code = is_array($item) ? ($item[0] ?? '') : ($item['course_code'] ?? '');

    if (strpos($item_code, $course_code) !== false || $item_code == $course_code) {
        if ($action == 'MOVE_TO_SLOT' || strpos($new_value, ' at ') !== false) {
            $parts = explode(' at ', $new_value);
            if (count($parts) == 2) {
                $day_name = trim($parts[0]);
                $slot = trim($parts[1]);
                $day_code = $day_map[$day_name] ?? $day_name;

                if (is_array($item)) {
                    $item[5] = $day_code;
                    $item[6] = $slot;
                    if (isset($item[10]))
                        $item[10] = $slot; // Sync both slot columns if present
                }
                else {
                    $item['day'] = $day_code;
                    $item['time_slot'] = $slot;
                }
                $updated = true;
            }
        }
    }
}

if ($updated) {
    $new_json = json_encode($schedule_items);
    $stmt = $conn->prepare("UPDATE generated_schedules SET schedule_data = ? WHERE id = ?");
    $stmt->bind_param("si", $new_json, $schedule_id);

    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'Schedule updated successfully locally.']);
    // Note: In a production system, we'd also push back to B2/CSV here.
    }
    else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to update database.']);
    }
}
else {
    echo json_encode(['status' => 'error', 'message' => 'Course entry not found in active schedule.']);
}