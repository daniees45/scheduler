<?php
// as/api/files_to_db.php
require_once 'db.php';

$csv_dir = '../../';
$files = [
    'rooms.csv', 'lecturer_availability.csv', 'departmental_courses.csv', 
    'special_rooms.csv', 'curriculum.csv', 'general_courses.csv', 
    'dept.csv', 'vvu_general_schedule.csv', 'historical_schedule.csv', 
    'user_feedback.csv', 'level_100.csv', 'level_200.csv', 
    'level_300.csv', 'level_400.csv', 'vvu_raw.csv',
    'computing_science_rooms.csv', 'nursing_rooms.csv', 'theology_rooms.csv'
];

$results = [];

foreach ($files as $file) {
    $path = $csv_dir . $file;
    if (file_exists($path)) {
        $content = file_get_contents($path);
        try {
            $stmt = $conn->prepare("INSERT INTO csv_storage (filename, content) VALUES (?, ?) 
                                   ON DUPLICATE KEY UPDATE content = VALUES(content)");
            $stmt->bind_param("sb", $file, $null);
            $stmt->send_long_data(1, $content);
            $stmt->execute();
            $results[] = "$file: Migrated";
        } catch (Exception $e) {
            $results[] = "$file: Error - " . $e->getMessage();
        }
    } else {
        $results[] = "$file: Not found on disk";
    }
}

echo json_encode(['status' => 'success', 'results' => $results]);
