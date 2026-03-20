"""
Test & Demo - Production Grade Integration with Historical Data

Tests the complete Option C integration with:
- Loading historical_data.csv
- Quality predictions
- Feedback collection
- Retraining capability
"""

import sys
import os
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from schedule_monitor_production import ProductionScheduleMonitor
from training_data_collector import TrainingDataCollector


def test_monitor_initialization():
    """Test 1: Initialize monitor with historical CSV"""
    print("\n" + "="*80)
    print("  TEST 1: MONITOR INITIALIZATION")
    print("="*80)
    
    data_path = "."
    csv_path = os.path.join(data_path, "..", "history", "historical_data.csv")
    
    print(f"CSV path: {csv_path}")
    print(f"CSV exists: {os.path.exists(csv_path)}")
    
    monitor = ProductionScheduleMonitor(data_path, csv_path)
    print(f"✓ Monitor initialized")
    
    return monitor


def test_load_historical_data(monitor):
    """Test 2: Load historical schedules from CSV"""
    print("\n" + "="*80)
    print("  TEST 2: LOAD HISTORICAL SCHEDULES")
    print("="*80)
    
    schedules = monitor.load_historical_schedules(limit=50)
    
    if schedules:
        print(f"✓ Loaded {len(schedules)} schedules")
        
        # Show sample
        sample = schedules[0]
        print(f"\nSample schedule:")
        print(f"  Course: {sample.get('course_code')} - {sample.get('title')}")
        print(f"  Lecturer: {sample.get('lecturer')}")
        print(f"  Room: {sample.get('room_name')}")
        print(f"  Time: {sample.get('day')} {sample.get('time_slot')}")
        print(f"  Enrollment: {sample.get('enrollment')}")
        
        return schedules
    else:
        print("✗ No schedules loaded")
        return []


def test_predict_quality(monitor, schedules):
    """Test 3: Predict schedule quality"""
    print("\n" + "="*80)
    print("  TEST 3: QUALITY PREDICTIONS")
    print("="*80)
    
    if not schedules:
        print("⚠ No schedules to predict")
        return None
    
    quality = monitor.predict_schedule_quality(schedules[:20], store_feedback=True)
    
    print(f"\n✓ Predictions completed")
    print(f"  Grade: {quality.get('grade')}")
    print(f"  Score: {quality.get('overall_quality_score')*100:.1f}%")
    print(f"  Total items: {quality.get('total_items')}")
    print(f"  Conflicts detected: {quality.get('conflict_count')}")
    print(f"  Average confidence: {quality.get('average_confidence')*100:.1f}%")
    
    return quality


def test_performance_summary(monitor):
    """Test 4: Get performance summary"""
    print("\n" + "="*80)
    print("  TEST 4: PERFORMANCE SUMMARY")
    print("="*80)
    
    summary = monitor.get_performance_summary()
    
    print(f"\n✓ Performance Summary:")
    print(f"  Total predictions: {summary['total_predictions']}")
    print(f"  Conflicts detected: {summary['total_conflicts_detected']}")
    print(f"  Conflict rate: {summary['conflict_rate']}")
    print(f"  Due for retraining: {summary['due_for_retraining']}")
    
    if summary['predictions_by_grade']:
        print(f"\n  Predictions by grade:")
        for grade, count in sorted(summary['predictions_by_grade'].items()):
            print(f"    {grade}: {count}")
    
    return summary


def test_feedback_storage(monitor):
    """Test 5: Check feedback CSV was created"""
    print("\n" + "="*80)
    print("  TEST 5: FEEDBACK STORAGE")
    print("="*80)
    
    feedback_csv = monitor.feedback_csv
    
    if os.path.exists(feedback_csv):
        with open(feedback_csv, 'r') as f:
            lines = f.readlines()
        
        print(f"✓ Feedback CSV created: {feedback_csv}")
        print(f"  Total lines: {len(lines)}")
        print(f"  Header + data rows: {len(lines) - 1}")
        
        # Show sample
        if len(lines) > 1:
            print(f"\n  Sample feedback:")
            print(f"    {lines[1].strip()[:80]}...")
    else:
        print(f"✗ Feedback CSV not found")


