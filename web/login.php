<?php
session_start();
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit;
}
require_once 'api/db.php';
$lecturers = [];
$res = $conn->query("SELECT id, name FROM lecturers ORDER BY name ASC");
if ($res) $lecturers = $res->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login & Register - VVU AI Scheduler</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">


</head>
<body>

<div class="login-container">
    <div class="glass-panel login-card animate-fade-in" id="authCard">
        <div class="logo-area">
            <h1>VVU Scheduler</h1>
            <p style="color: var(--text-muted);" id="subtitle">AI-Powered Timetabling System</p>
        </div>

        <?php if (isset($_GET['error'])): ?>
            <div id="urlError" class="alert alert-danger" style="margin-bottom: 1rem; font-size: 0.9rem;">
                <i class="fa-solid fa-circle-exclamation"></i> <?php echo htmlspecialchars($_GET['error']); ?>
            </div>
        <?php endif; ?>

        <div id="dynamicError" class="alert alert-danger" style="margin-bottom: 1rem; display: none; font-size: 0.9rem;"></div>
        <div id="dynamicSuccess" class="alert alert-success" style="margin-bottom: 1rem; display: none; font-size: 0.9rem;"></div>

        <!-- Login Form -->
        <form id="loginForm" action="api/auth.php" method="POST">
            <div class="form-group">
                <label><i class="fa-solid fa-user"></i> Username</label>
                <input type="text" name="username" class="glass-input" required placeholder="Enter your username">
            </div>
            
            <div class="form-group">
                <label><i class="fa-solid fa-lock"></i> Password</label>
                <input type="password" name="password" class="glass-input" required placeholder="Enter your password">
            </div>
            
            <button type="submit" class="glass-btn" style="width: 100%; margin-top: 1rem;">
                <i class="fa-solid fa-right-to-bracket"></i> Sign In
            </button>

            <div class="toggle-text">
                Don't have an account? <a href="javascript:void(0)" onclick="toggleAuth('register')">Create Account</a>
            </div>
        </form>

        <!-- Register Form -->
        <form id="registerForm" onsubmit="handleRegister(event)">
            <div class="form-group">
                <label><i class="fa-solid fa-id-badge"></i> Role</label>
                <select name="role" id="roleSelect" class="glass-input" style="background: rgba(15,23,42,0.9);" onchange="toggleRegFields()">
                    <option value="student">Student</option>
                    <!-- Lecturer registration removed - only admins can create lecturer accounts -->
                </select>
            </div>

            <div class="form-group" id="regNameField">
                <label><i class="fa-solid fa-signature"></i> Full Name</label>
                <input type="text" name="fullname" class="glass-input" placeholder="e.g. John Smith" required>
            </div>

            <div class="form-group" id="regLecturerField" style="display: none;">
                <label><i class="fa-solid fa-chalkboard-user"></i> Link Lecturer Profile</label>
                <select name="lecturer_id" class="glass-input" style="background: rgba(15,23,42,0.9);">
                    <option value="">-- Select Lecturer --</option>
                    <?php foreach($lecturers as $l): ?>
                        <option value="<?php echo $l['id']; ?>"><?php echo htmlspecialchars($l['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label><i class="fa-solid fa-building-columns"></i> Department</label>
                <select name="department" class="glass-input" style="background: rgba(15,23,42,0.9);" required>
                    <option value="">-- Select Department --</option>
                    <option value="Computing Science">Computing Science (CS/IT/BBIS)</option>
                    <option value="Nursing">Nursing</option>
                    <option value="Theology">Theology</option>
                    <option value="Business">Business Administration</option>
                    <option value="Development Studies">Development Studies</option>
                    <option value="Education">Education</option>
                    <option value="Biomedical Engineering">Biomedical Engineering</option>
                </select>
            </div>

            <div class="form-group" id="regLevelField">
                <label><i class="fa-solid fa-graduation-cap"></i> Level (Students only)</label>
                <select name="level" class="glass-input" style="background: rgba(15,23,42,0.9);">
                    <option value="">-- Not Applicable --</option>
                    <option value="100">Level 100</option>
                    <option value="200">Level 200</option>
                    <option value="300">Level 300</option>
                    <option value="400">Level 400</option>
                </select>
            </div>

            <div class="form-group">
                <label><i class="fa-solid fa-user"></i> Choose Username</label>
                <input type="text" name="username" class="glass-input" required placeholder="e.g. jsmith24">
            </div>
            
            <div class="form-group">
                <label><i class="fa-solid fa-lock"></i> Password</label>
                <input type="password" name="password" class="glass-input" required placeholder="Min. 6 characters">
            </div>
            
            <button type="submit" class="glass-btn" style="width: 100%; margin-top: 1rem;">
                <i class="fa-solid fa-user-plus"></i> Register
            </button>

            <div class="toggle-text">
                Already have an account? <a href="javascript:void(0)" onclick="toggleAuth('login')">Sign In</a>
            </div>
        </form>
    </div>
</div>

<script>
function toggleAuth(mode) {
    const loginForm = document.getElementById('loginForm');
    const registerForm = document.getElementById('registerForm');
    const subtitle = document.getElementById('subtitle');
    const urlError = document.getElementById('urlError');
    const dynamicError = document.getElementById('dynamicError');
    const dynamicSuccess = document.getElementById('dynamicSuccess');

    if (urlError) urlError.style.display = 'none';
    dynamicError.style.display = 'none';
    dynamicSuccess.style.display = 'none';

    if (mode === 'register') {
        loginForm.style.display = 'none';
        registerForm.style.display = 'block';
        registerForm.classList.add('fade-in-up');
        subtitle.innerText = 'Join the Scheduling System';
    } else {
        loginForm.style.display = 'block';
        registerForm.style.display = 'none';
        loginForm.classList.add('fade-in-up');
        subtitle.innerText = 'AI-Powered Timetabling System';
    }
}

function toggleRegFields() {
    const role = document.getElementById('roleSelect').value;
    const lecturerField = document.getElementById('regLecturerField');
    const nameField = document.getElementById('regNameField');
    const levelField = document.getElementById('regLevelField');
    const lecturerSelect = document.querySelector('select[name="lecturer_id"]');
    const nameInput = document.querySelector('input[name="fullname"]');
    const levelSelect = document.querySelector('select[name="level"]');
    
    if (role === 'lecturer') {
        lecturerField.style.display = 'block';
        nameField.style.display = 'none';
        nameInput.removeAttribute('required');
        lecturerSelect.setAttribute('required', 'required');
        levelSelect.removeAttribute('required');
    } else if (role === 'student') {
        lecturerField.style.display = 'none';
        nameField.style.display = 'block';
        nameInput.setAttribute('required', 'required');
        lecturerSelect.removeAttribute('required');
        levelSelect.setAttribute('required', 'required');
    } else {
        lecturerField.style.display = 'none';
        nameField.style.display = 'block';
        nameInput.setAttribute('required', 'required');
        lecturerSelect.removeAttribute('required');
        levelSelect.removeAttribute('required');
    }
}

async function handleRegister(e) {
    e.preventDefault();
    const form = e.target;
    const formData = new FormData(form);
    const errorDiv = document.getElementById('dynamicError');
    const successDiv = document.getElementById('dynamicSuccess');

    errorDiv.style.display = 'none';
    successDiv.style.display = 'none';

    try {
        const response = await fetch('api/register_public.php', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();
        
        if (data.status === 'success') {
            successDiv.innerText = data.message;
            successDiv.style.display = 'block';
            form.reset();
            setTimeout(() => toggleAuth('login'), 2000);
        } else {
            errorDiv.innerText = data.message;
            errorDiv.style.display = 'block';
        }
    } catch (e) {
        errorDiv.innerText = 'Network error. Please try again.';
        errorDiv.style.display = 'block';
    }
}
</script>
</body>
</html>
