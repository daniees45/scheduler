<?php
/**
 * Backblaze B2 Storage Helper
 * 
 * Provides S3-compatible storage operations using AWS SDK for PHP
 * B2 is S3-compatible, so we use the same SDK as R2/S3
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

class B2Storage {
    private $client;
    private $config;
    private $bucket;
    private $enabled;
    
    public function __construct() {
        $this->config = require __DIR__ . '/../config/b2_config.php';
        $this->bucket = $this->config['bucket_name'];
        $this->enabled = $this->config['enabled'];
        
        if ($this->enabled) {
            try {
                $this->client = new S3Client([
                    'version' => 'latest',
                    'region' => $this->config['region'],
                    'endpoint' => $this->config['endpoint'],
                    'credentials' => [
                        'key' => $this->config['app_key_id'],
                        'secret' => $this->config['app_key'],
                    ],
                    'use_path_style_endpoint' => true,
                ]);
            } catch (Exception $e) {
                error_log("B2 Client Error: " . $e->getMessage());
                $this->enabled = false;
            }
        }
    }
    
    /**
     * Upload a file to B2
     * 
     * @param string $filePath Local file path to upload
     * @param string $key B2 object key (path in bucket)
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
            error_log("B2 Upload Error: " . $e->getMessage());
            
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
     * Upload content directly to B2 (without local file)
     * 
     * @param string $content File content
     * @param string $key B2 object key
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
            error_log("B2 Upload Content Error: " . $e->getMessage());
            
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
     * Download a file from B2
     * 
     * @param string $key B2 object key
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
                
                // When SaveAs is used, AWS SDK writes directly to file
                $result = $this->client->getObject($params);
                
                // Verify file was written successfully
                if (file_exists($savePath) && filesize($savePath) > 0) {
                    return [
                        'success' => true,
                        'content' => null,
                        'error' => null
                    ];
                } else {
                    throw new Exception('File was not written to disk');
                }
            } else {
                // No SaveAs - return content in response
                $result = $this->client->getObject($params);
                $content = (string) $result['Body'];
                
                return [
                    'success' => true,
                    'content' => $content,
                    'error' => null
                ];
            }
        } catch (AwsException $e) {
            error_log("B2 Download Error: " . $e->getMessage());
            
            if ($this->config['fallback_to_local']) {
                return $this->fallbackToLocal('download', $key, $savePath);
            }
            
            return [
                'success' => false,
                'content' => null,
                'error' => $e->getMessage()
            ];
        } catch (Exception $e) {
            error_log("B2 Download Error: " . $e->getMessage());
            
            return [
                'success' => false,
                'content' => null,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Delete a file from B2
     * 
     * @param string $key B2 object key
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
            error_log("B2 Delete Error: " . $e->getMessage());
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Delete all old versions of a file (keep only latest)
     * Useful when B2 versioning is enabled to prevent accumulation
     * 
     * @param string $key File key to clean up
     * @return array ['success' => bool, 'deleted_count' => int, 'error' => string|null]
     */
    public function deleteOldVersions($key) {
        if (!$this->enabled) {
            return ['success' => true, 'deleted_count' => 0, 'error' => null];
        }
        
        try {
            // List all versions of the file
            $result = $this->client->listObjectVersions([
                'Bucket' => $this->bucket,
                'Prefix' => $key,
            ]);
            
            $deleted = 0;
            $versions = $result['Versions'] ?? [];
            
            // Skip the first (latest) version, delete the rest
            if (count($versions) > 1) {
                for ($i = 1; $i < count($versions); $i++) {
                    $version = $versions[$i];
                    if ($version['Key'] === $key) {
                        try {
                            $this->client->deleteObject([
                                'Bucket' => $this->bucket,
                                'Key' => $key,
                                'VersionId' => $version['VersionId'],
                            ]);
                            $deleted++;
                        } catch (AwsException $e) {
                            error_log("B2 Delete Version Error: " . $e->getMessage());
                        }
                    }
                }
            }
            
            return [
                'success' => true,
                'deleted_count' => $deleted,
                'error' => null
            ];
        } catch (AwsException $e) {
            error_log("B2 Delete Old Versions Error: " . $e->getMessage());
            
            return [
                'success' => false,
                'deleted_count' => 0,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * List files in B2 with prefix
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
            error_log("B2 List Error: " . $e->getMessage());
            
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
     * Check if a file exists in B2
     * 
     * @param string $key B2 object key
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
     * @param string $key B2 object key
     * @return string|null
     */
    public function getPublicUrl($key) {
        if ($this->config['public_url']) {
            return rtrim($this->config['public_url'], '/') . '/' . ltrim($key, '/');
        }
        
        // If no public domain, return B2 URL
        return $this->config['endpoint'] . '/file/' . $this->bucket . '/' . $key;
    }
    
    /**
     * Get signed URL for temporary access
     * 
     * @param string $key B2 object key
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
            error_log("B2 Signed URL Error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Fallback to local filesystem operations
     */
    private function fallbackToLocal($operation, ...$args) {
        $localPath = rtrim($this->config['local_path'], '/');
        
        $resolvePath = function($key) use ($localPath) {
            $key = ltrim($key, '/');
            if (strpos($key, 'csv/') === 0) {
                return $localPath . '/' . substr($key, 4);
            }
            return $localPath . '/' . $key;
        };
        
        switch ($operation) {
            case 'upload':
                // args: $filePath, $key
                $destPath = $resolvePath($args[1]);
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
                $destPath = $resolvePath($args[1]);
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
                $sourcePath = $resolvePath($args[0]);
                if (!file_exists($sourcePath)) {
                    return ['success' => false, 'content' => null, 'error' => 'File not found locally: ' . $sourcePath];
                }
                $content = file_get_contents($sourcePath);
                if (isset($args[1]) && $args[1]) {
                    $saveDir = dirname($args[1]);
                    if (!is_dir($saveDir)) {
                        mkdir($saveDir, 0755, true);
                    }
                    file_put_contents($args[1], $content);
                }
                return ['success' => true, 'content' => $content, 'error' => null];
                
            case 'delete':
                // args: $key
                $filePath = $resolvePath($args[0]);
                $success = file_exists($filePath) ? unlink($filePath) : false;
                return ['success' => $success, 'error' => $success ? null : 'File not found'];
                
            case 'listFiles':
                // args: $prefix, $maxKeys
                $dirPath = $resolvePath($args[0]);
                $files = [];
                if (is_dir($dirPath)) {
                    $items = scandir($dirPath);
                    foreach ($items as $item) {
                        if ($item === '.' || $item === '..') continue;
                        $fullPath = $dirPath . '/' . $item;
                        if (is_file($fullPath)) {
                            $files[] = [
                                'key' => rtrim($args[0], '/') . '/' . $item,
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
                $filePath = $resolvePath($args[0]);
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
     * Check if B2 is enabled and working
     * 
     * @return bool
     */
    public function isEnabled() {
        return $this->enabled;
    }
}
