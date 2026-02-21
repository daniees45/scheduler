<?php
$page_title = 'View Schedule';
include 'includes/header.php';
require_once 'api/db.php';
require_once '../lib/B2Storage.php';

// Prefer schedule ID (generated_schedules table)
$schedule_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Path to the generated schedule
// Validate requested file or default
$requested_file = $_GET['file'] ?? 'csv/final/final_web_schedule.csv';

// If no explicit file is provided, prefer latest generated file from B2
if (!isset($_GET['file']) || $_GET['file'] === '' || $_GET['file'] === 'null' || $_GET['file'] === 'undefined') {
    try {
        $b2_init = new B2Storage();
        $latest = $b2_init->listFiles('csv/final/', 1);
        if (!empty($latest['success']) && !empty($latest['files'][0]['key'])) {
            $requested_file = $latest['files'][0]['key'];
        }
    } catch (Exception $e) {
        // Keep default fallback path if B2 is unavailable
    }
}

// Handle 'null' string or empty values
if ($requested_file === 'null' || $requested_file === '' || $requested_file === 'undefined') {
    $requested_file = 'csv/final/final_web_schedule.csv';
}

// Security Check: Allow only alphanumeric/dash/underscore/slash with .csv extension
if (!preg_match('/^[a-zA-Z0-9_\-\/\.]+$/', $requested_file) || pathinfo($requested_file, PATHINFO_EXTENSION) !== 'csv') {
    $requested_file = 'csv/final/final_web_schedule.csv'; // Fallback if invalid
}

// Path to the generated schedule (Parent directory only)
$csv_file = realpath('../' . $requested_file);
$base_dir = realpath('../');

// Directory traversal protection
if (!$csv_file || strpos($csv_file, $base_dir) !== 0 || !file_exists($csv_file)) {
    $csv_file = '../final_web_schedule.csv'; // Fallback if not found or unsafe
}
$data = [];

// Detect if this is an exam schedule (will be set based on CSV headers)
$is_exam_schedule = false;

// If schedule_id provided, load from database
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
            
            // Detect exam format from headers
            $headers_lower = array_map('strtolower', $headers);
            if (in_array('invigilator', $headers_lower) || in_array('invigilator name', $headers_lower)) {
                $is_exam_schedule = true;
            }
            
            // Parse data according to format
            foreach ($decoded as $row) {
                if ($is_exam_schedule) {
                    // Exam format: Course Code, Course Title, Invigilator, No of Students, Level, Cohorts, Room, Day, Time
                    $data[] = [
                        'code' => $row[0] ?? '',
                        'title' => $row[1] ?? '',
                        'lecturer' => $row[2] ?? '', // Invigilator
                        'room' => $row[6] ?? '',
                        'day' => $row[7] ?? '',
                        'time' => $row[8] ?? ''
                    ];
                } else {
                    // Regular format: Course Code, Course Title, Credit Hrs, Lecturer Name, Room Name, Day, Time
                    $data[] = [
                        'code' => $row[0] ?? '',
                        'title' => $row[1] ?? '',
                        'lecturer' => $row[3] ?? '',
                        'room' => $row[4] ?? '',
                        'day' => $row[5] ?? '',
                        'time' => $row[6] ?? ''
                    ];
                }
            }
        } elseif (!empty($json)) {
            // Fallback: schedule_data stored as CSV string (legacy)
            $lines = preg_split('/\r\n|\r|\n/', $json);
            $rows = [];
            foreach ($lines as $line) {
                if (trim($line) === '') continue;
                $rows[] = str_getcsv($line);
            }
            if (!empty($rows)) {
                $headers = array_shift($rows);
                
                // Detect exam format from headers
                $headers_lower = array_map('strtolower', $headers);
                if (in_array('invigilator', $headers_lower) || in_array('invigilator name', $headers_lower)) {
                    $is_exam_schedule = true;
                }
                
                // Parse data according to format
                foreach ($rows as $row) {
                    if ($is_exam_schedule) {
                        // Exam format: Course Code, Course Title, Invigilator, No of Students, Level, Cohorts, Room, Day, Time
                        $data[] = [
                            'code' => $row[0] ?? '',
                            'title' => $row[1] ?? '',
                            'lecturer' => $row[2] ?? '', // Invigilator
                            'room' => $row[6] ?? '',
                            'day' => $row[7] ?? '',
                            'time' => $row[8] ?? ''
                        ];
                    } else {
                        // Regular format: Course Code, Course Title, Credit Hrs, Lecturer Name, Room Name, Day, Time
                        $data[] = [
                            'code' => $row[0] ?? '',
                            'title' => $row[1] ?? '',
                            'lecturer' => $row[3] ?? '',
                            'room' => $row[4] ?? '',
                            'day' => $row[5] ?? '',
                            'time' => $row[6] ?? ''
                        ];
                    }
                }
            }
        }
    }
}

