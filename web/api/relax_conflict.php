<?php
// web/api/relax_conflict.php
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/bootstrap.php';
require_once 'db.php';
require_once __DIR__ . '/../../lib/B2Storage.php';

$data = json_decode(file_get_contents('php://input'), true);
$conflict_idx = $data['conflict_idx'] ?? null;
$schedule_id = $data['schedule_id'] ?? null;
$file_path = $data['file_path'] ?? 'csv/final/final_web_schedule.csv';

$schedule_data = null;

// 1. Get schedule data
if ($schedule_id) {
    $stmt = $conn->prepare("SELECT schedule_data FROM generated_schedules WHERE id = ?");
    $stmt->bind_param("i", $schedule_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $schedule_data = json_decode($row['schedule_data'], true);
    }
}

if (!$schedule_data) {
    $b2 = new B2Storage();
    $result = $b2->download($file_path);
    if ($result['success']) {
        $lines = explode("\n", $result['content']);
        $schedule_data = [];
        foreach ($lines as $line) {
            if (trim($line) === '')
                continue;
            $schedule_data[] = str_getcsv($line);
        }
    }
}

if (!$schedule_data) {
    echo json_encode(['status' => 'error', 'message' => 'Schedule data not found.']);
    exit;
}

// 2. Call Python AI Engine
try {
    $ch = curl_init(scheduler_url_join(scheduler_ai_base_url(), 'conflicts/relax'));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'csv_content' => $schedule_data,
        'conflict_idx' => $conflict_idx
    ]));
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200) {
        echo $response;
    }
    else {
        echo json_encode([
            'status' => 'error',
            'message' => "AI Engine returned error code $http_code",
            'raw' => $response
        ]);
    }
}
catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}