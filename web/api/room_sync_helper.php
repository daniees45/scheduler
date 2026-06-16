<?php

function get_room_source_keys(): array
{
    return [
        'csv/general/rooms.csv',
        'csv/department/computing_science_rooms.csv',
        'csv/department/nursing_rooms.csv',
        'csv/department/theology_rooms.csv',
        'csv/department/business_rooms.csv',
        'csv/department/education_rooms.csv',
        'csv/department/biomedical_engineering_rooms.csv',
        'csv/department/development_studies_rooms.csv',
    ];
}

function get_local_room_source_path(string $source_key): string
{
    return dirname(__DIR__, 2) . '/' . ltrim($source_key, '/');
}

function room_rows_from_csv_content(string $content): array
{
    $rows = [];
    $handle = fopen('php://memory', 'r+');
    fwrite($handle, $content);
    rewind($handle);

    $headers = fgetcsv($handle);
    if (!$headers) {
        fclose($handle);
        return [];
    }

    $header_map = array_flip(array_map('trim', array_map('strtolower', $headers)));
    $name_idx = $header_map['room_name'] ?? 0;
    $cap_idx = $header_map['capacity'] ?? 1;

    while (($row = fgetcsv($handle, 1000, ',')) !== false) {
        $room_name = trim($row[$name_idx] ?? '');
        if ($room_name === '') {
            continue;
        }

        $capacity = (int) ($row[$cap_idx] ?? 50);
        if ($capacity <= 0) {
            $capacity = 50;
        }

        $rows[] = [
            'room_name' => $room_name,
            'capacity' => $capacity,
        ];
    }

    fclose($handle);
    return $rows;
}

function room_rows_to_csv_content(array $rows): string
{
    usort($rows, static function ($left, $right) {
        return strcasecmp($left['room_name'] ?? '', $right['room_name'] ?? '');
    });

    $handle = fopen('php://temp', 'r+');
    fputcsv($handle, ['room_name', 'capacity'], ',', '"', '\\');
    foreach ($rows as $row) {
        $room_name = trim((string) ($row['room_name'] ?? ''));
        if ($room_name === '') {
            continue;
        }

        $capacity = (int) ($row['capacity'] ?? 50);
        if ($capacity <= 0) {
            $capacity = 50;
        }

        fputcsv($handle, [$room_name, $capacity], ',', '"', '\\');
    }
    rewind($handle);
    $content = stream_get_contents($handle);
    fclose($handle);

    return $content;
}

function load_room_rows_from_storage($b2, string $source_key, bool $allow_local_fallback = true): array
{
    $download = $b2->download($source_key);
    if (!empty($download['success'])) {
        return room_rows_from_csv_content($download['content']);
    }

    if (!$allow_local_fallback) {
        return [];
    }

    $local_path = get_local_room_source_path($source_key);
    if (is_file($local_path)) {
        return room_rows_from_csv_content((string) file_get_contents($local_path));
    }

    return [];
}

function save_room_rows_to_storage($b2, string $source_key, array $rows): void
{
    $content = room_rows_to_csv_content($rows);
    $local_path = get_local_room_source_path($source_key);
    @mkdir(dirname($local_path), 0755, true);
    file_put_contents($local_path, $content);

    $result = $b2->uploadContent($content, $source_key);
    if (is_array($result) && empty($result['success'])) {
        $error = $result['error'] ?? 'Unknown Cloud upload error';
        throw new Exception("Cloud upload failed for {$source_key}: {$error}");
    }
}

function find_room_source_keys_by_name($b2, string $room_name): array
{
    $matches = [];
    foreach (get_room_source_keys() as $source_key) {
        $rows = load_room_rows_from_storage($b2, $source_key);
        foreach ($rows as $row) {
            if (strcasecmp((string) ($row['room_name'] ?? ''), $room_name) === 0) {
                $matches[] = $source_key;
                break;
            }
        }
    }

    return $matches;
}

function sync_all_room_files_to_db(mysqli $conn, $b2, bool $allow_local_fallback = true): void
{
    $rooms_by_name = [];

    foreach (get_room_source_keys() as $source_key) {
        foreach (load_room_rows_from_storage($b2, $source_key, $allow_local_fallback) as $row) {
            $name = trim((string) ($row['room_name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $capacity = (int) ($row['capacity'] ?? 50);
            if ($capacity <= 0) {
                $capacity = 50;
            }

            if (isset($rooms_by_name[$name])) {
                $rooms_by_name[$name] = max($rooms_by_name[$name], $capacity);
            } else {
                $rooms_by_name[$name] = $capacity;
            }
        }
    }

    $conn->query('SET FOREIGN_KEY_CHECKS = 0');
    $conn->query('TRUNCATE TABLE rooms');
    $conn->query('SET FOREIGN_KEY_CHECKS = 1');

    if (empty($rooms_by_name)) {
        return;
    }

    ksort($rooms_by_name, SORT_NATURAL | SORT_FLAG_CASE);
    $stmt = $conn->prepare('INSERT INTO rooms (room_name, capacity) VALUES (?, ?)');
    foreach ($rooms_by_name as $room_name => $capacity) {
        $stmt->bind_param('si', $room_name, $capacity);
        $stmt->execute();
    }
}