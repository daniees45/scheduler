<?php

function ai_learning_has_column(mysqli $conn, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;

    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $tableEsc = $conn->real_escape_string($table);
    $colEsc = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `{$tableEsc}` LIKE '{$colEsc}'");
    $cache[$key] = ($res && $res->num_rows > 0);

    return $cache[$key];
}

function ai_learning_ensure_schema(mysqli $conn): void
{
    $requiredColumns = [
        ['user_settings', 'productivity_pref', "ALTER TABLE user_settings ADD COLUMN productivity_pref ENUM('Detailed', 'Basic', 'Off') DEFAULT 'Detailed' AFTER analytics_opt_in"],
        ['user_settings', 'auto_suggest_free', 'ALTER TABLE user_settings ADD COLUMN auto_suggest_free BOOLEAN DEFAULT TRUE AFTER productivity_pref'],
        ['ai_settings', 'priority_goals_json', 'ALTER TABLE ai_settings ADD COLUMN priority_goals_json JSON NULL AFTER max_daily_workload'],
    ];

    foreach ($requiredColumns as [$table, $column, $sql]) {
        if (!ai_learning_has_column($conn, $table, $column)) {
            @$conn->query($sql);
        }
    }
}

function ai_learning_fetch_user_snapshot(mysqli $conn, int $user_id): array
{
    ai_learning_ensure_schema($conn);

    $snapshot = [
        'user_id' => $user_id,
        'updated_at' => date('c'),
        'preferences' => [],
        'priorities' => [],
        'goals' => [],
    ];

    $stmt = $conn->prepare("SELECT * FROM ai_settings WHERE user_id = ? LIMIT 1");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $aiSettings = $stmt->get_result()->fetch_assoc() ?: [];

    $stmt = $conn->prepare("SELECT * FROM user_settings WHERE user_id = ? LIMIT 1");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $userSettings = $stmt->get_result()->fetch_assoc() ?: [];

    $stmt = $conn->prepare("SELECT priority_name, priority_level, category, description, target_hours_per_week, is_active FROM user_priorities WHERE user_id = ? AND is_active = TRUE ORDER BY FIELD(priority_level, 'high', 'medium', 'low'), created_at DESC LIMIT 20");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $priorityResult = $stmt->get_result();
    while ($row = $priorityResult->fetch_assoc()) {
        $snapshot['priorities'][] = $row;
    }

    $stmt = $conn->prepare("SELECT goal_title, description, category, target_completion_date, status, priority_level, progress_percentage FROM user_goals WHERE user_id = ? ORDER BY FIELD(priority_level, 'high', 'medium', 'low'), target_completion_date ASC, created_at DESC LIMIT 20");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $goalResult = $stmt->get_result();
    while ($row = $goalResult->fetch_assoc()) {
        $snapshot['goals'][] = $row;
    }

    $snapshot['preferences'] = [
        'suggestion_intensity' => $aiSettings['suggestion_intensity'] ?? 'Medium',
        'preferred_work_start' => $aiSettings['preferred_work_start'] ?? null,
        'preferred_work_end' => $aiSettings['preferred_work_end'] ?? null,
        'max_daily_workload' => isset($aiSettings['max_daily_workload']) ? (int)$aiSettings['max_daily_workload'] : null,
        'accept_learning_toggle' => isset($aiSettings['accept_learning_toggle']) ? (int)$aiSettings['accept_learning_toggle'] : 1,
        'productivity_pref' => $userSettings['productivity_pref'] ?? 'Detailed',
        'auto_suggest_free' => isset($userSettings['auto_suggest_free']) ? (int)$userSettings['auto_suggest_free'] : 1,
        'profile_visibility' => $userSettings['profile_visibility'] ?? 'faculty',
        'analytics_opt_in' => isset($userSettings['analytics_opt_in']) ? (int)$userSettings['analytics_opt_in'] : 1,
    ];

    $snapshot['summary'] = [
        'priority_count' => count($snapshot['priorities']),
        'goal_count' => count($snapshot['goals']),
        'priority_hours' => array_reduce($snapshot['priorities'], static function ($carry, $row) {
            return $carry + (float)($row['target_hours_per_week'] ?? 0);
        }, 0.0),
        'completed_goals' => count(array_filter($snapshot['goals'], static function ($goal) {
            return ($goal['status'] ?? '') === 'completed';
        })),
    ];

    return $snapshot;
}

function ai_learning_sync_user_profile(mysqli $conn, int $user_id): bool
{
    ai_learning_ensure_schema($conn);
    $snapshot = ai_learning_fetch_user_snapshot($conn, $user_id);
    $snapshotJson = json_encode($snapshot, JSON_UNESCAPED_SLASHES);

    $stmt = $conn->prepare("INSERT IGNORE INTO ai_settings (user_id) VALUES (?)");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();

    $stmt = $conn->prepare("UPDATE ai_settings SET priority_goals_json = ? WHERE user_id = ?");
    $stmt->bind_param('si', $snapshotJson, $user_id);
    return $stmt->execute();
}
