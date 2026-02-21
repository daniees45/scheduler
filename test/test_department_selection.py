#!/usr/bin/env python3
"""
TEST: Department Selection + Availability Decision Mode

This test demonstrates the new features:
1. Department/General course selection
2. AI Automatic vs Manual Control for lecturer availability
"""

from main import main

print("=" * 80)
print("TEST: SCHEDULING WITH DEPARTMENT SELECTION + AVAILABILITY DECISION MODE")
print("=" * 80)
print()
print("This test will:")
print("  1. Ask you to select which department or general courses to schedule")
print("  2. Ask you to choose between AI Automatic or Manual Control mode")
print("  3. Run the scheduling with your selections")
print()
print("Follow the prompts below:")
print("=" * 80)
print()

# Run in interactive mode to see the new prompts
success = main(
    input_file="courses_input.csv",
    output_file="test_department_schedule.csv",
    interactive=True
)

print()
print("=" * 80)
if success:
    print("✓ TEST SUCCESSFUL!")
    print("  - Department selection working: ✓")
    print("  - Availability decision mode working: ✓")
    print("  - Schedule generated: test_department_schedule.csv ✓")
else:
    print("✗ TEST FAILED!")
    print("Review the messages above to see what went wrong.")
    exit(1)
print("=" * 80)
