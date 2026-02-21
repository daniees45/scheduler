<?php
$page_title = 'Manage Lecturers';
include 'includes/header.php';
require_once 'api/db.php';

// Access Control - Admin Only
requireAdmin();

// Handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_id'])) {
        $stmt = $conn->prepare("DELETE FROM lecturers WHERE id = ?");
        $stmt->bind_param("i", $_POST['delete_id']);
        $stmt->execute();
        header("Location: lecturers.php?msg=deleted");
        exit;
    }
    
    if (isset($_POST['add_lecturer'])) {
        $name = $_POST['name'];
        $email = $_POST['email'];
        $department = $_POST['department'] ?? null;
        // Default availability: full (all 5 days, 3 slots)
        // 0=Mon, 1=Tue etc. We store indices of days available.
        // Simplified: [0,1,2,3,4] (Mon-Fri)
        $avail = [0,1,2,3,4]; 
        $json = json_encode($avail);
        
        try {
            $stmt = $conn->prepare("INSERT INTO lecturers (name, email, department, availability_json) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("ssss", $name, $email, $department, $json);
            $stmt->execute();
            
            // Sync to CSV: Append to lecturer_availability.csv
            $csv_path = '../lecturer_availability.csv';
            $csv_data = [$name, '1', '1', '1', '1', '1']; // Full availability (all days)
            
            $file_handle = fopen($csv_path, 'a');
            if ($file_handle) {
                fputcsv($file_handle, $csv_data);
                fclose($file_handle);
            }
            
            header("Location: lecturers.php?msg=added");
            exit;
        } catch (Exception $e) {
            $error = "Error adding lecturer: " . $e->getMessage();
        }
    }
}

$search = $_GET['search'] ?? '';
$search_param = "%$search%";

// Pagination
$items_per_page = 15;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $items_per_page;

// Count
$count_stmt = $conn->prepare("SELECT COUNT(*) as total FROM lecturers WHERE name LIKE ?");
$count_stmt->bind_param("s", $search_param);
$count_stmt->execute();
$total_items = $count_stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_items / $items_per_page);

// Fetch lecturers
$stmt = $conn->prepare("SELECT * FROM lecturers WHERE name LIKE ? ORDER BY name ASC LIMIT ? OFFSET ?");
$stmt->bind_param("sii", $search_param, $items_per_page, $offset);
$stmt->execute();
$lecturers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

?>

