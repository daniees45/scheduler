import csv
import os
import re
import json
import dataclasses
from typing import List, Dict, Any, Optional, Union
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select
from sqlalchemy import delete
from sqlalchemy.orm import selectinload, joinedload
from .models import ScheduleItem, PersonalEvent, PersonalProfile, PersonalTask, ProductivityPattern
from backend.models import DBProfile, DBTask, DBEvent, DBProductivity

class EnhancedJSONEncoder(json.JSONEncoder):
    def default(self, o):
        if dataclasses.is_dataclass(o):
            return dataclasses.asdict(o)
        return super().default(o)

class PersonalScheduler:
    def __init__(self, db: AsyncSession, data_path: str = "."):
        self.db = db
        self.data_path = data_path
        self.profile: Optional[PersonalProfile] = None

    def create_profile(self, name: str, role: str, level: int = None, semester: int = None, department: str = None):
        """Initialize a user profile"""
        if level and level >= 100:
            level //= 100
            
        self.profile = PersonalProfile(
            name=name,
            role=role,
            level=level,
            semester=semester,
            department=department
        )
        return self.profile

    async def save_profile(self) -> bool:
        """Save profile to PostgreSQL"""
        if not self.profile:
            return False
        
        try:
            # Check if profile exists
            result = await self.db.execute(
                select(DBProfile)
                .options(selectinload(DBProfile.productivity))
                .filter(DBProfile.name == self.profile.name)
            )
            db_profile = result.scalars().first()
            if not db_profile:
                db_profile = DBProfile(name=self.profile.name)
                self.db.add(db_profile)
            
            # Update basic fields
            db_profile.role = self.profile.role
            db_profile.level = self.profile.level
            db_profile.semester = self.profile.semester
            db_profile.department = self.profile.department
            db_profile.tasks_completed = self.profile.tasks_completed

            # Update productivity
            if not db_profile.productivity:
                db_profile.productivity = DBProductivity()
            db_profile.productivity.slot_scores = self.profile.productivity.slot_scores
            db_profile.productivity.preferred_categories = self.profile.productivity.preferred_categories

            # Rewrite tasks (delete old, add new to ensure exact sync)
            await self.db.execute(delete(DBTask).filter(DBTask.profile_id == db_profile.id))
            for task in self.profile.tasks:
                db_task = DBTask(
                    profile_id=db_profile.id,
                    name=task.name,
                    deadline=task.deadline,
                    estimated_hours=task.estimated_hours,
                    priority=task.priority,
                    is_completed=task.is_completed,
                    tags=task.tags
                )
                self.db.add(db_task)

            # Rewrite events
            await self.db.execute(delete(DBEvent).filter(DBEvent.profile_id == db_profile.id))
            for event in self.profile.personal_events:
                db_event = DBEvent(
                    profile_id=db_profile.id,
                    name=event.name,
                    day=event.day,
                    time_slot=event.time_slot,
                    priority=event.priority,
                    is_completed=event.is_completed,
                    category=event.category,
                    location=event.location,
                    attendees=event.attendees
                )
                self.db.add(db_event)

            await self.db.commit()
            return True
        except Exception as e:
            import traceback
            traceback.print_exc()
            await self.db.rollback()
            print(f"Error saving profile to DB: {e}")
            return False

    async def load_profile(self, name: str) -> bool:
        """Load profile from PostgreSQL"""
        try:
            result = await self.db.execute(
                select(DBProfile)
                .options(
                    selectinload(DBProfile.events), 
                    selectinload(DBProfile.tasks), 
                    selectinload(DBProfile.productivity),
                    selectinload(DBProfile.enrollments).joinedload(DBEnrollment.course)
                )
                .filter(DBProfile.name == name)
            )
            db_profile = result.scalars().first()
            if not db_profile:
                return False

            events = [
                PersonalEvent(
                    name=e.name, day=e.day, time_slot=e.time_slot,
                    priority=e.priority, is_completed=e.is_completed,
                    category=e.category, location=e.location, attendees=e.attendees
                ) for e in db_profile.events
            ]
            
            tasks = [
                PersonalTask(
                    name=t.name, deadline=t.deadline, estimated_hours=t.estimated_hours,
                    priority=t.priority, is_completed=t.is_completed, tags=t.tags
                ) for t in db_profile.tasks
            ]
            
            prod_pattern = ProductivityPattern()
            if db_profile.productivity:
                prod_pattern.slot_scores = db_profile.productivity.slot_scores
                prod_pattern.preferred_categories = db_profile.productivity.preferred_categories

            self.profile = PersonalProfile(
                name=db_profile.name,
                role=db_profile.role,
                level=db_profile.level,
                semester=db_profile.semester,
                department=db_profile.department,
                tasks_completed=db_profile.tasks_completed,
                personal_events=events,
                tasks=tasks,
                productivity=prod_pattern,
                enrolled_course_codes=[en.course.code for en in db_profile.enrollments]
            )
            return True
        except Exception as e:
            print(f"Error loading profile from DB: {e}")
            return False

    def load_master_timetable(self, file_path: str) -> List[ScheduleItem]:
        """Load and filter a master timetable for the current profile"""
        if not self.profile:
            return []

        filtered_schedule = []
        if not os.path.exists(file_path):
            print(f"File not found: {file_path}")
            return []

        with open(file_path, 'r', encoding='utf-8-sig') as f:
            reader = csv.DictReader(f)
            # Normalize headers
            header_map = {h.strip().lower(): h for h in reader.fieldnames}
            
            # Identify columns
            col_code = header_map.get("course code") or "course_code"
            col_title = header_map.get("course title") or "course_title"
            col_lecturer = header_map.get("lecturer name") or header_map.get("lecturer") or header_map.get("invigilator")
            col_day = header_map.get("day")
            col_time = header_map.get("time")
            col_level = header_map.get("course_level") or header_map.get("level")
            col_room = header_map.get("room name") or header_map.get("room")
            col_cohort = header_map.get("cohorts") or header_map.get("cohort")

            for row in reader:
                matches = False
                
                # Pre-run Level and Semester detection
                row_level_val = row.get(col_level, "0")
                try:
                    row_level = int(row_level_val)
                    if row_level >= 100: row_level //= 100
                except:
                    row_level = 0
                
                row_sem_val = row.get(header_map.get("semester") or "semester", "1")
                try:
                    row_sem = int(row_sem_val)
                except:
                    row_sem = 1

                level_match = not self.profile.level or (row_level == self.profile.level)
                sem_match = not self.profile.semester or (row_sem == self.profile.semester)

                # Strict Level/Semester filtering
                if not level_match or not sem_match:
                    continue

                if self.profile.role == "Lecturer":
                    # Filter by lecturer name (partial match)
                    row_lecturer = row.get(col_lecturer, "")
                    if self.profile.name.lower() in row_lecturer.lower():
                        matches = True
                else:
                    # Student Logic: Filter by Enrolled Course Codes
                    row_code = row.get(col_code, "").strip().upper()
                    if row_code in [c.upper() for c in self.profile.enrolled_course_codes]:
                        matches = True

                if matches:
                    filtered_schedule.append(ScheduleItem(
                        course_code=row.get(col_code, ""),
                        day=row.get(col_day, ""),
                        time_slot=row.get(col_time, ""),
                        room_name=row.get(col_room, ""),
                        lecturer=row.get(col_lecturer, ""),
                        course_title=row.get(col_title or "", ""),
                        level=row_level,
                        cohort=row.get(col_cohort or "", "")
                    ))

        return filtered_schedule

    def add_personal_event(self, name: str, day: str, time_slot: str, 
                          priority: str = "Medium", category: str = "General", 
                          location: str = "", attendees: List[str] = None):
        """Add a custom event to the profile"""
        if not self.profile:
            return False
        
        event = PersonalEvent(
            name=name,
            day=day,
            time_slot=time_slot,
            priority=priority,
            category=category,
            location=location,
            attendees=attendees or []
        )
        self.profile.personal_events.append(event)
        self.save_profile()
        return True

    def add_task(self, name: str, deadline: str, estimated_hours: float = 1.0, 
                 priority: str = "Medium", tags: List[str] = None):
        """Add a task to the profile"""
        if not self.profile:
            return False
        
        task = PersonalTask(
            name=name,
            deadline=deadline,
            estimated_hours=estimated_hours,
            priority=priority,
            tags=tags or []
        )
        self.profile.tasks.append(task)
        self.save_profile()
        return True

    def toggle_task(self, task_idx: int):
        """Toggle task completion and update productivity data"""
        if not self.profile or task_idx >= len(self.profile.tasks):
            return False
        
        task = self.profile.tasks[task_idx]
        task.is_completed = not task.is_completed
        
        if task.is_completed:
            self.profile.tasks_completed += 1
            # Adaptive learning: correlate completion with current time if possible
            # Simplified version: increase scores for the "productive" period
        
        self.save_profile()
        return True

    def get_workload_analytics(self, combined_schedule: List[Any]) -> Dict[str, Any]:
        """Calculate workload metrics"""
        total_hours = 0
        days_active = set()
        
        for item in combined_schedule:
            total_hours += 3 # Placeholder estimate for a slot
            if hasattr(item, 'day'):
                days_active.add(item.day)
                
        pending_tasks = [t for t in self.profile.tasks if not t.is_completed]
        task_hours = sum(t.estimated_hours for t in pending_tasks)
        
        return {
            "total_estimated_hours": total_hours + task_hours,
            "days_per_week": len(days_active),
            "pending_tasks": len(pending_tasks),
            "intensity_score": round(((total_hours + task_hours) / 40) * 100, 2)
        }

    def generate_study_suggestions(self, filtered_schedule: List[ScheduleItem]) -> List[PersonalEvent]:
        """AI: Suggest study slots based on gaps and productivity patterns"""
        suggestions = []
        occ_by_day = {}
        for item in filtered_schedule:
            if item.day not in occ_by_day: occ_by_day[item.day] = []
            occ_by_day[item.day].append(item.time_slot)
            
        slots_config = [
            ("Morning Study", "9:00am - 12:00pm"),
            ("Afternoon Study", "2:00pm - 5:00pm")
        ]

        for day in ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday"]:
            occupied = str(occ_by_day.get(day, []))
            for name, slot in slots_config:
                if slot not in occupied:
                    # Weight by productivity score
                    score = self.profile.productivity.slot_scores.get(slot, 0.5)
                    priority = "High" if score > 0.7 else "Medium"
                    suggestions.append(PersonalEvent(name, day, slot, priority, False, "Study"))
                     
        return suggestions

    def export_to_ical(self, combined_schedule: List[Any], file_path: str, b2_service: Any = None):
        """Export the merged schedule to a basic ICS format"""
        from datetime import datetime, timedelta
        
        ics_content = [
            "BEGIN:VCALENDAR",
            "VERSION:2.0",
            "PRODID:-//PersonalScheduler//EN",
            "CALSCALE:GREGORIAN",
            "METHOD:PUBLISH"
        ]
        
        days_map = {"Monday": 0, "Tuesday": 1, "Wednesday": 2, "Thursday": 3, "Friday": 4}
        now = datetime.now()
        start_of_week = now - timedelta(days=now.weekday())
        
        for item in combined_schedule:
            ics_content.append("BEGIN:VEVENT")
            
            if hasattr(item, 'course_code'): # ScheduleItem
                summary = f"[{item.course_code}] {item.course_title}"
                description = f"Room: {item.room_name}\\nLecturer: {item.lecturer}"
            else: # PersonalEvent
                summary = f"[PERSONAL] {item.name}"
                description = f"Priority: {item.priority}\\nCategory: {item.category}\\nLocation: {getattr(item, 'location', '')}"

            day_str = getattr(item, 'day', 'Monday')
            time_slot = getattr(item, 'time_slot', '9:00am - 12:00pm')

            try:
                time_parts = time_slot.split("-")
                start_str = time_parts[0].strip().lower()
                end_str = time_parts[1].strip().lower()
                
                def parse_time(t_str):
                    t_str = t_str.replace("am", " AM").replace("pm", " PM")
                    return datetime.strptime(t_str, "%I:%M %p").time()
                
                start_time = parse_time(start_str)
                end_time = parse_time(end_str)
                event_date = start_of_week + timedelta(days=days_map.get(day_str, 0))
                
                ics_content.append(f"DTSTART:{datetime.combine(event_date, start_time).strftime('%Y%m%dT%H%M%S')}")
                ics_content.append(f"DTEND:{datetime.combine(event_date, end_time).strftime('%Y%m%dT%H%M%S')}")
            except:
                ics_content.append(f"DTSTART:{now.strftime('%Y%m%dT%H%M%S')}")
                ics_content.append(f"DTEND:{(now + timedelta(hours=1)).strftime('%Y%m%dT%H%M%S')}")

            ics_content.append(f"SUMMARY:{summary}")
            ics_content.append(f"DESCRIPTION:{description}")
            ics_content.append("END:VEVENT")
            
        ics_content.append("END:VCALENDAR")
        
        os.makedirs(os.path.dirname(file_path), exist_ok=True)
        with open(file_path, 'w') as f:
            f.write("\n".join(ics_content))
            
        if b2_service and b2_service.bucket:
            rel_path = os.path.relpath(file_path, self.data_path).replace("\\", "/")
            b2_service.upload_file(file_path, rel_path)
            
        return True
