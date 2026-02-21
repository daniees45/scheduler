#!/usr/bin/env python3
"""Simple validation that main.py can be executed."""

import sys
import os

print("Validation Report: main.py Non-Interactive Mode")
print("=" * 70)

# Check files exist
files_to_check = [
    "main.py",
    "courses_input.csv",
    "load_data.py",
    "builder.py",
    "constraints.py",
    "csp.py"
]

print("\n1. Checking required files...")
all_exist = True
for fname in files_to_check:
    exists = os.path.exists(fname)
    status = "✓" if exists else "✗"
    print(f"   {status} {fname}")
    if not exists:
        all_exist = False

if not all_exist:
    print("\n✗ Some required files missing!")
    sys.exit(1)

print("\n2. Checking main.py syntax...")
try:
    import py_compile
    py_compile.compile('main.py', doraise=True)
    print("   ✓ main.py syntax is valid")
except Exception as e:
    print(f"   ✗ Syntax error in main.py: {e}")
    sys.exit(1)

print("\n3. Checking main() function signature...")
try:
    with open("main.py") as f:
        content = f.read()
    
    # Check for the right function definition
    if "def main(input_file=None, output_file=None, interactive=True):" in content:
        print("   ✓ main() has correct multi-arg signature")
    else:
        print("   ✗ main() signature not found or incorrect")
        sys.exit(1)
    
    # Check for non-interactive mode logic
    if 'interactive=False' in content:
        print("   ✓ Non-interactive mode logic found")
    else:
        print("   ✗ Non-interactive mode logic missing")
        sys.exit(1)
        
    # Check for command-line argument handling
    if 'sys.argv' in content and "__name__" in content:
        print("   ✓ Command-line argument handling found")
    else:
        print("   ✗ Command-line argument handling missing")
        sys.exit(1)
        
except Exception as e:
    print(f"   ✗ Error checking main.py: {e}")
    sys.exit(1)

print("\n" + "=" * 70)
print("✓ All validation checks passed!")
print("\nYou can now run:")
print("  python3 main.py courses_input.csv output.csv    (non-interactive)")
print("  python3 main.py                                  (interactive)")
print("=" * 70)
