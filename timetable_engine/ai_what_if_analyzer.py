import csv
import os
import copy
from typing import List, Dict, Any, Optional
from .models import ScheduleItem, Room

class ImpactAnalyzer:
    def __init__(self, data_path: str = "."):
        self.data_path = data_path
        self.master_schedule: List[ScheduleItem] = []
        self.rooms: List[Room] = []

    def load_scenario(self, schedule_file: str, rooms_file: str = "rooms.csv"):
        """Load the baseline schedule and rooms for simulation"""
        self.master_schedule = []
        if not os.path.exists(schedule_file):
            return False

        with open(schedule_file, 'r', encoding='utf-8-sig') as f:
            reader = csv.DictReader(f)
            # Normalize headers
            h_map = {h.strip().lower(): h for h in reader.fieldnames}
            
            for row in reader:
                self.master_schedule.append(ScheduleItem(
                    course_code=row.get(h_map.get("course code") or "course_code", ""),
                    day=row.get(h_map.get("day") or "day", ""),
                    time_slot=row.get(h_map.get("time") or "time", ""),
                    room_name=row.get(h_map.get("room name") or "room", ""),
                    lecturer=row.get(h_map.get("lecturer name") or "lecturer", ""),
                    course_title=row.get(h_map.get("course title") or "course_title", ""),
                    level=int(row.get(h_map.get("course_level") or "level", 0) or 0),
                    enrollment=int(row.get(h_map.get("enrollment") or "enrollment", 40) or 40)
                ))

        # Load rooms
        rooms_path = os.path.join(self.data_path, rooms_file)
        if os.path.exists(rooms_path):
            with open(rooms_path, 'r') as f:
                reader = csv.DictReader(f)
                h_map = {h.strip().lower(): h for h in reader.fieldnames}
                for row in reader:
                    self.rooms.append(Room(
                        name=row.get(h_map.get("room_name") or h_map.get("room") or "name", ""),
                        capacity=int(row.get(h_map.get("capacity") or "capacity", "40") or "40"),
                        department=row.get(h_map.get("department") or "department")
                    ))
        return True

    def simulate_resource_loss(self, excluded_rooms: List[str] = None, excluded_buildings: List[str] = None):
        """Analyze what happens if specific rooms or buildings are unavailable"""
        excluded_rooms = excluded_rooms or []
        excluded_buildings = excluded_buildings or []
        
        impact_report = {
            "total_classes": len(self.master_schedule),
            "displaced_classes": [],
            "affected_rooms": set(),
            "remaining_capacity_by_slot": {},
            "recommendations": []
        }

        # 1. Identify Displaced Classes
        for item in self.master_schedule:
            is_displaced = False
            if item.room_name in excluded_rooms:
                is_displaced = True
            elif any(b.lower() in item.room_name.lower() for b in excluded_buildings):
                is_displaced = True
            
            if is_displaced:
                impact_report["displaced_classes"].append(item)
                impact_report["affected_rooms"].add(item.room_name)

        # 2. Analyze Remaining Capacity
        # Build map of used capacity in surviving rooms
        remaining_rooms = [r for r in self.rooms if r.name not in excluded_rooms and not any(b.lower() in r.name.lower() for b in excluded_buildings)]
        
        # 3. Find Migration Paths (Quick-Fix)
        for displaced in impact_report["displaced_classes"]:
            found_alt = False
            # Look for a room with capacity and no conflict in the same (day, slot)
            occupied_rooms = {item.room_name for item in self.master_schedule if item.day == displaced.day and item.time_slot == displaced.time_slot}
            
            for room in remaining_rooms:
                if room.name not in occupied_rooms and room.capacity >= displaced.enrollment:
                    impact_report["recommendations"].append({
                        "course": displaced.course_code,
                        "original_room": displaced.room_name,
                        "suggested_room": room.name,
                        "time": f"{displaced.day} {displaced.time_slot}",
                        "status": "Solvable"
                    })
                    found_alt = True
                    # Mark this as occupied for this simulation loop
                    occupied_rooms.add(room.name)
                    break
            
            if not found_alt:
                impact_report["recommendations"].append({
                    "course": displaced.course_code,
                    "original_room": displaced.room_name,
                    "suggested_room": "None Available",
                    "time": f"{displaced.day} {displaced.time_slot}",
                    "status": "CRITICAL CONFLICT"
                })

        impact_report["displaced_count"] = len(impact_report["displaced_classes"])
        impact_report["feasibility_score"] = round((1 - (sum(1 for r in impact_report["recommendations"] if r["status"] == "CRITICAL CONFLICT") / max(1, len(impact_report["displaced_classes"])))) * 100, 2)
        
        return impact_report
