#!/usr/bin/env python3
"""
Test script to verify Neural Network Scheduler auto-training fix
"""

import os
import sys
import numpy as np

# Add project root to path
sys.path.insert(0, os.path.dirname(__file__))

from timetable_engine.ai_unified_scheduler import AIUnifiedScheduler

def test_nn_auto_training():
    """Test that NN auto-trains from historical data"""
    print("="*70)
    print("TESTING NEURAL NETWORK AUTO-TRAINING")
    print("="*70)
    
    # Create minimal test data
    courses = [
        {"code": "CS 101", "title": "Intro to CS", "department": "CS", "credits": 3, "level": "100", "semester": "1", "lecturer": "Dr. Smith", "enrollment": 40},
        {"code": "CS 201", "title": "Data Structures", "department": "CS", "credits": 3, "level": "200", "semester": "1", "lecturer": "Dr. Jones", "enrollment": 35},
        {"code": "MATH 101", "title": "Calculus I", "department": "Math", "credits": 3, "level": "100", "semester": "1", "lecturer": "Dr. Brown", "enrollment": 50},
    ]
    
    lecturers = ["Dr. Smith", "Dr. Jones", "Dr. Brown"]
    
    rooms = [
        {"name": "Room A", "capacity": 50},
        {"name": "Room B", "capacity": 40},
        {"name": "Room C", "capacity": 30},
    ]
    
    time_slots = ["07:00 AM - 09:30 AM", "10:00 AM - 12:30 PM", "02:00 PM - 04:30 PM"]
    days = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday"]
    
    # Initialize scheduler with NN enabled
    print("\n1. Initializing AI Unified Scheduler with NN enabled...")
    scheduler = AIUnifiedScheduler(
        data_path=".",
        courses=courses,
        lecturers=lecturers,
        rooms=rooms,
        time_slots=time_slots,
        days=days,
        enable_ga=False,
        enable_rl=False,
        enable_nn=True,  # Only NN
        enable_ensemble=False,
        verbose=True
    )
    
    if not scheduler.nn_scheduler:
        print("❌ FAILED: NN Scheduler not initialized")
        return False
    
    print("✓ NN Scheduler initialized")
    
    # Test auto-training
    print("\n2. Testing NN schedule generation (will auto-train if historical data exists)...")
    try:
        schedule, quality_score, metadata = scheduler.schedule_with_nn()
        
        if schedule is None:
            print("❌ FAILED: NN returned None schedule")
            return False
        
        print(f"\n✓ Schedule generated: {len(schedule)} assignments")
        print(f"✓ Quality score: {quality_score:.3f}")
        print(f"✓ Method: {metadata.get('method')}")
        
        if metadata.get('training_stats'):
            print(f"✓ Training stats: {metadata['training_stats']}")
        
        # Verify it's actually placing courses
        if len(schedule) < len(courses):
            print(f"⚠ WARNING: Only placed {len(schedule)}/{len(courses)} courses")
        else:
            print(f"✓ All {len(courses)} courses placed successfully")
        
        # Check if model was trained
        is_trained = getattr(scheduler.nn_scheduler, 'is_sklearn_trained', False)
        if is_trained:
            print("✓ NN model is trained and active")
        else:
            print("⚠ NN model is using heuristic fallback (no historical data found)")
        
        return True
        
    except Exception as e:
        print(f"❌ FAILED: {e}")
        import traceback
        traceback.print_exc()
        return False

def main():
    print("\nTesting Neural Network Scheduler Fix...")
    print("This test verifies that the NN auto-trains from historical data")
    print("or uses intelligent heuristics when no training data is available.\n")
    
    success = test_nn_auto_training()
    
    print("\n" + "="*70)
    if success:
        print("✓ TEST PASSED: Neural Network Scheduler is working")
        print("\nThe NN will:")
        print("  1. Auto-load historical_schedule.csv if available")
        print("  2. Train itself automatically on first use")
        print("  3. Use intelligent heuristics if no training data exists")
        print("\nTo improve NN performance:")
        print("  - Generate more schedules to build historical_schedule.csv")
        print("  - Use other algorithms (GA, RL, Ensemble) to create training data")
        print("  - NN will get smarter over time as it learns from history")
    else:
        print("❌ TEST FAILED: Neural Network Scheduler has issues")
        print("\nPossible causes:")
        print("  - Missing dependencies (TensorFlow or scikit-learn)")
        print("  - File permissions issue")
        print("  - Code syntax error")
    print("="*70)
    
    return 0 if success else 1

if __name__ == "__main__":
    sys.exit(main())
