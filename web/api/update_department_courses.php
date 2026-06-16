<?php
// web/api/update_department_courses.php
// Updates departmental_courses.csv in B2 when new courses/sections are scheduled
// Handles duplicate course codes by generating unique suffixes

require_once 'db.php';
require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/../../lib/B2Storage.php';

/**
 * Sync current DB state to department_courses.csv in B2
 * Appends new courses and generates unique codes for duplicates
 * 
 * @param array $new_sections Optional: Array of specific section IDs to append (default: sync all)
 * @return array Result with status and message
 */
function update_department_courses_in_b2($new_sections = null) {
    global $conn;
    
    try {
        $b2 = new B2Storage();
        $csv_key = 'csv/department/departmental_courses.csv';
        
        // Download existing CSV from B2
        $existing_data = [];
        $existing_codes = [];
        $result = $b2->download($csv_key);
        
        if ($result['success'] && !empty($result['content'])) {
            $lines = explode("\n", trim($result['content']));
            $header = array_shift($lines); // Keep original header
            
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                
                // Parse CSV row
                $cols = str_getcsv($line);
                if (count($cols) > 0) {
                    $code = trim($cols[0]);
                    if ($code) {
                        $existing_codes[] = strtoupper($code);
                        $existing_data[] = $line;
                    }
                }
            }
        } else {
            // No existing file, create header
            $header = "course_code,course_title,lecturer_name,Semester,day,start_time,end_time,room_name,source_type,course_level,credit_hours";
        }
        
        // Build new rows from DB sections
        $new_rows = [];
        
        if ($new_sections !== null && is_array($new_sections) && count($new_sections) > 0) {
            // Only sync specific sections (append mode)
            $section_ids = implode(',', array_map('intval', $new_sections));
            $query = "SELECT 
                c.course_code, c.course_title, c.semester, c.type, c.level, c.credit_hours,
                l.name as lecturer_name,
                s.assigned_day as day,
                s.assigned_time as start_time,
                r.room_name,
                s.id as section_id
            FROM sections s
            INNER JOIN courses c ON s.course_id = c.id
            LEFT JOIN lecturers l ON s.lecturer_id = l.id
            LEFT JOIN rooms r ON s.room_id = r.id
            WHERE s.id IN ($section_ids)
            ORDER BY c.course_code";
        } else {
            // Full sync mode (all sections)
            $query = "SELECT 
                c.course_code, c.course_title, c.semester, c.type, c.level, c.credit_hours,
                l.name as lecturer_name,
                s.assigned_day as day,
                s.assigned_time as start_time,
                r.room_name,
                s.id as section_id
            FROM sections s
            INNER JOIN courses c ON s.course_id = c.id
            LEFT JOIN lecturers l ON s.lecturer_id = l.id
            LEFT JOIN rooms r ON s.room_id = r.id
            ORDER BY c.course_code";
        }
        
        $result = $conn->query($query);
        if (!$result) {
            throw new Exception("DB query failed: " . $conn->error);
        }
        
        $used_codes = $existing_codes; // Track codes to prevent duplicates in new additions
        
        while ($row = $result->fetch_assoc()) {
            $original_code = strtoupper(trim($row['course_code']));
            $course_code = $original_code;
            
            // Check if code already exists in B2 CSV
            if (in_array($course_code, $used_codes)) {
                // Generate unique code by appending suffix
                $suffix = 1;
                while (in_array($course_code . "_" . $suffix, $used_codes)) {
                    $suffix++;
                }
                $course_code = $course_code . "_" . $suffix;
            }
            
            // Add to used codes
            $used_codes[] = $course_code;
            
            // Build CSV row
            $csv_row = [
                $course_code,
                $row['course_title'] ?? '',
                $row['lecturer_name'] ?? '',
                $row['semester'] ?? '1',
                $row['day'] ?? '',
                $row['start_time'] ?? '',
                '', // end_time not tracked in sections table
                $row['room_name'] ?? '',
                $row['type'] ?? 'Departmental',
                $row['level'] ?? '100',
                $row['credit_hours'] ?? '3'
            ];
            
            // Escape and format as CSV
            $new_rows[] = implode(',', array_map(function($val) {
                // Escape quotes and wrap in quotes if contains comma or quotes
                if (strpos($val, ',') !== false || strpos($val, '"') !== false) {
                    return '"' . str_replace('"', '""', $val) . '"';
                }
                return $val;
            }, $csv_row));
        }
        
        // Combine existing + new rows
        $final_lines = [$header];
        $final_lines = array_merge($final_lines, $existing_data);
        
        if (count($new_rows) > 0) {
            $final_lines = array_merge($final_lines, $new_rows);
        }
        
        // Upload to B2
        $csv_content = implode("\n", $final_lines) . "\n";
        $upload_result = $b2->uploadContent($csv_key, $csv_content);
        
        if (!$upload_result['success']) {
            throw new Exception("Cloud upload failed: " . ($upload_result['message'] ?? 'Unknown error'));
        }
        
        return [
            'status' => 'success',
            'message' => 'Department courses CSV updated successfully',
            'rows_added' => count($new_rows),
            'total_rows' => count($final_lines) - 1 // excluding header
        ];
        
    } catch (Exception $e) {
        return [
            'status' => 'error',
            'message' => 'Failed to update department courses: ' . $e->getMessage()
        ];
    }
}

// If called directly via POST (for manual trigger)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_http_methods('POST');
    require_authenticated_user();
    require_admin_user();
    
    header('Content-Type: application/json');
    
    $input = json_decode(file_get_contents('php://input'), true);
    $section_ids = $input['section_ids'] ?? null;
    
    $result = update_department_courses_in_b2($section_ids);
    echo json_encode($result);
    exit;
}
?>
