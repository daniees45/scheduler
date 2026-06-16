<?php

function scheduler_load_dotenv(): void
{
    static $loaded = false;

    if ($loaded) {
        return;
    }

    $loaded = true;
    $envPath = dirname(__DIR__) . '/.env';

    if (!is_file($envPath) || !is_readable($envPath)) {
        return;
    }

    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        if (str_starts_with($line, 'export ')) {
            $line = trim(substr($line, 7));
        }

        $eqPos = strpos($line, '=');
        if ($eqPos === false) {
            continue;
        }

        $name = trim(substr($line, 0, $eqPos));
        $value = trim(substr($line, $eqPos + 1));

        if ($name === '') {
            continue;
        }

        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        // Respect already defined server-level environment values.
        if (getenv($name) !== false) {
            continue;
        }

        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}

scheduler_load_dotenv();

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
        'email' => [
            'driver'       => 'auto',
            'from_address' => 'noreply@vvuscheduler.local',
            'from_name'    => 'VVU Scheduler',
            'gmail' => [
                'app_email'    => '',
                'app_password' => '',
            ],
            'smtp' => [
                'host'       => 'smtp.mailtrap.io',
                'port'       => 465,
                'user'       => '',
                'pass'       => '',
                'encryption' => 'ssl',
            ],
            'sendgrid' => [
                'api_key'    => '',
                'from_email' => '',
                'from_name'  => 'VVU Scheduler',
            ],
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

/**
 * Return the resolved email configuration array.
 * Merges PHP config values with env-var fallbacks.
 */
function scheduler_email_config(): array
{
    $cfg = scheduler_config('email', []);

    $driver = (string)($cfg['driver'] ?? 'auto');

    // Resolve gmail credentials — prefer config, fall back to env
    $gmailEmail    = (string)($cfg['gmail']['app_email']    ?? getenv('GMAIL_APP_EMAIL')    ?: 'Oladipupoabeeb7@gmail.com');
    $gmailPassword = (string)($cfg['gmail']['app_password'] ?? getenv('GMAIL_APP_PASSWORD') ?: 'hmfd ubqa xgqy upol');

    // Resolve sendgrid credentials
    $sgKey       = (string)($cfg['sendgrid']['api_key']    ?? getenv('SENDGRID_API_KEY')    ?: '');
    $sgFromEmail = (string)($cfg['sendgrid']['from_email'] ?? getenv('SENDGRID_FROM_EMAIL') ?: '');
    $sgFromName  = (string)($cfg['sendgrid']['from_name']  ?? getenv('SENDGRID_FROM_NAME')  ?: 'VVU Scheduler');

    // Resolve generic SMTP credentials
    $smtpHost = (string)($cfg['smtp']['host'] ?? getenv('SMTP_HOST') ?: 'smtp.mailtrap.io');
    $smtpPort = (int)   ($cfg['smtp']['port'] ?? getenv('SMTP_PORT') ?: 465);
    $smtpUser = (string)($cfg['smtp']['user'] ?? getenv('SMTP_USER') ?: '');
    $smtpPass = (string)($cfg['smtp']['pass'] ?? getenv('SMTP_PASS') ?: '');
    $smtpEnc  = (string)($cfg['smtp']['encryption'] ?? 'ssl');

    // Determine effective from address
    $fromAddress = (string)($cfg['from_address'] ??
        getenv('SENDGRID_FROM_EMAIL') ?:
        getenv('SMTP_FROM') ?:
        ($gmailEmail !== '' ? $gmailEmail : 'noreply@vvuscheduler.local'));
    $fromName = (string)($cfg['from_name'] ?? getenv('SENDGRID_FROM_NAME') ?: 'VVU Scheduler');

    return [
        'driver'       => $driver,
        'from_address' => $fromAddress,
        'from_name'    => $fromName,
        'gmail' => [
            'app_email'    => $gmailEmail,
            'app_password' => $gmailPassword,
        ],
        'smtp' => [
            'host'       => $smtpHost,
            'port'       => $smtpPort,
            'user'       => $smtpUser,
            'pass'       => $smtpPass,
            'encryption' => $smtpEnc,
        ],
        'sendgrid' => [
            'api_key'    => $sgKey,
            'from_email' => $sgFromEmail,
            'from_name'  => $sgFromName,
        ],
    ];
}