<?php
$page_title = 'Settings & Preferences';
$page_css = 'assets/settings.css';
include 'includes/header.php';
require_once 'api/db.php';

$user_id = (int)($_SESSION['user_id'] ?? 0);
$role = $_SESSION['role'];

// Fetch current user settings
$user_settings = $conn->query("SELECT * FROM user_settings WHERE user_id = $user_id")->fetch_assoc() ?? [];
$notif_settings = $conn->query("SELECT * FROM notification_settings WHERE user_id = $user_id")->fetch_assoc() ?? [];
$ai_settings = $conn->query("SELECT * FROM ai_settings WHERE user_id = $user_id")->fetch_assoc() ?? [];
$admin_settings = $conn->query("SELECT * FROM admin_settings WHERE user_id = $user_id")->fetch_assoc() ?? [];
$admin_settings_json = json_decode($admin_settings['settings_json'] ?? '{}', true);
$admin_default_priority = $admin_settings_json['default_priority'] ?? 'Medium';

// Fetch basic user info
$user_res = $conn->query("SELECT full_name, email FROM users WHERE id = $user_id")->fetch_assoc();
$user_name = $user_res['full_name'] ?? 'User';
$user_email = $user_res['email'] ?? '';

// Branding settings (Global)
$branding_settings = $conn->query("SELECT * FROM branding_settings WHERE id = 1")->fetch_assoc() ?? [];

