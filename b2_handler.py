
import boto3
import os
import json
from botocore.exceptions import ClientError

class B2Handler:
    def __init__(self, config_path='config/b2_config_python.json'):
        self.config = {}
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
            self.s3 = None

    def download_file(self, key, download_path):
        if not self.s3:
            return False
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
            return False

    def upload_file(self, local_path, key):
        if not self.s3:
            return False
        try:
            self.s3.upload_file(local_path, self.bucket_name, key)
            print(f"[INFO] Uploaded {local_path} to {key}")
            return True
        except Exception as e:
             print(f"[ERROR] B2 Upload error for {key}: {e}")
             return False

    def download_folder(self, prefix, local_dir):
        """Downloads all files with a prefix to a local directory, preserving structure."""
        if not self.s3:
            return False
        try:
            paginator = self.s3.get_paginator('list_objects_v2')
            pages = paginator.paginate(Bucket=self.bucket_name, Prefix=prefix)

            count = 0
            for page in pages:
                if 'Contents' in page:
                    for obj in page['Contents']:
                        key = obj['Key']
                        # Calculate local path
                        # If prefix is 'csv/', we want 'csv/general/rooms.csv' -> 'local_dir/csv/general/rooms.csv'
                        # Or if we want to flatten? No, preserve structure relative to bucket root is safest usually,
                        # but standard usage here seems to simply map bucket/key to local/key
                        
                        # However, user wants to remove local storage.
                        # We are downloading to a TEMP directory likely.
                        
                        relative_path = key
                        local_file_path = os.path.join(local_dir, relative_path)
                        
                        self.download_file(key, local_file_path)
                        count += 1
            print(f"[INFO] Downloaded {count} files from B2 prefix '{prefix}'")
            return True
        except Exception as e:
            print(f"[ERROR] B2 Download Folder error: {e}")
            return False
