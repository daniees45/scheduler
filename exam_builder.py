from typing import Dict, List, Tuple, Any
from data_model import Room, Course, ClassSection, TimeSlot

Domain = Dict[str, List[Any]]


def build_exam_domain(data: dict) -> Domain:
    config = data["config"]
    rooms: Dict[str, Room] = data["rooms"]
    courses: Dict[str, Course] = data["courses"]
    sections: List[ClassSection] = data["sections"]

    days = config["days"]
    slots_per_day = config["slots_per_day"]
    friday_only_first_slot = bool(config.get("friday_only_first_slot", True))
    domains: Domain = {}

    strict_capacity = config.get("strict_capacity", True)
    print(f"[INFO] Exam Strict Capacity Check: {'ENABLED' if strict_capacity else 'DISABLED'}")

    for sec in sections:
        if sec.fixed_day is not None and sec.fixed_slot is not None:
            req_room_id = next(iter(rooms)) if rooms else None
            if req_room_id:
                domains[sec.id] = [(sec.fixed_day, sec.fixed_slot, req_room_id)]
            else:
                domains[sec.id] = []
            continue

        candidate_rooms = list(rooms.values())
        if strict_capacity:
            candidate_rooms = [r for r in candidate_rooms if r.capacity >= sec.enrollment]
            if not candidate_rooms:
                candidate_rooms = sorted(rooms.values(), key=lambda x: x.capacity, reverse=True)[:3]

        values: List[Tuple[int, TimeSlot, str]] = []
        for day, day_name in enumerate(days):
            for slot_start in range(slots_per_day):
                if friday_only_first_slot and str(day_name).lower().startswith("fri") and slot_start != 0:
                    continue
                for room in candidate_rooms:
                    values.append((day, slot_start, room.id))
        domains[sec.id] = values

    return domains
