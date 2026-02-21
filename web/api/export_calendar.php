<?php
require_once 'db.php';
header('Content-Type: application/json');

session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
$user_role = $_SESSION['role'] ?? 'student';
$is_lecturer = $user_role === 'lecturer';
$lecturer_id = (int)($_SESSION['lecturer_id'] ?? 0);
$semester = (string)($_SESSION['semester'] ?? '1');

$export_type = $_GET['type'] ?? 'google';
$week_offset = (int)($_GET['week'] ?? 0);

if ($export_type !== 'google') {
    http_response_code(400);
    echo json_encode(['error' => 'Unsupported export type']);
    exit;
}

try {
    // Calculate week dates (Monday to Sunday)
    $today = new DateTime();
    $monday = clone $today;
    $monday->modify('Monday this week');
    $monday->modify("+{$week_offset} weeks");
    
    $sunday = clone $monday;
    $sunday->modify('Sunday this week');
    
    $start_date = $monday->format('Y-m-d');
    $end_date = $sunday->format('Y-m-d');
    
    $events = [];
    
    // Fetch scheduled classes from generated_schedules
    if ($is_lecturer) {
        // Get lecturer name and department
        $lecturer_query = $conn->prepare("
            SELECT l.name, l.department, GROUP_CONCAT(DISTINCT c.course_code) as course_codes
            FROM lecturers l
            LEFT JOIN courses c ON l.id = c.lecturer_id
            WHERE l.id = ?
        ");
        if (!$lecturer_query) throw new Exception("Prepare failed: " . $conn->error);
        $lecturer_query->bind_param('i', $lecturer_id);
        $lecturer_query->execute();
        $lecturer_result = $lecturer_query->get_result()->fetch_assoc();
        $lecturer_name = $lecturer_result['name'] ?? '';
        $department = $lecturer_result['department'] ?? '';
        $course_codes = $lecturer_result['course_codes'] ?? '';
        
        // Infer department from course codes if not set
        if (empty($department) && !empty($course_codes)) {
            $codes = explode(',', $course_codes);
            foreach ($codes as $code) {
                $code = strtoupper(trim($code));
                if (preg_match('/^COSC/', $code)) {
                    $department = 'CS/IT/BBIS';
                    break;
                } elseif (preg_match('/^NURS/', $code)) {
                    $department = 'Nursing';
                    break;
                } elseif (preg_match('/^BUS/', $code)) {
                    $department = 'Business';
                    break;
                }
            }
        }
        
        // Get schedule rows from generated_schedules
        $schedule_query = $conn->prepare("
            SELECT schedule_data, created_at
            FROM generated_schedules
            WHERE department = ?
            ORDER BY created_at DESC
            LIMIT 50
        ");
        if (!$schedule_query) throw new Exception("Prepare failed: " . $conn->error);
        $schedule_query->bind_param('s', $department);
        $schedule_query->execute();
        $schedule_results = $schedule_query->get_result();
        
        $extracted_events = [];
        while ($row = $schedule_results->fetch_assoc()) {
            $schedule_data = json_decode($row['schedule_data'], true);
            if (is_array($schedule_data)) {
                foreach ($schedule_data as $item) {
                    if (is_array($item) && isset($item[0], $item[1], $item[2], $item[3])) {
                        list($day, $time_range, $label, $lecturer) = $item;
                        if (strtolower($lecturer) === strtolower($lecturer_name)) {
                            if (preg_match('/(\d{2}):(\d{2})\s*-\s*(\d{2}):(\d{2})/', $time_range, $m)) {
                                $extracted_events[] = [
                                    'day' => $day,
                                    'start_time' => $m[1] . ':' . $m[2],
                                    'end_time' => $m[3] . ':' . $m[4],
                                    'label' => $label
                                ];
                            }
                        }
                    }
                }
            }
        }
    } else {
        // Student: get enrolled courses
        $enrolled_query = $conn->prepare("
            SELECT DISTINCT c.course_code FROM user_progress up
            JOIN enrollments e ON up.user_id = e.user_id
            JOIN sections s ON e.section_id = s.id
            JOIN courses c ON s.course_id = c.id
            WHERE up.user_id = ?
        ");
        if (!$enrolled_query) throw new Exception("Prepare failed: " . $conn->error);
        $enrolled_query->bind_param('i', $user_id);
        $enrolled_query->execute();
        $enrolled_codes = [];
        $enrolled_result = $enrolled_query->get_result();
        while ($row = $enrolled_result->fetch_assoc()) {
            $enrolled_codes[] = $row['course_code'];
        }
        
        // Get schedule rows from generated_schedules for enrolled courses
        $schedule_query = $conn->prepare("
            SELECT schedule_data, created_at
            FROM generated_schedules
            ORDER BY created_at DESC
            LIMIT 50
        ");
        if (!$schedule_query) throw new Exception("Prepare failed: " . $conn->error);
        $schedule_query->execute();
        $schedule_results = $schedule_query->get_result();
        
        $extracted_events = [];
        while ($row = $schedule_results->fetch_assoc()) {
            $schedule_data = json_decode($row['schedule_data'], true);
            if (is_array($schedule_data)) {
                foreach ($schedule_data as $item) {
                    if (is_array($item) && isset($item[0], $item[1], $item[2])) {
                        list($day, $time_range, $label) = $item;
                        $course_code = strtoupper(explode(' ', $label)[0] ?? '');
                        if (in_array($course_code, $enrolled_codes, true)) {
                            if (preg_match('/(\d{2}):(\d{2})\s*-\s*(\d{2}):(\d{2})/', $time_range, $m)) {
                                $extracted_events[] = [
                                    'day' => $day,
                                    'start_time' => $m[1] . ':' . $m[2],
                                    'end_time' => $m[3] . ':' . $m[4],
                                    'label' => $label
                                ];
                            }
                        }
                    }
                }
            }
        }
    }
    
    // Convert schedule events to calendar format
    $day_map = ['Monday' => 0, 'Tuesday' => 1, 'Wednesday' => 2, 'Thursday' => 3, 'Friday' => 4, 'Saturday' => 5, 'Sunday' => 6];
    
    foreach ($extracted_events as $event) {
        $event_day = $day_map[$event['day']] ?? null;
        if ($event_day === null) continue;
        
        // Calculate the date for this event in the current week
        $event_date = clone $monday;
        $event_date->modify("+{$event_day} days");
        $event_date_str = $event_date->format('Y-m-d');
        
        $events[] = [
            'title' => $event['label'],
            'date' => $event_date_str,
            'start_time' => $event['start_time'],
            'end_time' => $event['end_time'],
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
        
        $events[] = [
            'title' => $row['title'],
            'date' => $event_date_str,
            'start_time' => $row['start_time'],
            'end_time' => $row['end_time'],
            'type' => 'personal'
        ];
    }
    
    // Build Google Calendar URL with events
    $calendar_events = [];
    foreach ($events as $evt) {
        $start_dt = $evt['date'] . 'T' . $evt['start_time'] . ':00Z';
        $end_dt = $evt['date'] . 'T' . $evt['end_time'] . ':00Z';
        $calendar_events[] = urlencode($evt['title']) . '/' . $start_dt . '/' . $end_dt;
    }
    
    // Generate Google Calendar URL
    $text = "My Weekly Schedule ({$start_date} to {$end_date})";
    $user_name = $_SESSION['name'] ?? 'User';
    $description = "Weekly timetable including classes and personal events for {$user_name}";
    
    $params = [
        'action' => 'TEMPLATE',
        'text' => $text,
        'details' => $description,
        'dates' => $start_date . 'T000000Z/' . $sunday->format('Y-m-d') . 'T235959Z'
    ];
    
    $calendar_url = 'https://calendar.google.com/calendar/u/0/r/eventedit?' . http_build_query($params);
    
    // Alternative: Generate multi-event URL by creating individual event links
    if (count($events) > 0) {
        $first_event = $events[0];
        $start_dt = $first_event['date'] . 'T' . str_replace(':', '', $first_event['start_time']) . '00Z';
        $end_dt = $first_event['date'] . 'T' . str_replace(':', '', $first_event['end_time']) . '00Z';
        
        $single_event_params = [
            'action' => 'TEMPLATE',
            'text' => $first_event['title'],
            'dates' => $start_dt . '/' . $end_dt
        ];
        
        $calendar_url = 'https://calendar.google.com/calendar/u/0/r/eventedit?' . http_build_query($single_event_params);
    }
    
    echo json_encode([
        'success' => true,
        'url' => $calendar_url,
        'week' => [
            'start' => $start_date,
            'end' => $end_date
        ],
        'events_count' => count($events),
        'message' => 'Calendar export ready. Open the link to add events to your Google Calendar.'
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Export failed: ' . $e->getMessage()]);
}
