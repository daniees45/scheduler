<?php
error_reporting(E_ALL);

// Force errors to display on the screen
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
$page_title = 'My Study Dashboard';
include 'includes/header.php';
require_once 'api/db.php';
require_once 'includes/unified_schedule_service.php';

// Ensure only students can access
requireRole(['student']);

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'Student';
$department = $_SESSION['department'] ?? 'CS';
$level = $_SESSION['level'] ?? 100;

// Fetch Global System Settings
$settings = [];
$res = $conn->query("SELECT setting_key, setting_value FROM system_settings");
while ($s_row = $res->fetch_assoc()) {
    $settings[$s_row['setting_key']] = $s_row['setting_value'];
}
$semester = $settings['current_semester'] ?? '1';
// Calculate semester week number
$semester_week = 1;
$semester_start_raw = $settings['semester_start_date'] ?? ($settings['start_date'] ?? '');
if ($semester_start_raw !== '') {
    $start_ts = strtotime($semester_start_raw);
    if ($start_ts !== false && $start_ts <= time()) {
        $diff_days = (int)floor((time() - $start_ts) / 86400);
        $semester_week = max(1, (int)floor($diff_days / 7) + 1);
    }
} else {
    // Fallback: ISO week number of year
    $semester_week = (int)date('W');
}

// 1. Fetch Schedule Data
$unified_payload = unified_schedule_fetch($conn, $_SESSION, [
    'semester' => $semester,
]);

$enrolled_courses = $unified_payload['enrolled_courses'] ?? [];
$schedule_rows = $unified_payload['rows'] ?? [];

// 2. Fetch Personal Schedule from DB (Two Sources: personal_schedule and personal_events)
$personal_schedule_db = [];

// Source A: Dashboard-specific personal_schedule
$p_stmt = $conn->prepare("SELECT day, time_slot, activity FROM personal_schedule WHERE user_id = ?");
$p_stmt->bind_param("i", $user_id);
$p_stmt->execute();
$p_res = $p_stmt->get_result();
while ($p_row = $p_res->fetch_assoc()) {
    $day_raw = trim($p_row['day']);
    $slot_key = $p_row['time_slot'];
    
    // Normalize to standard day name for the array key
    foreach (["Monday", "Tuesday", "Wednesday", "Thursday", "Friday"] as $dday) {
        if (strcasecmp($day_raw, $dday) === 0) {
            $personal_schedule_db[$dday][$slot_key] = $p_row['activity'];
            break;
        }
    }
}

// Source B: General personal_events (from my_schedule.php)
$slot_ranges = [
    "7:00 - 9:30"   => ["start" => "07:00:00", "end" => "09:30:00"],
    "10:00 - 12:30" => ["start" => "10:00:00", "end" => "12:30:00"],
    "12:30 - 14:00"  => ["start" => "12:30:00", "end" => "14:00:00"],
    "14:00 - 16:30"   => ["start" => "14:00:00", "end" => "16:30:00"],
    "17:00 - 18:00"      => ["start" => "17:00:00", "end" => "18:00:00"]
];

$pe_stmt = $conn->prepare("SELECT day, title, start_time, end_time FROM personal_events WHERE user_id = ?");
$pe_stmt->bind_param("i", $user_id);
$pe_stmt->execute();
$pe_res = $pe_stmt->get_result();

$dashboard_days = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday"];

while ($pe_row = $pe_res->fetch_assoc()) {
    $pe_day_raw = trim($pe_row['day']);
    $pe_title = $pe_row['title'];
    $pe_start = $pe_row['start_time'];
    $pe_end = $pe_row['end_time'];
    
    // Find matching dashboard day (case-insensitive)
    $matched_day = null;
    foreach ($dashboard_days as $dday) {
        if (strcasecmp($pe_day_raw, $dday) === 0) {
            $matched_day = $dday;
            break;
        }
    }
    
    if ($matched_day) {
        foreach ($slot_ranges as $slot_key => $range) {
            // Overlap check
            if ($pe_start < $range['end'] && $pe_end > $range['start']) {
                if (empty($personal_schedule_db[$matched_day][$slot_key])) {
                    $personal_schedule_db[$matched_day][$slot_key] = $pe_title;
                } else {
                    // Avoid duplicate titles in the same slot
                    if (strpos($personal_schedule_db[$matched_day][$slot_key], $pe_title) === false) {
                        $personal_schedule_db[$matched_day][$slot_key] .= " / " . $pe_title;
                    }
                }
            }
        }
    }
}

// 3. Fetch Course Status from DB
$completed_courses_db = [];
$c_stmt = $conn->prepare("SELECT course_code FROM student_course_status WHERE user_id = ? AND status = 'completed'");
$c_stmt->bind_param("i", $user_id);
$c_stmt->execute();
$c_res = $c_stmt->get_result();
while ($c_row = $c_res->fetch_assoc()) {
    $completed_courses_db[] = $c_row['course_code'];
}

// 4. Fetch Required Courses from DB (Faculty Admin saved courses for this dept)
$all_program_courses = [];
$q = "SELECT course_code, course_title FROM courses WHERE department = ? OR department = 'General'";
$stmt = $conn->prepare($q);
$stmt->bind_param("s", $department);
$stmt->execute();
$db_courses = $stmt->get_result();
while ($course = $db_courses->fetch_assoc()) {
    $all_program_courses[] = [
        'code' => $course['course_code'],
        'title' => $course['course_title']
    ];
}

// Filter into Required and Completed
$required_list = [];
$completed_list = [];
foreach ($all_program_courses as $pc) {
    if (in_array($pc['code'], $completed_courses_db)) {
        $completed_list[] = $pc;
    } else {
        $required_list[] = $pc;
    }
}

$total_required_count = count($all_program_courses);
$total_completed_count = count($completed_list);
$graduation_percent = $total_required_count > 0 ? round(($total_completed_count / $total_required_count) * 100) : 0;

