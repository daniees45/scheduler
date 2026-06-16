from typing import List, Dict, Tuple, Optional
from enum import Enum
from dataclasses import dataclass, field
from .models import ScheduleItem, Course, Lecturer

class ConstraintSeverity(Enum):
    """Severity levels for constraint violations"""
    CRITICAL = 1      # Must never violate (hard constraint)
    HIGH = 2           # Should almost never violate
    MEDIUM = 3         # Nice to avoid
    LOW = 4             # Not important

class ConflictType(Enum):
    """Types of scheduling conflicts"""
    ROOM_DOUBLE_BOOKING = "room_conflict"
    LECTURER_DOUBLE_BOOKING = "lecturer_conflict"
    STUDENT_LEVEL_CONFLICT = "level_conflict"
    LECTURER_UNAVAILABLE = "lecturer_availability"
    FRIDAY_RESTRICTION = "friday_restriction"
    CREDIT_HOUR_MISMATCH = "credit_hour_mismatch"
    DEPARTMENT_ROOM_MISMATCH = "department_mismatch"
    INVALID_TIME_SLOT = "invalid_time_slot"
    GROUP_SEPARATION = "group_separation"
    ROOM_CAPACITY_INSUFFICIENT = "capacity_insufficient"
    BUILDING_DISTANCE = "building_distance"

@dataclass
class ConflictRecord:
    """Represents a single scheduling conflict"""
    conflict_type: ConflictType
    severity: ConstraintSeverity
    involved_schedules: List[ScheduleItem] = field(default_factory=list)
    involved_courses: List[str] = field(default_factory=list)
    involved_resources: Dict[str, any] = field(default_factory=dict)  # room, lecturer, etc.
    description: str = ""
    resolution_suggestions: List[str] = field(default_factory=list)
    can_be_relaxed: bool = False
    impact_score: float = 0.0  # 0-1, higher = worse

class ConflictDetector:
    """Detects scheduling conflicts with severity assessment"""
    
    def __init__(self, courses: List[Course], lecturers: Dict[str, Lecturer], course_groups: Dict[str, List[str]] = None):
        self.courses = courses
        self.lecturers = lecturers
        self.course_groups = course_groups or {}
        self.severity_rules = self._init_severity_rules()
        self.conflicts: List[ConflictRecord] = []
        
    def _init_severity_rules(self) -> Dict[ConflictType, ConstraintSeverity]:
        """Define severity for each conflict type"""
        return {
            ConflictType.ROOM_DOUBLE_BOOKING: ConstraintSeverity.CRITICAL,
            ConflictType.LECTURER_DOUBLE_BOOKING: ConstraintSeverity.CRITICAL,
            ConflictType.STUDENT_LEVEL_CONFLICT: ConstraintSeverity.CRITICAL,
            ConflictType.LECTURER_UNAVAILABLE: ConstraintSeverity.CRITICAL,
            ConflictType.FRIDAY_RESTRICTION: ConstraintSeverity.HIGH,
            ConflictType.CREDIT_HOUR_MISMATCH: ConstraintSeverity.HIGH,
            ConflictType.DEPARTMENT_ROOM_MISMATCH: ConstraintSeverity.MEDIUM,
            ConflictType.INVALID_TIME_SLOT: ConstraintSeverity.CRITICAL,
            ConflictType.GROUP_SEPARATION: ConstraintSeverity.MEDIUM,
            ConflictType.ROOM_CAPACITY_INSUFFICIENT: ConstraintSeverity.HIGH,
            ConflictType.BUILDING_DISTANCE: ConstraintSeverity.LOW,
        }
    
    def detect_all_conflicts(self, schedule: List[ScheduleItem], semester: str = None) -> List[ConflictRecord]:
        """Run all conflict detection checks on a schedule, including student feedback."""
        self.conflicts = []
        self.detect_room_conflicts(schedule)
        self.detect_lecturer_conflicts(schedule)
        self.detect_level_conflicts(schedule)
        self.detect_lecturer_availability_conflicts(schedule)
        self.detect_friday_restrictions(schedule)
        self.detect_credit_hour_violations(schedule)
        self.detect_department_mismatches(schedule)
        self.detect_group_separations(schedule)
        self.detect_student_feedback_conflicts(schedule, semester)
        return self.conflicts

    def detect_student_feedback_conflicts(self, schedule: List[ScheduleItem], semester: str = None) -> None:
        """Detect conflicts reported by students (from csv/general/student_clashes.csv)."""
        try:
            import sys
            import os
            sys.path.append('.')  # Ensure root import
            from student_feedback import get_student_clash_constraints
            feedback_clashes = get_student_clash_constraints(semester) if semester else []
            # Build lookup: (course, day, slot) for all scheduled items
            course_times = {}
            for item in schedule:
                course_times.setdefault(item.course_code, []).append((item.day, item.time_slot, item))
            for _, c1, c2 in feedback_clashes:
                for t1 in course_times.get(c1, []):
                    for t2 in course_times.get(c2, []):
                        if t1[0] == t2[0] and t1[1] == t2[1]:
                            conflict = ConflictRecord(
                                conflict_type=ConflictType.STUDENT_LEVEL_CONFLICT,
                                severity=ConstraintSeverity.CRITICAL,
                                involved_schedules=[t1[2], t2[2]],
                                involved_courses=[c1, c2],
                                description=f"Student-reported clash: {c1} and {c2} overlap on {t1[0]} slot {t1[1]}",
                                resolution_suggestions=[f"Move {c1} or {c2} to a different time slot"]
                            )
                            self.conflicts.append(conflict)
        except Exception:
            pass

    
