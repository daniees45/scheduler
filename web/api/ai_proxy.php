<?php
/**
 * Same-origin proxy for AI Flask API.
 * Helps browsers (e.g. Safari) avoid CORS/mixed-content issues.
 */

require_once __DIR__ . '/../../config/bootstrap.php';

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
    '/cancel',
    '/generate',
    '/api/generate',
    '/generate/exam',
    '/predict/quality',
    '/predict/feasibility',
    '/feedback',
    '/suggestions',
    '/diagnostics',
    '/analytics/performance',
    '/explain/schedule',
    '/api/feasibility/heatmap',
    '/api/conflicts/relax',
    '/ai/train/all',
    '/ai/train/progress'
];

$baseEndpoint = explode('?', $endpoint, 2)[0];
if (!in_array($baseEndpoint, $allowed, true)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Endpoint not allowed']);
    exit;
}

$targetBase = scheduler_ai_base_url();
$targetUrl = rtrim($targetBase, '/') . $endpoint;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$rawBody = file_get_contents('php://input');

if (!function_exists('curl_init')) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'PHP cURL extension is not enabled on this server.'
    ]);
    exit;
}

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
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

if ($response === false) {
    http_response_code(502);
    echo json_encode(['status' => 'error', 'message' => 'AI proxy request failed: ' . $curlErr]);
    exit;
}

if ($statusCode >= 100 && $statusCode < 600) {
    http_response_code($statusCode);
}

// If Flask returned an error status but non-JSON body (e.g. HTML debug page),
// wrap it in a JSON error so the client always receives a parseable response.
if ($statusCode >= 400 && stripos($contentType, 'application/json') === false) {
    // Extract a short plain-text hint from the body (first 200 chars, no tags)
    $hint = strip_tags((string)$response);
    $hint = trim(preg_replace('/\s+/', ' ', $hint));
    if (strlen($hint) > 200) $hint = substr($hint, 0, 200) . '…';
    if ($hint === '') $hint = 'Unknown server error';
    echo json_encode(['status' => 'error', 'message' => $hint]);
    exit;
}

echo $response;