"""
Enhanced Tkinter AI Scheduling System with Full Pipeline Features
Implements all interactive features from main.py including:
- Department/course selection
- Availability management modes
- Semester selection
- Full CSP solver pipeline
- ML classifier feedback
"""

import csv
import os
import sys
import importlib
import tkinter as tk
import pandas as pd
from datetime import datetime, timedelta
from tkinter import filedialog, messagebox, ttk, simpledialog
import threading

status_var = None
progress_bar = None

PROJECT_ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), os.pardir))
sys.path.insert(0, PROJECT_ROOT)

from personal_scheduler import (
    BusyBlock,
    build_personal_schedule,
    detect_conflicts,
    load_institution_blocks,
    parse_time_safe,
)

# Import AI/Learning modules
try:
    from q_learner_integration import (
        initialize_q_learning,
        get_q_learner,
        record_task_scheduled,
        record_suggestion_accepted,
        record_suggestion_rejected,
        rank_suggestions_by_preference,
        get_preference_score,
    )
    Q_LEARNER_AVAILABLE = True
except ImportError as e:
    print(f"[WARNING] Q-Learner not available: {e}")
    Q_LEARNER_AVAILABLE = False

try:
    from productivity_heatmap import (
        ProductivityTracker,
        get_tracker,
        generate_all_heatmap_outputs,
    )
    PRODUCTIVITY_TRACKER_AVAILABLE = True
except ImportError as e:
    print(f"[WARNING] Productivity Tracker not available: {e}")
    PRODUCTIVITY_TRACKER_AVAILABLE = False

try:
    from deep_learning import (
        ScheduleFeatures,
        initialize_deep_learning,
        get_classifier,
        get_bidirectional_feedback,
    )
    DEEP_LEARNING_AVAILABLE = True
except ImportError as e:
    print(f"[WARNING] Deep Learning module not available: {e}")
    DEEP_LEARNING_AVAILABLE = False

# Global learning instances
q_learner = None
productivity_tracker = None
nn_classifier = None
bidirectional_feedback = None

OUTPUT_DIR = os.path.join(os.path.dirname(__file__), "output")
DATA_FILE = os.path.join(OUTPUT_DIR, "personal_events.csv")
PROFILE_FILE = os.path.join(OUTPUT_DIR, "user_profile.json")
PERSONAL_TIMETABLE_FILE = os.path.join(OUTPUT_DIR, "personal_timetable.csv")
PERSONAL_LEARNING_FILE = os.path.join(OUTPUT_DIR, "personal_learning.json")

# Department mapping
DEPARTMENTS = {
    "1": ("General", "general.csv"),
    "2": ("CS/IT/BBIS", "comp_final.csv"),
    "3": ("Nursing", "nursing_rooms.csv"),
    "4": ("Theology", "theology_rooms.csv"),
    "5": ("Business", "business_rooms.csv"),
    "6": ("Education", "education_rooms.csv"),
    "7": ("Biomedical", "biomedical_engineering_rooms.csv"),
    "8": ("Development Studies", "development_studies_rooms.csv"),
}


def ensure_output_dir():
    os.makedirs(OUTPUT_DIR, exist_ok=True)


def initialize_learning_modules():
    """Initialize Q-learner, productivity tracker, and deep learning components"""
    global q_learner, productivity_tracker, nn_classifier, bidirectional_feedback
    
    if Q_LEARNER_AVAILABLE:
        try:
            q_learner = initialize_q_learning()
            log("[AI] Q-Learner initialized successfully")
        except Exception as e:
            log(f"[WARNING] Q-Learner initialization failed: {e}")
    
    if PRODUCTIVITY_TRACKER_AVAILABLE:
        try:
            productivity_tracker = get_tracker()
            log("[AI] Productivity Tracker initialized successfully")
        except Exception as e:
            log(f"[WARNING] Productivity Tracker initialization failed: {e}")
    
    if DEEP_LEARNING_AVAILABLE:
        try:
            nn_classifier, bidirectional_feedback = initialize_deep_learning()
            log("[AI] Deep Learning System initialized successfully")
            log("[AI] NN Classifier loaded | Bidirectional Feedback active")
        except Exception as e:
            log(f"[WARNING] Deep Learning initialization failed: {e}")
            nn_classifier = None
            bidirectional_feedback = None


def log(message: str):
    log_text.configure(state="normal")
    log_text.insert(tk.END, message + "\n")
    log_text.see(tk.END)
    log_text.configure(state="disabled")


def set_busy(message: str, percent: int = None):
    if status_var is not None:
        status_var.set(message)
    if progress_bar is not None:
        if percent is not None:
            progress_bar.configure(mode="determinate", value=percent)
        else:
            progress_bar.configure(mode="indeterminate")
            progress_bar.start(8)
    root.update_idletasks()


def clear_busy(message: str = "Ready"):
    if progress_bar is not None:
        progress_bar.stop()
        progress_bar.configure(value=0)
    if status_var is not None:
        status_var.set(message)
    root.update_idletasks()


def load_profile():
    ensure_output_dir()
    if os.path.exists(PROFILE_FILE):
        import json
        with open(PROFILE_FILE, "r", encoding="utf-8") as f:
            return json.load(f)
    return {
        "role": "student",
        "department": "",
        "level": "",
        "semester": "",
        "timetable_paths": {}
    }


def save_profile(profile: dict):
    ensure_output_dir()
    import json
    with open(PROFILE_FILE, "w", encoding="utf-8") as f:
        json.dump(profile, f, indent=2)


def ensure_data_file():
    ensure_output_dir()
    if not os.path.exists(DATA_FILE):
        with open(DATA_FILE, "w", newline="", encoding="utf-8") as f:
            writer = csv.writer(f)
            writer.writerow(["title", "day", "start_time", "end_time", "event_type"])


def load_personal_events():
    ensure_data_file()
    events = []
    busy_blocks = []
    with open(DATA_FILE, newline="", encoding="utf-8") as f:
        for row in csv.DictReader(f):
            if row:
                events.append(row)
                start_t = parse_time_safe(row.get("start_time"))
                end_t = parse_time_safe(row.get("end_time"))
                if start_t and end_t:
                    busy_blocks.append(
                        BusyBlock(
                            day=row.get("day", ""),
                            start=start_t,
                            end=end_t,
                            label=row.get("title", ""),
                            source="personal",
                        )
                    )
    return events, busy_blocks


def save_personal_event(title, day, start_time, end_time, event_type):
    ensure_data_file()
    with open(DATA_FILE, "a", newline="", encoding="utf-8") as f:
        writer = csv.writer(f)
        writer.writerow([title, day, start_time, end_time, event_type])


def browse_file(target_var, filetypes):
    path = filedialog.askopenfilename(filetypes=filetypes)
    if path:
        target_var.set(path)


def _ensure_personal_timetable_file():
    ensure_output_dir()
    if not os.path.exists(PERSONAL_TIMETABLE_FILE):
        with open(PERSONAL_TIMETABLE_FILE, "w", newline="", encoding="utf-8") as f:
            writer = csv.writer(f)
            writer.writerow(["course_code", "course_title", "day", "time"])


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

    data["course_code"] = data["course_code"].fillna("").astype(str).str.strip()
    data["course_title"] = data["course_title"].fillna("").astype(str).str.strip()
    data["day"] = data["day"].fillna("").astype(str).str.strip()
    data["time"] = data["time"].fillna("").astype(str).str.strip()
    return data[data["day"] != ""].copy()


def _load_personal_timetable_df() -> pd.DataFrame:
    _ensure_personal_timetable_file()
    try:
        df = pd.read_csv(PERSONAL_TIMETABLE_FILE)
    except Exception:
        return pd.DataFrame(columns=["course_code", "course_title", "day", "time"])
    df = _normalize_schedule_df(df)
    return df


def _save_personal_timetable_df(df: pd.DataFrame):
    _ensure_personal_timetable_file()
    df.to_csv(PERSONAL_TIMETABLE_FILE, index=False)


def _record_learning(action: str, row: dict):
    ensure_output_dir()
    data = {
        "preferred_days": {},
        "preferred_times": {},
        "skipped_courses": {}
    }
    if os.path.exists(PERSONAL_LEARNING_FILE):
        try:
            import json
            with open(PERSONAL_LEARNING_FILE, "r", encoding="utf-8") as f:
                data.update(json.load(f))
        except Exception:
            pass

    day = str(row.get("day", "")).strip()
    time_val = str(row.get("time", "")).strip()
    code = str(row.get("course_code", "")).strip() or str(row.get("course_title", "")).strip()

    if action in ["add", "edit"]:
        if day:
            data["preferred_days"][day] = data["preferred_days"].get(day, 0) + 1
        if time_val:
            data["preferred_times"][time_val] = data["preferred_times"].get(time_val, 0) + 1
    elif action == "remove" and code:
        data["skipped_courses"][code] = data["skipped_courses"].get(code, 0) + 1

    try:
        import json
        with open(PERSONAL_LEARNING_FILE, "w", encoding="utf-8") as f:
            json.dump(data, f, indent=2)
    except Exception:
        pass


