"""
Production Grade ML Integration with Real-Time Feedback & Historical Data

Integrates EnsembleSchedulePredictor with historical_data.csv for:
- Loading existing schedule history
- Real-time quality predictions
- Feedback collection
- Performance monitoring
- Quarterly retraining
- Continuous improvement tracking
"""

import os
import json
import csv
from datetime import datetime, timedelta
from typing import Dict, List, Tuple, Optional
from collections import defaultdict

try:
    from schedule_accuracy_predictor_v2 import EnsembleSchedulePredictor
    from training_data_collector import TrainingDataCollector
    HAS_ML = True
except ImportError as e:
    print(f"Warning: ML components not available: {e}")
    HAS_ML = False


class ProductionScheduleMonitor:
    """
    Enterprise-grade schedule quality monitoring system
    
    Features:
    - Loads historical schedules from CSV
    - Real-time quality predictions
    - Feedback collection & storage
    - Performance tracking
    - Quarterly retraining
    - Continuous improvement
    """
    
    def __init__(self, data_path: str = ".", csv_path: Optional[str] = None):
        self.data_path = data_path
        self.history_dir = os.path.join(data_path, "history")
        os.makedirs(self.history_dir, exist_ok=True)
        
        # CSV paths
        self.historical_csv = csv_path or os.path.join(self.history_dir, "historical_data.csv")
        self.feedback_csv = os.path.join(self.history_dir, "feedback_log.csv")
        
        # ML components
        if HAS_ML:
            self.predictor = EnsembleSchedulePredictor(data_path)
            self.collector = TrainingDataCollector(data_path)
        
        # Monitoring state
        self.predictions_log = []
        self.performance_metrics = {
            "total_predictions": 0,
            "conflicts_detected": 0,
            "average_confidence": 0.0,
            "predictions_by_grade": defaultdict(int)
        }
        
        self.last_retraining = datetime.now()
        self.retraining_interval_days = 90  # Quarterly
        
        # Load existing feedback
        self._load_feedback_history()
    
    def load_historical_schedules(self, limit: Optional[int] = None) -> List[Dict]:
        """
        Load schedules from historical_data.csv
        
        CSV Format: timestamp,course_code,title,lecturer,room,day,time,level,semester,
                   enrollment,credits,dept,unknown1,utilization,unknown2
        
        Args:
            limit: Maximum schedules to load (None = all)
        
        Returns:
            List of schedule entries
        """
        schedules = []
        
        if not os.path.exists(self.historical_csv):
            print(f"Warning: Historical CSV not found: {self.historical_csv}")
            return schedules
        
        print(f"Loading historical schedules from {self.historical_csv}...")
        
        try:
            with open(self.historical_csv, 'r', encoding='utf-8') as f:
                reader = csv.reader(f)
                next(reader, None)  # Skip header if exists
                
                for idx, row in enumerate(reader):
                    if limit and idx >= limit:
                        break
                    
                    if len(row) < 9:
                        continue
                    
                    # Parse CSV row
                    schedule_entry = self._parse_csv_row(row)
                    if schedule_entry:
                        schedules.append(schedule_entry)
            
            print(f"✓ Loaded {len(schedules)} historical schedules")
            return schedules
        
        except Exception as e:
            print(f"Error loading historical data: {e}")
            return schedules
    
    @staticmethod
    def _parse_csv_row(row: List[str]) -> Optional[Dict]:
        """Parse CSV row into schedule entry dict"""
        try:
            schedule_item = {
                "timestamp": row[0] if len(row) > 0 else datetime.now().isoformat(),
                "course_code": row[1] if len(row) > 1 else "UNKNOWN",
                "title": row[2] if len(row) > 2 else "",
                "lecturer": row[3] if len(row) > 3 else "",
                "room_name": row[4] if len(row) > 4 else "",
                "day": row[5] if len(row) > 5 else "Monday",
                "time_slot": row[6] if len(row) > 6 else "7:00am - 9:30am",
                "level": int(row[7]) if len(row) > 7 else 1,
                "semester": int(row[8]) if len(row) > 8 else 1,
                "enrollment": int(row[9]) if len(row) > 9 else 0,
                "credits": row[10] if len(row) > 10 else "3",
                "department": row[11] if len(row) > 11 else "general",
                # Add default values for ML features
                "room_capacity": 100,  # Estimate
                "is_special_room": "LAB" in row[4] if len(row) > 4 else False,
                "lecturer_availability_ratio": 0.8,
                "lecturer_daily_load": 4.0,  # Estimate
                "conflict_likelihood": 0.0,
                "lecturer_specialty_match": 0.75,
                "enrollment_stability": 0.85,
                "time_gap_factor": 0.7
            }
            
            return schedule_item
        except Exception as e:
            print(f"Error parsing CSV row: {e}")
            return None
    
    def predict_schedule_quality(self, schedule_items: List[Dict], 
                                store_feedback: bool = True) -> Dict:
        """
        Predict quality for schedule and optionally store feedback
        
        Args:
            schedule_items: Schedule entries to predict
            store_feedback: Whether to store in feedback log
        
        Returns:
            Quality prediction with detailed metrics
        """
        if not HAS_ML:
            return {"error": "ML system not available"}
        
        print(f"\nPredicting quality for {len(schedule_items)} schedule items...")
        
        # Get prediction
        quality = self.predictor.predict_schedule_quality(schedule_items)
        
        # Update metrics
        self.performance_metrics["total_predictions"] += len(schedule_items)
        self.performance_metrics["conflicts_detected"] += quality.get("conflict_count", 0)
        grade = quality.get("grade", "N/A")
        self.performance_metrics["predictions_by_grade"][grade] += 1
        
        # Store in log
        prediction_entry = {
            "timestamp": datetime.now().isoformat(),
            "item_count": len(schedule_items),
            "grade": grade,
            "quality_score": quality.get("overall_quality_score", 0),
            "conflict_count": quality.get("conflict_count", 0),
            "avg_confidence": quality.get("average_confidence", 0),
            "conflicts": json.dumps(quality.get("conflict_items", []))
        }
        self.predictions_log.append(prediction_entry)
        
        # Store detailed feedback
        if store_feedback:
            self._store_feedback(schedule_items, quality)
        
        # Print summary
        print(f"  Grade: {grade}")
        print(f"  Score: {quality.get('overall_quality_score', 0)*100:.1f}%")
        print(f"  Conflicts: {quality.get('conflict_count', 0)}")
        print(f"  Confidence: {quality.get('average_confidence', 0)*100:.1f}%")
        
        return quality
    
    def _store_feedback(self, schedule_items: List[Dict], quality: Dict):
        """Store prediction feedback to CSV for analysis"""
        try:
            file_exists = os.path.exists(self.feedback_csv)
            
            with open(self.feedback_csv, 'a', newline='', encoding='utf-8') as f:
                fieldnames = ['timestamp', 'course_code', 'lecturer', 'day', 'time_slot',
                             'enrollment', 'predicted_quality', 'conflict_detected']
                writer = csv.DictWriter(f, fieldnames=fieldnames)
                
                if not file_exists:
                    writer.writeheader()
                
                for item in schedule_items:
                    has_conflict = quality.get('conflict_count', 0) > 0
                    
                    row = {
                        'timestamp': datetime.now().isoformat(),
                        'course_code': item.get('course_code', ''),
                        'lecturer': item.get('lecturer', ''),
                        'day': item.get('day', ''),
                        'time_slot': item.get('time_slot', ''),
                        'enrollment': item.get('enrollment', 0),
                        'predicted_quality': quality.get('grade', 'N/A'),
                        'conflict_detected': 1 if has_conflict else 0
                    }
                    writer.writerow(row)
        
        except Exception as e:
            print(f"Warning: Could not store feedback: {e}")
    
    def should_retrain(self) -> bool:
        """Check if quarterly retraining is due"""
        days_since = (datetime.now() - self.last_retraining).days
        return days_since >= self.retraining_interval_days
    
    def retrain_model(self, min_new_samples: int = 50) -> Dict:
        """
        Perform quarterly model retraining
        
        Args:
            min_new_samples: Minimum new samples needed to retrain
        
        Returns:
            Retraining metrics
        """
        if not HAS_ML:
            return {"error": "ML system not available"}
        
        print("\n" + "="*70)
        print("  QUARTERLY MODEL RETRAINING")
        print("="*70)
        
        # Get training data
        training_data = self.collector.get_training_data_for_model()
        
        if len(training_data) < min_new_samples:
            print(f"⚠ Insufficient new data: {len(training_data)}/{min_new_samples} samples")
            return {
                "status": "skipped",
                "reason": f"Need {min_new_samples} samples, have {len(training_data)}"
            }
        
        print(f"✓ Retraining with {len(training_data)} samples...")
        print(f"  Models: RandomForest, GradientBoosting, MLP")
        
        # Train
        metrics = self.predictor.train(training_data, validation_split=0.2)
        
        if metrics.get("status") == "trained":
            print(f"\n✓ Training completed!")
            print(f"  Accuracy:  {metrics.get('accuracy', 0)*100:.1f}%")
            print(f"  Precision: {metrics.get('precision', 0)*100:.1f}%")
            print(f"  Recall:    {metrics.get('recall', 0)*100:.1f}%")
            print(f"  F1-Score:  {metrics.get('f1', 0)*100:.1f}%")
            
            self.last_retraining = datetime.now()
            self._save_retraining_log(metrics)
            
            return metrics
        else:
            print(f"✗ Training failed: {metrics.get('reason')}")
            return metrics
    
    def _load_feedback_history(self):
        """Load previous feedback logs"""
        try:
            if os.path.exists(self.feedback_csv):
                with open(self.feedback_csv, 'r', encoding='utf-8') as f:
                    reader = csv.DictReader(f)
                    feedback_count = sum(1 for _ in reader)
                    print(f"✓ Loaded {feedback_count} previous feedback entries")
        except Exception as e:
            print(f"Warning: Could not load feedback history: {e}")
    
    def _save_retraining_log(self, metrics: Dict):
        """Save retraining metrics to history"""
        try:
            log_file = os.path.join(self.history_dir, "retraining_log.json")
            
            log_entry = {
                "timestamp": datetime.now().isoformat(),
                "metrics": metrics,
                "days_since_last_train": (datetime.now() - self.last_retraining).days
            }
            
            logs = []
            if os.path.exists(log_file):
                with open(log_file, 'r') as f:
                    logs = json.load(f)
            
            logs.append(log_entry)
            
            with open(log_file, 'w') as f:
                json.dump(logs, f, indent=2)
            
            print(f"✓ Retraining log saved")
        
        except Exception as e:
            print(f"Warning: Could not save retraining log: {e}")
    
    def get_performance_summary(self) -> Dict:
        """Get monitoring performance summary"""
        total = self.performance_metrics["total_predictions"]
        conflicts = self.performance_metrics["conflicts_detected"]
        
        summary = {
            "total_predictions": total,
            "total_conflicts_detected": conflicts,
            "conflict_rate": f"{conflicts/total*100:.1f}%" if total > 0 else "0%",
            "predictions_by_grade": dict(self.performance_metrics["predictions_by_grade"]),
            "last_retraining": self.last_retraining.isoformat(),
            "days_since_retraining": (datetime.now() - self.last_retraining).days,
            "due_for_retraining": self.should_retrain()
        }
        
        return summary
    
    def generate_daily_report(self) -> Dict:
        """Generate daily monitoring report"""
        report = {
            "date": datetime.now().strftime("%Y-%m-%d"),
            "summary": self.get_performance_summary(),
            "predictions_today": sum(1 for p in self.predictions_log 
                                     if datetime.fromisoformat(p["timestamp"]).date() == datetime.now().date()),
            "feedback_stored": len([f for f in self.predictions_log 
                                   if datetime.fromisoformat(f["timestamp"]).date() == datetime.now().date()])
        }
        
        return report
    
    def print_status_report(self):
        """Print detailed status report"""
        print("\n" + "="*70)
        print("  PRODUCTION MONITOR - STATUS REPORT")
        print("="*70)
        
        summary = self.get_performance_summary()
        
        print(f"\n📊 Predictions:")
        print(f"  Total: {summary['total_predictions']}")
        print(f"  Conflicts detected: {summary['total_conflicts_detected']}")
        print(f"  Conflict rate: {summary['conflict_rate']}")
        
        print(f"\n📈 Grade Distribution:")
        for grade, count in sorted(summary['predictions_by_grade'].items()):
            print(f"  {grade}: {count}")
        
        print(f"\n⏱️  Retraining:")
        print(f"  Last trained: {summary['last_retraining']}")
        print(f"  Days since: {summary['days_since_retraining']}")
        print(f"  Due for retraining: {'YES ⚠️' if summary['due_for_retraining'] else 'No ✓'}")
        
        print("\n" + "="*70)


