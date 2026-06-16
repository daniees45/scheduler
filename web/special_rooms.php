<?php
$page_title = 'Special Room Assignments';
$page_css = 'assets/special_rooms.css';
include 'includes/header.php';
require_once 'api/db.php';

// Access Control - Admin Only
requireAdmin();

// Access Control - Only admins can manage special rooms
requireRole(['super_admin', 'faculty_admin']);

// Auto-create the table if missing to avoid runtime SQL errors
if (function_exists('ensure_special_rooms_table')) {
    ensure_special_rooms_table($conn);
}

// Handle form submissions
$message = '';
$message_type = '';

// Add/Update special room assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $course_code = strtoupper(trim($_POST['course_code']));
    $room_name = trim($_POST['room_name']);
    $fixed_day = !empty($_POST['fixed_day']) ? $_POST['fixed_day'] : null;
    $fixed_time = !empty($_POST['fixed_time']) ? $_POST['fixed_time'] : null;

    if ($_POST['action'] === 'add' || $_POST['action'] === 'update') {
        $edit_id = !empty($_POST['edit_id']) ? intval($_POST['edit_id']) : null;
        
        if ($edit_id) {
            $stmt = $conn->prepare("UPDATE special_rooms SET course_code = ?, room_name = ?, fixed_day = ?, fixed_time = ? WHERE id = ?");
            $stmt->bind_param('ssssi', $course_code, $room_name, $fixed_day, $fixed_time, $edit_id);
        } else {
            $stmt = $conn->prepare("INSERT INTO special_rooms (course_code, room_name, fixed_day, fixed_time) 
                                    VALUES (?, ?, ?, ?) 
                                    ON DUPLICATE KEY UPDATE 
                                    room_name = VALUES(room_name), 
                                    fixed_day = VALUES(fixed_day), 
                                    fixed_time = VALUES(fixed_time)");
            $stmt->bind_param('ssss', $course_code, $room_name, $fixed_day, $fixed_time);
        }

        if ($stmt->execute()) {
            trigger_b2_sync();
            $message = ($edit_id || $_POST['action'] === 'update') ? 'Special room assignment updated successfully!' : 'Special room assignment added successfully!';
            $message_type = 'success';
        }
        else {
            $message = 'Error: ' . $stmt->error;
            $message_type = 'error';
        }
        $stmt->close();
    }
}

// Handle delete
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $stmt = $conn->prepare("DELETE FROM special_rooms WHERE id = ?");
    $stmt->bind_param('i', $id);
    if ($stmt->execute()) {
        trigger_b2_sync();
        $message = 'Special room assignment deleted successfully!';
        $message_type = 'success';
    }
    else {
        $message = 'Error deleting assignment!';
        $message_type = 'error';
    }
    $stmt->close();
}

// Fetch all special room assignments
$special_rooms = [];
$result = $conn->query("SELECT * FROM special_rooms ORDER BY course_code ASC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $special_rooms[] = $row;
    }
}

// Fetch available rooms for dropdown
$rooms = [];
$room_result = $conn->query("SELECT DISTINCT room_name FROM rooms ORDER BY room_name ASC");
if ($room_result) {
    while ($row = $room_result->fetch_assoc()) {
        $rooms[] = $row['room_name'];
    }
}
?>

