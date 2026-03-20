<?php
require_once 'db.php';
require_once '../includes/unified_schedule_service.php';
header('Content-Type: application/json');

session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
$user_role = $_SESSION['role'] ?? 'student';
$semester = (string)($_SESSION['semester'] ?? '1');

$export_type = $_GET['type'] ?? 'google';
$week_offset = (int)($_GET['week'] ?? 0);
$saved_at = trim((string)($_GET['saved_at'] ?? ''));

if ($export_type !== 'google') {
    http_response_code(400);
    echo json_encode(['error' => 'Unsupported export type']);
    exit;
}

$to_hhmm = static function (string $raw): ?string {
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }

    $ts = strtotime($raw);
    if ($ts !== false) {
        return date('H:i', $ts);
    }

    if (preg_match('/^(\d{1,2}):(\d{2})$/', $raw, $m)) {
        $h = (int)$m[1];
        $i = (int)$m[2];
        if ($h >= 0 && $h <= 23 && $i >= 0 && $i <= 59) {
            return sprintf('%02d:%02d', $h, $i);
        }
    }

    return null;
};

$parse_time_range = static function (string $range) use ($to_hhmm): array {
    $range = trim($range);
    if ($range === '') {
        return [null, null];
    }

    $parts = preg_split('/\s*-\s*/', $range);
    $start = $to_hhmm((string)($parts[0] ?? ''));
    $end = $to_hhmm((string)($parts[1] ?? ''));

    if ($start !== null && $end === null) {
        $start_dt = DateTime::createFromFormat('H:i', $start);
        if ($start_dt) {
            $start_dt->modify('+1 hour');
            $end = $start_dt->format('H:i');
        }
    }

    if ($start !== null && $end !== null && strtotime($end) <= strtotime($start)) {
        $start_dt = DateTime::createFromFormat('H:i', $start);
        if ($start_dt) {
            $start_dt->modify('+1 hour');
            $end = $start_dt->format('H:i');
        }
    }

    return [$start, $end];
};

try {
    // Calculate selected week dates (Monday to Sunday)
    $today = new DateTime();
    $monday = clone $today;
    $monday->modify('Monday this week');
    $monday->modify("+{$week_offset} weeks");
    
    $sunday = clone $monday;
    $sunday->modify('Sunday this week');
    
    $start_date = $monday->format('Y-m-d');
    $end_date = $sunday->format('Y-m-d');

    $payload_options = ['semester' => $semester];
    if ($saved_at !== '') {
        $payload_options['saved_at'] = $saved_at;
    }
    $unified_payload = unified_schedule_fetch($conn, $_SESSION, $payload_options);

    $events = [];

    // Convert unified schedule rows to calendar events
    $day_map = ['Monday' => 0, 'Tuesday' => 1, 'Wednesday' => 2, 'Thursday' => 3, 'Friday' => 4, 'Saturday' => 5, 'Sunday' => 6];

    foreach (($unified_payload['rows'] ?? []) as $row) {
        $event_day = $day_map[(string)($row['day'] ?? '')] ?? null;
        if ($event_day === null) continue;

        [$start_time, $end_time] = $parse_time_range((string)($row['time'] ?? ''));
        if ($start_time === null || $end_time === null) {
            continue;
        }

        $course_code = trim((string)($row['course_code'] ?? ''));
        $course_title = trim((string)($row['course_title'] ?? ''));
        $room = trim((string)($row['room'] ?? ''));

        $title = $course_code !== '' ? $course_code : 'Scheduled Class';
        if ($course_title !== '') {
            $title .= ' - ' . $course_title;
        }
        if ($room !== '') {
            $title .= ' @ ' . $room;
        }

        $event_date = clone $monday;
        $event_date->modify("+{$event_day} days");
        $event_date_str = $event_date->format('Y-m-d');

        $events[] = [
            'title' => $title,
            'date' => $event_date_str,
            'start_time' => $start_time,
            'end_time' => $end_time,
            'type' => 'scheduled'
        ];
    }

    // Fetch personal events
    $personal_query = $conn->prepare("
        SELECT pe.title, pe.day, pe.start_time, pe.end_time, pe.event_type
        FROM personal_events pe
        WHERE pe.user_id = ?
    ");
    if (!$personal_query) throw new Exception("Prepare failed: " . $conn->error);
    $personal_query->bind_param('i', $user_id);
    $personal_query->execute();
    $personal_results = $personal_query->get_result();
    
    while ($row = $personal_results->fetch_assoc()) {
        $event_day = $day_map[$row['day']] ?? null;
        if ($event_day === null) continue;

        $event_date = clone $monday;
        $event_date->modify("+{$event_day} days");
        $event_date_str = $event_date->format('Y-m-d');

        $start_time = $to_hhmm((string)$row['start_time']);
        $end_time = $to_hhmm((string)$row['end_time']);
        if ($start_time === null || $end_time === null) {
            continue;
        }

        $events[] = [
            'title' => $row['title'],
            'date' => $event_date_str,
            'start_time' => $start_time,
            'end_time' => $end_time,
            'type' => 'personal'
        ];
    }

    // De-duplicate and sort events
    $deduped = [];
    $seen = [];
    foreach ($events as $evt) {
        $key = implode('|', [$evt['title'], $evt['date'], $evt['start_time'], $evt['end_time'], $evt['type']]);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $deduped[] = $evt;
    }

    usort($deduped, static function ($a, $b) {
        $aKey = $a['date'] . ' ' . $a['start_time'];
        $bKey = $b['date'] . ' ' . $b['start_time'];
        return strcmp($aKey, $bKey);
    });

    if (empty($deduped)) {
        echo json_encode([
            'success' => false,
            'error' => 'No schedule or personal events found to export for this week.'
        ]);
        exit;
    }

    $event_links = [];
    foreach ($deduped as $evt) {
        $start_dt = str_replace('-', '', $evt['date']) . 'T' . str_replace(':', '', $evt['start_time']) . '00';
        $end_dt = str_replace('-', '', $evt['date']) . 'T' . str_replace(':', '', $evt['end_time']) . '00';

        $params = [
            'action' => 'TEMPLATE',
            'text' => $evt['title'],
            'details' => 'Exported from Scheduler. Week: ' . $start_date . ' to ' . $end_date,
            'dates' => $start_dt . '/' . $end_dt
        ];

        $event_links[] = [
            'title' => $evt['title'],
            'date' => $evt['date'],
            'start_time' => $evt['start_time'],
            'end_time' => $evt['end_time'],
            'url' => 'https://calendar.google.com/calendar/u/0/r/eventedit?' . http_build_query($params)
        ];
    }

    $first_event = $deduped[0];
    $calendar_url = $event_links[0]['url'];

    echo json_encode([
        'success' => true,
        'url' => $calendar_url,
        'week' => [
            'start' => $start_date,
            'end' => $end_date
        ],
        'events_count' => count($deduped),
        'message' => 'Calendar export ready. Use the event navigator to add multiple events to Google Calendar.',
        'first_event' => $first_event,
        'event_links' => $event_links
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Export failed: ' . $e->getMessage()]);
}
