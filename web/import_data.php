<?php
$page_title = 'Manage Data';
include 'includes/header.php';
require_once 'api/db.php';
require_once __DIR__ . '/../lib/B2Storage.php';

// Access Control - Admin Only
requireAdmin();

$message = '';
$error_message = '';

function download_first_available_b2_key(B2Storage $b2, array $keys): array {
    foreach ($keys as $key) {
        $result = $b2->download($key);
        if (!empty($result['success']) && isset($result['content'])) {
            return [
                'success' => true,
                'content' => (string)$result['content'],
                'key' => $key,
                'error' => null,
            ];
        }
    }
    return ['success' => false, 'content' => null, 'key' => null, 'error' => 'No matching key found'];
}

function build_availability_from_row(array $headers, array $row): array {
    $headerIndex = [];
    foreach ($headers as $idx => $header) {
        $headerIndex[strtolower(trim((string)$header))] = $idx;
    }
    $dayColumns = ['mon' => 0, 'tue' => 1, 'wed' => 2, 'thu' => 3, 'fri' => 4];
    $availability = [];
    $hasDayColumns = false;
    foreach ($dayColumns as $dayCol => $dayIndex) {
        if (isset($headerIndex[$dayCol])) {
            $hasDayColumns = true;
            $value = trim((string)($row[$headerIndex[$dayCol]] ?? '0'));
            if ($value === '1') $availability[] = $dayIndex;
        }
    }
    if ($hasDayColumns) return $availability;
    if (isset($headerIndex['availability_json'])) {
        $raw = (string)($row[$headerIndex['availability_json']] ?? '[]');
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $normalized = [];
            foreach ($decoded as $entry) {
                if (is_numeric($entry)) {
                    $day = (int)$entry;
                    if ($day >= 0 && $day <= 4) $normalized[] = $day;
                }
            }
            return array_values(array_unique($normalized));
        }
    }
    for ($index = 1; $index <= 5; $index++) {
        $value = trim((string)($row[$index] ?? '0'));
        if ($value === '1') $availability[] = $index - 1;
    }
    return $availability;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import'])) {
    $b2 = new B2Storage();
    $temp_dir = realpath('../') . '/temp/';
    if (!is_dir($temp_dir)) mkdir($temp_dir, 0755, true);
    
    $files_to_sync = [
        'rooms.csv' => ['csv/general/rooms.csv', 'rooms.csv'],
        'lecturer_availability.csv' => ['csv/general/lecturer_availability.csv', 'lecturer_availability.csv'],
        'special_rooms.csv' => ['csv/general/special_rooms.csv', 'special_rooms.csv'],
        'departmental_courses.csv' => ['csv/department/departmental_courses.csv', 'departmental_courses.csv']
    ];

    foreach($files_to_sync as $local => $keys) {
        $res = download_first_available_b2_key($b2, $keys);
        if ($res['success']) file_put_contents($temp_dir . $local, $res['content']);
    }

    $import_counts = ['rooms' => 0, 'lecturers' => 0, 'special_rooms' => 0, 'courses' => 0];
    
    // 1. Rooms
    if (($handle = fopen($temp_dir . "rooms.csv", "r")) !== FALSE) {
        $conn->query("TRUNCATE TABLE rooms");
        fgetcsv($handle);
        $stmt = $conn->prepare("INSERT INTO rooms (room_name, capacity) VALUES (?, ?)");
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            $n = $data[0] ?? 'Unknown'; $c = $data[1] ?? 50;
            $stmt->bind_param("si", $n, $c); $stmt->execute(); $import_counts['rooms']++;
        }
        fclose($handle);
    }
    // 2. Lecturers
    if (($handle = fopen($temp_dir . "lecturer_availability.csv", "r")) !== FALSE) {
        $headers = fgetcsv($handle) ?: [];
        $find_stmt = $conn->prepare("SELECT id FROM lecturers WHERE name = ? LIMIT 1");
        $update_stmt = $conn->prepare("UPDATE lecturers SET availability_json = ? WHERE id = ?");
        $insert_stmt = $conn->prepare("INSERT INTO lecturers (name, availability_json) VALUES (?, ?)");
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            $name = trim((string)($data[0] ?? '')); if(!$name) continue;
            $json = json_encode(build_availability_from_row($headers, $data));
            $find_stmt->bind_param("s", $name); $find_stmt->execute();
            $l_id = null; $find_stmt->bind_result($l_id); $found = $find_stmt->fetch(); $find_stmt->free_result();
            if ($found) { $update_stmt->bind_param("si", $json, $l_id); $update_stmt->execute(); }
            else { $insert_stmt->bind_param("ss", $name, $json); $insert_stmt->execute(); }
            $import_counts['lecturers']++;
        }
        fclose($handle);
    }
    // 3. Special Rooms
    if (($handle = fopen($temp_dir . "special_rooms.csv", "r")) !== FALSE) {
        $conn->query("TRUNCATE TABLE special_rooms");
        fgetcsv($handle);
        $stmt = $conn->prepare("INSERT INTO special_rooms (course_code, room_name, fixed_day, fixed_time) VALUES (?, ?, ?, ?)");
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            $c = trim($data[0] ?? ''); $r = trim($data[1] ?? '');
            if($c && $r) { $stmt->bind_param("ssss", $c, $r, $data[2], $data[3]); $stmt->execute(); $import_counts['special_rooms']++; }
        }
        fclose($handle);
    }
    // 4. Courses
    if (($handle = fopen($temp_dir . "departmental_courses.csv", "r")) !== FALSE) {
        $conn->query("SET FOREIGN_KEY_CHECKS = 0"); $conn->query("TRUNCATE TABLE sections"); $conn->query("TRUNCATE TABLE courses"); $conn->query("SET FOREIGN_KEY_CHECKS = 1");
        fgetcsv($handle);
        $l_map = []; $res = $conn->query("SELECT id, name FROM lecturers"); while($r = $res->fetch_assoc()) $l_map[$r['name']] = $r['id'];
        $stmt = $conn->prepare("INSERT INTO courses (course_code, course_title, semester, type, level, credit_hours) VALUES (?, ?, ?, ?, ?, ?)");
        $s_stmt = $conn->prepare("INSERT INTO sections (course_id, lecturer_id) VALUES (?, ?)");
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            $code = $data[0] ?? ''; if(!$code) continue;
            $stmt->bind_param("ssssss", $code, $data[1], $data[3], $data[8], $data[9], $data[10]);
            try { $stmt->execute(); $c_id = $conn->insert_id; $import_counts['courses']++;
                if(isset($l_map[$data[2] ?? ''])) { $s_stmt->bind_param("ii", $c_id, $l_map[$data[2]]); $s_stmt->execute(); }
            } catch(Exception $e){}
        }
        fclose($handle);
    }
    $message = "Sync Completed Successfully! Imported: " . implode(', ', array_map(function($k, $v) { return ucfirst($k).": $v"; }, array_keys($import_counts), $import_counts));
}
?>

