#!/usr/bin/env python3
"""
Historical Data Builder for AI Self-Learning
Collects and organizes scheduling patterns, conflicts, and solutions for machine learning
Tracks quality metrics, conflict patterns, and system performance over time
"""

import os
import csv
import json
from datetime import datetime
from typing import Dict, List, Any

class HistoricalDataBuilder:
    """Builds and maintains historical scheduling data for AI training and learning"""
    
    def __init__(self, base_path: str = "."):
        self.base_path = base_path
        self.history_path = os.path.join(base_path, "history")
        self.timestamp = datetime.now().isoformat()
        self.schedule_id = f"schedule_{datetime.now().strftime('%Y%m%d_%H%M%S')}"
        os.makedirs(self.history_path, exist_ok=True)
    
    def record_complete_schedule(self, schedule: List[Dict], quality_score: float, 
                                conflicts: List[Dict], metrics: Dict = None) -> str:
        """Record a complete schedule instance with all metrics"""
        
        schedule_record = {
            "schedule_id": self.schedule_id,
            "timestamp": self.timestamp,
            "num_courses": len(set(item.get('course_code', '') for item in schedule)),
            "num_items": len(schedule),
            "quality_score": quality_score,
            "conflict_count": len(conflicts),
            "critical_conflicts": len([c for c in conflicts if c.get('severity') == 'CRITICAL']),
            "daily_distribution": self._count_daily_courses(schedule),
            "metrics": metrics or {}
        }
        
        # Append to schedule_instances.jsonl
        instances_file = os.path.join(self.history_path, "schedule_instances.jsonl")
        with open(instances_file, 'a') as f:
            f.write(json.dumps(schedule_record) + '\n')
        
        # Record supporting patterns
        self.record_conflict_patterns(conflicts)
        self.record_timeslot_patterns(schedule)
        self.record_lecturer_patterns(schedule)
        self.record_room_patterns(schedule)
        self.record_daily_distribution(schedule)
        
        return self.schedule_id
    
    def record_conflict_patterns(self, conflicts: List[Dict]) -> None:
        """Record frequency and patterns of different conflict types"""
        
        patterns_file = os.path.join(self.history_path, "conflict_patterns.json")
        existing_patterns = {}
        
        if os.path.exists(patterns_file):
            with open(patterns_file, 'r') as f:
                existing_patterns = json.load(f)
        
        for conflict in conflicts:
            conflict_type = conflict.get('conflict_type', 'unknown')
            severity = conflict.get('severity', 'UNKNOWN')
            key = f"{conflict_type}_{severity}"
            
            if key not in existing_patterns:
                existing_patterns[key] = {
                    "type": conflict_type,
                    "severity": severity,
                    "count": 0,
                    "last_seen": None
                }
            
            existing_patterns[key]["count"] += 1
            existing_patterns[key]["last_seen"] = self.timestamp
        
        with open(patterns_file, 'w') as f:
            json.dump(existing_patterns, f, indent=2)
    
    def record_timeslot_patterns(self, schedule: List[Dict]) -> None:
        """Record time slot utilization patterns"""
        
        timeslot_file = os.path.join(self.history_path, "timeslot_patterns.json")
        existing_patterns = {}
        
        if os.path.exists(timeslot_file):
            with open(timeslot_file, 'r') as f:
                existing_patterns = json.load(f)
        
        # Count usage
        usage = {}
        for item in schedule:
            key = f"{item.get('day', 'Mon')}_{item.get('time_slot', 'Unknown')}"
            usage[key] = usage.get(key, 0) + 1
        
        for key, count in usage.items():
            if key not in existing_patterns:
                existing_patterns[key] = {"total_uses": 0, "last_updated": None}
            existing_patterns[key]["total_uses"] += count
            existing_patterns[key]["last_updated"] = self.timestamp
        
        with open(timeslot_file, 'w') as f:
            json.dump(existing_patterns, f, indent=2)
    
    def record_lecturer_patterns(self, schedule: List[Dict]) -> None:
        """Track lecturer workload and assignment patterns"""
        
        lecturer_file = os.path.join(self.history_path, "lecturer_patterns.json")
        existing_patterns = {}
        
        if os.path.exists(lecturer_file):
            with open(lecturer_file, 'r') as f:
                existing_patterns = json.load(f)
        
        lecturer_stats = {}
        for item in schedule:
            lecturer = item.get('lecturer', 'Unknown')
            if lecturer not in lecturer_stats:
                lecturer_stats[lecturer] = {"courses": 0, "days": set()}
            lecturer_stats[lecturer]["courses"] += 1
            lecturer_stats[lecturer]["days"].add(item.get('day', 'Unknown'))
        
        for lecturer, stats in lecturer_stats.items():
            if lecturer not in existing_patterns:
                existing_patterns[lecturer] = {
                    "total_assignments": 0,
                    "unique_days_taught": 0,
                    "assignment_history": []
                }
            
            # Ensure all required keys exist
            if "total_assignments" not in existing_patterns[lecturer]:
                existing_patterns[lecturer]["total_assignments"] = 0
            if "unique_days_taught" not in existing_patterns[lecturer]:
                existing_patterns[lecturer]["unique_days_taught"] = 0
            if "assignment_history" not in existing_patterns[lecturer]:
                existing_patterns[lecturer]["assignment_history"] = []
            
            existing_patterns[lecturer]["total_assignments"] += stats["courses"]
            existing_patterns[lecturer]["unique_days_taught"] = len(stats["days"])
            existing_patterns[lecturer]["assignment_history"].append({
                "timestamp": self.timestamp,
                "courses": stats["courses"]
            })
            if len(existing_patterns[lecturer]["assignment_history"]) > 20:
                existing_patterns[lecturer]["assignment_history"] = \
                    existing_patterns[lecturer]["assignment_history"][-20:]
        
        with open(lecturer_file, 'w') as f:
            json.dump(existing_patterns, f, indent=2)
    
    def record_room_patterns(self, schedule: List[Dict]) -> None:
        """Track room utilization patterns"""
        
        room_file = os.path.join(self.history_path, "room_patterns.json")
        existing_patterns = {}
        
        if os.path.exists(room_file):
            with open(room_file, 'r') as f:
                existing_patterns = json.load(f)
        
        room_stats = {}
        for item in schedule:
            room = item.get('room_name', 'Unknown')
            if room not in room_stats:
                room_stats[room] = 0
            room_stats[room] += 1
        
        for room, count in room_stats.items():
            if room not in existing_patterns:
                existing_patterns[room] = {
                    "total_bookings": 0,
                    "usage_history": []
                }
            
            # Ensure all required keys exist
            if "total_bookings" not in existing_patterns[room]:
                existing_patterns[room]["total_bookings"] = 0
            if "usage_history" not in existing_patterns[room]:
                existing_patterns[room]["usage_history"] = []
            
            existing_patterns[room]["total_bookings"] += count
            existing_patterns[room]["usage_history"].append({
                "timestamp": self.timestamp,
                "bookings": count
            })
            if len(existing_patterns[room]["usage_history"]) > 20:
                existing_patterns[room]["usage_history"] = \
                    existing_patterns[room]["usage_history"][-20:]
        
        with open(room_file, 'w') as f:
            json.dump(existing_patterns, f, indent=2)
    
    def record_daily_distribution(self, schedule: List[Dict]) -> None:
        """Record daily distribution for load analysis"""
        
        dist_file = os.path.join(self.history_path, "daily_distribution.json")
        existing_data = {}
        
        if os.path.exists(dist_file):
            with open(dist_file, 'r') as f:
                existing_data = json.load(f)
        
        daily_dist = {"Monday": 0, "Tuesday": 0, "Wednesday": 0, "Thursday": 0, "Friday": 0}
        for item in schedule:
            day = item.get('day', 'Monday')
            if day in daily_dist:
                daily_dist[day] += 1
        
        if "distributions" not in existing_data:
            existing_data["distributions"] = []
        
        existing_data["distributions"].append({
            "timestamp": self.timestamp,
            "distribution": daily_dist
        })
        
        if len(existing_data["distributions"]) > 50:
            existing_data["distributions"] = existing_data["distributions"][-50:]
        
        with open(dist_file, 'w') as f:
            json.dump(existing_data, f, indent=2)
    
    def _count_daily_courses(self, schedule: List[Dict]) -> Dict[str, int]:
        """Count courses scheduled per day"""
        days = {"Monday": 0, "Tuesday": 0, "Wednesday": 0, "Thursday": 0, "Friday": 0}
        for item in schedule:
            day = item.get('day', 'Monday')
            if day in days:
                days[day] += 1
        return days