// 5. Build the Timetable Grids (New Slots)
$days = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday"];
$time_slots = [
    "7:00am - 9:30am"   => "07:00 AM - 09:30 AM",
    "10:00am - 12:30pm" => "10:00 AM - 12:30 PM",
    "12:30pm - 2:00pm"  => "12:30 PM - 02:00 PM", 
    "2:00pm - 4:30pm"   => "02:00 PM - 04:30 PM",
    "5:00pm - 6:00pm"      => "05:00 PM - 06:00 PM"
];

$slot_windows = [
    "7:00am - 9:30am"   => ['start' => 7 * 60, 'end' => (9 * 60) + 30],
    "10:00am - 12:30pm" => ['start' => 10 * 60, 'end' => (12 * 60) + 30],
    "12:30pm - 2:00pm"  => ['start' => (12 * 60) + 30, 'end' => 14 * 60],
    "2:00pm - 4:30pm"   => ['start' => 14 * 60, 'end' => (16 * 60) + 30],
    "5:00pm - 6:00pm"      => ['start' => 17 * 60, 'end' => 18 * 60],
];

function normalizeScheduleDay($rawDay) {
    $value = strtolower(trim((string)$rawDay));
    if ($value === '') return '';

    $map = [
        'mon' => 'Monday', 'monday' => 'Monday',
        'tue' => 'Tuesday', 'tues' => 'Tuesday', 'tuesday' => 'Tuesday',
        'wed' => 'Wednesday', 'wednesday' => 'Wednesday',
        'thu' => 'Thursday', 'thur' => 'Thursday', 'thurs' => 'Thursday', 'thursday' => 'Thursday',
        'fri' => 'Friday', 'friday' => 'Friday',
    ];

    return $map[$value] ?? ucfirst($value);
}

function parseTimeTokenToMinutes($hourRaw, $minuteRaw, $ampmRaw, $preferAfternoon = false) {
    $hour = (int)$hourRaw;
    $minute = (int)($minuteRaw === '' ? 0 : $minuteRaw);
    $ampm = strtolower(trim((string)$ampmRaw));

    if ($ampm === 'am' || $ampm === 'pm') {
        if ($hour === 12) {
            $hour = 0;
        }
        if ($ampm === 'pm') {
            $hour += 12;
        }
    } else {
        // Heuristic for schedules written as 2-4:30 / 5-6 without AM/PM.
        if ($preferAfternoon && $hour >= 1 && $hour <= 7) {
            $hour += 12;
        }
    }

    return ($hour * 60) + $minute;
}

function parseScheduleRangeToMinutes($raw) {
    $text = trim((string)$raw);
    if ($text === '') {
        return [null, null];
    }

    if (preg_match_all('/(\d{1,2})(?::(\d{2}))?\s*(am|pm)?/i', $text, $matches, PREG_SET_ORDER) < 1) {
        return [null, null];
    }

    $preferAfternoon = (bool)preg_match('/\b(2|3|4|5|6|7)(?::\d{2})?\s*-\s*(2|3|4|5|6|7)(?::\d{2})?\b/', strtolower($text));

    $start = parseTimeTokenToMinutes($matches[0][1], $matches[0][2] ?? '', $matches[0][3] ?? '', $preferAfternoon);
    $end = null;
    if (isset($matches[1])) {
        $end = parseTimeTokenToMinutes($matches[1][1], $matches[1][2] ?? '', $matches[1][3] ?? '', $preferAfternoon);
        if ($end <= $start) {
            $end += 12 * 60;
        }
    } else {
        $end = $start + 60;
    }

    return [$start, $end];
}

function rowMatchesSlot($rowTime, $slotKey, $slotWindow) {
    $rowTimeText = strtolower(trim((string)$rowTime));
    if ($rowTimeText === '') {
        return false;
    }

    // Fast-path for exact slot-key style strings (e.g. 2-4:30)
    if (strpos(str_replace(' ', '', $rowTimeText), str_replace(' ', '', strtolower($slotKey))) !== false) {
        return true;
    }

    [$rowStart, $rowEnd] = parseScheduleRangeToMinutes($rowTimeText);
    if ($rowStart === null || $rowEnd === null) {
        return false;
    }

    return ($rowStart < $slotWindow['end']) && ($rowEnd > $slotWindow['start']);
}

function extractSectionToken($rawText) {
    $text = trim((string)$rawText);
    if ($text === '') {
        return '';
    }

    if (preg_match('/\[\s*sec(?:tion)?\s*([a-z0-9]+)\s*\]/i', $text, $m)) {
        return strtoupper(trim((string)$m[1]));
    }
    if (preg_match('/\bsec(?:tion)?\s*([a-z0-9]+)\b/i', $text, $m)) {
        return strtoupper(trim((string)$m[1]));
    }

    return '';
}

function normalizeSectionToken($explicitSection, $fallbackText = '') {
    $section = trim((string)$explicitSection);
    if ($section !== '') {
        $section = preg_replace('/^\s*sec(?:tion)?\s*/i', '', $section);
        $section = strtoupper(trim((string)$section));
        return preg_replace('/[^A-Z0-9]/', '', $section);
    }

    return preg_replace('/[^A-Z0-9]/', '', extractSectionToken($fallbackText));
}

function formatSectionLabel($normalizedSection) {
    $value = strtoupper(trim((string)$normalizedSection));
    return $value === '' ? '' : ('Sec ' . $value);
}

function generatedSchedulesHasColumn($conn, $column) {
    if (!($conn instanceof mysqli)) {
        return false;
    }

    $column = trim((string)$column);
    if ($column === '') {
        return false;
    }

    $escaped = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM generated_schedules LIKE '" . $escaped . "'");
    return $res && $res->num_rows > 0;
}

function examFindHeaderIndex($headerMap, $candidates) {
    foreach ($candidates as $candidate) {
        $needle = strtolower(trim((string)$candidate));
        if ($needle !== '' && isset($headerMap[$needle])) {
            return (int)$headerMap[$needle];
        }
    }
    return -1;
}

