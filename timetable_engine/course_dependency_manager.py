"""
Course Dependency Manager - Detects and manages dependencies between general and department courses
"""

import json
import os
from typing import List, Dict, Set, Tuple
from .models import Course

class CourseDependencyManager:
    """Manages dependencies between course types to prevent conflicts"""
    
    def __init__(self, base_path: str = "."):
        self.base_path = base_path
        self.general_courses = {}
        self.dept_courses = {}
        self.level_sem_groups = {}  # (level, semester) -> [courses]
        self.dependencies = {}  # tracks known conflicts
        self._load_dependencies()
    
    def _load_dependencies(self):
        """Load known dependencies from history"""
        path = os.path.join(self.base_path, "history", "conflict_patterns.json")
        if os.path.exists(path):
            try:
                with open(path, 'r') as f:
                    self.dependencies = json.load(f)
            except:
                self.dependencies = {}
    
    def register_courses(self, courses: List[Course]):
        """Register courses and organize by type and level/semester"""
        for course in courses:
            if course.is_general:
                self.general_courses[course.code] = course
            else:
                self.dept_courses[course.code] = course
            
            key = (course.level, course.semester)
            if key not in self.level_sem_groups:
                self.level_sem_groups[key] = []
            self.level_sem_groups[key].append(course)
    
    def get_general_courses_for_level_sem(self, level: int, semester: int) -> List[Course]:
        """Get all general courses for a specific level/semester"""
        key = (level, semester)
        courses = self.level_sem_groups.get(key, [])
        return [c for c in courses if c.is_general]
    
    def get_dept_courses_for_level_sem(self, level: int, semester: int) -> List[Course]:
        """Get all department courses for a specific level/semester"""
        key = (level, semester)
        courses = self.level_sem_groups.get(key, [])
        return [c for c in courses if not c.is_general]
    
    def check_scheduling_order(self) -> Tuple[bool, str]:
        """Check if general courses should be scheduled first"""
        # Count unscheduled courses by type
        general_count = len(self.general_courses)
        dept_count = len(self.dept_courses)
        
        if general_count > 0 and dept_count > 0:
            # Both types exist, recommend scheduling general first
            return True, f"Found {general_count} general and {dept_count} department courses.\nSchedule general courses first to prevent conflicts!"
        
        return False, "Only one course type found or no courses to schedule"
    
    def predict_conflicts(self, course: Course, time_slot: str, day: str) -> Tuple[List[str], float]:
        """Predict potential conflicts using historical data
        
        Returns:
            Tuple of (conflicting_courses, confidence_score)
        """
        conflicts = []
        confidence = 0.0
        
        # Check historical patterns
        conflict_key = f"{course.level}_{course.semester}"
        if conflict_key in self.dependencies:
            pattern = self.dependencies[conflict_key]
            if time_slot in pattern.get("time_slots", []):
                conflicts = pattern.get("courses", [])
                confidence = pattern.get("confidence", 0.5)
        
        return conflicts, confidence
    
    def save_conflict_history(self, conflict_data: Dict):
        """Save conflict data to history for future learning"""
        history_dir = os.path.join(self.base_path, "history")
        os.makedirs(history_dir, exist_ok=True)
        
        path = os.path.join(history_dir, "conflict_patterns.json")
        try:
            existing = {}
            if os.path.exists(path):
                with open(path, 'r') as f:
                    existing = json.load(f)
            
            existing.update(conflict_data)
            
            with open(path, 'w') as f:
                json.dump(existing, f, indent=2)
        except Exception as e:
            print(f"Warning: Could not save conflict history: {e}")
