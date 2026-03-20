<?php
/**
 * Room Utilization Analysis API
 * 
 * Analyzes:
 * - Room occupancy rates
 * - Peak hours by room
 * - Room capacity efficiency
 * - Underutilized rooms
 */

header('Content-Type: application/json');
require_once 'db.php';
require_once __DIR__ . '/../../lib/B2Storage.php';
require_once __DIR__ . '/../includes/unified_schedule_service.php';

function room_utilization_schedule_rows_from_generated(mysqli $conn, int $schedule_id = 0, string $saved_at = ''): array {
    $sql = "SELECT id, schedule_name, created_at, schedule_data
            FROM generated_schedules
            WHERE (schedule_name NOT LIKE 'exam_%' OR schedule_name IS NULL)";

    $types = '';
    $params = [];

    if ($schedule_id > 0) {
        $sql .= " AND id = ?";
        $types .= 'i';
        $params[] = $schedule_id;
    } elseif ($saved_at !== '') {
        $timestamp = strtotime($saved_at);
        if ($timestamp !== false) {
            $sql .= " AND created_at <= ?";
            $types .= 's';
            $params[] = date('Y-m-d H:i:s', $timestamp);
        }
    }

    $sql .= " ORDER BY created_at DESC, id DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return ['success' => false, 'error' => 'Could not prepare saved schedule lookup'];
    }

    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $res = $stmt->get_result();
    if (!$res || !($row = $res->fetch_assoc())) {
        return ['success' => false, 'error' => 'No saved schedule found for the selected snapshot'];
    }

    $rows = unified_schedule_extract_rows((string)($row['schedule_data'] ?? ''));
    if (empty($rows)) {
        return ['success' => false, 'error' => 'Selected saved schedule has no usable schedule rows'];
    }

    return [
        'success' => true,
        'rows' => $rows,
        'meta' => [
            'id' => (int)($row['id'] ?? 0),
            'schedule_name' => (string)($row['schedule_name'] ?? ''),
            'created_at' => (string)($row['created_at'] ?? '')
        ]
    ];
}

function room_utilization_rows_from_csv_content(string $schedule_csv_content): array {
    $rows = [];
    $lines = preg_split('/\r\n|\r|\n/', trim($schedule_csv_content));
    if (!$lines || empty($lines)) {
        return $rows;
    }

    $headers = array_map(static function ($header) {
        return strtolower(trim((string)$header, "\" "));
    }, str_getcsv(array_shift($lines)));

    foreach ($lines as $line) {
        if (trim($line) === '') {
            continue;
        }
        $row = str_getcsv($line);
        $assoc = [];
        foreach ($headers as $index => $header) {
            $assoc[$header] = $row[$index] ?? '';
        }
        $rows[] = $assoc;
    }

    return $rows;
}

function room_utilization_normalize_row(array $row): array {
    $normalized = [];
    foreach ($row as $key => $value) {
        $k = strtolower(trim((string)$key));
        $k = preg_replace('/\s+/', '_', $k);
        $normalized[$k] = $value;
    }
    return $normalized;
}

function room_utilization_row_value(array $row, array $keys, $default = '') {
    foreach ($keys as $key) {
        if (array_key_exists($key, $row) && trim((string)$row[$key]) !== '') {
            return $row[$key];
        }
    }
    return $default;
}

function room_utilization_normalize_room_name(string $room): string {
    $room = trim($room);
    $room = preg_replace('/\s+/', ' ', $room);
    return $room;
}

function room_utilization_parse_enrollment($raw): int {
    $str = trim((string)$raw);
    if ($str === '') {
        return 30;
    }
    $numeric = preg_replace('/[^0-9]/', '', $str);
    if ($numeric === '') {
        return 30;
    }
    $value = (int)$numeric;
    return $value > 0 ? $value : 30;
}

/**
 * Analyze room utilization
 */
