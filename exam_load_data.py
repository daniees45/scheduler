import os
from typing import Dict, List
import pandas as pd

from data_model import Room, Course, ClassSection
from load_data import load_level_data, day_to_index

DEFAULT_EXAM_CONFIG = {
    "days": ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday"],
    "slots_per_day": 2,
    "strict_capacity": True,
    "slot_times": {
        0: ("9:00 AM", "12:00 PM"),
        1: ("2:00 PM", "5:00 PM")
    },
    "max_exams_per_day_per_cohort": 0,
    "cohort_mode": "level_semester",
    "single_room": True,
    "default_room_name": None
}


def _load_exam_config(config_path: str) -> dict:
    config = DEFAULT_EXAM_CONFIG.copy()
    if os.path.exists(config_path):
        import json
        try:
            with open(config_path, "r") as f:
                loaded_conf = json.load(f)
                config.update(loaded_conf)
        except Exception as e:
            print(f"[WARNING] Could not load exam config: {e}")
    return config


def _build_time_to_slot(slot_times: dict) -> Dict[str, int]:
    time_to_slot = {}
    for slot_idx, (start, _) in slot_times.items():
        if not start:
            continue
        normalized = str(start).strip().lower()
        time_to_slot[normalized] = int(slot_idx)
    return time_to_slot


