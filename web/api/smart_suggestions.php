<?php
/**
 * Smart Scheduling Suggestions API
 * AI-powered scheduling recommendations based on priorities, productivity patterns, and goals
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
$user_role = $_SESSION['role'] ?? 'student';
$lecturer_id = (int)($_SESSION['lecturer_id'] ?? 0);
$semester = (string)($_SESSION['semester'] ?? '1');
$action = $_GET['action'] ?? $_POST['action'] ?? 'get_suggestions';

try {
    switch ($action) {
        case 'get_suggestions':
            $category = $_GET['category'] ?? 'all';
            echo json_encode(getSmartSuggestions($user_id, $category, $conn, $user_role, $lecturer_id));
            break;
        
        case 'accept_suggestion':
            $suggestion_id = $_POST['suggestion_id'] ?? 0;
            echo json_encode(acceptSuggestion($user_id, $suggestion_id, $conn));
            break;
        
        case 'reject_suggestion':
            $suggestion_id = $_POST['suggestion_id'] ?? 0;
            $reason = $_POST['reason'] ?? '';
            echo json_encode(rejectSuggestion($user_id, $suggestion_id, $reason, $conn));
            break;
        
        case 'generate_suggestions':
            echo json_encode(generateSuggestions($user_id, $conn, $user_role, $lecturer_id));
            break;
        
        case 'get_optimal_times':
            $task_category = $_GET['category'] ?? 'study';
            echo json_encode(getOptimalTimes($user_id, $task_category, $conn));
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
// SMART SUGGESTIONS ENGINE
// ============================================================================

function getSmartSuggestions($user_id, $category, $conn, $user_role, $lecturer_id) {
    // Get pending suggestions
    $sql = "
        SELECT * FROM schedule_suggestions
        WHERE user_id = ? AND status = 'pending'
    ";
    
    if ($category !== 'all') {
        $sql .= " AND suggestion_type = ?";
        $stmt = $conn->prepare($sql . " ORDER BY priority_score DESC, productivity_score DESC LIMIT 15");
        $stmt->bind_param("is", $user_id, $category);
    } else {
        $stmt = $conn->prepare($sql . " ORDER BY priority_score DESC, productivity_score DESC LIMIT 15");
        $stmt->bind_param("i", $user_id);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $suggestions = [];
    while ($row = $result->fetch_assoc()) {
        $suggestions[] = $row;
    }
    
    // If no suggestions exist, generate them
    if (empty($suggestions)) {
        generateSuggestions($user_id, $conn, $user_role, $lecturer_id);
        
        // Re-fetch
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $suggestions[] = $row;
        }
    }
    
    return [
        'success' => true,
        'suggestions' => $suggestions,
        'count' => count($suggestions)
    ];
}

function generateSuggestions($user_id, $conn, $user_role, $lecturer_id) {
    if ($user_role === 'lecturer') {
        return generateLecturerSuggestions($user_id, $conn, $lecturer_id);
    }

    // Clear old pending suggestions
    $stmt = $conn->prepare("DELETE FROM schedule_suggestions WHERE user_id = ? AND status = 'pending'");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    
    // Get user's busy blocks from courses and personal events
    $busy_blocks = getBusyBlocks($user_id, $conn, $user_role, $lecturer_id);
    
    // Get user priorities
    $priorities = getUserPriorities($user_id, $conn);
    
    // Get productivity patterns
    $productivity_patterns = getProductivityPatterns($user_id, $conn);
    
    // Get task preferences (learned)
    $task_preferences = getTaskPreferences($user_id, $conn);
    
    // Find free time slots
    $free_slots = findFreeSlots($busy_blocks);
    
    // Score and rank slots
    $scored_slots = scoreSlots($free_slots, $priorities, $productivity_patterns, $task_preferences);
    
    // Insert top suggestions
    $inserted = 0;
    foreach ($scored_slots as $slot) {
        if ($inserted >= 15) break;
        
        $stmt = $conn->prepare("
            INSERT INTO schedule_suggestions 
            (user_id, suggestion_type, day, start_time, end_time, duration_minutes, 
             priority_score, productivity_score, reason)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->bind_param("issssiids",
            $user_id,
            $slot['type'],
            $slot['day'],
            $slot['start_time'],
            $slot['end_time'],
            $slot['duration'],
            $slot['priority_score'],
            $slot['productivity_score'],
            $slot['reason']
        );
        
        if ($stmt->execute()) {
            $inserted++;
        }
    }
    
    return [
        'success' => true,
        'message' => "Generated $inserted scheduling suggestions",
        'suggestions_count' => $inserted
    ];
}

function getBusyBlocks($user_id, $conn, $user_role, $lecturer_id) {
    $blocks = [];
    
    // Get class blocks based on user role
    if ($user_role === 'lecturer') {
        if ($lecturer_id > 0) {
            $stmt = $conn->prepare("
                SELECT c.course_code, c.course_title, s.assigned_day as day, s.assigned_time as time
                FROM sections s
                JOIN courses c ON s.course_id = c.id
                WHERE s.lecturer_id = ? AND s.assigned_day IS NOT NULL
            ");
            $stmt->bind_param("i", $lecturer_id);
            $stmt->execute();
            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {
                if (preg_match('/(\d{1,2}:\d{2})\s*-\s*(\d{1,2}:\d{2})/', $row['time'], $matches)) {
                    $blocks[] = [
                        'day' => $row['day'],
                        'start' => $matches[1],
                        'end' => $matches[2],
                        'type' => 'course',
                        'label' => $row['course_code']
                    ];
                }
            }
        }
    } else {
        $stmt = $conn->prepare("
            SELECT c.course_code, c.course_title, s.assigned_day as day, s.assigned_time as time
            FROM student_enrollments e
            JOIN courses c ON e.course_id = c.id
            JOIN sections s ON c.id = s.course_id
            WHERE e.user_id = ? AND s.assigned_day IS NOT NULL
        ");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            if (preg_match('/(\d{1,2}:\d{2})\s*-\s*(\d{1,2}:\d{2})/', $row['time'], $matches)) {
                $blocks[] = [
                    'day' => $row['day'],
                    'start' => $matches[1],
                    'end' => $matches[2],
                    'type' => 'course',
                    'label' => $row['course_code']
                ];
            }
        }
    }
    
    // Get personal events
    $stmt = $conn->prepare("
        SELECT day, start_time, end_time, title, event_type
        FROM personal_events
        WHERE user_id = ?
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $blocks[] = [
            'day' => $row['day'],
            'start' => $row['start_time'],
            'end' => $row['end_time'],
            'type' => 'personal',
            'label' => $row['title']
        ];
    }
    
    return $blocks;
}

function generateLecturerSuggestions($user_id, $conn, $lecturer_id) {
    $stmt = $conn->prepare("DELETE FROM schedule_suggestions WHERE user_id = ? AND status = 'pending'");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();

    if ($lecturer_id <= 0) {
        return ['success' => false, 'error' => 'Lecturer profile not linked'];
    }

    $lecturer_name = '';
    $lecturer_department = '';

    $name_stmt = $conn->prepare("SELECT name, department FROM lecturers WHERE id = ?");
    if ($name_stmt) {
        $name_stmt->bind_param('i', $lecturer_id);
        $name_stmt->execute();
        $name_res = $name_stmt->get_result();
        if ($name_res && ($row = $name_res->fetch_assoc())) {
            $lecturer_name = trim((string)($row['name'] ?? ''));
            $lecturer_department = trim((string)($row['department'] ?? ''));
        }
    }

    if ($lecturer_name === '') {
        return ['success' => false, 'error' => 'Lecturer name not found'];
    }

    $get_department_from_code = static function ($course_code): string {
        $code = strtoupper((string)$course_code);
        if (preg_match('/(COSC|INFT|BBIS|CSCD)/', $code)) return 'CS/IT/BBIS';
        if (preg_match('/(ACCT|BUSI|MGMT|ECON|MKTG|FNCE)/', $code)) return 'Business';
        if (preg_match('/(EDUC|PEDC|TEAC|CLED)/', $code)) return 'Education';
        if (preg_match('/(DEVS|INTL|AFRI|AFRN)/', $code)) return 'DevelopmentStudies';
        if (preg_match('/(BIOM|ENGR|BENG|HLTC)/', $code)) return 'BiomedicalEngineering';
        if (preg_match('/(NURS|RNSG|MIDW)/', $code)) return 'Nursing';
        if (preg_match('/(RELB|RELT)/', $code)) return 'Theology';
        return 'General';
    };

    $infer_department_from_codes = static function (array $codes) use ($get_department_from_code): string {
        if (empty($codes)) return '';
        $counts = [];
        foreach ($codes as $code) {
            $dept = $get_department_from_code($code);
            $counts[$dept] = ($counts[$dept] ?? 0) + 1;
        }
        arsort($counts);
        $top = array_key_first($counts);
        return $top ? (string)$top : '';
    };

    if ($lecturer_department === '') {
        $codes = [];
        $code_stmt = $conn->prepare("SELECT DISTINCT c.course_code
                                     FROM sections s
                                     JOIN courses c ON s.course_id = c.id
                                     WHERE s.lecturer_id = ?");
        if ($code_stmt) {
            $code_stmt->bind_param('i', $lecturer_id);
            $code_stmt->execute();
            $code_res = $code_stmt->get_result();
            while ($code_row = $code_res->fetch_assoc()) {
                if (!empty($code_row['course_code'])) {
                    $codes[] = $code_row['course_code'];
                }
            }
        }
        $lecturer_department = $infer_department_from_codes($codes);
    }

    if ($lecturer_department === '') {
        $lecturer_department = 'General';
    }

    $schedule_rows = buildLecturerScheduleRows($conn, $lecturer_name, $lecturer_department);
    $personal_events = getPersonalEvents($user_id, $conn);

    $payload = [
        'role' => 'lecturer',
        'schedule_rows' => $schedule_rows,
        'personal_events' => $personal_events,
        'min_minutes' => 60,
        'day_start' => '07:00',
        'day_end' => '21:00'
    ];

    $python_result = runPythonSuggestions($payload);
    if (empty($python_result['success'])) {
        return ['success' => false, 'error' => $python_result['error'] ?? 'Python suggestion engine failed'];
    }

    $inserted = 0;
    foreach ($python_result['suggestions'] as $suggestion) {
        if ($inserted >= 15) break;
        $stmt = $conn->prepare("
            INSERT INTO schedule_suggestions
            (user_id, suggestion_type, day, start_time, end_time, duration_minutes,
             priority_score, productivity_score, reason)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $type = 'office_hour';
        $priority_score = (float)($suggestion['score'] ?? 0);
        $productivity_score = 0.0;
        $duration = (int)($suggestion['duration_minutes'] ?? 0);
        $reason = (string)($suggestion['reason'] ?? 'Suggested free slot');
        $stmt->bind_param(
            "issssiids",
            $user_id,
            $type,
            $suggestion['day'],
            $suggestion['start_time'],
            $suggestion['end_time'],
            $duration,
            $priority_score,
            $productivity_score,
            $reason
        );
        if ($stmt->execute()) {
            $inserted++;
        }
    }

    return [
        'success' => true,
        'message' => "Generated $inserted scheduling suggestions",
        'suggestions_count' => $inserted
    ];
}

function buildLecturerScheduleRows($conn, $lecturer_name, $lecturer_department) {
    $semester = (string)($_SESSION['semester'] ?? '1');
    $sources = [];

    $dept_stmt = $conn->prepare("SELECT schedule_data FROM generated_schedules
                                 WHERE LOWER(TRIM(department)) = LOWER(TRIM(?))
                                   AND (schedule_name NOT LIKE 'exam_%' OR schedule_name IS NULL)
                                   AND (semester = ? OR semester IS NULL OR TRIM(semester) = '')
                                 ORDER BY created_at DESC LIMIT 1");
    if ($dept_stmt) {
        $dept_stmt->bind_param('ss', $lecturer_department, $semester);
        $dept_stmt->execute();
        $res = $dept_stmt->get_result();
        if ($res && ($row = $res->fetch_assoc())) {
            $sources[] = $row['schedule_data'] ?? '';
        }
    }

    $gen_stmt = $conn->prepare("SELECT schedule_data FROM generated_schedules
                                WHERE (department IS NULL OR TRIM(department) = '' OR LOWER(TRIM(department)) = 'general')
                                  AND (schedule_name NOT LIKE 'exam_%' OR schedule_name IS NULL)
                                  AND (semester = ? OR semester IS NULL OR TRIM(semester) = '')
                                ORDER BY created_at DESC LIMIT 1");
    if ($gen_stmt) {
        $gen_stmt->bind_param('s', $semester);
        $gen_stmt->execute();
        $res = $gen_stmt->get_result();
        if ($res && ($row = $res->fetch_assoc())) {
            $sources[] = $row['schedule_data'] ?? '';
        }
    }

    $rows = [];
    foreach ($sources as $schedule_data) {
        $rows = array_merge($rows, extractScheduleRows($schedule_data, $lecturer_name));
    }

    return $rows;
}

function extractScheduleRows($schedule_data, $lecturer_name) {
    $rows_out = [];
    $decoded = json_decode((string)$schedule_data, true);

    if (is_array($decoded) && !empty($decoded)) {
        $first = $decoded[0] ?? null;
        if (is_array($first) && isset($first[0]) && is_string($first[0])) {
            $headers = array_map(static function ($h) {
                return strtolower(trim((string)$h, "\" "));
            }, $first);

            for ($i = 1; $i < count($decoded); $i++) {
                if (!is_array($decoded[$i])) continue;
                $assoc = [];
                foreach ($headers as $idx => $h) {
                    $assoc[$h] = $decoded[$i][$idx] ?? '';
                }
                $row = array_change_key_case($assoc, CASE_LOWER);
                $row_lecturer = trim((string)($row['lecturer name'] ?? ($row['lecturer'] ?? '')));
                if ($row_lecturer === '' || strcasecmp($row_lecturer, $lecturer_name) !== 0) {
                    continue;
                }
                $rows_out[] = [
                    'day' => trim((string)($row['day'] ?? '')),
                    'time' => trim((string)($row['time'] ?? '')),
                    'label' => trim((string)($row['course code'] ?? ($row['course'] ?? ($row['code'] ?? ''))))
                ];
            }
        }
    }

    return $rows_out;
}

function getPersonalEvents($user_id, $conn) {
    $stmt = $conn->prepare("SELECT day, start_time, end_time, title FROM personal_events WHERE user_id = ?");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $events = [];
    while ($row = $result->fetch_assoc()) {
        $events[] = [
            'day' => $row['day'],
            'start_time' => substr((string)$row['start_time'], 0, 5),
            'end_time' => substr((string)$row['end_time'], 0, 5),
            'title' => $row['title']
        ];
    }
    return $events;
}

function runPythonSuggestions(array $payload) {
    $script = realpath(__DIR__ . '/../../schedule_suggestions.py');
    if (!$script || !file_exists($script)) {
        return ['success' => false, 'error' => 'Python suggestions script not found'];
    }

    $cmd = 'python3 ' . escapeshellarg($script);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];
    $process = proc_open($cmd, $descriptors, $pipes, dirname($script));
    if (!is_resource($process)) {
        return ['success' => false, 'error' => 'Unable to start Python process'];
    }

    fwrite($pipes[0], json_encode($payload));
    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exit_code = proc_close($process);
    if ($exit_code !== 0) {
        return ['success' => false, 'error' => trim($stderr) ?: 'Python process failed'];
    }

    $decoded = json_decode($stdout, true);
    if (!is_array($decoded) || empty($decoded['success'])) {
        return ['success' => false, 'error' => 'Invalid Python response'];
    }

    return $decoded;
}

function getUserPriorities($user_id, $conn) {
    $stmt = $conn->prepare("
        SELECT category, priority_level, target_hours_per_week
        FROM user_priorities
        WHERE user_id = ? AND is_active = TRUE
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $priorities = [];
    while ($row = $result->fetch_assoc()) {
        $priorities[$row['category']] = [
            'level' => $row['priority_level'],
            'target_hours' => floatval($row['target_hours_per_week'])
        ];
    }
    
    return $priorities;
}

function getProductivityPatterns($user_id, $conn) {
    $stmt = $conn->prepare("
        SELECT 
            day,
            HOUR(start_time) as hour,
            AVG(productivity_score) as avg_score,
            AVG(quality_rating) as avg_quality,
            COUNT(*) as frequency
        FROM productivity_log
        WHERE user_id = ? AND completion_status = 'completed'
        GROUP BY day, HOUR(start_time)
        HAVING frequency >= 2
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $patterns = [];
    while ($row = $result->fetch_assoc()) {
        $key = $row['day'] . '_' . $row['hour'];
        $patterns[$key] = [
            'score' => floatval($row['avg_score']),
            'quality' => floatval($row['avg_quality']),
            'frequency' => intval($row['frequency'])
        ];
    }
    
    return $patterns;
}

function getTaskPreferences($user_id, $conn) {
    $stmt = $conn->prepare("
        SELECT task_category, preferred_day, 
               TIME_FORMAT(preferred_time_start, '%H:%i') as start_time,
               preference_score, times_accepted, times_rejected
        FROM task_preferences
        WHERE user_id = ? AND times_accepted > times_rejected
        ORDER BY preference_score DESC
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $preferences = [];
    while ($row = $result->fetch_assoc()) {
        $preferences[$row['task_category']][] = $row;
    }
    
    return $preferences;
}

function findFreeSlots($busy_blocks) {
    $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    $day_start = '07:00';
    $day_end = '21:00';
    
    $free_slots = [];
    
    foreach ($days as $day) {
        // Get blocks for this day and sort by start time
        $day_blocks = array_filter($busy_blocks, function($b) use ($day) {
            return $b['day'] === $day;
        });
        
        usort($day_blocks, function($a, $b) {
            return strcmp($a['start'], $b['start']);
        });
        
        $cursor = $day_start;
        
        foreach ($day_blocks as $block) {
            if ($block['start'] > $cursor) {
                $duration = (strtotime($block['start']) - strtotime($cursor)) / 60;
                if ($duration >= 60) { // At least 1 hour free
                    $free_slots[] = [
                        'day' => $day,
                        'start_time' => $cursor,
                        'end_time' => $block['start'],
                        'duration' => $duration
                    ];
                }
            }
            $cursor = max($cursor, $block['end']);
        }
        
        // Check time after last block
        if ($cursor < $day_end) {
            $duration = (strtotime($day_end) - strtotime($cursor)) / 60;
            if ($duration >= 60) {
                $free_slots[] = [
                    'day' => $day,
                    'start_time' => $cursor,
                    'end_time' => $day_end,
                    'duration' => $duration
                ];
            }
        }
    }
    
    return $free_slots;
}

function scoreSlots($free_slots, $priorities, $productivity_patterns, $task_preferences) {
    $scored = [];
    
    foreach ($free_slots as $slot) {
        $hour = intval(explode(':', $slot['start_time'])[0]);
        $pattern_key = $slot['day'] . '_' . $hour;
        
        // Base score from duration
        $duration_score = min($slot['duration'] / 30, 10);
        
        // Productivity pattern bonus
        $productivity_score = 5.0;
        if (isset($productivity_patterns[$pattern_key])) {
            $pattern = $productivity_patterns[$pattern_key];
            $productivity_score = $pattern['score'] * $pattern['quality'] / 3;
        }
        
        // Time of day preference (students prefer 9-18, general preference)
        $time_bonus = 0;
        if ($hour >= 9 && $hour <= 18) {
            $time_bonus = 5.0;
        } elseif ($hour >= 8 && $hour <= 20) {
            $time_bonus = 3.0;
        }
        
        // Priority alignment (default to study for students)
        $priority_score = 5.0;
        if (!empty($priorities['study'])) {
            if ($priorities['study']['level'] === 'high') {
                $priority_score = 10.0;
            } elseif ($priorities['study']['level'] === 'medium') {
                $priority_score = 7.0;
            }
        }
        
        $total_score = $duration_score + $productivity_score + $time_bonus + $priority_score;
        
        $reason = sprintf(
            "Score %.1f: %d min slot, productivity %.1f, time bonus %.1f, priority %.1f",
            $total_score, $slot['duration'], $productivity_score, $time_bonus, $priority_score
        );
        
        $scored[] = [
            'day' => $slot['day'],
            'start_time' => $slot['start_time'],
            'end_time' => $slot['end_time'],
            'duration' => $slot['duration'],
            'type' => 'study_slot',
            'priority_score' => $priority_score,
            'productivity_score' => $productivity_score,
            'total_score' => $total_score,
            'reason' => $reason
        ];
    }
    
    // Sort by total score descending
    usort($scored, function($a, $b) {
        return $b['total_score'] <=> $a['total_score'];
    });
    
    return $scored;
}

function acceptSuggestion($user_id, $suggestion_id, $conn) {
    // Update suggestion status
    $stmt = $conn->prepare("
        UPDATE schedule_suggestions 
        SET status = 'accepted', responded_at = NOW()
        WHERE id = ? AND user_id = ?
    ");
    $stmt->bind_param("ii", $suggestion_id, $user_id);
    
    if (!$stmt->execute() || $stmt->affected_rows === 0) {
        return ['success' => false, 'error' => 'Suggestion not found'];
    }
    
    // Get suggestion details
    $stmt = $conn->prepare("
        SELECT * FROM schedule_suggestions WHERE id = ?
    ");
    $stmt->bind_param("i", $suggestion_id);
    $stmt->execute();
    $suggestion = $stmt->get_result()->fetch_assoc();
    
    // Update task preferences (Q-learning)
    updateTaskPreference($user_id, $suggestion, 'accept', $conn);
    
    return [
        'success' => true,
        'message' => 'Suggestion accepted and preferences updated'
    ];
}

function rejectSuggestion($user_id, $suggestion_id, $reason, $conn) {
    // Update suggestion status
    $stmt = $conn->prepare("
        UPDATE schedule_suggestions 
        SET status = 'rejected', responded_at = NOW()
        WHERE id = ? AND user_id = ?
    ");
    $stmt->bind_param("ii", $suggestion_id, $user_id);
    
    if (!$stmt->execute() || $stmt->affected_rows === 0) {
        return ['success' => false, 'error' => 'Suggestion not found'];
    }
    
    // Get suggestion details
    $stmt = $conn->prepare("
        SELECT * FROM schedule_suggestions WHERE id = ?
    ");
    $stmt->bind_param("i", $suggestion_id);
    $stmt->execute();
    $suggestion = $stmt->get_result()->fetch_assoc();
    
    // Update task preferences (Q-learning)
    updateTaskPreference($user_id, $suggestion, 'reject', $conn);
    
    return [
        'success' => true,
        'message' => 'Suggestion rejected and preferences updated'
    ];
}

function updateTaskPreference($user_id, $suggestion, $action, $conn) {
    $category = $suggestion['suggestion_type'];
    $day = $suggestion['day'];
    $start_time = $suggestion['start_time'];
    
    $stmt = $conn->prepare("
        INSERT INTO task_preferences 
        (user_id, task_category, preferred_day, preferred_time_start, preference_score, times_accepted, times_rejected)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            preference_score = preference_score + VALUES(preference_score),
            times_accepted = times_accepted + VALUES(times_accepted),
            times_rejected = times_rejected + VALUES(times_rejected)
    ");
    
    if ($action === 'accept') {
        $score = 1.0;
        $accepted = 1;
        $rejected = 0;
    } else {
        $score = -0.5;
        $accepted = 0;
        $rejected = 1;
    }
    
    $stmt->bind_param("isssdii", $user_id, $category, $day, $start_time, $score, $accepted, $rejected);
    $stmt->execute();
}

function getOptimalTimes($user_id, $task_category, $conn) {
    $stmt = $conn->prepare("
        SELECT preferred_day, preferred_time_start, preference_score,
               times_accepted, times_rejected
        FROM task_preferences
        WHERE user_id = ? AND task_category = ?
        ORDER BY preference_score DESC, times_accepted DESC
        LIMIT 5
    ");
    $stmt->bind_param("is", $user_id, $task_category);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $optimal_times = [];
    while ($row = $result->fetch_assoc()) {
        $optimal_times[] = $row;
    }
    
    return [
        'success' => true,
        'optimal_times' => $optimal_times,
        'category' => $task_category
    ];
}