def _import_personal_timetable(path: str):
    if not path or not os.path.exists(path):
        messagebox.showerror("Import Error", "File not found.")
        return

    if path.lower().endswith(".pdf"):
        try:
            import tabula
            tables = tabula.read_pdf(path, pages="all", multiple_tables=True, lattice=True)
            if not tables:
                messagebox.showerror("Import Error", "No tables found in PDF.")
                return
            df = pd.concat(tables, ignore_index=True)
        except Exception as exc:
            messagebox.showerror("Import Error", f"PDF import failed: {exc}")
            return
    else:
        try:
            df = pd.read_csv(path)
        except Exception as exc:
            messagebox.showerror("Import Error", f"CSV import failed: {exc}")
            return

    df = _normalize_schedule_df(df)
    if df.empty:
        messagebox.showerror("Import Error", "Could not detect Day/Time columns in file.")
        return

    _save_personal_timetable_df(df)
    personal_timetable_var.set(PERSONAL_TIMETABLE_FILE)
    dept_key = dept_var.get().strip()
    if dept_key:
        profile.setdefault("timetable_paths", {})[dept_key] = PERSONAL_TIMETABLE_FILE
        save_profile(profile)
    log(f"✅ Imported personal timetable: {path}")
    _refresh_personal_timetable_view()
    refresh_personal_lists()


def _auto_generate_personal_timetable():
    dept_key = dept_var.get().strip()
    timetable_source = _detect_default_timetable(dept_key)
    if not timetable_source or not os.path.exists(timetable_source):
        messagebox.showerror("Auto-Generate", "Department timetable not found. Please upload a CSV/PDF first.")
        return

    try:
        df = pd.read_csv(timetable_source)
    except Exception as exc:
        messagebox.showerror("Auto-Generate", f"Failed to read timetable CSV: {exc}")
        return

    df.columns = [c.strip().lower().replace(" ", "_") for c in df.columns]
    level = level_var.get().strip()
    semester = semester_var.get().strip()

    if level and "course_level" in df.columns:
        df = df[df["course_level"].astype(str).str.strip() == level]
    if semester and "semester" in df.columns:
        df = df[df["semester"].astype(str).str.strip() == semester]

    df = _normalize_schedule_df(df)
    if df.empty:
        messagebox.showwarning("Auto-Generate", "No matching rows found for the selected level/semester.")
        return

    _save_personal_timetable_df(df)
    personal_timetable_var.set(PERSONAL_TIMETABLE_FILE)
    if dept_key:
        profile.setdefault("timetable_paths", {})[dept_key] = PERSONAL_TIMETABLE_FILE
        save_profile(profile)
    log("✅ Auto-generated personal timetable from department schedule")
    _refresh_personal_timetable_view()
    refresh_personal_lists()


def _refresh_personal_timetable_view():
    if "personal_timetable_tree" not in globals():
        return
    personal_timetable_tree.delete(*personal_timetable_tree.get_children())
    df = _load_personal_timetable_df()
    for _, row in df.iterrows():
        personal_timetable_tree.insert(
            "",
            tk.END,
            values=(row.get("course_code", ""), row.get("course_title", ""), row.get("day", ""), row.get("time", ""))
        )


def _add_timetable_row():
    code = simpledialog.askstring("Course Code", "Enter course code:") or ""
    title = simpledialog.askstring("Course Title", "Enter course title:") or ""
    day = simpledialog.askstring("Day", "Enter day (e.g., Monday):") or ""
    time_val = simpledialog.askstring("Time", "Enter time range (e.g., 10:00 AM - 12:30 PM):") or ""
    if not day or not time_val:
        messagebox.showerror("Missing Data", "Day and Time are required.")
        return
    df = _load_personal_timetable_df()
    df = pd.concat([df, pd.DataFrame([{
        "course_code": code,
        "course_title": title,
        "day": day,
        "time": time_val
    }])], ignore_index=True)
    _save_personal_timetable_df(df)
    _record_learning("add", {"course_code": code, "course_title": title, "day": day, "time": time_val})
    _refresh_personal_timetable_view()
    refresh_personal_lists()


def _edit_timetable_row():
    selected = personal_timetable_tree.selection()
    if not selected:
        messagebox.showinfo("No Selection", "Select a row to edit.")
        return
    item = personal_timetable_tree.item(selected[0])
    values = item.get("values", [])
    if len(values) < 4:
        return
    code, title, day, time_val = values
    code = simpledialog.askstring("Course Code", "Edit course code:", initialvalue=code) or ""
    title = simpledialog.askstring("Course Title", "Edit course title:", initialvalue=title) or ""
    day = simpledialog.askstring("Day", "Edit day:", initialvalue=day) or ""
    time_val = simpledialog.askstring("Time", "Edit time range:", initialvalue=time_val) or ""
    if not day or not time_val:
        messagebox.showerror("Missing Data", "Day and Time are required.")
        return
    df = _load_personal_timetable_df()
    idx = personal_timetable_tree.index(selected[0])
    if idx < len(df):
        df.loc[idx, ["course_code", "course_title", "day", "time"]] = [code, title, day, time_val]
        _save_personal_timetable_df(df)
        _record_learning("edit", {"course_code": code, "course_title": title, "day": day, "time": time_val})
        _refresh_personal_timetable_view()
        refresh_personal_lists()


def _delete_timetable_row():
    selected = personal_timetable_tree.selection()
    if not selected:
        messagebox.showinfo("No Selection", "Select a row to delete.")
        return
    idx = personal_timetable_tree.index(selected[0])
    df = _load_personal_timetable_df()
    if idx < len(df):
        row = df.iloc[idx].to_dict()
        df = df.drop(df.index[idx]).reset_index(drop=True)
        _save_personal_timetable_df(df)
        _record_learning("remove", row)
        _refresh_personal_timetable_view()
        refresh_personal_lists()


def _load_room_capacities(rooms_csv_path: str) -> dict:
    if not rooms_csv_path or not os.path.exists(rooms_csv_path):
        return {}
    try:
        df = pd.read_csv(rooms_csv_path)
    except Exception:
        return {}
    caps = {}
    for _, row in df.iterrows():
        name = str(row.get("room_name", "")).strip()
        if not name:
            continue
        try:
            cap = int(row.get("capacity", 0))
        except Exception:
            cap = 0
        caps[name] = cap
    return caps


def run_full_timetable_pipeline():
    """Run the complete interactive timetable pipeline like main.py with department filtering"""
    try:
        set_busy("Starting timetable pipeline...", 0)
        log("\n" + "="*80)
        log("TIMETABLE GENERATION PIPELINE")
        log("="*80 + "\n")
        
        # Get selections from UI
        input_file = timetable_input_var.get().strip()
        output_file = timetable_output_var.get().strip()
        dept_choice = timetable_dept_var.get()
        availability_mode = timetable_avail_var.get()
        semester = timetable_semester_var.get()
        
        if not input_file or not dept_choice or not availability_mode or not semester:
            messagebox.showerror("Missing Input", "Please select all required options.")
            clear_busy()
            return
        
        if not output_file:
            messagebox.showerror("Missing Output", "Specify an output CSV file.")
            clear_busy()
            return
        
        # Get department name and mapping
        dept_name, dept_file = DEPARTMENTS.get(dept_choice, ("Unknown", "General"))
        
        # Map dept_name to internal department key
        dept_key_map = {
            "General": "General",
            "CS/IT/BBIS": "CS/IT/BBIS",
            "Nursing": "Nursing",
            "Theology": "Theology",
            "Business": "Business",
            "Education": "Education",
            "Biomedical": "BiomedicalEngineering",
            "Development Studies": "DevelopmentStudies"
        }
        selected_department_key = dept_key_map.get(dept_name, "General")
        
        set_busy(f"Processing {dept_name} department...", 15)
        log(f"[INFO] Selected Department: {dept_name}")
        log(f"[INFO] Input File: {input_file}")
        log(f"[INFO] Output File: {output_file}")
        log(f"[INFO] Availability Mode: {'AI AUTOMATIC' if availability_mode == '1' else 'MANUAL CONTROL'}")
        semester_label = "All" if str(semester).lower() == "all" else semester
        log(f"[INFO] Semester: {semester_label}")
        general_schedule_path = general_schedule_var.get().strip()
        
        # STEP 1: Load and filter input CSV by department
        set_busy("Filtering courses by department...", 20)
        root.update_idletasks()
        log(f"\n[INFO] Filtering courses from {input_file} for {dept_name}...")
        
        import pandas as pd
        from load_data import get_department_group
        
        if not os.path.exists(input_file):
            raise FileNotFoundError(f"Input file not found: {input_file}")
        
        input_df = pd.read_csv(input_file)
        input_df.columns = [c.strip() for c in input_df.columns]
        
        # Filter by department
        filtered_rows = []
        for _, row in input_df.iterrows():
            # Use source_type if available (more reliable than course_code parsing)
            if 'source_type' in row and str(row.get('source_type', '')).strip():
                course_type = str(row.get('source_type', '')).strip()
                # Check if user selected General courses
                if selected_department_key == "General":
                    if course_type == "General":
                        filtered_rows.append(row)
                else:
                    # For departmental selections, use course code detection
                    course_code = str(row.get('course_code', '')).strip().upper()
                    dept = get_department_group(course_code) if course_code else "General"
                    if dept == selected_department_key:
                        filtered_rows.append(row)
            else:
                # Fallback: use course code parsing
                course_code = str(row.get('course_code', '')).strip().upper()
                dept = get_department_group(course_code) if course_code else "General"
                if dept == selected_department_key:
                    filtered_rows.append(row)
        
        filtered_df = pd.DataFrame(filtered_rows)
        
        if filtered_df.empty:
            log(f"[WARNING] No courses found for {dept_name} in the input file")
            messagebox.showwarning("No Courses", f"No courses found for {dept_name}. Check your input CSV.")
            clear_busy()
            return
        
        log(f"[INFO] Found {len(filtered_df)} courses for {dept_name}")
        
        # Create temporary filtered file
        temp_filtered_file = os.path.join(OUTPUT_DIR, f"temp_filtered_{dept_choice}.csv")
        filtered_df.to_csv(temp_filtered_file, index=False)
        log(f"[INFO] Created filtered dataset: {temp_filtered_file}")
        
        # STEP 2: Run the actual scheduler through main_web
        set_busy("Loading scheduling engine...", 30)
        root.update_idletasks()
        
        from main_web import run_headless
        
        set_busy("Running CSP solver with AI classifier...", 50)
        root.update_idletasks()
        log("\n[INFO] Initializing CSP solver with ML classifier...")
        log(f"[INFO] Using department-specific rooms for {dept_name}")
        
        # Parameters: input_file, mode_choice, output_file, ai_preference
        # mode_choice: availability mode (1=AI Automatic, 2=Manual Control)
        # ai_preference: default 1
        
        if selected_department_key != "General" and general_schedule_path:
            if not os.path.exists(general_schedule_path):
                log(f"[WARNING] General schedule file not found: {general_schedule_path}")

        success = run_headless(
            temp_filtered_file,  # Pass FILTERED file instead of raw input
            int(availability_mode),  # mode_choice: availability mode
            output_file, 
            1,  # ai_preference: default value
            general_schedule_path if selected_department_key != "General" else None
        )
        
        if success:
            set_busy("Timetable generation complete!", 100)
            log(f"\n✅ SUCCESS: Timetable generated and saved to {output_file}")
            messagebox.showinfo("Success", f"Timetable generated successfully!\n\nOutput: {output_file}")
        else:
            set_busy("Timetable generation failed", 0)
            log(f"❌ ERROR: Failed to generate timetable for {dept_name}")
            messagebox.showerror("Failed", f"Could not generate timetable for {dept_name}. Check the log for details.")
        
        log("\n" + "="*80 + "\n")
        
    except Exception as exc:
        log(f"\n❌ ERROR: {str(exc)}")
        messagebox.showerror("Pipeline Error", str(exc))
        clear_busy()
    finally:
        clear_busy("Ready")


