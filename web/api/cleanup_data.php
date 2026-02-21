<?php
// web/api/cleanup_data.php
header('Content-Type: application/json');
require_once 'db.php';
require_once __DIR__ . '/../../lib/B2Storage.php';

$b2 = new B2Storage();

$input_name = isset($_POST['input_filename']) ? $_POST['input_filename'] : 'vvu_raw.csv';
$output_name = isset($_POST['output_filename']) ? $_POST['output_filename'] : 'departmental_courses.csv';
$output_folder = isset($_POST['output_folder']) ? $_POST['output_folder'] : '';

// Ensure .csv extension and sanitize
$input_name = basename($input_name);
$output_name = basename($output_name);
if (substr($input_name, -4) !== '.csv') $input_name .= '.csv';
if (substr($output_name, -4) !== '.csv') $output_name .= '.csv';

// Normalize output folder
$allowed_folders = [
    '' => '',
    'csv/general/' => 'csv/general/',
    'csv/department/' => 'csv/department/',
    'csv/final/' => 'csv/final/'
];
$output_folder = $allowed_folders[$output_folder] ?? '';
$output_path = $output_folder . $output_name;

$root_dir = realpath('../../') . '/';
$temp_dir = $root_dir . 'temp/';
if (!is_dir($temp_dir)) {
    mkdir($temp_dir, 0755, true);
}
$temp_input = $temp_dir . 'pre_cleanup_' . time() . '.csv';
$temp_output = $temp_dir . 'post_cleanup_' . time() . '.csv';

// 1. Download input from B2
$download_result = $b2->download($input_name);
if (!$download_result['success']) {
    http_response_code(404);
    echo json_encode([
        'status' => 'error',
        'message' => "File $input_name not found in B2: " . $download_result['error']
    ]);
    exit;
}

file_put_contents($temp_input, $download_result['content']);

// 2. Run Python Cleanup
$python_code = "
import sys
import os
sys.path.append('.')
sys.path.append('lib')
try:
    from clean_up import clean_data
    clean_data(r'$temp_input', r'$temp_output')
    print('Cleanup successful')
except Exception as e:
    print(f'Python Error: {str(e)}')
";

$cmd = "cd " . escapeshellarg($root_dir) . " && /usr/local/bin/python3 -c " . escapeshellarg($python_code) . " 2>&1";
$output = shell_exec($cmd);

// 3. Upload result to B2
if (file_exists($temp_output)) {
    $cleaned_content = file_get_contents($temp_output);
    
    $b2_status = null;
    if ($output_folder !== '') {
        $result = $b2->uploadContent($cleaned_content, $output_path, [
            'source' => 'data_cleanup',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
        if ($result['success']) {
            $b2_status = "Uploaded to B2: $output_path";
            // Clean up old versions
            $b2->deleteOldVersions($output_path);
        } else {
            $b2_status = "B2 upload failed: " . $result['error'];
        }
    }
    
    // Cleanup temporary files
    @unlink($temp_input);
    @unlink($temp_output);
    
    echo json_encode([
        'status' => 'success',
        'storage' => 'B2',
        'message' => "Cleanup complete" . ($b2_status ? " | $b2_status" : ''),
        'output' => $output
    ]);
} else {
    @unlink($temp_input);
    echo json_encode([
        'status' => 'error',
        'message' => 'Cleanup failed to produce output.',
        'output' => $output
    ]);
}
