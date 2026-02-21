<?php
$page_title = 'Manage Courses';
include 'includes/header.php';
require_once 'api/db.php';

// Access Control - Admin Only
requireAdmin();

// Handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_id'])) {
        $stmt = $conn->prepare("DELETE FROM courses WHERE id = ?");
        $stmt->bind_param("i", $_POST['delete_id']);
        $stmt->execute();
        header("Location: courses.php?msg=deleted");
        exit;
    }
    
    if (isset($_POST['add_course'])) {
        $code = $_POST['code'];
        $title = $_POST['title'];
        $level = $_POST['level'];
        $credits = $_POST['credits'];
        $type = $_POST['type'];
        
        try {
            $conn->begin_transaction();
            $stmt = $conn->prepare("INSERT INTO courses (course_code, course_title, level, credit_hours, type) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("ssiis", $code, $title, $level, $credits, $type);
            $stmt->execute();
            $course_id = $conn->insert_id;
            
            // Create a default empty section for this course
            $stmt = $conn->prepare("INSERT INTO sections (course_id) VALUES (?)");
            $stmt->bind_param("i", $course_id);
            $stmt->execute();
            
            $conn->commit();
            header("Location: courses.php?msg=added");
            exit;
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Error adding course: " . $e->getMessage();
        }
    }
}

$search = $_GET['search'] ?? '';
$search_param = "%$search%";

// Pagination
$items_per_page = 15;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $items_per_page;

// Build Filter Query
$where_sql = "WHERE (c.course_code LIKE ? OR c.course_title LIKE ?)";
$params = [$search_param, $search_param];
$types = "ss";

// Add Department Filter for Faculty Admins
if ($_SESSION['role'] === 'faculty_admin' && isset($_SESSION['department'])) {
    $where_sql .= " AND c.program = ?";
    $params[] = $_SESSION['department'];
    $types .= "s";
}

// Add Level Filter
$filter_level = $_GET['level'] ?? '';
if ($filter_level) {
    $where_sql .= " AND c.level = ?";
    $params[] = $filter_level;
    $types .= "i"; // level is int
}

// Count total for pagination
// Note: 'c' alias is needed in count if we use the same where clause, but count query below didn't use alias.
// We'll adjust the count query to use alias or matching column.
$count_sql = "SELECT COUNT(*) as total FROM courses c $where_sql";
$count_stmt = $conn->prepare($count_sql);
$count_stmt->bind_param($types, ...$params);
$count_stmt->execute();
$total_items = $count_stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_items / $items_per_page);

// Fetch courses with assigned lecturer from sections
$sql = "SELECT c.*, l.name as lecturer_name 
        FROM courses c 
        LEFT JOIN sections s ON c.id = s.course_id 
        LEFT JOIN lecturers l ON s.lecturer_id = l.id
        $where_sql
        ORDER BY c.level ASC, c.course_code ASC
        LIMIT ? OFFSET ?";

// Add limit/offset parameters
$params[] = $items_per_page;
$params[] = $offset;
$types .= "ii";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$courses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>

