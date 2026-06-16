<?php
$page_title = 'System Audit Trail';
$page_css = 'assets/audit_trail.css';
include 'includes/header.php';
require_once 'api/db.php';

// Require admin access
requireRole(['super_admin', 'faculty_admin']);

// Ensure audit_log table exists
$conn->query("CREATE TABLE IF NOT EXISTS audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50),
    entity_id INT,
    details JSON,
    ip_address VARCHAR(45),
    user_agent VARCHAR(255),
    log_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_time (user_id, log_time),
    INDEX idx_action (action),
    INDEX idx_time (log_time)
)");
?>

<div class="glass-panel audit-trail-container">
    <div class="audit-trail-header">
        <div>
            <h2><i class="fa-solid fa-history"></i> System Audit Trail</h2>
            <p class="audit-trail-subtitle">Complete history of all system actions and decisions</p>
        </div>
        <div>
            <button class="glass-btn" onclick="exportAuditLog()">
                <i class="fa-solid fa-download"></i> Export CSV
            </button>
            <button class="glass-btn secondary audit-trail-stats-btn" onclick="showAuditStats()">
                <i class="fa-solid fa-chart-pie"></i> Statistics
            </button>
        </div>
    </div>

    <!-- Filters -->
    <div class="audit-trail-filters">
        <input type="text" id="searchInput" placeholder="Search action/user..." class="glass-input audit-trail-filter-input"
               onkeyup="filterLogs()">
        
        <select id="actionFilter" class="glass-input audit-trail-filter-input" onchange="filterLogs()">
            <option value="">All Actions</option>
            <option value="LOGIN">Login</option>
            <option value="SCHEDULE_GEN">Schedule Generation</option>
            <option value="USER_CREATED">User Created</option>
            <option value="COURSE_UPDATED">Course Updated</option>
            <option value="CONFLICT">Conflict Detected</option>
            <option value="SCHEDULE_EXPORTED">Schedule Exported</option>
        </select>

        <select id="userFilter" class="glass-input audit-trail-filter-input" onchange="filterLogs()">
            <option value="">All Users</option>
        </select>

        <select id="rangeFilter" class="glass-input audit-trail-filter-input" onchange="filterLogs()">
            <option value="">All Time</option>
            <option value="24h">Last 24 Hours</option>
            <option value="7d">Last 7 Days</option>
            <option value="30d">Last 30 Days</option>
        </select>
    </div>

    <!-- Summary Cards -->
    <div class="audit-trail-stats-grid">
        <div class="glass-panel audit-stat-card audit-stat-total">
            <p class="audit-stat-label">TOTAL ACTIONS</p>
            <p class="audit-stat-value audit-stat-value--green" id="totalActions">--</p>
        </div>
        <div class="glass-panel audit-stat-card audit-stat-users">
            <p class="audit-stat-label">ACTIVE USERS</p>
            <p class="audit-stat-value audit-stat-value--purple" id="activeUsers">--</p>
        </div>
        <div class="glass-panel audit-stat-card audit-stat-last">
            <p class="audit-stat-label">LAST ACTION</p>
            <p class="audit-stat-value audit-stat-value--violet" id="lastAction">--</p>
        </div>
        <div class="glass-panel audit-stat-card audit-stat-errors">
            <p class="audit-stat-label">ERRORS TODAY</p>
            <p class="audit-stat-value audit-stat-value--amber" id="errorsToday">0</p>
        </div>
    </div>

    <!-- Audit Log Table -->
    <div class="glass-panel audit-trail-table-panel">
        <table id="auditTable" class="audit-trail-table">
            <thead>
                <tr class="audit-trail-thead-row">
                    <th class="audit-trail-th">Timestamp</th>
                    <th class="audit-trail-th">User</th>
                    <th class="audit-trail-th">Action</th>
                    <th class="audit-trail-th">Details</th>
                    <th class="audit-trail-th">IP Address</th>
                    <th class="audit-trail-th audit-trail-th--center">Severity</th>
                </tr>
            </thead>
            <tbody id="logBody">
                <tr>
                    <td colspan="6" class="audit-trail-loading-cell">
                        <i class="fa-solid fa-spinner fa-spin"></i> Loading audit trail...
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <div class="audit-trail-pagination">
        <button class="glass-btn secondary" onclick="previousPage()" id="prevBtn">← Previous</button>
        <span id="pageInfo">Page 1</span>
        <button class="glass-btn secondary" onclick="nextPage()" id="nextBtn">Next →</button>
    </div>
