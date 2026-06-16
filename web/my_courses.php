<?php
$page_title = 'My Courses';
require_once 'api/db.php';
require_once 'includes/unified_schedule_service.php';
require_once 'includes/access_control.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

requireRole(['student']);

$user_id = (int)($_SESSION['user_id'] ?? 0);
$department = trim($_SESSION['department'] ?? '');
$level = (int)($_SESSION['level'] ?? 100);
$semester = (string)($_SESSION['semester'] ?? '1');
$academic_year = '';

$settings = [];
$settings_res = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('current_semester', 'current_academic_year')");
if ($settings_res) {
    while ($s = $settings_res->fetch_assoc()) {
        $settings[(string)$s['setting_key']] = (string)$s['setting_value'];
    }
}

if (!empty($settings['current_semester'])) {
    $semester = (string)$settings['current_semester'];
    $_SESSION['semester'] = $semester;
}

$academic_year = trim((string)($settings['current_academic_year'] ?? ($_SESSION['academic_year'] ?? '')));
if ($academic_year === '') {
    $yr = (int)date('Y');
    $academic_year = $yr . '/' . ($yr + 1);
}
$_SESSION['academic_year'] = $academic_year;

$message = '';
$message_type = '';
$show_completed = isset($_GET['show_completed']) && $_GET['show_completed'] === '1';
$saved_at_selection = '';

