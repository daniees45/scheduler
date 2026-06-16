<?php

function api_json_error(string $message, int $statusCode = 400, array $extra = []): void
{
    http_response_code($statusCode);
    $payload = array_merge(['status' => 'error', 'message' => $message], $extra);
    echo json_encode($payload);
    exit;
}

function ensure_api_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

function require_http_methods($allowedMethods): void
{
    $allowed = is_array($allowedMethods) ? $allowedMethods : [$allowedMethods];
    $normalized = array_map(static function ($m) {
        return strtoupper((string)$m);
    }, $allowed);

    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (!in_array($method, $normalized, true)) {
        api_json_error('Invalid request method.', 405, ['allowed_methods' => $normalized]);
    }
}

function require_authenticated_user(): int
{
    ensure_api_session();
    if (!isset($_SESSION['user_id'])) {
        api_json_error('Unauthorized', 401);
    }
    return (int)$_SESSION['user_id'];
}

function require_roles(array $roles, string $message = 'Forbidden'): string
{
    ensure_api_session();
    $role = (string)($_SESSION['role'] ?? '');
    if ($role === '' || !in_array($role, $roles, true)) {
        api_json_error($message, 403);
    }
    return $role;
}

function require_admin_user(): string
{
    return require_roles(['super_admin', 'faculty_admin'], 'Unauthorized');
}

function require_super_admin_user(): string
{
    return require_roles(['super_admin'], 'Unauthorized - Admin access required');
}
