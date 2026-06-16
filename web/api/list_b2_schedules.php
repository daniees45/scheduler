<?php
// web/api/list_b2_schedules.php
// List all generated schedules from B2 storage with metadata
require_once 'db.php';
require_once __DIR__ . '/auth_guard.php';
require_once '../../lib/B2Storage.php';

header('Content-Type: application/json');

require_http_methods('GET');
require_authenticated_user();

function normalize_schedule_name_key(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return '';
    }

    $filename = basename($name);
    if (!preg_match('/\.csv$/i', $filename)) {
        $filename .= '.csv';
    }

    return strtolower($filename);
}

function generated_schedules_has_column(mysqli $conn, string $column): bool
{
    $safe = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM generated_schedules LIKE '{$safe}'");
    return $res && $res->num_rows > 0;
}

function normalize_accuracy_percent($raw): ?string
{
    if (!is_string($raw) && !is_numeric($raw)) {
        return null;
    }

    $value = (float)$raw;
    if ($value < 0 || $value > 100) {
        return null;
    }

    $formatted = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    return $formatted . '%';
}

function extract_accuracy_from_details(string $details): string
{
    // Try explicit percentages FIRST (most specific - requires % sign)
    if (preg_match('/(\d{1,3}(?:\.\d+)?)\s*%/', $details, $matches)) {
        $normalized = normalize_accuracy_percent($matches[1]);
        if ($normalized !== null) {
            return $normalized;
        }
    }

    // Fallback to values explicitly tied to "accuracy" word (more permissive)
    if (preg_match('/accuracy[^0-9]{0,20}(\d{1,3}(?:\.\d+)?)/i', $details, $matches)) {
        $normalized = normalize_accuracy_percent($matches[1]);
        if ($normalized !== null) {
            return $normalized;
        }
    }

    return '0%';
}

try {
    $b2 = new B2Storage();
    
    // List all files in csv/final/ folder
    $result = $b2->listFiles('csv/final/');
    
    // Check if operation was successful
    if (!$result['success'] || empty($result['files'])) {
        echo json_encode([
            'status' => 'success',
            'schedules' => []
        ]);
        exit;
    }
    
    $files = $result['files'];
    
    // Check which files are saved in database
    $saved_files = [];
    $saved_meta = [];
    $has_academic_year = generated_schedules_has_column($conn, 'academic_year');
    $has_schedule_type = generated_schedules_has_column($conn, 'schedule_type');

    $sql = "SELECT schedule_name, accuracy"
        . ($has_academic_year ? ", academic_year" : "")
        . ($has_schedule_type ? ", schedule_type" : "")
        . " FROM generated_schedules ORDER BY created_at DESC, id DESC";

    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $key = normalize_schedule_name_key((string)($row['schedule_name'] ?? ''));
            if ($key === '') {
                continue;
            }

            $saved_files[$key] = true;
            if (!isset($saved_meta[$key])) {
                $saved_meta[$key] = [
                    'academic_year' => trim((string)($row['academic_year'] ?? '')),
                    'type' => trim((string)($row['schedule_type'] ?? '')),
                    'accuracy' => trim((string)($row['accuracy'] ?? '')),
                ];
            }
        }
    }
    
    // Parse metadata from filenames and enrich data
    $schedules = [];
    foreach ($files as $file) {
        $filename = basename($file['key']);
        $saved_key = normalize_schedule_name_key($filename);
        
        // Parse metadata from filenames and enrich data
        $metadata = parseFilename($filename);
        $db_meta = $saved_meta[$saved_key] ?? [];
        
        // Convert timestamp to readable date
        $uploaded_date = '';
        if (isset($file['modified'])) {
            $uploaded_date = date('Y-m-d H:i:s', $file['modified']);
        }
        
        // Prefer DB accuracy if schedule was already saved, fallback to audit logs.
        $accuracy = normalize_accuracy_percent($db_meta['accuracy'] ?? '') ?? '0%';
        if ($accuracy === '0%') {
            $pure_name = pathinfo($filename, PATHINFO_FILENAME);
            $stmt = $conn->prepare("SELECT details FROM audit_log WHERE action IN ('SCHEDULE_GEN_SUCCESS','EXAM_GEN_SUCCESS','EXAM_COMBINED_SUCCESS') AND details LIKE ? ORDER BY log_time DESC LIMIT 1");
            $search_term = "%" . $pure_name . "%";
            $stmt->bind_param("s", $search_term);
            $stmt->execute();
            $log_res = $stmt->get_result();
            if ($log_row = $log_res->fetch_assoc()) {
                $accuracy = extract_accuracy_from_details((string)($log_row['details'] ?? ''));
            }
        }
        
        $schedules[] = [
            'file' => $file['key'],
            'name' => $filename,
            'size' => $file['size'] ?? 0,
            'uploaded' => $uploaded_date,
            'semester' => $metadata['semester'] ?? '',
            'department' => $metadata['department'] ?? 'General',
            'type' => $db_meta['type'] !== '' ? $db_meta['type'] : ($metadata['type'] ?? 'class'),
            'academic_year' => $db_meta['academic_year'] ?? '',
            'accuracy' => $accuracy,
            'saved_to_db' => isset($saved_files[$saved_key])
        ];
    }
    
    // Sort by upload time (newest first)
    usort($schedules, function($a, $b) {
        return strtotime($b['uploaded']) - strtotime($a['uploaded']);
    });
    
    echo json_encode([
        'status' => 'success',
        'schedules' => $schedules
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}

function parseFilename($filename) {
    // Remove .csv extension
    $name = str_replace('.csv', '', $filename);
    
    // Try to parse structured filename
    $parts = explode('_', $name);
    
    $metadata = [
        'type' => 'class',
        'semester' => '',
        'department' => 'General'
    ];
    
    // Pattern: {type}_{semester}_{department}_{timestamp}
    if (count($parts) >= 3) {
        if (in_array($parts[0], ['class', 'exam'])) {
            $metadata['type'] = $parts[0];
        }
        
        if (is_numeric($parts[1]) && in_array($parts[1], ['1', '2', '3'])) {
            $metadata['semester'] = $parts[1];
        }
        
        // Department could be multi-word or abbreviated
        if (isset($parts[2])) {
            $dept_part = $parts[2];
            
            // Map common abbreviations
            $dept_map = [
                'CS' => 'CS/IT/BBIS',
                'IT' => 'CS/IT/BBIS',
                'BBIS' => 'CS/IT/BBIS',
                'Business' => 'Business',
                'BUSI' => 'Business',
                'ACCT' => 'Business',
                'Nursing' => 'Nursing',
                'NURS' => 'Nursing',
                'Theology' => 'Theology',
                'RELB' => 'Theology',
                'General' => 'General'
            ];
            
            $metadata['department'] = $dept_map[$dept_part] ?? $dept_part;
        }
    }
    
    return $metadata;
}
?>
