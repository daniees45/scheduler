<?php
$page_title = 'My Courses';
require_once 'api/db.php';
require_once 'includes/ai_predictions.php';
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
if (!$has_section_column) {
    $conn->query("ALTER TABLE student_enrollments ADD COLUMN section VARCHAR(32) NULL AFTER course_id");
    $has_section_column = $column_exists($conn, 'student_enrollments', 'section');
}

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

// Smart detection from DB: unified cross-department timetable (latest only for students)
$detected_codes = [];
$course_slot_map = [];
$course_source_map = [];
$course_section_map = [];
$detected_catalog = [];

$unified_payload = unified_schedule_fetch($conn, $_SESSION, [
    'semester' => $semester,
    'saved_at' => $saved_at_selection,
]);

foreach (($unified_payload['rows'] ?? []) as $r) {
    $code = strtoupper(trim((string)($r['course_code'] ?? '')));
    if ($code === '') {
        continue;
    }

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
        $detected_dept = $department ?: 'General';
    }

    $detected_level = $level;
    if (!empty($row_level_raw) && preg_match('/\d+/', $row_level_raw, $lm)) {
        $detected_level = (int)$lm[0] ?: $level;
    }

    if (!isset($detected_catalog[$code])) {
        $detected_catalog[$code] = [
            'course_code' => $code,
            'course_title' => trim((string)($r['course_title'] ?? $code)),
            'level' => $detected_level,
            'department' => $detected_dept
        ];
    }

    $section = $extract_section(($r['section'] ?? '') . ' ' . ($r['course_title'] ?? '') . ' ' . ($r['course_code'] ?? ''));
    $section_key = $section !== '' ? $section : 'DEFAULT';

    if (!isset($course_slot_map[$code])) {
        $course_slot_map[$code] = [];
    }

    if (!isset($course_slot_map[$code][$section_key])) {
        $course_slot_map[$code][$section_key] = [
            'day' => trim((string)($r['day'] ?? 'Monday')),
            'time' => trim((string)($r['time'] ?? '09:00-10:00')),
            'room' => trim((string)($r['room'] ?? '')),
            'section' => $section
        ];
    }

    if (!isset($course_section_map[$code]) && $section_key !== 'DEFAULT') {
        $course_section_map[$code] = $section_key;
    }

    if (!isset($course_source_map[$code])) {
        $course_source_map[$code] = strtolower($detected_dept === 'General' ? 'general' : 'department');
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
        $course_pool[] = $row;
    }
}

