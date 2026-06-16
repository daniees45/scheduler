<?php
// as/api/extract_pdf.php
header('Content-Type: application/json');
require_once 'db.php';
require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/../../lib/B2Storage.php';

require_http_methods('POST');
require_authenticated_user();
require_admin_user();

$b2 = new B2Storage();

$output_name = isset($_POST['filename']) ? $_POST['filename'] : 'vvu_raw.csv';
$output_folder = isset($_POST['output_folder']) ? $_POST['output_folder'] : '';
$validate_structure = isset($_POST['validate_structure']) ? (bool)$_POST['validate_structure'] : false;

// Ensure .csv extension and sanitize name
$output_name = basename($output_name);
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

if (!isset($_FILES['pdf_file'])) {
    echo json_encode(['status' => 'error', 'message' => 'No file uploaded.']);
    exit;
}

$file = $_FILES['pdf_file'];
$root_dir = realpath('../../') . '/';
$target_pdf = $root_dir . 'temp/vvu_temp_extract_' . time() . '.pdf';
$temp_csv = $root_dir . 'temp/vvu_temp_extract_' . time() . '.csv';

// Ensure temp directory exists
if (!is_dir($root_dir . 'temp')) {
    mkdir($root_dir . 'temp', 0755, true);
}

if (move_uploaded_file($file['tmp_name'], $target_pdf)) {
    $python_code = "
import sys
import os
sys.path.append('.')
sys.path.append('lib')

import tabula
import pandas as pd

pdf_file = r'$target_pdf'
out_csv = r'$temp_csv'

try:
    dfs = tabula.read_pdf(pdf_file, pages='all', multiple_tables=True, lattice=True)
    if dfs:
        all_data = pd.concat(dfs, ignore_index=True)
        all_data.to_csv(out_csv, index=False)
        print('Extraction successful')
    else:
        print('No tables found in PDF.')
except Exception as e:
    print(f'Python Error: {str(e)}')
";
    
    $cmd = "cd " . escapeshellarg($root_dir) . " && /usr/local/bin/python3 -c " . escapeshellarg($python_code) . " 2>&1";
    $output = shell_exec($cmd);
    
    // Check if temp CSV was created
    if (file_exists($temp_csv)) {
        $csv_content = file_get_contents($temp_csv);

        // Basic validation: check header row if requested
        $validation = [
            'ok' => true,
            'warnings' => []
        ];
        if ($validate_structure) {
            $lines = preg_split('/\r\n|\r|\n/', $csv_content);
            $header = isset($lines[0]) ? array_map('trim', str_getcsv($lines[0])) : [];
            $header_lower = array_map('strtolower', $header);
            $required = ['course_code', 'course_title', 'lecturer_name', 'semester'];
            $missing = array_values(array_diff($required, $header_lower));
            if (!empty($missing)) {
                $validation['ok'] = false;
                $validation['warnings'][] = 'Missing columns: ' . implode(', ', $missing);
            }
            if (count($header) < 3) {
                $validation['ok'] = false;
                $validation['warnings'][] = 'Header has too few columns.';
            }
        }
        
        // Upload directly to B2 (no DB storage)
        $b2_status = null;
        if ($output_folder !== '') {
            $result = $b2->uploadContent($csv_content, $output_path, [
                'source' => 'pdf_extraction',
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            
            if ($result['success']) {
                $b2_status = "Uploaded to B2: $output_path";
                // Clean up old versions
                $b2->deleteOldVersions($output_path);
            } else {
                $b2_status = "Failed to upload to B2: " . $result['error'];
            }
        }
        
        // Clean up temporary files
        @unlink($target_pdf);
        @unlink($temp_csv);
        
        echo json_encode([
            'status' => $validation['ok'] ? 'success' : 'warning',
            'message' => 'Extraction complete and uploaded to B2: ' . $b2_status,
            'filename' => $output_path,
            'storage' => 'B2',
            'output' => $output,
            'validation' => $validation
        ]);
    } else {
        echo json_encode([
            'status' => 'error',
            'message' => 'Extraction failed to produce CSV. Check PDF structure and server logs.',
            'output' => $output ?: 'No output from extraction command.'
        ]);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Failed to save uploaded PDF.']);
}
