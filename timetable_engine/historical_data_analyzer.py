"""
Historical Data Analyzer - Analyzes scheduling history to detect patterns and conflicts
"""

import json
import os
import csv
from typing import Dict, List, Tuple
from datetime import datetime

class HistoricalDataAnalyzer:
    """Analyzes historical scheduling data to learn conflict patterns"""
    
    def __init__(self, base_path: str = "."):
        self.base_path = base_path
        self.history_dir = os.path.join(base_path, "history")
        os.makedirs(self.history_dir, exist_ok=True)
        
        self.schedule_history = []
        self.conflict_history = []
        self._load_history()
    
    def _load_history(self):
        """Load historical data from history directory"""
        # Load schedule instances
        path = os.path.join(self.history_dir, "schedule_instances.jsonl")
        if os.path.exists(path):
            try:
                with open(path, 'r') as f:
                    for line in f:
                        if line.strip():
                            self.schedule_history.append(json.loads(line))
            except:
                pass
    
    def analyze_time_slot_patterns(self) -> Dict:
        """Analyze which time slots are most frequently used"""
        slot_counts = {}
        slot_by_type = {"general": {}, "department": {}}
        
        for schedule in self.schedule_history:
            for entry in schedule.get("entries", []):
                slot = entry.get("time_slot")
                is_general = entry.get("is_general", False)
                
                if slot:
                    slot_counts[slot] = slot_counts.get(slot, 0) + 1
                    type_key = "general" if is_general else "department"
                    slot_by_type[type_key][slot] = slot_by_type[type_key].get(slot, 0) + 1
        
        return {
            "overall": slot_counts,
            "by_type": slot_by_type
        }
    
    def analyze_conflict_patterns(self) -> Dict:
        """Analyze patterns in course conflicts"""
        level_sem_conflicts = {}
        time_day_conflicts = {}
        
        for schedule in self.schedule_history:
            if "conflicts" in schedule:
                for conflict in schedule["conflicts"]:
                    ls_key = f"{conflict.get('level')}_{conflict.get('semester')}"
                    level_sem_conflicts[ls_key] = level_sem_conflicts.get(ls_key, 0) + 1
                    
                    td_key = f"{conflict.get('day')}_{conflict.get('time')}"
                    time_day_conflicts[td_key] = time_day_conflicts.get(td_key, 0) + 1
        
        return {
            "level_semester": level_sem_conflicts,
            "time_day": time_day_conflicts
        }
    
    def analyze_special_room_usage(self) -> Dict:
        """Analyze special room constraints and their effectiveness"""
        special_room_data = {}
        
        for schedule in self.schedule_history:
            for entry in schedule.get("entries", []):
                if entry.get("has_special_constraint"):
                    room = entry.get("room_name")
                    if room not in special_room_data:
                        special_room_data[room] = {
                            "count": 0,
                            "conflicts": 0,
                            "success_rate": 0.0
                        }
                    
                    special_room_data[room]["count"] += 1
                    if entry.get("conflict"):
                        special_room_data[room]["conflicts"] += 1
        
        # Calculate success rates
        for room_data in special_room_data.values():
            if room_data["count"] > 0:
                room_data["success_rate"] = (
                    (room_data["count"] - room_data["conflicts"]) / 
                    room_data["count"]
                ) * 100
        
        return special_room_data
    
    def record_schedule(self, schedule_items: List[Dict], conflicts: List[Dict] = None):
        """Record a new schedule instance in history"""
        instance = {
            "timestamp": datetime.now().isoformat(),
            "entries": schedule_items,
            "conflicts": conflicts or [],
            "conflict_count": len(conflicts or [])
        }
        
        path = os.path.join(self.history_dir, "schedule_instances.jsonl")
        try:
            with open(path, 'a') as f:
                f.write(json.dumps(instance) + '\n')
        except Exception as e:
            print(f"Warning: Could not write schedule history: {e}")
    
    def get_learning_insights(self) -> Dict:
        """Get insights learned from historical data"""
        insights = {
            "time_patterns": self.analyze_time_slot_patterns(),
            "conflict_patterns": self.analyze_conflict_patterns(),
            "special_room_usage": self.analyze_special_room_usage(),
            "recommendations": []
        }
        
        # Generate recommendations
        if insights["special_room_usage"]:
            for room, data in insights["special_room_usage"].items():
                if data["success_rate"] > 90:
                    insights["recommendations"].append(
                        f"Room {room} shows high success rate ({data['success_rate']:.1f}%) - prioritize its use"
                    )
                elif data["success_rate"] < 70:
                    insights["recommendations"].append(
                        f"Room {room} shows low success rate ({data['success_rate']:.1f}%) - review constraints"
                    )
        
        return insights
    
    def save_insights(self):
        """Save learned insights to history directory"""
        insights = self.get_learning_insights()
        
        for key, data in insights.items():
            if key != "recommendations":
                path = os.path.join(self.history_dir, f"{key}.json")
                try:
                    with open(path, 'w') as f:
                        json.dump(data, f, indent=2)
                except Exception as e:
                    print(f"Warning: Could not save {key}: {e}")
