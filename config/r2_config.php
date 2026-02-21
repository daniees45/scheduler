<?php
/**
 * Cloudflare R2 Storage Configuration
 * 
 * R2 is S3-compatible, so we use AWS SDK for PHP
 * Get your credentials from: https://dash.cloudflare.com > R2 > Manage R2 API Tokens
 */

return [
    // Enable/disable R2 (set to false to use local storage)
    'enabled' => true,
    
    // Cloudflare Account ID (found in R2 dashboard)
    'account_id' => getenv('R2_ACCOUNT_ID') ?: 'YOUR_CLOUDFLARE_ACCOUNT_ID',
    
    // R2 Access Key ID
    'access_key_id' => getenv('R2_ACCESS_KEY_ID') ?: 'YOUR_R2_ACCESS_KEY_ID',
    
    // R2 Secret Access Key
    'secret_access_key' => getenv('R2_SECRET_ACCESS_KEY') ?: 'YOUR_R2_SECRET_ACCESS_KEY',
    
    // R2 Bucket Name
    'bucket' => getenv('R2_BUCKET') ?: 'vvu-scheduler',
    
    // R2 Endpoint (format: https://<account_id>.r2.cloudflarestorage.com)
    'endpoint' => getenv('R2_ENDPOINT') ?: 'https://YOUR_ACCOUNT_ID.r2.cloudflarestorage.com',
    
    // Public URL for accessing files (optional - for R2 custom domain)
    'public_url' => getenv('R2_PUBLIC_URL') ?: null,
    
    // Fallback to local storage if R2 fails
    'fallback_to_local' => true,
    
    // Local storage path (for fallback and migration)
    'local_path' => __DIR__ . '/../csv/',
    
    // File paths in R2 (mirrors local directory structure)
    'paths' => [
        'general' => 'csv/general/',
        'department' => 'csv/department/',
        'final' => 'csv/final/',
        'raw' => 'csv/raw/',
    ]
];