def detect_room_conflicts(self, schedule: List[ScheduleItem]) -> None:
        """Detect same room double-bookings"""
        room_slots = {}
        
        for item in schedule:
            key = (item.room_name, item.day, item.time_slot)
            if key not in room_slots:
                room_slots[key] = []
            room_slots[key].append(item)
        
        for (room, day, slot), items in room_slots.items():
            if len(items) > 1:
                # Group-aware filtering: multiple courses can share a room in the same slot
                # if they belong to the same synchronization group OR have very similar titles.
                if not self._is_intentional_pairing(items):
                    conflict = ConflictRecord(
                        conflict_type=ConflictType.ROOM_DOUBLE_BOOKING,
                        severity=ConstraintSeverity.CRITICAL,
                        involved_schedules=items,
                        involved_courses=[i.course_code for i in items],
                        involved_resources={"room": room, "day": day, "time_slot": slot},
                        description=f"Room '{room}' double-booked on {day} at {slot}",
                        can_be_relaxed=False,
                        impact_score=1.0
                    )
                    self._add_suggestions(conflict)
                    self.conflicts.append(conflict)
    
def detect_lecturer_conflicts(self, schedule: List[ScheduleItem]) -> None:
        """Detect lecturer double-bookings"""
        lecturer_slots = {}
        
        for item in schedule:
            key = (item.lecturer, item.day, item.time_slot)
            if key not in lecturer_slots:
                lecturer_slots[key] = []
            lecturer_slots[key].append(item)
        
        for (lecturer, day, slot), items in lecturer_slots.items():
            if len(items) > 1:
                # Group-aware filtering: multiple courses can be assigned to the same lecturer
                # in the same slot if they belong to the same synchronization group OR similarity.
                if not self._is_intentional_pairing(items):
                    conflict = ConflictRecord(
                        conflict_type=ConflictType.LECTURER_DOUBLE_BOOKING,
                        severity=ConstraintSeverity.CRITICAL,
                        involved_schedules=items,
                        involved_courses=[i.course_code for i in items],
                        involved_resources={"lecturer": lecturer, "day": day, "time_slot": slot},
                        description=f"Lecturer '{lecturer}' assigned to multiple courses on {day} at {slot}",
                        can_be_relaxed=False,
                        impact_score=1.0
                    )
                    self._add_suggestions(conflict)
                    self.conflicts.append(conflict)
    
