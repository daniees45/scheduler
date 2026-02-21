<?php
$page_title = 'Lecturer Dashboard';
include 'includes/header.php';
require_once 'api/db.php';

// Access Check
if ($_SESSION['role'] !== 'lecturer') {
    echo "<div class='glass-panel' style='padding: 2rem; margin: 2rem; text-align: center;'>Access Denied. Lecturer area only.</div>";
    include 'includes/footer.php';
    exit;
}

$user_id = $_SESSION['user_id'];
$lecturer_id = null;
$lecturer_name = '';
$availability = [];
$semester = (string)($_SESSION['semester'] ?? '1');
$lecturer_department = '';

$column_exists = static function (mysqli $conn, string $table, string $column): bool {
    $stmt = $conn->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $res = $stmt->get_result();
    return $res && $res->num_rows > 0;
};

// Get Lecturer Details
$stmt = $conn->prepare("SELECT l.id, l.name, l.availability_json FROM users u JOIN lecturers l ON u.lecturer_id = l.id WHERE u.id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
if ($row = $res->fetch_assoc()) {
    $lecturer_id = $row['id'];
    $lecturer_name = $row['name'];
    $availability = json_decode($row['availability_json'] ?? '[]', true);
    if (!is_array($availability)) $availability = [];

    if ($lecturer_id && $column_exists($conn, 'lecturers', 'department')) {
        $dept_stmt = $conn->prepare("SELECT department FROM lecturers WHERE id = ?");
        if ($dept_stmt) {
            $dept_stmt->bind_param("i", $lecturer_id);
            $dept_stmt->execute();
            $dept_res = $dept_stmt->get_result();
            if ($dept_res && ($dept_row = $dept_res->fetch_assoc())) {
                $lecturer_department = trim((string)($dept_row['department'] ?? ''));
            }
        }
    }
} else {
    echo "<div class='glass-panel' style='padding: 2rem; margin: 2rem; text-align: center; color: var(--warning);'>Your account is not linked to a Lecturer Profile. Please contact Admin.</div>";
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

if ($lecturer_department === '' && $lecturer_id) {
    $codes = [];
    $code_stmt = $conn->prepare("SELECT DISTINCT c.course_code
                                 FROM sections s
                                 JOIN courses c ON s.course_id = c.id
                                 WHERE s.lecturer_id = ?");
    if ($code_stmt) {
        $code_stmt->bind_param('i', $lecturer_id);
        $code_stmt->execute();
        $code_res = $code_stmt->get_result();
        while ($code_row = $code_res->fetch_assoc()) {
            if (!empty($code_row['course_code'])) {
                $codes[] = $code_row['course_code'];
            }
        }
    }
    $lecturer_department = $infer_department_from_codes($codes);
}

if ($lecturer_department === '') {
    $lecturer_department = 'General';
}
    include 'includes/footer.php';
    exit;
}

// Handle Availability Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_availability'])) {
    $new_avail = [];
    if (isset($_POST['days'])) {
        foreach ($_POST['days'] as $day_idx) {
            $new_avail[] = (int)$day_idx;
        }
    }
    $json = json_encode($new_avail);
    
    // Update DB
    $up_stmt = $conn->prepare("UPDATE lecturers SET availability_json = ? WHERE id = ?");
    $up_stmt->bind_param("si", $json, $lecturer_id);
    if ($up_stmt->execute()) {
        $msg = "Availability updated successfully.";
        $availability = $new_avail;
        
        // Trigger Sync to CSV for AI (Optional, but good practice)
        // We'll just update the CSV file directly or let the sync run later
    } else {
        $error = "Failed to update.";
    }
}

// Get My Schedule
$my_schedule = [];
$seen_rows = [];

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

$db_schedule_sources = [];
if ($lecturer_department !== '') {
    $dept_sched_stmt = $conn->prepare("SELECT schedule_name, department, created_at, schedule_data
                                       FROM generated_schedules
                                       WHERE LOWER(TRIM(department)) = LOWER(TRIM(?))
                                         AND (schedule_name NOT LIKE 'exam_%' OR schedule_name IS NULL)
                                         AND (semester = ? OR semester IS NULL OR TRIM(semester) = '')
                                       ORDER BY created_at DESC LIMIT 1");
    if ($dept_sched_stmt) {
        $dept_sched_stmt->bind_param('ss', $lecturer_department, $semester);
        $dept_sched_stmt->execute();
        $rs = $dept_sched_stmt->get_result();
        if ($rs && ($row = $rs->fetch_assoc())) {
            $db_schedule_sources[] = ['type' => 'department', 'row' => $row];
        }
    }
}

$gen_sched_stmt = $conn->prepare("SELECT schedule_name, department, created_at, schedule_data
                                  FROM generated_schedules
                                  WHERE (department IS NULL OR TRIM(department) = '' OR LOWER(TRIM(department)) = 'general')
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

if (!empty($db_schedule_sources) && $lecturer_name !== '') {
    foreach ($db_schedule_sources as $source) {
        $rows = $extract_rows_from_schedule_data($source['row']['schedule_data'] ?? '');
        foreach ($rows as $row) {
            $row_lecturer = trim((string)($row['lecturer name'] ?? ($row['lecturer'] ?? '')));
            if ($row_lecturer === '' || strcasecmp($row_lecturer, $lecturer_name) !== 0) {
                continue;
            }

            $code = trim((string)($row['course code'] ?? ($row['course'] ?? ($row['code'] ?? ''))));
            $title = trim((string)($row['course title'] ?? ($row['title'] ?? '')));
            $room = trim((string)($row['room name'] ?? ($row['room'] ?? ($row['room_name'] ?? ''))));
            $day = trim((string)($row['day'] ?? ''));
            $time = trim((string)($row['time'] ?? ($row['assigned time'] ?? ($row['assigned_time'] ?? ''))));

            $key = strtolower($code . '|' . $day . '|' . $time . '|' . $room);
            if (isset($seen_rows[$key])) {
                continue;
            }
            $seen_rows[$key] = true;

            $my_schedule[] = [
                'code' => $code,
                'title' => $title,
                'room' => $room,
                'day' => $day,
                'time' => $time
            ];
        }
    }
}

if (empty($my_schedule)) {
    $csv_file = realpath('../') . '/final_web_schedule.csv';
    /*
        Indices based on sync.php export:
        0:Code, 1:Title, 2:Credits, 3:Lecturer, 4:Room, 5:Day, 6:Time
    */
    if (file_exists($csv_file) && ($handle = fopen($csv_file, "r")) !== FALSE) {
        fgetcsv($handle);
        while (($row = fgetcsv($handle, 1000, ",")) !== FALSE) {
            if (trim($row[3]) == $lecturer_name) {
                $my_schedule[] = [
                    'code' => $row[0],
                    'title' => $row[1],
                    'room' => $row[4],
                    'day' => $row[5],
                    'time' => $row[6]
                ];
            }
        }
        fclose($handle);
    }
}

$days_map = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
$day_order = array_flip($days_map);
usort($my_schedule, function($a, $b) use ($day_order) {
    $da = $day_order[$a['day']] ?? 99;
    $db = $day_order[$b['day']] ?? 99;
    if ($da !== $db) return $da <=> $db;
    return strcmp($a['time'], $b['time']);
});
?>

<div class="glass-panel" style="padding: 2rem;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
        <div>
            <h2>Welcome, <?php echo htmlspecialchars($lecturer_name); ?></h2>
            <p style="color: var(--text-muted);">Manage your potential availability and view your current classes.</p>
        </div>
        <div class="status-badge online">
            <i class="fa-solid fa-check"></i> Profile Active
        </div>
    </div>

    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem;">
        <!-- 1. My Schedule -->
        <div>
            <h3 style="margin-bottom: 1rem;"><i class="fa-solid fa-calendar"></i> My Classes</h3>
            <?php if (empty($my_schedule)): ?>
                <div style="padding: 2rem; background: rgba(255,255,255,0.03); border-radius: 8px; text-align: center; color: var(--text-muted);">
                    No classes assigned in current schedule.
                </div>
            <?php else: ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Day/Time</th>
                                <th>Course</th>
                                <th>Room</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($my_schedule as $c): ?>
                            <tr>
                                <td>
                                    <div style="color: var(--primary-color); font-weight: 500;"><?php echo $c['day']; ?></div>
                                    <div style="font-size: 0.85rem; font-family: monospace;"><?php echo $c['time']; ?></div>
                                </td>
                                <td>
                                    <div style="font-weight: 600;"><?php echo $c['code']; ?></div>
                                    <div style="font-size: 0.8rem; color: var(--text-muted);"><?php echo $c['title']; ?></div>
                                </td>
                                <td><?php echo $c['room']; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- 2. Availability Settings -->
        <div>
            <h3 style="margin-bottom: 1rem;"><i class="fa-solid fa-clock"></i> Set Availability</h3>
            <?php if(isset($msg)) echo "<div class='alert alert-success'>$msg</div>"; ?>
            
            <form method="POST" style="background: rgba(255,255,255,0.03); padding: 1.5rem; border-radius: 12px; border: 1px solid rgba(255,255,255,0.05);">
                <p style="font-size: 0.9rem; color: var(--text-muted); margin-bottom: 1rem;">
                    Select the days you are available to teach. This will guide the AI for the next schedule generation.
                </p>
                
                <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                    <?php foreach($days_map as $idx => $day): ?>
                        <label class="glass-btn secondary" style="display: flex; align-items: center; gap: 10px; justify-content: flex-start; cursor: pointer; text-align: left;">
                            <input type="checkbox" name="days[]" value="<?php echo $idx; ?>" <?php if(in_array($idx, $availability)) echo 'checked'; ?>>
                            <span><?php echo $day; ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div style="margin-top: 1.5rem;">
                    <button type="submit" name="update_availability" class="glass-btn" style="width: 100%;">
                        <i class="fa-solid fa-save"></i> Save Availability
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
