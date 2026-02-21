<?php
/**
 * Calendar Export (iCalendar Format)
 * 
 * Exports schedule to .ics format for:
 * - Google Calendar import
 * - Outlook import
 * - Apple Calendar import
 * - Any calendar app supporting iCalendar standard
 */

header('Content-Type: application/json');
require_once 'db.php';
require_once 'error_handler.php';

/**
 * Generate iCalendar (ICS) format
 */
function generate_ics_calendar($courses, $calendar_name = 'Schedule', $role = 'student') {
    // iCalendar header
    $ics = "BEGIN:VCALENDAR\r\n";
    $ics .= "VERSION:2.0\r\n";
    $ics .= "PRODID:-//AI Scheduler//EN\r\n";
    $ics .= "CALSCALE:GREGORIAN\r\n";
    $ics .= "METHOD:PUBLISH\r\n";
    $ics .= "X-WR-CALNAME:" . $calendar_name . "\r\n";
    $ics .= "X-WR-TIMEZONE:UTC\r\n";
    $ics .= "BEGIN:VTIMEZONE\r\n";
    $ics .= "TZID:UTC\r\n";
    $ics .= "BEGIN:STANDARD\r\n";
    $ics .= "TZOFFSETFROM:+0000\r\n";
    $ics .= "TZOFFSETTO:+0000\r\n";
    $ics .= "TZNAME:UTC\r\n";
    $ics .= "DTSTART:19700101T000000\r\n";
    $ics .= "END:STANDARD\r\n";
    $ics .= "END:VTIMEZONE\r\n";
    
    // Add events
    foreach ($courses as $course) {
        $ics .= generate_ics_event($course);
    }
    
    // Closing
    $ics .= "END:VCALENDAR\r\n";
    
    return $ics;
}

/**
 * Generate single iCalendar event
 */
function generate_ics_event($course) {
    $event = "BEGIN:VEVENT\r\n";
    
    // Unique ID
    $uid = str_replace(' ', '-', $course['course_code'] ?? 'event') . '-' . 
           ($course['section_number'] ?? '1') . '-' . 
           time() . '@aischeduler.local';
    $event .= "UID:" . $uid . "\r\n";
    
    // Timestamps
    $created = date('Ymd\THis\Z');
    $event .= "DTSTAMP:" . $created . "\r\n";
    $event .= "CREATED:" . $created . "\r\n";
    $event .= "LAST-MODIFIED:" . $created . "\r\n";
    
    // Parse date/time
    $day_map = [
        'Monday' => 'MO',
        'Tuesday' => 'TU',
        'Wednesday' => 'WE',
        'Thursday' => 'TH',
        'Friday' => 'FR',
        'Saturday' => 'SA',
        'Sunday' => 'SU'
    ];
    
    $day = $course['day'] ?? 'Monday';
    $start_time = $course['start_time'] ?? '08:00';
    $end_time = $course['end_time'] ?? '10:00';
    
    // Create DTSTART and DTEND (using a baseline date - Monday of first week)
    $base_date = strtotime('2026-02-16'); // A Monday
    if ($day !== 'Monday') {
        foreach ($day_map as $day_name => $abbr) {
            if ($day_name === 'Monday') break;
            if ($day_name === $day) break;
            $base_date = strtotime('+1 day', $base_date);
        }
    }
    
    // Parse times
    $start_parts = explode(':', $start_time);
    $end_parts = explode(':', $end_time);
    
    $start_datetime = date('Ymd', $base_date) . 'T' . 
                      str_pad($start_parts[0], 2, '0', STR_PAD_LEFT) . 
                      str_pad($start_parts[1] ?? '00', 2, '0', STR_PAD_LEFT) . '00';
    
    $end_datetime = date('Ymd', $base_date) . 'T' . 
                    str_pad($end_parts[0], 2, '0', STR_PAD_LEFT) . 
                    str_pad($end_parts[1] ?? '00', 2, '0', STR_PAD_LEFT) . '00';
    
    $event .= "DTSTART:" . $start_datetime . "\r\n";
    $event .= "DTEND:" . $end_datetime . "\r\n";
    
    // Recurrence (weekly)
    $event .= "RRULE:FREQ=WEEKLY;BYDAY=" . $day_map[$day] . ";UNTIL=20260531T235959Z\r\n";
    
    // Summary and description
    $summary = ($course['course_code'] ?? 'Class') . 
               (isset($course['section_number']) ? ' - Sec ' . $course['section_number'] : '');
    $event .= "SUMMARY:" . escape_ics_text($summary) . "\r\n";
    
    $description = "Course: " . ($course['course_name'] ?? 'N/A') . "\n" .
                   "Lecturer: " . ($course['lecturer_name'] ?? 'TBA') . "\n" .
                   "Room: " . ($course['room_name'] ?? 'TBA') . "\n" .
                   "Day: " . $day . " " . $start_time . " - " . $end_time;
    
    $event .= "DESCRIPTION:" . escape_ics_text($description) . "\r\n";
    
    // Location
    if (!empty($course['room_name'])) {
        $event .= "LOCATION:" . escape_ics_text($course['room_name']) . "\r\n";
    }
    
    // Organizer (lecturer)
    if (!empty($course['lecturer_name'])) {
        $event .= "ORGANIZER:CN=" . escape_ics_text($course['lecturer_name']) . "\r\n";
    }
    
    // Alarm (2 hours before)
    $event .= "BEGIN:VALARM\r\n";
    $event .= "TRIGGER:-PT2H\r\n";
    $event .= "ACTION:DISPLAY\r\n";
    $event .= "DESCRIPTION:Upcoming class\r\n";
    $event .= "END:VALARM\r\n";
    
    // Categories
    $event .= "CATEGORIES:EDUCATION\r\n";
    
    // Status
    $event .= "STATUS:CONFIRMED\r\n";
    
    // Transparency
    $event .= "TRANSP:OPAQUE\r\n";
    
    // Priority
    $event .= "PRIORITY:5\r\n";
    
    $event .= "END:VEVENT\r\n";
    
    return $event;
}

