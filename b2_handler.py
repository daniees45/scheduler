
import boto3
import os
import json
from botocore.exceptions import ClientError

class B2Handler:
    def __init__(self, config_path='config/b2_config_python.json', enable_cache=False, cache_dir='temp/b2_cache'):
        self.config = {}
        self.enable_cache = enable_cache
        self.cache_handler = None
        
        # If caching is enabled, use the cache handler instead
        if enable_cache:
            try:
                from b2_cache_handler import B2CacheHandler
                self.cache_handler = B2CacheHandler(config_path, cache_dir)
                # Copy references for compatibility
                self.s3 = self.cache_handler.s3
                self.bucket_name = self.cache_handler.bucket_name
                print("[INFO] B2 Caching enabled")
                return
            except Exception as e:
                print(f"[WARNING] Failed to enable B2 caching, falling back to direct mode: {e}")
                self.enable_cache = False
        
        # Try loading from JSON config
        if os.path.exists(config_path):
            with open(config_path, 'r') as f:
                self.config = json.load(f)
        
        # Override with env vars if present (priority)
        self.config['account_id'] = os.environ.get('B2_ACCOUNT_ID', self.config.get('account_id'))
        self.config['app_key_id'] = os.environ.get('B2_APP_KEY_ID', self.config.get('app_key_id'))
        self.config['app_key'] = os.environ.get('B2_APP_KEY', self.config.get('app_key'))
        self.config['bucket_name'] = os.environ.get('B2_BUCKET', self.config.get('bucket_name', 'vvu-scheduler'))
        self.config['endpoint'] = os.environ.get('B2_ENDPOINT', self.config.get('endpoint'))
        self.config['region'] = os.environ.get('B2_REGION', self.config.get('region'))

        if not self.config.get('app_key_id') or not self.config.get('app_key'):
             print("[WARNING] B2 Credentials missing in B2Handler")

        try:
            self.s3 = boto3.client(
                's3',
                endpoint_url=self.config.get('endpoint'),
                aws_access_key_id=self.config.get('app_key_id'),
                aws_secret_access_key=self.config.get('app_key'),
                region_name=self.config.get('region')
            )
            self.bucket_name = self.config.get('bucket_name')
            print(f"[INFO] B2 Handler initialized for bucket: {self.bucket_name}")
        except Exception as e:
            print(f"[ERROR] Failed to initialize B2 client: {e}")
            self.s3 = "fallback"

    def _fallback_to_local_download(self, key, download_path):
        try:
            if os.path.abspath(key) == os.path.abspath(download_path):
                return True
            if not os.path.exists(key):
                print(f"[WARNING] Local fallback source file not found: {key}")
                return False
            dirname = os.path.dirname(download_path)
            if dirname:
                os.makedirs(dirname, exist_ok=True)
            import shutil
            shutil.copy2(key, download_path)
            print(f"[LOCAL FALLBACK] Downloaded {key} to {download_path}")
            return True
        except Exception as e:
            print(f"[ERROR] Local fallback download failed for {key}: {e}")
            return False

    def _fallback_to_local_upload(self, local_path, key):
        try:
            if os.path.abspath(local_path) == os.path.abspath(key):
                return True
            dirname = os.path.dirname(key)
            if dirname:
                os.makedirs(dirname, exist_ok=True)
            import shutil
            shutil.copy2(local_path, key)
            print(f"[LOCAL FALLBACK] Uploaded {local_path} to {key}")
            return True
        except Exception as e:
            print(f"[ERROR] Local fallback upload failed for {key}: {e}")
            return False

    def download_file(self, key, download_path, force=False):
        # Use cache handler if enabled - passes through force parameter
        if self.cache_handler:
            try:
                success, from_cache = self.cache_handler.download_file(key, download_path, force)
                if force and from_cache:
                    print(f"[WARNING] force=True but cache was used for {key}")
                return success
            except Exception as e:
                print(f"[ERROR] B2CacheHandler error for {key}: {type(e).__name__}: {e}")
                # Fall through to direct download
        
        if not self.s3 or self.s3 == "fallback":
            return self._fallback_to_local_download(key, download_path)
        try:
            # Create parent dirs if needed
            dirname = os.path.dirname(download_path)
            if dirname:
                os.makedirs(dirname, exist_ok=True)
            self.s3.download_file(self.bucket_name, key, download_path)
            # print(f"[INFO] Downloaded {key} to {download_path}")
            return True
        except ClientError as e:
            if e.response['Error']['Code'] == "404":
                print(f"[WARNING] File not found in B2: {key}")
            else:
                print(f"[ERROR] B2 Download error for {key}: {e}")
            print(f"[INFO] Falling back to local file for key: {key}")
            return self._fallback_to_local_download(key, download_path)
        except Exception as e:
            print(f"[ERROR] B2 Download exception for {key}: {e}")
            print(f"[INFO] Falling back to local file for key: {key}")
            return self._fallback_to_local_download(key, download_path)

    def upload_file(self, local_path, key):
        if not self.s3 or self.s3 == "fallback":
            return self._fallback_to_local_upload(local_path, key)
        try:
            self.s3.upload_file(local_path, self.bucket_name, key)
            print(f"[INFO] Uploaded {local_path} to {key}")
            return True
        except Exception as e:
            print(f"[ERROR] B2 Upload error for {key}: {e}")
            print(f"[INFO] Falling back to local file for upload: {key}")
            return self._fallback_to_local_upload(local_path, key)

    def download_folder(self, prefix, local_dir, force=False):
        """Downloads all files with a prefix to a local directory, preserving structure."""
        # Use cache handler if enabled
        if self.cache_handler:
            try:
                stats = self.cache_handler.download_folder(prefix, local_dir, force)
                if isinstance(stats, dict):
                    success = stats.get('success', False)
                    count = stats.get('count', 0)
                    if success:
                        print(f"[INFO] Cache handler: Downloaded {count} files from B2 prefix '{prefix}' (force={force})")
                    return success
                return False
            except Exception as e:
                print(f"[ERROR] B2CacheHandler folder download failed for '{prefix}': {type(e).__name__}: {e}")
                # Fall through to direct download
        
        if not self.s3 or self.s3 == "fallback":
            return self._fallback_to_local_folder(prefix, local_dir, force)
        try:
            paginator = self.s3.get_paginator('list_objects_v2')
            pages = paginator.paginate(Bucket=self.bucket_name, Prefix=prefix)

            count = 0
            for page in pages:
                if 'Contents' in page:
                    for obj in page['Contents']:
                        key = obj['Key']
                        relative_path = key
                        local_file_path = os.path.join(local_dir, relative_path)
                        
                        self.download_file(key, local_file_path, force)
                        count += 1
            print(f"[INFO] Downloaded {count} files from B2 prefix '{prefix}'")
            return True
        except Exception as e:
            print(f"[ERROR] B2 Download Folder error: {e}. Falling back to local.")
            return self._fallback_to_local_folder(prefix, local_dir, force)

    def _fallback_to_local_folder(self, prefix, local_dir, force=False):
        count = 0
        try:
            if os.path.exists(prefix):
                if os.path.isdir(prefix):
                    for root, dirs, files in os.walk(prefix):
                        for file in files:
                            if file.startswith('.'):
                                continue
                            file_path = os.path.join(root, file)
                            key = file_path
                            local_file_path = os.path.join(local_dir, key)
                            if self.download_file(key, local_file_path, force):
                                count += 1
                elif os.path.isfile(prefix):
                    key = prefix
                    local_file_path = os.path.join(local_dir, key)
                    if self.download_file(key, local_file_path, force):
                        count = 1
            print(f"[LOCAL FALLBACK] Synchronized {count} files for prefix '{prefix}'")
            return True
        except Exception as e:
            print(f"[ERROR] Local fallback folder download failed: {e}")
            return False
            