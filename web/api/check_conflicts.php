<?php
// as/api/check_conflicts.php
header('Content-Type: application/json');
require_once 'db.php';
require_once __DIR__ . '/../../lib/B2Storage.php';

$b2 = new B2Storage();
$conflicts = [];
$schedule = [];

// Prefer latest schedule from database
$res = $conn->query("SELECT schedule_data FROM generated_schedules ORDER BY created_at DESC LIMIT 1");
if ($res && $row = $res->fetch_assoc()) {
    $json = $row['schedule_data'] ?? '';
    $decoded = json_decode($json, true);
    if (is_array($decoded) && count($decoded) > 1) {
        $headers = array_shift($decoded); // Remove header
        foreach ($decoded as $r) {
            $schedule[] = [
                'code' => $r[0] ?? '',
                'title' => $r[1] ?? '',
                'lecturer' => $r[3] ?? '',
                'room' => $r[4] ?? '',
                'day' => $r[5] ?? '',
                'time' => $r[6] ?? ''
            ];
        }
    }
}

// Fallback to B2 CSV file if DB empty
if (empty($schedule)) {
    $result = $b2->download('csv/final/final_web_schedule.csv');
    if (!$result['success']) {
        echo json_encode(['status' => 'error', 'message' => 'No schedule data found in DB or B2.']);
        exit;
    }

    $lines = explode("\n", $result['content']);
    array_shift($lines); // Skip header
    foreach ($lines as $line) {
        if (trim($line) === '') continue;
        $row = str_getcsv($line);
        // [Code, Title, Credits, Lecturer, Room, Day, Time]
        $schedule[] = [
            'code' => $row[0] ?? '',
            'title' => $row[1] ?? '',
            'lecturer' => $row[3] ?? '',
            'room' => $row[4] ?? '',
            'day' => $row[5] ?? '',
            'time' => $row[6] ?? ''
        ];
    }
}

// Group by Day+Time
$time_slots = [];
foreach ($schedule as $idx => $class) {
    $key = $class['day'] . '|' . $class['time'];
    if (!isset($time_slots[$key])) {
        $time_slots[$key] = [];
    }
    $class['original_index'] = $idx;
    $time_slots[$key][] = $class;
}

// Analyze Conflicts
foreach ($time_slots as $slot => $classes) {
    list($day, $time) = explode('|', $slot);
    
    // Check Room Conflicts
    $rooms = [];
    foreach ($classes as $c) {
        $r = $c['room'];
        if (!$r || $r == 'Unassigned') continue;
        if (isset($rooms[$r])) {
            $conflicts[] = [
                'type' => 'Room Double Booking',
                'severity' => 'High',
                'description' => "Room '$r' is booked for multiple classes on $day at $time.",
                'entities' => [$rooms[$r]['code'], $c['code']],
                'details' => $c
            ];
        } else {
            $rooms[$r] = $c;
        }
    }

    // Check Lecturer Conflicts
    $lecturers = [];
    foreach ($classes as $c) {
        $l = $c['lecturer'];
        if (!$l || $l == 'TBD') continue;
        if (isset($lecturers[$l])) {
            $conflicts[] = [
                'type' => 'Lecturer Double Booking',
                'severity' => 'High',
                'description' => "Lecturer '$l' is assigned to multiple classes on $day at $time.",
                'entities' => [$lecturers[$l]['code'], $c['code']],
                'details' => $c
            ];
        } else {
            $lecturers[$l] = $c;
        }
    }
}

echo json_encode([
    'status' => 'success', 
    'count' => count($conflicts), 
    'conflicts' => $conflicts
]);