function analyze_room_utilization($schedule_csv_content = null, array $schedule_rows = [], array $source_meta = []) {
    global $conn;
    
    if (empty($schedule_rows) && !$schedule_csv_content) {
        $b2 = new B2Storage();
        $result = $b2->download('csv/final/final_web_schedule.csv');
        if (!$result['success']) {
            return ['success' => false, 'error' => 'Schedule CSV not found in B2'];
        }
        $schedule_csv_content = $result['content'];
    }

    if (empty($schedule_rows) && $schedule_csv_content) {
        $schedule_rows = room_utilization_rows_from_csv_content((string)$schedule_csv_content);
    }
    
    $analysis = [
        'timestamp' => date('Y-m-d H:i:s'),
        'rooms_analyzed' => 0,
        'overall_utilization' => 0,
        'room_details' => [],
        'peak_hours' => [],
        'utilization_by_day' => [],
        'bottlenecks' => [],
        'data_quality' => [
            'rows_received' => count($schedule_rows),
            'rows_processed' => 0,
            'rows_skipped_no_room' => 0,
            'rows_skipped_invalid' => 0,
            'rows_with_unknown_capacity' => 0,
            'rooms_with_unknown_capacity' => 0,
        ],
        'source' => $source_meta
    ];
    
    // Get room capacities
    $room_capacities = [];
    $result = $conn->query("SELECT room_name, capacity FROM rooms");
    while ($room = $result->fetch_assoc()) {
        $name = room_utilization_normalize_room_name((string)($room['room_name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $room_capacities[strtolower($name)] = (int)($room['capacity'] ?? 0);
    }
    
    $room_usage = [];
    $room_display_names = [];
    $unknown_capacity_rooms = [];
    $peak_by_hour = [];
    $peak_by_day = [];

    foreach ($schedule_rows as $course) {
        if (!is_array($course)) {
            $analysis['data_quality']['rows_skipped_invalid']++;
            continue;
        }

        $course = room_utilization_normalize_row($course);

        $room = room_utilization_normalize_room_name((string)room_utilization_row_value($course, ['room_name', 'room'], 'Unassigned'));
        
        if ($room === '' || strtolower($room) === 'unassigned' || strtolower($room) === 'tba') {
            $analysis['data_quality']['rows_skipped_no_room']++;
            continue;
        }

        $room_key = strtolower($room);
        if (!isset($room_display_names[$room_key])) {
            $room_display_names[$room_key] = $room;
        }
        
        $day = trim((string)room_utilization_row_value($course, ['day'], ''));
        $time = trim((string)room_utilization_row_value($course, ['start_time', 'time', 'assigned_time'], ''));
        $enrollment = room_utilization_parse_enrollment(room_utilization_row_value($course, ['enrollment', 'no_of_students', 'students'], 30));
        
        if (!isset($room_usage[$room_key])) {
            $capacity = (int)($room_capacities[$room_key] ?? 0);
            if ($capacity <= 0) {
                $unknown_capacity_rooms[$room_key] = true;
            }

            $room_usage[$room_key] = [
                'courses' => 0,
                'total_enrollment' => 0,
                'capacity' => $capacity,
                'hours_used' => [],
                'days_used' => [],
                'peak_enrollment' => 0,
                'avg_enrollment' => 0,
                'peak_hour' => '',
                'peak_day' => ''
            ];
        }
        
        if (($room_usage[$room_key]['capacity'] ?? 0) <= 0) {
            $analysis['data_quality']['rows_with_unknown_capacity']++;
        }

        $room_usage[$room_key]['courses']++;
        $room_usage[$room_key]['total_enrollment'] += $enrollment;
        $room_usage[$room_key]['peak_enrollment'] = max($room_usage[$room_key]['peak_enrollment'], $enrollment);
        $analysis['data_quality']['rows_processed']++;
        
        if (!empty($time)) {
            $room_usage[$room_key]['hours_used'][$time] = true;
            if (!isset($peak_by_hour[$time])) {
                $peak_by_hour[$time] = 0;
            }
            $peak_by_hour[$time]++;
        }
        
        if (!empty($day)) {
            $room_usage[$room_key]['days_used'][$day] = true;
            if (!isset($peak_by_day[$day])) {
                $peak_by_day[$day] = 0;
            }
            $peak_by_day[$day]++;
        }
    }
    
    // Calculate statistics
    $total_utilization = 0;
    $room_count = 0;
    
    foreach ($room_usage as $room_key => &$data) {
        $data['hours_used'] = count($data['hours_used']);
        $data['days_used'] = count($data['days_used']);
        $data['avg_enrollment'] = $data['courses'] > 0 ? 
            round($data['total_enrollment'] / $data['courses'], 1) : 0;
        
        // Utilization = (avg enrollment / capacity) * 100
        $data['utilization_percent'] = $data['capacity'] > 0 ? 
            round(($data['avg_enrollment'] / $data['capacity']) * 100, 2) : 0;
        
        // Efficiency = (actual bookings / potential slots)
        // Assume room available 8 hours/day, 5 days/week = 40 slots
        $potential_slots = $data['days_used'] * $data['hours_used'] > 0 ? 
            $data['days_used'] * 8 : 40;
        $data['efficiency_percent'] = round(($data['courses'] / $potential_slots) * 100, 2);
        
        // Status
        if ($data['utilization_percent'] > 85) {
            $data['status'] = 'High Demand';
        } elseif ($data['utilization_percent'] > 60) {
            $data['status'] = 'Good Utilization';
        } elseif ($data['utilization_percent'] > 30) {
            $data['status'] = 'Moderate Usage';
        } else {
            $data['status'] = 'Underutilized';
        }
        
        $total_utilization += $data['utilization_percent'];
        $room_count++;
    }
    unset($data);
    
    // Sort by utilization descending
    uasort($room_usage, function($a, $b) {
        return $b['utilization_percent'] <=> $a['utilization_percent'];
    });
    
    $analysis['rooms_analyzed'] = $room_count;
    $analysis['overall_utilization'] = $room_count > 0 ? 
        round($total_utilization / $room_count, 2) : 0;

    $named_room_usage = [];
    foreach ($room_usage as $room_key => $data) {
        $display = $room_display_names[$room_key] ?? $room_key;
        $named_room_usage[$display] = $data;
    }
    $analysis['room_details'] = $room_usage;
    $analysis['room_details'] = $named_room_usage;
    $analysis['data_quality']['rooms_with_unknown_capacity'] = count($unknown_capacity_rooms);
    
    // Peak hours
    arsort($peak_by_hour);
    $analysis['peak_hours'] = array_slice($peak_by_hour, 0, 5);
    
    // Peak days
    arsort($peak_by_day);
    $analysis['utilization_by_day'] = $peak_by_day;
    
    // Identify bottlenecks
    foreach ($room_usage as $room => $data) {
        if ($data['utilization_percent'] > 90) {
            $analysis['bottlenecks'][] = [
                'type' => 'High Demand Room',
                'room' => $room,
                'utilization' => $data['utilization_percent'] . '%',
                'recommendation' => "Consider adding capacity or spreading load to other rooms"
            ];
        } elseif ($data['utilization_percent'] < 20 && $data['capacity'] > 50) {
            $analysis['bottlenecks'][] = [
                'type' => 'Underutilized Large Room',
                'room' => $room,
                'capacity' => $data['capacity'],
                'utilization' => $data['utilization_percent'] . '%',
                'recommendation' => "Use for larger classes or special events"
            ];
        }
    }
    
    return ['success' => true, 'analysis' => $analysis];
}

/**
 * API Endpoint
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'analyze') {
        $schedule_csv = $_POST['schedule_csv'] ?? null;
        $schedule_id = (int)($_POST['schedule_id'] ?? 0);
        $saved_at = trim((string)($_POST['saved_at'] ?? ''));
        $schedule_rows = [];
        $source_meta = [];
        
        if ($schedule_csv) {
            $safe_path = realpath('../../' . $schedule_csv);
            if (!$safe_path || strpos($safe_path, realpath('../../')) !== 0) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Invalid file path']);
                exit;
            }
        } elseif ($schedule_id > 0 || $saved_at !== '') {
            $selected = room_utilization_schedule_rows_from_generated($conn, $schedule_id, $saved_at);
            if (!$selected['success']) {
                http_response_code(404);
                echo json_encode($selected);
                exit;
            }
            $schedule_rows = $selected['rows'] ?? [];
            $source_meta = $selected['meta'] ?? [];
        }
        
        $result = analyze_room_utilization($safe_path ?? null, $schedule_rows, $source_meta);
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
