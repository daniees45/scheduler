<?php
$page_title = 'Import Data';
include 'includes/header.php';
require_once 'api/db.php';
require_once __DIR__ . '/../lib/B2Storage.php';

// Access Control - Admin Only
requireAdmin();

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import'])) {
    $b2 = new B2Storage();
    
    // Download files from B2 to temp directory first
    $temp_dir = realpath('../') . '/temp/';
    if (!is_dir($temp_dir)) {
        mkdir($temp_dir, 0755, true);
    }
    
    // Download rooms.csv from B2
    $rooms_result = $b2->download('csv/general/rooms.csv');
    if ($rooms_result['success']) {
        file_put_contents($temp_dir . 'rooms.csv', $rooms_result['content']);
    }
    
    // Download lecturer_availability.csv from B2
    $lecturer_result = $b2->download('csv/general/lecturer_availability.csv');
    if ($lecturer_result['success']) {
        file_put_contents($temp_dir . 'lecturer_availability.csv', $lecturer_result['content']);
    }
    
    // Download departmental_courses.csv from B2
    $courses_result = $b2->download('csv/department/departmental_courses.csv');
    if ($courses_result['success']) {
        file_put_contents($temp_dir . 'departmental_courses.csv', $courses_result['content']);
    }
    
    // 1. Import Rooms
    if (($handle = fopen($temp_dir . "rooms.csv", "r")) !== FALSE) {
        $conn->query("TRUNCATE TABLE rooms"); // Reset for sync
        fgetcsv($handle); // Skip header
        $stmt = $conn->prepare("INSERT INTO rooms (room_name, capacity) VALUES (?, ?)");
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            $name = $data[0] ?? 'Unknown';
            $cap = $data[1] ?? 50;
            $stmt->bind_param("si", $name, $cap);
            try { $stmt->execute(); } catch(Exception $e){}
        }
        fclose($handle);
    }
    
    // 2. Import Lecturers (From Availability)
    if (($handle = fopen($temp_dir . "lecturer_availability.csv", "r")) !== FALSE) {
        // $conn->query("TRUNCATE TABLE lecturers"); // Don't truncate if we want to keep IDs
        fgetcsv($handle); // Skip header
        $stmt = $conn->prepare("INSERT INTO lecturers (name, availability_json) VALUES (?, ?) ON DUPLICATE KEY UPDATE availability_json = VALUES(availability_json)");
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            $name = $data[0] ?? '';
            if(!$name) continue;
            
            // Build simple availability JSON: [0, 1, 1, 1, 0] (Indices of days)
            $avail = [];
            // Mon(1), Tue(2), Wed(3), Thu(4), Fri(5) in csv indices
            if(($data[1]??0) == 1) $avail[] = 0;
            if(($data[2]??0) == 1) $avail[] = 1;
            if(($data[3]??0) == 1) $avail[] = 2;
            if(($data[4]??0) == 1) $avail[] = 3;
            if(($data[5]??0) == 1) $avail[] = 4;
            
            $json = json_encode($avail);
            $stmt->bind_param("ss", $name, $json);
            $stmt->execute();
        }
        fclose($handle);
    }
    
    // 3. Import Courses and create Sections
    if (($handle = fopen($temp_dir . "departmental_courses.csv", "r")) !== FALSE) {
        $conn->query("SET FOREIGN_KEY_CHECKS = 0");
        $conn->query("TRUNCATE TABLE sections");
        $conn->query("TRUNCATE TABLE courses");
        $conn->query("SET FOREIGN_KEY_CHECKS = 1");
        
        fgetcsv($handle);
        
        // Cache lecturers for fast mapping
        $lecturers = [];
        $res = $conn->query("SELECT id, name FROM lecturers");
        while ($lr = $res->fetch_assoc()) { $lecturers[$lr['name']] = $lr['id']; }

        // Cache rooms for optional initial mapping
        $rooms = [];
        $res = $conn->query("SELECT id, room_name FROM rooms");
        while ($rr = $res->fetch_assoc()) { $rooms[$rr['room_name']] = $rr['id']; }

        $stmt = $conn->prepare("INSERT INTO courses (course_code, course_title, semester, type, level, credit_hours) VALUES (?, ?, ?, ?, ?, ?)");
        $s_stmt = $conn->prepare("INSERT INTO sections (course_id, lecturer_id, room_id, assigned_day, assigned_time) VALUES (?, ?, ?, ?, ?)");

        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            // course_code,course_title,lecturer_name,Semester,day,start_time,end_time,room_name,source_type,course_level,credit_hours
            $code = $data[0] ?? '';
            if (!$code) continue;
            
            $title = $data[1] ?? '';
            $lecturer_name = $data[2] ?? '';
            $sem = $data[3] ?? '1';
            $assigned_day = $data[4] ?? null;
            $assigned_time = $data[5] ?? null;
            $room_name = $data[7] ?? null;
            $type = $data[8] ?? 'Departmental';
            $level = $data[9] ?? 100;
            $credits = $data[10] ?? 3;
            
            // Insert course
            $stmt->bind_param("ssssss", $code, $title, $sem, $type, $level, $credits);
            try { 
                $stmt->execute(); 
                $course_id = $conn->insert_id;
                
                // If we have a lecturer, create a section
                if ($lecturer_name && isset($lecturers[$lecturer_name])) {
                    $l_id = $lecturers[$lecturer_name];
                    $r_id = ($room_name && isset($rooms[$room_name])) ? $rooms[$room_name] : null;
                    $s_stmt->bind_param("iiiss", $course_id, $l_id, $r_id, $assigned_day, $assigned_time);
                    $s_stmt->execute();
                }
            } catch(Exception $e) {
                // Skip duplicates or errors
            }
        }
        fclose($handle);
    }
    
    // Clean up temporary files
    @unlink($temp_dir . 'rooms.csv');
    @unlink($temp_dir . 'lecturer_availability.csv');
    @unlink($temp_dir . 'departmental_courses.csv');
    
    $message = "Import Successful! Database synced with B2 storage.";
}
?>

