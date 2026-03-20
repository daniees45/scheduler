<?php
$page_title = 'Conflict Resolution';
include 'includes/header.php';
require_once 'api/db.php';

// Require admin access
requireRole(['super_admin', 'faculty_admin']);
?>

<div class="glass-panel" style="padding: 2rem; max-width: 1400px; margin: 0 auto;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
        <div>
            <h2><i class="fa-solid fa-triangle-exclamation" style="color: #f59e0b;"></i> Conflict Resolution Center</h2>
            <p style="color: var(--text-muted);">AI-assisted conflict detection and resolution</p>
        </div>
        <div>
            <button class="glass-btn" onclick="scanForConflicts()" id="scanBtn">
                <i class="fa-solid fa-magnifying-glass"></i> Scan for Conflicts
            </button>
            <button class="glass-btn secondary" onclick="autoResolveAll()" style="margin-left: 10px;">
                <i class="fa-solid fa-wand-magic-sparkles"></i> Auto-Resolve
            </button>
        </div>
    </div>

    <!-- Conflict Summary -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
        <div class="glass-panel" style="padding: 1.5rem; border-left: 4px solid #ef4444;">
            <p style="color: var(--text-muted); font-size: 0.8rem; margin: 0 0 0.5rem 0;">CRITICAL</p>
            <h3 style="margin: 0 0 0.5rem 0; font-size: 2rem; color: #ef4444;" id="criticalCount">0</h3>
            <p style="color: var(--text-muted); font-size: 0.8rem; margin: 0;">Immediate action required</p>
        </div>

        <div class="glass-panel" style="padding: 1.5rem; border-left: 4px solid #f59e0b;">
            <p style="color: var(--text-muted); font-size: 0.8rem; margin: 0 0 0.5rem 0;">HIGH</p>
            <h3 style="margin: 0 0 0.5rem 0; font-size: 2rem; color: #f59e0b;" id="highCount">0</h3>
            <p style="color: var(--text-muted); font-size: 0.8rem; margin: 0;">Should be resolved soon</p>
        </div>

        <div class="glass-panel" style="padding: 1.5rem; border-left: 4px solid #818cf8;">
            <p style="color: var(--text-muted); font-size: 0.8rem; margin: 0 0 0.5rem 0;">MEDIUM</p>
            <h3 style="margin: 0 0 0.5rem 0; font-size: 2rem; color: #818cf8;" id="mediumCount">0</h3>
            <p style="color: var(--text-muted); font-size: 0.8rem; margin: 0;">Consider resolving</p>
        </div>

        <div class="glass-panel" style="padding: 1.5rem; border-left: 4px solid #22c55e;">
            <p style="color: var(--text-muted); font-size: 0.8rem; margin: 0 0 0.5rem 0;">RESOLVED</p>
            <h3 style="margin: 0 0 0.5rem 0; font-size: 2rem; color: #22c55e;" id="resolvedCount">0</h3>
            <p style="color: var(--text-muted); font-size: 0.8rem; margin: 0;">Successfully resolved</p>
        </div>
    </div>

    <!-- Filter and Search -->
    <div style="margin-bottom: 2rem; display: flex; gap: 1rem; flex-wrap: wrap;">
        <input type="text" id="searchInput" placeholder="Search conflicts..." class="glass-input" 
               style="flex: 1; min-width: 250px; border: 1px solid rgba(255,255,255,0.2);" 
               onkeyup="filterConflicts()">
        
        <select id="priorityFilter" class="glass-input" onchange="filterConflicts()" 
                style="border: 1px solid rgba(255,255,255,0.2);">
            <option value="">All Priorities</option>
            <option value="critical">🔴 Critical</option>
            <option value="high">🟠 High</option>
            <option value="medium">🔵 Medium</option>
            <option value="resolved">✅ Resolved</option>
        </select>

        <select id="typeFilter" class="glass-input" onchange="filterConflicts()" 
                style="border: 1px solid rgba(255,255,255,0.2);">
            <option value="">All Types</option>
            <option value="lecturer_double_booking">Lecturer Double-Booking</option>
            <option value="room_conflict">Room Conflict</option>
            <option value="time_slot">Time Slot Violation</option>
            <option value="capacity_exceed">Capacity Exceeded</option>
        </select>
    </div>

    <!-- Conflicts List -->
    <div id="conflictsList" style="display: grid; grid-template-columns: 1fr; gap: 1rem;">
        <div style="text-align: center; padding: 3rem; color: var(--text-muted);">
            <i class="fa-solid fa-spinner fa-spin" style="font-size: 2rem; margin-bottom: 1rem;"></i>
            <p>Loading conflicts...</p>
        </div>
    </div>
