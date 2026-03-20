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

if (!$course) {
    echo "<div class='glass-panel edit-course-not-found'>Course not found.</div>";
    include 'includes/footer.php';
    exit;
}

// Fetch all lecturers for the dropdown
$res = $conn->query("SELECT id, name FROM lecturers ORDER BY name ASC");
$lecturers = $res->fetch_all(MYSQLI_ASSOC);

// Handle Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = $_POST['code'];
    $title = $_POST['title'];
    $level = $_POST['level'];
    $credits = $_POST['credits'];
    $type = $_POST['type'];
    $lecturer_id = $_POST['lecturer_id'] ?: null;

    try {
        $conn->begin_transaction();

        // Update course
        $stmt = $conn->prepare("UPDATE courses SET course_code=?, course_title=?, level=?, credit_hours=?, type=? WHERE id=?");
        $stmt->bind_param("ssiisi", $code, $title, $level, $credits, $type, $id);
        $stmt->execute();

        // Update or Insert section
        $stmt = $conn->prepare("SELECT id FROM sections WHERE course_id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) {
            $stmt = $conn->prepare("UPDATE sections SET lecturer_id = ? WHERE course_id = ?");
            $stmt->bind_param("ii", $lecturer_id, $id);
            $stmt->execute();
        }
        else {
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
    }
    catch (Exception $e) {
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
                <input type="text" name="code" class="glass-input"
                    value="<?php echo htmlspecialchars($course['course_code']); ?>" required>
            </div>

            <div>
                <label class="stat-label">Course Title</label>
                <input type="text" name="title" class="glass-input"
                    value="<?php echo htmlspecialchars($course['course_title']); ?>" required>
            </div>

            <div class="edit-course-two-col-grid">
                <div>
                    <label class="stat-label">Level</label>
                    <select name="level" class="glass-input edit-course-select">
                        <?php foreach (['100', '200', '300', '400'] as $l): ?>
                        <option value="<?php echo $l; ?>" <?php if ($course['level'] == $l)
        echo 'selected' ; ?>>
                            <?php echo $l; ?>
                        </option>
                        <?php
endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="stat-label">Credits</label>
                    <input type="number" name="credits" class="glass-input"
                        value="<?php echo htmlspecialchars($course['credit_hours']); ?>">
                </div>
            </div>

            <div>
                <label class="stat-label">Type</label>
                <select name="type" class="glass-input edit-course-select">
                    <option value="Departmental" <?php if ($course['type'] == 'Departmental' )
    echo 'selected' ; ?>
                        >Departmental</option>
                    <option value="General" <?php if ($course['type'] == 'General' )
    echo 'selected' ; ?>>General</option>
                </select>
            </div>

            <div>
                <label class="stat-label">Assigned Lecturer</label>
                <select name="lecturer_id" class="glass-input edit-course-select">
                    <option value="">-- Unassigned --</option>
                    <?php foreach ($lecturers as $l): ?>
                    <option value="<?php echo $l['id']; ?>" <?php if ($course['lecturer_id'] == $l['id'])
        echo 'selected' ;
                        ?>>
                        <?php echo htmlspecialchars($l['name']); ?>
                    </option>
                    <?php
endforeach; ?>
                </select>
            </div>

            <div class="edit-course-actions">
                <button type="submit" class="glass-btn edit-course-submit">Update Course</button>
                <a href="courses.php" class="glass-btn secondary edit-course-cancel">Cancel</a>
            </div>
        </div>
    </form>
</div>

<?php include 'includes/footer.php'; ?>