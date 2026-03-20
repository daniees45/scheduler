"""
Option C: Production Grade Integration - Usage Examples & Integration Code

This module shows how to integrate ProductionScheduleMonitor into your existing
intelligent_interface.py with real-time feedback collection and historical data.
"""

# ============================================================================
# INTEGRATION METHOD 1: Direct Integration into IntelligentInterface
# ============================================================================

INTEGRATION_CODE = """
# In intelligent_interface.py, add these imports at the top:

from schedule_monitor_production import (
    ProductionScheduleMonitor,
    integrate_with_intelligent_interface
)

# In IntelligentInterface.__init__:
class IntelligentInterface:
    def __init__(self, data_path="."):
        # ... existing code ...
        
        # NEW: Initialize production monitor
        csv_path = os.path.join(data_path, "history", "historical_data.csv")
        self.monitor = ProductionScheduleMonitor(data_path, csv_path)
        print("✓ Production monitor initialized")
    
    # Add this method to existing class:
    def generate_with_quality_monitoring(self, dept_code=None):
        \"\"\"Generate schedule with quality monitoring and feedback collection\"\"\"
        
        # Generate schedule using existing method
        if dept_code:
            schedule = self._schedule_department(dept_code)
        else:
            schedule = self.generate()
        
        if not schedule:
            return None
        
        # NEW: Predict quality
        quality = self.monitor.predict_schedule_quality(schedule)
        
        # NEW: Check for retraining
        if self.monitor.should_retrain():
            print("\\n⚠ Quarterly retraining due!")
            metrics = self.monitor.retrain_model()
            self.monitor.print_status_report()
        
        return {
            "schedule": schedule,
            "quality": quality,
            "grade": quality.get("grade"),
            "score": quality.get("overall_quality_score")
        }
    
    # Add this method for daily monitoring:
    def print_daily_monitoring_report(self):
        \"\"\"Print daily monitoring report\"\"\"
        report = self.monitor.generate_daily_report()
        
        print(f"\\n{'='*70}")
        print(f"  DAILY MONITORING REPORT - {report['date']}")
        print(f"{'='*70}")
        
        summary = report['summary']
        print(f"Predictions today: {report['predictions_today']}")
        print(f"Conflicts detected: {summary['total_conflicts_detected']}")
        print(f"Conflict rate: {summary['conflict_rate']}")
        print(f"Retraining due: {'YES ⚠' if summary['due_for_retraining'] else 'No ✓'}")
"""

# ============================================================================
# INTEGRATION METHOD 2: Standalone Production Monitoring Script
# ============================================================================

STANDALONE_MONITOR_SCRIPT = """
#!/usr/bin/env python3
'''
Production Schedule Monitor - Standalone Script
Run this daily/weekly to generate monitoring reports
'''

import sys
import os
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from schedule_monitor_production import ProductionScheduleMonitor
from intelligent_interface import IntelligentInterface

def monitor_and_retrain(data_path="."):
    '''Daily monitoring and quarterly retraining cycle'''
    
    # Initialize components
    interface = IntelligentInterface(data_path)
    
    # Load and predict on recent schedules
    schedules = interface.monitor.load_historical_schedules(limit=100)
    
    if schedules:
        print(f"Predicting quality for {len(schedules)} historical schedules...\\n")
        quality = interface.monitor.predict_schedule_quality(schedules)
        
        # Print summary
        interface.monitor.print_status_report()
        
        # Check for quarterly retraining
        if interface.monitor.should_retrain():
            print("\\n⚠ Performing quarterly retraining...")
            metrics = interface.monitor.retrain_model()
            
            if metrics.get("status") == "trained":
                print("\\n✓ Retraining successful!")
                print(f"  New accuracy: {metrics['accuracy']*100:.1f}%")
    
    return interface.monitor

if __name__ == "__main__":
    import argparse
    
    parser = argparse.ArgumentParser(description="Production Schedule Monitor")
    parser.add_argument("--data-path", default=".", help="Path to data directory")
    parser.add_argument("--limit", type=int, default=100, 
                       help="Max schedules to process")
    
    args = parser.parse_args()
    
    monitor = monitor_and_retrain(args.data_path)
    monitor.print_status_report()
"""

# ============================================================================
# INTEGRATION METHOD 3: Real-time Feedback Collection Loop
# ============================================================================

