"""
exam_combined_main.py
---------------------
A standalone exam scheduler that combines ALL departments into one shared
room pool and uses three daily slots:
    Slot 0 : 9:00 AM  – 12:00 PM
    Slot 1 : 2:00 PM  –  5:00 PM
    Slot 2 : 6:00 PM  –  9:00 PM

Does NOT import from or modify any of the original exam_main / exam_main_web
logic.  It reuses the same low-level building blocks (CSP, data_model,
csp.py) but has its own loader, domain-builder, constraints, and exporter.

Usage:
    python exam_combined_main.py
"""

from __future__ import annotations

import csv
import os
from typing import Dict, List, Set, Tuple, Any, Optional

import pandas as pd

from data_model import Room, Course, ClassSection
from csp import CSP


# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------

SLOT_TIMES: Dict[int, Tuple[str, str]] = {
    0: ("9:00 AM",  "12:00 PM"),
    1: ("2:00 PM",  "5:00 PM"),
    2: ("6:00 PM",  "9:00 PM"),
}

DEFAULT_DAYS = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday"]

DEFAULT_ROOMS_CSV = os.path.join("csv", "general", "rooms.csv")


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _parse_int(value: Any, default: int = 0) -> int:
    try:
        text = str(value).strip()
        if not text or text.lower() == "nan":
            return default
        return int(float(text))
    except Exception:
        return default


def _normalize_level(raw: Any) -> int:
    """Convert 100/200/300/400 or 1/2/3/4 to 1-4 scale."""
    level = _parse_int(raw, 0)
    if level <= 0:
        return 1
    while level >= 1000 and level % 100 == 0:
        level //= 100
    if level in (100, 200, 300, 400):
        return level // 100
    if level in (1, 2, 3, 4):
        return level
    for ch in str(level):
        if ch.isdigit() and ch != "0":
            return int(ch)
    return 1


def _guess_semester(code: str, title: str) -> str:
    try:
        from load_data import guess_semester
        return guess_semester(code, title)
    except Exception:
        return "1"


# ---------------------------------------------------------------------------
# Data loading – multi-file, all departments merged
# ---------------------------------------------------------------------------