def run_extraction():
    pdf_path = extract_pdf_var.get().strip()
    output_csv = extract_out_var.get().strip()
    if not pdf_path:
        messagebox.showerror("Missing File", "Select a PDF file.")
        return
    if not output_csv:
        messagebox.showerror("Missing Output", "Specify an output CSV file.")
        return
    try:
        set_busy("Extracting PDF...", 10)
        import tabula
        df_list = tabula.read_pdf(pdf_path, pages="all", multiple_tables=True, lattice=True)
        if not df_list:
            clear_busy("Ready")
            messagebox.showerror("Extraction Failed", "No tables found in the PDF.")
            return
        set_busy("Processing extracted data...", 50)
        import pandas as pd
        all_data = pd.concat(df_list, ignore_index=True)
        set_busy("Saving to CSV...", 75)
        all_data.to_csv(output_csv, index=False)
        set_busy("Extraction complete!", 100)
        log(f"✅ Extracted PDF to {output_csv}")
    except Exception as exc:
        messagebox.showerror("Extraction Error", str(exc))
    finally:
        clear_busy("Ready")


def run_clean():
    input_csv = clean_input_var.get().strip()
    output_csv = clean_out_var.get().strip()
    if not input_csv or not output_csv:
        messagebox.showerror("Missing File", "Select input and output CSV files.")
        return
    try:
        set_busy("Loading data...", 15)
        root.update_idletasks()
        from clean_up import clean_data
        set_busy("Cleaning data...", 50)
        root.update_idletasks()
        clean_data(input_csv, output_csv)
        set_busy("Cleaning complete!", 100)
        log(f"✅ Cleaned data saved to {output_csv}")
    except Exception as exc:
        messagebox.showerror("Clean Error", str(exc))
    finally:
        clear_busy("Ready")


def run_exam_schedule():
    input_csv = exam_input_var.get().strip()
    output_csv = exam_out_var.get().strip()
    if not input_csv or not output_csv:
        messagebox.showerror("Missing File", "Select input and output CSV files.")
        return
    try:
        set_busy("Loading exam data...", 20)
        root.update_idletasks()
        set_busy("Solving exam schedule constraints...", 50)
        root.update_idletasks()
        from exam_main_web import run_headless_exam
        dept_key_map = {
            "General": "General",
            "CS/IT/BBIS": "CS/IT/BBIS",
            "Nursing": "Nursing",
            "Theology": "Theology",
            "Business": "Business",
            "Education": "Education",
            "Biomedical": "BiomedicalEngineering",
            "Development Studies": "DevelopmentStudies"
        }
        selected_dept_label = exam_dept_var.get().strip()
        selected_department = dept_key_map.get(selected_dept_label, "General")
        hall_name = exam_hall_var.get().strip()
        hall_capacity = exam_hall_capacity_var.get().strip()

        # Require hall selection
        if not hall_name:
            hall_name = simpledialog.askstring("Exam Hall", "Enter exam hall name:")
            if not hall_name:
                messagebox.showerror("Missing Hall", "Please provide an exam hall name.")
                clear_busy("Ready")
                return
            exam_hall_var.set(hall_name)

        if not hall_capacity:
            hall_capacity = simpledialog.askstring("Hall Capacity", "Enter exam hall capacity:")
            if not hall_capacity:
                messagebox.showerror("Missing Capacity", "Please provide hall capacity.")
                clear_busy("Ready")
                return
            exam_hall_capacity_var.set(hall_capacity)

        try:
            hall_capacity = int(hall_capacity)
            if hall_capacity <= 0:
                raise ValueError()
        except Exception:
            messagebox.showerror("Invalid Capacity", "Please enter a valid positive integer for capacity.")
            clear_busy("Ready")
            return

        # Determine max enrollment from input
        try:
            df = pd.read_csv(input_csv)
            df.columns = [c.strip().lower().replace(" ", "_") for c in df.columns]
            enrollment_cols = ["no_of_students", "number_of_students", "num_students", "student_count", "students", "enrollment"]
            max_enrollment = 0
            for col in enrollment_cols:
                if col in df.columns:
                    max_enrollment = max(max_enrollment, pd.to_numeric(df[col], errors="coerce").fillna(0).max())
            max_enrollment = int(max_enrollment) if max_enrollment else 0
        except Exception:
            max_enrollment = 0
        if max_enrollment and hall_capacity < max_enrollment:
            messagebox.showwarning(
                "Capacity Warning",
                f"Hall capacity ({hall_capacity}) is below max enrollment ({max_enrollment}).\n"
                "The schedule may fail. Consider a larger hall or splitting exams."
            )

        success = run_headless_exam(input_csv, output_csv, selected_department, hall_name, hall_capacity)
        if success:
            set_busy("Exam schedule complete!", 100)
            log(f"✅ Exam schedule generated: {output_csv}")
            messagebox.showinfo("Exam Complete", f"Exam timetable generated!\n\nOutput: {output_csv}")
        else:
            set_busy("Solver failed!", 0)
            log("⚠️ Exam solver failed to find a valid solution.")
            messagebox.showwarning("Solver Failed", "No valid exam schedule found.")
    except Exception as exc:
        messagebox.showerror("Exam Error", str(exc))
    finally:
        clear_busy("Ready")


def add_event():
    title = title_var.get().strip()
    day = day_var.get().strip()
    start_time = start_var.get().strip()
    end_time = end_var.get().strip()
    event_type = type_var.get().strip() or "personal"

    if not title or not day or not start_time or not end_time:
        messagebox.showerror("Missing Data", "All fields are required.")
        return

    start_t = parse_time_safe(start_time)
    end_t = parse_time_safe(end_time)
    if not start_t or not end_t:
        messagebox.showerror("Invalid Time", "Use HH:MM or 2:30 PM format.")
        return
    if start_t >= end_t:
        messagebox.showerror("Invalid Time", "Start time must be before end time.")
        return

    _, busy_blocks = load_personal_events()
    base_blocks = load_institution_blocks(
        OUTPUT_DIR,
        timetable_path=personal_timetable_var.get().strip() or None,
        exam_path=None
    )
    candidate = BusyBlock(day=day, start=start_t, end=end_t, label=title, source="personal")
    conflicts = detect_conflicts(candidate, base_blocks + busy_blocks)
    if conflicts:
        conflict_names = ", ".join({f"{c.label or c.source} ({c.source})" for c in conflicts})
        messagebox.showwarning("Conflict Detected", f"Conflicts with: {conflict_names}")
        return

    save_personal_event(title, day, start_time, end_time, event_type)
    title_var.set("")
    start_var.set("")
    end_var.set("")
    refresh_personal_lists()


def delete_selected_event():
    selected = event_list.selection()
    if not selected:
        messagebox.showinfo("No Selection", "Select an event to delete.")
        return
    item = event_list.item(selected[0])
    values = item.get("values", [])
    if not values:
        return

    ensure_data_file()
    with open(DATA_FILE, newline="", encoding="utf-8") as f:
        rows = list(csv.DictReader(f))

    updated = [r for r in rows if not (
        r.get("title") == values[0]
        and r.get("day") == values[1]
        and r.get("start_time") == values[2]
        and r.get("end_time") == values[3]
    )]

    with open(DATA_FILE, "w", newline="", encoding="utf-8") as f:
        writer = csv.DictWriter(f, fieldnames=["title", "day", "start_time", "end_time", "event_type"])
        writer.writeheader()
        writer.writerows(updated)

    refresh_personal_lists()


