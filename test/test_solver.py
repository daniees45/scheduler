#!/usr/bin/env python
import sys
from load_data import load_combined_data
from builder import build_domain
from constraints import make_constraints
from csp import CSP
from analyzer import train_model, load_trained_model
from export_data import export_solution
import os

print("Loading data...")
try:
    data = load_combined_data(["vvu_raw.csv"])
    print(f"✓ Loaded {len(data['sections'])} sections, {len(data['lecturers'])} lecturers, {len(data['rooms'])} rooms")
except Exception as e:
    print(f"✗ Error loading data: {e}")
    sys.exit(1)

print("Building domain...")
try:
    domain = build_domain(data)
    print(f"✓ Domain built with {sum(len(v) for v in domain.values())} total domain values")
except Exception as e:
    print(f"✗ Error building domain: {e}")
    sys.exit(1)

print("Building constraints...")
try:
    preference_model = {}  # Empty for now
    constraints = make_constraints(data["sections"], data["rooms"], preference_model, 
                                   lecturers=data.get("lecturers", {}), enable_flexibility=True)
    print(f"✓ Created {len(constraints)} constraints (including flexible availability)")
except Exception as e:
    print(f"✗ Error building constraints: {e}")
    sys.exit(1)

print("Initializing CSP solver (30s timeout)...")
try:
    csp = CSP(variables=data["sections"], 
              domains=domain,
              constraints=constraints,
              lecturers=data["lecturers"],
              preferences=preference_model,
              timeout_seconds=30)
except Exception as e:
    print(f"✗ Error initializing CSP: {e}")
    sys.exit(1)

print("Solving...")
try:
    solution = csp.solve()
    if solution is None:
        print("✗ No solution found")
        sys.exit(1)
    
    print(f"✓ Solution found! Scheduling {len(solution)} sections")
    
    print("Exporting...")
    export_solution(solution, data, "output_schedule.csv")
    print("✓ Schedule exported to output_schedule.csv")
    
except Exception as e:
    print(f"✗ Error solving: {e}")
    import traceback
    traceback.print_exc()
    sys.exit(1)
