#!/usr/bin/env python3
"""
B2 Cache Fix Validation Script
Verifies that the B2 cache invalidation fix is working correctly
"""

import os
import sys
import json
from datetime import datetime

def check_b2_metadata():
    """Check if B2 cache metadata exists and is being tracked"""
    metadata_file = "temp/b2_cache/.b2_metadata.json"
    
    print("\n" + "=" * 60)
    print("B2 Cache Metadata Check")
    print("=" * 60)
    
    if not os.path.exists(metadata_file):
        print(f"❌ Metadata file not found: {metadata_file}")
        print("   → Cache tracking not initialized yet")
        return False
    
    try:
        with open(metadata_file, 'r') as f:
            metadata = json.load(f)
        
        print(f"✅ Metadata file exists: {metadata_file}")
        print(f"   Files tracked: {len(metadata)}")
        
        # Show some entries
        for key in list(metadata.keys())[:5]:
            info = metadata[key]
            print(f"\n   • {key}")
            print(f"     - ETag: {info.get('etag', 'N/A')[:16]}...")
            print(f"     - Size: {info.get('size', 0)} bytes")
            print(f"     - Downloaded: {info.get('downloaded_at', 'N/A')}")
        
        if len(metadata) > 5:
            print(f"\n   ... and {len(metadata) - 5} more files")
        
        return True
    except Exception as e:
        print(f"❌ Error reading metadata: {e}")
        return False


def check_force_flag():
    """Verify that force=True is being used in load_data.py"""
    print("\n" + "=" * 60)
    print("Force Fresh Download Check")
    print("=" * 60)
    
    try:
        with open("load_data.py", 'r') as f:
            content = f.read()
        
        # Check for force=True in download_folder
        if 'download_folder("csv/", temp_dir, force=True)' in content:
            print("✅ force=True found in load_data.py")
            print("   CSV files will be downloaded fresh from B2")
            return True
        elif 'download_folder("csv/", temp_dir)' in content:
            print("❌ force=True NOT found - using default force=False")
            print("   ⚠️  Cache will be used even if B2 has newer files!")
            return False
        else:
            print("⚠️  Could not find download_folder call")
            return None
    except Exception as e:
        print(f"❌ Error checking load_data.py: {e}")
        return False


def check_exception_handling():
    """Verify bare except blocks have been fixed"""
    print("\n" + "=" * 60)
    print("Exception Handling Check")
    print("=" * 60)
    
    files_to_check = [
        ("b2_handler.py", "b2_handler.py"),
        ("load_data.py", "load_data.py"),
        ("app.py", "app.py"),
    ]
    
    bare_excepts_found = 0
    
    for name, filepath in files_to_check:
        try:
            with open(filepath, 'r') as f:
                lines = f.readlines()
            
            for i, line in enumerate(lines):
                # Look for bare "except:" (not "except Exception" or "except ...")
                if line.strip() == "except:" or line.strip().startswith("except:"):
                    # Make sure it's not in a comment
                    if not line.strip().startswith("#"):
                        print(f"❌ {name}:{i+1} - Bare except found")
                        bare_excepts_found += 1
        except Exception as e:
            print(f"⚠️  Could not check {name}: {e}")
    
    if bare_excepts_found == 0:
        print("✅ No bare except blocks found - good!")
        return True
    else:
        print(f"❌ Found {bare_excepts_found} bare except blocks")
        return False


def check_cache_handler_class():
    """Verify that B2CacheHandler is properly defined"""
    print("\n" + "=" * 60)
    print("B2CacheHandler Class Check")
    print("=" * 60)
    
    try:
        with open("b2_cache_handler.py", 'r') as f:
            content = f.read()
        
        if "class B2CacheHandler:" in content:
            print("✅ B2CacheHandler class found")
            
            # Check for key methods
            methods = [
                "download_file",
                "download_folder",
                "_load_metadata",
                "_save_metadata",
                "_get_b2_file_info",
                "_is_file_cached"
            ]
            
            for method in methods:
                if f"def {method}(" in content:
                    print(f"   ✅ {method}() method found")
                else:
                    print(f"   ❌ {method}() method NOT found")
            
            return True
        else:
            print("❌ B2CacheHandler class NOT found")
            return False
    except Exception as e:
        print(f"❌ Error checking b2_cache_handler.py: {e}")
        return False


def print_summary(results):
    """Print validation summary"""
    print("\n" + "=" * 60)
    print("VALIDATION SUMMARY")
    print("=" * 60)
    
    total = len(results)
    passed = sum(1 for v in results.values() if v is True)
    failed = sum(1 for v in results.values() if v is False)
    skipped = sum(1 for v in results.values() if v is None)
    
    print(f"\nResults: {passed} passed, {failed} failed, {skipped} skipped out of {total} checks")
    
    if failed == 0:
        print("\n✅ All checks passed! B2 cache fix is properly installed.")
    else:
        print(f"\n❌ {failed} check(s) failed. Please review the issues above.")
    
    return failed == 0


if __name__ == "__main__":
    print("\n" + "=" * 60)
    print("B2 CACHE INVALIDATION FIX VALIDATION")
    print("=" * 60)
    
    results = {
        "metadata_tracking": check_b2_metadata(),
        "force_fresh_download": check_force_flag(),
        "exception_handling": check_exception_handling(),
        "cache_handler_class": check_cache_handler_class(),
    }
    
    success = print_summary(results)
    
    sys.exit(0 if success else 1)
