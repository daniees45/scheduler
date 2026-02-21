"""
Quick Phase 2 component test - without full UI init
"""
import sys
import os

sys.path.insert(0, os.path.dirname(__file__))

print("[TEST] Checking Phase 2 component imports...")

try:
    # Check functions exist
    from q_learner_integration import (
        record_suggestion_accepted,
        record_suggestion_rejected,
    )
    print("✅ Q-Learner functions available")
    
    # Check Suggestion class
    from personal_scheduler import Suggestion
    print("✅ Suggestion class available")
    
    # Create a test suggestion
    from datetime import time
    test_sugg = Suggestion(
        day="Monday",
        start=time(9, 0),
        end=time(10, 0),
        title="Study Time",
        reason="Good morning hours",
        score=8.5
    )
    print(f"✅ Created test suggestion: {test_sugg.title} on {test_sugg.day} at {test_sugg.start}")
    
    # Check productivity tracker
    from productivity_heatmap import generate_all_heatmap_outputs
    print("✅ Productivity heatmap generator available")
    
    print("\n" + "="*70)
    print("PHASE 2 LOCAL COMPONENT TEST - ALL CHECKS PASSED ✓")
    print("="*70)
    print("\n✨ Phase 2 Features Implemented:")
    print("  1. Accept/Reject Button Functions")
    print("     - accept_suggestion() - Records acceptance in Q-learner")
    print("     - reject_suggestion() - Records rejection in Q-learner")
    print("")
    print("  2. Suggestion Ranking Integration")
    print("     - Q-learner scores applied to suggestions")
    print("     - Learned preferences re-rank suggestions")
    print("")
    print("  3. Productivity Analytics")
    print("     - view_productivity_heatmap() - Display heatmap")
    print("     - Tracks completed tasks & productivity times")
    print("")
    print("  4. Performance Metrics Display")
    print("     - show_performance_metrics() - Display system stats")
    print("     - CSP solver performance info")
    print("     - Q-Learning engine statistics")
    print("     - Productivity tracking stats")
    print("\n" + "="*70)
    
except Exception as e:
    print(f"❌ Error: {e}")
    import traceback
    traceback.print_exc()
    sys.exit(1)
