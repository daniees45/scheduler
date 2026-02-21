
# main_web.py - Headless AI Scheduler Core
import sys
import os
import pandas as pd
import time
from load_data import load_combined_data, get_department_group, get_department_room_file
from builder import build_domain
from constraints import make_constraints
from csp import CSP
from analyzer import train_model, load_trained_model
from export_data import export_solution
from validators import pre_flight_check


def _emit_progress(progress_callback, percent: int, message: str, placed: int = 0):
    """Safely emit progress updates to the web layer."""
    if not progress_callback:
        return
    try:
        progress_callback(percent, message, placed)
    except Exception:
        # Never fail scheduling due to UI progress callback issues
        pass

def run_headless(input_file, mode_choice, output_file, ai_preference, course_type="Departmental", 
                 department="1", availability_mode="1", exam_mode=False, general_schedule_path=None,
                 progress_callback=None):
    """
    Non-interactive version of the scheduler for Web/PHP integration.
    
    Parameters:
        input_file: Path to input CSV file
        mode_choice: Scheduling mode (1=interactive, 2=auto)
        output_file: Path to output CSV file
        ai_preference: AI preference level (1-3)
        course_type: "Departmental" or "General"
        department: Department ID ("1"=CS, "2"=Nursing, "3"=Theology, "4"=General)
        availability_mode: "1"=AI Auto-expand, "2"=Strict
        exam_mode: Boolean, True for exam scheduling
        general_schedule_path: Path to general schedule CSV
    
    Returns:
        tuple: (success: bool, accuracy: float)
    """
    history_data = "csv/general/historical_schedule.csv"
    model_file = "scheduling_model.pkl"
    temp_dir = "temp"  # Temporary directory for B2 downloads

    _emit_progress(progress_callback, 5, "Initializing AI scheduler...")
    
    # Log all input parameters for debugging
    print(f"[START] Parameters: course_type='{course_type}', department='{department}'")
    
    # 1. AI Memory Loading
    print(f"[AI] Loading Intelligence from {model_file}...")
    preference_model = load_trained_model(model_path=model_file)
    _emit_progress(progress_callback, 12, "Loading AI models...")
    
    # 2. Detect department from input file to use department-specific rooms
    # BUT: For general schedules, always use "General" department
    if str(course_type).lower() == "general":
        # General courses - use general rooms, don't infer specific department
        inferred_department = "General"
        print(f"[INFO] General course type detected - using General rooms")
    else:
        # Departmental courses - infer specific department
        inferred_department = "General"
        try:
            input_df = pd.read_csv(input_file)
            departments_found = set()
            for _, row in input_df.iterrows():
                # Prioritize source_type column if available, but skip generic values
                source_type = str(row.get('source_type', '')).strip()
                # Skip generic categories - these don't help identify specific departments
                if source_type and source_type not in ['', 'Departmental', 'General', 'Shared']:
                    departments_found.add(source_type)
                else:
                    # Fall back to course code parsing (more reliable for department detection)
                    course_code = str(row.get('course_code', '')).strip().upper()
                    if course_code:
                        dept = get_department_group(course_code)
                        if dept != "General":  # Only add non-generic departments
                            departments_found.add(dept)
            
            # Use the most common department (or first non-General one)
            if departments_found:
                if len(departments_found) == 1:
                    inferred_department = list(departments_found)[0]
                else:
                    # Multiple departments - prioritize non-General
                    non_general = [d for d in departments_found if d != "General"]
                    inferred_department = non_general[0] if non_general else "General"
            
            print(f"[INFO] Inferred Department: {inferred_department}")
        except Exception as e:
            print(f"[WARNING] Could not infer department: {e}")
        
        # Override with passed department parameter if explicitly specified (not a numeric ID)
        if department and department not in ['1', '2', '3', '4'] and department != 'General':
            # department is a name like "CS/IT/BBIS", use it directly
            inferred_department = department
            print(f"[INFO] Using explicitly specified Department: {inferred_department}")    
    # Get department-specific room file
    rooms_csv_path = get_department_room_file(inferred_department)
    print(f"[INFO] Using rooms file: {rooms_csv_path}")
    _emit_progress(progress_callback, 20, f"Using room pool: {inferred_department}")
    
    # 3. General Schedule Dependency (Auto-detect if not provided)
    blocked_blocks = []
    default_gen_path = "csv/general/vvu_general_schedule.csv"

    # General schedules should NOT use blocking.
    if str(course_type).lower() == "general" or inferred_department == "General":
        general_schedule_path = None
        print("[INFO] General schedule detected. Skipping general schedule blocks.")
    else:
        # Auto-detect most recent generated schedule from B2 if not explicitly provided
        if not general_schedule_path or general_schedule_path == "":
            try:
                # Use PHP script to get latest schedule from B2
                import subprocess
                php_script = os.path.join(os.path.dirname(__file__), 'web', 'api', 'get_latest_b2_schedule.php')
                result = subprocess.run(
                    ['php', php_script],
                    capture_output=True,
                    text=True,
                    timeout=10
                )
                
                if result.returncode == 0:
                    import json
                    b2_data = json.loads(result.stdout)
                    if b2_data.get('status') == 'success' and b2_data.get('file'):
                        # Download the latest schedule from B2 to temp directory
                        latest_file = b2_data['file']
                        temp_schedule = os.path.join(temp_dir, 'latest_general_schedule.csv')
                        
                        # Download from B2
                        download_result = subprocess.run(
                            ['php', '-r', f'''
                            require_once "{os.path.join(os.path.dirname(__file__), 'lib', 'B2Storage.php')}";
                            $b2 = new B2Storage();
                            $result = $b2->download("{latest_file}", "{temp_schedule}");
                            if ($result['success']) echo "success"; else echo "failed";
                            '''],
                            capture_output=True,
                            text=True,
                            timeout=15
                        )
                        
                        if download_result.returncode == 0 and os.path.exists(temp_schedule):
                            general_schedule_path = temp_schedule
                            print(f"[INFO] Auto-detected recent schedule from B2 for blocking: {latest_file}")
            except Exception as e:
                print(f"[WARNING] Could not auto-detect recent schedule from B2: {e}")
        
        gen_path = general_schedule_path or default_gen_path
        if gen_path and os.path.exists(gen_path):
            from load_data import load_general_schedule_blocks
            inferred_semester = None
            if os.path.exists(input_file):
                try:
                    input_df = pd.read_csv(input_file)
                    if 'Semester' in input_df.columns:
                        sem_series = input_df['Semester'].dropna().astype(str)
                        if not sem_series.empty:
                            inferred_semester = sem_series.mode().iloc[0]
                except Exception:
                    inferred_semester = None
            blocked_blocks = load_general_schedule_blocks(gen_path, semester=inferred_semester)
            print(f"[INFO] Loaded general schedule blocks from: {gen_path}")
        elif general_schedule_path:
            print(f"[WARNING] General schedule file not found: {general_schedule_path}")

    _emit_progress(progress_callback, 28, "Resolving schedule blocks...")

    # 4. Load Data with department-specific rooms
    print(f"[DATA] Processing {input_file}...")
    
    # IMPORTANT: Filter courses based on course_type BEFORE loading
    # This ensures we only process courses matching the selected category
    if str(course_type).lower() == "general":
        print(f"[DATA] Filtering to include ONLY General courses...")
        filtered_input = os.path.join(temp_dir, 'filtered_input.csv')
        try:
            import_df = pd.read_csv(input_file)
            # Filter to only rows where source_type is "General"
            general_only = import_df[import_df['source_type'].str.strip() == 'General'].copy()
            if len(general_only) < len(import_df):
                filtered_count = len(import_df) - len(general_only)
                print(f"[DATA] Filtered OUT {filtered_count} non-General courses")
            general_only.to_csv(filtered_input, index=False)
            input_file = filtered_input
            print(f"[DATA] Using {len(general_only)} General courses from filtered file")
        except Exception as e:
            print(f"[WARNING] Could not filter courses: {e}. Proceeding with all courses.")
    
    # For headless web mode, disable interactive prompts
    # Pass rooms_csv_path to use department-specific rooms only
    # Also ensure special_rooms.csv is loaded for pre-assigned courses
    special_rooms_path = "csv/general/special_rooms.csv"
    if not os.path.exists(special_rooms_path):
        special_rooms_path = "special_rooms.csv"  # Fallback to root
    print(f"[INFO] Using special rooms file: {special_rooms_path}")
    data = load_combined_data([input_file], interactive=False, rooms_csv_path=rooms_csv_path, special_rooms_path=special_rooms_path)
    _emit_progress(progress_callback, 40, "Loading timetable data and special rooms...")
    
    # 5. Solve
    print("[AI] Solving CSP Constraints...")
    if not pre_flight_check(data, min_lecturer_slots=3):
        print("[ERROR] Validation failed. Fix issues before retrying.")
        _emit_progress(progress_callback, 100, "Validation failed", 0)
        return False
    _emit_progress(progress_callback, 52, "Building domain and constraints...")

    domain = build_domain(data)
    constraints = make_constraints(data["sections"], data["rooms"], preference_model, 
                                   blocked_blocks=blocked_blocks, lecturers=data["lecturers"], 
                                   enable_flexibility=True)

    # Incremental solver-progress bridge for web polling UI
    solve_percent = [60.0]
    last_emit_ts = [0.0]

    def _solver_progress(_msg: str):
        now = time.time()
        if now - last_emit_ts[0] < 0.4:
            return
        last_emit_ts[0] = now
        solve_percent[0] = min(94.0, solve_percent[0] + 0.7)
        _emit_progress(progress_callback, int(solve_percent[0]), "AI solving constraints...")

    _emit_progress(progress_callback, 60, "AI solving constraints...")

    solver = CSP(
        data["sections"],
        domain,
        constraints,
        data["lecturers"],
        data["rooms"],
        preference_model,
        progress_callback=_solver_progress,
        timeout_seconds=120
    )
    solution = solver.solve()
    
    if solution:
        print(f"[SUCCESS] Solution found. Exporting to {output_file}...")
        _emit_progress(progress_callback, 96, "Exporting generated schedule...")
        export_solution(solution, data, out_path=output_file)
        
        # Calculate accuracy (percentage of courses scheduled)
        total_sections = len(data["sections"])
        placed_sections = len(solution)
        accuracy = (placed_sections / total_sections * 100) if total_sections > 0 else 0.0
        
        print(f"[STATS] Placed {placed_sections}/{total_sections} sections ({accuracy:.1f}%)")
        
        # Retrain AI model
        train_model(history_data=history_data, model_save_path=model_file)
        _emit_progress(progress_callback, 100, "Schedule generation complete", placed_sections)
        
        return True, accuracy
    else:
        print("[FAILURE] No valid schedule found within constraints.")
        _emit_progress(progress_callback, 100, "No valid schedule found", 0)
        return False, 0.0

if __name__ == "__main__":
    # Expecting: python3 main_web.py <input.csv> <output.csv>
    if len(sys.argv) < 3:
        print("Usage: python3 main_web.py <input.csv> <output.csv>")
        sys.exit(1)
        
    in_file = sys.argv[1]
    out_file = sys.argv[2]
    
    success, accuracy = run_headless(in_file, 2, out_file, 1)
    print(f"Final Result: {'SUCCESS' if success else 'FAILED'} - Accuracy: {accuracy:.1f}%")
    sys.exit(0 if success else 1)