FEEDBACK_LOOP_CODE = """
# In intelligent_interface.py or a separate monitoring loop:

def continuous_monitoring_loop(interface, interval_hours=24):
    '''
    Continuous monitoring with feedback collection
    Run this as a background process
    '''
    import time
    
    while True:
        try:
            # Generate and monitor schedule
            result = interface.generate_with_quality_monitoring()
            
            if result:
                grade = result['grade']
                score = result['score']
                
                # Log feedback
                print(f"✓ Schedule quality: {grade} ({score*100:.1f}%)")
                
                # Check retraining
                if interface.monitor.should_retrain():
                    print("Performing quarterly retraining...")
                    interface.monitor.retrain_model()
            
            # Wait before next iteration
            time.sleep(interval_hours * 3600)
        
        except Exception as e:
            print(f"Error in monitoring loop: {e}")
            time.sleep(60)  # Retry after 1 minute

# Usage:
# Start monitoring in background:
# import threading
# monitor_thread = threading.Thread(
#     target=continuous_monitoring_loop,
#     args=(interface, 24),  # Check every 24 hours
#     daemon=True
# )
# monitor_thread.start()
"""

# ============================================================================
# INTEGRATION METHOD 4: Batch Processing Historical Data
# ============================================================================

BATCH_PROCESSING_CODE = """
# Process all historical data and generate training samples:

def process_historical_and_train(interface, output_metrics=True):
    '''
    Load historical schedules, predict quality, and improve model
    Useful for initial setup
    '''
    
    print("Loading historical schedules...")
    schedules = interface.monitor.load_historical_schedules()
    
    if schedules:
        print(f"\\n✓ Loaded {len(schedules)} historical schedules")
        
        # Batch predict
        print("Predicting quality for all schedules...")
        quality = interface.monitor.predict_schedule_quality(schedules)
        
        # Train model
        print("\\nTraining model with collected feedback...")
        metrics = interface.monitor.retrain_model(min_new_samples=30)
        
        if output_metrics and metrics.get("status") == "trained":
            print("\\n" + "="*70)
            print("  TRAINING RESULTS")
            print("="*70)
            print(f"Accuracy:  {metrics['accuracy']*100:.1f}%")
            print(f"Precision: {metrics['precision']*100:.1f}%")
            print(f"Recall:    {metrics['recall']*100:.1f}%")
            print(f"F1-Score:  {metrics['f1']*100:.1f}%")
        
        # Status report
        interface.monitor.print_status_report()
    
    return interface.monitor

# Usage:
# result = process_historical_and_train(interface)
"""

# ============================================================================
# USAGE EXAMPLES
# ============================================================================

USAGE_EXAMPLES = """
OPTION C: PRODUCTION GRADE INTEGRATION USAGE EXAMPLES

Example 1: Basic Integration
=========================================

from intelligent_interface import IntelligentInterface

interface = IntelligentInterface()

# Generate schedule with monitoring
result = interface.generate_with_quality_monitoring()

print(f"Schedule grade: {result['grade']}")
print(f"Score: {result['score']*100:.1f}%")


Example 2: Monitor Specific Department
=========================================

result = interface.generate_with_quality_monitoring("COSC")

if result:
    quality = result['quality']
    print(f"COSC Schedule Quality: {quality['grade']}")
    print(f"Conflicts: {quality['conflict_count']}")


Example 3: Daily Report
=========================================

# Print monitoring status
interface.monitor.print_status_report()

# Or get report as dict
report = interface.monitor.generate_daily_report()


Example 4: Manual Retraining
=========================================

if interface.monitor.should_retrain():
    print("Retraining model...")
    metrics = interface.monitor.retrain_model()
    
    print(f"New accuracy: {metrics['accuracy']*100:.1f}%")


Example 5: Historical Data Processing
=========================================

# Load and analyze historical schedules
schedules = interface.monitor.load_historical_schedules(limit=200)

quality = interface.monitor.predict_schedule_quality(schedules)

print(f"Historical schedules analysis:")
print(f"  Total: {len(schedules)}")
print(f"  Average grade: {quality['grade']}")
print(f"  Conflict rate: {quality['conflict_count']}/{len(schedules)}")


Example 6: Get Performance Metrics
=========================================

summary = interface.monitor.get_performance_summary()

print(f"Total predictions: {summary['total_predictions']}")
print(f"Conflicts detected: {summary['total_conflicts_detected']}")
print(f"Conflict rate: {summary['conflict_rate']}")

# See predictions by grade
for grade, count in summary['predictions_by_grade'].items():
    print(f"  {grade}: {count} schedules")


Example 7: Load Custom Historical CSV
=========================================

# Use custom CSV path
monitor = ProductionScheduleMonitor(
    data_path=".",
    csv_path="/path/to/custom/historical_data.csv"
)

schedules = monitor.load_historical_schedules()
quality = monitor.predict_schedule_quality(schedules)


Example 8: Automated Weekly Report
=========================================

import schedule
import time

def weekly_report():
    interface = IntelligentInterface()
    
    # Print report
    interface.monitor.print_status_report()
    
    # Check retraining
    if interface.monitor.should_retrain():
        interface.monitor.retrain_model()

# Schedule weekly
schedule.every().week.do(weekly_report)

while True:
    schedule.run_pending()
    time.sleep(60)
"""