if (isset($_SESSION['my_courses_flash']) && is_array($_SESSION['my_courses_flash'])) {
    $message = (string)($_SESSION['my_courses_flash']['message'] ?? '');
    $message_type = (string)($_SESSION['my_courses_flash']['type'] ?? 'success');
    unset($_SESSION['my_courses_flash']);
}

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
    if (preg_match('/\[SEC\s*([A-Z0-9]+)\]/i', $text, $m)) {
        return strtoupper($m[1]);
    }
    if (preg_match('/\bSEC(?:TION)?\s*-?\s*([A-Z0-9]+)/i', $text, $m)) {
        return strtoupper($m[1]);
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

$normalize_section_key = static function ($explicitSection, string $fallbackText = ''): string {
    $section = strtoupper(trim((string)$explicitSection));
    if ($section !== '') {
        $section = preg_replace('/^\s*SEC(?:TION)?\s*/i', '', $section);
        $section = preg_replace('/[^A-Z0-9]/', '', (string)$section);
        if ($section !== '') {
            return $section;
        }
    }

    $fallback = strtoupper((string)$fallbackText);
    if (preg_match('/\[\s*SEC(?:TION)?\s*([A-Z0-9]+)\s*\]/i', $fallback, $m)) {
        return strtoupper(trim((string)$m[1]));
    }
    if (preg_match('/\bSEC(?:TION)?\s*([A-Z0-9]+)\b/i', $fallback, $m)) {
        return strtoupper(trim((string)$m[1]));
    }

    return '';
};

$format_section_label = static function (string $sectionKey): string {
    $sectionKey = strtoupper(trim($sectionKey));
    if ($sectionKey === '' || $sectionKey === 'DEFAULT') {
        return 'Sec A';
    }
    return 'Sec ' . $sectionKey;
};

$normalize_title_for_identity = static function (string $title): string {
    $clean = strtoupper(trim($title));
    $clean = preg_replace('/\[\s*SEC(?:TION)?\s*[A-Z0-9]+\s*\]/i', '', $clean);
    $clean = preg_replace('/\bSEC(?:TION)?\s*-?\s*[A-Z0-9]+\b/i', '', $clean);
    $clean = preg_replace('/\s+/', ' ', (string)$clean);
    return trim((string)$clean);
};

$build_enrollment_identity = static function (string $code, string $title, string $section) use ($normalize_section_key, $normalize_title_for_identity): string {
    $code_key = strtoupper(trim($code));
    $title_key = $normalize_title_for_identity($title);
    $section_key = $normalize_section_key($section, $title);
    if ($code_key === '' || $title_key === '' || $section_key === '') {
        return '';
    }
    return $code_key . '|' . $title_key . '|' . strtoupper(trim($section_key));
};

$apply_section_to_title = static function (string $title, string $sectionKey) use ($normalize_section_key): string {
    $title = trim($title);
    $sectionKey = $normalize_section_key($sectionKey, $title);
    if ($title === '' || $sectionKey === '') {
        return $title;
    }

    $single_sec = '[Sec ' . strtoupper($sectionKey) . ']';
    if (preg_match('/\[\s*SEC(?:TION)?\s*[^\]]+\]/i', $title)) {
        return preg_replace('/\[\s*SEC(?:TION)?\s*[^\]]+\]/i', $single_sec, $title, 1) ?? $title;
    }

    return trim($title . ' ' . $single_sec);
};

$meetings_overlap = static function (array $a, array $b) use ($time_range_to_minutes): bool {
    $dayA = strtolower(trim((string)($a['day'] ?? '')));
    $dayB = strtolower(trim((string)($b['day'] ?? '')));
    if ($dayA === '' || $dayB === '' || $dayA !== $dayB) {
        return false;
    }

    [$startA, $endA] = $time_range_to_minutes((string)($a['time'] ?? ''));
    [$startB, $endB] = $time_range_to_minutes((string)($b['time'] ?? ''));
    if ($startA === null || $endA === null || $startB === null || $endB === null) {
        return false;
    }

    return !($endA <= $startB || $startA >= $endB);
};

$summarize_meetings = static function (array $meetings): string {
    $parts = [];
    foreach ($meetings as $meeting) {
        $day = trim((string)($meeting['day'] ?? ''));
        $time = trim((string)($meeting['time'] ?? ''));
        if ($day !== '' || $time !== '') {
            $parts[] = trim($day . ' ' . $time);
        }
    }

    $parts = array_values(array_unique(array_filter($parts)));
    return $parts ? implode(' | ', $parts) : 'TBA';
};

$get_slot_for_code_section = static function (array $slotMap, string $code, ?string $section): ?array {
    $code = strtoupper(trim($code));
    if ($code === '' || !isset($slotMap[$code])) return null;
    $sectionKey = $section ? strtoupper(trim($section)) : 'DEFAULT';
    if (isset($slotMap[$code][$sectionKey]['meetings'][0])) {
        $first = $slotMap[$code][$sectionKey]['meetings'][0];
        $first['section'] = $slotMap[$code][$sectionKey]['section'] ?? '';
        $first['section_key'] = $sectionKey;
        return $first;
    }
    if (isset($slotMap[$code]['DEFAULT']['meetings'][0])) {
        $first = $slotMap[$code]['DEFAULT']['meetings'][0];
        $first['section'] = $slotMap[$code]['DEFAULT']['section'] ?? '';
        $first['section_key'] = 'DEFAULT';
        return $first;
    }
    $first = array_values($slotMap[$code]);
    if (!empty($first)) {
        $candidate = $first[0];
        if (isset($candidate['meetings'][0])) {
            $meeting = $candidate['meetings'][0];
            $meeting['section'] = $candidate['section'] ?? '';
            $meeting['section_key'] = 'DEFAULT';
            return $meeting;
        }
    }
    return null;
};

$get_section_meetings = static function (array $slotMap, string $code, ?string $section): array {
    $code = strtoupper(trim($code));
    if ($code === '' || !isset($slotMap[$code])) return [];
    $sectionKey = $section ? strtoupper(trim($section)) : 'DEFAULT';
    if (isset($slotMap[$code][$sectionKey]['meetings'])) {
        return $slotMap[$code][$sectionKey]['meetings'];
    }
    if (isset($slotMap[$code]['DEFAULT']['meetings'])) {
        return $slotMap[$code]['DEFAULT']['meetings'];
    }
    foreach ($slotMap[$code] as $entry) {
        if (isset($entry['meetings'])) {
            return $entry['meetings'];
        }
    }
    return [];
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
if (!$has_section_column) {
    $conn->query("ALTER TABLE student_enrollments ADD COLUMN section VARCHAR(32) NULL AFTER course_id");
    $has_section_column = $column_exists($conn, 'student_enrollments', 'section');
}

$has_academic_year_column = $column_exists($conn, 'student_enrollments', 'academic_year');
$has_sections_table = $conn->query("SHOW TABLES LIKE 'sections'");
$has_sections_table = $has_sections_table && $has_sections_table->num_rows > 0;
$has_sections_section_name = $has_sections_table && $column_exists($conn, 'sections', 'section_name');
$has_sections_lecturer_id = $has_sections_table && $column_exists($conn, 'sections', 'lecturer_id');

// Enrolled courses
$enrolled_courses = [];
$enrolled_course_ids = [];
$enrolled_sql = "SELECT se.id AS enrollment_id, c.id, c.course_code, c.course_title, c.level, c.credit_hours, c.department,
                        l.name AS lecturer_name, se.semester, " . ($has_section_column ? 'se.section' : 'NULL AS section') . ", " . ($has_academic_year_column ? 'se.academic_year' : 'NULL AS academic_year') . "
                 FROM student_enrollments se
                 JOIN courses c ON se.course_id = c.id
                 LEFT JOIN lecturers l ON c.lecturer_id = l.id
                 WHERE se.user_id = ? AND se.semester = ?" . ($has_academic_year_column ? ' AND se.academic_year = ?' : '') . "
                 ORDER BY c.course_code";
$enrolled_stmt = $conn->prepare($enrolled_sql);
if ($enrolled_stmt) {
    if ($has_academic_year_column) {
        $enrolled_stmt->bind_param('iss', $user_id, $semester, $academic_year);
    } else {
        $enrolled_stmt->bind_param('is', $user_id, $semester);
    }
    $enrolled_stmt->execute();
    $res = $enrolled_stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        if ($has_section_column && $has_sections_section_name && $has_sections_lecturer_id) {
            $section_name = trim((string)($row['section'] ?? ''));
            if ($section_name !== '') {
                $lookup_stmt = $conn->prepare("SELECT l.name AS lecturer_name
                                              FROM sections s
                                              LEFT JOIN lecturers l ON s.lecturer_id = l.id
                                              WHERE s.course_id = ?
                                                AND UPPER(TRIM(s.section_name)) = UPPER(TRIM(?))
                                              LIMIT 1");
                if ($lookup_stmt) {
                    $course_id_lookup = (int)($row['id'] ?? 0);
                    $lookup_stmt->bind_param('is', $course_id_lookup, $section_name);
                    $lookup_stmt->execute();
                    $lookup_res = $lookup_stmt->get_result();
                    if ($lookup_res && ($lookup_row = $lookup_res->fetch_assoc())) {
                        $resolved_lecturer = trim((string)($lookup_row['lecturer_name'] ?? ''));
                        if ($resolved_lecturer !== '') {
                            $row['lecturer_name'] = $resolved_lecturer;
                        }
                    }
                }
            }
        }

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

// Smart detection from DB: unified cross-department timetable (latest only for students)
$detected_codes = [];
$course_slot_map = [];
$course_source_map = [];
$course_source_department_map = [];
$course_section_map = [];
$detected_catalog = [];
$titles_by_code = [];

$unified_payload = unified_schedule_fetch($conn, $_SESSION, [
    'semester' => $semester,
    'academic_year' => $academic_year,
    'saved_at' => $saved_at_selection,
    'include_all_student_rows' => true,
]);

$student_dept_norm = strtolower(trim((string)$department));

foreach (($unified_payload['rows'] ?? []) as $r) {
    $code = strtoupper(trim((string)($r['course_code'] ?? '')));
    if ($code === '') {
        continue;
    }

    $row_level_raw = trim((string)($r['level'] ?? ''));
    $row_title = trim((string)($r['course_title'] ?? $code));

    $detected_codes[$code] = true;
    $detected_dept = trim((string)($r['department'] ?? ''));
    if ($detected_dept === '') {
        $detected_dept = $department ?: 'General';
    }

    $detected_level = $level;
    if (!empty($row_level_raw) && preg_match('/\d+/', $row_level_raw, $lm)) {
        $detected_level = (int)$lm[0] ?: $level;
    }

    $section = $normalize_section_key($r['section'] ?? '', (string)($r['course_title'] ?? ''));
    $section = $section !== '' ? $section : $extract_section(($r['course_title'] ?? '') . ' ' . ($r['course_code'] ?? ''));
    $section_key = $section !== '' ? $section : 'DEFAULT';
    $row_title_for_section = $section_key !== 'DEFAULT'
        ? $apply_section_to_title($row_title, $section_key)
        : $row_title;

    $catalog_key = $code . '|' . strtoupper($row_title_for_section !== '' ? $row_title_for_section : $row_title);
    if (!isset($detected_catalog[$catalog_key])) {
        $detected_catalog[$catalog_key] = [
            'course_code' => $code,
            'course_title' => $row_title_for_section !== '' ? $row_title_for_section : $row_title,
            'level' => $detected_level,
            'department' => $detected_dept
        ];
    }

    if ($row_title_for_section !== '') {
        if (!isset($titles_by_code[$code])) {
            $titles_by_code[$code] = [];
        }
        $titles_by_code[$code][$row_title_for_section] = true;
    }

    if (!isset($course_slot_map[$code])) {
        $course_slot_map[$code] = [];
    }

    if (!isset($course_slot_map[$code][$section_key])) {
        $course_slot_map[$code][$section_key] = [
            'section' => $section,
            'title' => $row_title_for_section,
            'meetings' => []
        ];
    }

    $course_slot_map[$code][$section_key]['meetings'][] = [
        'day' => trim((string)($r['day'] ?? 'Monday')),
        'time' => trim((string)($r['time'] ?? '09:00-10:00')),
        'room' => trim((string)($r['room'] ?? '')),
        'section' => $section,
        'title' => $row_title_for_section
    ];

    if (!isset($course_section_map[$code]) && $section_key !== 'DEFAULT') {
        $course_section_map[$code] = $section_key;
    }

    if (!isset($course_source_map[$code])) {
        $detected_dept_norm = strtolower(trim((string)$detected_dept));
        if ($detected_dept_norm === '' || $detected_dept_norm === 'general') {
            $course_source_map[$code] = 'general';
        } elseif ($student_dept_norm !== '' && $detected_dept_norm === $student_dept_norm) {
            $course_source_map[$code] = 'department';
        } else {
            $course_source_map[$code] = 'interdepartment';
        }
    }

    if (!isset($course_source_department_map[$code])) {
        $course_source_department_map[$code] = $detected_dept;
    }
}

// Fallback section inference:
// If a course has only one detected section key but multiple same-day time slots,
// split into selectable variants (A/B/...) so students can pick a specific slot.
foreach ($course_slot_map as $code_key => $section_bucket) {
    if (!is_array($section_bucket) || count($section_bucket) !== 1) {
        continue;
    }

    $single_section_key = (string)array_key_first($section_bucket);
    $single_meta = $section_bucket[$single_section_key] ?? null;
    if (!is_array($single_meta)) {
        continue;
    }

    $single_meetings = $single_meta['meetings'] ?? [];
    if (!is_array($single_meetings) || count($single_meetings) < 2) {
        continue;
    }

    $distinct_days = [];
    $distinct_signatures = [];
    foreach ($single_meetings as $meeting) {
        $day_key = strtolower(trim((string)($meeting['day'] ?? '')));
        $time_key = trim((string)($meeting['time'] ?? ''));
        $sig = $day_key . '|' . $time_key;
        if ($day_key !== '' || $time_key !== '') {
            $distinct_signatures[$sig] = true;
        }
        if ($day_key !== '') {
            $distinct_days[$day_key] = true;
        }
    }

    if (count($distinct_days) !== 1 || count($distinct_signatures) < 2) {
        continue;
    }

    $base_key = strtoupper(trim($single_section_key));
    $repacked = [];
    $slot_index = 0;
    foreach ($single_meetings as $meeting) {
        if (preg_match('/^[A-Z]$/', $base_key)) {
            $ord = ord($base_key) + $slot_index;
            $variant_key = $ord <= ord('Z') ? chr($ord) : ($base_key . ($slot_index + 1));
        } else {
            $variant_key = ($base_key !== '' && $base_key !== 'DEFAULT') ? ($base_key . ($slot_index + 1)) : chr(ord('A') + ($slot_index % 26));
        }

        $meeting['section'] = $variant_key;
        $repacked[$variant_key] = [
            'section' => $variant_key,
            'title' => $apply_section_to_title((string)($single_meta['title'] ?? $code_key), $variant_key),
            'meetings' => [$meeting]
        ];
        $slot_index++;
    }

    if (!empty($repacked)) {
        $course_slot_map[$code_key] = $repacked;
        if (!isset($course_section_map[$code_key])) {
            $course_section_map[$code_key] = (string)array_key_first($repacked);
        }
    }
}

// Auto-sync detected courses into catalog to ensure students always see enrollable rows
if (!empty($detected_catalog)) {
    $sync_stmt = $conn->prepare("INSERT IGNORE INTO courses (course_code, course_title, credit_hours, level, semester, department)
                                 VALUES (?, ?, 3, ?, ?, ?)");
    if ($sync_stmt) {
        foreach ($detected_catalog as $meta) {
            $ccode = (string)$meta['course_code'];
            $ctitle = (string)$meta['course_title'];
            $clevel = (int)($meta['level'] ?? $level);
            $csemester = $semester;
            $cdept = (string)($meta['department'] ?: ($department ?: 'General'));
            $sync_stmt->bind_param('ssiss', $ccode, $ctitle, $clevel, $csemester, $cdept);
            $sync_stmt->execute();
        }
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

// Base course pool: all catalog courses (no level/semester restriction)
$course_pool = [];
$pool_sql = "SELECT c.id, c.course_code, c.course_title, c.level, c.credit_hours, c.department,
                    l.name AS lecturer_name
             FROM courses c
             LEFT JOIN lecturers l ON c.lecturer_id = l.id
             ORDER BY c.level ASC, c.course_code ASC";
$pool_res = $conn->query($pool_sql);
if ($pool_res) {
    while ($row = $pool_res->fetch_assoc()) {
        $course_pool[] = $row;
    }
}

$known_sections_by_code = [];
if ($has_sections_table && $has_sections_section_name) {
    $known_sections_sql = "SELECT c.course_code, s.section_name
                           FROM sections s
                           JOIN courses c ON s.course_id = c.id";
    $known_sections_res = $conn->query($known_sections_sql);
    if ($known_sections_res) {
        while ($sec_row = $known_sections_res->fetch_assoc()) {
            $sec_code = strtoupper(trim((string)($sec_row['course_code'] ?? '')));
            $sec_name = $normalize_section_key((string)($sec_row['section_name'] ?? ''), '');
            if ($sec_code === '' || $sec_name === '' || $sec_name === 'DEFAULT') {
                continue;
            }
            if (!isset($known_sections_by_code[$sec_code])) {
                $known_sections_by_code[$sec_code] = [];
            }
            $known_sections_by_code[$sec_code][$sec_name] = true;
        }
    }
}

foreach ($course_pool as $pool_course) {
    $pool_code = strtoupper(trim((string)($pool_course['course_code'] ?? '')));
    if ($pool_code === '') {
        continue;
    }
    $pool_title = trim((string)($pool_course['course_title'] ?? ''));
    if ($pool_title === '') {
        continue;
    }
    if (!isset($titles_by_code[$pool_code])) {
        $titles_by_code[$pool_code] = [];
    }
    $titles_by_code[$pool_code][$pool_title] = true;
}

// Enrich titles map from all generated schedule snapshots for the active term.
// This helps surface sections that may exist in older/other snapshots but not in the latest detected row set.
$has_generated_academic_year = $column_exists($conn, 'generated_schedules', 'academic_year');
$all_generated_sql = "SELECT schedule_data
                      FROM generated_schedules
                      WHERE (schedule_name NOT LIKE 'exam_%' OR schedule_name IS NULL)
                        AND (semester = ? OR semester IS NULL OR TRIM(semester) = '')";
if ($has_generated_academic_year && $academic_year !== '') {
    $all_generated_sql .= " AND academic_year = ?";
}

$all_generated_stmt = $conn->prepare($all_generated_sql);
if ($all_generated_stmt) {
    if ($has_generated_academic_year && $academic_year !== '') {
        $all_generated_stmt->bind_param('ss', $semester, $academic_year);
    } else {
        $all_generated_stmt->bind_param('s', $semester);
    }
    $all_generated_stmt->execute();
    $all_generated_res = $all_generated_stmt->get_result();

    while ($all_generated_res && ($generated_row = $all_generated_res->fetch_assoc())) {
        $parsed_rows = unified_schedule_extract_rows((string)($generated_row['schedule_data'] ?? ''));
        foreach ($parsed_rows as $parsed_row) {
            $parsed_code = strtoupper(trim((string)($parsed_row['course_code'] ?? ($parsed_row['code'] ?? ''))));
            if ($parsed_code === '') {
                continue;
            }
            $parsed_title = trim((string)($parsed_row['course_title'] ?? ($parsed_row['title'] ?? '')));
            if ($parsed_title === '') {
                continue;
            }
            if (!isset($titles_by_code[$parsed_code])) {
                $titles_by_code[$parsed_code] = [];
            }
            $titles_by_code[$parsed_code][$parsed_title] = true;
        }
    }
}

foreach ($titles_by_code as $title_code => $title_set) {
    $title_code = strtoupper(trim((string)$title_code));
    if ($title_code === '' || empty($title_set) || !is_array($title_set)) {
        continue;
    }

    foreach (array_keys($title_set) as $candidate_title) {
        $title_section = $normalize_section_key('', (string)$candidate_title);
        if ($title_section === '' || $title_section === 'DEFAULT') {
            continue;
        }
        if (!isset($known_sections_by_code[$title_code])) {
            $known_sections_by_code[$title_code] = [];
        }
        $known_sections_by_code[$title_code][$title_section] = true;
    }
}

// Handle actions (after schedule/slot maps & enrolled courses are known)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $is_ajax_request = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

    $redirect_with_message = static function (string $msg, string $type = 'success') use ($show_completed, $is_ajax_request): void {
        if ($is_ajax_request) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'message' => $msg,
                'type' => $type
            ]);
            exit;
        }

        $_SESSION['my_courses_flash'] = [
            'message' => $msg,
            'type' => $type
        ];

        $params = [];
        if ($show_completed) {
            $params['show_completed'] = '1';
        }
        $target = 'my_courses.php';
        if (!empty($params)) {
            $target .= '?' . http_build_query($params);
        }

        if (!headers_sent()) {
            header('Location: ' . $target);
        } else {
            echo '<script>window.location.href=' . json_encode($target) . ';</script>';
            echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($target, ENT_QUOTES, 'UTF-8') . '"></noscript>';
        }
        exit;
    };

    $action = $_POST['action'] ?? '';
    if (isset($_POST['enroll'])) $action = 'enroll';
    if (isset($_POST['unenroll'])) $action = 'unenroll';
    if (isset($_POST['mark_done'])) $action = 'mark_done';
    if (isset($_POST['unmark_done'])) $action = 'unmark_done';

    $course_id = (int)($_POST['course_id'] ?? 0);
    $enrollment_id = (int)($_POST['enrollment_id'] ?? 0);

    if ($course_id > 0 && $action === 'enroll') {
        $code_lookup = '';
        $title_lookup = '';
        $c_stmt = $conn->prepare("SELECT course_code, course_title FROM courses WHERE id = ? LIMIT 1");
        if ($c_stmt) {
            $c_stmt->bind_param('i', $course_id);
            $c_stmt->execute();
            $c_res = $c_stmt->get_result();
            if ($c_res && ($c_row = $c_res->fetch_assoc())) {
                $code_lookup = strtoupper(trim((string)($c_row['course_code'] ?? '')));
                $title_lookup = trim((string)($c_row['course_title'] ?? ''));
            }
        }

        $requested_section = $normalize_section_key($_POST['selected_section'] ?? '', '');
        if ($requested_section === 'DEFAULT') {
            $requested_section = '';
        }

        $target_section = $requested_section !== ''
            ? $requested_section
            : ($code_lookup !== '' ? ($course_section_map[$code_lookup] ?? null) : null);

        $selected_title = trim((string)($_POST['selected_title'] ?? ''));
        if ($selected_title === '') {
            $selected_title = $apply_section_to_title($title_lookup, (string)$target_section);
        }

        $target_identity = $build_enrollment_identity((string)$code_lookup, (string)$selected_title, (string)$target_section);
        $identity_exists = false;

        $existing_sql = "SELECT c.course_code, c.course_title, " . ($has_section_column ? "se.section" : "NULL AS section") . "
                         FROM student_enrollments se
                         JOIN courses c ON se.course_id = c.id
                         WHERE se.user_id = ? AND se.semester = ?";
        if ($has_academic_year_column) {
            $existing_sql .= " AND se.academic_year = ?";
        }
        $existing_stmt = $conn->prepare($existing_sql);
        if ($existing_stmt) {
            if ($has_academic_year_column) {
                $existing_stmt->bind_param('iss', $user_id, $semester, $academic_year);
            } else {
                $existing_stmt->bind_param('is', $user_id, $semester);
            }
            $existing_stmt->execute();
            $existing_res = $existing_stmt->get_result();
            while ($existing_res && ($existing_row = $existing_res->fetch_assoc())) {
                $existing_identity = $build_enrollment_identity(
                    (string)($existing_row['course_code'] ?? ''),
                    (string)($existing_row['course_title'] ?? ''),
                    (string)($existing_row['section'] ?? '')
                );
                if ($target_identity !== '' && $existing_identity === $target_identity) {
                    $identity_exists = true;
                    break;
                }
            }
        }

        if (!$identity_exists) {
            $target_meetings = $code_lookup !== '' ? $get_section_meetings($course_slot_map, $code_lookup, $target_section) : [];
            $conflict_reason = '';

            if (!empty($target_meetings)) {
                foreach ($enrolled_courses as $enrolled) {
                    $enrolled_code = strtoupper(trim((string)($enrolled['course_code'] ?? '')));
                    $enrolled_section = $normalize_section_key($enrolled['section'] ?? '', (string)($enrolled['course_title'] ?? ''));
                    $enrolled_meetings = $get_section_meetings($course_slot_map, $enrolled_code, $enrolled_section);
                    if ($enrolled_code === '' || empty($enrolled_meetings)) {
                        continue;
                    }

                    foreach ($target_meetings as $target_meeting) {
                        foreach ($enrolled_meetings as $enrolled_meeting) {
                            if ($meetings_overlap($target_meeting, $enrolled_meeting)) {
                                $conflict_reason = $enrolled_code . ' Day/Time conflicts with another enrolled course.';
                                break 3;
                            }
                        }
                    }
                }
            }

            if ($conflict_reason !== '') {
                $message = '⚠️ Enrollment blocked: ' . $conflict_reason . ' Please pick another course/time.';
                $message_type = 'warning';
            } else {
                if ($has_section_column) {
                    if ($has_academic_year_column) {
                        $stmt = $conn->prepare("INSERT INTO student_enrollments (user_id, course_id, section, semester, academic_year) VALUES (?, ?, ?, ?, ?)");
                    } else {
                        $stmt = $conn->prepare("INSERT INTO student_enrollments (user_id, course_id, section, semester) VALUES (?, ?, ?, ?)");
                    }
                    if ($stmt) {
                        $section_to_store = ($target_section && $target_section !== 'DEFAULT') ? $target_section : null;
                        if ($has_academic_year_column) {
                            $stmt->bind_param('iisss', $user_id, $course_id, $section_to_store, $semester, $academic_year);
                        } else {
                            $stmt->bind_param('iiss', $user_id, $course_id, $section_to_store, $semester);
                        }
                        if ($stmt->execute()) {
                            $redirect_with_message('Successfully enrolled in course.', 'success');
                        } else {
                            $message = 'Enrollment failed. Please try again.';
                            $message_type = 'error';
                        }
                    }
                } else {
                    if ($has_academic_year_column) {
                        $stmt = $conn->prepare("INSERT INTO student_enrollments (user_id, course_id, semester, academic_year) VALUES (?, ?, ?, ?)");
                    } else {
                        $stmt = $conn->prepare("INSERT INTO student_enrollments (user_id, course_id, semester) VALUES (?, ?, ?)");
                    }
                    if ($stmt) {
                        if ($has_academic_year_column) {
                            $stmt->bind_param('iiss', $user_id, $course_id, $semester, $academic_year);
                        } else {
                            $stmt->bind_param('iis', $user_id, $course_id, $semester);
                        }
                        if ($stmt->execute()) {
                            $redirect_with_message('Successfully enrolled in course.', 'success');
                        } else {
                            $message = 'Enrollment failed. Please try again.';
                            $message_type = 'error';
                        }
                    }
                }
            }
        } else {
            $message = 'You are already enrolled in this course section.';
            $message_type = 'warning';
        }
    }

    if ($course_id > 0 && $action === 'unenroll') {
        if ($enrollment_id > 0) {
            $delete_exact = $conn->prepare("DELETE FROM student_enrollments WHERE id = ? AND user_id = ? LIMIT 1");
            if ($delete_exact) {
                $delete_exact->bind_param('ii', $enrollment_id, $user_id);
                if ($delete_exact->execute() && $delete_exact->affected_rows > 0) {
                    $redirect_with_message('Successfully unenrolled from course.', 'success');
                }
            }
        }

        $requested_code = strtoupper(trim((string)($_POST['course_code'] ?? '')));
        $requested_title = trim((string)($_POST['course_title'] ?? ''));
        $requested_section = $normalize_section_key($_POST['selected_section'] ?? '', $requested_title);
        $requested_identity = $build_enrollment_identity($requested_code, $requested_title, $requested_section);

        $find_sql = "SELECT se.id, c.course_code, c.course_title, " . ($has_section_column ? "se.section" : "NULL AS section") . "
                     FROM student_enrollments se
                     JOIN courses c ON se.course_id = c.id
                     WHERE se.user_id = ? AND se.course_id = ? AND se.semester = ?";
        if ($has_academic_year_column) {
            $find_sql .= " AND se.academic_year = ?";
        }
        $find_stmt = $conn->prepare($find_sql);
        $matched_ids = [];
        if ($find_stmt) {
            if ($has_academic_year_column) {
                $find_stmt->bind_param('iiss', $user_id, $course_id, $semester, $academic_year);
            } else {
                $find_stmt->bind_param('iis', $user_id, $course_id, $semester);
            }
            $find_stmt->execute();
            $find_res = $find_stmt->get_result();
            while ($find_res && ($find_row = $find_res->fetch_assoc())) {
                $row_identity = $build_enrollment_identity(
                    (string)($find_row['course_code'] ?? ''),
                    (string)($find_row['course_title'] ?? ''),
                    (string)($find_row['section'] ?? '')
                );
                if ($requested_identity !== '' && $row_identity === $requested_identity) {
                    $matched_ids[] = (int)($find_row['id'] ?? 0);
                }
            }
        }

        if (empty($matched_ids)) {
            $delete_sql = "DELETE FROM student_enrollments WHERE user_id = ? AND course_id = ? AND semester = ?";
            if ($has_section_column && $requested_section !== '') {
                $delete_sql .= " AND UPPER(TRIM(COALESCE(section, ''))) = UPPER(TRIM(?))";
            }
            if ($has_academic_year_column) {
                $delete_sql .= " AND academic_year = ?";
            }
            $stmt = $conn->prepare($delete_sql);
            if ($stmt) {
                if ($has_section_column && $requested_section !== '' && $has_academic_year_column) {
                    $stmt->bind_param('iisss', $user_id, $course_id, $semester, $requested_section, $academic_year);
                } elseif ($has_section_column && $requested_section !== '') {
                    $stmt->bind_param('iiss', $user_id, $course_id, $semester, $requested_section);
                } elseif ($has_academic_year_column) {
                    $stmt->bind_param('iiss', $user_id, $course_id, $semester, $academic_year);
                } else {
                    $stmt->bind_param('iis', $user_id, $course_id, $semester);
                }

                if ($stmt->execute() && $stmt->affected_rows > 0) {
                    $redirect_with_message('Successfully unenrolled from course.', 'success');
                }
            }
        } else {
            $deleted = false;
            $del_stmt = $conn->prepare("DELETE FROM student_enrollments WHERE id = ? LIMIT 1");
            if ($del_stmt) {
                foreach ($matched_ids as $match_id) {
                    if ($match_id <= 0) {
                        continue;
                    }
                    $del_stmt->bind_param('i', $match_id);
                    if ($del_stmt->execute() && $del_stmt->affected_rows > 0) {
                        $deleted = true;
                    }
                }
            }
            if ($deleted) {
                $redirect_with_message('Successfully unenrolled from course.', 'success');
            }
        }

        $message = 'Unenrollment failed. Course section was not found in your current enrollments.';
        $message_type = 'warning';
    }

    if ($course_id > 0 && $action === 'mark_done') {
        $stmt = $conn->prepare("INSERT IGNORE INTO student_completed_courses (user_id, course_id) VALUES (?, ?)");
        if ($stmt) {
            $stmt->bind_param('ii', $user_id, $course_id);
            if ($stmt->execute()) {
                if ($enrollment_id > 0) {
                    $drop = $conn->prepare("DELETE FROM student_enrollments WHERE id = ? AND user_id = ? LIMIT 1");
                    if ($drop) {
                        $drop->bind_param('ii', $enrollment_id, $user_id);
                        $drop->execute();
                    }
                } else {
                    $drop = $conn->prepare("DELETE FROM student_enrollments WHERE user_id = ? AND course_id = ?");
                    if ($drop) {
                        $drop->bind_param('ii', $user_id, $course_id);
                        $drop->execute();
                    }
                }
                $redirect_with_message('Course marked as done. You can restore it anytime.', 'success');
            }
        }
    }

    if ($course_id > 0 && $action === 'unmark_done') {
        $stmt = $conn->prepare("DELETE FROM student_completed_courses WHERE user_id = ? AND course_id = ?");
        if ($stmt) {
            $stmt->bind_param('ii', $user_id, $course_id);
            if ($stmt->execute()) {
                $redirect_with_message('Course removed from done list.', 'success');
            }
        }
    }

    if ($is_ajax_request) {
        header('Content-Type: application/json');
        $fallback_type = in_array($message_type, ['success', 'error', 'warning', 'info'], true) ? $message_type : 'error';
        echo json_encode([
            'success' => false,
            'message' => $message !== '' ? $message : 'Action could not be completed.',
            'type' => $fallback_type
        ]);
        exit;
    }
}

$available_courses = [];
$completed_courses = [];
$enrolled_identity_map = [];
foreach ($enrolled_courses as $enrolled_course) {
    $identity = $build_enrollment_identity(
        (string)($enrolled_course['course_code'] ?? ''),
        (string)($enrolled_course['course_title'] ?? ''),
        (string)($enrolled_course['section'] ?? '')
    );
    if ($identity !== '') {
        $enrolled_identity_map[$identity] = true;
    }
}

$course_by_code = [];
foreach ($course_pool as $course) {
    $code = strtoupper(trim((string)($course['course_code'] ?? '')));
    if ($code !== '' && !isset($course_by_code[$code])) {
        $course_by_code[$code] = $course;
    }
}

foreach ($course_pool as $course) {
    $cid = (int)$course['id'];
    if (isset($completed_course_ids[$cid])) {
        $completed_courses[] = $course;
    }
}

foreach ($course_slot_map as $code => $course_sections) {
    $code = strtoupper(trim((string)$code));
    $catalog_course = $course_by_code[$code] ?? [
        'id' => 0,
        'course_code' => $code,
        'course_title' => $code,
        'level' => $level,
        'credit_hours' => 0,
        'department' => ($course_source_department_map[$code] ?? ($department ?: 'General')),
        'lecturer_name' => 'TBA'
    ];

    $cid = (int)($catalog_course['id'] ?? 0);

    if (isset($completed_course_ids[$cid]) && !$show_completed) {
        continue;
    }

    $section_options = [];

    foreach ($course_sections as $sectionKey => $sectionMeta) {
        $meetings = $sectionMeta['meetings'] ?? [];
        if (empty($meetings)) {
            continue;
        }

        $option_title = trim((string)($sectionMeta['title'] ?? ($catalog_course['course_title'] ?? $code)));
        $option_section_key = $normalize_section_key($sectionMeta['section'] ?? '', $option_title);
        if ($option_section_key === '') {
            $option_section_key = (string)$sectionKey;
        }

        $option_score = 0;
        $option_personal_conflict = false;
        $option_enrollment_conflict = false;
        $option_already_enrolled = false;

        $option_identity = $build_enrollment_identity($code, $option_title, (string)$option_section_key);
        if ($option_identity !== '' && isset($enrolled_identity_map[$option_identity])) {
            $option_score += 1000;
            $option_enrollment_conflict = true;
            $option_already_enrolled = true;
        }

        foreach ($meetings as $meeting) {
            $meeting_day = trim((string)($meeting['day'] ?? ''));
            $meeting_time = trim((string)($meeting['time'] ?? ''));

            if ($has_personal_conflict($meeting_day, $meeting_time, $personal_events)) {
                $option_score += 25;
                $option_personal_conflict = true;
            }

            foreach ($enrolled_courses as $enrolled) {
                $enrolled_code = strtoupper(trim((string)($enrolled['course_code'] ?? '')));
                if ($enrolled_code === '' || $enrolled_code === $code) {
                    continue;
                }

                $enrolled_section = $normalize_section_key($enrolled['section'] ?? '', (string)($enrolled['course_title'] ?? ''));
                $enrolled_meetings = $get_section_meetings($course_slot_map, $enrolled_code, $enrolled_section);
                foreach ($enrolled_meetings as $enrolled_meeting) {
                    if ($meetings_overlap($meeting, $enrolled_meeting)) {
                        $option_score += 100;
                        $option_enrollment_conflict = true;
                        break 2;
                    }
                }
            }
        }

        $conflict_messages = [];
        if ($option_already_enrolled) {
            $conflict_messages[] = 'Already enrolled in this section';
        }
        if ($option_enrollment_conflict) {
            $conflict_messages[] = 'Clashes with enrolled course';
        }
        if ($option_personal_conflict) {
            $conflict_messages[] = 'Clashes with personal event';
        }

        $section_options[] = [
            'key' => (string)$option_section_key,
            'label' => $format_section_label((string)$option_section_key),
            'summary' => $summarize_meetings($meetings),
            'meetings' => $meetings,
            'score' => $option_score,
            'title' => $option_title,
            'has_enrollment_conflict' => $option_enrollment_conflict,
            'has_personal_conflict' => $option_personal_conflict,
            'conflict_label' => implode(' | ', $conflict_messages)
        ];
    }

    $existing_option_keys = [];
    foreach ($section_options as $opt) {
        $existing_option_keys[(string)($opt['key'] ?? 'DEFAULT')] = true;
    }

    $template_meetings = [];
    if (!empty($section_options[0]['meetings'])) {
        $template_meetings = $section_options[0]['meetings'];
    } else {
        foreach ($course_sections as $meta) {
            if (!empty($meta['meetings'])) {
                $template_meetings = $meta['meetings'];
                break;
            }
        }
    }

    $known_keys_for_code = array_keys($known_sections_by_code[$code] ?? []);
    foreach ($known_keys_for_code as $known_key) {
        $known_key = strtoupper(trim((string)$known_key));
        if ($known_key === '' || $known_key === 'DEFAULT' || isset($existing_option_keys[$known_key])) {
            continue;
        }

        $known_meetings = $get_section_meetings($course_slot_map, $code, $known_key);
        if (empty($known_meetings)) {
            $known_meetings = $template_meetings;
        }

        $option_score = 0;
        $option_personal_conflict = false;
        $option_enrollment_conflict = false;
        $option_already_enrolled = false;

        $known_identity = $build_enrollment_identity($code, (string)($catalog_course['course_title'] ?? $code), $known_key);
        if ($known_identity !== '' && isset($enrolled_identity_map[$known_identity])) {
            $option_score += 1000;
            $option_enrollment_conflict = true;
            $option_already_enrolled = true;
        }

        foreach ($known_meetings as $meeting) {
            $meeting_day = trim((string)($meeting['day'] ?? ''));
            $meeting_time = trim((string)($meeting['time'] ?? ''));

            if ($has_personal_conflict($meeting_day, $meeting_time, $personal_events)) {
                $option_score += 25;
                $option_personal_conflict = true;
            }

            foreach ($enrolled_courses as $enrolled) {
                $enrolled_code = strtoupper(trim((string)($enrolled['course_code'] ?? '')));
                if ($enrolled_code === '' || $enrolled_code === $code) {
                    continue;
                }

                $enrolled_section = $normalize_section_key($enrolled['section'] ?? '', (string)($enrolled['course_title'] ?? ''));
                $enrolled_meetings = $get_section_meetings($course_slot_map, $enrolled_code, $enrolled_section);
                foreach ($enrolled_meetings as $enrolled_meeting) {
                    if ($meetings_overlap($meeting, $enrolled_meeting)) {
                        $option_score += 100;
                        $option_enrollment_conflict = true;
                        break 2;
                    }
                }
            }
        }

        $conflict_messages = [];
        if ($option_already_enrolled) {
            $conflict_messages[] = 'Already enrolled in this section';
        }
        if ($option_enrollment_conflict) {
            $conflict_messages[] = 'Clashes with enrolled course';
        }
        if ($option_personal_conflict) {
            $conflict_messages[] = 'Clashes with personal event';
        }

        $section_options[] = [
            'key' => $known_key,
            'label' => $format_section_label($known_key),
            'summary' => $summarize_meetings($known_meetings),
            'meetings' => $known_meetings,
            'score' => $option_score,
            'title' => $apply_section_to_title((string)($catalog_course['course_title'] ?? $code), $known_key),
            'has_enrollment_conflict' => $option_enrollment_conflict,
            'has_personal_conflict' => $option_personal_conflict,
            'conflict_label' => implode(' | ', $conflict_messages)
        ];
    }

    if (empty($section_options)) {
        continue;
    }

    usort($section_options, static function ($a, $b) {
        return ((int)($a['score'] ?? 0) <=> (int)($b['score'] ?? 0))
            ?: strcmp((string)($a['label'] ?? ''), (string)($b['label'] ?? ''));
    });

    $preferred_option = null;
    foreach ($section_options as $option) {
        if (empty($option['has_enrollment_conflict'])) {
            $preferred_option = $option;
            break;
        }
    }
    if ($preferred_option === null) {
        $preferred_option = $section_options[0];
    }

    $row_course = $catalog_course;
    $row_course['course_code'] = $code;
    $row_course['course_title'] = (string)($preferred_option['title'] ?? ($catalog_course['course_title'] ?? $code));
    $row_course['section_options'] = $section_options;
    $row_course['selected_section'] = (string)($preferred_option['key'] ?? 'DEFAULT');
    $row_course['selected_section_label'] = (string)($preferred_option['label'] ?? 'Sec A');
    $row_course['selected_summary'] = (string)($preferred_option['summary'] ?? 'TBA');
    $row_course['personal_conflict'] = !empty($preferred_option['has_personal_conflict']);
    $row_course['recommendation_score'] = max(0, 1000 - (int)($preferred_option['score'] ?? 0));
    $row_course['detected_source'] = $course_source_map[$code] ?? 'fallback';
    $row_course['source_department'] = $course_source_department_map[$code] ?? ($catalog_course['department'] ?? 'General');

    $available_courses[] = $row_course;
}

usort($available_courses, static function ($a, $b) {
    return ($b['recommendation_score'] ?? 0) <=> ($a['recommendation_score'] ?? 0);
});

$enrolled_count = count($enrolled_courses);
$available_count = count($available_courses);
$completed_count = count($completed_courses);
$detected_count = count($detected_codes);

include 'includes/header.php';
?>

<style>
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
.section-select { min-width: 160px; width: 100%; }
.section-meta { display: flex; flex-direction: column; gap: 0.45rem; }
</style>

<?php if (!empty($message)): ?>
<script>
window.addEventListener('load', () => {
    const msg = <?php echo json_encode((string)$message); ?>;
    const type = <?php echo json_encode(in_array($message_type, ['success', 'error', 'warning', 'info'], true) ? $message_type : 'info'); ?>;
    let title = 'Notice';
    if (type === 'success') title = 'Success';
    if (type === 'error') title = 'Error';
    if (type === 'warning') title = 'Warning';
    showAlert(msg, title, type);
});
</script>
<?php endif; ?>

<div class="glass-panel" style="padding: 1.2rem; margin-bottom: 1rem; background: linear-gradient(135deg, rgba(79,70,229,0.12), rgba(236,72,153,0.08));">
    <p style="margin: 0; color: var(--text-muted);">
        Course availability now shows the full catalog across levels and semesters.
        You can enroll from all listed courses while your timetable conflict checks still apply.
    </p>
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
                        <th>Section</th>
                        <th>Level</th>
                        <th>Credit Hrs</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($enrolled_courses as $course): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($course['course_code']); ?></strong></td>
                        <td><?php echo htmlspecialchars(trim(preg_replace('/\[\s*sec(?:tion)?\s*[a-z0-9]+\s*\]/i', '', $course['course_title']))); ?></td>
                        <td>
                            <?php
                            $enrolled_section = strtoupper(trim((string)($course['section'] ?? '')));
                            if ($enrolled_section !== '' && stripos($enrolled_section, 'SEC ') !== 0) {
                                $enrolled_section = 'Sec ' . $enrolled_section;
                            }
                            echo htmlspecialchars($enrolled_section !== '' ? $enrolled_section : '-');
                            ?>
                        </td>
                        <td><?php echo (int)$course['level']; ?></td>
                        <td><?php echo (int)($course['credit_hours'] ?? 0); ?></td>
                        <td>
                            <div class="action-row">
                                <form method="POST" onsubmit="event.preventDefault(); showConfirm('Unenroll from this course?', 'Unenroll Course').then(result => { if(result) this.submit(); });">
                                    <input type="hidden" name="action" value="unenroll">
                                    <input type="hidden" name="course_id" value="<?php echo (int)$course['id']; ?>">
                                    <input type="hidden" name="enrollment_id" value="<?php echo (int)($course['enrollment_id'] ?? 0); ?>">
                                    <input type="hidden" name="course_code" value="<?php echo htmlspecialchars((string)($course['course_code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="course_title" value="<?php echo htmlspecialchars((string)($course['course_title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="selected_section" value="<?php echo htmlspecialchars((string)$normalize_section_key((string)($course['section'] ?? ''), (string)($course['course_title'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>">
                                    <button type="submit" name="unenroll" class="small-btn btn-unenroll"><i class="fa-solid fa-user-minus"></i> Unenroll</button>
                                </form>
                                <form method="POST" onsubmit="event.preventDefault(); showConfirm('Mark this course as done? It will be removed from active enrollment.', 'Mark Complete').then(result => { if(result) this.submit(); });">
                                    <input type="hidden" name="action" value="mark_done">
                                    <input type="hidden" name="course_id" value="<?php echo (int)$course['id']; ?>">
                                    <input type="hidden" name="enrollment_id" value="<?php echo (int)($course['enrollment_id'] ?? 0); ?>">
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
        <h3 style="margin:0;"><i class="fa-solid fa-plus-circle"></i> Available Courses</h3>
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
                        <th>Section / Date / Time</th>
                        <th>Source</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($available_courses as $row_index => $course): ?>
                    <?php $cid = (int)$course['id']; ?>
                    <?php $row_uid = $cid . '-' . (int)$row_index; ?>
                    <?php
                    $section_options = $course['section_options'] ?? [];
                    $selected_section = (string)($course['selected_section'] ?? 'DEFAULT');
                    $selected_summary = (string)($course['selected_summary'] ?? ($course['detected_time'] ?? 'TBA'));
                    $section_options_for_js = array_map(static function ($opt) {
                        return [
                            'value' => (string)($opt['key'] ?? 'DEFAULT'),
                            'label' => (string)($opt['label'] ?? 'Sec A'),
                            'summary' => (string)($opt['summary'] ?? 'TBA'),
                            'title' => (string)($opt['title'] ?? ''),
                            'enrollmentConflict' => !empty($opt['has_enrollment_conflict']),
                            'conflictLabel' => (string)($opt['conflict_label'] ?? ''),
                        ];
                    }, $section_options);
                    $section_options_json = htmlspecialchars((string)json_encode($section_options_for_js, JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
                    ?>
                    <tr data-course-code="<?php echo htmlspecialchars((string)$course['course_code']); ?>" data-course-title="<?php echo htmlspecialchars((string)$course['course_title']); ?>" data-section-options="<?php echo $section_options_json; ?>">
                        <td><strong><?php echo htmlspecialchars($course['course_code']); ?></strong></td>
                        <td id="course-title-<?php echo $row_uid; ?>"><?php echo htmlspecialchars((string)$course['course_title']); ?></td>
                        <?php
                        $single_opt = $section_options[0] ?? ['key' => 'DEFAULT', 'summary' => $selected_summary];
                        $single_key = (string)($single_opt['key'] ?? 'DEFAULT');
                        $single_summary = (string)($single_opt['summary'] ?? $selected_summary);
                        if ($single_summary !== '') {
                            $selected_summary = $single_summary;
                        }
                        ?>
                        <td>
                            <div class="section-meta">
                                <select
                                    class="glass-input js-section-select section-select"
                                    name="selected_section"
                                    form="enroll-form-<?php echo $row_uid; ?>"
                                    data-summary-target="section-summary-<?php echo $row_uid; ?>"
                                >
                                    <?php foreach ($section_options as $opt): ?>
                                        <?php
                                        $opt_value = (string)($opt['key'] ?? 'DEFAULT');
                                        $opt_summary = (string)($opt['summary'] ?? 'TBA');
                                        $opt_conflict = !empty($opt['has_enrollment_conflict']);
                                        $opt_conflict_label = (string)($opt['conflict_label'] ?? '');
                                        $opt_title = (string)($opt['title'] ?? $course['course_title']);
                                        ?>
                                        <option
                                            value="<?php echo htmlspecialchars($opt_value); ?>"
                                            data-summary="<?php echo htmlspecialchars($opt_summary, ENT_QUOTES, 'UTF-8'); ?>"
                                            data-conflict-label="<?php echo htmlspecialchars($opt_conflict_label, ENT_QUOTES, 'UTF-8'); ?>"
                                            data-title="<?php echo htmlspecialchars($opt_title, ENT_QUOTES, 'UTF-8'); ?>"
                                            data-enrollment-conflict="<?php echo $opt_conflict ? '1' : '0'; ?>"
                                            <?php echo $opt_value === $selected_section ? 'selected' : ''; ?>
                                        >
                                            <?php echo htmlspecialchars((string)($opt['label'] ?? 'Sec A')); ?><?php echo $opt_conflict ? ' (Unavailable)' : ''; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <span class="badge-pill badge-ai section-summary" id="section-summary-<?php echo $row_uid; ?>"><?php echo htmlspecialchars($selected_summary); ?></span>
                            </div>
                        </td>
                        <td>
                            <?php
                            $source_key = strtolower(trim((string)($course['detected_source'] ?? 'general')));
                            if ($source_key === 'department') {
                                $source_label = 'Department';
                            } elseif ($source_key === 'interdepartment') {
                                $source_label = 'Interdepartment';
                            } elseif ($source_key === 'general') {
                                $source_label = 'General';
                            } else {
                                $source_label = 'Other';
                            }
                            $source_dept = trim((string)($course['source_department'] ?? 'General'));
                            ?>
                            <span class="badge-pill badge-ai"><?php echo htmlspecialchars($source_label); ?></span>
                            <div style="font-size:0.79rem; color: var(--text-muted);">
                                <?php echo htmlspecialchars($source_dept); ?>
                            </div>
                        </td>
                        <td>
                            <div class="action-row">
                                <form method="POST" class="js-async-enroll-form" id="enroll-form-<?php echo $row_uid; ?>">
                                    <input type="hidden" name="action" value="enroll">
                                    <input type="hidden" name="course_id" value="<?php echo $cid; ?>">
                                    <input type="hidden" name="selected_title" class="js-selected-title" value="<?php echo htmlspecialchars((string)$course['course_title'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <button type="submit" name="enroll" class="small-btn btn-enroll"><i class="fa-solid fa-user-plus"></i> Enroll</button>
                                </form>
                                <form method="POST" onsubmit="event.preventDefault(); showConfirm('Mark this as done/completed?', 'Mark Complete').then(result => { if(result) this.submit(); });">
                                    <input type="hidden" name="action" value="mark_done">
                                    <input type="hidden" name="course_id" value="<?php echo $cid; ?>">
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
                        <td><?php echo htmlspecialchars(trim(preg_replace('/\[\s*sec(?:tion)?\s*[a-z0-9]+\s*\]/i', '', $course['course_title']))); ?></td>
                        <td><span class="badge-pill badge-done">Done</span></td>
                        <td>
                            <form method="POST">
                                <input type="hidden" name="action" value="unmark_done">
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

function showSectionSelectionPopup(courseCode, courseTitle, rawOptions, currentValue) {
    const options = Array.isArray(rawOptions) ? rawOptions : [];
    if (options.length <= 1) {
        return Promise.resolve(currentValue || (options[0]?.value || 'DEFAULT'));
    }

    return new Promise((resolve) => {
        const modal = document.createElement('div');
        modal.className = 'custom-modal-overlay';
        modal.innerHTML = `
            <div class="custom-modal" role="dialog" aria-modal="true" style="max-width: 560px;">
                <div class="custom-modal-header">
                    <h3 class="custom-modal-title">Select Section & Time</h3>
                    <button class="custom-modal-close" type="button">&times;</button>
                </div>
                <div class="custom-modal-body">
                    <p style="margin-top: 0; color: var(--text-muted);"><strong>${courseCode}</strong> - ${courseTitle}</p>
                    <label for="enrollSectionModalSelect" style="display:block; margin-bottom:0.4rem; font-weight:600;">Available options</label>
                    <select id="enrollSectionModalSelect" class="glass-input" style="width:100%; margin-bottom:0.65rem;"></select>
                    <div id="enrollSectionModalSummary" class="badge-pill badge-ai" style="display:block; width:100%; text-align:left;">TBA</div>
                    <p style="margin:0.65rem 0 0; font-size:0.85rem; color: var(--text-muted);">Options that clash with enrolled courses are disabled.</p>
                </div>
                <div class="custom-modal-footer">
                    <button type="button" class="glass-btn glass-btn-secondary" id="enrollSectionCancel">Cancel</button>
                    <button type="button" class="glass-btn" id="enrollSectionOk">Continue</button>
                </div>
            </div>`;

        document.body.appendChild(modal);
        document.body.classList.add('modal-open');

        const closeBtn = modal.querySelector('.custom-modal-close');
        const cancelBtn = modal.querySelector('#enrollSectionCancel');
        const okBtn = modal.querySelector('#enrollSectionOk');
        const select = modal.querySelector('#enrollSectionModalSelect');
        const summary = modal.querySelector('#enrollSectionModalSummary');

        const validOptions = [];
        options.forEach((opt) => {
            const optionEl = document.createElement('option');
            optionEl.value = opt.value;
            optionEl.textContent = opt.label;
            optionEl.dataset.summary = opt.summary || 'TBA';
            optionEl.dataset.enrollmentConflict = opt.enrollmentConflict ? '1' : '0';
            optionEl.dataset.conflictLabel = opt.conflictLabel || '';
            if (opt.enrollmentConflict) {
                optionEl.disabled = true;
            } else {
                validOptions.push(opt.value);
            }
            select.appendChild(optionEl);
        });

        const setInitial = () => {
            if (currentValue && validOptions.includes(currentValue)) {
                select.value = currentValue;
            } else if (validOptions.length > 0) {
                select.value = validOptions[0];
            }
        };

        const refreshSummary = () => {
            const chosen = select.options[select.selectedIndex];
            if (!chosen) {
                summary.textContent = 'No available section without conflict.';
                okBtn.disabled = true;
                return;
            }
            const conflictLabel = chosen.dataset.conflictLabel || '';
            const summaryText = chosen.dataset.summary || 'TBA';
            summary.textContent = conflictLabel ? `${summaryText} | ${conflictLabel}` : summaryText;
            okBtn.disabled = chosen.dataset.enrollmentConflict === '1';
        };

        const cleanup = () => {
            document.body.classList.remove('modal-open');
            modal.remove();
        };

        const cancel = () => {
            cleanup();
            resolve(null);
        };

        const confirm = () => {
            const chosen = select.options[select.selectedIndex];
            if (!chosen || chosen.dataset.enrollmentConflict === '1') {
                return;
            }
            cleanup();
            resolve(chosen.value || null);
        };

        setInitial();
        refreshSummary();

        select.addEventListener('change', refreshSummary);
        closeBtn?.addEventListener('click', cancel);
        cancelBtn?.addEventListener('click', cancel);
        okBtn?.addEventListener('click', confirm);
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                cancel();
            }
        });
    });
}

const initAsyncEnroll = () => {
    const forms = document.querySelectorAll('.js-async-enroll-form');
    forms.forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();

            const submitBtn = form.querySelector('button[name="enroll"]');
            const originalHtml = submitBtn ? submitBtn.innerHTML : '';
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Enrolling...';
            }

            try {
                const formData = new FormData(form);
                const row = form.closest('tr');
                const linkedSectionSelect = form.id
                    ? document.querySelector(`.js-section-select[form="${form.id}"]`)
                    : null;

                let modalOptions = [];
                if (row && row.dataset.sectionOptions) {
                    try {
                        const parsed = JSON.parse(row.dataset.sectionOptions);
                        if (Array.isArray(parsed)) {
                            modalOptions = parsed;
                        }
                    } catch (e) {
                        modalOptions = [];
                    }
                }

                if (modalOptions.length === 0 && linkedSectionSelect) {
                    modalOptions = Array.from(linkedSectionSelect.options).map((opt) => ({
                        value: opt.value,
                        label: opt.textContent || opt.value,
                        summary: opt.dataset.summary || 'TBA',
                        enrollmentConflict: opt.dataset.enrollmentConflict === '1',
                        conflictLabel: opt.dataset.conflictLabel || ''
                    }));
                }

                if (modalOptions.length > 1 && !linkedSectionSelect) {
                    const courseCode = row?.dataset.courseCode || row?.querySelector('td strong')?.textContent?.trim() || 'Course';
                    const courseTitle = row?.dataset.courseTitle || row?.querySelector('td:nth-child(2)')?.textContent?.trim() || '';
                    const currentValue = linkedSectionSelect?.value || formData.get('selected_section') || modalOptions[0]?.value || 'DEFAULT';
                    const pickedSection = await showSectionSelectionPopup(courseCode, courseTitle, modalOptions, String(currentValue));
                    if (!pickedSection) {
                        return;
                    }
                    if (linkedSectionSelect) {
                        linkedSectionSelect.value = pickedSection;
                    }

                    const summaryTargetId = linkedSectionSelect?.getAttribute('data-summary-target');
                    if (summaryTargetId) {
                        const summaryTarget = document.getElementById(summaryTargetId);
                        const pickedOption = linkedSectionSelect ? linkedSectionSelect.options[linkedSectionSelect.selectedIndex] : null;
                        if (summaryTarget && pickedOption) {
                            const summaryText = pickedOption.dataset.summary || 'TBA';
                            const conflictText = pickedOption.dataset.conflictLabel || '';
                            summaryTarget.textContent = conflictText ? `${summaryText} | ${conflictText}` : summaryText;
                        }
                    }

                    formData.set('selected_section', pickedSection);
                }

                if (linkedSectionSelect && linkedSectionSelect.value) {
                    formData.set('selected_section', linkedSectionSelect.value);
                    const chosenTitle = linkedSectionSelect.options[linkedSectionSelect.selectedIndex]?.dataset?.title || '';
                    if (chosenTitle) {
                        formData.set('selected_title', chosenTitle);
                    }
                }

                if (!formData.get('selected_title')) {
                    const fallbackTitle = row?.dataset.courseTitle || '';
                    if (fallbackTitle) {
                        formData.set('selected_title', fallbackTitle);
                    }
                }

                const response = await fetch('my_courses.php', {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formData
                });

                const payload = await response.json();
                if (payload && payload.success) {
                    const type = payload.type || 'success';
                    const msg = payload.message || 'Successfully enrolled in course.';
                    await showAlert(msg, type === 'warning' ? 'Warning' : 'Success', type);
                    window.location.reload();
                    return;
                }

                const type = payload && payload.type ? payload.type : 'error';
                const msg = payload && payload.message ? payload.message : 'Enrollment failed. Please try again.';
                await showAlert(msg, type === 'warning' ? 'Warning' : 'Error', type);
            } catch (error) {
                await showAlert('Enrollment failed. Please try again.', 'Error', 'error');
            } finally {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalHtml;
                }
            }
        });
    });
};

