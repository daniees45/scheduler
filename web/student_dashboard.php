<?php
$page_title = 'Student Dashboard';
include 'includes/header.php';
require_once 'api/db.php';
require_once 'includes/unified_schedule_service.php';

// Ensure only students can access
requireRole(['student']);

$user_id = $_SESSION['user_id'];
$department = $_SESSION['department'] ?? 'Unknown';
$level = $_SESSION['level'] ?? 0;

// Get today's schedule for this student
$semester = (string)($_SESSION['semester'] ?? '1');
$today = date('l');
$saved_at_selection = trim((string)($_GET['saved_at'] ?? ''));

$unified_payload = unified_schedule_fetch($conn, $_SESSION, [
    'semester' => $semester,
    'saved_at' => $saved_at_selection,
]);

$enrolled_courses = $unified_payload['enrolled_courses'] ?? [];
$available_snapshots = $unified_payload['snapshots'] ?? [];
$active_saved_at = (string)($unified_payload['saved_at'] ?? '');

$today_classes = [];
foreach (($unified_payload['rows'] ?? []) as $row) {
    $day = trim((string)($row['day'] ?? ''));
    if (strcasecmp($day, $today) !== 0) {
        continue;
    }

    $today_classes[] = [
        'Time' => (string)($row['time'] ?? 'TBA'),
        'Course Code' => (string)($row['course_code'] ?? ''),
        'Course' => (string)($row['course_title'] ?? ''),
        'Lecturer' => (string)($row['lecturer'] ?? 'TBA'),
        'Room' => (string)($row['room'] ?? 'TBA')
    ];
}

// Sort today's classes by time
usort($today_classes, function ($a, $b) {
    $time_a = isset($a['Time']) ? $a['Time'] : ($a['start_time'] ?? '00:00');
    $time_b = isset($b['Time']) ? $b['Time'] : ($b['start_time'] ?? '00:00');
    // Handle time ranges like "10:00 AM - 12:30 PM"
    $time_a = preg_split('/\s*-\s*/', $time_a)[0];
    $time_b = preg_split('/\s*-\s*/', $time_b)[0];
    return strtotime($time_a) - strtotime($time_b);
});

// Quick stats
$total_courses = count($enrolled_courses);
$today_classes_count = count($today_classes);
?>

<style>
    .welcome-section {
        background: linear-gradient(135deg, rgba(var(--primary-rgb), 0.12), rgba(var(--site-secondary-rgb), 0.12));
        border-radius: 1rem;
        padding: 2rem;
        margin-bottom: 2rem;
        border: 1px solid rgba(255, 255, 255, 0.1);
    }

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1.5rem;
        margin-bottom: 2rem;
    }

    .stat-card {
        padding: 1.5rem;
        text-align: center;
        border-left: 4px solid var(--primary);
    }

    .stat-value {
        display: block;
        font-size: 2.5rem;
        font-weight: 700;
        color: var(--primary);
        margin-bottom: 0.5rem;
    }

    .stat-label {
        display: block;
        font-size: 0.9rem;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .schedule-card {
        background: rgba(15, 23, 42, 0.6);
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 0.5rem;
        padding: 1rem;
        margin-bottom: 1rem;
        border-left: 4px solid var(--secondary);
    }

    .schedule-time {
        font-size: 1.2rem;
        font-weight: 600;
        color: var(--secondary);
        margin-bottom: 0.5rem;
    }

    .schedule-course {
        font-size: 1rem;
        font-weight: 600;
        margin-bottom: 0.25rem;
    }

    .schedule-details {
        font-size: 0.9rem;
        color: var(--text-muted);
    }

    .course-card {
        background: rgba(15, 23, 42, 0.6);
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 0.5rem;
        padding: 1rem;
        margin-bottom: 1rem;
    }

    .course-code {
        font-weight: 700;
        color: var(--primary);
        font-size: 1.1rem;
    }

    .quick-action-btn {
        display: inline-block;
        padding: 0.75rem 1.5rem;
        background: var(--primary);
        color: white;
        text-decoration: none;
        border-radius: 0.5rem;
        margin-right: 1rem;
        margin-bottom: 1rem;
        transition: all 0.3s ease;
    }

    .quick-action-btn:hover {
        background: var(--primary-hover);
        transform: translateY(-2px);
    }

    .quick-action-btn.secondary {
        background: rgba(255, 255, 255, 0.1);
    }

    .quick-action-btn.secondary:hover {
        background: rgba(255, 255, 255, 0.2);
    }
</style>

<div class="welcome-section glass-panel">
    <h2 style="margin: 0 0 0.5rem 0;">Welcome back,
        <?php echo htmlspecialchars($user_name); ?>! 👋
    </h2>
    <p style="color: var(--text-muted); margin: 0;">
        <i class="fa-solid fa-building-columns"></i>
        <?php echo htmlspecialchars($department); ?>
        &nbsp;|&nbsp;
        <i class="fa-solid fa-graduation-cap"></i> Level
        <?php echo $level; ?>
        &nbsp;|&nbsp;
        <i class="fa-solid fa-calendar"></i>
        <?php echo date('l, F j, Y'); ?>
    </p>
    <?php if ($active_saved_at !== ''): ?>
    <p style="color: var(--text-muted); margin: 0.45rem 0 0 0; font-size: 0.88rem;">
        <i class="fa-solid fa-clock"></i> Schedule snapshot time:
        <?php echo htmlspecialchars($active_saved_at); ?>
    </p>
    <?php endif; ?>
</div>

<div class="glass-panel" style="padding: 1rem; margin-bottom: 1rem;">
    <form method="GET" style="display:flex; gap:0.6rem; flex-wrap:wrap; align-items:center;">
        <label for="saved_at" style="color: var(--text-muted);">Load by saved time:</label>
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
</div>

<!-- Stats -->
<div class="stats-grid">
    <div class="glass-panel stat-card">
        <span class="stat-value">
            <?php echo $total_courses; ?>
        </span>
        <span class="stat-label">Enrolled Courses</span>
    </div>

    <div class="glass-panel stat-card" style="border-left-color: var(--secondary);">
        <span class="stat-value">
            <?php echo $today_classes_count; ?>
        </span>
        <span class="stat-label">Classes Today</span>
    </div>

    <div class="glass-panel stat-card" style="border-left-color: var(--warning);">
        <span class="stat-value">
            <?php echo date('W') - 1; ?>
        </span>
        <span class="stat-label">Week of Semester</span>
    </div>
</div>

<!-- Quick Actions -->
<div class="glass-panel" style="padding: 1.5rem; margin-bottom: 2rem;">
    <h3 style="margin-bottom: 1rem;"><i class="fa-solid fa-bolt"></i> Quick Actions</h3>
    <div>
        <a href="my_courses.php" class="quick-action-btn">
            <i class="fa-solid fa-book"></i> Enroll in Courses
        </a>
        <a href="my_schedule.php" class="quick-action-btn secondary">
            <i class="fa-solid fa-calendar-user"></i> My Personal Schedule
        </a>
        <a href="my_schedule.php#generated-weekly" class="quick-action-btn secondary">
            <i class="fa-solid fa-table-cells"></i> Weekly Timetable
        </a>
    </div>
</div>

<!-- Today's Schedule -->
<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 2rem;">
    <div class="glass-panel" style="padding: 1.5rem;">
        <h3 style="margin-bottom: 1rem;"><i class="fa-solid fa-clock"></i> Today's Classes (
            <?php echo $today; ?>)
        </h3>

        <?php if (empty($today_classes)): ?>
        <div style="text-align: center; padding: 3rem; color: var(--text-muted);">
            <i class="fa-solid fa-calendar-xmark" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
            <p>No classes scheduled for today!</p>
            <p style="font-size: 0.9rem;">Enjoy your free day 🎉</p>
        </div>
        <?php
