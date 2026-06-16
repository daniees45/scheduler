<?php

/**
 * Split a schedule course-code cell that may join multiple codes with
 * slashes (e.g. "COCS 364 / INFT 364" or "COCS 364 \/ INFT 364").
 * Returns an array of upper-cased, trimmed individual codes.
 */
function unified_schedule_split_codes(string $raw): array
{
    $parts = preg_split('/\s*(?:\\\\\/|\/)\s*/', $raw);
    $out = [];
    foreach ($parts as $p) {
        $p = strtoupper(trim($p));
        if ($p !== '') {
            $out[] = $p;
        }
    }
    return $out ?: [strtoupper(trim($raw))];
}

/**
 * Return true if any of the codes in the slash-combined $raw cell
 * appears in the $enrolled_codes lookup map.
 */
function unified_schedule_code_matches(string $raw, array $enrolled_codes): bool
{
    foreach (unified_schedule_split_codes($raw) as $code) {
        if (isset($enrolled_codes[$code])) {
            return true;
        }
    }
    return false;
}

function unified_schedule_normalize_level($value): int
{
    if (is_int($value)) {
        return $value === 1 ? 100 : $value;
    }

    $raw = trim((string)$value);
    if ($raw === '') {
        return 0;
    }

    if (preg_match('/\d+/', $raw, $m)) {
        $num = (int)$m[0];
        return $num === 1 ? 100 : $num;
    }

    return 0;
}

function unified_schedule_normalize_department($department): string
{
    $dep = strtolower(trim((string)$department));
    if ($dep === '' || $dep === 'general') {
        return 'general';
    }
    return $dep;
}

function unified_schedule_normalize_row_keys(array $row): array
{
    $normalized = [];
    foreach ($row as $key => $value) {
        $k = strtolower(trim((string)$key));
        $k = preg_replace('/[\s\-]+/', '_', $k);
        $k = preg_replace('/_+/', '_', $k);
        if ($k === '') {
            continue;
        }
        $normalized[$k] = $value;
    }
    return $normalized;
}

function unified_schedule_alias_row_fields(array $row): array
{
    $pick = static function (array $source, array $keys, string $default = ''): string {
        foreach ($keys as $key) {
            if (array_key_exists($key, $source)) {
                $val = trim((string)$source[$key]);
                if ($val !== '') {
                    return $val;
                }
            }
        }
        return $default;
    };

    $courseCode = $pick($row, ['course_code', 'code']);
    if ($courseCode !== '' && empty($row['course_code'])) {
        $row['course_code'] = $courseCode;
    }

    $courseTitle = $pick($row, ['course_title', 'title']);
    if ($courseTitle !== '' && empty($row['course_title'])) {
        $row['course_title'] = $courseTitle;
    }

    $lecturerName = $pick($row, ['lecturer_name', 'lecturer']);
    if ($lecturerName !== '' && empty($row['lecturer_name'])) {
        $row['lecturer_name'] = $lecturerName;
    }

    $roomName = $pick($row, ['room_name', 'room']);
    if ($roomName !== '') {
        if (empty($row['room_name'])) {
            $row['room_name'] = $roomName;
        }
        if (empty($row['room'])) {
            $row['room'] = $roomName;
        }
    }

    $startTime = $pick($row, ['start_time', 'assigned_time', 'time']);
    if ($startTime !== '') {
        if (empty($row['start_time'])) {
            $row['start_time'] = $startTime;
        }
        if (empty($row['time'])) {
            $row['time'] = $startTime;
        }
    }

    $enrollment = $pick($row, ['enrollment', 'no_of_students', 'students']);
    if ($enrollment !== '' && empty($row['enrollment'])) {
        $row['enrollment'] = $enrollment;
    }

    $section = $pick($row, ['section', 'sec', 'class_section', 'class_group']);
    
    // If section is still empty, try to extract from title or code like "[SEC A]" or "SEC A"
    if ($section === '') {
        $search_text = ($row['course_title'] ?? '') . ' ' . ($row['course_code'] ?? '');
        if (preg_match('/\[SEC\s*([A-Z0-9]+)\]/i', $search_text, $m)) {
            $section = strtoupper($m[1]);
        } elseif (preg_match('/\bSEC\s*([A-Z0-9]+)\b/i', $search_text, $m)) {
            $section = strtoupper($m[1]);
        }
    }

    if ($section !== '' && empty($row['section'])) {
        $row['section'] = $section;
    }

    return $row;
}

