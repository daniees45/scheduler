<?php
$page_title = 'Departments';
$page_css = 'assets/style.css';
include 'includes/header.php';
require_once 'api/db.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$error = '';
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$edit_id = $_GET['edit'] ?? null;

try {

    // HANDLE FORM ACTIONS
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        $name = trim($_POST['name'] ?? '');
        $code = trim($_POST['code'] ?? '');
        $id = $_POST['id'] ?? null;

        // Detect General department
        $is_general = (strcasecmp(trim($name), 'General') === 0);

        // General does not need code
        if ($is_general) {
            $code = '';
        }

        // ADD DEPARTMENT
        if ($action === 'add') {

            if ($name === '') {
                $error = 'Department name is required.';
            }
            elseif (!$is_general && $code === '') {
                $error = 'Department code is required.';
            }
            else {

                // Prevent duplicates
                $check = $conn->prepare(
                    "SELECT id FROM departments WHERE LOWER(name)=LOWER(?)"
                );
                $check->bind_param("s", $name);
                $check->execute();

                if ($check->get_result()->num_rows > 0) {
                    $error = 'Department already exists.';
                } else {

                    $stmt = $conn->prepare(
                        "INSERT INTO departments (name, code)
                         VALUES (?, ?)"
                    );
                    $stmt->bind_param("ss", $name, $code);
                    $stmt->execute();

                    echo "
                    <script>
                    window.addEventListener('DOMContentLoaded',()=>{
                        showAlert(
                            'Department added successfully',
                            'Added',
                            'success'
                        ).then(()=>{
                            window.location.href='departments.php';
                        });
                    });
                    </script>";
                    exit;
                }
            }
        }

        // EDIT DEPARTMENT
        elseif ($action === 'edit' && $id) {

            // Prevent editing General
            $checkGeneral = $conn->prepare(
                "SELECT name FROM departments WHERE id=?"
            );
            $checkGeneral->bind_param("i", $id);
            $checkGeneral->execute();
            $currentDept = $checkGeneral
                ->get_result()
                ->fetch_assoc();

            if (
                $currentDept &&
                strtolower($currentDept['name']) === 'general'
            ) {
                $error = 'General department cannot be edited.';
            }
            elseif ($name === '') {
                $error = 'Department name is required.';
            }
            elseif (!$is_general && $code === '') {
                $error = 'Department code is required.';
            }
            else {

                // Prevent duplicate names excluding current row
                $dup = $conn->prepare(
                    "SELECT id FROM departments
                     WHERE LOWER(name)=LOWER(?)
                     AND id != ?"
                );
                $dup->bind_param("si", $name, $id);
                $dup->execute();

                if ($dup->get_result()->num_rows > 0) {
                    $error = 'Another department with this name already exists.';
                } else {

                    $stmt = $conn->prepare(
                        "UPDATE departments
                         SET name=?, code=?
                         WHERE id=?"
                    );
                    $stmt->bind_param(
                        "ssi",
                        $name,
                        $code,
                        $id
                    );
                    $stmt->execute();

                    echo "
                    <script>
                    window.addEventListener('DOMContentLoaded',()=>{
                        showAlert(
                            'Department updated successfully',
                            'Updated',
                            'success'
                        ).then(()=>{
                            window.location.href='departments.php';
                        });
                    });
                    </script>";
                    exit;
                }
            }
        }

        // DELETE DEPARTMENT
        elseif ($action === 'delete' && $id) {

            // Prevent deleting General
            $check = $conn->prepare(
                "SELECT name FROM departments WHERE id=?"
            );
            $check->bind_param("i", $id);
            $check->execute();
            $dept = $check->get_result()->fetch_assoc();

            if (
                $dept &&
                strtolower($dept['name']) === 'general'
            ) {
                $error = 'General department cannot be deleted.';
            } else {

                $stmt = $conn->prepare(
                    "DELETE FROM departments WHERE id=?"
                );
                $stmt->bind_param("i", $id);
                $stmt->execute();

                echo "
                <script>
                window.addEventListener('DOMContentLoaded',()=>{
                    showAlert(
                        'Department deleted successfully',
                        'Deleted',
                        'success'
                    ).then(()=>{
                        window.location.href='departments.php';
                    });
                });
                </script>";
                exit;
            }
        }
    }

} catch (Exception $e) {
    $error = $e->getMessage();
}

// FETCH DEPARTMENTS
$res = $conn->query(
    "SELECT * FROM departments ORDER BY name ASC"
);
$departments = $res
    ? $res->fetch_all(MYSQLI_ASSOC)
    : [];

// FETCH EDIT RECORD
$edit_department = null;