function normalize_settings_hex_color($hex, $fallback = '#2563eb') {
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

function adjust_settings_hex_brightness($hex, $steps) {
    $hex = normalize_settings_hex_color($hex);
    $hex = ltrim($hex, '#');
    $steps = max(-255, min(255, (int)$steps));

    $r = max(0, min(255, hexdec(substr($hex, 0, 2)) + $steps));
    $g = max(0, min(255, hexdec(substr($hex, 2, 2)) + $steps));
    $b = max(0, min(255, hexdec(substr($hex, 4, 2)) + $steps));

    return sprintf('#%02x%02x%02x', $r, $g, $b);
}

$branding_primary = normalize_settings_hex_color($branding_settings['site_color'] ?? '#2563eb', '#2563eb');
$branding_secondary = normalize_settings_hex_color(
    $branding_settings['site_secondary_color'] ?? '',
    adjust_settings_hex_brightness($branding_primary, 32)
);
$branding_strength = (int)($branding_settings['site_color_strength'] ?? 100);
$branding_strength = max(50, min(150, $branding_strength));
?>

<div class="glass-panel settings-page-panel">
    <div class="settings-page-header">
        <h2><i class="fa-solid fa-gears"></i> Account Settings</h2>
        <p class="settings-page-subtitle">Manage your profile, preferences, and system configuration</p>
    </div>

    <!-- Settings Tabs -->
    <div class="settings-tabs settings-tabs-row">
        <button class="tab-btn active" onclick="showTab('profile')"><i class="fa-solid fa-user"></i> Profile</button>
        <button class="tab-btn" onclick="showTab('security')"><i class="fa-solid fa-shield-halved"></i> Security</button>
        <button class="tab-btn" onclick="showTab('notifications')"><i class="fa-solid fa-bell"></i> Notifications</button>
        <button class="tab-btn" onclick="showTab('ai')"><i class="fa-solid fa-robot"></i> AI Preferences</button>
        <button class="tab-btn" onclick="showTab('privacy')"><i class="fa-solid fa-user-shield"></i> Privacy & Data</button>
        
        <?php if ($role == 'student'): ?>
        <button class="tab-btn" onclick="showTab('student')"><i class="fa-solid fa-graduation-cap"></i> Student Goals</button>
        <?php elseif ($role == 'faculty_admin' || $role == 'super_admin'): ?>
        <button class="tab-btn" onclick="showTab('admin')"><i class="fa-solid fa-user-tie"></i> Admin Defaults</button>
        <?php endif; ?>

        <?php if ($role == 'super_admin' || $role == 'faculty_admin'): ?>
        <button class="tab-btn" onclick="showTab('branding')"><i class="fa-solid fa-palette"></i> Branding & Logo</button>
        <?php endif; ?>
    </div>

    <!-- Tab Content Panels -->
    <div id="settingsPanels">
        <!-- Profile Panel -->
        <div id="profilePanel" class="tab-content active">
            <form id="profileForm" onsubmit="saveSettings(event, 'profile')">
                <div class="settings-grid-2">
                    <div class="form-group">
                        <label>Full Name</label>
                        <input type="text" name="full_name" value="<?php echo htmlspecialchars($user_name); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Email Address</label>
                        <input type="email" name="email" value="<?php echo htmlspecialchars($user_email); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Phone Number</label>
                        <input type="text" name="phone" value="<?php echo htmlspecialchars($user_settings['phone'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>Timezone</label>
                        <select name="timezone">
                            <option value="GMT" <?php echo ($user_settings['timezone'] ?? '') == 'GMT' ? 'selected' : ''; ?>>GMT (Default)</option>
                            <option value="UTC" <?php echo ($user_settings['timezone'] ?? '') == 'UTC' ? 'selected' : ''; ?>>UTC</option>
                            <option value="Africa/Accra" <?php echo ($user_settings['timezone'] ?? '') == 'Africa/Accra' ? 'selected' : ''; ?>>Africa/Accra</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Week Start</label>
                        <select name="week_start">
                            <option value="Monday" <?php echo ($user_settings['week_start'] ?? '') == 'Monday' ? 'selected' : ''; ?>>Monday</option>
                            <option value="Sunday" <?php echo ($user_settings['week_start'] ?? '') == 'Sunday' ? 'selected' : ''; ?>>Sunday</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Time Format</label>
                        <select name="time_format">
                            <option value="12" <?php echo ($user_settings['time_format'] ?? '') == '12' ? 'selected' : ''; ?>>12-hour (AM/PM)</option>
                            <option value="24" <?php echo ($user_settings['time_format'] ?? '') == '24' ? 'selected' : ''; ?>>24-hour</option>
                        </select>
                    </div>
                </div>
                <div class="settings-actions-row">
                    <button type="submit" class="glass-btn"><i class="fa-solid fa-save"></i> Save Profile</button>
                    <button type="button" class="glass-btn secondary" onclick="resetForm('profileForm')">Reset</button>
                </div>
            </form>
        </div>

        <!-- Security Panel -->
        <div id="securityPanel" class="tab-content tab-content-hidden">
            <form id="securityForm" onsubmit="saveSettings(event, 'security')">
            <div class="settings-max-500">
                    <div class="form-group">
                        <label>Change Password</label>
                        <input type="password" name="password" placeholder="Enter new password">
                    </div>
                    <div class="form-group">
                        <label>Confirm Password</label>
                        <input type="password" name="confirm_password" placeholder="Confirm new password">
                    </div>
                    
                </div>
                <div class="settings-actions-row">
                    <button type="submit" class="glass-btn"><i class="fa-solid fa-shield-check"></i> Update Security</button>
                </div>
            </form>
        </div>

        <!-- Notifications Panel -->
        <div id="notificationsPanel" class="tab-content tab-content-hidden">
            <form id="notificationsForm" onsubmit="saveSettings(event, 'notifications')">
            <div class="settings-grid-2-wide">
                    <div>
                        <h4>Reminder Rules</h4>
                        <div class="security-note-box">
                            <p class="security-note-title"><i class="fa-solid fa-bell"></i> Per-reminder timing and enable/disable rules are managed in the dedicated reminder settings page.</p>
                            <p class="security-note-desc">Use that page to control class, exam, personal-event, and study reminder timing.</p>
                            <?php if ($role === 'student' || $role === 'lecturer'): ?>
                            <button type="button" class="glass-btn security-note-btn" onclick="window.location.href='reminder_settings.php'">Open Reminder Settings</button>
                            <?php else: ?>
                            <button type="button" class="glass-btn security-note-btn" disabled>Reminder rules available for student and lecturer accounts</button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div>
                        <h4>Delivery Channels</h4>
                        <div class="toggle-group">
                            <label class="switch">
                                <input type="checkbox" name="email_toggles" <?php echo ($notif_settings['email_toggles'] ?? 1) ? 'checked' : ''; ?>>
                                <span class="slider"></span>
                            </label>
                            <span>Email Notifications</span>
                        </div>
                        <div class="toggle-group">
                            <label class="switch">
                                <input type="checkbox" name="in_app_toggles" <?php echo ($notif_settings['in_app_toggles'] ?? 1) ? 'checked' : ''; ?>>
                                <span class="slider"></span>
                            </label>
                            <span>In-App Toasts</span>
                        </div>
                    </div>
                </div>
                
                <div class="settings-actions-row">
                    <h4>Quiet Hours</h4>
                    <div class="settings-inline-row">
                        <input type="time" name="quiet_hours_start" value="<?php echo $notif_settings['quiet_hours_start'] ?? '22:00'; ?>">
                        <span>to</span>
                        <input type="time" name="quiet_hours_end" value="<?php echo $notif_settings['quiet_hours_end'] ?? '07:00'; ?>">
                        <label class="switch">
                            <input type="checkbox" name="quiet_hours_enabled" <?php echo ($notif_settings['quiet_hours_enabled'] ?? 0) ? 'checked' : ''; ?>>
                            <span class="slider"></span>
                        </label>
                        <span>Enabled</span>
                    </div>
                </div>

                <div class="settings-actions-row">
                    <button type="submit" class="glass-btn"><i class="fa-solid fa-save"></i> Save Notification Settings</button>
                </div>
            </form>
        </div>

        <!-- AI Preferences Panel -->
        <div id="aiPanel" class="tab-content tab-content-hidden">
            <form id="aiForm" onsubmit="saveSettings(event, 'ai')">
            <div class="settings-max-600">
                    <div class="form-group">
                        <label>Suggestion Intensity</label>
                        <select name="intensity">
                            <option value="Low" <?php echo ($ai_settings['suggestion_intensity'] ?? 'Medium') == 'Low' ? 'selected' : ''; ?>>Low (Only for conflicts)</option>
                            <option value="Medium" <?php echo ($ai_settings['suggestion_intensity'] ?? 'Medium') == 'Medium' ? 'selected' : ''; ?>>Medium (Heuristic improvements)</option>
                            <option value="High" <?php echo ($ai_settings['suggestion_intensity'] ?? 'Medium') == 'High' ? 'selected' : ''; ?>>High (Maximum optimization)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Preferred Work Windows</label>
                        <div class="settings-inline-row">
                            <input type="time" name="work_start" value="<?php echo $ai_settings['preferred_work_start'] ?? '08:00'; ?>">
                            <span>to</span>
                            <input type="time" name="work_end" value="<?php echo $ai_settings['preferred_work_end'] ?? '17:00'; ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Max Daily Workload (Hours)</label>
                        <input type="number" name="max_load" value="<?php echo $ai_settings['max_daily_workload'] ?? 8; ?>" min="1" max="16">
                    </div>
                    <div class="toggle-group settings-top-15">
                        <label class="switch">
                            <input type="checkbox" name="learning_toggle" <?php echo ($ai_settings['accept_learning_toggle'] ?? 1) ? 'checked' : ''; ?>>
                            <span class="slider"></span>
                        </label>
                        <span>Allow AI to learn from my schedule preferences</span>
                    </div>
                </div>

                <div class="settings-actions-row">
                    <button type="submit" class="glass-btn"><i class="fa-solid fa-brain"></i> Update AI Preferences</button>
                    <button type="button" class="glass-btn secondary" onclick="presetAI('Balanced')">Reset to Balanced</button>
                </div>
            </form>
        </div>

        <!-- Privacy Panel -->
        <div id="privacyPanel" class="tab-content tab-content-hidden">
            <form id="privacyForm" onsubmit="saveSettings(event, 'privacy')">
            <div class="settings-max-600">
                    <div class="form-group">
                        <label>Profile Visibility</label>
                        <select name="visibility">
                            <option value="public" <?php echo ($user_settings['profile_visibility'] ?? 'faculty') == 'public' ? 'selected' : ''; ?>>Public (Visible to everyone)</option>
                            <option value="faculty" <?php echo ($user_settings['profile_visibility'] ?? 'faculty') == 'faculty' ? 'selected' : ''; ?>>Internal (Visible to faculty/staff)</option>
                            <option value="private" <?php echo ($user_settings['profile_visibility'] ?? 'faculty') == 'private' ? 'selected' : ''; ?>>Private (Only me)</option>
                        </select>
                    </div>
                    <div class="toggle-group">
                        <label class="switch">
                            <input type="checkbox" name="analytics_opt_in" <?php echo ($user_settings['analytics_opt_in'] ?? 1) ? 'checked' : ''; ?>>
                            <span class="slider"></span>
                        </label>
                        <span>Opt-in to anonymous usage analytics</span>
                    </div>
                    
                </div>
                <div class="settings-actions-row">
                    <button type="submit" class="glass-btn"><i class="fa-solid fa-user-shield"></i> Save Privacy Settings</button>
                </div>
            </form>
        </div>

        <!-- Student Specific Panel -->
        <?php if ($role == 'student'): ?>
        <div id="studentPanel" class="tab-content tab-content-hidden">
            <form id="studentForm" onsubmit="saveSettings(event, 'student')">
            <div class="settings-max-600">
                    <div class="form-group">
                        <label>Productivity Tracking</label>
                        <select name="productivity_pref">
                            <option value="Detailed" <?php echo ($user_settings['productivity_pref'] ?? 'Detailed') == 'Detailed' ? 'selected' : ''; ?>>Detailed (Track every slot)</option>
                            <option value="Basic" <?php echo ($user_settings['productivity_pref'] ?? '') == 'Basic' ? 'selected' : ''; ?>>Basic (Daily summary only)</option>
                            <option value="Off" <?php echo ($user_settings['productivity_pref'] ?? '') == 'Off' ? 'selected' : ''; ?>>Disabled</option>
                        </select>
                    </div>
                    <div class="toggle-group">
                        <label class="switch">
                            <input type="checkbox" name="auto_suggest_free" <?php echo ($user_settings['auto_suggest_free'] ?? 1) ? 'checked' : ''; ?>>
                            <span class="slider"></span>
                        </label>
                        <span>Auto-suggest free time blocks for study</span>
                    </div>
                </div>
                <div class="settings-actions-row">
                    <button type="submit" class="glass-btn"><i class="fa-solid fa-graduation-cap"></i> Save Student Preferences</button>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <!-- Admin Specific Panel -->
        <?php if ($role == 'faculty_admin' || $role == 'super_admin'): ?>
        <div id="adminPanel" class="tab-content tab-content-hidden">
            <form id="adminForm" onsubmit="saveSettings(event, 'admin')">
            <div class="settings-max-600">
                    <div class="form-group">
                        <label>Default Priority level for new courses</label>
                        <select name="default_priority">
                            <option value="High" <?php echo $admin_default_priority == 'High' ? 'selected' : ''; ?>>High</option>
                            <option value="Medium" <?php echo $admin_default_priority == 'Medium' ? 'selected' : ''; ?>>Medium</option>
                            <option value="Low" <?php echo $admin_default_priority == 'Low' ? 'selected' : ''; ?>>Low</option>
                        </select>
                    </div>
                </div>
                <div class="settings-actions-row">
                    <button type="submit" class="glass-btn"><i class="fa-solid fa-user-tie"></i> Save Admin Defaults</button>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <!-- Branding Panel (Super Admin) -->
        <?php if ($role == 'super_admin' || $role == 'faculty_admin'): ?>
        <div id="brandingPanel" class="tab-content tab-content-hidden">
            <form id="brandingForm" onsubmit="saveSettings(event, 'branding')">
                <div class="settings-grid-2-wide">
                    <div>
                        <div class="form-group">
                            <label>Website Title</label>
                            <input type="text" name="title" value="<?php echo htmlspecialchars($branding_settings['site_title'] ?? 'VVU Scheduler AI'); ?>" oninput="updatePreview('title', this.value)">
                        </div>
                        <div class="form-group">
                            <label>Primary Brand Color (Buttons & Highlights)</label>
                            <input type="color" name="color" class="settings-color-input" value="<?php echo htmlspecialchars($branding_settings['site_color'] ?? '#2563eb'); ?>" oninput="updatePreview('color', this.value)">
                        </div>
                        <div class="form-group">
                            <label>Secondary Brand Color (Gradients & Accents)</label>
                            <input type="color" name="secondary_color" class="settings-color-input" value="<?php echo htmlspecialchars($branding_secondary); ?>" oninput="updatePreview('secondary_color', this.value)">
                        </div>
                        <div class="form-group">
                            <label>Color Strength (<span id="colorStrengthValue"><?php echo $branding_strength; ?>%</span>)</label>
                            <input type="range" name="color_strength" min="50" max="150" step="1" value="<?php echo $branding_strength; ?>" oninput="updatePreview('color_strength', this.value)">
                        </div>
                        <div class="form-group">
                            <label>Secondary Background Color (App Background)</label>
                            <input type="color" name="bg_color" class="settings-color-input" value="<?php echo $branding_settings['site_bg_color'] ?? '#0f172a'; ?>" oninput="updatePreview('bg_color', this.value)">
                        </div>
                        <div class="form-group">
                            <label>Update Website Logo (PNG/SVG preferred)</label>
                            <input type="file" name="logo_file" class="glass-input" accept="image/*">
                            <input type="hidden" name="logo" value="<?php echo htmlspecialchars($branding_settings['site_logo'] ?? ''); ?>">
                            <?php if (!empty($branding_settings['site_logo'])): ?>
                                <p class="branding-logo-note">Current logo: <code><?php echo basename($branding_settings['site_logo']); ?></code></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="glass-panel branding-preview-panel">
                        <h4 class="branding-preview-title">Live Preview</h4>
                        <div id="brandingPreview" class="branding-preview-box">
                            <div class="branding-preview-head">
                                <div id="previewLogo" class="branding-preview-logo"></div>
                                <span id="previewTitle" class="branding-preview-text"><?php echo htmlspecialchars($branding_settings['site_title'] ?? 'VVU Scheduler AI'); ?></span>
                            </div>
                            <button type="button" class="glass-btn preview-btn branding-preview-btn">Sample Button</button>
                        </div>
                        <p class="branding-preview-help">Changes to colors and title show immediately in preview but apply site-wide only after saving.</p>
                    </div>
                </div>
                <div class="settings-actions-row">
                    <button type="submit" class="glass-btn"><i class="fa-solid fa-wand-magic-sparkles"></i> Apply Site Branding</button>
                </div>
            </form>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
function showTab(tabId) {
    const panel = document.getElementById(tabId + 'Panel');
    if (!panel) {
        console.error('Panel not found:', tabId + 'Panel');
        return;
    }

    // Hide all panels
    document.querySelectorAll('.tab-content').forEach(p => p.style.display = 'none');
    // Deactivate all buttons
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    
    // Show selected panel
    panel.style.display = 'block';
    // Activate clicked button
    if (event && event.currentTarget) {
        event.currentTarget.classList.add('active');
    }
}

async function saveSettings(event, type) {
    event.preventDefault();
    const form = event.target;
    const formData = new FormData(form);
    let payload = {};
    let options = {};

    if (type === 'branding') {
        // Use FormData directly for file uploads
        formData.append('type', type);
        options = {
            method: 'POST',
            body: formData
        };
    } else {
        // Convert form data to object for JSON payload
        formData.forEach((value, key) => {
            if (form.elements[key].type === 'checkbox') {
                payload[key] = form.elements[key].checked ? 1 : 0;
            } else {
                payload[key] = value;
            }
        });
        options = {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ type: type, payload: payload })
        };
    }

    try {
        const response = await fetch('api/save_settings.php', options);
        const result = await response.json();
        if (result.status === 'success') {
            await alert('Settings updated successfully!');
            if (type === 'branding' || type === 'profile') location.reload();
        } else {
            await alert('Error: ' + result.message);
        }
    } catch (e) {
        await alert('Failed to save settings: ' + e.message);
    }
}

