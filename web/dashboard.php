<?php
session_start();
require_once 'api/db.php';
require_once 'includes/unified_schedule_service.php';

// Route to role-specific dashboards BEFORE any HTML output
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : null;

if ($user_role === 'student') {
    header("Location: student_dashboard.php");
    exit;
}

if ($user_role === 'lecturer') {
    header("Location: lecturer_dashboard.php");
    exit;
}

$page_title = 'Dashboard';
$page_css = 'assets/dashboard.css';
include 'includes/header.php';

// Admin dashboard below (super_admin, faculty_admin)
requireRole(['super_admin', 'faculty_admin']);

$semester = (string)($_SESSION['semester'] ?? '1');
$selected_schedule_id = (int)($_GET['schedule_id'] ?? 0);
$available_snapshots = unified_schedule_available_snapshots($conn, $semester, '', 60);
$active_snapshot = null;

if ($selected_schedule_id > 0) {
    foreach ($available_snapshots as $snapshot) {
        if ((int)($snapshot['id'] ?? 0) === $selected_schedule_id) {
            $active_snapshot = $snapshot;
            break;
        }
    }
}

if ($active_snapshot === null && !empty($available_snapshots)) {
    $active_snapshot = $available_snapshots[0];
    $selected_schedule_id = (int)($active_snapshot['id'] ?? 0);
}

// Quick stats
try {
    $course_count = $conn->query("SELECT COUNT(*) FROM courses")->fetch_row()[0];
    $room_count = $conn->query("SELECT COUNT(*) FROM rooms")->fetch_row()[0];
    $lecturer_count = $conn->query("SELECT COUNT(*) FROM lecturers")->fetch_row()[0];
// Use try-catch for tables that might not handle init yet
}
catch (Exception $e) {
    $course_count = 0;
    $room_count = 0;
    $lecturer_count = 0;
}
?>

<!-- Stats Row -->
<div class="stats-grid">
    <div class="glass-panel stat-card">
        <span class="stat-value"><?php echo $course_count; ?></span>
        <span class="stat-label">Total Courses</span>
    </div>
    
    <div class="glass-panel stat-card">
        <span class="stat-value"><?php echo $lecturer_count; ?></span>
        <span class="stat-label">Lecturers</span>
    </div>
    
    <div class="glass-panel stat-card">
        <span class="stat-value"><?php echo $room_count; ?></span>
        <span class="stat-label">Rooms Available</span>
    </div>
    
    <div class="glass-panel stat-card stat-card-warning">
        <span class="stat-value" id="aiAccuracyValue">--%</span>
        <span class="stat-label">AI Accuracy</span>
        <div class="ai-accuracy-hint" id="aiAccuracyHint">Highest from recent AI analytics</div>
    </div>
</div>

<div class="dashboard-grid">
    <!-- Main Chart -->
    <div class="glass-panel panel-p15">
        <h3 class="heading-mb1">Room Utilization</h3>
        <form method="GET" class="dashboard-filter-form" style="display: flex; gap: 0.6rem; flex-wrap: wrap; align-items: center; margin-bottom: 1rem;">
            <label for="schedule_id" style="color: var(--text-muted); font-size: 0.9rem; flex-shrink: 0;">Saved schedule:</label>
            <select id="schedule_id" name="schedule_id" class="glass-input" style="flex: 1; min-width: 180px;">
                <?php foreach ($available_snapshots as $snapshot): ?>
                <?php $snapshot_id = (int)($snapshot['id'] ?? 0); ?>
                <?php $snapshot_created_at = (string)($snapshot['created_at'] ?? ''); ?>
                <option value="<?php echo $snapshot_id; ?>" <?php echo $selected_schedule_id === $snapshot_id ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars(($snapshot_created_at !== '' ? $snapshot_created_at : 'Unknown time') . ' • ' . (($snapshot['department'] ?? '') ?: 'General') . ' • ' . (($snapshot['schedule_name'] ?? '') ?: 'Unnamed')); ?>
                </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="glass-btn secondary"><i class="fa-solid fa-filter"></i> View</button>
        </form>
        <div class="dashboard-chart-wrap">
            <canvas id="roomChart"></canvas>
        </div>
        <div id="roomChartHint" class="room-chart-footer"><span class="rcf-source">Loading&hellip;</span></div>
        <div id="roomChartDetails" class="room-chart-details"></div>
    </div>
    
    <!-- Quick Actions -->
    <div class="glass-panel panel-p15">
        <h3 class="heading-mb1">Quick Actions</h3>
        <div class="quick-actions-col">
            <a href="generate.php" class="glass-btn action-link-center">
                <i class="fa-solid fa-play"></i> Generate Schedule
            </a>
            
            <a href="ai_analytics.php" class="glass-btn secondary action-link-center">
                <i class="fa-solid fa-chart-bar"></i> AI Analytics
            </a>
            
            <a href="courses.php" class="glass-btn secondary action-link-center">
                Manage Courses
            </a>
            
            <a href="users.php" class="glass-btn secondary action-link-center">
                System Users
            </a>
        </div>
    </div>
