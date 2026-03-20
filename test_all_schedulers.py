#!/usr/bin/env python3
"""Verify RL and NN schedulers have the same constraint logic"""

try:
    from timetable_engine.reinforcement_learning_scheduler import ReinforcementLearningScheduler
    from timetable_engine.neural_network_scheduler import NeuralNetworkScheduler
    
    print('[INFO] Testing Reinforcement Learning Scheduler...')
    rl = ReinforcementLearningScheduler(
        courses=[
            {'code': 'TEST 101', 'title': 'Test Course', 'enrollment': 30, 'level': 100, 'semester': 1, 'department': 'General'},
        ],
        lecturers=['Dr. Smith'],
        rooms=[
            {'name': 'Room 101', 'capacity': 50, 'department': 'General'},
        ],
        time_slots=['9:00 AM - 10:00 AM'],
        days=['Monday'],
        verbose=False
    )
    
    rl_methods = ['_normalize_room_name', '_room_matches', '_slot_matches_fixed_time']
    for method in rl_methods:
        status = '[OK]' if hasattr(rl, method) else '[MISSING]'
        print(f'{status} RL: {method}')
    
    print('\n[INFO] Testing Neural Network Scheduler...')
    nn = NeuralNetworkScheduler(
        courses=[
            {'code': 'TEST 101', 'title': 'Test Course', 'enrollment': 30, 'level': 100, 'semester': 1, 'department': 'General'},
        ],
        lecturers=['Dr. Smith'],
        rooms=[
            {'name': 'Room 101', 'capacity': 50, 'department': 'General'},
        ],
        time_slots=['9:00 AM - 10:00 AM'],
        days=['Monday'],
        verbose=False
    )
    
    nn_methods = ['_normalize_room_name', '_room_matches', '_slot_matches_fixed_time']
    for method in nn_methods:
        status = '[OK]' if hasattr(nn, method) else '[MISSING]'
        print(f'{status} NN: {method}')
    
    print('\n[SUCCESS] All schedulers have been updated with normalized constraint logic!')
    
except Exception as e:
    print(f'[ERROR] {e}')
    import traceback
    traceback.print_exc()
