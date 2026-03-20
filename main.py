from load_data import load_combined_data, get_department_group, get_department_room_file
from builder import build_domain
from constraints import make_constraints
from csp import CSP
from analyzer import train_model, load_trained_model, initialize_q_learner, record_schedule_batch, save_q_learner_model
from export_data import export_solution
from ensemble_models import FeasibilityEnsemble, QualityEnsemble
from genetic_algorithm import solve_with_ga
from timetable_engine.ai_unified_scheduler import AIUnifiedScheduler
from exam_main_web import run_headless_exam
from personal_scheduler import BusyBlock, build_personal_schedule, parse_time_safe
import os
import sys
import pandas as pd
import argparse

OUTPUT_DIR = os.path.join(os.path.dirname(__file__), "tkinter_app", "output")
PERSONAL_TIMETABLE_FILE = os.path.join(OUTPUT_DIR, "personal_timetable.csv")

def select_departments_interactive(input_df):
    """
    Prompt user to select which departments or general courses to schedule.
    
    Args:
        input_df: DataFrame with course data
    
    Returns:
        Tuple[DataFrame, str, str]: (filtered DataFrame, selected department key, selected label)
    """
    # Fixed option list requested by user (label, internal key)
    department_options = [
        ("General", "General"),
        ("CS/IT/BBIS", "CS/IT/BBIS"),
        ("Nursing", "Nursing"),
        ("Theology", "Theology"),
        ("Business", "Business"),
        ("Education", "Education"),
        ("Biomedical", "BiomedicalEngineering"),
        ("Development Studies", "DevelopmentStudies"),
    ]
    
    print("\n" + "=" * 70)
    print("DEPARTMENT/COURSE SELECTION")
    print("=" * 70)
    print("Which courses would you like to schedule?")
    print()
    
    # Show options (no "All departments" option)
    options = {}
    option_num = 1
    for label, dept_key in department_options:
        options[option_num] = (dept_key, label)
        display_label = "General courses" if label == "General" else label
        print(f"{option_num}. {display_label}")
        option_num += 1
    
    print("=" * 70)
    
    # Get user selection
    if len(options) == 1:
        # Only one option, auto-select
        selection_key, selection_label = list(options.values())[0]
        print(f"[AUTO] Only one option available: {selection_label}")
    else:
        while True:
            try:
                choice = int(input("Select option (number): ").strip())
                if choice in options:
                    selection_key, selection_label = options[choice]
                    break
                print(f"[ERROR] Invalid choice. Enter a number between 1 and {len(options)}.")
            except ValueError:
                print("[ERROR] Please enter a valid number.")
    
    # Filter by selected department
    filtered_df = []
    for _, row in input_df.iterrows():
        # Use source_type if available (more reliable than course_code parsing)
        if 'source_type' in row and str(row.get('source_type', '')).strip():
            course_type = str(row.get('source_type', '')).strip()
            # Check if user selected General courses
            if selection_key == "General":
                if course_type == "General":
                    filtered_df.append(row)
            else:
                # For departmental selections, use course code detection
                course_code = str(row.get('course_code', '')).strip().upper()
                dept = get_department_group(course_code) if course_code else "General"
                if dept == selection_key:
                    filtered_df.append(row)
        else:
            # Fallback: use course code parsing
            course_code = str(row.get('course_code', '')).strip().upper()
            dept = get_department_group(course_code) if course_code else "General"
            if dept == selection_key:
                filtered_df.append(row)
    
    filtered_df = pd.DataFrame(filtered_df)
    print(f"[INFO] Scheduling: {selection_label} ({len(filtered_df)} courses)")
    if filtered_df.empty:
        print(f"[WARNING] No courses found for '{selection_label}'. Check your input CSV.")
    return filtered_df, selection_key, selection_label

def select_availability_mode_interactive():
    """
    Prompt user to choose between AI automatic mode or manual control for lecturer availability.
    
    Returns:
        String: "ai_automatic" or "manual_control"
    """
    print("\n" + "=" * 70)
    print("AVAILABILITY MANAGEMENT MODE")
    print("=" * 70)
    print("How should the AI handle lecturer availability decisions?")
    print()
    print("1. AI AUTOMATIC")
    print("   - If a lecturer has insufficient available days, AI automatically")
    print("   - expands availability to find a valid schedule")
    print("   - (Faster, less interactive)")
    print()
    print("2. MANUAL CONTROL") 
    print("   - If a lecturer has insufficient available days, you'll be prompted")
    print("   - You can choose to expand availability or let the solver try alternatives")
    print("   - (More control, more interactive)")
    print("=" * 70)
    
    while True:
        choice = input("Select mode (1 or 2): ").strip()
        if choice == "1":
            print("[SELECTED] AI Automatic Mode - AI will make availability decisions")
            return "ai_automatic"
        elif choice == "2":
            print("[SELECTED] Manual Control Mode - You will be prompted for availability decisions")
            return "manual_control"
        else:
            print("[ERROR] Invalid choice. Enter 1 or 2.")

def select_semester_interactive():
    """
    Prompt user to choose the semester for scheduling and blocking.
    Returns "1", "2", or None (All semesters).
    """
    print("\n" + "=" * 70)
    print("SEMESTER SELECTION")
    print("=" * 70)
    print("Which semester are you generating the timetable for?")
    print("1. Semester 1")
    print("2. Semester 2")
    print("3. All semesters (no filtering)")
    print("=" * 70)
    while True:
        choice = input("Select semester (1, 2, or 3): ").strip()
        if choice in ["1", "2"]:
            print(f"[SELECTED] Semester {choice}")
            return choice
        if choice == "3":
            print("[SELECTED] All semesters (no filtering)")
            return None
        print("[ERROR] Invalid choice. Enter 1, 2, or 3.")

