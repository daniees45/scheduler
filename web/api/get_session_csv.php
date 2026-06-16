<?php
// web/api/get_session_csv.php
// Return CSV content from session (uploaded + edited)
ini_set('display_errors', 0);
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    die(json_encode(["status" => "error", "message" => "Unauthorized"]));
}

if (!isset($_SESSION['ready_for_scheduling']) || empty($_SESSION['ready_for_scheduling']['data'])) {
    echo json_encode(['status' => 'error', 'message' => 'No session data ready.']);
    exit;
}

$data = $_SESSION['ready_for_scheduling']['data'];
$filename = $_SESSION['ready_for_scheduling']['filename'] ?? 'uploaded.csv';

$header = isset($data[0]) && is_array($data[0]) ? $data[0] : [];
$bodyRows = array_slice($data, 1);
$rowCount = count($bodyRows);

$colCount = count($header);
$nonEmptyCells = 0;
$totalCells = 0;
$courseIdx = -1;
foreach ($header as $idx => $colName) {
    if (strcasecmp(trim((string)$colName), 'course_code') === 0) {
        $courseIdx = $idx;
        break;
    }
}
if ($courseIdx < 0 && $colCount > 0) {
    $courseIdx = 0;
}

$distinctCourse = [];
foreach ($bodyRows as $row) {
    if (!is_array($row)) {
        continue;
    }
    $totalCells += max($colCount, count($row));
    foreach ($row as $cell) {
        if (trim((string)$cell) !== '') {
            $nonEmptyCells++;
        }
    }

    if ($courseIdx >= 0 && isset($row[$courseIdx])) {
        $courseCode = strtoupper(trim((string)$row[$courseIdx]));
        if ($courseCode !== '') {
            $distinctCourse[$courseCode] = true;
        }
    }
}

$fillRate = $totalCells > 0 ? round(($nonEmptyCells / $totalCells) * 100, 2) : 0;

// Build CSV string
$csv = '';
foreach ($data as $row) {
    $f = fopen('php://temp', 'r+');
    fputcsv($f, $row, ',', '"', '\\');
    rewind($f);
    $csv .= stream_get_contents($f);
    fclose($f);
}

echo json_encode([
    'status' => 'success',
    'filename' => $filename,
    'csv_content' => $csv,
    'stats' => [
        'rows' => $rowCount,
        'columns' => $colCount,
        'non_empty_cells' => $nonEmptyCells,
        'fill_rate_percent' => $fillRate,
        'distinct_course_codes' => count($distinctCourse),
    ],
]);
?>
