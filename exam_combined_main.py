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
import re

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

DEFAULT_DAYS = ["Mon W1", "Tue W1", "Wed W1", "Thu W1", "Fri W1", "Mon W2", "Tue W2", "Wed W2", "Thu W2", "Fri W2"]

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


def _normalize_grouped_exam_title(raw_title: str) -> str:
    """Strip section tags like [Sec A], (Section B), {sec c} from grouped exam titles."""
    title = str(raw_title or "").strip()
    if not title:
        return title

    title = re.sub(r"\s*[\[\(\{]\s*sec(?:tion)?\s*[A-Za-z0-9]+\s*[\]\)\}]", "", title, flags=re.IGNORECASE)
    title = re.sub(r"\s*[-/]\s*sec(?:tion)?\s*[A-Za-z0-9]+\s*$", "", title, flags=re.IGNORECASE)
    return re.sub(r"\s{2,}", " ", title).strip()


def _normalize_department_key(raw: Any) -> str:
    text = str(raw or "").strip().lower()
    return "".join(ch for ch in text if ch.isalnum())


def _normalize_slot_policy_map(raw: Any, slots_count: int) -> Dict[str, List[int]]:
    """
    Normalize a UI/API slot policy map to:
      key   -> normalized department token (alnum lowercase), or "*" for default
      value -> sorted unique list of valid slot indexes
    """
    if not isinstance(raw, dict):
        return {}

    normalized: Dict[str, List[int]] = {}
    for dept_raw, allowed_raw in raw.items():
        key = "*" if str(dept_raw).strip() == "*" else _normalize_department_key(dept_raw)
        if not key:
            continue

        if not isinstance(allowed_raw, list):
            continue

        slots: List[int] = []
        for item in allowed_raw:
            try:
                slot = int(item)
            except Exception:
                continue
            if 0 <= slot < slots_count:
                slots.append(slot)

        if slots:
            normalized[key] = sorted(set(slots))

    return normalized


def _allowed_slots_for_department(
    department: Any,
    slot_policy_map: Dict[str, List[int]],
    slots_count: int,
) -> List[int]:
    """
    Resolve allowed slot indexes for a department using policy map.
    Resolution order:
      1) exact normalized department key
      2) normalized key contained in a policy key or vice versa
      3) wildcard "*"
      4) all slots (no restriction)
    """
    all_slots = list(range(slots_count))
    if not slot_policy_map:
        return all_slots

    dept_key = _normalize_department_key(department)
    if dept_key in slot_policy_map:
        return slot_policy_map[dept_key]

    for policy_key, allowed in slot_policy_map.items():
        if policy_key == "*":
            continue
        if dept_key and (dept_key in policy_key or policy_key in dept_key):
            return allowed

    if "*" in slot_policy_map:
        return slot_policy_map["*"]

    return all_slots


