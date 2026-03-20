# InfinityFree + Render Deployment

This project can be split cleanly into:

- InfinityFree: PHP frontend + MySQL database
- Render: Python AI backend

InfinityFree does not support `.env` the way this project was being configured locally, so production settings are now loaded from a PHP config file instead.

## 1. InfinityFree PHP config

Create this file on the server:

- `config/app_config.php`

Start from:

- `config/app_config.example.php`

Example production values:

```php
<?php

return [
    'app' => [
        'environment' => 'production',
        'public_base_url' => 'https://your-subdomain.infinityfreeapp.com/scheduler/web',
        'web_callback_base_url' => 'https://your-subdomain.infinityfreeapp.com/scheduler/web',
    ],
    'database' => [
        'host' => 'sqlXXX.infinityfree.com',
        'port' => 3306,
        'name' => 'if0_XXXXXXX_vvu_scheduler',
        'user' => 'if0_XXXXXXX',
        'pass' => 'YOUR_INFINITYFREE_DATABASE_PASSWORD',
        'charset' => 'utf8mb4',
        'socket' => null,
    ],
    'ai' => [
        'base_url' => 'https://your-render-service.onrender.com',
        'browser_base_url' => 'https://your-render-service.onrender.com',
    ],
];
```

What these values do:

- `public_base_url`: the public URL where your PHP app is hosted
- `web_callback_base_url`: the URL Render should call for progress updates and logs
- `database.*`: your InfinityFree MySQL credentials
- `ai.base_url`: the Render backend URL used by PHP server-side requests
- `ai.browser_base_url`: the URL the browser can call directly before proxy fallback

## 2. Render environment variables

Set these in Render:

```text
PORT=10000
FLASK_DEBUG=0
PUBLIC_WEB_BASE_URL=https://your-subdomain.infinityfreeapp.com/scheduler/web
WEB_CALLBACK_BASE_URL=https://your-subdomain.infinityfreeapp.com/scheduler/web
ALLOWED_ORIGINS=https://your-subdomain.infinityfreeapp.com
```

If your frontend is served from a different path or domain, update the values accordingly.

## 3. Render start command

Use:

```text
gunicorn app:app
```

`gunicorn` has been added to `requirements.txt`.

## 4. Files changed for production config

- `config/bootstrap.php`
- `config/app_config.example.php`
- `web/api/db.php`
- `web/includes/header.php`
- `web/config.js`
- `web/api/ai_proxy.php`
- `web/api/ai_feedback.php`
- `web/api/get_ai_analytics.php`
- `web/api/check_conflicts.php`
- `web/api/relax_conflict.php`
- `main_web.py`
- `app.py`

## 5. Upload checklist

Upload these to InfinityFree:

- all `web/` PHP files
- `config/bootstrap.php`
- your new `config/app_config.php`
- any static assets used by `web/`

Deploy these to Render:

- `app.py`
- `main_web.py`
- Python scheduler modules
- `requirements.txt`
- model files and CSV assets required by the AI engine

## 6. Important note about InfinityFree

InfinityFree free hosting can restrict:

- long-running requests
- some outgoing cURL behavior
- background tasks and cron reliability

If AI generation takes too long through direct browser calls, the same-origin PHP proxy remains available as a fallback path.