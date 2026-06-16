# exam_main_web.py - Headless Exam Scheduler Core
import os
import pandas as pd

from exam_load_data import load_exam_data
from exam_builder import build_exam_domain
from exam_constraints import make_exam_constraints
from exam_export_data import export_exam_solution
from csp import CSP
from validators import validate_exam_data
from load_data import get_department_room_file
from typing import Callable, Optional


def run_headless_exam(input_file: str, output_file: str, department: str | None = None, hall_name: str | None = None, hall_capacity: int | None = None, blocked_schedule_paths=None, progress_callback: Optional[Callable] = None) -> bool:
    """Generate exam schedule with optional progress tracking
    
    Args:
        input_file: Path to exam courses CSV
        output_file: Path to output exam schedule
        department: Department name for filtering rooms
        hall_name: Exam hall name
        hall_capacity: Exam hall capacity override
        progress_callback: Function(percent, message, placed) for progress updates
    
    Returns:
        True if scheduling succeeds, False otherwise
    """
    history_data = "historical_exam_schedule.csv"
    
    def _emit_progress(percent: int, message: str):
        """Helper to emit progress updates"""
        if progress_callback:
            try:
                progress_callback(percent, message, 0)
            except Exception as e:
                print(f"[WARNING] Progress callback error: {e}")

    _emit_progress(5, "Initializing exam scheduler...")
    print(f"[EXAM] Processing {input_file}...")
    print(f"[EXAM] Output will be saved to: {output_file}")
    
    if department:
        rooms_path = get_department_room_file(department)
    else:
        rooms_path = "exam_rooms.csv" if os.path.exists("exam_rooms.csv") else "rooms.csv"
    
    _emit_progress(10, "Loading exam configuration...")

    # Force grouped exam scheduling so all sections of a course share one exam slot.
    exam_config_overrides = {
        "group_sections_by_course": True
    }
    rooms_override = None
    if hall_name:
        hall_name = hall_name.strip()
        exam_config_overrides.update({
            "single_room": True,
            "default_room_name": hall_name
        })
        print(f"[INFO] Exam hall requested: {hall_name}")

        if hall_capacity is not None:
            rooms_override = {hall_name: int(hall_capacity)}
            print(f"[INFO] Using provided hall capacity: {hall_capacity}")
        else:
            print("[WARNING] No hall capacity provided; falling back to room CSV capacity.")

    blocked_blocks = []
    from load_data import load_general_schedule_blocks
    lock_paths = []
    if isinstance(blocked_schedule_paths, list):
        lock_paths.extend(blocked_schedule_paths)
    elif blocked_schedule_paths:
        lock_paths.append(blocked_schedule_paths)

    for lock_path in lock_paths:
        if lock_path and os.path.exists(lock_path):
            extra_blocks = load_general_schedule_blocks(lock_path)
            blocked_blocks.extend(extra_blocks)
            print(f"[INFO] Added {len(extra_blocks)} exam smart-lock block(s) from {lock_path}")

    if blocked_blocks:
        print(f"[INFO] Total exam smart-lock blocks: {len(blocked_blocks)}")
        unique_lock_courses = {
            str(block.get('course_code', '')).strip().upper()
            for block in blocked_blocks
            if str(block.get('course_code', '')).strip()
        }
        unique_lecturer_slots = {
            (
                " ".join(str(block.get('lecturer_name', '')).strip().lower().replace('_', ' ').split()),
                block.get('day'),
                block.get('slot')
            )
            for block in blocked_blocks
            if str(block.get('lecturer_name', '')).strip()
        }
        print(
            f"[EXAM LOCK SUMMARY] blocks={len(blocked_blocks)} | "
            f"courses={len(unique_lock_courses)} | "
            f"lecturer_slots={len(unique_lecturer_slots)}"
        )

    _emit_progress(20, "Loading exam data and rooms...")
    data = load_exam_data(
        [input_file],
        rooms_csv_path=rooms_path,
        exam_config_overrides=exam_config_overrides,
        rooms_override=rooms_override,
        blocked_blocks=blocked_blocks
    )
    
    _emit_progress(35, "Validating exam data...")
    issues = validate_exam_data(data)
    if issues:
        for issue in issues:
            print(issue)
        _emit_progress(100, "Validation failed")
        return False

    _emit_progress(45, "Building exam domains...")
    domain = build_exam_domain(data)
    
    _emit_progress(50, "Preparing exam constraints...")
    max_exams_per_day = data["config"].get("max_exams_per_day_per_cohort", 1)
    max_students_per_hall = int(data["config"].get("max_students_per_hall", 0))
    
    print(f"[INFO] Max exams per cohort per day: {max_exams_per_day} (0 = unlimited)")
    print(f"[INFO] Max students per hall: {max_students_per_hall}")
    print(f"[INFO] Total sections to schedule: {len(data['sections'])}")
    print(f"[INFO] Available exam rooms: {len(data['rooms'])}")
    
    constraints = make_exam_constraints(
        data["sections"],
        data["rooms"],
        max_exams_per_day=max_exams_per_day,
        max_students_per_hall=max_students_per_hall
    )

    _emit_progress(55, "Initializing CSP solver...")
    
    # Track solver progress
    solve_percent = [60]  # Start at 60%, increment to 95%
    def _solver_progress(msg: str):
        """Capture CSP solver progress messages"""
        solve_percent[0] = min(95, solve_percent[0] + 1)
        _emit_progress(int(solve_percent[0]), "AI solving exam constraints...")
    
    solver = CSP(
        variables=data["sections"],
        domains=domain,
        constraints=constraints,
        lecturers={},
        preferences={},
        progress_callback=_solver_progress,
        timeout_seconds=120  # Increased timeout for exam scheduling (2 minutes)
    )

    _emit_progress(60, "AI solving exam constraints...")
    solution = solver.solve()
    if solution:
        _emit_progress(96, "Exporting exam schedule...")
        export_exam_solution(solution, data, out_path=output_file)
        
        _emit_progress(98, "Archiving exam schedule...")
        # Hybrid approach: Timestamped file + Master cumulative file
        if os.path.exists(output_file):
            try:
                from datetime import datetime
                df = pd.read_csv(output_file)
                timestamp_suffix = datetime.now().strftime('%Y%m%d_%H%M%S')
                
                # 1. Save as timestamped file (for version control)
                history_timestamped = history_data.replace('.csv', f'_{timestamp_suffix}.csv')
                df.to_csv(history_timestamped, index=False)
                print(f"[ARCHIVE] Timestamped exam: {history_timestamped}")
                
                # 2. Append to master historical CSV (for training)
                if os.path.exists(history_data):
                    df.to_csv(history_data, mode="a", header=False, index=False)
                    print(f"[ARCHIVE] Appended to master: {history_data}")
                else:
                    df.to_csv(history_data, index=False)
                    print(f"[ARCHIVE] Created master: {history_data}")
                
                # Upload to B2 if available
                try:
                    import sys
                    sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
                    from b2_handler import B2Handler
                    b2 = B2Handler(enable_cache=True, cache_dir="temp/b2_cache")
                    if b2.s3:
                        # Upload timestamped version
                        b2_history_key = f"csv/history/exam_{os.path.basename(history_timestamped)}"
                        b2.upload_file(history_timestamped, b2_history_key)
                        print(f"[B2] Timestamped: {b2_history_key}")
                        
                        # Upload master cumulative version
                        b2.upload_file(history_data, "csv/general/historical_exam_schedule.csv")
                        print(f"[B2] Master cumulative: csv/general/historical_exam_schedule.csv")
                except Exception as b2_err:
                    print(f"[B2] Could not upload exam history: {b2_err}")
                    
            except Exception as e:
                print(f"[WARNING] Could not save exam history: {e}")
        
        _emit_progress(100, "Exam schedule generated successfully")
        print(f"[SUCCESS] Exam schedule generated: {output_file}")
        return True

    _emit_progress(100, "No valid exam schedule found")
    print("[EXAM FAILURE] No valid exam timetable found within constraints.")
    return False


if __name__ == "__main__":
    import sys
    if len(sys.argv) < 3:
        print("Usage: python3 exam_main_web.py <input.csv> <output.csv>")
        sys.exit(1)

    in_file = sys.argv[1]
    out_file = sys.argv[2]
    success = run_headless_exam(in_file, out_file)
    sys.exit(0 if success else 1)