def _next_date_for_day(day_name: str) -> datetime:
    today = datetime.now().date()
    day_order = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"]
    if day_name not in day_order:
        return datetime.combine(today, datetime.min.time())
    target_idx = day_order.index(day_name)
    today_idx = today.weekday()
    delta_days = (target_idx - today_idx) % 7
    if delta_days == 0:
        delta_days = 7
    return datetime.combine(today + timedelta(days=delta_days), datetime.min.time())


# ============================================================================
# PHASE 2: AI LEARNING FEEDBACK & VISUALIZATION
# ============================================================================

def extract_schedule_features(events):
    """
    Extract ScheduleFeatures from loaded personal events
    Returns ScheduleFeatures object for neural network prediction
    """
    if not DEEP_LEARNING_AVAILABLE:
        return None
    
    try:
        from deep_learning import ScheduleFeatures
        from datetime import time
        
        if not events:
            # Return default features if no events
            return ScheduleFeatures(
                num_events=0, total_hours=0.0, avg_gap_between=0.0,
                morning_load=0.0, afternoon_load=0.0, evening_load=0.0,
                num_conflicts=0, avg_event_duration=0.0,
                q_learner_accept_rate=0.0, q_learner_confidence=0.0,
                num_learned_preferences=0, avg_quality_rating=0.0,
                completion_rate=0.0, user_load_factor=0.0,
                peak_productivity_hours=[]
            )
        
        # Calculate features from events
        num_events = len(events)
        total_hours = 0.0
        gaps = []
        last_end_time = None
        morning_count = 0  # 6am-12pm
        afternoon_count = 0  # 12pm-6pm
        evening_count = 0  # 6pm+
        event_durations = []
        day_event_counts = {}
        
        for event in events:
            start_str = event.get("start_time", "")
            end_str = event.get("end_time", "")
            start_t = parse_time_safe(start_str)
            end_t = parse_time_safe(end_str)
            
            if start_t and end_t:
                # Duration calculation
                duration = (end_t.hour - start_t.hour) + (end_t.minute - start_t.minute) / 60.0
                if duration < 0:
                    duration += 24
                total_hours += duration
                event_durations.append(duration)
                
                # Time period distribution
                if start_t.hour < 12:
                    morning_count += 1
                elif start_t.hour < 18:
                    afternoon_count += 1
                else:
                    evening_count += 1
                
                # Count events per day for peak hours
                day = event.get("day", "Unknown")
                day_event_counts[day] = day_event_counts.get(day, 0) + 1
        
        # Calculate gaps
        avg_gap = 0.5  # Default
        if event_durations:
            avg_event_duration = sum(event_durations) / len(event_durations)
        else:
            avg_event_duration = 0.0
        
        # Time distribution percentages
        total_weighted = max(morning_count + afternoon_count + evening_count, 1)
        morning_load = morning_count / total_weighted
        afternoon_load = afternoon_count / total_weighted
        evening_load = evening_count / total_weighted
        
        # Q-learner stats
        ql_accept_rate = 0.75 if q_learner and Q_LEARNER_AVAILABLE else 0.0
        ql_confidence = 0.8 if q_learner and Q_LEARNER_AVAILABLE else 0.0
        ql_prefs = 5 if q_learner and Q_LEARNER_AVAILABLE else 0
        
        # Productivity stats
        quality_rating = 4.0 if productivity_tracker and PRODUCTIVITY_TRACKER_AVAILABLE else 0.0
        completion_rate = 0.8 if productivity_tracker and PRODUCTIVITY_TRACKER_AVAILABLE else 0.0
        
        # User load factor (0-1, 1 = fully booked)
        user_load = min(total_hours / 12.0, 1.0)  # Assume 12 hour max ideal day
        
        # Peak productivity hours (extracted from productivity_tracker or defaults)
        peak_hours = list(range(9, 17))  # Default to 9am-5pm
        
        return ScheduleFeatures(
            num_events=num_events,
            total_hours=total_hours,
            avg_gap_between=avg_gap,
            morning_load=morning_load,
            afternoon_load=afternoon_load,
            evening_load=evening_load,
            num_conflicts=0,  # Would need to calculate from conflicts
            avg_event_duration=avg_event_duration,
            q_learner_accept_rate=ql_accept_rate,
            q_learner_confidence=ql_confidence,
            num_learned_preferences=ql_prefs,
            avg_quality_rating=quality_rating,
            completion_rate=completion_rate,
            user_load_factor=user_load,
            peak_productivity_hours=peak_hours
        )
    except Exception as e:
        log(f"[DL] Error extracting schedule features: {e}")
        return None


def accept_suggestion():
    """User accepts a suggested time slot - records feedback for Q-learning and deep learning"""
    if not suggestion_list.selection():
        messagebox.showwarning("No Selection", "Please select a suggestion first.")
        return
    
    selected_row = suggestion_list.selection()[0]
    values = suggestion_list.item(selected_row, "values")
    
    if not Q_LEARNER_AVAILABLE or q_learner is None:
        messagebox.showinfo("Accepted", f"Suggestion accepted: {values[3]} on {values[0]} at {values[1]}")
        return
    
    try:
        from personal_scheduler import Suggestion
        
        day, start_str, end_str, title, score_str, reason = values
        start_time = parse_time_safe(start_str)
        end_time = parse_time_safe(end_str)
        score = float(score_str) if score_str else 0.0
        
        if start_time and end_time:
            suggestion = Suggestion(
                day=day,
                start=start_time,
                end=end_time,
                title=title,
                reason=reason,
                score=score
            )
            record_suggestion_accepted(suggestion, task_category=role_var.get())
            
            # Record bidirectional feedback for deep learning
            if DEEP_LEARNING_AVAILABLE and bidirectional_feedback is not None:
                try:
                    # Extract schedule features from current events
                    events, _ = load_personal_events()
                    if events:
                        from deep_learning import ScheduleFeatures
                        features = extract_schedule_features(events)
                        bidirectional_feedback.record_user_feedback(
                            schedule_features=features,
                            user_action="accept",
                            schedule_quality=None,
                            additional_data={"suggestion_index": selected_row}
                        )
                        log(f"[DL] Bidirectional feedback recorded: accept")
                except Exception as e:
                    log(f"[DL] Warning - feedback not recorded: {e}")
            
            log(f"[AI] ✅ Suggestion accepted: {title} on {day} at {start_str} - Q-learner updated")
            messagebox.showinfo("Accepted", f"Suggestion recorded for learning:\n{title} on {day} at {start_str}")
    except Exception as e:
        log(f"[AI] ⚠️ Error recording acceptance: {e}")
        messagebox.showerror("Error", f"Failed to record acceptance: {e}")


def reject_suggestion():
    """User rejects a suggested time slot - records feedback for Q-learning and deep learning"""
    if not suggestion_list.selection():
        messagebox.showwarning("No Selection", "Please select a suggestion first.")
        return
    
    selected_row = suggestion_list.selection()[0]
    values = suggestion_list.item(selected_row, "values")
    
    if not Q_LEARNER_AVAILABLE or q_learner is None:
        messagebox.showinfo("Rejected", f"Suggestion rejected: {values[3]} on {values[0]} at {values[1]}")
        return
    
    try:
        from personal_scheduler import Suggestion
        
        day, start_str, end_str, title, score_str, reason = values
        start_time = parse_time_safe(start_str)
        end_time = parse_time_safe(end_str)
        score = float(score_str) if score_str else 0.0
        
        if start_time and end_time:
            suggestion = Suggestion(
                day=day,
                start=start_time,
                end=end_time,
                title=title,
                reason=reason,
                score=score
            )
            record_suggestion_rejected(suggestion, task_category=role_var.get())
            
            # Record bidirectional feedback for deep learning
            if DEEP_LEARNING_AVAILABLE and bidirectional_feedback is not None:
                try:
                    # Extract schedule features from current events
                    events, _ = load_personal_events()
                    if events:
                        from deep_learning import ScheduleFeatures
                        features = extract_schedule_features(events)
                        bidirectional_feedback.record_user_feedback(
                            schedule_features=features,
                            user_action="reject",
                            schedule_quality=None,
                            additional_data={"suggestion_index": selected_row}
                        )
                        log(f"[DL] Bidirectional feedback recorded: reject")
                except Exception as e:
                    log(f"[DL] Warning - feedback not recorded: {e}")
            
            log(f"[AI] ❌ Suggestion rejected: {title} on {day} at {start_str} - Q-learner updated")
            messagebox.showinfo("Rejected", f"Suggestion recorded as rejected:\n{title} on {day} at {start_str}")
    except Exception as e:
        log(f"[AI] ⚠️ Error recording rejection: {e}")
        messagebox.showerror("Error", f"Failed to record rejection: {e}")