class IntegratedScheduler:
    """
    Integrated scheduler combining CSPSolver with ProductionMonitor
    
    Usage:
        integrated = IntegratedScheduler(data_path, csv_path)
        schedule = integrated.generate_with_monitoring(dept_code)
        integrated.check_quarterly_retraining()
    """
    
    def __init__(self, scheduler, data_path: str = ".", 
                 csv_path: Optional[str] = None):
        """
        Initialize with existing scheduler
        
        Args:
            scheduler: SchoolScheduler instance
            data_path: Path for ML data
            csv_path: Path to historical_data.csv
        """
        self.scheduler = scheduler
        self.monitor = ProductionScheduleMonitor(data_path, csv_path)
    
    def generate_with_monitoring(self, dept_code: Optional[str] = None) -> Dict:
        """
        Generate schedule with quality monitoring
        
        Args:
            dept_code: Department code to schedule (None = full school)
        
        Returns:
            Schedule with quality metrics
        """
        print(f"\n{'='*70}")
        print(f"  GENERATING SCHEDULE WITH ML MONITORING")
        print(f"{'='*70}")
        
        # Generate schedule
        if dept_code:
            print(f"\nGenerating schedule for department: {dept_code}")
            schedule = self.scheduler._schedule_department(dept_code)
        else:
            print(f"\nGenerating full school schedule")
            schedule = self.scheduler.generate()
        
        # Predict quality
        if schedule:
            print(f"\n✓ Schedule generated: {len(schedule)} items")
            quality = self.monitor.predict_schedule_quality(schedule)
            
            return {
                "schedule": schedule,
                "quality": quality,
                "monitor": self.monitor
            }
        else:
            print("✗ Failed to generate schedule")
            return {"error": "Schedule generation failed"}
    
    def check_quarterly_retraining(self) -> Optional[Dict]:
        """
        Check and perform quarterly retraining if due
        
        Returns:
            Retraining metrics if performed, None otherwise
        """
        if self.monitor.should_retrain():
            print(f"\n⚠️  Quarterly retraining due!")
            return self.monitor.retrain_model()
        else:
            days_left = self.monitor.retraining_interval_days - \
                       (datetime.now() - self.monitor.last_retraining).days
            print(f"✓ Retraining not due for {days_left} days")
            return None
    
    def generate_daily_report(self):
        """Generate and display daily report"""
        report = self.monitor.generate_daily_report()
        
        print(f"\n{'='*70}")
        print(f"  DAILY MONITORING REPORT - {report['date']}")
        print(f"{'='*70}")
        
        print(f"\nPredictions today: {report['predictions_today']}")
        print(f"Feedback stored: {report['feedback_stored']}")
        
        summary = report['summary']
        print(f"\nOverall conflict rate: {summary['conflict_rate']}")
        print(f"Retraining due: {'YES' if summary['due_for_retraining'] else 'NO'}")


def integrate_with_intelligent_interface(intelligent_interface_instance, 
                                        data_path: str = ".",
                                        csv_path: Optional[str] = None):
    """
    Integrate ProductionMonitor into existing IntelligentInterface
    
    Usage in intelligent_interface.py:
        from schedule_monitor_production import integrate_with_intelligent_interface
        
        # In IntelligentInterface.__init__:
        integrate_with_intelligent_interface(self, data_path, csv_path)
        
        # In any scheduling method:
        quality = self.monitor.predict_schedule_quality(schedule)
        
        # Periodically:
        if self.monitor.should_retrain():
            self.monitor.retrain_model()
    """
    monitor = ProductionScheduleMonitor(data_path, csv_path)
    intelligent_interface_instance.monitor = monitor
    
    print(f"✓ Production monitor integrated")
    print(f"  - Monitor attached to: {intelligent_interface_instance.__class__.__name__}")
    print(f"  - Historical CSV: {csv_path or os.path.join(data_path, 'history/historical_data.csv')}")
    print(f"  - Access via: self.monitor.predict_schedule_quality(schedule)")
    
    return monitor
