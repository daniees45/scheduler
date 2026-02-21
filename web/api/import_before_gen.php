<?php
// as/api/import_before_gen.php
header('Content-Type: application/json');
require_once 'db.php';

$base_dir = realpath('../../') . '/';

$stats = ['rooms' => 0, 'lecturers' => 0];

try {
    // 1. Import Rooms
    $rooms_file = $base_dir . 'rooms.csv';
    if (file_exists($rooms_file) && ($handle = fopen($rooms_file, "r")) !== FALSE) {
        fgetcsv($handle); // Skip header logic if needed, usually rooms.csv has headers
        // Prepare INSERT IGNORE to skip duplicates based on room_name (assuming unique constraint)
        // If no unique constraint, we should check first.
        // Let's assume room_name is unique. If not, we might duplicates.
        // We'll use a check-first approach to be safe.
        
        $check = $conn->prepare("SELECT id FROM rooms WHERE room_name = ?");
        $insert = $conn->prepare("INSERT INTO rooms (room_name, capacity) VALUES (?, ?)");
        
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            $name = $data[0] ?? '';
            $cap = $data[1] ?? 50;
            if (!$name) continue;
            
            $check->bind_param("s", $name);
            $check->execute();
            if ($check->get_result()->num_rows === 0) {
                // Not found, insert
                $insert->bind_param("si", $name, $cap);
                $insert->execute();
                $stats['rooms']++;
            }
        }
        fclose($handle);
    }

    // 2. Import Lecturers
    $lect_file = $base_dir . 'lecturer_availability.csv';
    if (file_exists($lect_file) && ($handle = fopen($lect_file, "r")) !== FALSE) {
        fgetcsv($handle); // Skip header
        
        $check = $conn->prepare("SELECT id FROM lecturers WHERE name = ?");
        $insert = $conn->prepare("INSERT INTO lecturers (name, availability_json) VALUES (?, ?)");
        
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            $name = $data[0] ?? '';
            if (!$name) continue;
            
            $check->bind_param("s", $name);
            $check->execute();
            if ($check->get_result()->num_rows === 0) {
                // Build availability JSON
                $avail = [];
                if(($data[1]??0) == 1) $avail[] = 0;
                if(($data[2]??0) == 1) $avail[] = 1;
                if(($data[3]??0) == 1) $avail[] = 2;
                if(($data[4]??0) == 1) $avail[] = 3;
                if(($data[5]??0) == 1) $avail[] = 4;
                $json = json_encode($avail);
                
                $insert->bind_param("ss", $name, $json);
                $insert->execute();
                $stats['lecturers']++;
            }
        }
        fclose($handle);
    }

    echo json_encode([
        "status" => "success", 
        "message" => "Imported {$stats['rooms']} new rooms and {$stats['lecturers']} new lecturers.",
        "stats" => $stats
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
