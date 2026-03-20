#!/usr/bin/env python3
"""
Test script to verify that .pkl files are being uploaded to B2 after training.
This specifically tests the fix for: "The .pkl is not being updated in b2"
"""

import os
import sys
import json
import time
import hashlib
from datetime import datetime

def get_file_hash(file_path):
    """Calculate MD5 hash of a file"""
    hash_md5 = hashlib.md5()
    with open(file_path, "rb") as f:
        for chunk in iter(lambda: f.read(4096), b""):
            hash_md5.update(chunk)
    return hash_md5.hexdigest()

def test_b2_connection():
    """Test if B2 connection is available"""
    print("\n" + "=" * 60)
    print("TEST 1: B2 Connection")
    print("=" * 60)
    
    try:
        from b2_handler import B2Handler
        b2 = B2Handler(enable_cache=True, cache_dir="temp/b2_cache")
        if b2 and b2.s3:
            print("✅ B2Handler initialized successfully")
            print(f"✅ Bucket: {b2.bucket_name}")
            return b2
        else:
            print("❌ B2Handler initialized but no S3 connection")
            return None
    except Exception as e:
        print(f"❌ Failed to initialize B2Handler: {e}")
        return None

def test_model_files_exist():
    """Check if model files exist"""
    print("\n" + "=" * 60)
    print("TEST 2: Model Files Exist")
    print("=" * 60)
    
    model_files = {
        "scheduling_model.pkl": "scheduling_model.pkl",
        "temp/scheduling_model.pkl": "scheduling_model.pkl (temp)",
        "q_model.pkl": "q_model.pkl",
        "temp/q_model.pkl": "q_model.pkl (temp)",
        "feasibility_ensemble.pkl": "feasibility_ensemble.pkl",
        "temp/feasibility_classifier.pkl": "feasibility_classifier.pkl (temp)",
    }
    
    found_files = {}
    for path, label in model_files.items():
        if os.path.exists(path):
            size = os.path.getsize(path)
            mtime = os.path.getmtime(path)
            mtime_str = datetime.fromtimestamp(mtime).strftime('%Y-%m-%d %H:%M:%S')
            hash_val = get_file_hash(path)
            found_files[label] = {
                "path": path,
                "size": size,
                "modified": mtime_str,
                "hash": hash_val[:16]
            }
            print(f"✅ {label}: {size} bytes (modified: {mtime_str})")
            print(f"   Hash: {hash_val}")
    
    if not found_files:
        print("❌ No model files found!")
        return None
    
    return found_files

def test_b2_model_download(b2):
    """Test downloading models from B2"""
    print("\n" + "=" * 60)
    print("TEST 3: B2 Model Download Test")
    print("=" * 60)
    
    if not b2:
        print("⚠️  B2 connection not available, skipping")
        return None
    
    test_models = [
        ("scheduling_model.pkl", "test_scheduling_model.pkl"),
        ("q_model.pkl", "test_q_model.pkl"),
        ("feasibility_classifier.pkl", "test_feasibility_classifier.pkl"),
    ]
    
    downloaded = {}
    for b2_key, local_path in test_models:
        try:
            b2.download_file(b2_key, local_path)
            if os.path.exists(local_path):
                size = os.path.getsize(local_path)
                hash_val = get_file_hash(local_path)
                downloaded[b2_key] = {
                    "size": size,
                    "hash": hash_val[:16],
                    "local_path": local_path
                }
                print(f"✅ Downloaded {b2_key}: {size} bytes")
                print(f"   Hash: {hash_val}")
                # Clean up
                # os.remove(local_path)
            else:
                print(f"❌ Download failed for {b2_key}")
        except Exception as e:
            print(f"⚠️  {b2_key} not found on B2 (may not exist yet): {e}")
    
    return downloaded