<div class="glass-panel" style="padding: 2rem; max-width: 1200px; margin: 0 auto;">
    <h2 style="margin-bottom: 1rem; text-align: center;"><i class="fa-solid fa-file-csv"></i> Manage Data</h2>
    <p style="color: var(--text-muted); margin-bottom: 2rem; text-align: center;">
        Directly edit database records or synchronize them with CSV files.
    </p>

    <?php if ($message): ?>
        <div style="background: rgba(16, 185, 129, 0.1); border: 1px solid #10b981; color: #10b981; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem;">
            <i class="fa-solid fa-check-circle"></i> <?php echo $message; ?>
        </div>
    <?php endif; ?>

   

 

    <hr style="margin: 2rem 0; border: none; border-top: 1px solid rgba(255,255,255,0.1);">

    <div class="data-actions" style="display: flex; flex-direction: column; gap: 1.5rem;">

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
            <!-- 2. Core Scheduling Files -->
            <div style="background: rgba(255,255,255,0.03); padding: 1.5rem; border-radius: 12px; border: 1px solid rgba(255,255,255,0.05);">
                <h3 style="margin-bottom: 1rem; font-size: 1.1rem;"><i class="fa-solid fa-table"></i> Core Files</h3>
                <div class="file-grid">
                    <div class="file-item">
                        <span>Rooms</span>
                        <a href="edit_csv.php?file=csv/general/rooms.csv" class="edit-link">Edit</a>
                    </div>
                    <div class="file-item">
                        <span>Lecturer Availability</span>
                        <a href="edit_csv.php?file=csv/general/lecturer_availability.csv" class="edit-link">Edit</a>
                    </div>
                    <div class="file-item">
                        <span>Main Courses</span>
                        <a href="edit_csv.php?file=csv/department/departmental_courses.csv" class="edit-link">Edit</a>
                    </div>
                    <div class="file-item">
                        <span>Special Room Locks</span>
                        <a href="edit_csv.php?file=csv/general/special_rooms.csv" class="edit-link">Edit</a>
                    </div>
                </div>
                <h4 style="font-size: 0.85rem; margin-top: 1rem; color: var(--text-muted);">Departmental Specific Rooms</h4>
                <div class="file-grid">
                    <div class="file-item">
                        <span>CS Rooms</span>
                        <a href="edit_csv.php?file=csv/department/computing_science_rooms.csv" class="edit-link">Edit</a>
                    </div>
                    <div class="file-item">
                        <span>Nursing Rooms</span>
                        <a href="edit_csv.php?file=csv/department/nursing_rooms.csv" class="edit-link">Edit</a>
                    </div>
                    <div class="file-item">
                        <span>Theology Rooms</span>
                        <a href="edit_csv.php?file=csv/department/theology_rooms.csv" class="edit-link">Edit</a>
                    </div>
                     <div class="file-item">
                        <span>Business Rooms</span>
                        <a href="edit_csv.php?file=csv/department/business_rooms.csv" class="edit-link">Edit</a>
                    </div>
                    <div class="file-item">
                        <span>Education Rooms</span>
                        <a href="edit_csv.php?file=csv/department/education_rooms.csv" class="edit-link">Edit</a>
                    </div>
                    <div class="file-item">
                        <span>Biomed Rooms</span>
                        <a href="edit_csv.php?file=csv/department/biomedical_engineering_rooms.csv" class="edit-link">Edit</a>
                    </div>
                    <div class="file-item">
                        <span>Development Studies Rooms</span>
                        <a href="edit_csv.php?file=csv/department/development_studies_rooms.csv" class="edit-link">Edit</a>
                    </div>
                </div>
            </div>

            <!-- 3. AI Knowledge & Rules -->
            <div style="background: rgba(255,255,255,0.03); padding: 1.5rem; border-radius: 12px; border: 1px solid rgba(255,255,255,0.05);">
                <h3 style="margin-bottom: 1rem; font-size: 1.1rem;"><i class="fa-solid fa-brain"></i> AI Rules & Categorization</h3>
                <div class="file-grid">
                    <div class="file-item">
                        <span>Curriculum Mapping</span>
                        <a href="edit_csv.php?file=csv/general/curriculum.csv" class="edit-link">Edit</a>
                    </div>
                    <div class="file-item">
                        <span>Shared Course Aliases</span>
                        <a href="edit_csv.php?file=csv/general/shared_course_aliases.csv" class="edit-link">Edit</a>
                    </div>
                    <div class="file-item">
                        <span>General Prefixes</span>
                        <a href="edit_csv.php?file=csv/general/general_courses.csv" class="edit-link">Edit</a>
                    </div>
                    <div class="file-item">
                        <span>Dept Keywords</span>
                        <a href="edit_csv.php?file=csv/general/dept.csv" class="edit-link">Edit</a>
                    </div>
                    <div class="file-item">
                        <span>Shared Courses (Multi-dept)</span>
                        <a href="edit_csv.php?file=csv/general/shared_courses.csv" class="edit-link">Edit</a>
                    </div>
                    <!-- <div class="file-item">
                        <span>General Schedule Blocks</span>
                        <a href="edit_csv.php?file=csv/general/vvu_general_schedule.csv" class="edit-link">Edit</a>
                    </div> -->
                </div>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
            <!-- 4. Level Data & History -->
            <div style="background: rgba(255,255,255,0.03); padding: 1.5rem; border-radius: 12px; border: 1px solid rgba(255,255,255,0.05);">
                <h3 style="margin-bottom: 1rem; font-size: 1.1rem;"><i class="fa-solid fa-history"></i> History & Training</h3>
                <div class="file-grid">
                    <div class="file-item">
                        <span>Historical Schedules</span>
                        <a href="edit_csv.php?file=csv/general/historical_schedule.csv" class="edit-link">Edit</a>
                    </div>
                    <div class="file-item">
                        <span>User Feedback</span>
                        <a href="edit_csv.php?file=user_feedback.csv" class="edit-link">Edit</a>
                    </div>
                    <div class="file-item">
                        <span>Level 100 Data</span>
                        <a href="edit_csv.php?file=csv/general/level_100.csv" class="edit-link">Edit</a>
                    </div>
                    <div class="file-item">
                        <span>Level 200 Data</span>
                        <a href="edit_csv.php?file=csv/general/level_200.csv" class="edit-link">Edit</a>
                    </div>
                    <div class="file-item">
                        <span>Level 300 Data</span>
                        <a href="edit_csv.php?file=csv/general/level_300.csv" class="edit-link">Edit</a>
                    </div>
                    <div class="file-item">
                        <span>Level 400 Data</span>
                        <a href="edit_csv.php?file=csv/general/level_400.csv" class="edit-link">Edit</a>
                    </div>
                </div>
            </div>

            <!-- 5. System Actions -->
            <div style="background: rgba(255,255,255,0.03); padding: 1.5rem; border-radius: 12px; border: 1px solid rgba(255,255,255,0.05);">
                <h3 style="margin-bottom: 1rem; font-size: 1.1rem;"><i class="fa-solid fa-cogs"></i> System Sync</h3>
                <form method="POST" style="margin-bottom: 1rem;">
                    <button type="submit" name="import" class="glass-btn secondary small" style="width: 100%;">
                        <i class="fa-solid fa-sync"></i> Re-Sync DB from CSVs
                    </button>
                </form>
                <button onclick="runAnalysis()" id="analyzeBtn" class="glass-btn secondary small" style="width: 100%;">
                    <i class="fa-solid fa-microchip"></i> Run AI Analysis
                </button>
                <div style="margin-top: 10px; border-top: 1px solid rgba(255,255,255,0.1); padding-top: 10px;">
                    <button onclick="runValidation()" id="validateBtn" class="glass-btn small" style="width: 100%; background: rgba(16, 185, 129, 0.2); border: 1px solid rgba(16, 185, 129, 0.4);">
                        <i class="fa-solid fa-check-double"></i> Pre-flight Data Check
                    </button>
                </div>
                <div id="analysisLog" style="margin-top: 10px; font-family: monospace; font-size: 0.75rem; max-height: 120px; overflow-y: auto; background: rgba(0,0,0,0.2); border-radius: 8px; padding: 10px; display: none;"></div>
            </div>
        </div>
    </div>