def record_schedule_to_history(schedule: List[Dict], quality_score: float,
                               conflicts: List[Dict], base_path: str = ".") -> str:
    """Convenience function to record a schedule to history"""
    
    builder = HistoricalDataBuilder(base_path)
    return builder.record_complete_schedule(schedule, quality_score, conflicts)


def create_historical_data():
    """Generate initial historical scheduling data for training"""
    
    history_dir = os.path.join(os.path.dirname(os.path.dirname(__file__)), 'history')
    os.makedirs(history_dir, exist_ok=True)
    
    print("Building historical training data...")
    
    # Sample schedule instances
    schedule_instances = [
        {
            "course_code": "PSYC 105",
            "title": "Introduction to Psychology",
            "day": "Monday",
            "time_slot": "7:00am - 9:30am",
            "room": "American High",
            "lecturer": "Akua K. Amponsah",
            "quality_metric": "excellent"
        },
        {
            "course_code": "GNED 125",
            "title": "Study Skills",
            "day": "Monday",
            "time_slot": "10:00am - 12:30pm",
            "room": "Baobab RM1",
            "lecturer": "Ama O. Karikari",
            "quality_metric": "good"
        },
        {
            "course_code": "ENGL 112",
            "title": "Language and Writing Skills II",
            "day": "Tuesday",
            "time_slot": "7:00am - 9:30am",
            "room": "Baobab RM3",
            "lecturer": "C. Pokuaa",
            "quality_metric": "excellent"
        },
    ]
    
    with open(os.path.join(history_dir, 'schedule_instances.jsonl'), 'w') as f:
        for instance in schedule_instances:
            f.write(json.dumps(instance) + '\n')
    
    # Conflict patterns for AI learning
    conflict_patterns = [
        {"type": "lecturer_conflict", "severity": "CRITICAL", "frequency": 0.15},
        {"type": "room_conflict", "severity": "CRITICAL", "frequency": 0.10},
        {"type": "special_room_ignore", "severity": "HIGH", "frequency": 0.18},
    ]
    
    with open(os.path.join(history_dir, 'conflict_patterns.json'), 'w') as f:
        json.dump(conflict_patterns, f, indent=2)
    
    print(f"✓ Historical data created in {history_dir}")


if __name__ == "__main__":
    create_historical_data()

