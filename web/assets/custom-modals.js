/**
 * Custom Modal System - Replaces native alert, confirm, and prompt
 * Glass-morphism styled dialogs with Promise support
 */

// Create modal container on page load
document.addEventListener('DOMContentLoaded', function() {
    if (!document.getElementById('customModalContainer')) {
        const container = document.createElement('div');
        container.id = 'customModalContainer';
        document.body.appendChild(container);
    }
});

/**
 * Enhanced Custom Alert Dialog
 * @param {string} message - Message to display
 * @param {string} title - Optional title
 * @param {string} type - info, success, error, warning
 * @param {Array} buttons - Optional array of strings for button labels
 * @returns {Promise<boolean|string>}
 */
window.showAlert = function(message, title = 'Notice', type = 'info', buttons = null) {
    return new Promise((resolve) => {
        const container = document.getElementById('customModalContainer') || document.body;
        
        const icons = {
            'info': '<i class="fa-solid fa-circle-info" style="color: var(--primary-color);"></i>',
            'success': '<i class="fa-solid fa-check-circle" style="color: #10b981;"></i>',
            'error': '<i class="fa-solid fa-circle-exclamation" style="color: #ef4444;"></i>',
            'warning': '<i class="fa-solid fa-triangle-exclamation" style="color: #f59e0b;"></i>'
        };
        
        const modal = document.createElement('div');
        modal.className = 'custom-modal-overlay';
        
        let footerHtml = '';
        if (buttons && Array.isArray(buttons) && buttons.length > 0) {
            buttons.forEach((btnLabel, idx) => {
                const isPrimary = idx === 0;
                footerHtml += `
                    <button class="glass-btn ${isPrimary ? 'primary' : 'secondary'}" id="customAlertBtn_${idx}" style="min-width: 100px;">
                        ${btnLabel}
                    </button>
                `;
            });
        } else {
            footerHtml = `
                <button class="glass-btn" id="customAlertOk" style="min-width: 100px;">
                    <i class="fa-solid fa-check"></i> OK
                </button>
            `;
        }

        modal.innerHTML = `
            <div class="custom-modal glass-panel" style="max-width: 450px; width: 90%;">
                <div class="custom-modal-header">
                    <h3 style="margin: 0; display: flex; align-items: center; gap: 12px;">
                        ${icons[type] || icons.info} ${escapeHtml(title)}
                    </h3>
                </div>
                <div class="custom-modal-body">
                    <p style="margin: 0; white-space: pre-wrap; line-height: 1.6;">${message.includes('<') ? message : escapeHtml(message)}</p>
                </div>
                <div class="custom-modal-footer">
                    ${footerHtml}
                </div>
            </div>
        `;
        
        container.appendChild(modal);
        
        const closeModal = (result) => {
            modal.style.opacity = '0';
            modal.querySelector('.custom-modal').style.transform = 'scale(0.95)';
            setTimeout(() => {
                modal.remove();
                resolve(result);
            }, 200);
        };
        
        if (buttons && Array.isArray(buttons)) {
            buttons.forEach((_, idx) => {
                const btn = modal.querySelector(`#customAlertBtn_${idx}`);
                btn.addEventListener('click', () => closeModal(idx === 0)); // Match standard Boolean return or first index
            });
        } else {
            const okBtn = modal.querySelector('#customAlertOk');
            okBtn.addEventListener('click', () => closeModal(true));
            setTimeout(() => okBtn.focus(), 100);
        }

        modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(false); });
        
        document.addEventListener('keydown', function h(e) {
            if (e.key === 'Escape') {
                document.removeEventListener('keydown', h);
                closeModal(false);
            }
        });
    });
};

/**
 * Custom Confirm Dialog
 * @returns {Promise<boolean>}
 */
window.showConfirm = function(message, title = 'Confirm') {
    return new Promise((resolve) => {
        const container = document.getElementById('customModalContainer') || document.body;
        
        const modal = document.createElement('div');
        modal.className = 'custom-modal-overlay';
        modal.innerHTML = `
            <div class="custom-modal glass-panel" style="max-width: 450px; width: 90%;">
                <div class="custom-modal-header">
                    <h3 style="margin: 0; display: flex; align-items: center; gap: 12px;">
                        <i class="fa-solid fa-circle-question" style="color: #f59e0b;"></i> ${escapeHtml(title)}
                    </h3>
                </div>
                <div class="custom-modal-body">
                    <p style="margin: 0; white-space: pre-wrap; line-height: 1.6;">${escapeHtml(message)}</p>
                </div>
                <div class="custom-modal-footer">
                    <button class="glass-btn glass-btn-secondary" id="customConfirmCancel">
                        <i class="fa-solid fa-times"></i> Cancel
                    </button>
                    <button class="glass-btn" id="customConfirmOk">
                        <i class="fa-solid fa-check"></i> Confirm
                    </button>
                </div>
            </div>
        `;
        
        container.appendChild(modal);
        const okBtn = modal.querySelector('#customConfirmOk');
        const cancelBtn = modal.querySelector('#customConfirmCancel');
        
        const closeModal = (result) => {
            modal.style.opacity = '0';
            modal.querySelector('.custom-modal').style.transform = 'scale(0.95)';
            setTimeout(() => {
                modal.remove();
                resolve(result);
            }, 200);
        };
        
        okBtn.addEventListener('click', () => closeModal(true));
        cancelBtn.addEventListener('click', () => closeModal(false));
        modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(false); });
        
        document.addEventListener('keydown', function h(e) {
            if (e.key === 'Escape') {
                document.removeEventListener('keydown', h);
                closeModal(false);
            }
        });
        
        setTimeout(() => okBtn.focus(), 100);
    });
};