</div>

<!-- Interactive Data Editor -->
<!-- <div class="glass-panel" style="padding: 2rem; max-width: 1200px; margin: 2rem auto;">
    <h2 style="margin-bottom: 1.5rem; text-align: center;"><i class="fa-solid fa-edit"></i> Quick Data Editor</h2>
    
    <!-- Tab Buttons -->
    <div style="display: flex; gap: 1rem; border-bottom: 1px solid rgba(255,255,255,0.1); margin-bottom: 1.5rem; position: relative;">
        <button class="tab-btn" onclick="switchTab('rooms')" style="padding: 1rem 1.5rem; background: none; border: none; color: #a855f7; border-bottom: 2px solid #a855f7; cursor: pointer; font-size: 1rem; position: relative; top: 2px;">
            <i class="fa-solid fa-door-open"></i> Rooms
        </button>
        <button class="tab-btn" onclick="switchTab('lecturers')" style="padding: 1rem 1.5rem; background: none; border: none; color: var(--text-muted); cursor: pointer; font-size: 1rem;">
            <i class="fa-solid fa-user-tie"></i> Lecturers
        </button>
        <button class="tab-btn" onclick="switchTab('courses')" style="padding: 1rem 1.5rem; background: none; border: none; color: var(--text-muted); cursor: pointer; font-size: 1rem;">
            <i class="fa-solid fa-book"></i> Courses
        </button>
    </div>

    <!-- Rooms Tab -->
    <div id="tab-rooms" class="tab-content" style="display: block;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
            <h3 style="font-size: 1.1rem;"><i class="fa-solid fa-door-open"></i> Manage Rooms</h3>
            <button onclick="addRoom()" class="glass-btn small">
                <i class="fa-solid fa-plus"></i> Add Room
            </button>
        </div>
        <div id="roomsList" style="display: flex; flex-direction: column; gap: 0.5rem;"></div>
    </div>

    <!-- Lecturers Tab -->
    <div id="tab-lecturers" class="tab-content" style="display: none;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
            <h3 style="font-size: 1.1rem;"><i class="fa-solid fa-user-tie"></i> Manage Lecturers</h3>
            <button onclick="addLecturer()" class="glass-btn small">
                <i class="fa-solid fa-plus"></i> Add Lecturer
            </button>
        </div>
        <div id="lecturersList" style="display: flex; flex-direction: column; gap: 0.5rem;"></div>
    </div>

    <!-- Courses Tab -->
    <div id="tab-courses" class="tab-content" style="display: none;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
            <h3 style="font-size: 1.1rem;"><i class="fa-solid fa-book"></i> Manage Courses</h3>
            <button onclick="addCourse()" class="glass-btn small">
                <i class="fa-solid fa-plus"></i> Add Course
            </button>
        </div>
        <div id="coursesList" style="display: flex; flex-direction: column; gap: 0.5rem;"></div>
    </div>
