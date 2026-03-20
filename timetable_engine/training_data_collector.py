"""
Training Data Collector - Generates labeled training data for ML model
Autonomously generates schedules and collects quality metrics for model training
"""

import json
import os
import csv
from typing import List, Dict, Tuple, Optional
from datetime import datetime
import random

try:
    import numpy as np
    HAS_NUMPY = True
except ImportError:
    HAS_NUMPY = False


class TrainingDataCollector:
    """Collects and manages training data for schedule quality prediction"""
    
    def __init__(self, base_path: str = "."):
        self.base_path = base_path
        self.history_dir = os.path.join(base_path, "history")
        self.training_data_dir = os.path.join(self.history_dir, "training_data")
        os.makedirs(self.training_data_dir, exist_ok=True)
        
        self.training_data = []
        self.generated_schedule_logs = []
        self.load_existing_training_data()
    
    def load_existing_training_data(self):
        """Load previously collected training data"""
        data_file = os.path.join(self.training_data_dir, "labeled_schedules.json")
        if os.path.exists(data_file):
            try:
                with open(data_file, 'r') as f:
                    self.training_data = json.load(f)
                print(f"✓ Loaded {len(self.training_data)} existing training samples")
            except Exception as e:
                print(f"Warning: Could not load training data: {e}")
    
    def add_schedule_entry(self, schedule_item: Dict, quality_label: int, 
                          conflict_type: Optional[str] = None, 
                          details: Optional[str] = None) -> int:
        """
        Add a single schedule entry with quality label
        
        Args:
            schedule_item: The schedule entry
            quality_label: 0 = good schedule, 1 = conflict detected
            conflict_type: Type of conflict (time, room, lecturer, etc.)
            details: Additional details about the entry
        
        Returns:
            Current training data count
        """
        entry = {
            "timestamp": datetime.now().isoformat(),
            "schedule_item": schedule_item,
            "quality_label": quality_label,
            "conflict_type": conflict_type,
            "details": details
        }
        
        self.training_data.append(entry)
        return len(self.training_data)
    
    def add_schedule_batch(self, schedule_items: List[Tuple[Dict, int]], 
                          batch_name: str = "batch",
                          overall_quality: Optional[float] = None) -> Dict:
        """
        Add a batch of schedule items (from one full schedule generation)
        
        Args:
            schedule_items: List of (schedule_item, label) tuples
            batch_name: Name for this batch
            overall_quality: Overall schedule quality score (0-1)
        
        Returns:
            Batch statistics
        """
        batch = {
            "batch_name": batch_name,
            "timestamp": datetime.now().isoformat(),
            "overall_quality": overall_quality,
            "items": [],
            "label_distribution": {"good": 0, "conflict": 0}
        }
        
        for item, label in schedule_items:
            entry = {
                "schedule_item": item,
                "quality_label": label
            }
            batch["items"].append(entry)
            self.training_data.append(entry)
            
            if label == 0:
                batch["label_distribution"]["good"] += 1
            else:
                batch["label_distribution"]["conflict"] += 1
        
        batch["total_items"] = len(schedule_items)
        self.generated_schedule_logs.append(batch)
        
        return batch
    
    def generate_synthetic_training_data(self, count: int = 100) -> Dict:
        """
        Generate realistic synthetic training data based on patterns from existing schedules
        
        Args:
            count: Number of synthetic samples to generate
        
        Returns:
            Generation statistics
        """
        print(f"Generating {count} synthetic training samples...")
        
        # Common course prefixes and courses
        departments = ["COSC", "INFT", "BBIS", "MATH", "EDUC", "NURS", "BMED", "DVST"]
        lecturers = [
            "Dr. Smith", "Prof. Johnson", "Dr. Williams", "Prof. Brown",
            "Dr. Jones", "Prof. Garcia", "Dr. Miller", "Prof. Davis",
            "Dr. Rodriguez", "Prof. Martinez", "Dr. Anderson", "Prof. Taylor"
        ]
        days = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday"]
        time_slots = [
            "7:00am - 9:30am",
            "9:30am - 12:00pm",
            "12:00pm - 2:00pm",
            "2:00pm - 5:00pm",
            "5:00pm - 7:30pm"
        ]
        rooms = ["A101", "A102", "A103", "B201", "B202", "B203", "LAB1", "LAB2", "LAB3"]
        special_rooms = ["LAB1", "LAB2", "LAB3"]
        
        stats = {
            "generated": 0,
            "label_distribution": {"good": 0, "conflict": 0},
            "conflict_reasons": {}
        }
        
        for i in range(count):
            dept = random.choice(departments)
            level = random.randint(1, 4)
            semester = random.choice([1, 2])
            course_num = random.randint(100, 500)
            
            schedule_item = {
                "course_code": f"{dept} {course_num}",
                "title": f"{dept} Course {course_num}",
                "level": level,
                "semester": semester,
                "credits": str(random.choice([2.0, 3.0, 4.0])),
                "lecturer": random.choice(lecturers),
                "day": random.choice(days),
                "time_slot": random.choice(time_slots),
                "room_name": random.choice(rooms),
                "room_capacity": random.randint(30, 200),
                "enrollment": random.randint(20, 150),
                "is_special_room": random.choice(rooms) in special_rooms,
                "lecturer_availability_ratio": round(random.uniform(0.6, 1.0), 2),
                "lecturer_daily_load": round(random.uniform(0, 8), 2),
                "conflict_likelihood": round(random.uniform(0, 0.3), 2),
                "lecturer_specialty_match": round(random.uniform(0.5, 1.0), 2),
                "enrollment_stability": round(random.uniform(0.7, 1.0), 2),
                "time_gap_factor": round(random.uniform(0.5, 1.0), 2)
            }
            
            # Assign label based on heuristics
            label = 0  # Default: good
            conflict_reason = None
            
            # Conflict if room capacity < enrollment
            if schedule_item["enrollment"] > schedule_item["room_capacity"] * 0.95:
                if random.random() < 0.8:  # 80% chance
                    label = 1
                    conflict_reason = "room_overbooked"
            
            # Conflict if lecturer is too busy
            if schedule_item["lecturer_daily_load"] > 6:
                if random.random() < 0.7:
                    label = 1
                    conflict_reason = "lecturer_overloaded"
            
            # Conflict if poor time-lecturer match
            if schedule_item["lecturer_availability_ratio"] < 0.5:
                if random.random() < 0.6:
                    label = 1
                    conflict_reason = "lecturer_unavailable"
            
            # Evening classes have slight conflict tendency
            if "5:00pm" in schedule_item["time_slot"]:
                if random.random() < 0.3:
                    label = 1
                    conflict_reason = "evening_attendance_low"
            
            self.add_schedule_entry(schedule_item, label, conflict_reason)
            
            stats["generated"] += 1
            if label == 0:
                stats["label_distribution"]["good"] += 1
            else:
                stats["label_distribution"]["conflict"] += 1
                if conflict_reason:
                    stats["conflict_reasons"][conflict_reason] = \
                        stats["conflict_reasons"].get(conflict_reason, 0) + 1
        
        print(f"✓ Generated {stats['generated']} synthetic training samples")
        print(f"  Label distribution: Good={stats['label_distribution']['good']}, "
              f"Conflict={stats['label_distribution']['conflict']}")
        
        return stats
    
    def save_training_data(self) -> Dict:
        """Save training data to disk"""
        try:
            data_file = os.path.join(self.training_data_dir, "labeled_schedules.json")
            logs_file = os.path.join(self.training_data_dir, "generation_logs.json")
            
            with open(data_file, 'w') as f:
                json.dump(self.training_data, f, indent=2)
            
            with open(logs_file, 'w') as f:
                json.dump(self.generated_schedule_logs, f, indent=2)
            
            stats = {
                "total_samples": len(self.training_data),
                "total_batches": len(self.generated_schedule_logs),
                "saved_timestamp": datetime.now().isoformat(),
                "files": {
                    "training_data": data_file,
                    "logs": logs_file
                }
            }
            
            print(f"✓ Saved {len(self.training_data)} training samples to disk")
            return stats
        
        except Exception as e:
            print(f"Error saving training data: {e}")
            return {"error": str(e)}
    
    def export_to_csv(self) -> str:
        """Export training data to CSV for analysis and annotation"""
        try:
            csv_file = os.path.join(self.training_data_dir, "training_data.csv")
            
            if not self.training_data:
                print("Warning: No training data to export")
                return ""
            
            # Flatten the nested structure
            rows = []
            for entry in self.training_data:
                item = entry.get("schedule_item", {})
                row = {
                    "course_code": item.get("course_code", ""),
                    "lecturer": item.get("lecturer", ""),
                    "day": item.get("day", ""),
                    "time_slot": item.get("time_slot", ""),
                    "room_name": item.get("room_name", ""),
                    "room_capacity": item.get("room_capacity", 0),
                    "enrollment": item.get("enrollment", 0),
                    "level": item.get("level", 1),
                    "semester": item.get("semester", 1),
                    "has_conflict": entry.get("quality_label", 0),
                    "conflict_type": entry.get("conflict_type", ""),
                    "lecturer_daily_load": item.get("lecturer_daily_load", 0),
                    "is_special_room": item.get("is_special_room", False),
                    "enrollment_stability": item.get("enrollment_stability", 0.8)
                }
                rows.append(row)
            
            # Write CSV
            with open(csv_file, 'w', newline='') as f:
                writer = csv.DictWriter(f, fieldnames=rows[0].keys())
                writer.writeheader()
                writer.writerows(rows)
            
            print(f"✓ Exported training data to {csv_file}")
            return csv_file
        
        except Exception as e:
            print(f"Error exporting to CSV: {e}")
            return ""
    
    def get_statistics(self) -> Dict:
        """Get training data statistics"""
        if not self.training_data:
            return {
                "total_samples": 0,
                "status": "No training data collected yet"
            }
        
        labels = [entry.get("quality_label", 0) for entry in self.training_data]
        good_count = sum(1 for l in labels if l == 0)
        conflict_count = sum(1 for l in labels if l == 1)
        
        conflict_types = {}
        for entry in self.training_data:
            ct = entry.get("conflict_type")
            if ct:
                conflict_types[ct] = conflict_types.get(ct, 0) + 1
        
        return {
            "total_samples": len(self.training_data),
            "good_samples": good_count,
            "conflict_samples": conflict_count,
            "good_percentage": f"{good_count / len(self.training_data) * 100:.1f}%",
            "conflict_percentage": f"{conflict_count / len(self.training_data) * 100:.1f}%",
            "conflict_types": conflict_types,
            "batches": len(self.generated_schedule_logs),
            "ready_for_training": len(self.training_data) >= 50,
            "recommended_sample_size": 200,
            "current_size_rating": self._rate_size(len(self.training_data))
        }
    
    @staticmethod
    def _rate_size(count: int) -> str:
        """Rate training data size"""
        if count < 30:
            return "Insufficient (< 30 samples)"
        elif count < 50:
            return "Minimal (30-50 samples)"
        elif count < 100:
            return "Small (50-100 samples)"
        elif count < 200:
            return "Good (100-200 samples)"
        elif count < 500:
            return "Excellent (200-500 samples)"
        else:
            return "Outstanding (500+ samples)"
    
    def get_training_data_for_model(self) -> List[Tuple[Dict, int]]:
        """Get training data in format expected by model"""
        result = []
        for entry in self.training_data:
            schedule_item = entry.get("schedule_item")
            label = entry.get("quality_label", 0)
            result.append((schedule_item, label))
        
        return result
    
    def validate_data_balance(self) -> Dict:
        """Check if data has good label balance"""
        stats = self.get_statistics()
        
        good_count = stats.get("good_samples", 0)
        conflict_count = stats.get("conflict_samples", 0)
        total = stats.get("total_samples", 0)
        
        if total == 0:
            return {"status": "no_data"}
        
        # Ideal ratio is 70-30 or 80-20
        good_ratio = good_count / total
        
        recommendations = []
        
        if good_count < 50:
            recommendations.append("Generate more good (non-conflict) samples")
        
        if conflict_count < 20:
            recommendations.append("Generate more conflict samples")
        
        if good_ratio > 0.9:
            recommendations.append("Data is skewed toward good samples - add more conflicts")
        elif good_ratio < 0.5:
            recommendations.append("Data is skewed toward conflicts - add more good samples")
        
        return {
            "good_ratio": f"{good_ratio*100:.1f}%",
            "conflict_ratio": f"{(1-good_ratio)*100:.1f}%",
            "is_balanced": 0.6 <= good_ratio <= 0.85,
            "recommendations": recommendations,
            "overall_status": "Balanced" if 0.6 <= good_ratio <= 0.85 else "Needs Rebalancing"
        }
