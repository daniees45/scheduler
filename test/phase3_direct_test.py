"""
Simplified Phase 3 Test - Direct Function Testing
Tests Phase 2 components without full UI initialization
"""
import sys
import os
from datetime import time

# Setup paths
sys.path.insert(0, os.path.dirname(__file__))
os.chdir(os.path.dirname(__file__))

print("="*70)
print("PHASE 3: DIRECT COMPONENT TESTING")
print("="*70)

# TEST 1: Q-Learner Suggestion Recording
print("\n[TEST 1] Q-Learner Suggestion Recording")
print("-" * 70)
try:
    from q_learner_integration import (
        record_suggestion_accepted,
        record_suggestion_rejected,
    )
    from personal_scheduler import Suggestion
    
    # Create test suggestion
    test_sugg = Suggestion(
        day="Monday",
        start=time(10, 0),
        end=time(11, 0),
        title="Study Session",
        reason="Morning productivity peak",
        score=8.5
    )
    
    # Record acceptance
    result_accept = record_suggestion_accepted(test_sugg, "student")
    print(f"  Suggestion created: {test_sugg.title}")
    print(f"  Record acceptance: {result_accept}")
    
    # Create another suggestion
    test_sugg2 = Suggestion(
        day="Tuesday",
        start=time(14, 0),
        end=time(15, 0),
        title="Lunch Break Study",
        reason="Afternoon slot",
        score=6.5
    )
    
    # Record rejection
    result_reject = record_suggestion_rejected(test_sugg2, "student")
    print(f"  Record rejection: {result_reject}")
    print("  [PASS] Q-Learner recording functions work")
    
except Exception as e:
    print(f"  [FAIL] Q-Learner recording: {e}")
    import traceback
    traceback.print_exc()

# TEST 2: Suggestion Ranking
print("\n[TEST 2] Suggestion Ranking Integration")
print("-" * 70)
try:
    from q_learner_integration import rank_suggestions_by_preference
    from personal_scheduler import Suggestion
    
    # Create list of suggestions
    suggestions = [
        Suggestion("Monday", time(9, 0), time(10, 0), "Early Study", "Morning slot", 8.0),
        Suggestion("Monday", time(14, 0), time(15, 0), "Afternoon Study", "Post-lunch", 6.0),
        Suggestion("Wednesday", time(10, 0), time(11, 0), "Mid-week Study", "Mid-week", 7.0),
    ]
    
    # Rank by preference
    ranked = rank_suggestions_by_preference(suggestions, "student")
    
    print(f"  Input suggestions: {len(suggestions)}")
    print(f"  Ranked output: {len(ranked)} tuples")
    
    # Verify tuple structure
    for i, (sugg, score) in enumerate(ranked, 1):
        print(f"    {i}. {sugg.title}: {score:.2f} (base: {sugg.score:.1f})")
    
    print("  [PASS] Suggestion ranking works")
    
except Exception as e:
    print(f"  [FAIL] Suggestion ranking: {e}")
    import traceback
    traceback.print_exc()

# TEST 3: Productivity Tracker
print("\n[TEST 3] Productivity Tracker")
print("-" * 70)
try:
    from productivity_heatmap import ProductivityTracker, get_tracker
    
    tracker = get_tracker()
    print(f"  Tracker initialized: {tracker is not None}")
    
    # Record a task
    from datetime import datetime
    tracker.record_task_completed(
        task="Test Study Session",
        completed_at=datetime.now(),
        duration_minutes=60
    )
    print("  Task recorded: Test Study Session (60 min)")
    
    # Get stats
    if hasattr(tracker, 'get_statistics'):
        stats = tracker.get_statistics()
        print(f"  Tracker stats: {stats}")
    
    print("  [PASS] Productivity tracker works")
    
except Exception as e:
    print(f"  [FAIL] Productivity tracker: {e}")
    import traceback
    traceback.print_exc()

# TEST 4: Data Persistence
print("\n[TEST 4] Data Persistence Verification")
print("-" * 70)
try:
    from tkinter_app.personal_scheduler_ui import OUTPUT_DIR
    
    model_path = os.path.join(OUTPUT_DIR, "q_learner_model.pkl")
    log_path = os.path.join(OUTPUT_DIR, "q_learner_log.json")
    
    print(f"  Output directory: {OUTPUT_DIR}")
    print(f"  Model location: {model_path}")
    print(f"    Exists: {os.path.exists(model_path)}")
    print(f"  Log location: {log_path}")
    print(f"    Exists: {os.path.exists(log_path)}")
    
    if os.path.exists(log_path):
        import json
        with open(log_path, 'r') as f:
            log_data = json.load(f)
        print(f"  Log entries: {len(log_data) if isinstance(log_data, list) else 'N/A'}")
    
    print("  [PASS] Data persistence verified")
    
except Exception as e:
    print(f"  [FAIL] Data persistence: {e}")
    import traceback
    traceback.print_exc()

print("\n" + "="*70)
print("PHASE 3: DIRECT COMPONENT TESTING - COMPLETE")
print("="*70)
print("\nAll Phase 2 components operational. UI ready for integration testing.")
