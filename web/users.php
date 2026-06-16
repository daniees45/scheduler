<?php
$page_title = 'Manage Users';
include 'includes/header.php';
require_once 'api/db.php';

// Role Check
requireRole(['super_admin', 'faculty_admin']);

// Fetch Lecturers for linking
$lecturers = [];
$lecturer_res = $conn->query("SELECT id, name, department FROM lecturers ORDER BY name ASC");
if ($lecturer_res) {
    $lecturers = $lecturer_res->fetch_all(MYSQLI_ASSOC);
}

// Handle Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_id'])) {
    $update_id = (int)$_POST['update_id'];
    $username = trim($_POST['username'] ?? '');
    $fullname = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = trim($_POST['role'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $level = isset($_POST['level']) && $_POST['level'] !== '' ? (int)$_POST['level'] : null;
    $lecturer_id = !empty($_POST['lecturer_id']) ? (int)$_POST['lecturer_id'] : null;

    if ($update_id <= 0) {
        $error = 'Invalid user selected.';
    } elseif ($username === '' || $role === '') {
        $error = 'Username and role are required.';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please provide a valid email address.';
    } else {
        // Fetch current user for constraints
        $current_stmt = $conn->prepare("SELECT id, role, department FROM users WHERE id = ?");
        $current_stmt->bind_param("i", $update_id);
        $current_stmt->execute();
        $current_res = $current_stmt->get_result();
        $current_user = $current_res ? $current_res->fetch_assoc() : null;

        if (!$current_user) {
            $error = 'User not found.';
        } else {
            $is_self = ($update_id == $_SESSION['user_id']);

            if ($_SESSION['role'] === 'faculty_admin') {
                if (!isset($_SESSION['department']) || $current_user['department'] !== $_SESSION['department']) {
                    $error = 'You can only edit users in your department.';
                }
                if ($role === 'super_admin') {
                    $error = 'Faculty admins cannot assign super admin role.';
                }
                $department = $_SESSION['department'] ?? $department;
            }

            if (empty($error)) {
                // For self edits, keep current role to avoid lockout
                if ($is_self) {
                    $role = $current_user['role'];
                }

                if ($role === 'lecturer') {
                    if ($lecturer_id) {
                        $lect_stmt = $conn->prepare("SELECT name, department FROM lecturers WHERE id = ?");
                        $lect_stmt->bind_param("i", $lecturer_id);
                        $lect_stmt->execute();
                        $lect_res = $lect_stmt->get_result();
                        if ($lect_res && ($lect = $lect_res->fetch_assoc())) {
                            $fullname = $lect['name'];
                            if ($department === '' && !empty($lect['department'])) {
                                $department = $lect['department'];
                            }
                        }
                    } else {
                        $error = 'Lecturer accounts must be linked to a lecturer profile.';
                    }
                } else {
                    $lecturer_id = null;
                }
            }

            if (empty($error)) {
                $check_stmt = $conn->prepare("SELECT id FROM users WHERE username = ? AND id <> ?");
                $check_stmt->bind_param("si", $username, $update_id);
                $check_stmt->execute();
                if ($check_stmt->get_result()->num_rows > 0) {
                    $error = 'Username already exists.';
                }
            }

            if (empty($error) && $email !== '') {
                $email_check_stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1");
                $email_check_stmt->bind_param("si", $email, $update_id);
                $email_check_stmt->execute();
                if ($email_check_stmt->get_result()->num_rows > 0) {
                    $error = 'Email already exists.';
                }
            }

            if (empty($error)) {
                $update_stmt = $conn->prepare("UPDATE users SET username = ?, full_name = ?, email = ?, role = ?, department = ?, level = ?, lecturer_id = ? WHERE id = ?");
                $update_stmt->bind_param("sssssiii", $username, $fullname, $email, $role, $department, $level, $lecturer_id, $update_id);

                if ($update_stmt->execute()) {
                    $message = 'User updated successfully.';
                } else {
                    $error = 'Failed to update user.';
                }
            }
        }
    }
}

// Handle Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    if ($_POST['delete_id'] == $_SESSION['user_id']) {
        $error = "You cannot delete yourself.";
    } else {
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->bind_param("i", $_POST['delete_id']);
        $stmt->execute();
        header("Location: users.php?msg=deleted");
        exit;
    }
}

// Pagination
$items_per_page = 10;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $items_per_page;