def _generate_exam_days_from_dates(
    week1_start_date: Optional[str] = None,
    week2_start_date: Optional[str] = None,
) -> List[str]:
    """
    Generate a 10-day exam period from Monday start dates of each week.
    
    Parameters
    ----------
    week1_start_date : str, optional
        Monday of week 1 in YYYY-MM-DD format. If None, uses current Monday.
    week2_start_date : str, optional
        Monday of week 2 in YYYY-MM-DD format. If None, uses 7 days after week1.
    
    Returns
    -------
    List[str]
        10 day strings: "Mon 5/11", "Tue 5/12", ..., "Fri 5/22" (example format).
    """
    from datetime import datetime, timedelta
    
    try:
        if week1_start_date:
            w1_start = datetime.strptime(week1_start_date, "%Y-%m-%d")
        else:
            # Use today if it's a Monday, else previous Monday
            today = datetime.now()
            w1_start = today - timedelta(days=today.weekday())
        
        if week2_start_date:
            w2_start = datetime.strptime(week2_start_date, "%Y-%m-%d")
        else:
            w2_start = w1_start + timedelta(days=7)
        
        days_list: List[str] = []
        day_names = ["Mon", "Tue", "Wed", "Thu", "Fri"]
        
        # Week 1: Monday through Friday
        for i in range(5):
            d = w1_start + timedelta(days=i)
            day_str = f"{day_names[i]} {d.month}/{d.day}"
            days_list.append(day_str)
        
        # Week 2: Monday through Friday
        for i in range(5):
            d = w2_start + timedelta(days=i)
            day_str = f"{day_names[i]} {d.month}/{d.day}"
            days_list.append(day_str)
        
        return days_list
    
    except Exception as e:
        print(f"[WARNING] Failed to parse exam dates: {e}. Using default days.")
        return DEFAULT_DAYS


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
    slot_policy_map: Dict[str, List[int]] | None = None,
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
    # Default policy preserves current behavior:
    # - Nursing can use all slots (0,1,2)
    # - All other departments use morning/afternoon only (0,1)
    if slot_policy_map is None:
        slot_policy_map = {"Nursing": [0, 1, 2], "*": [0, 1]}
    normalized_slot_policy = _normalize_slot_policy_map(slot_policy_map, slots_count)

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
            title = _normalize_grouped_exam_title(str(first.get("course_title", code)).strip())

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
        "slot_policy_map": normalized_slot_policy,
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
    import time
    t0 = time.time()
    
    config  = data["config"]
    rooms   = data["rooms"]
    sections: List[ClassSection] = data["sections"]
    days    = config["days"]
    slots   = config["slots_per_day"]
    strict  = config.get("strict_capacity", True)
    slot_policy_map: Dict[str, List[int]] = config.get("slot_policy_map", {})

    domains: Dict[str, List[Any]] = {}
    
    min_domain_size = float('inf')
    max_domain_size = 0
    policy_pruned = 0
    
    for sec in sections:
        candidate_rooms = list(rooms.values())
        if strict:
            candidate_rooms = [r for r in candidate_rooms if r.capacity >= sec.enrollment]
            if not candidate_rooms:
                # Relax capacity if nothing fits
                candidate_rooms = sorted(rooms.values(), key=lambda r: r.capacity, reverse=True)[:3]

        values: List[Tuple[int, int, str]] = []
        allowed_slots = set(_allowed_slots_for_department(sec.departmental_group, slot_policy_map, slots))
        for day in range(len(days)):
            for slot in range(slots):
                if slot not in allowed_slots:
                    policy_pruned += len(candidate_rooms)
                    continue
                for room in candidate_rooms:
                    values.append((day, slot, room.id))
        
        domains[sec.id] = values
        domain_size = len(values)
        min_domain_size = min(min_domain_size, domain_size)
        max_domain_size = max(max_domain_size, domain_size)
    
    elapsed = time.time() - t0
    total_values = sum(len(v) for v in domains.values())
    avg_domain_size = total_values / len(sections) if sections else 0
    
    print(f"[DOMAIN BUILD ANALYSIS]")
    print(f"  Sections: {len(sections)}")
    print(f"  Rooms: {len(rooms)}")
    print(f"  Days per section: {len(days)}")
    print(f"  Slots per day: {slots}")
    print(f"  Min domain size: {min_domain_size}")
    print(f"  Max domain size: {max_domain_size}")
    print(f"  Avg domain size: {avg_domain_size:.1f}")
    print(f"  Total domain values: {total_values:,}")
    print(f"  Slot-policy values pruned: {policy_pruned:,}")
    print(f"  Domain build time: {elapsed:.2f}s")

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


def _level_no_same_time_conflict(
    assignment: Dict[str, Any],
    var_id: str,
    value: Any,
    sections_by_id: Dict[str, ClassSection],
) -> bool:
    """No two exams with the same course level can share the same (day, slot)."""
    day, slot, _ = value
    this_level = str(getattr(sections_by_id[var_id], "course_level", "")).strip()

    if not this_level:
        return True

    for other_id, other_val in assignment.items():
        if other_id == var_id:
            continue

        o_day, o_slot, _ = other_val
        if day == o_day and slot == o_slot:
            other_level = str(getattr(sections_by_id[other_id], "course_level", "")).strip()
            if other_level and other_level == this_level:
                return False

    return True


