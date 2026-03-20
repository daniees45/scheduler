<?php
$page_title = 'My Weekly Schedule';
$page_css = 'assets/my_schedule.css';
include 'includes/header.php';
require_once 'api/db.php';
require_once 'includes/unified_schedule_service.php';

requireRole(['student', 'lecturer']);

$user_id = (int)($_SESSION['user_id'] ?? 0);
$user_role = $_SESSION['role'] ?? 'student';
$is_lecturer = $user_role === 'lecturer';
$lecturer_id = (int)($_SESSION['lecturer_id'] ?? 0);
$lecturer_name = '';
$message = '';
$message_type = '';
$account_warning = '';
$semester = (string)($_SESSION['semester'] ?? '1');
$saved_at_selection = trim((string)($_GET['saved_at'] ?? ''));

$days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
$event_types = ['study', 'work', 'personal', 'exercise', 'rest', 'other'];

$column_exists = static function (mysqli $conn, string $table, string $column): bool {
    $stmt = $conn->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
    if (!$stmt)
        return false;
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $res = $stmt->get_result();
    return $res && $res->num_rows > 0;
};

// Ensure personal_events table exists (defensive to avoid 500s)
$create_personal_events_sql = "CREATE TABLE IF NOT EXISTS personal_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(120) NOT NULL,
    description TEXT NULL,
    day VARCHAR(20) NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    event_type VARCHAR(30) DEFAULT 'other',
    color VARCHAR(20) DEFAULT '#6366f1',
    priority_id INT NULL,
    goal_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_day_time (user_id, day, start_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$conn->query($create_personal_events_sql);

if (!$column_exists($conn, 'personal_events', 'priority_id')) {
    $conn->query("ALTER TABLE personal_events ADD COLUMN priority_id INT NULL");
}
if (!$column_exists($conn, 'personal_events', 'goal_id')) {
    $conn->query("ALTER TABLE personal_events ADD COLUMN goal_id INT NULL");
}

// Add/Delete actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_event') {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $day = trim($_POST['day'] ?? '');
        $start_time = trim($_POST['start_time'] ?? '');
        $end_time = trim($_POST['end_time'] ?? '');
        $event_type = trim($_POST['event_type'] ?? 'other');
        $color = trim($_POST['color'] ?? '#6366f1');
        $priority_id = isset($_POST['priority_id']) && $_POST['priority_id'] !== '' ? (int)$_POST['priority_id'] : null;
        $goal_id = isset($_POST['goal_id']) && $_POST['goal_id'] !== '' ? (int)$_POST['goal_id'] : null;

        if ($title === '' || !in_array($day, $days, true) || $start_time === '' || $end_time === '') {
            $message = 'Please fill all required fields correctly.';
            $message_type = 'error';
        }
        elseif (!preg_match('/^\d{2}:\d{2}$/', $start_time) || !preg_match('/^\d{2}:\d{2}$/', $end_time)) {
            $message = 'Invalid time format.';
            $message_type = 'error';
        }
        elseif (strtotime($end_time) <= strtotime($start_time)) {
            $message = 'End time must be later than start time.';
            $message_type = 'error';
        }
        else {
            if ($priority_id !== null) {
                $priority_check = $conn->prepare("SELECT id FROM user_priorities WHERE id = ? AND user_id = ? AND is_active = TRUE");
                if ($priority_check) {
                    $priority_check->bind_param('ii', $priority_id, $user_id);
                    $priority_check->execute();
                    if ($priority_check->get_result()->num_rows === 0) {
                        $message = 'Selected priority is not available.';
                        $message_type = 'error';
                    }
                }
            }

            if (empty($message) && $goal_id !== null) {
                $goal_check = $conn->prepare("SELECT id FROM user_goals WHERE id = ? AND user_id = ? AND status = 'active'");
                if ($goal_check) {
                    $goal_check->bind_param('ii', $goal_id, $user_id);
                    $goal_check->execute();
                    if ($goal_check->get_result()->num_rows === 0) {
                        $message = 'Selected goal is not available.';
                        $message_type = 'error';
                    }
                }
            }

            if (!empty($message)) {
            // Stop if priority/goal validation failed
            }
            else {
                // Conflict check within student's personal events for same day
                $conflict_sql = "SELECT id FROM personal_events
                             WHERE user_id = ? AND day = ?
                               AND NOT (end_time <= ? OR start_time >= ?)
                             LIMIT 1";
                $conflict_stmt = $conn->prepare($conflict_sql);
                if ($conflict_stmt) {
                    $conflict_stmt->bind_param('isss', $user_id, $day, $start_time, $end_time);
                    $conflict_stmt->execute();
                    $conflict_result = $conflict_stmt->get_result();

                    if ($conflict_result && $conflict_result->num_rows > 0) {
                        $message = 'This event overlaps with an existing personal event on ' . $day . '.';
                        $message_type = 'warning';
                    }
                    else {
                        $insert_sql = "INSERT INTO personal_events
                        (user_id, title, description, day, start_time, end_time, event_type, color, priority_id, goal_id)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                        $insert_stmt = $conn->prepare($insert_sql);
                        if ($insert_stmt) {
                            $insert_stmt->bind_param('isssssssii', $user_id, $title, $description, $day, $start_time, $end_time, $event_type, $color, $priority_id, $goal_id);
                            if ($insert_stmt->execute()) {
                                $message = 'Personal event added successfully.';
                                $message_type = 'success';
                            }
                            else {
                                $message = 'Unable to add event right now. Please try again.';
                                $message_type = 'error';
                            }
                        }
                        else {
                            $message = 'Unable to prepare event insert.';
                            $message_type = 'error';
                        }
                    }
                }
                else {
                    $message = 'Unable to validate event conflicts.';
                    $message_type = 'error';
                }
            }
        }
    }

    if ($action === 'delete_event') {
        $event_id = (int)($_POST['event_id'] ?? 0);
        if ($event_id > 0) {
            $delete_sql = "DELETE FROM personal_events WHERE id = ? AND user_id = ?";
            $delete_stmt = $conn->prepare($delete_sql);
            if ($delete_stmt) {
                $delete_stmt->bind_param('ii', $event_id, $user_id);
                if ($delete_stmt->execute()) {
                    $message = 'Event removed successfully.';
                    $message_type = 'success';
                }
                else {
                    $message = 'Unable to delete event.';
                    $message_type = 'error';
                }
            }
        }
    }
}

// Fetch events
$events = [];
$events_by_day = [];
foreach ($days as $d) {
    $events_by_day[$d] = [];
}