function unified_schedule_extract_rows($schedule_data): array
{
    $rows_out = [];
    $decoded = json_decode((string)$schedule_data, true);

    if (is_array($decoded) && !empty($decoded)) {
        $first = $decoded[0] ?? null;

        if (is_array($first) && isset($first[0]) && is_string($first[0])) {
            $headers = array_map(static function ($h) {
                $k = strtolower(trim((string)$h, "\" "));
                $k = preg_replace('/[\s\-]+/', '_', $k);
                $k = preg_replace('/_+/', '_', $k);
                return $k;
            }, $first);

            for ($i = 1; $i < count($decoded); $i++) {
                if (!is_array($decoded[$i])) {
                    continue;
                }
                $assoc = [];
                foreach ($headers as $idx => $header) {
                    $assoc[$header] = $decoded[$i][$idx] ?? '';
                }
                $assoc = array_change_key_case($assoc, CASE_LOWER);
                $assoc = unified_schedule_normalize_row_keys($assoc);
                $rows_out[] = unified_schedule_alias_row_fields($assoc);
            }
        } else {
            foreach ($decoded as $entry) {
                if (is_array($entry)) {
                    $entry = array_change_key_case($entry, CASE_LOWER);
                    $entry = unified_schedule_normalize_row_keys($entry);
                    $rows_out[] = unified_schedule_alias_row_fields($entry);
                }
            }
        }
    } elseif (is_string($schedule_data) && trim($schedule_data) !== '') {
        $lines = preg_split('/\r\n|\r|\n/', trim($schedule_data));
        $csv_rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $csv_rows[] = str_getcsv($line);
        }

        if (!empty($csv_rows)) {
            $headers = array_map(static function ($h) {
                $k = strtolower(trim((string)$h, "\" "));
                $k = preg_replace('/[\s\-]+/', '_', $k);
                $k = preg_replace('/_+/', '_', $k);
                return $k;
            }, array_shift($csv_rows));

            foreach ($csv_rows as $row) {
                $assoc = [];
                foreach ($headers as $idx => $header) {
                    $assoc[$header] = $row[$idx] ?? '';
                }
                $assoc = array_change_key_case($assoc, CASE_LOWER);
                $assoc = unified_schedule_normalize_row_keys($assoc);
                $rows_out[] = unified_schedule_alias_row_fields($assoc);
            }
        }
    }

    return $rows_out;
}

function unified_schedule_parse_cutoff(mysqli $conn, string $semester, ?string $requested_saved_at, int $schedule_id): ?string
{
    if ($schedule_id > 0) {
        $stmt = $conn->prepare("SELECT created_at FROM generated_schedules WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $schedule_id);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && ($row = $res->fetch_assoc()) && !empty($row['created_at'])) {
                return date('Y-m-d H:i:s', strtotime((string)$row['created_at']));
            }
        }
    }

    $saved_at = trim((string)$requested_saved_at);
    if ($saved_at === '') {
        return null;
    }

    $ts = strtotime($saved_at);
    if ($ts === false) {
        return null;
    }

    return date('Y-m-d H:i:s', $ts);
}

function unified_schedule_has_column(mysqli $conn, string $table, string $column): bool
{
    static $cache = [];
    $key = strtolower($table . '.' . $column);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $conn->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
    if (!$stmt) {
        $cache[$key] = false;
        return false;
    }

    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $res = $stmt->get_result();
    $cache[$key] = ($res && $res->num_rows > 0);

    return $cache[$key];
}

function unified_schedule_get_system_setting(mysqli $conn, string $key, string $default = ''): string
{
    $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
    if (!$stmt) {
        return $default;
    }

    $stmt->bind_param('s', $key);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && ($row = $res->fetch_assoc())) {
        $val = trim((string)($row['setting_value'] ?? ''));
        return $val !== '' ? $val : $default;
    }

    return $default;
}

