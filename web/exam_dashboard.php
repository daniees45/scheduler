<?php
$page_title = 'Final Exam Schedule';
include 'includes/header.php';
require_once 'api/db.php';

// Accessible to all logged-in users
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_name = $_SESSION['user_name'] ?? 'User';
$role = $_SESSION['role'] ?? 'student';
$user_id = (int)($_SESSION['user_id'] ?? 0);

function exam_normalize_code_token(string $value): string {
    $value = strtoupper(trim($value));
    $value = preg_replace('/\s+/', ' ', $value);
    return $value;
}

function exam_extract_code_tokens(string $courseCodeField): array {
    $tokens = preg_split('/\s*\/\s*/', (string)$courseCodeField);
    $out = [];
    foreach ($tokens as $token) {
        $normalized = exam_normalize_code_token($token);
        if ($normalized !== '') {
            $out[$normalized] = true;
        }
    }
    return array_keys($out);
}

function exam_find_header_index(array $headerMap, array $candidates): int {
    foreach ($candidates as $candidate) {
        $k = strtolower(trim($candidate));
        if (isset($headerMap[$k])) {
            return (int)$headerMap[$k];
        }
    }
    return -1;
}

function exam_generated_schedules_has_type(mysqli $conn): bool {
    $res = $conn->query("SHOW COLUMNS FROM generated_schedules LIKE 'schedule_type'");
    return $res && $res->num_rows > 0;
}

function exam_load_latest_from_generated_schedules(mysqli $conn): array {
    $queries = [];
    if (exam_generated_schedules_has_type($conn)) {
        $queries[] = "SELECT id, schedule_data
                     FROM generated_schedules
                     WHERE schedule_type = 'exam'
                       AND schedule_data IS NOT NULL AND schedule_data <> ''
                     ORDER BY created_at DESC, id DESC
                     LIMIT 50";
    } else {
        $queries[] = "SELECT id, schedule_data
                     FROM generated_schedules
                     WHERE schedule_data IS NOT NULL AND schedule_data <> ''
                     ORDER BY created_at DESC, id DESC
                     LIMIT 50";
    }

        $queries[] = "SELECT id, schedule_data
                                 FROM generated_schedules
                                 WHERE schedule_data IS NOT NULL AND schedule_data <> ''
                                 ORDER BY created_at DESC, id DESC
                                 LIMIT 50";

    foreach ($queries as $query) {
        $res = $conn->query($query);
        if (!$res) {
            continue;
        }

        while ($row = $res->fetch_assoc()) {
        $decoded = json_decode((string)$row['schedule_data'], true);
        if (!is_array($decoded) || count($decoded) < 2 || !is_array($decoded[0])) {
            continue;
        }

        $header = $decoded[0];
        $headerMap = [];
        foreach ($header as $idx => $name) {
            $headerMap[strtolower(trim((string)$name))] = (int)$idx;
        }

        $idxCode = exam_find_header_index($headerMap, ['course code', 'course_code']);
        $idxTitle = exam_find_header_index($headerMap, ['course title', 'course_title']);
        $idxInvigilator = exam_find_header_index($headerMap, ['invigilator', 'invigilator name']);
        $idxRoom = exam_find_header_index($headerMap, ['room', 'room name', 'room_name']);
        $idxDay = exam_find_header_index($headerMap, ['day']);
        $idxTime = exam_find_header_index($headerMap, ['time', 'time_slot', 'time slot']);

        // Saved exam schedules must include explicit invigilator/day/time columns.
        if ($idxCode < 0 || $idxInvigilator < 0 || $idxDay < 0 || $idxTime < 0) {
            continue;
        }

        $items = [];
        for ($i = 1; $i < count($decoded); $i++) {
            $r = $decoded[$i];
            if (!is_array($r)) continue;

            $code = trim((string)($r[$idxCode] ?? ''));
            if ($code === '') continue;

            // Section formatting
            $raw_title = trim((string)($r[$idxTitle] ?? ''));
            $section = '';
            if (isset($r['section'])) {
                $section = strtoupper(trim((string)$r['section']));
            } elseif (preg_match('/\[\s*sec(?:tion)?\s*([a-z0-9]+)\s*\]/i', $raw_title, $m)) {
                $section = strtoupper(trim((string)$m[1]));
            }
            $base_title = trim(preg_replace('/\[\s*sec(?:tion)?\s*[a-z0-9]+\s*\]/i', '', $raw_title));
            $formatted_title = $base_title;
            if ($section !== '') {
                $formatted_title .= ' [Sec ' . $section . ']';
            }
            $items[] = [
                'code' => $code,
                'title' => $formatted_title,
                'lecturer' => trim((string)($r[$idxInvigilator] ?? 'TBA')),
                'room' => trim((string)($r[$idxRoom] ?? 'TBA')),
                'day' => trim((string)($r[$idxDay] ?? 'TBA')),
                'time' => trim((string)($r[$idxTime] ?? 'TBA'))
            ];
        }

        if (!empty($items)) {
            return $items;
        }
    }
    }

    return [];
}