def detect_level_conflicts(self, schedule: List[ScheduleItem]) -> None:
        """Detect when same level/semester courses overlap"""
        level_slots = {}
        
        for item in schedule:
            course = next((c for c in self.courses if c.code == item.course_code), None)
            if not course:
                continue
            
            key = (course.level, course.semester, item.day, item.time_slot)
            if key not in level_slots:
                level_slots[key] = []
            level_slots[key].append((item, course))
        
        for (level, semester, day, slot), items in level_slots.items():
            if len(items) > 1:
                conflict = ConflictRecord(
                    conflict_type=ConflictType.STUDENT_LEVEL_CONFLICT,
                    severity=ConstraintSeverity.CRITICAL,
                    involved_schedules=[i[0] for i in items],
                    involved_courses=[i[0].course_code for i in items],
                    involved_resources={"level": level, "semester": semester, "day": day, "time_slot": slot},
                    description=f"Level {level}, Semester {semester} has {len(items)} courses on {day} at {slot}",
                    can_be_relaxed=False,
                    impact_score=1.0
                )
                self._add_suggestions(conflict)
                self.conflicts.append(conflict)
    
def detect_lecturer_availability_conflicts(self, schedule: List[ScheduleItem]) -> None:
        """Detect lecturers scheduled when unavailable"""
        for item in schedule:
            lecturer = self.lecturers.get(item.lecturer)
            if lecturer and not lecturer.availability.get(item.day, True):
                conflict = ConflictRecord(
                    conflict_type=ConflictType.LECTURER_UNAVAILABLE,
                    severity=ConstraintSeverity.CRITICAL,
                    involved_schedules=[item],
                    involved_courses=[item.course_code],
                    involved_resources={"lecturer": item.lecturer, "day": item.day},
                    description=f"Lecturer '{item.lecturer}' unavailable on {item.day}",
                    can_be_relaxed=False,
                    impact_score=1.0
                )
                self._add_suggestions(conflict)
                self.conflicts.append(conflict)
    
def detect_friday_restrictions(self, schedule: List[ScheduleItem]) -> None:
        """Detect courses on Friday afternoon"""
        friday_afternoon_slots = ["2:00pm - 4:30pm", "5:00pm - 6:00pm"]
        
        for item in schedule:
            if item.day == "Friday" and item.time_slot in friday_afternoon_slots:
                conflict = ConflictRecord(
                    conflict_type=ConflictType.FRIDAY_RESTRICTION,
                    severity=ConstraintSeverity.HIGH,
                    involved_schedules=[item],
                    involved_courses=[item.course_code],
                    involved_resources={"day": "Friday", "time_slot": item.time_slot},
                    description=f"Course scheduled on Friday afternoon ({item.time_slot})",
                    can_be_relaxed=True,
                    impact_score=0.6
                )
                self._add_suggestions(conflict)
                self.conflicts.append(conflict)
    
def detect_credit_hour_violations(self, schedule: List[ScheduleItem]) -> None:
        """Detect high-credit courses in evening slot"""
        for item in schedule:
            course = next((c for c in self.courses if c.code == item.course_code), None)
            if course and course.credits not in ["1", "NC"] and "5:00pm" in item.time_slot:
                conflict = ConflictRecord(
                    conflict_type=ConflictType.CREDIT_HOUR_MISMATCH,
                    severity=ConstraintSeverity.HIGH,
                    involved_schedules=[item],
                    involved_courses=[item.course_code],
                    involved_resources={"course": item.course_code, "credits": course.credits, "time_slot": item.time_slot},
                    description=f"Multi-credit course '{item.course_code}' in evening slot (5:00pm)",
                    can_be_relaxed=True,
                    impact_score=0.7
                )
                self._add_suggestions(conflict)
                self.conflicts.append(conflict)
    
