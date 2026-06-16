<?php
session_start();
require_once 'api/db.php';
require_once 'includes/access_control.php';

// Restrict access to admins only
requireRole(['super_admin', 'faculty_admin']);

header('Cache-Control: private, max-age=60, stale-while-revalidate=120');

$page_title = 'View Schedule';
include 'includes/header.php';
require_once '../lib/B2Storage.php';

// Help browsers paint the shell early while schedule data is prepared.
if (!headers_sent()) {
    header('X-Accel-Buffering: no');
}
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}
@ini_set('zlib.output_compression', '0');
@ini_set('implicit_flush', '1');
while (ob_get_level() > 0) {
    @ob_end_flush();
}
flush();

function detectExamHeaders($headers)
{
    if (!is_array($headers) || empty($headers)) {
        return false;
    }
    $headers_lower = array_map('strtolower', $headers);
    return in_array('invigilator', $headers_lower, true) || in_array('invigilator name', $headers_lower, true);
}

function findHeaderIndex(array $header_map, array $candidates): int
{
    foreach ($candidates as $candidate) {
        $k = strtolower(trim((string)$candidate));
        if (isset($header_map[$k])) {
            return (int)$header_map[$k];
        }
    }
    return -1;
}

function mapScheduleRow($row, $is_exam_schedule, $header_map = null)
{
    $header_map = is_array($header_map) ? $header_map : [];

    if ($is_exam_schedule) {
        $idx_code = findHeaderIndex($header_map, ['course code', 'course_code']);
        $idx_title = findHeaderIndex($header_map, ['course title', 'course_title']);
        $idx_invigilator = findHeaderIndex($header_map, ['invigilator', 'invigilator name', 'lecturer']);
        $idx_room = findHeaderIndex($header_map, ['room', 'exam hall', 'hall', 'room name', 'room_name']);
        $idx_day = findHeaderIndex($header_map, ['day']);
        $idx_time = findHeaderIndex($header_map, ['time', 'time slot', 'time_slot']);

        return [
            'code' => $idx_code >= 0 ? ($row[$idx_code] ?? '') : ($row[0] ?? ''),
            'title' => $idx_title >= 0 ? ($row[$idx_title] ?? '') : ($row[1] ?? ''),
            'lecturer' => $idx_invigilator >= 0 ? ($row[$idx_invigilator] ?? '') : ($row[3] ?? ''),
            'room' => $idx_room >= 0 ? ($row[$idx_room] ?? '') : ($row[7] ?? ''),
            'day' => $idx_day >= 0 ? ($row[$idx_day] ?? '') : ($row[8] ?? ''),
            'time' => $idx_time >= 0 ? ($row[$idx_time] ?? '') : ($row[9] ?? '')
        ];
    }

    return [
        'code' => $row[0] ?? '',
        'title' => $row[1] ?? '',
        'lecturer' => $row[3] ?? '',
        'room' => $row[4] ?? '',
        'day' => $row[5] ?? '',
        'time' => $row[6] ?? ''
    ];
}

function parseCsvContentToScheduleData($csv_content)
{
    $data = [];
    $is_exam_schedule = false;
    $handle = fopen('php://memory', 'r+');
    fwrite($handle, $csv_content);
    rewind($handle);

    $headers = fgetcsv($handle);
    $is_exam_schedule = detectExamHeaders($headers);
    $header_map = [];
    if (is_array($headers)) {
        foreach ($headers as $idx => $name) {
            $header_map[strtolower(trim((string)$name))] = (int)$idx;
        }
    }

    while (($row = fgetcsv($handle, 1000, ',')) !== false) {
        $data[] = mapScheduleRow($row, $is_exam_schedule, $header_map);
    }

    fclose($handle);
    return [$data, $is_exam_schedule];
}

function normalizeViewAccuracy($raw): string
{
    if (!is_string($raw) && !is_numeric($raw)) {
        return '';
    }

    if (is_numeric($raw)) {
        $value = (float)$raw;
        if ($value >= 0 && $value <= 100) {
            $formatted = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
            return $formatted . '%';
        }
        return '';
    }

    $text = trim((string)$raw);
    if ($text === '') {
        return '';
    }

    if (preg_match('/(\d{1,3}(?:\.\d+)?)\s*%+\s*accuracy/i', $text, $matches)
        || preg_match('/accuracy[^0-9]{0,20}(\d{1,3}(?:\.\d+)?)/i', $text, $matches)
        || preg_match('/(\d{1,3}(?:\.\d+)?)\s*%/', $text, $matches)
        || preg_match('/^\s*(\d{1,3}(?:\.\d+)?)\s*$/', $text, $matches)) {
        $value = (float)$matches[1];
        if ($value >= 0 && $value <= 100) {
            $formatted = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
            return $formatted . '%';
        }
    }

    return '';
}

function fetchAccuracyFromAudit(mysqli $conn, string $searchToken): string
{
    $searchToken = trim($searchToken);
    if ($searchToken === '') {
        return '';
    }

    $stmt = $conn->prepare("SELECT details FROM audit_log WHERE action IN ('SCHEDULE_GEN_SUCCESS','EXAM_GEN_SUCCESS','EXAM_COMBINED_SUCCESS') AND details LIKE ? ORDER BY log_time DESC LIMIT 1");
    if (!$stmt) {
        return '';
    }

    $search_term = "%" . $searchToken . "%";
    $stmt->bind_param("s", $search_term);
    $stmt->execute();
    $log_res = $stmt->get_result();
    if (!($log_row = $log_res->fetch_assoc())) {
        return '';
    }

    $details = (string)($log_row['details'] ?? '');
    if (preg_match('/accuracy[^0-9]{0,20}(\d{1,3}(?:\.\d+)?)/i', $details, $matches)) {
        return normalizeViewAccuracy($matches[1]);
    }
    if (preg_match('/(\d{1,3}(?:\.\d+)?)\s*%/', $details, $matches)) {
        return normalizeViewAccuracy($matches[1]);
    }

    return '';
}

function shouldUseImplicitLatest(): bool
{
    $fileParam = $_GET['file'] ?? '';
    $idParam = (int)($_GET['id'] ?? 0);
    return $idParam <= 0 && ($fileParam === '' || $fileParam === 'null' || $fileParam === 'undefined');
}

function getLatestSavedSchedule(mysqli $conn): array
{
    $stmt = $conn->prepare("SELECT id, schedule_name FROM generated_schedules ORDER BY created_at DESC, id DESC LIMIT 1");
    if (!$stmt) {
        return [0, ''];
    }

    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && ($row = $res->fetch_assoc())) {
        return [(int)($row['id'] ?? 0), (string)($row['schedule_name'] ?? '')];
    }

    return [0, ''];
}

/**
 * Logic to resolve the requested schedule file path
 */
function resolveScheduleFile() {
    $requested_file = $_GET['file'] ?? 'csv/final/final_web_schedule.csv';

    if (!isset($_GET['file']) || $_GET['file'] === '' || $_GET['file'] === 'null' || $_GET['file'] === 'undefined') {
        $default_local = realpath('../csv/final/final_web_schedule.csv');
        if (!$default_local || !file_exists($default_local)) {
            $cached_latest_b2_file = $_SESSION['latest_b2_schedule_file'] ?? null;
            $cached_latest_b2_at = (int)($_SESSION['latest_b2_schedule_file_at'] ?? 0);
            $latest_cache_ttl = 300;

            if (!empty($cached_latest_b2_file) && (time() - $cached_latest_b2_at) < $latest_cache_ttl) {
                $requested_file = $cached_latest_b2_file;
            } else {
                try {
                    $b2_init = new B2Storage();
                    $latest = $b2_init->listFiles('csv/final/', 1);
                    if (!empty($latest['success']) && !empty($latest['files'][0]['key'])) {
                        $requested_file = $latest['files'][0]['key'];
                        $_SESSION['latest_b2_schedule_file'] = $requested_file;
                        $_SESSION['latest_b2_schedule_file_at'] = time();
                    }
                } catch (Exception $e) {}
            }
        }
    }

    if ($requested_file === 'null' || $requested_file === '' || $requested_file === 'undefined') {
        $requested_file = 'csv/final/final_web_schedule.csv';
    }

    if (!preg_match('/^[a-zA-Z0-9_\-\/\.]+$/', $requested_file) || pathinfo($requested_file, PATHINFO_EXTENSION) !== 'csv') {
        $requested_file = 'csv/final/final_web_schedule.csv';
    }

    return $requested_file;
}

/**
 * Logic to fetch accuracy information
 */
