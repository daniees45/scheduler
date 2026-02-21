<?php
// web/api/upload_generated_to_b2.php
// Upload generated schedule file to B2 storage
// Called by Python app.py after schedule generation

require_once __DIR__ . '/../../lib/B2Storage.php';

// Get file path from command line argument
$file_path = $argv[1] ?? null;

if (!$file_path || !file_exists($file_path)) {
    fwrite(STDERR, "Error: File not found: $file_path\n");
    exit(1);
}

try {
    $b2 = new B2Storage();
    
    // Read file content
    $content = file_get_contents($file_path);
    if ($content === false) {
        throw new Exception("Could not read file: $file_path");
    }
    
    // Determine B2 path based on filename
    $filename = basename($file_path);
    $b2_path = 'csv/final/' . $filename;
    
    // Upload to B2
    $result = $b2->uploadContent($content, $b2_path, [
        'source' => 'ai_generation',
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
    if ($result['success']) {
        // Clean up old versions to prevent accumulation
        $cleanup = $b2->deleteOldVersions($b2_path);
        
        echo json_encode([
            'status' => 'success',
            'message' => "Uploaded $filename to B2",
            'b2_path' => $b2_path,
            'versions_cleaned' => $cleanup['deleted_count'] ?? 0
        ]);
        exit(0);
    } else {
        throw new Exception($result['error'] ?? 'Upload failed');
    }
    
} catch (Exception $e) {
    fwrite(STDERR, "Error uploading to B2: " . $e->getMessage() . "\n");
    exit(1);
}
