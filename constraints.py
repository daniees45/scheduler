from typing import List, Dict, Tuple, Any, Set
from data_model import ClassSection, Lecturer
import functools

Constraint = Dict[str, Any]  # A mapping from constraint type to its parameters


def credit_hour_slot_constraint(assignment: Dict[str, Any],
                                var_id: str,
                                value: Any,
                                sections: Dict[str, ClassSection],
                                csp_instance: Any = None) -> bool:
    """
    Enforce 5:00 PM - 6:00 PM slot constraints (Slot 3):
    - For general sessions (course_type == 'General'), slot 3 is strictly reserved for NC or 1.0 credit hour courses.
    - In both general and department sessions, departmental courses with 2.0 or 3.0 credit hours are strictly excluded from slot 3.
    """
    day, slot, _ = value
    if slot == 3:
        sec = sections[var_id]
        credit = str(sec.credit_hours).strip().upper()
        # General session: Only NC or 1.0 allowed in slot 3
        if sec.course_type == "General":
            if credit not in ["1", "NC"]:
                return False
        # Departmental session: 2.0 or 3.0 strictly excluded from slot 3
        if sec.course_type == "Departmental":
            if credit in ["2", "3", "2.0", "3.0"]:
                return False
    return True


def no_lecturer_conflict(assignment: Dict[str, Any], 
                         var_id: str,
                         value : Any,
                         sections: Dict[str, ClassSection],
                         csp_instance: Any = None) -> bool:
    
    day, slot, _ = value
    this_lect = sections[var_id].lecturer_id
    
    if not this_lect:
        return True # Without a lecturer, there can be no conflict
        
    # --- O(1) Check using CSP Tracker ---
    if csp_instance and hasattr(csp_instance, 'lecturer_allocations'):
        return (this_lect, day, slot) not in csp_instance.lecturer_allocations
        
    # Legacy fallback
    for other_id, other_val in assignment.items():
        if other_id == var_id:
            continue
        other_day, other_slot, _ = other_val
        if this_lect == sections[other_id].lecturer_id and day == other_day and slot == other_slot:
            return False
    return True

def no_room_conflict(assignment: Dict[str, Any], 
                     var_id: str,
                     value : Any,
                     csp_instance: Any = None) -> bool:
    day, slot, room_id = value
    
    # --- O(1) Check using CSP Tracker ---
    if csp_instance and hasattr(csp_instance, 'room_allocations'):
        return (room_id, day, slot) not in csp_instance.room_allocations
        
    # Legacy fallback
    for other_id, other_val in assignment.items():
        if other_id == var_id:
            continue
        other_day, other_slot, other_room_id = other_val
        if room_id == other_room_id and day == other_day and slot == other_slot:
            return False
    return True


def no_student_cohort_conflict(assignment: Dict[str, Any], 
                                var_id: str,
                                value : Any,
                                sections: Dict[str, ClassSection],
                                csp_instance: Any = None) -> bool:
    
    """
    This ensures that no classes sharing the same student program and level
    are scheduled at the same time.
    """
    day, slot, _ = value
    this_sec = sections[var_id]
    this_cohorts = this_sec.cohorts
    
    if not this_cohorts:
        return True # No cohorts to conflict with
        
    # --- O(1) Check using CSP Tracker ---
    if csp_instance and hasattr(csp_instance, 'cohort_allocations'):
        time_key = (day, slot)
        if time_key in csp_instance.cohort_allocations:
            active_cohorts = csp_instance.cohort_allocations[time_key]
            # Simple intersection check against active cohorts
            # Note: We are currently ignoring the semester exception in the fast path for speed,
            # this might make tracking slightly tighter than before, but overwhelmingly correct.
            # To handle semesters properly, cohorts should ideally include the semester.
            common = this_cohorts.intersection(active_cohorts)
            if common:
                # However we need to check the semester exemption. The fast path triggers here
                # Let's verify via the legacy loop if an intersection is found, 
                # to guarantee we don't block valid different-semester cohorts.
                pass # Proceed to legacy check if fast path fails
            else:
                return True # Fast path clean
        else:
            return True # Nobody scheduled at this time
    
    # Legacy fallback (needed if fast path flags an intersection to check semesters)
    for other_id, other_val in assignment.items():
        if other_id == var_id:
            continue
        other_day, other_slot, _ = other_val
        
        if day == other_day and slot == other_slot:
            other_sec = sections[other_id]
            other_cohorts = other_sec.cohorts
            
            # Intersection check: Is there any overlap in student groups?
            common = this_cohorts.intersection(other_cohorts)
            
            # SEMESTER CHECK:
            # Even if cohorts match (e.g. "General_100"), if they are explicitly for different semesters,
            # they do NOT conflict.
            this_sem = this_sec.semester
            other_sem = other_sec.semester
            
            # If both have semesters defined and they are DIFFERENT, then NO CONFLICT.
            if this_sem and other_sem and this_sem != other_sem:
                 continue # This specific intersection is safe, check others
            
            if common:
                return False

    return True