function fetchAccuracyInfo($conn, $requested_file, $schedule_id = 0) {
    $accuracy_display = normalizeViewAccuracy($_GET['accuracy'] ?? '');
    
    // 1. Try from database if ID is provided
    if (empty($accuracy_display) && $schedule_id > 0) {
        $stmt = $conn->prepare("SELECT accuracy FROM generated_schedules WHERE id = ?");
        $stmt->bind_param("i", $schedule_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $accuracy_display = normalizeViewAccuracy((string)($row['accuracy'] ?? ''));
        }
    }

    // 1b. Try latest matching DB record by schedule filename when ID is absent.
    if (empty($accuracy_display) && !empty($requested_file)) {
        $basename = basename((string)$requested_file);
        $base_no_ext = pathinfo($basename, PATHINFO_FILENAME);

        $stmt = $conn->prepare("SELECT accuracy FROM generated_schedules WHERE (LOWER(TRIM(schedule_name)) = LOWER(?) OR LOWER(TRIM(schedule_name)) = LOWER(?) OR LOWER(TRIM(schedule_name)) LIKE LOWER(?)) ORDER BY created_at DESC, id DESC LIMIT 1");
        if ($stmt) {
            $with_prefix = 'csv/final/' . $basename;
            $like_term = '%' . $base_no_ext . '%';
            $stmt->bind_param("sss", $basename, $with_prefix, $like_term);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $accuracy_display = normalizeViewAccuracy((string)($row['accuracy'] ?? ''));
            }
        }
    }

    // 2. Fallback to audit logs if still empty
    if (empty($accuracy_display) && !empty($requested_file)) {
        $file_basename = pathinfo($requested_file, PATHINFO_FILENAME);
        $accuracy_display = fetchAccuracyFromAudit($conn, $file_basename);

        // Fallback with full basename including extension for logs that contain full filename.
        if (empty($accuracy_display)) {
            $accuracy_display = fetchAccuracyFromAudit($conn, basename((string)$requested_file));
        }
    }
    return $accuracy_display;
}

/**
 * Load schedule data from DB or CSV
 */
function loadScheduleRawData($conn, $schedule_id, $requested_file) {
    $data = [];
    $is_exam_schedule = false;

    if ($schedule_id > 0) {
        $stmt = $conn->prepare("SELECT schedule_data FROM generated_schedules WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $schedule_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $json = $row['schedule_data'] ?? '';
            $decoded = json_decode($json, true);
            if (is_array($decoded) && count($decoded) > 0) {
                $headers = array_shift($decoded);
                $is_exam_schedule = detectExamHeaders($headers);
                $header_map = [];
                if (is_array($headers)) {
                    foreach ($headers as $idx => $name) {
                        $header_map[strtolower(trim((string)$name))] = (int)$idx;
                    }
                }
                foreach ($decoded as $row) {
                    $data[] = mapScheduleRow($row, $is_exam_schedule, $header_map);
                }
            } elseif (!empty($json)) {
                $lines = preg_split('/\r\n|\r|\n/', $json);
                $rows = [];
                foreach ($lines as $line) {
                    if (trim($line) === '') continue;
                    $rows[] = str_getcsv($line);
                }
                if (!empty($rows)) {
                    $headers = array_shift($rows);
                    $is_exam_schedule = detectExamHeaders($headers);
                    $header_map = [];
                    if (is_array($headers)) {
                        foreach ($headers as $idx => $name) {
                            $header_map[strtolower(trim((string)$name))] = (int)$idx;
                        }
                    }
                    foreach ($rows as $row) {
                        $data[] = mapScheduleRow($row, $is_exam_schedule, $header_map);
                    }
                }
            }
        }
    }

    if (empty($data)) {
        $csv_file = realpath('../' . $requested_file);
        $base_dir = realpath('../');
        if (!$csv_file || strpos($csv_file, $base_dir) !== 0 || !file_exists($csv_file)) {
            $csv_file = '../final_web_schedule.csv';
        }

        $cache_dir = sys_get_temp_dir() . '/scheduler_view_cache';
        if (!is_dir($cache_dir)) @mkdir($cache_dir, 0775, true);
        $cache_file = $cache_dir . '/schedule_' . sha1($requested_file) . '.json';
        $cache_ttl = 180;

        if (file_exists($cache_file) && (time() - filemtime($cache_file) < $cache_ttl)) {
            $cached_payload = json_decode((string)file_get_contents($cache_file), true);
            if (is_array($cached_payload) && isset($cached_payload['data']) && is_array($cached_payload['data'])) {
                $data = $cached_payload['data'];
                $is_exam_schedule = !empty($cached_payload['is_exam_schedule']);
            }
        }

        if (empty($data)) {
            $csv_content = file_exists($csv_file) ? file_get_contents($csv_file) : null;
            if ($csv_content === null || $csv_content === false) {
                $b2 = new B2Storage();
                $b2_key = (strpos($requested_file, 'csv/') !== 0) ? 'csv/final/' . $requested_file : $requested_file;
                $download = $b2->download($b2_key);
                if (!empty($download['success'])) $csv_content = $download['content'];
            }
            if ($csv_content !== null && $csv_content !== false) {
                [$data, $is_exam_schedule] = parseCsvContentToScheduleData($csv_content);
                $payload = json_encode(['is_exam_schedule' => $is_exam_schedule, 'data' => $data]);
                if ($payload !== false) @file_put_contents($cache_file, $payload, LOCK_EX);
            }
        }
    }
    return [$data, $is_exam_schedule];
}

$schedule_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (shouldUseImplicitLatest()) {
    [$latest_id, $latest_name] = getLatestSavedSchedule($conn);
    if ($latest_id > 0) {
        $schedule_id = $latest_id;
        if ($latest_name !== '') {
            $_GET['file'] = $latest_name;
        }
    }
}
$requested_file = resolveScheduleFile();
$accuracy_display = fetchAccuracyInfo($conn, $requested_file, $schedule_id);

[$data, $is_exam_schedule] = loadScheduleRawData($conn, $schedule_id, $requested_file);

// Filtering
$filter_lecturer = $_GET['lecturer'] ?? '';
$filter_room = $_GET['room'] ?? '';
$filter_day = $_GET['day'] ?? '';

// User context for filtering
$user_role = $_SESSION['user_role'] ?? '';
$enrolled_course_codes = [];

if ($user_role === 'lecturer' && !empty($_SESSION['lecturer_id'])) {
    $stmt = $conn->prepare("SELECT name FROM lecturers WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['lecturer_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) $filter_lecturer = $row['name'];
    $filter_room = '';
    $filter_day = $_GET['day'] ?? '';
} elseif ($user_role === 'student') {
    if (!empty($_SESSION['user_id'])) {
        $student_semester = (string)($_SESSION['semester'] ?? '1');
        $stmt = $conn->prepare("SELECT c.course_code FROM student_enrollments se JOIN courses c ON se.course_id = c.id WHERE se.user_id = ? AND (se.semester = ? OR se.semester IS NULL OR TRIM(se.semester) = '')");
        $stmt->bind_param("is", $_SESSION['user_id'], $student_semester);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) $enrolled_course_codes[] = strtolower(trim($row['course_code']));
    }
    $filter_lecturer = '';
    $filter_room = '';
    $filter_day = $_GET['day'] ?? '';
}

if ($filter_lecturer) {
    $data = array_filter($data, function ($item) use ($filter_lecturer) {
        return stripos($item['lecturer'] ?? '', $filter_lecturer) !== false;
    });
}
if ($filter_room) {
    $data = array_filter($data, function ($item) use ($filter_room) {
        return stripos($item['room'] ?? '', $filter_room) !== false;
    });
}
if ($filter_day) {
    $data = array_filter($data, function ($item) use ($filter_day) {
        return stripos($item['day'] ?? '', $filter_day) !== false;
    });
}
if ($user_role === 'student' && !empty($enrolled_course_codes)) {
    $data = array_filter($data, function ($item) use ($enrolled_course_codes) {
        return in_array(strtolower(trim($item['code'] ?? '')), $enrolled_course_codes);
    });
}

$days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
$items_per_page = 20;
$total_items = count($data);
$total_pages = ceil($total_items / $items_per_page);
$current_page = max(1, min($total_pages, (int)($_GET['page'] ?? 1)));
$offset = ($current_page - 1) * $items_per_page;

$day_order = array_flip($days);
usort($data, function ($a, $b) use ($day_order) {
    $da = $day_order[$a['day']] ?? 99;
    $db = $day_order[$b['day']] ?? 99;
    if ($da != $db) return $da - $db;
    return strcmp($a['time'] ?? '', $b['time'] ?? '');
});

$paged_data = array_slice($data, $offset, $items_per_page);
?>

