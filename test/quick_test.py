#!/usr/bin/env python3
"""
QUICK PERFORMANCE TEST
Purpose: Test if CSP solver works and measure speed
User selects input CSV file to benchmark
"""

import time
import sys
import os
import glob

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from load_data import load_combined_data
from builder import build_domain
from constraints import make_constraints
from csp import CSP

def select_input_csv():
    """
    Let user enter CSV file path or select from available files
    
    Returns:
        str: CSV file path
    """
    print("\n" + "="*70)
    print("VVU SCHEDULER - QUICK PERFORMANCE TEST")
    print("="*70)
    
    print("\nEnter CSV file path (or leave blank to select from list):")
    print("-" * 70)
    user_input = input("CSV file path: ").strip()
    
    # If user provided a path, use it
    if user_input:
        if os.path.exists(user_input):
            print(f"✅ Using: {user_input}")
            return user_input
        else:
            print(f"❌ File not found: {user_input}")
            sys.exit(1)
    
    # Otherwise, show available files
    print("\nShowing available CSV files in current directory:")
    print("-" * 70)
    
    # Find all CSV files in current directory
    csv_files = sorted([f for f in glob.glob("*.csv") if os.path.isfile(f)])
    
    # Filter for likely input files (prioritized)
    preferred_files = [
        "courses_input.csv",
        "comp_final.csv",
        "general.csv",
        "comp.csv",
        "comp_clean.csv",
        "fac_comp.csv"
    ]
    
    available = [f for f in preferred_files if f in csv_files]
    if not available:
        available = csv_files[:10]  # Show first 10 if no preferred files
    
    if not available:
        print("\n❌ No CSV files found in current directory")
        sys.exit(1)
    
    for i, filename in enumerate(available, 1):
        # Get file size for reference
        size_kb = os.path.getsize(filename) / 1024
        print(f"{i}. {filename:<35} ({size_kb:>6.1f} KB)")
    
    print("-" * 70)
    print("Or enter a custom path above")
    
    while True:
        try:
            choice = input("\nSelect by number (or press Ctrl+C to exit): ").strip()
            idx = int(choice) - 1
            if 0 <= idx < len(available):
                selected = available[idx]
                print(f"✅ Selected: {selected}")
                return selected
            else:
                print(f"❌ Invalid choice. Enter 1-{len(available)}")
        except ValueError:
            print("❌ Please enter a valid number")

def main():
    try:
        # Get user's CSV selection
        input_csv = select_input_csv()
        rooms_csv = "rooms.csv"
        
        # Verify rooms file exists
        if not os.path.exists(rooms_csv):
            print(f"\n❌ ERROR: {rooms_csv} not found")
            sys.exit(1)
        
        # Load data
        print(f"\n[1] Loading courses from {input_csv}...")
        start_load = time.time()
        data = load_combined_data([input_csv], rooms_csv_path=rooms_csv, interactive=False)
        load_time = time.time() - start_load
        print(f"    ✅ Loaded in {load_time:.2f}s")
        print(f"       - Sections: {len(data['sections'])}")
        print(f"       - Lecturers: {len(data['lecturers'])}")
        print(f"       - Rooms: {len(data['rooms'])}")
        
        # Build domain
        print("\n[2] Building domain...")
        start_domain = time.time()
        domain = build_domain(data)
        domain_time = time.time() - start_domain
        print(f"    ✅ Domain built in {domain_time:.2f}s")
        
        # Create constraints
        print("\n[3] Creating constraints...")
        constraints = make_constraints(data["sections"], data["lecturers"])
        print(f"    ✅ Created {len(constraints)} constraints")
        
        # Solve
        print("\n[4] Solving with CSP...")
        start_solve = time.time()
        csp = CSP(
            variables=data["sections"],
            domains=domain,
            constraints=constraints,
            lecturers=data["lecturers"],
            preferences={}
        )
        solution = csp.solve()
        solve_time = time.time() - start_solve
        
        if solution is not None:
            unscheduled = sum(1 for v in solution.values() if v is None)
            print(f"    ✅ Solved in {solve_time:.2f}s")
            print(f"       - Scheduled: {len(solution) - unscheduled}/{len(solution)}")
            if unscheduled > 0:
                print(f"       - Unscheduled: {unscheduled}")
        else:
            print(f"    ⚠️  Failed to find solution (solve time: {solve_time:.2f}s)")
        
        # Summary
        print("\n" + "="*70)
        print("PERFORMANCE SUMMARY")
        print("="*70)
        print(f"Load Time:    {load_time:.3f}s")
        print(f"Domain Time:  {domain_time:.3f}s")
        print(f"Solve Time:   {solve_time:.3f}s")
        print(f"Total Time:   {(load_time + domain_time + solve_time):.3f}s")
        print(f"Sections/sec: {len(data['sections'])/solve_time:.1f}")
        print("\n✅ Performance test complete!")
        print("="*70 + "\n")
        
    except Exception as e:
        print(f"\n❌ ERROR: {e}")
        import traceback
        traceback.print_exc()

if __name__ == "__main__":
    main()
