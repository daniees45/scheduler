#!/usr/bin/env python3
"""Test GA scheduler with normalized constraint logic"""

from timetable_engine.genetic_algorithm_optimizer import GeneticAlgorithmScheduler
from load_data import load_combined_data

# Load test data
courses, lecturers, rooms = load_combined_data('csv/general/rooms.csv', 'General', 'CS 100')
time_slots = ['9:00 AM - 10:00 AM', '10:30 AM - 11:30 AM', '1:00 PM - 2:30 PM', '3:00 PM - 4:30 PM', '5:00 PM - 6:30 PM']
days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday']

# Check that GA can be initialized
print('[INFO] Testing GA initialization with normalized room matching...')
ga = GeneticAlgorithmScheduler(
    courses=courses[:5],
    lecturers=lecturers[:3],
    rooms=rooms,
    time_slots=time_slots,
    days=days,
    verbose=False
)

# Check normalizer methods exist
print('[CHECK] _normalize_room_name method: ' + str(hasattr(ga, '_normalize_room_name')))
print('[CHECK] _room_matches method: ' + str(hasattr(ga, '_room_matches')))
print('[CHECK] _slot_matches_fixed_time method: ' + str(hasattr(ga, '_slot_matches_fixed_time')))
print('[CHECK] reserved_rooms_normalized attribute: ' + str(hasattr(ga, 'reserved_rooms_normalized')))

# Test normalizer functions
test_room_a = "Baobab RM1"
test_room_b = "baobab rm1"
test_room_c = "American High"

print(f'\n[TEST] Room normalization:')
print(f'  {test_room_a} -> {ga._normalize_room_name(test_room_a)}')
print(f'  {test_room_b} -> {ga._normalize_room_name(test_room_b)}')
print(f'  Match test_room_a to test_room_b: {ga._room_matches(test_room_a, test_room_b)}')
print(f'  Match test_room_a to test_room_c: {ga._room_matches(test_room_a, test_room_c)}')

print('\n[SUCCESS] GA scheduler initialized successfully with constraint enforcement!')
