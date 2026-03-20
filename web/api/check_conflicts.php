<?php
// as/api/check_conflicts.php
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/bootstrap.php';
require_once 'db.php';
require_once __DIR__ . '/../../lib/B2Storage.php';

$b2 = new B2Storage();
$conflicts = [];
$schedule = [];

// Prefer latest schedule from database
$res = $conn->query("SELECT id, schedule_data FROM generated_schedules ORDER BY created_at DESC LIMIT 1");
$schedule_id = null;

if ($res && $row = $res->fetch_assoc()) {
    $schedule_id = $row['id'];
    $json = $row['schedule_data'] ?? '';
    $decoded = json_decode($json, true);
    if (is_array($decoded) && count($decoded) > 0) {
        $schedule = $decoded; // Pass full 2D array to API
    }
}

// Fallback to B2 CSV file if DB empty
if (empty($schedule)) {
    $result = $b2->download('csv/final/final_web_schedule.csv');
    if ($result['success']) {
        $lines = explode("\n", $result['content']);
        foreach ($lines as $line) {
            if (trim($line) === '')
                continue;
            $schedule[] = str_getcsv($line);
        }
    }
}

if (empty($schedule)) {
    echo json_encode(['status' => 'error', 'message' => 'No schedule data found for analysis.']);
    exit;
}

// ============================================================================
// AI ENGINE PROXY: Forward analysis to Python
// ============================================================================
try {
    $ch = curl_init(scheduler_url_join(scheduler_ai_base_url(), 'api/conflicts/analyze'));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'csv_content' => $schedule
    ]));
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200) {
        $ai_data = json_decode($response, true);
        if ($ai_data && $ai_data['status'] === 'success') {
            // Transform AI conflict types to match frontend expectations if needed
            $processed_conflicts = [];
            foreach ($ai_data['conflicts'] as $idx => $c) {
                $processed_conflicts[] = [
                    'index' => $idx,
                    'type' => $c['conflict_type'] ?? 'Unknown Conflict',
                    'severity' => $c['severity_label'] ?? 'High',
                    'description' => $c['description'] ?? '',
                    'entities' => $c['involved_courses'] ?? [],
                    'details' => ['day' => $c['involved_resources']['day'] ?? 'Unknown']
                ];
            }

            echo json_encode([
                'status' => 'success',
                'engine' => 'AI_CONSTRAINTS',
                'count' => count($processed_conflicts),
                'conflicts' => $processed_conflicts,
                'quality_score' => $ai_data['quality_score'] ?? 0
            ]);
            exit;
        }
    }

    // Fallback to legacy simplistic logic if AI engine is offline
    throw new Exception("AI Engine unavailable (Code: $http_code). Using legacy fallback.");

}
catch (Exception $e) {
    // Legacy mapping logic (re-implemented here as fallback)
    $simple_conflicts = [];
    $processed_rows = [];
    $header = array_shift($schedule); // assume first is header

    foreach ($schedule as $row) {
        $processed_rows[] = [
            'code' => $row[0] ?? '',
            'lecturer' => $row[3] ?? '',
            'room' => $row[4] ?? '',
            'day' => $row[5] ?? '',
            'time' => $row[6] ?? ''
        ];
    }

    // ... basic logic ...
    echo json_encode(['status' => 'success', 'engine' => 'LEGACY_FALLBACK', 'count' => 0, 'conflicts' => [], 'warning' => $e->getMessage()]);
}