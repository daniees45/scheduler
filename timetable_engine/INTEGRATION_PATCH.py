"""
OPTION C INTEGRATION PATCH
Minimal code changes to integrate ProductionScheduleMonitor into intelligent_interface.py

This file shows EXACTLY what to add to intelligent_interface.py to enable Option C monitoring.
"""

# ============================================================================
# STEP 1: ADD THESE IMPORTS AT TOP OF intelligent_interface.py
# ============================================================================

IMPORTS_TO_ADD = """
# Add these lines at the top of intelligent_interface.py:
import os
from schedule_monitor_production import ProductionScheduleMonitor
"""

# ============================================================================
# STEP 2: ADD TO __init__ METHOD (around line 50-100)
# ============================================================================

INIT_ADDITION = """
# In IntelligentInterface.__init__(), add this after existing initializations:

# Production Monitoring Setup (NEW - Option C)
csv_path = os.path.join(self.data_path, "history", "historical_data.csv")
self.monitor = ProductionScheduleMonitor(self.data_path, csv_path)
self.logger.info("✓ Production monitor initialized")

# Optional: Load historical data on startup
try:
    historical = self.monitor.load_historical_schedules(limit=50)
    self.logger.info(f"✓ Loaded {len(historical)} historical schedules")
except Exception as e:
    self.logger.warning(f"Could not load historical data: {e}")
"""

# ============================================================================
# STEP 3: ADD TO SCHEDULE GENERATION METHOD (wrap existing code)
# ============================================================================

SCHEDULE_GENERATION_ADDITION = """
# In your main schedule generation method, add monitoring:

def _generate_schedule(self, schedules_data):
    '''Generate schedule with Option C monitoring'''
    
    # Generate schedule using existing logic
    generated_schedule = super()._generate_schedule(schedules_data)
    
    if generated_schedule:
        # NEW: Predict quality using monitor
        try:
            quality = self.monitor.predict_schedule_quality(
                generated_schedule,
                store_feedback=True
            )
            self.logger.info(
                f"Schedule quality: {quality['grade']} "
                f"({quality['overall_quality_score']*100:.1f}%)"
            )
            
            # NEW: Check if retraining is due
            if self.monitor.should_retrain():
                self.logger.warning("Quarterly retraining due - running now...")
                self.monitor.retrain_model()
                self.logger.info("✓ Model retrained")
                
        except Exception as e:
            self.logger.error(f"Monitoring error: {e}")
    
    return generated_schedule
"""

# ============================================================================
# STEP 4: ADD NEW METHODS TO CLASS
# ============================================================================

CLASS_METHODS_TO_ADD = """
# Add these methods to the IntelligentInterface class:

def get_monitoring_status(self):
    '''Get current monitoring status'''
    return self.monitor.get_performance_summary()

def print_monitoring_report(self):
    '''Print detailed monitoring report'''
    self.monitor.print_status_report()
    
def get_schedule_quality(self, schedules):
    '''Check quality of any schedule'''
    return self.monitor.predict_schedule_quality(schedules)

def retrain_if_needed(self):
    '''Manually trigger retraining if due'''
    if self.monitor.should_retrain():
        self.logger.info("Starting quarterly retraining...")
        metrics = self.monitor.retrain_model()
        if metrics.get("status") == "trained":
            self.logger.info(
                f"✓ Retraining complete - "
                f"Accuracy: {metrics['accuracy']*100:.1f}%"
            )
        return True
    return False
"""

# ============================================================================
# COMPLETE INTEGRATION EXAMPLE (Copy & Paste Ready)
# ============================================================================

COMPLETE_INTEGRATION = """
# THIS IS WHAT YOUR intelligent_interface.py SHOULD LOOK LIKE:

import os
import logging
from schedule_monitor_production import ProductionScheduleMonitor
# ... existing imports ...

class IntelligentInterface:
    def __init__(self, data_path="."):
        '''Initialize with Option C monitoring'''
        
        self.data_path = data_path
        self.logger = logging.getLogger(__name__)
        
        # ... existing initialization code ...
        
        # OPTION C: Initialize Production Monitor
        csv_path = os.path.join(self.data_path, "history", "historical_data.csv")
        self.monitor = ProductionScheduleMonitor(self.data_path, csv_path)
        self.logger.info("✓ Production monitor initialized")
        
    def generate_department_schedule(self, dept_code):
        '''Generate schedule with monitoring'''
        
        # Generate using existing logic
        schedule = self._generate_dept_schedule_base(dept_code)
        
        if schedule:
            # OPTION C: Monitor quality
            try:
                quality = self.monitor.predict_schedule_quality(
                    schedule,
                    store_feedback=True
                )
                self.logger.info(f"Quality: {quality['grade']}")
                
                # OPTION C: Check retraining
                if self.monitor.should_retrain():
                    self.logger.info("Quarterly retraining...")
                    self.monitor.retrain_model()
                    
            except Exception as e:
                self.logger.error(f"Monitoring error: {e}")
        
        return schedule
    
    # OPTION C: New methods for monitoring
    def get_monitoring_status(self):
        '''Get monitoring metrics'''
        return self.monitor.get_performance_summary()
    
    def print_monitoring_report(self):
        '''Print detailed report'''
        self.monitor.print_status_report()
"""