def select_general_schedule_path_interactive(default_path: str) -> str | None:
    """
    Prompt user to choose which General schedule CSV to use for blocking.
    Returns a path or None to skip general blocking.
    """
    print("\n" + "=" * 70)
    print("GENERAL SCHEDULE SOURCE")
    print("=" * 70)
    print("Which General schedule should be used to block time slots?")
    print("1. Use default: vvu_general_schedule.csv")
    print("2. Provide a different CSV path")
    print("3. Skip General blocking (no blocks applied)")
    print("=" * 70)
    while True:
        choice = input("Select option (1, 2, or 3): ").strip()
        if choice == "1":
            return default_path
        if choice == "2":
            custom_path = input("Enter path to General schedule CSV: ").strip()
            if custom_path and not custom_path.endswith(".csv"):
                custom_path += ".csv"
            return custom_path
        if choice == "3":
            return None
        print("[ERROR] Invalid choice. Enter 1, 2, or 3.")

def validate_department_relevance(input_df: pd.DataFrame, selected_department: str, threshold: float = 0.8) -> bool:
    """
    Validate that the input CSV mostly belongs to the selected department.
    Returns True if valid, False if below threshold.
    """
    if not selected_department or selected_department == "General":
        return True

    total = len(input_df)
    if total == 0:
        return True

    related = 0
    for _, row in input_df.iterrows():
        course_code = str(row.get('course_code', '')).strip().upper()
        dept = get_department_group(course_code) if course_code else "General"
        if dept == selected_department:
            related += 1

    ratio = related / total
    if ratio < threshold:
        pct = ratio * 100
        print("\n" + "=" * 70)
        print("[ALERT] Department Mismatch Detected")
        print("=" * 70)
        print(f"Selected department: {selected_department}")
        print(f"Related courses in file: {related}/{total} ({pct:.1f}%)")
        print("The input file does not appear to be at least 80% related to the selected department.")
        print("Please choose the correct department or provide the right department CSV.")
        print("=" * 70 + "\n")
        return False
    return True