function exam_load_enrolled_codes(mysqli $conn, int $userId): array {
    if ($userId <= 0) {
        return [];
    }

    $semester = (string)($_SESSION['semester'] ?? '1');
    $settingsRes = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'current_semester' LIMIT 1");
    if ($settingsRes && ($s = $settingsRes->fetch_assoc())) {
        $candidate = trim((string)($s['setting_value'] ?? ''));
        if ($candidate !== '') {
            $semester = $candidate;
        }
    }

    $codes = [];
    $stmt = $conn->prepare("SELECT c.course_code
                           FROM student_enrollments se
                           JOIN courses c ON se.course_id = c.id
                           WHERE se.user_id = ?
                             AND (se.semester = ? OR se.semester IS NULL OR TRIM(se.semester) = '')");
    if ($stmt) {
        $stmt->bind_param('is', $userId, $semester);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $token = exam_normalize_code_token((string)($row['course_code'] ?? ''));
            if ($token !== '') {
                $codes[$token] = true;
            }
        }
    }
    return $codes;
}

// Load latest exam schedule from DB snapshots (generated_schedules)
$exams = exam_load_latest_from_generated_schedules($conn);

// Fallback to CSV if no DB snapshot is available
if (empty($exams)) {
    $exam_file = '../csv/final/exam_schedule.csv';
    if (file_exists($exam_file) && ($handle = fopen($exam_file, 'r')) !== FALSE) {
        $headers = fgetcsv($handle);
        $headerMap = [];
        if (is_array($headers)) {
            foreach ($headers as $idx => $name) {
                $headerMap[strtolower(trim((string)$name))] = (int)$idx;
            }
        }
        $idxCode = exam_find_header_index($headerMap, ['course code', 'course_code']);
        $idxTitle = exam_find_header_index($headerMap, ['course title', 'course_title']);
        $idxInvigilator = exam_find_header_index($headerMap, ['invigilator', 'lecturer']);
        $idxRoom = exam_find_header_index($headerMap, ['room', 'room_name']);
        $idxDay = exam_find_header_index($headerMap, ['day']);
        $idxTime = exam_find_header_index($headerMap, ['time']);

        while (($row = fgetcsv($handle, 1000, ',')) !== FALSE) {
            $code = trim((string)($row[$idxCode >= 0 ? $idxCode : 0] ?? ''));
            if ($code === '') continue;
            // Section formatting for CSV fallback
            $raw_title = trim((string)($row[$idxTitle >= 0 ? $idxTitle : 1] ?? ''));
            $section = '';
            if (isset($row['section'])) {
                $section = strtoupper(trim((string)$row['section']));
            } elseif (preg_match('/\[\s*sec(?:tion)?\s*([a-z0-9]+)\s*\]/i', $raw_title, $m)) {
                $section = strtoupper(trim((string)$m[1]));
            }
            $base_title = trim(preg_replace('/\[\s*sec(?:tion)?\s*[a-z0-9]+\s*\]/i', '', $raw_title));
            $formatted_title = $base_title;
            if ($section !== '') {
                $formatted_title .= ' [Sec ' . $section . ']';
            }
            $exams[] = [
                'code' => $code,
                'title' => $formatted_title,
                'lecturer' => trim((string)($row[$idxInvigilator >= 0 ? $idxInvigilator : 2] ?? 'TBA')),
                'room' => trim((string)($row[$idxRoom >= 0 ? $idxRoom : 6] ?? 'TBA')),
                'day' => trim((string)($row[$idxDay >= 0 ? $idxDay : 7] ?? 'TBA')),
                'time' => trim((string)($row[$idxTime >= 0 ? $idxTime : 8] ?? 'TBA'))
            ];
        }
        fclose($handle);
    }
}

