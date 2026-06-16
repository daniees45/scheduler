<?php
$page_title = 'My Lecture Dashboard';
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

// Get Lecturer Details
$stmt = $conn->prepare("SELECT l.id, l.name, l.availability_json FROM users u JOIN lecturers l ON u.lecturer_id = l.id WHERE u.id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
if ($row = $res->fetch_assoc()) {
    $lecturer_id = $row['id'];
    $lecturer_name = $row['name'];
    $availability = json_decode($row['availability_json'] ?? '[]', true);
} else {
    echo "<div class='sketch-panel' style='text-align: center; color: var(--sketch-marker);'>Your account is not linked to a Lecturer Profile.</div>";
    include 'includes/footer.php';
    exit;
}



// Fetch Schedule Data
$unified_payload = unified_schedule_fetch($conn, $_SESSION, [
    'semester' => $semester,
]);

$my_schedule = [];
foreach (($unified_payload['rows'] ?? []) as $row) {
    if (trim($row['lecturer']) == $lecturer_name) {
        $my_schedule[] = [
            'code' => (string)($row['course_code'] ?? ''),
            'title' => (string)($row['course_title'] ?? ''),
            'room' => (string)($row['room'] ?? 'TBA'),
            'day' => (string)($row['day'] ?? ''),
            'time' => (string)($row['time'] ?? ''),
        ];
    }
}

// 2. Build the Lecture Timetable Grid
$days = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday"];
$time_slots = [
    "7-10" => ["07:00 AM - 09:30 AM"],
    "10-12" => ["10:00 AM - 12:30 PM"],
    "12-1" => ["BREAK"],
    "2-4"  => ["02:00 PM - 04:30 PM"],
    "6-7"  => ["05:00 PM - 06:00 PM"]
];

$grid_data = [];
foreach ($days as $day) {
    foreach ($time_slots as $slot_label => $slot_ranges) {
        $grid_data[$day][$slot_label] = [];
        
        if ($slot_label === "12-1") {
            continue;
        }

        foreach ($my_schedule as $item) {
            if (strcasecmp($item['day'], $day) === 0) {
                foreach ($slot_ranges as $range) {
                    if (strpos($item['time'], explode(' - ', $range)[0]) !== false) {
                        $grid_data[$day][$slot_label][] = $item['code'] . " (" . $item['room'] . ")";
                    }
                }
            }
        }
    }
}

$personal_schedule_db = [];
$lp_stmt = $conn->prepare("SELECT day, time_slot, activity FROM personal_schedule WHERE user_id = ?");
$lp_stmt->bind_param("i", $user_id);
$lp_stmt->execute();
$lp_res = $lp_stmt->get_result();
while ($lp_row = $lp_res->fetch_assoc()) {
    $day_raw = trim($lp_row['day']);
    $slot_key = $lp_row['time_slot'];

    foreach ($days as $dday) {
        if (strcasecmp($day_raw, $dday) === 0) {
            $personal_schedule_db[$dday][$slot_key] = $lp_row['activity'];
            break;
        }
    }
}

$slot_ranges = [
    "7-10" => ["start" => "07:00:00", "end" => "09:30:00"],
    "10-12" => ["start" => "10:00:00", "end" => "12:30:00"],
    "12-1" => ["start" => "12:00:00", "end" => "13:00:00"],
    "2-4"  => ["start" => "14:00:00", "end" => "16:30:00"],
    "6-7"  => ["start" => "17:00:00", "end" => "18:00:00"]
];

$lpe_stmt = $conn->prepare("SELECT day, title, start_time, end_time FROM personal_events WHERE user_id = ?");
$lpe_stmt->bind_param("i", $user_id);
$lpe_stmt->execute();
$lpe_res = $lpe_stmt->get_result();

while ($lpe_row = $lpe_res->fetch_assoc()) {
    $pe_day_raw = trim($lpe_row['day']);
    $pe_title = $lpe_row['title'];
    $pe_start = $lpe_row['start_time'];
    $pe_end = $lpe_row['end_time'];

    $matched_day = null;
    foreach ($days as $dday) {
        if (strcasecmp($pe_day_raw, $dday) === 0) {
            $matched_day = $dday;
            break;
        }
    }

    if ($matched_day) {
        foreach ($slot_ranges as $slot_key => $range) {
            if ($pe_start < $range['end'] && $pe_end > $range['start']) {
                if (empty($personal_schedule_db[$matched_day][$slot_key])) {
                    $personal_schedule_db[$matched_day][$slot_key] = $pe_title;
                } else if (strpos($personal_schedule_db[$matched_day][$slot_key], $pe_title) === false) {
                    $personal_schedule_db[$matched_day][$slot_key] .= " / " . $pe_title;
                }
            }
        }
    }
}
?>

<link rel="stylesheet" href="assets/sketch.css">

<div class="sketch-container">
    <div class="sketch-panel sketch-mobile-header">
        <div class="sketch-topbar">
            <div class="sketch-hero">
                <h1 class="sketch-title">Lecture Timetable</h1>
                <p class="sketch-subtitle">Lecturer: <?php echo htmlspecialchars($lecturer_name); ?></p>
            </div>
            <div class="sketch-meta">
                <div class="sketch-badge">Semester <?php echo htmlspecialchars($semester); ?></div>
                <div class="sketch-badge">Lecturer View</div>
            </div>
        </div>
    </div>

    <div class="sketch-panel schedule-grid-panel">
        <div class="desktop-grid-view">
        <div class="sketch-grid-wrap">
        <div class="sketch-grid">
            <!-- Headers -->
            <div class="sketch-cell sketch-header">Time / Day</div>
            <div class="sketch-cell sketch-header">7:00am - 9:30am</div>
            <div class="sketch-cell sketch-header">10:00am - 12:30pm</div>
            <div class="sketch-cell sketch-header">12:30pm - 2:00pm</div>
            <div class="sketch-cell sketch-header">2:00pm - 4:30pm</div>
            <div class="sketch-cell sketch-header">5:00pm - 6:00pm</div>

            <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri'] as $idx => $day_short): ?>
                <?php $day_full = $days[$idx]; ?>
                <div class="sketch-cell sketch-row-label"><?php echo $day_short; ?></div>
                <div class="sketch-cell"><?php echo implode("<br>", $grid_data[$day_full]['7-10']); ?></div>
                <div class="sketch-cell"><?php echo implode("<br>", $grid_data[$day_full]['10-12']); ?></div>
                <div class="sketch-cell sketch-break">BREAK</div>
                <div class="sketch-cell"><?php echo implode("<br>", $grid_data[$day_full]['2-4']); ?></div>
                <div class="sketch-cell"><?php echo implode("<br>", $grid_data[$day_full]['6-7']); ?></div>
            <?php endforeach; ?>
        </div>
        </div>
        </div>

        <div class="mobile-card-view">
            <div class="mobile-day-grid">
            <?php foreach ($days as $day_full): ?>
                <section class="mobile-day-card">
                    <h4 class="mobile-day-title"><?php echo htmlspecialchars($day_full); ?></h4>
                    <?php foreach ($time_slots as $slot_label => $slot_ranges): ?>
                        <div class="mobile-slot-row">
                            <div class="mobile-slot-label"><?php echo htmlspecialchars($slot_label); ?></div>
                            <?php if ($slot_label === "12-1"): ?>
                                <div class="mobile-break-chip">Break</div>
                            <?php else: ?>
                                <div class="mobile-slot-content">
                                    <?php if (empty($grid_data[$day_full][$slot_label])): ?>
                                        <p class="mobile-slot-empty">No lecture</p>
                                    <?php else: ?>
                                        <?php foreach ($grid_data[$day_full][$slot_label] as $lecture): ?>
                                            <div class="mobile-lecture-chip"><?php echo htmlspecialchars($lecture); ?></div>
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

    <div class="sketch-panel schedule-grid-panel">
        <h3>Personal Timetable</h3>
        <div class="desktop-grid-view">
        <div class="sketch-grid-wrap">
        <div class="sketch-grid">
            <div class="sketch-cell sketch-header">Time / Day</div>
            <div class="sketch-cell sketch-header">7:00am - 9:30am</div>
            <div class="sketch-cell sketch-header">10:00am - 12:30pm</div>
            <div class="sketch-cell sketch-header">12:30pm - 2:00pm</div>
            <div class="sketch-cell sketch-header">2:00pm - 4:30pm</div>
            <div class="sketch-cell sketch-header">5:00pm - 6:00pm</div>

            <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri'] as $idx => $day_short): ?>
                <?php $day_full = $days[$idx]; ?>
                <div class="sketch-cell sketch-row-label"><?php echo $day_short; ?></div>
                <?php foreach (array_keys($time_slots) as $slot_key): ?>
                    <?php $has_clash = !empty($grid_data[$day_full][$slot_key]); ?>
                    <div class="sketch-cell <?php echo $has_clash ? 'clash-slot' : ''; ?>">
                        <?php if ($has_clash): ?>
                            <span class="clash-icon" title="Clash">WARNING: Teaching slot</span>
                        <?php else: ?>
                            <textarea class="editable-slot" onchange="saveLecturerActivity('<?php echo $day_full; ?>', '<?php echo $slot_key; ?>', this.value)" placeholder="..."><?php echo htmlspecialchars($personal_schedule_db[$day_full][$slot_key] ?? ''); ?></textarea>
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
                    <?php foreach (array_keys($time_slots) as $slot_key): ?>
                        <?php $has_clash = !empty($grid_data[$day_full][$slot_key]); ?>
                        <div class="mobile-slot-row <?php echo $has_clash ? 'mobile-slot-row-clash' : ''; ?>">
                            <div class="mobile-slot-label"><?php echo htmlspecialchars($slot_key); ?></div>
                            <?php if ($has_clash): ?>
                                <div class="mobile-clash-chip">Teaching slot</div>
                            <?php else: ?>
                                <textarea class="editable-slot mobile-editable-slot" onchange="saveLecturerActivity('<?php echo $day_full; ?>', '<?php echo $slot_key; ?>', this.value)" placeholder="Add your activity..."><?php echo htmlspecialchars($personal_schedule_db[$day_full][$slot_key] ?? ''); ?></textarea>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </section>
            <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="sketch-two-col">
        <div class="sketch-panel">
            <h3 style="border-bottom: 2px solid var(--sketch-border); display: inline-block;">Availability Settings</h3>
            <p style="font-size: 0.9rem; margin-top: 10px;">Your preferred teaching days are managed by your academic department. Please contact your department administrator for changes.</p>
            <div style="margin-top: 1rem; display: flex; flex-wrap: wrap; gap: 10px;">
                <?php foreach ($availability as $idx): ?>
                    <span class="sketch-badge" style="background: #e0ffe0; color: #222; padding: 0.5em 1em; border-radius: 16px; font-weight: 600;">
                        <?php echo htmlspecialchars($days[$idx]); ?>
                    </span>
                <?php endforeach; ?>
                <?php if (empty($availability)): ?>
                    <span style="color: #888;">No preferred days set.</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="sketch-panel">
            <h3 style="color: var(--sketch-marker);">Final Exam Duty</h3>
            <p style="font-size: 0.9rem;">Check your invigilation slots.</p>
            <div style="border: 2px dashed var(--sketch-marker); padding: 1rem; border-radius: 5px; margin-top: 1rem;">
                <ul class="sketch-list">
                    <li class="sketch-list-item">Exam Period: June 15 - July 2</li>
                    <li class="sketch-list-item">Portal opens for results entry on July 5</li>
                </ul>
            </div>
            <a href="exam_dashboard.php" class="glass-btn" style="margin-top: 1rem; width: 100%; text-decoration: none; text-align: center; display: block;">
                View Global Exam Schedule
            </a>
        </div>
    </div>
</div>

<script>
async function saveLecturerActivity(day, slot, activity) {
    const formData = new FormData();
    formData.append('action', 'save_personal_activity');
    formData.append('day', day);
    formData.append('slot', slot);
    formData.append('activity', activity);

    try {
        const response = await fetch('api/lecturer_personal_data.php', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();
        if (!data.success) {
            alert('Failed to save: ' + (data.error || 'Unknown error'));
        }
    } catch (err) {
        console.error(err);
    }
}
const grid = document.querySelector('.sketch-grid');
const headers = Array.from(grid.querySelectorAll('.sketch-cell.sketch-header'));
const timeDay = headers.find(el => el.textContent.trim() === 'Time / Day');
const targetTime = headers.find(el => el.textContent.trim() === '5:00pm - 6:00pm');

if (grid && timeDay && targetTime) {
  // Option 1: Update grid-template-columns to accommodate 6 columns instead of 5
  // Current: 120px repeat(4, 1fr) -> Total 5 columns
  // Target: 120px repeat(5, 1fr) -> Total 6 columns
  grid.style.gridTemplateColumns = '120px repeat(5, 1fr)';
}
</script>

<?php include 'includes/footer.php'; ?>