function unified_schedule_available_snapshots(mysqli $conn, string $semester, string $academic_year = '', int $limit = 50): array
{
    $limit = max(1, min(200, $limit));
    $rows = [];

    $has_academic_year = unified_schedule_has_column($conn, 'generated_schedules', 'academic_year');
    $academic_year = trim($academic_year);

    $query = "SELECT id, schedule_name, department, semester, created_at
              FROM generated_schedules
              WHERE (schedule_name NOT LIKE 'exam_%' OR schedule_name IS NULL)
              AND (semester = ? OR semester IS NULL OR TRIM(semester) = '')";

    if ($has_academic_year && $academic_year !== '') {
        $query .= " AND academic_year = ?";
    }

    $query .= "
              ORDER BY created_at DESC, id DESC
              LIMIT {$limit}";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        return $rows;
    }

    if ($has_academic_year && $academic_year !== '') {
        $stmt->bind_param('ss', $semester, $academic_year);
    } else {
        $stmt->bind_param('s', $semester);
    }
    $stmt->execute();
    $res = $stmt->get_result();

    while ($res && ($row = $res->fetch_assoc())) {
        $rows[] = [
            'id' => (int)($row['id'] ?? 0),
            'schedule_name' => (string)($row['schedule_name'] ?? ''),
            'department' => (string)($row['department'] ?? 'General'),
            'semester' => (string)($row['semester'] ?? ''),
            'academic_year' => (string)($row['academic_year'] ?? ''),
            'created_at' => (string)($row['created_at'] ?? '')
        ];
    }

    return $rows;
}

function unified_schedule_latest_sources(mysqli $conn, string $semester, string $academic_year, ?string $cutoff_saved_at): array
{
    $sources = [];
    $seen = [];
    $has_academic_year = unified_schedule_has_column($conn, 'generated_schedules', 'academic_year');
    $academic_year = trim($academic_year);

    $sql = "SELECT id, schedule_name, department, semester, created_at, schedule_data
            FROM generated_schedules
            WHERE (schedule_name NOT LIKE 'exam_%' OR schedule_name IS NULL)
              AND (semester = ? OR semester IS NULL OR TRIM(semester) = '')";

    if ($has_academic_year && $academic_year !== '') {
        $sql .= " AND academic_year = ?";
    }

    if (!empty($cutoff_saved_at)) {
        $sql .= " AND created_at <= ?";
    }

    $sql .= " ORDER BY created_at DESC, id DESC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return $sources;
    }

    if ($has_academic_year && $academic_year !== '' && !empty($cutoff_saved_at)) {
        $stmt->bind_param('sss', $semester, $academic_year, $cutoff_saved_at);
    } elseif ($has_academic_year && $academic_year !== '') {
        $stmt->bind_param('ss', $semester, $academic_year);
    } elseif (!empty($cutoff_saved_at)) {
        $stmt->bind_param('ss', $semester, $cutoff_saved_at);
    } else {
        $stmt->bind_param('s', $semester);
    }

    $stmt->execute();
    $res = $stmt->get_result();

    while ($res && ($row = $res->fetch_assoc())) {
        $dep_key = unified_schedule_normalize_department($row['department'] ?? '');
        if (isset($seen[$dep_key])) {
            continue;
        }
        $seen[$dep_key] = true;

        $sources[] = [
            'id' => (int)($row['id'] ?? 0),
            'schedule_name' => (string)($row['schedule_name'] ?? ''),
            'department' => $dep_key === 'general' ? 'General' : (string)($row['department'] ?? ''),
            'academic_year' => (string)($row['academic_year'] ?? ''),
            'created_at' => (string)($row['created_at'] ?? ''),
            'schedule_data' => (string)($row['schedule_data'] ?? '')
        ];
    }

    return $sources;
}

function unified_schedule_get_lecturer_name(mysqli $conn, int $user_id): string
{
    $stmt = $conn->prepare("SELECT l.name
                           FROM users u
                           JOIN lecturers l ON u.lecturer_id = l.id
                           WHERE u.id = ?
                           LIMIT 1");
    if (!$stmt) {
        return '';
    }

    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res && ($row = $res->fetch_assoc())) {
        return trim((string)($row['name'] ?? ''));
    }

    return '';
}