<style>
    .data-control-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 1.5rem;
        margin-bottom: 2rem;
    }
    .resource-card {
        padding: 1.5rem;
        transition: transform 0.3s ease, box-shadow 0.3s ease;
        position: relative;
        overflow: hidden;
    }
    .resource-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 12px 40px rgba(0,0,0,0.4);
    }
    .resource-card h3 {
        font-size: 1.1rem;
        margin-bottom: 1.25rem;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        color: var(--primary-color);
    }
    .file-stack {
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
    }
    .file-entry {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.85rem 1rem;
        background: rgba(255,255,255,0.03);
        border: 1px solid rgba(255,255,255,0.05);
        border-radius: 10px;
        text-decoration: none;
        color: var(--text-main);
        transition: all 0.2s ease;
    }
    .file-entry:hover {
        background: rgba(var(--primary-rgb), 0.1);
        border-color: rgba(var(--primary-rgb), 0.3);
        padding-left: 1.25rem;
    }
    .file-entry .file-info {
        display: flex;
        flex-direction: column;
    }
    .file-entry .file-name {
        font-weight: 600;
        font-size: 0.9rem;
    }
    .file-entry .file-meta {
        font-size: 0.7rem;
        color: var(--text-muted);
    }
    .control-center {
        background: linear-gradient(135deg, rgba(var(--primary-rgb), 0.15), rgba(var(--secondary-rgb), 0.05));
        border: 1px solid rgba(255,255,255,0.1);
        padding: 2.5rem;
        border-radius: 20px;
        margin-bottom: 2.5rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 2rem;
    }
    @media (max-width: 900px) {
        .control-center { flex-direction: column; text-align: center; padding: 2rem; }
        .control-actions { width: 100%; }
    }
    .tab-system {
        background: rgba(15, 23, 42, 0.4);
        border-radius: 16px;
        padding: 1.5rem;
        margin-top: 2rem;
    }
    .tab-pill-box {
        display: flex;
        gap: 0.75rem;
        background: rgba(0,0,0,0.2);
        padding: 0.4rem;
        border-radius: 12px;
        margin-bottom: 1.5rem;
    }
    .tab-pill {
        flex: 1;
        padding: 0.85rem;
        border: none;
        background: none;
        color: var(--text-muted);
        border-radius: 10px;
        cursor: pointer;
        font-weight: 600;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.6rem;
    }
    .tab-pill.active {
        background: var(--primary-color);
        color: white;
        box-shadow: 0 4px 15px rgba(var(--primary-rgb), 0.4);
    }
    .editor-grid {
        width: 100%;
        overflow-x: auto;
    }
    .editor-table {
        width: 100%;
        border-collapse: collapse;
        background: rgba(255,255,255,0.02);
        border-radius: 12px;
        overflow: hidden;
    }
    .editor-table thead {
        background: rgba(255,255,255,0.08);
        border-bottom: 2px solid rgba(255,255,255,0.1);
    }
    .editor-table th {
        padding: 1rem;
        text-align: left;
        font-weight: 600;
        font-size: 0.95rem;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: 0.5px;
        border-right: 1px solid rgba(255,255,255,0.05);
    }
    .editor-table th:last-child {
        border-right: none;
    }
    .editor-table tbody tr {
        border-bottom: 1px solid rgba(255,255,255,0.05);
        transition: background-color 0.2s;
    }
    .editor-table tbody tr:hover {
        background: rgba(255,255,255,0.05);
    }
    .editor-table td {
        padding: 1rem;
        color: var(--text-main);
        border-right: 1px solid rgba(255,255,255,0.05);
    }
    .editor-table td:last-child {
        border-right: none;
    }
    .editor-table input.glass-input,
    .editor-table select.glass-input {
        width: 100%;
        padding: 0.5rem;
        background: rgba(255,255,255,0.08);
        border: 1px solid rgba(255,255,255,0.1);
        border-radius: 6px;
        color: var(--text-main);
        font-size: 0.9rem;
    }
    .editor-table input.glass-input:focus,
    .editor-table select.glass-input:focus {
        outline: none;
        border-color: var(--primary-color);
        background: rgba(255,255,255,0.12);
    }
    .editor-item {
        background: rgba(255,255,255,0.03);
        border: 1px solid rgba(255,255,255,0.05);
        border-radius: 12px;
        padding: 1.25rem;
        transition: border-color 0.2s;
    }
    .editor-item:focus-within {
        border-color: var(--primary-color);
    }
