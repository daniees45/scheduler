<?php
// web/api/upload_csv.php
// Parse CSV and store in session for immediate editing (no folder storage)
require_once __DIR__ . '/auth_guard.php';

header('Content-Type: application/json');

require_http_methods('POST');
require_authenticated_user();

if (!isset($_FILES["csv_file"]) || $_FILES["csv_file"]["error"] !== UPLOAD_ERR_OK) {
    die(json_encode(["status" => "error", "message" => "No file uploaded or upload error occurred."]));
}

$filename = basename($_FILES["csv_file"]["name"]);
$fileType = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

// Check if file is a CSV
if($fileType != "csv") {
    die(json_encode(["status" => "error", "message" => "Only CSV files are allowed."]));
}

// Check file size (limit to 5MB)
if ($_FILES["csv_file"]["size"] > 5000000) {
    die(json_encode(["status" => "error", "message" => "File is too large. Maximum size is 5MB."]));
}

// Parse CSV directly from upload (no folder storage)
$csvData = [];
$handle = fopen($_FILES["csv_file"]["tmp_name"], "r");

if ($handle === FALSE) {
    die(json_encode(["status" => "error", "message" => "Failed to read CSV file."]));
}

// Read all rows
while (($row = fgetcsv($handle, 1000, ",")) !== FALSE) {
    $csvData[] = $row;
}
fclose($handle);

if (empty($csvData)) {
    die(json_encode(["status" => "error", "message" => "CSV file is empty."]));
}

// Store in session for editing
$upload_id = uniqid('csv_', true);
$_SESSION['uploaded_csv'] = [
    'id' => $upload_id,
    'filename' => $filename,
    'data' => $csvData,
    'uploaded_at' => date('Y-m-d H:i:s')
];

echo json_encode([
    "status" => "success", 
    "message" => "CSV parsed successfully with " . (count($csvData) - 1) . " rows.",
    "filename" => $filename,
    "row_count" => count($csvData) - 1,
    "upload_id" => $upload_id,
    "redirect" => "edit_csv.php?session=" . $upload_id
]);
?>
