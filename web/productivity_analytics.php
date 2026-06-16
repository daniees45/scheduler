<?php
$page_title = 'Productivity Analytics';
include 'includes/header.php';
require_once 'api/db.php';

requireRole(['student', 'lecturer']);

$user_id = (int)($_SESSION['user_id'] ?? 0);
$period = $_GET['period'] ?? 'week';
?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

<style>
.analytics-container {
    max-width: 1440px;
    margin: 0 auto;
    padding: 24px;
    color: var(--text-main, #e5e7eb);
}

.analytics-hero {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
    align-items: flex-end;
    margin-bottom: 1.5rem;
    padding: 1.4rem 1.5rem;
    border-radius: 20px;
    background: linear-gradient(135deg, rgba(var(--primary-rgb), 0.18), rgba(var(--secondary-rgb), 0.12));
    border: 1px solid rgba(255,255,255,0.08);
    box-shadow: 0 18px 44px rgba(0,0,0,0.2);
}

.analytics-hero h1 {
    margin: 0;
    font-size: 2rem;
}

.analytics-hero p {
    margin: 0.35rem 0 0 0;
    color: rgba(255,255,255,0.78);
}

.analytics-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.7rem;
}

.analytics-action-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.stat-card {
    background: rgba(15, 23, 42, 0.62);
    padding: 20px;
    border-radius: 18px;
    box-shadow: 0 18px 36px rgba(0,0,0,0.16);
    border: 1px solid rgba(255,255,255,0.08);
}

.stat-card h3 {
    margin: 0 0 10px 0;
    color: #6366f1;
    color: var(--primary-color);
    text-transform: uppercase;
    font-weight: 600;
}