def load_exam_data(paths: List[str],
                   rooms_csv_path: str = "rooms.csv",
                   curriculum_path: str = "curriculum.csv",
                   exam_config_path: str = "json/exam_config.json",
                   exam_config_overrides: dict | None = None,
                   rooms_override: dict | None = None):
    config = _load_exam_config(exam_config_path)
    if exam_config_overrides:
        config.update(exam_config_overrides)
    slot_times_raw = config.get("slot_times", {})
    slot_times = {int(k): tuple(v) for k, v in slot_times_raw.items()} if isinstance(slot_times_raw, dict) else {}
    config["slot_times"] = slot_times
    time_to_slot = _build_time_to_slot(config.get("slot_times", {}))

    dfs = []
    for path in paths:
        if os.path.exists(path):
            dfs.append(pd.read_csv(path))
    if not dfs:
        raise FileNotFoundError("No valid exam data files found.")

    combined_df = pd.concat(dfs, ignore_index=True)
    combined_df.columns = [c.strip().lower().replace(" ", "_") for c in combined_df.columns]
    from validators import validate_required_columns
    issues = validate_required_columns(combined_df, ["course_code", "course_title"], "exam input CSV")
    if issues:
        raise ValueError("; ".join(issues))
    combined_df = combined_df.dropna(subset=["course_code"])
    level_map = load_level_data()

    cohort_mode = str(config.get("cohort_mode", "level_semester")).strip().lower()

    room_db = {}
    if rooms_override:
        room_db = {str(name).strip(): int(cap) for name, cap in rooms_override.items()}
    elif os.path.exists(rooms_csv_path):
        rooms_df = pd.read_csv(rooms_csv_path)
        room_db = dict(zip(rooms_df["room_name"], rooms_df["capacity"]))

    rooms: Dict[str, Room] = {}
    for room_name in room_db.keys():
        room_id = room_name.replace(" ", "_")
        capacity = int(room_db.get(room_name, 30))
        rooms[room_id] = Room(
            id=room_id,
            name=room_name,
            capacity=capacity,
            room_type="exam",
            available_time_slots=[(d, s) for d in range(len(config["days"])) for s in range(config["slots_per_day"])]
        )
    if config.get("single_room") and rooms:
        target_name = config.get("default_room_name")
        selected_room_id = None
        if target_name:
            target_id = str(target_name).replace(" ", "_")
            if target_id in rooms:
                selected_room_id = target_id
            else:
                # Try match by name
                for room_id, room in rooms.items():
                    if room.name.lower() == str(target_name).lower():
                        selected_room_id = room_id
                        break

        if not selected_room_id:
            selected_room_id = next(iter(rooms))
            print(f"[INFO] Using default single exam room: {rooms[selected_room_id].name}")

        rooms = {selected_room_id: rooms[selected_room_id]}
        

    courses: Dict[str, Course] = {}
    sections: List[ClassSection] = []

    def _parse_int(value, default=0):
        try:
            if value is None:
                return default
            text = str(value).strip()
            if not text or text.lower() == "nan":
                return default
            return int(float(text))
        except Exception:
            return default

    def _normalize_level(raw_level: int) -> int:
        """
        Normalize level to 1-4 scale.
        Handles values like 100, 200, 300, 400, or 10000/20000, etc.
        """
        level = int(raw_level) if raw_level else 0
        if level <= 0:
            return 1
        # Reduce values like 10000 -> 100 -> 1
        while level >= 1000 and level % 100 == 0:
            level = level // 100
        if level in [100, 200, 300, 400]:
            return level // 100
        if level in [1, 2, 3, 4]:
            return level
        # Fallback: use first digit if possible
        for ch in str(level):
            if ch.isdigit() and ch != "0":
                return int(ch)
        return 1

    def _get_enrollment(row_obj) -> int:
        for key in [
            "no_of_students",
            "number_of_students",
            "num_students",
            "student_count",
            "students",
            "enrollment",
        ]:
            if key in row_obj:
                val = _parse_int(row_obj.get(key), None)
                if val is not None:
                    return val
        return 30

    def _get_invigilator(row_obj) -> str:
        for key in ["lecturer_name", "lecturer", "invigilator"]:
            if key in row_obj:
                name = str(row_obj.get(key, "")).strip()
                if name and name.lower() != "nan":
                    return name
        return "TBA"

    # Group sections by course_code if enabled
    group_by_course = config.get("group_sections_by_course", False)
    
    if group_by_course:
        # Group rows by course_code
        grouped = combined_df.groupby("course_code")
        
        for course_code, course_rows in grouped:
            course_code = str(course_code).strip().upper()
            if not course_code:
                continue
            
            # Use first row to extract level, title, semester
            first_row = course_rows.iloc[0]
            level = level_map.get(course_code, int(first_row.get("course_level", 0)))
            if not level:
                for ch in course_code:
                    if ch.isdigit():
                        level = int(ch)
                        break
            if not level:
                level = 1
            level = _normalize_level(level)
            
            title = str(first_row.get("course_title", course_code)).strip()
            
            if course_code not in courses:
                courses[course_code] = Course(
                    code=course_code,
                    title=title,
                    credit_hours=str(first_row.get("credit_hours", "EXAM")).strip() or "EXAM",
                    required_room_type="exam",
                    required_lessons=1
                )
            
            # Determine semester (from first row with non-empty semester)
            semester = None
            for _, row in course_rows.iterrows():
                sem = str(row.get("semester", "")).strip()
                if sem and sem.lower() != "nan":
                    semester = sem
                    break
            if not semester:
                from load_data import guess_semester
                semester = guess_semester(course_code, title)
            
            if cohort_mode == "level_semester":
                cohorts = {f"Level_{level * 100}_Sem{semester}"}
            else:
                cohorts = {f"Level_{level * 100}"}
            
            # Sum enrollments from all sections of this course
            total_enrollment = 0
            for _, row in course_rows.iterrows():
                total_enrollment += _get_enrollment(row)
            
            # Use first invigilator found (or TBA if none)
            invigilator_name = "TBA"
            for _, row in course_rows.iterrows():
                inv = _get_invigilator(row)
                if inv != "TBA":
                    invigilator_name = inv
                    break
            
            # Fixed day/slot from first row (if any)
            fixed_day = None
            fixed_slot = None
            if "day" in combined_df.columns and "start_time" in combined_df.columns:
                day = str(first_row.get("day", "")).strip()
                start_time = str(first_row.get("start_time", "")).split("-")[0].strip().lower()
                if day in day_to_index:
                    fixed_day = day_to_index[day]
                if start_time in time_to_slot:
                    fixed_slot = time_to_slot[start_time]
            
            sec_id = f"{course_code}_EXAM"
            sections.append(ClassSection(
                id=sec_id,
                course_code=course_code,
                lecturer_id=invigilator_name,
                section_title=title,
                course_type="Exam",
                course_level=str(level * 100),
                enrollment=total_enrollment,
                cohorts=cohorts,
                fixed_day=fixed_day,
                fixed_slot=fixed_slot,
                semester=semester,
                departmental_group="Other"
            ))
    
    else:
        # Original behavior: one section per row
        for idx, row in combined_df.iterrows():
            course_code = str(row.get("course_code", "")).strip().upper()
            if not course_code:
                continue

            level = level_map.get(course_code, int(row.get("course_level", 0)))
            if not level:
                for ch in course_code:
                    if ch.isdigit():
                        level = int(ch)
                        break
            if not level:
                level = 1
            level = _normalize_level(level)
            title = str(row.get("course_title", course_code)).strip()

            if course_code not in courses:
                courses[course_code] = Course(
                    code=course_code,
                    title=title,
                    credit_hours=str(row.get("credit_hours", "EXAM")).strip() or "EXAM",
                    required_room_type="exam",
                    required_lessons=1
                )

            semester = str(row.get("semester", "")).strip()
            if not semester or semester.lower() == "nan":
                from load_data import guess_semester
                semester = guess_semester(course_code, title)

            if cohort_mode == "level_semester":
                cohorts = {f"Level_{level * 100}_Sem{semester}"}
            else:
                cohorts = {f"Level_{level * 100}"}

            fixed_day = None
            fixed_slot = None
            if "day" in combined_df.columns and "start_time" in combined_df.columns:
                day = str(row.get("day", "")).strip()
                start_time = str(row.get("start_time", "")).split("-")[0].strip().lower()
                if day in day_to_index:
                    fixed_day = day_to_index[day]
                if start_time in time_to_slot:
                    fixed_slot = time_to_slot[start_time]

            invigilator_name = _get_invigilator(row)
            enrollment = _get_enrollment(row)

            sec_id = f"{course_code}_EXAM_{idx}"
            sections.append(ClassSection(
                id=sec_id,
                course_code=course_code,
                lecturer_id=invigilator_name,
                section_title=title,
                course_type="Exam",
                course_level=str(level * 100),
                enrollment=enrollment,
                cohorts=cohorts,
                fixed_day=fixed_day,
                fixed_slot=fixed_slot,
                semester=semester,
                departmental_group="Other"
            ))

    return {
        "sections": sections,
        "rooms": rooms,
        "courses": courses,
        "config": config,
        "exam_time_to_slot": time_to_slot
    }