/**
 * Escape text for iCalendar format
 */
function escape_ics_text($text) {
    $text = str_replace('\\', '\\\\', $text);
    $text = str_replace(',', '\\,', $text);
    $text = str_replace(';', '\\;', $text);
    $text = str_replace("\n", '\\n', $text);
    $text = str_replace("\r", '', $text);
    return $text;
}

/**
 * Export schedule to ICS for user
 */
function export_user_schedule_to_ics($user_id, $role) {
    global $conn;
    
    $courses = [];
    
    if ($role === 'student') {
        // Get enrolled courses for student
        $query = "SELECT DISTINCT s.section_id, s.course_code, c.course_name,
                         sec.lecturer_name, r.room_name, st.day, st.start_time, st.end_time,
                         sec.section_number
                  FROM student_enrollments se
                  JOIN sections s ON se.section_id = s.section_id
                  JOIN courses c ON s.course_code = c.course_code
                  JOIN section_timings st ON s.section_id = st.section_id
                  JOIN lecturers sec ON s.lecturer_name = sec.lecturer_name
                  LEFT JOIN rooms r ON st.room_id = r.room_id
                  WHERE se.student_id = ?
                  ORDER BY st.day, st.start_time";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param('i', $user_id);
        
    } elseif ($role === 'lecturer') {
        // Get teaching assignments for lecturer
        $query = "SELECT DISTINCT s.section_id, s.course_code, c.course_name,
                         l.lecturer_name, r.room_name, st.day, st.start_time, st.end_time,
                         s.section_number
                  FROM sections s
                  JOIN courses c ON s.course_code = c.course_code
                  JOIN lecturers l ON s.lecturer_name = l.lecturer_name
                  JOIN section_timings st ON s.section_id = st.section_id
                  LEFT JOIN rooms r ON st.room_id = r.room_id
                  WHERE l.lecturer_id = ?
                  ORDER BY st.day, st.start_time";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param('i', $user_id);
        
    } else {
        // Admin can export entire schedule using B2 as fallback
        require_once __DIR__ . '/../../lib/B2Storage.php';
        $b2 = new B2Storage();
        $result = $b2->download('csv/final/final_web_schedule.csv');
        
        if ($result['success']) {
            $lines = explode("\n", $result['content']);
            $headers = str_getcsv(array_shift($lines));
            
            foreach ($lines as $line) {
                if (trim($line) === '') continue;
                $row = str_getcsv($line);
                $courses[] = array_combine($headers, $row);
            }
            
            return generate_ics_calendar($courses, 'Full Schedule', 'admin');
        }
        return null;
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $courses[] = $row;
    }
    
    if (empty($courses)) {
        return null;
    }
    
    $calendar_name = $role === 'student' ? 'My Classes' : 'My Teaching Schedule';
    return generate_ics_calendar($courses, $calendar_name, $role);
}