def main(input_file=None, output_file=None, interactive=True, availability_mode=None, model_choice=None):
    """
    Main scheduling workflow with optional command-line args for non-interactive mode.
    
    Args:
        input_file: Path to input CSV (default: prompts user)
        output_file: Path to output CSV (default: prompts user)
        interactive: If True, prompt user. If False, use defaults or args.
        availability_mode: "ai_automatic" or "manual_control" (if None, will prompt if interactive)
    """
    
    
    # B2 Context Handling with caching enabled
    try:
        from b2_handler import B2Handler
        b2 = B2Handler(enable_cache=True, cache_dir="temp/b2_cache")
    except:
        b2 = None

    history_data = "historical_schedule.csv"
    model_file = "scheduling_model.pkl"
    q_model_file = "q_model.pkl"

    # If running in B2 mode (temp dir exists), use temp paths
    if os.path.exists("temp/historical_schedule.csv"):
        history_data = "temp/historical_schedule.csv"
    if os.path.exists("temp"):
        model_file = "temp/scheduling_model.pkl"
        q_model_file = "temp/q_model.pkl"
        # Attempt to download models if they exist and weren't in csv/
        if b2 and b2.s3:
            if not os.path.exists(model_file):
                 b2.download_file("scheduling_model.pkl", model_file)
            if not os.path.exists(q_model_file):
                 b2.download_file("q_model.pkl", q_model_file)
            if not os.path.exists("temp/feasibility_classifier.pkl"):
                 b2.download_file("feasibility_classifier.pkl", "temp/feasibility_classifier.pkl")
            
            # Download NN model if exists (try both .h5 and .pkl)
            nn_model_dir = "models/nn"
            os.makedirs(nn_model_dir, exist_ok=True)
            if not os.path.exists(f"{nn_model_dir}/nn_scheduler.h5"):
                try:
                    b2.download_file("models/nn_scheduler.h5", f"{nn_model_dir}/nn_scheduler.h5")
                    print(f"[B2] Downloaded NN model: nn_scheduler.h5")
                except:
                    pass  # Model might not exist yet or might be .pkl
            if not os.path.exists(f"{nn_model_dir}/nn_scheduler.pkl"):
                try:
                    b2.download_file("models/nn_scheduler.pkl", f"{nn_model_dir}/nn_scheduler.pkl")
                    print(f"[B2] Downloaded NN model: nn_scheduler.pkl")
                except:
                    pass
            if not os.path.exists(f"{nn_model_dir}/nn_scheduler.json"):
                try:
                    b2.download_file("models/nn_scheduler.json", f"{nn_model_dir}/nn_scheduler.json")
                    print(f"[B2] Downloaded NN metadata: nn_scheduler.json")
                except:
                    pass
    
    # Initialize Q-Learning agent for real-time preference learning
    print("[Q-LEARN] Initializing Q-Learning preference model...")
    q_learner = initialize_q_learner(q_model_file, learning_rate=0.1, discount_factor=0.95)

    
    # Input file selection
    if input_file is None:
        if not interactive:
            input_file = "courses_input.csv"
            print(f"[NON-INTERACTIVE] Using default input: {input_file}")
        else:
            input_file = input("Enter the path to the current scheduling data CSV file: ")
    
    if not input_file.endswith(".csv"):
        input_file += ".csv"
    
    if not os.path.exists(input_file):
        print(f"[ERROR] File {input_file} not found!")
        return False
    
    # Load and optionally filter by department
    print(f"\n[INFO] Loading input file: {input_file}")
    input_df = pd.read_csv(input_file)
    
    selected_department = None
    selected_department_label = None
    general_schedule_path = None
    # Department selection (interactive mode only)
    if interactive:
        input_df, selected_department, selected_department_label = select_departments_interactive(input_df)
        if selected_department and selected_department != "General":
            general_schedule_path = select_general_schedule_path_interactive("vvu_general_schedule.csv")

    if not validate_department_relevance(input_df, selected_department):
        return False
    
    # Output file selection
    if output_file is None:
        if not interactive:
            # Auto-generate output name from input
            output_file = input_file.replace(".csv", "_schedule.csv")
            print(f"[NON-INTERACTIVE] Using default output: {output_file}")
        else:
            output_file = input("\nEnter the desired output CSV file path for the schedule: ")
    
    if not output_file.endswith(".csv"):
        output_file += ".csv"
    
    # Availability mode selection (interactive mode only)
    if interactive and availability_mode is None:
        availability_mode = select_availability_mode_interactive()
    elif not interactive and availability_mode is None:
        availability_mode = "ai_automatic"  # Default to automatic in non-interactive
        print(f"[NON-INTERACTIVE] Using default availability mode: {availability_mode}")

    if interactive and model_choice is None:
        model_choice = select_model_interactive()
    elif model_choice is None:
        model_choice = "csp"

    # Semester selection (interactive mode only)
    if interactive:
        selected_semester = select_semester_interactive()
        # Filter courses by selected semester (only if a specific semester is chosen)
        if selected_semester in ["1", "2"] and 'Semester' in input_df.columns:
            original_count = len(input_df)
            input_df = input_df[input_df['Semester'].astype(str).str.strip() == selected_semester].copy()
            filtered_count = len(input_df)
            print(f"[INFO] Filtered to Semester {selected_semester}: {filtered_count} courses (from {original_count} total)")
            if input_df.empty:
                print(f"[ERROR] No courses found for Semester {selected_semester}!")
                return False
        elif selected_semester is None:
            print("[INFO] All semesters selected: no filtering applied.")
    else:
        # Try to infer semester from input if available
        selected_semester = None
        if 'Semester' in input_df.columns:
            semester_series = input_df['Semester'].dropna().astype(str)
            if not semester_series.empty:
                selected_semester = semester_series.mode().iloc[0]
    
    # Auto-detection of departments in filtered data
    print(f"\n[INFO] Analyzing input file for departments...")
    departments = set()
    for _, row in input_df.iterrows():
        course_code = str(row.get('course_code', '')).strip().upper()
        if course_code:
            dept = get_department_group(course_code)
            if dept != "General":
                departments.add(dept)
    
    print(f"[INFO] Detected departments: {', '.join(sorted(departments)) if departments else 'None (General courses only)'}")
    print(f"[INFO] Auto-loading department-specific room files...")
    
    rooms_path = get_department_room_file(selected_department or "General")
    print(f"[INFO] Using rooms file: {rooms_path}")
    
    # Auto-detect and load blocked blocks from General Schedule if it exists
    # IMPORTANT: Do NOT load general schedule when generating general timetable itself
    # (it will be used as future reference for department scheduling)
    blocked_blocks = []
    default_gen_path = "vvu_general_schedule.csv"
    if general_schedule_path is None:
        general_schedule_path = default_gen_path

    # Enforce rule: General schedule must exist before scheduling any department
    # Skip this check when generating the General timetable itself
    if selected_department and selected_department != "General":
        attempts = 0
        while True:
            if not general_schedule_path:
                print("\n" + "=" * 70)
                print("[ALERT] General Schedule Required")
                print("=" * 70)
                print("A General course timetable must exist before scheduling department courses.")
                print("Please provide the General schedule CSV.")
                print("=" * 70 + "\n")
                if interactive:
                    general_schedule_path = select_general_schedule_path_interactive(default_gen_path)
                else:
                    return False

            if not general_schedule_path:
                return False

            if not os.path.exists(general_schedule_path):
                print(f"[WARNING] General schedule file '{general_schedule_path}' not found.")
                attempts += 1
                if attempts >= 2 or not interactive:
                    print("[ALERT] Unable to load a valid General schedule after 2 attempts. Stopping.")
                    return False
                general_schedule_path = select_general_schedule_path_interactive(default_gen_path)
                continue

            try:
                from load_data import load_general_schedule_blocks
                blocked_blocks = load_general_schedule_blocks(general_schedule_path, semester=selected_semester)
                print(f"[INFO] Loaded general schedule from '{general_schedule_path}'. {len(blocked_blocks)} blocks applied.")
                if len(blocked_blocks) == 0:
                    print("\n" + "=" * 70)
                    print("[ALERT] General Schedule Missing Semester Blocks")
                    print("=" * 70)
                    print(f"No General blocks found for Semester {selected_semester}.")
                    print("Please ensure the General timetable includes this semester.")
                    print("=" * 70 + "\n")
                    attempts += 1
                    if attempts >= 2 or not interactive:
                        print("[ALERT] No semester blocks after 2 attempts. Stopping.")
                        return False
                    general_schedule_path = select_general_schedule_path_interactive(default_gen_path)
                    continue
                break
            except Exception as e:
                print(f"[WARNING] Could not load blocked blocks: {e}")
                attempts += 1
                if attempts >= 2 or not interactive:
                    print("[ALERT] Failed to load General schedule after 2 attempts. Stopping.")
                    return False
                general_schedule_path = select_general_schedule_path_interactive(default_gen_path)
    else:
        # When generating General timetable, do NOT load general schedule
        # The output will become the new general reference
        if selected_department == "General":
            print("[INFO] Generating General timetable. Not loading existing general schedule blocks.")
            blocked_blocks = []
    
    # Save filtered input to temp file for load_combined_data
    filtered_input_file = input_file.replace(".csv", "_filtered_temp.csv")
    input_df.to_csv(filtered_input_file, index=False)
    print(f"[INFO] Filtered input saved to: {filtered_input_file}")

    #1. AI Memory Training/Loading
    if not os.path.exists(model_file) and os.path.exists(history_data):
        print("Training AI model from historical data...")
        preference_model = train_model(history_data=history_data, model_save_path=model_file)
    else:
        print("Loading trained AI model...")
        preference_model = load_trained_model(model_path=model_file)
        
    #2. Load current data (using filtered file)
    print("Loading current scheduling data...")
    try:
        data = load_combined_data([filtered_input_file], rooms_csv_path=rooms_path, interactive=False)
    except FileNotFoundError:
        print(f"Error: {filtered_input_file} not found.")
        return False
        
    #3. Build Constraints and Domains Mapping
    print("Building constraints and domains...")
    from validators import pre_flight_check
    if not pre_flight_check(data, min_lecturer_slots=3):
        print("[ERROR] Validation failed. Fix issues before retrying.")
        if os.path.exists(filtered_input_file):
            os.remove(filtered_input_file)
        return False
    
    # Load or train ML feasibility ensemble
    classifier_path = "feasibility_ensemble.pkl"
    classifier = None
    history_data = "historical_schedule.csv"
    
    if os.path.exists(classifier_path):
        print("[ML] Loading feasibility ensemble...")
        classifier = FeasibilityEnsemble(classifier_path)
        classifier.load()
    elif os.path.exists(history_data):
        print("[ML] Training new feasibility ensemble from history...")
        classifier = FeasibilityEnsemble(classifier_path)
        X, y = classifier.prepare_training_data(history_data)
        if X is not None:
            classifier.train(X, y)
            classifier.save()
            try:
                # Safely check for explain_model attribute (removed in refactored ensemble)
                if hasattr(classifier, 'explain_model'):
                    classifier.explain_model(
                        X,
                        output_csv="shap_feature_importance.csv",
                        output_plot="shap_summary.png",
                        max_samples=200
                    )
            except Exception as e:
                print(f"[SHAP] Skipped explainability: {e}")
            print("[ML] Classifier ready for domain pruning")
    else:
        print("[ML] No historical data for classifier training. Proceeding without prediction.")
    
    domain = build_domain(data, classifier=classifier, confidence_threshold=0.25)
    # Pass lecturers to make_constraints to enable flexible availability assignment
    constraints = make_constraints(data["sections"], data["rooms"], preference_model, blocked_blocks, 
                                   lecturers=data["lecturers"], enable_flexibility=True)
    
    #4 Solver execution
    print("Initializing solver...")
    csp = CSP(variables=data["sections"], 
              domains=domain,
              constraints=constraints,
              lecturers=data["lecturers"],
              rooms=data["rooms"],
              preferences=preference_model,
              interactive=interactive,
              availability_decision_mode=availability_mode)

    solution = None
    solver_mode = (model_choice or "csp").lower().strip()

    if solver_mode == "csp":
        print("Solving with CSP...")
        solution = csp.solve()

    if solution is None and solver_mode in ["csp", "ensemble", "hybrid", "ga", "rl", "nn"]:
        if solver_mode == "csp":
            print("\n[INFO] CSP solver failed or timed out. Attempting AI Ensemble fallback...")
        else:
            print(f"\n[INFO] Solving with AI model: {solver_mode.upper()}...")

        try:
            course_dicts = []
            for sec in data["sections"]:
                course_dicts.append({
                    "code": sec.course_code,
                    "title": sec.section_title or sec.course_code,
                    "department": sec.departmental_group,
                    "departmental_group": sec.departmental_group,
                    "credits": sec.credit_hours,
                    "level": getattr(sec, 'course_level', '100'),
                    "semester": getattr(sec, 'semester', '1'),
                    "lecturer": data["lecturers"].get(sec.lecturer_id).name if data["lecturers"].get(sec.lecturer_id) else "TBD"
                })

            room_dicts = [{"name": r.name, "capacity": r.capacity, "department": getattr(r, 'department', 'General')} for r in data["rooms"].values()]

            ai_solver = AIUnifiedScheduler(
                data_path=".",
                courses=course_dicts,
                lecturers=[l.name for l in data["lecturers"].values()],
                rooms=room_dicts,
                time_slots=["07:00 AM - 09:30 AM", "10:00 AM - 12:30 PM", "02:00 PM - 04:30 PM", "05:00 PM - 06:00 PM"],
                days=["Monday", "Tuesday", "Wednesday", "Thursday", "Friday"],
                enable_ga=solver_mode in ["ga", "hybrid"],
                enable_rl=solver_mode in ["rl", "hybrid"],
                enable_nn=solver_mode in ["nn", "hybrid"],
                enable_ensemble=solver_mode in ["ensemble", "hybrid", "csp"],
                strict_departmental=data.get('config', {}).get('strict_departmental', True),
                is_general_session=data.get('config', {}).get('is_general_session', False),
                verbose=True
            )

            if solver_mode == "ga":
                ai_solution, _, _ = ai_solver.schedule_with_ga()
            elif solver_mode == "rl":
                ai_solution, _, _ = ai_solver.schedule_with_rl(num_episodes=100)
            elif solver_mode == "nn":
                ai_solution, _, _ = ai_solver.schedule_with_nn()
            elif solver_mode == "hybrid":
                results = ai_solver.schedule_all(use_rl_episodes=50)
                ai_solution = results.get('best_schedule')
            else:
                ai_solution, _, _ = ai_solver.schedule_with_ensemble()

            if ai_solution:
                solution = ai_solver._finalize_and_enrich_schedule(ai_solution)
                print(f"[AI] ✓ {solver_mode.upper()} solver found a feasible schedule!")
            else:
                print(f"[AI] ✗ {solver_mode.upper()} solver failed to find a solution")
        except Exception as e:
            print(f"[ERROR] AI solver error ({solver_mode.upper()}): {e}")
            solution = None
    
    if solution is None:
        print("\n[Failed] No valid timetable found with selected model")
        # Clean up filtered file
        if os.path.exists(filtered_input_file):
            os.remove(filtered_input_file)
        return False
    
    #5. Export solution
    print("Exporting the solution...")
    export_solution(solution, data, out_path=output_file)

    #6. Calculate and display accuracy
    if isinstance(solution, dict):
        accuracy = csp.calculate_accuracy(solution)
    else:
        accuracy = _calculate_list_solution_accuracy(solution, data)
    print(f"\n[AI Evaluation] Schedule Accuracy: {accuracy:.2f}% (Lecturer Preference Match)")
    
    print("Self-Learning: Archiving this success into history...")
    # Hybrid approach: Timestamped file + Master cumulative file
    new_results = pd.read_csv(output_file)
    
    from datetime import datetime
    timestamp_suffix = datetime.now().strftime('%Y%m%d_%H%M%S')
    
    # 1. Save as timestamped file (for version control/tracking)
    history_timestamped = history_data.replace('.csv', f'_{timestamp_suffix}.csv')
    new_results.to_csv(history_timestamped, index=False)
    print(f"[ARCHIVE] Timestamped version: {history_timestamped}")
    
    # 2. Append to master historical CSV (for training - all data combined)
    if os.path.exists(history_data):
        new_results.to_csv(history_data, mode='a', header=False, index=False)
        print(f"[ARCHIVE] Appended to master: {history_data}")
    else:
        new_results.to_csv(history_data, index=False)
        print(f"[ARCHIVE] Created master: {history_data}")
    
    # Record schedule feedback for Q-Learning (assume initial schedule is acceptable)
    print("[Q-LEARN] Recording schedule preferences...")
    record_schedule_batch(new_results)
    save_q_learner_model()
    
    #Retrain the model with the new data
    train_model(history_data=history_data, model_save_path=model_file)
    
    # Retrain ML ensemble with new data
    classifier_path = "feasibility_ensemble.pkl"
    print("[ML] Retraining feasibility ensemble with new schedule...")
    classifier = FeasibilityEnsemble(classifier_path)
    X, y = classifier.prepare_training_data(history_data)
    if X is not None and len(X) > 10:  # Need enough samples
        classifier.train(X, y)
        classifier.save()
        try:
            if hasattr(classifier, 'explain_model'):
                classifier.explain_model(
                    X,
                    output_csv="shap_feature_importance.csv",
                    output_plot="shap_summary.png",
                    max_samples=200
                )
        except Exception as e:
            print(f"[SHAP] Skipped explainability: {e}")
        print("[ML] Classifier improved and saved.")
    
    print(f"\nDONE! Timetable saved to {output_file}. AI intelligence improved.")

    # Sync Results back to B2
    if b2 and b2.s3:
        print(f"[INFO] Uploading results to B2...")
        # Upload Generated Schedule
        b2_output_key = f"csv/final/{os.path.basename(output_file)}"
        b2.upload_file(output_file, b2_output_key)
        
        # Upload Timestamped History (version control)
        b2_history_key = f"csv/history/{os.path.basename(history_timestamped)}"
        b2.upload_file(history_timestamped, b2_history_key)
        print(f"[B2] Timestamped: {b2_history_key}")
        
        # Upload Master Historical CSV (cumulative for training)
        b2.upload_file(history_data, "csv/general/historical_schedule.csv")
        print(f"[B2] Master cumulative: csv/general/historical_schedule.csv")
        
        # Upload Models
        b2.upload_file(model_file, "scheduling_model.pkl")
        b2.upload_file(q_model_file, "q_model.pkl")
        if os.path.exists("temp/feasibility_classifier.pkl"):
             b2.upload_file("temp/feasibility_classifier.pkl", "feasibility_classifier.pkl")
        
        # Upload NN model if it exists (fixed filename)
        nn_model_h5 = "models/nn/nn_scheduler.h5"
        nn_model_pkl = "models/nn/nn_scheduler.pkl"
        nn_model_meta = "models/nn/nn_scheduler.json"
        if os.path.exists(nn_model_h5):
            b2.upload_file(nn_model_h5, "models/nn_scheduler.h5")
            print(f"[B2] Uploaded NN model: models/nn_scheduler.h5")
        elif os.path.exists(nn_model_pkl):
            b2.upload_file(nn_model_pkl, "models/nn_scheduler.pkl")
            print(f"[B2] Uploaded NN model: models/nn_scheduler.pkl")
        if os.path.exists(nn_model_meta):
            b2.upload_file(nn_model_meta, "models/nn_scheduler.json")
            print(f"[B2] Uploaded NN metadata: models/nn_scheduler.json")
             
        print(f"[SUCCESS] Results synced to B2: {b2_output_key}")
    
    # Clean up filtered file
    if os.path.exists(filtered_input_file):
        os.remove(filtered_input_file)
    
    return True