function examNormalizeCodeToken($value) {
    return strtoupper(trim((string)$value));
}

function examExtractCodeTokens($courseCodeField) {
    $tokens = [];
    foreach (preg_split('/\s*\/\s*/', strtoupper(trim($courseCodeField))) as $token) {
        $normalized = examNormalizeCodeToken($token);
        if ($normalized !== '') {
            $tokens[$normalized] = true;
        }
    }
    return array_keys($tokens);
}

function loadLatestSavedExamRows($conn) {
    $hasType = generatedSchedulesHasColumn($conn, 'schedule_type');
    $sql = $hasType
        ? "SELECT schedule_data FROM generated_schedules WHERE LOWER(TRIM(schedule_type)) = 'exam' ORDER BY created_at DESC, id DESC LIMIT 1"
        : "SELECT schedule_data FROM generated_schedules WHERE LOWER(TRIM(schedule_name)) LIKE 'exam%' ORDER BY created_at DESC, id DESC LIMIT 1";

    $res = $conn->query($sql);
    if (!$res || !($row = $res->fetch_assoc())) {
        return [];
    }

    $decoded = json_decode((string)($row['schedule_data'] ?? ''), true);
    return is_array($decoded) ? $decoded : [];
}

function normalizeSavedExamRows($rawRows) {
    if (empty($rawRows)) {
        return [];
    }

    $first = $rawRows[0] ?? null;
    $normalized = [];

    // Case A: associative row objects already in normalized form.
    if (is_array($first) && array_keys($first) !== range(0, count($first) - 1)) {
        foreach ($rawRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $normalized[] = [
                'course_code' => trim((string)($row['course_code'] ?? $row['course code'] ?? '')),
                'day' => trim((string)($row['day'] ?? '')),
                'time' => trim((string)($row['time'] ?? $row['time_slot'] ?? $row['time slot'] ?? '')),
                'room' => trim((string)($row['room'] ?? $row['room_name'] ?? $row['room name'] ?? '')),
            ];
        }
        return $normalized;
    }

    // Case B: sequential CSV-like arrays (often includes header row first).
    if (!is_array($first)) {
        return [];
    }

    $header = array_map(static function ($v) {
        return strtolower(trim((string)$v));
    }, $first);

    $headerMap = [];
    foreach ($header as $idx => $name) {
        if ($name !== '' && !isset($headerMap[$name])) {
            $headerMap[$name] = $idx;
        }
    }

    $idxCode = examFindHeaderIndex($headerMap, ['course code', 'course_code']);
    $idxRoom = examFindHeaderIndex($headerMap, ['room', 'room name', 'room_name', 'exam hall', 'hall']);
    $idxDay  = examFindHeaderIndex($headerMap, ['day']);
    $idxTime = examFindHeaderIndex($headerMap, ['time', 'time slot', 'time_slot']);

    // If no headers detected, fallback to known exam export positions.
    $hasHeader = ($idxCode >= 0 || $idxDay >= 0 || $idxTime >= 0 || $idxRoom >= 0);
    $startRow = $hasHeader ? 1 : 0;
    if ($idxCode < 0) $idxCode = 0;
    if ($idxRoom < 0) $idxRoom = 6;
    if ($idxDay < 0)  $idxDay = 7;
    if ($idxTime < 0) $idxTime = 8;

    for ($i = $startRow; $i < count($rawRows); $i++) {
        $row = $rawRows[$i];
        if (!is_array($row)) {
            continue;
        }

        $maxNeed = max($idxCode, $idxRoom, $idxDay, $idxTime);
        if (count($row) <= $maxNeed) {
            continue;
        }

        $normalized[] = [
            'course_code' => trim((string)($row[$idxCode] ?? '')),
            'day' => trim((string)($row[$idxDay] ?? '')),
            'time' => trim((string)($row[$idxTime] ?? '')),
            'room' => trim((string)($row[$idxRoom] ?? '')),
        ];
    }

    return $normalized;
}

// Sketch marker colors for lecturers
$lecturer_marker_colors = ["#fffa65", "#ffaf40", "#ff4d4d", "#7efff5", "#18dcff", "#7d5fff", "#7158e2", "#3ae374", "#ff3838", "#c56cf0"];

function getLecturerMarker($name, $colors) {
    if (!$name || $name === 'TBA') return "rgba(0,0,0,0.05)";
    $hash = crc32($name);
    return $colors[abs($hash) % count($colors)];
}

$study_grid = [];
$study_table_rows = [];
$study_table_seen = [];
$available_sections_by_code = [];
foreach ($schedule_rows as $row) {
    $code = strtoupper(trim((string)($row['course_code'] ?? '')));
    if ($code === '') {
        continue;
    }
    $section = normalizeSectionToken($row['section'] ?? '', $row['course_title'] ?? '');
    if ($section === '') {
        continue;
    }
    if (!isset($available_sections_by_code[$code])) {
        $available_sections_by_code[$code] = [];
    }
    $available_sections_by_code[$code][$section] = true;
}

$enrollment_by_code = [];
foreach ($enrolled_courses as $ec) {
    $ec_code = strtoupper(trim((string)($ec['course_code'] ?? '')));
    if ($ec_code === '') {
        continue;
    }

    $normalized_section = normalizeSectionToken($ec['section'] ?? '', $ec['course_title'] ?? '');
    if (!isset($enrollment_by_code[$ec_code])) {
        $enrollment_by_code[$ec_code] = [
            'sections' => [],
            'preferred' => ''
        ];
    }

    if ($normalized_section !== '') {
        $enrollment_by_code[$ec_code]['sections'][$normalized_section] = true;
        if ($enrollment_by_code[$ec_code]['preferred'] === '') {
            $enrollment_by_code[$ec_code]['preferred'] = $normalized_section;
        }
    }
}

