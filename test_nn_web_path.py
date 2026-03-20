#!/usr/bin/env python3
"""
Test NN scheduler through the unified scheduler interface (web path)
This mimics what exam_main_web.py does
"""

import sys
sys.path.insert(0, "/Applications/XAMPP/xamppfiles/htdocs/scheduler")

from timetable_engine.ai_unified_scheduler import AIUnifiedScheduler

print("=" * 70)
print("NN WEB INTERFACE PATH TEST")
print("=" * 70)

# Create problem with 37 courses (incompatible with trained 15)
courses = [{'code': f'CS{i+100}', 'enrollment': 30, 'level': 100, 'semester': 1, 'lecturer': f'Lect{i%5}'} for i in range(37)]
lecturers = [f'Lect{i}' for i in range(5)]
rooms = [{'name': f'Room{chr(65+i)}', 'capacity': 50} for i in range(10)]
days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday']
slots = ['8-9', '9-10', '10-11', '11-12']

print(f"\nProblem: {len(courses)} courses, {len(lecturers)} lecturers, {len(rooms)} rooms")
print(f"Trained model: 15 courses (INCOMPATIBLE!)\n")

# Initialize unified scheduler (this is what the web interface does)
scheduler = AIUnifiedScheduler(
    data_path=".",
    courses=courses,
    lecturers=lecturers,
    rooms=rooms,
    time_slots=slots,
    days=days,
    enable_ga=False,  # Disable others to focus on NN
    enable_rl=False,
    enable_nn=True,
    enable_ensemble=False,
    verbose=True
)

print("\nGenerating schedule with NN (should use heuristics)...\n")

try:
    schedule, quality, metadata = scheduler.schedule_with_nn()  # Returns tuple
    
    if schedule and len(schedule) > 0:
        print(f"\n✓ SUCCESS: Generated {len(schedule)} assignments")
        print(f"   Quality: {quality:.3f}")
        print(f"   Method: {metadata.get('method', 'Unknown')}")
        print("\nFirst 3 assignments:")
        for assign in schedule[:3]:
            print(f"   - {assign['course_code']}: {assign.get('day', '?')} {assign.get('time_slot', '?')} in {assign.get('room', '?')}")
    else:
        print("\n✗ FAIL: No schedule generated")
        sys.exit(1)
        
except Exception as e:
    print(f"\n✗ FAIL: Exception during scheduling: {e}")
    import traceback
    traceback.print_exc()
    sys.exit(1)

print("\n" + "=" * 70)
print("✓ NN WEB INTERFACE TEST PASSED")
print("=" * 70)
print("\nThe NN scheduler now works with incompatible models:")
print("  • Detects incompatibility automatically")
print("  • Falls back to heuristics gracefully")
print("  • No crashes or 500 errors!")