<div style="max-width: 1200px; margin: 0 auto;">
    
    <?php if ($is_exam_schedule): ?>
    <!-- Exam Schedule Header Badge -->
    <div class="glass-panel" style="padding: 1.5rem; margin-bottom: 1.5rem; background: linear-gradient(135deg, rgba(239, 68, 68, 0.15), rgba(251, 146, 60, 0.15)); border: 2px solid rgba(239, 68, 68, 0.4);">
        <div style="display: flex; align-items: center; gap: 1rem;">
            <div style="width: 60px; height: 60px; background: linear-gradient(135deg, #ef4444, #fb923c); border-radius: 12px; display: flex; align-items: center; justify-content: center; box-shadow: 0 8px 16px rgba(239, 68, 68, 0.3);">
                <i class="fa-solid fa-file-pen" style="font-size: 1.8rem; color: white;"></i>
            </div>
            <div style="flex: 1;">
                <h2 style="margin: 0 0 0.3rem 0; color: #fbbf24; font-size: 1.5rem; font-weight: 700; display: flex; align-items: center; gap: 10px;">
                    <i class="fa-solid fa-graduation-cap"></i>
                    Examination Timetable
                </h2>
                <p style="margin: 0; color: var(--text-muted); font-size: 0.95rem;">
                    <i class="fa-solid fa-info-circle"></i> View all scheduled exams with hall assignments, invigilators, and time slots.
                </p>
            </div>
            <div style="background: rgba(251, 146, 60, 0.2); padding: 0.75rem 1.5rem; border-radius: 8px; border: 1px solid rgba(251, 146, 60, 0.4);">
                <div style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">Total Exams</div>
                <div style="font-size: 1.8rem; font-weight: 700; color: #fbbf24; font-family: monospace;"><?php echo count($data); ?></div>
            </div>
        </div>
    </div>
    <?php
endif; ?>

 <?php if (!$is_exam_schedule): ?>
    <!-- Class Timetable Header Badge -->
    <div class="glass-panel" style="padding: 1.5rem; margin-bottom: 1.5rem; background: linear-gradient(135deg, rgba(59, 130, 246, 0.15), rgba(99, 102, 241, 0.15)); border: 2px solid rgba(59, 130, 246, 0.4);">
        <div style="display: flex; align-items: center; gap: 1rem;">
            <div style="width: 60px; height: 60px; background: linear-gradient(135deg, #3b82f6, #6366f1); border-radius: 12px; display: flex; align-items: center; justify-content: center; box-shadow: 0 8px 16px rgba(59, 130, 246, 0.3);">
                <i class="fa-solid fa-calendar-days" style="font-size: 1.8rem; color: white;"></i>
            </div>
            <div style="flex: 1;">
                <h2 style="margin: 0 0 0.3rem 0; color: #60a5fa; font-size: 1.5rem; font-weight: 700; display: flex; align-items: center; gap: 10px;">
                    <i class="fa-solid fa-book"></i>
                    Class Timetable
                </h2>
                <p style="margin: 0; color: var(--text-muted); font-size: 0.95rem;">
                    <i class="fa-solid fa-info-circle"></i> View all scheduled classes with instructors, rooms, and time slots.
                </p>
            </div>
            <div style="background: rgba(59, 130, 246, 0.2); padding: 0.75rem 1.5rem; border-radius: 8px; border: 1px solid rgba(59, 130, 246, 0.4);">
                <div style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">Total Classes</div>
                <div style="font-size: 1.8rem; font-weight: 700; color: #60a5fa; font-family: monospace;"><?php echo count($data); ?></div>
            </div>
        </div>
    </div>
    <?php
endif; ?>
    
    <?php if ($accuracy_display): ?>
    <div class="glass-panel" style="padding: 1rem; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 1rem; border-left: 4px solid #10b981;">
        <div style="background: rgba(16, 185, 129, 0.2); padding: 8px 12px; border-radius: 8px; color: #10b981; font-weight: 700;">
            <i class="fa-solid fa-bullseye"></i> AI Accuracy: <?php echo htmlspecialchars($accuracy_display); ?>
        </div>
        <p style="margin: 0; font-size: 0.85rem; color: var(--text-muted);">
            This schedule was generated with a high optimization score.
        </p>
    </div>
    <?php endif; ?>
    
    <!-- Control Center: Filters & Actions in Separate Cards on Same Line -->
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; max-width: 1200px; margin: 0 auto; margin-bottom: 1.5rem;">
        
        <!-- Card 1: Advanced Filters -->
        <div class="glass-panel" style="padding: 1.5rem; ">
            <?php if ($user_role === 'super_admin' || $user_role === 'faculty_admin'): ?>
            <!-- Admin Filters -->
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 1px solid var(--border-color);">
                <h3 style="margin: 0; font-size: 1.1rem; color: var(--primary-color); display: flex; align-items: center; gap: 10px;">
                    <i class="fa-solid fa-filter"></i> Advanced Filters
                </h3>
                <div style="position: relative; width: 200px;">
                    <i class="fa-solid fa-search"
                        style="position: absolute; left: 10px; top: 10px; color: var(--text-muted); font-size: 0.8rem;"></i>
                    <input type="text" id="liveSearchQuick" class="glass-input live-search-input" placeholder="Quick search..."
                        style="padding-left: 30px; font-size: 0.85rem; background: rgba(0,0,0,0.1); border-radius: 16px;">
                </div>
            </div>
            <form action="" method="GET"
                style="display: grid; grid-template-columns: 1fr; gap: 1rem;">
                <?php if ($schedule_id > 0): ?>
                <input type="hidden" name="id" value="<?php echo $schedule_id; ?>">
                <?php
    endif; ?>
                <?php if (isset($_GET['file'])): ?>
                <input type="hidden" name="file" value="<?php echo htmlspecialchars($_GET['file']); ?>">
                <?php
    endif; ?>
                <div>
                    <label class="stat-label" style="font-size: 0.75rem;">
                        <?php echo $is_exam_schedule ? 'Invigilator' : 'Lecturer'; ?>
                    </label>
                    <div style="position: relative;">
                        <i class="fa-solid <?php echo $is_exam_schedule ? 'fa-user-shield' : 'fa-user-tie'; ?>"
                            style="position: absolute; left: 10px; top: 12px; color: var(--text-muted); font-size: 0.85rem;"></i>
                        <input type="text" name="lecturer" class="glass-input small" style="padding-left: 32px;"
                            placeholder="Name..." value="<?php echo htmlspecialchars($filter_lecturer); ?>">
                    </div>
                </div>
                <div>
                    <label class="stat-label" style="font-size: 0.75rem;">
                        <?php echo $is_exam_schedule ? 'Exam Hall' : 'Room'; ?>
                    </label>
                    <div style="position: relative;">
                        <i class="fa-solid <?php echo $is_exam_schedule ? 'fa-building' : 'fa-location-dot'; ?>"
                            style="position: absolute; left: 10px; top: 12px; color: var(--text-muted); font-size: 0.85rem;"></i>
                        <input type="text" name="room" class="glass-input small" style="padding-left: 32px;"
                            placeholder="Room..."
                            value="<?php echo htmlspecialchars($filter_room); ?>">
                    </div>
                </div>
                <div>
                    <label class="stat-label" style="font-size: 0.75rem;">Day</label>
                    <div style="position: relative;">
                        <i class="fa-solid fa-calendar-day"
                            style="position: absolute; left: 10px; top: 12px; color: var(--text-muted); font-size: 0.85rem;"></i>
                        <select name="day" class="glass-input small"
                            style="padding-left: 32px; background: rgba(15, 23, 42, 0.8);">
                            <option value="">All Days</option>
                            <?php foreach ($days as $d): ?>
                            <option value="<?php echo $d; ?>" <?php if ($filter_day == $d)
                    echo 'selected'; ?>>
                                        <?php echo $d; ?>
                                    </option>
                                    <?php
            endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <button type="submit" class="glass-btn primary small" style="width: 100%; justify-content: center;">
                            <i class="fa-solid fa-sync"></i> Apply Filters
                                    </button>
                                </form>
                                <?php
                    endif; ?>
        </div>

        <!-- Card 2: Quick Actions -->
        <div class="glass-panel" style="padding: 1.5rem;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.25rem; padding-bottom: 1rem; border-bottom: 1px solid var(--border-color);">
                <h3 style="margin: 0; font-size: 1.1rem; color: var(--warning); display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-bolt"></i> Quick Actions
                </h3>
                <div style="display: flex; gap: 6px;">
                    <button onclick="saveVersion()" class="glass-btn small" title="Save Snapshot" style="padding: 8px 10px !important; font-size: 0.8rem;">
                        <i class="fa-solid fa-save"></i>
                    </button>
                    <button onclick="exportB2ToPDF()" class="glass-btn small primary"
                        style="padding: 8px 10px !important; font-size: 0.8rem;"
                        title="Cloud Export">
                        <i class="fa-solid fa-cloud-arrow-down"></i>
                    </button>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr; gap: 0.8rem;">
                <div>
                    <label class="stat-label" style="font-size: 0.75rem;">Generated Schedules (Cloud)</label>
                    <div style="display: flex; gap: 5px;">
                        <select id="b2ScheduleSelect" class="glass-input small"
                            style="background: rgba(0,0,0,0.2); font-size: 0.85rem; flex: 1;">
                            <option value="">Loading scenarios...</option>
                        </select>
                        <button onclick="viewSelectedB2Schedule()" class="glass-btn secondary small" style="padding: 8px 12px !important;">
                            <i class="fa-solid fa-play"></i>
                        </button>
                    </div>
                    <div id="b2ScheduleSkeleton" class="mobile-card-skeleton active" aria-hidden="true">
                        <div class="skeleton-card">
                            <span class="skeleton-line primary"></span>
                            <span class="skeleton-line secondary"></span>
                        </div>
                        <div class="skeleton-card">
                            <span class="skeleton-line primary"></span>
                            <span class="skeleton-line secondary"></span>
                        </div>
                        <div class="skeleton-card">
                            <span class="skeleton-line primary"></span>
                            <span class="skeleton-line secondary"></span>
                        </div>
                    </div>
                </div>

                <div style="display: flex; gap: 8px;">
                    <button onclick="saveSelectedB2ToDb()" class="glass-btn small"
                        style="background: linear-gradient(135deg, #10b981, #059669); flex: 1; padding: 9px !important; font-size: 0.8rem;">
                        <i class="fa-solid fa-check"></i> Commit to DB
                    </button>
                    <button onclick="acceptAndLearnFromSavedSchedule()" class="glass-btn small"
                        style="background: linear-gradient(135deg, #0ea5e9, #6366f1); flex: 1; padding: 9px !important; font-size: 0.8rem;">
                        <i class="fa-solid fa-brain"></i> AI Learn
                    </button>
                </div>
                
                <?php if ($user_role === 'super_admin' || $user_role === 'faculty_admin'): ?>
                <button id="editModeBtn" onclick="toggleEditMode()" class="glass-btn small"
                    style="background: rgba(99, 102, 241, 0.1); border: 1px solid rgba(99, 102, 241, 0.2); width: 100%; padding: 9px !important; border-radius: 8px; font-size: 0.8rem;">
                    <i class="fa-solid fa-arrows-alt"></i> Enable Drag-Drop
                </button>
                <?php
