<?php
/**
 * AI Scheduler Analytics Dashboard
 * 
 * Displays:
 * - Schedule quality metrics
 * - Room utilization analysis
 * - Lecturer workload distribution
 * - Time slot usage patterns
 * - Version comparison and history
 * - Actionable recommendations
 */

session_start();
require_once 'api/db.php';

// Role-based access
if (!isset($_SESSION['role']) || (isset($_SESSION['role']) && $_SESSION['role'] !== 'admin')) {
    echo "<script>alert('Access denied. Admin privileges required.'); window.location.href='index.php';</script>";
    exit;
}

$analytics_data = [
    'metrics' => null,
    'room_util' => null,
    'lecturer_load' => null,
    'time_dist' => null,
    'versions' => null,
    'errors' => []
];

// Fetch all analytics data
$ch = curl_init();
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);

// Get schedule metrics
curl_setopt($ch, CURLOPT_URL, 'http://localhost' . dirname($_SERVER['PHP_SELF']) . '/api/get_schedule_metrics.php');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, 'action=analyze');
$response = curl_exec($ch);
if ($response) {
    $analytics_data['metrics'] = json_decode($response, true);
}

// Get room utilization
curl_setopt($ch, CURLOPT_URL, 'http://localhost' . dirname($_SERVER['PHP_SELF']) . '/api/get_room_utilization.php');
$response = curl_exec($ch);
if ($response) {
    $analytics_data['room_util'] = json_decode($response, true);
}

// Get lecturer load
curl_setopt($ch, CURLOPT_URL, 'http://localhost' . dirname($_SERVER['PHP_SELF']) . '/api/get_lecturer_load.php');
$response = curl_exec($ch);
if ($response) {
    $analytics_data['lecturer_load'] = json_decode($response, true);
}

// Get time distribution
curl_setopt($ch, CURLOPT_URL, 'http://localhost' . dirname($_SERVER['PHP_SELF']) . '/api/get_time_distribution.php');
$response = curl_exec($ch);
if ($response) {
    $analytics_data['time_dist'] = json_decode($response, true);
}

// Get available versions
curl_setopt($ch, CURLOPT_URL, 'http://localhost' . dirname($_SERVER['PHP_SELF']) . '/api/compare_versions.php');
curl_setopt($ch, CURLOPT_POSTFIELDS, 'action=get_versions');
$response = curl_exec($ch);
if ($response) {
    $analytics_data['versions'] = json_decode($response, true);
}

curl_close($ch);

// Helper functions for display
function get_score_color($score) {
    if ($score >= 80) return '#28a745';
    if ($score >= 60) return '#ffc107';
    return '#dc3545';
}