def shared_course_same_time_constraint(assignment: Dict[str, Any],
                                       var_id: str,
                                       value: Any,
                                       sections: Dict[str, ClassSection],
                                       csp_instance: Any = None) -> bool:
    """
    Enforce that courses in the same shared_group_id AND same section are scheduled at the SAME day/slot.
    
    This forces equivalent courses across departments to align, but allows different sections
    of the same course to be scheduled at different times.
    
    Example:
    - GNED 125 [Sec A] for CS and GNED 125 [Sec A] for Nursing → MUST be same time
    - GNED 125 [Sec A] and GNED 125 [Sec B] → CAN be different times
    """
    day, slot, room_id = value
    this_sec = sections[var_id]
    
    # If this section is not part of a shared group, constraint is satisfied
    if not this_sec.shared_group_id:
        return True
    
    shared_group = this_sec.shared_group_id
    this_section_title = this_sec.section_title
    
    # Needs to scan assignment. Usually very few shared courses, but could be optimizing target later.
    for other_id, (other_day, other_slot, other_room) in assignment.items():
        if other_id == var_id:
            continue
        
        other_sec = sections[other_id]
        
        # If other section is in the same shared group AND has the same section title,
        # it MUST be at the same day/slot
        if other_sec.shared_group_id == shared_group and other_sec.section_title == this_section_title:
            if other_day != day or other_slot != slot:
                # Mismatch: shared courses with same section must align
                return False
    
    
    return True


def department_room_constraint(assignment: Dict[str, Any], 
                               var_id: str,
                               value: Any,
                               sections: Dict[str, ClassSection],
                               rooms: Dict[str, Any],
                               csp_instance: Any = None) -> bool:
    """
    Enforce that courses use only rooms from their owning department.
    
    Rules:
    - CS courses (COSC, INFT, BBIS) can only use CS rooms or General rooms
    - Nursing courses can only use Nursing or General rooms
    - Business/shared courses can use any rooms matching their owning_department
    - General courses can use any General rooms
    """
    day, slot, room_id = value
    sec = sections[var_id]
    
    if room_id not in rooms:
        return False  # Invalid room
    
    room = rooms[room_id]
    
    # Check if room's department matches course's owning department
    # Allow: owning_dept matches room.dept, or room is General
    allowed = (room.department == sec.owning_department or 
               room.department == "General")
    
    return allowed


def no_blocked_slot_conflict(assignment: Dict[str, Any], 
                              var_id: str,
                              value : Any,
                              sections: Dict[str, ClassSection],
                              blocked_blocks: List[dict],
                              course_cohorts: Dict[str, set] = None,
                              csp_instance: Any = None) -> bool:
    """
    Ensures that a Departmental course isn't scheduled during a time slot
    where its students (based on Level/Semester) are busy with a General course
    OR a shared course from another department.
    """
    if not blocked_blocks:
        return True

    def _norm_level(raw_level: Any) -> str:
        text = str(raw_level or '').strip()
        if not text or text.lower() == 'nan':
            return ''
        try:
            value = int(float(text))
            if 0 < value < 10:
                value *= 100
            return str(value)
        except Exception:
            return text

    def _norm_sem(raw_sem: Any) -> str:
        text = str(raw_sem or '').strip()
        if not text or text.lower() == 'nan':
            return ''
        return text

    def _norm_lecturer(raw_name: Any) -> str:
        name = str(raw_name or '').strip().lower().replace('_', ' ')
        return ' '.join(name.split())
        
    day, slot, _ = value
    sec = sections[var_id]
    sec_level = _norm_level(sec.course_level)
    sec_sem = _norm_sem(sec.semester)
    sec_lecturer = _norm_lecturer(sec.lecturer_id)
    
    for block in blocked_blocks:
        # 1. Check Day/Slot match
        if day != block['day'] or slot != block['slot']:
            continue

        # 1b. Lecturer occupancy from blocked schedules (cross-department guard)
        block_lecturer = _norm_lecturer(block.get('lecturer_name', ''))
        if block_lecturer and sec_lecturer and block_lecturer == sec_lecturer:
            return False
            
        # 2. Check Level/Semester match
        block_level = _norm_level(block.get('level', ''))
        block_sem = _norm_sem(block.get('semester', ''))
        level_match = (not block_level) or (block_level == sec_level)
        sem_match = (not block_sem) or (block_sem == sec_sem)
        
        if level_match and sem_match:
            # 3. SMART COHORT CHECK:
            # If the block has a course_code, we check if those specific students are affected.
            block_code = block.get('course_code')
            if block_code and course_cohorts:
                block_cohorts = course_cohorts.get(block_code, set())
                # Only block if there is a student overlap (common cohorts)
                if not sec.cohorts.intersection(block_cohorts):
                    continue # No shared students, this slot is FREE for this department
            
            # If it's a legacy block (no course_code) or cohorts overlap, BLOCK the slot
            return False
                        
    return True