</style>

<div class="animate-fade-in">
    <div class="header-bar">
        <div class="page-title">
            <h1><i class="fa-solid fa-database"></i> Resource Manager</h1>
            <p style="color: var(--text-muted);">Sync cloud masters, manage curriculum rules, and edit core records.</p>
        </div>
    </div>

    <?php if ($message || $error_message): ?>
        <div class="glass-panel" style="padding: 1.25rem; margin-bottom: 2rem; border-left: 4px solid <?php echo $error_message ? 'var(--danger)' : 'var(--success)'; ?>; background: rgba(0,0,0,0.2);">
            <p style="color: <?php echo $error_message ? '#fecaca' : '#10b981'; ?>; font-weight: 500;">
                <i class="fa-solid <?php echo $error_message ? 'fa-triangle-exclamation' : 'fa-check-circle'; ?> mr-2"></i> 
                <?php echo $error_message ?: $message; ?>
            </p>
        </div>
    <?php endif; ?>

    <!-- Control Center -->
    <div class="control-center glass-panel">
        <div style="flex: 1;">
            <h2 style="margin-bottom: 0.5rem;">Data Synchronization</h2>
            <p style="color: var(--text-muted); font-size: 0.95rem;">Bridge your local database with the global Cloud master files. Use Pre-flight to check for inconsistencies before syncing.</p>
        </div>
        <div class="control-actions" style="display: flex; gap: 1rem;">
            <form method="POST">
                <button type="submit" name="import" class="glass-btn" style="white-space: nowrap;">
                    <i class="fa-solid fa-cloud-arrow-down"></i> Sync Master CSVs
                </button>
            </form>
            <button onclick="runValidation()" id="validateBtn" class="glass-btn secondary">
                <i class="fa-solid fa-stethoscope"></i> Pre-flight Check
            </button>
        </div>
    </div>

    <div id="analysisLog" style="margin-bottom: 2rem; font-family: 'JetBrains Mono', monospace; font-size: 0.8rem; background: #000; padding: 1.5rem; border-radius: 12px; display: none; max-height: 250px; overflow-y: auto; border: 1px solid var(--border-color); color: #10b981;"></div>

    <div class="data-control-grid">
        <!-- Core Data -->
        <div class="glass-panel resource-card">
            <h3><i class="fa-solid fa-microchip"></i> Academic Core</h3>
            <div class="file-stack">
                <a href="edit_csv.php?file=csv/general/rooms.csv" class="file-entry">
                    <div class="file-info"><span class="file-name">Main Rooms Pool</span><span class="file-meta">Master facility definitions</span></div>
                    <i class="fa-solid fa-edit"></i>
                </a>
                <a href="edit_csv.php?file=csv/general/lecturer_availability.csv" class="file-entry">
                    <div class="file-info"><span class="file-name">Lecturer Availability</span><span class="file-meta">Time constraints for faculty</span></div>
                    <i class="fa-solid fa-edit"></i>
                </a>
                <a href="edit_csv.php?file=csv/department/departmental_courses.csv" class="file-entry">
                    <div class="file-info"><span class="file-name">Departmental Courses</span><span class="file-meta">Course-lecturer mappings</span></div>
                    <i class="fa-solid fa-edit"></i>
                </a>
            </div>
        </div>

        <!-- AI Knowledge -->
        <div class="glass-panel resource-card">
            <h3><i class="fa-solid fa-brain"></i> AI Logic Rules</h3>
            <div class="file-stack">
                <a href="edit_csv.php?file=csv/general/curriculum.csv" class="file-entry">
                    <div class="file-info"><span class="file-name">Curriculum Map</span><span class="file-meta">Prerequisites & Levels</span></div>
                    <i class="fa-solid fa-edit"></i>
                </a>
                <a href="edit_csv.php?file=csv/general/shared_course_aliases.csv" class="file-entry">
                    <div class="file-info"><span class="file-name">Course Aliases</span><span class="file-meta">Naming normalization</span></div>
                    <i class="fa-solid fa-edit"></i>
                </a>
                <a href="edit_csv.php?file=csv/general/dept.csv" class="file-entry">
                    <div class="file-info"><span class="file-name">Dept Logic</span><span class="file-meta">Keywords & Mapping</span></div>
                    <i class="fa-solid fa-edit"></i>
                </a>
            </div>
        </div>

        <!-- History -->
        <div class="glass-panel resource-card">
            <h3><i class="fa-solid fa-history"></i> Performance History</h3>
            <div class="file-stack">
                <a href="edit_csv.php?file=csv/general/historical_schedule.csv" class="file-entry">
                    <div class="file-info"><span class="file-name">Historical Data</span><span class="file-meta">Past successful patterns</span></div>
                    <i class="fa-solid fa-edit"></i>
                </a>
                <a href="user_feedback.csv" class="file-entry">
                    <div class="file-info"><span class="file-name">Model Feedback</span><span class="file-meta">User correction logs</span></div>
                    <i class="fa-solid fa-edit"></i>
                </a>
                <a href="edit_csv.php?file=csv/general/level_100.csv" class="file-entry">
                    <div class="file-info"><span class="file-name">Constraint Learning</span><span class="file-meta">Level-specific rules</span></div>
                    <i class="fa-solid fa-edit"></i>
                </a>
            </div>
        </div>

        <!-- Room Pools -->
        <div class="glass-panel resource-card" style="grid-column: 1 / -1;">
            <h3><i class="fa-solid fa-door-open"></i> Departmental Specialized Pools</h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 1rem;">
                <a href="edit_csv.php?file=csv/department/computing_science_rooms.csv" class="file-entry"><span>Computing Sci</span><i class="fa-solid fa-chevron-right"></i></a>
                <a href="edit_csv.php?file=csv/department/nursing_rooms.csv" class="file-entry"><span>Nursing</span><i class="fa-solid fa-chevron-right"></i></a>
                <a href="edit_csv.php?file=csv/department/theology_rooms.csv" class="file-entry"><span>Theology</span><i class="fa-solid fa-chevron-right"></i></a>
                <a href="edit_csv.php?file=csv/department/business_rooms.csv" class="file-entry"><span>Business</span><i class="fa-solid fa-chevron-right"></i></a>
                <a href="edit_csv.php?file=csv/department/education_rooms.csv" class="file-entry"><span>Education</span><i class="fa-solid fa-chevron-right"></i></a>
            </div>
        </div>
    </div>

    <!-- Interactive Quick Editor -->
    <div class="tab-system glass-panel">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
            <div>
                <h2 style="margin-bottom: 0.3rem;"><i class="fa-solid fa-bolt"></i> Database Quick Editor</h2>
                <p style="color: var(--text-muted); font-size: 0.9rem;">Modify active database records without affecting CSV masters.</p>
            </div>
            <button onclick="runAnalysis()" id="analyzeBtn" class="glass-btn secondary small">
                <i class="fa-solid fa-chart-line"></i> Run Analysis
            </button>
        </div>

        <div class="tab-pill-box">
            <button class="tab-pill active" onclick="switchTab('rooms', this)"><i class="fa-solid fa-door-open"></i> Rooms</button>
            <button class="tab-pill" onclick="switchTab('lecturers', this)"><i class="fa-solid fa-user-tie"></i> Lecturers</button>
            <button class="tab-pill" onclick="switchTab('courses', this)"><i class="fa-solid fa-book"></i> Courses</button>
        </div>

        <div id="tab-rooms" class="tab-content" style="display: block;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; padding: 0 0.5rem;">
                <h3 style="margin: 0; font-size: 1rem;">Managed Rooms</h3>
                <button onclick="addRoom()" class="glass-btn small primary"><i class="fa-solid fa-plus"></i> New Room</button>
            </div>
            <div id="roomsList" class="editor-grid"></div>
        </div>

        <div id="tab-lecturers" class="tab-content" style="display: none;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; padding: 0 0.5rem;">
                <h3 style="margin: 0; font-size: 1rem;">Faculty Registry</h3>
                <button onclick="addLecturer()" class="glass-btn small primary"><i class="fa-solid fa-user-plus"></i> New Faculty</button>
            </div>
            <div id="lecturersList" class="editor-grid"></div>
        </div>

        <div id="tab-courses" class="tab-content" style="display: none;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; padding: 0 0.5rem;">
                <h3 style="margin: 0; font-size: 1rem;">Course Catalog</h3>
                <button onclick="addCourse()" class="glass-btn small primary"><i class="fa-solid fa-book-medical"></i> New Course</button>
            </div>
            <div id="coursesList" class="editor-grid"></div>
        </div>
    </div>
