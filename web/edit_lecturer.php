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
// Success message flag
$update_success = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'];
    $email = $_POST['email'];
    $department = $_POST['department'] ?? null;
    $days = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday"];
    $availability = $_POST['availability'] ?? [];
    $clean_days = [];
    foreach ($availability as $d) {
        if (is_numeric($d)) {
            $d = (int)$d;
            if ($d >= 0 && $d <= 4) {
                $clean_days[] = $d;
            }
        }
    }
    $clean_days = array_values(array_unique($clean_days));
    $availability_json = json_encode($clean_days);

    $stmt = $conn->prepare("UPDATE lecturers SET name=?, email=?, department=?, availability_json=? WHERE id=?");
    $stmt->bind_param("ssssi", $name, $email, $department, $availability_json, $id);
    $stmt->execute();
    trigger_b2_sync();

    // Refresh lecturer data for display
    $stmt = $conn->prepare("SELECT * FROM lecturers WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $lecturer = $stmt->get_result()->fetch_assoc();
    $update_success = true;
}
?>

<div class="glass-panel edit-lecturer-container">
    <h3 class="edit-lecturer-title">Edit Lecturer</h3>
    <?php if (!empty($update_success)): ?>
        <div style="margin-bottom: 1rem; padding: 0.8rem 1rem; background: #e0ffe0; color: #166534; border: 1px solid #22c55e; border-radius: 6px; font-weight: 600;">
            <i class="fa-solid fa-circle-check"></i> Lecturer updated!
        </div>
        <script>setTimeout(function(){ document.querySelector('.edit-lecturer-container .fa-circle-check').parentElement.style.display = 'none'; }, 2500);</script>
    <?php endif; ?>
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

            <div>
                <label class="stat-label">Preferred Teaching Days</label>
                <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-top: 0.5em;">
                    <?php 
                        $days = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday"];
                        $availability = json_decode($lecturer['availability_json'] ?? '[]', true);
                    ?>
                    <?php foreach ($days as $idx => $day): ?>
                        <label style="display: flex; align-items: center; gap: 4px;">
                            <input type="checkbox" name="availability[]" value="<?php echo $idx; ?>" <?php if (in_array($idx, $availability)) echo 'checked'; ?>>
                            <?php echo htmlspecialchars($day); ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="edit-lecturer-actions">
                <button type="submit" class="glass-btn edit-lecturer-submit-btn">Update Lecturer</button>
                <a href="lecturers.php" class="glass-btn secondary edit-lecturer-cancel-btn">Cancel</a>
            </div>
        </div>
    </form>
</div>

<?php include 'includes/footer.php'; ?>