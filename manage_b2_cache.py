#!/usr/bin/env python3
"""
B2 Cache Management Utility
Provides commands to view, clear, and manage the B2 file cache.
"""

import sys
import argparse
from b2_cache_handler import B2CacheHandler


def show_stats(cache_handler):
    """Display cache statistics"""
    stats = cache_handler.get_cache_stats()
    print("\n=== B2 Cache Statistics ===")
    print(f"Cached Files: {stats['files']}")
    print(f"Total Size: {stats['total_size_mb']} MB")
    print(f"Metadata Entries: {stats['metadata_entries']}")
    
    if stats['files'] > 0:
        print("\n=== Cached Files ===")
        for key, info in cache_handler.metadata.items():
            print(f"\n  {key}")
            print(f"    Last Modified: {info.get('last_modified', 'N/A')}")
            print(f"    Downloaded: {info.get('downloaded_at', 'N/A')}")
            print(f"    Size: {info.get('size', 0)} bytes")
    else:
        print("\nNo files in cache.")


def clear_cache(cache_handler, key=None):
    """Clear cache (all or specific file)"""
    if key:
        print(f"\nClearing cache for: {key}")
        cache_handler.clear_cache(key)
    else:
        response = input("\nAre you sure you want to clear ALL cache? (yes/no): ")
        if response.lower() == 'yes':
            cache_handler.clear_cache()
            print("All cache cleared.")
        else:
            print("Operation cancelled.")


def force_refresh(cache_handler, key):
    """Force refresh a specific file from B2"""
    print(f"\nForce refreshing: {key}")
    temp_path = f"temp/refreshed_{key.replace('/', '_')}"
    success, from_cache = cache_handler.download_file(key, temp_path, force=True)
    if success:
        print(f"✓ Successfully refreshed: {key}")
        print(f"  Downloaded to: {temp_path}")
    else:
        print(f"✗ Failed to refresh: {key}")


def test_cache(cache_handler):
    """Test cache functionality with a sample file"""
    test_key = "csv/general/rooms.csv"
    test_path = "temp/test_cache_rooms.csv"
    
    print(f"\n=== Testing Cache with {test_key} ===")
    
    # First download
    print("\n1. First download (should download from B2):")
    success1, from_cache1 = cache_handler.download_file(test_key, test_path)
    print(f"   Success: {success1}, From Cache: {from_cache1}")
    
    # Second download
    print("\n2. Second download (should use cache):")
    success2, from_cache2 = cache_handler.download_file(test_key, test_path)
    print(f"   Success: {success2}, From Cache: {from_cache2}")
    
    # Force refresh
    print("\n3. Force refresh (should download from B2):")
    success3, from_cache3 = cache_handler.download_file(test_key, test_path, force=True)
    print(f"   Success: {success3}, From Cache: {from_cache3}")
    
    # Final cache check
    print("\n4. Final download (should use cache again):")
    success4, from_cache4 = cache_handler.download_file(test_key, test_path)
    print(f"   Success: {success4}, From Cache: {from_cache4}")
    
    print("\n✓ Cache test complete!")


def main():
    parser = argparse.ArgumentParser(
        description="B2 Cache Management Utility",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Examples:
  %(prog)s stats              Show cache statistics
  %(prog)s clear              Clear all cache (with confirmation)
  %(prog)s clear --key csv/general/rooms.csv  Clear specific file
  %(prog)s refresh --key csv/general/rooms.csv  Force refresh specific file
  %(prog)s test               Test cache functionality
        """
    )
    
    parser.add_argument(
        'action',
        choices=['stats', 'clear', 'refresh', 'test'],
        help='Action to perform'
    )
    
    parser.add_argument(
        '--key',
        help='Specific B2 file key (for clear/refresh actions)'
    )
    
    parser.add_argument(
        '--cache-dir',
        default='temp/b2_cache',
        help='Cache directory path (default: temp/b2_cache)'
    )
    
    args = parser.parse_args()
    
    # Initialize cache handler
    print(f"Initializing B2 Cache Handler...")
    try:
        cache_handler = B2CacheHandler(cache_dir=args.cache_dir)
    except Exception as e:
        print(f"Error initializing cache handler: {e}")
        return 1
    
    # Execute action
    try:
        if args.action == 'stats':
            show_stats(cache_handler)
        
        elif args.action == 'clear':
            clear_cache(cache_handler, args.key)
        
        elif args.action == 'refresh':
            if not args.key:
                print("Error: --key is required for refresh action")
                return 1
            force_refresh(cache_handler, args.key)
        
        elif args.action == 'test':
            test_cache(cache_handler)
        
        return 0
    
    except Exception as e:
        print(f"\nError: {e}")
        return 1


if __name__ == '__main__':
    sys.exit(main())
