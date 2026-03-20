<?php
// web/api/manage_templates.php
session_start();
header('Content-Type: application/json');
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        $stmt = $conn->prepare("SELECT id, template_name, config_json, created_at FROM schedule_templates WHERE user_id = ? ORDER BY created_at DESC");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $templates = [];
        while ($row = $result->fetch_assoc()) {
            $row['config_json'] = json_decode($row['config_json'], true);
            $templates[] = $row;
        }
        echo json_encode(['status' => 'success', 'data' => $templates]);
        break;

    case 'POST':
        $input = json_decode(file_get_contents('php://input'), true);
        $name = $input['template_name'] ?? 'Untitled Template';
        $config = json_encode($input['config_json'] ?? []);

        $stmt = $conn->prepare("INSERT INTO schedule_templates (user_id, template_name, config_json) VALUES (?, ?, ?)");
        $stmt->bind_param("iss", $user_id, $name, $config);

        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'id' => $conn->insert_id]);
        }
        else {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Failed to save template']);
        }
        break;

    case 'DELETE':
        $template_id = $_GET['id'] ?? null;
        if (!$template_id) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Missing template ID']);
            exit;
        }

        $stmt = $conn->prepare("DELETE FROM schedule_templates WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $template_id, $user_id);

        if ($stmt->execute()) {
            echo json_encode(['status' => 'success']);
        }
        else {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Failed to delete template']);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
        break;
}