// Fallback pool when strict student metadata filters produce zero rows
if (empty($course_pool)) {
    $fallback_sql = "SELECT c.id, c.course_code, c.course_title, c.level, c.credit_hours, c.department,
                            l.name AS lecturer_name
                     FROM courses c
                     LEFT JOIN lecturers l ON c.lecturer_id = l.id
                     WHERE c.semester = ?
                     ORDER BY c.level ASC, c.course_code ASC";
    $fallback_stmt = $conn->prepare($fallback_sql);
    if ($fallback_stmt) {
        $fallback_stmt->bind_param('s', $semester);
        $fallback_stmt->execute();
        $fallback_res = $fallback_stmt->get_result();
        while ($row = $fallback_res->fetch_assoc()) {
            $course_pool[] = $row;
        }
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

                $requested_section = strtoupper(trim((string)($_POST['selected_section'] ?? '')));
                if ($requested_section === 'DEFAULT') {
                    $requested_section = '';
                }

                $target_section = $requested_section !== ''
                    ? $requested_section
                    : ($code_lookup !== '' ? ($course_section_map[$code_lookup] ?? null) : null);
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
                        $stmt = $conn->prepare("INSERT INTO student_enrollments (user_id, course_id, section, semester) VALUES (?, ?, ?, ?)");
                        if ($stmt) {
                            $section_to_store = ($target_section && $target_section !== 'DEFAULT') ? $target_section : null;
                            $stmt->bind_param('iiss', $user_id, $course_id, $section_to_store, $semester);
                            if ($stmt->execute()) {
                                $redirect_with_message('Successfully enrolled in course.', 'success');
                            } else {
                                $message = 'Enrollment failed. Please try again.';
                                $message_type = 'error';
                            }
                        }
                    } else {
                        $stmt = $conn->prepare("INSERT INTO student_enrollments (user_id, course_id, semester) VALUES (?, ?, ?)");
                        if ($stmt) {
                            $stmt->bind_param('iis', $user_id, $course_id, $semester);
                            if ($stmt->execute()) {
                                $redirect_with_message('Successfully enrolled in course.', 'success');
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
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $redirect_with_message('Successfully unenrolled from course.', 'success');
            } else {
                $stmt2 = $conn->prepare("DELETE FROM student_enrollments WHERE user_id = ? AND course_id = ?");
                if ($stmt2) {
                    $stmt2->bind_param('ii', $user_id, $course_id);
                    if ($stmt2->execute() && $stmt2->affected_rows > 0) {
                        $redirect_with_message('Successfully unenrolled from course.', 'success');
                    }
                }
                $message = 'Unenrollment failed. Course was not found in your current enrollments.';
                $message_type = 'warning';
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
        Course availability is automatically loaded from your latest timetable snapshot for
        <strong><?php echo htmlspecialchars($department ?: 'Your Department'); ?></strong>, level <strong><?php echo (int)$level; ?></strong>.
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
                                <form method="POST" onsubmit="event.preventDefault(); showConfirm('Unenroll from this course?', 'Unenroll Course').then(result => { if(result) this.submit(); });">
                                    <input type="hidden" name="action" value="unenroll">
                                    <input type="hidden" name="course_id" value="<?php echo (int)$course['id']; ?>">
                                    <button type="submit" name="unenroll" class="small-btn btn-unenroll"><i class="fa-solid fa-user-minus"></i> Unenroll</button>
                                </form>
                                <form method="POST" onsubmit="event.preventDefault(); showConfirm('Mark this course as done? It will be removed from active enrollment.', 'Mark Complete').then(result => { if(result) this.submit(); });">
                                    <input type="hidden" name="action" value="mark_done">
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
                        <th>Section</th>
                        <th>Detected Slot</th>
                        
                        <th>Personal Fit</th>
                        <th>AI Feasibility</th>
                        
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
                            <?php
                            $code_key = strtoupper(trim((string)($course['course_code'] ?? '')));
                            $section_options = [];
                            if (isset($course_slot_map[$code_key]) && is_array($course_slot_map[$code_key])) {
                                foreach ($course_slot_map[$code_key] as $secKey => $slotMeta) {
                                    $secLabel = trim((string)($slotMeta['section'] ?? ''));
                                    if ($secLabel === '') {
                                        $secLabel = ($secKey !== 'DEFAULT') ? $secKey : 'DEFAULT';
                                    }
                                    $section_options[$secKey] = $secLabel;
                                }
                            }
                            if (empty($section_options)) {
                                $section_options = ['DEFAULT' => 'Default'];
                            }
                            ?>
                            <span class="badge-pill badge-ai"><?php echo htmlspecialchars(implode(', ', array_values($section_options))); ?></span>
                        </td>
                        <td>
                            <span class="badge-pill badge-ai">
                                <?php echo htmlspecialchars(($course['detected_day'] ?? '-') . ' ' . ($course['detected_time'] ?? '-')); ?>
                            </span>
                        </td>
                        
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
                      <td>
                            <div class="action-row">
                                <form method="POST" class="js-async-enroll-form">
                                    <input type="hidden" name="action" value="enroll">
                                    <input type="hidden" name="course_id" value="<?php echo (int)$course['id']; ?>">
                                    <select name="selected_section" class="glass-input" style="max-width:120px;">
                                        <?php foreach ($section_options as $secKey => $secLabel): ?>
                                            <option value="<?php echo htmlspecialchars($secKey); ?>"><?php echo htmlspecialchars($secLabel); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" name="enroll" class="small-btn btn-enroll"><i class="fa-solid fa-user-plus"></i> Enroll</button>
                                </form>
                                <form method="POST" onsubmit="event.preventDefault(); showConfirm('Mark this as done/completed?', 'Mark Complete').then(result => { if(result) this.submit(); });">
                                    <input type="hidden" name="action" value="mark_done">
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
                const response = await fetch('my_courses.php', {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: new FormData(form)
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
</script>

<?php include 'includes/footer.php'; ?>
