<?php
// web/api/get_latest_b2_schedule.php
// Get the latest schedule file from B2 storage based on upload time

require_once __DIR__ . '/../../lib/B2Storage.php';

header('Content-Type: application/json');

try {
    $b2 = new B2Storage();
    
    // List all files in csv/final/ folder
    $result = $b2->listFiles('csv/final/');
    
    if (!$result['success'] || empty($result['files'])) {
        echo json_encode([
            'status' => 'error',
            'message' => 'No schedules found in B2'
        ]);
        exit;
    }
    
    $files = $result['files'];
    
    // Files are already sorted by modified time descending in listFiles()
    // Get the most recent file
    $latest = $files[0];
    
    echo json_encode([
        'status' => 'success',
        'file' => $latest['key'],
        'uploaded' => date('Y-m-d H:i:s', $latest['modified']),
        'size' => $latest['size']
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
