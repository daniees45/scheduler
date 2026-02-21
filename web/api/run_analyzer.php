<?php
// as/api/run_analyzer.php
header('Content-Type: application/json');

$root_dir = realpath('../../') . '/';

$python_code = "
import sys
import os
sys.path.append('.')
try:
    import analyzer
    print('Starting analyzer...')
    # Trigger training logic here if applicable
    os.system('/usr/local/bin/python3 analyzer.py') 
    print('Analyzer finished')
except Exception as e:
    print(f'Python Error: {str(e)}')
";

// EXPLICITLY USE /usr/local/bin/python3
$cmd = "cd " . escapeshellarg($root_dir) . " && /usr/local/bin/python3 -c " . escapeshellarg($python_code) . " 2>&1";
$output = shell_exec($cmd);

echo json_encode([
    'status' => 'success',
    'message' => 'AI Analysis triggered.',
    'output' => $output
]);