else: ?>
        <?php foreach ($today_classes as $class): ?>
        <div class="schedule-card">
            <div class="schedule-time">
                <i class="fa-solid fa-clock"></i>
                <?php
        $time = $class['Time'] ?? $class['start_time'] ?? 'TBA';
        echo htmlspecialchars($time);
?>
            </div>
            <div class="schedule-course">
                <?php
        $course = $class['Course Code'] ?? $class['Course'] ?? 'Unknown';
        echo htmlspecialchars($course);
?>
            </div>
            <div class="schedule-details">
                <i class="fa-solid fa-chalkboard-user"></i>
                <?php
        $lecturer = $class['Lecturer Name'] ?? $class['Lecturer'] ?? 'TBA';
        echo htmlspecialchars($lecturer);
?>
                &nbsp;|&nbsp;
                <i class="fa-solid fa-door-open"></i>
                <?php
        $room = $class['Room Name'] ?? $class['Room'] ?? 'TBA';
        echo htmlspecialchars($room);
?>
            </div>
        </div>
        <?php
    endforeach; ?>
        <?php
endif; ?>
    </div>

    <!-- Enrolled Courses Summary -->
    <div class="glass-panel" style="padding: 1.5rem;">
        <h3 style="margin-bottom: 1rem;"><i class="fa-solid fa-list-check"></i> My Courses</h3>

        <?php if (empty($enrolled_courses)): ?>
        <div style="text-align: center; padding: 2rem; color: var(--text-muted);">
            <i class="fa-solid fa-book-open" style="font-size: 2rem; margin-bottom: 1rem; opacity: 0.5;"></i>
            <p style="font-size: 0.9rem;">No courses enrolled yet.</p>
            <a href="my_courses.php" class="glass-btn"
                style="margin-top: 1rem; text-decoration: none; display: inline-block;">
                Enroll Now
            </a>
        </div>
        <?php
else: ?>
        <div style="max-height: 400px; overflow-y: auto;">
            <?php foreach ($enrolled_courses as $course): ?>
            <div class="course-card">
                <div class="course-code">
                    <?php echo htmlspecialchars($course['course_code'] ?? ''); ?>
                </div>
                <div style="font-size: 0.9rem; margin-top: 0.25rem;">
                    <?php echo htmlspecialchars($course['course_title'] ?? $course['course_name'] ?? ''); ?>
                </div>
                <div style="font-size: 0.85rem; color: var(--text-muted); margin-top: 0.5rem;">
                    <i class="fa-solid fa-user"></i>
                    <?php echo htmlspecialchars($course['lecturer_name'] ?? 'TBA'); ?>
                </div>
            </div>
            <?php
    endforeach; ?>
        </div>
        <?php
endif; ?>
    </div>
</div>

<?php include 'includes/footer.php'; ?>