document.addEventListener('DOMContentLoaded', initAsyncEnroll);

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.js-section-select').forEach((select) => {
        const syncSummary = () => {
            const targetId = select.getAttribute('data-summary-target');
            if (!targetId) return;
            const target = document.getElementById(targetId);
            if (!target) return;
            const selectedOption = select.options[select.selectedIndex];
            const summary = selectedOption?.dataset?.summary || 'TBA';
            const conflictText = selectedOption?.dataset?.conflictLabel || '';
            target.textContent = conflictText ? `${summary} | ${conflictText}` : summary;
            
            const titleTargetId = 'course-title-' + targetId.replace('section-summary-', '');
            const titleTarget = document.getElementById(titleTargetId);
            if (titleTarget && selectedOption?.dataset?.title) {
                titleTarget.textContent = selectedOption.dataset.title;
            }

            const formId = select.getAttribute('form');
            if (formId) {
                const form = document.getElementById(formId);
                const hiddenTitleInput = form ? form.querySelector('.js-selected-title') : null;
                if (hiddenTitleInput) {
                    hiddenTitleInput.value = selectedOption?.dataset?.title || hiddenTitleInput.value;
                }
            }
        };

        select.addEventListener('change', syncSummary);
        syncSummary();
    });
});
</script>

<?php include 'includes/footer.php'; ?>
