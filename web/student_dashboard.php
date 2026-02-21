<?php
$page_title = 'Student Dashboard';
include 'includes/header.php';
require_once 'api/db.php';

// Ensure only students can access
requireRole(['student']);

$user_id = $_SESSION['user_id'];
$department = $_SESSION['department'] ?? 'Unknown';
$level = $_SESSION['level'] ?? 0;

// Get student's enrolled courses
$enrolled_courses = [];
try {
    // Check if student_enrollments table exists
    $check_table = $conn->query("SHOW TABLES LIKE 'student_enrollments'");
    if ($check_table && $check_table->num_rows > 0) {
        // Table exists, proceed with query
        $stmt = $conn->prepare("
            SELECT c.*, l.name as lecturer_name,
                   se.semester, se.academic_year
            FROM student_enrollments se
            JOIN courses c ON se.course_id = c.id
            LEFT JOIN lecturers l ON c.lecturer_id = l.id
            WHERE se.user_id = ?
            ORDER BY c.course_code
        ");
        if ($stmt) {
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $enrolled_courses[] = $row;
            }
        }
    }
} catch (Exception $e) {
    // Silently fail - no enrollments available yet
}

// Get today's schedule for this student
$today = date('l'); // Monday, Tuesday, etc.
$today_classes = [];

$schedule_file = 'csv/final/final_web_schedule.csv';
if (file_exists($schedule_file)) {
    $rows = array_map('str_getcsv', file($schedule_file));
    $headers = array_shift($rows);
    
    // Clean up headers - remove quotes and trim
    $headers = array_map(function($h) {
        return trim($h, '"');
    }, $headers);
    
    foreach ($rows as $row) {
        if (count($row) < count($headers)) continue;
        $class = array_combine($headers, $row);
        
        // Extract course code from "Course Code" field or handle if no enrollments
        $course_code_field = 'Course Code';
        if (!isset($class[$course_code_field])) {
            $course_code_field = 'Course'; // fallback
        }
        
        // If we have enrolled courses, filter by enrollment
        if (count($enrolled_courses) > 0) {
            foreach ($enrolled_courses as $enrollment) {
                $class_code = isset($class[$course_code_field]) ? trim($class[$course_code_field]) : '';
                $enrolled_code = isset($enrollment['course_code']) ? trim($enrollment['course_code']) : '';
                $class_day = isset($class['Day']) ? trim($class['Day']) : '';
                
                if (!empty($class_code) && !empty($enrolled_code) && 
                    strcasecmp($class_code, $enrolled_code) === 0 &&
                    strcasecmp($class_day, $today) === 0) {
                    $today_classes[] = $class;
                    break;
                }
            }
        } else {
            // No enrollments - show all classes for the day (for testing)
            $class_day = isset($class['Day']) ? trim($class['Day']) : '';
            if (strcasecmp($class_day, $today) === 0) {
                $today_classes[] = $class;
            }
        }
    }
}

// Sort today's classes by time
usort($today_classes, function($a, $b) {
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
    background: linear-gradient(135deg, rgba(79, 70, 229, 0.1), rgba(236, 72, 153, 0.1));
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
    border-left: 4px solid var(--accent);
}

.schedule-time {
    font-size: 1.2rem;
    font-weight: 600;
    color: var(--accent);
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
    background: rgba(79, 70, 229, 0.8);
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
    <h2 style="margin: 0 0 0.5rem 0;">Welcome back, <?php echo htmlspecialchars($user_name); ?>! 👋</h2>
    <p style="color: var(--text-muted); margin: 0;">
        <i class="fa-solid fa-building-columns"></i> <?php echo htmlspecialchars($department); ?> 
        &nbsp;|&nbsp; 
        <i class="fa-solid fa-graduation-cap"></i> Level <?php echo $level; ?>
        &nbsp;|&nbsp;
        <i class="fa-solid fa-calendar"></i> <?php echo date('l, F j, Y'); ?>
    </p>
</div>

<!-- Stats -->
<div class="stats-grid">
    <div class="glass-panel stat-card">
        <span class="stat-value"><?php echo $total_courses; ?></span>
        <span class="stat-label">Enrolled Courses</span>
    </div>
    
    <div class="glass-panel stat-card" style="border-left-color: var(--accent);">
        <span class="stat-value"><?php echo $today_classes_count; ?></span>
        <span class="stat-label">Classes Today</span>
    </div>
    
    <div class="glass-panel stat-card" style="border-left-color: var(--warning);">
        <span class="stat-value"><?php echo date('W') - 1; ?></span>
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
        <h3 style="margin-bottom: 1rem;"><i class="fa-solid fa-clock"></i> Today's Classes (<?php echo $today; ?>)</h3>
        
        <?php if (empty($today_classes)): ?>
            <div style="text-align: center; padding: 3rem; color: var(--text-muted);">
                <i class="fa-solid fa-calendar-xmark" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                <p>No classes scheduled for today!</p>
                <p style="font-size: 0.9rem;">Enjoy your free day 🎉</p>
            </div>
        <?php else: ?>
            <?php foreach ($today_classes as $class): ?>
                <div class="schedule-card">
                    <div class="schedule-time">
                        <i class="fa-solid fa-clock"></i> <?php 
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
                        <i class="fa-solid fa-chalkboard-user"></i> <?php 
                            $lecturer = $class['Lecturer Name'] ?? $class['Lecturer'] ?? 'TBA';
                            echo htmlspecialchars($lecturer); 
                        ?>
                        &nbsp;|&nbsp;
                        <i class="fa-solid fa-door-open"></i> <?php 
                            $room = $class['Room Name'] ?? $class['Room'] ?? 'TBA';
                            echo htmlspecialchars($room); 
                        ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    
    <!-- Enrolled Courses Summary -->
    <div class="glass-panel" style="padding: 1.5rem;">
        <h3 style="margin-bottom: 1rem;"><i class="fa-solid fa-list-check"></i> My Courses</h3>
        
        <?php if (empty($enrolled_courses)): ?>
            <div style="text-align: center; padding: 2rem; color: var(--text-muted);">
                <i class="fa-solid fa-book-open" style="font-size: 2rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                <p style="font-size: 0.9rem;">No courses enrolled yet.</p>
                <a href="my_courses.php" class="glass-btn" style="margin-top: 1rem; text-decoration: none; display: inline-block;">
                    Enroll Now
                </a>
            </div>
        <?php else: ?>
            <div style="max-height: 400px; overflow-y: auto;">
                <?php foreach ($enrolled_courses as $course): ?>
                    <div class="course-card">
                        <div class="course-code"><?php echo htmlspecialchars($course['course_code'] ?? ''); ?></div>
                        <div style="font-size: 0.9rem; margin-top: 0.25rem;">
                            <?php echo htmlspecialchars($course['course_title'] ?? $course['course_name'] ?? ''); ?>
                        </div>
                        <div style="font-size: 0.85rem; color: var(--text-muted); margin-top: 0.5rem;">
                            <i class="fa-solid fa-user"></i> <?php echo htmlspecialchars($course['lecturer_name'] ?? 'TBA'); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