<div class="glass-panel" style="padding: 1.5rem;">
    <!-- Top Action Bar -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
        <form method="GET" style="display: flex; gap: 10px; flex: 1; max-width: 400px;">
            <input type="text" name="search" class="glass-input" placeholder="Search courses..." value="<?php echo htmlspecialchars($search); ?>">
            <select name="level" class="glass-input" style="width: 100px; background: rgba(15,23,42,0.8);" onchange="this.form.submit()">
                <option value="">Level...</option>
                <option value="100" <?php if($filter_level=='100') echo 'selected'; ?>>100</option>
                <option value="200" <?php if($filter_level=='200') echo 'selected'; ?>>200</option>
                <option value="300" <?php if($filter_level=='300') echo 'selected'; ?>>300</option>
                <option value="400" <?php if($filter_level=='400') echo 'selected'; ?>>400</option>
            </select>
            <button type="submit" class="glass-btn"><i class="fa-solid fa-search"></i></button>
        </form>
        
        <button onclick="document.getElementById('addModal').style.display='flex'" class="glass-btn"><i class="fa-solid fa-plus"></i> Add Course</button>
    </div>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    
    <?php if (isset($_GET['msg'])): ?>
        <div class="alert alert-success">Operation successful.</div>
    <?php endif; ?>

    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Title</th>
                    <th>Level</th>
                    <th>Credits</th>
                    <th>Type</th>
                    <th>Lecturer</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($courses) > 0): ?>
                    <?php foreach ($courses as $course): ?>
                    <tr>
                        <td><span style="font-weight: 600; color: var(--primary-color);"><?php echo htmlspecialchars($course['course_code']); ?></span></td>
                        <td><?php echo htmlspecialchars($course['course_title']); ?></td>
                        <td><?php echo htmlspecialchars($course['level']); ?></td>
                        <td><?php echo htmlspecialchars($course['credit_hours']); ?></td>
                        <td>
                            <span style="padding: 4px 8px; border-radius: 4px; background: <?php echo $course['type'] == 'General' ? 'rgba(236, 72, 153, 0.2)' : 'rgba(79, 70, 229, 0.2)'; ?>; color: <?php echo $course['type'] == 'General' ? '#f472b6' : '#818cf8'; ?>; font-size: 0.8rem;">
                                <?php echo htmlspecialchars($course['type']); ?>
                            </span>
                        </td>
                        <td><?php echo htmlspecialchars($course['lecturer_name'] ?? 'Unassigned'); ?></td>
                        <td>
                            <a href="edit_course.php?id=<?php echo $course['id']; ?>" class="glass-btn secondary" style="padding: 6px 10px; display:inline-block; text-decoration:none;"><i class="fa-solid fa-pen"></i></a>
                            <form method="POST" style="display:inline;" onsubmit="confirmAction(event, 'Delete Course', 'Are you sure you want to delete this course?')">
                                <input type="hidden" name="delete_id" value="<?php echo $course['id']; ?>">
                                <button type="submit" class="glass-btn secondary" style="padding: 6px 10px; color: var(--danger); border-color: rgba(239,68,68,0.3);"><i class="fa-solid fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="7" style="text-align: center; padding: 2rem;">No courses found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Pagination Controls -->
    <?php if ($total_pages > 1): ?>
    <div style="margin-top: 1.5rem; display: flex; justify-content: center; align-items: center; gap: 1rem;">
        <?php 
            $params = $_GET;
            function build_course_query($p, $params) {
                $params['page'] = $p;
                return '?' . http_build_query($params);
            }
        ?>
        <a href="<?php echo build_course_query(max(1, $page - 1), $params); ?>" class="glass-btn secondary small <?php if($page <= 1) echo 'disabled'; ?>" style="<?php if($page <= 1) echo 'opacity: 0.5; pointer-events: none;'; ?>">
            <i class="fa-solid fa-chevron-left"></i>
        </a>
        <span style="font-size: 0.9rem; color: var(--text-muted);">Page <?php echo $page; ?> of <?php echo $total_pages; ?></span>
        <a href="<?php echo build_course_query(min($total_pages, $page + 1), $params); ?>" class="glass-btn secondary small <?php if($page >= $total_pages) echo 'disabled'; ?>" style="<?php if($page >= $total_pages) echo 'opacity: 0.5; pointer-events: none;'; ?>">
            <i class="fa-solid fa-chevron-right"></i>
        </a>
    </div>
    <?php endif; ?>
</div>

<!-- Add Modal -->
<div id="addModal" style="display: none; position: fixed; top:0; left:0; width:100%; height:100%; background: rgba(0,0,0,0.8); z-index: 1000; justify-content: center; align-items: center;">
    <div class="glass-panel" style="width: 100%; max-width: 500px; padding: 2rem;">
        <h3 style="margin-bottom: 1.5rem;">Add New Course</h3>
        <form method="POST">
            <input type="hidden" name="add_course" value="1">
            <div style="display: grid; gap: 1rem;">
                <div>
                    <label style="font-size: 0.9rem; color: var(--text-muted);">Course Code</label>
                    <input type="text" name="code" class="glass-input" required placeholder="e.g. COSC 110">
                </div>
                <div>
                    <label style="font-size: 0.9rem; color: var(--text-muted);">Course Title</label>
                    <input type="text" name="title" class="glass-input" required>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div>
                        <label style="font-size: 0.9rem; color: var(--text-muted);">Level</label>
                        <select name="level" class="glass-input" style="background: rgba(15,23,42,0.9);">
                            <option value="100">100</option>
                            <option value="200">200</option>
                            <option value="300">300</option>
                            <option value="400">400</option>
                        </select>
                    </div>
                    <div>
                        <label style="font-size: 0.9rem; color: var(--text-muted);">Credits</label>
                        <input type="number" name="credits" class="glass-input" value="3">
                    </div>
                </div>
                <div>
                    <label style="font-size: 0.9rem; color: var(--text-muted);">Type</label>
                    <select name="type" class="glass-input" style="background: rgba(15,23,42,0.9);">
                        <option value="Departmental">Departmental</option>
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
