<?php
// web/ai_models.php
$page_css = 'assets/ai_models.css';
require_once 'includes/header.php';
requireRole(['super_admin']);
?>

<div class="main-content">
    <div class="header-bar">
        <div class="page-title">
            <h1>AI Model Management</h1>
            <p class="text-muted">Monitor and retrain the deep learning models powering the scheduler.</p>
        </div>
    </div>

    <div class="stats-grid">
        <div class="glass-panel stat-card animate-fade-in">
            <span class="stat-label">Classifier Confidence</span>
            <div class="stat-value" id="classifierAccuracy">--%</div>
            <p class="text-muted ai-models-stat-note">Neural Network accuracy on quality prediction</p>
        </div>
        <div class="glass-panel stat-card animate-fade-in delay-1">
            <span class="stat-label">Feedback Samples</span>
            <div class="stat-value" id="feedbackCount">0</div>
            <p class="text-muted ai-models-stat-note">User feedback entries collected for learning</p>
        </div>
        <div class="glass-panel stat-card animate-fade-in delay-2">
            <span class="stat-label">System Health</span>
            <div class="stat-value ai-model-status-online" id="engineStatus">Online</div>
            <p class="text-muted ai-models-stat-note">AI Engine status on port 5000</p>
        </div>
    </div>

    <div class="grid-layout ai-models-grid-layout">
        <div class="glass-panel ai-models-panel">
            <h3 class="ai-models-section-title"><i class="fa-solid fa-brain"></i> Model Training</h3>
            <p class="ai-models-section-description">Retraining the model incorporates new user feedback and historical schedule
                data to improve future predictions and optimization heuristics.</p>

            <div class="ai-models-warning-box">
                <p class="ai-models-warning-text">
                    <i class="fa-solid fa-triangle-exclamation"></i> Training takes significant CPU resources and may
                    take several minutes. Scheduling will be paused during training.
                </p>
            </div>

            <button onclick="trainModel()" id="trainBtn" class="glass-btn ai-models-train-btn">
                <i class="fa-solid fa-dumbbell"></i> Start Model Retraining
            </button>
            <div id="trainingProgress" class="ai-models-training-progress">
                <div class="ai-models-training-progress-head">
                    <i class="fa-solid fa-circle-notch fa-spin"></i> <span id="trainingStatusText">Training in
                        progress...</span>
                </div>
                <div class="ai-models-training-progress-track">
                    <div id="trainingBar" class="ai-models-training-progress-bar"></div>
                </div>
            </div>
        </div>

        <div class="glass-panel ai-models-panel">
            <h3 class="ai-models-section-title"><i class="fa-solid fa-history"></i> Learning Logs</h3>
            <div id="feedbackLogs" class="ai-models-feedback-logs">
                <!-- Logs will be loaded here -->
                <div class="ai-models-feedback-placeholder">Loading feedback logs...</div>
            </div>
        </div>
    </div>
</div>

<script src="config.js"></script>
<script>
    async function safeAlert(title, message, type = 'info') {
        const fn = window.customAlert || window.showAlert;
        if (typeof fn === 'function') {
            return fn(message, title, type);
        }
        alert(`${title}: ${message}`);
    }

    async function updateStatus() {
        try {
            const data = await apiGet('/ai/status');
            if (data) {
                const conf = data.feedback_state?.nn_confidence ?? data.classifier_accuracy;
                document.getElementById('classifierAccuracy').innerText = Number.isFinite(conf) ? (conf * 100).toFixed(1) + '%' : 'N/A';
                document.getElementById('feedbackCount').innerText = data.feedback_state?.total_feedback_entries || 0;

                const actions = data.feedback_state?.feedback_actions || {};
                const logContainer = document.getElementById('feedbackLogs');
                if (Object.keys(actions).length > 0) {
                    logContainer.innerHTML = Object.entries(actions).map(([action, count]) => `
                        <div class="ai-models-feedback-row">
                            <span class="ai-models-feedback-action">${action} actions</span>
                            <span class="ai-models-feedback-count">${count}</span>
                        </div>
                    `).join('');
                } else {
                    logContainer.innerHTML = '<div class="ai-models-feedback-placeholder">No feedback recorded yet.</div>';
                }
            }
        } catch (e) {
            const engineStatus = document.getElementById('engineStatus');
            engineStatus.innerText = 'Offline';
            engineStatus.classList.remove('ai-model-status-online');
            engineStatus.classList.add('ai-model-status-offline');
        }
    }

    async function trainModel() {
        if (!await showConfirm('Are you sure you want to start retraining? This effectively restarts the AI intelligence from current data.', 'Retrain Model')) return;

        const btn = document.getElementById('trainBtn');
        const progress = document.getElementById('trainingProgress');
        const bar = document.getElementById('trainingBar');

        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Please Wait...';
        progress.style.display = 'block';

        // Simulating progressive UI for a long-running task
        let p = 0;
        const interval = setInterval(() => {
            p += Math.random() * 5;
            if (p > 95) p = 95;
            bar.style.width = p + '%';
        }, 1000);

        try {
            const res = await apiPost('/ai/train', {});
            clearInterval(interval);
            bar.style.width = '100%';

            if (res.status === 'success') {
                await safeAlert('Success', 'AI Model has been retrained successfully!', 'success');
                updateStatus();
            } else {
                await safeAlert('Error', 'Retraining failed: ' + res.message, 'error');
            }
        } catch (e) {
            clearInterval(interval);
            await safeAlert('Error', 'Failed to communicate with AI engine', 'error');
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-dumbbell"></i> Start Model Retraining';
            setTimeout(() => { progress.style.display = 'none'; bar.style.width = '0%'; }, 3000);
        }
    }

    document.addEventListener('DOMContentLoaded', updateStatus);
</script>

<?php include_once 'includes/footer.php'; ?>