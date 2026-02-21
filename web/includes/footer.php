<?php if (basename($_SERVER['PHP_SELF']) != 'login.php'): ?>
    </div> <!-- End Main Content -->
</div> <!-- End App Container -->
<?php endif; ?>

<!-- Core Scripts -->
<script src="assets/script.js"></script>

<!-- Chart.js for Dashboard -->
<?php if (basename($_SERVER['PHP_SELF']) == 'dashboard.php'): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<?php endif; ?>

<!-- Global Custom Modal -->
<div id="globalModal" style="display: none; position: fixed; top:0; left:0; width:100%; height:100%; background: rgba(0,0,0,0.85); z-index: 9999; justify-content: center; align-items: center; backdrop-filter: blur(5px);">
    <div class="glass-panel" style="width: 100%; max-width: 400px; padding: 2rem; text-align: center; border: 1px solid rgba(255,255,255,0.1); transform: scale(0.95); transition: transform 0.2s;">
        <div id="modalIcon" style="font-size: 3rem; margin-bottom: 1rem;"></div>
        <h3 id="modalTitle" style="margin-bottom: 1rem;">Title</h3>
        <p id="modalMessage" style="color: var(--text-muted); margin-bottom: 2rem; line-height: 1.5;">Message</p>
        <div id="modalActions" style="display: flex; gap: 10px; justify-content: center;"></div>
    </div>
</div>

<script>
window.customAlert = (title, message, type='info') => {
    return new Promise((resolve) => {
        showGlobalModal(title, message, type, [{text: 'OK', class: 'glass-btn', click: resolve}]);
    });
};

window.customConfirm = (title, message) => {
    return new Promise((resolve) => {
        showGlobalModal(title, message, 'question', [
            {text: 'Cancel', class: 'glass-btn secondary', click: () => resolve(false)},
            {text: 'Confirm', class: 'glass-btn', click: () => resolve(true)}
        ]);
    });
};

window.customPrompt = (title, message) => {
    return new Promise((resolve) => {
        const input = `<input type="text" id="modalInput" class="glass-input" style="margin-bottom: 1rem; width: 100%;">`;
        showGlobalModal(title, message + input, 'prompt', [
            {text: 'Cancel', class: 'glass-btn secondary', click: () => resolve(null)},
            {text: 'Submit', class: 'glass-btn', click: () => resolve(document.getElementById('modalInput').value)}
        ]);
        setTimeout(() => document.getElementById('modalInput').focus(), 100);
    });
};

function showGlobalModal(title, message, type, actions) {
    const modal = document.getElementById('globalModal');
    const icons = {
        'info': '<i class="fa-solid fa-circle-info" style="color: var(--primary);"></i>',
        'success': '<i class="fa-solid fa-check-circle" style="color: var(--success);"></i>',
        'error': '<i class="fa-solid fa-circle-exclamation" style="color: var(--danger);"></i>',
        'question': '<i class="fa-solid fa-circle-question" style="color: var(--warning);"></i>',
        'prompt': '<i class="fa-solid fa-pen-to-square" style="color: var(--primary);"></i>',
        'loading': '<i class="fa-solid fa-spinner fa-spin" style="color: var(--primary);"></i>'
    };
    
    document.getElementById('modalIcon').innerHTML = icons[type] || icons['info'];
    document.getElementById('modalTitle').textContent = title;
    document.getElementById('modalMessage').innerHTML = message;
    
    const actionContainer = document.getElementById('modalActions');
    actionContainer.innerHTML = '';
    
    // Only add action buttons if actions are provided
    if (actions && actions.length > 0) {
        actions.forEach(action => {
            const btn = document.createElement('button');
            btn.innerHTML = action.text;
            btn.className = action.class;
            btn.onclick = () => {
                modal.style.display = 'none';
                if(action.click) action.click();
            };
            actionContainer.appendChild(btn);
        });
    }
    
    modal.style.display = 'flex';
}

function hideGlobalModal() {
    const modal = document.getElementById('globalModal');
    if (modal) {
        modal.style.display = 'none';
    }
}
</script>
</body>
</html>
