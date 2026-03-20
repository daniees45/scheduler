<?php
$page_title = 'Register New User';
$page_css = 'assets/register.css';
include 'includes/header.php';
require_once 'api/db.php';

// Only admins
if ($_SESSION['role'] != 'super_admin' && $_SESSION['role'] != 'faculty_admin') {
    echo "<div class='glass-panel register-access-denied'>Access Denied</div>";
    include 'includes/footer.php';
    exit;
}

// Fetch Lecturers for linking
$lecturers = [];
$res = $conn->query("SELECT id, name, department FROM lecturers ORDER BY name ASC");
if ($res) $lecturers = $res->fetch_all(MYSQLI_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $fullname = trim($_POST['fullname']); // Keep for non-lecturers
    $role = $_POST['role'];
    $department = ($role === 'faculty_admin') ? $_POST['department'] : null;
    $password = $_POST['password'];
    $lecturer_id = ($role === 'lecturer' && !empty($_POST['lecturer_id'])) ? $_POST['lecturer_id'] : null;

    // If linking to a lecturer, use their name as full name
    if ($lecturer_id) {
        $stmt = $conn->prepare("SELECT name, department FROM lecturers WHERE id = ?");
        $stmt->bind_param("i", $lecturer_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($l = $res->fetch_assoc()) {
            $fullname = $l['name'];
            // Auto-set department from lecturer profile if not already set
            if (!$department && $l['department']) {
                $department = $l['department'];
            }
        }
    }
    
    // Hash password
    $hash = password_hash($password, PASSWORD_DEFAULT);
    
    try {
        $stmt = $conn->prepare("INSERT INTO users (username, full_name, role, department, password_hash, lecturer_id) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssi", $username, $fullname, $role, $department, $hash, $lecturer_id);
        
        if ($stmt->execute()) {
            echo "<script>
                window.addEventListener('load', async () => {
                    await customAlert('User Created', 'New user account has been successfully registered.', 'success');
                    window.location.href='users.php';
                });
            </script>";
        } else {
            $error = "Error: " . $stmt->error;
        }
    } catch (Exception $e) {
        $error = "Error creating user: " . $e->getMessage(); 
    }
}
?>

    <div class="glass-panel register-container">
    <h2 class="register-title">Add New User</h2>
    
    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form method="POST" action="register.php">
        <!-- Role Selection -->
        <div class="form-group">
            <label class="register-form-label">Role</label>
            <select name="role" id="roleSelect" class="glass-input" onchange="toggleFields()" required>
                <option value="student">Student</option>
                <option value="lecturer">Lecturer</option>
                <option value="faculty_admin">Faculty Admin</option>
                <?php if($_SESSION['role'] === 'super_admin'): ?>
                    <option value="super_admin">Super Admin</option>
                <?php endif; ?>
            </select>
        </div>

        <!-- Conditional Fields -->
        <div class="form-group register-dept-field" id="deptField">
            <label class="register-form-label">Department</label>
            <?php if ($_SESSION['role'] === 'faculty_admin'): ?>
                 <input type="hidden" name="department" value="<?php echo htmlspecialchars($_SESSION['department']); ?>">
                 <div class="glass-input register-dept-locked">
                    <i class="fa-solid fa-lock"></i> <?php echo htmlspecialchars($_SESSION['department']); ?> (Locked)
                </div>
            <?php else: ?>
                <select name="department" class="glass-input">
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

        <div class="form-group register-lecturer-field" id="lecturerField">
            <label class="register-form-label">Link to Lecturer Profile</label>
            <div class="register-lecturer-search-wrap">
                <input type="text" id="lecturerSearch" class="glass-input register-lecturer-search" placeholder="Search lecturer by name..." autocomplete="off">
                <input type="hidden" name="lecturer_id" id="lecturerIdInput">
                <div id="lecturerDropdown" class="register-lecturer-dropdown">
                    <div id="lecturerOptions">
                        <div class="register-lecturer-placeholder">Start typing to search...</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="form-group" id="nameField">
            <label class="register-form-label">Full Name</label>
            <input type="text" name="fullname" class="glass-input">
        </div>
        
        <div class="form-group" style="margin-top: 1rem;">
            <label class="register-form-label">Username</label>
            <input type="text" name="username" class="glass-input" required>
        </div>
        
        <div class="form-group" style="margin-top: 1rem;">
            <label class="register-form-label">Password</label>
            <input type="password" name="password" class="glass-input" required>
        </div>
        
        <button type="submit" class="glass-btn register-submit-btn">Create Account</button>
    </form>
</div>

<script>
// Lecturer data for search
const lecturers = <?php echo json_encode($lecturers); ?>;

function toggleFields() {
    const role = document.getElementById('roleSelect').value;
    const lecturerField = document.getElementById('lecturerField');
    const deptField = document.getElementById('deptField');
    const nameField = document.getElementById('nameField');
    
    if (role === 'lecturer') {
        lecturerField.style.display = 'block';
        deptField.style.display = 'none';
        nameField.style.display = 'none';
        document.querySelector('input[name="fullname"]').removeAttribute('required');
        document.getElementById('lecturerIdInput').setAttribute('required', 'required');
        document.querySelector('select[name="department"]').removeAttribute('required');
    } else if (role === 'faculty_admin') {
        lecturerField.style.display = 'none';
        deptField.style.display = 'block';
        nameField.style.display = 'block';
        document.querySelector('input[name="fullname"]').setAttribute('required', 'required');
        document.getElementById('lecturerIdInput').removeAttribute('required');
        document.querySelector('select[name="department"]').setAttribute('required', 'required');
    } else {
        lecturerField.style.display = 'none';
        deptField.style.display = 'none';
        nameField.style.display = 'block';
        document.querySelector('input[name="fullname"]').setAttribute('required', 'required');
        document.getElementById('lecturerIdInput').removeAttribute('required');
        document.querySelector('select[name="department"]').removeAttribute('required');
    }
}

// Searchable Lecturer Dropdown
const searchInput = document.getElementById('lecturerSearch');
const dropdown = document.getElementById('lecturerDropdown');
const optionsContainer = document.getElementById('lecturerOptions');
const hiddenInput = document.getElementById('lecturerIdInput');

searchInput.addEventListener('focus', function() {
    dropdown.style.display = 'block';
    if (searchInput.value.length === 0) {
        renderOptions(lecturers);
    }
});

searchInput.addEventListener('input', function() {
    const searchTerm = this.value.toLowerCase();
    if (searchTerm.length === 0) {
        renderOptions(lecturers);
    } else {
        const filtered = lecturers.filter(l => 
            l.name.toLowerCase().includes(searchTerm) || 
            (l.department && l.department.toLowerCase().includes(searchTerm))
        );
        renderOptions(filtered);
    }
    dropdown.style.display = 'block';
});

function renderOptions(lecturerList) {
    if (lecturerList.length === 0) {
        optionsContainer.innerHTML = '<div class="register-no-results">No lecturers found</div>';
        return;
    }
    
    optionsContainer.innerHTML = lecturerList.map(l => `
        <div class="lecturer-option" data-id="${l.id}" data-name="${l.name}">
            <div class="lecturer-option-name">${l.name}</div>
            ${l.department ? `<div class="lecturer-option-dept">${l.department}</div>` : ''}
        </div>
    `).join('');
    
    // Add click handlers
    document.querySelectorAll('.lecturer-option').forEach(option => {
        option.addEventListener('mouseenter', function() {
            this.classList.add('lecturer-option--hover');
        });
        option.addEventListener('mouseleave', function() {
            this.classList.remove('lecturer-option--hover');
        });
        option.addEventListener('click', function() {
            const id = this.getAttribute('data-id');
            const name = this.getAttribute('data-name');
            searchInput.value = name;
            hiddenInput.value = id;
            dropdown.style.display = 'none';
        });
    });
}

// Close dropdown when clicking outside
document.addEventListener('click', function(e) {
    if (!searchInput.contains(e.target) && !dropdown.contains(e.target)) {
        dropdown.style.display = 'none';
    }
});

// Run on load
toggleFields();
</script>

<?php include 'includes/footer.php'; ?>