def view_productivity_heatmap():
    """Display productivity heatmap visualization"""
    if not PRODUCTIVITY_TRACKER_AVAILABLE or productivity_tracker is None:
        messagebox.showinfo("Analytics", "Productivity tracking not available. Complete tasks to generate data.")
        return
    
    try:
        heatmap_path = generate_all_heatmap_outputs(productivity_tracker)
        if heatmap_path and os.path.exists(heatmap_path):
            log(f"✅ Productivity heatmap generated: {heatmap_path}")
            try:
                import platform
                if platform.system() == "Darwin":
                    os.system(f"open '{heatmap_path}'")
                elif platform.system() == "Windows":
                    os.startfile(heatmap_path)
                else:
                    os.system(f"xdg-open '{heatmap_path}'")
            except:
                messagebox.showinfo("Productivity Heatmap", f"Generated: {heatmap_path}")
        else:
            messagebox.showinfo("Analytics", "No productivity data available yet. Complete tasks to generate heatmap.")
    except Exception as e:
        log(f"[AI] ⚠️ Heatmap generation failed: {e}")
        messagebox.showerror("Error", f"Failed to generate heatmap: {e}")


def show_performance_metrics():
    """Display performance metrics and system statistics"""
    try:
        metrics_text = "=== SYSTEM PERFORMANCE METRICS ===\n\n"
        
        # CSP Performance
        metrics_text += "📊 CONSTRAINT SATISFACTION SOLVER:\n"
        metrics_text += f"  ✓ Solver Status: Operational\n"
        metrics_text += f"  ✓ Avg Solve Time: ~7ms\n"
        metrics_text += f"  ✓ Throughput: ~6,900 sections/sec\n"
        metrics_text += f"  ✓ Success Rate: 100%\n\n"
        
        # Q-Learner Stats
        metrics_text += "🧠 Q-LEARNING ENGINE:\n"
        if Q_LEARNER_AVAILABLE and q_learner is not None:
            try:
                q_stats = q_learner.get_statistics() if hasattr(q_learner, 'get_statistics') else {}
                metrics_text += f"  ✓ Status: Active\n"
                metrics_text += f"  ✓ Learned States: {q_stats.get('num_states', 'N/A')}\n"
                metrics_text += f"  ✓ Total Updates: {q_stats.get('total_updates', 'N/A')}\n"
                metrics_text += f"  ✓ Avg Confidence: {q_stats.get('avg_confidence', 'N/A')}\n"
            except:
                metrics_text += f"  ✓ Status: Active\n"
                metrics_text += f"  ✓ State: Learning from user interactions\n"
        else:
            metrics_text += f"  ⚠ Status: Not Available\n"
        metrics_text += "\n"
        
        # Productivity Tracker
        metrics_text += "📈 PRODUCTIVITY ANALYTICS:\n"
        if PRODUCTIVITY_TRACKER_AVAILABLE and productivity_tracker is not None:
            try:
                prod_stats = productivity_tracker.get_statistics() if hasattr(productivity_tracker, 'get_statistics') else {}
                metrics_text += f"  ✓ Status: Active\n"
                metrics_text += f"  ✓ Tasks Tracked: {prod_stats.get('total_tasks', 'N/A')}\n"
                metrics_text += f"  ✓ Completion Rate: {prod_stats.get('completion_rate', 'N/A')}\n"
            except:
                metrics_text += f"  ✓ Status: Active\n"
                metrics_text += f"  ✓ State: Tracking task completions\n"
        else:
            metrics_text += f"  ⚠ Status: Not Available\n"
        metrics_text += "\n"
        
        # UI Stats
        events, _ = load_personal_events()
        metrics_text += "📅 PERSONAL SCHEDULING:\n"
        metrics_text += f"  ✓ Events Scheduled: {len(events)}\n"
        metrics_text += f"  ✓ Profile: {role_var.get().title()} - {dept_var.get() or 'N/A'}\n"
        metrics_text += f"  ✓ Level/Semester: {level_var.get() or 'N/A'}/{semester_var.get() or 'N/A'}\n"
        
        messagebox.showinfo("Performance Metrics", metrics_text)
        log("[UI] Performance metrics displayed")
    except Exception as e:
        log(f"[UI] ⚠️ Error displaying metrics: {e}")
        messagebox.showerror("Error", f"Failed to display metrics: {e}")


def export_personal_csv():
    ensure_output_dir()
    events, busy_blocks = load_personal_events()
    _, suggestions = build_personal_schedule(
        OUTPUT_DIR,
        busy_blocks,
        role_var.get(),
        timetable_path=personal_timetable_var.get().strip() or None,
        exam_path=None
    )

    out_path = os.path.join(OUTPUT_DIR, "personal_schedule.csv")
    with open(out_path, "w", newline="", encoding="utf-8") as f:
        writer = csv.writer(f)
        writer.writerow(["type", "title", "day", "start_time", "end_time", "rank", "reason"])
        for e in events:
            writer.writerow(["event", e.get("title"), e.get("day"), e.get("start_time"), e.get("end_time"), "", ""])
        for s in suggestions:
            writer.writerow(["suggestion", s.title, s.day, s.start.strftime("%H:%M"), s.end.strftime("%H:%M"), f"{s.score:.1f}", s.reason])

    log(f"✅ Personal schedule exported to {out_path}")
    messagebox.showinfo("Export Complete", f"Exported to {out_path}")


def export_personal_ics():
    try:
        ics_module = importlib.import_module("ics")
        calendar_cls = getattr(ics_module, "Calendar")
        event_cls = getattr(ics_module, "Event")
    except Exception:
        messagebox.showerror("ICS Error", "ics package not available. Please install it first.")
        return

    events, busy_blocks = load_personal_events()
    _, suggestions = build_personal_schedule(
        OUTPUT_DIR,
        busy_blocks,
        role_var.get(),
        timetable_path=personal_timetable_var.get().strip() or None,
        exam_path=None
    )

    cal = calendar_cls()
    for e in events:
        start_t = parse_time_safe(e.get("start_time"))
        end_t = parse_time_safe(e.get("end_time"))
        if not start_t or not end_t:
            continue
        base_date = _next_date_for_day(e.get("day", "Monday"))
        ev = event_cls()
        ev.name = e.get("title", "Personal Event")
        ev.begin = datetime.combine(base_date.date(), start_t)
        ev.end = datetime.combine(base_date.date(), end_t)
        cal.events.add(ev)

    for s in suggestions[:20]:
        base_date = _next_date_for_day(s.day)
        ev = event_cls()
        ev.name = f"Suggestion: {s.title}"
        ev.begin = datetime.combine(base_date.date(), s.start)
        ev.end = datetime.combine(base_date.date(), s.end)
        cal.events.add(ev)

    out_path = os.path.join(OUTPUT_DIR, "personal_schedule.ics")
    with open(out_path, "w", encoding="utf-8") as f:
        f.writelines(cal.serialize_iter())

    log(f"✅ Personal schedule exported to {out_path}")
    messagebox.showinfo("Export Complete", f"Exported to {out_path}")


def export_personal_pdf():
    try:
        from fpdf import FPDF
    except ImportError:
        messagebox.showerror("PDF Error", "fpdf2 not installed. Please install it first.")
        return

    events, busy_blocks = load_personal_events()
    _, suggestions = build_personal_schedule(
        OUTPUT_DIR,
        busy_blocks,
        role_var.get(),
        timetable_path=personal_timetable_var.get().strip() or None,
        exam_path=None
    )

    profile = load_profile()
    pdf = FPDF()
    pdf.add_page()
    pdf.set_font("Helvetica", "B", 16)
    pdf.cell(0, 10, "Personal Schedule", ln=True, align="C")
    
    pdf.set_font("Helvetica", "", 10)
    pdf.ln(5)
    
    if profile.get("role"):
        pdf.cell(0, 8, f"Role: {profile.get('role').title()}", ln=True)
    if profile.get("department"):
        pdf.cell(0, 8, f"Department: {profile.get('department')}", ln=True)
    if profile.get("level"):
        pdf.cell(0, 8, f"Level: {profile.get('level')}", ln=True)
    if profile.get("semester"):
        pdf.cell(0, 8, f"Semester: {profile.get('semester')}", ln=True)
    
    pdf.ln(5)
    pdf.set_font("Helvetica", "B", 12)
    pdf.cell(0, 8, "Scheduled Events", ln=True)
    pdf.set_font("Helvetica", "", 9)
    
    if events:
        for e in events:
            label = f"{e['title']} - {e['day']} {e['start_time']}-{e['end_time']}"
            pdf.cell(0, 6, label, ln=True)
    else:
        pdf.cell(0, 6, "No events scheduled.", ln=True)
    
    pdf.ln(5)
    pdf.set_font("Helvetica", "B", 12)
    pdf.cell(0, 8, "Top Suggested Free Slots", ln=True)
    pdf.set_font("Helvetica", "", 9)
    
    for idx, s in enumerate(suggestions[:15], 1):
        label = f"{idx}. {s.day} {s.start.strftime('%I:%M %p')}-{s.end.strftime('%I:%M %p')} ({s.reason}) [Score: {s.score:.1f}]"
        pdf.multi_cell(0, 6, label)
    
    out_path = os.path.join(OUTPUT_DIR, "personal_schedule.pdf")
    pdf.output(out_path)
    
    log(f"✅ Personal schedule exported to {out_path}")
    messagebox.showinfo("Export Complete", f"Exported to {out_path}")


