<?php

session_start();
header('Content-Type: application/json');

require_once 'db.php';
require_once __DIR__ . '/../../lib/B2Storage.php';
require_once 'room_sync_helper.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

$source_key = trim((string) ($_GET['source_key'] ?? 'csv/general/rooms.csv'));
if (!in_array($source_key, get_room_source_keys(), true)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid room source']);
    exit;
}

try {
    $b2 = new B2Storage();
    $rows = load_room_rows_from_storage($b2, $source_key);

    $id_map = [];
    $result = $conn->query('SELECT id, room_name FROM rooms');
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $id_map[strtolower(trim((string) ($row['room_name'] ?? '')))] = (int) $row['id'];
        }
    }

    $payload = [];
    foreach ($rows as $row) {
        $room_name = trim((string) ($row['room_name'] ?? ''));
        if ($room_name === '') {
            continue;
        }

        $payload[] = [
            'id' => $id_map[strtolower($room_name)] ?? null,
            'room_name' => $room_name,
            'capacity' => (int) ($row['capacity'] ?? 50),
            'source_key' => $source_key,
        ];
    }

    echo json_encode([
        'status' => 'success',
        'source_key' => $source_key,
        'rooms' => $payload,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}