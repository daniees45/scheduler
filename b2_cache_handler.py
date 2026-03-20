"""
B2 Cache Handler - Optimized file downloads with change detection
Downloads files from Backblaze B2 only when they've been updated,
using local cache for unchanged files.
"""

import boto3
import os
import json
import hashlib
from datetime import datetime
from botocore.exceptions import ClientError


class B2CacheHandler:
    def __init__(self, config_path='config/b2_config_python.json', cache_dir='temp/b2_cache'):
        self.config = {}
        self.cache_dir = cache_dir
        self.metadata_file = os.path.join(cache_dir, '.b2_metadata.json')
        self.metadata = {}
        
        # Create cache directory
        os.makedirs(cache_dir, exist_ok=True)
        
        # Load existing metadata
        self._load_metadata()
        
        # Load B2 configuration
        if os.path.exists(config_path):
            with open(config_path, 'r') as f:
                self.config = json.load(f)
        
        # Override with env vars if present
        self.config['account_id'] = os.environ.get('B2_ACCOUNT_ID', self.config.get('account_id'))
        self.config['app_key_id'] = os.environ.get('B2_APP_KEY_ID', self.config.get('app_key_id'))
        self.config['app_key'] = os.environ.get('B2_APP_KEY', self.config.get('app_key'))
        self.config['bucket_name'] = os.environ.get('B2_BUCKET', self.config.get('bucket_name', 'vvu-scheduler'))
        self.config['endpoint'] = os.environ.get('B2_ENDPOINT', self.config.get('endpoint'))
        self.config['region'] = os.environ.get('B2_REGION', self.config.get('region'))

        if not self.config.get('app_key_id') or not self.config.get('app_key'):
            print("[WARNING] B2 Credentials missing in B2CacheHandler")

        try:
            self.s3 = boto3.client(
                's3',
                endpoint_url=self.config.get('endpoint'),
                aws_access_key_id=self.config.get('app_key_id'),
                aws_secret_access_key=self.config.get('app_key'),
                region_name=self.config.get('region')
            )
            self.bucket_name = self.config.get('bucket_name')
            print(f"[INFO] B2 Cache Handler initialized for bucket: {self.bucket_name}")
            print(f"[INFO] Cache directory: {self.cache_dir}")
        except Exception as e:
            print(f"[ERROR] Failed to initialize B2 client: {e}")
            self.s3 = None

    def _load_metadata(self):
        """Load cached file metadata from disk"""
        if os.path.exists(self.metadata_file):
            try:
                with open(self.metadata_file, 'r') as f:
                    self.metadata = json.load(f)
            except Exception as e:
                print(f"[WARNING] Failed to load B2 metadata: {e}")
                self.metadata = {}

    def _save_metadata(self):
        """Save file metadata to disk"""
        try:
            with open(self.metadata_file, 'w') as f:
                json.dump(self.metadata, f, indent=2)
        except Exception as e:
            print(f"[ERROR] Failed to save B2 metadata: {e}")

    def _get_b2_file_info(self, key):
        """Get file metadata from B2 without downloading"""
        if not self.s3:
            return None
        try:
            response = self.s3.head_object(Bucket=self.bucket_name, Key=key)
            return {
                'etag': response.get('ETag', '').strip('"'),
                'last_modified': response.get('LastModified').isoformat() if response.get('LastModified') else None,
                'size': response.get('ContentLength', 0)
            }
        except ClientError as e:
            if e.response['Error']['Code'] == '404':
                print(f"[WARNING] File not found in B2: {key}")
            return None

    def _is_file_cached(self, key, b2_info):
        """Check if we have a valid cached version of the file"""
        if key not in self.metadata:
            return False
        
        cached_info = self.metadata[key]
        cache_path = os.path.join(self.cache_dir, key)
        
        # Check if cached file exists
        if not os.path.exists(cache_path):
            return False
        
        # Compare ETags (most reliable)
        if b2_info.get('etag') and cached_info.get('etag'):
            if b2_info['etag'] == cached_info['etag']:
                return True
        
        # Fallback: compare last modified time
        if b2_info.get('last_modified') and cached_info.get('last_modified'):
            if b2_info['last_modified'] == cached_info['last_modified']:
                return True
        
        # Fallback: compare file size
        if b2_info.get('size') and cached_info.get('size'):
            if b2_info['size'] == cached_info['size']:
                actual_size = os.path.getsize(cache_path)
                if actual_size == b2_info['size']:
                    return True
        
        return False

    def download_file(self, key, download_path, force=False):
        """
        Download a file from B2 with caching.
        
        Args:
            key: B2 object key
            download_path: Local destination path
            force: Force download even if cached version exists
            
        Returns:
            tuple: (success: bool, from_cache: bool)
        """
        if not self.s3:
            return False, False
        
        try:
            # Get file info from B2
            b2_info = self._get_b2_file_info(key)
            if not b2_info:
                return False, False
            
            # Check if we can use cached version
            if not force and self._is_file_cached(key, b2_info):
                print(f"[CACHE HIT] Using cached version: {key}")
                
                # Copy from cache to destination
                cache_path = os.path.join(self.cache_dir, key)
                dirname = os.path.dirname(download_path)
                if dirname:
                    os.makedirs(dirname, exist_ok=True)
                
                # Copy file from cache
                import shutil
                shutil.copy2(cache_path, download_path)
                return True, True
            
            # Download from B2
            print(f"[CACHE MISS] Downloading from B2: {key}")
            
            # Download to cache location
            cache_path = os.path.join(self.cache_dir, key)
            cache_dirname = os.path.dirname(cache_path)
            if cache_dirname:
                os.makedirs(cache_dirname, exist_ok=True)
            
            self.s3.download_file(self.bucket_name, key, cache_path)
            
            # Update metadata
            self.metadata[key] = {
                'etag': b2_info['etag'],
                'last_modified': b2_info['last_modified'],
                'size': b2_info['size'],
                'downloaded_at': datetime.now().isoformat()
            }
            self._save_metadata()
            
            # Copy to destination
            dirname = os.path.dirname(download_path)
            if dirname:
                os.makedirs(dirname, exist_ok=True)
            
            import shutil
            shutil.copy2(cache_path, download_path)
            
            return True, False
            
        except ClientError as e:
            if e.response['Error']['Code'] == "404":
                print(f"[WARNING] File not found in B2: {key}")
            else:
                print(f"[ERROR] B2 Download error for {key}: {e}")
            return False, False

    def upload_file(self, local_path, key):
        """Upload a file to B2 and update cache"""
        if not self.s3:
            return False
        try:
            self.s3.upload_file(local_path, self.bucket_name, key)
            print(f"[INFO] Uploaded {local_path} to {key}")
            
            # Update cache and metadata
            b2_info = self._get_b2_file_info(key)
            if b2_info:
                cache_path = os.path.join(self.cache_dir, key)
                cache_dirname = os.path.dirname(cache_path)
                if cache_dirname:
                    os.makedirs(cache_dirname, exist_ok=True)
                
                import shutil
                shutil.copy2(local_path, cache_path)
                
                self.metadata[key] = {
                    'etag': b2_info['etag'],
                    'last_modified': b2_info['last_modified'],
                    'size': b2_info['size'],
                    'downloaded_at': datetime.now().isoformat()
                }
                self._save_metadata()
            
            return True
        except Exception as e:
            print(f"[ERROR] B2 Upload error for {key}: {e}")
            return False

    def download_folder(self, prefix, local_dir, force=False):
        """
        Download all files with a prefix to a local directory with caching.
        
        Args:
            prefix: B2 prefix to download
            local_dir: Local destination directory
            force: Force download even if cached versions exist
            
        Returns:
            dict: Statistics about the download operation
        """
        if not self.s3:
            return {'success': False, 'total': 0, 'downloaded': 0, 'cached': 0}
        
        try:
            paginator = self.s3.get_paginator('list_objects_v2')
            pages = paginator.paginate(Bucket=self.bucket_name, Prefix=prefix)

            stats = {'success': True, 'total': 0, 'downloaded': 0, 'cached': 0}
            
            for page in pages:
                if 'Contents' in page:
                    for obj in page['Contents']:
                        key = obj['Key']
                        relative_path = key
                        local_file_path = os.path.join(local_dir, relative_path)
                        
                        success, from_cache = self.download_file(key, local_file_path, force)
                        if success:
                            stats['total'] += 1
                            if from_cache:
                                stats['cached'] += 1
                            else:
                                stats['downloaded'] += 1
            
            print(f"[INFO] Folder sync complete: {stats['total']} files "
                  f"({stats['downloaded']} downloaded, {stats['cached']} from cache)")
            return stats
            
        except Exception as e:
            print(f"[ERROR] B2 Download Folder error: {e}")
            return {'success': False, 'total': 0, 'downloaded': 0, 'cached': 0}

    def clear_cache(self, key=None):
        """Clear cache for a specific file or all files"""
        if key:
            # Clear specific file
            cache_path = os.path.join(self.cache_dir, key)
            if os.path.exists(cache_path):
                os.remove(cache_path)
            if key in self.metadata:
                del self.metadata[key]
                self._save_metadata()
            print(f"[INFO] Cleared cache for: {key}")
        else:
            # Clear all cache
            import shutil
            if os.path.exists(self.cache_dir):
                shutil.rmtree(self.cache_dir)
                os.makedirs(self.cache_dir, exist_ok=True)
            self.metadata = {}
            self._save_metadata()
            print("[INFO] Cleared all cache")

    def get_cache_stats(self):
        """Get statistics about the cache"""
        total_size = 0
        file_count = 0
        
        for key in self.metadata:
            cache_path = os.path.join(self.cache_dir, key)
            if os.path.exists(cache_path):
                file_count += 1
                total_size += os.path.getsize(cache_path)
        
        return {
            'files': file_count,
            'total_size_mb': round(total_size / (1024 * 1024), 2),
            'metadata_entries': len(self.metadata)
        }
