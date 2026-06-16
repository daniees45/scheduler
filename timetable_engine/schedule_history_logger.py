"""
Schedule History Logger - Records all generated schedules for AI training
"""

import os
import csv
import json
from datetime import datetime
from typing import List, Dict, Any


class ScheduleHistoryLogger:
    """Logs all generated schedules to CSV for historical analysis and AI training"""
    
    def __init__(self, base_path: str = "."):
        self.base_path = base_path
        self.history_dir = os.path.join(base_path, "csv", "general")
        self.class_history_file = os.path.join(self.history_dir, "historical_schedule.csv")
        self.exam_history_file = os.path.join(base_path, "csv", "general", "historical_exam_schedule.csv")

        
        # Ensure history directory exists
        os.makedirs(self.history_dir, exist_ok=True)
        
        # Initialize CSV files if they don't exist
        self._init_class_history_csv()
        self._init_exam_history_csv()
    
    def _init_class_history_csv(self):
        """Initialize class schedule history CSV with headers"""
        if not os.path.exists(self.class_history_file):
            try:
                with open(self.class_history_file, 'w', newline='') as f:
                    writer = csv.DictWriter(f, fieldnames=[
                        'timestamp', 'course_code', 'course_title', 'lecturer', 'room_name',
                        'day', 'time_slot', 'level', 'semester', 'students', 'credits',
                        'department', 'conflicts_detected', 'quality_score', 'feasibility_score'
                    ])
                    writer.writeheader()
            except Exception as e:
                print(f"Error initializing class history CSV: {e}")
    
    def _init_exam_history_csv(self):
        """Initialize exam schedule history CSV with headers"""
        if not os.path.exists(self.exam_history_file):
            try:
                with open(self.exam_history_file, 'w', newline='') as f:
                    writer = csv.DictWriter(f, fieldnames=[
                        'timestamp', 'course_code', 'course_title', 'room_name',
                        'day', 'time_slot', 'level', 'semester', 'students',
                        'conflicts_detected', 'quality_score'
                    ])
                    writer.writeheader()
            except Exception as e:
                print(f"Error initializing exam history CSV: {e}")
    
    def log_class_schedule(self, schedule: List[Dict[str, Any]], quality_metrics: Dict[str, Any] = None):
        """Log a class schedule to historical data"""
        timestamp = datetime.now().isoformat()
        
        try:
            with open(self.class_history_file, 'a', newline='') as f:
                writer = csv.DictWriter(f, fieldnames=[
                    'timestamp', 'course_code', 'course_title', 'lecturer', 'room_name',
                    'day', 'time_slot', 'level', 'semester', 'students', 'credits',
                    'department', 'conflicts_detected', 'quality_score', 'feasibility_score'
                ])
                
                for item in schedule:
                    row = {
                        'timestamp': timestamp,
                        'course_code': item.get('course_code', ''),
                        'course_title': item.get('course_title', ''),
                        'lecturer': item.get('lecturer', ''),
                        'room_name': item.get('room_name', ''),
                        'day': item.get('day', ''),
                        'time_slot': item.get('time_slot', ''),
                        'level': item.get('level', ''),
                        'semester': item.get('semester', ''),
                        'students': item.get('students', ''),
                        'credits': item.get('credits', ''),
                        'department': item.get('department', ''),
                        'conflicts_detected': quality_metrics.get('conflicts', 0) if quality_metrics else 0,
                        'quality_score': quality_metrics.get('quality_score', 0) if quality_metrics else 0,
                        'feasibility_score': quality_metrics.get('feasibility_score', 0.5) if quality_metrics else 0.5
                    }
                    writer.writerow(row)
            
            print(f"✓ Logged {len(schedule)} courses to historical data")
            return True
        
        except Exception as e:
            print(f"⚠ Error logging schedule: {e}")
            return False
    
    def log_exam_schedule(self, schedule: List[Dict[str, Any]], quality_metrics: Dict[str, Any] = None):
        """Log an exam schedule to historical data"""
        timestamp = datetime.now().isoformat()
        
        try:
            with open(self.exam_history_file, 'a', newline='') as f:
                writer = csv.DictField(f, fieldnames=[
                    'timestamp', 'course_code', 'course_title', 'room_name',
                    'day', 'time_slot', 'level', 'semester', 'students',
                    'conflicts_detected', 'quality_score'
                ])
                
                for item in schedule:
                    row = {
                        'timestamp': timestamp,
                        'course_code': item.get('course_code', ''),
                        'course_title': item.get('course_title', ''),
                        'room_name': item.get('room_name', ''),
                        'day': item.get('day', ''),
                        'time_slot': item.get('time_slot', ''),
                        'level': item.get('level', ''),
                        'semester': item.get('semester', ''),
                        'students': item.get('students', ''),
                        'conflicts_detected': quality_metrics.get('conflicts', 0) if quality_metrics else 0,
                        'quality_score': quality_metrics.get('quality_score', 0) if quality_metrics else 0
                    }
                    writer.writerow(row)
            
            print(f"✓ Logged {len(schedule)} exams to exam historical data")
            return True
        
        except Exception as e:
            print(f"⚠ Error logging exam schedule: {e}")
            return False
    
    def get_schedule_statistics(self) -> Dict[str, Any]:
        """Get statistics from historical data for AI training"""
        stats = {
            'total_class_entries': 0,
            'total_exam_entries': 0,
            'average_quality_score': 0,
            'average_feasibility_score': 0,
            'most_common_conflicts': {},
            'time_slot_distribution': {}
        }
        
        try:
            # Read class history
            if os.path.exists(self.class_history_file):
                with open(self.class_history_file, 'r') as f:
                    reader = csv.DictReader(f)
                    quality_scores = []
                    feasibility_scores = []
                    
                    for row in reader:
                        stats['total_class_entries'] += 1
                        
                        if row.get('quality_score'):
                            quality_scores.append(float(row['quality_score']))
                        if row.get('feasibility_score'):
                            feasibility_scores.append(float(row['feasibility_score']))
                        
                        # Track time slots
                        time_slot = row.get('time_slot', 'Unknown')
                        stats['time_slot_distribution'][time_slot] = stats['time_slot_distribution'].get(time_slot, 0) + 1
                    
                    if quality_scores:
                        stats['average_quality_score'] = sum(quality_scores) / len(quality_scores)
                    if feasibility_scores:
                        stats['average_feasibility_score'] = sum(feasibility_scores) / len(feasibility_scores)
            
            # Read exam history
            if os.path.exists(self.exam_history_file):
                with open(self.exam_history_file, 'r') as f:
                    reader = csv.DictReader(f)
                    for row in reader:
                        stats['total_exam_entries'] += 1
        
        except Exception as e:
            print(f"⚠ Error reading schedule statistics: {e}")
        
        return stats