function unified_schedule_get_student_enrollments(mysqli $conn, int $user_id, string $semester, string $academic_year = ''): array
{
    $rows = [];

    $check = $conn->query("SHOW TABLES LIKE 'student_enrollments'");
    if (!$check || $check->num_rows === 0) {
        return $rows;
    }

    $has_section = false;
    $section_stmt = $conn->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'student_enrollments' AND COLUMN_NAME = 'section' LIMIT 1");
    if ($section_stmt) {
        $section_stmt->execute();
        $section_res = $section_stmt->get_result();
        $has_section = $section_res && $section_res->num_rows > 0;
    }

    $has_academic_year = false;
    $academic_stmt = $conn->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'student_enrollments' AND COLUMN_NAME = 'academic_year' LIMIT 1");
    if ($academic_stmt) {
        $academic_stmt->execute();
        $academic_res = $academic_stmt->get_result();
        $has_academic_year = $academic_res && $academic_res->num_rows > 0;
    }

    $sql = "SELECT c.id, c.course_code, c.course_title, c.level, c.department,
                   l.name AS lecturer_name, se.semester";
    if ($has_section) {
        $sql .= ", se.section";
    } else {
        $sql .= ", NULL AS section";
    }

    $sql .= " FROM student_enrollments se
              JOIN courses c ON se.course_id = c.id
              LEFT JOIN lecturers l ON c.lecturer_id = l.id
              WHERE se.user_id = ? AND (se.semester = ? OR se.semester IS NULL OR TRIM(se.semester) = '')";

    if ($has_academic_year && trim($academic_year) !== '') {
        $sql .= " AND se.academic_year = ?";
    }

    $sql .= "
              ORDER BY c.course_code";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return $rows;
    }

    if ($has_academic_year && trim($academic_year) !== '') {
        $stmt->bind_param('iss', $user_id, $semester, $academic_year);
    } else {
        $stmt->bind_param('is', $user_id, $semester);
    }
    $stmt->execute();
    $res = $stmt->get_result();

    while ($res && ($row = $res->fetch_assoc())) {
        $rows[] = $row;
    }

    return $rows;
}

