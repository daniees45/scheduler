<?php
/**
 * Database Migration: Add Department to Lecturers
 * 
 * This script adds the department column to the lecturers table.
 * Run this once to apply the migration.
 */

require_once '../api/db.php';

echo "<!DOCTYPE html><html><head><title>Migration</title>";
echo "<style>body{font-family:monospace;padding:2rem;background:#0f172a;color:#e2e8f0;}</style>";
echo "</head><body>";
echo "<h2>Lecturer Department Migration</h2>";

try {
    // Check if column already exists
    $result = $conn->query("SHOW COLUMNS FROM lecturers LIKE 'department'");
    
    if ($result->num_rows > 0) {
        echo "<p style='color: #fbbf24;'>⚠️ Department column already exists. Migration already applied.</p>";
    } else {
        // Add department column
        $sql = "ALTER TABLE lecturers ADD COLUMN department VARCHAR(100) DEFAULT NULL AFTER email";
        
        if ($conn->query($sql)) {
            echo "<p style='color: #10b981;'>✓ Successfully added department column to lecturers table.</p>";
            
            // Add index for better performance
            $conn->query("ALTER TABLE lecturers ADD INDEX idx_department (department)");
            echo "<p style='color: #10b981;'>✓ Added index on department column.</p>";
        } else {
            echo "<p style='color: #ef4444;'>✗ Error: " . $conn->error . "</p>";
        }
    }
    
    echo "<hr style='margin: 2rem 0; border-color: rgba(255,255,255,0.1);'>";
    echo "<p>Migration completed. You can now use department selection for lecturers.</p>";
    echo "<p><a href='../lecturers.php' style='color: #60a5fa;'>Go to Lecturers Management</a></p>";
    
} catch (Exception $e) {
    echo "<p style='color: #ef4444;'>Error: " . $e->getMessage() . "</p>";
}

echo "</body></html>";
$conn->close();
?>
