<?php
/**
 * Personal Priorities & Goals API
 * Manages user priorities, goals, and time allocation preferences
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
$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    $payload = [];
}
$action = $_GET['action'] ?? $_POST['action'] ?? ($payload['action'] ?? 'list_priorities');

try {
    switch ($action) {
        case 'list_priorities':
            echo json_encode(listPriorities($user_id, $conn));
            break;
        
        case 'add_priority':
            $data = !empty($payload) ? $payload : $_POST;
            echo json_encode(addPriority($user_id, $data, $conn));
            break;
        
        case 'update_priority':
            $data = !empty($payload) ? $payload : $_POST;
            echo json_encode(updatePriority($user_id, $data, $conn));
            break;
        
        case 'delete_priority':
            $id = $_GET['id'] ?? $_POST['id'] ?? 0;
            echo json_encode(deletePriority($user_id, $id, $conn));
            break;
        
        case 'list_goals':
            echo json_encode(listGoals($user_id, $conn));
            break;
        
        case 'add_goal':
            $data = !empty($payload) ? $payload : $_POST;
            echo json_encode(addGoal($user_id, $data, $conn));
            break;
        
        case 'update_goal':
            $data = !empty($payload) ? $payload : $_POST;
            echo json_encode(updateGoal($user_id, $data, $conn));
            break;
        
        case 'update_goal_progress':
            $id = $_POST['id'] ?? 0;
            $progress = $_POST['progress'] ?? 0;
            echo json_encode(updateGoalProgress($user_id, $id, $progress, $conn));
            break;
        
        case 'delete_goal':
            $id = $_GET['id'] ?? $_POST['id'] ?? 0;
            echo json_encode(deleteGoal($user_id, $id, $conn));
            break;
        
        case 'get_priority_summary':
            echo json_encode(getPrioritySummary($user_id, $conn));
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
// PRIORITY FUNCTIONS
// ============================================================================

function listPriorities($user_id, $conn) {
    $stmt = $conn->prepare("
        SELECT * FROM user_priorities 
        WHERE user_id = ? AND is_active = TRUE
        ORDER BY 
            FIELD(priority_level, 'high', 'medium', 'low'),
            created_at DESC
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $priorities = [];
    while ($row = $result->fetch_assoc()) {
        $priorities[] = $row;
    }
    
    return ['success' => true, 'priorities' => $priorities];
}

function addPriority($user_id, $data, $conn) {
    $required = ['priority_name', 'priority_level', 'category'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            return ['success' => false, 'error' => "Missing required field: $field"];
        }
    }
    
    $stmt = $conn->prepare("
        INSERT INTO user_priorities 
        (user_id, priority_name, priority_level, category, description, target_hours_per_week)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    
    $description = $data['description'] ?? '';
    $target_hours = $data['target_hours_per_week'] ?? 0;
    if ($target_hours === '' || $target_hours === null) {
        $target_hours = 0;
    }
    
    $stmt->bind_param("issssd", 
        $user_id, 
        $data['priority_name'], 
        $data['priority_level'], 
        $data['category'],
        $description,
        $target_hours
    );
    
    if ($stmt->execute()) {
        return [
            'success' => true, 
            'message' => 'Priority added successfully',
            'id' => $conn->insert_id
        ];
    }
    
    return ['success' => false, 'error' => 'Failed to add priority', 'details' => $stmt->error ?: $conn->error];
}

function updatePriority($user_id, $data, $conn) {
    if (empty($data['id'])) {
        return ['success' => false, 'error' => 'Priority ID required'];
    }
    
    $stmt = $conn->prepare("
        UPDATE user_priorities 
        SET priority_name = ?, 
            priority_level = ?, 
            category = ?,
            description = ?,
            target_hours_per_week = ?
        WHERE id = ? AND user_id = ?
    ");
    
    $stmt->bind_param("ssssdii",
        $data['priority_name'],
        $data['priority_level'],
        $data['category'],
        $data['description'] ?? '',
        $data['target_hours_per_week'] ?? 0,
        $data['id'],
        $user_id
    );
    
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        return ['success' => true, 'message' => 'Priority updated successfully'];
    }
    
    return ['success' => false, 'error' => 'Failed to update priority'];
}

function deletePriority($user_id, $id, $conn) {
    $stmt = $conn->prepare("
        UPDATE user_priorities 
        SET is_active = FALSE 
        WHERE id = ? AND user_id = ?
    ");
    $stmt->bind_param("ii", $id, $user_id);
    
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        return ['success' => true, 'message' => 'Priority deleted successfully'];
    }
    
    return ['success' => false, 'error' => 'Failed to delete priority'];
}

// ============================================================================
// GOAL FUNCTIONS
// ============================================================================

function listGoals($user_id, $conn) {
    $status = $_GET['status'] ?? 'active';
    
    $stmt = $conn->prepare("
        SELECT * FROM user_goals 
        WHERE user_id = ? AND status = ?
        ORDER BY 
            FIELD(priority_level, 'high', 'medium', 'low'),
            target_completion_date ASC,
            created_at DESC
    ");
    $stmt->bind_param("is", $user_id, $status);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $goals = [];
    while ($row = $result->fetch_assoc()) {
        $goals[] = $row;
    }
    
    return ['success' => true, 'goals' => $goals];
}

function addGoal($user_id, $data, $conn) {
    $required = ['goal_title', 'category'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            return ['success' => false, 'error' => "Missing required field: $field"];
        }
    }
    
    $stmt = $conn->prepare("
        INSERT INTO user_goals 
        (user_id, goal_title, description, category, target_completion_date, priority_level)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    
    $description = $data['description'] ?? '';
    $target_date = $data['target_completion_date'] ?? null;
    if ($target_date === '') {
        $target_date = null;
    }
    $priority = $data['priority_level'] ?? 'medium';
    
    $stmt->bind_param("isssss", 
        $user_id, 
        $data['goal_title'], 
        $description,
        $data['category'],
        $target_date,
        $priority
    );
    
    if ($stmt->execute()) {
        return [
            'success' => true, 
            'message' => 'Goal added successfully',
            'id' => $conn->insert_id
        ];
    }
    
    return ['success' => false, 'error' => 'Failed to add goal', 'details' => $stmt->error ?: $conn->error];
}

function updateGoal($user_id, $data, $conn) {
    if (empty($data['id'])) {
        return ['success' => false, 'error' => 'Goal ID required'];
    }
    
    $stmt = $conn->prepare("
        UPDATE user_goals 
        SET goal_title = ?, 
            description = ?,
            category = ?,
            target_completion_date = ?,
            priority_level = ?,
            status = ?
        WHERE id = ? AND user_id = ?
    ");
    
    $stmt->bind_param("ssssssii",
        $data['goal_title'],
        $data['description'] ?? '',
        $data['category'],
        $data['target_completion_date'] ?? null,
        $data['priority_level'] ?? 'medium',
        $data['status'] ?? 'active',
        $data['id'],
        $user_id
    );
    
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        return ['success' => true, 'message' => 'Goal updated successfully'];
    }
    
    return ['success' => false, 'error' => 'Failed to update goal'];
}

function updateGoalProgress($user_id, $id, $progress, $conn) {
    $progress = max(0, min(100, intval($progress)));
    
    $stmt = $conn->prepare("
        UPDATE user_goals 
        SET progress_percentage = ?,
            status = CASE 
                WHEN ? >= 100 THEN 'completed'
                ELSE status 
            END
        WHERE id = ? AND user_id = ?
    ");
    $stmt->bind_param("iiii", $progress, $progress, $id, $user_id);
    
    if ($stmt->execute()) {
        return ['success' => true, 'message' => 'Progress updated successfully'];
    }
    
    return ['success' => false, 'error' => 'Failed to update progress'];
}

function deleteGoal($user_id, $id, $conn) {
    $stmt = $conn->prepare("
        UPDATE user_goals 
        SET status = 'cancelled' 
        WHERE id = ? AND user_id = ?
    ");
    $stmt->bind_param("ii", $id, $user_id);
    
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        return ['success' => true, 'message' => 'Goal deleted successfully'];
    }
    
    return ['success' => false, 'error' => 'Failed to delete goal'];
}

// ============================================================================
// SUMMARY FUNCTIONS
// ============================================================================

function getPrioritySummary($user_id, $conn) {
    // Get priority counts by level
    $stmt = $conn->prepare("
        SELECT priority_level, COUNT(*) as count, SUM(target_hours_per_week) as total_hours
        FROM user_priorities
        WHERE user_id = ? AND is_active = TRUE
        GROUP BY priority_level
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $priority_summary = [];
    while ($row = $result->fetch_assoc()) {
        $priority_summary[$row['priority_level']] = [
            'count' => $row['count'],
            'total_hours' => floatval($row['total_hours'])
        ];
    }
    
    // Get goal statistics
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_goals,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_goals,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_goals,
            AVG(progress_percentage) as avg_progress
        FROM user_goals
        WHERE user_id = ?
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $goal_stats = $stmt->get_result()->fetch_assoc();
    
    return [
        'success' => true,
        'priority_summary' => $priority_summary,
        'goal_statistics' => $goal_stats
    ];
}
