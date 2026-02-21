
from dataclasses import dataclass, field
from typing import List, Optional, Tuple, Set

TimeSlot = Tuple[int, int]  # Represented as (start_time, end_time) 

@dataclass
class Lecturer:
    id: str
    name: str
    available_time_slots: List[TimeSlot]
    
@dataclass
class Room:
    id: str
    name: str
    capacity: int
    room_type: str
    available_time_slots: List[TimeSlot]
    department: str = "General"  # "CS", "General", "Nursing", "Business", etc.



@dataclass
class Course:
    code: str
    title: str
    credit_hours: str
    required_room_type: str
    required_lessons: int
    
@dataclass
class ClassSection:
    id: str
    course_code: str
    lecturer_id: str
    section_title: str
    course_type: str  # e.g., "General", or "Departmental"
    course_level: str  # e.g., "100", "200", etc.
    requested_room: Optional[str] = None
    enrollment: int = 0
    cohorts: Set[str] = field(default_factory=set)
    fixed_day: Optional[int] = None  # 0=Monday, 1=Tuesday, ..., 4=Friday
    fixed_slot: Optional[TimeSlot] = None
    semester: Optional[str] = None # "1", "2", or None for both/unknown
    departmental_group: str = "Other" # CS, Nursing, Theology, or Other
    shared_group_id: Optional[str] = None  # Used to force same-time scheduling across departments
    owning_department: str = "General"  # Department that originally offers this course
    credit_hours: str = "3"  # Course credit hours (e.g., "1", "2", "3", "NC" for No Credit)

