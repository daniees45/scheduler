<?php
/**
 * B2 Version Cleanup Script
 * 
 * Removes all old versions of files in B2 bucket, keeping only the latest version.
 * This resolves the issue where files appear as "file.csv(4)" in B2 web interface
 * due to B2's file versioning feature accumulating old versions.
 * 
 * Usage: php cleanup_b2_versions.php
 */

require_once __DIR__ . '/lib/B2Storage.php';

echo "B2 Version Cleanup Script\n";
echo "=========================\n\n";

$b2 = new B2Storage();

if (!$b2->isEnabled()) {
    die("Error: B2 is not enabled. Check config/b2_config.php\n");
}

// Directories to clean
$directories = [
    'csv/general/',
    'csv/department/',
    'csv/final/',
    'csv/raw/',
];

$total_cleaned = 0;
$total_files = 0;

foreach ($directories as $dir) {
    echo "Checking directory: $dir\n";
    
    $result = $b2->listFiles($dir);
    
    if (!$result['success']) {
        echo "  Error listing files: " . $result['error'] . "\n";
        continue;
    }
    
    $files = $result['files'];
    echo "  Found " . count($files) . " files\n";
    
    foreach ($files as $file) {
        $key = $file['key'];
        $total_files++;
        
        echo "  Cleaning: $key ... ";
        
        $cleanup = $b2->deleteOldVersions($key);
        
        if ($cleanup['success']) {
            $count = $cleanup['deleted_count'];
            if ($count > 0) {
                echo "✓ Deleted $count old version(s)\n";
                $total_cleaned += $count;
            } else {
                echo "✓ No old versions\n";
            }
        } else {
            echo "✗ Error: " . $cleanup['error'] . "\n";
        }
    }
    
    echo "\n";
}

echo "=========================\n";
echo "Cleanup Complete!\n";
echo "Total files processed: $total_files\n";
echo "Total old versions deleted: $total_cleaned\n";
echo "\n";

if ($total_cleaned > 0) {
    echo "✓ Your B2 bucket is now clean. Files should no longer show (2), (3), (4) in B2 interface.\n";
} else {
    echo "✓ No old versions found. Your bucket is already clean!\n";
}
