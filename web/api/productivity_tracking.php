<?php
/**
 * Productivity Tracking API
 * Track task completion, quality ratings, and productivity patterns
 */
require_once 'db.php';

header('Content-Type: application/json');

session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

try {
    switch ($action) {
        case 'log_task':
            $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            echo json_encode(logTask($user_id, $data, $conn));
            break;
        
        case 'list':
            $limit = $_GET['limit'] ?? 50;
            echo json_encode(listProductivityLog($user_id, $limit, $conn));
            break;
        
        case 'get_patterns':
            echo json_encode(getProductivityPatterns($user_id, $conn));
            break;
        
        case 'get_heatmap':
            echo json_encode(getProductivityHeatmap($user_id, $conn));
            break;
        
        case 'get_statistics':
            $period = $_GET['period'] ?? 'week'; // day, week, month
            echo json_encode(getProductivityStatistics($user_id, $period, $conn));
            break;
        
        case 'update_metrics':
            echo json_encode(updateProductivityMetrics($user_id, $conn));
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

// ============================================================================
// PRODUCTIVITY TRACKING FUNCTIONS
// ============================================================================

function logTask($user_id, $data, $conn) {
    $required = ['task_name', 'day', 'start_time', 'end_time'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            return ['success' => false, 'error' => "Missing required field: $field"];
        }
    }
    
    // Calculate duration
    $start = strtotime($data['start_time']);
    $end = strtotime($data['end_time']);
    $duration_minutes = ($end - $start) / 60;
    
    // Calculate productivity score
    $quality_rating = $data['quality_rating'] ?? 3;
    $completion_status = $data['completion_status'] ?? 'completed';
    
    $productivity_score = calculateProductivityScore(
        $duration_minutes, 
        $quality_rating, 
        $completion_status
    );
    
    $stmt = $conn->prepare("
        INSERT INTO productivity_log 
        (user_id, task_name, task_category, day, start_time, end_time, 
         duration_minutes, quality_rating, completion_status, productivity_score, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->bind_param("isssssiisds",
        $user_id,
        $data['task_name'],
        $data['task_category'] ?? 'general',
        $data['day'],
        $data['start_time'],
        $data['end_time'],
        $duration_minutes,
        $quality_rating,
        $completion_status,
        $productivity_score,
        $data['notes'] ?? ''
    );
    
    if ($stmt->execute()) {
        // Update metrics asynchronously
        updateProductivityMetrics($user_id, $conn);
        
        return [
            'success' => true, 
            'message' => 'Task logged successfully',
            'id' => $conn->insert_id,
            'productivity_score' => $productivity_score
        ];
    }
    
    return ['success' => false, 'error' => 'Failed to log task'];
}

function calculateProductivityScore($duration_minutes, $quality_rating, $completion_status) {
    // Base score from duration (max 10 points)
    $duration_score = min($duration_minutes / 30, 10);
    
    // Quality multiplier (1-5 scale)
    $quality_multiplier = $quality_rating / 3; // Normalize around 3
    
    // Completion bonus
    $completion_multiplier = 1.0;
    if ($completion_status === 'completed') {
        $completion_multiplier = 1.0;
    } elseif ($completion_status === 'partial') {
        $completion_multiplier = 0.7;
    } else { // skipped
        $completion_multiplier = 0.3;
    }
    
    $score = $duration_score * $quality_multiplier * $completion_multiplier;
    
    return round($score, 2);
}

function listProductivityLog($user_id, $limit, $conn) {
    $stmt = $conn->prepare("
        SELECT * FROM productivity_log
        WHERE user_id = ?
        ORDER BY logged_at DESC
        LIMIT ?
    ");
    $stmt->bind_param("ii", $user_id, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $log = [];
    while ($row = $result->fetch_assoc()) {
        $log[] = $row;
    }
    
    return [
        'success' => true,
        'log' => $log,
        'count' => count($log)
    ];
}

function getProductivityPatterns($user_id, $conn) {
    $stmt = $conn->prepare("
        SELECT 
            day,
            HOUR(start_time) as hour,
            AVG(productivity_score) as avg_score,
            AVG(quality_rating) as avg_quality,
            AVG(duration_minutes) as avg_duration,
            COUNT(*) as frequency,
            SUM(CASE WHEN completion_status = 'completed' THEN 1 ELSE 0 END) as completed_count
        FROM productivity_log
        WHERE user_id = ?
        GROUP BY day, HOUR(start_time)
        ORDER BY day, hour
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $patterns = [];
    while ($row = $result->fetch_assoc()) {
        $patterns[] = [
            'day' => $row['day'],
            'hour' => intval($row['hour']),
            'avg_score' => floatval($row['avg_score']),
            'avg_quality' => floatval($row['avg_quality']),
            'avg_duration' => floatval($row['avg_duration']),
            'frequency' => intval($row['frequency']),
            'completion_rate' => $row['frequency'] > 0 ? 
                floatval($row['completed_count']) / $row['frequency'] : 0
        ];
    }
    
    return [
        'success' => true,
        'patterns' => $patterns
    ];
}

function getProductivityHeatmap($user_id, $conn) {
    $stmt = $conn->prepare("
        SELECT 
            day,
            HOUR(start_time) as hour,
            AVG(productivity_score) as score,
            COUNT(*) as count
        FROM productivity_log
        WHERE user_id = ?
        GROUP BY day, HOUR(start_time)
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    // Initialize 7x24 matrix
    $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    $heatmap = [];
    foreach ($days as $day) {
        $heatmap[$day] = array_fill(0, 24, 0);
    }
    
    // Fill with data
    while ($row = $result->fetch_assoc()) {
        $day = $row['day'];
        $hour = intval($row['hour']);
        $score = floatval($row['score']);
        if (isset($heatmap[$day])) {
            $heatmap[$day][$hour] = $score;
        }
    }
    
    return [
        'success' => true,
        'heatmap' => $heatmap,
        'days' => $days
    ];
}

function getProductivityStatistics($user_id, $period, $conn) {
    // Determine date range
    $date_condition = '';
    switch ($period) {
        case 'day':
            $date_condition = "DATE(logged_at) = CURDATE()";
            break;
        case 'week':
            $date_condition = "logged_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
            break;
        case 'month':
            $date_condition = "logged_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
            break;
        default:
            $date_condition = "1=1";
    }
    
    // Overall statistics
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_tasks,
            SUM(duration_minutes) / 60 as total_hours,
            AVG(productivity_score) as avg_score,
            AVG(quality_rating) as avg_quality,
            SUM(CASE WHEN completion_status = 'completed' THEN 1 ELSE 0 END) as completed_tasks,
            SUM(CASE WHEN completion_status = 'partial' THEN 1 ELSE 0 END) as partial_tasks,
            SUM(CASE WHEN completion_status = 'skipped' THEN 1 ELSE 0 END) as skipped_tasks
        FROM productivity_log
        WHERE user_id = ? AND $date_condition
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $overall = $stmt->get_result()->fetch_assoc();
    
    // Category breakdown
    $stmt = $conn->prepare("
        SELECT 
            task_category,
            COUNT(*) as task_count,
            SUM(duration_minutes) / 60 as hours,
            AVG(productivity_score) as avg_score
        FROM productivity_log
        WHERE user_id = ? AND $date_condition
        GROUP BY task_category
        ORDER BY hours DESC
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $by_category = [];
    while ($row = $result->fetch_assoc()) {
        $by_category[] = [
            'category' => $row['task_category'],
            'task_count' => intval($row['task_count']),
            'hours' => floatval($row['hours']),
            'avg_score' => floatval($row['avg_score'])
        ];
    }
    
    // Most productive day and time
    $stmt = $conn->prepare("
        SELECT day, HOUR(start_time) as hour, AVG(productivity_score) as score
        FROM productivity_log
        WHERE user_id = ? AND $date_condition
        GROUP BY day, HOUR(start_time)
        ORDER BY score DESC
        LIMIT 1
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $best_time = $stmt->get_result()->fetch_assoc();
    
    // Completion rate
    $completion_rate = 0;
    if ($overall['total_tasks'] > 0) {
        $completion_rate = ($overall['completed_tasks'] / $overall['total_tasks']) * 100;
    }
    
    return [
        'success' => true,
        'period' => $period,
        'overall' => [
            'total_tasks' => intval($overall['total_tasks']),
            'total_hours' => round(floatval($overall['total_hours']), 2),
            'avg_productivity_score' => round(floatval($overall['avg_score']), 2),
            'avg_quality_rating' => round(floatval($overall['avg_quality']), 2),
            'completion_rate' => round($completion_rate, 1),
            'completed_tasks' => intval($overall['completed_tasks']),
            'partial_tasks' => intval($overall['partial_tasks']),
            'skipped_tasks' => intval($overall['skipped_tasks'])
        ],
        'by_category' => $by_category,
        'most_productive' => $best_time ? [
            'day' => $best_time['day'],
            'hour' => intval($best_time['hour']),
            'score' => floatval($best_time['score'])
        ] : null
    ];
}

function updateProductivityMetrics($user_id, $conn) {
    // Get date range for last 7 days
    $stmt = $conn->prepare("
        SELECT DISTINCT DATE(logged_at) as metric_date
        FROM productivity_log
        WHERE user_id = ? AND logged_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $dates = [];
    while ($row = $result->fetch_assoc()) {
        $dates[] = $row['metric_date'];
    }
    
    foreach ($dates as $date) {
        // Calculate metrics for this date
        $stmt = $conn->prepare("
            SELECT 
                SUM(duration_minutes) / 60 as total_hours,
                COUNT(*) as total_tasks,
                SUM(CASE WHEN completion_status = 'completed' THEN 1 ELSE 0 END) as completed,
                AVG(quality_rating) as avg_quality
            FROM productivity_log
            WHERE user_id = ? AND DATE(logged_at) = ?
        ");
        $stmt->bind_param("is", $user_id, $date);
        $stmt->execute();
        $metrics = $stmt->get_result()->fetch_assoc();
        
        // Get most productive day and hour
        $stmt = $conn->prepare("
            SELECT day, HOUR(start_time) as hour, AVG(productivity_score) as score
            FROM productivity_log
            WHERE user_id = ? AND DATE(logged_at) = ?
            GROUP BY day, HOUR(start_time)
            ORDER BY score DESC
            LIMIT 1
        ");
        $stmt->bind_param("is", $user_id, $date);
        $stmt->execute();
        $best_time = $stmt->get_result()->fetch_assoc();
        
        // Category breakdown
        $stmt = $conn->prepare("
            SELECT task_category, SUM(duration_minutes) / 60 as hours
            FROM productivity_log
            WHERE user_id = ? AND DATE(logged_at) = ?
            GROUP BY task_category
        ");
        $stmt->bind_param("is", $user_id, $date);
        $stmt->execute();
        $result_cat = $stmt->get_result();
        
        $category_breakdown = [];
        while ($row = $result_cat->fetch_assoc()) {
            $category_breakdown[$row['task_category']] = floatval($row['hours']);
        }
        
        $completion_rate = 0;
        if ($metrics['total_tasks'] > 0) {
            $completion_rate = ($metrics['completed'] / $metrics['total_tasks']) * 100;
        }
        
        // Insert or update metric
        $stmt = $conn->prepare("
            INSERT INTO productivity_metrics 
            (user_id, metric_date, total_productive_hours, task_completion_rate, 
             average_quality_rating, most_productive_day, most_productive_hour, category_breakdown)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                total_productive_hours = VALUES(total_productive_hours),
                task_completion_rate = VALUES(task_completion_rate),
                average_quality_rating = VALUES(average_quality_rating),
                most_productive_day = VALUES(most_productive_day),
                most_productive_hour = VALUES(most_productive_hour),
                category_breakdown = VALUES(category_breakdown)
        ");
        
        $category_json = json_encode($category_breakdown);
        
        $stmt->bind_param("isdddsis",
            $user_id,
            $date,
            $metrics['total_hours'],
            $completion_rate,
            $metrics['avg_quality'],
            $best_time['day'] ?? null,
            $best_time['hour'] ?? null,
            $category_json
        );
        
        $stmt->execute();
    }
    
    return [
        'success' => true,
        'message' => 'Productivity metrics updated',
        'dates_processed' => count($dates)
    ];
}