</div>

<script>
    function switchTab(tab, btn) {
        document.querySelectorAll('.tab-content').forEach(el => el.style.display = 'none');
        document.querySelectorAll('.tab-pill').forEach(el => el.classList.remove('active'));
        document.getElementById('tab-' + tab).style.display = 'block';
        btn.classList.add('active');
        if (tab === 'rooms') loadRooms();
        if (tab === 'lecturers') loadLecturers();
        if (tab === 'courses') loadCourses();
    }

    async function loadRooms() {
        const list = document.getElementById('roomsList');
        const res = await fetch('api/get_room_source.php?source_key=csv/general/rooms.csv');
        const data = await res.json();
        list.innerHTML = '';
        if (data.rooms && data.rooms.length > 0) {
            let table = `
                <table class="editor-table">
                    <thead>
                        <tr>
                            <th><i class="fa-solid fa-door-open"></i> Room Name</th>
                            <th style="width: 120px;">Capacity</th>
                            <th style="width: 80px;">ID</th>
                            <th style="width: 60px; text-align: center;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
            `;
            data.rooms.forEach(room => {
                table += `
                    <tr>
                        <td><input type="text" value="${room.room_name || ''}" class="glass-input" onchange="saveRoom(${room.id}, this.value, null)"></td>
                        <td><input type="number" value="${room.capacity || 0}" class="glass-input" onchange="saveRoom(${room.id}, null, this.value)"></td>
                        <td style="font-size: 0.85rem; color: var(--text-muted);">#${room.id}</td>
                        <td style="text-align: center;"><button onclick="deleteRoom(${room.id})" style="background: none; border: none; color: var(--danger); cursor: pointer; padding: 5px;" title="Delete"><i class="fa-solid fa-trash-alt"></i></button></td>
                    </tr>
                `;
            });
            table += `
                    </tbody>
                </table>
            `;
            list.innerHTML = table;
        } else {
            list.innerHTML = '<p style="color: var(--text-muted); padding: 2rem; text-align: center;">No rooms found. Click "New Room" to add one.</p>';
        }
    }

    async function loadLecturers() {
        const res = await fetch('api/db_query.php?type=lecturers');
        const data = await res.json();
        const list = document.getElementById('lecturersList');
        list.innerHTML = '';
        if (data.lecturers && data.lecturers.length > 0) {
            let table = `
                <table class="editor-table">
                    <thead>
                        <tr>
                            <th><i class="fa-solid fa-user-tie"></i> Lecturer Name</th>
                            <th style="width: 80px;">ID</th>
                            <th style="width: 60px; text-align: center;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
            `;
            data.lecturers.forEach(lec => {
                table += `
                    <tr>
                        <td><input type="text" value="${lec.name || ''}" class="glass-input" onchange="saveLecturer(${lec.id}, this.value)"></td>
                        <td style="font-size: 0.85rem; color: var(--text-muted);">#${lec.id}</td>
                        <td style="text-align: center;"><button onclick="deleteLecturer(${lec.id})" style="background: none; border: none; color: var(--danger); cursor: pointer; padding: 5px;" title="Delete"><i class="fa-solid fa-trash"></i></button></td>
                    </tr>
                `;
            });
            table += `
                    </tbody>
                </table>
            `;
            list.innerHTML = table;
        } else {
            list.innerHTML = '<p style="color: var(--text-muted); padding: 2rem; text-align: center;">No lecturers found. Click "New Faculty" to add one.</p>';
        }
    }

    async function loadCourses() {
        const res = await fetch('api/db_query.php?type=courses');
        const data = await res.json();
        const list = document.getElementById('coursesList');
        list.innerHTML = '';
        if (data.courses && data.courses.length > 0) {
            let table = `
                <table class="editor-table">
                    <thead>
                        <tr>
                            <th style="width: 100px;"><i class="fa-solid fa-book"></i> Code</th>
                            <th style="flex: 1;">Course Title</th>
                            <th style="width: 100px;">Semester</th>
                            <th style="width: 70px;">Level</th>
                            <th style="width: 80px;">Credits</th>
                          
                            <th style="width: 60px; text-align: center;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
            `;
            data.courses.forEach(c => {
                table += `
                    <tr>
                        <td><input type="text" value="${c.course_code || ''}" class="glass-input" style="font-weight: 700;" onchange="saveCourse(${c.id}, null, this.value)"></td>
                        <td><input type="text" value="${c.course_title || ''}" class="glass-input" onchange="saveCourse(${c.id}, this.value)"></td>
                        <td>
                            <select class="glass-input" onchange="saveCourse(${c.id}, null, null, this.value)">
                                <option value="1" ${c.semester == '1' ? 'selected' : ''}>Sem 1</option>
                                <option value="2" ${c.semester == '2' ? 'selected' : ''}>Sem 2</option>
                            </select>
                        </td>
                        <td><input type="number" value="${c.level || 1}" class="glass-input" onchange="saveCourse(${c.id}, null, null, null, this.value)"></td>
                        <td><input type="number" value="${c.credit_hours || 0}" class="glass-input" onchange="saveCourse(${c.id}, null, null, null, null, this.value)"></td>
                       @
                        <td style="text-align: center;"><button onclick="deleteCourse(${c.id})" style="background: none; border: none; color: var(--danger); cursor: pointer; padding: 5px;" title="Delete"><i class="fa-solid fa-trash"></i></button></td>
                    </tr>
                `;
            });
            table += `
                    </tbody>
                </table>
            `;
            list.innerHTML = table;
        } else {
            list.innerHTML = '<p style="color: var(--text-muted); padding: 2rem; text-align: center;">No courses found. Click "New Course" to add one.</p>';
        }
    }

    async function saveRoom(id, name, cap) {
        await fetch('api/save_data_edits.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ action: 'save_room', id, name, capacity: cap, source_key: 'csv/general/rooms.csv' })
        });
    }

    async function saveLecturer(id, name) {
        await fetch('api/save_data_edits.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ action: 'save_lecturer', id, name })
        });
    }

    async function saveCourse(id, title, code, semester, level, credits) {
        // Collect current values from the input fields - with table row context
        const table = event.target.closest('.editor-table');
        const row = event.target.closest('tr');
        if (!table || !row) {
            console.error('Could not find table or row context');
            return;
        }
        
        const inputs = row.querySelectorAll('input[type="text"], input[type="number"], select');
        const finalCode = code !== null ? code : inputs[0].value;
        const finalTitle = title !== null ? title : inputs[1].value;
        const finalSemester = semester !== null ? semester : row.querySelector('select').value;
        const finalLevel = level !== null ? level : inputs[2].value;
        const finalCredits = credits !== null ? credits : inputs[3].value;

        await fetch('api/save_data_edits.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ 
                action: 'save_course', 
                id, 
                course_title: finalTitle,
                course_code: finalCode,
                semester: finalSemester,
                level: finalLevel,
                credits: finalCredits
            })
        });
    }

    async function runValidation() {
        const log = document.getElementById('analysisLog');
        log.style.display = 'block';
        log.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Initializing Pre-flight checks...';
        try {
            const res = await fetch('api/validate_data.php');
            const data = await res.json();
            if(data.status === 'success') {
                log.innerHTML = `<span style="color: #10b981; font-weight: 700;">[PASS] HEALTH CHECK COMPLETED</span><br>${data.message}<br>`;
                if(data.issues.length) log.innerHTML += `<br><span style="color: var(--danger);">CRITICAL ISSUES:</span><br>${data.issues.map(i => '❌ '+i).join('<br>')}`;
                if(data.warnings.length) log.innerHTML += `<br><span style="color: var(--warning);">OPTIMIZATION WARNINGS:</span><br>${data.warnings.map(w => '⚠️ '+w).join('<br>')}`;
                if(!data.issues.length && !data.warnings.length) log.innerHTML += `<br><span style="color: #10b981;">✅ All validation rules passed. System is ready for generation.</span>`;
            }
        } catch(e) { log.innerHTML = `<span style="color: var(--danger);">[FAIL] Validation Service Unreachable</span>`; }
    }

    async function runAnalysis() {
        const btn = document.getElementById('analyzeBtn');
        const log = document.getElementById('analysisLog');
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Analyzing...';
        log.style.display = 'block';
        log.innerHTML = 'Starting Deep AI Analysis of curriculum dependencies...\n';
        try {
            const response = await fetch('api/run_analyzer.php', { method: 'POST' });
            const result = await response.json();
            log.innerHTML += (result.output || result.message) + '\n';
        } catch (error) { log.innerHTML += 'Failed: ' + error.message; }
        finally { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-chart-line"></i> Run Analysis'; }
    }

    // --- Deletion Functions ---
    async function deleteRoom(id) {
        if (!confirm('Are you sure you want to delete this room? This will also remove it from B2 master files.')) return;
        const res = await fetch('api/save_data_edits.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ action: 'delete_room', id, source_key: 'csv/general/rooms.csv' })
        });
        const data = await res.json();
        if (data.status === 'success') loadRooms();
    }

    async function deleteLecturer(id) {
        if (!confirm('Delete this lecturer?')) return;
        // Backend for delete_lecturer needs to be handled in save_data_edits.php if not already there
        // For now, using save_data_edits with a delete action if supported
        await fetch('api/save_data_edits.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ action: 'delete_lecturer', id })
        });
        loadLecturers();
    }

    async function deleteCourse(id) {
        if (!confirm('Delete this course?')) return;
        await fetch('api/save_data_edits.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ action: 'delete_course', id })
        });
        loadCourses();
    }

    // --- Addition Functions ---
    async function addRoom() {
        const name = prompt('Enter room name:');
        if (!name) return;
        const cap = prompt('Enter capacity:', '50');
        if (!cap) return;
        const res = await fetch('api/save_data_edits.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ action: 'save_room', name, capacity: parseInt(cap), source_key: 'csv/general/rooms.csv' })
        });
        const data = await res.json();
        if (data.status === 'success') loadRooms();
    }

    async function addLecturer() {
        const name = prompt('Enter lecturer name:');
        if (!name) return;
        await fetch('api/save_data_edits.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ action: 'save_lecturer', name })
        });
        loadLecturers();
    }

    async function addCourse() {
        const code = prompt('Enter course code:');
        if (!code) return;
        const title = prompt('Enter course title:');
        if (!title) return;
        await fetch('api/save_data_edits.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ action: 'save_course', course_code: code, course_title: title })
        });
        loadCourses();
    }

    // Initialize
    loadRooms();
</script>