</div>



<script>
let allLogs = [];
let currentPage = 1;
const logsPerPage = 50;

// Load audit logs
async function loadAuditLogs() {
    try {
        const response = await fetch('api/get_audit_logs.php', {
            credentials: 'include'
        });

        if (!response.ok) throw new Error('Failed to load audit logs');

        const data = await response.json();
        console.log('Audit logs:', data);

        if (data.status === 'success') {
            allLogs = data.logs || [];
            updateSummary();
            populateUserFilter();
            displayLogs(currentPage);
        }
    } catch (error) {
        console.error('Error loading logs:', error);
        document.getElementById('logBody').innerHTML = 
            '<tr><td colspan="6" class="audit-trail-error-cell"><i class="fa-solid fa-exclamation-circle"></i> Error loading audit trail</td></tr>';
    }
}

// Update summary cards
function updateSummary() {
    document.getElementById('totalActions').textContent = allLogs.length;
    
    const uniqueUsers = new Set(allLogs.map(log => log.user_id));
    document.getElementById('activeUsers').textContent = uniqueUsers.size;
    
    if (allLogs.length > 0) {
        const lastLog = allLogs[0];
        document.getElementById('lastAction').textContent = new Date(lastLog.log_time).toLocaleTimeString();
    }
    
    const todayStart = new Date();
    todayStart.setHours(0, 0, 0, 0);
    const errorsToday = allLogs.filter(log => {
        const logTime = new Date(log.log_time);
        return logTime >= todayStart && log.action.includes('ERROR');
    }).length;
    document.getElementById('errorsToday').textContent = errorsToday;
}

// Populate user filter
function populateUserFilter() {
    const users = [...new Set(allLogs.map(log => ({ id: log.user_id, name: log.user_name })))];
    const select = document.getElementById('userFilter');
    
    users.forEach(user => {
        const option = document.createElement('option');
        option.value = user.id;
        option.textContent = user.name;
        select.appendChild(option);
    });
}

// Display logs with pagination
function displayLogs(page) {
    currentPage = page;
    const start = (page - 1) * logsPerPage;
    const end = start + logsPerPage;
    const logsToShow = allLogs.slice(start, end);
    
    let html = '';
    
    if (logsToShow.length === 0) {
        html = '<tr><td colspan="6" class="audit-trail-loading-cell">No audit logs found</td></tr>';
    } else {
        logsToShow.forEach(log => {
            const severity = getSeverity(log.action);
            const date = new Date(log.log_time);
            
            html += `
            <tr onclick="showLogDetails('${encodeURIComponent(JSON.stringify(log))}')" class="audit-trail-log-row">
                <td data-label="Time">${date.toLocaleString()}</td>
                <td data-label="User">${log.user_name}</td>
                <td data-label="Action"><span class="action-badge">${log.action}</span></td>
                <td data-label="Details" class="audit-trail-details-cell">${log.details || 'N/A'}</td>
                <td data-label="IP" class="audit-trail-ip-cell">${log.ip_address}</td>
                <td data-label="Severity" class="audit-trail-severity-cell">
                    <span class="severity-${severity.level} audit-severity-badge">
                        ${severity.label}
                    </span>
                </td>
            </tr>`;
        });
    }
    
    document.getElementById('logBody').innerHTML = html;
    
    // Update pagination
    const totalPages = Math.ceil(allLogs.length / logsPerPage);
    document.getElementById('pageInfo').textContent = `Page ${page} of ${totalPages}`;
    document.getElementById('prevBtn').disabled = page === 1;
    document.getElementById('nextBtn').disabled = page === totalPages;
}

// Get severity level
function getSeverity(action) {
    if (action.includes('ERROR') || action.includes('FAILURE')) {
        return { level: 'critical', label: 'Critical' };
    } else if (action.includes('CONFLICT') || action.includes('WARNING')) {
        return { level: 'high', label: 'High' };
    } else if (action.includes('DELETE') || action.includes('REVOKE')) {
        return { level: 'medium', label: 'Medium' };
    }
    return { level: 'low', label: 'Low' };
}

