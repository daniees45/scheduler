<?php
$page_title = 'My Exam & Prep Dashboard';
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

// Fetch enrolled courses and schedule rows
$unified_payload = unified_schedule_fetch($conn, $_SESSION, [
    'semester' => $semester,
]);
$enrolled_courses = $unified_payload['enrolled_courses'] ?? [];

// Build enrolled code map for exam matching
$enrolled_code_map = [];
foreach ($enrolled_courses as $ec) {
    $ec_code = strtoupper(trim((string)($ec['course_code'] ?? '')));
    if ($ec_code !== '') {
        $enrolled_code_map[$ec_code] = true;
    }
}

// Free-day tips are based on enrolled-course exam days
$all_weekdays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
$free_days = $all_weekdays;

// Study tips keyed to each free day
$free_day_tips = [
    'Monday'    => ['icon' => 'fa-book-open',       'tip' => 'Great day for a deep-dive content review — go through lecture notes and textbook chapters for your most challenging courses.'],
    'Tuesday'   => ['icon' => 'fa-users',            'tip' => 'Use this free Tuesday for group study — compare notes with classmates and tackle difficult topics together.'],
    'Wednesday' => ['icon' => 'fa-pen-to-square',   'tip' => 'Mid-week free slot — perfect for practice questions and summarising key formulas or definitions.'],
    'Thursday'  => ['icon' => 'fa-layer-group',     'tip' => 'Free Thursday gives you time for past exam papers and flashcard drills — identify patterns in past questions.'],
    'Friday'    => ['icon' => 'fa-clock-rotate-left','tip' => 'End the academic week with a weekly recap — review what you covered Mon–Thu and update your revision notes.'],
    'Saturday'  => ['icon' => 'fa-brain',            'tip' => 'Use Saturday for extended revision blocks and a timed mock test to build exam stamina.'],
    'Sunday'    => ['icon' => 'fa-bed',              'tip' => 'Sunday is ideal for light recap and rest so you begin the week mentally fresh.'],
];

function prepGeneratedSchedulesHasColumn(mysqli $conn, string $column): bool {
    $column = trim((string)$column);
    if ($column === '') {
        return false;
    }

    $escaped = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM generated_schedules LIKE '" . $escaped . "'");
    return $res && $res->num_rows > 0;
}

function prepFindHeaderIndex(array $headerMap, array $candidates): int {
    foreach ($candidates as $candidate) {
        $needle = strtolower(trim((string)$candidate));
        if ($needle !== '' && isset($headerMap[$needle])) {
            return (int)$headerMap[$needle];
        }
    }
    return -1;
}

function prepExtractCodeTokens(string $courseCodeField): array {
    $tokens = [];
    foreach (preg_split('/\s*\/\s*/', strtoupper(trim($courseCodeField))) as $token) {
        $token = strtoupper(trim((string)$token));
        if ($token !== '') {
            $tokens[$token] = true;
        }
    }
    return array_keys($tokens);
}

