<?php
// as/api/migrate_csv_storage.php
require_once 'db.php';

try {
    $sql = "CREATE TABLE IF NOT EXISTS csv_storage (
        id INT AUTO_INCREMENT PRIMARY KEY,
        filename VARCHAR(255) UNIQUE NOT NULL,
        content LONGBLOB NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )";
    
    $conn->query($sql);
    echo json_encode(['status' => 'success', 'message' => 'Table csv_storage created successfully or already exists.']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
