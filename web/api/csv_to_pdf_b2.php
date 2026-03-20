<?php
// web/api/csv_to_pdf_b2.php
// Export CSV from B2 to PDF with optional upload back to B2

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

session_start();
require_once 'db.php';
require_once __DIR__ . '/../../lib/B2Storage.php';

function json_error(string $message, int $status = 500, array $extra = []): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json');
    }

    echo json_encode(array_merge([
        'status' => 'error',
        'message' => $message,
    ], $extra));
    exit;
}

function create_temp_file_path(string $label, string $suffix = ''): string
{
    $candidates = [
        (string)ini_get('upload_tmp_dir'),
        sys_get_temp_dir(),
        '/private/tmp',
        '/tmp',
    ];

    foreach ($candidates as $candidate) {
        $candidate = rtrim((string)$candidate, '/');
        if ($candidate === '') {
            continue;
        }

        $tmp = @tempnam($candidate, 'vvu_pdf_');
        if ($tmp !== false && is_string($tmp) && $tmp !== '') {
            if ($suffix !== '' && !str_ends_with($tmp, $suffix)) {
                $with_suffix = $tmp . $suffix;
                if (@rename($tmp, $with_suffix)) {
                    return $with_suffix;
                }
                @unlink($tmp);
                continue;
            }
            return $tmp;
        }
    }

    $fallback = @tempnam('', 'vvu_pdf_');
    if ($fallback !== false && is_string($fallback) && $fallback !== '') {
        if ($suffix !== '' && !str_ends_with($fallback, $suffix)) {
            $with_suffix = $fallback . $suffix;
            if (@rename($fallback, $with_suffix)) {
                return $with_suffix;
            }
            @unlink($fallback);
        } else {
            return $fallback;
        }
    }

    throw new RuntimeException('No writable temp file location available');
}

if (!isset($_SESSION['user_id'])) {
    json_error('Unauthorized', 401);
}

$raw = file_get_contents('php://input');
$input = json_decode($raw ?: '{}', true);
if (!is_array($input)) {
    json_error('Invalid JSON payload', 400);
}

$csv_key = (string)($input['csv_file'] ?? '');
$upload_to_b2 = (bool)($input['upload_to_b2'] ?? false);
$pdf_filename = trim((string)($input['pdf_filename'] ?? ''));
$return_download = (bool)($input['return_download'] ?? false);

// If client requested direct download, never attempt B2 upload
if ($return_download) {
    $upload_to_b2 = false;
}

if ($csv_key === '') {
    json_error('CSV file path required', 400);
}

$temp_csv = null;
$temp_pdf = null;