</div>

<style>
.conflict-card {
    background: rgba(15, 23, 42, 0.55);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 0.75rem;
    padding: 1.5rem;
    margin-bottom: 1rem;
    transition: all 0.2s;
}

.conflict-card:hover {
    background: rgba(15, 23, 42, 0.75);
    border-color: rgba(255,255,255,0.15);
}

.conflict-card.critical {
    border-left: 4px solid #ef4444;
    background: rgba(239, 68, 68, 0.05);
}

.conflict-card.high {
    border-left: 4px solid #f59e0b;
    background: rgba(245, 158, 11, 0.05);
}

.conflict-card.medium {
    border-left: 4px solid #818cf8;
    background: rgba(129, 140, 248, 0.05);
}

.conflict-card.resolved {
    border-left: 4px solid #22c55e;
    background: rgba(34, 197, 94, 0.05);
    opacity: 0.7;
}

.conflict-header {
    display: flex;
    justify-content: space-between;
    align-items: start;
    margin-bottom: 1rem;
}

.conflict-title {
    font-weight: 600;
    font-size: 1.1rem;
    margin-bottom: 0.5rem;
}

.conflicts-details {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin: 1rem 0;
}

.detail-item {
    padding: 0.75rem;
    background: rgba(0,0,0,0.2);
    border-radius: 0.5rem;
    font-size: 0.9rem;
}

.detail-label {
    color: var(--text-muted);
    font-size: 0.8rem;
    text-transform: uppercase;
}

.recommendation-box {
    padding: 1rem;
    background: rgba(99, 102, 241, 0.1);
    border: 1px solid rgba(99, 102, 241, 0.3);
    border-radius: 0.5rem;
    margin: 1rem 0;
}

.recommendation-title {
    color: #818cf8;
    font-weight: 600;
    margin-bottom: 0.5rem;
}

.action-buttons {
    display: flex;
    gap: 0.75rem;
    margin-top: 1rem;
    flex-wrap: wrap;
}

