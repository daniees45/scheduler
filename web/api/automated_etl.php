<?php
// web/api/automated_etl.php
header('Content-Type: application/json');
require_once 'db.php';
require_once __DIR__ . '/auth_guard.php';

require_http_methods('POST');
require_authenticated_user();
require_admin_user();

$output_name = isset($_POST['filename']) ? $_POST['filename'] : 'vvu_final_cleaned.csv';
$output_folder = isset($_POST['output_folder']) ? $_POST['output_folder'] : '';

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
$temp_dir = $root_dir . 'temp/';

if (!is_dir($temp_dir)) {
    if (!mkdir($temp_dir, 0777, true)) {
        echo json_encode(['status' => 'error', 'message' => 'Failed to create temp directory. check permissions.']);
        exit;
    }
}

$target_pdf = $temp_dir . 'vvu_etl_temp.pdf';
$raw_csv = $temp_dir . 'vvu_etl_raw.csv';
$clean_csv = $temp_dir . 'vvu_etl_clean.csv';
$final_csv_path = $root_dir . $output_path;

if (!move_uploaded_file($file['tmp_name'], $target_pdf)) {
    echo json_encode(['status' => 'error', 'message' => 'Failed to save uploaded PDF.']);
    exit;
}

try {
    // Stage 1: Extraction
    $extract_py = "
import sys
import os
sys.path.append('.')
import tabula
import pandas as pd

try:
    dfs = tabula.read_pdf(r'$target_pdf', pages='all', multiple_tables=True, lattice=True)
    if dfs:
        all_data = pd.concat(dfs, ignore_index=True)
        all_data.to_csv(r'$raw_csv', index=False)
        print('Extraction successful')
    else:
        print('No tables found in PDF.')
except Exception as e:
    print(f'Extraction Error: {str(e)}')
";
    $python_env = $root_dir . 'venv/bin/python';
    // Fallback if venv not found (though it should be there now)
    if (!file_exists($python_env)) {
        $python_env = '/usr/local/bin/python3'; 
    }

    $extract_cmd = "cd " . escapeshellarg($root_dir) . " && " . escapeshellarg($python_env) . " -c " . escapeshellarg($extract_py) . " 2>&1";
    $extract_out = shell_exec($extract_cmd);

    if (!file_exists($raw_csv)) {
        throw new Exception("Extraction failed to produce raw CSV. Output: " . $extract_out);
    }

    // Stage 2: Cleanup
    $cleanup_py = "
import sys
import os
sys.path.append('.')
try:
    from clean_up import clean_data
    clean_data(r'$raw_csv', r'$clean_csv')
    print('Cleanup successful')
except Exception as e:
    print(f'Cleanup Error: {str(e)}')
";
    $cleanup_cmd = "cd " . escapeshellarg($root_dir) . " && " . escapeshellarg($python_env) . " -c " . escapeshellarg($cleanup_py) . " 2>&1";
    $cleanup_out = shell_exec($cleanup_cmd);

    if (!file_exists($clean_csv)) {
        throw new Exception("Cleanup failed to produce clean CSV. Output: " . $cleanup_out);
    }

    $final_content = file_get_contents($clean_csv);

    // Upload to B2
    $b2_status = null;
    if ($output_folder !== '') {
        require_once '../../lib/B2Storage.php';
        $b2 = new B2Storage();
        
        $result = $b2->uploadContent($final_content, $output_path, [
            'source' => 'automated_etl',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
        if ($result['success']) {
            $b2_status = "Uploaded to B2: $output_path";
        } else {
            throw new Exception("Cloud upload failed: " . $result['error']);
        }
    } else {
         // If no folder selected, maybe user expects DB only? 
         // But we removed DB storage.
         // We should probably force a default folder or just warn.
         // Let's assume csv/final/ if nothing selected or just warn.
         // But existing logic allowed empty folder.
         // Let's force csv/final/ if empty for safety or just upload to root?
         // Uploading to root is fine.
         
         // Actually, let's just upload to root if output_folder is empty, using output_name
         require_once '../../lib/B2Storage.php';
         $b2 = new B2Storage();
         $result = $b2->uploadContent($final_content, $output_name);
         $b2_status = "Uploaded to Cloud: $output_name";
    }

    // Cleanup temp files
    @unlink($target_pdf);
    @unlink($raw_csv);
    @unlink($clean_csv);

    echo json_encode([
        'status' => 'success',
        'message' => 'Automated ETL complete. Final file: ' . $output_path
    ]);

} catch (Exception $e) {
    error_log('automated_etl failed: ' . $e->getMessage());
    @unlink($target_pdf);
    @unlink($raw_csv);
    @unlink($clean_csv);
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Automated ETL failed. Check server logs.'
    ]);
}
?>
