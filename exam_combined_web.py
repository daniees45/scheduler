"""
exam_combined_web.py
---------------------
Headless (web-callable) version of the combined multi-department exam
scheduler.  Drop-in replacement for exam_main_web.run_headless_exam()
for the "all departments, shared rooms, 3-slot-per-day" mode.

Slots:
    0 → 9:00 AM  – 12:00 PM
    1 → 2:00 PM  –  5:00 PM
    2 → 6:00 PM  –  9:00 PM

API
---
    from exam_combined_web import run_combined_exam

    ok = run_combined_exam(
        input_files        = ["cs_exam.csv", "nursing_exam.csv"],
        output_file        = "combined_exam_out.csv",
        rooms_csv          = "csv/general/rooms.csv",  # optional
        rooms_override     = {"Main Hall": 600},       # optional, overrides CSV
        days               = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday"],
        max_per_day        = 1,          # max exams per cohort per day
        group_by_course    = True,       # merge sections of same course
        progress_callback  = fn,         # fn(percent, message, placed) optional
    )
"""

from __future__ import annotations

import os
import time
from typing import Callable, Dict, List, Optional

from exam_combined_main import (
    load_combined_exam_data,
    build_combined_exam_domain,
    make_combined_exam_constraints,
    export_combined_exam_solution,
    DEFAULT_ROOMS_CSV,
)
from csp import CSP


def run_combined_exam(
    input_files: List[str],
    output_file: str,
    rooms_csv: str = DEFAULT_ROOMS_CSV,
    rooms_override: Optional[Dict[str, int]] = None,
    slot_policy_map: Optional[Dict[str, List[int]]] = None,
    days: Optional[List[str]] = None,
    max_per_day: int = 1,
    max_students_per_slot: int = 0,
    group_by_course: bool = True,
    cohort_mode: str = "level_semester",
    friday_only_first_slot: bool = False,
    progress_callback: Optional[Callable] = None,
    timeout_seconds: int = 180,
) -> bool:
    """
    Generate a combined multi-department exam schedule.

    Parameters
    ----------
    input_files          : List of exam input CSV paths (any departments).
    output_file          : Path to write the output CSV.
    rooms_csv            : Path to rooms CSV (room_name, capacity).
    rooms_override       : Dict {room_name: capacity} – overrides CSV.
    slot_policy_map      : Dict {department: [allowed_slot_indexes]} with optional '*' default.
    days                 : List of day names. Defaults to Mon-Fri.
    max_per_day          : Max exams per cohort per day (0 = unlimited).
    max_students_per_slot: Hard cap on students per room per slot (0 = off).
    group_by_course      : Merge all sections of the same course into one exam.
    cohort_mode          : "level_semester" or "level".
    friday_only_first_slot: Restrict Friday exams to 9-12 slot only.
    progress_callback    : Optional fn(percent: int, message: str, placed: int).
    timeout_seconds      : CSP solver timeout.

    Returns
    -------
    True on success, False on failure.
    """

    def _emit(percent: int, message: str, placed: int = 0) -> None:
        if progress_callback:
            try:
                progress_callback(percent, message, placed)
            except Exception as e:
                print(f"[WARNING] Progress callback error: {e}")

    _emit(5, "Initialising combined exam scheduler...")

    # Validate inputs
    for path in input_files:
        if not os.path.exists(path):
            print(f"[ERROR] Input file not found: {path}")
            _emit(100, f"File not found: {path}")
            return False

    _emit(10, "Loading exam data from all departments...")
    t0_load = time.time()

    try:
        data = load_combined_exam_data(
            csv_paths=input_files,
            rooms_csv=rooms_csv,
            rooms_override=rooms_override,
            slot_policy_map=slot_policy_map,
            days=days,
            max_exams_per_day_per_cohort=max_per_day,
            max_students_per_slot=max_students_per_slot,
            cohort_mode=cohort_mode,
            group_by_course=group_by_course,
        )
        # Add Friday restriction flag to config
        data["config"]["friday_only_first_slot"] = friday_only_first_slot
    except Exception as exc:
        print(f"[ERROR] Failed to load exam data: {exc}")
        _emit(100, f"Data load error: {exc}")
        return False

    time_load = time.time() - t0_load
    sections_count = len(data["sections"])
    rooms_count    = len(data["rooms"])
    courses_count  = len(data["courses"])

    print(f"[INFO] Courses  : {courses_count}")
    print(f"[INFO] Sections : {sections_count}")
    print(f"[INFO] Rooms    : {rooms_count}")
    print(f"[INFO] Slots/day: 3  (9-12 | 2-5 | 6-9)")
    print(f"[TIMING] Data Load Time: {time_load:.2f}s")

    if sections_count == 0:
        print("[ERROR] No sections to schedule.")
        _emit(100, "No sections found in input files.")
        return False

    _emit(25, "Building scheduling domains...")
    t0_domain = time.time()
    try:
        domains = build_combined_exam_domain(data)
    except Exception as exc:
        print(f"[ERROR] Domain build failed: {exc}")
        _emit(100, f"Domain error: {exc}")
        return False

    time_domain = time.time() - t0_domain
    total_domain_values = sum(len(v) for v in domains.values())
    print(f"[TIMING] Domain Build Time: {time_domain:.2f}s | Total domain values: {total_domain_values:,}")

    _emit(40, "Applying exam constraints...")
    t0_constraints = time.time()
    constraints = make_combined_exam_constraints(data)
    time_constraints = time.time() - t0_constraints
    print(f"[TIMING] Constraint Application Time: {time_constraints:.2f}s | Total constraints: {len(constraints)}")

    # Wrap solver progress into our callback
    solve_pct = [50]

    def _solver_cb(msg: str) -> None:
        solve_pct[0] = min(92, solve_pct[0] + 1)
        _emit(solve_pct[0], "AI solving combined exam timetable...")

    _emit(50, "Solving combined exam timetable (CSP)...")
    t0_solve = time.time()

    solver = CSP(
        variables=data["sections"],
        domains=domains,
        constraints=constraints,
        lecturers={},
        preferences={},
        progress_callback=_solver_cb,
        timeout_seconds=timeout_seconds,
    )

    solution = solver.solve()
    time_solve = time.time() - t0_solve
    print(f"[TIMING] CSP Solve Time: {time_solve:.2f}s")

    if solution is None:
        print("[FAILED] No valid combined exam timetable found.")
        print(f"[TIMING] Total execution time: {time_load + time_domain + time_constraints + time_solve:.2f}s")
        _emit(100, "Solver failed – no valid timetable found.")
        return False

    placed   = len(solution)
    expected = sections_count
    print(f"[OK] Placed {placed}/{expected} exams.")
    _emit(95, f"Exporting timetable ({placed}/{expected} placed)...", placed)

    t0_export = time.time()
    try:
        export_combined_exam_solution(solution, data, output_file)
    except Exception as exc:
        print(f"[ERROR] Export failed: {exc}")
        _emit(100, f"Export error: {exc}")
        return False

    time_export = time.time() - t0_export
    total_time = time_load + time_domain + time_constraints + time_solve + time_export
    print(f"[TIMING] Export Time: {time_export:.2f}s")
    print(f"[TIMING] TOTAL EXECUTION TIME: {total_time:.2f}s")

    _emit(100, f"Done – combined exam timetable saved to {output_file}", placed)
    return True
