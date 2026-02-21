<?php
$page_title = 'My Courses';
include 'includes/header.php';
require_once 'api/db.php';
require_once 'includes/ai_predictions.php';

requireRole(['student']);

$user_id = (int)($_SESSION['user_id'] ?? 0);
$department = trim($_SESSION['department'] ?? '');
$level = (int)($_SESSION['level'] ?? 100);
$semester = (string)($_SESSION['semester'] ?? '1');

$message = '';
$message_type = '';
$show_completed = isset($_GET['show_completed']) && $_GET['show_completed'] === '1';

// Defensive table for student-managed completed courses
$conn->query("CREATE TABLE IF NOT EXISTS student_completed_courses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    course_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_student_course (user_id, course_id),
    INDEX idx_user (user_id),
    INDEX idx_course (course_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Helpers
$to_minutes = static function ($value): ?int {
    $value = trim((string)$value);
    if ($value === '') return null;
    $ts = strtotime($value);
    if ($ts === false) return null;
    return ((int)date('H', $ts) * 60) + (int)date('i', $ts);
};

$extract_section = static function ($text): string {
    $text = strtoupper((string)$text);
    if (preg_match('/\bSEC(?:TION)?\s*-?\s*([A-Z0-9]+)/i', $text, $m)) {
        return 'SEC ' . strtoupper($m[1]);
    }
    return '';
};

$time_range_to_minutes = static function ($range) use ($to_minutes): array {
    $parts = preg_split('/\s*-\s*/', trim((string)$range));
    $start = $to_minutes($parts[0] ?? '');
    $end = $to_minutes($parts[1] ?? '');
    if ($start !== null && $end !== null && $end <= $start) {
        $end = $start + 60;
    }
    if ($start !== null && $end === null) {
        $end = $start + 60;
    }
    return [$start, $end];
};

$get_slot_for_code_section = static function (array $slotMap, string $code, ?string $section): ?array {
    $code = strtoupper(trim($code));
    if ($code === '' || !isset($slotMap[$code])) return null;
    $sectionKey = $section ? strtoupper(trim($section)) : 'DEFAULT';
    if (isset($slotMap[$code][$sectionKey])) {
        return $slotMap[$code][$sectionKey];
    }
    if (isset($slotMap[$code]['DEFAULT'])) {
        return $slotMap[$code]['DEFAULT'];
    }
    $first = array_values($slotMap[$code]);
    return $first[0] ?? null;
};

$column_exists = static function (mysqli $conn, string $table, string $column): bool {
    $stmt = $conn->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $res = $stmt->get_result();
    return $res && $res->num_rows > 0;
};

$has_section_column = $column_exists($conn, 'student_enrollments', 'section');

// Enrolled courses
$enrolled_courses = [];
$enrolled_course_ids = [];
$enrolled_sql = "SELECT c.id, c.course_code, c.course_title, c.level, c.credit_hours, c.department,
                        l.name AS lecturer_name, se.semester, " . ($has_section_column ? 'se.section' : 'NULL AS section') . "
                 FROM student_enrollments se
                 JOIN courses c ON se.course_id = c.id
                 LEFT JOIN lecturers l ON c.lecturer_id = l.id
                 WHERE se.user_id = ? AND se.semester = ?
                 ORDER BY c.course_code";
$enrolled_stmt = $conn->prepare($enrolled_sql);
if ($enrolled_stmt) {
    $enrolled_stmt->bind_param('is', $user_id, $semester);
    $enrolled_stmt->execute();
    $res = $enrolled_stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $enrolled_courses[] = $row;
        $enrolled_course_ids[(int)$row['id']] = true;
    }
}

// Completed courses map
$completed_course_ids = [];
$completed_id_stmt = $conn->prepare("SELECT course_id FROM student_completed_courses WHERE user_id = ?");
if ($completed_id_stmt) {
    $completed_id_stmt->bind_param('i', $user_id);
    $completed_id_stmt->execute();
    $completed_res = $completed_id_stmt->get_result();
    while ($row = $completed_res->fetch_assoc()) {
        $completed_course_ids[(int)$row['course_id']] = true;
    }
}

// Smart detection from DB: latest departmental + latest general timetable
$latest_schedule_labels = [];
$detected_codes = [];
$course_slot_map = [];
$course_source_map = [];
$course_section_map = [];
$detected_catalog = [];

$normalize_row = static function (array $row): array {
    return array_change_key_case($row, CASE_LOWER);
};

$extract_rows_from_schedule_data = static function ($schedule_data) use ($normalize_row): array {
    $rows_out = [];
    $decoded = json_decode((string)$schedule_data, true);

    // JSON array with header row + data rows (common format)
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
                $rows_out[] = $normalize_row($assoc);
            }
        } else {
            foreach ($decoded as $entry) {
                if (is_array($entry)) {
                    $rows_out[] = $normalize_row($entry);
                }
            }
        }
    } elseif (is_string($schedule_data) && trim($schedule_data) !== '') {
        // Legacy CSV text
        $lines = preg_split('/\r\n|\r|\n/', trim($schedule_data));
        $csv_rows = [];
        foreach ($lines as $ln) {
            if (trim($ln) === '') continue;
            $csv_rows[] = str_getcsv($ln);
        }
        if (!empty($csv_rows)) {
            $headers = array_map(static function ($h) {
                return strtolower(trim((string)$h, "\" "));
            }, array_shift($csv_rows));
            foreach ($csv_rows as $r) {
                $assoc = [];
                foreach ($headers as $idx => $h) {
                    $assoc[$h] = $r[$idx] ?? '';
                }
                $rows_out[] = $normalize_row($assoc);
            }
        }
    }

    return $rows_out;
};

