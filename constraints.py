from typing import List, Dict, Tuple, Any
from data_model import ClassSection, Lecturer

Constraint = Dict[str, Any]  # A mapping from constraint type to its parameters


def credit_hour_slot_constraint(assignment: Dict[str, Any],
                                var_id: str,
                                value: Any,
                                sections: Dict[str, ClassSection]) -> bool:
    """
    Enforce credit hour restrictions on 5pm slot (slot 3) for GENERAL courses only.
    General courses with 2+ credits cannot use 5pm slot.
    Departmental courses have no 5pm restriction.
    """
    day, slot, _ = value
    
    # Slot 3 is 5pm-6pm
    if slot == 3:
        sec = sections[var_id]
        
        # Only apply restriction to GENERAL courses, not departmental
        if sec.course_type == "General":
            credit = str(sec.credit_hours).strip().upper()
            
            # General courses with 2+ credits cannot use 5pm slot
            # Only allow 1-credit or NC (No Credit) General courses in 5pm slot
            if credit not in ["1", "NC"]:
                return False
    
    return True


def no_lecturer_conflict(assignment: Dict[str, Any], 
                         var_id: str,
                         value : Any,
                         sections: Dict[str, ClassSection]) -> bool:
    
    day, slot, _ = value
    this_lect = sections[var_id].lecturer_id
    for other_id, other_val in assignment.items():
        if other_id == var_id:
            continue
        other_day, other_slot, _ = other_val
        if this_lect == sections[other_id].lecturer_id and day == other_day and slot == other_slot:
            return False
    return True

def no_room_conflict(assignment: Dict[str, Any], 
                     var_id: str,
                     value : Any) -> bool:
    day, slot, room_id = value
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
                                sections: Dict[str, ClassSection]) -> bool:
    
    
    """
    This ensures that no classes sharing the same student program and level
    are scheduled at the same time.
    """
    day, slot, _ = value
    this_sec = sections[var_id]
    this_cohorts = this_sec.cohorts
    
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
            this_sem = sections[var_id].semester
            other_sem = sections[other_id].semester
            
            # If both have semesters defined and they are DIFFERENT, then NO CONFLICT.
            if this_sem and other_sem and this_sem != other_sem:
                 return True # Safe, different semesters
            
            if common:
                # OPTIMIZATION:
                # If one is General and one is Departmental, this is a CRITICAL conflict.
                # If both are Departmental, it might be an elective clash which is sometimes unavoidable.
                # But for now, we treat all cohort clashes as invalid to ensure clean schedules.
                return False

    return True


def shared_course_same_time_constraint(assignment: Dict[str, Any],
                                       var_id: str,
                                       value: Any,
                                       sections: Dict[str, ClassSection]) -> bool:
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
    
    # Check all already-assigned sections
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
                               rooms: Dict[str, Any]) -> bool:
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
                              blocked_blocks: List[dict]) -> bool:
    """
    Ensures that a Departmental course isn't scheduled during a time slot
    where its students (based on Level/Semester) are busy with a General course.
    """
    if not blocked_blocks:
        return True
        
    day, slot, _ = value
    sec = sections[var_id]
    
    for block in blocked_blocks:
        if block['level'] == str(sec.course_level) and \
           (block['semester'] is None or sec.semester is None or block['semester'] == str(sec.semester)):
               if day == block['day']:
                   if slot == block['slot']:
                       return False
                       
    return True


def flexible_lecturer_assignment(assignment: Dict[str, Any],
                                  var_id: str,
                                  value: Any,
                                  sections: Dict[str, ClassSection],
                                  lecturers: Dict[str, Lecturer]) -> bool:
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
                    lecturers: dict = None, enable_flexibility: bool = True):
    sections_by_id = {sec.id: sec for sec in sections}

    def lecturer_conflict_wrapper(assignment, var_id, value):
        return no_lecturer_conflict(assignment, var_id, value, sections_by_id)
    
    def cohort_conflict_wrapper(assignment, var_id, value):
        return no_student_cohort_conflict(assignment, var_id, value, sections_by_id)
    
    def shared_time_wrapper(assignment, var_id, value):
        return shared_course_same_time_constraint(assignment, var_id, value, sections_by_id)
    
    def dept_room_wrapper(assignment, var_id, value):
        return department_room_constraint(assignment, var_id, value, sections_by_id, rooms)
    
    def credit_slot_wrapper(assignment, var_id, value):
        return credit_hour_slot_constraint(assignment, var_id, value, sections_by_id)
        
    def blocked_slot_wrapper(assignment, var_id, value):
        if not blocked_blocks: return True
        return no_blocked_slot_conflict(assignment, var_id, value, sections_by_id, blocked_blocks)
    
    def flexible_assignment_wrapper(assignment, var_id, value):
        if not lecturers or not enable_flexibility: return True
        return flexible_lecturer_assignment(assignment, var_id, value, sections_by_id, lecturers)
    
    base_constraints = [
        lecturer_conflict_wrapper,
        no_room_conflict,
        cohort_conflict_wrapper,
        shared_time_wrapper,
        dept_room_wrapper,
        credit_slot_wrapper  # 5pm restriction for General courses only
    ]
    
    # Add flexible assignment constraint if lecturers provided and flexibility enabled
    if lecturers and enable_flexibility:
        base_constraints.append(flexible_assignment_wrapper)
    
    if blocked_blocks:
        base_constraints.append(blocked_slot_wrapper)
        
    return base_constraints