try {
    $b2 = new B2Storage();

    // Download CSV from B2
    $csv_result = $b2->download($csv_key);
    if (empty($csv_result['success'])) {
        throw new RuntimeException('Failed to download CSV from B2: ' . ($csv_result['message'] ?? 'Unknown error'));
    }

    $temp_csv = create_temp_file_path('csv', '.csv');
    if (file_put_contents($temp_csv, (string)$csv_result['content']) === false) {
        throw new RuntimeException('Failed to write CSV temp file');
    }

    if (!is_file($temp_csv) || filesize($temp_csv) === 0) {
        throw new RuntimeException('CSV temp file is empty');
    }

    // Detect CSV format by checking for "Invigilator" column
    $is_exam_format = false;
    $csv_handle = fopen($temp_csv, 'r');
    if ($csv_handle) {
        $headers = fgetcsv($csv_handle, 0, ',', '"', '\\');
        if ($headers) {
            $headers_lower = array_map('strtolower', $headers);
            $is_exam_format = in_array('invigilator', $headers_lower);
        }
        fclose($csv_handle);
    }

    // Set default headers based on format detection
    if ($is_exam_format) {
        // Exam format headers
        $h1 = (string)($input['h1'] ?? 'VALLEY VIEW UNIVERSITY');
        $h2 = (string)($input['h2'] ?? 'EXAMINATION TIMETABLE');
        $h3 = (string)($input['h3'] ?? 'SECOND SEMESTER - 2025 / 2026 ACADEMIC YEAR');
        $h4 = (string)($input['h4'] ?? 'EXAM SCHEDULE');
    } else {
        // Class format headers
        $h1 = (string)($input['h1'] ?? 'VALLEY VIEW UNIVERSITY');
        $h2 = (string)($input['h2'] ?? 'COMPUTER SCIENCE, INFORMATION TECHNOLOGY & BUSINESS INFORMATION SYSTEMS');
        $h3 = (string)($input['h3'] ?? 'SECOND SEMESTER - 2025 / 2026 ACADEMIC YEAR');
        $h4 = (string)($input['h4'] ?? 'TEACHING TIMETABLE');
    }

    // Generate PDF
    $temp_pdf = create_temp_file_path('pdf', '.pdf');

    $py_script = realpath(__DIR__ . '/../../csv_to_pdf.py');
    if ($py_script === false || !is_file($py_script)) {
        throw new RuntimeException('PDF generator script not found');
    }

    $python_cmd = '/usr/local/bin/python3';
    if (!is_file($python_cmd)) {
        $python_cmd = 'python3';
    }

    $cmd = $python_cmd . ' ' . escapeshellarg($py_script)
        . ' --input ' . escapeshellarg($temp_csv)
        . ' --output ' . escapeshellarg($temp_pdf)
        . ' --h1 ' . escapeshellarg($h1)
        . ' --h2 ' . escapeshellarg($h2)
        . ' --h3 ' . escapeshellarg($h3)
        . ' --h4 ' . escapeshellarg($h4)
        . ' 2>&1';

    error_log('CSV to PDF command: ' . $cmd);
    $output = shell_exec($cmd);
    error_log('CSV to PDF output: ' . (string)$output);

    if (!is_file($temp_pdf) || filesize($temp_pdf) === 0) {
        throw new RuntimeException('PDF generation failed: ' . trim((string)$output));
    }

    $pdf_content = file_get_contents($temp_pdf);
    if ($pdf_content === false) {
        throw new RuntimeException('Failed to read generated PDF');
    }
    $pdf_size = filesize($temp_pdf);

    // Optional upload to B2
    $b2_pdf_key = null;
    if ($upload_to_b2 && !$return_download) {
        if ($pdf_filename === '') {
            $csv_basename = basename($csv_key, '.csv');
            $pdf_filename = $csv_basename . '_' . date('Y-m-d') . '.pdf';
        }

        $b2_pdf_key = 'pdf/' . $pdf_filename;
        $upload_result = $b2->uploadContent($b2_pdf_key, $pdf_content);
        if (empty($upload_result['success'])) {
            error_log('B2 upload failed: ' . ($upload_result['message'] ?? 'Unknown error'));
        }
    }

    // Download response
    if ($return_download) {
        $filename = $pdf_filename !== '' ? $pdf_filename : (basename($csv_key, '.csv') . '.pdf');

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (!headers_sent()) {
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . (string)$pdf_size);
        }

        echo $pdf_content;

        @unlink($temp_csv);
        @unlink($temp_pdf);
        exit;
    }

    // JSON response
    if (!headers_sent()) {
        header('Content-Type: application/json');
    }

    echo json_encode([
        'status' => 'success',
        'message' => $b2_pdf_key ? 'PDF generated successfully and uploaded to B2' : 'PDF generated successfully',
        'pdf_size' => $pdf_size,
        'csv_source' => $csv_key,
        'b2_location' => $b2_pdf_key,
    ]);

    @unlink($temp_csv);
    @unlink($temp_pdf);
    exit;

} catch (Throwable $e) {
    error_log('csv_to_pdf_b2.php error: ' . $e->getMessage());
    error_log($e->getTraceAsString());

    if ($temp_csv && is_file($temp_csv)) {
        @unlink($temp_csv);
    }
    if ($temp_pdf && is_file($temp_pdf)) {
        @unlink($temp_pdf);
    }

    json_error($e->getMessage(), 500, [
        'file' => basename($e->getFile()),
        'line' => $e->getLine(),
    ]);
}
