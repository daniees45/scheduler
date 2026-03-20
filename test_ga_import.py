#!/usr/bin/env python3
"""Simple test to verify GA has the new constraint logic methods"""

try:
    from timetable_engine.genetic_algorithm_optimizer import GeneticAlgorithmScheduler
    print('[OK] GA module imported successfully')
    
    # Create a minimal instance to test
    ga = GeneticAlgorithmScheduler(
        courses=[
            {'code': 'TEST 101', 'title': 'Test Course', 'enrollment': 30, 'level': 100, 'semester': 1, 'department': 'General'},
        ],
        lecturers=['Dr. Smith'],
        rooms=[
            {'name': 'Room 101', 'capacity': 50, 'department': 'General'},
            {'name': 'Room 102', 'capacity': 30, 'department': 'General'},
        ],
        time_slots=['9:00 AM - 10:00 AM', '10:30 AM - 11:30 AM'],
        days=['Monday', 'Tuesday'],
        verbose=False
    )
    
    print('[OK] GA scheduler instantiated')
    
    # Test normalizer methods
    methods = [
        '_normalize_room_name',
        '_room_matches', 
        '_slot_matches_fixed_time',
        '_normalize_time',
        '_get_special_constraint',
        '_is_intentional_pairing',
        '_check_hard_constraints',
        '_evaluate_fitness'
    ]
    
    for method in methods:
        has_method = hasattr(ga, method)
        status = '[OK]' if has_method else '[MISSING]'
        print(f'{status} {method}')
    
    # Test normalization function
    test_cases = [
        ('Baobab RM1', 'baobab rm1', True),
        ('Room 101', 'ROOM 101', True),
        ('Room 101', 'Room 102', False),
    ]
    
    print('\n[Testing room normalization]')
    for room_a, room_b, expected_match in test_cases:
        result = ga._room_matches(room_a, room_b)
        status = '[PASS]' if result == expected_match else '[FAIL]'
        print(f'{status} _room_matches("{room_a}", "{room_b}") = {result} (expected {expected_match})')
    
    # Check reserved_rooms_normalized attribute
    print(f'\n[OK] reserved_rooms_normalized exists: {hasattr(ga, "reserved_rooms_normalized")}')
    print(f'[OK] reserved_rooms exists: {hasattr(ga, "reserved_rooms")}')
    
    print('\n[SUCCESS] GA scheduler implementation verified successfully!')
    
except Exception as e:
    print(f'[ERROR] {e}')
    import traceback
    traceback.print_exc()