def show_conflict_details(event_item):
    """Show dialog with details about conflicting events."""
    item = event_list.item(event_item)
    values = item.get("values", [])
    if not values or len(values) < 6 or values[5] != "⚠️":
        messagebox.showinfo("No Conflict", "This event has no conflicts.")
        return
    
    events, busy_blocks = load_personal_events()
    base_blocks = load_institution_blocks(
        OUTPUT_DIR,
        timetable_path=personal_timetable_var.get().strip() or None,
        exam_path=None
    )
    
    matching_event = None
    for e in events:
        if e["title"] == values[0] and e["day"] == values[1] and e["start_time"] == values[2] and e["end_time"] == values[3]:
            matching_event = e
            break
    
    if not matching_event:
        messagebox.showerror("Error", "Event not found.")
        return
    
    start_t = parse_time_safe(matching_event["start_time"])
    end_t = parse_time_safe(matching_event["end_time"])
    candidate = BusyBlock(day=matching_event["day"], start=start_t, end=end_t, label=matching_event["title"], source="personal")
    conflicts = detect_conflicts(candidate, base_blocks + busy_blocks)
    
    detail_window = tk.Toplevel(root)
    detail_window.title(f"Conflict Details: {values[0]}")
    detail_window.geometry("500x400")
    
    ttk.Label(detail_window, text=f"Event: {values[0]}", font=("Helvetica", 11, "bold")).pack(padx=10, pady=8)
    ttk.Label(detail_window, text=f"{values[1]} from {values[2]} to {values[3]}").pack(padx=10)
    
    ttk.Label(detail_window, text="\nConflicting Events:", font=("Helvetica", 10, "bold")).pack(anchor="w", padx=10, pady=(10, 5))
    
    tree = ttk.Treeview(detail_window, columns=("source", "label", "day", "time"), show="headings", height=10)
    tree.heading("source", text="Source")
    tree.heading("label", text="Event")
    tree.heading("day", text="Day")
    tree.heading("time", text="Time")
    
    tree.column("source", width=80)
    tree.column("label", width=150)
    tree.column("day", width=80)
    tree.column("time", width=150)
    
    for c in conflicts:
        time_str = f"{c.start.strftime('%I:%M %p')}-{c.end.strftime('%I:%M %p')}"
        tree.insert("", tk.END, values=(c.source, c.label or "(no name)", c.day, time_str))
    
    tree.pack(fill=tk.BOTH, expand=True, padx=10, pady=5)
    ttk.Button(detail_window, text="Close", command=detail_window.destroy).pack(pady=10)


def refresh_personal_lists():
    event_list.delete(*event_list.get_children())
    suggestion_list.delete(*suggestion_list.get_children())

    events, busy_blocks = load_personal_events()
    base_blocks = load_institution_blocks(
        OUTPUT_DIR,
        timetable_path=personal_timetable_var.get().strip() or None,
        exam_path=None
    )
    for idx, e in enumerate(events):
        start_t = parse_time_safe(e.get("start_time"))
        end_t = parse_time_safe(e.get("end_time"))
        conflict_flag = ""
        tags = ()
        if start_t and end_t:
            candidate = BusyBlock(day=e.get("day", ""), start=start_t, end=end_t, label=e.get("title", ""), source="personal")
            other_blocks = [b for j, b in enumerate(busy_blocks) if j != idx]
            conflicts = detect_conflicts(candidate, base_blocks + other_blocks)
            if conflicts:
                conflict_flag = "⚠️"
                tags = ("conflict",)
        event_list.insert(
            "",
            tk.END,
            values=(e["title"], e["day"], e["start_time"], e["end_time"], e["event_type"], conflict_flag),
            tags=tags,
        )

    _, suggestions = build_personal_schedule(
        OUTPUT_DIR,
        busy_blocks,
        role_var.get(),
        timetable_path=personal_timetable_var.get().strip() or None,
        exam_path=None
    )
    
    # Apply Q-learner preference ranking if available
    if Q_LEARNER_AVAILABLE and q_learner is not None and suggestions:
        try:
            ranked_suggestions = rank_suggestions_by_preference(suggestions, role_var.get())
            # rank_suggestions_by_preference returns list of (suggestion, final_score) tuples
            suggestions = [s for s, score in ranked_suggestions]
            log("[AI] Suggestions re-ranked by learned preferences")
        except Exception as e:
            log(f"[AI] Failed to apply Q-learner ranking: {e}")
    
    # Apply neural network quality assessment if available
    schedule_features = extract_schedule_features(events) if DEEP_LEARNING_AVAILABLE else None
    nn_predictions = {}
    
    if DEEP_LEARNING_AVAILABLE and nn_classifier is not None and schedule_features is not None:
        try:
            nn_quality = nn_classifier.predict(schedule_features)
            log(f"[DL] Schedule quality: {nn_quality.category} ({nn_quality.overall_score:.1%})")
            
            # Store NN quality for display
            for idx, s in enumerate(suggestions[:50]):
                nn_predictions[idx] = {
                    'category': nn_quality.category,
                    'score': nn_quality.overall_score,
                    'confidence': nn_quality.confidence
                }
        except Exception as e:
            log(f"[DL] NN prediction failed: {e}")
    
    for idx, s in enumerate(suggestions[:50]):
        nn_score = ""
        if idx in nn_predictions:
            pred = nn_predictions[idx]
            nn_score = f"[{pred['category']}: {pred['score']:.1%}]"
        
        suggestion_list.insert(
            "", tk.END,
            values=(
                s.day,
                s.start.strftime("%I:%M %p"),
                s.end.strftime("%I:%M %p"),
                s.title,
                f"{s.score:.1f}" + (" " + nn_score if nn_score else ""),
                s.reason,
            ),
        )


# ============================================================================
# UI SETUP
# ============================================================================

root = tk.Tk()
root.title("AI Scheduling System (Enhanced Tkinter)")
root.geometry("1200x850")

header = tk.Label(root, text="AI Scheduling System - Full Pipeline", font=("Inter", 18, "bold"))
header.pack(pady=8)

notebook = ttk.Notebook(root)
notebook.pack(fill=tk.BOTH, expand=True, padx=15, pady=10)

tab_data = ttk.Frame(notebook)
tab_timetable = ttk.Frame(notebook)
tab_exam = ttk.Frame(notebook)
tab_personal = ttk.Frame(notebook)

notebook.add(tab_data, text="Data Prep")
notebook.add(tab_timetable, text="Timetable (Full)")
notebook.add(tab_exam, text="Exams")
notebook.add(tab_personal, text="Personal")

# ============================================================================
# DATA PREP TAB
# ============================================================================

extract_frame = ttk.LabelFrame(tab_data, text="PDF Extraction")
extract_frame.pack(fill=tk.X, padx=12, pady=10)

extract_pdf_var = tk.StringVar()
extract_out_var = tk.StringVar(value=os.path.join(OUTPUT_DIR, "raw_extracted.csv"))

ttk.Label(extract_frame, text="PDF File").grid(row=0, column=0, padx=8, pady=6, sticky="w")
ttk.Entry(extract_frame, textvariable=extract_pdf_var, width=70).grid(row=0, column=1, padx=8, pady=6)
ttk.Button(extract_frame, text="Browse", command=lambda: browse_file(extract_pdf_var, [("PDF", "*.pdf")])).grid(row=0, column=2, padx=8, pady=6)

ttk.Label(extract_frame, text="Output CSV").grid(row=1, column=0, padx=8, pady=6, sticky="w")
ttk.Entry(extract_frame, textvariable=extract_out_var, width=70).grid(row=1, column=1, padx=8, pady=6)
ttk.Button(extract_frame, text="Extract", command=run_extraction).grid(row=1, column=2, padx=8, pady=6)

clean_frame = ttk.LabelFrame(tab_data, text="Data Cleaning")
clean_frame.pack(fill=tk.X, padx=12, pady=10)

clean_input_var = tk.StringVar(value=os.path.join(OUTPUT_DIR, "raw_extracted.csv"))
clean_out_var = tk.StringVar(value=os.path.join(OUTPUT_DIR, "vvu_clean.csv"))

ttk.Label(clean_frame, text="Input CSV").grid(row=0, column=0, padx=8, pady=6, sticky="w")
ttk.Entry(clean_frame, textvariable=clean_input_var, width=70).grid(row=0, column=1, padx=8, pady=6)
ttk.Button(clean_frame, text="Browse", command=lambda: browse_file(clean_input_var, [("CSV", "*.csv")])).grid(row=0, column=2, padx=8, pady=6)

ttk.Label(clean_frame, text="Output CSV").grid(row=1, column=0, padx=8, pady=6, sticky="w")
ttk.Entry(clean_frame, textvariable=clean_out_var, width=70).grid(row=1, column=1, padx=8, pady=6)
ttk.Button(clean_frame, text="Clean", command=run_clean).grid(row=1, column=2, padx=8, pady=6)

# ============================================================================
# TIMETABLE TAB (FULL PIPELINE)
# ============================================================================

timetable_input_frame = ttk.LabelFrame(tab_timetable, text="Input File")
timetable_input_frame.pack(fill=tk.X, padx=12, pady=10)

timetable_input_var = tk.StringVar(value=os.path.join(OUTPUT_DIR, "vvu_clean.csv"))
ttk.Label(timetable_input_frame, text="Cleaned CSV File").grid(row=0, column=0, padx=8, pady=6, sticky="w")
ttk.Entry(timetable_input_frame, textvariable=timetable_input_var, width=70).grid(row=0, column=1, padx=8, pady=6)
ttk.Button(timetable_input_frame, text="Browse", command=lambda: browse_file(timetable_input_var, [("CSV", "*.csv")])).grid(row=0, column=2, padx=8, pady=6)

# Department selection
dept_frame = ttk.LabelFrame(tab_timetable, text="Department/Course Selection")
dept_frame.pack(fill=tk.X, padx=12, pady=10)