$total_items = $conn->query("SELECT COUNT(*) FROM users")->fetch_row()[0];
$total_pages = ceil($total_items / $items_per_page);

// Fetch Users
$stmt = $conn->prepare("SELECT * FROM users ORDER BY created_at DESC LIMIT ? OFFSET ?");
$stmt->bind_param("ii", $items_per_page, $offset);
$stmt->execute();
$users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>

<div class="glass-panel" style="padding: 1.5rem;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
        <h3>System Users</h3>
        <a href="register.php" class="glass-btn"><i class="fa-solid fa-user-plus"></i> Add New User</a>
    </div>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if (isset($message)): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Username</th>
                    <th>Full Name</th>
                    <th>Role</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                <tr>
                    <td data-label="Username"><?php echo htmlspecialchars($u['username']); ?></td>
                    <td data-label="Full Name"><?php echo htmlspecialchars($u['full_name']); ?></td>
                    <td data-label="Role">
                        <span style="padding: 4px 8px; border-radius: 4px; background: rgba(255,255,255,0.1); font-size: 0.8rem;">
                            <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $u['role']))); ?>
                        </span>
                    </td>
                    <td data-label="Actions">
                        <button type="button" class="glass-btn secondary" style="padding: 6px 10px;" onclick="openEditUserModal(this)"
                            data-id="<?php echo (int)$u['id']; ?>"
                            data-username="<?php echo htmlspecialchars($u['username']); ?>"
                            data-fullname="<?php echo htmlspecialchars($u['full_name']); ?>"
                            data-email="<?php echo htmlspecialchars($u['email'] ?? ''); ?>"
                            data-role="<?php echo htmlspecialchars($u['role']); ?>"
                            data-department="<?php echo htmlspecialchars($u['department'] ?? ''); ?>"
                            data-level="<?php echo htmlspecialchars($u['level'] ?? ''); ?>"
                            data-lecturer-id="<?php echo htmlspecialchars($u['lecturer_id'] ?? ''); ?>"
                        >
                            <i class="fa-solid fa-pen"></i>
                        </button>

                        <?php if ($u['id'] != $_SESSION['user_id']): ?>
                        <form method="POST" style="display:inline;" onsubmit="confirmAction(event, 'Delete User', 'Are you sure you want to delete user <?php echo $u['username']; ?>?')">
                            <input type="hidden" name="delete_id" value="<?php echo $u['id']; ?>">
                            <button type="submit" class="glass-btn secondary" style="padding: 6px 10px; color: var(--danger); border-color: rgba(239,68,68,0.3);"><i class="fa-solid fa-trash"></i></button>
                        </form>
                        <?php else: ?>
                            <span style="color: var(--text-muted); font-size: 0.8rem;">(You)</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination Controls -->
    <?php if ($total_pages > 1): ?>
    <div style="margin-top: 1.5rem; display: flex; justify-content: center; align-items: center; gap: 1rem;">
        <?php 
            $params = $_GET;
            function build_user_query($p, $params) {
                $params['page'] = $p;
                return '?' . http_build_query($params);
            }
        ?>
        <a href="<?php echo build_user_query(max(1, $page - 1), $params); ?>" class="glass-btn secondary small <?php if($page <= 1) echo 'disabled'; ?>" style="<?php if($page <= 1) echo 'opacity: 0.5; pointer-events: none;'; ?>">
            <i class="fa-solid fa-chevron-left"></i>
        </a>
        <span style="font-size: 0.9rem; color: var(--text-muted);">Page <?php echo $page; ?> of <?php echo $total_pages; ?></span>
        <a href="<?php echo build_user_query(min($total_pages, $page + 1), $params); ?>" class="glass-btn secondary small <?php if($page >= $total_pages) echo 'disabled'; ?>" style="<?php if($page >= $total_pages) echo 'opacity: 0.5; pointer-events: none;'; ?>">
            <i class="fa-solid fa-chevron-right"></i>
        </a>
    </div>
    <?php endif; ?>
</div>

