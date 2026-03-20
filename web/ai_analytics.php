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
        <div id="aiStatusBadge"
            style="padding: 10px 15px; background: rgba(16, 185, 129, 0.2); border-radius: 8px; border: 1px solid rgba(16, 185, 129, 0.4); color: #10b981; font-size: 0.85rem; font-weight: 600;">
            <i class="fa-solid fa-circle-notch fa-spin"></i> Checking Status...
        </div>
    </div>

    <!-- Key Metrics Grid -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.5rem; margin-bottom: 3rem;"
        id="metricsGrid">
        <!-- Overall Success Rate -->
        <div class="glass-panel" style="padding: 1.5rem; border-left: 4px solid #10b981;">
            <p style="color: var(--text-muted); font-size: 0.85rem; margin: 0 0 0.5rem 0; text-transform: uppercase;">
                Success Rate</p>
            <h3 style="margin: 0 0 0.5rem 0; font-size: 2rem; color: #10b981;" id="successRate"><i
                    class="fa-solid fa-spinner fa-spin"></i></h3>
            <p style="color: var(--text-muted); font-size: 0.8rem; margin: 0;">Last 30 generations</p>
        </div>

        <!-- Average Accuracy -->
        <div class="glass-panel" style="padding: 1.5rem; border-left: 4px solid #818cf8;">
            <p style="color: var(--text-muted); font-size: 0.85rem; margin: 0 0 0.5rem 0; text-transform: uppercase;">
                Avg Accuracy</p>
            <h3 style="margin: 0 0 0.5rem 0; font-size: 2rem; color: #818cf8;" id="avgAccuracy"><i
                    class="fa-solid fa-spinner fa-spin"></i></h3>
            <p style="color: var(--text-muted); font-size: 0.8rem; margin: 0;">Schedule quality score</p>
        </div>

        <!-- Avg Generation Time -->
        <div class="glass-panel" style="padding: 1.5rem; border-left: 4px solid #a855f7;">
            <p style="color: var(--text-muted); font-size: 0.85rem; margin: 0 0 0.5rem 0; text-transform: uppercase;">
                Avg Time</p>
            <h3 style="margin: 0 0 0.5rem 0; font-size: 2rem; color: #a855f7;" id="avgTime"><i
                    class="fa-solid fa-spinner fa-spin"></i></h3>
            <p style="color: var(--text-muted); font-size: 0.8rem; margin: 0;">Per schedule generation</p>
        </div>

        <!-- Conflict Resolution -->
        <div class="glass-panel" style="padding: 1.5rem; border-left: 4px solid #f59e0b;">
            <p style="color: var(--text-muted); font-size: 0.85rem; margin: 0 0 0.5rem 0; text-transform: uppercase;">
                Conflicts Resolved</p>
            <h3 style="margin: 0 0 0.5rem 0; font-size: 2rem; color: #f59e0b;" id="conflictsResolved"><i
                    class="fa-solid fa-spinner fa-spin"></i></h3>
            <p style="color: var(--text-muted); font-size: 0.8rem; margin: 0;">vs. baseline algorithm</p>
        </div>
    </div>

    <!-- AI Component Status -->
    <div class="glass-panel" style="padding: 1.5rem; margin-bottom: 2rem;">
        <h3 style="margin-top: 0; margin-bottom: 1rem;">AI System Components</h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;"
            id="aiComponentsGrid">
            <div style="text-align: center; color: var(--text-muted); padding: 2rem; grid-column: 1 / -1;">
                <i class="fa-solid fa-spinner fa-spin" style="font-size: 2rem; margin-bottom: 1rem;"></i>
                <p>Connecting to AI Engine...</p>
            </div>
        </div>
    </div>

    <!-- Feasibility Heatmap -->
    <div class="glass-panel" style="padding: 1.5rem; margin-bottom: 2rem;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
            <h3 style="margin: 0;">Feasibility Heatmap</h3>
            <span style="font-size: 0.8rem; color: var(--text-muted);">Probability of successful scheduling
                (Historical)</span>
        </div>
        <div id="heatmapContainer" style="overflow-x: auto;">
            <div style="text-align: center; color: var(--text-muted); padding: 2rem;">
                <i class="fa-solid fa-layer-group fa-fade" style="font-size: 2rem; margin-bottom: 1rem;"></i>
                <p>Generating Heatmap...</p>
            </div>
        </div>
        <div
            style="display: flex; gap: 1rem; margin-top: 1rem; font-size: 0.75rem; align-items: center; justify-content: flex-end;">
            <span>Legend:</span>
            <div style="display: flex; align-items: center; gap: 4px;">
                <div style="width: 12px; height: 12px; background: #ef4444; border-radius: 2px;"></div> Difficult
            </div>
            <div style="display: flex; align-items: center; gap: 4px;">
                <div style="width: 12px; height: 12px; background: #f59e0b; border-radius: 2px;"></div> Balanced
            </div>
            <div style="display: flex; align-items: center; gap: 4px;">
                <div style="width: 12px; height: 12px; background: #10b981; border-radius: 2px;"></div> Optimal
            </div>
        </div>
    </div>

    <!-- Constraint Satisfaction Metrics -->
    <div class="glass-panel" style="padding: 1.5rem; margin-bottom: 2rem;">
        <h3 style="margin-top: 0; margin-bottom: 1rem;">Constraint Satisfaction Analysis</h3>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem;">
            <!-- Hard Constraints -->
            <div style="padding: 1rem; background: rgba(16, 185, 129, 0.1); border-radius: 8px; border-left: 4px solid #10b981;">
                <h4 style="margin-top: 0; margin-bottom: 1rem; color: #10b981;">Hard Constraints (Critical)</h4>
                <div style="margin-bottom: 1rem;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem; font-size: 0.9rem;">
                        <span>No Lecturer Conflicts</span>
                        <strong id="hardLecturerConflict">--</strong>
                    </div>
                    <div style="background: rgba(0,0,0,0.2); height: 8px; border-radius: 4px; overflow: hidden;">
                        <div id="hardLecturerBar" style="background: #10b981; height: 100%; width: 0%; border-radius: 4px;"></div>
                    </div>
                </div>
                <div style="margin-bottom: 1rem;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem; font-size: 0.9rem;">
                        <span>No Room Conflicts</span>
                        <strong id="hardRoomConflict">--</strong>
                    </div>
                    <div style="background: rgba(0,0,0,0.2); height: 8px; border-radius: 4px; overflow: hidden;">
                        <div id="hardRoomBar" style="background: #10b981; height: 100%; width: 0%; border-radius: 4px;"></div>
                    </div>
                </div>
                <div style="margin-bottom: 1rem;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem; font-size: 0.9rem;">
                        <span>Slot Restrictions Enforced</span>
                        <strong id="hardSlotRestrictions">--</strong>
                    </div>
                    <div style="background: rgba(0,0,0,0.2); height: 8px; border-radius: 4px; overflow: hidden;">
                        <div id="hardSlotBar" style="background: #10b981; height: 100%; width: 0%; border-radius: 4px;"></div>
                    </div>
                </div>
                <p style="margin: 0; margin-top: 1rem; padding-top: 1rem; border-top: 1px solid rgba(255,255,255,0.1); font-weight: 600; color: #10b981;">
                    Overall: <span id="hardConstraintRate">--</span>
                </p>
            </div>
            
            <!-- Soft Constraints -->
            <div style="padding: 1rem; background: rgba(168, 85, 247, 0.1); border-radius: 8px; border-left: 4px solid #a855f7;">
                <h4 style="margin-top: 0; margin-bottom: 1rem; color: #a855f7;">Soft Constraints (Preferences)</h4>
                <div style="margin-bottom: 1rem;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem; font-size: 0.9rem;">
                        <span>Preferred Time Slots</span>
                        <strong id="softPreferredTime">--</strong>
                    </div>
                    <div style="background: rgba(0,0,0,0.2); height: 8px; border-radius: 4px; overflow: hidden;">
                        <div id="softTimeBar" style="background: #a855f7; height: 100%; width: 0%; border-radius: 4px;"></div>
                    </div>
                </div>
                <div style="margin-bottom: 1rem;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem; font-size: 0.9rem;">
                        <span>Balanced Workload</span>
                        <strong id="softBalancedLoad">--</strong>
                    </div>
                    <div style="background: rgba(0,0,0,0.2); height: 8px; border-radius: 4px; overflow: hidden;">
                        <div id="softLoadBar" style="background: #a855f7; height: 100%; width: 0%; border-radius: 4px;"></div>
                    </div>
                </div>
                <div style="margin-bottom: 1rem;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem; font-size: 0.9rem;">
                        <span>Room Preferences</span>
                        <strong id="softRoomPref">--</strong>
                    </div>
                    <div style="background: rgba(0,0,0,0.2); height: 8px; border-radius: 4px; overflow: hidden;">
                        <div id="softRoomBar" style="background: #a855f7; height: 100%; width: 0%; border-radius: 4px;"></div>
                    </div>
                </div>
                <p style="margin: 0; margin-top: 1rem; padding-top: 1rem; border-top: 1px solid rgba(255,255,255,0.1); font-weight: 600; color: #a855f7;">
                    Overall: <span id="softConstraintRate">--</span>
                </p>
            </div>
        </div>
    </div>

    <!-- Faculty Workload Equity -->
    <div class="glass-panel" style="padding: 1.5rem; margin-bottom: 2rem;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
            <h3 style="margin: 0;">Faculty Workload Equity Analysis</h3>
            <span style="font-size: 0.8rem; color: var(--text-muted);" id="equityScore">Equity Score: --</span>
        </div>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
            <div style="padding: 1rem; background: rgba(99, 102, 241, 0.1); border-radius: 8px;">
                <p style="margin: 0; font-size: 0.8rem; color: var(--text-muted);">Average Load</p>
                <p style="margin: 0; font-size: 1.5rem; font-weight: 700; color: #818cf8;" id="avgLoad">--</p>
            </div>
            <div style="padding: 1rem; background: rgba(34, 197, 94, 0.1); border-radius: 8px;">
                <p style="margin: 0; font-size: 0.8rem; color: var(--text-muted);">Minimum Load</p>
                <p style="margin: 0; font-size: 1.5rem; font-weight: 700; color: #22c55e;" id="minLoad">--</p>
            </div>
            <div style="padding: 1rem; background: rgba(239, 68, 68, 0.1); border-radius: 8px;">
                <p style="margin: 0; font-size: 0.8rem; color: var(--text-muted);">Maximum Load</p>
                <p style="margin: 0; font-size: 1.5rem; font-weight: 700; color: #ef4444;" id="maxLoad">--</p>
            </div>
            <div style="padding: 1rem; background: rgba(245, 158, 11, 0.1); border-radius: 8px;">
                <p style="margin: 0; font-size: 0.8rem; color: var(--text-muted);">Std Deviation</p>
                <p style="margin: 0; font-size: 1.5rem; font-weight: 700; color: #f59e0b;" id="stdDev">--</p>
            </div>
        </div>
        <div id="workloadTable" style="overflow-x: auto;"></div>
    </div>

    <!-- Room Utilization -->
    <div class="glass-panel" style="padding: 1.5rem; margin-bottom: 2rem;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
            <h3 style="margin: 0;">Room Utilization Analysis</h3>
            <span style="font-size: 0.8rem; color: var(--text-muted);" id="overallUtil">Overall: --</span>
        </div>
        <div id="roomUtilTable" style="overflow-x: auto;"></div>
    </div>

    <!-- Schedule Quality Metrics -->
    <div class="glass-panel" style="padding: 1.5rem; margin-bottom: 2rem;">
        <h3 style="margin-top: 0; margin-bottom: 1rem;">Schedule Quality Score</h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem;">
            <div style="padding: 1.5rem; background: linear-gradient(135deg, rgba(16, 185, 129, 0.2), rgba(10, 132, 92, 0.2)); border-radius: 8px; border: 1px solid rgba(16, 185, 129, 0.3);">
                <p style="margin: 0; font-size: 0.8rem; color: var(--text-muted); text-transform: uppercase;">Conflict-Free %</p>
                <p style="margin: 0; margin-top: 0.5rem; font-size: 2rem; font-weight: 700; color: #10b981;" id="qualityConflictFree">--</p>
            </div>
            <div style="padding: 1.5rem; background: linear-gradient(135deg, rgba(168, 85, 247, 0.2), rgba(126, 34, 206, 0.2)); border-radius: 8px; border: 1px solid rgba(168, 85, 247, 0.3);">
                <p style="margin: 0; font-size: 0.8rem; color: var(--text-muted); text-transform: uppercase;">Clustering Score</p>
                <p style="margin: 0; margin-top: 0.5rem; font-size: 2rem; font-weight: 700; color: #a855f7;" id="qualityClustering">--</p>
            </div>
            <div style="padding: 1.5rem; background: linear-gradient(135deg, rgba(59, 130, 246, 0.2), rgba(29, 78, 216, 0.2)); border-radius: 8px; border: 1px solid rgba(59, 130, 246, 0.3);">
                <p style="margin: 0; font-size: 0.8rem; color: var(--text-muted); text-transform: uppercase;">Gap Efficiency</p>
                <p style="margin: 0; margin-top: 0.5rem; font-size: 2rem; font-weight: 700; color: #3b82f6;" id="qualityGaps">--</p>
            </div>
            <div style="padding: 1.5rem; background: linear-gradient(135deg, rgba(34, 197, 94, 0.2), rgba(20, 120, 56, 0.2)); border-radius: 8px; border: 1px solid rgba(34, 197, 94, 0.3);">
                <p style="margin: 0; font-size: 0.8rem; color: var(--text-muted); text-transform: uppercase;">Overall Quality</p>
                <p style="margin: 0; margin-top: 0.5rem; font-size: 2rem; font-weight: 700; color: #22c55e;" id="qualityOverall">--</p>
                <p style="margin: 0; margin-top: 0.5rem; font-size: 0.75rem; color: var(--text-muted);" id="qualityRanking">--</p>
            </div>
        </div>
    </div>

    <!-- AI Improvements -->
    <div class="glass-panel" style="padding: 1.5rem; margin-bottom: 2rem;">
        <h3 style="margin-top: 0; margin-bottom: 1rem;">Key Improvements Over Previous System</h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1.5rem;"
            id="improvementsGrid">
            <div style="padding: 1rem; background: rgba(16, 185, 129, 0.1); border-radius: 8px;">
                <div style="font-size: 1.5rem; font-weight: 700; color: #10b981; margin-bottom: 0.5rem;"
                    id="impConflict"><i class="fa-solid fa-spinner fa-spin"></i></div>
                <p style="margin: 0; font-size: 0.95rem; font-weight: 600;">Conflict Resolution Rate</p>
                <p style="margin: 0; font-size: 0.8rem; color: var(--text-muted); margin-top: 0.3rem;">Better detection
                    and resolution of scheduling conflicts</p>
            </div>
            <div style="padding: 1rem; background: rgba(168, 85, 247, 0.1); border-radius: 8px;">
                <div style="font-size: 1.5rem; font-weight: 700; color: #a855f7; margin-bottom: 0.5rem;" id="impRoom"><i
                        class="fa-solid fa-spinner fa-spin"></i></div>
                <p style="margin: 0; font-size: 0.95rem; font-weight: 600;">Room Utilization</p>
                <p style="margin: 0; font-size: 0.8rem; color: var(--text-muted); margin-top: 0.3rem;">Smarter room
                    assignments reduce empty slots</p>
            </div>
            <div style="padding: 1rem; background: rgba(99, 102, 241, 0.1); border-radius: 8px;">
                <div style="font-size: 1.5rem; font-weight: 700; color: #818cf8; margin-bottom: 0.5rem;"
                    id="impSatisfaction"><i class="fa-solid fa-spinner fa-spin"></i></div>
                <p style="margin: 0; font-size: 0.95rem; font-weight: 600;">Lecturer Satisfaction</p>
                <p style="margin: 0; font-size: 0.8rem; color: var(--text-muted); margin-top: 0.3rem;">Q-Learning learns
                    and respects instructor preferences</p>
            </div>
            <div style="padding: 1rem; background: rgba(245, 158, 11, 0.1); border-radius: 8px;">
                <div style="font-size: 1.5rem; font-weight: 700; color: #f59e0b; margin-bottom: 0.5rem;" id="impTime"><i
                        class="fa-solid fa-spinner fa-spin"></i></div>
                <p style="margin: 0; font-size: 0.95rem; font-weight: 600;">Generation Time</p>
                <p style="margin: 0; font-size: 0.8rem; color: var(--text-muted); margin-top: 0.3rem;">Feasibility
                    classifier reduces solver search space</p>
            </div>
            <div style="padding: 1rem; background: rgba(34, 197, 94, 0.1); border-radius: 8px;">
                <div style="font-size: 1.5rem; font-weight: 700; color: #22c55e; margin-bottom: 0.5rem;"
                    id="impSuccess"><i class="fa-solid fa-spinner fa-spin"></i></div>
                <p style="margin: 0; font-size: 0.95rem; font-weight: 600;">Success Rate</p>
                <p style="margin: 0; font-size: 0.8rem; color: var(--text-muted); margin-top: 0.3rem;"
                    id="impSuccessDesc">AI finds valid schedules for a high percentage of cases</p>
            </div>
            <div style="padding: 1rem; background: rgba(59, 130, 246, 0.1); border-radius: 8px;">
                <div style="font-size: 1.5rem; font-weight: 700; color: #3b82f6; margin-bottom: 0.5rem;"
                    id="impAccuracy"><i class="fa-solid fa-spinner fa-spin"></i></div>
                <p style="margin: 0; font-size: 0.95rem; font-weight: 600;">Predicted Accuracy</p>
                <p style="margin: 0; font-size: 0.8rem; color: var(--text-muted); margin-top: 0.3rem;"
                    id="impAccuracyDesc">Deep learning precisely estimates quality before generation</p>
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
            const successRateNum = Number(data?.metrics?.success_rate);
            const avgAccuracyNum = Number(data?.metrics?.avg_accuracy);
            const avgTimeNum = Number(data?.metrics?.avg_time_seconds);
            const conflictsResolvedNum = Number(data?.metrics?.conflicts_resolved);

            const successRateDisplay = Number.isFinite(successRateNum)
                ? successRateNum
                : (Number.isFinite(avgAccuracyNum) ? avgAccuracyNum : 0);

            document.getElementById('successRate').textContent = `${successRateDisplay.toFixed(1)}%`;
            document.getElementById('avgAccuracy').textContent = `${Number.isFinite(avgAccuracyNum) ? avgAccuracyNum.toFixed(1) : '0.0'}%`;
            document.getElementById('avgTime').textContent = `${Number.isFinite(avgTimeNum) ? Math.round(avgTimeNum) : 0}s`;
            document.getElementById('conflictsResolved').textContent = `+${Number.isFinite(conflictsResolvedNum) ? Math.round(conflictsResolvedNum) : 0}%`;

            // Update improvements
            document.getElementById('impConflict').textContent = '+' + data.improvements.conflict_resolution + '%';
            document.getElementById('impRoom').textContent = '+' + data.improvements.room_utilization + '%';
            document.getElementById('impSatisfaction').textContent = '+' + data.improvements.lecturer_satisfaction + '%';
            document.getElementById('impTime').textContent = '-' + data.improvements.generation_time + '%';
            document.getElementById('impSuccess').textContent = (data.improvements.success_rate > 0 ? '+' : '') + data.improvements.success_rate + '%';
            document.getElementById('impAccuracy').textContent = (data.improvements.accuracy > 0 ? '+' : '') + data.improvements.accuracy + '%';

            // Update dynamic descriptions
            if (document.getElementById('impSuccessDesc')) {
                document.getElementById('impSuccessDesc').textContent = `AI successfully found valid schedules for ${data.metrics.success_rate}% of cases`;
            }
            if (document.getElementById('impAccuracyDesc')) {
                document.getElementById('impAccuracyDesc').textContent = `AI generated schedules with an average of ${data.metrics.avg_accuracy}% accuracy`;
            }

            // Generate Component Grid
            if (data.ai_components) {
                const grid = document.getElementById('aiComponentsGrid');
                grid.innerHTML = '';

                const componentsMap = {
                    'csp_solver': { icon: 'fa-cube', color: '#10b981', desc: 'Backtracking & MRV Heuristic' },
                    'deep_learning': { icon: 'fa-brain', color: '#a855f7', desc: 'Neural Network Classifier' },
                    'q_learning': { icon: 'fa-graduation-cap', color: '#818cf8', desc: 'Preference Learning Agent' },
                    'feasibility': { icon: 'fa-shield-check', color: '#10b981', desc: 'Random Forest Classifier' },
                    'explainability': { icon: 'fa-magnifying-glass', color: '#ec4899', desc: 'SHAP Feature Analysis' },
                    'bidirectional': { icon: 'fa-arrows-spin', color: '#818cf8', desc: 'Feedback Integration' }
                };

                for (const [key, comp] of Object.entries(data.ai_components)) {
                    const meta = componentsMap[key] || { icon: 'fa-microchip', color: '#6366f1', desc: 'System Component' };

                    let statusColor = '#ef4444';
                    let statusIcon = 'fa-times-circle';
                    if (['operational', 'ready', 'active', 'trained', 'enabled', 'connected'].includes(comp.status.toLowerCase())) {
                        statusColor = meta.color;
                        statusIcon = 'fa-check-circle';
                    }

                    const accHtml = comp.accuracy ? `<span style="float: right; font-size: 0.7rem; background: rgba(255,255,255,0.1); padding: 2px 6px; border-radius: 8px;">${comp.accuracy}% Acc</span>` : '';

                    grid.innerHTML += `
                        <div style="padding: 1rem; background: ${meta.color}15; border-radius: 8px; border-left: 3px solid ${meta.color};">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.3rem;">
                                <div style="display: flex; align-items: center; gap: 0.5rem;">
                                    <i class="fa-solid ${meta.icon}" style="color: ${meta.color};"></i>
                                    <span style="font-weight: 600;">${comp.name}</span>
                                </div>
                                ${accHtml}
                            </div>
                            <p style="font-size: 0.8rem; color: var(--text-muted); margin: 0;">${meta.desc}</p>
                            <p style="font-size: 0.75rem; color: ${statusColor}; margin: 0; margin-top: 0.5rem; text-transform: capitalize;">
                                <i class="fa-solid ${statusIcon}"></i> ${comp.status}
                            </p>
                        </div>
                    `;
                }
            }

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

    // Load constraint metrics
    async function loadConstraintMetrics() {
        try {
            const response = await fetch('api/get_constraint_metrics.php', {
                credentials: 'include'
            });

            if (!response.ok) throw new Error('Failed to fetch constraint metrics');

            const data = await response.json();
            console.log('Constraint metrics:', data);

            if (data.status !== 'success') throw new Error(data.message);

            // Hard constraints
            const hc = data.constraint_metrics.hard_constraints;
            document.getElementById('hardLecturerConflict').textContent = hc.no_lecturer_conflicts + '%';
            document.getElementById('hardLecturerBar').style.width = hc.no_lecturer_conflicts + '%';
            document.getElementById('hardRoomConflict').textContent = hc.no_room_conflicts + '%';
            document.getElementById('hardRoomBar').style.width = hc.no_room_conflicts + '%';
            document.getElementById('hardSlotRestrictions').textContent = hc.slot_hour_restrictions + '%';
            document.getElementById('hardSlotBar').style.width = hc.slot_hour_restrictions + '%';
            document.getElementById('hardConstraintRate').textContent = hc.satisfaction_rate + '%';

            // Soft constraints
            const sc = data.constraint_metrics.soft_constraints;
            document.getElementById('softPreferredTime').textContent = sc.preferred_time_slots + '%';
            document.getElementById('softTimeBar').style.width = sc.preferred_time_slots + '%';
            document.getElementById('softBalancedLoad').textContent = sc.balanced_workload + '%';
            document.getElementById('softLoadBar').style.width = sc.balanced_workload + '%';
            document.getElementById('softRoomPref').textContent = sc.room_preferences + '%';
            document.getElementById('softRoomBar').style.width = sc.room_preferences + '%';
            document.getElementById('softConstraintRate').textContent = sc.satisfaction_rate + '%';

            // Workload equity
            const we = data.workload_equity;
            document.getElementById('equityScore').textContent = 'Equity Score: ' + we.equity_score + '%';
            document.getElementById('avgLoad').textContent = we.average_load.toFixed(1);
            document.getElementById('minLoad').textContent = we.min_load;
            document.getElementById('maxLoad').textContent = we.max_load;
            document.getElementById('stdDev').textContent = we.std_deviation.toFixed(2);

            // Faculty workload table
            if (we.faculty_breakdown && we.faculty_breakdown.length > 0) {
                let table = '<table style="width: 100%; border-collapse: collapse;">';
                table += '<thead><tr style="border-bottom: 1px solid rgba(255,255,255,0.1);">';
                table += '<th style="text-align: left; padding: 0.5rem;">Lecturer</th>';
                table += '<th style="text-align: center; padding: 0.5rem;">Courses</th>';
                table += '<th style="text-align: center; padding: 0.5rem;">Credits</th>';
                table += '</tr></thead><tbody>';

                we.faculty_breakdown.slice(0, 10).forEach(faculty => {
                    table += '<tr style="border-bottom: 1px solid rgba(255,255,255,0.05);">';
                    table += '<td style="padding: 0.5rem;">' + faculty.name + '</td>';
                    table += '<td style="text-align: center; padding: 0.5rem;">' + faculty.courses + '</td>';
                    table += '<td style="text-align: center; padding: 0.5rem;">' + faculty.credits.toFixed(1) + '</td>';
                    table += '</tr>';
                });
                table += '</tbody></table>';
                document.getElementById('workloadTable').innerHTML = table;
            }

            // Room utilization
            const ru = data.room_utilization;
            document.getElementById('overallUtil').textContent = 'Overall: ' + ru.overall_utilization.toFixed(1) + '%';

            if (ru.rooms && ru.rooms.length > 0) {
                let table = '<table style="width: 100%; border-collapse: collapse;">';
                table += '<thead><tr style="border-bottom: 1px solid rgba(255,255,255,0.1);">';
                table += '<th style="text-align: left; padding: 0.5rem;">Room</th>';
                table += '<th style="text-align: center; padding: 0.5rem;">Capacity</th>';
                table += '<th style="text-align: center; padding: 0.5rem;">Usage</th>';
                table += '<th style="text-align: center; padding: 0.5rem;">Avg Enrollment</th>';
                table += '<th style="text-align: center; padding: 0.5rem;">Utilization</th>';
                table += '</tr></thead><tbody>';

                ru.rooms.slice(0, 12).forEach(room => {
                    const utilColor = room.utilization >= 80 ? '#10b981' : (room.utilization >= 60 ? '#f59e0b' : '#ef4444');
                    table += '<tr style="border-bottom: 1px solid rgba(255,255,255,0.05);">';
                    table += '<td style="padding: 0.5rem;">' + room.name + '</td>';
                    table += '<td style="text-align: center; padding: 0.5rem;">' + room.capacity + '</td>';
                    table += '<td style="text-align: center; padding: 0.5rem;">' + room.usage_count + '</td>';
                    table += '<td style="text-align: center; padding: 0.5rem;">' + room.avg_enrollment + '</td>';
                    table += '<td style="text-align: center; padding: 0.5rem;"><span style="color: ' + utilColor + '; font-weight: 600;">' + room.utilization.toFixed(1) + '%</span></td>';
                    table += '</tr>';
                });
                table += '</tbody></table>';
                document.getElementById('roomUtilTable').innerHTML = table;
            }

            // Quality metrics
            const qm = data.quality_metrics;
            document.getElementById('qualityConflictFree').textContent = qm.conflict_free_percentage + '%';
            document.getElementById('qualityClustering').textContent = qm.clustering_score + '%';
            document.getElementById('qualityGaps').textContent = qm.gap_efficiency + '%';
            document.getElementById('qualityOverall').textContent = qm.overall_quality_score + '%';
            document.getElementById('qualityRanking').textContent = 'Rating: ' + qm.ranking;

        } catch (error) {
            console.error('Constraint metrics error:', error);
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
        loadConstraintMetrics();
        checkAIStatus();

        // Refresh analytics every 30 seconds
        setInterval(loadAnalytics, 30000);
        setInterval(loadConstraintMetrics, 30000);
        setInterval(checkAIStatus, 30000);
        loadHeatmap();
    });

    async function loadHeatmap() {
        const container = document.getElementById('heatmapContainer');
        try {
            const data = await apiGet('/feasibility/heatmap');
            if (data.status !== 'success') throw new Error(data.message);

            let html = `<table style="width: 100%; border-spacing: 4px; border-collapse: separate;">`;
            html += `<thead><tr><th style="background: transparent;"></th>`;
            data.slots.forEach(slot => {
                const shortSlot = slot.split(' - ')[0];
                html += `<th style="font-size: 0.75rem; color: var(--text-muted); font-weight: 500; text-align: center;">${shortSlot}</th>`;
            });
            html += `</tr></thead><tbody>`;

            data.heatmap.forEach(row => {
                html += `<tr><td style="font-size: 0.8rem; font-weight: 600; padding-right: 1rem; width: 100px;">${row.day}</td>`;
                data.slots.forEach(slot => {
                    const score = row.slots[slot];
                    let bgColor = '#ef4444'; // Red
                    if (score > 0.4) bgColor = '#f59e0b'; // Amber
                    if (score > 0.7) bgColor = '#10b981'; // Green

                    const opacity = Math.max(0.2, score); // Ensure at least some visibility

                    html += `<td style="background: ${bgColor}; opacity: ${opacity}; height: 40px; border-radius: 4px; position: relative;" title="${row.day} ${slot}: ${Math.round(score * 100)}% Feasibility">
                        <div style="font-size: 0.7rem; color: #fff; font-weight: 700; display: flex; align-items: center; justify-content: center; height: 100%; text-shadow: 0 1px 2px rgba(0,0,0,0.5);">
                            ${Math.round(score * 100)}%
                        </div>
                    </td>`;
                });
                html += `</tr>`;
            });

            html += `</tbody></table>`;
            container.innerHTML = html;
        } catch (e) {
            container.innerHTML = `<div style="text-align: center; color: var(--danger);"><i class="fa-solid fa-triangle-exclamation"></i> Error loading heatmap: ${e.message}</div>`;
        }
    }
</script>

<?php include 'includes/footer.php'; ?>