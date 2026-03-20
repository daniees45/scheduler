<?php
session_start();
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit;
}
require_once 'api/db.php';
require_once 'includes/branding.php';

function login_normalize_hex_color($hex, $fallback = '#2563eb') {
    $hex = trim((string)$hex);
    if (!preg_match('/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $hex)) {
        return $fallback;
    }
    if (strlen($hex) === 4) {
        return '#'
            . $hex[1] . $hex[1]
            . $hex[2] . $hex[2]
            . $hex[3] . $hex[3];
    }
    return strtolower($hex);
}

function login_hex_to_rgb($hex) {
    $hex = login_normalize_hex_color($hex);
    $hex = ltrim($hex, '#');
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    return "$r, $g, $b";
}

function login_adjust_hex_brightness($hex, $steps) {
    $hex = login_normalize_hex_color($hex);
    $hex = ltrim($hex, '#');
    $steps = max(-255, min(255, (int)$steps));

    $r = max(0, min(255, hexdec(substr($hex, 0, 2)) + $steps));
    $g = max(0, min(255, hexdec(substr($hex, 2, 2)) + $steps));
    $b = max(0, min(255, hexdec(substr($hex, 4, 2)) + $steps));

    return sprintf('#%02x%02x%02x', $r, $g, $b);
}

function login_apply_hex_strength($hex, $strengthPercent) {
    $hex = login_normalize_hex_color($hex);
    $hex = ltrim($hex, '#');
    $strengthPercent = max(50, min(150, (int)$strengthPercent));

    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));

    if ($strengthPercent < 100) {
        $factor = $strengthPercent / 100;
        $r = (int)round($r * $factor);
        $g = (int)round($g * $factor);
        $b = (int)round($b * $factor);
    } elseif ($strengthPercent > 100) {
        $factor = ($strengthPercent - 100) / 50;
        $r = (int)round($r + (255 - $r) * $factor);
        $g = (int)round($g + (255 - $g) * $factor);
        $b = (int)round($b + (255 - $b) * $factor);
    }

    return sprintf('#%02x%02x%02x', max(0, min(255, $r)), max(0, min(255, $g)), max(0, min(255, $b)));
}

$site_primary_base = login_normalize_hex_color($branding['site_color'] ?? '#2563eb', '#2563eb');
$site_secondary_base = login_normalize_hex_color($branding['site_secondary_color'] ?? '', login_adjust_hex_brightness($site_primary_base, 32));
$site_bg = login_normalize_hex_color($branding['site_bg_color'] ?? '#0f172a', '#0f172a');
$color_strength = max(50, min(150, (int)($branding['site_color_strength'] ?? 100)));
$site_primary = login_apply_hex_strength($site_primary_base, $color_strength);
$site_secondary = login_apply_hex_strength($site_secondary_base, $color_strength);
$site_primary_hover = login_adjust_hex_brightness($site_primary, -18);
$site_primary_rgb = login_hex_to_rgb($site_primary);
$site_secondary_rgb = login_hex_to_rgb($site_secondary);

$lecturers = [];
$res = $conn->query("SELECT id, name FROM lecturers ORDER BY name ASC");
if ($res) $lecturers = $res->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login & Register - <?php echo htmlspecialchars($branding['site_title'] ?? 'VVU AI Scheduler'); ?></title>
    <?php if (!empty($branding['site_icon'])): ?>
    <link rel="icon" href="<?php echo htmlspecialchars($branding['site_icon']); ?>">
    <?php endif; ?>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/login.css">
    <style>
        :root {
            --site-primary: <?php echo $site_primary; ?>;
            --site-primary-hover: <?php echo $site_primary_hover; ?>;
            --site-secondary: <?php echo $site_secondary; ?>;
            --site-primary-rgb: <?php echo $site_primary_rgb; ?>;
            --site-secondary-rgb: <?php echo $site_secondary_rgb; ?>;
            --site-bg: <?php echo $site_bg; ?>;

            --primary-color: var(--site-primary);
            --primary: var(--site-primary);
            --primary-hover: var(--site-primary-hover);
            --secondary-color: var(--site-secondary);
            --secondary: var(--site-secondary);
            --bg-dark: var(--site-bg);
        }
        body {
            background-color: var(--site-bg);
            background-image: radial-gradient(circle at top right, rgba(var(--site-primary-rgb), 0.18), transparent),
                              radial-gradient(circle at bottom left, rgba(var(--site-secondary-rgb), 0.12), transparent);
            background-attachment: fixed;
        }
        .logo-area h1 {
            background: linear-gradient(to right, var(--site-primary), var(--site-secondary));
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
        }
    </style>


</head>
<body>

<div class="login-container">
    <div class="glass-panel login-card animate-fade-in" id="authCard">
        <div class="logo-area">
            <h1><?php echo htmlspecialchars($branding['site_title'] ?? 'VVU Scheduler'); ?></h1>
            <p class="login-subtitle" id="subtitle">AI-Powered Timetabling System</p>
        </div>

        <?php if (isset($_GET['error'])): ?>
            <div id="urlError" class="alert alert-danger login-alert">
                <i class="fa-solid fa-circle-exclamation"></i> <?php echo htmlspecialchars($_GET['error']); ?>
            </div>
        <?php endif; ?>

        <div id="dynamicError" class="alert alert-danger login-alert login-alert--hidden"></div>
        <div id="dynamicSuccess" class="alert alert-success login-alert login-alert--hidden"></div>

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
            
            <button type="submit" class="glass-btn login-submit-btn">
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
                <select name="role" id="roleSelect" class="glass-input login-select" onchange="toggleRegFields()">
                    <option value="student">Student</option>
                    <!-- Lecturer registration removed - only admins can create lecturer accounts -->
                </select>
            </div>

            <div class="form-group" id="regNameField">
                <label><i class="fa-solid fa-signature"></i> Full Name</label>
                <input type="text" name="fullname" class="glass-input" placeholder="e.g. John Smith" required>
            </div>

            <div class="form-group login-reg-lecturer-field" id="regLecturerField">
                <label><i class="fa-solid fa-chalkboard-user"></i> Link Lecturer Profile</label>
                <select name="lecturer_id" class="glass-input login-select">
                    <option value="">-- Select Lecturer --</option>
                    <?php foreach($lecturers as $l): ?>
                        <option value="<?php echo $l['id']; ?>"><?php echo htmlspecialchars($l['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label><i class="fa-solid fa-building-columns"></i> Department</label>
                <select name="department" class="glass-input login-select" required>
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
                <select name="level" class="glass-input login-select">
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
            
            <button type="submit" class="glass-btn login-submit-btn">
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
