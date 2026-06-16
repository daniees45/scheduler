<?php
/**
 * Get Conflicts API
 * Returns detected schedule conflicts using the latest generated schedule snapshot.
 */

session_start();
header('Content-Type: application/json');
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

function normalize_key(string $value): string
{
    return strtolower(trim($value));
}

function find_header_index(array $headerMap, array $candidates): int
{
    foreach ($candidates as $candidate) {
        $key = normalize_key($candidate);
        if (isset($headerMap[$key])) {
            return (int)$headerMap[$key];
        }
    }
    return -1;
}

function parse_enrollment(string $value): int
{
    if ($value === '') {
        return 0;
    }
    if (preg_match('/\d+/', $value, $m)) {
        return (int)$m[0];
    }
    return 0;
}

function load_latest_schedule_rows(mysqli $conn): array
{
    $rows = [];
    $res = $conn->query("SELECT schedule_data FROM generated_schedules ORDER BY created_at DESC LIMIT 1");
    if (!$res || !($dbRow = $res->fetch_assoc())) {
        return $rows;
    }

    $raw = $dbRow['schedule_data'] ?? '';
    if ($raw === '') {
        return $rows;
    }

    $decoded = json_decode($raw, true);
    if (is_array($decoded) && !empty($decoded)) {
        if (isset($decoded[0]) && is_array($decoded[0])) {
            return $decoded;
        }
        // Handle list of associative objects
        if (isset($decoded[0]) && is_array($decoded[0])) {
            $header = array_keys($decoded[0]);
            $rows[] = $header;
            foreach ($decoded as $item) {
                $line = [];
                foreach ($header as $key) {
                    $line[] = $item[$key] ?? '';
                }
                $rows[] = $line;
            }
            return $rows;
        }
    }

    // Legacy CSV-text fallback
    $lines = preg_split('/\r\n|\r|\n/', (string)$raw);
    foreach ($lines as $line) {
        if (trim($line) === '') {
            continue;
        }
        $rows[] = str_getcsv($line);
    }
    return $rows;
}

function get_room_capacity_map(mysqli $conn): array
{
    $map = [];
    $res = $conn->query("SELECT room_name, capacity FROM rooms");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $map[normalize_key((string)($row['room_name'] ?? ''))] = (int)($row['capacity'] ?? 0);
        }
    }
    return $map;
}

function get_resolution_status_map(mysqli $conn): array
{
    $statusMap = [];
    $res = $conn->query(
        "SELECT cr.conflict_id, cr.status
         FROM conflict_resolutions cr
         INNER JOIN (
            SELECT conflict_id, MAX(id) AS max_id
            FROM conflict_resolutions
            GROUP BY conflict_id
         ) latest ON latest.max_id = cr.id"
    );

    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $statusMap[(string)$row['conflict_id']] = (string)$row['status'];
        }
    }

    return $statusMap;
}