$events_stmt = $conn->prepare(
    "SELECT pe.id, pe.title, pe.description, pe.day, pe.start_time, pe.end_time,
            pe.event_type, pe.color, pe.priority_id, pe.goal_id,
            p.priority_name, p.priority_level, g.goal_title
     FROM personal_events pe
     LEFT JOIN user_priorities p ON pe.priority_id = p.id AND p.user_id = pe.user_id
     LEFT JOIN user_goals g ON pe.goal_id = g.id AND g.user_id = pe.user_id
     WHERE pe.user_id = ?
     ORDER BY FIELD(pe.day, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'), pe.start_time"
);

if ($events_stmt) {
    $events_stmt->bind_param('i', $user_id);
    $events_stmt->execute();
    $events_result = $events_stmt->get_result();
    while ($row = $events_result->fetch_assoc()) {
        $events[] = $row;
        if (isset($events_by_day[$row['day']])) {
            $events_by_day[$row['day']][] = $row;
        }
    }
}

$event_count = count($events);

// Load priorities and goals for event tagging
$priority_options = [];
$goal_options = [];

$priority_stmt = $conn->prepare("
    SELECT id, priority_name, priority_level
    FROM user_priorities
    WHERE user_id = ? AND is_active = TRUE
    ORDER BY FIELD(priority_level, 'high', 'medium', 'low'), created_at DESC
");
if ($priority_stmt) {
    $priority_stmt->bind_param('i', $user_id);
    $priority_stmt->execute();
    $priority_res = $priority_stmt->get_result();
    while ($row = $priority_res->fetch_assoc()) {
        $priority_options[] = $row;
    }
}

$goal_stmt = $conn->prepare("
    SELECT id, goal_title, priority_level
    FROM user_goals
    WHERE user_id = ? AND status = 'active'
    ORDER BY FIELD(priority_level, 'high', 'medium', 'low'), created_at DESC
");
if ($goal_stmt) {
    $goal_stmt->bind_param('i', $user_id);
    $goal_stmt->execute();
    $goal_res = $goal_stmt->get_result();
    while ($row = $goal_res->fetch_assoc()) {
        $goal_options[] = $row;
    }
}

// Build generated weekly timetable (classes + personal events)
$weekly_rows = [];
$conflict_count = 0;
$day_order = array_flip($days);

$to_minutes = static function ($timeStr) {
    $ts = strtotime(trim((string)$timeStr));
    if ($ts === false)
        return null;
    return ((int)date('H', $ts)) * 60 + (int)date('i', $ts);
};

$extract_section = static function ($text): string {
    $text = strtoupper((string)$text);
    if (preg_match('/\bSEC(?:TION)?\s*-?\s*([A-Z0-9]+)/i', $text, $m)) {
        return 'SEC ' . strtoupper($m[1]);
    }
    return '';
};

$parse_range = static function ($range) use ($to_minutes) {
    $raw = trim((string)$range);
    if ($raw === '')
        return [null, null, ''];

    $parts = preg_split('/\s*-\s*/', $raw);
    if (!$parts || count($parts) < 2) {
        $start = $to_minutes($raw);
        return [$start, $start !== null ? $start + 60 : null, $raw];
    }

    $start = $to_minutes($parts[0]);
    $end = $to_minutes($parts[1]);
    if ($start !== null && $end !== null && $end <= $start) {
        $end = $start + 60;
    }

    return [$start, $end, $raw];
};

$has_section_column = $is_lecturer ? false : $column_exists($conn, 'student_enrollments', 'section');
$lecturer_department = '';

// Student enrolled course codes
$enrolled_codes = [];
$enrolled_meta = [];
$enrolled_sections = [];
$selected_section_by_code = [];
if ($is_lecturer) {
    if ($lecturer_id > 0) {
        $name_stmt = $conn->prepare("SELECT name FROM lecturers WHERE id = ?");
        if ($name_stmt) {
            $name_stmt->bind_param('i', $lecturer_id);
            $name_stmt->execute();
            $name_res = $name_stmt->get_result();
            if ($name_res && ($name_row = $name_res->fetch_assoc())) {
                $lecturer_name = trim((string)$name_row['name']);
            }
        }

        if ($column_exists($conn, 'lecturers', 'department')) {
            $dept_stmt = $conn->prepare("SELECT department FROM lecturers WHERE id = ?");
            if ($dept_stmt) {
                $dept_stmt->bind_param('i', $lecturer_id);
                $dept_stmt->execute();
                $dept_res = $dept_stmt->get_result();
                if ($dept_res && ($dept_row = $dept_res->fetch_assoc())) {
                    $lecturer_department = trim((string)($dept_row['department'] ?? ''));
                }
            }
        }

        $enrolled_sql = "SELECT DISTINCT c.course_code, c.course_title, l.name AS lecturer_name
                         FROM sections s
                         JOIN courses c ON s.course_id = c.id
                         LEFT JOIN lecturers l ON s.lecturer_id = l.id
                         WHERE s.lecturer_id = ?";
        $enrolled_stmt = $conn->prepare($enrolled_sql);
        if ($enrolled_stmt) {
            $enrolled_stmt->bind_param('i', $lecturer_id);
            $enrolled_stmt->execute();
            $enrolled_res = $enrolled_stmt->get_result();
            while ($r = $enrolled_res->fetch_assoc()) {
                $code = strtoupper(trim($r['course_code'] ?? ''));
                if ($code !== '') {
                    $enrolled_codes[$code] = true;
                    $enrolled_meta[$code] = $r;
                }
            }
        }
    }
    else {
        $account_warning = 'Your account is not linked to a lecturer profile. Please contact an administrator.';
    }
}
else {
    $enrolled_sql = "SELECT c.course_code, c.course_title, l.name AS lecturer_name, " . ($has_section_column ? 'se.section' : 'NULL AS section') . "
                     FROM student_enrollments se
                     JOIN courses c ON se.course_id = c.id
                     LEFT JOIN lecturers l ON c.lecturer_id = l.id
                     WHERE se.user_id = ? AND se.semester = ?";
    $enrolled_stmt = $conn->prepare($enrolled_sql);
    if ($enrolled_stmt) {
        $enrolled_stmt->bind_param('is', $user_id, $semester);
        $enrolled_stmt->execute();
        $enrolled_res = $enrolled_stmt->get_result();
        while ($r = $enrolled_res->fetch_assoc()) {
            $code = strtoupper(trim($r['course_code'] ?? ''));
            if ($code !== '') {
                $enrolled_codes[$code] = true;
                $enrolled_meta[$code] = $r;
                $stored_section = trim((string)($r['section'] ?? ''));
                if ($stored_section !== '') {
                    $enrolled_sections[$code] = strtoupper($stored_section);
                }
                else {
                    $section_hint = $extract_section($code . ' ' . ($r['course_title'] ?? ''));
                    if ($section_hint !== '') {
                        $enrolled_sections[$code] = $section_hint;
                    }
                }
            }
        }
    }
}

$get_department_from_code = static function ($course_code): string {
    $code = strtoupper((string)$course_code);

    if (preg_match('/(COSC|INFT|BBIS|CSCD)/', $code))
        return 'CS/IT/BBIS';
    if (preg_match('/(ACCT|BUSI|MGMT|ECON|MKTG|FNCE)/', $code))
        return 'Business';
    if (preg_match('/(EDUC|PEDC|TEAC|CLED)/', $code))
        return 'Education';
    if (preg_match('/(DEVS|INTL|AFRI|AFRN)/', $code))
        return 'DevelopmentStudies';
    if (preg_match('/(BIOM|ENGR|BENG|HLTC)/', $code))
        return 'BiomedicalEngineering';
    if (preg_match('/(NURS|RNSG|MIDW)/', $code))
        return 'Nursing';
    if (preg_match('/(RELB|RELT)/', $code))
        return 'Theology';

    return 'General';
};

$infer_department_from_codes = static function (array $codes) use ($get_department_from_code): string {
    if (empty($codes))
        return '';

    $counts = [];
    foreach ($codes as $code) {
        $dept = $get_department_from_code($code);
        $counts[$dept] = ($counts[$dept] ?? 0) + 1;
    }

    arsort($counts);
    $top = array_key_first($counts);
    return $top ? (string)$top : '';
};

$unified_payload = unified_schedule_fetch($conn, $_SESSION, [
    'semester' => $semester,
    'saved_at' => $saved_at_selection,
]);
$available_snapshots = $unified_payload['snapshots'] ?? [];
$active_saved_at = (string)($unified_payload['saved_at'] ?? '');

if (($is_lecturer && $lecturer_name !== '') || (!$is_lecturer && !empty($enrolled_codes))) {
    foreach (($unified_payload['rows'] ?? []) as $row) {
        $code = strtoupper(trim((string)($row['course_code'] ?? '')));
        $day = trim((string)($row['day'] ?? ''));
        $time_range = trim((string)($row['time'] ?? ''));

        if ($code === '' || !isset($day_order[$day])) {
            continue;
        }

        if (!$is_lecturer && !isset($enrolled_codes[$code])) {
            continue;
        }

        if (!$is_lecturer) {
            $row_section = $extract_section(($row['section'] ?? '') . ' ' . ($row['course_title'] ?? '') . ' ' . ($row['course_code'] ?? ''));
            $enrolled_section = $enrolled_sections[$code] ?? '';

            if ($enrolled_section !== '' && $row_section !== '' && $row_section !== $enrolled_section) {
                continue;
            }

            if ($enrolled_section === '' && $row_section !== '') {
                if (!isset($selected_section_by_code[$code])) {
                    $selected_section_by_code[$code] = $row_section;
                }
                elseif ($selected_section_by_code[$code] !== $row_section) {
                    continue;
                }
            }
        }

        [$s, $e, $label] = $parse_range($time_range);
        $weekly_rows[] = [
            'day' => $day,
            'start' => $s,
            'end' => $e,
            'time' => $label,
            'type' => 'class',
            'title' => $code . ' - ' . trim((string)($row['course_title'] ?? ($enrolled_meta[$code]['course_title'] ?? 'Class'))),
            'details' => trim((string)($row['room'] ?? '')),
            'lecturer' => trim((string)($row['lecturer'] ?? ($enrolled_meta[$code]['lecturer_name'] ?? ''))),
            'conflict' => false
        ];
    }
}

// Fallback to CSV if no classes found in DB
$has_classes = false;
foreach ($weekly_rows as $row) {
    if ($row['type'] === 'class') {
        $has_classes = true;
        break;
    }
}

if (!$has_classes) {
    $csv_file = realpath('../') . '/csv/final/final_web_schedule.csv';
    if (file_exists($csv_file) && ($handle = fopen($csv_file, "r")) !== FALSE) {
        fgetcsv($handle); // skip header
        while (($row = fgetcsv($handle, 1000, ",")) !== FALSE) {
            $code = strtoupper(trim($row[0] ?? ''));
            $day = trim($row[5] ?? '');
            $time_range = trim($row[6] ?? '');
            
            if ($code === '' || !isset($day_order[$day])) continue;
            
            // Student filtering
            if (!$is_lecturer && !isset($enrolled_codes[$code])) continue;
            
            // Lecturer filtering
            if ($is_lecturer && $lecturer_name !== '') {
                $row_lecturer = trim($row[3] ?? '');
                if ($row_lecturer !== '' && strcasecmp($row_lecturer, $lecturer_name) !== 0) {
                    continue;
                }
            }
            
            [$s, $e, $label] = $parse_range($time_range);
            $weekly_rows[] = [
                'day' => $day,
                'start' => $s,
                'end' => $e,
                'time' => $label,
                'type' => 'class',
                'title' => $code . ' - ' . trim($row[1] ?? 'Class'),
                'details' => trim($row[4] ?? ''),
                'lecturer' => trim($row[3] ?? ''),
                'conflict' => false
            ];
        }
        fclose($handle);
    }
}

// Add personal events to same timeline
foreach ($events as $ev) {
    $day = trim((string)($ev['day'] ?? ''));
    if (!isset($day_order[$day]))
        continue;
    $start_label = substr((string)$ev['start_time'], 0, 5);
    $end_label = substr((string)$ev['end_time'], 0, 5);
    [$s, $e] = $parse_range($start_label . ' - ' . $end_label);

    $meta_parts = [];
    if (!empty($ev['priority_name'])) {
        $meta_parts[] = 'Priority: ' . (string)$ev['priority_name'];
    }
    if (!empty($ev['goal_title'])) {
        $meta_parts[] = 'Goal: ' . (string)$ev['goal_title'];
    }
    $details = (string)($ev['event_type'] ?? 'personal');
    if (!empty($meta_parts)) {
        $details .= ' | ' . implode(' | ', $meta_parts);
    }

    $weekly_rows[] = [
        'day' => $day,
        'start' => $s,
        'end' => $e,
        'time' => $start_label . ' - ' . $end_label,
        'type' => 'personal',
        'title' => (string)($ev['title'] ?? 'Personal Event'),
        'details' => $details,
        'lecturer' => '',
        'conflict' => false
    ];
}

// Detect overlaps between class and personal rows on same day
$rows_by_day = [];
foreach ($weekly_rows as $idx => $w) {
    $rows_by_day[$w['day']][] = $idx;
}

foreach ($rows_by_day as $d => $idx_list) {
    $class_idxs = [];
    $personal_idxs = [];

    foreach ($idx_list as $i) {
        if ($weekly_rows[$i]['type'] === 'class')
            $class_idxs[] = $i;
        if ($weekly_rows[$i]['type'] === 'personal')
            $personal_idxs[] = $i;
    }

    foreach ($class_idxs as $ci) {
        foreach ($personal_idxs as $pi) {
            $a1 = $weekly_rows[$ci]['start'];
            $a2 = $weekly_rows[$ci]['end'];
            $b1 = $weekly_rows[$pi]['start'];
            $b2 = $weekly_rows[$pi]['end'];

            if ($a1 === null || $a2 === null || $b1 === null || $b2 === null)
                continue;

            $overlap = !($a2 <= $b1 || $a1 >= $b2);
            if ($overlap) {
                if ($weekly_rows[$ci]['conflict'] === false)
                    $conflict_count++;
                if ($weekly_rows[$pi]['conflict'] === false)
                    $conflict_count++;
                $weekly_rows[$ci]['conflict'] = true;
                $weekly_rows[$pi]['conflict'] = true;
            }
        }
    }
}

usort($weekly_rows, static function ($a, $b) use ($day_order) {
    $da = $day_order[$a['day']] ?? 99;
    $db = $day_order[$b['day']] ?? 99;
    if ($da !== $db)
        return $da <=> $db;
    return ($a['start'] ?? 9999) <=> ($b['start'] ?? 9999);
});

// Recommend free time based on class timetable
$free_time_suggestions = [];
$default_day_start = 7 * 60;
$default_day_end = 21 * 60;

$table_exists = static function (mysqli $conn, string $table): bool {
    $safe_table = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '{$safe_table}'");
    return $res && $res->num_rows > 0;
};

$productivity_patterns = [];
if ($table_exists($conn, 'productivity_log')
    && $column_exists($conn, 'productivity_log', 'day')
    && $column_exists($conn, 'productivity_log', 'start_time')
    && $column_exists($conn, 'productivity_log', 'productivity_score')) {

    $has_quality_rating = $column_exists($conn, 'productivity_log', 'quality_rating');
    $has_completion_status = $column_exists($conn, 'productivity_log', 'completion_status');

    $quality_expr = $has_quality_rating ? 'AVG(COALESCE(quality_rating, 3))' : '3.0';
    $productivity_sql = "SELECT day,
                                HOUR(start_time) AS hour,
                                AVG(COALESCE(productivity_score, 5)) AS avg_score,
                                {$quality_expr} AS avg_quality,
                                COUNT(*) AS frequency
                         FROM productivity_log
                         WHERE user_id = ?";
    if ($has_completion_status) {
        $productivity_sql .= " AND completion_status = 'completed'";
    }
    $productivity_sql .= " GROUP BY day, HOUR(start_time)
                           HAVING frequency >= 2";

    $productivity_stmt = $conn->prepare($productivity_sql);
    if ($productivity_stmt) {
        $productivity_stmt->bind_param('i', $user_id);
        $productivity_stmt->execute();
        $productivity_res = $productivity_stmt->get_result();
        while ($row = $productivity_res->fetch_assoc()) {
            $pattern_key = (string)$row['day'] . '_' . (int)$row['hour'];
            $score = (float)$row['avg_score'];
            $quality = (float)$row['avg_quality'];
            $productivity_patterns[$pattern_key] = max(0.0, min(10.0, $score * ($quality / 3.0)));
        }
    }
}

$priority_weight = ['high' => 10.0, 'medium' => 7.0, 'low' => 4.0];
$priority_scores = [];
foreach ($priority_options as $priority_item) {
    $level = strtolower((string)($priority_item['priority_level'] ?? ''));
    if (isset($priority_weight[$level])) {
        $priority_scores[] = $priority_weight[$level];
    }
}
foreach ($goal_options as $goal_item) {
    $level = strtolower((string)($goal_item['priority_level'] ?? ''));
    if (isset($priority_weight[$level])) {
        $priority_scores[] = $priority_weight[$level];
    }
}
$priority_pressure_score = !empty($priority_scores)
    ? (array_sum($priority_scores) / count($priority_scores))
    : 5.0;

foreach ($days as $day) {
    $class_blocks = array_filter($weekly_rows, static function ($row) use ($day) {
        return $row['type'] === 'class' && $row['day'] === $day;
    });

    usort($class_blocks, static function ($a, $b) {
        return ($a['start'] ?? 0) <=> ($b['start'] ?? 0);
    });

    $day_start_minutes = $default_day_start;
    $day_end_minutes = $default_day_end;

    $valid_blocks = array_filter($class_blocks, static function ($row) {
        return $row['start'] !== null && $row['end'] !== null;
    });

    if (!empty($valid_blocks)) {
        $starts = array_map(static fn($row) => $row['start'], $valid_blocks);
        $ends = array_map(static fn($row) => $row['end'], $valid_blocks);
        $min_start = min($starts);
        $max_end = max($ends);
        if ($min_start < $day_start_minutes) {
            $day_start_minutes = $min_start;
        }
        if ($max_end > $day_end_minutes) {
            $day_end_minutes = $max_end;
        }
        if ($day_end_minutes <= $day_start_minutes) {
            $day_start_minutes = $default_day_start;
            $day_end_minutes = $default_day_end;
        }
    }

    $cursor = $day_start_minutes;
    $day_free_slots = [];
    foreach ($class_blocks as $block) {
        $start = $block['start'] ?? null;
        $end = $block['end'] ?? null;
        if ($start === null || $end === null)
            continue;

        if ($start > $cursor) {
            $day_free_slots[] = [
                'day' => $day,
                'start' => $cursor,
                'end' => $start,
                'duration' => $start - $cursor
            ];
        }
        if ($end > $cursor) {
            $cursor = $end;
        }
    }

    if ($cursor < $day_end_minutes) {
        $day_free_slots[] = [
            'day' => $day,
            'start' => $cursor,
            'end' => $day_end_minutes,
            'duration' => $day_end_minutes - $cursor
        ];
    }

    if (!empty($day_free_slots)) {
        foreach ($day_free_slots as $slot) {
            $start_hour = (int)floor(($slot['start'] ?? 0) / 60);
            $duration_score = min((float)($slot['duration'] ?? 0) / 30.0, 10.0);

            $pattern_key = $day . '_' . $start_hour;
            $productivity_score = isset($productivity_patterns[$pattern_key])
                ? (float)$productivity_patterns[$pattern_key]
                : 5.0;

            $time_bonus = 0.0;
            if ($start_hour >= 9 && $start_hour <= 18) {
                $time_bonus = 5.0;
            } elseif ($start_hour >= 8 && $start_hour <= 20) {
                $time_bonus = 3.0;
            }

            $total_score = $duration_score + $productivity_score + $time_bonus + $priority_pressure_score;
            $slot['ai_score'] = round($total_score, 1);
            $slot['reason'] = sprintf(
                'AI score %.1f = duration %.1f + productivity %.1f + time bonus %.1f + priority %.1f',
                $total_score,
                $duration_score,
                $productivity_score,
                $time_bonus,
                $priority_pressure_score
            );
            $free_time_suggestions[] = $slot;
        }
    }
}

// Keep all recommendations ordered by day and start time
usort($free_time_suggestions, static function ($a, $b) use ($day_order) {
    $da = $day_order[$a['day']] ?? 99;
    $db = $day_order[$b['day']] ?? 99;
    if ($da !== $db)
        return $da <=> $db;
    return ($a['start'] ?? 0) <=> ($b['start'] ?? 0);
});
?>

<?php if (!empty($account_warning)): ?>
<div class="alert alert-warning">
    <?php echo htmlspecialchars($account_warning); ?>
</div>
<?php
endif; ?>

<?php if (!empty($message)): ?>
<div class="alert alert-<?php echo htmlspecialchars($message_type); ?>">
    <?php echo htmlspecialchars($message); ?>
</div>
<?php
endif; ?>

<div class="glass-panel panel-p15 mb-15">
    <h3 class="section-title-tight"><i class="fa-solid fa-calendar-user"></i> Personal Weekly Schedule</h3>
    <p class="muted-zero">
        Add your weekly commitments (study, work, personal time, rest). Events added: <strong>
            <?php echo $event_count; ?>
        </strong>
    </p>
</div>

<div class="glass-panel quick-actions-panel">
    <a href="productivity_analytics.php" class="glass-btn action-link">
        <i class="fas fa-chart-line"></i> Analytics
    </a>
    <button class="glass-btn" onclick="loadSmartSuggestions()">
        <i class="fas fa-brain"></i> AI Suggestions
    </button>
    <button class="glass-btn" onclick="openPrioritiesModal()">
        <i class="fas fa-star"></i> Priorities & Goals
    </button>
    <button class="glass-btn" onclick="openNotificationsPanel()" id="notificationBtn">
        <i class="fas fa-bell"></i> Notifications <span id="notificationBadge" class="badge badge-hidden"></span>
    </button>
    <button class="glass-btn" onclick="openReminderSettings()">
        <i class="fas fa-clock"></i> Reminders
    </button>
    <button class="glass-btn" onclick="exportToGoogleCalendar()">
        <i class="fas fa-calendar-plus"></i> Export to Google Calendar
    </button>
</div>

<!-- Smart Suggestions Panel -->
<div id="suggestionsPanel" class="glass-panel panel-p15 mb-15 panel-hidden">
    <div class="panel-head-row">
        <h3 class="title-zero"><i class="fas fa-lightbulb"></i> AI-Powered Scheduling Suggestions</h3>
        <button class="glass-btn small" onclick="document.getElementById('suggestionsPanel').style.display='none'">
            <i class="fas fa-times"></i>
        </button>
    </div>
    <p class="muted-mb10">
        Based on your priorities, productivity patterns, and schedule constraints
    </p>
    <div id="suggestionsContent">
        <div class="center-pad20 muted-text">
            Loading suggestions...
        </div>
    </div>
</div>

<div class="glass-panel panel-p15 mb-15">
    <h3 class="title-top-zero"><i class="fa-solid fa-plus"></i> Add Personal Event</h3>
    <form method="POST" class="event-form-grid">
        <input type="hidden" name="action" value="add_event">

        <div class="col-span-2 minw-220">
            <label class="stat-label form-label-sm">Title *</label>
            <input type="text" name="title" class="glass-input" maxlength="120" required
                placeholder="e.g., Library Study Block">
        </div>

        <div>
            <label class="stat-label form-label-sm">Day *</label>
            <select name="day" class="glass-input" required>
                <option value="">Select Day</option>
                <?php foreach ($days as $d): ?>
                <option value="<?php echo htmlspecialchars($d); ?>">
                    <?php echo htmlspecialchars($d); ?>
                </option>
                <?php
endforeach; ?>
            </select>
        </div>

        <div>
            <label class="stat-label form-label-sm">Type</label>
            <select name="event_type" class="glass-input">
                <?php foreach ($event_types as $t): ?>
                <option value="<?php echo htmlspecialchars($t); ?>">
                    <?php echo ucfirst(htmlspecialchars($t)); ?>
                </option>
                <?php
endforeach; ?>
            </select>
        </div>

        <div>
            <label class="stat-label form-label-sm">Priority (optional)</label>
            <select name="priority_id" class="glass-input">
                <option value="">Select Priority</option>
                <?php foreach ($priority_options as $p): ?>
                <option value="<?php echo (int)$p['id']; ?>">
                    <?php echo htmlspecialchars($p['priority_name']); ?> (
                    <?php echo htmlspecialchars(ucfirst($p['priority_level'])); ?>)
                </option>
                <?php
endforeach; ?>
            </select>
        </div>

        <div>
            <label class="stat-label form-label-sm">Goal (optional)</label>
            <select name="goal_id" class="glass-input">
                <option value="">Select Goal</option>
                <?php foreach ($goal_options as $g): ?>
                <option value="<?php echo (int)$g['id']; ?>">
                    <?php echo htmlspecialchars($g['goal_title']); ?> (
                    <?php echo htmlspecialchars(ucfirst($g['priority_level'])); ?>)
                </option>
                <?php
endforeach; ?>
            </select>
        </div>

        <div>
            <label class="stat-label form-label-sm">Start *</label>
            <input type="time" name="start_time" class="glass-input" required>
        </div>

        <div>
            <label class="stat-label form-label-sm">End *</label>
            <input type="time" name="end_time" class="glass-input" required>
        </div>

        <div>
            <label class="stat-label form-label-sm">Color</label>
            <input type="color" name="color" class="glass-input color-input-44" value="#6366f1">
        </div>

        <div class="col-full">
            <label class="stat-label form-label-sm">Description</label>
            <textarea name="description" class="glass-input" rows="2" placeholder="Optional notes..."></textarea>
        </div>

        <?php if (empty($priority_options) && empty($goal_options)): ?>
        <div class="col-full muted-small">
            You can add this event without priority/goal, or create them first to tag events.
        </div>
        <?php
endif; ?>

        <div class="col-full row-end">
            <button type="submit" class="glass-btn primary">
                <i class="fa-solid fa-plus"></i> Add Event
            </button>
        </div>
    </form>
</div>

<div class="glass-panel panel-p15">
    <h3 class="title-top-zero"><i class="fa-solid fa-calendar-week"></i> Weekly Overview</h3>

    <?php if (empty($events)): ?>
    <div class="center-pad2rem muted-text">
        <i class="fa-solid fa-calendar-xmark icon-empty-state"></i>
        <p class="m0">No personal events yet. Add your first event above.</p>
    </div>
    <?php
else: ?>
    <div class="schedule-grid">
        <?php foreach ($days as $day): ?>
        <div class="day-card">
            <h4 class="day-title">
                <?php echo htmlspecialchars($day); ?>
            </h4>

            <?php if (empty($events_by_day[$day])): ?>
            <p class="m0 muted-09">No events</p>
            <?php
        else: ?>
            <?php foreach ($events_by_day[$day] as $event): ?>
            <div class="event-item event-item-bg"
                style="border-left: 4px solid <?php echo htmlspecialchars($event['color'] ?: '#6366f1'); ?>;">
                <div class="event-item-row">
                    <div class="event-main-wrap">
                        <div class="event-title">
                            <?php echo htmlspecialchars($event['title']); ?>
                        </div>
                        <div class="event-time-row">
                            <i class="fa-regular fa-clock"></i>
                            <?php echo htmlspecialchars(substr($event['start_time'], 0, 5)); ?> -
                            <?php echo htmlspecialchars(substr($event['end_time'], 0, 5)); ?>
                        </div>
                        <div class="event-type-row">
                            <?php echo htmlspecialchars($event['event_type']); ?>
                        </div>
                        <?php if (!empty($event['priority_name']) || !empty($event['goal_title'])): ?>
                        <div class="event-meta-row">
                            <?php if (!empty($event['priority_name'])): ?>
                            <span>Priority:
                                <?php echo htmlspecialchars($event['priority_name']); ?>
                            </span>
                            <?php
                    endif; ?>
                            <?php if (!empty($event['goal_title'])): ?>
                            <span>
                                <?php echo !empty($event['priority_name']) ? ' | ' : ''; ?>Goal:
                                <?php echo htmlspecialchars($event['goal_title']); ?>
                            </span>
                            <?php
                    endif; ?>
                        </div>
                        <?php
                endif; ?>
                        <?php if (!empty($event['description'])): ?>
                        <div class="event-desc-row">
                            <?php echo htmlspecialchars($event['description']); ?>
                        </div>
                        <?php
                endif; ?>
                    </div>

                    <form method="POST" onsubmit="event.preventDefault(); showConfirm('Delete this event?', 'Delete Event').then(result => { if(result) this.submit(); });" class="delete-form-reset">
                        <input type="hidden" name="action" value="delete_event">
                        <input type="hidden" name="event_id" value="<?php echo (int)$event['id']; ?>">
                        <button type="submit" class="glass-btn small delete-btn"
                            title="Delete event">
                            <i class="fa-solid fa-trash"></i>
                        </button>
                    </form>
                </div>
            </div>
            <?php
            endforeach; ?>
            <?php
        endif; ?>
        </div>
        <?php
    endforeach; ?>
    </div>
    <?php
endif; ?>
</div>

<div class="glass-panel panel-p15 mt-15">
    <div class="row-between-wrap mb-06">
        <h3 class="m0"><i class="fa-solid fa-clock"></i> Recommended Free Time (AI-ranked from class timetable)</h3>
    </div>

    <?php if (empty($free_time_suggestions)): ?>
    <p class="m0 muted-text">
        <?php echo $is_lecturer
        ? 'No free time slots detected yet. Assign lectures to build your timetable.'
        : 'No free time slots detected yet. Enroll in courses to build your timetable.'; ?>
    </p>
    <?php
else: ?>
    <?php foreach ($days as $day): ?>
    <?php
    $day_slots = array_values(array_filter($free_time_suggestions, static function ($slot) use ($day) {
        return ($slot['day'] ?? '') === $day;
    }));
    if (empty($day_slots)) {
        continue;
    }
    ?>
    <div class="rec-card">
        <div style="font-weight:700; margin-bottom:0.45rem;"><?php echo htmlspecialchars($day); ?></div>
        <?php foreach ($day_slots as $index => $slot): ?>
        <div class="row-between-wrap">
            <div>
                <div class="free-slot-time">
                    <?php
        $start_hr = floor(($slot['start'] ?? 0) / 60);
        $start_min = ($slot['start'] ?? 0) % 60;
        $end_hr = floor(($slot['end'] ?? 0) / 60);
        $end_min = ($slot['end'] ?? 0) % 60;
        $start_label = sprintf('%02d:%02d', $start_hr, $start_min);
        $end_label = sprintf('%02d:%02d', $end_hr, $end_min);
?>
                    Free:
                    <?php echo $start_label . ' - ' . $end_label; ?>
                </div>
            </div>
            <div class="text-right">
                <div class="duration-accent">Duration:
                    <?php echo number_format(($slot['duration'] ?? 0) / 60, 1); ?> hrs
                </div>
                <div class="free-slot-ok" style="font-weight:600;">
                    <i class="fa-solid fa-brain"></i> AI Score: <?php echo number_format((float)($slot['ai_score'] ?? 0), 1); ?>
                </div>
                <div class="free-slot-ok"><i class="fa-solid fa-check"></i> No classes in this
                    block</div>
            </div>
        </div>
        <?php if (!empty($slot['reason'])): ?>
        <div class="muted-086" style="margin-top: 0.2rem;">
            <?php echo htmlspecialchars($slot['reason']); ?>
        </div>
        <?php endif; ?>
        <?php if ($index < count($day_slots) - 1): ?>
        <div style="height:1px; background:rgba(255,255,255,0.08); margin:0.45rem 0;"></div>
        <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <?php
    endforeach; ?>
    <?php
endif; ?>
</div>

<div id="generated-weekly" class="glass-panel panel-p15 mt-15">
    <div class="row-between-wrap mb-08">
        <h3 class="m0"><i class="fa-solid fa-table-cells"></i> Generated Weekly Timetable</h3>
        <div class="muted-086">
            Total entries: <strong>
                <?php echo count($weekly_rows); ?>
            </strong>
            <?php if ($conflict_count > 0): ?>
            &nbsp;|&nbsp; <span class="conflict-pill"><i class="fa-solid fa-triangle-exclamation"></i> Conflicts:
                <?php echo (int)$conflict_count; ?>
            </span>
            <?php
endif; ?>
        </div>
    </div>

    <form method="GET" class="table-tools-row" style="margin-bottom: 0.65rem;">
        <select name="saved_at" class="glass-input maxw-260">
            <option value="">Latest saved schedules</option>
            <?php foreach ($available_snapshots as $snapshot): ?>
            <?php $snapshot_created_at = (string)($snapshot['created_at'] ?? ''); ?>
            <option value="<?php echo htmlspecialchars($snapshot_created_at); ?>" <?php echo ($saved_at_selection !== '' && $saved_at_selection === $snapshot_created_at) ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars(($snapshot_created_at !== '' ? $snapshot_created_at : 'Unknown time') . ' • ' . (($snapshot['department'] ?? '') ?: 'General') . ' • ' . (($snapshot['schedule_name'] ?? '') ?: 'Unnamed')); ?>
            </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="glass-btn small"><i class="fa-solid fa-filter"></i> Load by saved time</button>
        <a href="my_schedule.php#generated-weekly" class="glass-btn small secondary"><i class="fa-solid fa-rotate-left"></i> Reset to Latest</a>
        <?php if ($active_saved_at !== ''): ?>
        <div class="muted-086" style="display:flex; align-items:center;">
            <i class="fa-solid fa-clock"></i>&nbsp;Active snapshot: <?php echo htmlspecialchars($active_saved_at); ?>
        </div>
        <?php endif; ?>
    </form>

    <?php if (empty($weekly_rows)): ?>
    <p class="muted-text m0">
        <?php echo $is_lecturer
        ? 'No timetable entries yet. Assign lectures and/or add personal events.'
        : 'No timetable entries yet. Enroll in courses and/or add personal events.'; ?>
    </p>
    <?php
else: ?>
    <div class="table-tools-row">
        <input type="text" class="glass-input table-search maxw-260" data-target-table="weekly-table"
            placeholder="Search timetable...">
        <select class="glass-input table-page-size maxw-160" data-target-table="weekly-table">
            <option value="5">5 / page</option>
            <option value="10" selected>10 / page</option>
            <option value="20">20 / page</option>
            <option value="50">50 / page</option>
        </select>
        <div class="table-pagination" data-target-table="weekly-table"></div>
    </div>
    <div class="table-wrap">
        <table class="table-modern" id="weekly-table">
            <thead>
                <tr>
                    <th>Day</th>
                    <th>Time</th>
                    <th>Type</th>
                    <th>Item</th>
                    <th>Details</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($weekly_rows as $row): ?>
                <tr>
                    <td>
                        <?php echo htmlspecialchars($row['day']); ?>
                    </td>
                    <td>
                        <?php echo htmlspecialchars($row['time']); ?>
                    </td>
                    <td>
                        <span
                            class="type-pill <?php echo $row['type'] === 'class' ? 'type-class' : 'type-personal'; ?>">
                            <?php echo ucfirst(htmlspecialchars($row['type'])); ?>
                        </span>
                    </td>
                    <td>
                        <strong>
                            <?php echo htmlspecialchars($row['title']); ?>
                        </strong>
                        <?php if (!empty($row['lecturer'])): ?>
                        <div class="lecturer-meta">Lecturer:
                            <?php echo htmlspecialchars($row['lecturer']); ?>
                        </div>
                        <?php
        endif; ?>
                    </td>
                    <td>
                        <?php echo htmlspecialchars($row['details'] ?: '-'); ?>
                    </td>
                    <td>
                        <?php if (!empty($row['conflict'])): ?>
                        <span class="conflict-pill"><i class="fa-solid fa-triangle-exclamation"></i> Conflict</span>
                        <?php
        else: ?>
                        <span class="status-ok"><i class="fa-solid fa-check"></i> OK</span>
                        <?php
        endif; ?>
                    </td>
                </tr>
                <?php
    endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
endif; ?>
</div>


<script>
    const initTableTools = () => {
        const tables = document.querySelectorAll('table.table-modern[id]');
        tables.forEach((table) => {
            const tableId = table.getAttribute('id');
            const searchInput = document.querySelector(`.table-search[data-target-table="${tableId}"]`);
            const pageSizeSelect = document.querySelector(`.table-page-size[data-target-table="${tableId}"]`);
            const pager = document.querySelector(`.table-pagination[data-target-table="${tableId}"]`);
            const tbody = table.querySelector('tbody');
            if (!tbody || !pager) return;

            let currentPage = 1;
            let pageSize = parseInt(pageSizeSelect?.value || '10', 10);

            const getRows = () => Array.from(tbody.querySelectorAll('tr'));

            const filterRows = (rows) => {
                const term = (searchInput?.value || '').toLowerCase().trim();
                if (!term) return rows;
                return rows.filter((row) => row.textContent.toLowerCase().includes(term));
            };

            const renderPager = (totalPages) => {
                pager.innerHTML = '';
                if (totalPages <= 1) return;

                const createBtn = (label, page, disabled = false) => {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'glass-btn small';
                    btn.textContent = label;
                    btn.disabled = disabled;
                    btn.addEventListener('click', () => {
                        currentPage = page;
                        render();
                    });
                    return btn;
                };

                pager.appendChild(createBtn('Prev', Math.max(1, currentPage - 1), currentPage === 1));
                for (let p = 1; p <= totalPages; p++) {
                    const btn = createBtn(String(p), p, p === currentPage);
                    if (p === currentPage) btn.style.opacity = '0.6';
                    pager.appendChild(btn);
                }
                pager.appendChild(createBtn('Next', Math.min(totalPages, currentPage + 1), currentPage === totalPages));
            };

            const render = () => {
                const rows = filterRows(getRows());
                const totalPages = Math.max(1, Math.ceil(rows.length / pageSize));
                if (currentPage > totalPages) currentPage = totalPages;

                rows.forEach((row, index) => {
                    const start = (currentPage - 1) * pageSize;
                    const end = start + pageSize;
                    row.style.display = index >= start && index < end ? '' : 'none';
                });

                getRows().forEach((row) => {
                    if (!rows.includes(row)) row.style.display = 'none';
                });

                renderPager(totalPages);
            };

            searchInput?.addEventListener('input', () => {
                currentPage = 1;
                render();
            });

            pageSizeSelect?.addEventListener('change', () => {
                pageSize = parseInt(pageSizeSelect.value, 10) || 10;
                currentPage = 1;
                render();
            });

            render();
        });
    };

    // ============================================================================
    // NEW FEATURES: Smart Suggestions, Priorities, Notifications
    // ============================================================================

    async function loadSmartSuggestions() {
        const panel = document.getElementById('suggestionsPanel');
        const content = document.getElementById('suggestionsContent');
        panel.style.display = 'block';

        try {
            const response = await fetch('api/smart_suggestions.php?action=get_suggestions');
            const data = await response.json();

            if (data.success && data.suggestions.length > 0) {
                let html = '<div class="suggestions-grid">';
                data.suggestions.forEach(s => {
                    const score = (parseFloat(s.priority_score) + parseFloat(s.productivity_score)).toFixed(1);
                    html += `
                    <div class="rec-card">
                        <div class="suggestion-head-row">
                            <div>
                                <strong class="suggestion-title">${s.day} ${s.start_time} - ${s.end_time}</strong>
                                <div class="suggestion-meta-line">
                                    ${s.duration_minutes} minutes • Score: ${score}
                                </div>
                            </div>
                            <span class="type-pill type-suggestion">${s.suggestion_type.replace('_', ' ')}</span>
                        </div>
                        <div class="suggestion-reason">
                            ${s.reason || 'Recommended slot'}
                        </div>
                        <div class="suggestion-actions">
                            <button class="glass-btn small" onclick="acceptSuggestion(${s.id})">
                                <i class="fas fa-check"></i> Accept
                            </button>
                            <button class="glass-btn small" onclick="rejectSuggestion(${s.id})">
                                <i class="fas fa-times"></i> Reject
                            </button>
                        </div>
                    </div>
                `;
                });
                html += '</div>';
                content.innerHTML = html;
            } else {
                content.innerHTML = `
                <div class="center-pad20 muted-text">
                    <i class="fas fa-calendar-check icon-suggestion-empty"></i>
                    <p>No suggestions available. Start logging tasks to improve recommendations!</p>
                    <button class="glass-btn" onclick="generateNewSuggestions()">Generate Suggestions</button>
                </div>
            `;
            }
        } catch (error) {
            content.innerHTML = '<div class="error-pad20">Failed to load suggestions</div>';
        }
    }

    async function acceptSuggestion(id) {
        try {
            const response = await fetch('api/smart_suggestions.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=accept_suggestion&suggestion_id=${id}`
            });
            const data = await response.json();
            if (data.success) {
                await showAlert('Suggestion accepted! Your preferences have been updated.', 'Success');
                loadSmartSuggestions();
            }
        } catch (error) {
            await showAlert('Failed to accept suggestion', 'Error');
        }
    }

    async function rejectSuggestion(id) {
        try {
            const response = await fetch('api/smart_suggestions.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=reject_suggestion&suggestion_id=${id}`
            });
            const data = await response.json();
            if (data.success) {
                loadSmartSuggestions();
            }
        } catch (error) {
            await showAlert('Failed to reject suggestion', 'Error');
        }
    }

    async function generateNewSuggestions() {
        try {
            const response = await fetch('api/smart_suggestions.php?action=generate_suggestions');
            const data = await response.json();
            if (data.success) {
                loadSmartSuggestions();
            }
        } catch (error) {
            await showAlert('Failed to generate suggestions', 'Error');
        }
    }

    function openPrioritiesModal() {
        window.location.href = 'priorities_goals.php';
    }

    async function openNotificationsPanel() {
        window.location.href = 'notifications.php';
    }

    function openReminderSettings() {
        window.location.href = 'reminder_settings.php';
    }

    async function exportToGoogleCalendar() {
        try {
            const response = await fetch('api/export_calendar.php?type=google&week=0');
            const data = await response.json();

            const links = Array.isArray(data.event_links) ? data.event_links : [];

            if (data.success && links.length > 0) {
                openGoogleExportNavigator(links);
            } else if (data.success && data.url) {
                window.open(data.url, '_blank');
            } else {
                await showAlert('Export failed: ' + (data.error || 'Unknown error'), 'Error');
            }
        } catch (error) {
            await showAlert('Failed to export calendar: ' + error.message, 'Error');
        }
    }

    function openGoogleExportNavigator(links) {
        const safeText = (value) => {
            const div = document.createElement('div');
            div.textContent = value == null ? '' : String(value);
            return div.innerHTML;
        };

        const container = document.getElementById('customModalContainer') || document.body;
        const modal = document.createElement('div');
        modal.className = 'custom-modal-overlay';

        let index = 0;
        modal.innerHTML = `
            <div class="custom-modal glass-panel" style="max-width: 560px; width: 92%;">
                <div class="custom-modal-header">
                    <h3 style="margin: 0; display: flex; align-items: center; gap: 10px;">
                        <i class="fas fa-calendar-plus" style="color: var(--primary-color);"></i>
                        Google Calendar Export
                    </h3>
                </div>
                <div class="custom-modal-body" style="max-height: 70vh; overflow-y: auto;">
                    <p style="margin: 0 0 0.8rem 0; color: var(--text-muted);">
                        Use Next/Previous to review events, then click Open Event to add each to Google Calendar.
                    </p>
                    <div id="gcalExportCounter" style="font-weight: 600; margin-bottom: 0.5rem;"></div>
                    <div id="gcalExportTitle" style="font-size: 1rem; font-weight: 700; margin-bottom: 0.35rem;"></div>
                    <div id="gcalExportTime" style="color: var(--text-muted);"></div>
                </div>
                <div class="custom-modal-footer" style="display:flex; gap:0.5rem; flex-wrap: wrap; justify-content: space-between;">
                    <div style="display:flex; gap:0.5rem;">
                        <button class="glass-btn secondary" id="gcalPrevBtn"><i class="fa-solid fa-chevron-left"></i> Previous</button>
                        <button class="glass-btn secondary" id="gcalNextBtn">Next <i class="fa-solid fa-chevron-right"></i></button>
                    </div>
                    <div style="display:flex; gap:0.5rem;">
                        <button class="glass-btn" id="gcalOpenBtn"><i class="fa-solid fa-up-right-from-square"></i> Open Event</button>
                        <button class="glass-btn secondary" id="gcalCloseBtn"><i class="fa-solid fa-times"></i> Close</button>
                    </div>
                </div>
            </div>
        `;

        container.appendChild(modal);

        const counterEl = modal.querySelector('#gcalExportCounter');
        const titleEl = modal.querySelector('#gcalExportTitle');
        const timeEl = modal.querySelector('#gcalExportTime');
        const prevBtn = modal.querySelector('#gcalPrevBtn');
        const nextBtn = modal.querySelector('#gcalNextBtn');
        const openBtn = modal.querySelector('#gcalOpenBtn');
        const closeBtn = modal.querySelector('#gcalCloseBtn');

        const closeModal = () => {
            modal.style.opacity = '0';
            const dialog = modal.querySelector('.custom-modal');
            if (dialog) {
                dialog.style.transform = 'scale(0.95)';
            }
            setTimeout(() => modal.remove(), 180);
        };

        const render = () => {
            const evt = links[index];
            counterEl.textContent = `Event ${index + 1} of ${links.length}`;
            titleEl.innerHTML = safeText(evt.title || 'Untitled event');
            timeEl.innerHTML = safeText(`${evt.date || ''}  ${evt.start_time || ''} - ${evt.end_time || ''}`);
            prevBtn.disabled = index === 0;
            nextBtn.disabled = index === links.length - 1;
        };

        prevBtn.addEventListener('click', () => {
            if (index > 0) {
                index -= 1;
                render();
            }
        });

        nextBtn.addEventListener('click', () => {
            if (index < links.length - 1) {
                index += 1;
                render();
            }
        });

        openBtn.addEventListener('click', () => {
            const evt = links[index];
            if (evt && evt.url) {
                window.open(evt.url, '_blank');
            }
        });

        closeBtn.addEventListener('click', closeModal);
        modal.addEventListener('click', (e) => {
            if (e.target === modal) closeModal();
        });

        render();
    }

    async function loadNotificationCount() {
        try {
            const response = await fetch('api/notifications.php?action=list&unread_only=true');
            const data = await response.json();
            if (data.success && data.unread_count > 0) {
                const badge = document.getElementById('notificationBadge');
                badge.textContent = data.unread_count;
                badge.style.display = 'inline-block';
                badge.style.background = '#ef4444';
                badge.style.color = 'white';
                badge.style.borderRadius = '999px';
                badge.style.padding = '2px 6px';
                badge.style.fontSize = '11px';
                badge.style.marginLeft = '5px';
            }
        } catch (error) {
            console.error('Failed to load notification count');
        }
    }

    // Initialize on page load
    document.addEventListener('DOMContentLoaded', () => {
        initTableTools();
        loadNotificationCount();

        // Auto-refresh notifications every 60 seconds
        setInterval(loadNotificationCount, 60000);
    });
</script>

<?php include 'includes/footer.php'; ?>