// Legacy fallback: if enrollment has no section, pin to first known schedule section for that code.
foreach ($enrollment_by_code as $code => &$entry) {
    if (!empty($entry['sections'])) {
        continue;
    }
    if (!empty($available_sections_by_code[$code])) {
        $sections = array_keys($available_sections_by_code[$code]);
        sort($sections, SORT_NATURAL | SORT_FLAG_CASE);
        $fallback = (string)($sections[0] ?? '');
        if ($fallback !== '') {
            $entry['sections'][$fallback] = true;
            $entry['preferred'] = $fallback;
        }
    }
}
unset($entry);

foreach ($days as $day) {
    foreach ($time_slots as $slot_key => $time_range) {
        $study_grid[$day][$slot_key] = [];
        if ($slot_key === "12:30-2") continue; 

        foreach ($schedule_rows as $row) {
            $row_day = normalizeScheduleDay($row['day'] ?? '');
            $row_time = (string)($row['time'] ?? '');
            $row_code = strtoupper(trim((string)($row['course_code'] ?? '')));

            if ($row_code === '' || !isset($enrollment_by_code[$row_code])) {
                continue;
            }

            $row_section = normalizeSectionToken($row['section'] ?? '', $row['course_title'] ?? '');
            $student_sections = $enrollment_by_code[$row_code]['sections'] ?? [];
            $preferred_section = $enrollment_by_code[$row_code]['preferred'] ?? '';
            $has_student_section = !empty($student_sections);

            // If a student selected a section, only show rows that explicitly match that section.
            if ($has_student_section) {
                if ($row_section === '' || !isset($student_sections[$row_section])) {
                    continue;
                }
            }

            $display_section = $row_section !== '' ? $row_section : $preferred_section;

            // Always append [Sec X] if section is present
            $base_title = trim(preg_replace('/\[\s*sec(?:tion)?\s*[a-z0-9]+\s*\]/i', '', $row['course_title'] ?? ''));
            $row_title = $base_title;
            if ($display_section !== '') {
                $row_title .= ' [' . $display_section . ']';
            }

            if (strcasecmp($row_day, $day) === 0) {
                if (rowMatchesSlot($row_time, $slot_key, $slot_windows[$slot_key])) {
                    $study_grid[$day][$slot_key][] = [
                        'code' => $row['course_code'],
                        'title' => $row_title,
                        'day' => $row_day,
                        'time' => $row_time,
                        'section' => formatSectionLabel($display_section),
                        'lecturer' => $row['lecturer'] ?? 'TBA',
                        'room' => $row['room'] ?? 'TBA',
                        'color' => getLecturerMarker($row['lecturer'] ?? 'TBA', $lecturer_marker_colors)
                    ];

                    $table_key = strtolower(trim((string)$day) . '|' . trim((string)$slot_key) . '|' . trim((string)$row_time) . '|' . trim((string)($row['course_code'] ?? '')) . '|' . trim((string)$display_section) . '|' . trim((string)($row['lecturer'] ?? 'TBA')) . '|' . trim((string)($row['room'] ?? 'TBA')));
                    if (!isset($study_table_seen[$table_key])) {
                        $study_table_seen[$table_key] = true;
                        $study_table_rows[] = [
                            'day' => $day,
                            'slot' => $slot_key,
                            'time' => $row_time !== '' ? $row_time : $time_range,
                            'course' => $row['course_code'] ?? 'TBA',
                            'title' => $row_title,
                            'section' => formatSectionLabel($display_section),
                            'lecturer' => $row['lecturer'] ?? 'TBA',
                            'room' => $row['room'] ?? 'TBA',
                        ];
                    }
                }
            }
        }
    }
}

$day_order = ['Monday' => 1, 'Tuesday' => 2, 'Wednesday' => 3, 'Thursday' => 4, 'Friday' => 5];
usort($study_table_rows, function ($a, $b) use ($day_order) {
    $day_cmp = ($day_order[$a['day']] ?? 99) <=> ($day_order[$b['day']] ?? 99);
    if ($day_cmp !== 0) {
        return $day_cmp;
    }

    return strcmp((string)$a['slot'], (string)$b['slot']);
});

// 6. Build student's exam list strictly from enrolled courses
$enrolled_code_map = [];
foreach ($enrolled_courses as $ec) {
    $ec_code = strtoupper(trim((string)($ec['course_code'] ?? '')));
    if ($ec_code !== '') {
        $enrolled_code_map[$ec_code] = true;
    }
}

$my_exams = [];
$exam_seen = [];

if (!empty($enrolled_code_map)) {
    $exam_rows_raw = loadLatestSavedExamRows($conn);
    $exam_rows = normalizeSavedExamRows($exam_rows_raw);

    // Fallback only when no saved exam row exists in DB yet.
    if (empty($exam_rows)) {
        $exam_file = '../csv/final/exam_schedule.csv';
        if (file_exists($exam_file) && ($exam_handle = fopen($exam_file, 'r')) !== false) {
            $csv_rows = [];
            while (($exam_row = fgetcsv($exam_handle, 1000, ',')) !== false) {
                $csv_rows[] = $exam_row;
            }
            fclose($exam_handle);
            $exam_rows = normalizeSavedExamRows($csv_rows);
        }
    }

    foreach ($exam_rows as $exam_row) {
        $course_code_raw = strtoupper(trim((string)($exam_row['course_code'] ?? '')));
        if ($course_code_raw === '') {
            continue;
        }

        $matched_code = '';
        foreach (examExtractCodeTokens($course_code_raw) as $token) {
            if (isset($enrolled_code_map[$token])) {
                $matched_code = $token;
                break;
            }
        }

        if ($matched_code === '') {
            continue;
        }

        $exam_day = trim((string)($exam_row['day'] ?? ''));
        $exam_time = trim((string)($exam_row['time'] ?? ''));
        $exam_room = trim((string)($exam_row['room'] ?? ''));

        $exam_key = strtolower($matched_code . '|' . $exam_day . '|' . $exam_time);
        if (isset($exam_seen[$exam_key])) {
            continue;
        }
        $exam_seen[$exam_key] = true;

        $my_exams[] = [
            'code' => $matched_code,
            'day' => $exam_day !== '' ? $exam_day : 'TBA',
            'time' => $exam_time !== '' ? $exam_time : 'TBA',
            'room' => $exam_room !== '' ? $exam_room : 'TBA',
        ];
    }
}
?>