</div>

<!-- Include Unified API client (with Safari-compatible proxy fallback) -->
<script src="config.js?v=<?php echo filemtime(__DIR__ . '/config.js'); ?>"></script>

<script>
let roomChartInstance = null;
const selectedScheduleId = <?php echo (int)$selected_schedule_id; ?>;
let currentRoomChartEntries = [];

async function loadHighestAIAccuracy() {
    const valueEl = document.getElementById('aiAccuracyValue');
    const hintEl = document.getElementById('aiAccuracyHint');
    if (!valueEl || !hintEl) return;

    try {
        // Fetch from the AI analytics API
        const response = await fetch('api/get_ai_analytics.php', {
            credentials: 'include'
        });
        
        if (!response.ok) {
            throw new Error('Failed to fetch AI analytics');
        }
        
        const data = await response.json();
        
        if (data.status === 'success' && data.metrics && data.metrics.avg_accuracy !== undefined) {
            const avgAccuracy = Number(data.metrics.avg_accuracy);
            
            if (!Number.isNaN(avgAccuracy)) {
                valueEl.textContent = `${Math.round(Math.max(0, Math.min(100, avgAccuracy)))}%`;
                hintEl.textContent = 'Average AI schedule quality';
                return;
            }
        }
        
        // Fallback if no data
        valueEl.textContent = '0%';
        hintEl.textContent = 'No analytics data available yet';
        
    } catch (e) {
        valueEl.textContent = '--%';
        hintEl.textContent = 'Analytics unavailable right now';
        console.warn('Could not load AI accuracy:', e.message || e);
    }
}

function getCssVar(name, fallback) {
    const value = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    return value || fallback;
}

function buildRoomUtilizationSeries(roomDetails) {
    const entries = Object.entries(roomDetails || {})
        .map(([roomName, detail]) => ({
            room: roomName,
            utilization: Number(Number(detail.utilization_percent || 0).toFixed(1)),
            courses: Number(detail.courses || 0),
            capacity: Number(detail.capacity || 0),
            avgEnrollment: Number(detail.avg_enrollment || 0),
            status: detail.status || 'Unknown',
            hoursUsed: Number(detail.hours_used || 0),
            daysUsed: Number(detail.days_used || 0),
            efficiency: Number(Number(detail.efficiency_percent || 0).toFixed(1))
        }))
        .filter((item) => item.room && !Number.isNaN(item.utilization))
        .sort((a, b) => b.utilization - a.utilization);

    return {
        labels: entries.map((item) => item.room),
        values: entries.map((item) => item.utilization),
        entries
    };
}

function renderRoomChartDetails(selectedRoom) {
    const detailsEl = document.getElementById('roomChartDetails');
    if (!detailsEl) return;

    if (!selectedRoom) {
        detailsEl.innerHTML = '<div class="room-chart-empty">Click a bar to inspect a room</div>';
        return;
    }

    const pct = selectedRoom.utilization;
    const pctClass = pct >= 70 ? 'high' : pct >= 40 ? 'medium' : 'low';

    detailsEl.innerHTML = `
        <div class="rdc-strip">
            <div class="rdc-label">
                <span class="rdc-label-name">${selectedRoom.room}</span>
                <span class="rdc-label-pct ${pctClass}">${pct}% utilized</span>
            </div>
            <div class="rdc-stats">
                <div class="rdc-stat"><b>${selectedRoom.courses}</b><small>sessions</small></div>
                <div class="rdc-stat"><b>${selectedRoom.capacity || '&mdash;'}</b><small>capacity</small></div>
                <div class="rdc-stat"><b>${selectedRoom.avgEnrollment}</b><small>avg enroll</small></div>
                <div class="rdc-stat"><b>${selectedRoom.efficiency}%</b><small>efficiency</small></div>
                <div class="rdc-stat"><b>${selectedRoom.daysUsed}</b><small>days</small></div>
                <div class="rdc-stat"><b>${selectedRoom.hoursUsed}</b><small>slots</small></div>
            </div>
        </div>
    `;
}

function selectRoomByIndex(index) {
    const selected = currentRoomChartEntries[index] || null;
    renderRoomChartDetails(selected);
}

