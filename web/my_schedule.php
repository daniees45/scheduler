<?php
$page_title = 'My Weekly Schedule';
include 'includes/header.php';
require_once 'api/db.php';
require_once 'includes/unified_schedule_service.php';

requireRole(['student', 'lecturer']);

$user_id = (int)($_SESSION['user_id'] ?? 0);
$user_role = $_SESSION['role'] ?? 'student';
$is_lecturer = $user_role === 'lecturer';
$semester = (string)($_SESSION['semester'] ?? '1');

$days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
$event_types = ['study', 'work', 'personal', 'exercise', 'rest', 'other'];

$message = '';
$message_type = '';

// Fetch Academic Schedule for clash detection
$unified_payload = unified_schedule_fetch($conn, $_SESSION, ['semester' => $semester]);
$academic_rows = $unified_payload['rows'] ?? [];
$enrolled_courses = $unified_payload['enrolled_courses'] ?? [];

function parseFlexibleAcademicTimePart($value) {
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    $formats = ['g:i A', 'g A', 'H:i', 'H'];
    foreach ($formats as $format) {
        $dt = DateTime::createFromFormat($format, strtoupper($value));
        if ($dt instanceof DateTime) {
            return $dt->format('H:i:s');
        }
    }

    $timestamp = strtotime($value);
    if ($timestamp !== false) {
        return date('H:i:s', $timestamp);
    }

    return null;
}