<div class="glass-panel" style="padding: 1.5rem;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
        <form method="GET" style="display: flex; gap: 10px; flex: 1; max-width: 400px;">
            <input type="text" name="search" class="glass-input" placeholder="Search lecturers..." value="<?php echo htmlspecialchars($search); ?>">
            <button type="submit" class="glass-btn"><i class="fa-solid fa-search"></i></button>
        </form>
        
        <button onclick="document.getElementById('addModal').style.display='flex'" class="glass-btn"><i class="fa-solid fa-plus"></i> Add Lecturer</button>
    </div>

    <div class="table-container">
        <table style="width: 100%;">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Department</th>
                    <th>Availability Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($lecturers): ?>
                    <?php foreach ($lecturers as $lecturer): ?>
                    <tr>
                        <td style="font-weight: 500;"><?php echo htmlspecialchars($lecturer['name']); ?></td>
                        <td style="color: var(--text-muted);"><?php echo htmlspecialchars($lecturer['email'] ?? 'N/A'); ?></td>
                        <td style="color: var(--text-muted);"><?php echo htmlspecialchars($lecturer['department'] ?? 'N/A'); ?></td>
                        <td>
                            <?php 
                                $avail = json_decode($lecturer['availability_json'] ?? '[]', true); 
                                $count = is_array($avail) ? count($avail) : 5; // Default full if null
                            ?>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <div style="height: 8px; flex: 1; max-width: 100px; background: rgba(255,255,255,0.1); border-radius: 4px; overflow: hidden;">
                                    <div style="height: 100%; width: <?php echo ($count/5)*100; ?>%; background: <?php echo ($count < 3) ? 'var(--danger)' : 'var(--success)'; ?>;"></div>
                                </div>
                                <span style="font-size: 0.8rem; color: var(--text-muted);"><?php echo $count; ?>/5 Days</span>
                            </div>
                        </td>
                        <td>
                            <form method="POST" style="display:inline;" onsubmit="confirmAction(event, 'Delete Lecturer', 'Are you sure you want to delete this lecturer?')">
                                <input type="hidden" name="delete_id" value="<?php echo $lecturer['id']; ?>">
                                <button type="submit" class="glass-btn secondary" style="padding: 6px 10px; color: var(--danger); border-color: rgba(239,68,68,0.3);"><i class="fa-solid fa-trash"></i></button>
                            </form>
                            <a href="edit_lecturer.php?id=<?php echo $lecturer['id']; ?>" class="glass-btn secondary" style="padding: 6px 10px; display:inline-block; text-decoration:none;"><i class="fa-solid fa-pen"></i></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="5" style="text-align: center; padding: 2rem;">No lecturers found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination Controls -->
    <?php if ($total_pages > 1): ?>
    <div style="margin-top: 1.5rem; display: flex; justify-content: center; align-items: center; gap: 1rem;">
        <?php 
            $params = $_GET;
            function build_lecturer_query($p, $params) {
                $params['page'] = $p;
                return '?' . http_build_query($params);
            }
        ?>
        <a href="<?php echo build_lecturer_query(max(1, $page - 1), $params); ?>" class="glass-btn secondary small <?php if($page <= 1) echo 'disabled'; ?>" style="<?php if($page <= 1) echo 'opacity: 0.5; pointer-events: none;'; ?>">
            <i class="fa-solid fa-chevron-left"></i>
        </a>
        <span style="font-size: 0.9rem; color: var(--text-muted);">Page <?php echo $page; ?> of <?php echo $total_pages; ?></span>
        <a href="<?php echo build_lecturer_query(min($total_pages, $page + 1), $params); ?>" class="glass-btn secondary small <?php if($page >= $total_pages) echo 'disabled'; ?>" style="<?php if($page >= $total_pages) echo 'opacity: 0.5; pointer-events: none;'; ?>">
            <i class="fa-solid fa-chevron-right"></i>
        </a>
    </div>
    <?php endif; ?>
</div>

<!-- Add Modal -->
<div id="addModal" style="display: none; position: fixed; top:0; left:0; width:100%; height:100%; background: rgba(0,0,0,0.8); z-index: 1000; justify-content: center; align-items: center;">
    <div class="glass-panel" style="width: 100%; max-width: 400px; padding: 2rem;">
        <h3 style="margin-bottom: 1.5rem;">Add Lecturer</h3>
        <form method="POST">
            <input type="hidden" name="add_lecturer" value="1">
            <div style="display: grid; gap: 1rem;">
                <div>
                    <label style="font-size: 0.9rem; color: var(--text-muted);">Full Name (with Title)</label>
                    <input type="text" name="name" class="glass-input" required placeholder="e.g. Dr. John Doe">
                </div>
                <div>
                    <label style="font-size: 0.9rem; color: var(--text-muted);">Email</label>
                    <input type="email" name="email" class="glass-input" placeholder="john@vvu.edu.gh">
                </div>
                <div>
                    <label style="font-size: 0.9rem; color: var(--text-muted);">Department</label>
                    <select name="department" class="glass-input">
                        <option value="">Select Department...</option>
                        <option value="Computer Science">Computer Science</option>
                        <option value="Nursing">Nursing</option>
                        <option value="Theology">Theology</option>
                        <option value="Business">Business</option>
                        <option value="Education">Education</option>
                        <option value="General">General</option>
                    </select>
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
