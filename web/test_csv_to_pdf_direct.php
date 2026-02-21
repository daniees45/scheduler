<?php
// Direct test of csv_to_pdf_b2.php functionality
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<pre>\n";
echo "=== Testing CSV to PDF B2 Directly ===\n\n";

// Simulate session
session_start();
$_SESSION['user_id'] = 1; // Fake user for testing

// Simulate JSON input
$test_input = [
    'csv_file' => 'csv/final/schedule_computer.csv',
    'upload_to_b2' => true,
    'return_download' => false, // Get JSON response instead
    'h1' => 'TEST HEADER 1',
    'h2' => 'TEST HEADER 2',
    'h3' => 'TEST HEADER 3',
    'h4' => 'TEST HEADER 4'
];

// Set up the request
$_SERVER['REQUEST_METHOD'] = 'POST';
file_put_contents('php://input', json_encode($test_input));

echo "Input JSON:\n";
echo json_encode($test_input, JSON_PRETTY_PRINT) . "\n\n";

echo "Executing csv_to_pdf_b2.php...\n";
echo str_repeat('-', 50) . "\n";

// Capture the output
ob_start();
try {
    include 'api/csv_to_pdf_b2.php';
    $output = ob_get_clean();
    echo "Output:\n" . $output . "\n";
} catch (Exception $e) {
    $output = ob_get_clean();
    echo "Exception: " . $e->getMessage() . "\n";
    echo "Output before exception:\n" . $output . "\n";
}

echo "</pre>";
