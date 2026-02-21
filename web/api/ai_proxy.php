<?php
/**
 * Same-origin proxy for AI Flask API.
 * Helps browsers (e.g. Safari) avoid CORS/mixed-content issues.
 */

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$endpoint = $_GET['endpoint'] ?? '/health';
if (!is_string($endpoint) || $endpoint === '' || $endpoint[0] !== '/') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid endpoint']);
    exit;
}

// Basic allowlist to reduce proxy abuse risk
$allowed = [
    '/health',
    '/ai/status',
    '/progress',
    '/generate',
    '/api/generate',
    '/generate/exam',
    '/predict/quality',
    '/predict/feasibility',
    '/feedback',
    '/suggestions',
    '/diagnostics',
    '/analytics/performance',
    '/explain/schedule'
];

$baseEndpoint = explode('?', $endpoint, 2)[0];
if (!in_array($baseEndpoint, $allowed, true)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Endpoint not allowed']);
    exit;
}

$targetBase = getenv('AI_PROXY_BASE_URL') ?: 'http://127.0.0.1:5000';
$targetUrl = rtrim($targetBase, '/') . $endpoint;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$rawBody = file_get_contents('php://input');

$ch = curl_init($targetUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
curl_setopt($ch, CURLOPT_TIMEOUT, 600); // allow long-running generation
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Accept: application/json'
]);

if ($method !== 'GET' && $rawBody !== false && $rawBody !== '') {
    curl_setopt($ch, CURLOPT_POSTFIELDS, $rawBody);
}

$response = curl_exec($ch);
$curlErr = curl_error($ch);
$statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false) {
    http_response_code(502);
    echo json_encode(['status' => 'error', 'message' => 'AI proxy request failed: ' . $curlErr]);
    exit;
}

if ($statusCode >= 100 && $statusCode < 600) {
    http_response_code($statusCode);
}

echo $response;