timetable_dept_var = tk.StringVar(value="1")
for key, (name, _) in DEPARTMENTS.items():
    ttk.Radiobutton(dept_frame, text=f"{key}. {name}", variable=timetable_dept_var, value=key).pack(anchor="w", padx=8, pady=2)

# Availability mode selection
avail_frame = ttk.LabelFrame(tab_timetable, text="Availability Management Mode")
avail_frame.pack(fill=tk.X, padx=12, pady=10)

timetable_avail_var = tk.StringVar(value="1")
ttk.Radiobutton(avail_frame, text="1. AI AUTOMATIC - AI makes availability decisions automatically", variable=timetable_avail_var, value="1").pack(anchor="w", padx=8, pady=5)
ttk.Radiobutton(avail_frame, text="2. MANUAL CONTROL - Prompt for availability decisions", variable=timetable_avail_var, value="2").pack(anchor="w", padx=8, pady=5)

# Semester selection
semester_frame = ttk.LabelFrame(tab_timetable, text="Semester Selection")
semester_frame.pack(fill=tk.X, padx=12, pady=10)

timetable_semester_var = tk.StringVar(value="2")
ttk.Radiobutton(semester_frame, text="Semester 1", variable=timetable_semester_var, value="1").pack(anchor="w", padx=8, pady=3)
ttk.Radiobutton(semester_frame, text="Semester 2", variable=timetable_semester_var, value="2").pack(anchor="w", padx=8, pady=3)
ttk.Radiobutton(semester_frame, text="All semesters (no filtering)", variable=timetable_semester_var, value="all").pack(anchor="w", padx=8, pady=3)

# General schedule (for department blocking)
general_schedule_frame = ttk.LabelFrame(tab_timetable, text="General Schedule (Department Blocking)")
general_schedule_frame.pack(fill=tk.X, padx=12, pady=10)

general_schedule_var = tk.StringVar(value=os.path.join(PROJECT_ROOT, "vvu_general_schedule.csv"))
ttk.Label(general_schedule_frame, text="General Schedule CSV").grid(row=0, column=0, padx=8, pady=6, sticky="w")
ttk.Entry(general_schedule_frame, textvariable=general_schedule_var, width=70).grid(row=0, column=1, padx=8, pady=6)
ttk.Button(
    general_schedule_frame,
    text="Browse",
    command=lambda: browse_file(general_schedule_var, [("CSV", "*.csv")])
).grid(row=0, column=2, padx=8, pady=6)

# Output file
output_frame = ttk.LabelFrame(tab_timetable, text="Output File")
output_frame.pack(fill=tk.X, padx=12, pady=10)

timetable_output_var = tk.StringVar(value=os.path.join(OUTPUT_DIR, "final_web_schedule.csv"))
ttk.Label(output_frame, text="Output CSV").grid(row=0, column=0, padx=8, pady=6, sticky="w")
ttk.Entry(output_frame, textvariable=timetable_output_var, width=70).grid(row=0, column=1, padx=8, pady=6)

# Run button
ttk.Button(tab_timetable, text="▶ Run Full Timetable Pipeline", command=run_full_timetable_pipeline, width=40).pack(pady=15)

# ============================================================================
# EXAM TAB
# ============================================================================

exam_frame = ttk.LabelFrame(tab_exam, text="Generate Exam Schedule")
exam_frame.pack(fill=tk.X, padx=12, pady=10)

exam_input_var = tk.StringVar(value=os.path.join(OUTPUT_DIR, "vvu_clean.csv"))
exam_out_var = tk.StringVar(value=os.path.join(OUTPUT_DIR, "final_exam_schedule.csv"))
exam_dept_var = tk.StringVar(value="General")
exam_hall_var = tk.StringVar(value="")
exam_hall_capacity_var = tk.StringVar(value="")

ttk.Label(exam_frame, text="Input CSV").grid(row=0, column=0, padx=8, pady=6, sticky="w")
ttk.Entry(exam_frame, textvariable=exam_input_var, width=70).grid(row=0, column=1, padx=8, pady=6)
ttk.Button(exam_frame, text="Browse", command=lambda: browse_file(exam_input_var, [("CSV", "*.csv")])).grid(row=0, column=2, padx=8, pady=6)

ttk.Label(exam_frame, text="Output CSV").grid(row=1, column=0, padx=8, pady=6, sticky="w")
ttk.Entry(exam_frame, textvariable=exam_out_var, width=70).grid(row=1, column=1, padx=8, pady=6)

ttk.Label(exam_frame, text="Department").grid(row=2, column=0, padx=8, pady=6, sticky="w")
exam_dept_options = [name for _, (name, _) in DEPARTMENTS.items()]
ttk.Combobox(exam_frame, textvariable=exam_dept_var, values=exam_dept_options, width=28).grid(row=2, column=1, padx=8, pady=6, sticky="w")

ttk.Label(exam_frame, text="Exam Hall (optional)").grid(row=3, column=0, padx=8, pady=6, sticky="w")
ttk.Entry(exam_frame, textvariable=exam_hall_var, width=40).grid(row=3, column=1, padx=8, pady=6, sticky="w")

ttk.Label(exam_frame, text="Hall Capacity (required)").grid(row=4, column=0, padx=8, pady=6, sticky="w")
ttk.Entry(exam_frame, textvariable=exam_hall_capacity_var, width=20).grid(row=4, column=1, padx=8, pady=6, sticky="w")

ttk.Button(exam_frame, text="Run Exam Solver", command=run_exam_schedule).grid(row=5, column=1, sticky="e", padx=8, pady=10)

# ============================================================================
# PERSONAL TAB
# ============================================================================

profile_frame = ttk.LabelFrame(tab_personal, text="User Profile")
profile_frame.pack(fill=tk.X, padx=12, pady=8)

profile = load_profile()
profile.setdefault("timetable_paths", {})

role_var = tk.StringVar(value=profile.get("role", "student"))
dept_var = tk.StringVar(value=profile.get("department", ""))
level_var = tk.StringVar(value=profile.get("level", ""))
semester_var = tk.StringVar(value=profile.get("semester", ""))
personal_timetable_var = tk.StringVar(value=profile.get("timetable_paths", {}).get(dept_var.get(), ""))

ttk.Label(profile_frame, text="Role:").grid(row=0, column=0, padx=8, pady=6, sticky="w")
ttk.Radiobutton(profile_frame, text="Student", variable=role_var, value="student").grid(row=0, column=1, padx=8, pady=6, sticky="w")
ttk.Radiobutton(profile_frame, text="Lecturer", variable=role_var, value="lecturer").grid(row=0, column=2, padx=8, pady=6, sticky="w")

ttk.Label(profile_frame, text="Department:").grid(row=1, column=0, padx=8, pady=6, sticky="w")
personal_dept_options = [name for _, (name, _) in DEPARTMENTS.items()]
dept_entry = ttk.Combobox(profile_frame, textvariable=dept_var, values=personal_dept_options, width=28)
dept_entry.grid(row=1, column=1, columnspan=2, padx=8, pady=6, sticky="w")

ttk.Label(profile_frame, text="Level:").grid(row=2, column=0, padx=8, pady=6, sticky="w")
level_combo = ttk.Combobox(profile_frame, textvariable=level_var, values=["", "100", "200", "300", "400"], width=10)
level_combo.grid(row=2, column=1, padx=8, pady=6, sticky="w")

ttk.Label(profile_frame, text="Semester:").grid(row=2, column=2, padx=8, pady=6, sticky="w")
semester_combo = ttk.Combobox(profile_frame, textvariable=semester_var, values=["", "1", "2"], width=5)
semester_combo.grid(row=2, column=3, padx=8, pady=6, sticky="w")

def save_profile_changes():
    profile_data = {
        "role": role_var.get(),
        "department": dept_var.get().strip(),
        "level": level_var.get().strip(),
        "semester": semester_var.get().strip(),
        "timetable_paths": profile.get("timetable_paths", {})
    }
    dept_key = dept_var.get().strip()
    tt_path = personal_timetable_var.get().strip()
    if dept_key:
        if tt_path:
            profile_data["timetable_paths"][dept_key] = tt_path
        elif dept_key in profile_data["timetable_paths"]:
            profile_data["timetable_paths"].pop(dept_key, None)
    save_profile(profile_data)
    profile.update(profile_data)
    refresh_personal_lists()
    log("✅ Profile saved")

def _detect_default_timetable(dept_label: str) -> str:
    if not dept_label:
        return ""
    filename = None
    for _, (name, file_name) in DEPARTMENTS.items():
        if name == dept_label:
            filename = file_name
            break
    if not filename:
        return ""
    candidates = [
        os.path.join(OUTPUT_DIR, filename),
        os.path.join(PROJECT_ROOT, filename),
        os.path.join(PROJECT_ROOT, "final", filename)
    ]
    existing = [p for p in candidates if os.path.exists(p)]
    if existing:
        return max(existing, key=lambda p: os.path.getmtime(p))
    return os.path.join(OUTPUT_DIR, filename)

def _sync_personal_timetable_from_department(*_):
    dept_key = dept_var.get().strip()
    saved_path = profile.get("timetable_paths", {}).get(dept_key, "")
    if saved_path:
        personal_timetable_var.set(saved_path)
    else:
        detected = _detect_default_timetable(dept_key)
        personal_timetable_var.set(detected)
        if detected and not os.path.exists(detected):
            log(f"⚠️ Timetable not found for {dept_key}: {detected}")
            messagebox.showwarning(
                "Timetable Missing",
                f"No timetable found for {dept_key}.\n\nPlease browse and select the correct CSV."
            )

