from .data_loader import DataLoader
from .models import Day, ScheduleItem, Room
import re
from typing import List, Dict, Optional, Any
from datetime import datetime

class ExamScheduler:
    def __init__(self, data_path: str):
        self.data_path = data_path
        self.loader = DataLoader(data_path)
        self.slots = ["9:00am - 12:00pm", "2:00pm - 5:00pm"]
        self.days = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday"]
        
    def generate(self, halls: List[Dict[str, Any]], dept_filter: str = None, semester_filter: int = None, specific_file: str = None, 
                 existing_schedule: List[ScheduleItem] = None, existing_course_lookup: Dict[str, Any] = None):
        """
        Generate an exam schedule with specific constraints:
        1. Courses with same code grouped
        2. No level/semester conflicts
        3. 2 slots/day, Friday morning only
        4. Hall capacity allocation
        """
        courses, _ = self.loader.load_all(dept_filter, semester_filter, specific_file=specific_file)
        
        # 1. Group courses by code (Deduplication)
        # Some courses might have sections [Sec A], we treat them as one exam
        course_groups = {}
        for c in courses:
            base_code = re.sub(r'\[Sec [A-Z0-9]+\]', '', c.code).strip()
            # Use enrollment from course if available, else fallback
            enrollment = c.enrollment if getattr(c, 'enrollment', 0) > 0 else 40
                
            if base_code not in course_groups:
                course_groups[base_code] = {
                    'code': base_code,
                    'title': c.title,
                    'level': c.level,
                    'semester': c.semester,
                    'students': enrollment,
                    'lecturer': c.lecturer
                }
            else:
                # If we find more sections, use the higher student count or sum them?
                # Usually sections are scheduled together in one exam.
                # Let's keep the highest for now or sum if it makes sense.
                # For exams, usually all students of the course meet.
                course_groups[base_code]['students'] = max(course_groups[base_code]['students'], enrollment)
        
        exam_items = list(course_groups.values())
        # Sort by level then code for structured scheduling
        exam_items.sort(key=lambda x: (x['level'], x['code']))
        
        # 2. Define valid Slots (Friday afternoon excluded)
        valid_slots = []
        for day in self.days:
            for slot in self.slots:
                # Exclude Friday afternoon (2:00pm - 5:00pm)
                if day == "Friday" and slot == "2:00pm - 5:00pm":
                    continue
                valid_slots.append((day, slot))
        
        # 3. Schedule with Constraints
        schedule = []
        # Track occupied (day, slot, level, semester) -> Only block same level/semester
        occupied_conflicts = set()
        # Track hall occupancy (day, slot, hall_name) -> remaining_capacity
        hall_occupancy = {}
        for hall in halls:
            for day, slot in valid_slots:
                hall_occupancy[(day, slot, hall['name'])] = hall['capacity']

        # Seed with existing schedule (baseline)
        if existing_schedule:
            for item in existing_schedule:
                # 1. Block Level/Semester
                info = (existing_course_lookup or {}).get(item.course_code.split(":")[0].strip())
                if info:
                    level_sem_key = (item.day, item.time_slot, info.get('level'), info.get('semester'))
                    occupied_conflicts.add(level_sem_key)
                
                # 2. Consume Hall Capacity (if room matches a hall)
                cap_key = (item.day, item.time_slot, item.room_name)
                if cap_key in hall_occupancy:
                    # For classes, we assume they take up some capacity, though usually class rooms != exam halls
                    # But if they overlap, we should decrement. 
                    # For simplicity, we assume one class takes 40 seats in a hall.
                    hall_occupancy[cap_key] = max(0, hall_occupancy[cap_key] - 40)

        unified_slots = valid_slots
        
        current_slot_idx = 0
        for exam in exam_items:
            assigned = False
            attempts = 0
            
            # Find a valid slot
            while not assigned and attempts < len(unified_slots):
                slot_idx = (current_slot_idx + attempts) % len(unified_slots)
                day, slot = unified_slots[slot_idx]
                
                # Rule: ONLY block if same level AND same semester are in the same slot
                level_sem_key = (day, slot, exam['level'], exam['semester'])
                
                # Check Level/Semester conflict
                if level_sem_key not in occupied_conflicts:
                    # Find a hall with capacity
                    # REFINEMENT: Multiple exams CAN share a hall if remaining capacity allows
                    for hall in halls:
                        cap_key = (day, slot, hall['name'])
                        if hall_occupancy[cap_key] >= exam['students']:
                            # Assign!
                            display_level = exam['level'] * 100 if exam['level'] < 10 else exam['level']
                            cohort = f"Level_{display_level}_Sem{exam['semester']}"
                            schedule.append(ScheduleItem(
                                course_code=exam['code'],
                                day=day,
                                time_slot=slot,
                                room_name=hall['name'],
                                lecturer=exam['lecturer'],
                                course_title=f"{exam['title']} (All Sections)",
                                enrollment=exam['students'],
                                level=display_level,
                                cohort=cohort
                            ))
                            # Update constraints
                            occupied_conflicts.add(level_sem_key)
                            hall_occupancy[cap_key] -= exam['students']
                            assigned = True
                            # Move current_slot_idx forward slightly to spread exams out
                            current_slot_idx = (slot_idx + 1) % len(unified_slots)
                            break
                            
                attempts += 1
            
            if not assigned:
                # Fallback: schedule anyway but mark as overlap
                schedule.append(ScheduleItem(
                    course_code=f"{exam['code']} (OVERLAP)",
                    day="TBA",
                    time_slot="TBA",
                    room_name="TBA",
                    lecturer=exam['lecturer'],
                    course_title=exam['title'],
                    enrollment=exam['students'],
                    level=exam['level'],
                    cohort=f"Level_{exam['level']}_Sem{exam['semester']}"
                ))

        return schedule