<!--</div> -->

<script>
async function runAnalysis() {
    const btn = document.getElementById('analyzeBtn');
    const log = document.getElementById('analysisLog');
    
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Analyzing...';
    log.style.display = 'block';
    log.innerHTML = 'Starting analysis...\n';

    try {
        const response = await fetch('api/run_analyzer.php');
        const result = await response.json();
        
        log.innerHTML += (result.output || result.message) + '\n';
        if (result.status === 'success') {
            log.innerHTML += 'Analysis complete successfully!';
        } else {
            log.innerHTML += 'Error: ' + result.message;
        }
    } catch (error) {
        log.innerHTML += 'Failed: ' + error.message;
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-microchip"></i> Run AI Analysis';
    }
}

async function runValidation() {
    const btn = document.getElementById('validateBtn');
    const log = document.getElementById('analysisLog');
    
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Checking...';
    log.style.display = 'block';
    log.innerHTML = 'Running Pre-flight Checks...\n';

    try {
        const response = await fetch('api/validate_data.php');
        const result = await response.json();
        
        if (result.status === 'success') {
            log.innerHTML += result.message + '\n';
            if (result.issues.length > 0) {
                log.innerHTML += '\n[CRITICAL ISSUES]\n';
                result.issues.forEach(i => log.innerHTML += '❌ ' + i + '\n');
            }
            if (result.warnings.length > 0) {
                log.innerHTML += '\n[WARNINGS]\n';
                result.warnings.forEach(w => log.innerHTML += '⚠️ ' + w + '\n');
            }
            if (result.issues.length === 0 && result.warnings.length === 0) {
                log.innerHTML += '✅ All systems go!';
            }
        } else {
            log.innerHTML += 'Error: ' + result.message;
        }
    } catch (error) {
        log.innerHTML += 'Failed: ' + error.message;
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-check-double"></i> Pre-flight Data Check';
    }
}