.action-buttons button {
    padding: 0.5rem 1rem;
    font-size: 0.85rem;
    border-radius: 0.5rem;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-accept {
    background: rgba(34, 197, 94, 0.2);
    border: 1px solid rgba(34, 197, 94, 0.5);
    color: #22c55e;
}

.btn-accept:hover {
    background: rgba(34, 197, 94, 0.3);
}

.btn-reject {
    background: rgba(239, 68, 68, 0.2);
    border: 1px solid rgba(239, 68, 68, 0.5);
    color: #ef4444;
}

.btn-reject:hover {
    background: rgba(239, 68, 68, 0.3);
}

.btn-details {
    background: rgba(129, 140, 248, 0.2);
    border: 1px solid rgba(129, 140, 248, 0.5);
    color: #818cf8;
}

.btn-details:hover {
    background: rgba(129, 140, 248, 0.3);
}
</style>

<script>
let allConflicts = [];

// Load conflicts
async function loadConflicts() {
    try {
        const response = await fetch('api/get_conflicts.php', {
            credentials: 'include'
        });

        if (!response.ok) throw new Error('Failed to load conflicts');

        const data = await response.json();
        console.log('Conflicts data:', data);

        if (data.status === 'success') {
            allConflicts = data.conflicts || [];
            updateConflictCounts();
            displayConflicts(allConflicts);
        }
    } catch (error) {
        console.error('Error loading conflicts:', error);
        document.getElementById('conflictsList').innerHTML = 
            '<div style="text-align: center; color: #ef4444;"><i class="fa-solid fa-exclamation-circle"></i> Error loading conflicts</div>';
    }
}

// Update summary counts
function updateConflictCounts() {
    const counts = {
        critical: 0,
        high: 0,
        medium: 0,
        resolved: 0
    };

    allConflicts.forEach(conflict => {
        if (conflict.status === 'resolved') counts.resolved++;
        else if (conflict.priority === 'critical') counts.critical++;
        else if (conflict.priority === 'high') counts.high++;
        else if (conflict.priority === 'medium') counts.medium++;
    });

    document.getElementById('criticalCount').textContent = counts.critical;
    document.getElementById('highCount').textContent = counts.high;
    document.getElementById('mediumCount').textContent = counts.medium;
    document.getElementById('resolvedCount').textContent = counts.resolved;
}

// Display conflicts
function displayConflicts(conflicts) {
    let html = '';

    if (conflicts.length === 0) {
        html = '<div style="text-align: center; padding: 3rem; color: var(--text-muted);"><i class="fa-solid fa-check-circle" style="font-size: 3rem; color: #22c55e; margin-bottom: 1rem;"></i><p>No conflicts detected!</p></div>';
    } else {
        conflicts.forEach((conflict, index) => {
            const priorityColor = {
                critical: '#ef4444',
                high: '#f59e0b',
                medium: '#818cf8'
            }[conflict.priority] || '#6366f1';

            html += `
            <div class="conflict-card ${conflict.priority || 'medium'}" id="conflict-${index}">
                <div class="conflict-header">
                    <div style="flex: 1;">
                        <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem;">
                            <span style="display: inline-block; width: 12px; height: 12px; border-radius: 50%; background: ${priorityColor};"></span>
                            <span class="conflict-title">${conflict.type}</span>
                        </div>
                        <p style="margin: 0; color: var(--text-muted); font-size: 0.9rem;">${conflict.description}</p>
                    </div>
                    <span style="background: ${priorityColor}; color: white; padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase;">
                        ${conflict.priority}
                    </span>
                </div>

                <div class="conflicts-details">
                    <div class="detail-item">
                        <div class="detail-label">Involved Parties</div>
                        <div>${conflict.involved_parties || 'N/A'}</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Affected Resource</div>
                        <div>${conflict.resource || 'N/A'}</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Time Slot</div>
                        <div>${conflict.time_slot || 'N/A'}</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Detection Time</div>
                        <div>${new Date(conflict.detected_at).toLocaleString()}</div>
                    </div>
                </div>

                <div class="recommendation-box">
                    <div class="recommendation-title">🤖 AI Recommendation</div>
                    <p style="margin: 0; font-size: 0.95rem;">${conflict.ai_recommendation || 'Manual resolution required'}</p>
                </div>

                <div class="action-buttons">
                    ${conflict.status !== 'resolved' ? `
                        <button class="btn-accept" onclick="resolveConflict(${index}, 'accepted')">
                            <i class="fa-solid fa-check"></i> Accept Solution
                        </button>
                        <button class="btn-reject" onclick="resolveConflict(${index}, 'rejected')">
                            <i class="fa-solid fa-times"></i> Need Revision
                        </button>
                    ` : '<span style="color: #22c55e;"><i class="fa-solid fa-check-circle"></i> Resolved</span>'}
                    <button class="btn-details" onclick="showConflictDetails(${index})">
                        <i class="fa-solid fa-info-circle"></i> Details
                    </button>
                </div>
            </div>`;
        });
    }

    document.getElementById('conflictsList').innerHTML = html;
}

// Filter conflicts
function filterConflicts() {
    const searchTerm = document.getElementById('searchInput').value.toLowerCase();
    const priorityFilter = document.getElementById('priorityFilter').value;
    const typeFilter = document.getElementById('typeFilter').value;

    const filtered = allConflicts.filter(conflict => {
        const matchesSearch = !searchTerm || 
            conflict.type.toLowerCase().includes(searchTerm) ||
            conflict.description.toLowerCase().includes(searchTerm);
        
        const matchesPriority = !priorityFilter || conflict.priority === priorityFilter;
        const matchesType = !typeFilter || conflict.type.toLowerCase().includes(typeFilter.toLowerCase());

        return matchesSearch && matchesPriority && matchesType;
    });

    displayConflicts(filtered);
}

// Scan for conflicts
async function scanForConflicts() {
    const btn = document.getElementById('scanBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Scanning...';

    try {
        const response = await fetch('api/scan_conflicts.php', {
            method: 'POST',
            credentials: 'include'
        });

        const data = await response.json();
        
        if (data.status === 'success') {
            await showAlert(`Scan complete: Found ${data.conflicts_found} new conflicts`, 'Scan Complete');
            loadConflicts();
        } else {
            await showAlert('Scan failed: ' + (data.message || 'Unknown error'), 'Error');
        }
    } catch (error) {
        await showAlert('Error during scan: ' + error.message, 'Error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-magnifying-glass"></i> Scan for Conflicts';
    }
}

// Resolve conflict
async function resolveConflict(index, action) {
    if (index >= allConflicts.length) return;

    const conflict = allConflicts[index];
    
    try {
        const response = await fetch('api/resolve_conflict.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({
                conflict_id: conflict.id,
                action: action,
                status: action === 'accepted' ? 'resolved' : 'pending_revision'
            })
        });

        const data = await response.json();

        if (data.status === 'success') {
            await showAlert(`Conflict ${action === 'accepted' ? 'resolved' : 'marked for revision'}`, 'Success');
            loadConflicts();
        } else {
            await showAlert('Error: ' + (data.message || 'Failed to update conflict'), 'Error');
        }
    } catch (error) {
        await showAlert('Error: ' + error.message, 'Error');
    }
}

