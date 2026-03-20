<?php
$page_title = 'Lecturer Dashboard';
include 'includes/header.php';
require_once 'api/db.php';
require_once 'includes/unified_schedule_service.php';
require_once __DIR__ . '/../lib/B2Storage.php';

requireRole(['lecturer']);

$user_id = $_SESSION['user_id'];
$lecturer_id = null;
$lecturer_name = '';
$availability = [];
$semester = (string)($_SESSION['semester'] ?? '1');
$lecturer_department = '';

$column_exists = static function (mysqli $conn, string $table, string $column): bool {
    $stmt = $conn->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
    if (!$stmt)
        return false;
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $res = $stmt->get_result();
    return $res && $res->num_rows > 0;
};

$sync_lecturer_availability_to_b2 = static function (mysqli $conn): array {
    $rows = [];
    $result = $conn->query("SELECT name, availability_json FROM lecturers ORDER BY name");
    if (!$result) {
        return ['success' => false, 'error' => 'Failed to read lecturers for B2 sync'];
    }

    while ($lecturer = $result->fetch_assoc()) {
        $avail = json_decode((string)($lecturer['availability_json'] ?? '[]'), true);
        if (!is_array($avail)) {
            $avail = [];
        }

        $normalized = [];
        foreach ($avail as $dayIdx) {
            $idx = (int)$dayIdx;
            if ($idx >= 0 && $idx <= 4) {
                $normalized[$idx] = true;
            }
        }

        $rows[] = [
            (string)($lecturer['name'] ?? ''),
            isset($normalized[0]) ? 1 : 0,
            isset($normalized[1]) ? 1 : 0,
            isset($normalized[2]) ? 1 : 0,
            isset($normalized[3]) ? 1 : 0,
            isset($normalized[4]) ? 1 : 0,
        ];
    }

    $handle = fopen('php://temp', 'r+');
    if (!$handle) {
        return ['success' => false, 'error' => 'Failed to prepare lecturer availability CSV'];
    }

    fputcsv($handle, ['lecturer_name', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri']);
    foreach ($rows as $row) {
        fputcsv($handle, $row);
    }
    rewind($handle);
    $csv_content = stream_get_contents($handle);
    fclose($handle);

    $b2 = new B2Storage();
    $upload = $b2->uploadContent((string)$csv_content, 'csv/general/lecturer_availability.csv', [
        'source' => 'lecturer_dashboard',
        'updated_at' => date('Y-m-d H:i:s')
    ]);

    if (!($upload['success'] ?? false)) {
        return ['success' => false, 'error' => (string)($upload['error'] ?? 'B2 upload failed')];
    }

    return ['success' => true];
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
    if (!is_array($availability))
        $availability = [];

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
}
else {
    echo "<div class='glass-panel' style='padding: 2rem; margin: 2rem; text-align: center; color: var(--warning);'>Your account is not linked to a Lecturer Profile. Please contact Admin.</div>";
    include 'includes/footer.php';
    exit;
}

$get_department_from_code = static function ($course_code): string {
    $code = strtoupper((string)$course_code);

    if (preg_match('/(COSC|INFT|BBIS|CSCD)/', $code))
        return 'CS/IT/BBIS';
    if (preg_match('/(ACCT|BUSI|MGMT|ECON|MKTG|FNCE)/', $code))
        return 'Business';
    if (preg_match('/(EDUC|PEDC|TEAC|CLED)/', $code))
        return 'Education';
    if (preg_match('/(DEVS|INTL|AFRI|AFRN)/', $code))
        return 'DevelopmentStudies';
    if (preg_match('/(BIOM|ENGR|BENG|HLTC)/', $code))
        return 'BiomedicalEngineering';
    if (preg_match('/(NURS|RNSG|MIDW)/', $code))
        return 'Nursing';
    if (preg_match('/(RELB|RELT)/', $code))
        return 'Theology';

    return 'General';
};

$infer_department_from_codes = static function (array $codes) use ($get_department_from_code): string {
    if (empty($codes))
        return '';

    $counts = [];
    foreach ($codes as $code) {
        $dept = $get_department_from_code($code);
        $counts[$dept] = ($counts[$dept] ?? 0) + 1;
    }

    arsort($counts);
    $top = array_key_first($counts);
    return $top ? (string)$top : '';
};

if (($lecturer_department === '' || $lecturer_department === 'General') && $lecturer_id) {
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
    $inferred = $infer_department_from_codes($codes);
    if ($inferred !== '') {
        $lecturer_department = $inferred;
    }
}

if ($lecturer_department === '') {
    $lecturer_department = 'General';
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
        $sync = $sync_lecturer_availability_to_b2($conn);
        if ($sync['success']) {
            $msg = "Availability updated and synced to B2 successfully.";
        } else {
            $error = "Availability updated locally, but B2 sync failed: " . (string)($sync['error'] ?? 'Unknown error');
        }
        $availability = $new_avail;
    }
    else {
        $error = "Failed to update.";
    }
}

$saved_at_selection = trim((string)($_GET['saved_at'] ?? ''));

$unified_payload = unified_schedule_fetch($conn, $_SESSION, [
    'semester' => $semester,
    'saved_at' => $saved_at_selection,
]);

$available_snapshots = $unified_payload['snapshots'] ?? [];
$active_saved_at = (string)($unified_payload['saved_at'] ?? '');
$my_schedule = [];

foreach (($unified_payload['rows'] ?? []) as $row) {
    $my_schedule[] = [
        'code' => (string)($row['course_code'] ?? ''),
        'title' => (string)($row['course_title'] ?? ''),
        'room' => (string)($row['room'] ?? 'TBA'),
        'day' => (string)($row['day'] ?? ''),
        'time' => (string)($row['time'] ?? ''),
    ];
}

if (empty($my_schedule)) {
    $csv_file = realpath('../') . '/csv/final/final_web_schedule.csv';
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
usort($my_schedule, function ($a, $b) use ($day_order) {
    $da = $day_order[$a['day']] ?? 99;
    $db = $day_order[$b['day']] ?? 99;
    if ($da !== $db)
        return $da <=> $db;
    return strcmp($a['time'], $b['time']);
});
?>

<style>
    .lecturer-dashboard-shell {
        padding: 2rem;
    }

    .lecturer-dashboard-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 2rem;
        padding: 1.5rem;
        border-radius: 1rem;
        background: linear-gradient(135deg, rgba(var(--primary-rgb), 0.12), rgba(var(--site-secondary-rgb), 0.1));
        border: 1px solid rgba(255, 255, 255, 0.08);
    }

    .lecturer-dashboard-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 2rem;
    }

    .lecturer-empty-state,
    .lecturer-availability-card {
        background: rgba(255,255,255,0.03);
        border: 1px solid rgba(255,255,255,0.06);
        border-radius: 12px;
    }

    .lecturer-availability-option {
        display: flex;
        align-items: center;
        gap: 10px;
        justify-content: flex-start;
        cursor: pointer;
        text-align: left;
        border-color: rgba(var(--primary-rgb), 0.15);
    }

    .lecturer-availability-option:has(input:checked) {
        background: rgba(var(--primary-rgb), 0.14);
        border-color: rgba(var(--primary-rgb), 0.28);
    }

    @media (max-width: 900px) {
        .lecturer-dashboard-header,
        .lecturer-dashboard-grid {
            grid-template-columns: 1fr;
            display: grid;
        }
    }
