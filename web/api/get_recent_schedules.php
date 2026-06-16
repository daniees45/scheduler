<?php
/**
 * API to get recently generated GENERAL schedules for block selection.
 * Prioritizes DB records with department metadata, then falls back to B2/local csv/final files.
 */

session_start();
header('Content-Type: application/json');
require_once 'db.php';
require_once __DIR__ . '/../../lib/B2Storage.php';

$b2 = new B2Storage();

// Access control
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
if ($limit < 1) $limit = 1;
if ($limit > 20) $limit = 20;

$academic_year = trim((string)($_GET['academic_year'] ?? ''));
$schedule_kind = strtolower(trim((string)($_GET['type'] ?? 'class')));
if (!in_array($schedule_kind, ['class', 'exam', 'all'], true)) {
    $schedule_kind = 'class';
}

$schedules = [];
$seen_paths = [];

function has_generated_schedule_column(mysqli $conn, string $column): bool {
    $safe = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM generated_schedules LIKE '{$safe}'");
    return $res && $res->num_rows > 0;
}

function normalize_schedule_path($name) {
    $name = trim((string)$name);
    if ($name === '') return '';

    if (strpos($name, 'csv/') === 0) {
        return $name;
    }

    $filename = basename($name);
    if (!preg_match('/\.csv$/i', $filename)) {
        $filename .= '.csv';
    }
    return 'csv/final/' . $filename;
}

function local_line_count_for_path($relative_path) {
    $abs = realpath(__DIR__ . '/../../') . '/' . ltrim($relative_path, '/');
    if (!is_file($abs)) return null;

    $line_count = 0;
    $handle = fopen($abs, 'r');
    if ($handle !== false) {
        while (fgets($handle) !== false) {
            $line_count++;
        }
        fclose($handle);
    }
    return max(0, $line_count - 1);
}

// 1) Primary source: generated_schedules table, optionally filtered by academic year.
$has_academic_year = has_generated_schedule_column($conn, 'academic_year');
$has_schedule_type = has_generated_schedule_column($conn, 'schedule_type');

$sql = "SELECT id, schedule_name, created_at, schedule_data, department"
    . ($has_academic_year ? ", academic_year" : "")
    . ($has_schedule_type ? ", schedule_type" : "")
    . " FROM generated_schedules WHERE 1=1";

if ($has_schedule_type) {
    if ($schedule_kind === 'exam') {
        $sql .= " AND (LOWER(TRIM(schedule_type)) = 'exam' OR LOWER(TRIM(schedule_name)) LIKE 'exam%')";
    } elseif ($schedule_kind === 'class') {
        $sql .= " AND (schedule_type IS NULL OR TRIM(schedule_type) = '' OR LOWER(TRIM(schedule_type)) = 'class')";
    }
} elseif ($schedule_kind === 'exam') {
    $sql .= " AND LOWER(TRIM(schedule_name)) LIKE 'exam%'";
}

if ($academic_year !== '' && $has_academic_year) {
    $sql .= " AND academic_year = ?";
}

$sql .= " ORDER BY created_at DESC LIMIT ?";

if ($stmt = $conn->prepare($sql)) {
    if ($academic_year !== '' && $has_academic_year) {
        $stmt->bind_param('si', $academic_year, $limit);
    } else {
        $stmt->bind_param('i', $limit);
    }
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $path = normalize_schedule_path($row['schedule_name'] ?? '');
        if ($path === '' || isset($seen_paths[$path])) continue;

        $name = basename($path);
        $created_at = $row['created_at'] ?? '';
        $created_ts = $created_at ? strtotime($created_at) : 0;

        $line_count = null;
        $decoded = json_decode($row['schedule_data'] ?? '', true);
        if (is_array($decoded) && count($decoded) > 0) {
            $line_count = max(0, count($decoded) - 1);
        } else {
            $line_count = local_line_count_for_path($path);
            $decoded = [];
        }

        $schedules[] = [
            'name' => $name,
            'path' => $path,
            'lines' => $line_count,
            'generated_at' => $created_ts ? date('M d, Y h:i A', $created_ts) : 'Unknown date',
            'timestamp' => $created_ts,
            'department' => $row['department'] ?? 'General',
            'academic_year' => $row['academic_year'] ?? '',
            'schedule_type' => $row['schedule_type'] ?? 'class',
            'csv_content' => $decoded,
            'source' => 'db'
        ];

        $seen_paths[$path] = true;
        if (count($schedules) >= $limit) break;
    }
}

// 2) Sorting newest first and capping results
usort($schedules, function($a, $b) {
    return (int)($b['timestamp'] ?? 0) <=> (int)($a['timestamp'] ?? 0);
});
$schedules = array_slice($schedules, 0, $limit);

echo json_encode([
    'status' => 'success',
    'schedules' => $schedules,
    'total' => count($schedules)
]);