// Show conflict details
async function showConflictDetails(index) {
    if (index >= allConflicts.length) return;
    const conflict = allConflicts[index];
    
    const details = `
Conflict ID: ${conflict.id}
Type: ${conflict.type}
Priority: ${conflict.priority}
Description: ${conflict.description}
Involved Parties: ${conflict.involved_parties}
Resource: ${conflict.resource}
Time Slot: ${conflict.time_slot}
AI Recommendation: ${conflict.ai_recommendation}
Status: ${conflict.status}
Detected: ${new Date(conflict.detected_at).toLocaleString()}
    `;
    
    await showAlert(details, 'Conflict Details');
}

// Auto-resolve all medium priority conflicts
async function autoResolveAll() {
    if (!await showConfirm('Auto-resolve all medium-priority conflicts? (Critical and High require manual review)', 'Auto-Resolve')) return;

    const mediumConflicts = allConflicts.filter(c => c.priority === 'medium' && c.status !== 'resolved');
    
    for (const conflict of mediumConflicts) {
        try {
            const response = await fetch('api/resolve_conflict.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({
                    conflict_id: conflict.id,
                    action: 'accepted',
                    status: 'resolved'
                })
            });
        } catch (error) {
            console.error('Error resolving conflict:', error);
        }
    }

    await showAlert(`Auto-resolved ${mediumConflicts.length} conflicts`, 'Success');
    loadConflicts();
}

// Initialize on load
document.addEventListener('DOMContentLoaded', () => {
    loadConflicts();
    // Refresh every 60 seconds
    setInterval(loadConflicts, 60000);
});
</script>

<?php include 'includes/footer.php'; ?>
