<?php
// web/api/sync.php
// Exports MySQL tables to CSV files in B2 storage for the AI engine
header('Content-Type: application/json');

require_once 'db.php';
require_once __DIR__ . '/../../lib/B2Storage.php';

$b2 = new B2Storage();

function get_department_from_code($course_code) {
    $code = strtoupper($course_code);
    
    // CS/IT/BBIS Group
    if (preg_match('/(COSC|INFT|BBIS|CSCD)/', $code)) return 'CS/IT/BBIS';
    // Business Group
    if (preg_match('/(ACCT|BUSI|MGMT|ECON|MKTG|FNCE)/', $code)) return 'Business';
    // Education Group
    if (preg_match('/(EDUC|PEDC|TEAC|CLED)/', $code)) return 'Education';
    // Development Studies Group
    if (preg_match('/(DEVS|INTL|AFRI|AFRN)/', $code)) return 'DevelopmentStudies';
    // Biomedical Engineering Group
    if (preg_match('/(BIOM|ENGR|BENG|HLTC)/', $code)) return 'BiomedicalEngineering';
    // Nursing Group  
    if (preg_match('/(NURS|RNSG|MIDW)/', $code)) return 'Nursing';
    // Theology Group
    if (preg_match('/(RELB|RELT)/', $code)) return 'Theology';
    
    return 'General';
}

function export_to_csv_b2($filename, $headers, $data, $b2) {
    // Generate CSV content in memory
    $handle = fopen('php://temp', 'r+');
    fputcsv($handle, $headers);
    foreach ($data as $row) {
        fputcsv($handle, $row);
    }
    rewind($handle);
    $content = stream_get_contents($handle);
    fclose($handle);
    
    // Upload to B2
    $result = $b2->uploadContent($content, $filename, [
        'source' => 'db_sync',
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
    if (!$result['success']) {
        throw new Exception("Could not upload $filename to B2: " . $result['error']);
    }
    
    // Clean up old versions to prevent accumulation
    $cleanup = $b2->deleteOldVersions($filename);
    if ($cleanup['deleted_count'] > 0) {
        error_log("B2: Cleaned up {$cleanup['deleted_count']} old versions of $filename");
    }
    
    return $result;
}

try {
    // NOTE: Room CSVs are NOT exported here. Each department's room CSV (csv/general/rooms.csv,
    // csv/department/*_rooms.csv) is managed directly via the Rooms Management UI and B2.
    // Exporting the merged DB rooms table back to csv/general/rooms.csv would pollute it
    // with all department rooms, breaking the per-source isolation.

    // 2. Export Lecturers
    $res = $conn->query("SELECT name, availability_json FROM lecturers");
    if (!$res) throw new Exception("Error fetching lecturers: " . $conn->error);
    $lecturers_csv = [];
    while ($l = $res->fetch_assoc()) {
        $avail = json_decode($l['availability_json'] ?? '[]', true);
        if (!is_array($avail)) $avail = [0,1,2,3,4]; // Default full
        
        $lecturers_csv[] = [
            $l['name'],
            in_array(0, $avail) ? 1 : 0,
            in_array(1, $avail) ? 1 : 0,
            in_array(2, $avail) ? 1 : 0,
            in_array(3, $avail) ? 1 : 0,
            in_array(4, $avail) ? 1 : 0
        ];
    }
    export_to_csv_b2('csv/general/lecturer_availability.csv', ['lecturer_name', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri'], $lecturers_csv, $b2);

    // 3. Export Courses/Sections with proper department detection
    $sql = "SELECT c.course_code, c.course_title, l.name as lecturer_name, c.semester, 
                   s.assigned_day as day, s.assigned_time as start_time, '' as end_time, 
                   r.room_name, c.type as source_type, c.level as course_level, c.credit_hours
            FROM courses c
            JOIN sections s ON c.id = s.course_id
            LEFT JOIN lecturers l ON s.lecturer_id = l.id
            LEFT JOIN rooms r ON s.room_id = r.id";
            
    $sections_res = $conn->query($sql);
    if (!$sections_res) throw new Exception("Error fetching sections: " . $conn->error);
    
    // Process sections to add proper department names
    $sections = [];
    while ($row = $sections_res->fetch_assoc()) {
        // If source_type is generic (Departmental), derive from course code
        $source_type = $row['source_type'];
        if (in_array($source_type, ['Departmental', 'General', '', null])) {
            $source_type = get_department_from_code($row['course_code']);
        }
        
        $sections[] = [
            $row['course_code'],
            $row['course_title'],
            $row['lecturer_name'],
            $row['semester'],
            $row['day'],
            $row['start_time'],
            $row['end_time'],
            $row['room_name'],
            $source_type,  // Use derived department
            $row['course_level'],
            $row['credit_hours']
        ];
    }
    
    $headers = ['course_code','course_title','lecturer_name','Semester','day','start_time','end_time','room_name','source_type','course_level','credit_hours'];
    export_to_csv_b2('csv/department/departmental_courses.csv', $headers, $sections, $b2);

    // 4. Export Special Rooms (Course-to-Room Pre-assignments)
    if (function_exists('ensure_special_rooms_table')) {
        ensure_special_rooms_table($conn);
    }

    $special_table = $conn->query("SHOW TABLES LIKE 'special_rooms'");
    if ($special_table && $special_table->num_rows > 0) {
        $special_res = $conn->query("SELECT course_code, room_name, fixed_day, fixed_time FROM special_rooms");
        if ($special_res) {
            $special_rooms = $special_res->fetch_all(MYSQLI_NUM);
            export_to_csv_b2('csv/general/special_rooms.csv', ['course_code', 'room_name', 'fixed_day', 'fixed_time'], $special_rooms, $b2);
        } else {
            export_to_csv_b2('csv/general/special_rooms.csv', ['course_code', 'room_name', 'fixed_day', 'fixed_time'], [], $b2);
        }
    } else {
        // Table missing: export an empty file without throwing a DB warning
        export_to_csv_b2('csv/general/special_rooms.csv', ['course_code', 'room_name', 'fixed_day', 'fixed_time'], [], $b2);
    }

    echo json_encode([
        "status" => "success", 
        "message" => "Database synced to B2 successfully",
        "storage" => "B2"
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
