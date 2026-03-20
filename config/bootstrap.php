<?php

function scheduler_array_merge_recursive_distinct(array $base, array $override): array
{
    foreach ($override as $key => $value) {
        if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
            $base[$key] = scheduler_array_merge_recursive_distinct($base[$key], $value);
            continue;
        }

        $base[$key] = $value;
    }

    return $base;
}

function scheduler_load_config(): array
{
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    $defaultConfig = [
        'app' => [
            'environment' => 'development',
            'public_base_url' => null,
            'web_callback_base_url' => null,
        ],
        'database' => [
            'host' => '127.0.0.1',
            'port' => 3307,
            'name' => 'vvu_scheduler',
            'user' => 'root',
            'pass' => '',
            'charset' => 'utf8mb4',
            'socket' => null,
        ],
        'ai' => [
            'base_url' => 'http://127.0.0.1:5000',
            'browser_base_url' => 'http://127.0.0.1:5000',
        ],
    ];

    $config = $defaultConfig;
    $appConfigPath = __DIR__ . '/app_config.php';

    if (is_file($appConfigPath)) {
        $userConfig = require $appConfigPath;
        if (is_array($userConfig)) {
            $config = scheduler_array_merge_recursive_distinct($config, $userConfig);
        }
    }

    return $config;
}

function scheduler_config(string $path, $default = null)
{
    $config = scheduler_load_config();
    $segments = explode('.', $path);
    $value = $config;

    foreach ($segments as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }

        $value = $value[$segment];
    }

    return $value;
}

function scheduler_is_production(): bool
{
    return scheduler_config('app.environment', 'development') === 'production';
}

function scheduler_ai_base_url(): string
{
    $url = scheduler_config('ai.base_url', 'http://127.0.0.1:5000');
    return rtrim((string)$url, '/');
}

function scheduler_browser_ai_base_url(): string
{
    $url = scheduler_config('ai.browser_base_url', scheduler_ai_base_url());
    return rtrim((string)$url, '/');
}

function scheduler_public_base_url(): ?string
{
    $url = scheduler_config('app.public_base_url', null);
    if (!is_string($url) || trim($url) === '') {
        return null;
    }

    return rtrim($url, '/');
}

function scheduler_web_callback_base_url(): ?string
{
    $url = scheduler_config('app.web_callback_base_url', scheduler_public_base_url());
    if (!is_string($url) || trim($url) === '') {
        return null;
    }

    return rtrim($url, '/');
}

function scheduler_url_join(string $base, string $path): string
{
    return rtrim($base, '/') . '/' . ltrim($path, '/');
}