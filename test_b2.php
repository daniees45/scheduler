<?php
/**
 * Backblaze B2 Storage Test Script
 * 
 * Run this script to verify B2 is configured correctly:
 * php test_b2.php
 */

require_once __DIR__ . '/lib/B2Storage.php';

echo "========================================\n";
echo "  B2 Storage Configuration Test\n";
echo "========================================\n\n";

try {
    $b2 = new B2Storage();
    
    echo "✓ B2Storage class loaded successfully\n";
    
    // Check if B2 is enabled
    if (!$b2->isEnabled()) {
        echo "\n⚠️  B2 is DISABLED\n";
        echo "    Set 'enabled' => true in config/b2_config.php\n";
        echo "    Or check for configuration errors in logs\n";
        exit(1);
    }
    
    echo "✓ B2 is enabled\n\n";
    
    // Test: Upload a small test file
    echo "Testing upload...\n";
    $testContent = "Test file created at " . date('Y-m-d H:i:s');
    $testKey = 'csv/test/b2_test_' . time() . '.txt';
    
    $uploadResult = $b2->uploadContent($testContent, $testKey, [
        'test' => 'true',
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
    if ($uploadResult['success']) {
        echo "✓ Upload successful\n";
        echo "  Key: {$uploadResult['key']}\n";
        echo "  URL: {$uploadResult['url']}\n\n";
        
        // Test: Check if file exists
        echo "Testing existence check...\n";
        if ($b2->exists($testKey)) {
            echo "✓ File exists in B2\n\n";
        } else {
            echo "❌ File not found after upload\n\n";
        }
        
        // Test: Download the file
        echo "Testing download...\n";
        $downloadResult = $b2->download($testKey);
        
        if ($downloadResult['success']) {
            echo "✓ Download successful\n";
            echo "  Content: " . substr($downloadResult['content'], 0, 50) . "...\n\n";
        } else {
            echo "❌ Download failed: {$downloadResult['error']}\n\n";
        }
        
        // Test: List files
        echo "Testing list files...\n";
        $listResult = $b2->listFiles('csv/test/', 10);
        
        if ($listResult['success']) {
            echo "✓ List successful\n";
            echo "  Files found: " . count($listResult['files']) . "\n";
            foreach ($listResult['files'] as $file) {
                echo "    - {$file['key']} (" . number_format($file['size']) . " bytes)\n";
            }
            echo "\n";
        } else {
            echo "❌ List failed: {$listResult['error']}\n\n";
        }
        
        // Test: Delete the test file
        echo "Cleaning up test file...\n";
        $deleteResult = $b2->delete($testKey);
        
        if ($deleteResult['success']) {
            echo "✓ Delete successful\n\n";
        } else {
            echo "⚠️  Delete failed: {$deleteResult['error']}\n\n";
        }
        
        echo "========================================\n";
        echo "  ✓ All Tests Passed!\n";
        echo "========================================\n\n";
        echo "B2 is configured correctly and ready to use.\n\n";
        
    } else {
        echo "❌ Upload failed: {$uploadResult['error']}\n\n";
        
        echo "========================================\n";
        echo "  Troubleshooting Steps:\n";
        echo "========================================\n";
        echo "1. Check credentials in config/b2_config.php\n";
        echo "2. Verify bucket name matches your B2 bucket\n";
        echo "3. Ensure app key has 'writeFiles' capability\n";
        echo "4. Check region: us-west-002, us-east-005, eu-central-003, etc.\n";
        echo "5. Run: composer require aws/aws-sdk-php\n\n";
        
        exit(1);
    }
    
} catch (Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    echo "    File: " . $e->getFile() . "\n";
    echo "    Line: " . $e->getLine() . "\n\n";
    
    if (strpos($e->getMessage(), 'S3Client') !== false || 
        strpos($e->getMessage(), 'autoload') !== false) {
        echo "It looks like AWS SDK is not installed.\n";
        echo "Run: composer require aws/aws-sdk-php\n\n";
    }
    
    exit(1);
}
