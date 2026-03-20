<?php
/**
 * API to save edited data (rooms, lecturers, courses)
 */

session_start();
header('Content-Type: application/json');
require_once 'db.php';
require_once __DIR__ . '/../../lib/B2Storage.php';
require_once 'room_sync_helper.php';

// Access control
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$action = $data['action'] ?? null;

function normalize_room_source_key(?string $source_key): string
{
    $source_key = trim((string) ($source_key ?? ''));
    return $source_key !== '' ? $source_key : 'csv/general/rooms.csv';
}

function write_room_change_to_sources(mysqli $conn, B2Storage $b2, ?int $id, string $name, int $capacity, ?string $source_key = null): array
{
    $source_key = normalize_room_source_key($source_key);
    $old_name = null;
    $target_sources = [$source_key];

    if ($id) {
        $stmt = $conn->prepare('SELECT room_name FROM rooms WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $existing = $result ? $result->fetch_assoc() : null;
        if (!$existing) {
            throw new Exception('Room not found');
        }

        $old_name = trim((string) ($existing['room_name'] ?? ''));
        if ($source_key === 'csv/general/rooms.csv') {
            $matched_sources = find_room_source_keys_by_name($b2, $old_name);
            if (!empty($matched_sources)) {
                $target_sources = $matched_sources;
            }
        }
    }

    foreach ($target_sources as $target_source) {
        $rows = load_room_rows_from_storage($b2, $target_source);
        $updated = false;

        foreach ($rows as &$row) {
            $row_name = trim((string) ($row['room_name'] ?? ''));
            if ($id && $old_name !== null && strcasecmp($row_name, $old_name) === 0) {
                $row['room_name'] = $name;
                $row['capacity'] = $capacity;
                $updated = true;
                break;
            }

            if (!$id && strcasecmp($row_name, $name) === 0) {
                $row['capacity'] = $capacity;
                $updated = true;
                break;
            }
        }
        unset($row);

        if (!$updated) {
            $rows[] = ['room_name' => $name, 'capacity' => $capacity];
        }

        save_room_rows_to_storage($b2, $target_source, $rows);
    }

    sync_all_room_files_to_db($conn, $b2, false);

    $lookup = $conn->prepare('SELECT id FROM rooms WHERE room_name = ? ORDER BY id ASC LIMIT 1');
    $lookup->bind_param('s', $name);
    $lookup->execute();
    $lookup_result = $lookup->get_result();
    $saved = $lookup_result ? $lookup_result->fetch_assoc() : null;

    return [
        'id' => (int) ($saved['id'] ?? 0),
        'source_keys' => array_values(array_unique($target_sources)),
    ];
}

function delete_room_from_sources(mysqli $conn, B2Storage $b2, int $id, ?string $source_key = null): array
{
    $stmt = $conn->prepare('SELECT room_name FROM rooms WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $existing = $result ? $result->fetch_assoc() : null;
    if (!$existing) {
        throw new Exception('Room not found');
    }

    $room_name = trim((string) ($existing['room_name'] ?? ''));
    $target_sources = [];

    if ($source_key) {
        $target_sources[] = normalize_room_source_key($source_key);
    } else {
        $target_sources = find_room_source_keys_by_name($b2, $room_name);
        if (empty($target_sources)) {
            $target_sources[] = 'csv/general/rooms.csv';
        }
    }

    foreach ($target_sources as $target_source) {
        $rows = load_room_rows_from_storage($b2, $target_source);
        $filtered = array_values(array_filter($rows, static function ($row) use ($room_name) {
            return strcasecmp(trim((string) ($row['room_name'] ?? '')), $room_name) !== 0;
        }));
        save_room_rows_to_storage($b2, $target_source, $filtered);
    }

    sync_all_room_files_to_db($conn, $b2, false);

    return [
        'room_name' => $room_name,
        'source_keys' => array_values(array_unique($target_sources)),
    ];
}

if ($action === 'save_room') {
    $id = isset($data['id']) ? (int) $data['id'] : null;
    $name = trim((string) ($data['name'] ?? ''));
    $capacity = max(1, (int) ($data['capacity'] ?? 50));
    $source_key = $data['source_key'] ?? null;
    
    if (!$name) {
        echo json_encode(['status' => 'error', 'message' => 'Room name required']);
        exit;
    }

    try {
        $b2 = new B2Storage();
        $saved = write_room_change_to_sources($conn, $b2, $id, $name, $capacity, $source_key);
        echo json_encode([
            'status' => 'success',
            'message' => 'Room saved and synced through room CSV sources',
            'id' => $saved['id'],
            'exported' => true,
            'source_keys' => $saved['source_keys']
        ]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
} 
elseif ($action === 'delete_room') {
    $id = isset($data['id']) ? (int) $data['id'] : null;
    $source_key = $data['source_key'] ?? null;
    if (!$id) {
        echo json_encode(['status' => 'error', 'message' => 'Room ID required']);
        exit;
    }

    try {
        $b2 = new B2Storage();
        $deleted = delete_room_from_sources($conn, $b2, $id, $source_key);
        echo json_encode([
            'status' => 'success',
            'message' => 'Room deleted and synced through room CSV sources',
            'room_name' => $deleted['room_name'],
            'source_keys' => $deleted['source_keys']
        ]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
}
elseif ($action === 'save_lecturer') {
    $id = $data['id'] ?? null;
    $name = $data['name'] ?? '';
    $avail = $data['availability'] ?? [];
    
    if (!$name) {
        echo json_encode(['status' => 'error', 'message' => 'Lecturer name required']);
        exit;
    }
    
    $avail_json = json_encode($avail);
    
    if ($id) {
        // Update
        $stmt = $conn->prepare("UPDATE lecturers SET name = ?, availability_json = ? WHERE id = ?");
        $stmt->bind_param("ssi", $name, $avail_json, $id);
    } else {
        // Insert
        $stmt = $conn->prepare("INSERT INTO lecturers (name, availability_json) VALUES (?, ?)");
        $stmt->bind_param("ss", $name, $avail_json);
    }
    
    if ($stmt->execute()) {
        // Export updated lecturers to CSV and B2 immediately
        $newId = $id || $conn->insert_id;
        include 'export_db_to_csv.php';
        // Continue after export
        echo json_encode([
            'status' => 'success',
            'message' => 'Lecturer saved and exported to B2',
            'id' => $newId,
            'exported' => true
        ]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to save lecturer']);
    }
}
elseif ($action === 'save_course') {
    $id = $data['id'] ?? null;
    $code = $data['course_code'] ?? '';
    $title = $data['course_title'] ?? '';
    $semester = $data['semester'] ?? '1';
    $level = $data['level'] ?? 100;
    $credits = $data['credits'] ?? 3;
    
    if (!$code || !$title) {
        echo json_encode(['status' => 'error', 'message' => 'Course code and title required']);
        exit;
    }
    
    if ($id) {
        // Update
        $stmt = $conn->prepare("UPDATE courses SET course_code = ?, course_title = ?, semester = ?, level = ?, credit_hours = ? WHERE id = ?");
        $stmt->bind_param("ssssii", $code, $title, $semester, $level, $credits, $id);
    } else {
        // Insert
        $stmt = $conn->prepare("INSERT INTO courses (course_code, course_title, semester, type, level, credit_hours) VALUES (?, ?, ?, 'Departmental', ?, ?)");
        $stmt->bind_param("sssii", $code, $title, $semester, $level, $credits);
    }
    
    if ($stmt->execute()) {
        // Export updated courses to CSV and B2 immediately
        $newId = $id || $conn->insert_id;
        include 'export_db_to_csv.php';
        // Continue after export
        echo json_encode([
            'status' => 'success',
            'message' => 'Course saved and exported to B2',
            'id' => $newId,
            'exported' => true
        ]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to save course']);
    }
}
else {
    echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
}
