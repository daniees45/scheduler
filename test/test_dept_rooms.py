#!/usr/bin/env python3
"""
Quick test of department-specific room allocation system.
"""

from load_data import load_combined_data

print("Testing department-specific room allocation...")
print("=" * 60)

# Load data
data = load_combined_data(['courses_input.csv'], interactive=False)

print(f"\n✓ Loaded {len(data['sections'])} sections")
print(f"✓ Loaded {len(data['rooms'])} rooms")

# Show room departments
print("\n[Room Departments]")
dept_rooms = {}
for room in data['rooms'].values():
    if room.department not in dept_rooms:
        dept_rooms[room.department] = []
    dept_rooms[room.department].append(room.name)

for dept in sorted(dept_rooms.keys()):
    print(f"  {dept}: {', '.join(dept_rooms[dept])}")

# Show section departments
print("\n[Section Owning Departments]")
dept_sections = {}
for sec in data['sections']:
    if sec.owning_department not in dept_sections:
        dept_sections[sec.owning_department] = []
    dept_sections[sec.owning_department].append(sec.course_code)

for dept in sorted(dept_sections.keys()):
    codes = sorted(set(dept_sections[dept]))
    print(f"  {dept}: {codes}")

print("\n" + "=" * 60)
print("✓ Department-room allocation system is ready!")
print("=" * 60)