endif; ?>
            </div>
        </div>
    </div>

    <!-- Main Schedule Table -->
    <div class="glass-panel" style="padding: 0; overflow: hidden; position: relative;">
        <!-- Gradient Line Top -->
        <?php if ($is_exam_schedule): ?>
        <div style="height: 3px; background: linear-gradient(90deg, #ef4444, #fb923c, #fbbf24);"></div>
        <?php
else: ?>
        <div style="height: 3px; background: linear-gradient(90deg, var(--primary), var(--secondary));"></div>
        <?php
endif; ?>
        
        <div class="table-container schedule-shell" style="max-height: 900px; overflow-y: auto;">
            <!-- Row Search Bar -->
            <div style="padding: 1rem 1.5rem; background: rgba(255,255,255,0.02); border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
                <div style="position: relative; flex: 1; min-width: 250px;">
                    <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 0.9rem;"></i>
                    <input type="text" id="liveSearchTable" class="glass-input live-search-input" style="padding-left: 38px; width: 100%; border-radius: 10px;" placeholder="Search by course, lecturer, room or day...">
                </div>
                <div style="display: flex; align-items: center; gap: 12px; font-size: 0.85rem; color: var(--text-muted);">
                    <span id="searchCount"><?php echo count($data); ?></span> Items Found
                    <div style="width: 1px; height: 16px; background: rgba(255,255,255,0.1);"></div>
                    <span style="color: var(--primary-color);"><i class="fa-solid fa-info-circle"></i> Showing all results</span>
                </div>
            </div>
        <?php if (empty($data)): ?>
            <div style="text-align: center; padding: 4rem 2rem; color: var(--text-muted); display: flex; flex-direction: column; align-items: center;">
                <?php if (!file_exists($csv_file)): ?>
                    <div style="width: 80px; height: 80px; background: rgba(255,255,255,0.05); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-bottom: 1.5rem;">
                        <?php if ($is_exam_schedule): ?>
                        <i class="fa-solid fa-file-pen" style="font-size: 2.5rem; opacity: 0.5; color: #fb923c;"></i>
                        <?php
        else: ?>
                        <i class="fa-solid fa-calendar-xmark" style="font-size: 2.5rem; opacity: 0.5;"></i>
                        <?php
        endif; ?>
                    </div>
                    <?php if ($is_exam_schedule): ?>
                    <h3 style="margin-bottom: 0.5rem; color: #fbbf24;">No Exam Schedule Generated</h3>
                    <p style="margin-bottom: 2rem;">Use the AI Generator to create your exam timetable.</p>
                    <?php
        else: ?>
                    <h3 style="margin-bottom: 0.5rem;">No Schedule Generated</h3>
                    <p style="margin-bottom: 2rem;">Use the AI Generator to create your first schedule.</p>
                    <?php
        endif; ?>
                    <a href="generate.php" class="glass-btn"><i class="fa-solid fa-wand-magic-sparkles"></i> Go to Generator</a>
                <?php
    else: ?>
                    <div style="width: 80px; height: 80px; background: rgba(255,255,255,0.05); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-bottom: 1.5rem;">
                        <i class="fa-solid fa-filter-circle-xmark" style="font-size: 2.5rem; opacity: 0.5;"></i>
                    </div>
                    <?php if ($is_exam_schedule): ?>
                    <h3>No Exams Found</h3>
                    <?php
        else: ?>
                    <h3>No Classes Found</h3>
                    <?php
        endif; ?>
                    <p>Try adjusting your search filters.</p>
                <?php
    endif; ?>
            </div>
        <?php
else: ?>
            <table class="schedule-table" style="width: 100%; border-collapse: collapse; font-size: 0.95rem;">
                <?php if ($is_exam_schedule): ?>
                <!-- EXAM SCHEDULE TABLE -->
                <thead style="background: rgba(239, 68, 68, 0.15); position: sticky; top: 0; z-index: 10; backdrop-filter: blur(5px); border-bottom: 2px solid rgba(239, 68, 68, 0.3);">
                    <tr>
                        <th style="padding: 1rem; text-align: left; font-weight: 600; color: #fbbf24; border-bottom: 1px solid rgba(255,255,255,0.05);">
                            <i class="fa-solid fa-calendar-day"></i> Day
                        </th>
                        <th style="padding: 1rem; text-align: left; font-weight: 600; color: #fbbf24; border-bottom: 1px solid rgba(255,255,255,0.05);">
                            <i class="fa-solid fa-clock"></i> Time Slot
                        </th>
                        <th style="padding: 1rem; text-align: left; font-weight: 600; color: #fbbf24; border-bottom: 1px solid rgba(255,255,255,0.05);">
                            <i class="fa-solid fa-book"></i> Course
                        </th>
                        <th style="padding: 1rem; text-align: left; font-weight: 600; color: #fbbf24; border-bottom: 1px solid rgba(255,255,255,0.05);">
                            <i class="fa-solid fa-building"></i> Exam Hall
                        </th>
                        <th style="padding: 1rem; text-align: left; font-weight: 600; color: #fbbf24; border-bottom: 1px solid rgba(255,255,255,0.05);">
                            <i class="fa-solid fa-user-shield"></i> Invigilator
                        </th>
                    </tr>
                </thead>
                <tbody id="scheduleTableBody">
                    <?php foreach ($paged_data as $idx => $row):
            $bg = $idx % 2 == 0 ? 'rgba(239, 68, 68, 0.05)' : 'rgba(251, 146, 60, 0.03)';
