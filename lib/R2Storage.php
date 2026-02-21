<?php
/**
 * Cloudflare R2 Storage Helper
 * 
 * Provides S3-compatible storage operations using AWS SDK for PHP
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

class R2Storage {
    private $client;
    private $config;
    private $bucket;
    private $enabled;
    
    public function __construct() {
        $this->config = require __DIR__ . '/../config/r2_config.php';
        $this->bucket = $this->config['bucket'];
        $this->enabled = $this->config['enabled'];
        
        if ($this->enabled) {
            try {
                $this->client = new S3Client([
                    'version' => 'latest',
                    'region' => 'auto',
                    'endpoint' => $this->config['endpoint'],
                    'credentials' => [
                        'key' => $this->config['access_key_id'],
                        'secret' => $this->config['secret_access_key'],
                    ],
                    'use_path_style_endpoint' => true,
                ]);
            } catch (Exception $e) {
                error_log("R2 Client Error: " . $e->getMessage());
                $this->enabled = false;
            }
        }
    }
    
    /**
     * Upload a file to R2
     * 
     * @param string $filePath Local file path to upload
     * @param string $key R2 object key (path in bucket)
     * @param array $metadata Optional metadata
     * @return array ['success' => bool, 'url' => string|null, 'error' => string|null]
     */
    public function upload($filePath, $key, $metadata = []) {
        if (!$this->enabled) {
            return $this->fallbackToLocal('upload', $filePath, $key);
        }
        
        try {
            $result = $this->client->putObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'SourceFile' => $filePath,
                'Metadata' => $metadata,
                'ContentType' => $this->getMimeType($filePath),
            ]);
            
            $url = $this->getPublicUrl($key);
            
            return [
                'success' => true,
                'url' => $url,
                'key' => $key,
                'etag' => $result['ETag'],
                'error' => null
            ];
        } catch (AwsException $e) {
            error_log("R2 Upload Error: " . $e->getMessage());
            
            if ($this->config['fallback_to_local']) {
                return $this->fallbackToLocal('upload', $filePath, $key);
            }
            
            return [
                'success' => false,
                'url' => null,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Upload content directly to R2 (without local file)
     * 
     * @param string $content File content
     * @param string $key R2 object key
     * @param array $metadata Optional metadata
     * @return array ['success' => bool, 'url' => string|null, 'error' => string|null]
     */
    public function uploadContent($content, $key, $metadata = []) {
        if (!$this->enabled) {
            return $this->fallbackToLocal('uploadContent', $content, $key);
        }
        
        try {
            $result = $this->client->putObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'Body' => $content,
                'Metadata' => $metadata,
                'ContentType' => $this->getMimeTypeFromKey($key),
            ]);
            
            $url = $this->getPublicUrl($key);
            
            return [
                'success' => true,
                'url' => $url,
                'key' => $key,
                'etag' => $result['ETag'],
                'error' => null
            ];
        } catch (AwsException $e) {
            error_log("R2 Upload Content Error: " . $e->getMessage());
            
            if ($this->config['fallback_to_local']) {
                return $this->fallbackToLocal('uploadContent', $content, $key);
            }
            
            return [
                'success' => false,
                'url' => null,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Download a file from R2
     * 
     * @param string $key R2 object key
     * @param string $savePath Optional local path to save
     * @return array ['success' => bool, 'content' => string|null, 'error' => string|null]
     */
    public function download($key, $savePath = null) {
        if (!$this->enabled) {
            return $this->fallbackToLocal('download', $key, $savePath);
        }
        
        try {
            $params = [
                'Bucket' => $this->bucket,
                'Key' => $key,
            ];
            
            if ($savePath) {
                $params['SaveAs'] = $savePath;
            }
            
            $result = $this->client->getObject($params);
            $content = (string) $result['Body'];
            
            return [
                'success' => true,
                'content' => $content,
                'error' => null
            ];
        } catch (AwsException $e) {
            error_log("R2 Download Error: " . $e->getMessage());
            
            if ($this->config['fallback_to_local']) {
                return $this->fallbackToLocal('download', $key, $savePath);
            }
            
            return [
                'success' => false,
                'content' => null,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Delete a file from R2
     * 
     * @param string $key R2 object key
     * @return array ['success' => bool, 'error' => string|null]
     */
    public function delete($key) {
        if (!$this->enabled) {
            return $this->fallbackToLocal('delete', $key);
        }
        
        try {
            $this->client->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
            ]);
            
            return [
                'success' => true,
                'error' => null
            ];
        } catch (AwsException $e) {
            error_log("R2 Delete Error: " . $e->getMessage());
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * List files in R2 with prefix
     * 
     * @param string $prefix Path prefix (e.g., 'csv/final/')
     * @param int $maxKeys Maximum number of keys to return
     * @return array ['success' => bool, 'files' => array, 'error' => string|null]
     */
    public function listFiles($prefix = '', $maxKeys = 1000) {
        if (!$this->enabled) {
            return $this->fallbackToLocal('listFiles', $prefix, $maxKeys);
        }
        
        try {
            $result = $this->client->listObjectsV2([
                'Bucket' => $this->bucket,
                'Prefix' => $prefix,
                'MaxKeys' => $maxKeys,
            ]);
            
            $files = [];
            if (isset($result['Contents'])) {
                foreach ($result['Contents'] as $object) {
                    $files[] = [
                        'key' => $object['Key'],
                        'size' => $object['Size'],
                        'modified' => $object['LastModified']->getTimestamp(),
                        'etag' => trim($object['ETag'], '"'),
                    ];
                }
            }
            
            // Sort by modified time descending
            usort($files, function($a, $b) {
                return $b['modified'] - $a['modified'];
            });
            
            return [
                'success' => true,
                'files' => $files,
                'error' => null
            ];
        } catch (AwsException $e) {
            error_log("R2 List Error: " . $e->getMessage());
            
            if ($this->config['fallback_to_local']) {
                return $this->fallbackToLocal('listFiles', $prefix, $maxKeys);
            }
            
            return [
                'success' => false,
                'files' => [],
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Check if a file exists in R2
     * 
     * @param string $key R2 object key
     * @return bool
     */
    public function exists($key) {
        if (!$this->enabled) {
            $result = $this->fallbackToLocal('exists', $key);
            return $result['success'] ?? false;
        }
        
        try {
            $this->client->headObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
            ]);
            return true;
        } catch (AwsException $e) {
            return false;
        }
    }
    
    /**
     * Get public URL for a file
     * 
     * @param string $key R2 object key
     * @return string|null
     */
    public function getPublicUrl($key) {
        if ($this->config['public_url']) {
            return rtrim($this->config['public_url'], '/') . '/' . ltrim($key, '/');
        }
        
        // If no public domain, return R2 URL (may require authentication)
        return $this->config['endpoint'] . '/' . $this->bucket . '/' . $key;
    }
    
    /**
     * Get signed URL for temporary access
     * 
     * @param string $key R2 object key
     * @param int $expiresIn Expiration time in seconds (default: 1 hour)
     * @return string|null
     */
    public function getSignedUrl($key, $expiresIn = 3600) {
        if (!$this->enabled) {
            return null;
        }
        
        try {
            $cmd = $this->client->getCommand('GetObject', [
                'Bucket' => $this->bucket,
                'Key' => $key
            ]);
            
            $request = $this->client->createPresignedRequest($cmd, "+{$expiresIn} seconds");
            return (string) $request->getUri();
        } catch (Exception $e) {
            error_log("R2 Signed URL Error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Fallback to local filesystem operations
     */
    private function fallbackToLocal($operation, ...$args) {
        $localPath = $this->config['local_path'];
        
        switch ($operation) {
            case 'upload':
                // args: $filePath, $key
                $destPath = $localPath . $args[1];
                $destDir = dirname($destPath);
                if (!is_dir($destDir)) {
                    mkdir($destDir, 0755, true);
                }
                $success = copy($args[0], $destPath);
                return [
                    'success' => $success,
                    'url' => $success ? $args[1] : null,
                    'key' => $args[1],
                    'error' => $success ? null : 'Local copy failed'
                ];
                
            case 'uploadContent':
                // args: $content, $key
                $destPath = $localPath . $args[1];
                $destDir = dirname($destPath);
                if (!is_dir($destDir)) {
                    mkdir($destDir, 0755, true);
                }
                $success = file_put_contents($destPath, $args[0]) !== false;
                return [
                    'success' => $success,
                    'url' => $success ? $args[1] : null,
                    'key' => $args[1],
                    'error' => $success ? null : 'Local write failed'
                ];
                
            case 'download':
                // args: $key, $savePath
                $sourcePath = $localPath . $args[0];
                if (!file_exists($sourcePath)) {
                    return ['success' => false, 'content' => null, 'error' => 'File not found locally'];
                }
                $content = file_get_contents($sourcePath);
                if (isset($args[1])) {
                    file_put_contents($args[1], $content);
                }
                return ['success' => true, 'content' => $content, 'error' => null];
                
            case 'delete':
                // args: $key
                $filePath = $localPath . $args[0];
                $success = file_exists($filePath) ? unlink($filePath) : false;
                return ['success' => $success, 'error' => $success ? null : 'File not found'];
                
            case 'listFiles':
                // args: $prefix, $maxKeys
                $dirPath = $localPath . $args[0];
                $files = [];
                if (is_dir($dirPath)) {
                    $items = scandir($dirPath);
                    foreach ($items as $item) {
                        if ($item === '.' || $item === '..') continue;
                        $fullPath = $dirPath . $item;
                        if (is_file($fullPath)) {
                            $files[] = [
                                'key' => $args[0] . $item,
                                'size' => filesize($fullPath),
                                'modified' => filemtime($fullPath),
                                'etag' => md5_file($fullPath),
                            ];
                        }
                    }
                    usort($files, function($a, $b) {
                        return $b['modified'] - $a['modified'];
                    });
                }
                return ['success' => true, 'files' => $files, 'error' => null];
                
            case 'exists':
                // args: $key
                $filePath = $localPath . $args[0];
                return ['success' => file_exists($filePath)];
                
            default:
                return ['success' => false, 'error' => 'Unknown operation'];
        }
    }
    
    /**
     * Get MIME type from file path
     */
    private function getMimeType($filePath) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $filePath);
        finfo_close($finfo);
        return $mimeType ?: 'application/octet-stream';
    }
    
    /**
     * Get MIME type from key/filename
     */
    private function getMimeTypeFromKey($key) {
        $ext = strtolower(pathinfo($key, PATHINFO_EXTENSION));
        $mimeTypes = [
            'csv' => 'text/csv',
            'pdf' => 'application/pdf',
            'json' => 'application/json',
            'txt' => 'text/plain',
        ];
        return $mimeTypes[$ext] ?? 'application/octet-stream';
    }
    
    /**
     * Check if R2 is enabled and working
     * 
     * @return bool
     */
    public function isEnabled() {
        return $this->enabled;
    }
}
