#!/usr/bin/env python3
"""
Test that pre-trained models are automatically loaded
"""

import sys
from timetable_engine.ai_unified_scheduler import AIUnifiedScheduler

print("\nTesting Pre-Trained Model Auto-Loading...\n")
print("="*70)

# Minimal test data
courses = [
    {"code": "TEST101", "title": "Test Course", "level": 100, "semester": 1, "credits": 3, "enrollment": 30, "lecturer": "Dr. Test"}
]

lecturers = ["Dr. Test"]
rooms = [{"name": "Test Room", "capacity": 40, "type": "Lecture"}]
time_slots = ["07:00 AM - 09:30 AM"]
days = ["Monday"]

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

print("\nGenerating schedule (should auto-load pre-trained model)...")
schedule, quality, metadata = scheduler.schedule_with_nn()

print("\n" + "="*70)
print("RESULTS")
print("="*70)
print(f"Schedule generated: {len(schedule)} assignments")
print(f"Quality score: {quality:.3f}")
print(f"Method: {metadata.get('method', 'Unknown')}")

# Check if pre-trained model was used
training_stats = metadata.get('training_stats', {})
if training_stats.get('status') == 'pre-trained':
    print(f"\n✓ SUCCESS: Pre-trained model auto-loaded!")
    print(f"   Model: {training_stats.get('model_name', 'Unknown')}")
    print(f"   Path: {training_stats.get('model_path', 'Unknown')}")
else:
    print(f"\n⚠ WARNING: Model was not pre-trained")
    print(f"   Training stats: {training_stats}")

print("="*70)
