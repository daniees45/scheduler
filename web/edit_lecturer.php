<?php
$page_title = 'Edit Lecturer';
$page_css = 'assets/edit_lecturer.css';
include 'includes/header.php';
require_once 'api/db.php';

$id = $_GET['id'] ?? null;
if (!$id) {
    header("Location: lecturers.php");
    exit;
}

// Fetch existing
$stmt = $conn->prepare("SELECT * FROM lecturers WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$lecturer = $stmt->get_result()->fetch_assoc();

if (!$lecturer) {
    echo "<div class='glass-panel edit-lecturer-not-found'>Lecturer not found.</div>";
    include 'includes/footer.php';
    exit;
}

// Handle Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'];
    $email = $_POST['email'];
    $department = $_POST['department'] ?? null;

    $stmt = $conn->prepare("UPDATE lecturers SET name=?, email=?, department=? WHERE id=?");
    $stmt->bind_param("sssi", $name, $email, $department, $id);
    $stmt->execute();
    trigger_b2_sync();

    header("Location: lecturers.php?msg=updated");
    exit;
}
?>

<div class="glass-panel edit-lecturer-container">
    <h3 class="edit-lecturer-title">Edit Lecturer</h3>

    <form method="POST">
        <div class="edit-lecturer-form-grid">
            <div>
                <label class="stat-label">Full Name</label>
                <input type="text" name="name" class="glass-input"
                    value="<?php echo htmlspecialchars($lecturer['name']); ?>" required>
            </div>

            <div>
                <label class="stat-label">Email</label>
                <input type="email" name="email" class="glass-input"
                    value="<?php echo htmlspecialchars($lecturer['email']); ?>">
            </div>

            <div>
                <label class="stat-label">Department</label>
                <select name="department" class="glass-input">
                    <option value="">Select Department...</option>
                    <option value="Computer Science" <?php echo ($lecturer['department']==='Computer Science' )
                        ? 'selected' : '' ; ?>>Computer Science</option>
                    <option value="Nursing" <?php echo ($lecturer['department']==='Nursing' ) ? 'selected' : '' ; ?>
                        >Nursing</option>
                    <option value="Theology" <?php echo ($lecturer['department']==='Theology' ) ? 'selected' : '' ; ?>
                        >Theology</option>
                    <option value="Business" <?php echo ($lecturer['department']==='Business' ) ? 'selected' : '' ; ?>
                        >Business</option>
                    <option value="Education" <?php echo ($lecturer['department']==='Education' ) ? 'selected' : '' ; ?>
                        >Education</option>
                    <option value="General" <?php echo ($lecturer['department']==='General' ) ? 'selected' : '' ; ?>
                        >General</option>
                </select>
            </div>

            <div class="edit-lecturer-actions">
                <button type="submit" class="glass-btn edit-lecturer-submit-btn">Update Lecturer</button>
                <a href="lecturers.php" class="glass-btn secondary edit-lecturer-cancel-btn">Cancel</a>
            </div>
        </div>
    </form>
</div>

<?php include 'includes/footer.php'; ?>