<?php
// web/api/delete_csv.php
require_once 'db.php';
require_once __DIR__ . '/auth_guard.php';

header('Content-Type: application/json');

require_http_methods('POST');
$user_id = require_authenticated_user();

$input = json_decode(file_get_contents('php://input'), true);
$file_path = $input['file_path'] ?? '';

if (!$file_path) {
    echo json_encode(['status' => 'error', 'message' => 'No file specified.']);
    exit;
}

// Get filename from database to verify it exists
$stmt = $conn->prepare("SELECT filename, uploaded_by FROM csv_storage WHERE file_path = ?");
$stmt->bind_param("s", $file_path);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['status' => 'error', 'message' => 'File not found in database.']);
    exit;
}

$row = $result->fetch_assoc();

// Check if user owns the file or is admin
if ((int)$row['uploaded_by'] !== $user_id && (string)($_SESSION['role'] ?? '') !== 'super_admin') {
    echo json_encode(['status' => 'error', 'message' => 'You do not have permission to delete this file.']);
    exit;
}

// Delete physical file
$physical_path = '../../' . $file_path;
if (file_exists($physical_path)) {
    unlink($physical_path);
}

// Delete from database
$stmt = $conn->prepare("DELETE FROM csv_storage WHERE file_path = ?");
$stmt->bind_param("s", $file_path);
$stmt->execute();

echo json_encode([
    'status' => 'success',
    'message' => 'File deleted successfully.'
]);
?>