<!-- Edit User Modal -->
<div id="editUserModal" class="modal" style="display: none;">
    <div class="modal-content glass-panel" style="max-width: 560px; margin: 50px auto; padding: 2rem;">
        <h3 style="margin-top: 0;"><i class="fa-solid fa-user-pen"></i> Edit User</h3>
        <form method="POST" id="editUserForm">
            <input type="hidden" name="update_id" id="editUserId">

            <div class="form-group" style="margin-bottom: 1rem;">
                <label style="display:block; margin-bottom: 0.5rem; color: var(--text-muted);">Role</label>
                <select name="role" id="editRole" class="glass-input" onchange="toggleEditFields()" required>
                    <option value="student">Student</option>
                    <option value="lecturer">Lecturer</option>
                    <option value="faculty_admin">Faculty Admin</option>
                    <?php if($_SESSION['role'] === 'super_admin'): ?>
                        <option value="super_admin">Super Admin</option>
                    <?php endif; ?>
                </select>
            </div>

            <div class="form-group" id="editDeptField" style="display: none; margin-bottom: 1rem;">
                <label style="display:block; margin-bottom: 0.5rem; color: var(--text-muted);">Department</label>
                <?php if ($_SESSION['role'] === 'faculty_admin'): ?>
                     <input type="hidden" name="department" id="editDeptLocked" value="<?php echo htmlspecialchars($_SESSION['department']); ?>">
                     <div class="glass-input" style="background: rgba(255, 255, 255, 0.1); color: var(--text-muted); cursor: not-allowed;">
                        <i class="fa-solid fa-lock"></i> <?php echo htmlspecialchars($_SESSION['department']); ?> (Locked)
                    </div>
                <?php else: ?>
                    <select name="department" id="editDepartment" class="glass-input">
                        <option value="">Select Department...</option>
                        <option value="Computer Science">Computer Science</option>
                        <option value="Nursing">Nursing</option>
                        <option value="Theology">Theology</option>
                        <option value="Business">Business</option>
                        <option value="Education">Education</option>
                        <option value="General">General</option>
                    </select>
                <?php endif; ?>
            </div>

            <div class="form-group" id="editLecturerField" style="display: none; margin-bottom: 1rem;">
                <label style="display:block; margin-bottom: 0.5rem; color: var(--text-muted);">Link to Lecturer Profile</label>
                <div style="position: relative;">
                    <input type="text" id="editLecturerSearch" class="glass-input" placeholder="Search lecturer by name..." autocomplete="off" style="background: rgba(15,23,42,0.9);">
                    <input type="hidden" name="lecturer_id" id="editLecturerId">
                    <div id="editLecturerDropdown" style="display: none; position: absolute; top: 100%; left: 0; right: 0; max-height: 200px; overflow-y: auto; background: rgba(15,23,42,0.95); border: 1px solid rgba(255,255,255,0.1); border-radius: 8px; margin-top: 4px; z-index: 1000;">
                        <div id="editLecturerOptions">
                            <div class="lecturer-option" style="padding: 0.75rem; color: var(--text-muted); text-align: center;">Start typing to search...</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-group" id="editNameField" style="margin-bottom: 1rem;">
                <label style="display:block; margin-bottom: 0.5rem; color: var(--text-muted);">Full Name</label>
                <input type="text" name="full_name" id="editFullName" class="glass-input">
            </div>

            <div class="form-group" style="margin-bottom: 1rem;">
                <label style="display:block; margin-bottom: 0.5rem; color: var(--text-muted);">Username</label>
                <input type="text" name="username" id="editUsername" class="glass-input" required>
            </div>

            <div class="form-group" style="margin-bottom: 1rem;">
                <label style="display:block; margin-bottom: 0.5rem; color: var(--text-muted);">Email</label>
                <input type="email" name="email" id="editEmail" class="glass-input" placeholder="user@example.com">
            </div>

            <div class="form-group" id="editLevelField" style="margin-bottom: 1rem;">
                <label style="display:block; margin-bottom: 0.5rem; color: var(--text-muted);">Level</label>
                <input type="number" name="level" id="editLevel" class="glass-input" min="100" max="900" step="100" placeholder="e.g., 100">
            </div>

            <div style="display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" class="glass-btn" onclick="closeEditUserModal()">Cancel</button>
                <button type="submit" class="glass-btn">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
const lecturers = <?php echo json_encode($lecturers); ?>;