<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<link rel="stylesheet" href="assets/sketch.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<style>
    .editable-slot {
        background: transparent;
        border: none;
        width: 100%;
        height: 100%;
        text-align: center;
        font-family: inherit;
        color: var(--sketch-marker);
        resize: none;
        overflow: hidden;
        font-size: 1.1rem;
        padding: 10px;
    }
    .sketch-cell {
        min-height: 150px; /* Big boxes for personal timetable */
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
    }
    .sketch-header, .sketch-row-label {
        min-height: auto;
    }
    .clash-slot {
        background: rgba(255, 0, 0, 0.05) !important;
        cursor: not-allowed;
    }
    .clash-icon {
        color: var(--sketch-marker);
        font-size: 1rem;
        display: block;
        margin-bottom: 8px;
    }
    .move-btn {
        cursor: pointer;
        background: var(--sketch-marker);
        color: white;
        border: none;
        padding: 5px 10px;
        border-radius: 5px;
        font-size: 0.8rem;
        margin-top: 5px;
    }
    .export-btn {
        background: var(--sketch-ink);
        color: white;
        padding: 8px 15px;
        border-radius: 5px;
        text-decoration: none;
        font-size: 0.9rem;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        border: 2px solid var(--sketch-border);
    }
    .sketch-table-wrap {
        overflow-x: auto;
        border: 2px solid rgba(74, 85, 104, 0.35);
        border-radius: 12px;
        background: #fff;
    }
    .sketch-data-table {
        width: 100%;
        min-width: 680px;
        border-collapse: collapse;
        font-size: 0.92rem;
    }
    .sketch-data-table th,
    .sketch-data-table td {
        border: 1px solid rgba(74, 85, 104, 0.4);
        padding: 0.65rem 0.7rem;
        text-align: left;
        vertical-align: top;
    }
    .sketch-data-table thead th {
        background: rgba(var(--primary-rgb), 0.12);
        position: sticky;
        top: 0;
        z-index: 1;
    }
    .sketch-data-table tbody tr:nth-child(even) {
        background: rgba(248, 250, 252, 0.8);
    }
    .sketch-sort-btn {
        cursor: pointer;
        user-select: none;
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        font-weight: 700;
    }
    .sketch-sort-btn .sort-indicator {
        color: var(--sketch-marker);
        font-size: 0.74rem;
        line-height: 1;
    }
    .table-empty {
        text-align: center;
        color: #64748b;
        font-style: italic;
    }
    .table-note {
        font-size: 0.82rem;
        color: #475569;
        margin: 0.65rem 0 0;
    }
    @media (max-width: 768px) {
        .sketch-data-table {
            min-width: 560px;
        }
    }

    
</style>

<div class="sketch-container" id="dashboard-content">
    <div class="sketch-panel sketch-mobile-header">
        <div class="sketch-topbar">
            <div class="sketch-hero">
                <h1 class="sketch-title">Lecture/Study Time Table</h1>
                <p class="sketch-subtitle">Student: <?php echo htmlspecialchars($user_name); ?> | Program: <?php echo htmlspecialchars($department); ?></p>
            </div>
            <div class="sketch-meta">
                <div class="sketch-actions">
                    <a href="student_exam_prep.php" class="export-btn" style="background: var(--sketch-marker);">
                        <i class="fa-solid fa-graduation-cap"></i> View Exam & Prep Plan
                    </a>
                    <button onclick="exportToPDF()" class="export-btn">
                        <i class="fa-solid fa-file-pdf"></i> Export to PDF
                    </button>
                </div>
                <div class="sketch-badge">Semester <?php echo $semester; ?> | Week <?php echo $semester_week; ?></div>
                <div class="sketch-badge">Level <?php echo $level; ?></div>
            </div>
        </div>
    </div>
