#!/usr/bin/env python3
"""
Test the interactive availability expansion feature.
Tests that the CSP solver can prompt for availability expansion during scheduling.
"""

import sys
import os
from main import main

print("=" * 70)
print("TEST: Interactive Availability Expansion During Scheduling")
print("=" * 70)

# Test with interactive mode enabled
print("\nRunning scheduler in INTERACTIVE mode...")
print("If a course cannot be scheduled due to insufficient lecturer availability,")
print("you will be prompted to expand the lecturer's available days.\n")

success = main(
    input_file="courses_input.csv",
    output_file="test_interactive_schedule.csv",
    interactive=True  # Enable interactive prompts
)

print("=" * 70)
if success:
    print("✓ INTERACTIVE SCHEDULING SUCCESSFUL!")
    print("  - Real-time availability expansion: ✓")
    print("  - Schedule generated: test_interactive_schedule.csv")
else:
    print("✗ INTERACTIVE SCHEDULING DID NOT COMPLETE")
    print("  This could be due to:")
    print("  1. User chose not to expand availability when prompted")
    print("  2. Lecturer availability still insufficient after expansion")
    print("  3. Other constraint conflicts (rooms, cohorts, etc.)")
    sys.exit(1)

print("=" * 70)
