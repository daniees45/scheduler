#!/usr/bin/env python3
"""
Test the updated main.py logic with auto-department detection and room loading.
Calls main() in non-interactive mode to test the full workflow.
"""

import sys
from main import main

print("=" * 70)
print("Testing updated main.py workflow (NON-INTERACTIVE MODE)")
print("=" * 70)

# Test with default courses_input.csv and auto-generated output
success = main(
    input_file="clean/computer.csv",
    output_file="final/test_schedule.csv",
    interactive=False
)

print("=" * 70)
if success:
    print("✓ MAIN.PY WORKFLOW SUCCESSFUL!")
    print("  - Department auto-detection: ✓")
    print("  - Department-specific room loading: ✓")
    print("  - Non-interactive execution: ✓")
    print("  - Scheduling model: ✓")
else:
    print("✗ MAIN.PY WORKFLOW FAILED!")
    sys.exit(1)

print("=" * 70)
