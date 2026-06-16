<?php
$page_title = 'Edit Course';
$page_css = 'assets/edit_course.css';
include 'includes/header.php';
require_once 'api/db.php';

$id = $_GET['id'] ?? null;
if (!$id) {
    header("Location: courses.php");
    exit;
}



// Fetch course with its assigned lecturer
$stmt = $conn->prepare("SELECT c.*, s.lecturer_id FROM courses c LEFT JOIN sections s ON c.id = s.course_id WHERE c.id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$course = $stmt->get_result()->fetch_assoc();

// Fetch all sections for this course
$sections = [];
$section_stmt = $conn->prepare("SELECT id, section_name, section_title FROM sections WHERE course_id = ? ORDER BY section_name ASC");
$section_stmt->bind_param("i", $id);
$section_stmt->execute();
$sections = $section_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

if (!$course) {
    echo "<div class='glass-panel edit-course-not-found'>Course not found.</div>";
    include 'includes/footer.php';
    exit;
}

// Fetch all lecturers for the dropdown

// Fetch all lecturers for the dropdown
$res = $conn->query("SELECT id, name FROM lecturers ORDER BY name ASC");
$lecturers = $res->fetch_all(MYSQLI_ASSOC);

// Fetch all departments for the dropdown
$dept_res = $conn->query("SELECT name FROM departments ORDER BY name ASC");
$departments = $dept_res ? $dept_res->fetch_all(MYSQLI_ASSOC) : [];

// Handle Update



if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = $_POST['code'];
    $title = $_POST['title'];
    $level = $_POST['level'];
    $credits = $_POST['credits'];
    $type = $_POST['type'];
    $lecturer_id = $_POST['lecturer_id'] ?: null;
    $department = $_POST['department'] ?? '';
    $semester = $_POST['semester'] ?? '';
    $section_names = $_POST['section_names'] ?? [];

    try {
        $conn->begin_transaction();


        $base_title = preg_replace('/\s*\[.*\]$/', '', $title);
        foreach ($section_names as $section_id => $value) {
            if (empty($value)) {
                $section_title = $base_title ;
            } else {
               $section_title = $base_title . ' [' . $value . ']';
            }
            
        }

        $stmt = $conn->prepare("UPDATE courses SET course_code=?, course_title=?, level=?, credit_hours=?, type=?, department=?, semester=? WHERE id=?");
        $stmt->bind_param("ssiisssi", $code, $section_title, $level, $credits, $type, $department, $semester, $id);
        $stmt->execute();

        // Remove any trailing [ ... ] from course title for section title generation
        $base_title = preg_replace('/\s*\[.*\]$/', '', $title);
        // Update section names and titles
        foreach ($section_names as $section_id => $section_name) {
            $section_title = $base_title . ' [' . $section_name . ']';
            $stmt = $conn->prepare("UPDATE sections SET section_name = ?, section_title = ? WHERE id = ?");
            $stmt->bind_param("ssi", $section_name, $section_title, $section_id);
            $stmt->execute();
        }

        // Update or Insert section lecturer
        $stmt = $conn->prepare("SELECT id FROM sections WHERE course_id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) {
            $stmt = $conn->prepare("UPDATE sections SET lecturer_id = ? WHERE course_id = ?");
            $stmt->bind_param("ii", $lecturer_id, $id);
            $stmt->execute();
        } else {
            $stmt = $conn->prepare("INSERT INTO sections (course_id, lecturer_id) VALUES (?, ?)");
            $stmt->bind_param("ii", $id, $lecturer_id);
            $stmt->execute();
        }

        $conn->commit();
        trigger_b2_sync();
        echo "<script>
            window.addEventListener('load', async () => {
                await customAlert('Course Updated', 'The course details have been successfully updated.', 'success');
                window.location.href = 'courses.php';
            });
        </script>";
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        $error = "Update failed: " . $e->getMessage();
    }
}
?>

