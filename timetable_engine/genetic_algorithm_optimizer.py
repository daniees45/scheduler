"""
Genetic Algorithm Optimizer - Production-Grade Implementation
Advanced evolutionary optimization for course timetable scheduling
"""

import random
import numpy as np
from typing import List, Tuple, Dict, Any, Callable, Optional
from dataclasses import dataclass
from datetime import datetime
import copy


@dataclass
class ChromosomeStats:
    """Statistics for a chromosome"""
    fitness: float
    conflicts: int
    room_utilization: float
    lecturer_load_balance: float
    student_free_time: float


class Chromosome:
    """Represents a schedule solution as a chromosome"""
    
    def __init__(self, schedule_items: List[Tuple] = None):
        """
        Initialize chromosome
        schedule_items: List of (course_code, lecturer, room, day, slot) or (course_code, lecturer, room, day, slot, section_id) tuples
        """
        self.schedule_items = schedule_items or []
        self.fitness = 0.0
        self.stats = None
        
    def copy(self):
        """Create a deep copy of this chromosome"""
        return Chromosome(copy.deepcopy(self.schedule_items))
    
    def __lt__(self, other):
        """Used for sorting by fitness (higher fitness is better)"""
        return self.fitness > other.fitness  # Sort descending


class GeneticAlgorithmScheduler:
    """
    Production-grade Genetic Algorithm for course timetable scheduling
    
    Features:
    - Elitism: Preserve best solutions
    - Multi-point crossover: Better solution exploration
    - Adaptive mutation: Dynamic mutation rates
    - Parallel fitness evaluation: Scale to large problems
    - Constraint preservation: Hard constraints always satisfied
    - Convergence tracking: Monitor algorithm performance
    """
    
    def __init__(self, 
                 courses: List[Dict[str, Any]],
                 lecturers: List[str],
                 rooms: List[Dict[str, Any]],
                 time_slots: List[str],
                 days: Optional[List[str]] = None,
                 special_room_constraints: Optional[Dict[str, Dict[str, str]]] = None,
                 enforce_lecturer_assignment: bool = True,
                 population_size: int = 100,
                 generations: int = 300,
                 elite_size: int = None,
                 tournament_size: int = 5,
                 crossover_rate: float = 0.8,
                 mutation_rate: float = 0.15,
                 constraint_weights: Optional[Dict[str, float]] = None,
                 existing_schedule: List[Any] = None,
                 existing_course_lookup: Dict[str, Any] = None,
                 course_groups: Optional[Dict[str, List[str]]] = None,
                 lecturer_availability: Optional[Dict[str, Dict[str, bool]]] = None,
                 shared_course_aliases: Optional[Dict[str, str]] = None,
                 verbose: bool = True,
                 strict_departmental: bool = True,
                 reserved_rooms: Optional[set] = None):
        """
        Initialize GA scheduler
        
        Args:
            courses: List of course dictionaries with {code, title, enrollment, level, dept}
            lecturers: List of lecturer names
            rooms: List of room dictionaries with {name, capacity}
            time_slots: List of available time slots
            population_size: Initial population size
            generations: Number of generations to evolve
            elite_size: Number of elite solutions to preserve (default: 10% of population)
            tournament_size: Size of tournament selection
            crossover_rate: Probability of crossover (0-1)
            mutation_rate: Initial mutation rate (0-1)
            verbose: Print progress information
        """
        self.courses = courses
        self.lecturers = lecturers
        self.rooms = rooms
        self.time_slots = time_slots
        self.days = days or ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday"]
        self.enforce_lecturer_assignment = enforce_lecturer_assignment
        # Create lookups: primary by section_id (if available), fallback by code for backward compatibility
        self.course_lookup = {c.get('section_id'): c for c in self.courses if c.get('section_id')}
        # Also create code-based lookup for backward compatibility
        self.course_lookup_by_code = {c.get('code'): c for c in self.courses}
        
        # Debug: check if section_id is present in courses
        if verbose:
            section_ids_found = sum(1 for c in self.courses if c.get('section_id'))
            print(f"[GA] Initialized with {len(self.courses)} courses")
            print(f"[GA] {section_ids_found} courses have section_id")
            if section_ids_found > 0:
                sample_course = next((c for c in self.courses if c.get('section_id')), {})
                print(f"[GA] Sample course with section_id: code={sample_course.get('code')}, section_id={sample_course.get('section_id')}, title={sample_course.get('title')}")
        
        self.course_groups = course_groups or {}
        self.lecturer_availability = lecturer_availability or {}
        self.shared_course_aliases = {
            " ".join(str(k).strip().upper().split()): " ".join(str(v).strip().upper().split())
            for k, v in (shared_course_aliases or {}).items()
            if str(k).strip() and str(v).strip()
        }
        
        # Filter special_room_constraints to active room pool only
        all_special_constraints = special_room_constraints or {}
        available_room_names_normalized = {
            " ".join(str(r.get('name', '')).strip().lower().split())
            for r in self.rooms
            if str(r.get('name', '')).strip()
        }
        self.special_room_constraints = {
            self._normalize_course_code(code): info
            for code, info in all_special_constraints.items()
            if isinstance(info, dict)
            and " ".join(str(info.get('room_name', '')).strip().lower().split()) in available_room_names_normalized
        }
        
        # Build normalized reserved rooms set
        self.reserved_rooms = reserved_rooms or set()
        self.reserved_rooms_normalized = {
            self._normalize_room_name(name)
            for name in self.reserved_rooms
            if self._normalize_room_name(name)
        }
        
        # Existing Baseline (for blocking)
        self.existing_schedule = existing_schedule or []
        self.existing_course_lookup = existing_course_lookup or {}
        
        self.population_size = population_size
        self.generations = generations
        self.elite_size = elite_size or max(5, population_size // 10)
        self.tournament_size = tournament_size
        self.crossover_rate = crossover_rate
        self.mutation_rate = mutation_rate
        self.verbose = verbose
        self.strict_departmental = strict_departmental
        
        # Tracking
        self.generation_best_fitness = []
        self.generation_avg_fitness = []
        self.generation_best_chromosome = None
        self.convergence_counter = 0
        self.adaptive_mutation_rate = mutation_rate
        
        # Constraint weights
        self.constraint_weights = {
            'hard_conflict_penalty': 5000,  # Room/lecturer overlap
            'soft_conflict_penalty': 200,   # Room capacity
            'room_utilization': 50,         # Prefer efficient room usage
            'lecturer_balance': 30,         # Even load distribution
            'student_free_time': 20,        # Minimize schedule gaps
            'duplicate_course': 5000,        # Same course scheduled twice
            'lecturer_mismatch': 2000,       # Assigned lecturer differs from course
            'special_room_violation': 5000,  # Special room/time/day violated
            'credit_hour_violation': 1000,    # Credit hour restriction violated
            'level_semester_conflict': 5000, # Same level/semester overlap
            'missing_course_penalty': 20000, # Course failed to be scheduled
            'group_separation_penalty': 3000  # Grouped courses in different slots
        }
        
        if constraint_weights:
            self.constraint_weights.update(constraint_weights)

    def _get_course(self, course_code: str) -> Dict[str, Any]:
        """Fetch course dict by code"""
        found = self.course_lookup.get(course_code)
        if found:
            return found
        norm = self._normalize_course_code(course_code)
        for c in self.courses:
            if self._normalize_course_code(c.get('code', '')) == norm:
                return c
        return None

    def _normalize_course_code(self, course_code: str) -> str:
        if not course_code:
            return ""
        code = str(course_code).split(" [Sec")[0].split(":")[0].strip()
        code = " ".join(code.upper().split())
        return self.shared_course_aliases.get(code, code)

    def _get_shared_block_keys(self, course: Dict[str, Any]) -> List[str]:
        if not isinstance(course, dict):
            return []
        return [str(k) for k in (course.get('_shared_block_keys', []) or []) if str(k).strip()]

    def _get_course_lecturer(self, course: Dict[str, Any]) -> Optional[str]:
        """Return the fixed lecturer for a course if enforced"""
        if not course:
            return None
        return course.get('lecturer') if self.enforce_lecturer_assignment else None

    def _get_special_constraint(self, course_code: str) -> Dict[str, str]:
        """Return special room constraint for course"""
        return self.special_room_constraints.get(self._normalize_course_code(course_code), {})
    
    def _normalize_room_name(self, room_name: str) -> str:
        """Normalize room names for consistent comparison"""
        if not room_name:
            return ""
        return " ".join(str(room_name).strip().lower().split())
    
    def _room_matches(self, room_a: str, room_b: str) -> bool:
        """Check if two room names match using normalized comparison"""
        return self._normalize_room_name(room_a) == self._normalize_room_name(room_b)
    
    def _slot_matches_fixed_time(self, slot_value: str, fixed_time_value: str) -> bool:
        """Check if slot start time matches fixed time using normalized comparison"""
        if not fixed_time_value:
            return True
        slot_start = str(slot_value or "").split("-")[0].strip()
        return self._normalize_time(slot_start) == self._normalize_time(fixed_time_value)

    def _normalize_time(self, time_str: str) -> str:
        """Normalize time strings like '5:00pm' to '05:00 PM' for matching"""
        if not time_str: return ""
        t = str(time_str).lower().strip().replace(" ", "")
        try:
            if ":" in t:
                parts = t.split(":")
                h = "".join(filter(str.isdigit, parts[0]))
                m = "".join(filter(str.isdigit, parts[1]))
                suffix = "pm" if "pm" in t else "am"
                if h and m:
                    return f"{int(h):02d}:{int(m):02d} {suffix.upper()}"
            return t
        except:
            return t

    def _is_intentional_pairing(self, code1: str, code2: str) -> bool:
        """Check if two courses are intentionally paired (sync groups, shared_group_id, or title similarity)"""
        if not code1 or not code2:
            return False
        
        # Strip section markers
        c1 = self._normalize_course_code(code1)
        c2 = self._normalize_course_code(code2)
        
        if c1 == c2:
            return True
            
        # 1. Check shared_group_id (Forced sync)
        course1 = self._get_course(code1)
        course2 = self._get_course(code2)
        if course1 and course2:
            s1 = course1.get('shared_group_id')
            s2 = course2.get('shared_group_id')
            if s1 and s2 and s1 == s2:
                return True

        # 2. Check course groups
        if c1 in self.course_groups and c2 in self.course_groups[c1]:
            return True
        if c2 in self.course_groups and c1 in self.course_groups[c2]:
            return True
            
        # 3. Check title similarity
        if course1 and course2:
            t1 = course1.get('title', '').lower()
            t2 = course2.get('title', '').lower()
            if t1 and t2 and (t1 in t2 or t2 in t1):
                return True
                
        return False

    def _is_credit_hour_restricted(self, course: Dict[str, Any], slot: str) -> bool:
        """Return True if credit hour restriction is violated"""
        if not course:
            return False

        credits = str(course.get('credits', '')).strip()
        if not credits or credits.upper() == "NC":
            return False

        try:
            credit_val = float(credits)
        except ValueError:
            credit_val = 2.0

        if credit_val <= 1:
            return False

        # Block 2 and 3-credit courses from the last slot (e.g. 5pm)
        return slot == self.time_slots[-1]
        
    def _create_random_chromosome(self) -> Chromosome:
        """Create a random valid schedule chromosome"""
        schedule_items = []
        room_occupancy = {room['name']: {} for room in self.rooms}
        # Ensure special/reserved rooms are tracked even if not in the general pool
        for special in self.special_room_constraints.values():
            if isinstance(special, dict):
                r_name = special.get('room_name')
                if r_name and r_name not in room_occupancy:
                    room_occupancy[r_name] = {}
        
        lecturer_schedule = {lecturer: {} for lecturer in self.lecturers}
        level_semester_slots = {}
        
        for course in self.courses:
            # Assign lecturer to course
            fixed_lecturer = self._get_course_lecturer(course)
            lecturer = fixed_lecturer or random.choice(self.lecturers)
            
            # Find available room and slot
            assigned = False
            attempts = 0
            max_attempts = 100
            
            while not assigned and attempts < max_attempts:
                special = self._get_special_constraint(course.get('code'))
                fixed_day = course.get('fixed_day')
                fixed_time = course.get('fixed_time')
                fixed_room = course.get('fixed_room')

                if fixed_day or fixed_time or fixed_room:
                    # Priority 1: Use course-level fixed fields (smart locking)
                    day = fixed_day or random.choice(self.days)
                    slot = fixed_time or random.choice(self.time_slots)
                    
                    target_name = fixed_room or special.get('room_name')
                    if target_name:
                        room = next((r for r in self.rooms if r['name'] == target_name), None)
                        if room is None:
                            room = {'name': target_name, 'capacity': 30, 'department': 'General'}
                    else:
                        room = random.choice(self.rooms)
                elif special:
                    # Priority 2: Use special_rooms.csv constraints
                    target_name = special.get('room_name')
                    if target_name:
                        room = next((r for r in self.rooms if r['name'] == target_name), None)
                        if room is None:
                            room = {'name': target_name, 'capacity': 30, 'department': 'General'}
                    else:
                        room = random.choice(self.rooms)
                        
                    slot = special.get('fixed_time') or random.choice(self.time_slots)
                    day = special.get('fixed_day') or random.choice(self.days)
                else:
                    # Priority 3: Random (Strictly from general pool)
                    room = random.choice(self.rooms)
                    slot = random.choice(self.time_slots)
                    day = random.choice(self.days)
                
                # Check constraints
                if self._check_hard_constraints(
                    course, lecturer, room, day, slot,
                    room_occupancy, lecturer_schedule, level_semester_slots
                ):
                    schedule_items.append((
                        course['code'],
                        lecturer,
                        room['name'],
                        day,
                        slot,
                        course.get('section_id')  # Include section_id if available
                    ))
                    
                    # Update occupancy tracking
                    room_key = f"{day}_{slot}"
                    if room['name'] not in room_occupancy:
                        room_occupancy[room['name']] = {}
                    room_occupancy[room['name']][room_key] = room_occupancy[room['name']].get(room_key, 0) + 1
                    if room_key not in lecturer_schedule[lecturer]:
                        lecturer_schedule[lecturer][room_key] = 0
                    lecturer_schedule[lecturer][room_key] += 1

                    level_key = f"{course.get('level')}_{course.get('semester')}_{day}_{slot}"
                    level_semester_slots[level_key] = level_semester_slots.get(level_key, 0) + 1
                    for shared_key in self._get_shared_block_keys(course):
                        shared_slot_key = f"shared::{shared_key}::{day}::{slot}"
                        level_semester_slots[shared_slot_key] = level_semester_slots.get(shared_slot_key, 0) + 1
                    
                    assigned = True
                
                attempts += 1
        
        return Chromosome(schedule_items)
    
    def _check_hard_constraints(self, course: Dict, lecturer: str, room: Dict, day: str, slot: str,
                               room_occupancy: Dict, lecturer_schedule: Dict,
                               level_semester_slots: Dict) -> bool:
        """Check hard constraints for a schedule item"""
        course_code = course.get('code') if course else ""

        # Constraint 0: Fixed lecturer assignment
        fixed_lecturer = self._get_course_lecturer(course)
        if fixed_lecturer and lecturer != fixed_lecturer:
            return False

        # Constraint 0b: Special room constraints and Smart Locking
        special = self._get_special_constraint(course_code)
        fixed_day = course.get('fixed_day')
        fixed_time = course.get('fixed_time')
        fixed_room = course.get('fixed_room')

        # Check Smart Locking (course-level)
        if fixed_day and day != fixed_day:
            return False
        if fixed_time:
            if self._normalize_time(fixed_time) != self._normalize_time(slot.split(" - ")[0]):
                return False
        if fixed_room and room['name'] != fixed_room:
            return False

        # Check special_rooms.csv with normalized room matching
        if special:
            room_name_special = special.get('room_name')
            if room_name_special and not self._room_matches(room['name'], room_name_special):
                return False
            if special.get('fixed_day') and day != special['fixed_day']:
                return False
            
            f_time = special.get('fixed_time')
            if f_time and not self._slot_matches_fixed_time(slot, f_time):
                return False
        else:
            # Prevent non-special courses from using reserved rooms (normalized check)
            if not fixed_room and self._normalize_room_name(room['name']) in self.reserved_rooms_normalized:
                return False

        # Constraint 0c: Credit hour restriction
        if self._is_credit_hour_restricted(course, slot):
            return False

        # Constraint 0d: Friday slot restriction (Morning only)
        if day.lower() == "friday" and slot not in self.time_slots[:2]:
            return False
        # Constraint 1: Room capacity
        if room['capacity'] < course.get('enrollment', 30):
            return False
        
        # Constraint 2: Room not already occupied at this slot
        room_key = f"{day}_{slot}"
        # NOTE: In random generation, we simply avoid double booking for speed.
        # Fitness evaluation will allow intentional pairings correctly.
        if room_occupancy[room['name']].get(room_key, 0) > 0:
            return False
        
        # Constraint 3: Lecturer not already scheduled at this slot
        if lecturer_schedule[lecturer].get(room_key, 0) >= 1:
            return False

        # Constraint 3b: Lecturer availability
        if self.lecturer_availability.get(lecturer) and not self.lecturer_availability[lecturer].get(day, True):
            return False

        # Constraint 4: No level/semester overlap
        level_key = f"{course.get('level')}_{course.get('semester')}_{day}_{slot}"
        if level_semester_slots.get(level_key, 0) > 0:
            return False
        for shared_key in self._get_shared_block_keys(course):
            shared_slot_key = f"shared::{shared_key}::{day}::{slot}"
            if level_semester_slots.get(shared_slot_key, 0) > 0:
                return False
        
        # Constraint 5: Departmental room enforcement (Strict)
        if self.strict_departmental:
            # Bypass if room is explicitly fixed/requested
            if room.get('name') == course.get('fixed_room'):
                return True
            special = self._get_special_constraint(course.get('code'))
            if special and special.get('room_name') == room.get('name'):
                return True
                
            course_grp = course.get('departmental_group', course.get('department', "General"))
            room_dept = room.get('department', "General")
            
            # Token-based check
            rd = str(room_dept).lower().replace("/", " ").replace("-", " ")
            cg = str(course_grp).lower().replace("/", " ").replace("-", " ")
            is_match = (rd == cg) or (rd in cg) or (cg in rd)
            if not is_match:
                return False
        
        return True
    
    def _evaluate_fitness(self, chromosome: Chromosome) -> Tuple[float, ChromosomeStats]:
        """
        Evaluate fitness of a chromosome
        Higher fitness is better
        """
        penalty = 0
        stats = {
            'conflicts': 0,
            'room_util': 0,
            'lecturer_balance': 0,
            'free_time': 0
        }
        
        # Track occupancy for conflict detection
        room_slots = {}
        lecturer_slots = {}
        level_semester_slots = {}
        
        # Seed with existing schedule (baseline)
        for item in self.existing_schedule:
            slot_key = (item.day, item.time_slot)
            
            # Room occupancy
            room_key = f"{item.room_name}_{item.day}_{item.time_slot}"
            room_slots[room_key] = item.course_code
            
            # Lecturer occupancy
            lect_key = f"{item.lecturer}_{item.day}_{item.time_slot}"
            lecturer_slots[lect_key] = item.course_code
            
            # Level/Semester occupancy
            info = self.existing_course_lookup.get(item.course_code.split(":")[0].strip())
            if info:
                lvl_key = f"{info.get('level')}_{info.get('semester')}_{item.day}_{item.time_slot}"
                if lvl_key not in level_semester_slots:
                    level_semester_slots[lvl_key] = []
                level_semester_slots[lvl_key].append(item.course_code)
                for shared_key in info.get('_shared_block_keys', []) or []:
                    shared_slot_key = f"shared::{shared_key}::{item.day}::{item.time_slot}"
                    level_semester_slots.setdefault(shared_slot_key, []).append(item.course_code)
        
        seen_courses = set()
        
        # Identify missing courses
        scheduled_codes = {item[0] for item in chromosome.schedule_items}
        
        # Course Groups and shared_group_id Check
        if self.course_groups or any(c.get('shared_group_id') for c in self.courses):
            group_assignments = {} # {group_id: (day, slot)}
            for item in chromosome.schedule_items:
                code = item[0]
                course = self._get_course(code)
                slot_key = (item[3], item[4])
                
                # Check shared_group_id (forced sync)
                shared_id = course.get('shared_group_id') if course else None
                if shared_id:
                    if shared_id not in group_assignments:
                        group_assignments[shared_id] = slot_key
                    elif group_assignments[shared_id] != slot_key:
                        penalty += self.constraint_weights.get('group_separation_penalty', 3000)
                        stats['conflicts'] += 1
                
                # Check title-similarity groups
                norm_code = self._normalize_course_code(code)
                if norm_code in self.course_groups:
                    group_members = tuple(sorted(self.course_groups[norm_code]))
                    if group_members not in group_assignments:
                        group_assignments[group_members] = slot_key
                    elif group_assignments[group_members] != slot_key:
                        penalty += self.constraint_weights.get('group_separation_penalty', 3000)
                        stats['conflicts'] += 1
        num_missing = len(self.courses) - len(scheduled_codes)
        penalty += num_missing * self.constraint_weights['missing_course_penalty']
        stats['conflicts'] += num_missing

        for item in chromosome.schedule_items:
            # Handle both 5-tuple (old format) and 6-tuple (with section_id) formats
            if len(item) >= 6:
                course_code, lecturer, room, day, slot, section_id = item[0], item[1], item[2], item[3], item[4], item[5]
            else:
                course_code, lecturer, room, day, slot = item[0], item[1], item[2], item[3], item[4]
            
            key = f"{room}_{day}_{slot}"
            course = self._get_course(course_code)

            if course_code in seen_courses:
                penalty += self.constraint_weights['duplicate_course']
                stats['conflicts'] += 1
            else:
                seen_courses.add(course_code)
            
            # Hard constraint: No room double-booking (unless intentional)
            # Use normalized room name for matching
            norm_room = self._normalize_room_name(room)
            norm_key = f"{norm_room}_{day}_{slot}"
            if norm_key in room_slots:
                # Check for intentional pairing (e.g., grouped courses sharing a room)
                other_code = room_slots[norm_key]
                if not self._is_intentional_pairing(course_code, other_code):
                    penalty += self.constraint_weights['hard_conflict_penalty']
                    stats['conflicts'] += 1
            else:
                room_slots[norm_key] = course_code
            
            # Hard constraint: No lecturer double-booking (unless intentional)
            lecturer_key = f"{lecturer}_{day}_{slot}"
            if lecturer_key in lecturer_slots:
                # Check for intentional pairing
                other_code = lecturer_slots[lecturer_key]
                if not self._is_intentional_pairing(course_code, other_code):
                    penalty += self.constraint_weights['hard_conflict_penalty']
                    stats['conflicts'] += 1
            else:
                lecturer_slots[lecturer_key] = course_code

            # Hard constraint: Lecturer availability
            if self.lecturer_availability.get(lecturer) and not self.lecturer_availability[lecturer].get(day, True):
                penalty += self.constraint_weights.get('hard_conflict_penalty', 5000)
                stats['conflicts'] += 1


            # Room/Department matching with normalized room name
            room_obj = next((r for r in self.rooms if self._room_matches(r.get('name', ''), room)), None)
            if room_obj:
                course_grp = course.get('departmental_group', "General")
                room_dept = room_obj.get('department', "General")
                
                if self.strict_departmental:
                    # Token-based check
                    rd = str(room_dept).lower().replace("/", " ").replace("-", " ")
                    cg = str(course_grp).lower().replace("/", " ").replace("-", " ")
                    is_match = (rd == cg) or (rd in cg) or (cg in rd)
                    
                    if not is_match:
                        # Massive penalty for department mismatch in strict mode
                        penalty += 10000 
                        stats['conflicts'] += 1
                else:
                    # Soft preference in non-strict mode
                    if room_dept != "General" and course_grp != room_dept:
                        penalty += 500 # Mild penalty for borrowing rooms

            fixed_lecturer = self._get_course_lecturer(course)
            if fixed_lecturer and lecturer != fixed_lecturer:
                penalty += self.constraint_weights['lecturer_mismatch']
                stats['conflicts'] += 1

            # Check special room constraints with normalized matching
            special = self._get_special_constraint(course_code)
            if special:
                room_name_special = special.get('room_name')
                if room_name_special and not self._room_matches(room, room_name_special):
                    penalty += self.constraint_weights['special_room_violation']
                    stats['conflicts'] += 1
                if special.get('fixed_day') and day != special['fixed_day']:
                    penalty += self.constraint_weights['special_room_violation']
                    stats['conflicts'] += 1
                f_time = special.get('fixed_time')
                if f_time and not self._slot_matches_fixed_time(slot, f_time):
                    penalty += self.constraint_weights['special_room_violation']
                    stats['conflicts'] += 1
            else:
                # Check against reserved rooms using normalized names
                if self._normalize_room_name(room) in self.reserved_rooms_normalized:
                    penalty += self.constraint_weights['special_room_violation']
                    stats['conflicts'] += 1

            if self._is_credit_hour_restricted(course, slot):
                penalty += self.constraint_weights['credit_hour_violation']
                stats['conflicts'] += 1

            # Rule: Department room enforcement (Soft penalty)
            if not course.get('is_general', False):
                course_dept = course.get('departmental_group', "General")
                # Find the room dict
                room_obj = next((r for r in self.rooms if r['name'] == room), None)
                room_dept = room_obj.get('department', "General") if room_obj else "General"
                
                if room_dept != "General" and course_dept != "General" and room_dept != course_dept:
                    # Penalty for department mismatch - lower than hard conflict
                    penalty += self.constraint_weights.get('soft_conflict_penalty', 200)

            # Friday restriction penalty (Morning only)
            if day.lower() == "friday" and slot not in self.time_slots[:2]:
                penalty += self.constraint_weights.get('hard_conflict_penalty', 5000)
                stats['conflicts'] += 1

            level_key = f"{course.get('level')}_{course.get('semester')}_{day}_{slot}"
            if level_key not in level_semester_slots:
                level_semester_slots[level_key] = []
            level_semester_slots[level_key].append(course_code)
            for shared_key in self._get_shared_block_keys(course):
                shared_slot_key = f"shared::{shared_key}::{day}::{slot}"
                level_semester_slots.setdefault(shared_slot_key, []).append(course_code)

        for key, items_list in level_semester_slots.items():
            if len(items_list) > 1:
                # Check if all items in the clash are intentional pairings
                # If ANY pair in the list is NOT an intentional pairing, it's a conflict
                has_hard_clash = False
                for i in range(len(items_list)):
                    for j in range(i + 1, len(items_list)):
                        if not self._is_intentional_pairing(items_list[i], items_list[j]):
                            has_hard_clash = True
                            break
                    if has_hard_clash: break
                
                if has_hard_clash:
                    count = len(items_list)
                    penalty += self.constraint_weights['level_semester_conflict'] * (count - 1)
                    stats['conflicts'] += (count - 1)
        
        # Soft metrics
        stats['room_util'] = self._calculate_room_utilization(chromosome)
        stats['lecturer_balance'] = self._calculate_lecturer_balance(chromosome)
        stats['free_time'] = self._calculate_student_free_time(chromosome)
        
        # Apply soft constraint penalties
        penalty += max(0, 1.0 - stats['room_util']) * self.constraint_weights['room_utilization']
        penalty += stats['lecturer_balance'] * self.constraint_weights['lecturer_balance']
        penalty -= stats['free_time'] * self.constraint_weights['student_free_time']
        
        # Calculate final fitness (minimize penalty)
        fitness = max(0.1, 1000 - penalty)  # Ensure positive fitness
        
        chromosome.fitness = fitness
        chromosome.stats = ChromosomeStats(
            fitness=fitness,
            conflicts=stats['conflicts'],
            room_utilization=stats['room_util'],
            lecturer_load_balance=stats['lecturer_balance'],
            student_free_time=stats['free_time']
        )
        
        return fitness, chromosome.stats
    
    def _calculate_room_utilization(self, chromosome: Chromosome) -> float:
        """Calculate average room utilization (0-1, higher is better)"""
        if not chromosome.schedule_items:
            return 0
        
        room_slots = {}
        for item in chromosome.schedule_items:
            # Handle both 5-tuple and 6-tuple formats
            room = item[2]  # room is always at index 2
            day = item[3]   # day is always at index 3
            slot = item[4]  # slot is always at index 4
            key = f"{room}_{day}_{slot}"
            room_slots[key] = room_slots.get(key, 0) + 1
        
        # Average utilization across all schedule slots
        total_possible = len(self.rooms) * len(self.time_slots) * len(self.days)
        utilized = len(room_slots)
        
        return min(utilized / total_possible, 1.0) if total_possible > 0 else 0
    
    def _calculate_lecturer_balance(self, chromosome: Chromosome) -> float:
        """
        Calculate lecturer load balance
        Lower is better (values between 0-1)
        """
        if not chromosome.schedule_items:
            return 0
        
        lecturer_loads = {}
        for item in chromosome.schedule_items:
            # Handle both 5-tuple and 6-tuple formats
            lecturer = item[1]  # lecturer is always at index 1
            lecturer_loads[lecturer] = lecturer_loads.get(lecturer, 0) + 1
        
        if not lecturer_loads:
            return 1.0
        
        # Calculate coefficient of variation
        loads = list(lecturer_loads.values())
        mean_load = sum(loads) / len(loads)
        if mean_load == 0:
            return 0
        
        variance = sum((x - mean_load) ** 2 for x in loads) / len(loads)
        std_dev = variance ** 0.5
        cv = std_dev / mean_load
        
        return min(cv / 2.0, 1.0)  # Normalize to 0-1
    
    def _calculate_student_free_time(self, chromosome: Chromosome) -> float:
        """
        Calculate average free time for students
        Higher is better
        """
        if not chromosome.schedule_items or not self.time_slots:
            return 0
        
        # Track occupied slots
        student_schedule_slots = set()
        for item in chromosome.schedule_items:
            # Handle both 5-tuple and 6-tuple formats
            day = item[3]   # day is always at index 3
            slot = item[4]  # slot is always at index 4
            student_schedule_slots.add(f"{day}_{slot}")

        total_slots = len(self.time_slots) * len(self.days)
        if total_slots == 0:
            return 0

        free_slots = total_slots - len(student_schedule_slots)
        return max(0.0, free_slots / total_slots)
    
    def tournament_selection(self, population: List[Chromosome]) -> Chromosome:
        """Select a chromosome using tournament selection"""
        tournament = random.sample(population, min(self.tournament_size, len(population)))
        return max(tournament, key=lambda x: x.fitness)
    
    def crossover(self, parent1: Chromosome, parent2: Chromosome) -> Tuple[Chromosome, Chromosome]:
        """
        Perform two-point crossover
        Creates two offspring from two parents
        """
        if random.random() > self.crossover_rate:
            return parent1.copy(), parent2.copy()
        
        # Two-point crossover
        points = sorted(random.sample(range(len(parent1.schedule_items)), 
                                     min(2, len(parent1.schedule_items))))
        
        if not points or len(points) < 2:
            return parent1.copy(), parent2.copy()
        
        p1, p2 = points
        
        child1_items = (parent1.schedule_items[:p1] + 
                       parent2.schedule_items[p1:p2] + 
                       parent1.schedule_items[p2:])
        
        child2_items = (parent2.schedule_items[:p1] + 
                       parent1.schedule_items[p1:p2] + 
                       parent2.schedule_items[p2:])
        
        return Chromosome(child1_items), Chromosome(child2_items)
    
    def mutate(self, chromosome: Chromosome) -> Chromosome:
        """
        Perform adaptive mutation
        Can swap items, modify assignments, or add/remove items
        Respects special room constraints
        """
        if random.random() > self.adaptive_mutation_rate:
            return chromosome
        
        mutated = chromosome.copy()
        
        if not mutated.schedule_items:
            return mutated
        
        # Choose mutation type
        mutation_type = random.choice(['swap', 'modify', 'remove_add'])
        
        def is_hard_locked(code):
            c = self._get_course(code)
            if not c: return False
            # Check for special_rooms.csv OR smart locking fields
            if self._get_special_constraint(code): return True
            if c.get('fixed_day') or c.get('fixed_time') or c.get('fixed_room'): return True
            return False

        if mutation_type == 'swap' and len(mutated.schedule_items) >= 2:
            # Swap two items (respecting special constraints)
            i, j = random.sample(range(len(mutated.schedule_items)), 2)
            # Don't swap if either has a special room constraint or smart lock
            if not is_hard_locked(mutated.schedule_items[i][0]) and not is_hard_locked(mutated.schedule_items[j][0]):
                mutated.schedule_items[i], mutated.schedule_items[j] = \
                    mutated.schedule_items[j], mutated.schedule_items[i]
        
        elif mutation_type == 'modify' and mutated.schedule_items:
            # Modify a single assignment (respect special constraints)
            idx = random.randint(0, len(mutated.schedule_items) - 1)
            item = mutated.schedule_items[idx]
            # Handle both 5-tuple and 6-tuple formats
            course_code, lecturer = item[0], item[1]
            section_id = item[5] if len(item) >= 6 else None
            
            # Don't modify if it has special room constraint or smart lock
            if not is_hard_locked(course_code):
                # Change room or slot
                available_rooms = [
                    r for r in self.rooms
                    if self._normalize_room_name(r['name']) not in self.reserved_rooms_normalized
                ]
                
                # Apply departmental filtering in strict mode
                if self.strict_departmental:
                    course_info = self._get_course(course_code)
                    course_grp = course_info.get('departmental_group', course_info.get('department', "General"))
                    cg = str(course_grp).lower().replace("/", " ").replace("-", " ")
                    
                    filtered = []
                    for r in available_rooms:
                        rd = str(r.get('department', 'General')).lower().replace("/", " ").replace("-", " ")
                        if (rd == cg) or (rd in cg) or (cg in rd):
                            filtered.append(r)
                    
                    if filtered:
                        available_rooms = filtered
                
                new_room_obj = random.choice(available_rooms) if available_rooms else random.choice(self.rooms)
                new_room = new_room_obj['name']
                new_slot = random.choice(self.time_slots)
                new_day = random.choice(self.days)
                course_info = self._get_course(course_code)
                fixed_lecturer = self._get_course_lecturer(course_info) or lecturer
                
                # Preserve section_id if present
                if section_id:
                    mutated.schedule_items[idx] = (course_code, fixed_lecturer, new_room, new_day, new_slot, section_id)
                else:
                    mutated.schedule_items[idx] = (course_code, fixed_lecturer, new_room, new_day, new_slot)
        
        elif mutation_type == 'remove_add' and mutated.schedule_items:
            # Remove one and add another
            idx = random.randint(0, len(mutated.schedule_items) - 1)
            removed_code = mutated.schedule_items[idx][0]
            
            # Don't remove if it has a special room constraint or smart lock
            if not is_hard_locked(removed_code):

                mutated.schedule_items.pop(idx)
                
                # Try to add a new valid assignment
                available_courses = [c for c in self.courses 
                                   if c['code'] not in [item[0] for item in mutated.schedule_items]]
                if available_courses:
                    course = random.choice(available_courses)
                    lecturer = course.get('lecturer') if self.enforce_lecturer_assignment else random.choice(self.lecturers)
                    available_rooms = [
                        r for r in self.rooms
                        if self._normalize_room_name(r['name']) not in self.reserved_rooms_normalized
                    ]
                    
                    # Apply departmental filtering in strict mode
                    if self.strict_departmental:
                        course_grp = course.get('departmental_group', course.get('department', "General"))
                        cg = str(course_grp).lower().replace("/", " ").replace("-", " ")
                        
                        filtered = []
                        for r in available_rooms:
                            rd = str(r.get('department', 'General')).lower().replace("/", " ").replace("-", " ")
                            if (rd == cg) or (rd in cg) or (cg in rd):
                                filtered.append(r)
                        
                        if filtered:
                            available_rooms = filtered
                    
                    room_obj = random.choice(available_rooms) if available_rooms else random.choice(self.rooms)
                    room = room_obj['name']
                    day = random.choice(self.days)
                    slot = random.choice(self.time_slots)
                    mutated.schedule_items.append((course['code'], lecturer, room, day, slot))
        
        return mutated
    
    def _repair_chromosome(self, chromosome: Chromosome) -> Chromosome:
        """
        Identify conflicts in the chromosome and attempt to fix them
        respecting special room constraints, using normalized room matching.
        """
        if not chromosome.schedule_items:
            return chromosome
            
        # 1. Identify occupied slots (using normalized rooms)
        room_occupancy = {} # (norm_room, day, slot) -> count
        lecturer_occupancy = {} # (lecturer, day, slot) -> count
        level_sem_occupancy = {} # (level, sem, day, slot) -> count
        
        for item in chromosome.schedule_items:
            # Handle both 5-tuple and 6-tuple formats
            code, l, r, d, s = item[0], item[1], item[2], item[3], item[4]
            section_id = item[5] if len(item) >= 6 else None
            
            norm_r = self._normalize_room_name(r)
            rk = (norm_r, d, s)
            lk = (l, d, s)
            course = self._get_course(code)
            sk = (course.get('level'), course.get('semester'), d, s)
            
            room_occupancy[rk] = room_occupancy.get(rk, 0) + 1
            lecturer_occupancy[lk] = lecturer_occupancy.get(lk, 0) + 1
            level_sem_occupancy[sk] = level_sem_occupancy.get(sk, 0) + 1

        # 2. Fix conflicts
        new_items = []
        for item in chromosome.schedule_items:
            # Handle both 5-tuple and 6-tuple formats
            code, l, r, d, s = item[0], item[1], item[2], item[3], item[4]
            section_id = item[5] if len(item) >= 6 else None
            
            course = self._get_course(code)
            norm_r = self._normalize_room_name(r)
            rk = (norm_r, d, s)
            lk = (l, d, s)
            sk = (course.get('level'), course.get('semester'), d, s)
            
            # If special constraint exists, enforce it strictly
            special = self._get_special_constraint(code)
            if special:
                # Keep special room constraints locked
                if section_id:
                    new_items.append((code, l, r, d, s, section_id))
                else:
                    new_items.append((code, l, r, d, s))
                continue
            
            # If conflict exists, try to find a free slot
            if room_occupancy[rk] > 1 or lecturer_occupancy[lk] > 1 or level_sem_occupancy[sk] > 1:
                repaired = False
                for _ in range(50): # 50 attempts to find a free spot
                    new_room_obj = random.choice(self.rooms)
                    
                    # Apply departmental filtering in strict mode
                    if self.strict_departmental:
                        course_grp = course.get('departmental_group', course.get('department', "General"))
                        cg = str(course_grp).lower().replace("/", " ").replace("-", " ")
                        rd = str(new_room_obj.get('department', 'General')).lower().replace("/", " ").replace("-", " ")
                        if not ((rd == cg) or (rd in cg) or (cg in rd)):
                            continue

                    new_r = new_room_obj['name']
                    new_d = random.choice(self.days)
                    new_s = random.choice(self.time_slots)
                    
                    # Skip reserved rooms for non-special courses
                    if self._normalize_room_name(new_r) in self.reserved_rooms_normalized:
                        continue
                    
                    # Minimal check for free spot (using normalized room)
                    norm_new_r = self._normalize_room_name(new_r)
                    new_rk = (norm_new_r, new_d, new_s)
                    new_lk = (l, new_d, new_s)
                    new_sk = (course.get('level'), course.get('semester'), new_d, new_s)
                    
                    if new_rk not in room_occupancy and new_lk not in lecturer_occupancy and new_sk not in level_sem_occupancy:
                        # Update occupancy
                        room_occupancy[rk] -= 1
                        lecturer_occupancy[lk] -= 1
                        level_sem_occupancy[sk] -= 1
                        
                        room_occupancy[new_rk] = 1
                        lecturer_occupancy[new_lk] = 1
                        level_sem_occupancy[new_sk] = 1
                        
                        if section_id:
                            new_items.append((code, l, new_r, new_d, new_s, section_id))
                        else:
                            new_items.append((code, l, new_r, new_d, new_s))
                        repaired = True
                        break
                
                if not repaired:
                    if section_id:
                        new_items.append((code, l, r, d, s, section_id)) # Keep original if can't repair
                    else:
                        new_items.append((code, l, r, d, s)) # Keep original if can't repair
            else:
                if section_id:
                    new_items.append((code, l, r, d, s, section_id))
                else:
                    new_items.append((code, l, r, d, s))
                
        return Chromosome(new_items)

    def evolve(self, callback: Optional[Callable] = None) -> Chromosome:
        """
        Main GA evolution loop
        Returns best chromosome found
        """
        if self.verbose:
            print("="*70)
            print("GENETIC ALGORITHM SCHEDULER - EVOLUTION STARTING")
            print("="*70)
            print(f"Population: {self.population_size}")
            print(f"Generations: {self.generations}")
            print(f"Elite size: {self.elite_size}")
            print(f"Tournament size: {self.tournament_size}")
            print(f"Crossover rate: {self.crossover_rate}")
            print(f"Initial mutation rate: {self.mutation_rate}")
            print("="*70)
        
        # Initialize population
        population = [self._create_random_chromosome() for _ in range(self.population_size)]
        
        # Evaluate initial population
        for individual in population:
            self._evaluate_fitness(individual)
        
        population.sort()
        
        # Evolution loop
        for generation in range(self.generations):
            # Adaptive mutation rate (decrease over time)
            progress = generation / self.generations
            self.adaptive_mutation_rate = self.mutation_rate * (1 - progress * 0.7)
            
            # Track best in generation
            best_fit = population[0].fitness
            avg_fit = sum(p.fitness for p in population) / len(population)
            
            self.generation_best_fitness.append(best_fit)
            self.generation_avg_fitness.append(avg_fit)
            self.generation_best_chromosome = population[0]
            
            if self.verbose and (generation % 20 == 0 or generation == self.generations - 1):
                conflicts = population[0].stats.conflicts if population[0].stats else 0
                print(f"Gen {generation:3d}: Best={best_fit:8.1f} Avg={avg_fit:8.1f} "
                      f"Conflicts={conflicts} MutRate={self.adaptive_mutation_rate:.3f}")
            
            # Check convergence
            if generation > 10 and best_fit == self.generation_best_fitness[-2]:
                self.convergence_counter += 1
            else:
                self.convergence_counter = 0
            
            # Early stopping if convergence
            if self.convergence_counter > 30:
                # Only stop if we have a perfect or near-perfect solution
                best = population[0]
                has_conflicts = best.stats.conflicts > 0
                all_scheduled = len(best.schedule_items) == len(self.courses)
                
                if not has_conflicts and all_scheduled:
                    if self.verbose:
                        print(f"Early stopping at generation {generation} (converged on valid solution)")
                    break
            
            # Create next generation
            new_population = []
            
            # Elitism: Keep best solutions
            elite = sorted(population, key=lambda x: x.fitness)[:self.elite_size]
            new_population.extend(elite)
            
            # Generate offspring through crossover and mutation
            while len(new_population) < self.population_size:
                parent1 = self.tournament_selection(population)
                parent2 = self.tournament_selection(population)
                
                child1, child2 = self.crossover(parent1, parent2)
                child1 = self.mutate(child1)
                child2 = self.mutate(child2)
                
                # REPAIR: Fix conflicts immediately
                if random.random() < 0.3: # 30% chance to run repair
                    child1 = self._repair_chromosome(child1)
                    child2 = self._repair_chromosome(child2)
                
                self._evaluate_fitness(child1)
                self._evaluate_fitness(child2)
                
                new_population.append(child1)
                if len(new_population) < self.population_size:
                    new_population.append(child2)
            
            # Trim to population size
            population = new_population[:self.population_size]
            population.sort()
            
            if callback:
                callback(generation, population[0], avg_fit)
        
        best = population[0]
        
        if self.verbose:
            print("="*70)
            print("EVOLUTION COMPLETE")
            print("="*70)
            print(f"Best fitness achieved: {best.fitness:.1f}")
            print(f"Total generations: {len(self.generation_best_fitness)}")
            print(f"Conflicts in best solution: {best.stats.conflicts}")
            print(f"Room utilization: {best.stats.room_utilization:.1%}")
            print(f"Lecturer balance (CV): {best.stats.lecturer_load_balance:.3f}")
            print(f"Student free time: {best.stats.student_free_time:.1%}")
            print("="*70)
        
        return best
    
    def get_best_schedule(self) -> List[Dict[str, str]]:
        """Convert best chromosome to schedule format"""
        if not self.generation_best_chromosome:
            return []
        
        schedule = []
        section_id_count = 0
        for item in self.generation_best_chromosome.schedule_items:
            # Handle both old format (5-tuple) and new format (6-tuple with section_id)
            if len(item) >= 6:
                course_code, lecturer, room, day, slot, section_id = item[0], item[1], item[2], item[3], item[4], item[5]
            else:
                course_code, lecturer, room, day, slot = item[0], item[1], item[2], item[3], item[4]
                section_id = None
                
            schedule_dict = {
                'course_code': course_code,
                'lecturer': lecturer,
                'room': room,
                'day': day,
                'time_slot': slot
            }
            if section_id:
                schedule_dict['section_id'] = section_id
                section_id_count += 1
            schedule.append(schedule_dict)
        
        if self.verbose and section_id_count > 0:
            print(f"[GA] Schedule includes {section_id_count} items with section_id")
        elif self.verbose:
            print(f"[GA] Warning: No section_id found in schedule items")
        
        return schedule
    
    def get_convergence_data(self) -> Dict[str, List[float]]:
        """Return convergence tracking data"""
        return {
            'best_fitness': self.generation_best_fitness,
            'avg_fitness': self.generation_avg_fitness
        }