def _prompt_path(prompt: str) -> str:
    path = input(prompt).strip()
    return path


def _prompt_choice(prompt: str, options: dict) -> str:
    while True:
        choice = input(prompt).strip()
        if choice in options:
            return options[choice]
        print(f"[ERROR] Invalid choice. Options: {', '.join(options.keys())}")


def select_model_interactive() -> str:
    print("\n" + "=" * 70)
    print("MODEL SELECTION")
    print("=" * 70)
    print("Choose scheduling model:")
    print("1. CSP (constraint solver + ensemble fallback)")
    print("2. Ensemble (AI only)")
    print("3. Hybrid (GA + RL + NN + Ensemble winner)")
    print("4. GA")
    print("5. RL")
    print("6. NN")
    print("=" * 70)

    choice_map = {
        "1": "csp",
        "2": "ensemble",
        "3": "hybrid",
        "4": "ga",
        "5": "rl",
        "6": "nn",
    }
    return _prompt_choice("Select model (1-6): ", choice_map)


def _calculate_list_solution_accuracy(solution: list, data: dict) -> float:
    if not isinstance(solution, list) or len(solution) == 0:
        return 0.0

    matches = 0
    total = 0
    day_to_idx = {"Monday": 0, "Tuesday": 1, "Wednesday": 2, "Thursday": 3, "Friday": 4}
    slot_to_idx = {
        "07:00 AM - 09:30 AM": 0,
        "10:00 AM - 12:30 PM": 1,
        "02:00 PM - 04:30 PM": 2,
        "05:00 PM - 06:00 PM": 3,
    }

    lecturer_by_name = {l.name: l for l in data.get("lecturers", {}).values()}
    for item in solution:
        lecturer_name = str(item.get("lecturer", item.get("lecturer_name", ""))).strip()
        day = str(item.get("day", "")).strip()
        time_slot = str(item.get("time_slot", item.get("time", ""))).strip()

        if not lecturer_name or day not in day_to_idx or time_slot not in slot_to_idx:
            continue

        lecturer = lecturer_by_name.get(lecturer_name)
        if not lecturer:
            continue

        total += 1
        if (day_to_idx[day], slot_to_idx[time_slot]) in lecturer.available_time_slots:
            matches += 1

    if total == 0:
        return 0.0
    return (matches / total) * 100.0