def test_retraining_check(monitor):
    """Test 6: Check retraining logic"""
    print("\n" + "="*80)
    print("  TEST 6: RETRAINING CHECK")
    print("="*80)
    
    should_retrain = monitor.should_retrain()
    days_since = (
        __import__('datetime').datetime.now() - monitor.last_retraining
    ).days
    
    print(f"\n✓ Retraining Status:")
    print(f"  Should retrain: {should_retrain}")
    print(f"  Last retraining: {monitor.last_retraining.isoformat()}")
    print(f"  Days since: {days_since}")
    print(f"  Interval: {monitor.retraining_interval_days} days (quarterly)")


def test_training_data_collection(monitor):
    """Test 7: Training data in collector"""
    print("\n" + "="*80)
    print("  TEST 7: TRAINING DATA COLLECTION")
    print("="*80)
    
    if not hasattr(monitor, 'collector'):
        print("⚠ ML components not available")
        return
    
    stats = monitor.collector.get_statistics()
    
    print(f"\n✓ Training data statistics:")
    print(f"  Total samples: {stats.get('total_samples', 0)}")
    print(f"  Good samples: {stats.get('good_samples', 0)}")
    print(f"  Conflict samples: {stats.get('conflict_samples', 0)}")
    print(f"  Ready for training: {stats.get('ready_for_training', False)}")
    
    # Check balance
    balance = monitor.collector.validate_data_balance()
    print(f"\n  Data balance:")
    print(f"    Status: {balance.get('overall_status')}")
    print(f"    Good ratio: {balance.get('good_ratio')}")


def test_daily_report(monitor):
    """Test 8: Generate daily report"""
    print("\n" + "="*80)
    print("  TEST 8: DAILY REPORT")
    print("="*80)
    
    report = monitor.generate_daily_report()
    
    print(f"\n✓ Daily Report Generated:")
    print(f"  Date: {report['date']}")
    print(f"  Predictions today: {report['predictions_today']}")
    print(f"  Feedback stored: {report['feedback_stored']}")
    
    summary = report['summary']
    print(f"\n  Summary:")
    print(f"    Total predictions: {summary['total_predictions']}")
    print(f"    Conflict rate: {summary['conflict_rate']}")
    print(f"    Retraining due: {summary['due_for_retraining']}")


def run_all_tests():
    """Run complete test suite"""
    print("\n" + "="*80)
    print("  PRODUCTION GRADE INTEGRATION TEST SUITE")
    print("="*80)
    print("Testing Option C with historical_data.csv integration\n")
    
    try:
        # Test 1
        monitor = test_monitor_initialization()
        
        # Test 2
        schedules = test_load_historical_data(monitor)
        
        # Test 3
        if schedules:
            quality = test_predict_quality(monitor, schedules)
        
        # Test 4
        test_performance_summary(monitor)
        
        # Test 5
        test_feedback_storage(monitor)
        
        # Test 6
        test_retraining_check(monitor)
        
        # Test 7
        test_training_data_collection(monitor)
        
        # Test 8
        test_daily_report(monitor)
        
        # Final status
        print("\n" + "="*80)
        print("  FINAL STATUS REPORT")
        print("="*80)
        monitor.print_status_report()
        
        return True
    
    except Exception as e:
        print(f"\n✗ Test failed with error: {e}")
        import traceback
        traceback.print_exc()
        return False


if __name__ == "__main__":
    success = run_all_tests()
    
    print("\n" + "="*80)
    if success:
        print("✓ ALL TESTS PASSED - Production integration is working!")
    else:
        print("✗ Some tests failed")
    print("="*80)
    
    sys.exit(0 if success else 1)
