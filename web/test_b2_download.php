<?php
// test_b2_download.php
// Test B2 download functionality
session_start();
require_once '../lib/B2Storage.php';

// Set fake session for testing
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'super_admin';

?>
<!DOCTYPE html>
<html>
<head>
    <title>B2 Download Test</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; }
        .success { color: green; }
        .error { color: red; }
        pre { background: #f5f5f5; padding: 10px; border-radius: 5px; overflow-x: auto; }
        .test-section { margin: 20px 0; padding: 15px; border: 1px solid #ddd; border-radius: 5px; }
        h3 { margin-top: 0; }
    </style>
</head>
<body>
    <h1>B2 Download Functionality Test</h1>
    
    <?php
    $b2 = new B2Storage();
    
    // Test 1: List files to get a real file to test with
    echo '<div class="test-section">';
    echo '<h3>Test 1: List Available Files</h3>';
    $list_result = $b2->listFiles('csv/final/', 5);
    
    if ($list_result['success'] && !empty($list_result['files'])) {
        echo '<p class="success">✓ Found ' . count($list_result['files']) . ' files</p>';
        $test_file = $list_result['files'][0]['key'];
        echo '<p>Testing with file: <strong>' . htmlspecialchars($test_file) . '</strong></p>';
    } else {
        echo '<p class="error">✗ No files found in csv/final/</p>';
        $test_file = null;
    }
    echo '</div>';
    
    if ($test_file) {
        // Test 2: Download without SaveAs (content in response)
        echo '<div class="test-section">';
        echo '<h3>Test 2: Download Without SaveAs (Content in Response)</h3>';
        $download1 = $b2->download($test_file);
        
        if ($download1['success']) {
            echo '<p class="success">✓ Download successful</p>';
            echo '<p>Content length: ' . strlen($download1['content']) . ' bytes</p>';
            
            // Show first few lines
            $lines = explode("\n", $download1['content']);
            echo '<p>First 5 lines:</p>';
            echo '<pre>' . htmlspecialchars(implode("\n", array_slice($lines, 0, 5))) . '</pre>';
        } else {
            echo '<p class="error">✗ Download failed: ' . htmlspecialchars($download1['error']) . '</p>';
        }
        echo '</div>';
        
        // Test 3: Download with SaveAs (write to temp file)
        echo '<div class="test-section">';
        echo '<h3>Test 3: Download With SaveAs (Write to File)</h3>';
        $temp_file = tempnam(sys_get_temp_dir(), 'b2_test_');
        $download2 = $b2->download($test_file, $temp_file);
        
        if ($download2['success']) {
            echo '<p class="success">✓ Download successful</p>';
            
            if (file_exists($temp_file)) {
                $file_size = filesize($temp_file);
                echo '<p class="success">✓ File exists: ' . $temp_file . '</p>';
                echo '<p>File size: ' . $file_size . ' bytes</p>';
                
                // Read and show first few lines
                $content = file_get_contents($temp_file);
                $lines = explode("\n", $content);
                echo '<p>First 5 lines:</p>';
                echo '<pre>' . htmlspecialchars(implode("\n", array_slice($lines, 0, 5))) . '</pre>';
                
                // Cleanup
                unlink($temp_file);
                echo '<p class="success">✓ Temp file cleaned up</p>';
            } else {
                echo '<p class="error">✗ File does not exist after download</p>';
            }
        } else {
            echo '<p class="error">✗ Download failed: ' . htmlspecialchars($download2['error']) . '</p>';
        }
        echo '</div>';
        
        // Test 4: Test the API endpoint
        echo '<div class="test-section">';
        echo '<h3>Test 4: Test API Endpoint</h3>';
        echo '<p>Testing: <code>api/download_b2_file.php?file=' . urlencode($test_file) . '</code></p>';
        echo '<button onclick="testAPI()">Test API</button>';
        echo '<div id="api-result" style="margin-top: 10px;"></div>';
        echo '</div>';
    }
    ?>
    
    <script>
    async function testAPI() {
        const resultDiv = document.getElementById('api-result');
        resultDiv.innerHTML = '<p>Testing...</p>';
        
        try {
            const response = await fetch('api/download_b2_file.php?file=<?php echo urlencode($test_file ?? ''); ?>');
            const data = await response.json();
            
            if (data.status === 'success') {
                resultDiv.innerHTML = `
                    <p class="success">✓ API call successful</p>
                    <p>Rows returned: ${data.data ? data.data.length : 0}</p>
                    <p>First row sample:</p>
                    <pre>${JSON.stringify(data.data ? data.data[0] : null, null, 2)}</pre>
                `;
            } else {
                resultDiv.innerHTML = `<p class="error">✗ API Error: ${data.message}</p>`;
            }
        } catch (error) {
            resultDiv.innerHTML = `<p class="error">✗ JavaScript Error: ${error.message}</p>`;
        }
    }
    </script>
</body>
</html>