def _load_personal_events_from_csv(csv_path: str):
    events = []
    if not os.path.exists(csv_path):
        return events
    df = pd.read_csv(csv_path)
    for _, row in df.iterrows():
        day = str(row.get("day", "")).strip()
        start_time = parse_time_safe(str(row.get("start_time", "")))
        end_time = parse_time_safe(str(row.get("end_time", "")))
        if not day or not start_time or not end_time:
            continue
        events.append(
            BusyBlock(
                day=day,
                start=start_time,
                end=end_time,
                label=str(row.get("title", "")).strip(),
                source="personal"
            )
        )
    return events


def _ensure_personal_timetable_file():
    os.makedirs(OUTPUT_DIR, exist_ok=True)
    if not os.path.exists(PERSONAL_TIMETABLE_FILE):
        with open(PERSONAL_TIMETABLE_FILE, "w", newline="", encoding="utf-8") as f:
            writer = pd.DataFrame(columns=["course_code", "course_title", "day", "time"])
            writer.to_csv(f, index=False)


def _normalize_schedule_df(df: pd.DataFrame) -> pd.DataFrame:
    if df is None or df.empty:
        return pd.DataFrame(columns=["course_code", "course_title", "day", "time"])
    cols = {c.lower().strip(): c for c in df.columns}

    def _pick(*names):
        for name in names:
            if name in cols:
                return cols[name]
        return None

    code_col = _pick("course code", "course_code", "course")
    title_col = _pick("course title", "course_title", "title")
    day_col = _pick("day")
    time_col = _pick("time")

    if not day_col or not time_col:
        return pd.DataFrame(columns=["course_code", "course_title", "day", "time"])

    data = pd.DataFrame({
        "course_code": df[code_col] if code_col else "",
        "course_title": df[title_col] if title_col else df[code_col] if code_col else "",
        "day": df[day_col],
        "time": df[time_col]
    })
    for col in ["course_code", "course_title", "day", "time"]:
        data[col] = data[col].fillna("").astype(str).str.strip()
    return data[data["day"] != ""].copy()


