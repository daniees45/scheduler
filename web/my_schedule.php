<?php
$page_title = 'My Weekly Schedule';
include 'includes/header.php';
require_once 'api/db.php';

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

$days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
$event_types = ['study', 'work', 'personal', 'exercise', 'rest', 'other'];

$column_exists = static function (mysqli $conn, string $table, string $column): bool {
    $stmt = $conn->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
    if (!$stmt) return false;
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
        } elseif (!preg_match('/^\d{2}:\d{2}$/', $start_time) || !preg_match('/^\d{2}:\d{2}$/', $end_time)) {
            $message = 'Invalid time format.';
            $message_type = 'error';
        } elseif (strtotime($end_time) <= strtotime($start_time)) {
            $message = 'End time must be later than start time.';
            $message_type = 'error';
        } elseif ($priority_id === null && $goal_id === null) {
            $message = 'Select a priority or goal for this event.';
            $message_type = 'error';
        } else {
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
            } else {
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
                } else {
                    $insert_sql = "INSERT INTO personal_events
                        (user_id, title, description, day, start_time, end_time, event_type, color, priority_id, goal_id)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    $insert_stmt = $conn->prepare($insert_sql);
                    if ($insert_stmt) {
                        $insert_stmt->bind_param('isssssssii', $user_id, $title, $description, $day, $start_time, $end_time, $event_type, $color, $priority_id, $goal_id);
                        if ($insert_stmt->execute()) {
                            $message = 'Personal event added successfully.';
                            $message_type = 'success';
                        } else {
                            $message = 'Unable to add event right now. Please try again.';
                            $message_type = 'error';
                        }
                    } else {
                        $message = 'Unable to prepare event insert.';
                        $message_type = 'error';
                    }
                }
            } else {
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
                } else {
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
    if ($ts === false) return null;
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
    if ($raw === '') return [null, null, ''];

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
    } else {
        $account_warning = 'Your account is not linked to a lecturer profile. Please contact an administrator.';
    }
} else {
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
                } else {
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

    if (preg_match('/(COSC|INFT|BBIS|CSCD)/', $code)) return 'CS/IT/BBIS';
    if (preg_match('/(ACCT|BUSI|MGMT|ECON|MKTG|FNCE)/', $code)) return 'Business';
    if (preg_match('/(EDUC|PEDC|TEAC|CLED)/', $code)) return 'Education';
    if (preg_match('/(DEVS|INTL|AFRI|AFRN)/', $code)) return 'DevelopmentStudies';
    if (preg_match('/(BIOM|ENGR|BENG|HLTC)/', $code)) return 'BiomedicalEngineering';
    if (preg_match('/(NURS|RNSG|MIDW)/', $code)) return 'Nursing';
    if (preg_match('/(RELB|RELT)/', $code)) return 'Theology';

    return 'General';
};

$infer_department_from_codes = static function (array $codes) use ($get_department_from_code): string {
    if (empty($codes)) return '';

    $counts = [];
    foreach ($codes as $code) {
        $dept = $get_department_from_code($code);
        $counts[$dept] = ($counts[$dept] ?? 0) + 1;
    }

    arsort($counts);
    $top = array_key_first($counts);
    return $top ? (string)$top : '';
};

// Pull class slots from latest DB timetables (department + general)
$student_department = trim((string)($_SESSION['department'] ?? ''));
$schedule_department = $student_department;
if ($is_lecturer) {
    if ($lecturer_department === '') {
        if ($student_department !== '') {
            $lecturer_department = $student_department;
        } else {
            $lecturer_department = $infer_department_from_codes(array_keys($enrolled_codes));
        }
    }
    if ($lecturer_department === '') {
        $lecturer_department = 'General';
    }
    $schedule_department = $lecturer_department;
}
$db_schedule_sources = [];

