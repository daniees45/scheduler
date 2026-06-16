<?php
/**
 * Application Configuration — Local / Environment Override
 *
 * This file overrides the defaults defined in bootstrap.php.
 * Sensitive values (passwords, API keys) are kept in .env and read via
 * getenv() so they are never committed to version control.
 *
 * Do NOT commit this file to a public repository if it contains real credentials.
 */

return [

    // -------------------------------------------------------------------------
    // Application
    // -------------------------------------------------------------------------
    'app' => [
        'environment'           => 'development', // 'production' on live server
        'public_base_url'       => 'http://localhost/scheduler/web',
        'web_callback_base_url' => 'http://localhost/scheduler/web',
    ],

    // -------------------------------------------------------------------------
    // Database
    // -------------------------------------------------------------------------
    'database' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'vvu_scheduler',
        'user'    => 'root',
        'pass'    => '',          // override in .env with DB_PASS if needed
        'charset' => 'utf8mb4',
        'socket'  => null,
    ],

    // -------------------------------------------------------------------------
    // AI Backend
    // -------------------------------------------------------------------------
    'ai' => [
        'base_url'         => 'http://127.0.0.1:5000',
        'browser_base_url' => 'http://127.0.0.1:5000',
    ],

    // -------------------------------------------------------------------------
    // Email / SMTP
    //
    // driver options:
    //   'auto'      — try Gmail → SendGrid → generic fallback in that order
    //   'gmail'     — force Gmail App-Password SMTP
    //   'sendgrid'  — force SendGrid API via SMTP relay
    //   'smtp'      — force custom SMTP settings below
    // -------------------------------------------------------------------------
    'email' => [
        'driver'       => 'gmail',               // change to 'smtp' or 'sendgrid' as needed
        'from_address' => getenv('GMAIL_APP_EMAIL') ?: 'noreply@vvuscheduler.local',
        'from_name'    => 'VVU Scheduler',

        // --- Gmail App Password (recommended for development/small deployments)
        'gmail' => [
            'app_email'    => getenv('GMAIL_APP_EMAIL')    ?: '',
            'app_password' => getenv('GMAIL_APP_PASSWORD') ?: '',
        ],

        // --- Generic SMTP (e.g. Mailtrap, Zoho, corporate SMTP)
        'smtp' => [
            'host'       => getenv('SMTP_HOST') ?: 'smtp.mailtrap.io',
            'port'       => (int)(getenv('SMTP_PORT') ?: 587),
            'user'       => getenv('SMTP_USER') ?: '',
            'pass'       => getenv('SMTP_PASS') ?: '',
            'encryption' => 'tls',   // 'tls' (STARTTLS/587) | 'ssl' (SMTPS/465) | '' (plain/25)
        ],

        // --- SendGrid (production)
        'sendgrid' => [
            'api_key'    => getenv('SENDGRID_API_KEY')      ?: '',
            'from_email' => getenv('SENDGRID_FROM_EMAIL')   ?: '',
            'from_name'  => getenv('SENDGRID_FROM_NAME')    ?: 'VVU Scheduler',
        ],
    ],

];
