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