// ============================================================================
// INTERACTIVE DATA EDITOR FUNCTIONS
// ============================================================================

function switchTab(tab) {
    // Hide all tabs
    document.querySelectorAll('.tab-content').forEach(el => el.style.display = 'none');
    document.querySelectorAll('.tab-btn').forEach(el => {
        el.style.color = 'var(--text-muted)';
        el.style.borderBottom = 'none';
        el.style.top = '0';
    });
    
    // Show selected tab
    const tabEl = document.getElementById('tab-' + tab);
    if (tabEl) {
        tabEl.style.display = 'block';
        event.target.closest('.tab-btn').style.color = '#a855f7';
        event.target.closest('.tab-btn').style.borderBottom = '2px solid #a855f7';
        event.target.closest('.tab-btn').style.top = '2px';
    }
    
    // Load data for tab
    if (tab === 'rooms') {
        loadRooms();
    } else if (tab === 'lecturers') {
        loadLecturers();
    } else if (tab === 'courses') {
        loadCourses();
    }
}

async function loadRooms() {
    const list = document.getElementById('roomsList');
    if (!list) return;

    const sourceKey = 'csv/general/rooms.csv';
    const res = await fetch(`api/get_room_source.php?source_key=${encodeURIComponent(sourceKey)}`);
    const data = await res.json();
    list.innerHTML = '';
    
    if (data.rooms && data.rooms.length > 0) {
        data.rooms.forEach(room => {
            const item = document.createElement('div');
            item.style.display = 'grid';
            item.style.gridTemplateColumns = '1fr 1fr 100px 40px';
            item.style.gap = '0.5rem';
            item.style.alignItems = 'center';
            item.style.padding = '0.75rem';
            item.style.background = 'rgba(255,255,255,0.05)';
            item.style.borderRadius = '8px';
            item.innerHTML = `
                <input type="text" value="${room.room_name}" placeholder="Room name" class="glass-input" style="font-size: 0.9rem;" onchange="saveRoom(${room.id}, this.value)">
                <input type="number" value="${room.capacity}" placeholder="Capacity" class="glass-input" style="font-size: 0.9rem;" onchange="saveRoom(${room.id}, null, this.value)">
                <span style="color: var(--text-muted); font-size: 0.8rem;">#${room.id}</span>
                <button onclick="deleteRoom(${room.id})" class="glass-btn secondary small" style="padding: 0.5rem"><i class="fa-solid fa-trash"></i></button>
            `;
            list.appendChild(item);
        });
    }
}

async function addRoom() {
    const name = await showPrompt('Enter room name:', '', 'Add Room');
    if (!name) return;
    const cap = await showPrompt('Enter capacity:', '50', 'Room Capacity');
    if (!cap) return;
    
    const res = await fetch('api/save_data_edits.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({action: 'save_room', name, capacity: parseInt(cap), source_key: 'csv/general/rooms.csv'})
    });
    const data = await res.json();
    if (data.status === 'success') {
        loadRooms();
    }
}