function get_status_badge($score) {
    if ($score >= 80) return '<span class="badge badge-success">Good</span>';
    if ($score >= 60) return '<span class="badge badge-warning">Fair</span>';
    return '<span class="badge badge-danger">Poor</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics Dashboard - AI Scheduler</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    <style>
        body {
            background-color: #f5f7fa;
            padding: 20px;
        }
        .card {
            border: none;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 8px 8px 0 0;
            padding: 15px;
        }
        .metric-box {
            padding: 15px;
            text-align: center;
            background: #f8f9fa;
            border-radius: 5px;
            margin: 10px 0;
        }
        .metric-value {
            font-size: 28px;
            font-weight: bold;
            color: #667eea;
        }
        .metric-label {
            font-size: 12px;
            color: #6c757d;
            text-transform: uppercase;
            margin-top: 5px;
        }
        .chart-container {
            position: relative;
            height: 300px;
            margin: 20px 0;
        }
        .alert-concern {
            background-color: #fff3cd;
            border-color: #ffc107;
            color: #856404;
        }
        .alert-recommendation {
            background-color: #d1ecf1;
            border-color: #17a2b8;
            color: #0c5460;
        }
        .concern-item {
            padding: 10px;
            margin: 8px 0;
            border-left: 4px solid #ffc107;
            background: #fff8e1;
        }
        .recommendation-item {
            padding: 10px;
            margin: 8px 0;
            border-left: 4px solid #17a2b8;
            background: #e1f5fe;
        }
        .lecturer-bar {
            margin: 8px 0;
            padding: 8px;
            background: #f8f9fa;
            border-radius: 4px;
        }
        .progress {
            height: 8px;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <!-- Header -->
        <div class="row mb-4">
            <div class="col-md-8">
                <h1><i class="fas fa-chart-line"></i> Analytics Dashboard</h1>
                <p class="text-muted">AI Scheduler Quality & Performance Metrics</p>
            </div>
            <div class="col-md-4 text-right">
                <a href="index.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>
            </div>
        </div>

        <!-- Quality Score Overview -->
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <h4 class="mb-0"><i class="fas fa-chart-pie"></i> Schedule Quality Overview</h4>
                    </div>
                    <div class="card-body">
                        <?php if ($analytics_data['metrics'] && $analytics_data['metrics']['success']): 
                            $metrics = $analytics_data['metrics']['analysis'];
                            $quality = $metrics['quality_score'] ?? 0;
                        ?>
                        <div class="row">
                            <div class="col-md-3 metric-box">
                                <div class="metric-value" style="color: <?php echo get_score_color($quality); ?>">
                                    <?php echo $quality; ?>%
                                </div>
                                <div class="metric-label">Overall Quality Score</div>
                                <?php echo get_status_badge($quality); ?>
                            </div>
                            <div class="col-md-3 metric-box">
                                <div class="metric-value"><?php echo $metrics['statistics']['total_courses'] ?? 0; ?></div>
                                <div class="metric-label">Courses Scheduled</div>
                            </div>
                            <div class="col-md-3 metric-box">
                                <div class="metric-value"><?php echo $metrics['statistics']['conflict_count'] ?? 0; ?></div>
                                <div class="metric-label">Conflicts Detected</div>
                            </div>
                            <div class="col-md-3 metric-box">
                                <div class="metric-value">
                                    <?php 
                                    $success_rate = $metrics['statistics']['success_rate'] ?? 0;
                                    echo round($success_rate * 100, 1); 
                                    ?>%
                                </div>
                                <div class="metric-label">Success Rate</div>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="alert alert-info">No schedule metrics available yet. Generate a schedule first.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts Row 1 -->
        <div class="row">
            <!-- Daily Distribution Chart -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-calendar"></i> Courses by Day</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="dayChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Hourly Distribution Chart -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-clock"></i> Courses by Hour</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="hourChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts Row 2 -->
        <div class="row">
            <!-- Room Utilization -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-door-open"></i> Top Room Utilization</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($analytics_data['room_util'] && $analytics_data['room_util']['success']): 
                            $rooms = $analytics_data['room_util']['analysis']['room_details'] ?? [];
                            $rooms = array_slice($rooms, 0, 5);
                        ?>
                            <?php foreach ($rooms as $room): ?>
                            <div class="lecturer-bar">
                                <small><?php echo htmlspecialchars($room['room_name']); ?></small>
                                <div class="progress">
                                    <div class="progress-bar" style="width: <?php echo $room['utilization_percent']; ?>%">
                                        <?php echo round($room['utilization_percent'], 1); ?>%
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                        <div class="alert alert-info">Room utilization data not available.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Lecturer Workload -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-users"></i> Lecturer Workload Balance</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($analytics_data['lecturer_load'] && $analytics_data['lecturer_load']['success']): 
                            $lecturers = array_slice($analytics_data['lecturer_load']['analysis']['lecturer_details'] ?? [], 0, 5);
                            $balance = $analytics_data['lecturer_load']['analysis']['overall_balance_score'] ?? 0;
                        ?>
                        <div class="metric-box mb-3">
                            <div class="metric-value" style="color: <?php echo get_score_color($balance); ?>">
                                <?php echo round($balance, 1); ?>%
                            </div>
                            <div class="metric-label">Balance Score</div>
                        </div>
                        
                        <?php foreach ($lecturers as $lecturer => $data): ?>
                        <div class="lecturer-bar">
                            <small><?php echo htmlspecialchars($lecturer); ?></small>
                            <div class="progress">
                                <div class="progress-bar 
                                    <?php if ($data['workload_score'] >= 80) echo 'bg-success';
                                          elseif ($data['workload_score'] >= 60) echo 'bg-warning';
                                          else echo 'bg-danger'; ?>"
                                    style="width: <?php echo $data['workload_score']; ?>%">
                                    <?php echo $data['courses']; ?> courses
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <div class="alert alert-info">Lecturer load data not available.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Concerns & Recommendations -->
        <div class="row">
            <!-- Concerns -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                        <h5 class="mb-0"><i class="fas fa-exclamation-triangle"></i> Issues & Concerns</h5>
                    </div>
                    <div class="card-body">
                        <?php 
                        $all_concerns = [];
                        if ($analytics_data['metrics'] && $analytics_data['metrics']['success']) {
                            $all_concerns = array_merge($all_concerns, 
                                $analytics_data['metrics']['analysis']['concerns'] ?? []);
                        }
                        if ($analytics_data['room_util'] && $analytics_data['room_util']['success']) {
                            $all_concerns = array_merge($all_concerns, 
                                $analytics_data['room_util']['analysis']['concerns'] ?? []);
                        }
                        if ($analytics_data['lecturer_load'] && $analytics_data['lecturer_load']['success']) {
                            $all_concerns = array_merge($all_concerns, 
                                $analytics_data['lecturer_load']['analysis']['concerns'] ?? []);
                        }
                        if ($analytics_data['time_dist'] && $analytics_data['time_dist']['success']) {
                            $all_concerns = array_merge($all_concerns, 
                                $analytics_data['time_dist']['analysis']['concerns'] ?? []);
                        }
                        
                        if (empty($all_concerns)): ?>
                            <div class="alert alert-success">No concerns detected. Schedule looks good!</div>
                        <?php else: ?>
                            <?php foreach ($all_concerns as $concern): ?>
                            <div class="concern-item">
                                <strong><?php echo htmlspecialchars($concern['type'] ?? 'Issue'); ?></strong>
                                <small class="d-block text-muted">
                                    <?php echo htmlspecialchars($concern['detail'] ?? $concern['lecturer'] ?? ''); ?>
                                </small>
                                <?php if (isset($concern['severity'])): ?>
                                <span class="badge 
                                    <?php if ($concern['severity'] === 'high') echo 'badge-danger';
                                          elseif ($concern['severity'] === 'medium') echo 'badge-warning';
                                          else echo 'badge-info'; ?>">
                                    <?php echo ucfirst($concern['severity']); ?>
                                </span>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Recommendations -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                        <h5 class="mb-0"><i class="fas fa-lightbulb"></i> Recommendations</h5>
                    </div>
                    <div class="card-body">
                        <?php 
                        $all_recs = [];
                        if ($analytics_data['metrics'] && $analytics_data['metrics']['success']) {
                            $all_recs = array_merge($all_recs, 
                                $analytics_data['metrics']['analysis']['recommendations'] ?? []);
                        }
                        if ($analytics_data['room_util'] && $analytics_data['room_util']['success']) {
                            $all_recs = array_merge($all_recs, 
                                $analytics_data['room_util']['analysis']['recommendations'] ?? []);
                        }
                        if ($analytics_data['lecturer_load'] && $analytics_data['lecturer_load']['success']) {
                            $all_recs = array_merge($all_recs, 
                                $analytics_data['lecturer_load']['analysis']['recommendations'] ?? []);
                        }
                        if ($analytics_data['time_dist'] && $analytics_data['time_dist']['success']) {
                            $all_recs = array_merge($all_recs, 
                                $analytics_data['time_dist']['analysis']['recommendations'] ?? []);
                        }
                        
                        if (empty($all_recs)): ?>
                            <div class="alert alert-info">No specific recommendations at this time.</div>
                        <?php else: ?>
                            <?php foreach ($all_recs as $rec): ?>
                            <div class="recommendation-item">
                                <strong><?php echo htmlspecialchars($rec['suggestion'] ?? 'Recommendation'); ?></strong>
                                <small class="d-block text-muted mt-1">
                                    <?php echo htmlspecialchars($rec['benefit'] ?? $rec['impact'] ?? ''); ?>
                                </small>
                                <?php if (isset($rec['priority'])): ?>
                                <span class="badge 
                                    <?php if ($rec['priority'] === 'high') echo 'badge-danger';
                                          elseif ($rec['priority'] === 'medium') echo 'badge-warning';
                                          else echo 'badge-info'; ?>">
                                    Priority: <?php echo ucfirst($rec['priority']); ?>
                                </span>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Schedule Details -->
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-info-circle"></i> Additional Details</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3">
                                <h6>Time Distribution</h6>
                                <?php if ($analytics_data['time_dist'] && $analytics_data['time_dist']['success']): 
                                    $td = $analytics_data['time_dist']['analysis'];
                                ?>
                                    <small class="text-muted">
                                        Compression: <strong><?php echo $td['compression_ratio'] ?? 'N/A'; ?></strong><br>
                                        Days Used: <strong><?php echo count($td['days_in_use'] ?? []); ?>/5</strong><br>
                                        Hours Used: <strong><?php echo count($td['hours_in_use'] ?? []); ?></strong>
                                    </small>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-3">
                                <h6>Distribution Summary</h6>
                                <?php if ($analytics_data['metrics'] && $analytics_data['metrics']['success']): 
                                    $m = $analytics_data['metrics']['analysis'];
                                ?>
                                    <small class="text-muted">
                                        By Day: <strong><?php echo count($m['distribution_by_day'] ?? []); ?></strong><br>
                                        By Room: <strong><?php echo count($m['distribution_by_room'] ?? []); ?></strong><br>
                                        By Lecturer: <strong><?php echo count($m['distribution_by_lecturer'] ?? []); ?></strong>
                                    </small>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-3">
                                <h6>Workload Analysis</h6>
                                <?php if ($analytics_data['lecturer_load'] && $analytics_data['lecturer_load']['success']): 
                                    $ll = $analytics_data['lecturer_load']['analysis']['workload_distribution'] ?? [];
                                ?>
                                    <small class="text-muted">
                                        Balanced: <strong><?php echo $ll['balanced'] ?? 0; ?></strong><br>
                                        Moderate: <strong><?php echo $ll['moderate'] ?? 0; ?></strong><br>
                                        Heavy: <strong><?php echo $ll['heavy'] ?? 0; ?></strong>
                                    </small>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-3">
                                <h6>Room Efficiency</h6>
                                <?php if ($analytics_data['room_util'] && $analytics_data['room_util']['success']): 
                                    $ru = $analytics_data['room_util']['analysis'];
                                ?>
                                    <small class="text-muted">
                                        Rooms Analyzed: <strong><?php echo count($ru['room_details'] ?? []); ?></strong><br>
                                        Avg Utilization: <strong><?php echo round($ru['average_utilization'] ?? 0, 1); ?>%</strong>
                                    </small>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Version History -->
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-history"></i> Schedule Version History</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($analytics_data['versions'] && $analytics_data['versions']['success'] && 
                                  !empty($analytics_data['versions']['versions'])): 
                            $versions = $analytics_data['versions']['versions'];
                        ?>
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Version</th>
                                        <th>Created</th>
                                        <th>Quality Score</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_slice($versions, 0, 10) as $v): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($v['version_name']); ?></td>
                                        <td><?php echo date('M d, Y H:i', strtotime($v['created_at'])); ?></td>
                                        <td>
                                            <?php if ($v['quality_score'] !== null): ?>
                                            <span style="color: <?php echo get_score_color($v['quality_score']); ?>;">
                                                <?php echo round($v['quality_score'], 1); ?>%
                                            </span>
                                            <?php else: ?>
                                            N/A
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="#" class="btn btn-sm btn-info">View</a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="alert alert-info">No schedule versions available yet.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Day Chart
        <?php if ($analytics_data['metrics'] && $analytics_data['metrics']['success']): 
            $daily_dist = $analytics_data['metrics']['analysis']['distribution_by_day'] ?? [];
        ?>
        const dayCtx = document.getElementById('dayChart').getContext('2d');
        new Chart(dayCtx, {
            type: 'bar',
            data: {
                labels: Object.keys(<?php echo json_encode($daily_dist); ?>),
                datasets: [{
                    label: 'Courses',
                    data: Object.values(<?php echo json_encode($daily_dist); ?>),
                    backgroundColor: '#667eea',
                    borderColor: '#667eea',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: { beginAtZero: true }
                }
            }
        });
        <?php endif; ?>

        // Hour Chart
        <?php if ($analytics_data['time_dist'] && $analytics_data['time_dist']['success']): 
            $hourly_dist = $analytics_data['time_dist']['analysis']['hourly_distribution'] ?? [];
        ?>
        const hourCtx = document.getElementById('hourChart').getContext('2d');
        new Chart(hourCtx, {
            type: 'line',
            data: {
                labels: Object.keys(<?php echo json_encode($hourly_dist); ?>).map(h => h + ':00'),
                datasets: [{
                    label: 'Courses',
                    data: Object.values(<?php echo json_encode($hourly_dist); ?>),
                    borderColor: '#764ba2',
                    backgroundColor: 'rgba(118, 75, 162, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: { beginAtZero: true }
                }
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>