def detect_department_mismatches(self, schedule: List[ScheduleItem], rooms_dept_map: Dict[str, str] = None) -> None:
        """Detect department course/room mismatches"""
        if not rooms_dept_map:
            return
        
        for item in schedule:
            course = next((c for c in self.courses if c.code == item.course_code), None)
            if not course or course.is_general:
                continue
            
            room_dept = rooms_dept_map.get(item.room_name)
            # Use departmental_group if available
            course_dept = getattr(course, 'departmental_group', None)
            
            if room_dept and room_dept != "General" and course_dept and course_dept != "General":
                # Normalize for comparison
                rd = room_dept.lower().replace("/", " ").replace("-", " ")
                cd = course_dept.lower().replace("/", " ").replace("-", " ")
                
                # Check if there is significant overlap
                rd_tokens = set(rd.split())
                cd_tokens = set(cd.split())
                
                if not (rd_tokens & cd_tokens):
                    conflict = ConflictRecord(
                        conflict_type=ConflictType.DEPARTMENT_ROOM_MISMATCH,
                        severity=ConstraintSeverity.MEDIUM,
                        involved_schedules=[item],
                        involved_courses=[item.course_code],
                        involved_resources={"room": item.room_name, "room_dept": room_dept, "course_dept": course_dept},
                        description=f"Course '{item.course_code}' in {room_dept} room but is {course_dept} department",
                        can_be_relaxed=True,
                        impact_score=0.5
                    )
                    self._add_suggestions(conflict)
                    self.conflicts.append(conflict)
    
def detect_group_separations(self, schedule: List[ScheduleItem]) -> None:
        """Detect grouped courses scheduled at different times"""
        group_slots = {}
        
        for item in schedule:
            code = item.course_code.split(':')[0].strip() if ":" in item.course_code else item.course_code
            course = next((c for c in self.courses if c.code == code), None)
            if not course:
                continue
            
            # Use specific group key if available, otherwise fallback to title similarity groups
            key = course.group_key or f"title_group_{item.course_title.lower()}"
            if key not in group_slots:
                group_slots[key] = []
            group_slots[key].append((item, course.code))
        
        for group_key, items in group_slots.items():
            if len(items) > 1:
                slots_set = set((i[0].day, i[0].time_slot) for i in items)
                if len(slots_set) > 1:
                    conflict = ConflictRecord(
                        conflict_type=ConflictType.GROUP_SEPARATION,
                        severity=ConstraintSeverity.MEDIUM,
                        involved_schedules=[i[0] for i in items],
                        involved_courses=[i[1] for i in items],
                        involved_resources={"group_key": group_key, "actual_slots": list(slots_set)},
                        description=f"Grouped courses separated at different times: {set(i[1] for i in items)}",
                        can_be_relaxed=True,
                        impact_score=0.4
                    )
                    self._add_suggestions(conflict)
                    self.conflicts.append(conflict)

def _is_intentional_pairing(self, items: List[ScheduleItem]) -> bool:
        """Check if multiple assignments in the same slot are an intentional pairing"""
        if len(items) <= 1:
            return True
            
        # 1. Check if all items belong to the same course_group
        group_leads = set()
        for item in items:
            code = item.course_code.split(':')[0].strip() if ":" in item.course_code else item.course_code
            group = self.course_groups.get(code)
            if group:
                group_leads.add(tuple(sorted(group)))
            else:
                group_leads.add(code)
        
        if len(group_leads) == 1:
            return True
            
        # 2. Check for numeric code match (e.g. COSC 240 and INFT 240)
        nums = set()
        for item in items:
            code = item.course_code.split(':')[0].strip() if ":" in item.course_code else item.course_code
            num = self._extract_numeric_code(code)
            if num:
                nums.add(num)
        
        if len(nums) == 1:
            return True

        # 3. Check for title similarity pairing
        # All items must have high similarity with each other
        for i in range(len(items)):
            for j in range(i + 1, len(items)):
                sim = self._calculate_title_similarity(items[i].course_title, items[j].course_title)
                if sim < 0.60: # Match solver threshold
                    return False
                    
        return True

def _extract_numeric_code(self, code: str) -> Optional[str]:
        """Extract the numeric part of a course code (e.g., 'COSC 370' -> '370')"""
        import re
        match = re.search(r'\d+', code)
        return match.group(0) if match else None

