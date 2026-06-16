<?php
$page_title = 'Manage Courses';
$page_css = 'assets/courses.css';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'api/db.php';
require_once 'includes/access_control.php';

// Access Control - Admin Only
requireAdmin();

// Handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_id']) && isset($_POST['course_title'])) {

        $stmt = $conn->prepare("DELETE FROM courses WHERE id = ? AND course_title =? ");
        $stmt->bind_param("is", $_POST['delete_id'], $_POST['course_title'] );
        $stmt->execute();
        trigger_b2_sync();
        header("Location: courses.php?msg=deleted");
        exit;
    }

    if (isset($_POST['add_course'])) {
        $code = $_POST['code'];
        $title = $_POST['title'];
        $level = $_POST['level'];
        $credits = $_POST['credits'];
        $type = $_POST['type'];
        $sections_text = trim((string)($_POST['sections'] ?? 'Sec A'));
        // Split by comma, allow flexible input (e.g. 'Sec A, Sec B')
        $section_names = array_filter(array_map('trim', preg_split('/,|\n/', $sections_text)));
        if (empty($section_names)) {
            $section_names = ['Sec A'];
        }
        $department = trim((string)($_POST['department'] ?? ''));
        if ($department === '') {
            if (strcasecmp((string)$type, 'General') === 0) {
                $department = 'General';
            } else {
                $department = trim((string)($_SESSION['department'] ?? 'General'));
                if ($department === '') {
                    $department = 'General';
                }
            }
        }

        try {
    $conn->begin_transaction();

    foreach ($section_names as $section_name) {

        // Create title with single section
        if (empty($section_name)) {
              $course_title_with_section = $title ;
        }else {
        $course_title_with_section = $title . ' [' . $section_name . ']';
        }

        // Insert course
        $stmt = $conn->prepare("
            INSERT INTO courses
            (course_code, course_title, level, credit_hours, type, department, sections_count)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        $single_section_count = 1;

        $stmt->bind_param(
            "ssiissi",
            $code,
            $course_title_with_section,
            $level,
            $credits,
            $type,
            $department,
            $single_section_count
        );

        $stmt->execute();
        $course_id = $conn->insert_id;

        // Insert section
        $stmt = $conn->prepare("
            INSERT INTO sections
            (course_id, section_name, section_title)
            VALUES (?, ?, ?)
        ");

        $section_title = $course_title_with_section;

        $stmt->bind_param(
            "iss",
            $course_id,
            $section_name,
            $section_title
        );

        $stmt->execute();
    }

    $conn->commit();
    trigger_b2_sync();
    header("Location: courses.php?msg=added");
    exit;

} catch (Exception $e) {
    $conn->rollback();
    $error = "Error adding course: " . $e->getMessage();
}
    }
}

include 'includes/header.php';

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

$department_options = ['Computer Science', 'Nursing', 'Theology', 'Business', 'Education', 'General'];
$selected_department = trim((string)($_POST['department'] ?? ($_SESSION['department'] ?? '')));
if ($selected_department === '') {
    $selected_department = 'General';
}
if (!in_array($selected_department, $department_options, true)) {
    $department_options[] = $selected_department;
}
?>

<div class="glass-panel courses-container">

     <!-- <div id="coursesSpinner" class="courses-spinner-overlay" aria-hidden="true">
        <div class="courses-spinner"></div>
        <span class="courses-spinner-label" id="coursesSpinnerLabel">Loading&hellip;</span>
    </div> -->

    <!-- Top Action Bar -->
    <div class="courses-action-bar">
        <form method="GET" class="courses-search-form">
            <input type="text" name="search" class="glass-input" placeholder="Search courses..."
                value="<?php echo htmlspecialchars($search); ?>">
            <select name="level" class="glass-input courses-level-select"
                onchange="this.form.submit()">
                <option value="">Level...</option>
                <option value="100" <?php if ($filter_level == '100' )
    echo 'selected' ; ?>>100</option>
                <option value="200" <?php if ($filter_level == '200' )
    echo 'selected' ; ?>>200</option>
                <option value="300" <?php if ($filter_level == '300' )
    echo 'selected' ; ?>>300</option>
                <option value="400" <?php if ($filter_level == '400' )
    echo 'selected' ; ?>>400</option>
            </select>
            <button type="submit" class="glass-btn"><i class="fa-solid fa-search"></i></button>
        </form>

        <button onclick="document.getElementById('addModal').style.display='flex'" class="glass-btn"><i
                class="fa-solid fa-plus"></i> Add Course</button>
    </div>

    <?php if (isset($_GET['msg']) || isset($error)): ?>
    <script>
        window.addEventListener('load', () => {
            const msg = <?php echo json_encode((string)($_GET['msg'] ?? '')); ?>;
            const errorText = <?php echo json_encode((string)($error ?? '')); ?>;

            if (errorText) {
                showAlert(errorText, 'Course Error', 'error');
                return;
            }

            let title = 'Success';
            let text = 'Operation successful.';
            if (msg === 'added') { title = 'Course Added'; text = 'The new course has been successfully created.'; }
            if (msg === 'updated') { title = 'Course Updated'; text = 'The course details have been successfully updated.'; }
            if (msg === 'deleted') { title = 'Course Deleted'; text = 'The course has been permanently removed.'; }
            if (msg) {
                showAlert(text, title, 'success');
            }
        });
    </script>
    <?php endif; ?>

    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Title</th>
                    <th>Level</th>
                    <th>Semester</th>
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
                    <td data-label="Code"><span class="courses-code-text">
                            <?php echo htmlspecialchars($course['course_code']); ?>
                        </span></td>
                    <td data-label="Title">
                        <?php echo htmlspecialchars($course['course_title']); ?>
                    </td>
                    <td data-label="Level">
                        <?php echo htmlspecialchars($course['level']); ?>
                    </td>
                    <td data-label="Semester">
                        <?php echo htmlspecialchars($course['semester']); ?>
                    </td>
                    <td data-label="Credits">
                        <?php echo htmlspecialchars($course['credit_hours']); ?>
                    </td>
                    <td data-label="Type">
                        <span class="courses-type-badge <?php echo $course['type'] == 'General' ? 'courses-type-badge--general' : 'courses-type-badge--departmental'; ?>">
                            <?php echo htmlspecialchars($course['type']); ?>
                        </span>
                    </td>
                    <td data-label="Lecturer">
                        <?php echo htmlspecialchars($course['lecturer_name'] ?? 'Unassigned'); ?>
                    </td>
                    <td data-label="Actions" class="courses-actions-cell">
                        <div class="courses-actions-wrap">
                            <a href="edit_course.php?id=<?php echo $course['id']; ?>" class="glass-btn secondary courses-edit-btn"><i class="fa-solid fa-pen"></i></a>
                            <form method="POST" class="courses-delete-form"
                                onsubmit="confirmAction(event, 'Delete Course', 'Are you sure you want to delete this course?')">
                                <input type="hidden" name="delete_id" value="<?php echo $course['id'], $course['course_title']; ?>">
                                <input type="hidden" name="course_title" value="<?php echo $course['course_title']; ?>">
                                <button type="submit" class="glass-btn secondary courses-delete-btn"><i class="fa-solid fa-trash"></i></button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php
    endforeach; ?>
                <?php
else: ?>
                <tr class="courses-empty-row">
                    <td colspan="7" class="courses-empty-cell">No courses found.</td>
                </tr>
                <?php
endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination Controls -->
    <?php if ($total_pages > 1): ?>
    <div class="courses-pagination">
        <?php
    $params = $_GET;
    function build_course_query($p, $params)
    {
        $params['page'] = $p;
        return '?' . http_build_query($params);
    }
?>
        <a href="<?php echo build_course_query(max(1, $page - 1), $params); ?>"
            class="glass-btn secondary small <?php if ($page <= 1)
        echo 'disabled courses-pagination-btn--disabled'; ?>">
            <i class="fa-solid fa-chevron-left"></i>
        </a>
        <span class="courses-pagination-info">Page
            <?php echo $page; ?> of
            <?php echo $total_pages; ?>
        </span>
        <a href="<?php echo build_course_query(min($total_pages, $page + 1), $params); ?>"
            class="glass-btn secondary small <?php if ($page >= $total_pages)
        echo 'disabled courses-pagination-btn--disabled'; ?>">
            <i class="fa-solid fa-chevron-right"></i>
        </a>
    </div>
    <?php
endif; ?>
</div>

<!-- Add Modal -->
<div id="addModal" class="courses-modal-overlay">
    <div class="glass-panel courses-modal-panel">
        <h3 class="courses-modal-title">Add New Course</h3>
        <form method="POST">
            <input type="hidden" name="add_course" value="1">
            <div class="courses-modal-form-grid">
                <div>
                    <label class="courses-modal-label">Course Code</label>
                    <input type="text" name="code" class="glass-input" required placeholder="e.g. COSC 110">
                </div>
                <div>
                    <label class="courses-modal-label">Course Title</label>
                    <input type="text" name="title" class="glass-input" required>
                </div>
                <div class="courses-modal-two-col">
                    <div>
                        <label class="courses-modal-label">Level</label>
                        <select name="level" class="glass-input courses-modal-select">
                            <option value="100">100</option>
                            <option value="200">200</option>
                            <option value="300">300</option>
                            <option value="400">400</option>
                        </select>
                    </div>
                    <div>
                        <label class="courses-modal-label">Credits</label>
                        <input type="number" name="credits" class="glass-input" value="3">
                    </div>
                </div>
                <div>
                    <label class="courses-modal-label">Type</label>
                    <select name="type" id="courseTypeSelect" class="glass-input courses-modal-select">
                        <option value="Departmental">Departmental</option>
                        <option value="General">General</option>
                    </select>
                </div>
                <div>
                    <label class="courses-modal-label">Department</label>
                    <select name="department" id="courseDepartmentInput" class="glass-input courses-modal-select">
                        <?php foreach ($department_options as $dept): ?>
                            <option value="<?php echo htmlspecialchars($dept); ?>" <?php echo $selected_department === $dept ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($dept); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="courses-modal-label">Sections</label>
                    <input type="text" name="sections" class="glass-input" value="Sec A" placeholder="e.g. Sec A, Sec B">
                    <small style="color: var(--text-muted);">Separate multiple sections with commas (e.g. Sec A, Sec B)</small>
                </div>
                <div class="courses-modal-actions">
                    <button type="submit" class="glass-btn courses-modal-save-btn">Save</button>
                    <button type="button" onclick="document.getElementById('addModal').style.display='none'"
                        class="glass-btn secondary">Cancel</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
(() => {
    const typeSelect = document.getElementById('courseTypeSelect');
    const departmentInput = document.getElementById('courseDepartmentInput');
    if (!typeSelect || !departmentInput) return;

    const syncDepartmentFromType = () => {
        if (typeSelect.value === 'General') {
            departmentInput.value = 'General';
        }
    };

    typeSelect.addEventListener('change', syncDepartmentFromType);
})();

function showSpinner(message = 'Loading&hellip;') {
    const spinnerOverlay = document.getElementById('coursesSpinner');
    const spinnerLabel = document.getElementById('coursesSpinnerLabel');
    if (spinnerOverlay && spinnerLabel) {
        spinnerLabel.textContent = message || 'Loading';
        spinnerOverlay.style.display = 'flex'; spinnerOverlay.removeAttribute('aria-hidden');
    }
}

function hideSpinner() {
    const spinnerOverlay = document.getElementById('coursesSpinner');
    if (spinnerOverlay) {
        spinnerOverlay.style.display = 'none'; spinnerOverlay.setAttribute('aria-hidden', 'true');
    }
}
</script>

<?php include 'includes/footer.php'; ?>