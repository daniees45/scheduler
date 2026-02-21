<?php
$page_title = 'Manage Rooms';
include 'includes/header.php';
require_once 'api/db.php';

// Access Control - Admin Only
requireAdmin();

// Pagination
$per_page = 10;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $per_page;

// Handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_id'])) {
        $stmt = $conn->prepare("DELETE FROM rooms WHERE id = ?");
        $stmt->bind_param("i", $_POST['delete_id']);
        $stmt->execute();
        
        // Update CSV after deletion
        syncRoomsToCSV($conn);
        
        header("Location: rooms.php?msg=deleted");
        exit;
    }
    
    if (isset($_POST['add_room'])) {
        $name = $_POST['name'];
        $capacity = $_POST['capacity'];
        $type = $_POST['type'];
        
        $equipment = $_POST['equipment'] ?? '';
        $access = isset($_POST['accessibility']) ? 1 : 0;
        $dept = $_POST['primary_dept'] ?? '';

        try {
            $stmt = $conn->prepare("INSERT INTO rooms (room_name, capacity, type, equipment, accessibility, primary_dept) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sissss", $name, $capacity, $type, $equipment, $access, $dept);
            $stmt->execute();
            
            // Sync to CSV
            syncRoomsToCSV($conn);
            
            header("Location: rooms.php?msg=added");
            exit;
        } catch (Exception $e) {
            $error = "Error adding room: " . $e->getMessage();
        }
    }
}

function syncRoomsToCSV($conn) {
    $csv_path = '../rooms.csv';
    $res = $conn->query("SELECT room_name, capacity FROM rooms ORDER BY room_name ASC");
    $rooms = $res->fetch_all(MYSQLI_ASSOC);
    
    $file_handle = fopen($csv_path, 'w');
    if ($file_handle) {
        fputcsv($file_handle, ['Room Name', 'Capacity']);
        foreach ($rooms as $room) {
            fputcsv($file_handle, [$room['room_name'], $room['capacity']]);
        }
        fclose($file_handle);
    }
}

// Get total count
$count_res = $conn->query("SELECT COUNT(*) as total FROM rooms");
$count_row = $count_res->fetch_assoc();
$total_rooms = $count_row['total'];
$total_pages = ceil($total_rooms / $per_page);

// Get paginated results
$res = $conn->query("SELECT * FROM rooms ORDER BY room_name ASC LIMIT $offset, $per_page");
$rooms = $res->fetch_all(MYSQLI_ASSOC) ?? [];

// CSV Stats logic moved to dashboard.php
?>

