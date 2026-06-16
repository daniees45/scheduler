<?php
// web/api/init_manual_input.php
require_once __DIR__ . '/auth_guard.php';
header('Content-Type: application/json');

require_http_methods(['GET', 'POST']);
require_authenticated_user();

$type = $_GET['type'] ?? 'class';
$filename = $type === 'exam' ? 'manual_exam_input.csv' : 'manual_course_input.csv';

// Default headers
if ($type === 'exam') {
    $headers = ["course_code", "course_title", "enrollment", "semester", "lecturer_name", "course_level", "credit_hours"];
}
else {
    $headers = ["course_code", "course_title", "lecturer_name", "course_level", "enrollment", "credit_hours", "source_type", "Semester"];
}

$csvData = [$headers];
// Add 5 blank rows to make it look like a table immediately as requested by user
for ($i = 0; $i < 5; $i++) {
    $csvData[] = array_fill(0, count($headers), "");
}

$upload_id = uniqid('manual_', true);
$_SESSION['uploaded_csv'] = [
    'id' => $upload_id,
    'filename' => $filename,
    'data' => $csvData,
    'uploaded_at' => date('Y-m-d H:i:s')
];

echo json_encode([
    "status" => "success",
    "redirect" => "edit_csv.php?session=" . $upload_id
]);
?>