<!-- 
    <div class="sketch-panel" style="overflow-x: auto;">
        <h3>My Exams (Enrolled Courses)</h3>
        <div class="sketch-table-wrap" style="overflow-x: auto;">
            <table class="sketch-data-table sortable-table" id="myExamsTable" style="width: 100%; min-width: 560px;">
                <div style="overflow-x: auto; width: 100%;">
                <table class="sketch-data-table sortable-table" id="myExamsTable" style="width: 100%; min-width: 560px;">
                <thead>
                    <tr>
                        <th data-sort="code"><span class="sketch-sort-btn">Course <span class="sort-indicator">↕</span></span></th>
                        <th data-sort="day"><span class="sketch-sort-btn">Day <span class="sort-indicator">↕</span></span></th>
                        <th data-sort="time"><span class="sketch-sort-btn">Time <span class="sort-indicator">↕</span></span></th>
                        <th data-sort="room"><span class="sketch-sort-btn">Venue <span class="sort-indicator">↕</span></span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($my_exams)): ?>
                        <tr>
                            <td colspan="4" class="table-empty">No exams available for your enrolled courses yet.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($my_exams as $exam): ?>
                            <tr>
                                <td data-value="<?php echo htmlspecialchars(strtoupper($exam['code'])); ?>"><strong><?php echo htmlspecialchars($exam['code']); ?></strong></td>
                                <td data-value="<?php echo htmlspecialchars($exam['day']); ?>"><?php echo htmlspecialchars($exam['day']); ?></td>
                                <td data-value="<?php echo htmlspecialchars($exam['time']); ?>"><?php echo htmlspecialchars($exam['time']); ?></td>
                                <td data-value="<?php echo htmlspecialchars($exam['room']); ?>"><?php echo htmlspecialchars($exam['room']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
                </div>
        </div>
        <p class="table-note">Tip: click any column title to sort ascending/descending.</p>
        <div style="margin-top: 0.8rem;">
            <a href="student_exam_prep.php" class="export-btn" style="background: var(--sketch-marker);">
                <i class="fa-solid fa-list-check"></i> Open Full Exam & Prep View
            </a>
        </div>
    </div> -->

    <div class="sketch-panel">
        <h3>My Class Schedule (Table View)</h3>
        <div class="sketch-table-wrap" style="overflow-x: auto;">
            <table class="sketch-data-table sortable-table" id="myClassesTable">
                <thead>
                    <tr>
                        <th data-sort="day"><span class="sketch-sort-btn">Day <span class="sort-indicator">↕</span></span></th>
                        <th data-sort="time"><span class="sketch-sort-btn">Time <span class="sort-indicator">↕</span></span></th>
                        <th data-sort="course"><span class="sketch-sort-btn">Course <span class="sort-indicator">↕</span></span></th>
                        <th data-sort="title"><span class="sketch-sort-btn">Title <span class="sort-indicator">↕</span></span></th>
                        <th data-sort="section"><span class="sketch-sort-btn">Section <span class="sort-indicator">↕</span></span></th>
                        <th data-sort="lecturer"><span class="sketch-sort-btn">Lecturer <span class="sort-indicator">↕</span></span></th>
                        <th data-sort="room"><span class="sketch-sort-btn">Room <span class="sort-indicator">↕</span></span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($study_table_rows)): ?>
                        <tr>
                            <td colspan="7" class="table-empty">No class schedule is currently available.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($study_table_rows as $class_row): ?>
                            <tr>
                                <td data-value="<?php echo htmlspecialchars($class_row['day']); ?>"><?php echo htmlspecialchars($class_row['day']); ?></td>
                                <td data-value="<?php echo htmlspecialchars($class_row['time']); ?>"><?php echo htmlspecialchars($class_row['time']); ?></td>
                                <td data-value="<?php echo htmlspecialchars(strtoupper($class_row['course'])); ?>"><strong><?php echo htmlspecialchars($class_row['course']); ?></strong></td>
                                <td data-value="<?php echo htmlspecialchars($class_row['title'] ?? ''); ?>"><?php echo htmlspecialchars($class_row['title'] ?? '-'); ?></td>
                                <td data-value="<?php echo htmlspecialchars($class_row['section'] ?? ''); ?>"><?php echo htmlspecialchars($class_row['section'] ?? '-'); ?></td>
                                <td data-value="<?php echo htmlspecialchars($class_row['lecturer']); ?>"><?php echo htmlspecialchars($class_row['lecturer']); ?></td>
                                <td data-value="<?php echo htmlspecialchars($class_row['room']); ?>"><?php echo htmlspecialchars($class_row['room']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <p class="table-note">Table view keeps the same dashboard style but gives spreadsheet-like sorting and scanning.</p>
    </div>

    <!-- Study Timetable Grid -->
    <div class="sketch-panel">
        <h3>Study Timetable (Academic)</h3>
        <div class="desktop-grid-view">
        <div class="sketch-grid-wrap">
        <div class="sketch-grid" style="grid-template-columns: 80px repeat(5, 1fr);">
            <div class="sketch-cell sketch-header">Day / Time</div>
            <?php foreach ($time_slots as $slot_key => $label): ?>
                <div class="sketch-cell sketch-header"><?php echo $slot_key; ?></div>
            <?php endforeach; ?>

            <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri'] as $idx => $day_short): ?>
                <?php $day_full = $days[$idx]; ?>
                <div class="sketch-cell sketch-row-label"><?php echo $day_short; ?></div>
                <?php foreach ($time_slots as $slot_key => $label): ?>
                    <?php if ($slot_key === "12:30-2"): ?>
                        <div class="sketch-cell sketch-break">BREAK</div>
                    <?php else: ?>
                        <div class="sketch-cell">
                            <?php foreach ($study_grid[$day_full][$slot_key] as $course): ?>
                                <div class="sketch-badge" style="background: <?php echo $course['color']; ?>; color: #000; margin-bottom: 5px; width: 90%; font-size: 0.8rem; border: 1px solid rgba(0,0,0,0.1); box-shadow: 2px 2px 0 rgba(0,0,0,0.1);">
                                    <strong><?php echo $course['code']; ?></strong><br>
                                    <!-- <?php if (!empty($course['title'])): ?><span style="font-size: 0.7rem; font-weight: 600;"><?php echo htmlspecialchars($course['title']); ?></span><br><?php endif; ?> -->
                                    <?php if (!empty($course['section'])): ?><span style="font-size: 0.7rem; font-weight: 700;"><?php echo htmlspecialchars($course['section']); ?></span><br><?php endif; ?>
                                    <?php if (!empty($course['time'])): ?><span style="font-size: 0.68rem;"><?php echo htmlspecialchars($course['time']); ?></span><br><?php endif; ?>
                                    <!-- <span style="font-size: 0.7rem; opacity: 0.8;"><?php echo $course['lecturer']; ?></span><br> -->
                                    <span style="font-size: 0.7rem; font-weight: bold;"><?php echo $course['room']; ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </div>
        </div>
        </div>

        <div class="mobile-card-view">
            <div class="mobile-day-grid">
            <?php foreach ($days as $day_full): ?>
                <section class="mobile-day-card">
                    <h4 class="mobile-day-title"><?php echo htmlspecialchars($day_full); ?></h4>
                    <?php foreach ($time_slots as $slot_key => $label): ?>
                        <div class="mobile-slot-row">
                            <div class="mobile-slot-label"><?php echo htmlspecialchars($label); ?></div>
                            <?php if ($slot_key === "12:30-2"): ?>
                                <div class="mobile-break-chip">Break</div>
                            <?php else: ?>
                                <div class="mobile-slot-content">
                                    <?php if (empty($study_grid[$day_full][$slot_key])): ?>
                                        <p class="mobile-slot-empty">No class</p>
                                    <?php else: ?>
                                        <?php foreach ($study_grid[$day_full][$slot_key] as $course): ?>
                                            <div class="sketch-badge" style="background: <?php echo $course['color']; ?>; color: #000; margin: 0 0 6px 0; width: 100%; font-size: 0.82rem; border: 1px solid rgba(0,0,0,0.1); box-shadow: 2px 2px 0 rgba(0,0,0,0.1);">
                                                <strong><?php echo $course['code']; ?></strong><br>
                                                <?php if (!empty($course['title'])): ?><span style="font-size: 0.72rem; font-weight: 600;"><?php echo htmlspecialchars($course['title']); ?></span><br><?php endif; ?>
                                                <?php if (!empty($course['section'])): ?><span style="font-size: 0.72rem; font-weight: 700;"><?php echo htmlspecialchars($course['section']); ?></span><br><?php endif; ?>
                                                <?php if (!empty($course['time'])): ?><span style="font-size: 0.70rem;"><?php echo htmlspecialchars($course['time']); ?></span><br><?php endif; ?>
                                                <span style="font-size: 0.72rem; opacity: 0.8;"><?php echo $course['lecturer']; ?></span><br>
                                                <span style="font-size: 0.72rem; font-weight: bold;"><?php echo $course['room']; ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </section>
            <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Personal Timetable Grid -->
    <div class="sketch-panel">
        <h3>Personal Timetable</h3>
        <div class="desktop-grid-view">
        <div class="sketch-grid-wrap">
        <div class="sketch-grid" style="grid-template-columns: 80px repeat(5, 1fr);">
            <div class="sketch-cell sketch-header">Day / Time</div>
            <?php foreach ($time_slots as $slot_key => $label): ?>
                <div class="sketch-cell sketch-header"><?php echo $slot_key; ?></div>
            <?php endforeach; ?>

            <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri'] as $idx => $day_short): ?>
                <?php $day_full = $days[$idx]; ?>
                <div class="sketch-cell sketch-row-label"><?php echo $day_short; ?></div>
                <?php foreach ($time_slots as $slot_key => $label): ?>
                    <?php 
                    $has_clash = !empty($study_grid[$day_full][$slot_key]); 
                    ?>
                    <div class="sketch-cell <?php echo $has_clash ? 'clash-slot' : ''; ?>">
                        <?php if ($has_clash): ?>
                            <span class="clash-icon" title="Clash">⚠️ Clash</span>
                        <?php else: ?>
                            <textarea class="editable-slot" onchange="saveActivity('<?php echo $day_full; ?>', '<?php echo $slot_key; ?>', this.value)" placeholder="..."><?php echo htmlspecialchars($personal_schedule_db[$day_full][$slot_key] ?? ''); ?></textarea>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </div>
        </div>
        </div>

        <div class="mobile-card-view">
            <div class="mobile-day-grid">
            <?php foreach ($days as $day_full): ?>
                <section class="mobile-day-card">
                    <h4 class="mobile-day-title"><?php echo htmlspecialchars($day_full); ?></h4>
                    <?php foreach ($time_slots as $slot_key => $label): ?>
                        <?php $has_clash = !empty($study_grid[$day_full][$slot_key]); ?>
                        <div class="mobile-slot-row <?php echo $has_clash ? 'mobile-slot-row-clash' : ''; ?>">
                            <div class="mobile-slot-label"><?php echo htmlspecialchars($label); ?></div>
                            <?php if ($has_clash): ?>
                                <div class="mobile-clash-chip">Clash with class</div>
                            <?php else: ?>
                                <textarea class="editable-slot mobile-editable-slot" onchange="saveActivity('<?php echo $day_full; ?>', '<?php echo $slot_key; ?>', this.value)" placeholder="Add your activity..."><?php echo htmlspecialchars($personal_schedule_db[$day_full][$slot_key] ?? ''); ?></textarea>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </section>
            <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Graduation Status Section (Excluded from PDF) -->
    <div class="sketch-panel" id="graduation-status-section">
        <h3 class="sketch-title">Graduation Status</h3>
        <div class="sketch-progress-container">
            <div class="sketch-progress-bar">
                <div class="sketch-progress-fill" style="width: <?php echo $graduation_percent; ?>%;"></div>
                <div class="sketch-progress-text"><?php echo $graduation_percent; ?>% Journey Completed</div>
            </div>
        </div>

        <div class="sketch-two-col" style="margin-top: 2rem;">
            <div>
                <h4>Required Courses (<?php echo $department; ?>)</h4>
                <select id="requiredCourses" class="glass-input" style="width: 100%;">
                    <option value="">-- Select Course --</option>
                    <?php foreach ($required_list as $course): ?>
                        <option value="<?php echo htmlspecialchars($course['code']); ?>">
                            <?php echo htmlspecialchars($course['code']); ?>: <?php echo htmlspecialchars($course['title']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button class="move-btn" style="min-height:44px;min-width:44px;font-size:1rem;" onclick="moveToCompleted()">Move to Completed →</button>
            </div>

            <div>
                <h4>Completed Courses</h4>
                <select id="completedCourses" class="glass-input" style="width: 100%;">
                    <option value="">-- Select Course --</option>
                    <?php foreach ($completed_list as $course): ?>
                        <option value="<?php echo htmlspecialchars($course['code']); ?>">
                            <?php echo htmlspecialchars($course['code']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button class="move-btn" style="background: var(--sketch-ink);min-height:44px;min-width:44px;font-size:1rem;" onclick="moveToRequired()">← Move to Required</button>
            </div>
        </div>
    </div>
</div>

<script>
function exportToPDF() {
    const element = document.getElementById('dashboard-content');
    const gradSection = document.getElementById('graduation-status-section');
    
    // Hide graduation section for export
    gradSection.style.display = 'none';

    const opt = {
        margin:       10,
        filename:     'My_Timetable_Dashboard.pdf',
        image:        { type: 'jpeg', quality: 0.98 },
        html2canvas:  { scale: 2, useCORS: true },
        jsPDF:        { unit: 'mm', format: 'a3', orientation: 'landscape' }
    };
    
    html2pdf().set(opt).from(element).save().then(() => {
        // Show it back after export
        gradSection.style.display = 'block';
    });
}

async function saveActivity(day, slot, activity) {
    const formData = new FormData();
    formData.append('action', 'save_personal_activity');
    formData.append('day', day);
    formData.append('slot', slot);
    formData.append('activity', activity);

    try {
        const response = await fetch('api/student_personal_data.php', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();
        if (!data.success) alert('Failed to save: ' + data.error);
    } catch (err) {
        console.error(err);
    }
}

async function moveToCompleted() {
    const code = document.getElementById('requiredCourses').value;
    if (!code) return;

    const formData = new FormData();
    formData.append('action', 'mark_course_completed');
    formData.append('course_code', code);

    try {
        const response = await fetch('api/student_personal_data.php', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();
        if (data.success) location.reload();
        else alert('Error: ' + data.error);
    } catch (err) {
        console.error(err);
    }
}

async function moveToRequired() {
    const code = document.getElementById('completedCourses').value;
    if (!code) return;

    const formData = new FormData();
    formData.append('action', 'mark_course_required');
    formData.append('course_code', code);

    try {
        const response = await fetch('api/student_personal_data.php', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();
        if (data.success) location.reload();
        else alert('Error: ' + data.error);
    } catch (err) {
        console.error(err);
    }
}

function toSortableValue(raw, key) {
    const value = (raw || '').trim();
    const dayOrder = {
        monday: 1,
        tuesday: 2,
        wednesday: 3,
        thursday: 4,
        friday: 5,
        saturday: 6,
        sunday: 7
    };
    const slotOrder = {
        '7-9:30': 1,
        '10-12:30': 2,
        '12:30-2': 3,
        '2-4:30': 4,
        '5-6': 5
    };

    if (key === 'day') {
        return dayOrder[value.toLowerCase()] || 99;
    }
    if (key === 'slot') {
        return slotOrder[value] || 99;
    }
    if (key === 'time') {
        const timeMatch = value.match(/(\d{1,2}):(\d{2})\s*(AM|PM)/i);
        if (timeMatch) {
            let hour = parseInt(timeMatch[1], 10);
            const minute = parseInt(timeMatch[2], 10);
            const meridian = timeMatch[3].toUpperCase();
            if (meridian === 'PM' && hour !== 12) hour += 12;
            if (meridian === 'AM' && hour === 12) hour = 0;
            return (hour * 60) + minute;
        }
    }

    const numericValue = Number(value);
    if (!Number.isNaN(numericValue) && value !== '') {
        return numericValue;
    }

    return value.toLowerCase();
}

function initSortableTables() {
    document.querySelectorAll('.sortable-table').forEach((table) => {
        const tbody = table.querySelector('tbody');
        if (!tbody) return;

        table.querySelectorAll('th[data-sort]').forEach((th) => {
            th.addEventListener('click', () => {
                const sortKey = th.getAttribute('data-sort');
                const currentDirection = th.getAttribute('data-direction') === 'asc' ? 'desc' : 'asc';

                table.querySelectorAll('th[data-sort]').forEach((header) => {
                    header.removeAttribute('data-direction');
                    const indicator = header.querySelector('.sort-indicator');
                    if (indicator) indicator.textContent = '↕';
                });

                th.setAttribute('data-direction', currentDirection);
                const activeIndicator = th.querySelector('.sort-indicator');
                if (activeIndicator) activeIndicator.textContent = currentDirection === 'asc' ? '↑' : '↓';

                const rows = Array.from(tbody.querySelectorAll('tr')).filter((row) => row.querySelector('td'));
                const colIndex = Array.from(th.parentElement.children).indexOf(th);

                rows.sort((a, b) => {
                    const aCell = a.children[colIndex];
                    const bCell = b.children[colIndex];
                    const aRaw = aCell?.getAttribute('data-value') || aCell?.textContent || '';
                    const bRaw = bCell?.getAttribute('data-value') || bCell?.textContent || '';

                    const aVal = toSortableValue(aRaw, sortKey);
                    const bVal = toSortableValue(bRaw, sortKey);

                    let result = 0;
                    if (typeof aVal === 'number' && typeof bVal === 'number') {
                        result = aVal - bVal;
                    } else {
                        result = String(aVal).localeCompare(String(bVal), undefined, { numeric: true, sensitivity: 'base' });
                    }

                    return currentDirection === 'asc' ? result : -result;
                });

                rows.forEach((row) => tbody.appendChild(row));
            });
        });
    });
}


// Responsive table fix: Ensure exam table always fits and scrolls if needed
document.addEventListener('DOMContentLoaded', function() {
    const table = document.getElementById('myExamsTable');
    if (table) {
        table.style.width = '100%';
        table.style.minWidth = '560px';
        table.style.maxWidth = '100%';
        table.style.tableLayout = 'auto';
        // Ensure cells wrap content
        const cells = table.querySelectorAll('th, td');
        cells.forEach(cell => {
            cell.style.wordBreak = 'break-word';
            cell.style.whiteSpace = 'normal';
            cell.style.padding = '8px 4px';
        });
    }
    const container = document.querySelector('.sketch-table-wrap');
    if (container) {
        container.style.overflowX = 'auto';
        container.style.width = '100%';
    }
});

document.addEventListener('DOMContentLoaded', initSortableTables);
</script>

<?php include 'includes/footer.php'; ?>
