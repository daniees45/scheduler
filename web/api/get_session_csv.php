<?php
// web/api/get_session_csv.php
// Return CSV content from session (uploaded + edited)
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    die(json_encode(["status" => "error", "message" => "Unauthorized"]));
}

if (!isset($_SESSION['ready_for_scheduling']) || empty($_SESSION['ready_for_scheduling']['data'])) {
    echo json_encode(['status' => 'error', 'message' => 'No session data ready.']);
    exit;
}

$data = $_SESSION['ready_for_scheduling']['data'];
$filename = $_SESSION['ready_for_scheduling']['filename'] ?? 'uploaded.csv';

// Build CSV string
$csv = '';
foreach ($data as $row) {
    $f = fopen('php://temp', 'r+');
    fputcsv($f, $row);
    rewind($f);
    $csv .= stream_get_contents($f);
    fclose($f);
}

echo json_encode([
    'status' => 'success',
    'filename' => $filename,
    'csv_content' => $csv
]);
?>