def load_combined_exam_data(
    csv_paths: List[str],
    rooms_csv: str = DEFAULT_ROOMS_CSV,
    days: List[str] | None = None,
    rooms_override: Dict[str, int] | None = None,
    max_exams_per_day_per_cohort: int = 1,
    max_students_per_slot: int = 0,
    cohort_mode: str = "level_semester",
    group_by_course: bool = True,
) -> dict:
    """
    Load exam courses from one or more CSV files (any department) and merge
    them into a single scheduling problem that uses the shared room pool.

    Returns a data dict compatible with the combined-exam builder / solver.
    """
    if days is None:
        days = DEFAULT_DAYS[:]

    # ---- Rooms ----
    room_db: Dict[str, int] = {}
    if rooms_override:
        room_db = {str(k).strip(): int(v) for k, v in rooms_override.items()}
    elif os.path.exists(rooms_csv):
        rdf = pd.read_csv(rooms_csv)
        rdf.columns = [c.strip().lower() for c in rdf.columns]
        room_col  = next((c for c in rdf.columns if "room" in c), None)
        cap_col   = next((c for c in rdf.columns if "cap" in c), None)
        if room_col and cap_col:
            for _, r in rdf.iterrows():
                name = str(r[room_col]).strip()
                cap  = _parse_int(r[cap_col], 30)
                if name:
                    room_db[name] = cap

    if not room_db:
        print("[WARNING] No rooms found – using a default 500-seat hall.")
        room_db = {"Main Hall": 500}

    slots_count = len(SLOT_TIMES)
    rooms: Dict[str, Room] = {}
    for rname, rcap in room_db.items():
        rid = rname.replace(" ", "_")
        rooms[rid] = Room(
            id=rid,
            name=rname,
            capacity=rcap,
            room_type="exam",
            available_time_slots=[
                (d, s)
                for d in range(len(days))
                for s in range(slots_count)
            ],
        )

    # ---- Course CSV files ----
    dfs: List[pd.DataFrame] = []
    for path in csv_paths:
        if os.path.exists(path):
            dfs.append(pd.read_csv(path))
        else:
            print(f"[WARNING] File not found, skipping: {path}")

    if not dfs:
        raise FileNotFoundError(f"No valid exam CSV files found in: {csv_paths}")

    df = pd.concat(dfs, ignore_index=True)
    df.columns = [c.strip().lower().replace(" ", "_") for c in df.columns]
    df = df.dropna(subset=["course_code"])

    def _get_enrollment(row: pd.Series) -> int:
        for key in ("no_of_students", "number_of_students", "num_students",
                    "student_count", "students", "enrollment"):
            if key in row and pd.notna(row[key]):
                v = _parse_int(row[key], None)
                if v is not None:
                    return v
        return 30

    def _get_invigilator(row: pd.Series) -> str:
        for key in ("lecturer_name", "lecturer", "invigilator"):
            if key in row:
                name = str(row[key]).strip()
                if name and name.lower() != "nan":
                    return name
        return "TBA"

    courses: Dict[str, Course] = {}
    sections: List[ClassSection] = []

    if group_by_course:
        for course_code_raw, group in df.groupby("course_code"):
            code = str(course_code_raw).strip().upper()
            if not code:
                continue

            first = group.iloc[0]
            level = _normalize_level(first.get("course_level", first.get("level", 0)))
            title = str(first.get("course_title", code)).strip()

            if code not in courses:
                courses[code] = Course(
                    code=code,
                    title=title,
                    credit_hours=str(first.get("credit_hours", "EXAM")).strip() or "EXAM",
                    required_room_type="exam",
                    required_lessons=1,
                )

            semester = None
            for _, row in group.iterrows():
                sem = str(row.get("semester", "")).strip()
                if sem and sem.lower() != "nan":
                    semester = sem
                    break
            if not semester:
                semester = _guess_semester(code, title)

            cohorts: Set[str]
            if cohort_mode == "level_semester":
                cohorts = {f"Level_{level * 100}_Sem{semester}"}
            else:
                cohorts = {f"Level_{level * 100}"}

            total_enrollment = sum(_get_enrollment(row) for _, row in group.iterrows())
            invigilator = next(
                (_get_invigilator(row) for _, row in group.iterrows()
                 if _get_invigilator(row) != "TBA"),
                "TBA",
            )

            # Department sourced from CSV column if present
            dept = str(first.get("department", "")).strip() or "General"

            sections.append(ClassSection(
                id=f"{code}_EXAM",
                course_code=code,
                lecturer_id=invigilator,
                section_title=title,
                course_type="Exam",
                course_level=str(level * 100),
                enrollment=total_enrollment,
                cohorts=cohorts,
                fixed_day=None,
                fixed_slot=None,
                requested_room=None,
                semester=semester,
                departmental_group=dept,
            ))

    else:
        for idx, row in df.iterrows():
            code = str(row.get("course_code", "")).strip().upper()
            if not code:
                continue

            level = _normalize_level(row.get("course_level", row.get("level", 0)))
            title = str(row.get("course_title", code)).strip()

            if code not in courses:
                courses[code] = Course(
                    code=code,
                    title=title,
                    credit_hours=str(row.get("credit_hours", "EXAM")).strip() or "EXAM",
                    required_room_type="exam",
                    required_lessons=1,
                )

            semester = str(row.get("semester", "")).strip()
            if not semester or semester.lower() == "nan":
                semester = _guess_semester(code, title)

            cohorts = (
                {f"Level_{level * 100}_Sem{semester}"}
                if cohort_mode == "level_semester"
                else {f"Level_{level * 100}"}
            )

            dept = str(row.get("department", "")).strip() or "General"

            sections.append(ClassSection(
                id=f"{code}_EXAM_{idx}",
                course_code=code,
                lecturer_id=_get_invigilator(row),
                section_title=title,
                course_type="Exam",
                course_level=str(level * 100),
                enrollment=_get_enrollment(row),
                cohorts=cohorts,
                fixed_day=None,
                fixed_slot=None,
                requested_room=None,
                semester=semester,
                departmental_group=dept,
            ))

    config = {
        "days": days,
        "slots_per_day": slots_count,
        "slot_times": SLOT_TIMES,
        "strict_capacity": True,
        "max_exams_per_day_per_cohort": max_exams_per_day_per_cohort,
        "max_students_per_slot": max_students_per_slot,
        "cohort_mode": cohort_mode,
        "group_by_course": group_by_course,
    }

    return {
        "sections": sections,
        "rooms": rooms,
        "courses": courses,
        "config": config,
    }


# ---------------------------------------------------------------------------
# Domain builder
# ---------------------------------------------------------------------------