function openEditUserModal(btn) {
    document.getElementById('editUserId').value = btn.dataset.id || '';
    document.getElementById('editUsername').value = btn.dataset.username || '';
    document.getElementById('editFullName').value = btn.dataset.fullname || '';
    document.getElementById('editEmail').value = btn.dataset.email || '';
    document.getElementById('editRole').value = btn.dataset.role || 'student';
    const deptSelect = document.getElementById('editDepartment');
    if (deptSelect) {
        deptSelect.value = btn.dataset.department || '';
    }
    document.getElementById('editLevel').value = btn.dataset.level || '';
    document.getElementById('editLecturerId').value = btn.dataset.lecturerId || '';
    document.getElementById('editLecturerSearch').value = '';

    if (btn.dataset.role === 'lecturer' && btn.dataset.lecturerId) {
        const match = lecturers.find(l => String(l.id) === String(btn.dataset.lecturerId));
        if (match) {
            document.getElementById('editLecturerSearch').value = match.name;
        }
    }

    toggleEditFields();
    document.getElementById('editUserModal').style.display = 'block';
}

function closeEditUserModal() {
    document.getElementById('editUserModal').style.display = 'none';
}

function toggleEditFields() {
    const role = document.getElementById('editRole').value;
    const lecturerField = document.getElementById('editLecturerField');
    const deptField = document.getElementById('editDeptField');
    const nameField = document.getElementById('editNameField');
    const levelField = document.getElementById('editLevelField');

    if (role === 'lecturer') {
        lecturerField.style.display = 'block';
        deptField.style.display = 'block';
        nameField.style.display = 'none';
        levelField.style.display = 'none';
    } else if (role === 'faculty_admin') {
        lecturerField.style.display = 'none';
        deptField.style.display = 'block';
        nameField.style.display = 'block';
        levelField.style.display = 'none';
    } else if (role === 'student') {
        lecturerField.style.display = 'none';
        deptField.style.display = 'block';
        nameField.style.display = 'block';
        levelField.style.display = 'block';
    } else {
        lecturerField.style.display = 'none';
        deptField.style.display = 'none';
        nameField.style.display = 'block';
        levelField.style.display = 'none';
    }
}

// Lecturer search dropdown (edit modal)
const editSearchInput = document.getElementById('editLecturerSearch');
const editDropdown = document.getElementById('editLecturerDropdown');
const editOptionsContainer = document.getElementById('editLecturerOptions');
const editHiddenInput = document.getElementById('editLecturerId');

editSearchInput.addEventListener('focus', function() {
    editDropdown.style.display = 'block';
    if (editSearchInput.value.length === 0) {
        renderEditOptions(lecturers);
    }
});

editSearchInput.addEventListener('input', function() {
    const searchTerm = this.value.toLowerCase();
    if (searchTerm.length === 0) {
        renderEditOptions(lecturers);
    } else {
        const filtered = lecturers.filter(l =>
            l.name.toLowerCase().includes(searchTerm) ||
            (l.department && l.department.toLowerCase().includes(searchTerm))
        );
        renderEditOptions(filtered);
    }
    editDropdown.style.display = 'block';
});

function renderEditOptions(lecturerList) {
    if (lecturerList.length === 0) {
        editOptionsContainer.innerHTML = '<div style="padding: 0.75rem; color: var(--text-muted); text-align: center;">No lecturers found</div>';
        return;
    }

    editOptionsContainer.innerHTML = lecturerList.map(l => `
        <div class="lecturer-option" data-id="${l.id}" data-name="${l.name}" style="padding: 0.75rem; cursor: pointer; border-bottom: 1px solid rgba(255,255,255,0.05); transition: background 0.2s;">
            <div style="font-weight: 500; color: var(--text-primary);">${l.name}</div>
            ${l.department ? `<div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 2px;">${l.department}</div>` : ''}
        </div>
    `).join('');

    document.querySelectorAll('#editLecturerOptions .lecturer-option').forEach(option => {
        option.addEventListener('mouseenter', function() {
            this.style.background = 'rgba(255,255,255,0.1)';
        });
        option.addEventListener('mouseleave', function() {
            this.style.background = 'transparent';
        });
        option.addEventListener('click', function() {
            const id = this.getAttribute('data-id');
            const name = this.getAttribute('data-name');
            editSearchInput.value = name;
            editHiddenInput.value = id;
            editDropdown.style.display = 'none';
        });
    });
}

document.addEventListener('click', function(e) {
    if (!editSearchInput.contains(e.target) && !editDropdown.contains(e.target)) {
        editDropdown.style.display = 'none';
    }
});
</script>

<?php include 'includes/footer.php'; ?>