# ============================================================================
# COMPLETE INTEGRATION SNIPPET
# ============================================================================

COMPLETE_INTEGRATION = """
# File: intelligent_interface_production_version.py
# Copy this snippet into your intelligent_interface.py

import os
from schedule_monitor_production import ProductionScheduleMonitor

class IntelligentInterface:
    def __init__(self, data_path="."):
        # ... existing initialization ...
        
        # Initialize production monitor
        self.data_path = data_path
        csv_path = os.path.join(data_path, "history", "historical_data.csv")
        self.monitor = ProductionScheduleMonitor(data_path, csv_path)
    
    def _schedule_department(self, dept_code):
        '''Override existing _schedule_department with monitoring'''
        
        # Call original scheduling logic
        schedule = super()._schedule_department(dept_code)
        
        # NEW: Add quality monitoring
        if schedule:
            quality = self.monitor.predict_schedule_quality(schedule)
            
            # Check for retraining
            if self.monitor.should_retrain():
                print("⚠ Performing quarterly retraining...")
                self.monitor.retrain_model()
        
        return schedule
    
    def generate(self):
        '''Override existing generate with monitoring'''
        
        schedule = super().generate()
        
        if schedule:
            quality = self.monitor.predict_schedule_quality(schedule)
        
        return schedule
    
    def get_quality_report(self):
        '''Get current quality report'''
        return self.monitor.generate_daily_report()
    
    def print_monitor_status(self):
        '''Print monitoring status'''
        self.monitor.print_status_report()
"""

# ============================================================================
# MAIN HELP TEXT
# ============================================================================

HELP_TEXT = """
================================================================================
                    OPTION C: PRODUCTION GRADE INTEGRATION
================================================================================

QUICK START (5 minutes):
  1. Copy COMPLETE_INTEGRATION snippet into intelligent_interface.py
  2. Update imports: from schedule_monitor_production import ProductionScheduleMonitor
  3. Change method calls to use generate_with_quality_monitoring()
  4. Run test: python3 test_production_integration.py

FEATURES INCLUDED:
  ✓ Real-time quality predictions
  ✓ Historical data integration (from historical_data.csv)
  ✓ Feedback collection & storage
  ✓ Performance monitoring & tracking
  ✓ Automatic quarterly retraining
  ✓ Daily/weekly/monthly reporting
  ✓ Daily conflict rate tracking
  ✓ Model performance metrics

FILES CREATED:
  - schedule_monitor_production.py      (Main integration module)
  - option_c_integration_examples.py    (This file - examples & code)
  - test_production_integration.py      (Test script)

METHODS AVAILABLE:
  - monitor.predict_schedule_quality(schedules)
  - monitor.should_retrain()
  - monitor.retrain_model(min_new_samples=50)
  - monitor.load_historical_schedules(limit=None)
  - monitor.get_performance_summary()
  - monitor.generate_daily_report()
  - monitor.print_status_report()

CSV FILES GENERATED:
  - history/historical_data.csv         (Input - existing schedules)
  - history/feedback_log.csv            (Output - prediction feedback)
  - history/retraining_log.json         (Output - training history)

EXPECTED TIMELINE:
  Week 1:    Integrate & monitor
  Month 2:   Collect feedback data (500+ samples)
  Month 3:   Quarterly retraining (improve to 85%+)
  Month 4+:  Continuous improvement cycle
================================================================================
"""

if __name__ == "__main__":
    print(HELP_TEXT)
    print("\nAvailable code snippets:")
    print("  1. INTEGRATION_CODE - Add to intelligent_interface.py")
    print("  2. STANDALONE_MONITOR_SCRIPT - Run as standalone")
    print("  3. FEEDBACK_LOOP_CODE - Continuous monitoring")
    print("  4. BATCH_PROCESSING_CODE - Bulk historical processing")
    print("  5. USAGE_EXAMPLES - 8 practical examples")
    print("  6. COMPLETE_INTEGRATION - Full code snippet")
