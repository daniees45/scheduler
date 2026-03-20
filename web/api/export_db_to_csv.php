<?php
/**
 * Export database tables (rooms, lecturers, courses) to CSV and upload to B2
 * Can be called standalone OR included from other scripts
 * When included: silently exports, sets $export_result
 * When standalone: outputs JSON response
 */

// Suppress errors if included (prevent output corruption)
if (!defined('EXPORT_DB_SILENCE_ERRORS')) {
    define('EXPORT_DB_SILENCE_ERRORS', true);
    error_reporting(0);  // When included, suppress all errors from being output
}

// Determine if this is a standalone request
$is_standalone = (php_sapi_name() === 'cli' || basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'export_db_to_csv.php');

// If standalone, restore normal error reporting for debugging
if ($is_standalone) {
    error_reporting(E_ALL);
    ini_set('display_errors', 0);  // Log but don't display
}

// Only start session if standalone
if ($is_standalone && session_status() === PHP_SESSION_NONE) {
    @session_start();  // Suppress any session headers already sent errors
}

// Ensure required globals exist
if (!isset($conn)) {
    require_once 'db.php';
}

if (!isset($b2)) {
    require_once dirname(__DIR__) . '/lib/B2Storage.php';
    $b2 = new B2Storage();
}

$exported = [];
$errors = [];

try {
    // NOTE: Room CSVs are NOT exported here. Each room source file (csv/general/rooms.csv,
    // csv/department/*_rooms.csv) is managed directly via the Rooms Management UI and stored
    // in B2 as the source of truth. Exporting the merged DB rooms table back to csv/general/rooms.csv
    // would overwrite it with ALL rooms from every department, breaking per-source isolation.
    // Use the Rooms Management page to edit room lists.
    $exported['rooms.csv'] = 'Skipped (managed via Rooms UI)';

    // Export Lecturers
    try {
        $result = $conn->query("SELECT name, availability_json FROM lecturers ORDER BY name");
        if ($result && $result->num_rows > 0) {
            $csvContent = "name,availability_json\n";
            while ($row = $result->fetch_assoc()) {
                $avail = str_replace('"', '""', $row['availability_json'] ?? '[]');
                $csvContent .= "\"{$row['name']}\",\"{$avail}\"\n";
            }
            
            $lecturersPath = '../../csv/general/lecturers.csv';
            @mkdir(dirname($lecturersPath), 0755, true);
            file_put_contents($lecturersPath, $csvContent);
            $exported['lecturers.csv'] = 'Exported to local';
            
            if ($b2->isEnabled()) {
                if ($b2->uploadContent($csvContent, 'csv/general/lecturers.csv')) {
                    $exported['lecturers.csv'] = 'Exported to B2 and local';
                } else {
                    $errors['lecturers.csv'] = 'Local OK, B2 upload failed';
                }
            }
        }
    } catch (Exception $e) {
        $errors['lecturers.csv'] = $e->getMessage();
    }

    // Export Courses
    try {
        $result = $conn->query("SELECT course_code, course_title, semester, level, credit_hours, type FROM courses ORDER BY course_code");
        if ($result && $result->num_rows > 0) {
            $csvContent = "course_code,course_title,semester,level,credit_hours,type\n";
            while ($row = $result->fetch_assoc()) {
                $csvContent .= "\"{$row['course_code']}\",\"{$row['course_title']}\",{$row['semester']},{$row['level']},{$row['credit_hours']},\"{$row['type']}\"\n";
            }
            
            $coursesPath = '../../csv/general/courses.csv';
            @mkdir(dirname($coursesPath), 0755, true);
            file_put_contents($coursesPath, $csvContent);
            $exported['courses.csv'] = 'Exported to local';
            
            if ($b2->isEnabled()) {
                if ($b2->uploadContent($csvContent, 'csv/general/courses.csv')) {
                    $exported['courses.csv'] = 'Exported to B2 and local';
                } else {
                    $errors['courses.csv'] = 'Local OK, B2 upload failed';
                }
            }
        }
    } catch (Exception $e) {
        $errors['courses.csv'] = $e->getMessage();
    }
} catch (Exception $e) {
    $errors['general'] = 'Export error: ' . $e->getMessage();
}

// Build result
$status = empty($errors) ? 'success' : (empty($exported) ? 'error' : 'partial');
$export_result = [
    'status' => $status,
    'message' => 'Database exported to CSV files',
    'exported' => $exported,
    'errors' => $errors,
    'timestamp' => date('Y-m-d H:i:s')
];

// Only output JSON if this is a standalone request
if ($is_standalone) {
    header('Content-Type: application/json');
    echo json_encode($export_result);
}
