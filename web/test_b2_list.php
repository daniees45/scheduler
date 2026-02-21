<?php
// Test file to check B2 listFiles functionality
require_once __DIR__ . '/../lib/B2Storage.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>B2 List Test</title>
    <style>
        body { font-family: monospace; padding: 20px; background: #1a1a1a; color: #fff; }
        pre { background: #2a2a2a; padding: 15px; border-radius: 5px; overflow-x: auto; }
        .success { color: #10b981; }
        .error { color: #ef4444; }
    </style>
</head>
<body>
    <h1>B2 Storage List Files Test</h1>
    
    <?php
    try {
        echo "<h2>Testing B2 Connection...</h2>";
        $b2 = new B2Storage();
        echo "<p class='success'>✓ B2Storage object created</p>";
        
        echo "<h2>Listing files in csv/final/...</h2>";
        $result = $b2->listFiles('csv/final/');
        
        echo "<h3>Raw Result:</h3>";
        echo "<pre>" . htmlspecialchars(print_r($result, true)) . "</pre>";
        
        if ($result['success']) {
            echo "<p class='success'>✓ List operation successful</p>";
            echo "<p>Found " . count($result['files']) . " files</p>";
            
            if (!empty($result['files'])) {
                echo "<h3>Files:</h3>";
                echo "<table border='1' cellpadding='10' style='border-collapse: collapse; width: 100%;'>";
                echo "<tr><th>Key</th><th>Size</th><th>Modified</th><th>Modified (Readable)</th></tr>";
                foreach ($result['files'] as $file) {
                    echo "<tr>";
                    echo "<td>" . htmlspecialchars($file['key']) . "</td>";
                    echo "<td>" . number_format($file['size']) . " bytes</td>";
                    echo "<td>" . $file['modified'] . "</td>";
                    echo "<td>" . date('Y-m-d H:i:s', $file['modified']) . "</td>";
                    echo "</tr>";
                }
                echo "</table>";
            } else {
                echo "<p class='error'>⚠ No files found in csv/final/</p>";
            }
        } else {
            echo "<p class='error'>✗ List operation failed</p>";
            echo "<p>Error: " . htmlspecialchars($result['error']) . "</p>";
        }
        
    } catch (Exception $e) {
        echo "<p class='error'>✗ Exception: " . htmlspecialchars($e->getMessage()) . "</p>";
        echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    }
    ?>
    
    <hr>
    <p><a href="schedules.php">← Back to Schedules</a></p>
</body>
</html>
