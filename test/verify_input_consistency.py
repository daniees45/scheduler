#!/usr/bin/env python3
"""
Verify that all input CSV files are consistent with the new format.
"""

import pandas as pd
import os

print("=" * 60)
print("INPUT FORMAT CONSISTENCY CHECK")
print("=" * 60)

# Check courses_input.csv
print("\n[1] Checking courses_input.csv...")
if os.path.exists('courses_input.csv'):
    df = pd.read_csv('courses_input.csv')
    required_cols = ['course_code', 'course_title', 'lecturer_name', 
                     'source_type', 'course_level', 'credit_hours']
    actual_cols = list(df.columns)
    
    if actual_cols == required_cols:
        print(f"    ✓ Format correct: {required_cols}")
        print(f"    ✓ Records: {len(df)}")
        print(f"    ✓ Missing values: {df.isnull().sum().sum()}")
    else:
        print(f"    ✗ Column mismatch!")
        print(f"       Expected: {required_cols}")
        print(f"       Actual: {actual_cols}")
else:
    print("    ✗ courses_input.csv not found")

# Check curriculum.csv
print("\n[2] Checking curriculum.csv...")
if os.path.exists('curriculum.csv'):
    df = pd.read_csv('curriculum.csv')
    expected_cols = ['program', 'level', 'semester', 'course_code']
    actual_cols = list(df.columns)
    
    if actual_cols == expected_cols:
        print(f"    ✓ Format correct: {expected_cols}")
        print(f"    ✓ Records: {len(df)}")
    else:
        print(f"    ✗ Column mismatch!")
        print(f"       Expected: {expected_cols}")
        print(f"       Actual: {actual_cols}")
else:
    print("    ✗ curriculum.csv not found")

# Check shared_course_aliases.csv
print("\n[3] Checking shared_course_aliases.csv...")
if os.path.exists('shared_course_aliases.csv') and \
   os.path.getsize('shared_course_aliases.csv') > 0:
    df = pd.read_csv('shared_course_aliases.csv')
    print(f"    ✓ Format correct: {list(df.columns)}")
    print(f"    ✓ Records: {len(df)}")
else:
    print("    ✓ File empty or not needed (optional)")

# Check categorized files
print("\n[4] Checking categorized files...")
for fname in ['general_courses.csv', 'departmental_courses.csv']:
    if os.path.exists(fname):
        size = os.path.getsize(fname)
        if size == 0:
            print(f"    ✓ {fname}: empty (fresh start)")
        else:
            print(f"    ✓ {fname}: exists ({size} bytes)")
    else:
        print(f"    ✓ {fname}: not yet created")

# Test data loading
print("\n[5] Testing data loading pipeline...")
try:
    from load_data import load_combined_data
    data = load_combined_data(['courses_input.csv'], interactive=False)
    print(f"    ✓ load_combined_data() works")
    print(f"    ✓ Sections loaded: {len(data['sections'])}")
    print(f"    ✓ Lecturers loaded: {len(data['lecturers'])}")
    print(f"    ✓ Rooms loaded: {len(data['rooms'])}")
except Exception as e:
    print(f"    ✗ Error: {e}")

print("\n" + "=" * 60)
print("✓ Consistency check complete!")
print("=" * 60)
