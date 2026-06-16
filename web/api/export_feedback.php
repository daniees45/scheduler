<?php
// export_feedback.php — Exports student_feedback from DB to CSV for Python AI Engine
require_once __DIR__ . '/../../config/bootstrap.php';

// Only allow local or admin access if needed, but since it's just dumping to a local file, we can restrict by basic auth or internal network if required.
// For now, it will dump all non-ignored feedback.

$output_file = __DIR__ . '/../../csv/general/student_clashes.csv';
$fp = fopen($output_file, 'w');

if (!$fp) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to open output file']);
    exit;
}

// Write the header expected by Python
fputcsv($fp, ['student_id', 'course1', 'course2', 'semester', 'timetable_file', 'reason']);

// Fetch all feedback that isn't ignored
$res = $conn->query("SELECT user_id, course1, course2, semester, timetable_file, reason FROM student_feedback WHERE status != 'ignored'");

if ($res) {
    while ($row = $res->fetch_assoc()) {
        fputcsv($fp, [
            $row['user_id'],
            $row['course1'],
            $row['course2'],
            $row['semester'],
            $row['timetable_file'],
            $row['reason']
        ]);
    }
}

fclose($fp);
echo json_encode(['status' => 'success', 'message' => 'Feedback exported successfully to CSV']);
