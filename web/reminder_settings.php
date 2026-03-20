<?php
$page_title = 'Reminder Settings';
$page_css = 'assets/reminder_settings.css';
include 'includes/header.php';
requireRole(['student', 'lecturer']);
$user_id = $_SESSION['user_id'];
?>

<div class="container reminder-settings-container">
    <h1><i class="fas fa-clock"></i> Reminder Settings</h1>
    <p class="reminder-settings-subtitle">
        Configure when and how you want to be reminded about your commitments
    </p>
    
    <div class="glass-panel reminder-settings-panel">
        <div id="settingsList">
            <div class="reminder-settings-placeholder">
                Loading settings...
            </div>
        </div>
        
        <div class="reminder-settings-actions">
            <button class="glass-btn" onclick="saveSettings()">
                <i class="fas fa-save"></i> Save Settings
            </button>
            <button class="glass-btn reminder-settings-generate-btn" onclick="generateReminders()">
                <i class="fas fa-sync"></i> Generate Reminders Now
            </button>
        </div>
    </div>
</div>

<script>
let settings = [];

async function loadSettings() {
    try {
        const response = await fetch('api/notifications.php?action=get_reminder_settings');
        const data = await response.json();
        
        if (data.success) {
            settings = data.settings;
            
            // If no settings exist, create defaults
            if (settings.length === 0) {
                await createDefaultSettings();
                return loadSettings();
            }
            
            renderSettings();
        }
    } catch (error) {
        console.error('Failed to load settings:', error);
        document.getElementById('settingsList').innerHTML = 
            '<div class="reminder-settings-error">Failed to load settings</div>';
    }
}

async function createDefaultSettings() {
    const defaults = [
        { reminder_type: 'course_reminder', enabled: true, minutes_before: 30, delivery_method: 'in_app' },
        { reminder_type: 'exam_reminder', enabled: true, minutes_before: 1440, delivery_method: 'in_app' },
        { reminder_type: 'personal_event', enabled: true, minutes_before: 15, delivery_method: 'in_app' },
        { reminder_type: 'study_session', enabled: false, minutes_before: 30, delivery_method: 'in_app' }
    ];
    
    for (const setting of defaults) {
        await fetch('api/notifications.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'update_reminder_settings', ...setting})
        });
    }
}

function renderSettings() {
    const typeNames = {
        'course_reminder': 'Class Reminders',
        'exam_reminder': 'Exam Reminders',
        'personal_event': 'Personal Event Reminders',
        'study_session': 'Study Session Reminders'
    };
    
    const typeDescriptions = {
        'course_reminder': 'Get notified before your classes start',
        'exam_reminder': 'Don\'t miss important exams',
        'personal_event': 'Reminders for your personal commitments',
        'study_session': 'Scheduled study time reminders'
    };
    
    const typeIcons = {
        'course_reminder': 'fa-graduation-cap',
        'exam_reminder': 'fa-file-alt',
        'personal_event': 'fa-calendar-check',
        'study_session': 'fa-book'
    };
    
    let html = '';
    
    settings.forEach((setting, index) => {
        const name = typeNames[setting.reminder_type] || setting.reminder_type;
        const description = typeDescriptions[setting.reminder_type] || '';
        const icon = typeIcons[setting.reminder_type] || 'fa-bell';
        const enabled = setting.enabled === 1 || setting.enabled === '1' || setting.enabled === true;
        
        html += `
            <div class="setting-item">
                <div class="setting-header">
                    <div>
                        <h3 class="setting-title">
                            <i class="fas ${icon}"></i> ${name}
                        </h3>
                        <p class="setting-description">
                            ${description}
                        </p>
                    </div>
                    <label class="toggle-switch">
                        <input type="checkbox" 
                               ${enabled ? 'checked' : ''} 
                               onchange="updateSettingEnabled(${index}, this.checked)">
                        <span class="toggle-slider"></span>
                    </label>
                </div>
                
                <div class="setting-controls ${enabled ? '' : 'setting-controls-disabled'}">
                    <div class="input-group">
                        <label>Remind me (global lead time)</label>
                        <select class="glass-input" 
                                onchange="updateSettingMinutes(${index}, this.value)">
                            <option value="5" ${setting.minutes_before == 5 ? 'selected' : ''}>5 minutes before</option>
                            <option value="10" ${setting.minutes_before == 10 ? 'selected' : ''}>10 minutes before</option>
                            <option value="15" ${setting.minutes_before == 15 ? 'selected' : ''}>15 minutes before</option>
                            <option value="30" ${setting.minutes_before == 30 ? 'selected' : ''}>30 minutes before</option>
                            <option value="60" ${setting.minutes_before == 60 ? 'selected' : ''}>1 hour before</option>
                            <option value="120" ${setting.minutes_before == 120 ? 'selected' : ''}>2 hours before</option>
                            <option value="1440" ${setting.minutes_before == 1440 ? 'selected' : ''}>1 day before</option>
                            <option value="2880" ${setting.minutes_before == 2880 ? 'selected' : ''}>2 days before</option>
                        </select>
                    </div>
                    
                    <div class="input-group">
                        <label>Delivery method (global)</label>
                        <select class="glass-input" 
                                onchange="updateSettingDelivery(${index}, this.value)">
                            <option value="in_app" ${setting.delivery_method === 'in_app' ? 'selected' : ''}>In-App</option>
                            <option value="email" ${setting.delivery_method === 'email' ? 'selected' : ''} disabled>Email (Coming Soon)</option>
                            <option value="sms" ${setting.delivery_method === 'sms' ? 'selected' : ''} disabled>SMS (Coming Soon)</option>
                        </select>
                    </div>
                </div>
            </div>
        `;
    });
    
    document.getElementById('settingsList').innerHTML = html;
}

function updateSettingEnabled(index, enabled) {
    settings[index].enabled = enabled;
    renderSettings();
}

function updateSettingMinutes(index, minutes) {
    const val = parseInt(minutes);
    settings.forEach(s => s.minutes_before = val);
}

function updateSettingDelivery(index, method) {
    settings.forEach(s => s.delivery_method = method);
}

async function saveSettings() {
    let saved = 0;
    let failed = 0;
    
    for (const setting of settings) {
        try {
            const response = await fetch('api/notifications.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    action: 'update_reminder_settings',
                    reminder_type: setting.reminder_type,
                    enabled: setting.enabled,
                    minutes_before: setting.minutes_before,
                    delivery_method: setting.delivery_method
                })
            });
            
            const data = await response.json();
            if (data.success) {
                saved++;
            } else {
                failed++;
            }
        } catch (error) {
            failed++;
        }
    }
    
    if (failed === 0) {
        await showAlert('All settings saved successfully!', 'Success');
    } else {
        await showAlert(`Saved ${saved} settings, ${failed} failed`, 'Warning');
    }
}

async function generateReminders() {
    try {
        const response = await fetch('api/notifications.php?action=generate_reminders');
        const data = await response.json();
        
        if (data.success) {
            await showAlert(`Generated ${data.count} reminder(s)!`, 'Success');
        } else {
            await showAlert('Failed to generate reminders', 'Error');
        }
    } catch (error) {
        await showAlert('Error generating reminders', 'Error');
    }
}

document.addEventListener('DOMContentLoaded', loadSettings);
</script>

<?php include 'includes/footer.php'; ?>