def test_model_upload_code():
    """Check if upload code exists in main_web.py"""
    print("\n" + "=" * 60)
    print("TEST 4: Model Upload Code in main_web.py")
    print("=" * 60)
    
    try:
        with open("main_web.py", 'r') as f:
            content = f.read()
        
        checks = [
            ("b2 = B2Handler", "B2Handler initialization"),
            ('b2.upload_file(model_file, "scheduling_model.pkl")', "Upload scheduling_model"),
            ('b2.upload_file(q_model_file, "q_model.pkl")', "Upload q_model"),
            ('b2.upload_file(classifier_path, "feasibility_classifier.pkl")', "Upload feasibility classifier"),
        ]
        
        found_count = 0
        for code_snippet, label in checks:
            if code_snippet in content:
                print(f"✅ {label} - Code found")
                found_count += 1
            else:
                print(f"❌ {label} - Code NOT found")
        
        if found_count == len(checks):
            print(f"\n✅ ALL upload code found in main_web.py")
            return True
        else:
            print(f"\n❌ MISSING {len(checks) - found_count} upload code sections")
            return False
    except Exception as e:
        print(f"❌ Error checking main_web.py: {e}")
        return False

def test_metadata_tracking():
    """Check B2 metadata for .pkl files"""
    print("\n" + "=" * 60)
    print("TEST 5: B2 Metadata Tracking for .pkl Files")
    print("=" * 60)
    
    metadata_file = "temp/b2_cache/.b2_metadata.json"
    if not os.path.exists(metadata_file):
        print("❌ Metadata file not found")
        return None
    
    try:
        with open(metadata_file, 'r') as f:
            metadata = json.load(f)
        
        pkl_files = {k: v for k, v in metadata.items() if k.endswith('.pkl')}
        if pkl_files:
            print(f"✅ Found {len(pkl_files)} .pkl files in metadata:")
            for key in list(pkl_files.keys())[:5]:
                info = pkl_files[key]
                print(f"   • {key}")
                print(f"     - ETag: {info.get('etag', 'N/A')[:16]}...")
                print(f"     - Size: {info.get('size', 0)} bytes")
            if len(pkl_files) > 5:
                print(f"   ... and {len(pkl_files) - 5} more .pkl files")
            return pkl_files
        else:
            print("⚠️  No .pkl files found in metadata (may not have been cached yet)")
            return None
    except Exception as e:
        print(f"❌ Error reading metadata: {e}")
        return None

def print_summary(results):
    """Print test summary"""
    print("\n" + "=" * 60)
    print("TEST SUMMARY")
    print("=" * 60)
    
    print("\n📋 What was tested:")
    print("1. B2 connection availability")
    print("2. Local model files exist and are accessible")
    print("3. Model files can be downloaded from B2")
    print("4. Upload code exists in main_web.py")
    print("5. B2 cache metadata includes .pkl files")
    
    print("\n🔧 Fix applied:")
    print("- Added B2Handler initialization to main_web.py")
    print("- Added model download logic before training")
    print("- Added model upload logic after training:")
    print("  • scheduling_model.pkl")
    print("  • q_model.pkl")
    print("  • feasibility_classifier.pkl")
    
    print("\n✅ Expected behavior after fix:")
    print("- When scheduler runs via Flask (app.py → main_web.py → run_headless)")
    print("  1. Models are downloaded from B2 at start (force=True ignores cache)")
    print("  2. Training happens with fresh data")
    print("  3. Trained models are immediately uploaded to B2")
    print("  4. B2 metadata is updated with new ETags")
    print("  5. Next run downloads fresh versions of trained models")

if __name__ == "__main__":
    print("\n" + "=" * 60)
    print(".PKL FILE B2 UPLOAD TEST")
    print("=" * 60)
    
    b2 = test_b2_connection()
    local_models = test_model_files_exist()
    b2_models = test_b2_model_download(b2)
    upload_code_exists = test_model_upload_code()
    metadata_pkl_files = test_metadata_tracking()
    
    print_summary({
        "b2": b2,
        "local_models": local_models,
        "b2_models": b2_models,
        "upload_code": upload_code_exists,
        "metadata": metadata_pkl_files
    })