?>
                        <tr style="background: <?php echo $bg; ?>; border-bottom: 1px solid rgba(255,255,255,0.02); transition: all 0.3s; border-left: 3px solid transparent;" 
                            onmouseover="this.style.background='rgba(251, 146, 60, 0.12)'; this.style.borderLeftColor='#fb923c';" 
                            onmouseout="this.style.background='<?php echo $bg; ?>'; this.style.borderLeftColor='transparent';">
                            <td data-label="Day" style="padding: 1.2rem 1rem;">
                                <span style="font-weight: 700; color: #fbbf24; font-size: 1rem; display: inline-flex; align-items: center; gap: 6px;">
                                    <i class="fa-solid fa-calendar" style="font-size: 0.85rem; opacity: 0.7;"></i>
                                    <?php echo htmlspecialchars($row['day']); ?>
                                </span>
                            </td>
                            <td data-label="Time" style="padding: 1.2rem 1rem;">
                                <div style="display: inline-flex; align-items: center; gap: 8px; background: linear-gradient(135deg, rgba(239, 68, 68, 0.2), rgba(251, 146, 60, 0.2)); padding: 8px 16px; border-radius: 20px; font-family: 'Courier New', monospace; font-size: 0.9rem; color: white; font-weight: 600; border: 1px solid rgba(251, 146, 60, 0.4);">
                                    <i class="fa-regular fa-clock" style="font-size: 1rem;"></i> 
                                    <?php echo htmlspecialchars($row['time']); ?>
                                </div>
                            </td>
                            <td data-label="Course" style="padding: 1.2rem 1rem;">
                                <div style="font-weight: 700; color: #60a5fa; margin-bottom: 5px; font-size: 1.05rem;"><?php echo htmlspecialchars($row['code']); ?></div>
                                <div style="font-size: 0.88rem; color: var(--text-muted); line-height: 1.4;"><?php echo htmlspecialchars($row['title']); ?></div>
                            </td>
                            <td data-label="Hall" style="padding: 1.2rem 1rem;">
                                <div style="display: inline-flex; align-items: center; gap: 8px; background: rgba(16, 185, 129, 0.15); padding: 8px 14px; border-radius: 8px; border: 1px solid rgba(16, 185, 129, 0.3);">
                                    <i class="fa-solid fa-door-open" style="font-size: 1rem; color: #10b981;"></i>
                                    <span style="color: #34d399; font-weight: 600;"><?php echo htmlspecialchars($row['room']); ?></span>
                                </div>
                            </td>
                            <td data-label="Invigilator" style="padding: 1.2rem 1rem;">
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <div style="width: 32px; height: 32px; background: linear-gradient(135deg, #f59e0b, #ef4444); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.85rem; color: white; font-weight: 700; box-shadow: 0 2px 8px rgba(245, 158, 11, 0.4);">
                                        <?php echo strtoupper(substr($row['lecturer'], 0, 1)); ?>
                                    </div>
                                    <span style="color: var(--text-main); font-weight: 500;"><?php echo htmlspecialchars($row['lecturer']); ?></span>
                                </div>
                            </td>
                        </tr>
                    <?php
        endforeach; ?>
                </tbody>
                
                <?php
    else: ?>
                <!-- REGULAR CLASS SCHEDULE TABLE -->
                <thead style="background: rgba(0,0,0,0.2); position: sticky; top: 0; z-index: 10; backdrop-filter: blur(5px);">
                    <tr>
                        <th style="padding: 1rem; text-align: left; font-weight: 600; color: var(--text-muted); border-bottom: 1px solid rgba(255,255,255,0.05);">Day</th>
                        <th style="padding: 1rem; text-align: left; font-weight: 600; color: var(--text-muted); border-bottom: 1px solid rgba(255,255,255,0.05);">Time</th>
                        <th style="padding: 1rem; text-align: left; font-weight: 600; color: var(--text-muted); border-bottom: 1px solid rgba(255,255,255,0.05);">Course</th>
                        <th style="padding: 1rem; text-align: left; font-weight: 600; color: var(--text-muted); border-bottom: 1px solid rgba(255,255,255,0.05);">Room</th>
                        <th style="padding: 1rem; text-align: left; font-weight: 600; color: var(--text-muted); border-bottom: 1px solid rgba(255,255,255,0.05);">Lecturer</th>
                    </tr>
                </thead>
                <tbody id="scheduleTableBody">
                    <?php foreach ($paged_data as $idx => $row):
            $bg = $idx % 2 == 0 ? 'rgba(255,255,255,0.01)' : 'transparent';
?>
                        <tr style="background: <?php echo $bg; ?>; border-bottom: 1px solid rgba(255,255,255,0.02); transition: background 0.2s;">
                            <td data-label="Day" style="padding: 1rem;">
                                <span style="font-weight: 600; color: var(--text-main);"><?php echo htmlspecialchars($row['day']); ?></span>
                            </td>
                            <td data-label="Time" style="padding: 1rem;">
                                <div style="display: inline-flex; align-items: center; gap: 5px; background: rgba(255,255,255,0.05); padding: 4px 10px; border-radius: 20px; font-family: monospace; font-size: 0.85rem; color: var(--warning);">
                                    <i class="fa-regular fa-clock"></i> <?php echo htmlspecialchars($row['time']); ?>
                                </div>
                            </td>
                            <td data-label="Course" style="padding: 1rem;">
                                <div style="font-weight: 700; color: var(--primary-color); margin-bottom: 3px;"><?php echo htmlspecialchars($row['code']); ?></div>
                                <div style="font-size: 0.85rem; color: var(--text-muted);"><?php echo htmlspecialchars($row['title']); ?></div>
                            </td>
                            <td data-label="Room" style="padding: 1rem;">
                                <div style="color: #4ade80; display: flex; align-items: center; gap: 5px;">
                                    <i class="fa-solid fa-door-open" style="font-size: 0.8rem;"></i> <?php echo htmlspecialchars($row['room']); ?>
                                </div>
                            </td>
                            <td data-label="Lecturer" style="padding: 1rem;">
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <div style="width: 25px; height: 25px; background: linear-gradient(45deg, #4f46e5, #ec4899); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.7rem; color: white;">
                                        <?php echo substr($row['lecturer'], 0, 1); ?>
                                    </div>
                                    <span><?php echo htmlspecialchars($row['lecturer']); ?></span>
                                </div>
                            </td>
                        </tr>
                    <?php
        endforeach; ?>
                </tbody>
                <?php
    endif; ?>
            </table>
            
            <!-- Pagination Controls -->
            <?php if ($total_pages > 1): ?>
            <div id="schedulePagination" style="padding: 1rem; border-top: 1px solid rgba(255,255,255,0.05); display: flex; justify-content: center; align-items: center; gap: 1rem;">
                <?php
        $params = $_GET;
        function build_query($p, $params)
        {
            $params['page'] = $p;
            return '?' . http_build_query($params);
        }
?>
                <a href="<?php echo build_query(max(1, $current_page - 1), $params); ?>" class="glass-btn secondary small <?php if ($current_page <= 1)
            echo 'disabled'; ?>" style="<?php if ($current_page <= 1)
            echo 'opacity: 0.5; pointer-events: none;'; ?>">
                    <i class="fa-solid fa-chevron-left"></i>
                </a>
                <span style="font-size: 0.9rem; color: var(--text-muted);">Page <?php echo $current_page; ?> of <?php echo $total_pages; ?></span>
                <a href="<?php echo build_query(min($total_pages, $current_page + 1), $params); ?>" class="glass-btn secondary small <?php if ($current_page >= $total_pages)
            echo 'disabled'; ?>" style="<?php if ($current_page >= $total_pages)
            echo 'opacity: 0.5; pointer-events: none;'; ?>">
                    <i class="fa-solid fa-chevron-right"></i>
                </a>
            </div>
            <?php
    endif; ?>
        <?php
endif; ?>
        </div>
    </div>
</div>



<script>
function setB2SkeletonLoading(isLoading) {
    const skeleton = document.getElementById('b2ScheduleSkeleton');
    if (!skeleton) return;
    skeleton.classList.toggle('active', !!isLoading);
}

async function loadVersionList() {
    const select = document.getElementById('versionSelect');
    if (!select) return; // Element might not exist on this page
    try {
        const formData = new FormData();
        formData.append('action', 'list');
        const res = await fetch('api/schedule_versions.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.status === 'success') {
            select.innerHTML = '<option value="">Select Version...</option>';
            data.versions.forEach(v => {
                const opt = document.createElement('option');
                opt.value = v.id;
                opt.innerText = `${v.version_name} (${v.created_at.substring(5,16)})`;
                select.appendChild(opt);
            });
        }
    } catch (e) { console.error(e); }
}

async function loadDbSchedules() {
    const select = document.getElementById('b2ScheduleSelect');
    if (!select) return;
    setB2SkeletonLoading(true);
    try {
        const res = await fetch('api/list_b2_schedules.php');
        const data = await res.json();
        if (data.status === 'success') {
            select.innerHTML = '<option value="">Select Schedule...</option>';
            data.schedules.forEach(s => {
                const when = s.uploaded ? new Date(s.uploaded).toLocaleString() : 'Unknown time';
                const label = `${s.name || 'Schedule'} | ${s.department || 'Dept'} | ${when}`;
                const opt = document.createElement('option');
                opt.value = s.file;
                opt.innerText = label;
                opt.dataset.name = s.name || '';
                opt.dataset.semester = s.semester || '';
                opt.dataset.department = s.department || 'General';
                opt.dataset.type = s.type || '';
                opt.dataset.saved = s.saved_to_db ? '1' : '0';
                opt.dataset.accuracy = s.accuracy || '0%';
                select.appendChild(opt);
            });

            // Select current file if applicable
            const currentFile = '<?php echo htmlspecialchars($requested_file, ENT_QUOTES); ?>';
            if (currentFile) {
                const found = Array.from(select.options).find(o => o.value === currentFile);
                if (found) select.value = currentFile;
            }

            updateSaveRecommendation();
        }
    } catch (e) { console.error(e); }
    finally {
        setB2SkeletonLoading(false);
    }
}

function updateSaveRecommendation() {
    const select = document.getElementById('b2ScheduleSelect');
    const icon = document.getElementById('saveRecommendationIcon');
    const title = document.getElementById('saveRecommendationTitle');
    const text = document.getElementById('saveRecommendationText');
    const panel = document.getElementById('saveRecommendation');

    if (!select || !icon || !title || !text || !panel) return;

    if (!select.value) {
        panel.style.background = 'rgba(99, 102, 241, 0.12)';
        panel.style.borderColor = 'rgba(99, 102, 241, 0.35)';
        icon.className = 'fa-solid fa-circle-info';
        icon.style.color = '#818cf8';
        title.textContent = 'Recommendation';
        text.textContent = 'Select schedule to see save recommendation.';
        return;
    }

    const opt = select.options[select.selectedIndex];
    const isSaved = (opt?.dataset?.saved === '1');
    const name = opt?.dataset?.name || select.value;

    if (isSaved) {
        panel.style.background = 'rgba(16, 185, 129, 0.12)';
        panel.style.borderColor = 'rgba(16, 185, 129, 0.35)';
        icon.className = 'fa-solid fa-circle-check';
        icon.style.color = '#10b981';
        title.textContent = 'Already Saved to DB';
        text.textContent = `${name} is already stored in database. Recommended: only save again if you intentionally want a new copy/version.`;
    } else {
        panel.style.background = 'rgba(251, 146, 60, 0.12)';
        panel.style.borderColor = 'rgba(251, 146, 60, 0.35)';
        icon.className = 'fa-solid fa-triangle-exclamation';
        icon.style.color = '#fb923c';
        title.textContent = 'Not Saved to DB Yet';
        text.textContent = `${name} exists in cloud storage. Recommended: review the timetable, then click “Save Selected Schedule to DB” to make it available system-wide.`;
    }
}