// Parse CSV from B2 (Primary) or Local (Fallback)
if (empty($data)) {
    // Try B2 first
    $b2 = new B2Storage();
    
    // Key is likely the requested_file itself e.g., csv/final/schedule.csv
    // But we need to be careful about keys.
    // If requested_file starts with csv/, use it.
    $b2_key = $requested_file;
    if (strpos($b2_key, 'csv/') !== 0) {
        $b2_key = 'csv/final/' . $requested_file;
    }
    
    $download = $b2->download($b2_key);
    $csv_content = null;
    
    if ($download['success']) {
        $csv_content = $download['content'];
    } elseif (file_exists($csv_file)) {
        // Fallback to local file
        $csv_content = file_get_contents($csv_file);
    }
    
    if ($csv_content !== null) {
        $handle = fopen('php://memory', 'r+');
        fwrite($handle, $csv_content);
        rewind($handle);
        
        $headers = fgetcsv($handle); // "Course Code","Course Title",...
        
        // Detect exam format from headers
        if ($headers) {
            $headers_lower = array_map('strtolower', $headers);
            if (in_array('invigilator', $headers_lower) || in_array('invigilator name', $headers_lower)) {
                $is_exam_schedule = true;
            }
        }
        
        // Parse data according to format
        while (($row = fgetcsv($handle, 1000, ",")) !== FALSE) {
            if ($is_exam_schedule) {
                // Exam format: Course Code, Course Title, Invigilator, No of Students, Level, Cohorts, Room, Day, Time
                $data[] = [
                    'code' => $row[0] ?? '',
                    'title' => $row[1] ?? '',
                    'lecturer' => $row[2] ?? '', // Invigilator
                    'room' => $row[6] ?? '',
                    'day' => $row[7] ?? '',
                    'time' => $row[8] ?? ''
                ];
            } else {
                // Regular format: Course Code, Course Title, Credit Hrs, Lecturer Name, Room Name, Day, Time
                $data[] = [
                    'code' => $row[0] ?? '',
                    'title' => $row[1] ?? '',
                    'lecturer' => $row[3] ?? '',
                    'room' => $row[4] ?? '',
                    'day' => $row[5] ?? '',
                    'time' => $row[6] ?? ''
                ];
            }
        }
        fclose($handle);
    }
}

// Filtering
$filter_lecturer = $_GET['lecturer'] ?? '';
$filter_room = $_GET['room'] ?? '';
$filter_day = $_GET['day'] ?? '';

// Auto-filter based on user role - ENFORCE restrictions for non-admin users
if ($user_role === 'lecturer' && !empty($_SESSION['lecturer_id'])) {
    // Lecturers: FORCE filter to show only their classes - override any GET parameters
    require_once 'api/db.php';
    $stmt = $conn->prepare("SELECT name FROM lecturers WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['lecturer_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $filter_lecturer = $row['name'];
    }
    // Clear other filters for lecturers - they can only see their own schedule
    $filter_room = '';
    $filter_day = $_GET['day'] ?? ''; // Allow day filter for lecturers
} elseif ($user_role === 'student') {
    // Students: FORCE filter to show only enrolled courses - override any GET parameters
    $enrolled_course_codes = [];
    if (!empty($_SESSION['user_id'])) {
        require_once 'api/db.php';
        $stmt = $conn->prepare("
            SELECT c.course_code
            FROM student_enrollments se
            JOIN courses c ON se.course_id = c.id
            WHERE se.user_id = ?
        ");
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $enrolled_course_codes[] = strtolower(trim($row['course_code']));
        }
    }
    // Clear all filters for students - they can only see enrolled courses
    $filter_lecturer = '';
    $filter_room = '';
    $filter_day = $_GET['day'] ?? ''; // Allow day filter for students
}

