#!/usr/bin/env python3
"""
Test that NN gracefully handles incompatible model sizes
"""

import sys
from timetable_engine.ai_unified_scheduler import AIUnifiedScheduler

print("\nTesting NN Model Compatibility Handling...\n")
print("="*70)

# Create problem with MORE courses than the trained model (37 courses vs 15 in model)
courses = [
    {"code": f"TEST{i:03d}", "title": f"Test Course {i}", "level": 100 + (i//10)*100, 
     "semester": 1, "credits": 3, "enrollment": 30, "lecturer": f"Dr. Test{i%5}"}
    for i in range(37)
]

lecturers = [f"Dr. Test{i}" for i in range(5)]
rooms = [{"name": f"Room {i}", "capacity": 40, "type": "Lecture"} for i in range(10)]
time_slots = ["07:00 AM - 09:30 AM", "10:00 AM - 12:30 PM", "02:00 PM - 04:30 PM"]
days = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday"]

print(f"Problem size: {len(courses)} courses, {len(lecturers)} lecturers, {len(rooms)} rooms")
print(f"Trained model: 15 courses, 14 lecturers, 6 rooms (incompatible!)\n")

# Initialize scheduler
print("Initializing scheduler with NN enabled...")
scheduler = AIUnifiedScheduler(
    data_path=".",
    courses=courses,
    lecturers=lecturers,
    rooms=rooms,
    time_slots=time_slots,
    days=days,
    enable_nn=True,
    verbose=True
)

print("\nGenerating schedule with incompatible model...")
print("Should fall back to heuristics gracefully...\n")
print("="*70)

try:
    schedule, quality, metadata = scheduler.schedule_with_nn()
    
    print("\n" + "="*70)
    print("RESULTS")
    print("="*70)
    print(f"✓ Schedule generated: {len(schedule)} assignments")
    print(f"✓ Quality score: {quality:.3f}")
    print(f"✓ Method: {metadata.get('method', 'Unknown')}")
    print(f"✓ No crashes - graceful fallback working!")
    print("="*70)
    
except Exception as e:
    print("\n" + "="*70)
    print("❌ FAILED WITH ERROR")
    print("="*70)
    print(f"Error: {e}")
    import traceback
    traceback.print_exc()
    sys.exit(1)