/**
 * Export full schedule to ICS
 */
function export_full_schedule_to_ics($schedule_csv_content = null) {
    global $conn;
    
    if (!$schedule_csv_content) {
        $b2 = new B2Storage();
        $result = $b2->download('csv/final/final_web_schedule.csv');
        if (!$result['success']) {
            return null;
        }
        $schedule_csv_content = $result['content'];
    }
    
    $courses = [];
    $lines = explode("\n", $schedule_csv_content);
    $headers = str_getcsv(array_shift($lines));
    
    foreach ($lines as $line) {
        if (trim($line) === '') continue;
        $row = str_getcsv($line);
        $course = array_combine($headers, $row);
        $courses[] = $course;
    }
    
    if (empty($courses)) {
        return null;
    }
    
    return generate_ics_calendar($courses, 'Full Schedule');
}

/**
 * API Endpoint
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'export_user' && isset($_POST['user_id'], $_POST['role'])) {
        $user_id = (int)$_POST['user_id'];
        $role = $_POST['role'];
        
        $ics_content = export_user_schedule_to_ics($user_id, $role);
        
        if (!$ics_content) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'No schedule data found']);
            exit;
        }
        
        // Return ICS content or suggest download
        $filename = 'schedule_' . $role . '_' . $user_id . '.ics';
        
        if (isset($_POST['download']) && $_POST['download'] === '1') {
            header('Content-Type: text/calendar; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            echo $ics_content;
        } else {
            // Return as base64 for download
            echo json_encode([
                'success' => true,
                'filename' => $filename,
                'content' => base64_encode($ics_content),
                'download_url' => 'export_to_ics.php?action=download&file=' . urlencode($filename)
            ]);
        }
        
    } elseif ($action === 'export_full') {
        $schedule_csv = $_POST['schedule_csv'] ?? null;
        
        if ($schedule_csv) {
            $safe_path = realpath('../../' . $schedule_csv);
            if (!$safe_path || strpos($safe_path, realpath('../../')) !== 0) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Invalid file path']);
                exit;
            }
        }
        
        $ics_content = export_full_schedule_to_ics($safe_path ?? null);
        
        if (!$ics_content) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'No schedule data found']);
            exit;
        }
        
        if (isset($_POST['download']) && $_POST['download'] === '1') {
            header('Content-Type: text/calendar; charset=utf-8');
            header('Content-Disposition: attachment; filename="full_schedule.ics"');
            echo $ics_content;
        } else {
            echo json_encode([
                'success' => true,
                'filename' => 'full_schedule.ics',
                'content' => base64_encode($ics_content)
            ]);
        }
        
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
    }
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    if ($_GET['action'] === 'download' && isset($_GET['file'])) {
        // Serve cached ICS file if available
        $filename = basename($_GET['file']);
        $filepath = '../../temp/' . md5($filename) . '.ics';
        
        if (file_exists($filepath)) {
            header('Content-Type: text/calendar; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            readfile($filepath);
        } else {
            http_response_code(404);
            echo 'File not found';
        }
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
?>