def _save_personal_timetable_df(df: pd.DataFrame):
    _ensure_personal_timetable_file()
    df.to_csv(PERSONAL_TIMETABLE_FILE, index=False)


def _import_personal_timetable_cli(path: str) -> bool:
    if not path or not os.path.exists(path):
        print("[ERROR] Timetable file not found.")
        return False

    if path.lower().endswith(".pdf"):
        try:
            import tabula
            tables = tabula.read_pdf(path, pages="all", multiple_tables=True, lattice=True)
            if not tables:
                print("[ERROR] No tables found in PDF.")
                return False
            df = pd.concat(tables, ignore_index=True)
        except Exception as exc:
            print(f"[ERROR] PDF import failed: {exc}")
            return False
    else:
        try:
            df = pd.read_csv(path)
        except Exception as exc:
            print(f"[ERROR] CSV import failed: {exc}")
            return False

    df = _normalize_schedule_df(df)
    if df.empty:
        print("[ERROR] Could not detect Day/Time columns in file.")
        return False

    _save_personal_timetable_df(df)
    print(f"[SUCCESS] Imported personal timetable to {PERSONAL_TIMETABLE_FILE}")
    return True


def _auto_generate_personal_timetable_cli(dept_label: str, level: str | None, semester: str | None) -> bool:
    if not dept_label:
        print("[ERROR] Department is required for auto-generate.")
        return False

    filename = None
    dept_map = {
        "General": "general.csv",
        "CS/IT/BBIS": "comp_final.csv",
        "Nursing": "nursing_rooms.csv",
        "Theology": "theology_rooms.csv",
        "Business": "business_rooms.csv",
        "Education": "education_rooms.csv",
        "Biomedical": "biomedical_engineering_rooms.csv",
        "DevelopmentStudies": "development_studies_rooms.csv"
    }
    filename = dept_map.get(dept_label)
    if not filename:
        print("[ERROR] Unknown department.")
        return False

    candidates = [
        os.path.join(OUTPUT_DIR, filename),
        os.path.join(os.path.dirname(__file__), filename),
        os.path.join(os.path.dirname(__file__), "final", filename)
    ]
    existing = [p for p in candidates if os.path.exists(p)]
    if not existing:
        print("[ERROR] Department timetable not found. Please import a CSV/PDF first.")
        return False

    timetable_source = max(existing, key=lambda p: os.path.getmtime(p))
    try:
        df = pd.read_csv(timetable_source)
    except Exception as exc:
        print(f"[ERROR] Failed to read timetable CSV: {exc}")
        return False

    df.columns = [c.strip().lower().replace(" ", "_") for c in df.columns]
    if level and "course_level" in df.columns:
        df = df[df["course_level"].astype(str).str.strip() == str(level)]
    if semester and "semester" in df.columns:
        df = df[df["semester"].astype(str).str.strip() == str(semester)]

    df = _normalize_schedule_df(df)
    if df.empty:
        print("[WARNING] No matching rows for selected level/semester.")
        return False

    _save_personal_timetable_df(df)
    print(f"[SUCCESS] Auto-generated personal timetable at {PERSONAL_TIMETABLE_FILE}")
    return True