$latest_department_schedule = null;
$latest_general_schedule = null;

$dept_stmt = $conn->prepare("SELECT id, schedule_name, department, semester, created_at, schedule_data
                                                         FROM generated_schedules
                                                         WHERE LOWER(TRIM(department)) = LOWER(TRIM(?))
                                                             AND (schedule_name NOT LIKE 'exam_%' OR schedule_name IS NULL)
                                                             AND (semester = ? OR semester IS NULL OR TRIM(semester) = '')
                                                         ORDER BY created_at DESC
                                                         LIMIT 1");
if ($dept_stmt) {
        $dept_stmt->bind_param('ss', $department, $semester);
    $dept_stmt->execute();
    $dept_res = $dept_stmt->get_result();
    $latest_department_schedule = $dept_res ? $dept_res->fetch_assoc() : null;
}

$general_stmt = $conn->prepare("SELECT id, schedule_name, department, semester, created_at, schedule_data
                                                                FROM generated_schedules
                                                                WHERE (
                                                                        department IS NULL
                                                                        OR TRIM(department) = ''
                                                                        OR LOWER(TRIM(department)) = 'general'
                                                                )
                                                                    AND (schedule_name NOT LIKE 'exam_%' OR schedule_name IS NULL)
                                                                    AND (semester = ? OR semester IS NULL OR TRIM(semester) = '')
                                                                ORDER BY created_at DESC
                                                                LIMIT 1");
if ($general_stmt) {
        $general_stmt->bind_param('s', $semester);
        $general_stmt->execute();
    $general_res = $general_stmt->get_result();
    $latest_general_schedule = $general_res ? $general_res->fetch_assoc() : null;
}

$schedule_sources = [];
if (!empty($latest_department_schedule)) $schedule_sources[] = ['type' => 'department', 'row' => $latest_department_schedule];
if (!empty($latest_general_schedule)) $schedule_sources[] = ['type' => 'general', 'row' => $latest_general_schedule];

foreach ($schedule_sources as $sourceItem) {
    $sourceType = $sourceItem['type'];
    $sourceRow = $sourceItem['row'];
    $sourceLabel = $sourceType === 'department'
        ? ('Department (' . ($sourceRow['department'] ?: $department ?: 'N/A') . ')')
        : 'General';

    $latest_schedule_labels[] = $sourceLabel . ' • ' . ($sourceRow['schedule_name'] ?? 'Unnamed') . ' • ' . ($sourceRow['created_at'] ?? '');

    $parsed_rows = $extract_rows_from_schedule_data($sourceRow['schedule_data'] ?? '');
    foreach ($parsed_rows as $r) {
        $code = strtoupper(trim((string)($r['course code'] ?? ($r['course'] ?? ($r['code'] ?? '')))));
        if ($code === '') continue;

        $row_level_raw = trim((string)($r['level'] ?? ''));
        if ($row_level_raw !== '' && preg_match('/\d+/', $row_level_raw, $m)) {
            $row_level = (int)$m[0];
            if ($row_level > 0 && $row_level !== $level) {
                continue;
            }
        }

        $detected_codes[$code] = true;
        $detected_dept = trim((string)($r['department'] ?? ''));
        if ($detected_dept === '') {
            $detected_dept = $sourceType === 'general' ? 'General' : ($department ?: 'General');
        }

        $detected_level = $level;
        if (!empty($row_level_raw) && preg_match('/\d+/', $row_level_raw, $lm)) {
            $detected_level = (int)$lm[0] ?: $level;
        }

        if (!isset($detected_catalog[$code])) {
            $detected_catalog[$code] = [
                'course_code' => $code,
                'course_title' => trim((string)($r['course title'] ?? ($r['title'] ?? $code))),
                'level' => $detected_level,
                'department' => $detected_dept
            ];
        }

        $section = $extract_section(($r['section'] ?? '') . ' ' . ($r['course title'] ?? '') . ' ' . ($r['title'] ?? '') . ' ' . ($r['course'] ?? '') . ' ' . ($r['code'] ?? ''));
        $section_key = $section !== '' ? $section : 'DEFAULT';

        if (!isset($course_slot_map[$code])) {
            $course_slot_map[$code] = [];
        }

        if (!isset($course_slot_map[$code][$section_key])) {
            $course_slot_map[$code][$section_key] = [
                'day' => trim((string)($r['day'] ?? 'Monday')),
                'time' => trim((string)($r['time'] ?? '09:00-10:00')),
                'room' => trim((string)($r['room name'] ?? ($r['room'] ?? ''))),
                'section' => $section
            ];
        }

        if (!isset($course_section_map[$code]) && $section_key !== 'DEFAULT') {
            $course_section_map[$code] = $section_key;
        }

        if (!isset($course_source_map[$code])) {
            $course_source_map[$code] = $sourceType;
        }
    }
}

// Upload detected timetable courses into courses catalog for enrollment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'sync_detected_courses' || isset($_POST['sync_detected']))) {
    $inserted = 0;
    $updated = 0;

    if (empty($detected_catalog)) {
        $message = 'No detected timetable courses found to upload.';
        $message_type = 'warning';
    } else {
        $insert_stmt = $conn->prepare("INSERT IGNORE INTO courses (course_code, course_title, credit_hours, level, semester, department)
                                       VALUES (?, ?, 3, ?, '1', ?)");
        $update_stmt = $conn->prepare("UPDATE courses SET course_title = COALESCE(NULLIF(?, ''), course_title),
                                                          level = ?,
                                                          department = COALESCE(NULLIF(?, ''), department)
                                       WHERE course_code = ?");

        foreach ($detected_catalog as $code => $meta) {
            $ccode = $meta['course_code'];
            $ctitle = $meta['course_title'];
            $clevel = (int)($meta['level'] ?? $level);
            $cdept = $meta['department'] ?: ($department ?: 'General');

            if ($insert_stmt) {
                $insert_stmt->bind_param('ssis', $ccode, $ctitle, $clevel, $cdept);
                $insert_stmt->execute();
                if ($insert_stmt->affected_rows > 0) {
                    $inserted++;
                }
            }

            if ($update_stmt) {
                $update_stmt->bind_param('siss', $ctitle, $clevel, $cdept, $ccode);
                $update_stmt->execute();
                if ($update_stmt->affected_rows > 0) {
                    $updated++;
                }
            }
        }

        $message = "Uploaded timetable courses to enroll catalog. Added: {$inserted}, Updated: {$updated}.";
        $message_type = 'success';
    }
}

// Personal scheduling engine input (existing personal_events)
$personal_events = [];
$pe_stmt = $conn->prepare("SELECT day, start_time, end_time, title FROM personal_events WHERE user_id = ?");
if ($pe_stmt) {
    $pe_stmt->bind_param('i', $user_id);
    $pe_stmt->execute();
    $pe_res = $pe_stmt->get_result();
    while ($row = $pe_res->fetch_assoc()) {
        $personal_events[] = $row;
    }
}

$to_minutes = static function ($value): ?int {
    $value = trim((string)$value);
    if ($value === '') return null;
    $ts = strtotime($value);
    if ($ts === false) return null;
    return ((int)date('H', $ts) * 60) + (int)date('i', $ts);
};

$time_range_to_minutes = static function ($range) use ($to_minutes): array {
    $parts = preg_split('/\s*-\s*/', trim((string)$range));
    $start = $to_minutes($parts[0] ?? '');
    $end = $to_minutes($parts[1] ?? '');
    if ($start !== null && $end !== null && $end <= $start) {
        $end = $start + 60;
    }
    if ($start !== null && $end === null) {
        $end = $start + 60;
    }
    return [$start, $end];
};

$has_personal_conflict = static function (string $day, string $timeRange, array $events) use ($to_minutes, $time_range_to_minutes): bool {
    [$s1, $e1] = $time_range_to_minutes($timeRange);
    if ($s1 === null || $e1 === null) return false;

    foreach ($events as $ev) {
        if (strcasecmp(trim((string)($ev['day'] ?? '')), $day) !== 0) continue;
        $s2 = $to_minutes($ev['start_time'] ?? '');
        $e2 = $to_minutes($ev['end_time'] ?? '');
        if ($s2 === null || $e2 === null) continue;
        if ($e2 <= $s2) $e2 = $s2 + 60;

        if (!($e1 <= $s2 || $s1 >= $e2)) {
            return true;
        }
    }
    return false;
};

// Base course pool: level + (department OR general)
$course_pool = [];
$pool_stmt = $conn->prepare("SELECT c.id, c.course_code, c.course_title, c.level, c.credit_hours, c.department,
                                    l.name AS lecturer_name
                             FROM courses c
                             LEFT JOIN lecturers l ON c.lecturer_id = l.id
                             WHERE c.level = ?
                                                             AND c.semester = ?
                               AND (
                                    LOWER(TRIM(c.department)) = LOWER(TRIM(?))
                                    OR c.department IS NULL
                                    OR TRIM(c.department) = ''
                                    OR LOWER(TRIM(c.department)) = 'general'
                               )
                             ORDER BY c.course_code");

if ($pool_stmt) {
        $pool_stmt->bind_param('iss', $level, $semester, $department);
    $pool_stmt->execute();
    $pool_res = $pool_stmt->get_result();

    while ($row = $pool_res->fetch_assoc()) {
        $code = strtoupper(trim($row['course_code'] ?? ''));
        if (!empty($detected_codes) && !isset($detected_codes[$code])) {
            continue;
        }
        $course_pool[] = $row;
    }
}

// Handle actions (after schedule/slot maps & enrolled courses are known)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (isset($_POST['enroll'])) $action = 'enroll';
    if (isset($_POST['unenroll'])) $action = 'unenroll';
    if (isset($_POST['mark_done'])) $action = 'mark_done';
    if (isset($_POST['unmark_done'])) $action = 'unmark_done';
    if (isset($_POST['sync_detected'])) $action = 'sync_detected_courses';

    $course_id = (int)($_POST['course_id'] ?? 0);

    if ($course_id > 0 && $action === 'enroll') {
        $check = $conn->prepare("SELECT id FROM student_enrollments WHERE user_id = ? AND course_id = ? AND semester = ?");
        if ($check) {
            $check->bind_param('iis', $user_id, $course_id, $semester);
            $check->execute();
            $exists = $check->get_result();

            if ($exists && $exists->num_rows === 0) {
                $code_lookup = '';
                $course_semester = '';
                $c_stmt = $conn->prepare("SELECT course_code, semester FROM courses WHERE id = ? LIMIT 1");
                if ($c_stmt) {
                    $c_stmt->bind_param('i', $course_id);
                    $c_stmt->execute();
                    $c_res = $c_stmt->get_result();
                    if ($c_res && ($c_row = $c_res->fetch_assoc())) {
                        $code_lookup = strtoupper(trim((string)$c_row['course_code']));
                        $course_semester = (string)($c_row['semester'] ?? '');
                    }
                }

                if ($course_semester !== '' && $course_semester !== $semester) {
                    $message = '⚠️ Enrollment blocked: Course is not offered in your current semester.';
                    $message_type = 'warning';
                } else {

                $target_section = $code_lookup !== '' ? ($course_section_map[$code_lookup] ?? null) : null;
                $target_slot = $code_lookup !== '' ? $get_slot_for_code_section($course_slot_map, $code_lookup, $target_section) : null;
                $target_room = $target_slot['room'] ?? '';
                $conflict_reason = '';

                if ($target_slot) {
                    foreach ($enrolled_courses as $enrolled) {
                        $enrolled_code = strtoupper(trim((string)($enrolled['course_code'] ?? '')));
                        $enrolled_section = isset($enrolled['section']) ? strtoupper(trim((string)$enrolled['section'])) : null;
                        $slot = $get_slot_for_code_section($course_slot_map, $enrolled_code, $enrolled_section);
                        if ($enrolled_code === '' || !$slot) {
                            continue;
                        }

                        if (($slot['day'] ?? '') !== ($target_slot['day'] ?? '')) {
                            continue;
                        }

                        [$s1, $e1] = $time_range_to_minutes($slot['time'] ?? '');
                        [$s2, $e2] = $time_range_to_minutes($target_slot['time'] ?? '');
                        if ($s1 === null || $e1 === null || $s2 === null || $e2 === null) {
                            continue;
                        }

                        $overlap = !($e1 <= $s2 || $s1 >= $e2);
                        if ($overlap) {
                            $conflict_reason = 'Time slot conflicts with another enrolled course.';
                            break;
                        }

                        $room_match = $target_room !== ''
                            && !empty($slot['room'])
                            && strcasecmp($target_room, $slot['room']) === 0;
                        if ($room_match && $overlap) {
                            $conflict_reason = 'Room conflict detected for the same time slot.';
                            break;
                        }
                    }
                }

                if ($conflict_reason !== '') {
                    $message = '⚠️ Enrollment blocked: ' . $conflict_reason . ' Please pick another course/time.';
                    $message_type = 'warning';
                } else {
                    if ($has_section_column) {
                        $stmt = $conn->prepare("INSERT INTO student_enrollments (user_id, course_id, section, semester, academic_year) VALUES (?, ?, ?, ?, '2025/2026')");
                        if ($stmt) {
                            $section_to_store = ($target_section && $target_section !== 'DEFAULT') ? $target_section : null;
                            $stmt->bind_param('iiss', $user_id, $course_id, $section_to_store, $semester);
                            if ($stmt->execute()) {
                                $message = 'Successfully enrolled in course.';
                                $message_type = 'success';
                            } else {
                                $message = 'Enrollment failed. Please try again.';
                                $message_type = 'error';
                            }
                        }
                    } else {
                        $stmt = $conn->prepare("INSERT INTO student_enrollments (user_id, course_id, semester, academic_year) VALUES (?, ?, ?, '2025/2026')");
                        if ($stmt) {
                            $stmt->bind_param('iis', $user_id, $course_id, $semester);
                            if ($stmt->execute()) {
                                $message = 'Successfully enrolled in course.';
                                $message_type = 'success';
                            } else {
                                $message = 'Enrollment failed. Please try again.';
                                $message_type = 'error';
                            }
                        }
                    }
                }
                }
            } else {
                $message = 'You are already enrolled in this course.';
                $message_type = 'warning';
            }
        }
    }

    if ($course_id > 0 && $action === 'unenroll') {
        $stmt = $conn->prepare("DELETE FROM student_enrollments WHERE user_id = ? AND course_id = ? AND semester = ?");
        if ($stmt) {
            $stmt->bind_param('iis', $user_id, $course_id, $semester);
            if ($stmt->execute()) {
                $message = 'Successfully unenrolled from course.';
                $message_type = 'success';
            } else {
                $message = 'Unenrollment failed. Please try again.';
                $message_type = 'error';
            }
        }
    }

    if ($course_id > 0 && $action === 'mark_done') {
        $stmt = $conn->prepare("INSERT IGNORE INTO student_completed_courses (user_id, course_id) VALUES (?, ?)");
        if ($stmt) {
            $stmt->bind_param('ii', $user_id, $course_id);
            if ($stmt->execute()) {
                $drop = $conn->prepare("DELETE FROM student_enrollments WHERE user_id = ? AND course_id = ?");
                if ($drop) {
                    $drop->bind_param('ii', $user_id, $course_id);
                    $drop->execute();
                }
                $message = 'Course marked as done. You can restore it anytime.';
                $message_type = 'success';
            }
        }
    }

    if ($course_id > 0 && $action === 'unmark_done') {
        $stmt = $conn->prepare("DELETE FROM student_completed_courses WHERE user_id = ? AND course_id = ?");
        if ($stmt) {
            $stmt->bind_param('ii', $user_id, $course_id);
            if ($stmt->execute()) {
                $message = 'Course removed from done list.';
                $message_type = 'success';
            }
        }
    }
}

$available_courses = [];
$completed_courses = [];

foreach ($course_pool as $course) {
    $cid = (int)$course['id'];
    $code = strtoupper(trim($course['course_code'] ?? ''));

    if (isset($completed_course_ids[$cid])) {
        $completed_courses[] = $course;
        if (!$show_completed) {
            continue;
        }
    }

    if (isset($enrolled_course_ids[$cid])) {
        continue;
    }

    $slot = $get_slot_for_code_section($course_slot_map, $code, $course_section_map[$code] ?? null) ?? ['day' => 'Monday', 'time' => '09:00-10:00'];
    $time_start = trim(explode('-', $slot['time'])[0] ?? '09:00');

    $feasibility = predictAssignmentFeasibility($code, $slot['day'], $time_start, 30);
    $prob = (float)($feasibility['probability'] ?? 0.5);
    $conflict = $has_personal_conflict($slot['day'], $slot['time'], $personal_events);

    $score = ($prob * 100) - ($conflict ? 35 : 0);
    if ($score < 0) $score = 0;

    $course['detected_day'] = $slot['day'];
    $course['detected_time'] = $slot['time'];
    $course['detected_source'] = $course_source_map[$code] ?? 'fallback';
    $course['feasibility_score'] = $prob;
    $course['feasibility_badge'] = getFeasibilityBadge($prob);
    $course['personal_conflict'] = $conflict;
    $course['recommendation_score'] = $score;

    $available_courses[] = $course;
}

usort($available_courses, static function ($a, $b) {
    return ($b['recommendation_score'] ?? 0) <=> ($a['recommendation_score'] ?? 0);
});

$enrolled_count = count($enrolled_courses);
$available_count = count($available_courses);
$completed_count = count($completed_courses);
$detected_count = count($detected_codes);
?>

<style>
.alert { padding: 1rem; border-radius: 0.5rem; border-left: 4px solid transparent; margin-bottom: 1rem; }
.alert-success { background: rgba(16,185,129,0.12); border-left-color: #10b981; color: #10b981; }
.alert-error { background: rgba(239,68,68,0.12); border-left-color: #ef4444; color: #ef4444; }
.alert-warning { background: rgba(245,158,11,0.12); border-left-color: #f59e0b; color: #f59e0b; }

.table-wrap { overflow-x: auto; }
.table-modern { width: 100%; border-collapse: collapse; font-size: 0.93rem; }
.table-modern th, .table-modern td { padding: 0.8rem; border-bottom: 1px solid rgba(255,255,255,0.08); vertical-align: top; }
.table-modern th { color: var(--text-muted); font-weight: 600; text-align: left; }
.table-modern tbody tr:hover { background: rgba(255,255,255,0.03); }
.badge-pill { padding: 0.24rem 0.58rem; border-radius: 999px; font-size: 0.78rem; font-weight: 600; display: inline-block; }
.badge-ok { background: rgba(16,185,129,0.16); color: #34d399; }
.badge-done { background: rgba(59,130,246,0.16); color: #93c5fd; }
.badge-ai { background: rgba(139,92,246,0.14); color: #c4b5fd; }

.action-row { display: flex; gap: 0.4rem; flex-wrap: wrap; }
.small-btn { border: 0; border-radius: 0.45rem; padding: 0.42rem 0.62rem; cursor: pointer; }
.btn-enroll { background: #4f46e5; color: #fff; }
.btn-unenroll { background: rgba(239,68,68,0.2); color: #ef4444; }
.btn-done { background: rgba(59,130,246,0.2); color: #93c5fd; }
.btn-undo { background: rgba(245,158,11,0.2); color: #fbbf24; }

.kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 1rem; margin-bottom: 1.3rem; }
.kpi { background: rgba(15,23,42,0.55); border: 1px solid rgba(255,255,255,0.08); border-radius: 0.65rem; padding: 0.95rem; }
.kpi-value { font-size: 1.4rem; font-weight: 700; color: var(--primary-color); }
.kpi-label { color: var(--text-muted); font-size: 0.82rem; }

.rec-card { background: rgba(14,165,233,0.08); border: 1px solid rgba(14,165,233,0.25); border-radius: 0.6rem; padding: 0.8rem; margin-bottom: 0.6rem; }
</style>

<?php if (!empty($message)): ?>
    <div class="alert alert-<?php echo htmlspecialchars($message_type); ?>"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<div class="glass-panel" style="padding: 1.2rem; margin-bottom: 1rem; background: linear-gradient(135deg, rgba(79,70,229,0.12), rgba(236,72,153,0.08));">
    <div style="display:flex; justify-content:space-between; gap:0.8rem; flex-wrap:wrap; align-items:center; margin-bottom:0.4rem;">
        <h3 style="margin: 0;"><i class="fa-solid fa-robot"></i> Smart Course Detection</h3>
        <form method="POST" style="margin:0;">
            <input type="hidden" name="action" value="sync_detected_courses">
            <button type="submit" name="sync_detected" class="glass-btn small">
                <i class="fa-solid fa-upload"></i> Upload Detected Courses
            </button>
        </form>
    </div>
    <p style="margin: 0; color: var(--text-muted);">
        Detection source: <strong>Database (latest departmental + latest general timetable)</strong>.
        Filtered for <strong><?php echo htmlspecialchars($department ?: 'Your Department'); ?></strong>, level <strong><?php echo (int)$level; ?></strong>,
        and scored against your <strong>personal schedule engine</strong>.
    </p>
    <?php if (!empty($latest_schedule_labels)): ?>
        <div style="margin-top: 0.55rem; font-size: 0.84rem; color: var(--text-muted);">
            <?php foreach ($latest_schedule_labels as $lbl): ?>
                <div><i class="fa-solid fa-database"></i> <?php echo htmlspecialchars($lbl); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div class="kpi-grid">
    <div class="kpi"><div class="kpi-value"><?php echo $detected_count; ?></div><div class="kpi-label">Detected timetable courses</div></div>
    <div class="kpi"><div class="kpi-value"><?php echo $enrolled_count; ?></div><div class="kpi-label">Currently enrolled</div></div>
    <div class="kpi"><div class="kpi-value"><?php echo $available_count; ?></div><div class="kpi-label">Available to enroll</div></div>
    <div class="kpi"><div class="kpi-value"><?php echo $completed_count; ?></div><div class="kpi-label">Marked as done</div></div>
</div>


<div class="glass-panel" style="padding: 1.25rem; margin-bottom: 1.2rem;">
    <div style="display:flex; justify-content: space-between; gap: 1rem; flex-wrap: wrap; align-items:center;">
        <h3 style="margin:0;"><i class="fa-solid fa-list-check"></i> My Enrolled Courses</h3>
    </div>

    <?php if (empty($enrolled_courses)): ?>
        <p style="color: var(--text-muted); margin-top: 0.9rem;">No enrolled courses yet.</p>
    <?php else: ?>
        <div style="display:flex; gap:0.8rem; flex-wrap:wrap; align-items:center; margin-top:0.8rem;">
            <input type="text" class="glass-input table-search" data-target-table="enrolled-table" placeholder="Search enrolled courses..." style="max-width:260px;">
            <select class="glass-input table-page-size" data-target-table="enrolled-table" style="max-width:160px;">
                <option value="5">5 / page</option>
                <option value="10" selected>10 / page</option>
                <option value="20">20 / page</option>
                <option value="50">50 / page</option>
            </select>
            <div class="table-pagination" data-target-table="enrolled-table"></div>
        </div>
        <div class="table-wrap" style="margin-top: 0.9rem;">
            <table class="table-modern" id="enrolled-table">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Title</th>
                        <th>Lecturer</th>
                        <th>Level</th>
                        <th>Credit Hrs</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($enrolled_courses as $course): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($course['course_code']); ?></strong></td>
                        <td><?php echo htmlspecialchars($course['course_title']); ?></td>
                        <td><?php echo htmlspecialchars($course['lecturer_name'] ?? 'TBA'); ?></td>
                        <td><?php echo (int)$course['level']; ?></td>
                        <td><?php echo (int)($course['credit_hours'] ?? 0); ?></td>
                        <td>
                            <div class="action-row">
                                <form method="POST" onsubmit="return confirm('Unenroll from this course?');">
                                    <input type="hidden" name="course_id" value="<?php echo (int)$course['id']; ?>">
                                    <button type="submit" name="unenroll" class="small-btn btn-unenroll"><i class="fa-solid fa-user-minus"></i> Unenroll</button>
                                </form>
                                <form method="POST" onsubmit="return confirm('Mark this course as done? It will be removed from active enrollment.');">
                                    <input type="hidden" name="course_id" value="<?php echo (int)$course['id']; ?>">
                                    <button type="submit" name="mark_done" class="small-btn btn-done"><i class="fa-solid fa-check"></i> Mark Done</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="glass-panel" style="padding: 1.25rem; margin-bottom: 1.2rem;">
    <div style="display:flex; justify-content: space-between; gap: 1rem; flex-wrap: wrap; align-items:center;">
        <h3 style="margin:0;"><i class="fa-solid fa-plus-circle"></i> Available Courses (AI-filtered)</h3>
        <a class="glass-btn small" href="my_courses.php<?php echo $show_completed ? '' : '?show_completed=1'; ?>">
            <i class="fa-solid fa-eye"></i> <?php echo $show_completed ? 'Hide Done Courses' : 'Show Done Courses'; ?>
        </a>
    </div>

    <?php if (empty($available_courses)): ?>
        <p style="color: var(--text-muted); margin-top: 0.9rem;">No available courses to enroll right now.</p>
    <?php else: ?>
        <div style="display:flex; gap:0.8rem; flex-wrap:wrap; align-items:center; margin-top:0.8rem;">
            <input type="text" class="glass-input table-search" data-target-table="available-table" placeholder="Search available courses..." style="max-width:260px;">
            <select class="glass-input table-page-size" data-target-table="available-table" style="max-width:160px;">
                <option value="5">5 / page</option>
                <option value="10" selected>10 / page</option>
                <option value="20">20 / page</option>
                <option value="50">50 / page</option>
            </select>
            <div class="table-pagination" data-target-table="available-table"></div>
        </div>
        <div class="table-wrap" style="margin-top: 0.9rem;">
            <table class="table-modern" id="available-table">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Title</th>
                        <th>Lecturer</th>
                        <th>Detected Slot</th>
                        <th>Source</th>
                        <th>Personal Fit</th>
                        <th>AI Feasibility</th>
                        <th>Fit Score</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($available_courses as $course): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($course['course_code']); ?></strong></td>
                        <td><?php echo htmlspecialchars($course['course_title']); ?></td>
                        <td><?php echo htmlspecialchars($course['lecturer_name'] ?? 'TBA'); ?></td>
                        <td>
                            <span class="badge-pill badge-ai">
                                <?php echo htmlspecialchars(($course['detected_day'] ?? '-') . ' ' . ($course['detected_time'] ?? '-')); ?>
                            </span>
                        </td>
                        <td><?php echo htmlspecialchars(ucfirst($course['detected_source'] ?? 'fallback')); ?></td>
                        <td>
                            <?php if (!empty($course['personal_conflict'])): ?>
                                <span style="color:#fca5a5; font-weight:600;"><i class="fa-solid fa-triangle-exclamation"></i> Conflict</span>
                            <?php else: ?>
                                <span style="color:#86efac; font-weight:600;"><i class="fa-solid fa-check"></i> Good</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php $fb = $course['feasibility_badge'] ?? ['label' => 'Uncertain', 'color' => '#f59e0b']; ?>
                            <span style="font-weight:600; color: <?php echo htmlspecialchars($fb['color']); ?>;"><?php echo htmlspecialchars($fb['label']); ?></span>
                            <div style="font-size:0.79rem; color: var(--text-muted);">
                                <?php echo number_format(((float)($course['feasibility_score'] ?? 0.5)) * 100, 0); ?>%
                            </div>
                        </td>
                        <td><strong style="color:#22d3ee;"><?php echo number_format((float)($course['recommendation_score'] ?? 0), 0); ?></strong></td>
                        <td>
                            <div class="action-row">
                                <form method="POST">
                                    <input type="hidden" name="course_id" value="<?php echo (int)$course['id']; ?>">
                                    <button type="submit" name="enroll" class="small-btn btn-enroll"><i class="fa-solid fa-user-plus"></i> Enroll</button>
                                </form>
                                <form method="POST" onsubmit="return confirm('Mark this as done/completed?');">
                                    <input type="hidden" name="course_id" value="<?php echo (int)$course['id']; ?>">
                                    <button type="submit" name="mark_done" class="small-btn btn-done"><i class="fa-solid fa-check"></i> Done</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="glass-panel" style="padding: 1.25rem;">
    <h3 style="margin:0 0 0.8rem 0;"><i class="fa-solid fa-pen-to-square"></i> Editable Done Courses</h3>

    <?php if (empty($completed_courses)): ?>
        <p style="color: var(--text-muted);">No courses marked as done yet.</p>
    <?php else: ?>
        <div style="display:flex; gap:0.8rem; flex-wrap:wrap; align-items:center; margin-bottom:0.8rem;">
            <input type="text" class="glass-input table-search" data-target-table="done-table" placeholder="Search done courses..." style="max-width:260px;">
            <select class="glass-input table-page-size" data-target-table="done-table" style="max-width:160px;">
                <option value="5">5 / page</option>
                <option value="10" selected>10 / page</option>
                <option value="20">20 / page</option>
                <option value="50">50 / page</option>
            </select>
            <div class="table-pagination" data-target-table="done-table"></div>
        </div>
        <div class="table-wrap">
            <table class="table-modern" id="done-table">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Title</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($completed_courses as $course): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($course['course_code']); ?></strong></td>
                        <td><?php echo htmlspecialchars($course['course_title']); ?></td>
                        <td><span class="badge-pill badge-done">Done</span></td>
                        <td>
                            <form method="POST">
                                <input type="hidden" name="course_id" value="<?php echo (int)$course['id']; ?>">
                                <button type="submit" name="unmark_done" class="small-btn btn-undo"><i class="fa-solid fa-rotate-left"></i> Restore</button>
                            </form>
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

document.addEventListener('DOMContentLoaded', initTableTools);
</script>

<?php include 'includes/footer.php'; ?>