function updatePreview(key, value) {
    const strengthInput = document.querySelector('input[name="color_strength"]');
    const strength = strengthInput ? parseInt(strengthInput.value || '100', 10) : 100;

    if (key === 'title') {
        document.getElementById('previewTitle').textContent = value;
    } else if (key === 'color') {
        const secondaryInput = document.querySelector('input[name="secondary_color"]');
        const secondary = (secondaryInput && secondaryInput.value) ? secondaryInput.value : adjustHexColor(value, 32);
        const appliedPrimary = applyColorStrength(value, strength);
        const appliedSecondary = applyColorStrength(secondary, strength);
        const primaryRgb = hexToRgbString(appliedPrimary);
        const secondaryRgb = hexToRgbString(appliedSecondary);
        document.documentElement.style.setProperty('--site-primary', appliedPrimary);
        document.documentElement.style.setProperty('--site-primary-hover', adjustHexColor(appliedPrimary, -18));
        document.documentElement.style.setProperty('--site-secondary', appliedSecondary);
        document.documentElement.style.setProperty('--site-primary-rgb', primaryRgb);
        document.documentElement.style.setProperty('--site-secondary-rgb', secondaryRgb);
        document.documentElement.style.setProperty('--primary-color', appliedPrimary);
        document.documentElement.style.setProperty('--primary', appliedPrimary);
        document.documentElement.style.setProperty('--secondary-color', appliedSecondary);
        document.documentElement.style.setProperty('--secondary', appliedSecondary);
        document.getElementById('previewLogo').style.background = appliedPrimary;
    } else if (key === 'secondary_color') {
        const appliedSecondary = applyColorStrength(value, strength);
        const secondaryRgb = hexToRgbString(appliedSecondary);
        document.documentElement.style.setProperty('--site-secondary', appliedSecondary);
        document.documentElement.style.setProperty('--site-secondary-rgb', secondaryRgb);
        document.documentElement.style.setProperty('--secondary-color', appliedSecondary);
        document.documentElement.style.setProperty('--secondary', appliedSecondary);
    } else if (key === 'color_strength') {
        document.getElementById('colorStrengthValue').textContent = `${value}%`;
        const primaryInput = document.querySelector('input[name="color"]');
        if (primaryInput) {
            updatePreview('color', primaryInput.value);
        }
    } else if (key === 'bg_color') {
        document.documentElement.style.setProperty('--site-bg', value);
        document.documentElement.style.setProperty('--bg-dark', value);
        document.getElementById('brandingPreview').style.backgroundColor = value;
    }
}

