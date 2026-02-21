<?php
// web/api/export_pdf.php
// Export CSV to PDF with B2 support
require_once 'db.php';
require_once __DIR__ . '/../../lib/B2Storage.php';

$requested_file = $_POST['file'] ?? $_GET['file'] ?? 'csv/final/final_web_schedule.csv';
$from_b2 = $_POST['from_b2'] ?? $_GET['from_b2'] ?? false;

// Headers will be set after format detection

// Security Check
if (!preg_match('/^[a-zA-Z0-9_\-\.\/]+$/', $requested_file) || pathinfo($requested_file, PATHINFO_EXTENSION) !== 'csv') {
    die("Invalid file");
}

$base_dir = realpath('../../');
$temp_dir = $base_dir . '/temp/';

if (!is_dir($temp_dir)) {
    mkdir($temp_dir, 0755, true);
}

// Download from B2 if requested
if ($from_b2) {
    try {
        $b2 = new B2Storage();
        $csv_result = $b2->download($requested_file);
        
        if (!$csv_result['success']) {
            die("Failed to download CSV from B2: " . ($csv_result['message'] ?? 'Unknown error'));
        }
        
        $csv_path = $temp_dir . 'export_csv_' . time() . '.csv';
        file_put_contents($csv_path, $csv_result['content']);
    } catch (Exception $e) {
        die("B2 download error: " . $e->getMessage());
    }
} else {
    // Use local file
    $csv_path = realpath($base_dir . '/' . $requested_file);
    
    if (!$csv_path || strpos($csv_path, $base_dir) !== 0 || !file_exists($csv_path)) {
        die("File not found or access denied");
    }
}

// Detect CSV format by checking for "Invigilator" column
$is_exam_format = false;
if (file_exists($csv_path)) {
    $csv_handle = fopen($csv_path, 'r');
    if ($csv_handle) {
        $headers = fgetcsv($csv_handle, 0, ',', '"', '\\');
        if ($headers) {
            $headers_lower = array_map('strtolower', $headers);
            $is_exam_format = in_array('invigilator', $headers_lower);
        }
        fclose($csv_handle);
    }
}

// Set headers based on format detection (can be overridden by user input)
if ($is_exam_format) {
    // Exam format headers
    $h1 = $_POST['h1'] ?? $_GET['h1'] ?? "VALLEY VIEW UNIVERSITY";
    $h2 = $_POST['h2'] ?? $_GET['h2'] ?? "EXAMINATION TIMETABLE";
    $h3 = $_POST['h3'] ?? $_GET['h3'] ?? "SECOND SEMESTER - 2025 / 2026 ACADEMIC YEAR";
    $h4 = $_POST['h4'] ?? $_GET['h4'] ?? "EXAM SCHEDULE";
} else {
    // Class format headers
    $h1 = $_POST['h1'] ?? $_GET['h1'] ?? "VALLEY VIEW UNIVERSITY";
    $h2 = $_POST['h2'] ?? $_GET['h2'] ?? "COMPUTER SCIENCE, INFORMATION TECHNOLOGY, BUSINESS INFORMATION SYSTEMS AND MATHEMATICAL SCIENCES";
    $h3 = $_POST['h3'] ?? $_GET['h3'] ?? "SECOND SEMESTER - 2025 / 2026 ACADEMIC YEAR";
    $h4 = $_POST['h4'] ?? $_GET['h4'] ?? "TEACHING TIMETABLE";
}

$temp_pdf = $temp_dir . 'export_' . time() . '.pdf';
$py_script = realpath('../../csv_to_pdf.py');

// Use full path to python3 to avoid PATH issues with web server
$python_cmd = '/usr/local/bin/python3';
if (!file_exists($python_cmd)) {
    // Fallback to python3 in PATH
    $python_cmd = 'python3';
}

$cmd = $python_cmd . " " . escapeshellarg($py_script) . 
       " --input " . escapeshellarg($csv_path) . 
       " --output " . escapeshellarg($temp_pdf) . 
       " --h1 " . escapeshellarg($h1) . 
       " --h2 " . escapeshellarg($h2) . 
       " --h3 " . escapeshellarg($h3) . 
       " --h4 " . escapeshellarg($h4);

$output = shell_exec($cmd . " 2>&1");

if (!file_exists($temp_pdf) || filesize($temp_pdf) === 0) {
    error_log("PDF Generation failed: " . $output);
    
    // Cleanup temp CSV if from B2
    if ($from_b2 && isset($csv_path) && file_exists($csv_path)) {
        @unlink($csv_path);
    }
    
    http_response_code(500);
    die("Error generating PDF. Please contact the administrator.");
}

// Send PDF to browser
$filename = preg_replace('/[^a-zA-Z0-9_\-]/', '_', basename($requested_file, '.csv')) . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($temp_pdf));
readfile($temp_pdf);

// Cleanup
@unlink($temp_pdf);
if ($from_b2 && isset($csv_path) && file_exists($csv_path)) {
    @unlink($csv_path);
}