def build_combined_exam_domain(data: dict) -> Dict[str, List[Any]]:
    """
    Build CSP domains: each section can be placed at any (day, slot, room)
    triple where the room has enough capacity.  All three daily slots are
    available for every day.
    """
    config  = data["config"]
    rooms   = data["rooms"]
    sections: List[ClassSection] = data["sections"]
    days    = config["days"]
    slots   = config["slots_per_day"]
    strict  = config.get("strict_capacity", True)

    domains: Dict[str, List[Any]] = {}
    for sec in sections:
        candidate_rooms = list(rooms.values())
        if strict:
            candidate_rooms = [r for r in candidate_rooms if r.capacity >= sec.enrollment]
            if not candidate_rooms:
                # Relax capacity if nothing fits
                candidate_rooms = sorted(rooms.values(), key=lambda r: r.capacity, reverse=True)[:3]

        values: List[Tuple[int, int, str]] = []
        for day in range(len(days)):
            for slot in range(slots):
                for room in candidate_rooms:
                    values.append((day, slot, room.id))
        domains[sec.id] = values

    return domains


# ---------------------------------------------------------------------------
# Constraints
# ---------------------------------------------------------------------------

def _cohort_no_conflict(
    assignment: Dict[str, Any],
    var_id: str,
    value: Any,
    sections_by_id: Dict[str, ClassSection],
) -> bool:
    """No two exams sharing a student cohort can clash at the same (day, slot)."""
    day, slot, _ = value
    this_cohorts = sections_by_id[var_id].cohorts

    for other_id, other_val in assignment.items():
        if other_id == var_id:
            continue
        o_day, o_slot, _ = other_val
        if day == o_day and slot == o_slot:
            if this_cohorts.intersection(sections_by_id[other_id].cohorts):
                return False
    return True


def _max_exams_per_day(
    assignment: Dict[str, Any],
    var_id: str,
    value: Any,
    sections_by_id: Dict[str, ClassSection],
    limit: int,
) -> bool:
    """No cohort sits more than `limit` exams on the same day (0 = unlimited)."""
    if limit <= 0:
        return True

    day, _, _ = value
    this_cohorts = sections_by_id[var_id].cohorts
    counts: Dict[str, int] = {c: 0 for c in this_cohorts}

    for other_id, other_val in assignment.items():
        if other_id == var_id:
            continue
        if other_val[0] != day:
            continue
        for cohort in this_cohorts.intersection(sections_by_id[other_id].cohorts):
            counts[cohort] = counts.get(cohort, 0) + 1
            if counts[cohort] >= limit:
                return False
    return True


def _room_capacity(
    assignment: Dict[str, Any],
    var_id: str,
    value: Any,
    sections_by_id: Dict[str, ClassSection],
    rooms: Dict[str, Room],
    max_per_slot: int,
) -> bool:
    """
    Total students in the same room + slot must not exceed the room's capacity
    (or max_per_slot if set).
    """
    day, slot, room_id = value
    room = rooms.get(room_id)
    if not room:
        return False

    limit = room.capacity
    if max_per_slot > 0:
        limit = min(limit, max_per_slot)

    total = sections_by_id[var_id].enrollment
    for other_id, other_val in assignment.items():
        if other_id == var_id:
            continue
        o_day, o_slot, o_room = other_val
        if o_day == day and o_slot == slot and o_room == room_id:
            total += sections_by_id[other_id].enrollment
            if total > limit:
                return False
    return total <= limit


def make_combined_exam_constraints(data: dict) -> list:
    sections_by_id = {sec.id: sec for sec in data["sections"]}
    rooms          = data["rooms"]
    config         = data["config"]
    max_per_day    = config.get("max_exams_per_day_per_cohort", 1)
    max_per_slot   = config.get("max_students_per_slot", 0)

    def cohort_clash(assignment, var_id, value, csp_instance=None):
        return _cohort_no_conflict(assignment, var_id, value, sections_by_id)

    def day_limit(assignment, var_id, value, csp_instance=None):
        return _max_exams_per_day(assignment, var_id, value, sections_by_id, max_per_day)

    def capacity(assignment, var_id, value, csp_instance=None):
        return _room_capacity(assignment, var_id, value, sections_by_id, rooms, max_per_slot)

    return [cohort_clash, day_limit, capacity]


# ---------------------------------------------------------------------------
# Exporter
# ---------------------------------------------------------------------------