</style>

<div class="glass-panel lecturer-dashboard-shell">
    <div class="lecturer-dashboard-header">
        <div>
            <h2>Welcome,
                <?php echo htmlspecialchars($lecturer_name); ?>
            </h2>
            <p style="color: var(--text-muted);">Manage your potential availability and view your current classes.</p>
            <?php if ($active_saved_at !== ''): ?>
            <p style="color: var(--text-muted); margin-top: 0.5rem; font-size: 0.88rem;">
                <i class="fa-solid fa-clock"></i> Schedule snapshot time:
                <?php echo htmlspecialchars($active_saved_at); ?>
            </p>
            <?php endif; ?>
        </div>
        <div class="status-badge online">
            <i class="fa-solid fa-check"></i> Profile Active
        </div>
    </div>

    <form method="GET" style="display: flex; gap: 0.6rem; flex-wrap: wrap; align-items: center; margin-bottom: 1rem;">
        <label for="saved_at" style="color: var(--text-muted); font-size: 0.9rem;">Load by saved time:</label>
        <select id="saved_at" name="saved_at" class="glass-input" style="min-width: 280px;">
            <option value="">Latest saved schedules</option>
            <?php foreach ($available_snapshots as $snapshot): ?>
            <?php $snapshot_created_at = (string)($snapshot['created_at'] ?? ''); ?>
            <option value="<?php echo htmlspecialchars($snapshot_created_at); ?>" <?php echo ($saved_at_selection !== '' && $saved_at_selection === $snapshot_created_at) ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars(($snapshot_created_at !== '' ? $snapshot_created_at : 'Unknown time') . ' • ' . (($snapshot['department'] ?? '') ?: 'General') . ' • ' . (($snapshot['schedule_name'] ?? '') ?: 'Unnamed')); ?>
            </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="glass-btn secondary"><i class="fa-solid fa-filter"></i> Apply</button>
    </form>

    <div class="lecturer-dashboard-grid">
        <!-- 1. My Schedule -->
        <div>
            <h3 style="margin-bottom: 1rem;"><i class="fa-solid fa-calendar"></i> My Classes</h3>
            <?php if (empty($my_schedule)): ?>
            <div class="lecturer-empty-state"
                style="padding: 2rem; text-align: center; color: var(--text-muted);">
                No classes assigned in current schedule.
            </div>
            <?php
