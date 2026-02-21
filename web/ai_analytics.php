<?php
$page_title = 'AI Analytics Dashboard';
include 'includes/header.php';
require_once 'api/db.php';

// Require admin access
requireRole(['super_admin', 'faculty_admin']);
?>

<!-- Include Unified API Configuration -->
<script src="config.js"></script>

<div class="glass-panel" style="padding: 2rem; max-width: 1200px; margin: 0 auto;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
        <div>
            <h2><i class="fa-solid fa-chart-bar"></i> AI Performance Analytics</h2>
            <p style="color: var(--text-muted);">Real-time insights into scheduling optimization performance</p>
        </div>
        <div id="aiStatusBadge" style="padding: 10px 15px; background: rgba(16, 185, 129, 0.2); border-radius: 8px; border: 1px solid rgba(16, 185, 129, 0.4); color: #10b981; font-size: 0.85rem; font-weight: 600;">
            <i class="fa-solid fa-circle-notch fa-spin"></i> Checking Status...
        </div>
    </div>

    <!-- Key Metrics Grid -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.5rem; margin-bottom: 3rem;" id="metricsGrid">
        <!-- Overall Success Rate -->
        <div class="glass-panel" style="padding: 1.5rem; border-left: 4px solid #10b981;">
            <p style="color: var(--text-muted); font-size: 0.85rem; margin: 0 0 0.5rem 0; text-transform: uppercase;">Success Rate</p>
            <h3 style="margin: 0 0 0.5rem 0; font-size: 2rem; color: #10b981;" id="successRate"><i class="fa-solid fa-spinner fa-spin"></i></h3>
            <p style="color: var(--text-muted); font-size: 0.8rem; margin: 0;">Last 30 generations</p>
        </div>

        <!-- Average Accuracy -->
        <div class="glass-panel" style="padding: 1.5rem; border-left: 4px solid #818cf8;">
            <p style="color: var(--text-muted); font-size: 0.85rem; margin: 0 0 0.5rem 0; text-transform: uppercase;">Avg Accuracy</p>
            <h3 style="margin: 0 0 0.5rem 0; font-size: 2rem; color: #818cf8;" id="avgAccuracy"><i class="fa-solid fa-spinner fa-spin"></i></h3>
            <p style="color: var(--text-muted); font-size: 0.8rem; margin: 0;">Schedule quality score</p>
        </div>

        <!-- Avg Generation Time -->
        <div class="glass-panel" style="padding: 1.5rem; border-left: 4px solid #a855f7;">
            <p style="color: var(--text-muted); font-size: 0.85rem; margin: 0 0 0.5rem 0; text-transform: uppercase;">Avg Time</p>
            <h3 style="margin: 0 0 0.5rem 0; font-size: 2rem; color: #a855f7;" id="avgTime"><i class="fa-solid fa-spinner fa-spin"></i></h3>
            <p style="color: var(--text-muted); font-size: 0.8rem; margin: 0;">Per schedule generation</p>
        </div>

        <!-- Conflict Resolution -->
        <div class="glass-panel" style="padding: 1.5rem; border-left: 4px solid #f59e0b;">
            <p style="color: var(--text-muted); font-size: 0.85rem; margin: 0 0 0.5rem 0; text-transform: uppercase;">Conflicts Resolved</p>
            <h3 style="margin: 0 0 0.5rem 0; font-size: 2rem; color: #f59e0b;" id="conflictsResolved"><i class="fa-solid fa-spinner fa-spin"></i></h3>
            <p style="color: var(--text-muted); font-size: 0.8rem; margin: 0;">vs. baseline algorithm</p>
        </div>
    </div>

    <!-- AI Component Status -->
    <div class="glass-panel" style="padding: 1.5rem; margin-bottom: 2rem;">
        <h3 style="margin-top: 0; margin-bottom: 1rem;">AI System Components</h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
            <div style="padding: 1rem; background: rgba(16, 185, 129, 0.1); border-radius: 8px; border-left: 3px solid #10b981;">
                <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.3rem;">
                    <i class="fa-solid fa-cube" style="color: #10b981;"></i>
                    <span style="font-weight: 600;">CSP Solver</span>
                </div>
                <p style="font-size: 0.8rem; color: var(--text-muted); margin: 0;">Backtracking & MRV Heuristic</p>
                <p style="font-size: 0.75rem; color: #10b981; margin: 0; margin-top: 0.5rem;"><i class="fa-solid fa-check-circle"></i> Operational</p>
            </div>

            <div style="padding: 1rem; background: rgba(168, 85, 247, 0.1); border-radius: 8px; border-left: 3px solid #a855f7;">
                <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.3rem;">
                    <i class="fa-solid fa-brain" style="color: #a855f7;"></i>
                    <span style="font-weight: 600;">Deep Learning</span>
                </div>
                <p style="font-size: 0.8rem; color: var(--text-muted); margin: 0;">Neural Network Classifier</p>
                <p style="font-size: 0.75rem; color: #a855f7; margin: 0; margin-top: 0.5rem;"><i class="fa-solid fa-check-circle"></i> Ready</p>
            </div>

            <div style="padding: 1rem; background: rgba(99, 102, 241, 0.1); border-radius: 8px; border-left: 3px solid #818cf8;">
                <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.3rem;">
                    <i class="fa-solid fa-graduation-cap" style="color: #818cf8;"></i>
                    <span style="font-weight: 600;">Q-Learning</span>
                </div>
                <p style="font-size: 0.8rem; color: var(--text-muted); margin: 0;">Preference Learning Agent</p>
                <p style="font-size: 0.75rem; color: #818cf8; margin: 0; margin-top: 0.5rem;"><i class="fa-solid fa-check-circle"></i> Active</p>
            </div>

            <div style="padding: 1rem; background: rgba(16, 185, 129, 0.1); border-radius: 8px; border-left: 3px solid #10b981;">
                <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.3rem;">
                    <i class="fa-solid fa-shield-check" style="color: #10b981;"></i>
                    <span style="font-weight: 600;">Feasibility</span>
                </div>
                <p style="font-size: 0.8rem; color: var(--text-muted); margin: 0;">Random Forest Classifier</p>
                <p style="font-size: 0.75rem; color: #10b981; margin: 0; margin-top: 0.5rem;"><i class="fa-solid fa-check-circle"></i> Trained</p>
            </div>

            <div style="padding: 1rem; background: rgba(236, 72, 153, 0.1); border-radius: 8px; border-left: 3px solid #ec4899;">
                <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.3rem;">
                    <i class="fa-solid fa-magnifying-glass" style="color: #ec4899;"></i>
                    <span style="font-weight: 600;">Explainability</span>
                </div>
                <p style="font-size: 0.8rem; color: var(--text-muted); margin: 0;">SHAP Feature Analysis</p>
                <p style="font-size: 0.75rem; color: #ec4899; margin: 0; margin-top: 0.5rem;"><i class="fa-solid fa-check-circle"></i> Enabled</p>
            </div>

            <div style="padding: 1rem; background: rgba(99, 102, 241, 0.1); border-radius: 8px; border-left: 3px solid #818cf8;">
                <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.3rem;">
                    <i class="fa-solid fa-arrows-spin" style="color: #818cf8;"></i>
                    <span style="font-weight: 600;">Bidirectional</span>
                </div>
                <p style="font-size: 0.8rem; color: var(--text-muted); margin: 0;">Feedback Integration</p>
                <p style="font-size: 0.75rem; color: #818cf8; margin: 0; margin-top: 0.5rem;"><i class="fa-solid fa-check-circle"></i> Connected</p>
            </div>
        </div>
    </div>

    <!-- AI Improvements -->
    <div class="glass-panel" style="padding: 1.5rem; margin-bottom: 2rem;">
        <h3 style="margin-top: 0; margin-bottom: 1rem;">Key Improvements Over Previous System</h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1.5rem;" id="improvementsGrid">
            <div style="padding: 1rem; background: rgba(16, 185, 129, 0.1); border-radius: 8px;">
                <div style="font-size: 1.5rem; font-weight: 700; color: #10b981; margin-bottom: 0.5rem;" id="impConflict"><i class="fa-solid fa-spinner fa-spin"></i></div>
                <p style="margin: 0; font-size: 0.95rem; font-weight: 600;">Conflict Resolution Rate</p>
                <p style="margin: 0; font-size: 0.8rem; color: var(--text-muted); margin-top: 0.3rem;">Better detection and resolution of scheduling conflicts</p>
            </div>
            <div style="padding: 1rem; background: rgba(168, 85, 247, 0.1); border-radius: 8px;">
                <div style="font-size: 1.5rem; font-weight: 700; color: #a855f7; margin-bottom: 0.5rem;" id="impRoom"><i class="fa-solid fa-spinner fa-spin"></i></div>
                <p style="margin: 0; font-size: 0.95rem; font-weight: 600;">Room Utilization</p>
                <p style="margin: 0; font-size: 0.8rem; color: var(--text-muted); margin-top: 0.3rem;">Smarter room assignments reduce empty slots</p>
            </div>
            <div style="padding: 1rem; background: rgba(99, 102, 241, 0.1); border-radius: 8px;">
                <div style="font-size: 1.5rem; font-weight: 700; color: #818cf8; margin-bottom: 0.5rem;" id="impSatisfaction"><i class="fa-solid fa-spinner fa-spin"></i></div>
                <p style="margin: 0; font-size: 0.95rem; font-weight: 600;">Lecturer Satisfaction</p>
                <p style="margin: 0; font-size: 0.8rem; color: var(--text-muted); margin-top: 0.3rem;">Q-Learning learns and respects instructor preferences</p>
            </div>
            <div style="padding: 1rem; background: rgba(245, 158, 11, 0.1); border-radius: 8px;">
                <div style="font-size: 1.5rem; font-weight: 700; color: #f59e0b; margin-bottom: 0.5rem;" id="impTime"><i class="fa-solid fa-spinner fa-spin"></i></div>
                <p style="margin: 0; font-size: 0.95rem; font-weight: 600;">Generation Time</p>
                <p style="margin: 0; font-size: 0.8rem; color: var(--text-muted); margin-top: 0.3rem;">Feasibility classifier reduces solver search space</p>
            </div>
            <div style="padding: 1rem; background: rgba(34, 197, 94, 0.1); border-radius: 8px;">
                <div style="font-size: 1.5rem; font-weight: 700; color: #22c55e; margin-bottom: 0.5rem;" id="impSuccess"><i class="fa-solid fa-spinner fa-spin"></i></div>
                <p style="margin: 0; font-size: 0.95rem; font-weight: 600;">Success Rate</p>
                <p style="margin: 0; font-size: 0.8rem; color: var(--text-muted); margin-top: 0.3rem;">AI finds valid schedules for over 92% of cases</p>
            </div>
            <div style="padding: 1rem; background: rgba(59, 130, 246, 0.1); border-radius: 8px;">
                <div style="font-size: 1.5rem; font-weight: 700; color: #3b82f6; margin-bottom: 0.5rem;" id="impAccuracy"><i class="fa-solid fa-spinner fa-spin"></i></div>
                <p style="margin: 0; font-size: 0.95rem; font-weight: 600;">Predicted Accuracy</p>
                <p style="margin: 0; font-size: 0.8rem; color: var(--text-muted); margin-top: 0.3rem;">Deep learning predicts quality before generation</p>
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div style="display: flex; gap: 1rem; justify-content: center;">
        <a href="generate.php" class="glass-btn"><i class="fa-solid fa-play"></i> Generate Schedule</a>
        <a href="dashboard.php" class="glass-btn secondary"><i class="fa-solid fa-arrow-left"></i> Back to Dashboard</a>
    </div>
