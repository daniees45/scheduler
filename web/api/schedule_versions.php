<?php
// as/api/schedule_versions.php
ini_set('display_errors', 0);
header('Content-Type: application/json');
require_once 'db.php';
require_once __DIR__ . '/../../lib/B2Storage.php';

$b2 = new B2Storage();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$action = $_POST['action'] ?? '';
$chosen_file = $_POST['file_name'] ?? 'csv/final/final_web_schedule.csv';

// Ensure the path is correct (handle both basename and relative path)
if (strpos($chosen_file, 'csv/final/') === false && strpos($chosen_file, '/') === false) {
    $chosen_file = 'csv/final/' . $chosen_file;
}

$csv_path = realpath('../../') . '/' . $chosen_file;

// Helper: convert schedule_data (array) to CSV string
function schedule_array_to_csv($rows) {
    $csv = '';
    foreach ($rows as $row) {
        $f = fopen('php://temp', 'r+');
        fputcsv($f, $row, ',', '"', '\\');
        rewind($f);
        $csv .= stream_get_contents($f);
        fclose($f);
    }
    return $csv;
}

try {
    if ($action === 'save') {
        $name = $_POST['name'] ?? 'Untitled Version';
        $desc = $_POST['description'] ?? '';
        $user_id = $_SESSION['user_id'] ?? null;
        $schedule_id = $_POST['schedule_id'] ?? null;

        // Prefer specific DB schedule if provided
        $content = null;
        if ($schedule_id) {
            $stmt = $conn->prepare("SELECT schedule_name, semester, department, accuracy, created_at, schedule_data FROM generated_schedules WHERE id = ? LIMIT 1");
            $stmt->bind_param("i", $schedule_id);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $json = $row['schedule_data'] ?? '';
                $decoded = json_decode($json, true);
                if (is_array($decoded) && count($decoded) > 0) {
                    $content = schedule_array_to_csv($decoded);
                }
                // Auto metadata if description not provided
                if (!$desc) {
                    $desc_parts = [];
                    if (!empty($row['schedule_name'])) $desc_parts[] = "Name: {$row['schedule_name']}";
                    if (!empty($row['semester'])) $desc_parts[] = "Semester: {$row['semester']}";
                    if (!empty($row['department'])) $desc_parts[] = "Dept: {$row['department']}";
                    if (!empty($row['accuracy'])) $desc_parts[] = "Accuracy: {$row['accuracy']}";
                    if (!empty($row['created_at'])) $desc_parts[] = "Generated: {$row['created_at']}";
                    $desc = implode(' | ', $desc_parts);
                }
            }
        }

        // Fallback to latest DB schedule
        if ($content === null) {
            $res = $conn->query("SELECT schedule_name, semester, department, accuracy, created_at, schedule_data FROM generated_schedules ORDER BY created_at DESC LIMIT 1");
            if ($res && $row = $res->fetch_assoc()) {
                $json = $row['schedule_data'] ?? '';
                $decoded = json_decode($json, true);
                if (is_array($decoded) && count($decoded) > 0) {
                    $content = schedule_array_to_csv($decoded);
                }
                if (!$desc) {
                    $desc_parts = [];
                    if (!empty($row['schedule_name'])) $desc_parts[] = "Name: {$row['schedule_name']}";
                    if (!empty($row['semester'])) $desc_parts[] = "Semester: {$row['semester']}";
                    if (!empty($row['department'])) $desc_parts[] = "Dept: {$row['department']}";
                    if (!empty($row['accuracy'])) $desc_parts[] = "Accuracy: {$row['accuracy']}";
                    if (!empty($row['created_at'])) $desc_parts[] = "Generated: {$row['created_at']}";
                    $desc = implode(' | ', $desc_parts);
                }
            }
        }

        // Fallback to CSV file or B2
        $temp_csv = null;
        if ($content === null) {
            if (!file_exists($csv_path)) {
                // Try B2
                $b2_result = $b2->download($chosen_file);
                if ($b2_result['success']) {
                    $content = $b2_result['content'];
                    // We need a local file for PDF generation below
                    $temp_csv = tempnam(sys_get_temp_dir(), 'sched_csv_');
                    file_put_contents($temp_csv, $content);
                    $csv_path = $temp_csv;
                } else {
                    throw new Exception("No current schedule data found (DB, Local CSV, or B2).");
                }
            } else {
                $content = file_get_contents($csv_path);
            }
        }
        
        // Generate PDF
        $temp_pdf = tempnam(sys_get_temp_dir(), 'sched_') . '.pdf';
        $py_script = realpath('../../csv_to_pdf.py');
        
        // Use full path to python3 to avoid PATH issues with web server
        $python_cmd = '/usr/local/bin/python3';
        if (!file_exists($python_cmd)) {
            // Fallback to python3 in PATH
            $python_cmd = 'python3';
        }
        
        $cmd = $python_cmd . " " . escapeshellarg($py_script) . " " . escapeshellarg($csv_path) . " " . escapeshellarg($temp_pdf);
        
        $output = shell_exec($cmd . " 2>&1");
        
        $pdf_content = null;
        if (file_exists($temp_pdf)) {
            $pdf_content = file_get_contents($temp_pdf);
            @unlink($temp_pdf);
        } else {
            // Log error but don't fail the whole CSV save
            error_log("PDF generation failed: " . $output);
        }
        
        // Clean up temp CSV if created from B2
        if ($temp_csv && file_exists($temp_csv)) {
            @unlink($temp_csv);
        }
        
        $stmt = $conn->prepare("INSERT INTO schedule_versions (version_name, description, file_content, pdf_content, user_id) VALUES (?, ?, ?, ?, ?)");
        $null = NULL;
        $stmt->bind_param("ssssi", $name, $desc, $content, $null, $user_id);
        
        if ($pdf_content !== null) {
            $stmt->send_long_data(3, $pdf_content);
        }
        
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Version saved successfully.', 'id' => $conn->insert_id]);
        } else {
            throw new Exception("Database save failed: " . $stmt->error);
        }

    } elseif ($action === 'load') {
        $id = $_POST['id'] ?? 0;

        $stmt = $conn->prepare("SELECT file_content FROM schedule_versions WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($row = $res->fetch_assoc()) {
            $file_content = $row['file_content'] ?? '';
            if (!$file_content) {
                throw new Exception("Version content is empty.");
            }

            if (file_put_contents($csv_path, $file_content) === FALSE) {
                throw new Exception("Failed to write to CSV file.");
            }

            // Also store into generated_schedules for DB-based views
            $lines = str_getcsv($file_content, "\n");
            $rows = [];
            foreach ($lines as $line) {
                if ($line === '') continue;
                $rows[] = str_getcsv($line);
            }
            $schedule_json = json_encode($rows);
            $check_col = $conn->query("SHOW COLUMNS FROM generated_schedules LIKE 'academic_year'");
            $has_academic_year = $check_col && $check_col->num_rows > 0;

            $academic_year = '';
            $set_res = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'current_academic_year' LIMIT 1");
            if ($set_res && ($set_row = $set_res->fetch_assoc())) {
                $academic_year = trim((string)($set_row['setting_value'] ?? ''));
            }
            if (!preg_match('/^\d{4}\/\d{4}$/', $academic_year)) {
                $academic_year = date('Y') . '/' . (date('Y') + 1);
            }

            if ($has_academic_year) {
                $stmt2 = $conn->prepare("INSERT INTO generated_schedules (schedule_name, semester, academic_year, department, accuracy, schedule_data, generated_by)
                                         VALUES (?, ?, ?, ?, ?, ?, ?)");
            } else {
                $stmt2 = $conn->prepare("INSERT INTO generated_schedules (schedule_name, semester, department, accuracy, schedule_data, generated_by)
                                         VALUES (?, ?, ?, ?, ?, ?)");
            }
            $schedule_name = 'Loaded Version #' . $id;
            $semester = $_POST['semester'] ?? '';
            $department = $_POST['department'] ?? '';
            $accuracy = '';
            $gen_by = $_SESSION['user_id'] ?? 0;
            if ($has_academic_year) {
                $stmt2->bind_param("ssssssi", $schedule_name, $semester, $academic_year, $department, $accuracy, $schedule_json, $gen_by);
            } else {
                $stmt2->bind_param("sssssi", $schedule_name, $semester, $department, $accuracy, $schedule_json, $gen_by);
            }
            $stmt2->execute();

            echo json_encode(['status' => 'success', 'message' => 'Version loaded successfully.']);
        } else {
            throw new Exception("Version not found.");
        }

    } elseif ($action === 'list') {
        $res = $conn->query("SELECT id, version_name, description, created_at FROM schedule_versions ORDER BY created_at DESC");
        $versions = $res->fetch_all(MYSQLI_ASSOC);
        echo json_encode(['status' => 'success', 'versions' => $versions]);
        
    } else {
        throw new Exception("Invalid action.");
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
