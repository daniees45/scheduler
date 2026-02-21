<?php
/**
 * Backblaze B2 Storage Configuration
 * 
 * Backblaze B2 is a cloud storage service with S3-compatible API
 * Get your credentials from: https://secure.backblaze.com > Account > App Keys
 */

return [
    // Enable/disable B2 (set to false to use local storage)
    'enabled' => true,
    
    // Backblaze Account ID (found in Account > App Keys)
    'account_id' => getenv('B2_ACCOUNT_ID') ?: 'daniees491',
    
    // B2 Application Key ID
    'app_key_id' => getenv('B2_APP_KEY_ID') ?: '005180d9858c79c0000000001',
    
    // B2 Application Key
    'app_key' => getenv('B2_APP_KEY') ?: 'K0058WJLbz3T9kfxsTbO76ccjXWO24Q',
    
    // B2 Bucket Name
    'bucket' => getenv('B2_BUCKET') ?: 'vvu-scheduler',
    
    // B2 Bucket Name (for S3-compatible API)
    'bucket_name' => getenv('B2_BUCKET') ?: 'vvu-scheduler',
    
    // B2 Endpoint (S3-compatible: region-specific endpoint required)
    'endpoint' => getenv('B2_ENDPOINT') ?: 'https://s3.us-east-005.backblazeb2.com',
    
    // B2 Region (us-west-002, us-east-005, eu-central-003, etc.)
    'region' => getenv('B2_REGION') ?: 'us-east-005',
    
    // Public URL for accessing files (optional - for B2 custom domain)
    'public_url' => getenv('B2_PUBLIC_URL') ?: null,
    
    // Fallback to local storage if B2 fails
    'fallback_to_local' => true,
    
    // Local storage path (for fallback and migration)
    'local_path' => __DIR__ . '/../csv/',
    
    // File paths in B2 (mirrors local directory structure)
    'paths' => [
        'general' => 'csv/general/',
        'department' => 'csv/department/',
        'final' => 'csv/final/',
        'raw' => 'csv/raw/',
    ]
];
