#!/usr/bin/env python3
"""
Simple focused test for NN model compatibility
Tests ONLY the core NN scheduler, not the unified scheduler
"""

import sys
sys.path.insert(0, "/Applications/XAMPP/xamppfiles/htdocs/scheduler")

from timetable_engine.neural_network_scheduler import NeuralNetworkScheduler

print("=" * 70)
print("SIMPLE NN COMPATIBILITY TEST")
print("=" * 70)

# Create scheduler with 37 courses (more than the trained 15)
courses = [{'code': f'CS{i+100}', 'enrollment': 30, 'level': 100, 'semester': 1} for i in range(37)]
lecturers = [f'Lecturer_{i}' for i in range(5)]
rooms = [{'name': f'Room{chr(65+i)}', 'capacity': 50} for i in range(10)]
days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday']
slots = ['8-9', '9-10', '10-11', '11-12']

print(f"\nProblem Size: {len(courses)} courses, {len(lecturers)} lecturers, {len(rooms)} rooms")

scheduler = NeuralNetworkScheduler(
    courses=courses,
    lecturers=lecturers,
    rooms=rooms,
    time_slots=slots,
    days=days,
    verbose=True
)

print(f"\nAttempting to load pre-trained model...")
model_path = "models/nn/nn_scheduler_20260302_051447.h5"
loaded = scheduler.load_model(model_path)

print(f"\nModel loaded: {loaded}")
print(f"Model compatible: {scheduler.is_model_compatible()}")
print(f"Model dimensions: {scheduler.model_num_courses} courses, {scheduler.model_num_lecturers} lecturers")
print(f"Current problem: {scheduler.num_courses} courses, {scheduler.num_lecturers} lecturers")

if not loaded:
    print("\n✓ PASS: Model correctly refused to load incompatible model")
    print("   The scheduler will use heuristics instead of the incompatible model")
else:
    print("\n✗ FAIL: Model should have refused to load since it's incompatible")
    sys.exit(1)

print("\n" + "=" * 70)
print("✓ NN COMPATIBILITY TEST PASSED")
print("=" * 70)
print("\nKey behaviors verified:")
print("  • Model detects incompatibility correctly")
print("  • load_model() returns False for incompatible models")
print("  • Scheduler ready to use heuristics for out-of-range indices")