async function viewSelectedB2Schedule() {
    const select = document.getElementById('b2ScheduleSelect');
    if (!select) return;
    const file = select.value;
    if (!file) {
        await customAlert('Select File', 'Please choose a schedule first.', 'warning');
        return;
    }
    window.location.href = 'view_schedule.php?file=' + encodeURIComponent(file);
}

async function saveSelectedB2ToDb() {
    const select = document.getElementById('b2ScheduleSelect');
    if (!select || !select.value) {
        await customAlert('Select File', 'Please choose a schedule first.', 'warning');
        return;
    }

    const selectedOpt = select.options[select.selectedIndex];
    const file = select.value;
    const scheduleName = selectedOpt?.dataset?.name || file.split('/').pop();
    const semester = selectedOpt?.dataset?.semester || '';
    const defaultDept = selectedOpt?.dataset?.department || 'General';
    const alreadySaved = selectedOpt?.dataset?.saved === '1';

    if (alreadySaved) {
        const proceed = await customConfirm('Already Saved', 'This schedule appears to already be saved in DB. Save another copy?');
        if (!proceed) return;
    }

    const department = await promptDepartmentSelection(defaultDept);
    if (!department) {
        return;
    }

    selectedOpt.dataset.department = department;

    const normalizeAccuracy = (raw) => {
        const text = String(raw ?? '').trim();
        const match =
            text.match(/(\d{1,3}(?:\.\d+)?)\s*%+\s*accuracy/i) ||
            text.match(/accuracy[^0-9]{0,20}(\d{1,3}(?:\.\d+)?)/i) ||
            text.match(/(\d{1,3}(?:\.\d+)?)\s*%/) ||
            text.match(/^\s*(\d{1,3}(?:\.\d+)?)\s*$/);
        if (!match) return '0%';
        const n = Number.parseFloat(match[1]);
        if (!Number.isFinite(n) || n < 0 || n > 100) return '0%';
        return `${Number(n.toFixed(2)).toString()}%`;
    };

    const detectScheduleType = (rowData, optionType, filenameHint) => {
        const cleanType = String(optionType || '').trim().toLowerCase();
        if (cleanType === 'exam' || cleanType === 'class') {
            return cleanType;
        }

        const fileText = String(filenameHint || '').toLowerCase();
        if (fileText.startsWith('exam_') || fileText.includes('/exam_') || fileText.includes('exam_schedule')) {
            return 'exam';
        }

        if (Array.isArray(rowData) && rowData.length > 0) {
            const first = rowData[0];
            let headers = [];
            if (Array.isArray(first)) {
                headers = first.map(v => String(v || '').trim().toLowerCase());
            } else if (first && typeof first === 'object') {
                headers = Object.keys(first).map(v => String(v || '').trim().toLowerCase());
            }

            if (headers.some(h => h === 'invigilator' || h === 'invigilator name')) {
                return 'exam';
            }
        }

        return 'class';
    };

    try {
        const dlRes = await fetch(`api/download_b2_file.php?file=${encodeURIComponent(file)}`);
        const dlData = await dlRes.json();
        if (dlData.status !== 'success') {
            throw new Error(dlData.message || 'Failed to download schedule from B2');
        }

        const saveRes = await fetch('api/save_generated_schedule.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                schedule_name: scheduleName,
                semester: semester,
                department: department,
                schedule_type: detectScheduleType(dlData.data || [], selectedOpt?.dataset?.type || '', scheduleName || file),
                accuracy: normalizeAccuracy(selectedOpt?.dataset?.accuracy || '<?php echo $accuracy_display; ?>'),
                schedule_data: dlData.data || []
            })
        });
        const saveData = await saveRes.json();
        if (saveData.status !== 'success') {
            throw new Error(saveData.message || 'Failed to save schedule to DB');
        }

        selectedOpt.dataset.saved = '1';
        updateSaveRecommendation();
        await customAlert('Saved', 'Schedule saved to database successfully.', 'success');

        if (saveData.schedule_id) {
            window.location.href = `view_schedule.php?id=${encodeURIComponent(saveData.schedule_id)}`;
        }
    } catch (e) {
        await customAlert('Save Error', e.message || String(e), 'error');
    }
}

async function acceptAndLearnFromSavedSchedule() {
    const select = document.getElementById('b2ScheduleSelect');
    if (!select || !select.value) {
        await customAlert('Select File', 'Please choose a schedule first.', 'warning');
        return;
    }

    const selectedOpt = select.options[select.selectedIndex];
    const file = select.value;
    const scheduleName = selectedOpt?.dataset?.name || file.split('/').pop();
    const isSaved = selectedOpt?.dataset?.saved === '1';

    if (!isSaved) {
        const saveFirst = await customConfirm(
            'Save Required',
            'This schedule is not saved to DB yet. Save it first before sending learning feedback?'
        );
        if (!saveFirst) return;
        await saveSelectedB2ToDb();
        if (selectedOpt?.dataset?.saved !== '1') {
            return;
        }
    }

    // Use actual accuracy if available, otherwise fallback to basic quality estimate
    const renderedRows = <?php echo (int)$total_items; ?>;
    let accuracyText = '<?php echo $accuracy_display; ?>';
    let quality = 0.85; // Default
    
    if (accuracyText) {
        let num = parseFloat(accuracyText.replace('%', ''));
        if (!isNaN(num)) quality = num / 100;
    } else {
        quality = Math.max(0.5, Math.min(1.0, 0.75 + Math.min(renderedRows, 500) / 2000));
    }

    try {
        const res = await fetch('api/ai_feedback.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'accept_from_view',
                quality: quality,
                metadata: {
                    source: 'view_schedule',
                    schedule_file: file,
                    schedule_name: scheduleName,
                    schedule_saved_to_db: true,
                    total_rows_seen: renderedRows,
                    timestamp: new Date().toISOString()
                }
            })
        });

        const data = await res.json();
        if (data.status === 'success') {
            await customAlert('Learning Updated', 'Feedback sent successfully. AI learning data has been recorded.', 'success');
        } else {
            throw new Error(data.message || 'Could not record feedback');
        }
    } catch (e) {
        await customAlert('Feedback Error', e.message || String(e), 'error');
    }
}

async function saveVersion() {
    const name = await customPrompt("Save Version", "Enter a name for this version:");
    if (!name) return;

    const b2Select = document.getElementById('b2ScheduleSelect');
    const selectedFile = b2Select ? b2Select.value : '';
    
    const formData = new FormData();
    formData.append('action', 'save');
    formData.append('name', name);
    if (selectedFile) {
        formData.append('file_name', selectedFile);
        formData.append('description', 'Saved from Cloud: ' + selectedFile + ' at ' + new Date().toLocaleString());
    }
    
    try {
        const res = await fetch('api/schedule_versions.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.status === 'success') {
            await customAlert("Version Saved", data.message, "success");
            loadVersionList();
        } else {
            await customAlert("Save Error", data.message, "error");
        }
    } catch (e) { await customAlert("Error", "Check console for details.", "error"); }
}

async function loadVersion() {
    const id = document.getElementById('versionSelect').value;
    if (!id) return;
    
    const confirmed = await customConfirm("Load Version?", "This will overwrite the current schedule displayed on the website. Continue?");
    if (!confirmed) return;

    const formData = new FormData();
    formData.append('action', 'load');
    formData.append('id', id);
    
    try {
        const res = await fetch('api/schedule_versions.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.status === 'success') {
            await customAlert("Version Loaded", data.message, "success");
            location.reload();
        } else {
            await customAlert("Load Error", data.message, "error");
        }
    } catch (e) { await customAlert("Error", "Check console for details.", "error"); }
}

async function exportToPDF() {
    const headers = await customPDFPrompt("Export Timeline PDF");
    if (!headers) return;
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'api/export_pdf.php';
    // form.target = '_blank';
    
    const fields = {
        'file': '<?php echo $requested_file; ?>',
        'from_b2': '0',
        'h1': headers.h1,
        'h2': headers.h2,
        'h3': headers.h3,
        'h4': headers.h4
    };
    
    for (const [name, value] of Object.entries(fields)) {
        const inp = document.createElement('input');
        inp.type = 'hidden';
        inp.name = name;
        inp.value = value;
        form.appendChild(inp);
    }
    
    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);
}