<div class="animate-fade-in special-rooms-container">
    <div class="special-rooms-header">
        <div>
            <h2><i class="fa-solid fa-door-open" style="color: var(--primary-color);"></i> Special Room Assignments</h2>
            <p class="special-rooms-subtitle">Precision control over course venue and time constraints.</p>
        </div>
        <button onclick="showAddForm()" class="glass-btn primary" style="padding: 0.9rem 1.8rem;">
            <i class="fa-solid fa-plus-circle"></i> New Assignment
        </button>
    </div>

    <?php if ($message): ?>
    <div class="glass-panel" style="padding: 1rem 1.5rem; margin-bottom: 2rem; border-left: 4px solid <?php echo $message_type === 'success' ? '#10b981' : '#ef4444'; ?>; display: flex; align-items: center; gap: 12px; background: rgba(255,255,255,0.02);">
        <i class="fa-solid fa-<?php echo $message_type === 'success' ? 'circle-check' : 'circle-exclamation'; ?>" style="font-size: 1.2rem; color: <?php echo $message_type === 'success' ? '#10b981' : '#ef4444'; ?>;"></i>
        <span style="font-weight: 600; color: <?php echo $message_type === 'success' ? '#10b981' : '#ef4444'; ?>;"><?php echo htmlspecialchars($message); ?></span>
    </div>
    <?php endif; ?>

    <!-- Add/Edit Form -->
    <div id="addForm" class="glass-panel special-rooms-form-panel animate-fade-in">
        <h3 class="special-rooms-form-title" id="formTitle">
            <i class="fa-solid fa-wand-magic-sparkles"></i> Configure Assignment
        </h3>

        <form method="POST" class="special-rooms-form-grid">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="edit_id" id="editId" value="">

            <div class="form-group">
                <label class="special-rooms-form-label">Target Course Code</label>
                <div style="position: relative;">
                    <i class="fa-solid fa-book" style="position: absolute; left: 14px; top: 14px; color: var(--text-muted);"></i>
                    <input type="text" name="course_code" id="courseCodeInput" required class="glass-input special-rooms-form-input" style="padding-left: 42px;" placeholder="e.g. COSC 480">
                </div>
            </div>

            <div class="form-group">
                <label class="special-rooms-form-label">Designated Room</label>
                <div style="position: relative;">
                    <i class="fa-solid fa-building" style="position: absolute; left: 14px; top: 14px; color: var(--text-muted);"></i>
                    <select name="room_name" id="roomNameInput" required class="glass-input special-rooms-form-input" style="padding-left: 42px;">
                        <option value="">-- Choose Venue --</option>
                        <?php foreach ($rooms as $room): ?>
                        <option value="<?php echo htmlspecialchars($room); ?>"><?php echo htmlspecialchars($room); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label class="special-rooms-form-label">Day Constraint <span style="font-weight: normal; opacity: 0.6;">(Optional)</span></label>
                <select name="fixed_day" id="fixedDayInput" class="glass-input special-rooms-form-input">
                    <option value="">Any available day</option>
                    <option value="Monday">Monday</option>
                    <option value="Tuesday">Tuesday</option>
                    <option value="Wednesday">Wednesday</option>
                    <option value="Thursday">Thursday</option>
                    <option value="Friday">Friday</option>
                </select>
            </div>

            <div class="form-group">
                <label class="special-rooms-form-label">Time Slot Constraint <span style="font-weight: normal; opacity: 0.6;">(Optional)</span></label>
                <select name="fixed_time" id="fixedTimeInput" class="glass-input special-rooms-form-input">
                    <option value="">Any available time</option>
                    <option value="8:00am">8:00 AM</option>
                    <option value="9:00am">9:00 AM</option>
                    <option value="10:00am">10:00 AM</option>
                    <option value="11:00am">11:00 AM</option>
                    <option value="12:00pm">12:00 PM</option>
                    <option value="1:00pm">1:00 PM</option>
                    <option value="2:00pm">2:00 PM</option>
                    <option value="3:00pm">3:00 PM</option>
                    <option value="4:00pm">4:00 PM</option>
                    <option value="5:00pm">5:00 PM</option>
                </select>
            </div>

            <div class="special-rooms-form-actions">
                <button type="button" onclick="hideAddForm()" class="glass-btn secondary">Cancel</button>
                <button type="submit" class="glass-btn primary" id="submitBtn"><i class="fa-solid fa-save"></i> Save Configuration</button>
            </div>
        </form>
    </div>

    <div style="display: grid; grid-template-columns: 1fr 350px; gap: 2rem; align-items: start;">
        <div>
            <!-- Filters -->
            <div class="glass-panel special-rooms-filter-bar">
                <div class="form-group">
                    <label class="special-rooms-filter-label">Quick Search</label>
                    <input type="text" id="searchInput" class="glass-input small" style="background: rgba(0,0,0,0.2);" placeholder="Course or room..." oninput="applyFilters()">
                </div>
                <div class="form-group">
                    <label class="special-rooms-filter-label">Venue</label>
                    <select id="roomFilter" class="glass-input small" onchange="applyFilters()">
                        <option value="">All Rooms</option>
                        <?php foreach ($rooms as $room): ?>
                        <option value="<?php echo htmlspecialchars($room); ?>"><?php echo htmlspecialchars($room); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="special-rooms-filter-label">Constraint Type</label>
                    <select id="typeFilter" class="glass-input small" onchange="applyFilters()">
                        <option value="">All Types</option>
                        <option value="room_only">Room Only</option>
                        <option value="fully_locked">Fully Locked</option>
                    </select>
                </div>
                <button class="glass-btn secondary small" onclick="clearFilters()"><i class="fa-solid fa-rotate"></i></button>
            </div>

            <!-- Table -->
            <div class="special-rooms-table-wrap glass-panel">
                <table class="special-rooms-table">
                    <thead>
                        <tr class="special-rooms-thead-row">
                            <th class="special-rooms-th">Course</th>
                            <th class="special-rooms-th">Assigned Venue</th>
                            <th class="special-rooms-th">Constraints</th>
                            <th class="special-rooms-th">Type</th>
                            <th class="special-rooms-th" style="text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($special_rooms)): ?>
                        <tr>
                            <td colspan="5" style="padding: 4rem; text-align: center; color: var(--text-muted);">
                                <i class="fa-solid fa-inbox" style="font-size: 2.5rem; display: block; margin-bottom: 1rem; opacity: 0.3;"></i>
                                No special assignments found.
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($special_rooms as $assignment): 
                            $type_key = ($assignment['fixed_day'] && $assignment['fixed_time']) ? 'fully_locked' : 'room_only';
                        ?>
                        <tr class="special-rooms-data-row" 
                            data-id="<?php echo $assignment['id']; ?>"
                            data-course="<?php echo htmlspecialchars($assignment['course_code']); ?>"
                            data-room="<?php echo htmlspecialchars($assignment['room_name']); ?>"
                            data-day="<?php echo htmlspecialchars($assignment['fixed_day'] ?? ''); ?>"
                            data-time="<?php echo htmlspecialchars($assignment['fixed_time'] ?? ''); ?>"
                            data-type="<?php echo $type_key; ?>">
                            <td class="special-rooms-course-cell"><?php echo htmlspecialchars($assignment['course_code']); ?></td>
                            <td class="special-rooms-td">
                                <span style="display: flex; align-items: center; gap: 8px;">
                                    <i class="fa-solid fa-location-dot" style="color: var(--primary-color); opacity: 0.7;"></i>
                                    <?php echo htmlspecialchars($assignment['room_name']); ?>
                                </span>
                            </td>
                            <td class="special-rooms-td">
                                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                    <?php if ($assignment['fixed_day']): ?>
                                        <span class="special-rooms-day-badge"><i class="fa-solid fa-calendar-day"></i> <?php echo $assignment['fixed_day']; ?></span>
                                    <?php endif; ?>
                                    <?php if ($assignment['fixed_time']): ?>
                                        <span class="special-rooms-time-badge"><i class="fa-solid fa-clock"></i> <?php echo $assignment['fixed_time']; ?></span>
                                    <?php endif; ?>
                                    <?php if (!$assignment['fixed_day'] && !$assignment['fixed_time']): ?>
                                        <span style="font-size: 0.8rem; color: var(--text-muted); font-style: italic;">No temporal constraints</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="special-rooms-td">
                                <?php
                                    $type = 'Room Only'; $type_class = 'room-only';
                                    if ($assignment['fixed_day'] && $assignment['fixed_time']) { $type = 'Fully Locked'; $type_class = 'fully-locked'; }
                                    elseif ($assignment['fixed_time']) { $type = 'Time Fixed'; $type_class = 'time-fixed'; }
                                    elseif ($assignment['fixed_day']) { $type = 'Day Fixed'; $type_class = 'day-fixed'; }
                                ?>
                                <span class="special-rooms-type-badge special-rooms-type-badge--<?php echo $type_class; ?>"><?php echo $type; ?></span>
                            </td>
                            <td class="special-rooms-td" style="text-align: right;">
                                <div style="display: flex; gap: 8px; justify-content: flex-end;">
                                    <button onclick="editAssignment(this)" class="glass-btn secondary small" title="Edit">
                                        <i class="fa-solid fa-pen-to-square"></i>
                                    </button>
                                    <a href="?delete=<?php echo $assignment['id']; ?>" class="glass-btn secondary small" style="color: #f87171;" onclick="return confirm('Remove this assignment?')">
                                        <i class="fa-solid fa-trash-can"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Sidebar Info -->
        <div style="display: flex; flex-direction: column; gap: 1.5rem;">
            <div class="glass-panel special-rooms-info-box" style="margin: 0;">
                <h4 class="special-rooms-info-title"><i class="fa-solid fa-circle-info"></i> Assignment Logic</h4>
                <ul class="special-rooms-info-list" style="padding-left: 1rem;">
                    <li><strong>Venue Lock:</strong> Forces AI to use the specified room exclusively.</li>
                    <li><strong>Temporal Lock:</strong> Binds the course to a fixed day and time slot.</li>
                    <li><strong>Exclusive Use:</strong> Other courses cannot use a room if a special assignment is active.</li>
                </ul>
            </div>

            <div class="special-rooms-stats-grid" style="grid-template-columns: 1fr; margin: 0;">
                <div class="glass-panel special-rooms-stat-card">
                    <p class="special-rooms-stat-label">Total Active Assignments</p>
                    <h3 class="special-rooms-stat-value" style="color: var(--primary-color);"><?php echo count($special_rooms); ?></h3>
                </div>
                <div class="glass-panel special-rooms-stat-card">
                    <p class="special-rooms-stat-label">Reserved Venues</p>
                    <h3 class="special-rooms-stat-value" style="color: #34d399;"><?php echo count(array_unique(array_column($special_rooms, 'room_name'))); ?></h3>
                </div>
            </div>
        </div>
    </div>