.stat-value {
    font-size: 32px;
    font-weight: bold;
    color: #1f2937;
    color: var(--text-main, #fff);

.stat-label {
    font-size: 14px;
    color: #6b7280;
    color: rgba(255,255,255,0.68);
}

.chart-card {
    background: white;
    background: rgba(15, 23, 42, 0.62);
    border-radius: 8px;
    border-radius: 18px;
    box-shadow: 0 18px 36px rgba(0,0,0,0.16);
    border: 1px solid rgba(255,255,255,0.08);
}

.chart-card h2 {
    margin: 0 0 20px 0;
    color: #1f2937;
    color: var(--text-main, #fff);
}

.heatmap-container {
    overflow-x: auto;
}

.heatmap {
    display: table;
    border-collapse: collapse;
    margin: 0 auto;
}

.heatmap-row {
    display: table-row;
}

.heatmap-cell {
    display: table-cell;
    width: 30px;
    height: 30px;
    border: 1px solid rgba(255,255,255,0.08);
    text-align: center;
    vertical-align: middle;
    font-size: 11px;
    cursor: pointer;
}

.heatmap-header {
    background: rgba(255,255,255,0.06);
    font-weight: 600;
    color: var(--text-main, #fff);
}

.heatmap-score-0 { background-color: rgba(255,255,255,0.03); }
.heatmap-score-1 { background-color: rgba(var(--primary-rgb), 0.16); }
.heatmap-score-2 { background-color: rgba(var(--primary-rgb), 0.28); }
.heatmap-score-3 { background-color: rgba(var(--primary-rgb), 0.4); }
.heatmap-score-4 { background-color: rgba(var(--secondary-rgb), 0.55); }
.heatmap-score-5 { background-color: var(--primary-color); color: white; }

.category-breakdown {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
}

.category-item {
    padding: 15px;
    background: rgba(255,255,255,0.04);
    border-radius: 12px;
    border-left: 4px solid var(--primary-color);
}

.category-item h4 {
    margin: 0 0 8px 0;
    color: var(--text-main, #fff);
    text-transform: capitalize;
}

.category-stat {
    font-size: 14px;
    color: rgba(255,255,255,0.68);
    margin: 4px 0;
}

.period-selector {
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
}

.period-btn {
    padding: 8px 16px;
    background: rgba(255,255,255,0.05);
    color: var(--text-main, #fff);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 999px;
    cursor: pointer;
    font-size: 14px;
    transition: all 0.2s;
}

.period-btn:hover {
    background: rgba(255,255,255,0.09);
}

.period-btn.active {
    background: var(--primary-color);
    color: white;
    border-color: var(--primary-color);
}

.progress-bar {
    width: 100%;
    height: 8px;
    background: rgba(255,255,255,0.08);
    border-radius: 4px;
    overflow: hidden;
    margin-top: 8px;
}

.progress-fill {
    height: 100%;
    background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
    transition: width 0.3s;
}

.weekly-activity-list {
    display: grid;
    gap: 0.75rem;
}

.weekly-activity-item {
    display: grid;
    grid-template-columns: 1.5fr 0.8fr 0.8fr 0.7fr;
    gap: 0.75rem;
    padding: 0.9rem 1rem;
    background: rgba(255,255,255,0.04);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 14px;
}

.weekly-activity-item strong {
    color: var(--text-main, #fff);
}

.weekly-activity-meta {
    color: rgba(255,255,255,0.68);
    font-size: 0.9rem;
}

@media (max-width: 768px) {
    .analytics-container { padding: 16px; }
    .weekly-activity-item { grid-template-columns: 1fr; }
}
</style>

<div class="analytics-container">
    <div class="analytics-hero">
        <div>
            <h1><i class="fas fa-chart-line"></i> Productivity Analytics</h1>
            <p>Weekly activity insights, weekly summaries, and exports aligned to your schedule branding.</p>
        </div>
        <div class="analytics-actions">
            <button class="glass-btn analytics-action-btn" onclick="exportWeeklyPdf()"><i class="fa-solid fa-file-pdf"></i> Export PDF</button>
            <button class="glass-btn secondary analytics-action-btn" onclick="exportGoogleCalendar()"><i class="fa-brands fa-google"></i> Export Calendar</button>
        </div>
    </div>
    
    <div class="period-selector">
        <button class="period-btn <?= $period === 'day' ? 'active' : '' ?>" onclick="changePeriod('day')">Today</button>
        <button class="period-btn <?= $period === 'week' ? 'active' : '' ?>" onclick="changePeriod('week')">This Week</button>
        <button class="period-btn <?= $period === 'month' ? 'active' : '' ?>" onclick="changePeriod('month')">This Month</button>
    </div>

    <div class="chart-card" id="weeklyActivityReport">
        <h2><i class="fas fa-calendar-week"></i> Weekly Activity Summary</h2>
        <div class="stats-grid">
            <div class="stat-card">
                <h3>Weekly Activities</h3>
                <div class="stat-value" id="weeklyActivityCount">-</div>
                <div class="stat-label">Logged activities in the last 7 days</div>
            </div>
            <div class="stat-card">
                <h3>Weekly Hours</h3>
                <div class="stat-value" id="weeklyActivityHours">-</div>
                <div class="stat-label">Total time spent</div>
            </div>
            <div class="stat-card">
                <h3>Completed</h3>
                <div class="stat-value" id="weeklyCompletedCount">-</div>
                <div class="stat-label">Successfully completed activities</div>
            </div>
            <div class="stat-card">
                <h3>Best Day</h3>
                <div class="stat-value" id="weeklyBestDay">-</div>
                <div class="stat-label">Highest concentration day</div>
            </div>
        </div>

        <div style="margin-top: 1rem;">
            <h3 style="margin-bottom: 1rem; color: var(--text-main, #fff);">Weekly Activity Log</h3>
            <div id="weeklyActivityList" class="weekly-activity-list">
                <div style="text-align: center; padding: 24px; color: rgba(255,255,255,0.6);">Loading weekly activity...</div>
            </div>
        </div>
    </div>
    
    <div class="stats-grid" id="statsGrid">
        <div class="stat-card">
            <h3>Total Tasks</h3>
            <div class="stat-value" id="totalTasks">-</div>
            <div class="stat-label">Completed tasks</div>
        </div>
        
        <div class="stat-card">
            <h3>Productive Hours</h3>
            <div class="stat-value" id="totalHours">-</div>
            <div class="stat-label">Hours logged</div>
        </div>
        
        <div class="stat-card">
            <h3>Completion Rate</h3>
            <div class="stat-value" id="completionRate">-</div>
            <div class="stat-label">Tasks completed</div>
            <div class="progress-bar">
                <div class="progress-fill" id="completionProgress" style="width: 0%"></div>
            </div>
        </div>
        
        <div class="stat-card">
            <h3>Avg Quality</h3>
            <div class="stat-value" id="avgQuality">-</div>
            <div class="stat-label">Out of 5.0</div>
        </div>
    </div>
    
    <div class="chart-card">
        <h2><i class="fas fa-fire"></i> Productivity Heatmap</h2>
        <p style="color: #6b7280; margin-bottom: 20px;">Your most productive times throughout the week</p>
        <div class="heatmap-container" id="heatmapContainer">
            <div style="text-align: center; padding: 40px; color: #9ca3af;">
                Loading heatmap...
            </div>
        </div>
    </div>
    
    <div class="chart-card">
        <h2><i class="fas fa-chart-pie"></i> Time Allocation by Category</h2>
        <div class="category-breakdown" id="categoryBreakdown">
            <div style="text-align: center; padding: 40px; color: #9ca3af;">
                Loading category data...
            </div>
        </div>
    </div>
    
    <div class="chart-card">
        <h2><i class="fas fa-star"></i> Peak Performance</h2>
        <div id="peakPerformance" style="padding: 20px; background: #f9fafb; border-radius: 6px;">
            <p style="margin: 0; color: #6b7280;">Loading...</p>
        </div>
    </div>
</div>

<script>
const period = '<?= htmlspecialchars($period) ?>';

function changePeriod(newPeriod) {
    window.location.href = `?period=${newPeriod}`;
}

function exportWeeklyPdf() {
    const element = document.getElementById('weeklyActivityReport');
    if (!element || typeof html2pdf === 'undefined') {
        showAlert('PDF export is not available right now.', 'Export Error', 'error');
        return;
    }

    html2pdf().set({
        margin: 0.35,
        filename: `weekly-activity-${new Date().toISOString().slice(0, 10)}.pdf`,
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2, useCORS: true },
        jsPDF: { unit: 'in', format: 'a4', orientation: 'portrait' }
    }).from(element).save();
}

function exportGoogleCalendar() {
    window.location.href = 'api/export_weekly_activity_ics.php';
}

async function loadAnalytics() {
    try {
        // Load statistics
        const statsResponse = await fetch(`api/productivity_tracking.php?action=get_statistics&period=${period}`);
        const statsData = await statsResponse.json();
        
        if (statsData.success) {
            const stats = statsData.overall;
            document.getElementById('totalTasks').textContent = stats.total_tasks;
            document.getElementById('totalHours').textContent = stats.total_hours.toFixed(1) + 'h';
            document.getElementById('completionRate').textContent = stats.completion_rate.toFixed(1) + '%';
            document.getElementById('avgQuality').textContent = stats.avg_quality_rating.toFixed(1);
            document.getElementById('completionProgress').style.width = stats.completion_rate + '%';
            
            // Category breakdown
            if (statsData.by_category && statsData.by_category.length > 0) {
                const categoryHTML = statsData.by_category.map(cat => `
                    <div class="category-item">
                        <h4><i class="fas fa-folder"></i> ${cat.category}</h4>
                        <div class="category-stat"><strong>${cat.hours.toFixed(1)}h</strong> total</div>
                        <div class="category-stat">${cat.task_count} tasks</div>
                        <div class="category-stat">Score: ${cat.avg_score.toFixed(1)}</div>
                    </div>
                `).join('');
                document.getElementById('categoryBreakdown').innerHTML = categoryHTML;
            } else {
                document.getElementById('categoryBreakdown').innerHTML = 
                    '<div style="text-align: center; padding: 40px; color: #9ca3af;">No category data yet. Start logging tasks!</div>';
            }
            
            // Peak performance
            if (statsData.most_productive) {
                const peak = statsData.most_productive;
                document.getElementById('peakPerformance').innerHTML = `
                    <p style="margin: 0; font-size: 18px; color: #1f2937;">
                        <i class="fas fa-trophy" style="color: #fbbf24;"></i>
                        Your most productive time is <strong>${peak.day}</strong> at <strong>${peak.hour}:00</strong>
                        <span style="color: #6b7280;">(Score: ${peak.score.toFixed(1)})</span>
                    </p>
                `;
            }
        }

        const activitiesResponse = await fetch('api/productivity_tracking.php?action=get_weekly_activities');
        const activitiesData = await activitiesResponse.json();
        if (activitiesData.success) {
            document.getElementById('weeklyActivityCount').textContent = activitiesData.summary.activity_count;
            document.getElementById('weeklyActivityHours').textContent = activitiesData.summary.total_hours.toFixed(1) + 'h';
            document.getElementById('weeklyCompletedCount').textContent = activitiesData.summary.completed_count;

            const byDay = {};
            activitiesData.activities.forEach(activity => {
                byDay[activity.day] = (byDay[activity.day] || 0) + activity.duration_minutes;
            });
            const bestDay = Object.entries(byDay).sort((a, b) => b[1] - a[1])[0];
            document.getElementById('weeklyBestDay').textContent = bestDay ? bestDay[0] : '—';

            const activityHTML = activitiesData.activities.length > 0
                ? activitiesData.activities.map(activity => `
                    <div class="weekly-activity-item">
                        <div>
                            <strong>${activity.task_name}</strong>
                            <div class="weekly-activity-meta">${activity.task_category || 'General'} · ${activity.day}</div>
                        </div>
                        <div class="weekly-activity-meta">${activity.start_time} - ${activity.end_time}</div>
                        <div class="weekly-activity-meta">${(activity.duration_minutes / 60).toFixed(1)}h</div>
                        <div class="weekly-activity-meta">${activity.completion_status} · ${Number(activity.productivity_score).toFixed(1)}</div>
                    </div>
                `).join('')
                : '<div style="text-align:center; padding: 24px; color: rgba(255,255,255,0.6);">No weekly activity logged yet.</div>';

            document.getElementById('weeklyActivityList').innerHTML = activityHTML;
        }
        
        // Load heatmap
        const heatmapResponse = await fetch('api/productivity_tracking.php?action=get_heatmap');
        const heatmapData = await heatmapResponse.json();
        
        if (heatmapData.success) {
            renderHeatmap(heatmapData.heatmap, heatmapData.days);
        }
        
    } catch (error) {
        console.error('Error loading analytics:', error);
    }
}

function renderHeatmap(heatmap, days) {
    const hours = Array.from({length: 24}, (_, i) => i);
    
    let html = '<div class="heatmap">';
    
    // Header row
    html += '<div class="heatmap-row">';
    html += '<div class="heatmap-cell heatmap-header" style="width: 80px;">Day / Hour</div>';
    hours.forEach(hour => {
        html += `<div class="heatmap-cell heatmap-header">${hour}</div>`;
    });
    html += '</div>';
    
    // Data rows
    days.forEach(day => {
        html += '<div class="heatmap-row">';
        html += `<div class="heatmap-cell heatmap-header" style="width: 80px;">${day.substring(0, 3)}</div>`;
        hours.forEach(hour => {
            const score = heatmap[day] && heatmap[day][hour] ? heatmap[day][hour] : 0;
            const level = score === 0 ? 0 : Math.min(5, Math.ceil(score / 2));
            const title = score > 0 ? `${day} ${hour}:00 - Score: ${score.toFixed(1)}` : 'No data';
            html += `<div class="heatmap-cell heatmap-score-${level}" title="${title}">${score > 0 ? score.toFixed(0) : ''}</div>`;
        });
        html += '</div>';
    });
    
    html += '</div>';
    
    document.getElementById('heatmapContainer').innerHTML = html;
}

// Load on page load
document.addEventListener('DOMContentLoaded', loadAnalytics);
</script>

<?php include 'includes/footer.php'; ?>