async function exportB2ToPDF() {
    // Get selected B2 file
    const select = document.getElementById('b2ScheduleSelect');
    const selectedFile = select?.value;
    
    if (!selectedFile) {
        await customAlert("No File Selected", "Please select a schedule file to export", "warning");
        return;
    }
    
    const headers = await customPDFPrompt("Export Schedule to PDF");
    if (!headers) return;
    
    try {
        // Show loading modal
        showLoadingModal("Exporting PDF", "Converting CSV to PDF...");
        
        const response = await fetch('api/csv_to_pdf_b2.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                csv_file: selectedFile,
                from_b2: true,
                return_download: true,
                h1: headers.h1,
                h2: headers.h2,
                h3: headers.h3,
                h4: headers.h4
            })
        });
        
        console.log('Response status:', response.status);
        console.log('Response headers:', response.headers.get('Content-Type'));
        
        if (response.ok) {
            const contentType = response.headers.get('Content-Type');
            
            // Check if we received a PDF
            if (contentType && contentType.includes('application/pdf')) {
                // Download PDF
                const blob = await response.blob();
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                // Extract filename from path and add .pdf extension
                const filename = selectedFile.split('/').pop().replace('.csv', '.pdf');
                a.download = filename;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                window.URL.revokeObjectURL(url);
                
                hideGlobalModal();
                await customAlert("Success", "PDF exported and downloaded successfully!", "success");
            } else {
                // Received JSON instead of PDF
                const data = await response.json();
                console.error('Unexpected JSON response:', data);
                hideGlobalModal();
                await customAlert("Unexpected Response", "Server returned JSON instead of PDF: " + (data.message || "Unknown"), "warning");
            }
        } else {
            const error = await response.text();
            console.error('Export failed:', error);
            hideGlobalModal();
            
            try {
                const jsonError = JSON.parse(error);
                await customAlert("Export Failed", jsonError.message || "Unknown error", "error");
            } catch (e) {
                await customAlert("Export Failed", "Server error: " + error.substring(0, 200), "error");
            }
        }
    } catch (e) {
        console.error('Exception:', e);
        hideGlobalModal();
        await customAlert("Error", "PDF export failed: " + e.message, "error");
    }
}

function showLoadingModal(title, message) {
    hideGlobalModal();

    const container = document.getElementById('customModalContainer') || document.body;
    const overlay = document.createElement('div');
    overlay.className = 'custom-modal-overlay';
    overlay.id = 'exportLoadingOverlay';
    overlay.innerHTML = `
        <div class="custom-modal glass-panel" style="max-width: 420px; width: 90%; text-align: center;">
            <div class="custom-modal-header">
                <h3 style="margin: 0; display: flex; align-items: center; justify-content: center; gap: 12px;">
                    <i class="fa-solid fa-spinner fa-spin" style="color: var(--primary-color);"></i>
                    <span>${escapeHtml(title)}</span>
                </h3>
            </div>
            <div class="custom-modal-body">
                <p style="margin: 0; line-height: 1.6;">${message.includes('<') ? message : escapeHtml(message)}</p>
            </div>
        </div>
    `;
    container.appendChild(overlay);
}

function hideGlobalModal() {
    const overlay = document.getElementById('exportLoadingOverlay');
    if (!overlay) {
        return;
    }

    overlay.style.opacity = '0';
    const modal = overlay.querySelector('.custom-modal');
    if (modal) {
        modal.style.transform = 'scale(0.95)';
    }
    setTimeout(() => overlay.remove(), 200);
}

function promptDepartmentSelection(defaultDept) {
    return new Promise((resolve) => {
        const norm = String(defaultDept || 'General').toLowerCase();
        let defaultValue = 'General';
        if (norm.includes('computer') || norm.includes('cs') || norm.includes('it') || norm.includes('bis') || norm.includes('bbis')) {
            defaultValue = 'Computer Science';
        } else if (norm.includes('nursing')) {
            defaultValue = 'Nursing';
        } else if (norm.includes('theology')) {
            defaultValue = 'Theology';
        } else if (norm.includes('business')) {
            defaultValue = 'Business';
        } else if (norm.includes('education')) {
            defaultValue = 'Education';
        } else if (norm.includes('biomedical')) {
            defaultValue = 'Biomedical Engineering';
        } else if (norm.includes('development')) {
            defaultValue = 'Development Studies';
        }

        const options = [
            { value: 'General', label: 'General (All Courses)' },
            { value: 'Computer Science', label: 'CS/IT/BIS (Computer Science)' },
            { value: 'Nursing', label: 'Nursing & Midwifery' },
            { value: 'Theology', label: 'Theology' },
            { value: 'Business', label: 'Business' },
            { value: 'Education', label: 'Education' },
            { value: 'Biomedical Engineering', label: 'Biomedical Engineering' },
            { value: 'Development Studies', label: 'Development Studies' }
        ];

        const optionsHtml = options.map(opt => {
            const selected = opt.value === defaultValue ? 'selected' : '';
            return `<option value="${opt.value}" ${selected}>${opt.label}</option>`;
        }).join('');

        const content = `
            <div style="text-align: left; margin-top: 0.75rem;">
                <label class="stat-label">Department (Required)</label>
                <select id="departmentSelect" class="glass-input" style="margin-top: 0.4rem; width: 100%;">
                    ${optionsHtml}
                </select>
                <div style="margin-top: 0.6rem; font-size: 0.78rem; color: var(--text-muted);">
                    This value is saved with the schedule to help students see the correct courses.
                </div>
            </div>
        `;

        showGlobalModal('Select Department', content, 'prompt', [
            { text: 'Cancel', class: 'glass-btn secondary', click: () => resolve(null) },
            { text: 'Save Schedule', class: 'glass-btn primary', click: () => {
                const value = document.getElementById('departmentSelect')?.value || 'General';
                resolve(value);
            }}
        ]);
    });
}

function customPDFPrompt(title) {
    return new Promise((resolve) => {
        const h1 = "VALLEY VIEW UNIVERSITY";
        const h2 = "COMPUTER SCIENCE, INFORMATION TECHNOLOGY, BUSINESS INFORMATION SYSTEMS AND MATHEMATICAL SCIENCES";
        const h3 = "SECOND SEMESTER - 2025 / 2026 ACADEMIC YEAR";
        const h4 = "TEACHING TIMETABLE";
        
        const inputs = `
            <div style="text-align: left; margin-top: 1rem;">
                <label class="stat-label">Header Line 1</label>
                <input type="text" id="pdfH1" class="glass-input" style="margin-bottom: 0.5rem; width: 100%; font-size: 0.8rem;" value="${h1}">
                <label class="stat-label">Header Line 2</label>
                <input type="text" id="pdfH2" class="glass-input" style="margin-bottom: 0.5rem; width: 100%; font-size: 0.8rem;" value="${h2}">
                <label class="stat-label">Header Line 3</label>
                <input type="text" id="pdfH3" class="glass-input" style="margin-bottom: 0.5rem; width: 100%; font-size: 0.8rem;" value="${h3}">
                <label class="stat-label">Header Line 4</label>
                <input type="text" id="pdfH4" class="glass-input" style="margin-bottom: 1rem; width: 100%; font-size: 0.8rem;" value="${h4}">
            </div>
        `;
        showGlobalModal(title, inputs, 'prompt', [
            {text: 'Cancel', class: 'glass-btn secondary', click: () => resolve(null)},
            {text: 'Export PDF', class: 'glass-btn primary', click: () => {
                resolve({
                    h1: document.getElementById('pdfH1').value,
                    h2: document.getElementById('pdfH2').value,
                    h3: document.getElementById('pdfH3').value,
                    h4: document.getElementById('pdfH4').value
                });
            }}
        ]);
    });
}

loadVersionList();
loadDbSchedules();
document.getElementById('b2ScheduleSelect')?.addEventListener('change', updateSaveRecommendation);

