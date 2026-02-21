from builder import build_domain
from data_model import ClassSection, Room, Course

# Mock Data
# Rooms
r1 = Room(id="B._Ball_Court", name="B. Ball Court", capacity=30, room_type="lecture", available_time_slots=[])
r2 = Room(id="Normal_Room", name="Normal Room", capacity=30, room_type="lecture", available_time_slots=[])
rooms = {"B._Ball_Court": r1, "Normal_Room": r2}

# Sections
# 1. Evening Course - Should get Slot 3
sec_evening = ClassSection(id="S1", course_code="PEAC 100", lecturer_id="L1", section_title="S", course_type="Gen", course_level="100")

sections = [sec_evening]
config = {"days": ["Mon"], "slots_per_day": 3} # Normal slots 0,1,2

# Data Dictionary with NEW Dict Structure
data = {
    "config": config,
    "lecturers": {},
    "rooms": rooms,
    "courses": {},
    "sections": sections,
    "special_rooms": {"PEAC 100": {"room": "B. Ball Court", "slot": 3}}
}

# Run Builder
print("Building Domain...")
try:
    domain = build_domain(data)
    
    # Verify
    d_evening = domain["S1"]
    slots = set(val[1] for val in d_evening)
    rooms_assigned = set(val[2] for val in d_evening)

    print(f"Assigned Slots: {slots} (Expected {{3}})")
    print(f"Assigned Rooms: {rooms_assigned} (Expected {{'B._Ball_Court'}})")

    if 3 in slots and len(slots) == 1:
        print("PASS: Course restricted to Evening Slot 3.")
    else:
        print("FAIL: Slot assignment incorrect.")

    if "B._Ball_Court" in rooms_assigned and len(rooms_assigned) == 1:
        print("PASS: Course restricted to B. Ball Court.")
    else:
        print("FAIL: Room assignment incorrect.")

except AttributeError as e:
    print(f"CRITICAL FAIL: AttributeError during build_domain: {e}")
except Exception as e:
    print(f"CRITICAL FAIL: Exception during build_domain: {e}")
