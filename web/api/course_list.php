<?php
// course_list.php — Paginated, cached course list for dropdowns
require_once __DIR__ . '/../../config/bootstrap.php';

// Simple file cache (5 min)
$cache_dir = __DIR__ . '/../../temp/cache';
if (!is_dir($cache_dir)) {
    mkdir($cache_dir, 0777, true);
}
$cache_file = $cache_dir . '/course_list.json';
$cache_ttl = 300;

if (file_exists($cache_file) && (time() - filemtime($cache_file) < $cache_ttl)) {
    $courses = json_decode(file_get_contents($cache_file), true);
} else {
    $courses = [];
    $csv_path = __DIR__ . '/../../exam_general.csv';
    if (file_exists($csv_path)) {
        $csv = fopen($csv_path, 'r');
        while (($row = fgetcsv($csv)) !== false) {
            if (!empty($row[0]) && $row[0] !== 'code') {
                $courses[] = $row[0];
            }
        }
        fclose($csv);
        file_put_contents($cache_file, json_encode($courses));
    }
}

// Pagination
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = max(10, min(100, intval($_GET['per_page'] ?? 50)));
$total = count($courses);
$start = ($page - 1) * $per_page;
$paginated = array_slice($courses, $start, $per_page);
header('Content-Type: application/json');
echo json_encode([
    'status' => 'success',
    'data' => $paginated,
    'page' => $page,
    'per_page' => $per_page,
    'total' => $total,
    'total_pages' => ceil($total / $per_page)
]);
