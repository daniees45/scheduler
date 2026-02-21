<?php
// web/api/delete_b2_file.php
// Delete file from B2 storage (super_admin only)
session_start();
require_once 'db.php';
require_once '../../lib/B2Storage.php';

header('Content-Type: application/json');

// Check authentication and authorization
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'super_admin') {
    die(json_encode(['status' => 'error', 'message' => 'Unauthorized - Admin access required']));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die(json_encode(['status' => 'error', 'message' => 'Invalid request method']));
}

$input = json_decode(file_get_contents('php://input'), true);
$file_key = $input['file'] ?? '';

if (empty($file_key)) {
    die(json_encode(['status' => 'error', 'message' => 'File parameter required']));
}

try {
    $b2 = new B2Storage();
    
    // Delete the file from B2
    $b2->delete($file_key);
    
    // Also remove from database if it was saved
    $filename = basename($file_key);
    $stmt = $conn->prepare("DELETE FROM generated_schedules WHERE schedule_name = ?");
    $stmt->bind_param("s", $filename);
    $stmt->execute();
    
    echo json_encode([
        'status' => 'success',
        'message' => 'File deleted successfully'
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
?>
