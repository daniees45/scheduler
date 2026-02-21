<?php
$page_title = 'Productivity Analytics';
include 'includes/header.php';
require_once 'api/db.php';

requireRole(['student', 'lecturer']);

$user_id = (int)($_SESSION['user_id'] ?? 0);
$period = $_GET['period'] ?? 'week';
?>

<style>
.analytics-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.stat-card {
    background: white;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.stat-card h3 {
    margin: 0 0 10px 0;
    color: #6366f1;
    font-size: 14px;
    text-transform: uppercase;
    font-weight: 600;
}

.stat-value {
    font-size: 32px;
    font-weight: bold;
    color: #1f2937;
}

.stat-label {
    font-size: 14px;
    color: #6b7280;
    margin-top: 5px;
}

.chart-card {
    background: white;
    padding: 25px;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    margin-bottom: 25px;
}

.chart-card h2 {
    margin: 0 0 20px 0;
    color: #1f2937;
    font-size: 20px;
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
    border: 1px solid #e5e7eb;
    text-align: center;
    vertical-align: middle;
    font-size: 11px;
    cursor: pointer;
}

.heatmap-header {
    background: #f3f4f6;
    font-weight: 600;
    color: #374151;
}

.heatmap-score-0 { background-color: #f9fafb; }
.heatmap-score-1 { background-color: #dbeafe; }
.heatmap-score-2 { background-color: #93c5fd; }
.heatmap-score-3 { background-color: #60a5fa; }
.heatmap-score-4 { background-color: #3b82f6; }
.heatmap-score-5 { background-color: #2563eb; color: white; }

.category-breakdown {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
}

.category-item {
    padding: 15px;
    background: #f9fafb;
    border-radius: 6px;
    border-left: 4px solid #6366f1;
}

.category-item h4 {
    margin: 0 0 8px 0;
    color: #1f2937;
    text-transform: capitalize;
}

.category-stat {
    font-size: 14px;
    color: #6b7280;
    margin: 4px 0;
}

.period-selector {
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
}

.period-btn {
    padding: 8px 16px;
    background: white;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
    transition: all 0.2s;
}

.period-btn:hover {
    background: #f3f4f6;
}

.period-btn.active {
    background: #6366f1;
    color: white;
    border-color: #6366f1;
}

.progress-bar {
    width: 100%;
    height: 8px;
    background: #e5e7eb;
    border-radius: 4px;
    overflow: hidden;
    margin-top: 8px;
}

.progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #6366f1, #8b5cf6);
    transition: width 0.3s;
}
</style>

<div class="analytics-container">
    <h1><i class="fas fa-chart-line"></i> Productivity Analytics</h1>
    
    <div class="period-selector">
        <button class="period-btn <?= $period === 'day' ? 'active' : '' ?>" onclick="changePeriod('day')">Today</button>
        <button class="period-btn <?= $period === 'week' ? 'active' : '' ?>" onclick="changePeriod('week')">This Week</button>
        <button class="period-btn <?= $period === 'month' ? 'active' : '' ?>" onclick="changePeriod('month')">This Month</button>
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