async function loadRoomUtilizationChart() {
    const canvas = document.getElementById('roomChart');
    const hintEl = document.getElementById('roomChartHint');
    const detailsEl = document.getElementById('roomChartDetails');
    if (!canvas) return;

    const ctx = canvas.getContext('2d');
    const formData = new FormData();
    formData.append('action', 'analyze');
    if (selectedScheduleId > 0) {
        formData.append('schedule_id', String(selectedScheduleId));
    }

    try {
        const response = await fetch('api/get_room_utilization.php', {
            method: 'POST',
            body: formData,
            credentials: 'include'
        });

        if (!response.ok) {
            throw new Error('Failed to fetch room utilization data');
        }

        const payload = await response.json();
        if (!payload.success || !payload.analysis) {
            throw new Error(payload.error || 'Room utilization data unavailable');
        }

        const chartData = buildRoomUtilizationSeries(payload.analysis.room_details || {});
        if (!chartData.values.length) {
            throw new Error('No room utilization data available yet');
        }
        currentRoomChartEntries = chartData.entries;

        const primaryRgb = getCssVar('--primary-rgb', '37, 99, 235');
        const secondaryRgb = getCssVar('--site-secondary-rgb', '63, 131, 248');
        const primaryColor = getCssVar('--primary-color', '#2563eb');
        const secondaryColor = getCssVar('--secondary-color', '#3f83f8');
        const textMuted = getCssVar('--text-muted', '#94a3b8');
        const textMain = getCssVar('--text-main', '#f8fafc');

        if (roomChartInstance) {
            roomChartInstance.destroy();
        }

        roomChartInstance = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: chartData.labels,
                datasets: [{
                    label: 'Room Utilization (%)',
                    data: chartData.values,
                    backgroundColor: chartData.labels.map((_, index) =>
                        index % 2 === 0
                            ? `rgba(${primaryRgb}, 0.65)`
                            : `rgba(${secondaryRgb}, 0.65)`
                    ),
                    borderColor: chartData.labels.map((_, index) =>
                        index % 2 === 0 ? primaryColor : secondaryColor
                    ),
                    borderWidth: 1,
                    borderRadius: 6,
                    maxBarThickness: 42
                }]
            },
            options: {
                onClick: (_, elements) => {
                    if (!elements || !elements.length) {
                        return;
                    }
                    selectRoomByIndex(elements[0].index);
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(255, 255, 255, 0.1)' },
                        ticks: { color: textMuted },
                        title: {
                            display: true,
                            text: 'Utilization Percentage',
                            color: textMuted
                        },
                        suggestedMax: 100
                    },
                    x: {
                        grid: { display: false },
                        ticks: { color: textMuted },
                        title: {
                            display: true,
                            text: 'Rooms',
                            color: textMuted
                        }
                    }
                },
                plugins: {
                    legend: { labels: { color: textMain } },
                    tooltip: {
                        callbacks: {
                            label: (context) => `${context.parsed.y}% utilized`,
                            afterBody: (items) => {
                                const room = chartData.entries[items[0]?.dataIndex ?? -1];
                                if (!room) {
                                    return [];
                                }
                                return [
                                    `${room.courses} course(s) used this room`,
                                    `Capacity: ${room.capacity || 'N/A'}`,
                                    `Avg enrollment: ${room.avgEnrollment}`
                                ];
                            }
                        }
                    }
                },
                responsive: true,
                maintainAspectRatio: false
            }
        });

        if (hintEl) {
            const overall = Number(payload.analysis.overall_utilization || 0).toFixed(1);
            const source = payload.analysis.source || {};
            const sourceName = source.schedule_name || 'Latest saved schedule';
            const sourceTime = source.created_at || 'Unknown time';
            hintEl.className = 'room-chart-footer';
            hintEl.innerHTML = `
                <span class="rcf-source">${sourceName} &middot; ${sourceTime}</span>
                <span class="rcf-badge">${overall}% overall utilization</span>
                <span class="rcf-hint">Click a bar to inspect</span>
            `;
        }
        selectRoomByIndex(0);
    } catch (e) {
        if (roomChartInstance) {
            roomChartInstance.destroy();
            roomChartInstance = null;
        }
        currentRoomChartEntries = [];
        if (detailsEl) {
            detailsEl.innerHTML = '<div class="room-chart-empty">No room breakdown available right now.</div>';
        }

        roomChartInstance = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: ['No data'],
                datasets: [{
                    label: 'Room Utilization (%)',
                    data: [0],
                    backgroundColor: ['rgba(148, 163, 184, 0.35)'],
                    borderColor: ['rgba(148, 163, 184, 0.7)'],
                    borderWidth: 1,
                    borderRadius: 6,
                    maxBarThickness: 42
                }]
            },
            options: {
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(255, 255, 255, 0.1)' },
                        ticks: { color: '#94a3b8' }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { color: '#94a3b8' }
                    }
                },
                plugins: {
                    legend: { labels: { color: '#f8fafc' } }
                },
                responsive: true,
                maintainAspectRatio: false
            }
        });

        if (hintEl) {
            hintEl.textContent = e.message || 'Could not load room utilization data right now';
        }
        console.warn('Could not load room utilization chart:', e.message || e);
    }
}

document.addEventListener('DOMContentLoaded', function() {
    loadHighestAIAccuracy();
    loadRoomUtilizationChart();
});
</script>

<?php include 'includes/footer.php'; ?>
