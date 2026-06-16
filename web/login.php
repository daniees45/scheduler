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
$site_text = login_normalize_hex_color($branding['site_text_color'] ?? '#f8fafc', '#f8fafc');
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
            --site-text: <?php echo $site_text; ?>;

            --primary-color: var(--site-primary);
            --primary: var(--site-primary);
            --primary-hover: var(--site-primary-hover);
            --secondary-color: var(--site-secondary);
            --secondary: var(--site-secondary);
            --bg-dark: var(--site-bg);
            --text-main: var(--site-text);
        }
        body {
            background-color: var(--site-bg);
            color: var(--site-text);
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
        .password-wrap {
            position: relative;
        }
        .password-wrap .toggle-pass {
            position: absolute;
            right: 0.65rem;
            top: 50%;
            transform: translateY(-50%);
            border: none;
            background: transparent;
            color: var(--text-muted);
            cursor: pointer;
            padding: 0.25rem;
        }
        .caps-hint {
            display: none;
            margin-top: 0.35rem;
            font-size: 0.8rem;
            color: #f59e0b;
        }
        .caps-hint.is-visible {
            display: block;
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

        <?php if (isset($_GET['error'])): ?>
        <script>
            window.addEventListener('load', () => {
                showAlert(<?php echo json_encode((string)$_GET['error']); ?>, 'Login Error', 'error');
            });
        </script>
        <?php endif; ?>

        <!-- Login Form -->
        <form id="loginForm" action="api/auth.php" method="POST">
            <div class="form-group">
                <label><i class="fa-solid fa-user" style = "margin-bottom : 1.5rem"></i> Username</label>
                <input type="text" name="username" class="glass-input" required placeholder="Enter your username">
            </div>
            
            <div class="form-group">
                <label><i class="fa-solid fa-lock" style = "margin-bottom : 1.5rem"></i> Password</label>
                <div class="password-wrap">
                    <input id="loginPassword" type="password" name="password" class="glass-input" required placeholder="Enter your password">
                    <button type="button" class="toggle-pass" aria-label="Toggle password visibility" onclick="togglePassword('loginPassword', this)">
                        <i class="fa-solid fa-eye"></i>
                    </button>
                </div>
                <div id="capsHint" class="caps-hint"><i class="fa-solid fa-lock"></i> Caps Lock appears to be on.</div>
            </div>

            <div class="login-links-row">
                <a href="javascript:void(0)" onclick="toggleAuth('forgot')" style="text-decoration: none;
    color: var(--primary); font-size : 12px; margin-right : 8rem">Forgot password?</a>
                <a href="javascript:void(0)" onclick="toggleAuth('verify')" style="text-decoration: none;
    color: var(--primary); margin-left : 15px; font-size : 12px;">Activate Account</a>
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
                <label><i class="fa-solid fa-graduation-cap"></i> Level </label>
                <select name="level" class="glass-input login-select">
                    <option value="">--Select level--</option>
                    <option value="100">Level 100</option>
                    <option value="200">Level 200</option>
                    <option value="300">Level 300</option>
                    <option value="400">Level 400</option>
                </select>
            </div>

            <div class="form-group">
                <label><i class="fa-solid fa-envelope"></i> Email</label>
                <input type="email" name="email" class="glass-input" required placeholder="you@example.com">
            </div>

            <div class="form-group">
                <label><i class="fa-solid fa-user"></i> Choose Username</label>
                <input type="text" name="username" class="glass-input" required placeholder="e.g. jsmith24">
            </div>
            
            <div class="form-group">
                <label><i class="fa-solid fa-lock"></i> Password</label>
                <div class="password-wrap">
                    <input id="registerPassword" type="password" name="password" class="glass-input" required placeholder="Min. 6 characters">
                    <button type="button" class="toggle-pass" aria-label="Toggle password visibility" onclick="togglePassword('registerPassword', this)">
                        <i class="fa-solid fa-eye"></i>
                    </button>
                </div>
            </div>
            
            <button type="submit" class="glass-btn login-submit-btn">
                <i class="fa-solid fa-user-plus"></i> Register
            </button>

            <div class="toggle-text">
                Already have an account? <a href="javascript:void(0)" onclick="toggleAuth('login')">Sign In</a>
            </div>
        </form>

        <form id="verifyForm" onsubmit="handleVerifyRegistration(event)">
            <div class="form-group">
                <label><i class="fa-solid fa-user"></i> Username</label>
                <input type="text" name="username" id="verifyUsername" class="glass-input" required placeholder="Your username">
            </div>
            <div class="form-group">
                <label><i class="fa-solid fa-envelope"></i> Email</label>
                <input type="email" name="email" id="verifyEmail" class="glass-input" required placeholder="you@example.com">
            </div>
            <div class="form-group">
                <label><i class="fa-solid fa-shield-halved"></i> Confirmation Code</label>
                <input type="text" name="code" class="glass-input" required placeholder="6-digit code" maxlength="10">
            </div>
            <button type="submit" class="glass-btn login-submit-btn">
                <i class="fa-solid fa-check"></i> Confirm Account
            </button>

            <button type="button" class="glass-btn secondary login-submit-btn" onclick="resendConfirmationCode()">
                <i class="fa-solid fa-paper-plane"></i> Resend Code
            </button>

            <div class="toggle-text">
                Back to <a href="javascript:void(0)" onclick="toggleAuth('login')">Sign In</a>
            </div>
        </form>

        <form id="forgotRequestForm" onsubmit="handleForgotRequest(event)">
            <div class="form-group">
                <label><i class="fa-solid fa-envelope"></i> Email</label>
                <input type="email" name="email" id="forgotEmail" class="glass-input" required placeholder="you@example.com">
            </div>

            <button type="submit" class="glass-btn login-submit-btn">
                <i class="fa-solid fa-key"></i> Send Reset Code
            </button>

            <div class="toggle-text">
                Remembered your password? <a href="javascript:void(0)" onclick="toggleAuth('login')">Sign In</a>
            </div>
        </form>

        <form id="forgotResetForm" onsubmit="handleForgotReset(event)">
            <div class="form-group">
                <label><i class="fa-solid fa-envelope"></i> Email</label>
                <input type="email" name="email" id="resetEmail" class="glass-input" required placeholder="you@example.com">
            </div>

            <div class="form-group">
                <label><i class="fa-solid fa-shield-halved"></i> Reset Code</label>
                <input type="text" name="code" class="glass-input" required placeholder="6-digit code" maxlength="10">
            </div>

            <div class="form-group">
                <label><i class="fa-solid fa-lock"></i> New Password</label>
                <div class="password-wrap">
                    <input id="resetPassword" type="password" name="new_password" class="glass-input" required placeholder="Min. 6 characters">
                    <button type="button" class="toggle-pass" aria-label="Toggle password visibility" onclick="togglePassword('resetPassword', this)">
                        <i class="fa-solid fa-eye"></i>
                    </button>
                </div>
            </div>

            <button type="submit" class="glass-btn login-submit-btn">
                <i class="fa-solid fa-arrows-rotate"></i> Reset Password
            </button>

            <div class="toggle-text">
                Need a new code? <a href="javascript:void(0)" onclick="toggleAuth('forgot')">Send again</a>
            </div>
        </form>
    </div>
</div>

<script>
function toggleAuth(mode) {
    const loginForm = document.getElementById('loginForm');
    const registerForm = document.getElementById('registerForm');
    const verifyForm = document.getElementById('verifyForm');
    const forgotRequestForm = document.getElementById('forgotRequestForm');
    const forgotResetForm = document.getElementById('forgotResetForm');
    const subtitle = document.getElementById('subtitle');
    const urlError = document.getElementById('urlError');
    const dynamicError = document.getElementById('dynamicError');
    const dynamicSuccess = document.getElementById('dynamicSuccess');

    if (urlError) urlError.style.display = 'none';
    dynamicError.style.display = 'none';
    dynamicSuccess.style.display = 'none';

    loginForm.style.display = 'none';
    registerForm.style.display = 'none';
    verifyForm.style.display = 'none';
    forgotRequestForm.style.display = 'none';
    forgotResetForm.style.display = 'none';

    if (mode === 'register') {
        registerForm.style.display = 'block';
        registerForm.classList.add('fade-in-up');
        subtitle.innerText = 'Join the Scheduling System';
    } else if (mode === 'verify') {
        verifyForm.style.display = 'block';
        verifyForm.classList.add('fade-in-up');
        subtitle.innerText = 'Confirm Your Registration';
    } else if (mode === 'forgot') {
        forgotRequestForm.style.display = 'block';
        forgotRequestForm.classList.add('fade-in-up');
        subtitle.innerText = 'Reset Your Password';
    } else if (mode === 'forgot-reset') {
        forgotResetForm.style.display = 'block';
        forgotResetForm.classList.add('fade-in-up');
        subtitle.innerText = 'Enter Reset Code';
    } else {
        loginForm.style.display = 'block';
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

function togglePassword(inputId, btn) {
    const input = document.getElementById(inputId);
    if (!input) return;
    const icon = btn.querySelector('i');
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    if (icon) {
        icon.classList.toggle('fa-eye', !show);
        icon.classList.toggle('fa-eye-slash', show);
    }
}

function setSubmitBusy(form, busy, busyText) {
    const submit = form.querySelector('button[type="submit"]');
    if (!submit) return;
    if (busy) {
        if (!submit.dataset.originalHtml) submit.dataset.originalHtml = submit.innerHTML;
        submit.disabled = true;
        submit.innerHTML = `<i class="fa-solid fa-circle-notch fa-spin"></i> ${busyText || 'Please wait...'}`;
    } else {
        submit.disabled = false;
        if (submit.dataset.originalHtml) submit.innerHTML = submit.dataset.originalHtml;
    }
}

async function showAuthModal(message, title, type = 'info') {
    const errorDiv = document.getElementById('dynamicError');
    const successDiv = document.getElementById('dynamicSuccess');

    if (type === 'success') {
        if (successDiv) {
            successDiv.innerText = message;
            successDiv.style.display = 'block';
        }
        if (errorDiv) {
            errorDiv.style.display = 'none';
        }
    } else {
        if (errorDiv) {
            errorDiv.innerText = message;
            errorDiv.style.display = 'block';
        }
        if (successDiv) {
            successDiv.style.display = 'none';
        }
    }

    if (typeof showAlert === 'function') {
        await showAlert(message, title, type);
    }
}

async function showAuthConfirm(message, title) {
    if (typeof showConfirm === 'function') {
        return await showConfirm(message, title);
    }

    return window.confirm(message);
}

async function showAuthPrompt(message, defaultValue, title) {
    if (typeof showPrompt === 'function') {
        return await showPrompt(message, defaultValue, title);
    }

    return window.prompt(message, defaultValue);
}

async function handleRegister(e) {
    e.preventDefault();
    const form = e.target;
    const formData = new FormData(form);

    const displayName = (formData.get('fullname') || formData.get('username') || 'this account').toString().trim();
    if (!await showAuthConfirm(`Create the account for ${displayName}?`, 'Confirm Registration')) {
        return;
    }

    setSubmitBusy(form, true, 'Creating Account...');

    try {
        const response = await fetch('api/register_public.php', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();
        
        if (data.status === 'success') {
            await showAuthModal(data.message, 'Registration Successful', 'success');
            form.reset();

            if (data.verify_required) {
                document.getElementById('verifyUsername').value = data.username || '';
                document.getElementById('verifyEmail').value = data.email || '';
                setTimeout(() => toggleAuth('verify'), 1200);
            } else {
                setTimeout(() => toggleAuth('login'), 1200);
            }
        } else {
            await showAuthModal(data.message || 'Registration failed.', 'Registration Error', 'error');
        }
    } catch (e) {
        await showAuthModal('Network error. Please try again.', 'Registration Error', 'error');
    } finally {
        setSubmitBusy(form, false);
    }
}

async function handleVerifyRegistration(e) {
    e.preventDefault();
    const form = e.target;
    const formData = new FormData(form);
    formData.append('action', 'verify_registration');
    setSubmitBusy(form, true, 'Verifying...');

    try {
        const response = await fetch('api/auth_recovery.php', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();
        if (data.status === 'success') {
            await showAuthModal(data.message, 'Account Verified', 'success');
            setTimeout(() => toggleAuth('login'), 1200);
        } else {
            await showAuthModal(data.message || 'Verification failed.', 'Verification Error', 'error');
        }
    } catch (err) {
        await showAuthModal('Network error. Please try again.', 'Verification Error', 'error');
    } finally {
        setSubmitBusy(form, false);
    }
}

async function resendConfirmationCode() {
    const username = document.getElementById('verifyUsername').value.trim();
    const email = document.getElementById('verifyEmail').value.trim();

    if (!username || !email) {
        await showAuthModal('Please enter username and email first.', 'Missing Details', 'error');
        return;
    }

    if (!await showAuthConfirm(`Send a new confirmation code to ${email}?`, 'Resend Confirmation Code')) {
        return;
    }

    const formData = new FormData();
    formData.append('action', 'resend_registration_code');
    formData.append('username', username);
    formData.append('email', email);

    try {
        const response = await fetch('api/auth_recovery.php', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();
        if (data.status === 'success') {
            await showAuthModal(data.message, 'Code Sent', 'success');
        } else {
            await showAuthModal(data.message || 'Unable to resend the code.', 'Resend Failed', 'error');
        }
    } catch (err) {
        await showAuthModal('Network error. Please try again.', 'Resend Failed', 'error');
    }
}

async function handleForgotRequest(e) {
    e.preventDefault();
    const form = e.target;
    const formData = new FormData(form);

    let email = (document.getElementById('forgotEmail').value || '').trim();
    if (!email) {
        const promptValue = await showAuthPrompt('Enter the email address for the reset code:', '', 'Password Reset');
        if (promptValue === null) {
            return;
        }
        email = String(promptValue).trim();
        if (!email) {
            await showAuthModal('Email address is required.', 'Missing Email', 'error');
            return;
        }
        document.getElementById('forgotEmail').value = email;
    }

    if (!await showAuthConfirm(`Send a password reset code to ${email}?`, 'Send Reset Code')) {
        return;
    }

    formData.set('email', email);
    formData.append('action', 'forgot_password_request');
    setSubmitBusy(form, true, 'Sending Code...');

    try {
        const response = await fetch('api/auth_recovery.php', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();
        if (data.status === 'success') {
            await showAuthModal(data.message, 'Reset Code Sent', 'success');

            document.getElementById('resetEmail').value = email;
            setTimeout(() => toggleAuth('forgot-reset'), 1000);
        } else {
            await showAuthModal(data.message || 'Unable to send reset code.', 'Reset Code Failed', 'error');
        }
    } catch (err) {
        await showAuthModal('Network error. Please try again.', 'Reset Code Failed', 'error');
    } finally {
        setSubmitBusy(form, false);
    }
}

async function handleForgotReset(e) {
    e.preventDefault();
    const form = e.target;
    const formData = new FormData(form);
    formData.append('action', 'forgot_password_reset');

    if (!await showAuthConfirm('Apply this reset code and change the password now?', 'Confirm Password Reset')) {
        return;
    }

    setSubmitBusy(form, true, 'Resetting Password...');

    try {
        const response = await fetch('api/auth_recovery.php', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();
        if (data.status === 'success') {
            await showAuthModal(data.message, 'Password Updated', 'success');
            e.target.reset();
            setTimeout(() => toggleAuth('login'), 1400);
        } else {
            await showAuthModal(data.message || 'Password reset failed.', 'Reset Error', 'error');
        }
    } catch (err) {
        await showAuthModal('Network error. Please try again.', 'Reset Error', 'error');
    } finally {
        setSubmitBusy(form, false);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    toggleAuth('login');
    toggleRegFields();

    const loginForm = document.getElementById('loginForm');
    if (loginForm) {
        loginForm.addEventListener('submit', () => {
            setSubmitBusy(loginForm, true, 'Signing In...');
        });
    }

    const loginPassword = document.getElementById('loginPassword');
    const capsHint = document.getElementById('capsHint');
    if (loginPassword && capsHint) {
        const checkCaps = (ev) => {
            const on = ev.getModifierState && ev.getModifierState('CapsLock');
            capsHint.classList.toggle('is-visible', !!on);
        };
        loginPassword.addEventListener('keydown', checkCaps);
        loginPassword.addEventListener('keyup', checkCaps);
        loginPassword.addEventListener('blur', () => capsHint.classList.remove('is-visible'));
    }
});
</script>
</body>
</html>
