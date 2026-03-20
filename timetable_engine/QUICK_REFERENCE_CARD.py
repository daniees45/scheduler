#!/usr/bin/env python3
"""
OPTION C: QUICK REFERENCE CARD
Print this page and keep it nearby during integration
"""

QUICK_REFERENCE_CARD = """
╔════════════════════════════════════════════════════════════════════════════╗
║                      OPTION C INTEGRATION CARD                            ║
║                          QUICK REFERENCE                                  ║
║                      Print & Keep During Deployment                       ║
╚════════════════════════════════════════════════════════════════════════════╝


SECTION 1: PRE-INTEGRATION CHECKLIST
════════════════════════════════════════════════════════════════════════════

[ ] Files present:
    ✓ schedule_monitor_production.py
    ✓ option_c_integration_examples.py
    ✓ test_production_integration.py

[ ] Dependencies installed:
    $ python3 -c "import sklearn, pandas, numpy, joblib; print('OK')"

[ ] Tests passing:
    $ python3 test_production_integration.py
    Expected: ✅ ALL TESTS PASSED (8/8)

[ ] Backups created:
    $ cp intelligent_interface.py intelligent_interface.py.backup
    $ cp -r history history.backup


SECTION 2: THE 4 CODE ADDITIONS
════════════════════════════════════════════════════════════════════════════

LOCATION 1: TOP OF FILE
──────────────────────────────────────────────────────────────────────────
Add this import (1 line):

from schedule_monitor_production import ProductionScheduleMonitor


LOCATION 2: END OF __init__ METHOD
──────────────────────────────────────────────────────────────────────────
Add these lines (2 lines):

csv_path = os.path.join(self.data_path, "history", "historical_data.csv")
self.monitor = ProductionScheduleMonitor(self.data_path, csv_path)


LOCATION 3: AFTER GENERATING SCHEDULE
──────────────────────────────────────────────────────────────────────────
Add this code (5 lines):

if schedule:
    quality = self.monitor.predict_schedule_quality(schedule, store_feedback=True)
    self.logger.info(f"Quality: {quality['grade']}")
    if self.monitor.should_retrain():
        self.monitor.retrain_model()


LOCATION 4: END OF CLASS (OPTIONAL)
──────────────────────────────────────────────────────────────────────────
Add these methods (~10 lines):

def get_monitoring_status(self):
    return self.monitor.get_performance_summary()

def print_monitoring_report(self):
    self.monitor.print_status_report()

def check_schedule_quality(self, schedules):
    return self.monitor.predict_schedule_quality(schedules)


SECTION 3: INTEGRATION WORKFLOW
════════════════════════════════════════════════════════════════════════════

Step 1: Edit intelligent_interface.py
  ├─ Add import (Location 1)
  ├─ Add init (Location 2)
  ├─ Add monitoring (Location 3)
  └─ Add methods (Location 4)

Step 2: Verify syntax
  $ python3 -m py_compile intelligent_interface.py

Step 3: Test import
  $ python3 -c "from intelligent_interface import IntelligentInterface; print('OK')"

Step 4: Run test suite
  $ python3 test_production_integration.py

Step 5: Deploy
  $ python3 main.py

Step 6: Verify in logs
  grep "Production monitor" *.log


SECTION 4: POST-DEPLOYMENT VERIFICATION
════════════════════════════════════════════════════════════════════════════

Immediate (first 5 minutes):
  [ ] Process running: ps aux | grep intelligent
  [ ] No errors: grep -i error *.log
  [ ] Feedback created: ls -la history/feedback_log.csv

First hour:
  [ ] Predictions logged: tail -f history/feedback_log.csv
  [ ] Grades appearing: grep -i "quality" *.log
  [ ] System stable: top -n 1

First 24 hours:
  CPU usage:     < 30%
  Memory usage:  < 300MB
  Disk growth:   < 10MB
  Uptime:        100%
  Errors:        0


SECTION 5: MONITORING COMMANDS
════════════════════════════════════════════════════════════════════════════

Generate report:
  $ python3 -c "from intelligent_interface import IntelligentInterface; \\
    i = IntelligentInterface(); i.print_monitoring_report()"

Check status:
  $ python3 -c "from intelligent_interface import IntelligentInterface; \\
    i = IntelligentInterface(); \\
    print(i.get_monitoring_status())"

View feedback:
  $ tail -20 history/feedback_log.csv

Count predictions:
  $ wc -l history/feedback_log.csv

View quality distribution:
  $ tail -100 history/feedback_log.csv | cut -d',' -f7 | sort | uniq -c

Check for retraining:
  $ python3 -c "from intelligent_interface import IntelligentInterface; \\
    i = IntelligentInterface(); \\
    print(f'Retrain due: {i.monitor.should_retrain()}')"

Manual retrain:
  $ python3 -c "from intelligent_interface import IntelligentInterface; \\
    i = IntelligentInterface(); \\
    m = i.monitor.retrain_model(); \\
    print(f'Accuracy: {m[\"accuracy\"]*100:.1f}%')"


SECTION 6: EXPECTED OUTPUT EXAMPLES
════════════════════════════════════════════════════════════════════════════

In logs you should see:
  ✓ IntelligentInterface initialized
  ✓ Production monitor activated (Option C)
  ✓ Quality: A+ (if schedule good)
  ✓ Quality: B- (if some conflicts)

In feedback_log.csv:
  timestamp,course_code,lecturer,day,time_slot,enrollment,quality,conflicts
  2026-02-22T15:31:00,PEAC 100,K. Oheneba Nti,Monday,5:00pm - 6:00pm,30,A+,0
  2026-02-22T15:31:02,COSC 101,Dr. Smith,Monday,5:00pm - 6:00pm,45,B+,1

Dashboard output (print_monitoring_report):
  ╔═══════════════════════════════════════════════════════════════╗
  ║          PRODUCTION SCHEDULE MONITOR - STATUS REPORT         ║
  ╠═══════════════════════════════════════════════════════════════╣
  ║ Total Predictions Made: 245                                  ║
  ║ Conflicts Detected: 8                                        ║
  ║ Conflict Rate: 3.27%                                          ║
  ║ Average Grade: A- (Excellent)                                ║
  ║ Days Since Last Retraining: 0                                ║
  ║ Quarterly Retraining Due: No (90-day interval)               ║
  ╚═══════════════════════════════════════════════════════════════╝


SECTION 7: TROUBLESHOOTING
════════════════════════════════════════════════════════════════════════════

Problem: ImportError for ProductionScheduleMonitor
Solution:
  1. Verify file: ls -la timetable_engine/schedule_monitor_production.py
  2. Check PYTHONPATH: export PYTHONPATH=.:./timetable_engine:$PYTHONPATH
  3. Retry: cd timetable_engine && python3 import_test.py

Problem: Module not found error
Solution:
  1. Install packages: pip install scikit-learn pandas numpy joblib
  2. Verify: python3 -m pip list | grep scikit
  3. Retry: python3 test_production_integration.py

Problem: historical_data.csv not found
Solution:
  1. Check path: pwd
  2. Verify file: ls -la history/historical_data.csv
  3. Fix path in code if needed

Problem: feedback_log.csv not being created
Solution:
  1. Check permissions: ls -la history/
  2. Fix if needed: chmod 755 history/
  3. Manual trigger: 
     python3 -c "from schedule_monitor_production import ProductionScheduleMonitor; \\
       m = ProductionScheduleMonitor('.', '.'); m.predict_schedule_quality([{}])"

Problem: Low accuracy
Solution: This is normal! Model improves:
  Month 1: 76.7% (baseline)
  Month 2: 80-82% (first retrain)
  Month 3: 88-92% (production grade)

Problem: Want to rollback
Solution:
  1. Stop service: pkill -f intelligent
  2. Restore: cp intelligent_interface.py.backup intelligent_interface.py
  3. Restore: rm -rf history/ && cp -r history.backup history
  4. Verify: python3 -c "from intelligent_interface import IntelligentInterface; print('OK')"
  5. Restart: python3 main.py


SECTION 8: DAILY MONITORING CHECKLIST
════════════════════════════════════════════════════════════════════════════

Daily (Morning):
  [ ] Process running:
      ps aux | grep intelligent
  [ ] Check logs:
      tail -20 *.log
  [ ] View status:
      python3 -c "from intelligent_interface import IntelligentInterface; \\
        i = IntelligentInterface(); print(i.get_monitoring_status())"

Weekly (Monday):
  [ ] Run report:
      python3 -c "from intelligent_interface import IntelligentInterface; \\
        i = IntelligentInterface(); i.print_monitoring_report()"
  [ ] Backup data:
      cp history/feedback_log.csv backups/feedback_log.csv.weekly
  [ ] Analyze trends:
      wc -l history/feedback_log.csv

Monthly (1st of month):
  [ ] Generate comprehensive report
  [ ] Analyze performance trends
  [ ] Check if retraining due (after day 30)
  [ ] Plan for next month

Quarterly (Every 90 days):
  [ ] Check if retraining due:
      python3 -c "from intelligent_interface import IntelligentInterface; \\
        i = IntelligentInterface(); \\
        if i.monitor.should_retrain(): \\
            print('RETRAIN NOW')"
  [ ] Execute retraining if due
  [ ] Review accuracy improvement
  [ ] Plan next 90 days


SECTION 9: INTEGRATION PHASES
════════════════════════════════════════════════════════════════════════════

Phase 1: Setup (Day 1)
  Duration: 1 hour
  Tasks: Add 4 code sections, test, deploy
  Result: Monitoring active

Phase 2: Data Collection (Days 1-30)
  Duration: 30 days (passive)
  Tasks: System collects 500-1000 feedback entries
  Result: Baseline established

Phase 3: First Retraining (Day 30-90)
  Duration: Optional, ~5 minutes if triggered
  Tasks: Retrain model with collected data
  Result: Accuracy improves to 80%+

Phase 4: Production (Month 2+)
  Duration: Ongoing
  Tasks: Continuous monitoring & quarterly retraining
  Result: 88-92% accuracy, autonomous operation


SECTION 10: SUCCESS INDICATORS
════════════════════════════════════════════════════════════════════════════

✅ Week 1 Success:
   • 1,000+ predictions
   • Mostly A/A+ grades
   • <2% conflicts
   • No errors

✅ Month 1 Success:
   • 10,000+ predictions
   • 5,000+ feedback entries
   • Ready for retraining
   • Stable system

✅ Month 2 Success:
   • Model retrained
   • Accuracy 80%+
   • Patterns identified
   • Automated operation

✅ Month 3+ Success:
   • 88-92% accuracy
   • Production-grade reliability
   • Zero manual intervention
   • Continuous improvement


SECTION 11: QUICK FILE REFERENCE
════════════════════════════════════════════════════════════════════════════

Modified:
  intelligent_interface.py          (Your integration point)

Created:
  history/feedback_log.csv          (Predictions stored here)

Already present:
  schedule_monitor_production.py    (Core monitor class)
  test_production_integration.py    (Tests)
  history/historical_data.csv       (Historical schedules)
  history/ensemble_model.pkl        (ML model)

Documentation:
  OPTION_C_PRODUCTION_INTEGRATION.md (Start here!)
  IMPLEMENTATION_GUIDE.py            (Step-by-step)
  DEPLOYMENT_CHECKLIST.py            (Pre/post checks)
  option_c_integration_examples.py   (Code examples)


SECTION 12: CONTACTS & RESOURCES
════════════════════════════════════════════════════════════════════════════

Documentation:
  • For overview: OPTION_C_PRODUCTION_INTEGRATION.md
  • For details: IMPLEMENTATION_GUIDE.py
  • For examples: option_c_integration_examples.py
  • For testing: test_production_integration.py

Code:
  • ProductionScheduleMonitor class
  • Methods: predict_schedule_quality(), retrain_model(), etc.
  • Source: schedule_monitor_production.py

Data:
  • Input: history/historical_data.csv (226 schedules)
  • Output: history/feedback_log.csv (auto-created)
  • Models: history/*.pkl (auto-saved)


════════════════════════════════════════════════════════════════════════════════

TOTAL TIME TO INTEGRATION: 1 hour
TOTAL TIME TO PRODUCTION: 1 month
TOTAL TIME TO EXCELLENCE: 3 months

Ready? Let's do this! 🚀

════════════════════════════════════════════════════════════════════════════════
"""


if __name__ == "__main__":
    print(QUICK_REFERENCE_CARD)
    
    # Save to file for printing
    with open("QUICK_REFERENCE_CARD.txt", "w") as f:
        f.write(QUICK_REFERENCE_CARD)
    
    print("\n\n" + "="*80)
    print("Card saved to: QUICK_REFERENCE_CARD.txt")
    print("Print and keep nearby during deployment!")
    print("="*80)