<div class="glass-panel" style="padding: 2rem;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
        <h2 style="margin: 0; font-size: 1.5rem;">Rooms Management</h2>
        <button onclick="document.getElementById('addModal').style.display='flex'" class="glass-btn"><i class="fa-solid fa-plus"></i> Add Room</button>
    </div>
    
    <?php if (isset($_GET['msg'])): ?>
        <div class="alert alert-success" style="margin-bottom: 1.5rem;">
            <i class="fa-solid fa-check-circle"></i> Action completed successfully.
        </div>
    <?php endif; ?>

    <?php if (empty($rooms)): ?>
        <div style="text-align: center; color: var(--text-muted); padding: 3rem;">
            <i class="fa-solid fa-inbox" style="font-size: 3rem; opacity: 0.5; margin-bottom: 1rem; display: block;"></i>
            <p>No rooms available. Add your first room to get started.</p>
        </div>
    <?php else: ?>
    <!-- Table Container -->
    <div style="overflow-x: auto; margin-bottom: 2rem;">
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="border-bottom: 2px solid rgba(255,255,255,0.1); background: rgba(255,255,255,0.02);">
                    <th style="padding: 1rem; text-align: left; color: var(--text-muted); font-weight: 600; font-size: 0.9rem;">Room Name</th>
                    <th style="padding: 1rem; text-align: center; color: var(--text-muted); font-weight: 600; font-size: 0.9rem;">Capacity</th>
                    <th style="padding: 1rem; text-align: center; color: var(--text-muted); font-weight: 600; font-size: 0.9rem;">Type</th>
                    <th style="padding: 1rem; text-align: left; color: var(--text-muted); font-weight: 600; font-size: 0.9rem;">Department</th>
                    <th style="padding: 1rem; text-align: center; color: var(--text-muted); font-weight: 600; font-size: 0.9rem;">Features</th>
                    <th style="padding: 1rem; text-align: center; color: var(--text-muted); font-weight: 600; font-size: 0.9rem;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rooms as $room): ?>
                <tr style="border-bottom: 1px solid rgba(255,255,255,0.05); transition: background 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.03)'" onmouseout="this.style.background='transparent'">
                    <td style="padding: 1rem; font-weight: 500;">
                        <div><?php echo htmlspecialchars($room['room_name']); ?></div>
                        <?php if ($room['equipment']): ?>
                            <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 0.25rem;"><i class="fa-solid fa-plug"></i> <?php echo htmlspecialchars(substr($room['equipment'], 0, 25)) . (strlen($room['equipment'])>25?'...':''); ?></div>
                        <?php endif; ?>
                    </td>
                    <td style="padding: 1rem; text-align: center;">
                        <span style="background: rgba(99, 102, 241, 0.2); color: var(--primary); padding: 0.25rem 0.75rem; border-radius: 12px; font-size: 0.85rem; font-weight: 600;"><?php echo $room['capacity']; ?></span>
                    </td>
                    <td style="padding: 1rem; text-align: center;">
                        <span style="background: rgba(168, 85, 247, 0.2); color: #a855f7; padding: 0.25rem 0.75rem; border-radius: 12px; font-size: 0.85rem;"><?php echo $room['type']; ?></span>
                    </td>
                    <td style="padding: 1rem;">
                        <?php if ($room['primary_dept']): ?>
                            <span style="background: rgba(245, 158, 11, 0.2); color: var(--warning); padding: 0.25rem 0.75rem; border-radius: 12px; font-size: 0.85rem;"><i class="fa-solid fa-star" style="margin-right: 0.25rem;"></i><?php echo htmlspecialchars($room['primary_dept']); ?></span>
                        <?php else: ?>
                            <span style="color: var(--text-muted); font-size: 0.9rem;">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="padding: 1rem; text-align: center;">
                        <?php if ($room['accessibility']): ?>
                            <span title="Wheelchair Accessible" style="background: rgba(16, 185, 129, 0.2); color: #10b981; padding: 0.4rem 0.6rem; border-radius: 6px; display: inline-block;"><i class="fa-solid fa-wheelchair"></i></span>
                        <?php endif; ?>
                    </td>
                    <td style="padding: 1rem; text-align: center;">
                        <div style="display: flex; gap: 0.5rem; justify-content: center;">
                            <a href="edit_room.php?id=<?php echo $room['id']; ?>" class="glass-btn" style="padding: 0.4rem 0.8rem; font-size: 0.85rem; text-decoration: none; display: inline-flex; align-items: center; gap: 0.25rem;">
                                <i class="fa-solid fa-pen"></i> Edit
                            </a>
                            <form method="POST" style="display: inline;" onsubmit="confirmAction(event, 'Delete Room', 'Are you sure you want to delete <?php echo htmlspecialchars(preg_replace("/'/", "\\\\", $room['room_name'])); ?>?')">
                                <input type="hidden" name="delete_id" value="<?php echo $room['id']; ?>">
                                <button type="submit" class="glass-btn" style="padding: 0.4rem 0.8rem; font-size: 0.85rem; color: var(--danger); background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3);">
                                    <i class="fa-solid fa-trash"></i> Delete
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div style="display: flex; justify-content: center; align-items: center; gap: 0.5rem; margin-top: 2rem; flex-wrap: wrap;">
        <?php if ($page > 1): ?>
            <a href="rooms.php?page=1" class="glass-btn secondary" style="padding: 0.5rem 0.75rem; font-size: 0.85rem; text-decoration: none;">
                <i class="fa-solid fa-angles-left"></i>
            </a>
            <a href="rooms.php?page=<?php echo $page - 1; ?>" class="glass-btn secondary" style="padding: 0.5rem 0.75rem; font-size: 0.85rem; text-decoration: none;">
                <i class="fa-solid fa-angle-left"></i> Prev
            </a>
        <?php endif; ?>
        
        <div style="display: flex; gap: 0.25rem;">
            <?php 
            $start = max(1, $page - 2);
            $end = min($total_pages, $page + 2);
            
            if ($start > 1) echo '<span style="padding: 0 0.5rem; color: var(--text-muted);">...</span>';
            
            for ($i = $start; $i <= $end; $i++): ?>
                <?php if ($i == $page): ?>
                    <span style="padding: 0.5rem 0.75rem; background: var(--primary); border-radius: 4px; font-weight: 600; font-size: 0.85rem;"><?php echo $i; ?></span>
                <?php else: ?>
                    <a href="rooms.php?page=<?php echo $i; ?>" class="glass-btn secondary" style="padding: 0.5rem 0.75rem; font-size: 0.85rem; text-decoration: none;"><?php echo $i; ?></a>
                <?php endif; ?>
            <?php endfor;
            
            if ($end < $total_pages) echo '<span style="padding: 0 0.5rem; color: var(--text-muted);">...</span>';
            ?>
        </div>
        
        <?php if ($page < $total_pages): ?>
            <a href="rooms.php?page=<?php echo $page + 1; ?>" class="glass-btn secondary" style="padding: 0.5rem 0.75rem; font-size: 0.85rem; text-decoration: none;">
                Next <i class="fa-solid fa-angle-right"></i>
            </a>
            <a href="rooms.php?page=<?php echo $total_pages; ?>" class="glass-btn secondary" style="padding: 0.5rem 0.75rem; font-size: 0.85rem; text-decoration: none;">
                <i class="fa-solid fa-angles-right"></i>
            </a>
        <?php endif; ?>
    </div>
    <div style="text-align: center; color: var(--text-muted); font-size: 0.85rem; margin-top: 1rem;">
        Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $per_page, $total_rooms); ?> of <?php echo $total_rooms; ?> rooms
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Add Modal -->
<div id="addModal" style="display: none; position: fixed; top:0; left:0; width:100%; height:100%; background: rgba(0,0,0,0.8); z-index: 1000; justify-content: center; align-items: center;">
    <div class="glass-panel" style="width: 100%; max-width: 400px; padding: 2rem;">
        <h3 style="margin-bottom: 1.5rem;">Add Room</h3>
        <form method="POST">
            <input type="hidden" name="add_room" value="1">
            <div style="display: grid; gap: 1rem;">
                <div>
                    <label style="font-size: 0.9rem; color: var(--text-muted);">Room Name</label>
                    <input type="text" name="name" class="glass-input" required placeholder="e.g. CS Lab 1">
                </div>
                <div>
                    <label style="font-size: 0.9rem; color: var(--text-muted);">Capacity</label>
                    <input type="number" name="capacity" class="glass-input" required value="50">
                </div>
                <div>
                    <label style="font-size: 0.9rem; color: var(--text-muted);">Type</label>
                    <select name="type" class="glass-input" style="background: rgba(15,23,42,0.9);">
                        <option value="Lecture">Lecture Hall</option>
                        <option value="Lab">Laboratory</option>
                        <option value="Auditorium">Auditorium</option>
                    </select>
                </div>
                <div>
                     <label style="font-size: 0.9rem; color: var(--text-muted);">Primary Department (Optional)</label>
                     <input type="text" name="primary_dept" class="glass-input" placeholder="e.g. Nursing">
                </div>
                <div>
                     <label style="font-size: 0.9rem; color: var(--text-muted);">Equipment (comma separated)</label>
                     <textarea name="equipment" class="glass-input" rows="2" placeholder="Projector, Smartboard..."></textarea>
                </div>
                <div>
                    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                        <input type="checkbox" name="accessibility" value="1">
                        <span style="font-size: 0.9rem; color: var(--text-muted);">Wheelchair Accessible</span>
                    </label>
                </div>
                <div style="display: flex; gap: 10px; margin-top: 1rem;">
                    <button type="submit" class="glass-btn" style="flex: 1;">Save</button>
                    <button type="button" onclick="document.getElementById('addModal').style.display='none'" class="glass-btn secondary">Cancel</button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