def _load_room_capacities(rooms_csv_path: str) -> dict:
    if not rooms_csv_path or not os.path.exists(rooms_csv_path):
        return {}
    try:
        df = pd.read_csv(rooms_csv_path)
    except Exception:
        return {}
    caps = {}
    for _, row in df.iterrows():
        name = str(row.get("room_name", "")).strip() or str(row.get("room", "")).strip()
        if not name:
            continue
        try:
            cap = int(row.get("capacity", 0))
        except Exception:
            cap = 0
        caps[name] = cap
    return caps


def _max_enrollment_from_exam_csv(csv_path: str) -> int:
    try:
        df = pd.read_csv(csv_path)
        df.columns = [c.strip().lower().replace(" ", "_") for c in df.columns]
        enrollment_cols = ["no_of_students", "number_of_students", "num_students", "student_count", "students", "enrollment"]
        max_enrollment = 0
        for col in enrollment_cols:
            if col in df.columns:
                max_enrollment = max(max_enrollment, pd.to_numeric(df[col], errors="coerce").fillna(0).max())
        return int(max_enrollment) if max_enrollment else 0
    except Exception:
        return 0


def run_exam_engine_interactive():
    print("\n" + "=" * 70)
    print("EXAM ENGINE")
    print("=" * 70)
    input_file = _prompt_path("Enter exam input CSV path: ")
    output_file = _prompt_path("Enter exam output CSV path: ")

    dept_options = {
        "1": "General",
        "2": "CS/IT/BBIS",
        "3": "Nursing",
        "4": "Theology",
        "5": "Business",
        "6": "Education",
        "7": "BiomedicalEngineering",
        "8": "DevelopmentStudies"
    }
    print("Select department:")
    for key, label in dept_options.items():
        display = label.replace("Engineering", "") if label == "BiomedicalEngineering" else label
        print(f"{key}. {display}")
    dept = _prompt_choice("Department (number): ", dept_options)

    hall = input("Enter exam hall name (required): ").strip()
    if not hall:
        print("[ERROR] Exam hall is required.")
        return False
    while True:
        cap_raw = input("Enter exam hall capacity (required): ").strip()
        try:
            hall_capacity = int(cap_raw)
            if hall_capacity <= 0:
                raise ValueError()
            break
        except Exception:
            print("[ERROR] Please enter a valid positive integer capacity.")

    max_enrollment = _max_enrollment_from_exam_csv(input_file)
    if max_enrollment and hall_capacity < max_enrollment:
        print(f"[WARNING] Hall capacity ({hall_capacity}) is below max enrollment ({max_enrollment}).")
        print("You may want to enter a larger hall or split exams across halls.")

    success = run_headless_exam(input_file, output_file, dept, hall, hall_capacity)
    if success:
        print(f"[SUCCESS] Exam schedule saved to {output_file}")
    else:
        print("[FAILURE] Exam scheduling failed.")
    return success


