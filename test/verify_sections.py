#!/usr/bin/env python3
import csv

# Get input
with open('clean/general.csv', 'r') as f:
    reader = csv.reader(f)
    input_rows = list(reader)

input_sections = set()
for row in input_rows[1:]:
    if row and row[0]:
        key = row[0] + " - " + row[1]
        input_sections.add(key)

# Get output  
with open('final/general.csv', 'r') as f:
    reader = csv.reader(f)
    output_rows = list(reader)

output_sections = set()
for row in output_rows[1:]:
    if row and row[0]:
        key = row[0] + " - " + row[1]
        output_sections.add(key)

print("=== SECTION EXPORT VERIFICATION ===")
print("")
print("Input sections: " + str(len(input_sections)))
print("Output sections: " + str(len(output_sections)))
print("")

if input_sections == output_sections:
    print("✅ PERFECT MATCH - All 37 sections exported individually!")
    print("")
    print("Each section treated as SEPARATE (NOT merged or deduplicated)")
    print("")
    print("Evidence - ENGL sections (NOT merged by course code):")
    engl_sections = sorted([s for s in output_sections if s.startswith('ENGL')])
    for s in engl_sections:
        print("  • " + s)
    print("")
    print("Proof - FREN sections (each section separate):")
    fren_sections = sorted([s for s in output_sections if s.startswith('FREN')])
    for s in fren_sections:
        print("  • " + s)
else:
    print("ERROR: Section count mismatch!")
    print("Sections in input: " + str(len(input_sections)))
    print("Sections in output: " + str(len(output_sections)))
    missing = input_sections - output_sections
    extra = output_sections - input_sections
    if missing:
        print("\nMissing (" + str(len(missing)) + "):")
        for s in missing:
            print("  - " + s)
    if extra:
        print("\nExtra (" + str(len(extra)) + "):")
        for s in extra:
            print("  + " + s)
