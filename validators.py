from typing import Dict, List, Tuple, Any
import pandas as pd
from data_model import Lecturer, Room, ClassSection


def validate_lecturer_availability(lecturers: Dict[str, Lecturer], min_slots: int = 3) -> List[str]:
    issues = []
    for lect_id, lect in lecturers.items():
        slot_count = len(lect.available_time_slots)
        if slot_count < min_slots:
            issues.append(
                f"WARNING: Lecturer {lect.name} has only {slot_count} available slots (min recommended {min_slots})."
            )
    return issues


def validate_room_capacity(sections: List[ClassSection], rooms: Dict[str, Room]) -> List[str]:
    issues = []
    for sec in sections:
        if sec.requested_room:
            room_id = sec.requested_room.replace(" ", "_")
            room = rooms.get(room_id)
            if room and sec.enrollment > room.capacity:
                issues.append(
                    f"ERROR: {sec.course_code} enrollment {sec.enrollment} exceeds room {room.name} capacity {room.capacity}."
                )
    return issues


def validate_domain_nonempty(domains: Dict[str, List[Tuple[int, int, str]]], sections: Dict[str, ClassSection]) -> List[str]:
    issues = []
    for sec_id, values in domains.items():
        if not values:
            sec = sections.get(sec_id)
            if sec:
                issues.append(f"ERROR: No domain values available for {sec.course_code} ({sec.section_title}).")
            else:
                issues.append(f"ERROR: No domain values available for {sec_id}.")
    return issues


def validate_required_columns(df: pd.DataFrame, required: List[str], label: str) -> List[str]:
    missing = [col for col in required if col not in df.columns]
    if missing:
        return [f"ERROR: Missing required columns in {label}: {', '.join(missing)}"]
    return []


def validate_exam_data(data: Dict[str, Any]) -> List[str]:
    issues = []
    sections = data.get("sections", [])
    rooms = data.get("rooms", {})

    if not sections:
        issues.append("ERROR: No exam sections found in input.")
    if not rooms:
        issues.append("ERROR: No rooms available for exam scheduling.")

    return issues


def pre_flight_check(data: Dict[str, Any], min_lecturer_slots: int = 3) -> bool:
    sections = data["sections"]
    lecturers = data["lecturers"]
    rooms = data["rooms"]

    issues = []
    issues.extend(validate_lecturer_availability(lecturers, min_slots=min_lecturer_slots))
    issues.extend(validate_room_capacity(sections, rooms))

    if issues:
        print("\n" + "=" * 70)
        print("PRE-FLIGHT VALIDATION REPORT")
        print("=" * 70)
        for issue in issues:
            print(issue)
        print("=" * 70 + "\n")

    return len([i for i in issues if i.startswith("ERROR:")]) == 0
