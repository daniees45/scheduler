<?php
$page_title = 'Conflict Dashboard';
include 'includes/header.php';
?>

<div class="glass-panel" style="padding: 2rem; max-width: 900px; margin: 0 auto;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
        <div>
            <h2><i class="fa-solid fa-triangle-exclamation" style="color: var(--warning);"></i> Schedule Conflicts</h2>
            <p style="color: var(--text-muted);">Real-time analysis of the current schedule.</p>
        </div>
        <button onclick="loadConflicts()" class="glass-btn secondary"><i class="fa-solid fa-rotate"></i> Refresh Analysis</button>
    </div>

    <div id="loading" style="text-align: center; padding: 3rem;">
        <i class="fa-solid fa-circle-notch fa-spin" style="font-size: 2rem; color: var(--primary-color);"></i>
        <p style="margin-top: 1rem;">Scanning schedule for overlaps...</p>
    </div>

    <div id="no-conflicts" style="display: none; text-align: center; padding: 3rem; background: rgba(34, 197, 94, 0.1); border-radius: 12px; border: 1px solid rgba(34, 197, 94, 0.2);">
        <i class="fa-solid fa-circle-check" style="font-size: 3rem; color: #4ade80; margin-bottom: 1rem;"></i>
        <h3>No Conflicts Detected!</h3>
        <p>The current schedule is free of room and lecturer overlap.</p>
    </div>

    <div id="conflict-list" style="display: none; display: flex; flex-direction: column; gap: 1rem;">
        <!-- Conflicts injected here -->
    </div>
</div>

<script>
async function loadConflicts() {
    const list = document.getElementById('conflict-list');
    const loading = document.getElementById('loading');
    const empty = document.getElementById('no-conflicts');
    
    loading.style.display = 'block';
    list.style.display = 'none';
    empty.style.display = 'none';
    list.innerHTML = '';

    try {
        const res = await fetch('api/check_conflicts.php');
        const data = await res.json();
        
        loading.style.display = 'none';
        
        if (data.status === 'success') {
            if (data.count === 0) {
                empty.style.display = 'block';
            } else {
                list.style.display = 'flex';
                data.conflicts.forEach(c => {
                    const el = document.createElement('div');
                    el.className = 'conflict-card';
                    el.style = `
                        background: rgba(255,255,255,0.03); 
                        padding: 1.5rem; 
                        border-radius: 8px; 
                        border-left: 4px solid var(--danger);
                        display: flex;
                        justify-content: space-between;
                        align-items: center;
                    `;
                    el.innerHTML = `
                        <div>
                            <div style="font-weight: 600; color: var(--danger); margin-bottom: 0.25rem;">
                                <i class="fa-solid fa-bolt"></i> ${c.type}
                            </div>
                            <div style="font-size: 0.95rem; margin-bottom: 0.5rem;">${c.description}</div>
                            <div style="font-size: 0.85rem; color: var(--text-muted);">
                                Affecting: <span style="color: white;">${c.entities.join(', ')}</span>
                            </div>
                        </div>
                        <div style="text-align: right;">
                           <a href="view_schedule.php?day=${c.details.day}" class="glass-btn secondary small">View in Schedule</a>
                        </div>
                    `;
                    list.appendChild(el);
                });
            }
        } else {
            await customAlert('Analysis Error', data.message, 'error');
        }
    } catch (e) {
        await customAlert('Network Error', 'Failed to load conflicts from server.', 'error');
        loading.style.display = 'none';
    }
}

// Load on start
loadConflicts();
</script>

<?php include 'includes/footer.php'; ?>