function adjustHexColor(hex, steps) {
    const normalized = (hex || '').replace('#', '');
    if (!/^[0-9a-fA-F]{6}$/.test(normalized)) return hex;
    const clamp = (n) => Math.max(0, Math.min(255, n));
    const r = clamp(parseInt(normalized.slice(0, 2), 16) + steps);
    const g = clamp(parseInt(normalized.slice(2, 4), 16) + steps);
    const b = clamp(parseInt(normalized.slice(4, 6), 16) + steps);
    return `#${r.toString(16).padStart(2, '0')}${g.toString(16).padStart(2, '0')}${b.toString(16).padStart(2, '0')}`;
}

function hexToRgbString(hex) {
    const normalized = (hex || '').replace('#', '');
    if (!/^[0-9a-fA-F]{6}$/.test(normalized)) return '79, 70, 229';
    const r = parseInt(normalized.slice(0, 2), 16);
    const g = parseInt(normalized.slice(2, 4), 16);
    const b = parseInt(normalized.slice(4, 6), 16);
    return `${r}, ${g}, ${b}`;
}

function applyColorStrength(hex, strengthPercent) {
    const normalized = (hex || '').replace('#', '');
    if (!/^[0-9a-fA-F]{6}$/.test(normalized)) return hex;

    const clamp = (n) => Math.max(0, Math.min(255, n));
    const strength = Math.max(50, Math.min(150, parseInt(strengthPercent || '100', 10)));
    let r = parseInt(normalized.slice(0, 2), 16);
    let g = parseInt(normalized.slice(2, 4), 16);
    let b = parseInt(normalized.slice(4, 6), 16);

    if (strength < 100) {
        const factor = strength / 100;
        r = Math.round(r * factor);
        g = Math.round(g * factor);
        b = Math.round(b * factor);
    } else if (strength > 100) {
        const factor = (strength - 100) / 50;
        r = Math.round(r + (255 - r) * factor);
        g = Math.round(g + (255 - g) * factor);
        b = Math.round(b + (255 - b) * factor);
    }

    return `#${clamp(r).toString(16).padStart(2, '0')}${clamp(g).toString(16).padStart(2, '0')}${clamp(b).toString(16).padStart(2, '0')}`;
}

function presetAI(preset) {
    if (preset === 'Balanced') {
        const form = document.getElementById('aiForm');
        form.elements['intensity'].value = 'Medium';
        form.elements['max_load'].value = 8;
        form.elements['learning_toggle'].checked = true;
    }
}
</script>

<?php include 'includes/footer.php'; ?>
