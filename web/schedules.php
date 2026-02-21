<?php
// web/schedules.php
// List and manage AI-generated schedules from B2 storage with RBAC
$page_title = 'Generated Schedules';
include 'includes/header.php';
require_once 'api/db.php';
require_once '../lib/B2Storage.php';

// Access Control: super_admin can see all, faculty_admin only their department
requireRole(['super_admin', 'faculty_admin']);

$user_role = $_SESSION['role'] ?? 'faculty_admin';
$user_department = $_SESSION['department'] ?? '';
?>

<div class="glass-panel" style="padding: 2rem; max-width: 1400px; margin: 0 auto;">
    <div style="text-align: center; margin-bottom: 2rem;">
        <div style="width: 80px; height: 80px; background: linear-gradient(135deg, #10b981, #3b82f6); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1rem; box-shadow: 0 0 20px rgba(16, 185, 129, 0.5);">
            <i class="fa-solid fa-calendar-days" style="font-size: 2rem; color: white;"></i>
        </div>
        <h2>Generated Schedules</h2>
        <p style="color: var(--text-muted);">View and manage AI-generated schedules from B2 storage</p>
    </div>

    <!-- Filters -->
    <div style="display: flex; gap: 1rem; margin-bottom: 1.5rem; flex-wrap: wrap;">
        <select id="filterSemester" class="glass-input" style="flex: 1; min-width: 200px;">
            <option value="">All Semesters</option>
            <option value="1">First Semester</option>
            <option value="2">Second Semester</option>
            <option value="3">All Semesters</option>
        </select>
        
        <?php if ($user_role === 'super_admin'): ?>
        <select id="filterDepartment" class="glass-input" style="flex: 1; min-width: 200px;">
            <option value="">All Departments</option>
            <option value="CS/IT/BBIS">Computing Science / IT / BBIS</option>
            <option value="Business">Business</option>
            <option value="Nursing">Nursing</option>
            <option value="Theology">Theology</option>
            <option value="General">General</option>
        </select>
        <?php endif; ?>

        <select id="filterType" class="glass-input" style="flex: 1; min-width: 200px;">
            <option value="">All Types</option>
            <option value="class">Class Timetable</option>
            <option value="exam">Exam Timetable</option>
        </select>
        
        <button onclick="refreshSchedules()" class="glass-btn" style="min-width: 120px;">
            <i class="fa-solid fa-rotate"></i> Refresh
        </button>
    </div>

    <!-- Schedules List -->
    <div id="schedulesList">
        <div style="text-align: center; padding: 3rem; color: var(--text-muted);">
            <i class="fa-solid fa-circle-notch fa-spin" style="font-size: 2rem; margin-bottom: 1rem;"></i>
            <p>Loading schedules from B2...</p>
        </div>
    </div>
</div>

<style>
    .schedule-card {
        background: rgba(15, 23, 42, 0.6);
        border: 1px solid rgba(99, 102, 241, 0.3);
        border-radius: 12px;
        padding: 1.5rem;
        margin-bottom: 1rem;
        transition: all 0.3s ease;
    }
    
    .schedule-card:hover {
        border-color: rgba(99, 102, 241, 0.6);
        box-shadow: 0 4px 20px rgba(99, 102, 241, 0.3);
        transform: translateY(-2px);
    }
    
    .schedule-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1rem;
    }
    
    .schedule-title {
        font-size: 1.1rem;
        font-weight: 600;
        color: white;
        margin: 0;
    }
    
    .schedule-meta {
        display: flex;
        gap: 1.5rem;
        flex-wrap: wrap;
        margin-bottom: 1rem;
    }
    
    .meta-item {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        color: var(--text-muted);
        font-size: 0.9rem;
    }
    
    .schedule-actions {
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
    }
    
    .badge {
        padding: 4px 12px;
        border-radius: 12px;
        font-size: 0.85rem;
        font-weight: 600;
    }
    
    .badge-saved {
        background: rgba(16, 185, 129, 0.2);
        color: #10b981;
    }
    
    .badge-unsaved {
        background: rgba(251, 146, 60, 0.2);
        color: #fb923c;
    }
</style>

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
            <div style="text-align: center; padding: 3rem; color: var(--text-muted);">
                <i class="fa-solid fa-folder-open" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                <p>No schedules found matching your filters</p>
            </div>
        `;
        return;
    }
    
    container.innerHTML = filtered.map(schedule => `
        <div class="schedule-card">
            <div class="schedule-header">
                <div>
                    <h3 class="schedule-title">${escapeHtml(schedule.name)}</h3>
                    <span class="badge ${schedule.saved_to_db ? 'badge-saved' : 'badge-unsaved'}">
                        <i class="fa-solid fa-${schedule.saved_to_db ? 'check-circle' : 'clock'}"></i>
                        ${schedule.saved_to_db ? 'Saved to DB' : 'Not Saved'}
                    </span>
                </div>
            </div>
            
            <div class="schedule-meta">
                <div class="meta-item">
                    <i class="fa-solid fa-calendar"></i>
                    Semester ${schedule.semester || 'N/A'}
                </div>
                <div class="meta-item">
                    <i class="fa-solid fa-building"></i>
                    ${escapeHtml(schedule.department || 'N/A')}
                </div>
                <div class="meta-item">
                    <i class="fa-solid fa-${schedule.type === 'exam' ? 'file-pen' : 'chalkboard-user'}"></i>
                    ${schedule.type === 'exam' ? 'Exam' : 'Class'}
                </div>
                <div class="meta-item">
                    <i class="fa-solid fa-clock"></i>
                    ${schedule.uploaded ? new Date(schedule.uploaded).toLocaleString() : 'N/A'}
                </div>
                <div class="meta-item">
                    <i class="fa-solid fa-file"></i>
                    ${formatFileSize(schedule.size || 0)}
                </div>
            </div>
            
            <div class="schedule-actions">
                <button onclick="viewSchedule('${escapeJs(schedule.file)}')" class="glass-btn primary">
                    <i class="fa-solid fa-eye"></i> View
                </button>
                
                <button onclick="downloadSchedule('${escapeJs(schedule.file)}', '${escapeJs(schedule.name)}')" class="glass-btn secondary">
                    <i class="fa-solid fa-download"></i> Download
                </button>
                
                ${!schedule.saved_to_db ? `
                    <button onclick="saveToDatabase('${escapeJs(schedule.file)}', '${escapeJs(schedule.name)}', '${schedule.semester}', '${escapeJs(schedule.department)}')" class="glass-btn" style="background: linear-gradient(135deg, #10b981, #059669);">
                        <i class="fa-solid fa-floppy-disk"></i> Save to DB
                    </button>
                ` : ''}
                
                <?php if ($user_role === 'super_admin'): ?>
                <button onclick="deleteSchedule('${escapeJs(schedule.file)}')" class="glass-btn danger">
                    <i class="fa-solid fa-trash"></i> Delete
                </button>
                <?php endif; ?>
            </div>
        </div>
    `).join('');
}

async function saveToDatabase(file, name, semester, department) {
    if (!confirm(`Save "${name}" to database? This will make it available for viewing and reporting.`)) {
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
    if (!confirm(`Delete "${file}" permanently? This cannot be undone.`)) {
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
        <div style="text-align: center; padding: 3rem; color: var(--text-muted);">
            <i class="fa-solid fa-circle-notch fa-spin" style="font-size: 2rem; margin-bottom: 1rem;"></i>
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

function showSuccess(message) {
    // Implement toast notification
    alert(message);
}

function showError(message) {
    // Implement toast notification
    alert('Error: ' + message);
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
