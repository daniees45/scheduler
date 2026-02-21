<?php
require_once 'db.php';
$tables = ['sections', 'courses', 'rooms', 'lecturers', 'csv_storage', 'schedule_versions'];
$output = [];
foreach ($tables as $table) {
    $res = $conn->query("DESCRIBE $table");
    if ($res) {
        $output[$table] = $res->fetch_all(MYSQLI_ASSOC);
    } else {
        $output[$table] = "Error: " . $conn->error;
    }
}
echo json_encode($output, JSON_PRETTY_PRINT);
?>
