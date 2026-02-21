<?php
// web/api/list_b2_schedules.php
// List all generated schedules from B2 storage with metadata
session_start();
require_once 'db.php';
require_once '../../lib/B2Storage.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    die(json_encode(['status' => 'error', 'message' => 'Unauthorized']));
}

try {
    $b2 = new B2Storage();
    
    // List all files in csv/final/ folder
    $result = $b2->listFiles('csv/final/');
    
    // Check if operation was successful
    if (!$result['success'] || empty($result['files'])) {
        echo json_encode([
            'status' => 'success',
            'schedules' => []
        ]);
        exit;
    }
    
    $files = $result['files'];
    
    // Check which files are saved in database
    $saved_files = [];
    $res = $conn->query("SELECT schedule_name FROM generated_schedules");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $saved_files[] = $row['schedule_name'];
        }
    }
    
    // Parse metadata from filenames and enrich data
    $schedules = [];
    foreach ($files as $file) {
        $filename = basename($file['key']);
        
        // Parse filename pattern: {type}_{semester}_{department}_{timestamp}.csv
        // Example: class_1_CS_20260216_143022.csv or exam_2_Business_20260216_143022.csv
        $metadata = parseFilename($filename);
        
        // Convert timestamp to readable date
        $uploaded_date = '';
        if (isset($file['modified'])) {
            $uploaded_date = date('Y-m-d H:i:s', $file['modified']);
        }
        
        $schedules[] = [
            'file' => $file['key'],
            'name' => $filename,
            'size' => $file['size'] ?? 0,
            'uploaded' => $uploaded_date,
            'semester' => $metadata['semester'] ?? '',
            'department' => $metadata['department'] ?? 'General',
            'type' => $metadata['type'] ?? 'class',
            'saved_to_db' => in_array($filename, $saved_files)
        ];
    }
    
    // Sort by upload time (newest first)
    usort($schedules, function($a, $b) {
        return strtotime($b['uploaded']) - strtotime($a['uploaded']);
    });
    
    echo json_encode([
        'status' => 'success',
        'schedules' => $schedules
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}

function parseFilename($filename) {
    // Remove .csv extension
    $name = str_replace('.csv', '', $filename);
    
    // Try to parse structured filename
    $parts = explode('_', $name);
    
    $metadata = [
        'type' => 'class',
        'semester' => '',
        'department' => 'General'
    ];
    
    // Pattern: {type}_{semester}_{department}_{timestamp}
    if (count($parts) >= 3) {
        if (in_array($parts[0], ['class', 'exam'])) {
            $metadata['type'] = $parts[0];
        }
        
        if (is_numeric($parts[1]) && in_array($parts[1], ['1', '2', '3'])) {
            $metadata['semester'] = $parts[1];
        }
        
        // Department could be multi-word or abbreviated
        if (isset($parts[2])) {
            $dept_part = $parts[2];
            
            // Map common abbreviations
            $dept_map = [
                'CS' => 'CS/IT/BBIS',
                'IT' => 'CS/IT/BBIS',
                'BBIS' => 'CS/IT/BBIS',
                'Business' => 'Business',
                'BUSI' => 'Business',
                'ACCT' => 'Business',
                'Nursing' => 'Nursing',
                'NURS' => 'Nursing',
                'Theology' => 'Theology',
                'RELB' => 'Theology',
                'General' => 'General'
            ];
            
            $metadata['department'] = $dept_map[$dept_part] ?? $dept_part;
        }
    }
    
    return $metadata;
}
?>
