from dataclasses import dataclass, field
from typing import List, Optional, Dict, Set
from enum import Enum

class Semester(Enum):
    ONE = 1
    TWO = 2

class Day(Enum):
    MONDAY = "Monday"
    TUESDAY = "Tuesday"
    WEDNESDAY = "Wednesday"
    THURSDAY = "Thursday"
    FRIDAY = "Friday"
    SATURDAY = "Saturday"

@dataclass(frozen=True)
class TimeSlot:
    start: str
    end: str
    is_exam: bool = False

@dataclass
class Room:
    name: str
    capacity: int
    department: Optional[str] = "General"
    is_special: bool = False
    id: Optional[str] = None # For ID-based lookups

@dataclass
class Lecturer:
    name: str
    id: Optional[str] = None
    availability: Dict[str, bool] = field(default_factory=dict) # e.g. {"Monday": True, ...}

@dataclass
class Course:
    code: str
    title: str
    level: int
    semester: int
    credits: str
    lecturer: str
    is_general: bool = False
    enrollment: int = 0
    aliases: List[str] = field(default_factory=list)
    group_key: Optional[str] = None # Courses with same key should be scheduled in same slot
    departmental_group: str = "Other" # CS, Nursing, etc.
    shared_group_id: Optional[str] = None # Force same-time scheduling
    id: Optional[str] = None # Internal unique ID

    @property
    def display_name(self):
        return f"{self.code}: {self.title}"

@dataclass
class ScheduleItem:
    course_code: str
    day: str
    time_slot: str
    room_name: str
    lecturer: str
    course_title: Optional[str] = ""
    enrollment: Optional[int] = 40
    level: Optional[int] = 0
    cohort: Optional[str] = ""

@dataclass
class PersonalEvent:
    name: str
    day: str
    time_slot: str
    priority: str = "Medium" # High, Medium, Low
    is_completed: bool = False
    category: str = "General" # Study, Meeting, Appointment, etc.
    location: Optional[str] = ""
    attendees: List[str] = field(default_factory=list)

@dataclass
class PersonalTask:
    name: str
    deadline: str # "YYYY-MM-DD"
    estimated_hours: float = 1.0
    priority: str = "Medium"
    is_completed: bool = False
    tags: List[str] = field(default_factory=list)

@dataclass
class ProductivityPattern:
    # Scores for different day/slot combinations (higher is better)
    # format: {(day, slot): score}
    slot_scores: Dict[str, float] = field(default_factory=lambda: {
        "9:00am - 12:00pm": 0.8,
        "2:00pm - 5:00pm": 0.6
    })
    preferred_categories: Dict[str, List[str]] = field(default_factory=dict) # category -> [slots]

@dataclass
class PersonalProfile:
    name: str
    role: str # "Student" or "Lecturer"
    level: Optional[int] = None
    semester: Optional[int] = None
    department: Optional[str] = None
    tasks_completed: int = 0
    personal_events: List[PersonalEvent] = field(default_factory=list)
    tasks: List[PersonalTask] = field(default_factory=list)
    productivity: ProductivityPattern = field(default_factory=ProductivityPattern)
    enrolled_course_codes: List[str] = field(default_factory=list)