if ($filter_lecturer) {
    $data = array_filter($data, function($item) use ($filter_lecturer) {
        return stripos($item['lecturer'], $filter_lecturer) !== false;
    });
}
if ($filter_room) {
    $data = array_filter($data, function($item) use ($filter_room) {
        return stripos($item['room'], $filter_room) !== false;
    });
}
if ($filter_day) {
    $data = array_filter($data, function($item) use ($filter_day) {
        return stripos($item['day'], $filter_day) !== false;
    });
}

// Filter for students - only show enrolled courses
if ($user_role === 'student' && !empty($enrolled_course_codes)) {
    $data = array_filter($data, function($item) use ($enrolled_course_codes) {
        return in_array(strtolower(trim($item['code'])), $enrolled_course_codes);
    });
}

// Extract unique values for filters
$days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];

// Pagination
$items_per_page = 20;
$total_items = count($data);
$total_pages = ceil($total_items / $items_per_page);
$current_page = max(1, min($total_pages, (int)($_GET['page'] ?? 1)));
$offset = ($current_page - 1) * $items_per_page;

// Sort by Day then Time before slicing
$day_order = array_flip($days);
usort($data, function($a, $b) use ($day_order) {
    $da = $day_order[$a['day']] ?? 99;
    $db = $day_order[$b['day']] ?? 99;
    if ($da != $db) return $da - $db;
    return strcmp($a['time'], $b['time']);
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
    <?php endif; ?>
    
    <!-- Header & Controls -->
    <div style="display: grid; grid-template-columns: 3fr 1fr; gap: 1.5rem; margin-bottom: 2rem;">
        
        <?php if ($user_role === 'super_admin' || $user_role === 'faculty_admin'): ?>
        <!-- Filters Card (Admin Only) -->
        <div class="glass-panel" style="padding: 1.5rem;">
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 1rem; color: var(--primary-color);">
                <i class="fa-solid fa-filter"></i>
                <h3 style="margin: 0; font-size: 1.1rem;">Filter Schedule</h3>
            </div>
            
            <form method="GET" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 1rem;">
                <?php if ($schedule_id > 0): ?>
                    <input type="hidden" name="id" value="<?php echo $schedule_id; ?>">
                <?php endif; ?>
                <?php if (isset($_GET['file'])): ?>
                    <input type="hidden" name="file" value="<?php echo htmlspecialchars($_GET['file']); ?>">
                <?php endif; ?>
                <div>
                    <label class="stat-label" style="font-size: 0.8rem;"><?php echo $is_exam_schedule ? 'Invigilator' : 'Lecturer'; ?></label>
                    <div style="position: relative;">
                        <i class="fa-solid <?php echo $is_exam_schedule ? 'fa-user-shield' : 'fa-user-tie'; ?>" style="position: absolute; left: 10px; top: 12px; color: var(--text-muted); font-size: 0.9rem;"></i>
                        <input type="text" name="lecturer" class="glass-input" style="padding-left: 35px;" placeholder="Search Name..." value="<?php echo htmlspecialchars($filter_lecturer); ?>">
                    </div>
                </div>
                <div>
                    <label class="stat-label" style="font-size: 0.8rem;"><?php echo $is_exam_schedule ? 'Exam Hall' : 'Room'; ?></label>
                    <div style="position: relative;">
                        <i class="fa-solid <?php echo $is_exam_schedule ? 'fa-building' : 'fa-location-dot'; ?>" style="position: absolute; left: 10px; top: 12px; color: var(--text-muted); font-size: 0.9rem;"></i>
                        <input type="text" name="room" class="glass-input" style="padding-left: 35px;" placeholder="<?php echo $is_exam_schedule ? 'Search Hall...' : 'Search Room...'; ?>" value="<?php echo htmlspecialchars($filter_room); ?>">
                    </div>
                </div>
                <div>
                    <label class="stat-label" style="font-size: 0.8rem;">Day</label>
                    <div style="position: relative;">
                        <i class="fa-solid fa-calendar-day" style="position: absolute; left: 10px; top: 12px; color: var(--text-muted); font-size: 0.9rem;"></i>
                        <select name="day" class="glass-input" style="padding-left: 35px; background: rgba(15, 23, 42, 0.8);">
                            <option value="">All Days</option>
                            <?php foreach($days as $d): ?>
                                <option value="<?php echo $d; ?>" <?php if($filter_day == $d) echo 'selected'; ?>><?php echo $d; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div style="display: flex; align-items: flex-end;">
                    <button type="submit" class="glass-btn primary" style="width: 100%; justify-content: center;">
                        <i class="fa-solid fa-magnifying-glass"></i> Apply
                    </button>
                </div>
            </form>
        </div>
        <?php else: ?>
        <!-- Info Card for Students and Lecturers -->
        <div class="glass-panel" style="padding: 1.5rem;">
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 1rem; color: var(--primary-color);">
                <i class="fa-solid fa-info-circle"></i>
                <h3 style="margin: 0; font-size: 1.1rem;">Your Schedule</h3>
            </div>
            <div style="padding: 1rem; background: rgba(99, 102, 241, 0.12); border-radius: 8px; border: 1px solid rgba(99, 102, 241, 0.35);">
                <?php if ($user_role === 'lecturer'): ?>
                    <p style="margin: 0; color: var(--text-main); line-height: 1.6;">
                        <i class="fa-solid fa-lock" style="color: var(--warning);"></i> 
                        You are viewing only the classes assigned to you. 
                        This is your personalized teaching schedule.
                    </p>
                <?php elseif ($user_role === 'student'): ?>
                    <p style="margin: 0; color: var(--text-main); line-height: 1.6;">
                        <i class="fa-solid fa-lock" style="color: var(--warning);"></i> 
                        You are viewing only the courses you are enrolled in. 
                        This is your personalized class schedule.
                    </p>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Versions Card -->
        <div class="glass-panel" style="padding: 1.5rem; display: flex; flex-direction: column;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem; color: var(--warning);">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <i class="fa-solid fa-code-branch"></i>
                    <h3 style="margin: 0; font-size: 1.1rem;">Actions</h3>
                </div>
                <div style="display: flex; gap: 5px;">
                    <button onclick="saveVersion()" class="glass-btn small" title="Save Current State"><i class="fa-solid fa-floppy-disk"></i></button>
                    <button onclick="exportToPDF()" class="glass-btn small primary" title="Export to PDF"><i class="fa-solid fa-file-pdf"></i></button>
                    <button onclick="exportB2ToPDF()" class="glass-btn small" style="background: rgba(138, 43, 226, 0.2); border: 1px solid rgba(138, 43, 226, 0.5);" title="Export from B2 to PDF"><i class="fa-solid fa-cloud"></i> PDF</button>
                </div>
            </div>
            
            <div style="flex: 1; display: flex; flex-direction: column; justify-content: center; gap: 0.5rem;">
                <label class="stat-label" style="font-size: 0.8rem;">B2 Generated Files</label>
                <div style="display: flex; gap: 5px;">
                    <select id="b2ScheduleSelect" class="glass-input" style="padding: 8px; font-size: 0.9rem; background: rgba(0,0,0,0.3);">
                        <option value="">Loading B2 files...</option>
                    </select>
                    <button onclick="viewSelectedB2Schedule()" class="glass-btn secondary small" title="View Selected B2 Schedule">
                        <i class="fa-solid fa-eye"></i>
                    </button>
                </div>
            </div>
            <div style="flex: 1; display: flex; flex-direction: column; justify-content: center; gap: 0.5rem;">
                <label class="stat-label" style="font-size: 0.8rem;">Database Save (Manual)</label>
                <div style="display: flex; gap: 5px;">
                    <button onclick="saveSelectedB2ToDb()" class="glass-btn" style="background: linear-gradient(135deg, #10b981, #059669); width: 100%; justify-content: center;">
                        <i class="fa-solid fa-floppy-disk"></i> Save Selected B2 Schedule to DB
                    </button>
                </div>
            </div>
            <div style="flex: 1; display: flex; flex-direction: column; justify-content: center; gap: 0.5rem;">
                <label class="stat-label" style="font-size: 0.8rem;">AI Learning</label>
                <div style="display: flex; gap: 5px;">
                    <button onclick="acceptAndLearnFromSavedSchedule()" class="glass-btn" style="background: linear-gradient(135deg, #0ea5e9, #6366f1); width: 100%; justify-content: center;">
                        <i class="fa-solid fa-thumbs-up"></i> Accept &amp; Learn
                    </button>
                </div>
            </div>
            <div id="saveRecommendation" style="margin-top: 0.75rem; padding: 0.75rem; border-radius: 8px; background: rgba(99, 102, 241, 0.12); border: 1px solid rgba(99, 102, 241, 0.35); color: var(--text-main); font-size: 0.85rem;">
                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">
                    <i id="saveRecommendationIcon" class="fa-solid fa-circle-info" style="color: #818cf8;"></i>
                    <strong id="saveRecommendationTitle">Recommendation</strong>
                </div>
                <div id="saveRecommendationText" style="color: var(--text-muted); line-height: 1.4;">
                    Select a B2 schedule to see save recommendation.
                </div>
            </div>
            <!-- <div style="flex: 1; display: flex; flex-direction: column; justify-content: center; gap: 0.5rem;">
                <label class="stat-label" style="font-size: 0.8rem;">Restore Previous</label>
                <div style="display: flex; gap: 5px;">
                    <select id="versionSelect" class="glass-input" style="padding: 8px; font-size: 0.9rem; background: rgba(0,0,0,0.3);">
                        <option value="">Select Version...</option>
                    </select>
                    <button onclick="loadVersion()" class="glass-btn secondary small"><i class="fa-solid fa-rotate-left"></i></button>
                </div>
            </div> -->
        </div>
    </div>

    <!-- Main Schedule Table -->
    <div class="glass-panel" style="padding: 0; overflow: hidden; position: relative;">
        <!-- Gradient Line Top -->
        <?php if ($is_exam_schedule): ?>
        <div style="height: 3px; background: linear-gradient(90deg, #ef4444, #fb923c, #fbbf24);"></div>
        <?php else: ?>
        <div style="height: 3px; background: linear-gradient(90deg, var(--primary), var(--secondary));"></div>
        <?php endif; ?>
        
        <div class="table-container" style="max-height: 700px; overflow-y: auto;">
        <?php if (empty($data)): ?>
            <div style="text-align: center; padding: 4rem 2rem; color: var(--text-muted); display: flex; flex-direction: column; align-items: center;">
                <?php if (!file_exists($csv_file)): ?>
                    <div style="width: 80px; height: 80px; background: rgba(255,255,255,0.05); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-bottom: 1.5rem;">
                        <?php if ($is_exam_schedule): ?>
                        <i class="fa-solid fa-file-pen" style="font-size: 2.5rem; opacity: 0.5; color: #fb923c;"></i>
                        <?php else: ?>
                        <i class="fa-solid fa-calendar-xmark" style="font-size: 2.5rem; opacity: 0.5;"></i>
                        <?php endif; ?>
                    </div>
                    <?php if ($is_exam_schedule): ?>
                    <h3 style="margin-bottom: 0.5rem; color: #fbbf24;">No Exam Schedule Generated</h3>
                    <p style="margin-bottom: 2rem;">Use the AI Generator to create your exam timetable.</p>
                    <?php else: ?>
                    <h3 style="margin-bottom: 0.5rem;">No Schedule Generated</h3>
                    <p style="margin-bottom: 2rem;">Use the AI Generator to create your first schedule.</p>
                    <?php endif; ?>
                    <a href="generate.php" class="glass-btn"><i class="fa-solid fa-wand-magic-sparkles"></i> Go to Generator</a>
                <?php else: ?>
                    <div style="width: 80px; height: 80px; background: rgba(255,255,255,0.05); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-bottom: 1.5rem;">
                        <i class="fa-solid fa-filter-circle-xmark" style="font-size: 2.5rem; opacity: 0.5;"></i>
                    </div>
                    <?php if ($is_exam_schedule): ?>
                    <h3>No Exams Found</h3>
                    <?php else: ?>
                    <h3>No Classes Found</h3>
                    <?php endif; ?>
                    <p>Try adjusting your search filters.</p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <table style="width: 100%; border-collapse: collapse; font-size: 0.95rem;">
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
                <tbody>
                    <?php foreach ($paged_data as $idx => $row): 
                        $bg = $idx % 2 == 0 ? 'rgba(239, 68, 68, 0.05)' : 'rgba(251, 146, 60, 0.03)';
                    ?>
                        <tr style="background: <?php echo $bg; ?>; border-bottom: 1px solid rgba(255,255,255,0.02); transition: all 0.3s; border-left: 3px solid transparent;" 
                            onmouseover="this.style.background='rgba(251, 146, 60, 0.12)'; this.style.borderLeftColor='#fb923c';" 
                            onmouseout="this.style.background='<?php echo $bg; ?>'; this.style.borderLeftColor='transparent';">
                            <td style="padding: 1.2rem 1rem;">
                                <span style="font-weight: 700; color: #fbbf24; font-size: 1rem; display: inline-flex; align-items: center; gap: 6px;">
                                    <i class="fa-solid fa-calendar" style="font-size: 0.85rem; opacity: 0.7;"></i>
                                    <?php echo htmlspecialchars($row['day']); ?>
                                </span>
                            </td>
                            <td style="padding: 1.2rem 1rem;">
                                <div style="display: inline-flex; align-items: center; gap: 8px; background: linear-gradient(135deg, rgba(239, 68, 68, 0.2), rgba(251, 146, 60, 0.2)); padding: 8px 16px; border-radius: 20px; font-family: 'Courier New', monospace; font-size: 0.9rem; color: white; font-weight: 600; border: 1px solid rgba(251, 146, 60, 0.4);">
                                    <i class="fa-regular fa-clock" style="font-size: 1rem;"></i> 
                                    <?php echo htmlspecialchars($row['time']); ?>
                                </div>
                            </td>
                            <td style="padding: 1.2rem 1rem;">
                                <div style="font-weight: 700; color: #60a5fa; margin-bottom: 5px; font-size: 1.05rem;"><?php echo htmlspecialchars($row['code']); ?></div>
                                <div style="font-size: 0.88rem; color: var(--text-muted); line-height: 1.4;"><?php echo htmlspecialchars($row['title']); ?></div>
                            </td>
                            <td style="padding: 1.2rem 1rem;">
                                <div style="display: inline-flex; align-items: center; gap: 8px; background: rgba(16, 185, 129, 0.15); padding: 8px 14px; border-radius: 8px; border: 1px solid rgba(16, 185, 129, 0.3);">
                                    <i class="fa-solid fa-door-open" style="font-size: 1rem; color: #10b981;"></i>
                                    <span style="color: #34d399; font-weight: 600;"><?php echo htmlspecialchars($row['room']); ?></span>
                                </div>
                            </td>
                            <td style="padding: 1.2rem 1rem;">
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <div style="width: 32px; height: 32px; background: linear-gradient(135deg, #f59e0b, #ef4444); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.85rem; color: white; font-weight: 700; box-shadow: 0 2px 8px rgba(245, 158, 11, 0.4);">
                                        <?php echo strtoupper(substr($row['lecturer'], 0, 1)); ?>
                                    </div>
                                    <span style="color: var(--text-main); font-weight: 500;"><?php echo htmlspecialchars($row['lecturer']); ?></span>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                
                <?php else: ?>
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
                <tbody>
                    <?php foreach ($paged_data as $idx => $row): 
                        $bg = $idx % 2 == 0 ? 'rgba(255,255,255,0.01)' : 'transparent';
                    ?>
                        <tr style="background: <?php echo $bg; ?>; border-bottom: 1px solid rgba(255,255,255,0.02); transition: background 0.2s;">
                            <td style="padding: 1rem;">
                                <span style="font-weight: 600; color: var(--text-main);"><?php echo htmlspecialchars($row['day']); ?></span>
                            </td>
                            <td style="padding: 1rem;">
                                <div style="display: inline-flex; align-items: center; gap: 5px; background: rgba(255,255,255,0.05); padding: 4px 10px; border-radius: 20px; font-family: monospace; font-size: 0.85rem; color: var(--warning);">
                                    <i class="fa-regular fa-clock"></i> <?php echo htmlspecialchars($row['time']); ?>
                                </div>
                            </td>
                            <td style="padding: 1rem;">
                                <div style="font-weight: 700; color: var(--primary-color); margin-bottom: 3px;"><?php echo htmlspecialchars($row['code']); ?></div>
                                <div style="font-size: 0.85rem; color: var(--text-muted);"><?php echo htmlspecialchars($row['title']); ?></div>
                            </td>
                            <td style="padding: 1rem;">
                                <div style="color: #4ade80; display: flex; align-items: center; gap: 5px;">
                                    <i class="fa-solid fa-door-open" style="font-size: 0.8rem;"></i> <?php echo htmlspecialchars($row['room']); ?>
                                </div>
                            </td>
                            <td style="padding: 1rem;">
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <div style="width: 25px; height: 25px; background: linear-gradient(45deg, #4f46e5, #ec4899); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.7rem; color: white;">
                                        <?php echo substr($row['lecturer'], 0, 1); ?>
                                    </div>
                                    <span><?php echo htmlspecialchars($row['lecturer']); ?></span>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <?php endif; ?>
            </table>
            
            <!-- Pagination Controls -->
            <?php if ($total_pages > 1): ?>
            <div style="padding: 1rem; border-top: 1px solid rgba(255,255,255,0.05); display: flex; justify-content: center; align-items: center; gap: 1rem;">
                <?php 
                    $params = $_GET;
                    function build_query($p, $params) {
                        $params['page'] = $p;
                        return '?' . http_build_query($params);
                    }
                ?>
                <a href="<?php echo build_query(max(1, $current_page - 1), $params); ?>" class="glass-btn secondary small <?php if($current_page <= 1) echo 'disabled'; ?>" style="<?php if($current_page <= 1) echo 'opacity: 0.5; pointer-events: none;'; ?>">
                    <i class="fa-solid fa-chevron-left"></i>
                </a>
                <span style="font-size: 0.9rem; color: var(--text-muted);">Page <?php echo $current_page; ?> of <?php echo $total_pages; ?></span>
                <a href="<?php echo build_query(min($total_pages, $current_page + 1), $params); ?>" class="glass-btn secondary small <?php if($current_page >= $total_pages) echo 'disabled'; ?>" style="<?php if($current_page >= $total_pages) echo 'opacity: 0.5; pointer-events: none;'; ?>">
                    <i class="fa-solid fa-chevron-right"></i>
                </a>
            </div>
            <?php endif; ?>
        <?php endif; ?>
        </div>
    </div>
</div>



<script>
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
    try {
        const res = await fetch('api/list_b2_schedules.php');
        const data = await res.json();
        if (data.status === 'success') {
            select.innerHTML = '<option value="">Select B2 Schedule...</option>';
            data.schedules.forEach(s => {
                const when = s.uploaded ? new Date(s.uploaded).toLocaleString() : 'Unknown time';
                const label = `${s.name || 'Schedule'} | ${s.department || 'Dept'} | ${when}`;
                const opt = document.createElement('option');
                opt.value = s.file;
                opt.innerText = label;
                opt.dataset.name = s.name || '';
                opt.dataset.semester = s.semester || '';
                opt.dataset.department = s.department || 'General';
                opt.dataset.saved = s.saved_to_db ? '1' : '0';
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
        text.textContent = 'Select a B2 schedule to see save recommendation.';
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
        text.textContent = `${name} exists in B2 only. Recommended: review the timetable, then click “Save Selected B2 Schedule to DB” to make it available system-wide.`;
    }
}

function viewSelectedB2Schedule() {
    const select = document.getElementById('b2ScheduleSelect');
    if (!select) return;
    const file = select.value;
    if (!file) {
        customAlert('Select File', 'Please choose a B2 schedule first.', 'warning');
        return;
    }
    window.location.href = 'view_schedule.php?file=' + encodeURIComponent(file);
}

async function saveSelectedB2ToDb() {
    const select = document.getElementById('b2ScheduleSelect');
    if (!select || !select.value) {
        await customAlert('Select File', 'Please choose a B2 schedule first.', 'warning');
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
                accuracy: '',
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
    } catch (e) {
        await customAlert('Save Error', e.message || String(e), 'error');
    }
}

async function acceptAndLearnFromSavedSchedule() {
    const select = document.getElementById('b2ScheduleSelect');
    if (!select || !select.value) {
        await customAlert('Select File', 'Please choose a B2 schedule first.', 'warning');
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

    // Basic quality estimate from currently rendered rows (kept bounded)
    const renderedRows = <?php echo (int)$total_items; ?>;
    const quality = Math.max(0.5, Math.min(1.0, 0.75 + Math.min(renderedRows, 500) / 2000));

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
        formData.append('description', 'Saved from B2 file: ' + selectedFile + ' at ' + new Date().toLocaleString());
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
    const selectedFile = select.value;
    
    if (!selectedFile) {
        await customAlert("No File Selected", "Please select a B2 schedule file to export", "warning");
        return;
    }
    
    const headers = await customPDFPrompt("Export B2 Schedule to PDF");
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
    const modal = document.getElementById('globalModal');
    const icon = '<i class="fa-solid fa-spinner fa-spin" style="color: var(--primary);"></i>';
    
    document.getElementById('modalIcon').innerHTML = icon;
    document.getElementById('modalTitle').textContent = title;
    document.getElementById('modalMessage').innerHTML = message;
    document.getElementById('modalActions').innerHTML = '';
    
    modal.style.display = 'flex';
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
</script>

<?php include 'includes/footer.php'; ?>