// Role filtering: students see enrolled courses only, lecturers see assigned invigilation only.
if ($role === 'student') {
    $enrolledCodeMap = exam_load_enrolled_codes($conn, $user_id);
    $exams = array_values(array_filter($exams, static function ($exam) use ($enrolledCodeMap) {
        $tokens = exam_extract_code_tokens((string)($exam['code'] ?? ''));
        foreach ($tokens as $token) {
            if (isset($enrolledCodeMap[$token])) {
                return true;
            }
        }
        return false;
    }));
} elseif ($role === 'lecturer') {
    $lecturerName = trim((string)($_SESSION['full_name'] ?? $_SESSION['user_name'] ?? ''));

    if (!empty($_SESSION['lecturer_id'])) {
        $stmt = $conn->prepare('SELECT name FROM lecturers WHERE id = ? LIMIT 1');
        if ($stmt) {
            $lecturerId = (int)$_SESSION['lecturer_id'];
            $stmt->bind_param('i', $lecturerId);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && ($r = $res->fetch_assoc())) {
                $lecturerName = trim((string)($r['name'] ?? $lecturerName));
            }
        }
    }

    $lecturerNameLc = strtolower($lecturerName);
    $exams = array_values(array_filter($exams, static function ($exam) use ($lecturerNameLc) {
        $invigilator = strtolower(trim((string)($exam['lecturer'] ?? '')));
        return $lecturerNameLc !== '' && $invigilator !== '' && strpos($invigilator, $lecturerNameLc) !== false;
    }));
}

// Sort exams by day/time
$days_map = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
$day_order = array_flip($days_map);
usort($exams, function($a, $b) use ($day_order) {
    $da = $day_order[$a['day']] ?? 99;
    $db = $day_order[$b['day']] ?? 99;
    if ($da !== $db) return $da <=> $db;
    return strcmp($a['time'], $b['time']);
});
?>

<link rel="stylesheet" href="assets/sketch.css">

<div class="sketch-container">
    <div class="sketch-panel">
        <h1 class="sketch-title">Final Exam Schedule</h1>
        <p style="font-size: 1.2rem; margin-top: -10px;">
            <?php if ($role === 'student'): ?>
                Your enrolled exam courses.
            <?php elseif ($role === 'lecturer'): ?>
                Your assigned invigilation schedule.
            <?php else: ?>
                Official examination timetable for all departments.
            <?php endif; ?>
        </p>
    </div>

    <div class="sketch-panel">
        <div class="table-container">
            <table class="sketch-list" style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="border-bottom: 3px solid var(--sketch-border);">
                        <th style="padding: 10px; text-align: left;">Day / Time</th>
                        <th style="padding: 10px; text-align: left;">Course</th>
                        <th style="padding: 10px; text-align: left;">Venue</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($exams)): ?>
                        <tr>
                            <td colspan="3" style="text-align: center; padding: 2rem; color: var(--sketch-marker);">
                                No exams scheduled yet.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($exams as $exam): ?>
                            <tr style="border-bottom: 1px dashed var(--sketch-border);">
                                <td style="padding: 15px;">
                                    <div style="font-weight: bold; color: var(--sketch-marker);"><?php echo htmlspecialchars($exam['day']); ?></div>
                                    <div style="font-size: 0.85rem;"><?php echo htmlspecialchars($exam['time']); ?></div>
                                </td>
                                <td style="padding: 15px;">
                                    <div style="font-weight: bold;"><?php echo htmlspecialchars($exam['code']); ?></div>
                                    <div style="font-size: 0.8rem;"><?php echo htmlspecialchars($exam['title']); ?></div>
                                    <div style="font-size: 0.75rem; color: var(--text-muted);">Invigilator: <?php echo htmlspecialchars($exam['lecturer']); ?></div>
                                </td>
                                <td style="padding: 15px;">
                                    <div class="sketch-badge" style="display: inline-block;"><?php echo htmlspecialchars($exam['room']); ?></div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="sketch-panel" style="background: rgba(var(--primary-rgb), 0.05);">
        <h3>Important Exam Notices</h3>
        <ul class="sketch-list">
            <li class="sketch-list-item">Arrive at the venue at least 30 minutes before the start time.</li>
            <li class="sketch-list-item">Mobile phones and smartwatches are strictly prohibited in the exam hall.</li>
            <li class="sketch-list-item">Ensure you have your valid Student ID card.</li>
        </ul>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