</div>


<script>
    function showAddForm() {
        document.getElementById('formTitle').innerHTML = '<i class="fa-solid fa-wand-magic-sparkles"></i> Configure Assignment';
        document.getElementById('formAction').value = 'add';
        document.getElementById('editId').value = '';
        document.getElementById('courseCodeInput').value = '';
        document.getElementById('roomNameInput').value = '';
        document.getElementById('fixedDayInput').value = '';
        document.getElementById('fixedTimeInput').value = '';
        document.getElementById('submitBtn').innerHTML = '<i class="fa-solid fa-save"></i> Save Configuration';
        
        document.getElementById('addForm').style.display = 'block';
        document.getElementById('courseCodeInput').focus();
    }

    function editAssignment(btn) {
        const row = btn.closest('tr');
        const id = row.getAttribute('data-id');
        const course = row.getAttribute('data-course');
        const room = row.getAttribute('data-room');
        const day = row.getAttribute('data-day');
        const time = row.getAttribute('data-time');

        document.getElementById('formTitle').innerHTML = '<i class="fa-solid fa-pen-to-square"></i> Edit Assignment';
        document.getElementById('formAction').value = 'update';
        document.getElementById('editId').value = id;
        document.getElementById('courseCodeInput').value = course;
        document.getElementById('roomNameInput').value = room;
        document.getElementById('fixedDayInput').value = day;
        document.getElementById('fixedTimeInput').value = time;
        document.getElementById('submitBtn').innerHTML = '<i class="fa-solid fa-check"></i> Update Assignment';

        document.getElementById('addForm').style.display = 'block';
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function hideAddForm() {
        document.getElementById('addForm').style.display = 'none';
    }

    function applyFilters() {
        const search = document.getElementById('searchInput').value.trim().toLowerCase();
        const room = document.getElementById('roomFilter').value.toLowerCase();
        const type = document.getElementById('typeFilter').value;

        const rows = document.querySelectorAll('.special-rooms-table tbody tr');
        rows.forEach(row => {
            if (row.querySelector('td')?.getAttribute('colspan')) {
                return;
            }

            const course = (row.getAttribute('data-course') || '').toLowerCase();
            const rname = (row.getAttribute('data-room') || '').toLowerCase();
            const rtype = row.getAttribute('data-type') || '';

            const matchesSearch = !search || course.includes(search) || rname.includes(search);
            const matchesRoom = !room || rname === room;
            const matchesType = !type || rtype === type;

            row.style.display = (matchesSearch && matchesRoom && matchesType) ? '' : 'none';
        });
    }

    function clearFilters() {
        document.getElementById('searchInput').value = '';
        document.getElementById('roomFilter').value = '';
        document.getElementById('dayFilter').value = '';
        document.getElementById('typeFilter').value = '';
        applyFilters();
    }
</script>

<?php include 'includes/footer.php'; ?>