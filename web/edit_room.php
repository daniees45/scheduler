<?php
$page_title = 'Edit Room';
include 'includes/header.php';
require_once 'api/db.php';

$id = $_GET['id'] ?? 0;
if (!$id) {
    header("Location: rooms.php");
    exit;
}

// Fetch Room
$stmt = $conn->prepare("SELECT * FROM rooms WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$res = $stmt->get_result();
$room = $res->fetch_assoc();

if (!$room) {
    echo "<div class='glass-panel' style='padding: 2rem; text-align: center;'>Room not found.</div>";
    include 'includes/footer.php';
    exit;
}

// Handle Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'];
    $capacity = $_POST['capacity'];
    $type = $_POST['type'];
    $dept = $_POST['primary_dept'];
    $equipment = $_POST['equipment'];
    $access = isset($_POST['accessibility']) ? 1 : 0;

    $up_stmt = $conn->prepare("UPDATE rooms SET room_name=?, capacity=?, type=?, primary_dept=?, equipment=?, accessibility=? WHERE id=?");
    $up_stmt->bind_param("sisssii", $name, $capacity, $type, $dept, $equipment, $access, $id);

    if ($up_stmt->execute()) {
        // Sync to CSV after update
        syncRoomsToCSV($conn);
        trigger_b2_sync();

        echo "<script>
            window.addEventListener('load', async () => {
                await customAlert('Room Updated', 'The room details have been successfully updated.', 'success');
                window.location.href = 'rooms.php';
            });
        </script>";
    }
    else {
        $error = "Update failed: " . $conn->error;
    }
}

function syncRoomsToCSV($conn)
{
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
?>

<div class="glass-panel" style="padding: 2rem; max-width: 600px; margin: 0 auto;">
    <h2 style="margin-bottom: 2rem;">Edit Room:
        <?php echo htmlspecialchars($room['room_name']); ?>
    </h2>

    <?php if (isset($error)): ?>
    <div class="alert alert-danger">
        <?php echo $error; ?>
    </div>
    <?php
endif; ?>

    <form method="POST">
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
            <div class="form-group">
                <label>Room Name</label>
                <input type="text" name="name" class="glass-input" required
                    value="<?php echo htmlspecialchars($room['room_name']); ?>">
            </div>

            <div class="form-group">
                <label>Capacity</label>
                <input type="number" name="capacity" class="glass-input" required
                    value="<?php echo htmlspecialchars($room['capacity']); ?>">
            </div>

            <div class="form-group">
                <label>Type</label>
                <select name="type" class="glass-input" style="background: rgba(15,23,42,0.9);">
                    <option value="Lecture" <?php if ($room['type'] == 'Lecture' )
    echo 'selected' ; ?>>Lecture Hall
                    </option>
                    <option value="Lab" <?php if ($room['type'] == 'Lab' )
    echo 'selected' ; ?>>Laboratory</option>
                    <option value="Auditorium" <?php if ($room['type'] == 'Auditorium' )
    echo 'selected' ; ?>>Auditorium
                    </option>
                </select>
            </div>

            <div class="form-group">
                <label>Primary Department</label>
                <input type="text" name="primary_dept" class="glass-input" placeholder="e.g. Nursing"
                    value="<?php echo htmlspecialchars($room['primary_dept'] ?? ''); ?>">
                <small style="color: var(--text-muted);">Optional. If set, AI prioritizes this room for this
                    dept.</small>
            </div>
        </div>

        <div class="form-group" style="margin-top: 1.5rem;">
            <label>Equipment & Features</label>
            <textarea name="equipment" class="glass-input" rows="3"
                placeholder="Projector, Smartboard, Sink..."><?php echo htmlspecialchars($room['equipment'] ?? ''); ?></textarea>
        </div>

        <div class="form-group" style="margin-top: 1rem;">
            <label
                style="display: flex; align-items: center; gap: 10px; cursor: pointer; padding: 10px; background: rgba(255,255,255,0.05); border-radius: 8px;">
                <input type="checkbox" name="accessibility" value="1" <?php if ($room['accessibility'])
    echo 'checked' ;
                    ?>>
                <span><i class="fa-solid fa-wheelchair"></i> Wheelchair Accessible</span>
            </label>
        </div>

        <div style="margin-top: 2rem; display: flex; gap: 1rem;">
            <button type="submit" class="glass-btn" style="flex: 2;"><i class="fa-solid fa-save"></i> Save
                Changes</button>
            <a href="rooms.php" class="glass-btn secondary" style="flex: 1; text-align: center;">Cancel</a>
        </div>
    </form>
</div>

<?php include 'includes/footer.php'; ?>