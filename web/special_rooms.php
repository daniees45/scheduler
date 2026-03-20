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
        $stmt = $conn->prepare("INSERT INTO special_rooms (course_code, room_name, fixed_day, fixed_time) 
                                VALUES (?, ?, ?, ?) 
                                ON DUPLICATE KEY UPDATE 
                                room_name = VALUES(room_name), 
                                fixed_day = VALUES(fixed_day), 
                                fixed_time = VALUES(fixed_time)");
        $stmt->bind_param('ssss', $course_code, $room_name, $fixed_day, $fixed_time);

        if ($stmt->execute()) {
            trigger_b2_sync();
            $message = $_POST['action'] === 'add' ? 'Special room assignment added successfully!' : 'Special room assignment updated successfully!';
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

<div class="glass-panel special-rooms-container">
    <div class="special-rooms-header">
        <div>
            <h2><i class="fa-solid fa-door-open"></i> Special Room Assignments</h2>
            <p class="special-rooms-subtitle">Pre-assign specific courses to specific rooms with optional time/day
                constraints</p>
        </div>
        <button onclick="showAddForm()" class="glass-btn primary">
            <i class="fa-solid fa-plus"></i> Add Assignment
        </button>
    </div>

    <?php if ($message): ?>
    <div class="alert special-rooms-alert <?php echo $message_type === 'success' ? 'special-rooms-alert--success' : 'special-rooms-alert--error'; ?>">
        <i class="fa-solid fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
        <?php echo htmlspecialchars($message); ?>
    </div>
    <?php
endif; ?>

    <!-- Add/Edit Form (Hidden by default) -->
    <div id="addForm" class="glass-panel special-rooms-form-panel">
        <h3 class="special-rooms-form-title">
            <i class="fa-solid fa-plus-circle"></i> Add Special Room Assignment
        </h3>

        <form method="POST" class="special-rooms-form-grid">
            <input type="hidden" name="action" value="add">

            <div class="form-group">
                <label class="special-rooms-form-label">Course Code *</label>
                <input type="text" name="course_code" required class="glass-input special-rooms-form-input" placeholder="e.g., PEAC 100">
            </div>

            <div class="form-group">
                <label class="special-rooms-form-label">Room Name *</label>
                <select name="room_name" required class="glass-input special-rooms-form-input">
                    <option value="">-- Select Room --</option>
                    <?php foreach ($rooms as $room): ?>
                    <option value="<?php echo htmlspecialchars($room); ?>">
                        <?php echo htmlspecialchars($room); ?>
                    </option>
                    <?php
endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="special-rooms-form-label">Fixed Day (Optional)</label>
                <select name="fixed_day" class="glass-input special-rooms-form-input">
                    <option value="">-- Any Day --</option>
                    <option value="Monday">Monday</option>
                    <option value="Tuesday">Tuesday</option>
                    <option value="Wednesday">Wednesday</option>
                    <option value="Thursday">Thursday</option>
                    <option value="Friday">Friday</option>
                </select>
                <small class="special-rooms-field-hint">Leave blank to allow any day</small>
            </div>

            <div class="form-group">
                <label class="special-rooms-form-label">Fixed Time (Optional)</label>
                <select name="fixed_time" class="glass-input special-rooms-form-input">
                    <option value="">-- Any Time --</option>
                    <option value="8:00am">8:00am</option>
                    <option value="9:00am">9:00am</option>
                    <option value="10:00am">10:00am</option>
                    <option value="11:00am">11:00am</option>
                    <option value="12:00pm">12:00pm</option>
                    <option value="1:00pm">1:00pm</option>
                    <option value="2:00pm">2:00pm</option>
                    <option value="3:00pm">3:00pm</option>
                    <option value="4:00pm">4:00pm</option>
                    <option value="5:00pm">5:00pm</option>
                </select>
                <small class="special-rooms-field-hint">Leave blank to allow any time slot</small>
            </div>

            <div class="special-rooms-form-actions">
                <button type="button" onclick="hideAddForm()" class="glass-btn secondary">
                    <i class="fa-solid fa-times"></i> Cancel
                </button>
                <button type="submit" class="glass-btn primary">
                    <i class="fa-solid fa-save"></i> Save Assignment
                </button>
            </div>
        </form>
    </div>

    <!-- Info Box -->
    <div class="glass-panel special-rooms-info-box">
        <h4 class="special-rooms-info-title">
            <i class="fa-solid fa-info-circle"></i> How it works
        </h4>
        <ul class="special-rooms-info-list">
            <li><strong>Room Only:</strong> Course must use this specific room, but any day/time is allowed</li>
            <li><strong>Room + Time:</strong> Course must use this room at this specific time on any day</li>
            <li><strong>Room + Day + Time:</strong> Course is locked to this exact room, day, and time slot</li>
            <li><strong>Reserved Rooms:</strong> Rooms assigned here cannot be used by other courses</li>
        </ul>
    </div>

    <div class="glass-panel special-rooms-filter-bar">
        <div class="form-group">
            <label class="special-rooms-filter-label">Search</label>
            <input type="text" id="searchInput" class="glass-input" placeholder="Course or room..."
                oninput="applyFilters()">
        </div>
        <div class="form-group">
            <label class="special-rooms-filter-label">Room</label>
            <select id="roomFilter" class="glass-input" onchange="applyFilters()">
                <option value="">All Rooms</option>
                <?php foreach ($rooms as $room): ?>
                <option value="<?php echo htmlspecialchars($room); ?>">
                    <?php echo htmlspecialchars($room); ?>
                </option>
                <?php
endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label class="special-rooms-filter-label">Day</label>
            <select id="dayFilter" class="glass-input" onchange="applyFilters()">
                <option value="">Any Day</option>
                <option value="Monday">Monday</option>
                <option value="Tuesday">Tuesday</option>
                <option value="Wednesday">Wednesday</option>
                <option value="Thursday">Thursday</option>
                <option value="Friday">Friday</option>
            </select>
        </div>
        <div class="form-group">
            <label class="special-rooms-filter-label">Constraint</label>
            <select id="typeFilter" class="glass-input" onchange="applyFilters()">
                <option value="">All Types</option>
                <option value="room_only">Room Only</option>
                <option value="day_fixed">Day Fixed</option>
                <option value="time_fixed">Time Fixed</option>
                <option value="fully_locked">Fully Locked</option>
            </select>
        </div>
        <div class="form-group special-rooms-filter-btns">
            <button class="glass-btn secondary" type="button" onclick="clearFilters()">
                <i class="fa-solid fa-rotate"></i> Reset
            </button>
        </div>
    </div>

    <!-- Special Room Assignments Table -->
    <div class="special-rooms-table-wrap">
        <table class="data-table special-rooms-table">
            <thead>
                <tr class="special-rooms-thead-row">
                    <th class="special-rooms-th">Course Code</th>
                    <th class="special-rooms-th">Assigned Room</th>
                    <th class="special-rooms-th">Fixed Day</th>
                    <th class="special-rooms-th">Fixed Time</th>
                    <th class="special-rooms-th">Constraint Type</th>
                    <th class="special-rooms-th special-rooms-th--center">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($special_rooms)): ?>
                <tr>
                    <td colspan="6" class="special-rooms-empty-cell">
                        <i class="fa-solid fa-inbox special-rooms-empty-icon"></i>
                        No special room assignments yet. Click "Add Assignment" to create one.
                    </td>
                </tr>
                <?php
else: ?>
                <?php foreach ($special_rooms as $assignment): ?>
                <?php
        $type_key = 'room_only';
        if ($assignment['fixed_day'] && $assignment['fixed_time']) {
            $type_key = 'fully_locked';
        }
        elseif ($assignment['fixed_time']) {
            $type_key = 'time_fixed';
        }
        elseif ($assignment['fixed_day']) {
            $type_key = 'day_fixed';
        }
?>
                <tr class="special-rooms-data-row"
                    data-course="<?php echo strtolower($assignment['course_code']); ?>"
                    data-room="<?php echo strtolower($assignment['room_name']); ?>"
                    data-day="<?php echo strtolower($assignment['fixed_day'] ?? ''); ?>"
                    data-type="<?php echo $type_key; ?>">
                    <td class="special-rooms-course-cell">
                        <?php echo htmlspecialchars($assignment['course_code']); ?>
                    </td>
                    <td class="special-rooms-td">
                        <i class="fa-solid fa-door-open"></i>
                        <?php echo htmlspecialchars($assignment['room_name']); ?>
                    </td>
                    <td class="special-rooms-td">
                        <?php if ($assignment['fixed_day']): ?>
                        <span class="special-rooms-day-badge">
                            <i class="fa-solid fa-calendar-day"></i>
                            <?php echo htmlspecialchars($assignment['fixed_day']); ?>
                        </span>
                        <?php
        else: ?>
                        <span class="special-rooms-muted-text">Any day</span>
                        <?php
        endif; ?>
                    </td>
                    <td class="special-rooms-td">
                        <?php if ($assignment['fixed_time']): ?>
                        <span class="special-rooms-time-badge">
                            <i class="fa-solid fa-clock"></i>
                            <?php echo htmlspecialchars($assignment['fixed_time']); ?>
                        </span>
                        <?php
        else: ?>
                        <span class="special-rooms-muted-text">Any time</span>
                        <?php
        endif; ?>
                    </td>
                    <td class="special-rooms-td">
                        <?php
        $type = 'Room Only';
        $type_class = 'room-only';
        if ($assignment['fixed_day'] && $assignment['fixed_time']) {
            $type = 'Fully Locked';
            $type_class = 'fully-locked';
        }
        elseif ($assignment['fixed_time']) {
            $type = 'Time Fixed';
            $type_class = 'time-fixed';
        }
        elseif ($assignment['fixed_day']) {
            $type = 'Day Fixed';
            $type_class = 'day-fixed';
        }
?>
                        <span class="special-rooms-type-badge special-rooms-type-badge--<?php echo $type_class; ?>">
                            <?php echo $type; ?>
                        </span>
                    </td>
                    <td class="special-rooms-td--center">
                        <a href="?delete=<?php echo $assignment['id']; ?>"
                            onclick="event.preventDefault(); showConfirm('Are you sure you want to delete this assignment for <?php echo htmlspecialchars($assignment['course_code']); ?>?', 'Delete Assignment').then(result => { if(result) this.closest('form').submit(); });"
                            class="glass-btn secondary special-rooms-delete-btn">
                            <i class="fa-solid fa-trash"></i> Delete
                        </a>
                    </td>
                </tr>
                <?php
    endforeach; ?>
                <?php
endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Statistics -->
    <div class="special-rooms-stats-grid">
        <div class="glass-panel special-rooms-stat-card">
            <h3 class="special-rooms-stat-value special-rooms-stat-value--purple">
                <?php echo count($special_rooms); ?>
            </h3>
            <p class="special-rooms-stat-label">Total Assignments</p>
        </div>
        <div class="glass-panel special-rooms-stat-card">
            <h3 class="special-rooms-stat-value special-rooms-stat-value--green">
                <?php echo count(array_filter($special_rooms, fn($r) => $r['fixed_day'] && $r['fixed_time'])); ?>
            </h3>
            <p class="special-rooms-stat-label">Fully Locked</p>
        </div>
        <div class="glass-panel special-rooms-stat-card">
            <h3 class="special-rooms-stat-value special-rooms-stat-value--amber">
                <?php echo count(array_unique(array_column($special_rooms, 'room_name'))); ?>
            </h3>
            <p class="special-rooms-stat-label">Reserved Rooms</p>
        </div>
    </div>
</div>

<script>
    function showAddForm() {
        document.getElementById('addForm').style.display = 'block';
        document.querySelector('#addForm input[name="course_code"]').focus();
    }

    function hideAddForm() {
        document.getElementById('addForm').style.display = 'none';
    }

    function applyFilters() {
        const search = document.getElementById('searchInput').value.trim().toLowerCase();
        const room = document.getElementById('roomFilter').value;
        const day = document.getElementById('dayFilter').value;
        const type = document.getElementById('typeFilter').value;

        const rows = document.querySelectorAll('table.data-table tbody tr');
        rows.forEach(row => {
            if (row.querySelector('td')?.getAttribute('colspan') === '6') {
                return;
            }

            const course = row.getAttribute('data-course') || '';
            const rname = row.getAttribute('data-room') || '';
            const rday = row.getAttribute('data-day') || '';
            const rtype = row.getAttribute('data-type') || '';

            const matchesSearch = !search || course.includes(search) || rname.includes(search);
            const matchesRoom = !room || rname === room.toLowerCase();
            const matchesDay = !day || rday === day.toLowerCase();
            const matchesType = !type || rtype === type;

            row.style.display = (matchesSearch && matchesRoom && matchesDay && matchesType) ? '' : 'none';
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