function unified_schedule_fetch(mysqli $conn, array $session, array $options = []): array
{
    $role = (string)($session['role'] ?? 'guest');
    $user_id = (int)($session['user_id'] ?? 0);
    $semester = (string)($options['semester'] ?? ($session['semester'] ?? '1'));
    $academic_year = trim((string)($options['academic_year'] ?? ''));
    if ($academic_year === '') {
        $academic_year = unified_schedule_get_system_setting($conn, 'current_academic_year', '');
    }
    $requested_saved_at = isset($options['saved_at']) ? (string)$options['saved_at'] : '';
    $schedule_id = (int)($options['schedule_id'] ?? 0);

    $cutoff_saved_at = unified_schedule_parse_cutoff($conn, $semester, $requested_saved_at, $schedule_id);
    $include_all_student_rows = !empty($options['include_all_student_rows']);
    $sources = unified_schedule_latest_sources($conn, $semester, $academic_year, $cutoff_saved_at);
    $snapshots = unified_schedule_available_snapshots($conn, $semester, $academic_year, 60);

    $rows = [];
    $seen = [];
    $effective_saved_at = $cutoff_saved_at;

    if ($effective_saved_at === null && !empty($sources)) {
        $latest = array_map(static function ($src) {
            return (string)($src['created_at'] ?? '');
        }, $sources);
        rsort($latest);
        $effective_saved_at = $latest[0] ?? null;
    }

    $selected_source_meta = array_map(static function ($src) {
        return [
            'id' => (int)$src['id'],
            'schedule_name' => (string)$src['schedule_name'],
            'department' => (string)$src['department'],
            'academic_year' => (string)($src['academic_year'] ?? ''),
            'created_at' => (string)$src['created_at']
        ];
    }, $sources);

    if ($role === 'lecturer') {
        $lecturer_name = unified_schedule_get_lecturer_name($conn, $user_id);

        foreach ($sources as $source) {
            $parsed_rows = unified_schedule_extract_rows($source['schedule_data'] ?? '');
            foreach ($parsed_rows as $row) {
                $row_lecturer = trim((string)($row['lecturer_name'] ?? ($row['lecturer name'] ?? ($row['lecturer'] ?? ''))));
                if ($lecturer_name === '' || $row_lecturer === '' || strcasecmp($row_lecturer, $lecturer_name) !== 0) {
                    continue;
                }

                $code = strtoupper(trim((string)($row['course_code'] ?? ($row['course code'] ?? ($row['course'] ?? ($row['code'] ?? ''))))));
                // Split slash-combined codes; use first part for display
                $code_parts = unified_schedule_split_codes($code);
                $code = $code_parts[0];
                $day = trim((string)($row['day'] ?? ''));
                $time = trim((string)($row['time'] ?? ($row['assigned_time'] ?? ($row['assigned time'] ?? ''))));
                $room = trim((string)($row['room_name'] ?? ($row['room name'] ?? ($row['room'] ?? ''))));
                $title = trim((string)($row['course_title'] ?? ($row['course title'] ?? ($row['title'] ?? ''))));

                if ($code === '' || $day === '' || $time === '') {
                    continue;
                }

                $section_val = trim((string)($row['section'] ?? ''));
                $dedupe_key = strtolower($code . '|' . $day . '|' . $time . '|' . $room . '|' . $row_lecturer . '|' . $section_val);
                if (isset($seen[$dedupe_key])) {
                    continue;
                }
                $seen[$dedupe_key] = true;

                $rows[] = [
                    'course_code' => $code,
                    'course_title' => $title,
                    'day' => $day,
                    'time' => $time,
                    'room' => $room,
                    'lecturer' => $row_lecturer,
                    'department' => (string)$source['department'],
                    'source_created_at' => (string)$source['created_at']
                ];
            }
        }

        return [
            'role' => $role,
            'semester' => $semester,
            'academic_year' => $academic_year,
            'saved_at' => $effective_saved_at,
            'requested_saved_at' => $requested_saved_at,
            'snapshots' => $snapshots,
            'sources' => $selected_source_meta,
            'lecturer_name' => $lecturer_name,
            'rows' => $rows,
            'enrolled_courses' => []
        ];
    }

    $level = unified_schedule_normalize_level($session['level'] ?? 0);
    $enrollments = unified_schedule_get_student_enrollments($conn, $user_id, $semester, $academic_year);
    $enrolled_codes = [];
    foreach ($enrollments as $enrollment) {
        $code = strtoupper(trim((string)($enrollment['course_code'] ?? '')));
        if ($code !== '') {
            $enrolled_codes[$code] = true;
        }
    }

    foreach ($sources as $source) {
        $parsed_rows = unified_schedule_extract_rows($source['schedule_data'] ?? '');
        foreach ($parsed_rows as $row) {
            $code_raw = strtoupper(trim((string)($row['course_code'] ?? ($row['course code'] ?? ($row['course'] ?? ($row['code'] ?? ''))))));
            if ($code_raw === '') {
                continue;
            }

            // Resolve the canonical code: prefer whichever part matched an enrollment
            $code = $code_raw;
            if (!empty($enrolled_codes) && !$include_all_student_rows) {
                if (!unified_schedule_code_matches($code_raw, $enrolled_codes)) {
                    continue;
                }
                // Use the specific enrolled code that matched (first match wins)
                foreach (unified_schedule_split_codes($code_raw) as $part) {
                    if (isset($enrolled_codes[$part])) {
                        $code = $part;
                        break;
                    }
                }
            } else {
                // No enrollments: use first code in the cell
                $codes = unified_schedule_split_codes($code_raw);
                $code = $codes[0];
            }

            if (($include_all_student_rows || empty($enrolled_codes)) && $level > 0) {
                $row_level = unified_schedule_normalize_level($row['course_level'] ?? ($row['level'] ?? ''));
                if ($row_level > 0 && $row_level !== $level) {
                    continue;
                }
            }

            $day = trim((string)($row['day'] ?? ''));
            $time = trim((string)($row['time'] ?? ($row['assigned_time'] ?? ($row['assigned time'] ?? ''))));
            $room = trim((string)($row['room_name'] ?? ($row['room name'] ?? ($row['room'] ?? ''))));
            $lecturer = trim((string)($row['lecturer_name'] ?? ($row['lecturer name'] ?? ($row['lecturer'] ?? 'TBA'))));
            $title = trim((string)($row['course_title'] ?? ($row['course title'] ?? ($row['title'] ?? $code))));

            if ($day === '' || $time === '') {
                continue;
            }

            $section_val = trim((string)($row['section'] ?? ''));
            $dedupe_key = strtolower($code . '|' . $day . '|' . $time . '|' . $room . '|' . $section_val);
            if (isset($seen[$dedupe_key])) {
                continue;
            }
            $seen[$dedupe_key] = true;

            $rows[] = [
                'course_code' => $code,
                'course_title' => $title,
                'day' => $day,
                'time' => $time,
                'room' => $room,
                'lecturer' => $lecturer,
                'section' => trim((string)($row['section'] ?? '')),
                'department' => (string)$source['department'],
                'source_created_at' => (string)$source['created_at']
            ];
        }
    }

    return [
        'role' => $role,
        'semester' => $semester,
        'academic_year' => $academic_year,
        'saved_at' => $effective_saved_at,
        'requested_saved_at' => $requested_saved_at,
        'snapshots' => $snapshots,
        'sources' => $selected_source_meta,
        'lecturer_name' => '',
        'rows' => $rows,
        'enrolled_courses' => $enrollments
    ];
}
