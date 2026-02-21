<?php
// Simple test script to debug CSV to PDF B2 export
session_start();
require_once 'api/db.php';
require_once '../lib/B2Storage.php';

header('Content-Type: text/plain');

echo "=== B2 to PDF Test ===\n\n";

// Test 1: Check B2 connection
echo "1. Testing B2 Connection...\n";
try {
    $b2 = new B2Storage();
    echo "   ✓ B2 Storage initialized\n\n";
} catch (Exception $e) {
    echo "   ✗ B2 Failed: " . $e->getMessage() . "\n";
    exit;
}

// Test 2: List available CSV files
echo "2. Listing CSV files in B2...\n";
try {
    $list = $b2->listFiles('csv/final/', 5);
    if ($list['success'] && !empty($list['files'])) {
        echo "   ✓ Found " . count($list['files']) . " files:\n";
        foreach ($list['files'] as $file) {
            echo "     - " . $file['key'] . " (" . number_format($file['size']) . " bytes)\n";
        }
        $test_csv_key = $list['files'][0]['key'];
        echo "\n   Using: " . $test_csv_key . "\n\n";
    } else {
        echo "   ✗ No CSV files found\n";
        exit;
    }
} catch (Exception $e) {
    echo "   ✗ List failed: " . $e->getMessage() . "\n";
    exit;
}

// Test 3: Download CSV from B2
echo "3. Downloading CSV from B2...\n";
try {
    $csv_result = $b2->download($test_csv_key);
    if ($csv_result['success']) {
        echo "   ✓ Downloaded " . strlen($csv_result['content']) . " bytes\n";
        echo "   First 100 chars: " . substr($csv_result['content'], 0, 100) . "...\n\n";
    } else {
        echo "   ✗ Download failed: " . ($csv_result['message'] ?? 'Unknown error') . "\n";
        exit;
    }
} catch (Exception $e) {
    echo "   ✗ Exception: " . $e->getMessage() . "\n";
    exit;
}

// Test 4: Write CSV to temp
echo "4. Writing CSV to temp directory...\n";
$temp_dir = realpath('../temp/');
if (!is_dir($temp_dir)) {
    mkdir($temp_dir, 0755, true);
}
$temp_csv = $temp_dir . '/test_pdf_' . time() . '.csv';
file_put_contents($temp_csv, $csv_result['content']);

if (file_exists($temp_csv) && filesize($temp_csv) > 0) {
    echo "   ✓ CSV written to: " . $temp_csv . "\n";
    echo "   Size: " . filesize($temp_csv) . " bytes\n\n";
} else {
    echo "   ✗ Failed to write CSV\n";
    exit;
}

// Test 5: Check Python script
echo "5. Checking Python script...\n";
$py_script = realpath('../csv_to_pdf.py');
if (file_exists($py_script)) {
    echo "   ✓ Python script found: " . $py_script . "\n\n";
} else {
    echo "   ✗ Python script not found\n";
    exit;
}

// Test 6: Generate PDF
echo "6. Generating PDF...\n";
$temp_pdf = $temp_dir . '/test_pdf_' . time() . '.pdf';

// Use full path to python3 to avoid PATH issues with web server
$python_cmd = '/usr/local/bin/python3';
if (!file_exists($python_cmd)) {
    // Fallback to python3 in PATH
    $python_cmd = 'python3';
}

$cmd = $python_cmd . " " . escapeshellarg($py_script) . 
       " --input " . escapeshellarg($temp_csv) . 
       " --output " . escapeshellarg($temp_pdf) . 
       " --h1 " . escapeshellarg("TEST HEADER 1") . 
       " --h2 " . escapeshellarg("TEST HEADER 2") . 
       " --h3 " . escapeshellarg("TEST HEADER 3") . 
       " --h4 " . escapeshellarg("TEST HEADER 4") . 
       " 2>&1";

echo "   Command: " . $cmd . "\n";
echo "   Python: " . $python_cmd . "\n";
$output = shell_exec($cmd);
echo "   Output: " . $output . "\n";

if (file_exists($temp_pdf) && filesize($temp_pdf) > 0) {
    echo "   ✓ PDF generated: " . $temp_pdf . "\n";
    echo "   Size: " . filesize($temp_pdf) . " bytes\n\n";
} else {
    echo "   ✗ PDF generation failed\n";
    echo "   PDF exists: " . (file_exists($temp_pdf) ? 'yes' : 'no') . "\n";
    if (file_exists($temp_pdf)) {
        echo "   PDF size: " . filesize($temp_pdf) . " bytes\n";
    }
    exit;
}

// Test 7: Clean up
echo "7. Cleanup...\n";
@unlink($temp_csv);
@unlink($temp_pdf);
echo "   ✓ Temp files removed\n\n";

echo "=== ALL TESTS PASSED ===\n";