const isExamSchedule = <?php echo $is_exam_schedule ? 'true' : 'false'; ?>;
const fullScheduleRows = <?php echo json_encode(array_values($data), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const scheduleTableBody = document.getElementById('scheduleTableBody');
const schedulePagination = document.getElementById('schedulePagination');
const originalTableBodyHtml = scheduleTableBody ? scheduleTableBody.innerHTML : '';

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function renderSearchRows(rows) {
    if (!scheduleTableBody) {
        return;
    }

    if (!rows.length) {
        scheduleTableBody.innerHTML = `<tr><td colspan="5" style="padding: 1.25rem; text-align: center; color: var(--text-muted);">No matching rows found.</td></tr>`;
        return;
    }

    if (isExamSchedule) {
        scheduleTableBody.innerHTML = rows.map((row, idx) => {
            const bg = idx % 2 === 0 ? 'rgba(239, 68, 68, 0.05)' : 'rgba(251, 146, 60, 0.03)';
            const lecturer = escapeHtml(row.lecturer || '');
            const initial = lecturer ? lecturer.charAt(0).toUpperCase() : '-';

            return `
                <tr style="background: ${bg}; border-bottom: 1px solid rgba(255,255,255,0.02); transition: all 0.3s; border-left: 3px solid transparent;" 
                    onmouseover="this.style.background='rgba(251, 146, 60, 0.12)'; this.style.borderLeftColor='#fb923c';" 
                    onmouseout="this.style.background='${bg}'; this.style.borderLeftColor='transparent';">
                    <td data-label="Day" style="padding: 1.2rem 1rem;">
                        <span style="font-weight: 700; color: #fbbf24; font-size: 1rem; display: inline-flex; align-items: center; gap: 6px;">
                            <i class="fa-solid fa-calendar" style="font-size: 0.85rem; opacity: 0.7;"></i>
                            ${escapeHtml(row.day)}
                        </span>
                    </td>
                    <td data-label="Time" style="padding: 1.2rem 1rem;">
                        <div style="display: inline-flex; align-items: center; gap: 8px; background: linear-gradient(135deg, rgba(239, 68, 68, 0.2), rgba(251, 146, 60, 0.2)); padding: 8px 16px; border-radius: 20px; font-family: 'Courier New', monospace; font-size: 0.9rem; color: white; font-weight: 600; border: 1px solid rgba(251, 146, 60, 0.4);">
                            <i class="fa-regular fa-clock" style="font-size: 1rem;"></i>
                            ${escapeHtml(row.time)}
                        </div>
                    </td>
                    <td data-label="Course" style="padding: 1.2rem 1rem;">
                        <div style="font-weight: 700; color: #60a5fa; margin-bottom: 5px; font-size: 1.05rem;">${escapeHtml(row.code)}</div>
                        <div style="font-size: 0.88rem; color: var(--text-muted); line-height: 1.4;">${escapeHtml(row.title)}</div>
                    </td>
                    <td data-label="Hall" style="padding: 1.2rem 1rem;">
                        <div style="display: inline-flex; align-items: center; gap: 8px; background: rgba(16, 185, 129, 0.15); padding: 8px 14px; border-radius: 8px; border: 1px solid rgba(16, 185, 129, 0.3);">
                            <i class="fa-solid fa-door-open" style="font-size: 1rem; color: #10b981;"></i>
                            <span style="color: #34d399; font-weight: 600;">${escapeHtml(row.room)}</span>
                        </div>
                    </td>
                    <td data-label="Invigilator" style="padding: 1.2rem 1rem;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <div style="width: 32px; height: 32px; background: linear-gradient(135deg, #f59e0b, #ef4444); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.85rem; color: white; font-weight: 700; box-shadow: 0 2px 8px rgba(245, 158, 11, 0.4);">
                                ${escapeHtml(initial)}
                            </div>
                            <span style="color: var(--text-main); font-weight: 500;">${lecturer}</span>
                        </div>
                    </td>
                </tr>`;
        }).join('');
        return;
    }

    scheduleTableBody.innerHTML = rows.map((row, idx) => {
        const bg = idx % 2 === 0 ? 'rgba(255,255,255,0.01)' : 'transparent';
        const lecturer = escapeHtml(row.lecturer || '');
        const initial = lecturer ? lecturer.charAt(0) : '-';

        return `
            <tr style="background: ${bg}; border-bottom: 1px solid rgba(255,255,255,0.02); transition: background 0.2s;">
                <td data-label="Day" style="padding: 1rem;">
                    <span style="font-weight: 600; color: var(--text-main);">${escapeHtml(row.day)}</span>
                </td>
                <td data-label="Time" style="padding: 1rem;">
                    <div style="display: inline-flex; align-items: center; gap: 5px; background: rgba(255,255,255,0.05); padding: 4px 10px; border-radius: 20px; font-family: monospace; font-size: 0.85rem; color: var(--warning);">
                        <i class="fa-regular fa-clock"></i> ${escapeHtml(row.time)}
                    </div>
                </td>
                <td data-label="Course" style="padding: 1rem;">
                    <div style="font-weight: 700; color: var(--primary-color); margin-bottom: 3px;">${escapeHtml(row.code)}</div>
                    <div style="font-size: 0.85rem; color: var(--text-muted);">${escapeHtml(row.title)}</div>
                </td>
                <td data-label="Room" style="padding: 1rem;">
                    <div style="color: #4ade80; display: flex; align-items: center; gap: 5px;">
                        <i class="fa-solid fa-door-open" style="font-size: 0.8rem;"></i> ${escapeHtml(row.room)}
                    </div>
                </td>
                <td data-label="Lecturer" style="padding: 1rem;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <div style="width: 25px; height: 25px; background: linear-gradient(45deg, #4f46e5, #ec4899); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.7rem; color: white;">
                            ${escapeHtml(initial)}
                        </div>
                        <span>${lecturer}</span>
                    </div>
                </td>
            </tr>`;
    }).join('');
}

function applyLiveSearch(term) {
    if (!scheduleTableBody) {
        return;
    }

    const normalizedTerm = String(term || '').toLowerCase().trim();
    const countEl = document.getElementById('searchCount');

    if (normalizedTerm === '') {
        scheduleTableBody.innerHTML = originalTableBodyHtml;
        if (schedulePagination) {
            schedulePagination.style.display = '';
        }
        if (countEl) {
            countEl.innerText = fullScheduleRows.length;
        }
        return;
    }

    const matches = fullScheduleRows.filter((row) => {
        const searchable = `${row.code || ''} ${row.title || ''} ${row.lecturer || ''} ${row.room || ''} ${row.day || ''} ${row.time || ''}`.toLowerCase();
        return searchable.includes(normalizedTerm);
    });

    renderSearchRows(matches);

    if (schedulePagination) {
        schedulePagination.style.display = 'none';
    }
    if (countEl) {
        countEl.innerText = matches.length;
    }
}

// Live Search for Schedule Table
document.querySelectorAll('.live-search-input').forEach((input) => {
    input.addEventListener('keyup', function() {
        const term = this.value || '';
        document.querySelectorAll('.live-search-input').forEach((other) => {
            if (other !== this && other.value !== term) {
                other.value = term;
            }
        });
        applyLiveSearch(term);
    });
});

let editMode = false;
let draggedRow = null;

async function toggleEditMode() {
    editMode = !editMode;
    const btn = document.getElementById('editModeBtn');
    const rows = document.querySelectorAll('.schedule-table tbody tr');
    
    if (editMode) {
        btn.innerHTML = '<i class="fa-solid fa-check"></i> Exit Edit Mode';
        btn.classList.add('active');
        btn.style.background = 'rgba(16, 185, 129, 0.2)';
        btn.style.borderColor = 'rgba(16, 185, 129, 0.6)';
        
        rows.forEach(row => {
            row.setAttribute('draggable', true);
            row.style.cursor = 'move';
            row.classList.add('draggable-row');
            
            // Add listeners
            row.addEventListener('dragstart', handleDragStart);
            row.addEventListener('dragover', handleDragOver);
            row.addEventListener('drop', handleDrop);
            row.addEventListener('dragend', handleDragEnd);
        });
        
        await customAlert('Edit Mode Enabled', 'You can now drag rows to reorder them.', 'info');
    } else {
        // ... (rest of function unchanged)
        btn.innerHTML = '<i class="fa-solid fa-arrows-alt"></i> Enable Drag-Drop';
        btn.classList.remove('active');
        btn.style.background = '';
        btn.style.borderColor = '';
        
        rows.forEach(row => {
            row.removeAttribute('draggable');
            row.style.cursor = '';
            row.classList.remove('draggable-row');
            
            // Remove listeners
            row.removeEventListener('dragstart', handleDragStart);
            row.removeEventListener('dragover', handleDragOver);
            row.removeEventListener('drop', handleDrop);
            row.removeEventListener('dragend', handleDragEnd);
        });
    }
}

function handleDragStart(e) {
    draggedRow = this;
    this.style.opacity = '0.4';
    this.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
}

function handleDragOver(e) {
    if (e.preventDefault) {
        e.preventDefault(); 
    }
    e.dataTransfer.dropEffect = 'move';
    return false;
}

function handleDrop(e) {
    if (e.stopPropagation) {
        e.stopPropagation(); 
    }
    
    if (draggedRow !== this) {
        const tbody = this.parentNode;
        const allRows = Array.from(tbody.querySelectorAll('tr'));
        const draggedIdx = allRows.indexOf(draggedRow);
        const targetIdx = allRows.indexOf(this);
        
        if (draggedIdx < targetIdx) {
            tbody.insertBefore(draggedRow, this.nextSibling);
        } else {
            tbody.insertBefore(draggedRow, this);
        }
    }
    
    return false;
}

function handleDragEnd(e) {
    this.style.opacity = '1';
    const rows = document.querySelectorAll('.schedule-table tbody tr');
    rows.forEach(row => {
        row.classList.remove('dragging');
        row.classList.remove('drag-over');
    });
}


</script>

<?php include 'includes/footer.php'; ?>