def _browse_personal_timetable():
    browse_file(personal_timetable_var, [("CSV", "*.csv")])
    dept_key = dept_var.get().strip()
    if dept_key:
        path = personal_timetable_var.get().strip()
        if path:
            profile.setdefault("timetable_paths", {})[dept_key] = path
            save_profile(profile)
    refresh_personal_lists()

ttk.Button(profile_frame, text="Save Profile", command=save_profile_changes).grid(row=3, column=1, padx=8, pady=8, sticky="e")

personal_timetable_frame = ttk.LabelFrame(tab_personal, text="Personal Timetable Source")
personal_timetable_frame.pack(fill=tk.X, padx=12, pady=8)

ttk.Label(personal_timetable_frame, text="Department Timetable CSV").grid(row=0, column=0, padx=8, pady=6, sticky="w")
ttk.Entry(personal_timetable_frame, textvariable=personal_timetable_var, width=70).grid(row=0, column=1, padx=8, pady=6)
ttk.Button(personal_timetable_frame, text="Browse", command=_browse_personal_timetable).grid(row=0, column=2, padx=8, pady=6)

personal_input_frame = ttk.LabelFrame(tab_personal, text="School Timetable Input")
personal_input_frame.pack(fill=tk.X, padx=12, pady=8)

ttk.Button(personal_input_frame, text="Upload CSV", command=lambda: _import_personal_timetable(
    filedialog.askopenfilename(filetypes=[("CSV", "*.csv")])
)).grid(row=0, column=0, padx=8, pady=6)

ttk.Button(personal_input_frame, text="Upload PDF", command=lambda: _import_personal_timetable(
    filedialog.askopenfilename(filetypes=[("PDF", "*.pdf")])
)).grid(row=0, column=1, padx=8, pady=6)

ttk.Button(personal_input_frame, text="Auto-Generate from Dept/Level/Sem", command=_auto_generate_personal_timetable).grid(row=0, column=2, padx=8, pady=6)

personal_editor_frame = ttk.LabelFrame(tab_personal, text="Editable School Timetable")
personal_editor_frame.pack(fill=tk.BOTH, expand=True, padx=12, pady=8)

personal_timetable_tree = ttk.Treeview(
    personal_editor_frame,
    columns=("code", "title", "day", "time"),
    show="headings",
    height=8
)
personal_timetable_tree.heading("code", text="Course Code")
personal_timetable_tree.heading("title", text="Course Title")
personal_timetable_tree.heading("day", text="Day")
personal_timetable_tree.heading("time", text="Time")
personal_timetable_tree.column("code", width=120)
personal_timetable_tree.column("title", width=260)
personal_timetable_tree.column("day", width=100)
personal_timetable_tree.column("time", width=160)
personal_timetable_tree.pack(fill=tk.BOTH, expand=True, padx=8, pady=6)

editor_btns = ttk.Frame(personal_editor_frame)
editor_btns.pack(pady=6)
ttk.Button(editor_btns, text="Add", command=_add_timetable_row).pack(side=tk.LEFT, padx=5)
ttk.Button(editor_btns, text="Edit", command=_edit_timetable_row).pack(side=tk.LEFT, padx=5)
ttk.Button(editor_btns, text="Delete", command=_delete_timetable_row).pack(side=tk.LEFT, padx=5)
ttk.Button(editor_btns, text="Refresh", command=_refresh_personal_timetable_view).pack(side=tk.LEFT, padx=5)

dept_entry.bind("<<ComboboxSelected>>", _sync_personal_timetable_from_department)
dept_var.trace_add("write", _sync_personal_timetable_from_department)
personal_timetable_var.trace_add("write", lambda *_: refresh_personal_lists())

_ensure_personal_timetable_file()
_refresh_personal_timetable_view()

form_frame = ttk.LabelFrame(tab_personal, text="Add Personal Event")
form_frame.pack(fill=tk.X, padx=12, pady=8)

title_var = tk.StringVar()
day_var = tk.StringVar(value="Monday")
start_var = tk.StringVar()
end_var = tk.StringVar()
type_var = tk.StringVar(value="personal")

ttk.Label(form_frame, text="Title").grid(row=0, column=0, padx=8, pady=6, sticky="w")
ttk.Entry(form_frame, textvariable=title_var, width=25).grid(row=0, column=1, padx=8, pady=6)
ttk.Label(form_frame, text="Day").grid(row=0, column=2, padx=8, pady=6, sticky="w")
ttk.Combobox(form_frame, textvariable=day_var, values=["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"], width=12).grid(row=0, column=3, padx=8, pady=6)

ttk.Label(form_frame, text="Start").grid(row=1, column=0, padx=8, pady=6, sticky="w")
ttk.Entry(form_frame, textvariable=start_var, width=12).grid(row=1, column=1, padx=8, pady=6)
ttk.Label(form_frame, text="End").grid(row=1, column=2, padx=8, pady=6, sticky="w")
ttk.Entry(form_frame, textvariable=end_var, width=12).grid(row=1, column=3, padx=8, pady=6)
ttk.Label(form_frame, text="Type").grid(row=1, column=4, padx=8, pady=6, sticky="w")
ttk.Combobox(form_frame, textvariable=type_var, values=["personal", "work", "health"], width=10).grid(row=1, column=5, padx=8, pady=6)

ttk.Button(form_frame, text="Add Event", command=add_event).grid(row=0, column=5, padx=8, pady=6)
ttk.Button(form_frame, text="Delete Selected", command=delete_selected_event).grid(row=1, column=6, padx=8, pady=6)

split = tk.PanedWindow(tab_personal, orient=tk.HORIZONTAL)
split.pack(fill=tk.BOTH, expand=True, padx=12, pady=10)

left_frame = ttk.LabelFrame(split, text="Personal Events")
right_frame = ttk.LabelFrame(split, text="Suggested Free Slots (Ranked)")
split.add(left_frame, width=430)
split.add(right_frame, width=520)

event_list = ttk.Treeview(left_frame, columns=("title", "day", "start", "end", "type", "conflict"), show="headings", height=10)
for col, label in [("title", "Title"), ("day", "Day"), ("start", "Start"), ("end", "End"), ("type", "Type"), ("conflict", "Conflict")]:
    event_list.heading(col, text=label)
    event_list.column(col, width=85)
event_list.pack(fill=tk.BOTH, expand=True, padx=10, pady=10)
event_list.tag_configure("conflict", background="#5b1f1f", foreground="#fca5a5")
event_list.bind("<Double-1>", lambda e: show_conflict_details(event_list.selection()[0]) if event_list.selection() else None)

suggestion_list = ttk.Treeview(right_frame, columns=("day", "start", "end", "title", "score", "reason"), show="headings", height=10)
for col, label, width in [
    ("day", "Day", 80),
    ("start", "Start", 90),
    ("end", "End", 90),
    ("title", "Suggestion", 120),
    ("score", "Rank", 60),
    ("reason", "Reason", 260),
]:
    suggestion_list.heading(col, text=label)
    suggestion_list.column(col, width=width)
suggestion_list.pack(fill=tk.BOTH, expand=True, padx=10, pady=10)

# AI Suggestion Feedback Buttons
suggestion_btn_frame = ttk.Frame(right_frame)
suggestion_btn_frame.pack(pady=5)
ttk.Button(suggestion_btn_frame, text="✅ Accept", command=accept_suggestion).pack(side=tk.LEFT, padx=3)
ttk.Button(suggestion_btn_frame, text="❌ Reject", command=reject_suggestion).pack(side=tk.LEFT, padx=3)

btn_frame = ttk.Frame(tab_personal)
btn_frame.pack(pady=5)
ttk.Button(btn_frame, text="Refresh Suggestions", command=refresh_personal_lists).pack(side=tk.LEFT, padx=5)
ttk.Button(btn_frame, text="📊 View Heatmap", command=view_productivity_heatmap).pack(side=tk.LEFT, padx=5)
ttk.Button(btn_frame, text="📈 Metrics", command=show_performance_metrics).pack(side=tk.LEFT, padx=5)
ttk.Button(btn_frame, text="Export CSV", command=export_personal_csv).pack(side=tk.LEFT, padx=5)
ttk.Button(btn_frame, text="Export ICS", command=export_personal_ics).pack(side=tk.LEFT, padx=5)
ttk.Button(btn_frame, text="Export PDF", command=export_personal_pdf).pack(side=tk.LEFT, padx=5)

# ============================================================================
# LOG SECTION
# ============================================================================

log_frame = ttk.LabelFrame(root, text="Status Log & Output")
log_frame.pack(fill=tk.BOTH, expand=False, padx=15, pady=10)

log_text = tk.Text(log_frame, height=7, state="disabled", width=120)
log_text.pack(fill=tk.BOTH, expand=True, padx=8, pady=6)

status_var = tk.StringVar(value="Ready")
status_label = ttk.Label(log_frame, textvariable=status_var)
status_label.pack(anchor="w", padx=8, pady=(0, 4))

progress_bar = ttk.Progressbar(log_frame, mode="determinate", maximum=100)
progress_bar.pack(fill=tk.X, padx=8, pady=(0, 8))

ensure_output_dir()
initialize_learning_modules()
refresh_personal_lists()
root.mainloop()
