from typing import Dict, Any, List
from data_model import ClassSection, Room


def no_student_cohort_conflict(assignment: Dict[str, Any],
                                var_id: str,
                                value: Any,
                                sections: Dict[str, ClassSection]) -> bool:
    day, slot, _ = value
    this_sec = sections[var_id]
    this_cohorts = this_sec.cohorts

    for other_id, other_val in assignment.items():
        if other_id == var_id:
            continue
        other_day, other_slot, _ = other_val

        if day == other_day and slot == other_slot:
            other_sec = sections[other_id]
            if this_cohorts.intersection(other_sec.cohorts):
                return False

    return True


def no_multiple_exams_same_day(assignment: Dict[str, Any],
                               var_id: str,
                               value: Any,
                               sections: Dict[str, ClassSection],
                               max_exams_per_day: int) -> bool:
    day, _, _ = value
    this_sec = sections[var_id]
    this_cohorts = this_sec.cohorts

    if not this_cohorts or max_exams_per_day <= 0:
        return True

    counts = {c: 0 for c in this_cohorts}
    for other_id, other_val in assignment.items():
        if other_id == var_id:
            continue
        other_day, _, _ = other_val
        if other_day != day:
            continue

        other_sec = sections[other_id]
        common = this_cohorts.intersection(other_sec.cohorts)
        for cohort in common:
            counts[cohort] = counts.get(cohort, 0) + 1
            if counts[cohort] >= max_exams_per_day:
                return False

    return True


def no_room_capacity_overflow(assignment: Dict[str, Any],
                              var_id: str,
                              value: Any,
                              sections: Dict[str, ClassSection],
                              rooms: Dict[str, Room],
                              max_students_per_hall: int) -> bool:
    if max_students_per_hall <= 0:
        return True

    day, slot, room_id = value
    room = rooms.get(room_id)
    if not room:
        return False

    limit = min(max_students_per_hall, room.capacity) if room.capacity else max_students_per_hall
    total = sections[var_id].enrollment

    for other_id, other_val in assignment.items():
        if other_id == var_id:
            continue
        other_day, other_slot, other_room_id = other_val
        if other_day == day and other_slot == slot and other_room_id == room_id:
            total += sections[other_id].enrollment
            if total > limit:
                return False

    return total <= limit


def make_exam_constraints(sections: list,
                           rooms: Dict[str, Room],
                           max_exams_per_day: int = 1,
                           max_students_per_hall: int = 0):
    sections_by_id = {sec.id: sec for sec in sections}

    def cohort_conflict_wrapper(assignment, var_id, value, csp_instance=None):
        return no_student_cohort_conflict(assignment, var_id, value, sections_by_id)

    def same_day_wrapper(assignment, var_id, value, csp_instance=None):
        return no_multiple_exams_same_day(assignment, var_id, value, sections_by_id, max_exams_per_day)

    def hall_capacity_wrapper(assignment, var_id, value, csp_instance=None):
        return no_room_capacity_overflow(
            assignment,
            var_id,
            value,
            sections_by_id,
            rooms,
            max_students_per_hall
        )

    # For exam scheduling:
    # - Cohort conflict DISABLED: allows overlapping schedules for different levels/semesters
    # - Multiple exams per day: controlled by config (0 = unlimited, 1 = max 1 per day per cohort)
    # - Room capacity: ensures total students in same room/time don't exceed capacity
    base_constraints = [
        # cohort_conflict_wrapper,  # DISABLED - allows Level 100 Sem1 and Sem2 to overlap
        same_day_wrapper,           # Limits exams per cohort per day (if max_exams_per_day > 0)
        hall_capacity_wrapper       # Ensures room capacity not exceeded
    ]

    return base_constraints