def _calculate_title_similarity(self, title1: str, title2: str) -> float:
        """Calculate weighted title similarity (Logic mirrored from CSPSolver)"""
        import re
        if not title1 or not title2: return 0.0
        
        title1_l, title2_l = title1.lower(), title2.lower()
        if title1_l == title2_l: return 1.0
        
        # Strip sections [Sec A]
        t1 = re.sub(r'\[sec [a-z0-9]+\]', '', title1_l).strip()
        t2 = re.sub(r'\[sec [a-z0-9]+\]', '', title2_l).strip()
        if t1 == t2: return 0.90 # Very similar
        
        # Token overlap
        s1, s2 = set(t1.split()), set(t2.split())
        if not s1 or not s2: return 0.0
        intersection = len(s1 & s2)
        union = len(s1 | s2)
        return intersection / union if union > 0 else 0.0
    
def _add_suggestions(self, conflict: ConflictRecord) -> None:
        """Add resolution suggestions based on conflict type"""
        suggestions = {
            ConflictType.ROOM_DOUBLE_BOOKING: [
                "Move one course to a different time slot",
                "Move one course to a different room",
                "Check if courses can be combined into one session"
            ],
            ConflictType.LECTURER_DOUBLE_BOOKING: [
                "Move one course to a different time slot",
                "Assign a different lecturer to one course",
                "Check lecturer availability data"
            ],
            ConflictType.FRIDAY_RESTRICTION: [
                "Move course to morning time slot (before 12:30pm)"
            ],
            ConflictType.CREDIT_HOUR_MISMATCH: [
                "Move course to earlier time slot",
                "Split course into multiple sessions"
            ],
            ConflictType.GROUP_SEPARATION: [
                "Reassign all courses in group to same time slot",
                "Review group key assignment"
            ]
        }
        
        conflict.resolution_suggestions = suggestions.get(conflict.conflict_type, ["Review conflict manually"])
    
def get_critical_conflicts(self) -> List[ConflictRecord]:
        """Return only critical (hard constraint) conflicts"""
        return [c for c in self.conflicts if c.severity == ConstraintSeverity.CRITICAL]
    
def get_conflicts_by_severity(self, severity: ConstraintSeverity) -> List[ConflictRecord]:
        """Filter conflicts by severity level"""
        return [c for c in self.conflicts if c.severity == severity]
    
def get_conflicts_by_type(self, conflict_type: ConflictType) -> List[ConflictRecord]:
        """Filter conflicts by type"""
        return [c for c in self.conflicts if c.conflict_type == conflict_type]
    
def calculate_overall_quality_score(self) -> float:
        """Calculate schedule quality 0-100 (100 = perfect)"""
        if not self.conflicts:
            return 100.0
        
        total_impact = sum(c.impact_score * c.severity.value for c in self.conflicts)
        # Normalize: max 15 conflicts * 1.0 impact * 4 severity = 60 max impact
        max_possible_impact = 15 * 1.0 * 4
        quality = max(0, 100 - (total_impact / max_possible_impact * 100))
        return round(quality, 2)
    
def generate_conflict_report(self) -> Dict:
        """Generate comprehensive conflict report"""
        return {
            "total_conflicts": len(self.conflicts),
            "critical_count": len(self.get_critical_conflicts()),
            "by_severity": {
                "critical": len(self.get_conflicts_by_severity(ConstraintSeverity.CRITICAL)),
                "high": len(self.get_conflicts_by_severity(ConstraintSeverity.HIGH)),
                "medium": len(self.get_conflicts_by_severity(ConstraintSeverity.MEDIUM)),
                "low": len(self.get_conflicts_by_severity(ConstraintSeverity.LOW)),
            },
            "quality_score": self.calculate_overall_quality_score(),
            "conflicts": [
                {
                    "type": c.conflict_type.value,
                    "severity": c.severity.name,
                    "description": c.description,
                    "courses": c.involved_courses,
                    "suggestions": c.resolution_suggestions,
                    "can_relax": c.can_be_relaxed
                }
                for c in self.conflicts
            ]
        }
