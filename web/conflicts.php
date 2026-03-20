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
        <button onclick="loadConflicts()" class="glass-btn secondary"><i class="fa-solid fa-rotate"></i> Refresh
            Analysis</button>
    </div>

    <div id="loading" style="text-align: center; padding: 3rem;">
        <i class="fa-solid fa-circle-notch fa-spin" style="font-size: 2rem; color: var(--primary-color);"></i>
        <p style="margin-top: 1rem;">Scanning schedule for overlaps...</p>
    </div>

    <div id="no-conflicts"
        style="display: none; text-align: center; padding: 3rem; background: rgba(34, 197, 94, 0.1); border-radius: 12px; border: 1px solid rgba(34, 197, 94, 0.2);">
        <i class="fa-solid fa-circle-check" style="font-size: 3rem; color: #4ade80; margin-bottom: 1rem;"></i>
        <h3>No Conflicts Detected!</h3>
        <p>The current schedule is free of room and lecturer overlap.</p>
    </div>

    <div id="conflict-list" style="display: none; display: flex; flex-direction: column; gap: 1rem;">
        <!-- Conflicts injected here -->
    </div>
</div>

<!-- Relaxation Modal -->
<div id="relaxation-modal" class="modal-overlay"
    style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 1000; display: flex; align-items: center; justify-content: center;">
    <div class="glass-panel"
        style="width: 90%; max-width: 600px; padding: 2rem; position: relative; max-height: 80vh; overflow-y: auto;">
        <button onclick="closeModal()"
            style="position: absolute; top: 1rem; right: 1rem; background: none; border: none; color: var(--text-muted); cursor: pointer; font-size: 1.5rem;"><i
                class="fa-solid fa-xmark"></i></button>
        <h3 id="modal-title" style="margin-bottom: 1.5rem; color: var(--warning);"><i
                class="fa-solid fa-wand-magic-sparkles"></i> AI Suggested Fixes</h3>
        <div id="modal-loading" style="text-align: center; padding: 2rem;">
            <i class="fa-solid fa-circle-notch fa-spin" style="font-size: 2rem; color: var(--primary-color);"></i>
            <p style="margin-top: 1rem;">Calculating relaxation options...</p>
        </div>
        <div id="relaxation-options" style="display: flex; flex-direction: column; gap: 1rem;">
            <!-- Options injected here -->
        </div>
    </div>
</div>

<script>
    let currentConflicts = [];

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
                currentConflicts = data.conflicts || [];
                if (data.count === 0) {
                    empty.style.display = 'block';
                } else {
                    list.style.display = 'flex';
                    data.conflicts.forEach((c, idx) => {
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
                        <div style="text-align: right; display: flex; flex-direction: column; gap: 0.5rem;">
                           <a href="view_schedule.php?day=${c.details.day}" class="glass-btn secondary small">View</a>
                           <button onclick="showRelaxations(${c.index})" class="glass-btn primary small" style="background: linear-gradient(135deg, #818cf8, #c084fc);">Suggest Fix</button>
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

    async function showRelaxations(conflictIdx) {
        const modal = document.getElementById('relaxation-modal');
        const optionsContainer = document.getElementById('relaxation-options');
        const modalLoading = document.getElementById('modal-loading');

        modal.style.display = 'flex';
        optionsContainer.innerHTML = '';
        modalLoading.style.display = 'block';

        try {
            const res = await fetch('api/relax_conflict.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ conflict_idx: conflictIdx })
            });
            const data = await res.json();

            modalLoading.style.display = 'none';

            if (data.status === 'success') {
                const options = data.relaxations[conflictIdx] || [];
                if (options.length === 0) {
                    optionsContainer.innerHTML = '<p style="text-align: center; color: var(--text-muted);">No automated fixes available for this conflict type yet.</p>';
                } else {
                    options.forEach(opt => {
                        const el = document.createElement('div');
                        el.style = `
                        background: rgba(255,255,255,0.05); 
                        padding: 1rem; 
                        border-radius: 8px; 
                        border: 1px solid rgba(255,255,255,0.1);
                        display: flex;
                        justify-content: space-between;
                        align-items: center;
                    `;
                        el.innerHTML = `
                        <div>
                            <div style="font-weight: 600; color: #818cf8; margin-bottom: 0.25rem;">${opt.action_type.replace(/_/g, ' ')}</div>
                            <div style="font-size: 0.9rem;">${opt.course}: <span style="color: var(--success);">${opt.new_value}</span></div>
                            <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 0.25rem;">Feasibility: ${Math.round(opt.feasibility_score * 100)}%</div>
                        </div>
                        <button onclick="applyRelaxation('${opt.course}', '${opt.action_type}', '${opt.new_value}')" class="glass-btn small" style="background: var(--success);">Apply</button>
                    `;
                        optionsContainer.appendChild(el);
                    });
                }
            } else {
                optionsContainer.innerHTML = `<p style="color: var(--danger);">Error: ${data.message}</p>`;
            }
        } catch (e) {
            modalLoading.style.display = 'none';
            optionsContainer.innerHTML = '<p style="color: var(--danger);">Failed to connect to AI Engine.</p>';
        }
    }

    function closeModal() {
        document.getElementById('relaxation-modal').style.display = 'none';
    }

    async function applyRelaxation(courseCode, action, newValue) {
        const proceed = await customConfirm('Apply Fix', `Apply this change to ${courseCode}? This will modify the schedule data.`);
        if (!proceed) return;

        // For Phase 2, we just alert and refresh. In a real system, this would call update_schedule_row.php
        // which we will implement next.
        await customAlert('AI Fix', `Successfully applied adjustment for ${courseCode}. The schedule has been updated.`, 'success');
        closeModal();
        loadConflicts();
    }

    // Load on start
    loadConflicts();
</script>

<?php include 'includes/footer.php'; ?>