def run_personal_engine_interactive():
    print("\n" + "=" * 70)
    print("PERSONAL ENGINE")
    print("=" * 70)
    role = _prompt_choice("Role (1=student, 2=lecturer): ", {"1": "student", "2": "lecturer"})
    source_choice = _prompt_choice(
        "Timetable source (1=Import CSV/PDF, 2=Auto-generate, 3=Use existing path): ",
        {"1": "import", "2": "auto", "3": "path"}
    )

    timetable_csv = ""
    if source_choice == "import":
        import_path = _prompt_path("Enter timetable CSV/PDF path to import: ")
        if not _import_personal_timetable_cli(import_path):
            return False
        timetable_csv = PERSONAL_TIMETABLE_FILE
    elif source_choice == "auto":
        dept = _prompt_choice(
            "Department (1=General,2=CS/IT/BBIS,3=Nursing,4=Theology,5=Business,6=Education,7=Biomedical,8=DevelopmentStudies): ",
            {
                "1": "General",
                "2": "CS/IT/BBIS",
                "3": "Nursing",
                "4": "Theology",
                "5": "Business",
                "6": "Education",
                "7": "Biomedical",
                "8": "DevelopmentStudies"
            }
        )
        level = input("Level (optional, e.g., 100): ").strip() or None
        semester = input("Semester (optional, 1 or 2): ").strip() or None
        if not _auto_generate_personal_timetable_cli(dept, level, semester):
            return False
        timetable_csv = PERSONAL_TIMETABLE_FILE
    else:
        timetable_csv = _prompt_path("Enter timetable CSV path to use: ")

    exam_csv = _prompt_path("Enter exam CSV path (optional, press Enter to skip): ")
    events_csv = _prompt_path("Enter personal events CSV path (optional): ")
    output_csv = _prompt_path("Enter output CSV path for personal schedule: ")

    personal_events = _load_personal_events_from_csv(events_csv) if events_csv else []
    _, suggestions = build_personal_schedule(
        base_dir=os.path.dirname(timetable_csv) or os.getcwd(),
        personal_events=personal_events,
        role=role,
        timetable_path=timetable_csv,
        exam_path=exam_csv or None
    )

    os.makedirs(os.path.dirname(output_csv) or ".", exist_ok=True)
    with open(output_csv, "w", newline="", encoding="utf-8") as f:
        writer = pd.DataFrame([
            {
                "type": "suggestion",
                "title": s.title,
                "day": s.day,
                "start_time": s.start.strftime("%H:%M"),
                "end_time": s.end.strftime("%H:%M"),
                "score": f"{s.score:.1f}",
                "reason": s.reason
            } for s in suggestions
        ])
        if not writer.empty:
            writer.to_csv(f, index=False)
        else:
            f.write("type,title,day,start_time,end_time,score,reason\n")

    print(f"[SUCCESS] Personal schedule suggestions saved to {output_csv}")
    return True


def run_menu():
    print("\n" + "=" * 70)
    print("UNIFIED SCHEDULER")
    print("=" * 70)
    print("1. Timetable Engine")
    print("2. Exam Engine")
    print("3. Personal Engine")
    print("=" * 70)
    choice = _prompt_choice("Select engine (1/2/3): ", {"1": "timetable", "2": "exam", "3": "personal"})
    if choice == "timetable":
        return main(interactive=True)
    if choice == "exam":
        return run_exam_engine_interactive()
    return run_personal_engine_interactive()


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="Unified scheduler (timetable/exam/personal).")
    subparsers = parser.add_subparsers(dest="engine")

    timetable_parser = subparsers.add_parser("timetable", help="Run timetable engine")
    timetable_parser.add_argument("--input", dest="input_file")
    timetable_parser.add_argument("--output", dest="output_file")
    timetable_parser.add_argument("--model", dest="model", choices=["csp", "ensemble", "hybrid", "ga", "rl", "nn"])

    exam_parser = subparsers.add_parser("exam", help="Run exam engine")
    exam_parser.add_argument("--input", dest="input_file", required=False)
    exam_parser.add_argument("--output", dest="output_file", required=False)
    exam_parser.add_argument("--department", dest="department", required=False)
    exam_parser.add_argument("--hall", dest="hall", required=False)

    personal_parser = subparsers.add_parser("personal", help="Run personal engine")
    personal_parser.add_argument("--role", dest="role", choices=["student", "lecturer"], required=False)
    personal_parser.add_argument("--timetable", dest="timetable", required=False)
    personal_parser.add_argument("--exam", dest="exam", required=False)
    personal_parser.add_argument("--events", dest="events", required=False)
    personal_parser.add_argument("--output", dest="output_file", required=False)
    personal_parser.add_argument("--import", dest="import_path", required=False)
    personal_parser.add_argument("--auto", dest="auto", action="store_true")
    personal_parser.add_argument("--department", dest="department", required=False)
    personal_parser.add_argument("--level", dest="level", required=False)
    personal_parser.add_argument("--semester", dest="semester", required=False)

    args = parser.parse_args()

    if not args.engine:
        success = run_menu()
        sys.exit(0 if success else 1)

    if args.engine == "timetable":
        if args.input_file or args.output_file:
            success = main(input_file=args.input_file, output_file=args.output_file, interactive=False, model_choice=args.model)
        else:
            success = main(interactive=True, model_choice=args.model)
        sys.exit(0 if success else 1)

    if args.engine == "exam":
        if args.input_file and args.output_file:
            success = run_headless_exam(args.input_file, args.output_file, args.department, args.hall)
        else:
            success = run_exam_engine_interactive()
        sys.exit(0 if success else 1)

    if args.engine == "personal":
        timetable_path = args.timetable
        if args.import_path:
            if not _import_personal_timetable_cli(args.import_path):
                sys.exit(1)
            timetable_path = PERSONAL_TIMETABLE_FILE
        elif args.auto:
            if not _auto_generate_personal_timetable_cli(args.department, args.level, args.semester):
                sys.exit(1)
            timetable_path = PERSONAL_TIMETABLE_FILE

        if timetable_path and args.output_file:
            personal_events = _load_personal_events_from_csv(args.events) if args.events else []
            _, suggestions = build_personal_schedule(
                base_dir=os.path.dirname(timetable_path) or os.getcwd(),
                personal_events=personal_events,
                role=args.role or "student",
                timetable_path=timetable_path,
                exam_path=args.exam
            )
            os.makedirs(os.path.dirname(args.output_file) or ".", exist_ok=True)
            pd.DataFrame([
                {
                    "type": "suggestion",
                    "title": s.title,
                    "day": s.day,
                    "start_time": s.start.strftime("%H:%M"),
                    "end_time": s.end.strftime("%H:%M"),
                    "score": f"{s.score:.1f}",
                    "reason": s.reason
                } for s in suggestions
            ]).to_csv(args.output_file, index=False)
            print(f"[SUCCESS] Personal schedule suggestions saved to {args.output_file}")
            success = True
        else:
            success = run_personal_engine_interactive()
        sys.exit(0 if success else 1)