async function saveRoom(id, name, cap) {
    const res = await fetch('api/save_data_edits.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({action: 'save_room', id, name, capacity: parseInt(cap), source_key: 'csv/general/rooms.csv'})
    });
    const data = await res.json();
    console.log(data);
}

async function deleteRoom(id) {
    if (!await showConfirm('Delete this room? This action cannot be undone.', 'Delete Room')) return;
    const res = await fetch('api/save_data_edits.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({action: 'delete_room', id, source_key: 'csv/general/rooms.csv'})
    });
    const data = await res.json();
    if (data.status === 'success') {
        loadRooms();
    }
}

async function loadLecturers() {
    const res = await fetch('api/db_query.php?type=lecturers');
    const data = await res.json();
    const list = document.getElementById('lecturersList');
    list.innerHTML = '';
    
    if (data.lecturers && data.lecturers.length > 0) {
        data.lecturers.forEach(lec => {
            const item = document.createElement('div');
            item.style.display = 'grid';
            item.style.gridTemplateColumns = '1fr 200px 40px';
            item.style.gap = '0.5rem';
            item.style.alignItems = 'center';
            item.style.padding = '0.75rem';
            item.style.background = 'rgba(255,255,255,0.05)';
            item.style.borderRadius = '8px';
            item.innerHTML = `
                <input type="text" value="${lec.name}" placeholder="Lecturer name" class="glass-input" style="font-size: 0.9rem;" onchange="saveLecturer(${lec.id}, this.value)">
                <span style="color: var(--text-muted); font-size: 0.8rem;">#${lec.id}</span>
                <button onclick="deleteLecturer(${lec.id})" class="glass-btn secondary small"><i class="fa-solid fa-trash"></i></button>
            `;
            list.appendChild(item);
        });
    }
}

async function addLecturer() {
    const name = await showPrompt('Enter lecturer name:', '', 'Add Lecturer');
    if (!name) return;
    
    const res = await fetch('api/save_data_edits.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({action: 'save_lecturer', name, availability: []})
    });
    const data = await res.json();
    if (data.status === 'success') {
        loadLecturers();
    }
}

async function saveLecturer(id, name) {
    await fetch('api/save_data_edits.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({action: 'save_lecturer', id, name, availability: []})
    });
}

async function deleteLecturer(id) {
    await showAlert('Lecturer deletion not implemented. Please delete from database directly.', 'Not Implemented');
}

async function loadCourses() {
    const res = await fetch('api/db_query.php?type=courses');
    const data = await res.json();
    const list = document.getElementById('coursesList');
    list.innerHTML = '';
    
    if (data.courses && data.courses.length > 0) {
        data.courses.forEach(course => {
            const item = document.createElement('div');
            item.style.display = 'grid';
            item.style.gridTemplateColumns = '150px 1fr 60px 60px 80px';
            item.style.gap = '0.5rem';
            item.style.alignItems = 'center';
            item.style.padding = '0.75rem';
            item.style.background = 'rgba(255,255,255,0.05)';
            item.style.borderRadius = '8px';
            item.innerHTML = `
                <input type="text" value="${course.course_code}" placeholder="Code" class="glass-input" style="font-size: 0.85rem;">
                <input type="text" value="${course.course_title}" placeholder="Title" class="glass-input" style="font-size: 0.85rem;">
                <select class="glass-input" style="font-size: 0.85rem;">
                    <option value="1" ${course.semester == 1 ? 'selected' : ''}>Sem 1</option>
                    <option value="2" ${course.semester == 2 ? 'selected' : ''}>Sem 2</option>
                </select>
                <input type="number" value="${course.level}" placeholder="Level" class="glass-input" style="font-size: 0.85rem;">
                <span style="color: var(--text-muted); font-size: 0.8rem;">#${course.id}</span>
            `;
            list.appendChild(item);
        });
    }
}

async function addCourse() {
    const code = await showPrompt('Enter course code:', '', 'Add Course');
    if (!code) return;
    const title = await showPrompt('Enter course title:', '', 'Course Title');
    if (!title) return;
    
    const res = await fetch('api/save_data_edits.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({action: 'save_course', course_code: code, course_title: title})
    });
    const data = await res.json();
    if (data.status === 'success') {
        loadCourses();
    }
}

// Load all data on page load
window.addEventListener('load', () => {
    loadRooms();
});</script>



<?php include 'includes/footer.php'; ?>