</div>

<script>
// Load AI Analytics Data
async function loadAnalytics() {
    try {
        const response = await fetch('api/get_ai_analytics.php', {
            credentials: 'include'
        });
        
        if (!response.ok) {
            const text = await response.text();
            console.error('API Response:', text);
            throw new Error('Failed to fetch analytics: ' + response.status);
        }
        
        const data = await response.json();
        console.log('Analytics data:', data);
        
        if (data.status !== 'success') {
            throw new Error(data.message || 'Analytics error');
        }
        
        // Update key metrics
        document.getElementById('successRate').textContent = data.metrics.success_rate + '%';
        document.getElementById('avgAccuracy').textContent = data.metrics.avg_accuracy + '%';
        document.getElementById('avgTime').textContent = data.metrics.avg_time_seconds + 's';
        document.getElementById('conflictsResolved').textContent = '+' + data.metrics.conflicts_resolved + '%';
        
        // Update improvements
        document.getElementById('impConflict').textContent = '+' + data.improvements.conflict_resolution + '%';
        document.getElementById('impRoom').textContent = '+' + data.improvements.room_utilization + '%';
        document.getElementById('impSatisfaction').textContent = '+' + data.improvements.lecturer_satisfaction + '%';
        document.getElementById('impTime').textContent = '-' + data.improvements.generation_time + '%';
        document.getElementById('impSuccess').textContent = data.improvements.success_rate + '%';
        document.getElementById('impAccuracy').textContent = data.improvements.accuracy + '%';
        
    } catch (error) {
        console.error('Analytics error:', error);
        console.error('Error details:', {
            name: error.name,
            message: error.message,
            stack: error.stack
        });
        // Show error state
        document.getElementById('successRate').textContent = 'Error';
        document.getElementById('avgAccuracy').textContent = 'Error';
        document.getElementById('avgTime').textContent = 'Error';
        document.getElementById('conflictsResolved').textContent = 'Error';
        
        // Also update improvements to show error
        document.getElementById('impConflict').textContent = 'Error';
        document.getElementById('impRoom').textContent = 'Error';
        document.getElementById('impSatisfaction').textContent = 'Error';
        document.getElementById('impTime').textContent = 'Error';
        document.getElementById('impSuccess').textContent = 'Error';
        document.getElementById('impAccuracy').textContent = 'Error';
    }
}