$dept_sched_stmt = $conn->prepare("SELECT schedule_name, department, created_at, schedule_data
                                                                     FROM generated_schedules
                                                                     WHERE LOWER(TRIM(department)) = LOWER(TRIM(?))
                                                                         AND (schedule_name NOT LIKE 'exam_%' OR schedule_name IS NULL)
                                                                         AND (semester = ? OR semester IS NULL OR TRIM(semester) = '')
                                                                     ORDER BY created_at DESC LIMIT 1");
if ($dept_sched_stmt) {
        $dept_sched_stmt->bind_param('ss', $schedule_department, $semester);
    $dept_sched_stmt->execute();
    $rs = $dept_sched_stmt->get_result();
    if ($rs && ($row = $rs->fetch_assoc())) {
        $db_schedule_sources[] = ['type' => 'department', 'row' => $row];
    }
}

$gen_sched_stmt = $conn->prepare("SELECT schedule_name, department, created_at, schedule_data
                                                                    FROM generated_schedules
                                                                    WHERE (
                                                                                department IS NULL
                                                                                OR TRIM(department) = ''
                                                                                OR LOWER(TRIM(department)) = 'general'
                                                                    )
                                                                        AND (schedule_name NOT LIKE 'exam_%' OR schedule_name IS NULL)
                                                                        AND (semester = ? OR semester IS NULL OR TRIM(semester) = '')
                                                                    ORDER BY created_at DESC LIMIT 1");
if ($gen_sched_stmt) {
        $gen_sched_stmt->bind_param('s', $semester);
        $gen_sched_stmt->execute();
    $rs = $gen_sched_stmt->get_result();
    if ($rs && ($row = $rs->fetch_assoc())) {
        $db_schedule_sources[] = ['type' => 'general', 'row' => $row];
    }
}

$extract_rows_from_schedule_data = static function ($schedule_data): array {
    $rows_out = [];
    $decoded = json_decode((string)$schedule_data, true);

    if (is_array($decoded) && !empty($decoded)) {
        $first = $decoded[0] ?? null;
        if (is_array($first) && isset($first[0]) && is_string($first[0])) {
            $headers = array_map(static function ($h) {
                return strtolower(trim((string)$h, "\" "));
            }, $first);

            for ($i = 1; $i < count($decoded); $i++) {
                if (!is_array($decoded[$i])) continue;
                $assoc = [];
                foreach ($headers as $idx => $h) {
                    $assoc[$h] = $decoded[$i][$idx] ?? '';
                }
                $rows_out[] = array_change_key_case($assoc, CASE_LOWER);
            }
        }
    }

    return $rows_out;
};

if (($is_lecturer && $lecturer_name !== '') || (!$is_lecturer && !empty($enrolled_codes))) {
    foreach ($db_schedule_sources as $source) {
        $rows = $extract_rows_from_schedule_data($source['row']['schedule_data'] ?? '');
        foreach ($rows as $row) {
            $code = strtoupper(trim((string)($row['course code'] ?? ($row['course'] ?? ($row['code'] ?? '')))));
            $day = trim((string)($row['day'] ?? ''));
            $time_range = trim((string)($row['time'] ?? ''));

            if ($code === '' || !isset($day_order[$day])) continue;

            if (!$is_lecturer && !isset($enrolled_codes[$code])) continue;

            if ($is_lecturer && $lecturer_name !== '') {
                $row_lecturer = trim((string)($row['lecturer name'] ?? ($row['lecturer'] ?? '')));
                if ($row_lecturer !== '' && strcasecmp($row_lecturer, $lecturer_name) !== 0) {
                    continue;
                }
            }

            if (!$is_lecturer) {
                $row_section = $extract_section(($row['section'] ?? '') . ' ' . ($row['course title'] ?? '') . ' ' . ($row['title'] ?? '') . ' ' . ($row['class'] ?? '') . ' ' . ($row['group'] ?? ''));
                $enrolled_section = $enrolled_sections[$code] ?? '';

                if ($enrolled_section !== '' && $row_section !== '' && $row_section !== $enrolled_section) {
                    continue;
                }

                if ($enrolled_section === '' && $row_section !== '') {
                    if (!isset($selected_section_by_code[$code])) {
                        $selected_section_by_code[$code] = $row_section;
                    } elseif ($selected_section_by_code[$code] !== $row_section) {
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
                'title' => $code . ' - ' . trim((string)($row['course title'] ?? ($row['title'] ?? ($enrolled_meta[$code]['course_title'] ?? 'Class')))),
                'details' => trim((string)($row['room name'] ?? ($row['room'] ?? ''))),
                'lecturer' => trim((string)($row['lecturer name'] ?? ($row['lecturer'] ?? ($enrolled_meta[$code]['lecturer_name'] ?? '')))),
                'conflict' => false
            ];
        }
    }
}

// Add personal events to same timeline
foreach ($events as $ev) {
    $day = trim((string)($ev['day'] ?? ''));
    if (!isset($day_order[$day])) continue;
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
        if ($weekly_rows[$i]['type'] === 'class') $class_idxs[] = $i;
        if ($weekly_rows[$i]['type'] === 'personal') $personal_idxs[] = $i;
    }

    foreach ($class_idxs as $ci) {
        foreach ($personal_idxs as $pi) {
            $a1 = $weekly_rows[$ci]['start'];
            $a2 = $weekly_rows[$ci]['end'];
            $b1 = $weekly_rows[$pi]['start'];
            $b2 = $weekly_rows[$pi]['end'];

            if ($a1 === null || $a2 === null || $b1 === null || $b2 === null) continue;

            $overlap = !($a2 <= $b1 || $a1 >= $b2);
            if ($overlap) {
                if ($weekly_rows[$ci]['conflict'] === false) $conflict_count++;
                if ($weekly_rows[$pi]['conflict'] === false) $conflict_count++;
                $weekly_rows[$ci]['conflict'] = true;
                $weekly_rows[$pi]['conflict'] = true;
            }
        }
    }
}

usort($weekly_rows, static function ($a, $b) use ($day_order) {
    $da = $day_order[$a['day']] ?? 99;
    $db = $day_order[$b['day']] ?? 99;
    if ($da !== $db) return $da <=> $db;
    return ($a['start'] ?? 9999) <=> ($b['start'] ?? 9999);
});

// Recommend free time based on class timetable
$free_time_suggestions = [];
$default_day_start = 7 * 60;
$default_day_end = 21 * 60;

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
        if ($start === null || $end === null) continue;

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
        usort($day_free_slots, static function ($a, $b) {
            return ($b['duration'] ?? 0) <=> ($a['duration'] ?? 0);
        });
        $free_time_suggestions[] = $day_free_slots[0];
    }
}

// Keep one recommendation per day in week order
usort($free_time_suggestions, static function ($a, $b) use ($day_order) {
    $da = $day_order[$a['day']] ?? 99;
    $db = $day_order[$b['day']] ?? 99;
    if ($da !== $db) return $da <=> $db;
    return ($b['duration'] ?? 0) <=> ($a['duration'] ?? 0);
});
?>

<style>
.alert { padding: 1rem; border-radius: 0.5rem; margin-bottom: 1rem; }
.alert-success { background: rgba(16, 185, 129, 0.12); border-left: 4px solid #10b981; color: #10b981; }
.alert-error { background: rgba(239, 68, 68, 0.12); border-left: 4px solid #ef4444; color: #ef4444; }
.alert-warning { background: rgba(245, 158, 11, 0.12); border-left: 4px solid #f59e0b; color: #f59e0b; }

.schedule-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 1rem;
}

.day-card {
    background: rgba(15, 23, 42, 0.55);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 0.75rem;
    padding: 1rem;
    min-height: 220px;
}

.event-item {
    border-radius: 0.55rem;
    border: 1px solid rgba(255,255,255,0.08);
    padding: 0.75rem;
    margin-bottom: 0.65rem;
}

.event-item:last-child { margin-bottom: 0; }

.table-wrap { overflow-x: auto; }
.table-modern { width: 100%; border-collapse: collapse; font-size: 0.92rem; }
.table-modern th, .table-modern td { padding: 0.8rem; border-bottom: 1px solid rgba(255,255,255,0.08); text-align: left; }
.table-modern th { color: var(--text-muted); font-weight: 600; }
.table-modern tbody tr:hover { background: rgba(255,255,255,0.03); }
.type-pill { padding: 0.2rem 0.55rem; border-radius: 999px; font-size: 0.75rem; font-weight: 600; display: inline-block; }
.type-class { background: rgba(99,102,241,0.2); color: #a5b4fc; }
.type-personal { background: rgba(16,185,129,0.2); color: #6ee7b7; }
.conflict-pill { background: rgba(239,68,68,0.2); color: #fca5a5; padding: 0.2rem 0.45rem; border-radius: 999px; font-size: 0.72rem; }
.rec-card { background: rgba(14,165,233,0.08); border: 1px solid rgba(14,165,233,0.28); border-radius: 0.6rem; padding: 0.85rem; margin-bottom: 0.6rem; }
</style>

<?php if (!empty($account_warning)): ?>
    <div class="alert alert-warning">
        <?php echo htmlspecialchars($account_warning); ?>
    </div>
<?php endif; ?>

<?php if (!empty($message)): ?>
    <div class="alert alert-<?php echo htmlspecialchars($message_type); ?>">
        <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<div class="glass-panel" style="padding: 1.5rem; margin-bottom: 1.5rem;">
    <h3 style="margin: 0 0 0.4rem 0;"><i class="fa-solid fa-calendar-user"></i> Personal Weekly Schedule</h3>
    <p style="margin: 0; color: var(--text-muted);">
        Add your weekly commitments (study, work, personal time, rest). Events added: <strong><?php echo $event_count; ?></strong>
    </p>
</div>

<!-- Quick Actions Panel -->
<div class="glass-panel" style="padding: 1.2rem; margin-bottom: 1.5rem; display: flex; gap: 12px; flex-wrap: wrap; align-items: center;">
    <a href="productivity_analytics.php" class="glass-btn" style="text-decoration: none;">
        <i class="fas fa-chart-line"></i> Analytics
    </a>
    <button class="glass-btn" onclick="loadSmartSuggestions()">
        <i class="fas fa-brain"></i> AI Suggestions
    </button>
    <button class="glass-btn" onclick="openPrioritiesModal()">
        <i class="fas fa-star"></i> Priorities & Goals
    </button>
    <button class="glass-btn" onclick="openNotificationsPanel()" id="notificationBtn">
        <i class="fas fa-bell"></i> Notifications <span id="notificationBadge" class="badge" style="display:none;"></span>
    </button>
    <button class="glass-btn" onclick="openReminderSettings()">
        <i class="fas fa-clock"></i> Reminders
    </button>
    <button class="glass-btn" onclick="exportToGoogleCalendar()">
        <i class="fas fa-calendar-plus"></i> Export to Google Calendar
    </button>
</div>

<!-- Smart Suggestions Panel -->
<div id="suggestionsPanel" class="glass-panel" style="padding: 1.5rem; margin-bottom: 1.5rem; display: none;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
        <h3 style="margin: 0;"><i class="fas fa-lightbulb"></i> AI-Powered Scheduling Suggestions</h3>
        <button class="glass-btn small" onclick="document.getElementById('suggestionsPanel').style.display='none'">
            <i class="fas fa-times"></i>
        </button>
    </div>
    <p style="color: var(--text-muted); margin-bottom: 1rem;">
        Based on your priorities, productivity patterns, and schedule constraints
    </p>
    <div id="suggestionsContent">
        <div style="text-align: center; padding: 20px; color: var(--text-muted);">
            Loading suggestions...
        </div>
    </div>
</div>

<div class="glass-panel" style="padding: 1.5rem; margin-bottom: 1.5rem;">
    <h3 style="margin-top: 0;"><i class="fa-solid fa-plus"></i> Add Personal Event</h3>
    <form method="POST" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.8rem;">
        <input type="hidden" name="action" value="add_event">

        <div style="grid-column: span 2; min-width: 220px;">
            <label class="stat-label" style="font-size: 0.82rem;">Title *</label>
            <input type="text" name="title" class="glass-input" maxlength="120" required placeholder="e.g., Library Study Block">
        </div>

        <div>
            <label class="stat-label" style="font-size: 0.82rem;">Day *</label>
            <select name="day" class="glass-input" required>
                <option value="">Select Day</option>
                <?php foreach ($days as $d): ?>
                    <option value="<?php echo htmlspecialchars($d); ?>"><?php echo htmlspecialchars($d); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="stat-label" style="font-size: 0.82rem;">Type</label>
            <select name="event_type" class="glass-input">
                <?php foreach ($event_types as $t): ?>
                    <option value="<?php echo htmlspecialchars($t); ?>"><?php echo ucfirst(htmlspecialchars($t)); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="stat-label" style="font-size: 0.82rem;">Priority (required if no goal)</label>
            <select name="priority_id" class="glass-input">
                <option value="">Select Priority</option>
                <?php foreach ($priority_options as $p): ?>
                    <option value="<?php echo (int)$p['id']; ?>">
                        <?php echo htmlspecialchars($p['priority_name']); ?> (<?php echo htmlspecialchars(ucfirst($p['priority_level'])); ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="stat-label" style="font-size: 0.82rem;">Goal (required if no priority)</label>
            <select name="goal_id" class="glass-input">
                <option value="">Select Goal</option>
                <?php foreach ($goal_options as $g): ?>
                    <option value="<?php echo (int)$g['id']; ?>">
                        <?php echo htmlspecialchars($g['goal_title']); ?> (<?php echo htmlspecialchars(ucfirst($g['priority_level'])); ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="stat-label" style="font-size: 0.82rem;">Start *</label>
            <input type="time" name="start_time" class="glass-input" required>
        </div>

        <div>
            <label class="stat-label" style="font-size: 0.82rem;">End *</label>
            <input type="time" name="end_time" class="glass-input" required>
        </div>

        <div>
            <label class="stat-label" style="font-size: 0.82rem;">Color</label>
            <input type="color" name="color" class="glass-input" value="#6366f1" style="height: 44px;">
        </div>

        <div style="grid-column: 1 / -1;">
            <label class="stat-label" style="font-size: 0.82rem;">Description</label>
            <textarea name="description" class="glass-input" rows="2" placeholder="Optional notes..."></textarea>
        </div>

        <?php if (empty($priority_options) && empty($goal_options)): ?>
            <div style="grid-column: 1 / -1; color: var(--text-muted); font-size: 0.82rem;">
                Add a priority or goal first to tag your events.
            </div>
        <?php endif; ?>

        <div style="grid-column: 1 / -1; display: flex; justify-content: flex-end;">
            <button type="submit" class="glass-btn primary">
                <i class="fa-solid fa-plus"></i> Add Event
            </button>
        </div>
    </form>
</div>

<div class="glass-panel" style="padding: 1.5rem;">
    <h3 style="margin-top: 0;"><i class="fa-solid fa-calendar-week"></i> Weekly Overview</h3>

    <?php if (empty($events)): ?>
        <div style="text-align: center; padding: 2rem; color: var(--text-muted);">
            <i class="fa-solid fa-calendar-xmark" style="font-size: 2rem; opacity: 0.5; margin-bottom: 0.6rem;"></i>
            <p style="margin: 0;">No personal events yet. Add your first event above.</p>
        </div>
    <?php else: ?>
        <div class="schedule-grid">
            <?php foreach ($days as $day): ?>
                <div class="day-card">
                    <h4 style="margin: 0 0 0.8rem 0; color: #a5b4fc;"><?php echo htmlspecialchars($day); ?></h4>

                    <?php if (empty($events_by_day[$day])): ?>
                        <p style="margin: 0; color: var(--text-muted); font-size: 0.9rem;">No events</p>
                    <?php else: ?>
                        <?php foreach ($events_by_day[$day] as $event): ?>
                            <div class="event-item" style="border-left: 4px solid <?php echo htmlspecialchars($event['color'] ?: '#6366f1'); ?>; background: rgba(255,255,255,0.02);">
                                <div style="display: flex; justify-content: space-between; gap: 0.5rem; align-items: flex-start;">
                                    <div style="min-width: 0;">
                                        <div style="font-weight: 700; margin-bottom: 0.2rem; word-break: break-word;">
                                            <?php echo htmlspecialchars($event['title']); ?>
                                        </div>
                                        <div style="font-size: 0.82rem; color: var(--text-muted); margin-bottom: 0.25rem;">
                                            <i class="fa-regular fa-clock"></i>
                                            <?php echo htmlspecialchars(substr($event['start_time'], 0, 5)); ?> - <?php echo htmlspecialchars(substr($event['end_time'], 0, 5)); ?>
                                        </div>
                                        <div style="font-size: 0.78rem; color: #c4b5fd; text-transform: capitalize;">
                                            <?php echo htmlspecialchars($event['event_type']); ?>
                                        </div>
                                        <?php if (!empty($event['priority_name']) || !empty($event['goal_title'])): ?>
                                            <div style="font-size: 0.78rem; color: var(--text-muted); margin-top: 0.2rem;">
                                                <?php if (!empty($event['priority_name'])): ?>
                                                    <span>Priority: <?php echo htmlspecialchars($event['priority_name']); ?></span>
                                                <?php endif; ?>
                                                <?php if (!empty($event['goal_title'])): ?>
                                                    <span><?php echo !empty($event['priority_name']) ? ' | ' : ''; ?>Goal: <?php echo htmlspecialchars($event['goal_title']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($event['description'])): ?>
                                            <div style="font-size: 0.78rem; color: var(--text-muted); margin-top: 0.25rem; word-break: break-word;">
                                                <?php echo htmlspecialchars($event['description']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <form method="POST" onsubmit="return confirm('Delete this event?');" style="margin: 0;">
                                        <input type="hidden" name="action" value="delete_event">
                                        <input type="hidden" name="event_id" value="<?php echo (int)$event['id']; ?>">
                                        <button type="submit" class="glass-btn small" style="padding: 6px 8px; color: #ef4444;" title="Delete event">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div class="glass-panel" style="padding: 1.5rem; margin-top: 1.5rem;">
    <div style="display:flex; justify-content:space-between; gap:0.8rem; flex-wrap:wrap; align-items:center; margin-bottom:0.6rem;">
        <h3 style="margin:0;"><i class="fa-solid fa-clock"></i> Recommended Free Time (based on class timetable)</h3>
    </div>

    <?php if (empty($free_time_suggestions)): ?>
        <p style="margin:0; color: var(--text-muted);">
            <?php echo $is_lecturer
                ? 'No free time slots detected yet. Assign lectures to build your timetable.'
                : 'No free time slots detected yet. Enroll in courses to build your timetable.'; ?>
        </p>
    <?php else: ?>
        <?php foreach ($free_time_suggestions as $slot): ?>
            <div class="rec-card">
                <div style="display:flex; justify-content:space-between; gap:0.8rem; flex-wrap:wrap;">
                    <div>
                        <strong><?php echo htmlspecialchars($slot['day']); ?></strong>
                        <div style="font-size:0.84rem; color: var(--text-muted); margin-top:0.2rem;">
                            <?php
                                $start_hr = floor(($slot['start'] ?? 0) / 60);
                                $start_min = ($slot['start'] ?? 0) % 60;
                                $end_hr = floor(($slot['end'] ?? 0) / 60);
                                $end_min = ($slot['end'] ?? 0) % 60;
                                $start_label = sprintf('%02d:%02d', $start_hr, $start_min);
                                $end_label = sprintf('%02d:%02d', $end_hr, $end_min);
                            ?>
                            Free: <?php echo $start_label . ' - ' . $end_label; ?>
                        </div>
                    </div>
                    <div style="text-align:right;">
                        <div style="font-weight:700; color:#22d3ee;">Duration: <?php echo number_format(($slot['duration'] ?? 0) / 60, 1); ?> hrs</div>
                        <div style="font-size:0.78rem; color:#86efac;"><i class="fa-solid fa-check"></i> No classes in this block</div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<div id="generated-weekly" class="glass-panel" style="padding: 1.5rem; margin-top: 1.5rem;">
    <div style="display:flex; justify-content: space-between; align-items: center; gap: 0.8rem; flex-wrap: wrap; margin-bottom: 0.8rem;">
        <h3 style="margin: 0;"><i class="fa-solid fa-table-cells"></i> Generated Weekly Timetable</h3>
        <div style="font-size: 0.86rem; color: var(--text-muted);">
            Total entries: <strong><?php echo count($weekly_rows); ?></strong>
            <?php if ($conflict_count > 0): ?>
                &nbsp;|&nbsp; <span class="conflict-pill"><i class="fa-solid fa-triangle-exclamation"></i> Conflicts: <?php echo (int)$conflict_count; ?></span>
            <?php endif; ?>
        </div>
    </div>

    <?php if (empty($weekly_rows)): ?>
        <p style="color: var(--text-muted); margin: 0;">
            <?php echo $is_lecturer
                ? 'No timetable entries yet. Assign lectures and/or add personal events.'
                : 'No timetable entries yet. Enroll in courses and/or add personal events.'; ?>
        </p>
    <?php else: ?>
        <div style="display:flex; gap:0.8rem; flex-wrap:wrap; align-items:center; margin-bottom:0.8rem;">
            <input type="text" class="glass-input table-search" data-target-table="weekly-table" placeholder="Search timetable..." style="max-width:260px;">
            <select class="glass-input table-page-size" data-target-table="weekly-table" style="max-width:160px;">
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
                        <td><?php echo htmlspecialchars($row['day']); ?></td>
                        <td><?php echo htmlspecialchars($row['time']); ?></td>
                        <td>
                            <span class="type-pill <?php echo $row['type'] === 'class' ? 'type-class' : 'type-personal'; ?>">
                                <?php echo ucfirst(htmlspecialchars($row['type'])); ?>
                            </span>
                        </td>
                        <td>
                            <strong><?php echo htmlspecialchars($row['title']); ?></strong>
                            <?php if (!empty($row['lecturer'])): ?>
                                <div style="font-size: 0.8rem; color: var(--text-muted);">Lecturer: <?php echo htmlspecialchars($row['lecturer']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($row['details'] ?: '-'); ?></td>
                        <td>
                            <?php if (!empty($row['conflict'])): ?>
                                <span class="conflict-pill"><i class="fa-solid fa-triangle-exclamation"></i> Conflict</span>
                            <?php else: ?>
                                <span style="color:#34d399; font-size:0.82rem;"><i class="fa-solid fa-check"></i> OK</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
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
            let html = '<div style="display: grid; gap: 12px;">';
            data.suggestions.forEach(s => {
                const score = (parseFloat(s.priority_score) + parseFloat(s.productivity_score)).toFixed(1);
                html += `
                    <div class="rec-card">
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 8px;">
                            <div>
                                <strong style="font-size: 15px;">${s.day} ${s.start_time} - ${s.end_time}</strong>
                                <div style="font-size: 13px; color: var(--text-muted); margin-top: 4px;">
                                    ${s.duration_minutes} minutes • Score: ${score}
                                </div>
                            </div>
                            <span class="type-pill" style="background: rgba(99,102,241,0.3);">${s.suggestion_type.replace('_', ' ')}</span>
                        </div>
                        <div style="font-size: 13px; color: var(--text-muted); margin-bottom: 10px;">
                            ${s.reason || 'Recommended slot'}
                        </div>
                        <div style="display: flex; gap: 8px;">
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
                <div style="text-align: center; padding: 20px; color: var(--text-muted);">
                    <i class="fas fa-calendar-check" style="font-size: 40px; margin-bottom: 10px; opacity: 0.5;"></i>
                    <p>No suggestions available. Start logging tasks to improve recommendations!</p>
                    <button class="glass-btn" onclick="generateNewSuggestions()">Generate Suggestions</button>
                </div>
            `;
        }
    } catch (error) {
        content.innerHTML = '<div style="color: #ef4444; padding: 20px;">Failed to load suggestions</div>';
    }
}

async function acceptSuggestion(id) {
    try {
        const response = await fetch('api/smart_suggestions.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `action=accept_suggestion&suggestion_id=${id}`
        });
        const data = await response.json();
        if (data.success) {
            alert('Suggestion accepted! Your preferences have been updated.');
            loadSmartSuggestions();
        }
    } catch (error) {
        alert('Failed to accept suggestion');
    }
}

async function rejectSuggestion(id) {
    try {
        const response = await fetch('api/smart_suggestions.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `action=reject_suggestion&suggestion_id=${id}`
        });
        const data = await response.json();
        if (data.success) {
            loadSmartSuggestions();
        }
    } catch (error) {
        alert('Failed to reject suggestion');
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
        alert('Failed to generate suggestions');
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
        
        if (data.success && data.url) {
            // Show notification
            const msg = document.createElement('div');
            msg.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: #10b981;
                color: white;
                padding: 1rem 1.5rem;
                border-radius: 8px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                z-index: 9999;
                font-size: 14px;
            `;
            msg.innerHTML = `
                <strong>Calendar Export Ready</strong><br>
                <small>${data.events_count} events ready to add. Opening Google Calendar...</small>
            `;
            document.body.appendChild(msg);
            
            // Open Google Calendar in new tab
            window.open(data.url, '_blank');
            
            // Remove notification after 5 seconds
            setTimeout(() => msg.remove(), 5000);
        } else {
            alert('Export failed: ' + (data.error || 'Unknown error'));
        }
    } catch (error) {
        alert('Failed to export calendar: ' + error.message);
    }
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

<style>
.badge {
    background: #ef4444;
    color: white;
    border-radius: 999px;
    padding: 2px 6px;
    font-size: 11px;
    margin-left: 5px;
    font-weight: bold;
}
</style>

<?php include 'includes/footer.php'; ?>
