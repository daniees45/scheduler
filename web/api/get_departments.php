<?php
// api/get_departments.php
header('Content-Type: application/json');
require_once __DIR__ . '/db.php';

$result = [
    'status' => 'error',
    'departments' => [],
    'message' => ''
];

try {
    $query = "SELECT name FROM departments ORDER BY name ASC";
    $stmt = $conn->query($query);
    if ($stmt) {
        $departments = [];
        while ($row = $stmt->fetch_assoc()) {
            $departments[] = [
                'name' => $row['name']
            ];
        }
        $result['status'] = 'success';
        $result['departments'] = $departments;
    } else {
        $result['message'] = 'No departments found.';
    }
} catch (Exception $e) {
    $result['message'] = 'Database error: ' . $e->getMessage();
}

echo json_encode($result);