def export_combined_exam_solution(solution: Dict[str, Any], data: dict, out_path: str) -> None:
    days          = data["config"]["days"]
    slot_times    = data["config"]["slot_times"]
    sections_by_id = {sec.id: sec for sec in data["sections"]}
    courses       = data["courses"]
    rooms         = data["rooms"]

    header = [
        "Course Code", "Course Title", "Department",
        "Invigilator", "No of Students", "Level", "Cohorts",
        "Room", "Day", "Time",
    ]
    rows = [header]

    # Sort by day then slot for a readable timetable
    sorted_items = sorted(
        solution.items(),
        key=lambda kv: (kv[1][0], kv[1][1], kv[0]),
    )

    for sec_id, (day_idx, slot_idx, room_id) in sorted_items:
        sec    = sections_by_id[sec_id]
        course = courses[sec.course_code]
        room   = rooms[room_id]

        start, end = slot_times.get(slot_idx, (str(slot_idx), ""))
        time_label = f"{start} - {end}" if end else str(slot_idx)

        rows.append([
            course.code,
            sec.section_title,
            sec.departmental_group,
            sec.lecturer_id,
            sec.enrollment,
            sec.course_level,
            "; ".join(sorted(sec.cohorts)),
            room.name,
            days[day_idx],
            time_label,
        ])

    with open(out_path, "w", newline="", encoding="utf-8") as f:
        csv.writer(f).writerows(rows)
    print(f"[DONE] Combined exam timetable saved to: {out_path}")


# ---------------------------------------------------------------------------
# CLI entry point
# ---------------------------------------------------------------------------

def main() -> None:
    print("=" * 60)
    print("  Combined Multi-Department Exam Scheduler")
    print("  Slots: 9-12  |  2-5  |  6-9")
    print("=" * 60)

    # Collect CSV paths
    csv_paths: List[str] = []
    print("\nEnter exam CSV file paths (one per line, blank line to finish):")
    while True:
        path = input("  CSV path: ").strip()
        if not path:
            break
        if not path.endswith(".csv"):
            path += ".csv"
        csv_paths.append(path)

    if not csv_paths:
        print("[ERROR] No input files provided.")
        return

    # Output
    out_path = input("\nOutput CSV path for combined exam timetable: ").strip()
    if not out_path.endswith(".csv"):
        out_path += ".csv"

    # Rooms
    rooms_csv = input(
        f"\nRooms CSV path [default: {DEFAULT_ROOMS_CSV}]: "
    ).strip() or DEFAULT_ROOMS_CSV

    # Days
    days_input = input(
        "\nDays (comma-separated) or blank for Mon-Fri: "
    ).strip()
    days = [d.strip() for d in days_input.split(",") if d.strip()] or DEFAULT_DAYS[:]

    # Limits
    max_per_day_raw = input("\nMax exams per cohort per day [default 1, 0=unlimited]: ").strip()
    max_per_day = _parse_int(max_per_day_raw, 1) if max_per_day_raw else 1

    group_raw = input("\nGroup sections by course code? (y/n) [default y]: ").strip().lower()
    group_by_course = group_raw != "n"

    print("\n[INFO] Loading data...")
    data = load_combined_exam_data(
        csv_paths=csv_paths,
        rooms_csv=rooms_csv,
        days=days,
        max_exams_per_day_per_cohort=max_per_day,
        group_by_course=group_by_course,
    )

    print(f"[INFO] Courses loaded   : {len(data['courses'])}")
    print(f"[INFO] Sections to place: {len(data['sections'])}")
    print(f"[INFO] Rooms available  : {len(data['rooms'])}")
    print(f"[INFO] Days             : {', '.join(days)}")
    print(f"[INFO] Slots per day    : {len(SLOT_TIMES)}  (9-12 | 2-5 | 6-9)")

    print("\n[INFO] Building domains...")
    domains = build_combined_exam_domain(data)

    print("[INFO] Building constraints...")
    constraints = make_combined_exam_constraints(data)

    print("[INFO] Solving (CSP)...")
    solver = CSP(
        variables=data["sections"],
        domains=domains,
        constraints=constraints,
        lecturers={},
        preferences={},
        timeout_seconds=180,
    )

    solution = solver.solve()
    if solution is None:
        print("\n[FAILED] No valid combined exam timetable found.")
        print("  Try: fewer days, larger room capacity, 0 for max-per-day limit.")
        return

    placed   = len(solution)
    expected = len(data["sections"])
    print(f"\n[OK] Placed {placed}/{expected} exams.")

    export_combined_exam_solution(solution, data, out_path)


if __name__ == "__main__":
    main()
