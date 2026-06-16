<?php
// Allow general schedule uploads to B2 storage
require_once __DIR__ . '/auth_guard.php';
header('Content-Type: application/json');

require_once 'db.php';
require_once __DIR__ . '/../../lib/B2Storage.php';

$b2 = new B2Storage();

require_http_methods('POST');
require_authenticated_user();
require_admin_user();

// Get data
$data = json_decode(file_get_contents('php://input'), true);
$filename = $data['filename'] ?? null;
$content = $data['content'] ?? null;

if (!$filename || !$content) {
    echo json_encode(['status' => 'error', 'message' => 'Missing filename or content']);
    exit;
}

// Sanitize filename
$filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($filename));
$filename = 'gen_' . time() . '_' . $filename;

// Upload directly to B2
$b2_path = 'csv/general/uploaded/' . $filename;
$result = $b2->uploadContent($content, $b2_path, [
    'source' => 'general_schedule_upload',
    'timestamp' => date('Y-m-d H:i:s')
]);

if ($result['success']) {
    // Clean up old versions
    $b2->deleteOldVersions($b2_path);
    
    // Store reference in session for later retrieval
    $_SESSION['uploaded_general_schedules'] = $_SESSION['uploaded_general_schedules'] ?? [];
    $_SESSION['uploaded_general_schedules'][$filename] = $b2_path;
    
    echo json_encode([
        'status' => 'success',
        'message' => 'General schedule uploaded to B2 successfully',
        'path' => $b2_path,
        'filename' => $filename,
        'url' => $result['url']
    ]);
} else {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Failed to upload to B2: ' . $result['error']
    ]);
}