<div class="glass-panel edit-course-panel">
    <h3 class="edit-course-title">Edit Course</h3>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger edit-course-alert">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php
    endif; ?>

    <form method="POST">
        <div class="edit-course-form-grid">
            <div>
                <label class="stat-label">Course Code</label>
                <?php if (!empty($course['course_title'])) {
                    $clean_title = preg_replace('/\s*\[.*?\]/', '', $course['course_title']);
                } ?>
                <input type="text" name="code" class="glass-input"
                    value="<?php echo htmlspecialchars($course['course_code']); ?>" required>

            </div>


            <div>
                <label class="stat-label">Course Title</label>
                <input type="text" name="title" class="glass-input"
                    value="<?php echo htmlspecialchars($clean_title); ?>" required>
            </div>

            <?php if (!empty($sections)): ?>
                <div>
                    <label class="stat-label">Sections</label>
                    <?php foreach ($sections as $section): ?>
                        <div style="margin-bottom: 0.5rem; display: flex; align-items: center; gap: 1rem;">
                            <input type="text" name="section_names[<?php echo $section['id']; ?>]" class="glass-input section-name-input" data-section-id="<?php echo $section['id']; ?>" value="<?php echo htmlspecialchars($section['section_name']); ?>" style="width: 180px;"  oninput="updateSectionTitle(<?php echo $section['id']; ?>)">
                            <input type="text" name="section_titles[<?php echo $section['id']; ?>]" class="glass-input section-title-input" data-section-id="<?php echo $section['id']; ?>" value="<?php echo htmlspecialchars($section['section_title']); ?>" style="width: 320px; color: var(--primary-color); font-family: monospace;" readonly required>
                        </div>
                    <?php endforeach; ?>
                    <small style="color: var(--text-muted);">Edit section names (e.g. Sec A, Sec B). Title will be auto-generated as "Course Title [Section]".</small>
                </div>
                <script>
                    function updateSectionTitle(sectionId) {
                        var courseTitle = document.querySelector('input[name="title"]')?.value || '';
                        // Remove any trailing [ ... ] from course title
                        courseTitle = courseTitle.replace(/\s*\[.*\]$/, '');
                        var nameInput = document.querySelector('input.section-name-input[data-section-id="' + sectionId + '"]');
                        var titleInput = document.querySelector('input.section-title-input[data-section-id="' + sectionId + '"]');
                        if (nameInput && titleInput.value.trim() !== '') {
                            titleInput.value = courseTitle + ' [' + nameInput.value + ']';
                        }else{
                            titleInput.value = courseTitle ;
                        }
                    }
                    // Update all section titles if course title changes
                    document.querySelector('input[name="title"]').addEventListener('input', function() {
                        document.querySelectorAll('input.section-name-input').forEach(function(nameInput) {
                            var sectionId = nameInput.getAttribute('data-section-id');
                            updateSectionTitle(sectionId);
                        });
                    });
                </script>
            <?php endif; ?>


            <div class="edit-course-two-col-grid">
                <div>
                    <label class="stat-label">Level</label>
                    <select name="level" class="glass-input edit-course-select">
                        <?php foreach (["100", "200", "300", "400"] as $l): ?>
                            <option value="<?php echo $l; ?>" <?php if ($course['level'] == $l) echo 'selected'; ?>>
                                <?php echo $l; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="stat-label">Credits</label>
                    <input type="number" name="credits" class="glass-input"
                        value="<?php echo htmlspecialchars($course['credit_hours']); ?>">
                </div>
                <div>
                    <label class="stat-label">Semester</label>
                    <select name="semester" class="glass-input edit-course-select">
                        <?php foreach (["1", "2", "summer 1", "summer 11"] as $sem): ?>
                            <option value="<?php echo $sem; ?>" <?php if (($course['semester'] ?? '') == $sem) echo 'selected'; ?>>
                                <?php echo ucfirst($sem); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="stat-label">Type</label>
                    <select name="type" class="glass-input edit-course-select">
                        <option value="Departmental" <?php if ($course['type'] == 'Departmental')
                                                            echo 'selected'; ?>>Departmental</option>
                        <option value="General" <?php if ($course['type'] == 'General')
                                                    echo 'selected'; ?>>General</option>
                    </select>
                </div>

                <div>
                    <label class="stat-label">Assigned Lecturer</label>
                    <select name="lecturer_id" class="glass-input edit-course-select">
                        <option value="">-- Unassigned --</option>
                        <?php foreach ($lecturers as $l): ?>
                            <option value="<?php echo $l['id']; ?>" <?php if ($course['lecturer_id'] == $l['id'])
                                                                        echo 'selected';
                                                                    ?>>
                                <?php echo htmlspecialchars($l['name']); ?>
                            </option>
                        <?php
                        endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="stat-label">Department</label>
                    <select name="department" class="glass-input edit-course-select">
                        <option value="">-- Select Department --</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo htmlspecialchars($dept['name']); ?>" <?php if (($course['department'] ?? '') == $dept['name']) echo 'selected'; ?>>
                                <?php echo htmlspecialchars($dept['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>




            <div class="edit-course-actions">
                <button type="submit" class="glass-btn edit-course-submit">Update Course</button>
                <a href="courses.php" class="glass-btn secondary edit-course-cancel">Cancel</a>
            </div>
        </div>
    </form>
</div>

<?php include 'includes/footer.php'; ?>