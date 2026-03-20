from typing import List, Dict, Any, Optional
from .models import Course, Room, Day, ScheduleItem, Lecturer
from .conflict_detector import ConflictDetector, ConstraintSeverity
from .constraint_relaxation import ConstraintRelaxationEngine
from .soft_constraint_weights import SoftConstraintWeightingSystem
from .room_intelligence import RoomIntelligenceEngine, CourseRequirement
import re
import logging

logger = logging.getLogger("timetable_engine.csp")

class CSPSolver:
    def __init__(self, courses: List[Course], rooms: List[Room], slots: List[str], lecturers: Dict[str, Lecturer],
                 enable_conflict_detection: bool = True, enable_room_intelligence: bool = True,
                 soft_constraint_weights: SoftConstraintWeightingSystem = None, base_path: str = ".", 
                 enable_department_blocking: bool = True, enable_random_slots: bool = True,
                 availability_mode: str = "automatic", course_groups: Optional[Dict[str, List[str]]] = None,
                 strict_departmental: bool = True):
        self.courses = courses
        self.rooms = rooms
        self.slots = slots
        self.lecturers = lecturers
        self.days = [Day.MONDAY, Day.TUESDAY, Day.WEDNESDAY, Day.THURSDAY, Day.FRIDAY]
        self.schedule = []
        self.base_path = base_path
        
        # Course grouping (same courses with different codes)
        self.course_groups = course_groups or {}
        
        # Availability management mode
        self.availability_mode = availability_mode  # "automatic" or "manual"
        
        # Department blocking and randomization features
        self.enable_department_blocking = enable_department_blocking
        self.enable_random_slots = enable_random_slots
        self.strict_departmental = strict_departmental
        
        # Split slots for department/general blocking (first half for general, second for dept)
        self.general_slots = self.slots[:len(self.slots)//2] if enable_department_blocking else self.slots
        self.dept_slots = self.slots[len(self.slots)//2:] if enable_department_blocking else self.slots
        
        # Load special room constraints
        self.special_room_constraints = self._load_special_rooms()
        
        # Enhanced features
        self.enable_conflict_detection = enable_conflict_detection
        self.conflict_detector = ConflictDetector(courses, lecturers, self.course_groups) if enable_conflict_detection else None
        self.relaxation_engine = ConstraintRelaxationEngine(courses, rooms, slots, lecturers, [])
        
        self.enable_room_intelligence = enable_room_intelligence
        self.room_engine = RoomIntelligenceEngine() if enable_room_intelligence else None
        self._init_room_engine()
        
        self.soft_weights = soft_constraint_weights or SoftConstraintWeightingSystem()
        self.solve_attempts = 0
        self.max_attempts = 3
    
    def _normalize_fixed_time(self, fixed_time: Optional[str]) -> Optional[str]:
        """Normalize fixed_time to match a full slot string"""
        if not fixed_time:
            return None

        fixed_time = fixed_time.strip()
        if "-" in fixed_time:
            return fixed_time

        # Match slot by start time (e.g., "5:00pm" -> "5:00pm - 6:00pm")
        for slot in self.slots:
            if slot.lower().startswith(fixed_time.lower()):
                return slot

        return fixed_time

    def _load_special_rooms(self) -> Dict[str, Dict]:
        """Load special room constraints from special_rooms.csv"""
        import csv
        import os
        
        special_rooms = {}
        path = os.path.join(self.base_path, "special_rooms.csv")
        if not os.path.exists(path):
             path = "temp/csv/general/special_rooms.csv"
             
        if os.path.exists(path):
            try:
                with open(path, 'r') as f:
                    reader = csv.DictReader(f)
                    for row in reader:
                        course_code = (row.get('course_code') or "").split(":")[0].strip()
                        room_name = (row.get('room_name') or "").strip()
                        if course_code and room_name:
                            special_rooms[course_code] = {
                                'room_name': room_name,
                                'fixed_day': (row.get('fixed_day') or "").strip(),
                                'fixed_time': self._normalize_fixed_time(row.get('fixed_time'))
                            }
            except:
                pass  # Ignore parse errors
        
        return special_rooms
    
    def check_and_handle_availability(self):
        """Check lecturer availability and handle based on mode"""
        insufficient_lecturers = []
        
        # Check each lecturer's availability
        for lecturer_name, lecturer in self.lecturers.items():
            if lecturer and hasattr(lecturer, 'availability'):
                available_days = sum(1 for day in self.days if lecturer.availability.get(day.value, True))
                
                # Need at least 2-3 days for reasonable scheduling
                if available_days < 2:
                    insufficient_lecturers.append({
                        'name': lecturer_name,
                        'available_days': available_days,
                        'lecturer': lecturer
                    })
        
        if not insufficient_lecturers:
            return  # All lecturers have sufficient availability
        
        # Handle based on mode
        if self.availability_mode == "automatic":
            print(f"\n[Availability Management] Auto-expanding availability for {len(insufficient_lecturers)} lecturer(s)...")
            for info in insufficient_lecturers:
                lecturer = info['lecturer']
                # Expand availability to all days
                for day in self.days:
                    lecturer.availability[day.value] = True
                print(f"  • {info['name']}: Availability expanded to all days")
        
        elif self.availability_mode == "manual":
            print(f"\n[Availability Management] {len(insufficient_lecturers)} lecturer(s) have insufficient availability")
            for info in insufficient_lecturers:
                lecturer_name = info['name']
                available_days = info['available_days']
                lecturer = info['lecturer']
                
                print(f"\n  Lecturer: {lecturer_name}")
                print(f"  Available days: {available_days}/5")
                
                response = input(f"  Expand availability for {lecturer_name}? (y/n, default y): ").strip().lower()
                
                if response != 'n':
                    # Expand availability to all days
                    for day in self.days:
                        lecturer.availability[day.value] = True
                    print(f"  ✓ Availability expanded to all days")
                else:
                    print(f"  Note: Scheduler will try alternatives if possible")

    def _load_special_rooms(self) -> Dict[str, Dict]:
        """Load special room constraints from special_rooms.csv"""
        import csv
        import os
        
        special_rooms = {}
        path = os.path.join(self.base_path, "special_rooms.csv")
        if not os.path.exists(path):
             path = "temp/csv/general/special_rooms.csv"

        if os.path.exists(path):
            try:
                with open(path, 'r') as f:
                    reader = csv.DictReader(f)
                    for row in reader:
                        course_code = (row.get('course_code') or "").split(":")[0].strip()
                        room_name = (row.get('room_name') or "").strip()
                        if course_code and room_name:
                            special_rooms[course_code] = {
                                'room_name': room_name,
                                'fixed_day': (row.get('fixed_day') or "").strip(),
                                'fixed_time': self._normalize_fixed_time(row.get('fixed_time'))
                            }
            except:
                pass  # Ignore parse errors
        
        return special_rooms

    def _get_course_info(self, course_code: str):
        """Get course info from current courses or existing schedule lookup"""
        code_key = course_code.split(":")[0].strip()
        course = next((c for c in self.courses if c.code == code_key), None)
        if course:
            return {
                "level": course.level,
                "semester": course.semester,
                "is_general": course.is_general
            }
        if getattr(self, "existing_course_lookup", None):
            return self.existing_course_lookup.get(code_key)
        return None

    def is_consistent(self, course: Course, day: Day, slot: str, room: Room, current_schedule: List[ScheduleItem]):
        
        # Rule: Lecturer Availability
        lecturer = self.lecturers.get(course.lecturer)
        if lecturer and not lecturer.availability.get(day.value, True):
            logger.debug(f"Reject {course.code} in {room.name}: Lecturer {course.lecturer} not available on {day.value}")
            return False

        # Rule: No room conflict
        for item in current_schedule:
            if item.day == day.value and item.time_slot == slot and item.room_name == room.name:
                logger.debug(f"Reject {course.code} in {room.name}: Room already occupied by {item.course_code}")
                return False
            
        # Rule: No lecturer conflict
        for item in current_schedule:
            if item.day == day.value and item.time_slot == slot and item.lecturer == course.lecturer:
                logger.debug(f"Reject {course.code} in {room.name}: Lecturer {course.lecturer} already teaching {item.course_code}")
                return False
        
        # Rule: No level/semester conflict
        for item in current_schedule:
            scheduled_info = self._get_course_info(item.course_code)
            if scheduled_info and scheduled_info["level"] == course.level and \
               scheduled_info["semester"] == course.semester and \
               item.day == day.value and item.time_slot == slot:
                logger.debug(f"Reject {course.code} in {room.name}: Conflict with {item.course_code} (Same Level/Sem)")
                return False
        
        # Rule: Department room enforcement
        if not course.is_general and room.department:
            course_dept = getattr(course, 'departmental_group', "General")
            room_dept = room.department
            
            if room_dept != "General" and course_dept != "General":
                # Normalize for comparison
                rd = room_dept.lower().replace("/", " ").replace("-", " ")
                cd = course_dept.lower().replace("/", " ").replace("-", " ")
                
                # Check if there is significant overlap
                rd_tokens = set(rd.split())
                cd_tokens = set(cd.split())
                
                # If they are different departments, discourage/reject unless it's the only option
                # For CSP, we are strict unless relaxation is enabled
                if not (rd_tokens & cd_tokens):
                    # Special Case: Allow CS/IT to share rooms if one is Computing Science (only if not strict)
                    if not self.strict_departmental:
                        if "computing" in rd or "cs" in rd:
                            if any(t in cd for t in ["it", "bbis", "cs"]):
                                return True
                    
                    logger.debug(f"Reject {course.code} in {room.name}: Department mismatch ({course_dept} vs {room_dept})")
                    return False
                
                # If strict mode, even "General" rooms are rejected for departmental courses
                if self.strict_departmental and room_dept == "general" and course_dept != "general":
                    logger.debug(f"Reject {course.code} in {room.name}: Strict mode restricts to departmental rooms only")
                    return False
        
        # Rule: Friday restriction (morning only)
        if day == Day.FRIDAY:
            if slot not in self.slots[:2]:
                logger.debug(f"Reject {course.code} in {room.name}: Friday afternoon restricted")
                return False

        return True

    def solve(
        self,
        existing_schedule: Optional[List[ScheduleItem]] = None,
        existing_course_lookup: Optional[Dict[str, Dict[str, Any]]] = None
    ):
        logger.info(f"Solving CSP for {len(self.courses)} courses and {len(self.rooms)} rooms")
        
    def _init_room_engine(self):
        """Initialize room intelligence engine with rooms"""
        if not self.room_engine:
            return
        
        for room in self.rooms:
            from .room_intelligence import RoomProfile, RoomType
            profile = RoomProfile(
                name=room.name,
                capacity=room.capacity,
                department=room.department,
                room_type=RoomType.LECTURE_HALL
            )
            self.room_engine.add_room(profile)

    def _get_available_slots_for_course(self, course: Course, day: Day, current_schedule: List[ScheduleItem]):
        """Get available time slots for a course on a given day"""
        import random
        
        # Determine which slots to use based on department blocking
        if self.enable_department_blocking:
            preferred = self.general_slots if course.is_general else self.dept_slots
            other = self.dept_slots if course.is_general else self.general_slots
            candidate_slots = preferred + [slot for slot in other if slot not in preferred]
        else:
            candidate_slots = self.slots
        
        # Filter out slots with conflicts
        available = []
        for slot in candidate_slots:
            has_conflict = False
            
            for item in current_schedule:
                if item.day == day.value and item.time_slot == slot:
                    # Check lecturer conflict
                    if item.lecturer == course.lecturer:
                        has_conflict = True
                        break
                    
                    # Check level/semester conflict with opposite type (general vs dept)
                    if self.enable_department_blocking:
                        scheduled_info = self._get_course_info(item.course_code)
                        if scheduled_info and scheduled_info["level"] == course.level and \
                           scheduled_info["semester"] == course.semester and \
                           scheduled_info["is_general"] != course.is_general:
                            # Conflict: same level/semester but different type (gen vs dept)
                            has_conflict = True
                            break
            
            if not has_conflict:
                available.append(slot)
        
        # Randomize slot selection if enabled
        if self.enable_random_slots and available:
            random.shuffle(available)
        
        return available

    def _find_group_schedule(self, course: Course) -> Optional[ScheduleItem]:
        """Find if any course in the same group or with same shared_group_id is already scheduled."""
        
        # 1. Check shared_group_id (Forced sync)
        if hasattr(course, 'shared_group_id') and course.shared_group_id:
            for item in self.schedule:
                # We need to find the original course object to check its shared_group_id
                # or hope it was stored in the item (engine ScheduleItem doesn't store objects)
                # Let's use display_name or lookup
                info = self._get_course_info(item.course_code)
                # Since engine ScheduleItem doesn't have shared_group_id, we need a lookup
                # Let's assume courses is the full list
                for c in self.courses:
                    if (c.code == item.course_code or c.display_name == item.course_code) and \
                       getattr(c, 'shared_group_id', None) == course.shared_group_id:
                        return item

        # 2. Check title similarity groups
        if course.code not in self.course_groups:
            return None
        
        group = self.course_groups[course.code]
        for scheduled_item in self.schedule:
            for group_member in group:
                if scheduled_item.course_code == group_member or scheduled_item.course_code.startswith(group_member):
                    return scheduled_item
        
        return None

    def _calculate_title_similarity(self, title1: str, title2: str) -> float:
        """Calculate similarity between two course titles using weighted scoring.
        Returns a similarity score between 0.0 and 1.0.
        
        Scoring criteria (reaching 0.80+ triggers merge):
        - Section match [Sec A] = 0.30
        - Token overlap (Jaccard) = 0.50
        - Course level similarity (both programming, both security, etc.) = 0.20
        
        IMPORTANT: Courses with "Introduction to" or "Intro to" will NOT auto-merge
        unless explicitly listed in same_courses.csv
        """
        import re
        
        # Check for "Introduction to" or "Intro to" pattern
        # These courses should NOT auto-merge (return 0.0 similarity)
        intro_pattern = r'\b(introduction\s+to|intro\s+to)\b'
        title1_lower = title1.lower()
        title2_lower = title2.lower()
        
        has_intro1 = bool(re.search(intro_pattern, title1_lower))
        has_intro2 = bool(re.search(intro_pattern, title2_lower))
        
        # If one has "intro to" and the other doesn't, they're different course levels
        if has_intro1 != has_intro2:
            return 0.0
        
        # If both have "intro to", only merge if titles are nearly identical
        # (this prevents "Intro to X" from merging with "Intro to Y")
        if has_intro1 and has_intro2:
            # Remove "introduction to" / "intro to" and compare the rest
            clean1 = re.sub(intro_pattern, '', title1_lower).strip()
            clean2 = re.sub(intro_pattern, '', title2_lower).strip()
            
            # Only merge if the remainder is very similar (85%+ match)
            if clean1 != clean2:
                # Strip sections and compare
                clean1 = re.sub(r'\[sec [a-z0-9]+\]', '', clean1).strip()
                clean2 = re.sub(r'\[sec [a-z0-9]+\]', '', clean2).strip()
                
                if clean1 != clean2:
                    return 0.0  # Don't merge different "Intro to X" vs "Intro to Y"
        
        score = 0.0
        
        # 1. Check for same section marker (30% weight)
        section_pattern = r'\[Sec ([A-Z0-9]+)\]'
        sec1 = re.search(section_pattern, title1)
        sec2 = re.search(section_pattern, title2)
        
        # CRITICAL: If sections are different, they are different classes. Never merge.
        if sec1 and sec2 and sec1.group(1) != sec2.group(1):
            return 0.0
            
        if sec1 and sec2 and sec1.group(1) == sec2.group(1):
            score += 0.30
        
        # 2. Normalize titles and calculate token overlap (50% weight)
        def normalize(title):
            # Remove section markers like [Sec A], [Sec B]
            title = re.sub(r'\[Sec [A-Z0-9]+\]', '', title)
            # Convert to lowercase
            title = title.lower()
            # Remove punctuation and special chars
            title = re.sub(r'[^\w\s]', ' ', title)
            # Split into tokens and filter out common words
            stopwords = {'and', 'or', 'the', 'a', 'an', 'in', 'to', 'for', 'of', 'with', 'i', 'ii', 'iii', 'iv', 'introduction', 'intro'}
            tokens = [t.strip() for t in title.split() if t.strip() and t.strip() not in stopwords]
            return set(tokens)
        
        tokens1 = normalize(title1)
        tokens2 = normalize(title2)
        
        if tokens1 and tokens2:
            # Jaccard similarity for token overlap
            intersection = len(tokens1 & tokens2)
            union = len(tokens1 | tokens2)
            token_similarity = intersection / union if union > 0 else 0.0
            score += token_similarity * 0.50
        
        # 3. Check for domain similarity (20% weight)
        # Look for common domain keywords
        domain_keywords = {
            'programming': ['programming', 'software', 'coding', 'development'],
            'network': ['network', 'networking', 'communication', 'data'],
            'security': ['security', 'cyber', 'forensics', 'encryption'],
            'database': ['database', 'sql', 'data', 'storage'],
            'web': ['web', 'internet', 'online', 'html'],
            'systems': ['systems', 'operating', 'administration'],
            'mathematics': ['math', 'calculus', 'algebra', 'statistics'],
        }
        
        def get_domain(title):
            title_lower = title.lower()
            for domain, keywords in domain_keywords.items():
                for keyword in keywords:
                    if keyword in title_lower:
                        return domain
            return None
        
        domain1 = get_domain(title1)
        domain2 = get_domain(title2)
        
        if domain1 and domain2 and domain1 == domain2:
            score += 0.20
        
        return score

    def _extract_numeric_code(self, code: str) -> Optional[str]:
        """Extract the numeric part of a course code (e.g., 'COSC 370' -> '370')"""
        match = re.search(r'\d+', code)
        return match.group(0) if match else None

    def _find_compatible_course_schedule(self, course: Course, similarity_threshold: float = 0.60) -> Optional[ScheduleItem]:
        """Find a compatible course that is already scheduled with the same lecturer.
        Returns the ScheduleItem if found, or None.
        
        Compatibility criteria:
        1. Explicitly grouped in same_courses.csv (Handled in solve loop)
        2. Numeric code match AND same lecturer (e.g. COSC 240 and INFT 240)
        3. Title similarity >= threshold AND same lecturer
        
        All pairings require SAME LECTURER and SAME SEMESTER.
        """

        section_pattern = r'\[Sec ([A-Z0-9]+)\]'
        course_section = re.search(section_pattern, course.title)
        course_num = self._extract_numeric_code(course.code)

        for scheduled_item in self.schedule:
            # 1. Basic checks
            if scheduled_item.lecturer != course.lecturer:
                continue
                
            scheduled_code = scheduled_item.course_code.split(":")[0].strip() if ":" in scheduled_item.course_code else scheduled_item.course_code
            scheduled_course = next((c for c in self.courses if c.code == scheduled_code or c.display_name == scheduled_item.course_code), None)
            
            if not scheduled_course:
                continue
            
            if scheduled_course.semester != course.semester:
                continue

            # 2. Section check
            scheduled_section = re.search(section_pattern, scheduled_course.title)
            if (course_section and scheduled_section and course_section.group(1) != scheduled_section.group(1)) or \
               (course_section and not scheduled_section) or (scheduled_section and not course_section):
                continue

            # 3. Numeric code match (Smart pairing)
            scheduled_num = self._extract_numeric_code(scheduled_code)
            if course_num and scheduled_num and course_num == scheduled_num:
                return scheduled_item

            # 4. Title similarity match (Smart pairing)
            similarity = self._calculate_title_similarity(course.title, scheduled_course.title)
            if similarity >= similarity_threshold:
                return scheduled_item
                
        return None

    def solve(
        self,
        existing_schedule: Optional[List[ScheduleItem]] = None,
        existing_course_lookup: Optional[Dict[str, Dict[str, Any]]] = None
    ):
        # Identify exclusive rooms (only in special_rooms.csv, not in main rooms list)
        exclusive_rooms = set()
        all_room_names = set(r.name for r in self.rooms)
        for course_code, constraint in self.special_room_constraints.items():
            room_name = constraint.get('room_name')
            if room_name and room_name not in all_room_names:
                exclusive_rooms.add(room_name)
        # Check and handle lecturer availability before solving
        self.check_and_handle_availability()
        
        # Track auto-merged courses (for statistics)
        self.auto_merged_courses = []
        
        # Track lecturer assignments to prevent double-booking
        lecturer_assignments = {}
        
        # Track room usage to ensure all department rooms are utilized
        room_usage_count = {room.name: 0 for room in self.rooms}
        
        day_load_balance = {day.value: 0 for day in self.days}

        # Seed schedule with existing items (e.g., general courses)
        if existing_schedule:
            self.existing_course_lookup = existing_course_lookup or {}
            self.schedule = list(existing_schedule)
            for item in existing_schedule:
                slot_key = (item.day, item.time_slot)
                if slot_key not in lecturer_assignments:
                    lecturer_assignments[slot_key] = []
                lecturer_assignments[slot_key].append(item.lecturer)
                if item.day in day_load_balance:
                    day_load_balance[item.day] += 1
        
        # Sort courses: prioritize those with special room constraints
        all_courses = sorted(self.courses, key=lambda c: c.code in self.special_room_constraints, reverse=True)
        
        # Try to schedule each course individually
        scheduled_keys = set()
        for course in all_courses:
            # Use course code + section marker as unique key
            section_pattern = r'\[Sec ([A-Z0-9]+)\]'
            section_match = re.search(section_pattern, course.title)
            section = section_match.group(1) if section_match else ""
            unique_key = f"{course.code.strip()}__{section}"
            # Check if this unique course+section is already scheduled
            if unique_key in scheduled_keys:
                continue  # Already scheduled

            # Prevent duplicate course+section assignments in the schedule
            if any((item.course_code.strip().split(":")[0] + "__" + (re.search(section_pattern, getattr(item, 'course_code', '')) or [None,""])[1]) == unique_key for item in self.schedule):
                continue
            
            # Check if this course is part of a group and if a group member is already scheduled
            group_schedule = self._find_group_schedule(course)
            if group_schedule:
                # Assign to the same day/time/room as the group
                self.schedule.append(ScheduleItem(
                    course_code=course.display_name,
                    day=group_schedule.day,
                    time_slot=group_schedule.time_slot,
                    room_name=group_schedule.room_name,
                    lecturer=course.lecturer,
                    course_title=course.title,
                    level=course.level
                ))
                
                # Track room usage
                if group_schedule.room_name in room_usage_count:
                    room_usage_count[group_schedule.room_name] += 1
                
                # Update lecturer assignments
                slot_key = (group_schedule.day, group_schedule.time_slot)
                if slot_key not in lecturer_assignments:
                    lecturer_assignments[slot_key] = []
                lecturer_assignments[slot_key].append(course.lecturer)
                day_load_balance[group_schedule.day] += 1
                
                # Mark as scheduled
                scheduled_keys.add(unique_key)
                continue
            
            # Check for compatible courses (80%+ title similarity, same lecturer, same semester)
            compatible_schedule = self._find_compatible_course_schedule(course)
            if compatible_schedule:
                # Merge: assign to the same day/time/room as the compatible course
                self.schedule.append(ScheduleItem(
                    course_code=course.display_name,
                    day=compatible_schedule.day,
                    time_slot=compatible_schedule.time_slot,
                    room_name=compatible_schedule.room_name,
                    lecturer=course.lecturer,
                    course_title=course.title,
                    level=course.level
                ))
                
                # Track room usage
                if compatible_schedule.room_name in room_usage_count:
                    room_usage_count[compatible_schedule.room_name] += 1
                
                # Track for statistics
                self.auto_merged_courses.append({
                    'course': course.display_name,
                    'merged_with': compatible_schedule.course_code,
                    'lecturer': course.lecturer
                })
                
                # Update lecturer assignments
                slot_key = (compatible_schedule.day, compatible_schedule.time_slot)
                if slot_key not in lecturer_assignments:
                    lecturer_assignments[slot_key] = []
                lecturer_assignments[slot_key].append(course.lecturer)
                day_load_balance[compatible_schedule.day] += 1
                
                # Mark as scheduled
                scheduled_keys.add(unique_key)
                continue
            
            # Check for special room constraint
            course_key = course.code.split(":")[0].strip()
            special_constraint = self.special_room_constraints.get(course_key)
            if special_constraint:
                fixed_day = special_constraint.get('fixed_day')
                fixed_time = special_constraint.get('fixed_time')
                fixed_room_name = special_constraint.get('room_name')
                day_obj = None
                for d in self.days:
                    if d.value == fixed_day:
                        day_obj = d
                        break
                if day_obj and fixed_time and fixed_room_name:
                    # If room is exclusive, only assign to this course
                    if fixed_room_name in exclusive_rooms:
                        self.schedule.append(ScheduleItem(
                            course_code=course.display_name,
                            day=day_obj.value,
                            time_slot=fixed_time,
                            room_name=fixed_room_name,
                            lecturer=course.lecturer,
                            course_title=course.title,
                            level=course.level
                        ))
                        # Track room usage
                        if fixed_room_name in room_usage_count:
                            room_usage_count[fixed_room_name] += 1
                        slot_key = (day_obj.value, fixed_time)
                        if slot_key not in lecturer_assignments:
                            lecturer_assignments[slot_key] = []
                        lecturer_assignments[slot_key].append(course.lecturer)
                        day_load_balance[day_obj.value] += 1
                        
                        # Mark as scheduled
                        scheduled_keys.add(unique_key)
                        continue
                    # If room is not exclusive, assign as normal (can be used by other courses)
                    room = next((r for r in self.rooms if r.name == fixed_room_name), None)
                    if room and self.is_consistent(course, day_obj, fixed_time, room, self.schedule):
                        self.schedule.append(ScheduleItem(
                            course_code=course.display_name,
                            day=day_obj.value,
                            time_slot=fixed_time,
                            room_name=room.name,
                            lecturer=course.lecturer,
                            course_title=course.title,
                            level=course.level
                        ))
                        if room.name in room_usage_count:
                            room_usage_count[room.name] += 1
                        slot_key = (day_obj.value, fixed_time)
                        if slot_key not in lecturer_assignments:
                            lecturer_assignments[slot_key] = []
                        lecturer_assignments[slot_key].append(course.lecturer)
                        day_load_balance[day_obj.value] += 1
                        
                        # Mark as scheduled
                        scheduled_keys.add(unique_key)
                        continue
            
            # Regular scheduling with load balancing across days
            scheduled = False
            
            # Get least loaded days first (for better distribution)
            sorted_days = sorted(self.days, key=lambda d: day_load_balance.get(d.value, 0))
            
            for day in sorted_days:
                if scheduled:
                    break
                
                # Get available slots for this course (respects department blocking)
                available_slots = self._get_available_slots_for_course(course, day, self.schedule)
                    
                for slot in available_slots:
                    # Check lecturer availability
                    lecturer = self.lecturers.get(course.lecturer)
                    if lecturer and not lecturer.availability.get(day.value, True):
                        continue
                    
                    # Credit hour logic
                    if "5:00pm" in slot and course.credits not in ["1", "NC"]:
                        continue
                    
                    # Check if lecturer is already assigned to this slot
                    slot_key = (day.value, slot)
                    if course.lecturer in lecturer_assignments.get(slot_key, []):
                        continue
                    
                    # Find an available room - prioritize less-used rooms for better distribution
                    sorted_rooms = sorted(self.rooms, key=lambda r: room_usage_count.get(r.name, 0))
                    
                    for room in sorted_rooms:
                        if self.is_consistent(course, day, slot, room, self.schedule):
                            # Assign course
                            self.schedule.append(ScheduleItem(
                                course_code=course.display_name,
                                day=day.value,
                                time_slot=slot,
                                room_name=room.name,
                                lecturer=course.lecturer,
                                course_title=course.title,
                                level=course.level
                            ))
                            
                            # Track room usage
                            if room.name in room_usage_count:
                                room_usage_count[room.name] += 1
                            
                            if slot_key not in lecturer_assignments:
                                lecturer_assignments[slot_key] = []
                            lecturer_assignments[slot_key].append(course.lecturer)
                            day_load_balance[day.value] += 1
                            
                            # Mark as scheduled
                            scheduled_keys.add(unique_key)
                            scheduled = True
                            break
                    
                    if scheduled:
                        break
        
        # Log auto-merge statistics if any courses were merged
        if hasattr(self, 'auto_merged_courses') and self.auto_merged_courses:
            logger.info(f"Auto-merged {len(self.auto_merged_courses)} compatible courses (60%+ similarity, same lecturer):")
            for merge_info in self.auto_merged_courses:
                logger.info(f"  • {merge_info['course']} → merged with {merge_info['merged_with']} (Lecturer: {merge_info['lecturer']})")
        
        # Log room utilization statistics
        if room_usage_count:
            used_rooms = sum(1 for count in room_usage_count.values() if count > 0)
            total_rooms = len(room_usage_count)
            total_assignments = sum(room_usage_count.values())
            
            logger.info(f"Room Utilization: {used_rooms}/{total_rooms} rooms used ({used_rooms/total_rooms*100:.1f}%)")
            if total_assignments > 0:
                # Show rooms with their usage count
                sorted_usage = sorted(room_usage_count.items(), key=lambda x: x[1], reverse=True)
                logger.info(f"  Total room-slot assignments: {total_assignments}")
                
                # Show top utilized rooms
                used_room_list = [(name, count) for name, count in sorted_usage if count > 0]
                if len(used_room_list) <= 10:
                    # Show all if 10 or fewer
                    for name, count in used_room_list:
                        logger.info(f"    {name}: {count} assignments")
                else:
                    # Show top 5 and bottom 5
                    logger.info(f"  Top 5 most used rooms:")
                    for name, count in used_room_list[:5]:
                        logger.info(f"    {name}: {count} assignments")
                    
                    # Check for unused rooms
                    unused_rooms = [name for name, count in sorted_usage if count == 0]
                    if unused_rooms:
                        logger.info(f"  {len(unused_rooms)} rooms not used: {', '.join(unused_rooms[:5])}{'...' if len(unused_rooms) > 5 else ''}")
        # Return only newly scheduled items
        if existing_schedule:
            return self.schedule[len(existing_schedule):]
        return self.schedule
    
    def solve_with_conflict_detection(self):
        """Solve with built-in conflict detection"""
        schedule = self.solve()
        
        if self.conflict_detector:
            conflicts = self.conflict_detector.detect_all_conflicts(schedule)
            critical_conflicts = self.conflict_detector.get_critical_conflicts()
            
            if critical_conflicts and self.solve_attempts < self.max_attempts:
                self.solve_attempts += 1
                self.schedule = []
                return self.solve_with_conflict_detection()
        
        return schedule
    
    def get_conflict_report(self) -> Dict:
        """Get detailed conflict report for current schedule"""
        if not self.conflict_detector:
            return {"error": "Conflict detection not enabled"}
        
        return self.conflict_detector.generate_conflict_report()
    
    def get_quality_score(self) -> float:
        """Get schedule quality score (0-100)"""
        if not self.conflict_detector:
            return 0.0
        
        return self.conflict_detector.calculate_overall_quality_score()
    
    def suggest_improvements(self) -> List[Dict]:
        """Suggest improvements based on conflicts and relaxations"""
        if not self.conflict_detector or not self.relaxation_engine:
            return []
        
        improvements = []
        
        # Get all conflicts
        conflicts = self.conflict_detector.conflicts
        
        # Find relaxation options for each conflict
        for conflict in conflicts:
            if conflict.can_be_relaxed:
                relaxations = self.relaxation_engine.suggest_relaxations(
                    conflict, self.schedule, max_suggestions=3
                )
                
                for relaxation in relaxations:
                    improvements.append({
                        "conflict_type": conflict.conflict_type.value,
                        "conflict_description": conflict.description,
                        "suggested_action": relaxation.action_type,
                        "affected_course": relaxation.schedule_item.course_code,
                        "feasibility": relaxation.feasibility_score,
                        "benefit": relaxation.net_benefit
                    })
        
        # Sort by benefit
        improvements.sort(key=lambda x: x["benefit"], reverse=True)
        return improvements