def flexible_lecturer_assignment(assignment: Dict[str, Any],
                                  var_id: str,
                                  value: Any,
                                  sections: Dict[str, ClassSection],
                                  lecturers: Dict[str, Lecturer],
                                  csp_instance: Any = None) -> bool:
    """
    Soft constraint that encourages flexible assignment when lecturer slots are occupied.
    
    This constraint allows assignments even if a lecturer's preferred time is taken,
    as long as they have alternative slots available (flexibility-based assignment).
    
    Hard constraint: Lecturer cannot be in TWO places at same time (no_lecturer_conflict)
    Soft constraint: But if they have flexibility, they CAN use alternative slots
    
    Returns:
        True: Assignment is allowed (either lecturer is free OR has flexibility)
        False: Should not allow (only if lecturer has NO alternative slots)
    """
    day, slot, _ = value
    sec = sections[var_id]
    lecturer = lecturers.get(sec.lecturer_id)
    
    if not lecturer:
        return True  # No lecturer info, allow
    
    # Check if this slot is available for the lecturer
    is_preferred_slot_available = (day, slot) in lecturer.available_time_slots
    
    if is_preferred_slot_available:
        return True  # Preferred slot is free - definitely use it
    
    # Preferred slot is NOT available - check flexibility
    # Count how many other available slots this lecturer has
    other_available_slots = []
    for other_day, other_slot in lecturer.available_time_slots:
        if (other_day, other_slot) != (day, slot):
            # Check if this alternative is occupied
            is_occupied = False
            for other_id, other_val in assignment.items():
                if other_id == var_id:
                    continue
                other_day_val, other_slot_val, _ = other_val
                if sections[other_id].lecturer_id == sec.lecturer_id and \
                   other_day_val == other_day and other_slot_val == other_slot:
                    is_occupied = True
                    break
            
            if not is_occupied:
                other_available_slots.append((other_day, other_slot))
    
    # If lecturer has alternative slots, allow this assignment (soft flexibility)
    # This enables the solver to use less-preferred slots when necessary
    if other_available_slots:
        return True  # Lecturer has alternatives, can use this slot
    
    # No alternatives available at all - cannot assign
    return False


def make_constraints(sections: list,  rooms: dict, preference_model: dict = None, blocked_blocks: list = None,
                    lecturers: dict = None, enable_flexibility: bool = True, course_cohorts: dict = None):
    sections_by_id = {sec.id: sec for sec in sections}

    base_constraints = [
        functools.partial(no_lecturer_conflict, sections=sections_by_id),
        no_room_conflict,
        functools.partial(no_student_cohort_conflict, sections=sections_by_id),
        functools.partial(shared_course_same_time_constraint, sections=sections_by_id),
        functools.partial(credit_hour_slot_constraint, sections=sections_by_id)
    ]
    
    # Add flexible assignment constraint if lecturers provided and flexibility enabled
    if lecturers and enable_flexibility:
        base_constraints.append(functools.partial(flexible_lecturer_assignment, sections=sections_by_id, lecturers=lecturers))
    
    if blocked_blocks:
        base_constraints.append(functools.partial(no_blocked_slot_conflict, sections=sections_by_id, 
                                                 blocked_blocks=blocked_blocks, course_cohorts=course_cohorts))
        
    return base_constraints