else: ?>
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
                        <?php foreach ($my_schedule as $c): ?>
                        <tr>
                            <td>
                                <div style="color: var(--primary-color); font-weight: 500;">
                                    <?php echo $c['day']; ?>
                                </div>
                                <div style="font-size: 0.85rem; font-family: monospace;">
                                    <?php echo $c['time']; ?>
                                </div>
                            </td>
                            <td>
                                <div style="font-weight: 600;">
                                    <?php echo $c['code']; ?>
                                </div>
                                <div style="font-size: 0.8rem; color: var(--text-muted);">
                                    <?php echo $c['title']; ?>
                                </div>
                            </td>
                            <td>
                                <?php echo $c['room']; ?>
                            </td>
                        </tr>
                        <?php
    endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php
endif; ?>
        </div>

        <!-- 2. Availability Settings -->
        <div>
            <h3 style="margin-bottom: 1rem;"><i class="fa-solid fa-clock"></i> Set Availability</h3>
            <?php if (isset($msg))
    echo "<div class='alert alert-success'>$msg</div>"; ?>
                <?php if (isset($error))
            echo "<div class='alert alert-warning'>$error</div>"; ?>

            <form method="POST" class="lecturer-availability-card"
                style="padding: 1.5rem;">
                <p style="font-size: 0.9rem; color: var(--text-muted); margin-bottom: 1rem;">
                    Select the days you are available to teach. This will guide the AI for the next schedule generation.
                </p>

                <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                    <?php foreach ($days_map as $idx => $day): ?>
                    <label class="glass-btn secondary lecturer-availability-option">
                        <input type="checkbox" name="days[]" value="<?php echo $idx; ?>" <?php if (in_array($idx,
    $availability))
        echo 'checked'; ?>>
                        <span>
                            <?php echo $day; ?>
                        </span>
                    </label>
                    <?php
endforeach; ?>
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