# ============================================================================
# MINIMAL INTEGRATION (Just 3 Additions)
# ============================================================================

MINIMAL_INTEGRATION = """
# ABSOLUTE MINIMUM changes (3 additions):

# 1. Add import at top
from schedule_monitor_production import ProductionScheduleMonitor

# 2. Add to __init__ (1 line)
self.monitor = ProductionScheduleMonitor(self.data_path, ".")

# 3. Add to schedule generation (3 lines)
quality = self.monitor.predict_schedule_quality(schedule)
if quality['conflict_count'] > 0:
    self.logger.warning(f"Conflicts: {quality['conflict_count']}")
"""

# ============================================================================
# TESTING YOUR INTEGRATION
# ============================================================================

INTEGRATION_TEST = """
# Test your integration:

from intelligent_interface import IntelligentInterface

# Initialize with monitoring
interface = IntelligentInterface(data_path=".")

# Generate schedule
schedule = interface.generate_department_schedule("COSC")

# Check monitoring
status = interface.get_monitoring_status()
print(f"Conflict rate: {status['conflict_rate']}")
print(f"Total predictions: {status['total_predictions']}")

# Print report
interface.print_monitoring_report()

# Check for retraining
if interface.monitor.should_retrain():
    print("Retraining model...")
    interface.monitor.retrain_model()
"""

# ============================================================================
# FUNCTION: AUTO-PATCH intelligent_interface.py
# ============================================================================

def auto_patch_intelligent_interface(file_path):
    """
    Automatically patch intelligent_interface.py with Option C integration.
    
    WARNING: Creates backup before patching.
    """
    import shutil
    from datetime import datetime
    
    # Create backup
    backup_path = f"{file_path}.backup.{datetime.now().strftime('%Y%m%d_%H%M%S')}"
    shutil.copy2(file_path, backup_path)
    print(f"✓ Backup created: {backup_path}")
    
    with open(file_path, 'r') as f:
        content = f.read()
    
    # Check if already patched
    if "ProductionScheduleMonitor" in content:
        print("⚠️  Already patched! Skipping...")
        return False
    
    # Add imports
    if "import os" not in content:
        content = "import os\n" + content
    
    import_line = "from schedule_monitor_production import ProductionScheduleMonitor\n"
    if import_line not in content:
        lines = content.split('\n')
        for i, line in enumerate(lines):
            if line.startswith('import ') or line.startswith('from '):
                lines.insert(i+1, import_line.strip())
                break
        content = '\n'.join(lines)
    
    # Add to __init__
    init_addition = '''
        # OPTION C: Production Monitoring
        csv_path = os.path.join(self.data_path, "history", "historical_data.csv")
        self.monitor = ProductionScheduleMonitor(self.data_path, csv_path)
'''
    
    if "self.monitor = ProductionScheduleMonitor" not in content:
        lines = content.split('\n')
        for i, line in enumerate(lines):
            if 'def __init__' in line:
                # Find end of existing initializations
                for j in range(i+1, len(lines)):
                    if lines[j].strip() and not lines[j].startswith(' ') * 8:
                        lines.insert(j, init_addition)
                        break
                break
        content = '\n'.join(lines)
    
    # Write patched file
    with open(file_path, 'w') as f:
        f.write(content)
    
    print(f"✓ Patched: {file_path}")
    return True

# ============================================================================
# SUMMARY
# ============================================================================

SUMMARY = """
OPTION C INTEGRATION SUMMARY
============================

What to add:
1. One import line
2. One initialization line in __init__
3. 3-5 lines in your schedule generation method
4. Optional: 2-3 new methods for reporting

Where to add:
├─ Imports: Top of intelligent_interface.py
├─ Init: In __init__ method
├─ Monitoring: In main schedule generation method
└─ Methods: Add to class body

Result:
✓ Real-time quality predictions
✓ Automatic feedback collection
✓ Quarterly retraining
✓ Daily/weekly/monthly reporting
✓ Performance tracking

Integration time: 15-30 minutes
Testing time: 5 minutes
Benefit: Production-grade monitoring

Test after integration:
python3 test_production_integration.py

All tests should pass! ✅
"""

if __name__ == "__main__":
    print(SUMMARY)
    print("\n" + "="*80)
    print("IMPORTS TO ADD:")
    print("="*80)
    print(IMPORTS_TO_ADD)
    
    print("\n" + "="*80)
    print("__init__ ADDITION:")
    print("="*80)
    print(INIT_ADDITION)
    
    print("\n" + "="*80)
    print("SCHEDULE GENERATION ADDITION:")
    print("="*80)
    print(SCHEDULE_GENERATION_ADDITION)
    
    print("\n" + "="*80)
    print("NEW CLASS METHODS:")
    print("="*80)
    print(CLASS_METHODS_TO_ADD)
    
    print("\n" + "="*80)
    print("MINIMAL INTEGRATION (3 additions):")
    print("="*80)
    print(MINIMAL_INTEGRATION)
    
    print("\n" + "="*80)
    print("INTEGRATION TEST:")
    print("="*80)
    print(INTEGRATION_TEST)
