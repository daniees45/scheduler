<?php
// as/api/run_analyzer.php
require_once 'db.php';
require_once __DIR__ . '/auth_guard.php';
header('Content-Type: application/json');

require_http_methods('POST');
require_authenticated_user();
require_admin_user();

$root_dir = realpath('../../') . '/';
$analyzer_path = $root_dir . 'analyzer.py';
$venv_python = $root_dir . '.venv/bin/python';
$python_bin = file_exists($venv_python) ? $venv_python : '/usr/local/bin/python3';
$cmd = "cd " . escapeshellarg($root_dir) . " && " . escapeshellarg($python_bin) . " " . escapeshellarg($analyzer_path) . " 2>&1";
$output_lines = [];
$exit_code = 0;
exec($cmd, $output_lines, $exit_code);

echo json_encode([
    'status' => $exit_code === 0 ? 'success' : 'error',
    'message' => $exit_code === 0 ? 'AI Analysis completed.' : 'AI Analysis failed. Check server logs.',
    'output' => implode("\n", array_slice($output_lines, -40))
]);