function prepLoadLatestSavedExamRows(mysqli $conn): array {
    $hasType = prepGeneratedSchedulesHasColumn($conn, 'schedule_type');
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

function prepNormalizeExamRows(array $rawRows): array {
    if (empty($rawRows)) {
        return [];
    }

    $first = $rawRows[0] ?? null;
    $normalized = [];

    if (is_array($first) && array_keys($first) !== range(0, count($first) - 1)) {
        foreach ($rawRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $normalized[] = [
                'course_code' => trim((string)($row['course_code'] ?? $row['course code'] ?? '')),
                'title' => trim((string)($row['course_title'] ?? $row['course title'] ?? '')),
                'day' => trim((string)($row['day'] ?? '')),
                'time' => trim((string)($row['time'] ?? $row['time_slot'] ?? $row['time slot'] ?? '')),
                'room' => trim((string)($row['room'] ?? $row['room_name'] ?? $row['room name'] ?? '')),
            ];
        }
        return $normalized;
    }

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

    $idxCode = prepFindHeaderIndex($headerMap, ['course code', 'course_code']);
    $idxTitle = prepFindHeaderIndex($headerMap, ['course title', 'course_title']);
    $idxRoom = prepFindHeaderIndex($headerMap, ['room', 'room name', 'room_name', 'exam hall', 'hall']);
    $idxDay = prepFindHeaderIndex($headerMap, ['day']);
    $idxTime = prepFindHeaderIndex($headerMap, ['time', 'time slot', 'time_slot']);

    $hasHeader = ($idxCode >= 0 || $idxDay >= 0 || $idxTime >= 0 || $idxRoom >= 0);
    $startRow = $hasHeader ? 1 : 0;
    if ($idxCode < 0) $idxCode = 0;
    if ($idxTitle < 0) $idxTitle = 1;
    if ($idxRoom < 0) $idxRoom = 6;
    if ($idxDay < 0) $idxDay = 7;
    if ($idxTime < 0) $idxTime = 8;

    for ($i = $startRow; $i < count($rawRows); $i++) {
        $row = $rawRows[$i];
        if (!is_array($row)) {
            continue;
        }

        $maxNeed = max($idxCode, $idxTitle, $idxRoom, $idxDay, $idxTime);
        if (count($row) <= $maxNeed) {
            continue;
        }

        $normalized[] = [
            'course_code' => trim((string)($row[$idxCode] ?? '')),
            'title' => trim((string)($row[$idxTitle] ?? '')),
            'day' => trim((string)($row[$idxDay] ?? '')),
            'time' => trim((string)($row[$idxTime] ?? '')),
            'room' => trim((string)($row[$idxRoom] ?? '')),
        ];
    }

    return $normalized;
}

// Build exam list from latest saved DB exam schedule (fallback to CSV)
$my_exams = [];
$seen_exam_keys = [];
if (!empty($enrolled_code_map)) {
    $exam_rows_raw = prepLoadLatestSavedExamRows($conn);
    $exam_rows = prepNormalizeExamRows($exam_rows_raw);

    if (empty($exam_rows)) {
        $exam_file = '../csv/final/exam_schedule.csv';
        if (file_exists($exam_file) && ($handle = fopen($exam_file, 'r')) !== false) {
            $csv_rows = [];
            while (($row = fgetcsv($handle, 1000, ',')) !== false) {
                $csv_rows[] = $row;
            }
            fclose($handle);
            $exam_rows = prepNormalizeExamRows($csv_rows);
        }
    }

    foreach ($exam_rows as $row) {
        $course_code_raw = strtoupper(trim((string)($row['course_code'] ?? '')));
        if ($course_code_raw === '') {
            continue;
        }

        $matched_code = '';
        foreach (prepExtractCodeTokens($course_code_raw) as $code_part) {
            if (isset($enrolled_code_map[$code_part])) {
                $matched_code = $code_part;
                break;
            }
        }

        if ($matched_code === '') {
            continue;
        }

        $exam_day = trim((string)($row['day'] ?? ''));
        $exam_time = trim((string)($row['time'] ?? ''));
        $exam_room = trim((string)($row['room'] ?? ''));
        $exam_title = trim((string)($row['title'] ?? ''));

        $exam_key = strtolower($matched_code . '|' . $exam_day . '|' . $exam_time);
        if (isset($seen_exam_keys[$exam_key])) {
            continue;
        }
        $seen_exam_keys[$exam_key] = true;

        $my_exams[] = [
            'code'  => $matched_code,
            'title' => $exam_title,
            'day'   => $exam_day !== '' ? $exam_day : 'TBA',
            'time'  => $exam_time !== '' ? $exam_time : 'TBA',
            'room'  => $exam_room !== '' ? $exam_room : 'TBA',
        ];
    }
}

$exam_days = [];
foreach ($my_exams as $exam_item) {
    $exam_day_raw = trim((string)($exam_item['day'] ?? ''));
    if ($exam_day_raw === '') {
        continue;
    }
    foreach ($all_weekdays as $weekday) {
        if (strcasecmp($exam_day_raw, $weekday) === 0) {
            $exam_days[$weekday] = true;
            break;
        }
    }
}
$free_days = array_values(array_filter($all_weekdays, static function ($d) use ($exam_days) {
    return !isset($exam_days[$d]);
}));
?>

<link rel="stylesheet" href="assets/sketch.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<style>
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
        cursor: pointer;
    }
    .exam-card {
        border: 2px solid var(--sketch-border);
        border-left: 6px solid var(--sketch-marker);
        border-radius: 10px;
        padding: 1rem 1.2rem;
        background: rgba(255, 255, 255, 0.55);
        margin-bottom: 1rem;
        transform: rotate(-0.3deg);
        transition: transform 0.2s;
    }
    .exam-card:hover {
        transform: rotate(0deg) scale(1.01);
        background: rgba(255, 255, 255, 0.75);
    }
    .exam-card .exam-code {
        font-size: 1.15rem;
        font-weight: bold;
    }
    .exam-card .exam-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 0.6rem;
        margin-top: 0.5rem;
    }
    .exam-meta-chip {
        background: rgba(255,255,255,0.7);
        border: 1.5px solid var(--sketch-border);
        border-radius: 999px;
        padding: 2px 10px;
        font-size: 0.82rem;
        display: flex;
        align-items: center;
        gap: 5px;
    }
    .free-day-card {
        border: 2px solid var(--sketch-border);
        border-radius: 10px;
        padding: 0.9rem 1rem;
        background: rgba(255, 255, 255, 0.5);
        margin-bottom: 0.85rem;
        transition: background 0.2s;
    }
    .free-day-card:hover {
        background: rgba(255, 255, 255, 0.75);
    }
    .free-day-title {
        font-weight: bold;
        margin-bottom: 0.35rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    .week-badge {
        background: var(--sketch-ink);
        color: white;
        border: 2px solid var(--sketch-border);
        border-radius: 999px;
        padding: 3px 14px;
        font-size: 0.82rem;
        font-weight: bold;
        display: inline-block;
    }
    .prep-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 2rem;
        align-items: start;
    }
    @media (max-width: 800px) {
        .prep-grid { grid-template-columns: 1fr; }
    }
