<?php
// web/schedules.php
// List and manage AI-generated schedules from B2 storage with RBAC
$page_title = 'Generated Schedules';
$page_css = 'assets/schedules.css';
include 'includes/header.php';
require_once 'api/db.php';
require_once '../lib/B2Storage.php';

// Access Control: super_admin can see all, faculty_admin only their department
requireRole(['super_admin', 'faculty_admin']);

$user_role = $_SESSION['role'] ?? 'faculty_admin';
$user_department = $_SESSION['department'] ?? '';
?>

<div class="glass-panel schedules-container">
    <div class="schedules-hero">
        <div class="schedules-icon-wrap">
            <i class="fa-solid fa-calendar-days schedules-icon"></i>
        </div>
        <h2>Generated Schedules</h2>
        <p class="schedules-subtitle">View and manage AI-generated schedules from B2 storage</p>
    </div>

    <!-- Filters -->
    <div class="schedules-filters">
        <select id="filterSemester" class="glass-input schedules-filter-select">
            <option value="">All Semesters</option>
            <option value="1">First Semester</option>
            <option value="2">Second Semester</option>
            <option value="3">All Semesters</option>
        </select>
        
        <?php if ($user_role === 'super_admin'): ?>
        <select id="filterDepartment" class="glass-input schedules-filter-select">
            <option value="">All Departments</option>
            <option value="CS/IT/BBIS">Computing Science / IT / BBIS</option>
            <option value="Business">Business</option>
            <option value="Nursing">Nursing</option>
            <option value="Theology">Theology</option>
            <option value="General">General</option>
        </select>
        <?php endif; ?>

        <select id="filterType" class="glass-input schedules-filter-select">
            <option value="">All Types</option>
            <option value="class">Class Timetable</option>
            <option value="exam">Exam Timetable</option>
        </select>
        
        <button onclick="refreshSchedules()" class="glass-btn schedules-refresh-btn">
            <i class="fa-solid fa-rotate"></i> Refresh
        </button>
    </div>

    <!-- Schedules List -->
    <div id="schedulesList">
        <div class="schedules-loading">
            <i class="fa-solid fa-circle-notch fa-spin schedules-loading-icon"></i>
            <p>Loading schedules from B2...</p>
        </div>
    </div>
</div>

<script>
let allSchedules = [];

async function loadSchedules() {
    try {
        const response = await fetch('api/list_b2_schedules.php');
        const data = await response.json();
        
        console.log('B2 Schedules Response:', data); // Debug log
        
        if (data.status === 'success') {
            allSchedules = data.schedules || [];
            console.log('Loaded schedules:', allSchedules); // Debug log
            renderSchedules();
        } else {
            showError(data.message || 'Failed to load schedules');
        }
    } catch (e) {
        console.error('Error loading schedules:', e); // Debug log
        showError('Error loading schedules: ' + e.message);
    }
}

