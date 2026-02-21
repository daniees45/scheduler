<?php
// web/api/download_b2_file.php
// Download file content from B2 storage
session_start();
require_once '../../lib/B2Storage.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    die(json_encode(['status' => 'error', 'message' => 'Unauthorized']));
}

if (!isset($_GET['file'])) {
    die(json_encode(['status' => 'error', 'message' => 'File parameter required']));
}

$file_key = $_GET['file'];
$download_mode = isset($_GET['download']) && $_GET['download'] == '1';

// Normalize key for B2 lookup
$file_key = trim((string)$file_key);
if ($file_key === '') {
    die(json_encode(['status' => 'error', 'message' => 'Empty file key']));
}

try {
    $b2 = new B2Storage();

    // Try key variations to improve compatibility with callers
    $keys_to_try = [$file_key];
    if (strpos($file_key, 'csv/') !== 0) {
        $keys_to_try[] = 'csv/final/' . ltrim($file_key, '/');
    }

    $base_name = basename($file_key);
    if (!in_array($base_name, $keys_to_try, true)) {
        $keys_to_try[] = 'csv/final/' . $base_name;
    }

    $content = null;
    $last_error = null;

    foreach ($keys_to_try as $try_key) {
        $result = $b2->download($try_key); // content mode
        if (!empty($result['success']) && isset($result['content']) && $result['content'] !== null) {
            $content = (string)$result['content'];
            $file_key = $try_key;
            break;
        }
        $last_error = $result['error'] ?? $last_error;
    }

    if ($content === null) {
        throw new Exception($last_error ?: 'Failed to download file from B2');
    }
    
    if ($download_mode) {
        // Stream file for download
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . basename($file_key) . '"');
        echo $content;
        exit;
    } else {
        // Return file content as JSON
        $lines = explode("\n", $content);
        $data = [];
        
        foreach ($lines as $line) {
            if (trim($line) === '') continue;
            $data[] = str_getcsv($line);
        }
        
        echo json_encode([
            'status' => 'success',
            'file' => $file_key,
            'data' => $data
        ]);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
?>