// Filter logs
function filterLogs() {
    const search = document.getElementById('searchInput').value.toLowerCase();
    const action = document.getElementById('actionFilter').value;
    const user = document.getElementById('userFilter').value;
    const range = document.getElementById('rangeFilter').value;
    
    const filtered = allLogs.filter(log => {
        const matchesSearch = !search || 
            log.action.toLowerCase().includes(search) ||
            log.user_name.toLowerCase().includes(search);
        
        const matchesAction = !action || log.action.includes(action);
        const matchesUser = !user || log.user_id == user;
        
        let matchesRange = true;
        if (range) {
            const logDate = new Date(log.log_time);
            const now = new Date();
            const diffMs = now - logDate;
            
            if (range === '24h') matchesRange = diffMs < 24 * 60 * 60 * 1000;
            else if (range === '7d') matchesRange = diffMs < 7 * 24 * 60 * 60 * 1000;
            else if (range === '30d') matchesRange = diffMs < 30 * 24 * 60 * 60 * 1000;
        }
        
        return matchesSearch && matchesAction && matchesUser && matchesRange;
    });
    
    allLogs = filtered;
    currentPage = 1;
    displayLogs(1);
}

// Pagination navigation
function nextPage() {
    const totalPages = Math.ceil(allLogs.length / logsPerPage);
    if (currentPage < totalPages) {
        displayLogs(currentPage + 1);
        window.scrollTo(0, 0);
    }
}

function previousPage() {
    if (currentPage > 1) {
        displayLogs(currentPage - 1);
        window.scrollTo(0, 0);
    }
}

// Show log details
async function showLogDetails(logJson) {
    const log = JSON.parse(decodeURIComponent(logJson));
    await showAlert(`
Timestamp: ${log.log_time}
User: ${log.user_name} (ID: ${log.user_id})
Action: ${log.action}
Entity: ${log.entity_type || 'N/A'} ID: ${log.entity_id || 'N/A'}
IP Address: ${log.ip_address}
User Agent: ${log.user_agent || 'N/A'}
Details: ${log.details || 'None'}
    `, 'Log Details');
}

// Export audit log
function exportAuditLog() {
    const csv = [
        ['Timestamp', 'User', 'Action', 'Details', 'IP Address'],
        ...allLogs.map(log => [
            log.log_time,
            log.user_name,
            log.action,
            log.details || '',
            log.ip_address
        ])
    ].map(row => row.map(cell => `"${cell}"`).join(',')).join('\n');
    
    const link = document.createElement('a');
    link.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent(csv);
    link.download = `audit_trail_${new Date().toISOString().split('T')[0]}.csv`;
    link.click();
}

// Show audit statistics
async function showAuditStats() {
    const stats = {
        totalLogs: allLogs.length,
        actionCounts: {},
        userCounts: {}
    };
    
    allLogs.forEach(log => {
        stats.actionCounts[log.action] = (stats.actionCounts[log.action] || 0) + 1;
        stats.userCounts[log.user_name] = (stats.userCounts[log.user_name] || 0) + 1;
    });
    
    const topActions = Object.entries(stats.actionCounts)
        .sort((a, b) => b[1] - a[1])
        .slice(0, 5);
    
    const topUsers = Object.entries(stats.userCounts)
        .sort((a, b) => b[1] - a[1])
        .slice(0, 5);
    
    let msg = 'AUDIT TRAIL STATISTICS\n\n';
    msg += `Total Log Entries: ${stats.totalLogs}\n\n`;
    msg += 'Top Actions:\n';
    topActions.forEach(([action, count]) => {
        msg += `  ${action}: ${count}\n`;
    });
    msg += '\nTop Users:\n';
    topUsers.forEach(([user, count]) => {
        msg += `  ${user}: ${count} actions\n`;
    });

    await showAlert(msg, 'Audit Statistics');
}

// Initialize
document.addEventListener('DOMContentLoaded', () => {
    loadAuditLogs();
    setInterval(loadAuditLogs, 60000); // Refresh every minute
});
</script>

<?php include 'includes/footer.php'; ?>
