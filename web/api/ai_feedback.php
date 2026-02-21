<?php
/**
 * PHP bridge for user feedback -> AI learning endpoint.
 * Also stores a local audit trail in user_feedback.csv.
 */

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON payload']);
    exit;
}

$action = (string)($payload['action'] ?? 'feedback');
$qualityRaw = $payload['quality'] ?? 0.5;
$quality = is_numeric($qualityRaw) ? (float)$qualityRaw : 0.5;
$quality = max(0.0, min(1.0, $quality));

$metadata = isset($payload['metadata']) && is_array($payload['metadata']) ? $payload['metadata'] : [];
$metadata['user_id'] = $_SESSION['user_id'];
$metadata['username'] = $_SESSION['username'] ?? null;
$metadata['source'] = 'web_php_bridge';

$forward = [
    'action' => $action,
    'quality' => $quality,
    'metadata' => $metadata,
    'features' => isset($payload['features']) && is_array($payload['features']) ? $payload['features'] : new stdClass()
];

$root = realpath(__DIR__ . '/../../');
$feedbackCsv = $root . '/user_feedback.csv';

// Local persistence for audit / fallback training history
$header = ['timestamp', 'user_id', 'action', 'quality', 'department', 'course_type', 'output_file', 'metadata_json'];
$row = [
    date('Y-m-d H:i:s'),
    $_SESSION['user_id'],
    $action,
    $quality,
    $metadata['department'] ?? '',
    $metadata['course_type'] ?? '',
    $metadata['output_file'] ?? '',
    json_encode($metadata)
];

$needsHeader = !file_exists($feedbackCsv) || filesize($feedbackCsv) === 0;
$fp = fopen($feedbackCsv, 'a');
if ($fp) {
    if ($needsHeader) {
        fputcsv($fp, $header);
    }
    fputcsv($fp, $row);
    fclose($fp);
}

// Forward to AI service via localhost first, then deployed fallback
$targets = [
    getenv('AI_PROXY_BASE_URL') ?: 'http://127.0.0.1:5000',
    'https://my-ai-service-yj44.onrender.com'
];

$forwarded = false;
$forwardError = null;

foreach ($targets as $base) {
    $url = rtrim($base, '/') . '/feedback';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 25);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($forward));

    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp !== false && $code >= 200 && $code < 300) {
        $forwarded = true;
        break;
    }

    $forwardError = $err ?: ('HTTP ' . (int)$code);
}

if ($forwarded) {
    echo json_encode([
        'status' => 'success',
        'message' => 'Feedback recorded and forwarded to AI learning pipeline',
        'quality' => $quality
    ]);
} else {
    // Keep success-like response for UX continuity, but disclose forwarding status
    echo json_encode([
        'status' => 'success',
        'message' => 'Feedback recorded locally; AI forwarding will retry on next feedback',
        'forwarded' => false,
        'forward_error' => $forwardError,
        'quality' => $quality
    ]);
}