function renderSchedules() {
    const semester = document.getElementById('filterSemester').value;
    const department = document.getElementById('filterDepartment')?.value || '';
    const type = document.getElementById('filterType').value;
    
    let filtered = allSchedules.filter(schedule => {
        if (semester && schedule.semester !== semester) return false;
        if (department && schedule.department !== department) return false;
        if (type && schedule.type !== type) return false;
        
        // RBAC: faculty_admin can only see their department
        <?php if ($user_role === 'faculty_admin'): ?>
        if (schedule.department !== '<?php echo $user_department; ?>') return false;
        <?php endif; ?>
        
        return true;
    });
    
    const container = document.getElementById('schedulesList');
    
    if (filtered.length === 0) {
        container.innerHTML = `
            <div class="schedules-empty">
                <i class="fa-solid fa-folder-open schedules-empty-icon"></i>
                <p>No schedules found matching your filters</p>
            </div>
        `;
        return;
    }
    
    container.innerHTML = `
        <div class="schedules-table-wrap">
            <table class="schedules-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Status</th>
                       
                        <th>Department</th>
                       
                        <th>Uploaded</th>
                        <th>Size</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    ${filtered.map(schedule => `
                        <tr>
                            <td><strong>${escapeHtml(schedule.name || 'N/A')}</strong></td>
                            <td>
                                <span class="badge ${schedule.saved_to_db ? 'badge-saved' : 'badge-unsaved'}">
                                    <i class="fa-solid fa-${schedule.saved_to_db ? 'check-circle' : 'clock'}"></i>
                                    ${schedule.saved_to_db ? 'Saved' : 'Not Saved'}
                                </span>
                            </td>
                            
                            <td>${escapeHtml(schedule.department || 'N/A')}</td>
                           
                            <td>${schedule.uploaded ? new Date(schedule.uploaded).toLocaleString() : 'N/A'}</td>
                            <td>${formatFileSize(schedule.size || 0)}</td>
                            <td>
                                <div class="schedule-actions">
                                    <button onclick="viewSchedule('${escapeJs(schedule.file)}')" class="glass-btn primary small">
                                        <i class="fa-solid fa-eye"></i> View
                                    </button>
                                    <button onclick="downloadSchedule('${escapeJs(schedule.file)}', '${escapeJs(schedule.name)}')" class="glass-btn secondary small">
                                        <i class="fa-solid fa-download"></i> Download
                                    </button>
                                    ${!schedule.saved_to_db ? `
                                        <button onclick="saveToDatabase('${escapeJs(schedule.file)}', '${escapeJs(schedule.name)}', '${schedule.semester}', '${escapeJs(schedule.department)}')" class="glass-btn schedules-save-btn small">
                                            <i class="fa-solid fa-floppy-disk"></i> Save
                                        </button>
                                    ` : ''}
                                    <?php if ($user_role === 'super_admin'): ?>
                                    <button onclick="deleteSchedule('${escapeJs(schedule.file)}')" class="glass-btn danger small">
                                        <i class="fa-solid fa-trash"></i> Delete
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        </div>
    `;
}

async function saveToDatabase(file, name, semester, department) {
    if (!await showConfirm(`Save "${name}" to database? This will make it available for viewing and reporting.`, 'Save Schedule')) {
        return;
    }
    
    try {
        // First, download the file content
        const downloadResponse = await fetch(`api/download_b2_file.php?file=${encodeURIComponent(file)}`);
        const scheduleData = await downloadResponse.json();
        
        if (scheduleData.status !== 'success') {
            throw new Error(scheduleData.message || 'Failed to download schedule');
        }
        
        // Save to database
        const saveResponse = await fetch('api/save_generated_schedule.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                schedule_name: name,
                semester: semester,
                department: department,
                accuracy: '', // Can be extracted from filename or metadata
                schedule_data: scheduleData.data
            })
        });
        
        const saveResult = await saveResponse.json();
        
        if (saveResult.status === 'success') {
            showSuccess('Schedule saved to database successfully!');
            // Mark as saved in UI
            setTimeout(() => loadSchedules(), 500);
        } else {
            throw new Error(saveResult.message || 'Failed to save to database');
        }
    } catch (e) {
        showError('Error saving schedule: ' + e.message);
    }
}

function viewSchedule(file) {
    // Open schedule viewer in current or new tab
    window.open(`view_schedule.php?file=${encodeURIComponent(file)}`, '_blank');
}

async function downloadSchedule(file, name) {
    try {
        const response = await fetch(`api/download_b2_file.php?file=${encodeURIComponent(file)}&download=1`);
        const blob = await response.blob();
        
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = name.endsWith('.csv') ? name : name + '.csv';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        window.URL.revokeObjectURL(url);
        
        showSuccess('Schedule downloaded successfully!');
    } catch (e) {
        showError('Error downloading schedule: ' + e.message);
    }
}

<?php if ($user_role === 'super_admin'): ?>
async function deleteSchedule(file) {
    if (!await showConfirm(`Delete "${file}" permanently? This cannot be undone.`, 'Delete Schedule')) {
        return;
    }
    
    try {
        const response = await fetch('api/delete_b2_file.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ file: file })
        });
        
        const result = await response.json();
        
        if (result.status === 'success') {
            showSuccess('Schedule deleted successfully!');
            loadSchedules();
        } else {
            throw new Error(result.message || 'Failed to delete schedule');
        }
    } catch (e) {
        showError('Error deleting schedule: ' + e.message);
    }
}
<?php endif; ?>

function refreshSchedules() {
    document.getElementById('schedulesList').innerHTML = `
        <div class="schedules-loading">
            <i class="fa-solid fa-circle-notch fa-spin schedules-loading-icon"></i>
            <p>Refreshing schedules...</p>
        </div>
    `;
    loadSchedules();
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function escapeJs(text) {
    if (!text) return '';
    return text.replace(/\\/g, '\\\\')
               .replace(/'/g, "\\'")
               .replace(/"/g, '\\"')
               .replace(/\n/g, '\\n')
               .replace(/\r/g, '\\r');
}

function formatFileSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
}

async function showSuccess(message) {
    // Implement toast notification
    await showAlert(message, 'Success');
}

async function showError(message) {
    // Implement toast notification
    await showAlert('Error: ' + message, 'Error');
}

// Add event listeners for filters
document.getElementById('filterSemester').addEventListener('change', renderSchedules);
<?php if ($user_role === 'super_admin'): ?>
document.getElementById('filterDepartment').addEventListener('change', renderSchedules);
<?php endif; ?>
document.getElementById('filterType').addEventListener('change', renderSchedules);

// Load schedules on page load
loadSchedules();
</script>

<?php include 'includes/footer.php'; ?>
