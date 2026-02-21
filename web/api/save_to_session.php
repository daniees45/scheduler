<?php
// web/api/save_to_session.php
// Save edited CSV data back to session
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    die(json_encode(["status" => "error", "message" => "Unauthorized"]));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$data = $input['data'] ?? [];
$filename = $input['filename'] ?? 'untitled.csv';

if (empty($data)) {
    echo json_encode(['status' => 'error', 'message' => 'No data provided.']);
    exit;
}

// Store in session with a unique ID
$ready_id = uniqid('ready_', true);
$_SESSION['ready_for_scheduling'] = [
    'id' => $ready_id,
    'filename' => $filename,
    'data' => $data,
    'prepared_at' => date('Y-m-d H:i:s')
];

// Do NOT write temp file here (permissions issues). We will send CSV content to Flask.
$_SESSION['ready_for_scheduling']['temp_file'] = null;

echo json_encode([
    'status' => 'success',
    'message' => 'Data prepared for scheduling.',
    'ready_id' => $ready_id
]);
?>
