<?php
// web/api/delete_b2_file.php
// Delete file from B2 storage (super_admin only)
require_once 'db.php';
require_once '../../lib/B2Storage.php';
require_once __DIR__ . '/auth_guard.php';

header('Content-Type: application/json');

require_http_methods('POST');
require_authenticated_user();
require_super_admin_user();

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
