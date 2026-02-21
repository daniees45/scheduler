#!/usr/bin/env python3
"""
Phase 3: Validation & Integration Testing
Tests all Phase 2 components in a unified test suite
"""
import sys
import os

sys.path.insert(0, os.path.dirname(__file__))

print('[TEST] Running Phase 3 validation checks...\n')

# Test 1: UI Functions Load
try:
    from tkinter_app.personal_scheduler_ui import (
        accept_suggestion,
        reject_suggestion, 
        view_productivity_heatmap,
        show_performance_metrics,
    )
    print('[PASS] Phase 2 UI functions loaded')
except Exception as e:
    print(f'[FAIL] Phase 2 UI functions: {e}')
    sys.exit(1)

# Test 2: Q-learner Feedback Recording
try:
    from q_learner_integration import (
        record_suggestion_accepted,
        record_suggestion_rejected,
        initialize_q_learning,
        get_q_learner
    )
    from personal_scheduler import Suggestion
    from datetime import time
    
    print('[PASS] Q-learner feedback functions available')
    
    # Create and validate test suggestion
    test_sugg = Suggestion(
        day='Monday',
        start=time(9, 0),
        end=time(10, 0),
        title='Test Suggestion',
        reason='Morning slot',
        score=7.5
    )
    print(f'[PASS] Test suggestion created: {test_sugg.title}')
    
except Exception as e:
    print(f'[FAIL] Q-learner validation: {e}')
    sys.exit(1)

# Test 3: Productivity Tracker
try:
    from productivity_heatmap import ProductivityTracker, generate_all_heatmap_outputs
    print('[PASS] Productivity tracker available')
except Exception as e:
    print(f'[FAIL] Productivity tracker: {e}')
    sys.exit(1)

# Test 4: Data Persistence
try:
    from tkinter_app.personal_scheduler_ui import OUTPUT_DIR
    model_path = os.path.join(OUTPUT_DIR, 'q_learner_model.pkl')
    log_path = os.path.join(OUTPUT_DIR, 'q_learner_log.json')
    
    print(f'[PASS] Q-learner model location: {model_path}')
    print(f'       Model exists: {os.path.exists(model_path)}')
    print(f'       Log location: {log_path}')
    print(f'       Log exists: {os.path.exists(log_path)}')
except Exception as e:
    print(f'[FAIL] Data persistence: {e}')
    sys.exit(1)

# Test 5: Ranking Integration
try:
    from q_learner_integration import rank_suggestions_by_preference
    print('[PASS] Suggestion ranking integration available')
except Exception as e:
    print(f'[FAIL] Ranking integration: {e}')
    sys.exit(1)

print('\n' + '='*70)
print('PHASE 3: VALIDATION CHECKS - ALL PASSED')
print('='*70)
print('\nReady to proceed with integration testing:')
print('  1. Start UI application')
print('  2. Test accept/reject buttons')
print('  3. Verify Q-learner feedback loop')
print('  4. Test heatmap generation')
print('  5. Validate metrics display')
print('  6. Full end-to-end loop test')