function detect_conflicts_from_rows(array $rows, array $roomCapacities, array $statusMap): array
{
    if (count($rows) < 2) {
        return [];
    }

    $header = array_map(static function ($h) {
        return normalize_key((string)$h);
    }, $rows[0]);

    $headerMap = [];
    foreach ($header as $idx => $key) {
        $headerMap[$key] = $idx;
    }

    $idxCode = find_header_index($headerMap, ['course code', 'course_code']);
    $idxTitle = find_header_index($headerMap, ['course title', 'course_title']);
    $idxLecturer = find_header_index($headerMap, ['lecturer name', 'lecturer', 'invigilator', 'lecturer_name']);
    $idxRoom = find_header_index($headerMap, ['room name', 'room', 'room_name']);
    $idxDay = find_header_index($headerMap, ['day']);
    $idxTime = find_header_index($headerMap, ['time', 'time slot', 'time_slot']);
    $idxEnrollment = find_header_index($headerMap, ['enrollment', 'no of students', 'no_of_students', 'students']);

    $normalized = [];
    for ($i = 1; $i < count($rows); $i++) {
        $r = $rows[$i];
        if (!is_array($r)) {
            continue;
        }

        $code = $idxCode >= 0 ? trim((string)($r[$idxCode] ?? '')) : '';
        $title = $idxTitle >= 0 ? trim((string)($r[$idxTitle] ?? '')) : '';
        $lecturer = $idxLecturer >= 0 ? trim((string)($r[$idxLecturer] ?? '')) : '';
        $room = $idxRoom >= 0 ? trim((string)($r[$idxRoom] ?? '')) : '';
        $day = $idxDay >= 0 ? trim((string)($r[$idxDay] ?? '')) : '';
        $time = $idxTime >= 0 ? trim((string)($r[$idxTime] ?? '')) : '';
        $enrollment = $idxEnrollment >= 0 ? parse_enrollment((string)($r[$idxEnrollment] ?? '')) : 0;

        if ($code === '' && $title === '') {
            continue;
        }

        $name = trim($code . ($title !== '' ? ' - ' . $title : ''));
        $normalized[] = [
            'name' => $name,
            'lecturer' => $lecturer,
            'room' => $room,
            'day' => $day,
            'time' => $time,
            'enrollment' => $enrollment,
        ];
    }

    $conflicts = [];
    $now = date('Y-m-d H:i:s');

    // Lecturer clashes
    $lecturerSlots = [];
    foreach ($normalized as $item) {
        if ($item['lecturer'] === '' || $item['day'] === '' || $item['time'] === '') {
            continue;
        }
        $key = normalize_key($item['lecturer']) . '|' . normalize_key($item['day']) . '|' . normalize_key($item['time']);
        if (!isset($lecturerSlots[$key])) {
            $lecturerSlots[$key] = [];
        }
        $lecturerSlots[$key][] = $item;
    }
    foreach ($lecturerSlots as $key => $items) {
        if (count($items) < 2) {
            continue;
        }

        $courseNames = array_map(static function ($i) {
            return $i['name'];
        }, $items);
        $conflictId = sha1('lecturer|' . $key . '|' . implode('|', $courseNames));
        $conflicts[] = [
            'id' => $conflictId,
            'type' => 'Lecturer Double-Booking',
            'priority' => 'critical',
            'description' => $items[0]['lecturer'] . ' is assigned to multiple classes in the same slot.',
            'involved_parties' => $items[0]['lecturer'],
            'resource' => implode(' + ', $courseNames),
            'time_slot' => $items[0]['day'] . ' at ' . $items[0]['time'],
            'ai_recommendation' => 'Move one of the courses to a free slot for this lecturer.',
            'status' => $statusMap[$conflictId] ?? 'pending',
            'detected_at' => $now,
        ];
    }

    // Room clashes
    $roomSlots = [];
    foreach ($normalized as $item) {
        if ($item['room'] === '' || $item['day'] === '' || $item['time'] === '') {
            continue;
        }
        $key = normalize_key($item['room']) . '|' . normalize_key($item['day']) . '|' . normalize_key($item['time']);
        if (!isset($roomSlots[$key])) {
            $roomSlots[$key] = [];
        }
        $roomSlots[$key][] = $item;
    }
    foreach ($roomSlots as $key => $items) {
        if (count($items) < 2) {
            continue;
        }

        $courseNames = array_map(static function ($i) {
            return $i['name'];
        }, $items);
        $conflictId = sha1('room|' . $key . '|' . implode('|', $courseNames));
        $conflicts[] = [
            'id' => $conflictId,
            'type' => 'Room Conflict',
            'priority' => 'critical',
            'description' => $items[0]['room'] . ' is assigned to multiple classes in the same slot.',
            'involved_parties' => $items[0]['room'],
            'resource' => implode(' + ', $courseNames),
            'time_slot' => $items[0]['day'] . ' at ' . $items[0]['time'],
            'ai_recommendation' => 'Reassign one class to another available room with enough capacity.',
            'status' => $statusMap[$conflictId] ?? 'pending',
            'detected_at' => $now,
        ];
    }

    // Capacity violations
    foreach ($normalized as $item) {
        if ($item['room'] === '' || $item['enrollment'] <= 0) {
            continue;
        }

        $capacity = $roomCapacities[normalize_key($item['room'])] ?? 0;
        if ($capacity > 0 && $item['enrollment'] > $capacity) {
            $overflow = $item['enrollment'] - $capacity;
            $conflictId = sha1('capacity|' . normalize_key($item['name']) . '|' . normalize_key($item['room']));
            $conflicts[] = [
                'id' => $conflictId,
                'type' => 'Capacity Exceeded',
                'priority' => 'high',
                'description' => 'Enrollment (' . $item['enrollment'] . ') exceeds room capacity (' . $capacity . ').',
                'involved_parties' => $item['name'],
                'resource' => $item['room'],
                'time_slot' => ($item['day'] && $item['time']) ? ($item['day'] . ' at ' . $item['time']) : 'N/A',
                'ai_recommendation' => 'Move to a larger room or split attendance. Need room for +' . $overflow . ' students.',
                'status' => $statusMap[$conflictId] ?? 'pending',
                'detected_at' => $now,
            ];
        }
    }

    return $conflicts;
}

try {
    $rows = load_latest_schedule_rows($conn);
    if (empty($rows)) {
        echo json_encode([
            'status' => 'success',
            'timestamp' => date('Y-m-d H:i:s'),
            'conflicts_count' => 0,
            'conflicts' => [],
            'message' => 'No schedule data available for conflict analysis.'
        ]);
        exit;
    }

    $roomCaps = get_room_capacity_map($conn);
    $statusMap = get_resolution_status_map($conn);
    $conflicts = detect_conflicts_from_rows($rows, $roomCaps, $statusMap);

    echo json_encode([
        'status' => 'success',
        'timestamp' => date('Y-m-d H:i:s'),
        'conflicts_count' => count($conflicts),
        'conflicts' => $conflicts
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Failed to fetch conflicts: ' . $e->getMessage()
    ]);
}
?>