</style>

<div class="sketch-container" id="prep-content">
    <!-- Header -->
    <div class="sketch-panel sketch-mobile-header">
        <div class="sketch-topbar">
            <div class="sketch-hero">
                <h1 class="sketch-title">Exam &amp; Preparation Dashboard</h1>
                <p class="sketch-subtitle">Student: <?php echo htmlspecialchars($user_name); ?> | Program: <?php echo htmlspecialchars($department); ?></p>
            </div>
            <div class="sketch-meta">
                <div class="sketch-actions">
                    <a href="student_dashboard.php" class="export-btn" style="background: var(--sketch-marker);">
                        <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
                    </a>
                    <button onclick="exportPrepToPDF()" class="export-btn">
                        <i class="fa-solid fa-file-pdf"></i> Export PDF
                    </button>
                </div>

                <div class="sketch-badge">Semester <?php echo $semester; ?> | Week <?php echo $semester_week; ?></div>
                <div class="sketch-badge">Level <?php echo $level; ?></div>
            </div>
        </div>
    </div>

    <div class="prep-grid">
        <!-- Exam Timetable -->
        <div class="sketch-panel" style="border-color: var(--sketch-marker);">
            <h3 style="color: var(--sketch-marker);"><i class="fa-solid fa-file-circle-check"></i> My Exam Timetable</h3>
            <p style="opacity: 0.65; font-size: 0.9rem; margin-top: 0;">Exams for your enrolled courses — Semester <?php echo $semester; ?>, Week <?php echo $semester_week; ?></p>

            <?php if (empty($my_exams)): ?>
                <div class="free-day-card" style="text-align:center; opacity: 0.6;">
                    <i class="fa-solid fa-inbox" style="font-size: 1.8rem; display:block; margin-bottom:0.5rem;"></i>
                    No exams scheduled for your enrolled courses yet.
                </div>
            <?php else: ?>
                <?php foreach ($my_exams as $ex): ?>
                <div class="exam-card">
                    <div class="exam-code"><?php echo htmlspecialchars($ex['code']); ?><?php if ($ex['title'] !== ''): ?> <span style="font-weight:normal; font-size:0.9rem; opacity:0.75;">— <?php echo htmlspecialchars($ex['title']); ?></span><?php endif; ?></div>
                    <div class="exam-meta">
                        <span class="exam-meta-chip"><i class="fa-solid fa-calendar-day"></i> <?php echo htmlspecialchars($ex['day']); ?></span>
                        <span class="exam-meta-chip"><i class="fa-regular fa-clock"></i> <?php echo htmlspecialchars($ex['time']); ?></span>
                        <span class="exam-meta-chip"><i class="fa-solid fa-location-dot"></i> <?php echo htmlspecialchars($ex['room']); ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Preparation & Study Focus -->
        <div class="sketch-panel">
            <h3><i class="fa-solid fa-lightbulb"></i> Preparation &amp; Study Focus</h3>
            <p style="opacity: 0.65; font-size: 0.9rem; margin-top: 0;">
                Study tips tailored to your free days this week (Week <?php echo $semester_week; ?>).
            </p>

            <?php if (empty($free_days)): ?>
                <div class="free-day-card">
                    <div class="free-day-title"><i class="fa-solid fa-triangle-exclamation"></i> No Free Days Detected</div>
                    <p style="font-size: 0.88rem; margin:0;">Your timetable appears fully packed this week. Focus on short revision breaks between classes — even 20-minute reviews help retain material.</p>
                </div>
            <?php else: ?>
                <?php foreach ($free_days as $fd): 
                    $tip_data = $free_day_tips[$fd] ?? ['icon' => 'fa-book', 'tip' => 'Use this free day for revision and rest.'];
                ?>
                <div class="free-day-card">
                    <div class="free-day-title">
                        <i class="fa-solid <?php echo $tip_data['icon']; ?>"></i>
                        <?php echo $fd; ?> <span class="sketch-badge" style="font-size:0.7rem; margin-left:4px; background:rgba(0,0,0,0.07); color:inherit;">Free Day</span>
                    </div>
                    <p style="font-size: 0.88rem; margin:0; opacity: 0.85;"><?php echo $tip_data['tip']; ?></p>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <div class="free-day-card" style="margin-top: 1rem; background: rgba(99,102,241,0.07); border-color: rgba(99,102,241,0.35);">
                <div class="free-day-title" style="color: #6366f1;"><i class="fa-solid fa-quote-left"></i> Exam Readiness Tip</div>
                <p style="font-size: 0.88rem; font-style: italic; margin:0; opacity: 0.85;">"The secret to getting ahead is getting started. Focus on understanding concepts, not just memorising."</p>
            </div>

            <div class="free-day-card" style="margin-top: 0.75rem;">
                <div class="free-day-title"><i class="fa-solid fa-circle-check"></i> General Weekly Checklist</div>
                <ul style="margin: 0.4rem 0 0 1rem; padding: 0; font-size: 0.88rem; line-height: 1.9;">
                    <li>Review lecture slides within 24 h of each class</li>
                    <li>Attempt at least one past exam question per enrolled course</li>
                    <li>Make summary sheets for each topic — one page max</li>
                    <li>Sleep 7–8 hours the night before any exam</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
function exportPrepToPDF() {
    const element = document.getElementById('prep-content');
    const opt = {
        margin:       10,
        filename:     'My_Exam_Prep_Plan.pdf',
        image:        { type: 'jpeg', quality: 0.98 },
        html2canvas:  { scale: 2, useCORS: true },
        jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' }
    };
    html2pdf().set(opt).from(element).save();
}
</script>

<?php include 'includes/footer.php'; ?>