/**
 * Custom Prompt Dialog
 * @returns {Promise<string|null>}
 */
window.showPrompt = function(message, defaultValue = '', title = 'Input Required') {
    return new Promise((resolve) => {
        const container = document.getElementById('customModalContainer') || document.body;
        
        const modal = document.createElement('div');
        modal.className = 'custom-modal-overlay';
        modal.innerHTML = `
            <div class="custom-modal glass-panel" style="max-width: 450px; width: 90%;">
                <div class="custom-modal-header">
                    <h3 style="margin: 0; display: flex; align-items: center; gap: 12px;">
                        <i class="fa-solid fa-keyboard" style="color: var(--primary-color);"></i> ${escapeHtml(title)}
                    </h3>
                </div>
                <div class="custom-modal-body">
                    <p style="margin: 0 0 1rem 0;">${escapeHtml(message)}</p>
                    <input type="text" id="customPromptInput" class="glass-input" 
                           value="${escapeHtml(defaultValue)}" 
                           style="width: 100%; padding: 0.8rem; font-size: 1rem; background: rgba(0,0,0,0.2);">
                </div>
                <div class="custom-modal-footer">
                    <button class="glass-btn glass-btn-secondary" id="customPromptCancel">
                        <i class="fa-solid fa-times"></i> Cancel
                    </button>
                    <button class="glass-btn" id="customPromptOk">
                        <i class="fa-solid fa-check"></i> Submit
                    </button>
                </div>
            </div>
        `;
        
        container.appendChild(modal);
        const input = modal.querySelector('#customPromptInput');
        const okBtn = modal.querySelector('#customPromptOk');
        const cancelBtn = modal.querySelector('#customPromptCancel');
        
        const closeModal = (result) => {
            modal.style.opacity = '0';
            modal.querySelector('.custom-modal').style.transform = 'scale(0.95)';
            setTimeout(() => {
                modal.remove();
                resolve(result);
            }, 200);
        };
        
        okBtn.addEventListener('click', () => closeModal(input.value));
        cancelBtn.addEventListener('click', () => closeModal(null));
        input.addEventListener('keypress', (e) => { if (e.key === 'Enter') closeModal(input.value); });
        modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(null); });
        
        setTimeout(() => {
            input.focus();
            input.select();
        }, 100);
    });
};

/**
 * showGlobalModal - A more generic modal that takes a list of buttons
 * @param {string} title - Title of the modal
 * @param {string} content - HTML content
 * @param {string} type - info, prompt, confirm
 * @param {Array} buttons - Array of {text, class, click} objects
 */
window.showGlobalModal = function(title, content, type = 'info', buttons = []) {
    const container = document.getElementById('customModalContainer') || document.body;
    
    const icons = {
        'info': '<i class="fa-solid fa-circle-info" style="color: var(--primary-color);"></i>',
        'prompt': '<i class="fa-solid fa-keyboard" style="color: var(--primary-color);"></i>',
        'confirm': '<i class="fa-solid fa-circle-question" style="color: #f59e0b);"></i>'
    };
    
    const modal = document.createElement('div');
    modal.className = 'custom-modal-overlay';
    
    let buttonsHtml = '';
    if (buttons.length === 0) {
        buttonsHtml = `<button class="glass-btn" id="modalClose">OK</button>`;
    } else {
        buttons.forEach((btn, idx) => {
            buttonsHtml += `<button class="${btn.class || 'glass-btn'}" id="modalBtn_${idx}">${btn.text}</button>`;
        });
    }

    modal.innerHTML = `
        <div class="custom-modal glass-panel" style="max-width: 500px; width: 90%;">
            <div class="custom-modal-header">
                <h3 style="margin: 0; display: flex; align-items: center; gap: 12px;">
                    ${icons[type] || icons.info} ${escapeHtml(title)}
                </h3>
            </div>
            <div class="custom-modal-body" style="max-height: 70vh; overflow-y: auto;">
                ${content}
            </div>
            <div class="custom-modal-footer">
                ${buttonsHtml}
            </div>
        </div>
    `;
    
    container.appendChild(modal);
    
    const closeModal = () => {
        modal.style.opacity = '0';
        modal.querySelector('.custom-modal').style.transform = 'scale(0.95)';
        setTimeout(() => modal.remove(), 200);
    };

    if (buttons.length === 0) {
        document.getElementById('modalClose').addEventListener('click', closeModal);
    } else {
        buttons.forEach((btn, idx) => {
            const btnEl = document.getElementById(`modalBtn_${idx}`);
            btnEl.addEventListener('click', () => {
                if (btn.click) {
                    btn.click();
                }
                closeModal();
            });
        });
    }

    modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });
};

/**
 * Escape HTML helper
 */
function escapeHtml(text) {
    if (typeof text !== 'string') return text;
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Global Aliases (Backward Compatibility)
window.customAlert = window.showAlert;
window.customConfirm = window.showConfirm;
window.customPrompt = window.showPrompt;

// Native Overrides
// Note: Code using alert/confirm as blocking (sync) will show the modal 
// but continue execution immediately. Use 'await alert()' for serial flow.
window.alert = function(msg, title) { return window.showAlert(msg, title || 'Alert'); };
window.confirm = function(msg, title) { return window.showConfirm(msg, title || 'Confirm'); };
window.prompt = function(msg, def, title) { return window.showPrompt(msg, def, title || 'Input'); };
