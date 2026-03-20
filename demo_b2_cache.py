#!/usr/bin/env python3
"""
B2 Caching Demo
Demonstrates the performance improvement with B2 caching enabled.
"""

import time
import os
from b2_handler import B2Handler


def demo_without_cache():
    """Demonstrate download without caching"""
    print("\n" + "="*60)
    print("DEMO 1: WITHOUT CACHING (Legacy Mode)")
    print("="*60)
    
    # Clean up test files
    test_files = ["temp/test_no_cache_1.csv", "temp/test_no_cache_2.csv"]
    for f in test_files:
        if os.path.exists(f):
            os.remove(f)
    
    b2 = B2Handler(enable_cache=False)
    if not b2.s3:
        print("B2 not available. Skipping demo.")
        return
    
    test_key = "csv/general/rooms.csv"
    
    # First download
    print("\n1. First download:")
    start = time.time()
    b2.download_file(test_key, test_files[0])
    elapsed1 = time.time() - start
    print(f"   Time: {elapsed1:.3f} seconds")
    
    # Second download (still downloads from B2)
    print("\n2. Second download:")
    start = time.time()
    b2.download_file(test_key, test_files[1])
    elapsed2 = time.time() - start
    print(f"   Time: {elapsed2:.3f} seconds")
    
    print(f"\n   Total time: {elapsed1 + elapsed2:.3f} seconds")
    print("   Note: Both downloads hit B2 (no caching)")
    
    return elapsed1 + elapsed2


def demo_with_cache():
    """Demonstrate download with caching"""
    print("\n" + "="*60)
    print("DEMO 2: WITH CACHING (Optimized Mode)")
    print("="*60)
    
    # Clean up test files and cache
    test_files = ["temp/test_cache_1.csv", "temp/test_cache_2.csv"]
    for f in test_files:
        if os.path.exists(f):
            os.remove(f)
    
    # Clear cache for this specific file to ensure clean test
    from b2_cache_handler import B2CacheHandler
    cache = B2CacheHandler()
    cache.clear_cache("csv/general/rooms.csv")
    
    b2 = B2Handler(enable_cache=True)
    if not b2.s3:
        print("B2 not available. Skipping demo.")
        return
    
    test_key = "csv/general/rooms.csv"
    
    # First download (will download from B2 and cache)
    print("\n1. First download:")
    start = time.time()
    b2.download_file(test_key, test_files[0])
    elapsed1 = time.time() - start
    print(f"   Time: {elapsed1:.3f} seconds")
    
    # Second download (will use cache)
    print("\n2. Second download:")
    start = time.time()
    b2.download_file(test_key, test_files[1])
    elapsed2 = time.time() - start
    print(f"   Time: {elapsed2:.3f} seconds")
    
    print(f"\n   Total time: {elapsed1 + elapsed2:.3f} seconds")
    print("   Note: Second download used cache (much faster!)")
    
    return elapsed1 + elapsed2


def demo_folder_sync():
    """Demonstrate folder sync with caching"""
    print("\n" + "="*60)
    print("DEMO 3: FOLDER SYNC WITH CACHE STATISTICS")
    print("="*60)
    
    # Use cache handler directly to get stats
    from b2_cache_handler import B2CacheHandler
    
    b2 = B2CacheHandler()
    if not b2.s3:
        print("B2 not available. Skipping demo.")
        return
    
    print("\n1. First sync (will download new/updated files):")
    start = time.time()
    stats1 = b2.download_folder("csv/general/", "temp/demo_sync/", force=False)
    elapsed1 = time.time() - start
    print(f"\n   Results:")
    print(f"   - Total files: {stats1['total']}")
    print(f"   - Downloaded: {stats1['downloaded']}")
    print(f"   - From cache: {stats1['cached']}")
    print(f"   - Time: {elapsed1:.3f} seconds")
    
    print("\n2. Second sync (should mostly use cache):")
    start = time.time()
    stats2 = b2.download_folder("csv/general/", "temp/demo_sync/", force=False)
    elapsed2 = time.time() - start
    print(f"\n   Results:")
    print(f"   - Total files: {stats2['total']}")
    print(f"   - Downloaded: {stats2['downloaded']}")
    print(f"   - From cache: {stats2['cached']}")
    print(f"   - Time: {elapsed2:.3f} seconds")
    
    speedup = elapsed1 / elapsed2 if elapsed2 > 0 else float('inf')
    print(f"\n   Speedup: {speedup:.1f}x faster!")


def main():
    print("\n" + "="*60)
    print("B2 CACHING SYSTEM - PERFORMANCE DEMO")
    print("="*60)
    print("\nThis demo shows the performance difference between:")
    print("  • Legacy mode: Downloads from B2 every time")
    print("  • Cached mode: Downloads only when files change")
    print("\nNote: First run builds the cache, subsequent runs are much faster!")
    
    input("\nPress Enter to start demo...")
    
    # Demo 1: Without cache
    time1 = demo_without_cache()
    
    input("\nPress Enter to continue to cached demo...")
    
    # Demo 2: With cache
    time2 = demo_with_cache()
    
    # Comparison
    if time1 and time2:
        print("\n" + "="*60)
        print("PERFORMANCE COMPARISON")
        print("="*60)
        print(f"Without cache: {time1:.3f} seconds")
        print(f"With cache:    {time2:.3f} seconds")
        speedup = time1 / time2
        print(f"\nCache speedup: {speedup:.1f}x faster!")
        savings = ((time1 - time2) / time1) * 100
        print(f"Time savings:  {savings:.1f}% reduction")
    
    input("\nPress Enter to demonstrate folder sync...")
    
    # Demo 3: Folder sync
    demo_folder_sync()
    
    # Cache stats
    print("\n" + "="*60)
    print("CACHE STATISTICS")
    print("="*60)
    
    from b2_cache_handler import B2CacheHandler
    cache = B2CacheHandler()
    stats = cache.get_cache_stats()
    
    print(f"\nCached files: {stats['files']}")
    print(f"Cache size:   {stats['total_size_mb']} MB")
    print(f"Metadata entries: {stats['metadata_entries']}")
    
    print("\n" + "="*60)
    print("DEMO COMPLETE")
    print("="*60)
    print("\nKey takeaways:")
    print("  ✓ Cache automatically detects file changes using ETags")
    print("  ✓ Unchanged files are served from cache (much faster)")
    print("  ✓ No code changes needed - just enable caching")
    print("  ✓ Significant performance gains on subsequent runs")
    print("\nFor more info, see: B2_CACHING_GUIDE.md")


if __name__ == '__main__':
    main()
