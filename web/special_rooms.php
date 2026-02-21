<?php
$page_title = 'Special Room Assignments';
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
            $message = $_POST['action'] === 'add' ? 'Special room assignment added successfully!' : 'Special room assignment updated successfully!';
            $message_type = 'success';
        } else {
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
        $message = 'Special room assignment deleted successfully!';
        $message_type = 'success';
    } else {
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

<div class="glass-panel" style="padding: 2rem; max-width: 1400px; margin: 0 auto;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
        <div>
            <h2><i class="fa-solid fa-door-open"></i> Special Room Assignments</h2>
            <p style="color: var(--text-muted);">Pre-assign specific courses to specific rooms with optional time/day constraints</p>
        </div>
        <button onclick="showAddForm()" class="glass-btn primary">
            <i class="fa-solid fa-plus"></i> Add Assignment
        </button>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type; ?>" style="margin-bottom: 2rem; padding: 1rem; border-radius: 8px; background: <?php echo $message_type === 'success' ? 'rgba(16, 185, 129, 0.15)' : 'rgba(239, 68, 68, 0.15)'; ?>; border: 1px solid <?php echo $message_type === 'success' ? 'rgba(16, 185, 129, 0.4)' : 'rgba(239, 68, 68, 0.4)'; ?>; color: <?php echo $message_type === 'success' ? '#10b981' : '#ef4444'; ?>;">
            <i class="fa-solid fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <!-- Add/Edit Form (Hidden by default) -->
    <div id="addForm" class="glass-panel" style="display: none; padding: 2rem; margin-bottom: 2rem; border: 2px solid rgba(129, 140, 248, 0.3);">
        <h3 style="margin-bottom: 1.5rem;">
            <i class="fa-solid fa-plus-circle"></i> Add Special Room Assignment
        </h3>
        
        <form method="POST" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
            <input type="hidden" name="action" value="add">
            
            <div class="form-group">
                <label style="display: block; margin-bottom: 0.5rem; font-weight: 600;">Course Code *</label>
                <input type="text" name="course_code" required class="glass-input" placeholder="e.g., PEAC 100" style="width: 100%;">
            </div>

            <div class="form-group">
                <label style="display: block; margin-bottom: 0.5rem; font-weight: 600;">Room Name *</label>
                <select name="room_name" required class="glass-input" style="width: 100%;">
                    <option value="">-- Select Room --</option>
                    <?php foreach ($rooms as $room): ?>
                        <option value="<?php echo htmlspecialchars($room); ?>"><?php echo htmlspecialchars($room); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label style="display: block; margin-bottom: 0.5rem; font-weight: 600;">Fixed Day (Optional)</label>
                <select name="fixed_day" class="glass-input" style="width: 100%;">
                    <option value="">-- Any Day --</option>
                    <option value="Monday">Monday</option>
                    <option value="Tuesday">Tuesday</option>
                    <option value="Wednesday">Wednesday</option>
                    <option value="Thursday">Thursday</option>
                    <option value="Friday">Friday</option>
                </select>
                <small style="color: var(--text-muted); font-size: 0.8rem;">Leave blank to allow any day</small>
            </div>

            <div class="form-group">
                <label style="display: block; margin-bottom: 0.5rem; font-weight: 600;">Fixed Time (Optional)</label>
                <select name="fixed_time" class="glass-input" style="width: 100%;">
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
                <small style="color: var(--text-muted); font-size: 0.8rem;">Leave blank to allow any time slot</small>
            </div>

            <div style="grid-column: span 2; display: flex; gap: 1rem; justify-content: flex-end;">
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
    <div class="glass-panel" style="padding: 1rem; margin-bottom: 2rem; background: rgba(99, 102, 241, 0.1); border: 1px solid rgba(99, 102, 241, 0.3);">
        <h4 style="margin: 0 0 0.5rem 0; color: #818cf8;">
            <i class="fa-solid fa-info-circle"></i> How it works
        </h4>
        <ul style="margin: 0; padding-left: 1.5rem; color: var(--text-muted); line-height: 1.8;">
            <li><strong>Room Only:</strong> Course must use this specific room, but any day/time is allowed</li>
            <li><strong>Room + Time:</strong> Course must use this room at this specific time on any day</li>
            <li><strong>Room + Day + Time:</strong> Course is locked to this exact room, day, and time slot</li>
            <li><strong>Reserved Rooms:</strong> Rooms assigned here cannot be used by other courses</li>
        </ul>
    </div>

    <div class="glass-panel" style="padding: 1rem; margin-bottom: 1.5rem; display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; align-items: end;">
        <div class="form-group">
            <label style="display: block; margin-bottom: 0.4rem; font-weight: 600;">Search</label>
            <input type="text" id="searchInput" class="glass-input" placeholder="Course or room..." oninput="applyFilters()">
        </div>
        <div class="form-group">
            <label style="display: block; margin-bottom: 0.4rem; font-weight: 600;">Room</label>
            <select id="roomFilter" class="glass-input" onchange="applyFilters()">
                <option value="">All Rooms</option>
                <?php foreach ($rooms as $room): ?>
                    <option value="<?php echo htmlspecialchars($room); ?>"><?php echo htmlspecialchars($room); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label style="display: block; margin-bottom: 0.4rem; font-weight: 600;">Day</label>
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
            <label style="display: block; margin-bottom: 0.4rem; font-weight: 600;">Constraint</label>
            <select id="typeFilter" class="glass-input" onchange="applyFilters()">
                <option value="">All Types</option>
                <option value="room_only">Room Only</option>
                <option value="day_fixed">Day Fixed</option>
                <option value="time_fixed">Time Fixed</option>
                <option value="fully_locked">Fully Locked</option>
            </select>
        </div>
        <div class="form-group" style="display: flex; gap: 0.5rem;">
            <button class="glass-btn secondary" type="button" onclick="clearFilters()">
                <i class="fa-solid fa-rotate"></i> Reset
            </button>
        </div>
    </div>

    <!-- Special Room Assignments Table -->
    <div style="overflow-x: auto;">
        <table class="data-table" style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background: rgba(99, 102, 241, 0.15); border-bottom: 2px solid rgba(99, 102, 241, 0.3);">
                    <th style="padding: 1rem; text-align: left;">Course Code</th>
                    <th style="padding: 1rem; text-align: left;">Assigned Room</th>
                    <th style="padding: 1rem; text-align: left;">Fixed Day</th>
                    <th style="padding: 1rem; text-align: left;">Fixed Time</th>
                    <th style="padding: 1rem; text-align: left;">Constraint Type</th>
                    <th style="padding: 1rem; text-align: center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($special_rooms)): ?>
                    <tr>
                        <td colspan="6" style="padding: 2rem; text-align: center; color: var(--text-muted);">
                            <i class="fa-solid fa-inbox" style="font-size: 2rem; margin-bottom: 0.5rem; display: block;"></i>
                            No special room assignments yet. Click "Add Assignment" to create one.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($special_rooms as $assignment): ?>
                        <?php
                            $type_key = 'room_only';
                            if ($assignment['fixed_day'] && $assignment['fixed_time']) {
                                $type_key = 'fully_locked';
                            } elseif ($assignment['fixed_time']) {
                                $type_key = 'time_fixed';
                            } elseif ($assignment['fixed_day']) {
                                $type_key = 'day_fixed';
                            }
                        ?>
                        <tr style="border-bottom: 1px solid rgba(255, 255, 255, 0.1);"
                            data-course="<?php echo strtolower($assignment['course_code']); ?>"
                            data-room="<?php echo strtolower($assignment['room_name']); ?>"
                            data-day="<?php echo strtolower($assignment['fixed_day'] ?? ''); ?>"
                            data-type="<?php echo $type_key; ?>">
                            <td style="padding: 1rem; font-weight: 600; color: #818cf8;">
                                <?php echo htmlspecialchars($assignment['course_code']); ?>
                            </td>
                            <td style="padding: 1rem;">
                                <i class="fa-solid fa-door-open"></i> <?php echo htmlspecialchars($assignment['room_name']); ?>
                            </td>
                            <td style="padding: 1rem;">
                                <?php if ($assignment['fixed_day']): ?>
                                    <span style="padding: 4px 8px; background: rgba(16, 185, 129, 0.15); border-radius: 4px; font-size: 0.85rem; color: #10b981;">
                                        <i class="fa-solid fa-calendar-day"></i> <?php echo htmlspecialchars($assignment['fixed_day']); ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color: var(--text-muted); font-size: 0.85rem;">Any day</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 1rem;">
                                <?php if ($assignment['fixed_time']): ?>
                                    <span style="padding: 4px 8px; background: rgba(245, 158, 11, 0.15); border-radius: 4px; font-size: 0.85rem; color: #f59e0b;">
                                        <i class="fa-solid fa-clock"></i> <?php echo htmlspecialchars($assignment['fixed_time']); ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color: var(--text-muted); font-size: 0.85rem;">Any time</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 1rem;">
                                <?php
                                    $type = 'Room Only';
                                    $color = '#818cf8';
                                    if ($assignment['fixed_day'] && $assignment['fixed_time']) {
                                        $type = 'Fully Locked';
                                        $color = '#ef4444';
                                    } elseif ($assignment['fixed_time']) {
                                        $type = 'Time Fixed';
                                        $color = '#f59e0b';
                                    } elseif ($assignment['fixed_day']) {
                                        $type = 'Day Fixed';
                                        $color = '#10b981';
                                    }
                                ?>
                                <span style="padding: 4px 10px; background: rgba(129, 140, 248, 0.15); border-radius: 12px; font-size: 0.8rem; font-weight: 600; color: <?php echo $color; ?>;">
                                    <?php echo $type; ?>
                                </span>
                            </td>
                            <td style="padding: 1rem; text-align: center;">
                                <a href="?delete=<?php echo $assignment['id']; ?>" 
                                   onclick="return confirm('Are you sure you want to delete this assignment for <?php echo htmlspecialchars($assignment['course_code']); ?>?')"
                                   class="glass-btn secondary" 
                                   style="padding: 0.5rem 1rem; font-size: 0.85rem;">
                                    <i class="fa-solid fa-trash"></i> Delete
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Statistics -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-top: 2rem;">
        <div class="glass-panel" style="padding: 1rem; text-align: center;">
            <h3 style="margin: 0 0 0.5rem 0; font-size: 2rem; color: #818cf8;"><?php echo count($special_rooms); ?></h3>
            <p style="color: var(--text-muted); font-size: 0.85rem; margin: 0;">Total Assignments</p>
        </div>
        <div class="glass-panel" style="padding: 1rem; text-align: center;">
            <h3 style="margin: 0 0 0.5rem 0; font-size: 2rem; color: #10b981;">
                <?php echo count(array_filter($special_rooms, fn($r) => $r['fixed_day'] && $r['fixed_time'])); ?>
            </h3>
            <p style="color: var(--text-muted); font-size: 0.85rem; margin: 0;">Fully Locked</p>
        </div>
        <div class="glass-panel" style="padding: 1rem; text-align: center;">
            <h3 style="margin: 0 0 0.5rem 0; font-size: 2rem; color: #f59e0b;">
                <?php echo count(array_unique(array_column($special_rooms, 'room_name'))); ?>
            </h3>
            <p style="color: var(--text-muted); font-size: 0.85rem; margin: 0;">Reserved Rooms</p>
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