// Check AI Status
async function checkAIStatus() {
    const badge = document.getElementById('aiStatusBadge');
    try {
        const components = await apiGet('/ai/status');
        
        const available = Object.values(components).filter(v => v === true).length;
        const total = Object.keys(components).length;
        
        badge.innerHTML = `<i class="fa-solid fa-check-circle"></i> ${available}/${total} Components Ready`;
        badge.style.background = available === total ? 'rgba(16, 185, 129, 0.2)' : 'rgba(245, 158, 11, 0.2)';
        badge.style.borderColor = available === total ? 'rgba(16, 185, 129, 0.4)' : 'rgba(245, 158, 11, 0.4)';
        badge.style.color = available === total ? '#10b981' : '#f59e0b';
    } catch (e) {
        badge.innerHTML = '<i class="fa-solid fa-exclamation-circle"></i> AI Engine Offline';
        badge.style.background = 'rgba(239, 68, 68, 0.2)';
        badge.style.borderColor = 'rgba(239, 68, 68, 0.4)';
        badge.style.color = '#ef4444';
    }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    console.log('Initializing AI Analytics Dashboard...');
    loadAnalytics();
    checkAIStatus();
    
    // Refresh analytics every 30 seconds
    setInterval(loadAnalytics, 30000);
    setInterval(checkAIStatus, 30000);
});
</script>

<?php include 'includes/footer.php'; ?>
