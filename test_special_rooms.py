#!/usr/bin/env python3
"""
Test script to verify special_rooms.csv is being loaded and used correctly
"""

import sys
import os

# Add project root to path
sys.path.insert(0, os.path.dirname(__file__))

from load_data import load_combined_data
from builder import build_domain

def test_special_rooms():
    print("=" * 60)
    print("TESTING SPECIAL ROOMS IMPLEMENTATION")
    print("=" * 60)
    
    # Test 1: Load data with special_rooms
    print("\n[TEST 1] Loading data with special_rooms.csv...")
    try:
        data = load_combined_data(
            ["csv/clean/general.csv"],
            special_rooms_path="csv/general/special_rooms.csv",
            interactive=False
        )
        
        special_rooms = data.get('special_rooms', {})
        print(f"✅ Data loaded successfully")
        print(f"   Special rooms found: {len(special_rooms)}")
        
        if special_rooms:
            print("\n   Special room assignments:")
            for code, info in special_rooms.items():
                if isinstance(info, dict):
                    room = info.get('room', 'Unknown')
                    day = info.get('day', 'Any')
                    slot = info.get('slot', 'Any')
                    print(f"   - {code:15} → Room: {room:20} Day: {day if day is not None else 'Any':10} Slot: {slot if slot is not None else 'Any'}")
                else:
                    print(f"   - {code:15} → {info}")
        else:
            print("   ⚠️  No special rooms loaded!")
            
    except Exception as e:
        print(f"❌ Failed to load data: {e}")
        return False
    
    # Test 2: Build domain and verify constraints
    print("\n[TEST 2] Building domain with special room constraints...")
    try:
        domain = build_domain(data)
        print(f"✅ Domain built successfully")
        print(f"   Total sections: {len(data['sections'])}")
        print(f"   Total domains: {len(domain)}")
        
        # Check if any section has a locked domain due to special_rooms
        locked_domains = 0
        for sec in data['sections']:
            if sec.id in domain:
                domain_size = len(domain[sec.id])
                if domain_size == 1:  # Fully locked
                    locked_domains += 1
                    day, slot, room = domain[sec.id][0]
                    print(f"   - {sec.course_code} is LOCKED to: Day {day}, Slot {slot}, Room {room}")
                elif sec.course_code in special_rooms:
                    print(f"   - {sec.course_code} uses special room: {domain_size} possible slots")
        
        print(f"\n   Fully locked domains: {locked_domains}")
        
        if locked_domains == 0 and special_rooms:
            print("   ⚠️  Special rooms defined but no domains were locked!")
            
    except Exception as e:
        print(f"❌ Failed to build domain: {e}")
        import traceback
        traceback.print_exc()
        return False
    
    # Test 3: Verify special_rooms.csv file exists
    print("\n[TEST 3] Checking special_rooms.csv file...")
    if os.path.exists("special_rooms.csv"):
        print("✅ special_rooms.csv found in project root")
        with open("special_rooms.csv", "r") as f:
            lines = f.readlines()
            print(f"   File has {len(lines)} lines")
            if len(lines) > 1:
                print("   Sample content:")
                for line in lines[:5]:
                    print(f"   {line.strip()}")
    else:
        print("⚠️  special_rooms.csv NOT found in project root")
        print("   Checking alternative locations...")
        if os.path.exists("csv/general/special_rooms.csv"):
            print("   ✅ Found in csv/general/special_rooms.csv")
        else:
            print("   ❌ Not found anywhere!")
    
    print("\n" + "=" * 60)
    print("TEST COMPLETE")
    print("=" * 60)
    return True

if __name__ == "__main__":
    test_special_rooms()