if ($edit_id) {
    $stmt = $conn->prepare(
        "SELECT * FROM departments WHERE id=?"
    );
    $stmt->bind_param("i", $edit_id);
    $stmt->execute();
    $edit_department = $stmt
        ->get_result()
        ->fetch_assoc();
}
?>

<div class="glass-panel departments-panel">

    <div style="
        margin-bottom:1rem;
        color:var(--text-muted);
        font-size:1.05em;
    ">
        <b>Note:</b>
        <span style="color:var(--primary-color)">
            General
        </span>
        is a special department for courses
        available to all departments.
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <form method="POST"
          class="departments-form"
          style="margin-bottom:2rem;margin-top:1rem;">

        <input type="hidden"
               name="action"
               value="<?php echo $edit_department ? 'edit' : 'add'; ?>">

        <?php if ($edit_department): ?>
            <input type="hidden"
                   name="id"
                   value="<?php echo $edit_department['id']; ?>">
        <?php endif; ?>

        <div style="
            display:flex;
            gap:1rem;
            align-items:center;
        ">

            <input type="text"
                   name="name"
                   class="glass-input"
                   placeholder="Department Name"
                   value="<?php echo htmlspecialchars($edit_department['name'] ?? ''); ?>"
                   required
                   oninput="toggleCodeRequired(this.value)">

            <input type="text"
                   name="code"
                   id="deptCodeInput"
                   class="glass-input"
                   placeholder="Code"
                   value="<?php echo htmlspecialchars($edit_department['code'] ?? ''); ?>"
                   style="width:120px;">

            <button type="submit"
                    class="glass-btn primary">
                <?php echo $edit_department ? 'Update' : 'Add'; ?>
            </button>

            <?php if ($edit_department): ?>
                <a href="departments.php"
                   class="glass-btn secondary">
                    Cancel
                </a>
            <?php endif; ?>

        </div>
    </form>

    <table class="sketch-data-table"
           style="width:100%;">

        <thead>
            <tr>
                <th>Name</th>
                <th>Code</th>
                <th>Actions</th>
            </tr>
        </thead>

        <tbody>
            <?php foreach ($departments as $dept): ?>
                <tr <?php if (strtolower($dept['name'])==='general') echo 'style="background:rgba(16,185,129,0.08);font-style:italic;"'; ?>>

                    <td>
                        <?php echo htmlspecialchars($dept['name']); ?>

                        <?php if (strtolower($dept['name'])==='general'): ?>
                            <span style="
                                color:var(--primary-color);
                                font-size:0.95em;
                            ">
                                (All Departments)
                            </span>
                        <?php endif; ?>
                    </td>

                    <td>
                        <?php echo htmlspecialchars($dept['code']); ?>
                    </td>

                    <td>

                        <?php if (strtolower($dept['name'])!=='general'): ?>

                            <a href="#"
                               class="glass-btn small"
                               onclick="editDepartment(<?php echo $dept['id']; ?>);return false;">
                               Edit
                            </a>

                            <form method="POST"
                                  style="display:inline;"
                                  onsubmit="return confirmDeleteDepartment(this);">

                                <input type="hidden"
                                       name="action"
                                       value="delete">

                                <input type="hidden"
                                       name="id"
                                       value="<?php echo $dept['id']; ?>">

                                <button type="submit"
                                        class="glass-btn small danger">
                                    Delete
                                </button>
                            </form>

                        <?php else: ?>
                            <span style="
                                color:var(--text-muted);
                                font-size:0.95em;
                            ">
                                (Cannot edit/delete)
                            </span>
                        <?php endif; ?>

                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>

    </table>

</div>

<script>
function toggleCodeRequired(name) {

    let codeInput =
        document.getElementById('deptCodeInput');

    if (name.trim().toLowerCase() === 'general') {
        codeInput.required = false;
        codeInput.placeholder =
            'Code not required';
    } else {
        codeInput.required = true;
        codeInput.placeholder = 'Code';
    }
}

document.addEventListener(
    'DOMContentLoaded',
    function() {

        let nameInput =
            document.querySelector(
                'input[name="name"]'
            );

        if (nameInput) {
            toggleCodeRequired(nameInput.value);
        }
    }
);

function confirmDeleteDepartment(form) {

    if (window.showConfirm) {

        showConfirm(
            'Delete this department?',
            'Confirm Delete'
        ).then(function(confirmed){

            if (confirmed) {
                form.submit();
            }
        });

        return false;
    }

    return confirm(
        'Delete this department?'
    );
}

function editDepartment(id) {
    window.location.href =
        'departments.php?edit=' + id;
}
</script>

<?php include 'includes/footer.php'; ?>