// Helper to parse flexible academic time formats into start/end H:i:s
function parseAcademicTime($time_range) {
    $time_range = trim((string)$time_range);
    if ($time_range === '') {
        return null;
    }

    $normalized = preg_replace('/\s+/', ' ', str_replace([' to ', ' TO ', '–', '—'], ' - ', $time_range));
    $parts = preg_split('/\s*-\s*/', $normalized);
    if (!$parts || count($parts) < 2) {
        return null;
    }

    $start = parseFlexibleAcademicTimePart($parts[0]);
    $end = parseFlexibleAcademicTimePart($parts[1]);
    if (!$start || !$end) {
        return null;
    }

    return [
        'start' => $start,
        'end' => $end
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_event') {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $day = trim($_POST['day'] ?? '');
        $start_time = $_POST['start_time'] . ':00';
        $end_time = $_POST['end_time'] . ':00';
        $event_type = trim($_POST['event_type'] ?? 'other');
        $color = trim($_POST['color'] ?? '#6366f1');

        if ($title === '' || !in_array($day, $days, true) || $start_time === ':00' || $end_time === ':00') {
            $message = 'Please fill all required fields.';
            $message_type = 'error';
        } elseif (strtotime($end_time) <= strtotime($start_time)) {
            $message = 'End time must be after start time.';
            $message_type = 'error';
        } else {
            // 1. Check Academic Clash
            $academic_clash = null;
            foreach ($academic_rows as $ar) {
                if (strcasecmp($ar['day'], $day) === 0) {
                    $is_relevant = false;
                    if ($is_lecturer) {
                        $is_relevant = true; // For lecturers, check everything in their view
                    } else {
                        foreach ($enrolled_courses as $ec) {
                            if (strcasecmp($ec['course_code'], $ar['course_code']) === 0) {
                                $is_relevant = true; break;
                            }
                        }
                    }

                    if ($is_relevant) {
                        $a_times = parseAcademicTime($ar['time']);
                        if ($a_times) {
                            if ($start_time < $a_times['end'] && $end_time > $a_times['start']) {
                                $academic_clash = $ar['course_code'] . " (" . $ar['time'] . ")";
                                break;
                            }
                        }
                    }
                }
            }

            if ($academic_clash) {
                $message = "Academic Clash! You have a class: $academic_clash";
                $message_type = "error";
            } else {
                // 2. Check Personal Clash
                $stmt = $conn->prepare("SELECT id FROM personal_events WHERE user_id = ? AND day = ? AND NOT (end_time <= ? OR start_time >= ?) LIMIT 1");
                $stmt->bind_param("isss", $user_id, $day, $start_time, $end_time);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    $message = "Personal Clash! This overlaps with another personal event.";
                    $message_type = "error";
                } else {
                    $ins = $conn->prepare("INSERT INTO personal_events (user_id, title, description, day, start_time, end_time, event_type, color) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $ins->bind_param("isssssss", $user_id, $title, $description, $day, $start_time, $end_time, $event_type, $color);
                    if ($ins->execute()) {
                        $message = "Event added successfully!";
                        $message_type = "success";
                    } else {
                        $message = "Database error.";
                        $message_type = "error";
                    }
                }
            }
        }
    }

    if ($action === 'delete_event') {
        $id = (int)$_POST['event_id'];
        $stmt = $conn->prepare("DELETE FROM personal_events WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $id, $user_id);
        $stmt->execute();
        $message = "Event deleted.";
        $message_type = "success";
    }
}

// Fetch Events
$events_by_day = [];
foreach ($days as $d) $events_by_day[$d] = [];
$res = $conn->query("SELECT * FROM personal_events WHERE user_id = $user_id ORDER BY start_time");
while ($row = $res->fetch_assoc()) {
    $events_by_day[$row['day']][] = $row;
}

// Also pull Dashboard Personal Timetable entries (personal_schedule table)
$dashboard_slot_times = [
    "7:00 - 9:30"   => ['start' => '07:00:00', 'end' => '09:30:00'],
    "10:00 - 12:30" => ['start' => '10:00:00', 'end' => '12:30:00'],
    "12:30 - 14:00"  => ['start' => '12:30:00', 'end' => '14:00:00'],
    "14:00 - 16:30"   => ['start' => '14:00:00', 'end' => '16:30:00'],
    "17:00 - 18:00"      => ['start' => '17:00:00', 'end' => '18:00:00'],
];
$ps_res = $conn->query("SELECT day, time_slot, activity FROM personal_schedule WHERE user_id = $user_id AND activity != '' AND activity IS NOT NULL");
if ($ps_res) {
    while ($ps_row = $ps_res->fetch_assoc()) {
        $ps_day = trim($ps_row['day']);
        $ps_slot = trim($ps_row['time_slot']);
        $ps_activity = trim($ps_row['activity']);
        if ($ps_activity === '' || !isset($dashboard_slot_times[$ps_slot]) || !isset($events_by_day[$ps_day])) continue;
        $times = $dashboard_slot_times[$ps_slot];
        $events_by_day[$ps_day][] = [
            'id'           => null,
            'title'        => $ps_activity,
            'description'  => '',
            'day'          => $ps_day,
            'start_time'   => $times['start'],
            'end_time'     => $times['end'],
            'event_type'   => 'dashboard',
            'color'        => '#a8edea',
            'is_dashboard' => true,
        ];
    }
}

$academic_by_day = [];
foreach ($days as $day_name) {
    $academic_by_day[$day_name] = [];
}

foreach ($academic_rows as $row) {
    $day_name = trim((string)($row['day'] ?? ''));
    if ($day_name === '' || !isset($academic_by_day[$day_name])) {
        continue;
    }
    $academic_by_day[$day_name][] = [
        'code' => trim((string)($row['course_code'] ?? 'Class')),
        'time' => trim((string)($row['time'] ?? '')),
        'room' => trim((string)($row['room'] ?? 'TBA')),
        'lecturer' => trim((string)($row['lecturer'] ?? 'TBA')),
    ];
}
?>

<link rel="stylesheet" href="assets/sketch.css">
<style>
    .sketch-form-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1.5rem;
        margin-top: 1rem;
    }
    .event-card {
        background: rgba(255, 255, 255, 0.4);
        border: 2px solid var(--sketch-border);
        border-radius: 10px;
        padding: 15px;
        margin-bottom: 15px;
        position: relative;
        transform: rotate(-0.5deg);
        transition: all 0.2s;
    }
    .event-card:hover {
        transform: rotate(0deg) scale(1.02);
        background: rgba(255, 255, 255, 0.6);
    }
    .delete-btn {
        position: absolute;
        top: 8px;
        right: 8px;
        background: transparent;
        border: none;
        color: var(--sketch-marker);
        cursor: pointer;
        font-size: 1.2rem;
    }
    .planner-grid {
        display: grid;
        grid-template-columns: minmax(0, 1.1fr) minmax(320px, 0.9fr);
        gap: 1.5rem;
        align-items: start;
    }
    .planner-insight-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
        gap: 1rem;
        margin-top: 1rem;
    }
    .planner-kpi {
        border: 2px solid var(--sketch-border);
        border-radius: 12px;
        background: rgba(255, 255, 255, 0.75);
        padding: 0.9rem;
    }
    .planner-kpi strong {
        display: block;
        font-size: 1.35rem;
        margin-top: 0.25rem;
    }
    .academic-item,
    .suggestion-card {
        border: 2px solid var(--sketch-border);
        border-radius: 10px;
        padding: 0.85rem;
        background: rgba(255,255,255,0.75);
        margin-bottom: 0.75rem;
    }
    .academic-item:last-child,
    .suggestion-card:last-child {
        margin-bottom: 0;
    }
    .suggestion-actions {
        display: flex;
        gap: 0.6rem;
        margin-top: 0.75rem;
        flex-wrap: wrap;
    }
    .suggestion-btn {
        border: 2px solid var(--sketch-border);
        background: white;
        border-radius: 999px;
        padding: 0.45rem 0.8rem;
        cursor: pointer;
        font-family: inherit;
    }
    .suggestion-btn.primary {
        background: var(--sketch-ink);
        color: white;
    }
    .planner-muted {
        color: #64748b;
        font-size: 0.9rem;
    }
    @media (max-width: 900px) {
        .planner-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="sketch-container">
    <div class="sketch-panel">
        <h1 class="sketch-title">Weekly Planner</h1>
        <p>Sync your life with your academic schedule. Hand-drawn for students, by students.</p>
    </div>

    <?php if ($message): ?>
    <div class="sketch-panel" style="background: <?php echo $message_type == 'success' ? 'rgba(0,255,0,0.1)' : 'rgba(255,0,0,0.1)'; ?>; border-color: <?php echo $message_type == 'success' ? '#2ecc71' : '#e74c3c'; ?>;">
        <strong><?php echo $message_type == 'success' ? '✓' : '⚠'; ?></strong> <?php echo htmlspecialchars($message); ?>
    </div>
    <?php endif; ?>

    <div class="sketch-panel">
        <h3><i class="fa-solid fa-plus-circle"></i> Add New Event</h3>
        <form method="POST">
            <input type="hidden" name="action" value="add_event">
            <div class="sketch-form-grid">
                <div class="form-group">
                    <label>Activity Title</label>
                    <input type="text" name="title" class="glass-input" required placeholder="e.g. Library Study">
                </div>
                <div class="form-group">
                    <label>Day</label>
                    <select name="day" class="glass-input" required>
                        <?php foreach ($days as $d): ?>
                        <option value="<?php echo $d; ?>"><?php echo $d; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Start Time</label>
                    <input type="time" name="start_time" class="glass-input" required>
                </div>
                <div class="form-group">
                    <label>End Time</label>
                    <input type="time" name="end_time" class="glass-input" required>
                </div>
                <div class="form-group">
                    <label>Category</label>
                    <select name="event_type" class="glass-input">
                        <?php foreach ($event_types as $t): ?>
                        <option value="<?php echo $t; ?>"><?php echo ucfirst($t); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Marker Color</label>
                    <input type="color" name="color" class="glass-input" style="height: 45px; padding: 5px;" value="#6366f1">
                </div>
            </div>
            <div class="form-group" style="margin-top: 1rem;">
                <label>Description</label>
                <textarea name="description" class="glass-input" rows="2" placeholder="Notes..."></textarea>
            </div>
            <button type="submit" class="sketch-badge" style="margin-top: 1.5rem; cursor: pointer; background: var(--sketch-ink); color: white; border: 2px solid var(--sketch-border); padding: 10px 20px;">
                Save to My Planner
            </button>
        </form>
    </div>

    <div class="planner-grid">
        <div class="sketch-panel">
            <h3><i class="fa-solid fa-robot"></i> AI Study Suggestions</h3>
            <p class="planner-muted">Python-ranked free slots, learned preference scoring, and quality guidance for your planner.</p>
            <div class="planner-insight-grid" id="plannerInsights">
                <div class="planner-kpi">Loading insights...</div>
            </div>
            <div id="plannerQualityPanel" style="margin-top: 1rem;"></div>
            <div id="plannerSuggestions" style="margin-top: 1rem;">
                <div class="planner-muted">Loading suggestions...</div>
            </div>
        </div>

        <div class="sketch-panel">
            <h3><i class="fa-solid fa-graduation-cap"></i> Academic Schedule</h3>
            <p class="planner-muted">Visible separately for now so you can compare it against your personal plans without mixing both timelines.</p>
            <?php foreach ($days as $day): ?>
                <div style="margin-bottom: 1rem;">
                    <h4 style="margin-bottom: 0.5rem;"><?php echo htmlspecialchars($day); ?></h4>
                    <?php if (empty($academic_by_day[$day])): ?>
                        <p class="planner-muted" style="margin: 0;">No academic rows.</p>
                    <?php else: ?>
                        <?php foreach ($academic_by_day[$day] as $academic): ?>
                            <div class="academic-item">
                                <strong><?php echo htmlspecialchars($academic['code']); ?></strong>
                                <div><?php echo htmlspecialchars($academic['time']); ?></div>
                                <div class="planner-muted">Room: <?php echo htmlspecialchars($academic['room']); ?> | Lecturer: <?php echo htmlspecialchars($academic['lecturer']); ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem; align-items: start;">
        <?php foreach ($days as $day): ?>
        <div class="sketch-panel">
            <h4 style="border-bottom: 3px double var(--sketch-border); padding-bottom: 8px; margin-bottom: 15px;">
                <i class="fa-solid fa-calendar-day"></i> <?php echo $day; ?>
            </h4>
            <?php if (empty($events_by_day[$day])): ?>
                <p style="opacity: 0.5; font-style: italic; text-align: center; padding: 20px;">Empty slot</p>
            <?php else: ?>
                <?php foreach ($events_by_day[$day] as $ev): ?>
                <div class="event-card" style="border-left: 10px solid <?php echo $ev['color']; ?>;">
                    <?php if (!($ev['is_dashboard'] ?? false)): ?>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="delete_event">
                        <input type="hidden" name="event_id" value="<?php echo $ev['id']; ?>">
                        <button type="submit" class="delete-btn" onclick="return confirm('Delete this event?')">
                            <i class="fa-solid fa-eraser"></i>
                        </button>
                    </form>
                    <?php else: ?>
                    <span class="sketch-badge" style="position: absolute; top: 8px; right: 8px; font-size: 0.65rem; background: #a8edea;">From Dashboard</span>
                    <?php endif; ?>
                    <div style="font-weight: bold; font-size: 1.1rem;"><?php echo htmlspecialchars($ev['title']); ?></div>
                    <div style="font-size: 0.9rem; margin: 5px 0;">
                        <i class="fa-regular fa-clock"></i> 
                        <?php echo date('h:i A', strtotime($ev['start_time'])); ?> - 
                        <?php echo date('h:i A', strtotime($ev['end_time'])); ?>
                    </div>
                    <?php if ($ev['description']): ?>
                    <p style="font-size: 0.85rem; background: rgba(255,255,255,0.3); padding: 5px; border-radius: 5px;"><?php echo htmlspecialchars($ev['description']); ?></p>
                    <?php endif; ?>
                    <div class="sketch-badge" style="font-size: 0.7rem; margin-top: 8px; background: white;">
                        <?php echo strtoupper($ev['event_type']); ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
async function plannerRequest(url, options = {}) {
    const response = await fetch(url, options);
    return response.json();
}

function renderPlannerInsights(insights) {
    const container = document.getElementById('plannerInsights');
    const quality = insights.quality_prediction || null;
    container.innerHTML = `
        <div class="planner-kpi">Accept Rate<strong>${Number(insights.accept_rate || 0).toFixed(1)}%</strong></div>
        <div class="planner-kpi">Learned Preferences<strong>${insights.learned_preferences || 0}</strong></div>
        <div class="planner-kpi">Completion Rate<strong>${Number(insights.completion_rate || 0).toFixed(1)}%</strong></div>
        <div class="planner-kpi">Avg Quality<strong>${Number(insights.avg_quality_rating || 0).toFixed(2)}</strong></div>
    `;

    const qualityPanel = document.getElementById('plannerQualityPanel');
    if (!quality) {
        qualityPanel.innerHTML = `<div class="planner-muted">No predictive quality insight yet.</div>`;
        return;
    }

    qualityPanel.innerHTML = `
        <div class="academic-item">
            <strong>AI Quality Prediction: ${quality.category}</strong>
            <div class="planner-muted">Confidence ${Math.round((quality.confidence || 0) * 100)}% | Completion ${Math.round((quality.completion_probability || 0) * 100)}% | Conflict severity ${Math.round((quality.conflict_severity || 0) * 100)}%</div>
            <div style="margin-top: 0.5rem;">Best learned focus window: ${insights.best_focus_window || 'Not learned yet'}</div>
            ${(quality.optimization_suggestions || []).length ? `<ul class="sketch-list" style="margin-top: 0.5rem;">${quality.optimization_suggestions.map(item => `<li class="sketch-list-item">${item}</li>`).join('')}</ul>` : ''}
        </div>
    `;
}

function renderSuggestions(items) {
    const container = document.getElementById('plannerSuggestions');
    if (!items || !items.length) {
        container.innerHTML = `<div class="planner-muted">No suggestions available yet.</div>`;
        return;
    }

    container.innerHTML = items.map(item => `
        <div class="suggestion-card">
            <strong>${item.day} ${String(item.start_time).slice(0,5)} - ${String(item.end_time).slice(0,5)}</strong>
            <div class="planner-muted">Duration ${item.duration_minutes} mins | Learned preference ${Number(item.productivity_score || 0).toFixed(2)} | Score ${Number(item.priority_score || 0).toFixed(2)}</div>
            <p style="margin: 0.5rem 0 0 0;">${item.reason || 'Suggested free slot'}</p>
            <div class="suggestion-actions">
                <button class="suggestion-btn primary" onclick="respondToSuggestion(${item.id}, 'accept_suggestion')">Accept</button>
                <button class="suggestion-btn" onclick="respondToSuggestion(${item.id}, 'reject_suggestion')">Reject</button>
            </div>
        </div>
    `).join('');
}

async function loadPlannerIntelligence() {
    const data = await plannerRequest('api/smart_suggestions.php?action=get_suggestions&category=all');
    if (!data.success) {
        document.getElementById('plannerSuggestions').innerHTML = `<div class="planner-muted">${data.error || 'Unable to load suggestions.'}</div>`;
        return;
    }
    renderPlannerInsights(data.insights || {});
    renderSuggestions(data.suggestions || []);
}

async function respondToSuggestion(id, action) {
    const formData = new FormData();
    formData.append('action', action);
    formData.append('suggestion_id', id);
    if (action === 'reject_suggestion') {
        formData.append('reason', 'User rejected from planner');
    }

    const data = await plannerRequest('api/smart_suggestions.php', {
        method: 'POST',
        body: formData
    });

    if (!data.success) {
        alert(data.error || 'Unable to update suggestion.');
        return;
    }

    loadPlannerIntelligence();
}

document.addEventListener('DOMContentLoaded', loadPlannerIntelligence);
</script>

<?php include 'includes/footer.php'; ?>