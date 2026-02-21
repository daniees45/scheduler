#!/usr/bin/env python3
"""
SIMPLIFIED BENCHMARKING SUITE
Purpose: Validate CSP solver performance with real VVU data
Measures: Solve time, conflicts, and scalability metrics
Output: Results to console and CSV
"""

import time
import json
import csv
import os
import sys
import glob
import pandas as pd
from datetime import datetime
from typing import Dict, List, Any, Tuple

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from load_data import load_combined_data
from builder import build_domain
from constraints import make_constraints
from csp import CSP

# ============================================================================
# CONFIGURATION
# ============================================================================

OUTPUT_DIR = "benchmark_results"
ROOMS_FILE = "rooms.csv"

# ============================================================================
# CSV SELECTION
# ============================================================================

def select_input_csv():
    """
    Let user enter CSV file path or select from available files
    
    Returns:
        str: CSV file path
    """
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
    
    csv_files = sorted([f for f in glob.glob("*.csv") if os.path.isfile(f)])
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
        available = csv_files[:10]
    
    if not available:
        print("\n❌ No CSV files found")
        sys.exit(1)
    
    for i, filename in enumerate(available, 1):
        size_kb = os.path.getsize(filename) / 1024
        print(f"{i}. {filename:<35} ({size_kb:>6.1f} KB)")
    
    print("-" * 70)
    
    while True:
        try:
            choice = input("\nSelect by number: ").strip()
            idx = int(choice) - 1
            if 0 <= idx < len(available):
                selected = available[idx]
                print(f"✅ Selected: {selected}")
                return selected
            else:
                print(f"❌ Invalid choice. Enter 1-{len(available)}")
        except ValueError:
            print("❌ Please enter a valid number")

# ============================================================================
# BENCHMARK FUNCTION
# ============================================================================

def benchmark_csp_solver(data_dict: Dict[str, Any]) -> Dict[str, Any]:
    """
    Benchmark CSP solver on actual data
    
    Returns:
        Results dict with timing and metrics
    """
    sections = data_dict["sections"]
    num_sections = len(sections)
    
    print(f"\n  Solving {num_sections} sections...", end="", flush=True)
    start_time = time.time()
    
    try:
        # Build domain
        domain = build_domain(data_dict)
        
        # Create constraints
        constraints = make_constraints(data_dict["sections"], data_dict["lecturers"])
        
        # Create CSP
        csp = CSP(
            variables=sections,
            domains=domain,
            constraints=constraints,
            lecturers=data_dict["lecturers"],
            preferences={}
        )
        
        # Solve
        solution = csp.solve()
        solve_time = time.time() - start_time
        
        if solution is not None:
            # Count unscheduled sections
            num_conflicts = sum(1 for v in solution.values() if v is None)
            success = True
        else:
            num_conflicts = num_sections
            success = False
        
        result = {
            "num_sections": num_sections,
            "solve_time": round(solve_time, 4),
            "success": success,
            "num_conflicts": num_conflicts,
            "solution_quality": 1.0 if success else 0.0,
            "sections_per_second": round(num_sections / max(0.001, solve_time), 1),
            "timestamp": datetime.now().isoformat()
        }
        
        status = "✅ SUCCESS" if success else f"⚠️  {num_conflicts} unscheduled"
        print(f" {status} ({solve_time:.3f}s)")
        
        return result
        
    except Exception as e:
        print(f" ❌ ERROR: {str(e)[:50]}")
        return {
            "num_sections": num_sections,
            "solve_time": 0,
            "success": False,
            "error": str(e)[:100],
            "timestamp": datetime.now().isoformat()
        }

# ============================================================================
# MAIN
# ============================================================================

def main():
    print("\n" + "="*70)
    print("VVU SCHEDULER - SIMPLIFIED BENCHMARK")
    print("="*70)
    print(f"Started: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    
    # Get user's CSV selection
    input_csv = select_input_csv()
    
    # Verify rooms file exists
    if not os.path.exists(ROOMS_FILE):
        print(f"\n❌ ERROR: {ROOMS_FILE} not found")
        sys.exit(1)
    
    # Create output directory
    os.makedirs(OUTPUT_DIR, exist_ok=True)
    
    results = []
    
    # Test: Load selected CSV
    print(f"\n--- Loading {input_csv} ---")
    
    try:
        data = load_combined_data([input_csv], rooms_csv_path=ROOMS_FILE, interactive=False)
        result = benchmark_csp_solver(data)
        results.append((os.path.basename(input_csv), result))
    except Exception as e:
        print(f"❌ Failed to load {input_csv}: {e}")
        sys.exit(1)
    
    # ========================================================================
    # SAVE RESULTS
    # ========================================================================
    
    print("\n" + "="*70)
    print("RESULTS SUMMARY")
    print("="*70)
    
    if results:
        # Print table
        print(f"\n{'Test Name':<25} {'Sections':<12} {'Time (s)':<12} {'Status':<15}")
        print("-" * 70)
        
        for test_name, result in results:
            sections = result.get("num_sections", 0)
            time_sec = result.get("solve_time", 0)
            success = "✅ SUCCESS" if result.get("success", False) else "❌ FAILED"
            print(f"{test_name:<25} {sections:<12} {time_sec:<12.4f} {success:<15}")
        
        # Save to JSON
        json_path = os.path.join(OUTPUT_DIR, "benchmark_results.json")
        with open(json_path, "w") as f:
            json.dump(
                {
                    "timestamp": datetime.now().isoformat(),
                    "results": [{"name": name, "data": result} for name, result in results]
                },
                f,
                indent=2
            )
        print(f"\n✅ Saved JSON results to: {json_path}")
        
        # Save to CSV
        csv_path = os.path.join(OUTPUT_DIR, "benchmark_results.csv")
        with open(csv_path, "w", newline="") as f:
            if results:
                writer = csv.DictWriter(f, fieldnames=["test_name", "num_sections", "solve_time", "success", "num_conflicts"])
                writer.writeheader()
                for test_name, result in results:
                    writer.writerow({
                        "test_name": test_name,
                        "num_sections": result.get("num_sections", 0),
                        "solve_time": result.get("solve_time", 0),
                        "success": result.get("success", False),
                        "num_conflicts": result.get("num_conflicts", 0)
                    })
        print(f"✅ Saved CSV results to: {csv_path}")
        
        print("\n" + "="*70)
        print("PERFORMANCE METRICS")
        print("="*70)
        
        total_sections = sum(r.get("num_sections", 0) for _, r in results)
        total_time = sum(r.get("solve_time", 0) for _, r in results)
        successful = sum(1 for _, r in results if r.get("success", False))
        
        print(f"Total Sections Tested: {total_sections}")
        print(f"Total Solve Time: {total_time:.4f}s")
        print(f"Successful Solves: {successful}/{len(results)}")
        print(f"Average Time per Section: {(total_time/max(1,total_sections)*1000):.2f}ms")
        print(f"\n✅ Benchmarking Complete!")
    else:
        print("❌ No results to report - check that CSV files exist")
    
    print("\n" + "="*70 + "\n")

if __name__ == "__main__":
    main()