def _friday_only_first_slot(
    assignment: Dict[str, Any],
    var_id: str,
    value: Any,
    sections_by_id: Dict[str, ClassSection],
    days: List[str],
) -> bool:
    """Enforce Friday 9-12 PM only constraint.
    
    If exam is on Friday, it must be in slot 0 (9-12 AM).
    Days pattern for 2-week calendar: Mon(0), Tue(1), Wed(2), Thu(3), Fri(4), Mon(5), Tue(6), Wed(7), Thu(8), Fri(9).
    """
    day_idx, slot_idx, _ = value
    
    # Fridays are at indices 4 and 9 in a 10-day 2-week calendar
    is_friday = (day_idx % 5 == 4)
    
    if is_friday and slot_idx != 0:
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


def _department_slot_policy(
    var_id: str,
    value: Any,
    sections_by_id: Dict[str, ClassSection],
    slot_policy_map: Dict[str, List[int]],
    slots_count: int,
) -> bool:
    """Department-specific slot permission policy."""
    _, slot, _ = value
    dept = getattr(sections_by_id[var_id], "departmental_group", "")
    allowed = _allowed_slots_for_department(dept, slot_policy_map, slots_count)
    return slot in set(allowed)


def make_combined_exam_constraints(data: dict) -> list:
    sections_by_id = {sec.id: sec for sec in data["sections"]}
    rooms          = data["rooms"]
    config         = data["config"]
    max_per_day    = config.get("max_exams_per_day_per_cohort", 1)
    max_per_slot   = config.get("max_students_per_slot", 0)
    slot_policy_map: Dict[str, List[int]] = config.get("slot_policy_map", {})
    slots_count    = config.get("slots_per_day", len(SLOT_TIMES))
    days           = config.get("days", DEFAULT_DAYS)
    friday_only_first = config.get("friday_only_first_slot", False)

    def cohort_clash(assignment, var_id, value, csp_instance=None):
        return _cohort_no_conflict(assignment, var_id, value, sections_by_id)

    def level_same_slot_clash(assignment, var_id, value, csp_instance=None):
        return _level_no_same_time_conflict(assignment, var_id, value, sections_by_id)

    def day_limit(assignment, var_id, value, csp_instance=None):
        return _max_exams_per_day(assignment, var_id, value, sections_by_id, max_per_day)

    def capacity(assignment, var_id, value, csp_instance=None):
        return _room_capacity(assignment, var_id, value, sections_by_id, rooms, max_per_slot)

    def dept_slot_policy(assignment, var_id, value, csp_instance=None):
        return _department_slot_policy(var_id, value, sections_by_id, slot_policy_map, slots_count)

    def friday_slot_constraint(assignment, var_id, value, csp_instance=None):
        return _friday_only_first_slot(assignment, var_id, value, sections_by_id, days)

    # --- Student feedback clash constraints ---
    try:
        from student_feedback import get_student_clash_constraints
        semester = data["config"].get("semester") or os.environ.get("SCHEDULER_SEMESTER")
        feedback_clashes = get_student_clash_constraints(semester) if semester else []
        if feedback_clashes:
            def feedback_clash_wrapper(assignment, var_id, value, csp_instance=None):
                for _, c1, c2 in feedback_clashes:
                    if var_id in (c1, c2):
                        other_id = c2 if var_id == c1 else c1
                        if other_id in assignment:
                            d1, s1, _ = value
                            d2, s2, _ = assignment[other_id]
                            if d1 == d2 and s1 == s2:
                                return False
                return True
            constraints = [feedback_clash_wrapper, dept_slot_policy, level_same_slot_clash, cohort_clash, day_limit, capacity]
            if friday_only_first:
                constraints.insert(0, friday_slot_constraint)
            return constraints
    except Exception:
        pass
    
    constraints = [dept_slot_policy, level_same_slot_clash, cohort_clash, day_limit, capacity]
    if friday_only_first:
        constraints.insert(0, friday_slot_constraint)
    return constraints


# ---------------------------------------------------------------------------
# Exporter
# ---------------------------------------------------------------------------

def export_combined_exam_solution(solution: Dict[str, Any], data: dict, out_path: str) -> None:
    days          = data["config"]["days"]
    slot_times    = data["config"]["slot_times"]
    sections_by_id = {sec.id: sec for sec in data["sections"]}
    courses       = data["courses"]
    rooms         = data["rooms"]
    grouped_by_course = bool(data["config"].get("group_by_course", True))

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
            f"{sec.section_title} (ALL Sections)" if grouped_by_course else sec.section_title,
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
