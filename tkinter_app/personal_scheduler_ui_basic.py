import csv
import os
import sys
import importlib
import tkinter as tk
from datetime import datetime, timedelta
from tkinter import filedialog, messagebox, ttk

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

OUTPUT_DIR = os.path.join(os.path.dirname(__file__), "output")
DATA_FILE = os.path.join(OUTPUT_DIR, "personal_events.csv")
PROFILE_FILE = os.path.join(OUTPUT_DIR, "user_profile.json")


def ensure_output_dir():
    os.makedirs(OUTPUT_DIR, exist_ok=True)


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
    return {"role": "student", "department": "", "level": "", "semester": ""}


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
            writer.writerow(["title", "day", "start_time", "end_time", "event_type"])  # header


def load_personal_events():
    ensure_data_file()
    events = []
    busy_blocks = []
    with open(DATA_FILE, newline="", encoding="utf-8") as f:
        reader = csv.DictReader(f)
        for row in reader:
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


def refresh_personal_lists():
    event_list.delete(*event_list.get_children())
    suggestion_list.delete(*suggestion_list.get_children())

    events, busy_blocks = load_personal_events()
    base_blocks = load_institution_blocks(OUTPUT_DIR)
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

    _, suggestions = build_personal_schedule(OUTPUT_DIR, busy_blocks, role_var.get())
    for s in suggestions[:50]:
        suggestion_list.insert(
            "", tk.END,
            values=(
                s.day,
                s.start.strftime("%I:%M %p"),
                s.end.strftime("%I:%M %p"),
                s.title,
                f"{s.score:.1f}",
                s.reason,
            ),
        )


def browse_file(target_var, filetypes):
    path = filedialog.askopenfilename(filetypes=filetypes)
    if path:
        target_var.set(path)


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


def run_timetable():
    input_csv = schedule_input_var.get().strip()
    output_csv = schedule_out_var.get().strip()
    semester = int(schedule_semester_var.get())
    general_flag = 1 if use_general_var.get() else 0
    if not input_csv or not output_csv:
        messagebox.showerror("Missing File", "Select input and output CSV files.")
        return
    try:
        set_busy("Loading schedule data...", 20)
        root.update_idletasks()
        set_busy("Solving timetable constraints...", 50)
        root.update_idletasks()
        from main_web import run_headless
        success = run_headless(input_csv, semester, output_csv, general_flag)
        if success:
            set_busy("Timetable complete!", 100)
            log(f"✅ Timetable generated: {output_csv}")
        else:
            set_busy("Solver failed!", 0)
            log("⚠️ Timetable solver failed to find a valid solution.")
            messagebox.showwarning("Solver Failed", "No valid timetable found.")
    except Exception as exc:
        messagebox.showerror("Timetable Error", str(exc))
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
        success = run_headless_exam(input_csv, output_csv)
        if success:
            set_busy("Exam schedule complete!", 100)
            log(f"✅ Exam schedule generated: {output_csv}")
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
    base_blocks = load_institution_blocks(OUTPUT_DIR)
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


def export_personal_csv():
    ensure_output_dir()
    events, busy_blocks = load_personal_events()
    _, suggestions = build_personal_schedule(OUTPUT_DIR, busy_blocks, role_var.get())

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
    _, suggestions = build_personal_schedule(OUTPUT_DIR, busy_blocks, role_var.get())

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
    _, suggestions = build_personal_schedule(OUTPUT_DIR, busy_blocks, role_var.get())

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
    base_blocks = load_institution_blocks(OUTPUT_DIR)
    
    # Find the event with matching title, day, start, end
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
    
    # Show conflict details in a new window
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


root = tk.Tk()
root.title("AI Scheduling System (Tkinter)")
root.geometry("1100x820")

header = tk.Label(root, text="AI Scheduling System", font=("Inter", 18, "bold"))
header.pack(pady=8)

notebook = ttk.Notebook(root)
notebook.pack(fill=tk.BOTH, expand=True, padx=15, pady=10)

tab_data = ttk.Frame(notebook)
tab_timetable = ttk.Frame(notebook)
tab_exam = ttk.Frame(notebook)
tab_personal = ttk.Frame(notebook)

notebook.add(tab_data, text="Data Prep")
notebook.add(tab_timetable, text="Timetable")
notebook.add(tab_exam, text="Exams")
notebook.add(tab_personal, text="Personal")

# Data Prep Tab
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

# Timetable Tab
timetable_frame = ttk.LabelFrame(tab_timetable, text="Generate Timetable")
timetable_frame.pack(fill=tk.X, padx=12, pady=10)

schedule_input_var = tk.StringVar(value=os.path.join(OUTPUT_DIR, "vvu_clean.csv"))
schedule_out_var = tk.StringVar(value=os.path.join(OUTPUT_DIR, "final_web_schedule.csv"))
schedule_semester_var = tk.StringVar(value="2")
use_general_var = tk.BooleanVar(value=True)

ttk.Label(timetable_frame, text="Input CSV").grid(row=0, column=0, padx=8, pady=6, sticky="w")
ttk.Entry(timetable_frame, textvariable=schedule_input_var, width=70).grid(row=0, column=1, padx=8, pady=6)
ttk.Button(timetable_frame, text="Browse", command=lambda: browse_file(schedule_input_var, [("CSV", "*.csv")])).grid(row=0, column=2, padx=8, pady=6)

ttk.Label(timetable_frame, text="Output CSV").grid(row=1, column=0, padx=8, pady=6, sticky="w")
ttk.Entry(timetable_frame, textvariable=schedule_out_var, width=70).grid(row=1, column=1, padx=8, pady=6)

ttk.Label(timetable_frame, text="Semester").grid(row=2, column=0, padx=8, pady=6, sticky="w")
ttk.Combobox(timetable_frame, textvariable=schedule_semester_var, values=["1", "2"], width=5).grid(row=2, column=1, sticky="w", padx=8, pady=6)
ttk.Checkbutton(timetable_frame, text="Use General Schedule Blocking", variable=use_general_var).grid(row=2, column=1, sticky="e", padx=8, pady=6)

ttk.Button(timetable_frame, text="Run Timetable Solver", command=run_timetable).grid(row=3, column=1, sticky="e", padx=8, pady=10)

# Exam Tab
exam_frame = ttk.LabelFrame(tab_exam, text="Generate Exam Schedule")
exam_frame.pack(fill=tk.X, padx=12, pady=10)

exam_input_var = tk.StringVar(value=os.path.join(OUTPUT_DIR, "vvu_clean.csv"))
exam_out_var = tk.StringVar(value=os.path.join(OUTPUT_DIR, "final_exam_schedule.csv"))

ttk.Label(exam_frame, text="Input CSV").grid(row=0, column=0, padx=8, pady=6, sticky="w")
ttk.Entry(exam_frame, textvariable=exam_input_var, width=70).grid(row=0, column=1, padx=8, pady=6)
ttk.Button(exam_frame, text="Browse", command=lambda: browse_file(exam_input_var, [("CSV", "*.csv")])).grid(row=0, column=2, padx=8, pady=6)

ttk.Label(exam_frame, text="Output CSV").grid(row=1, column=0, padx=8, pady=6, sticky="w")
ttk.Entry(exam_frame, textvariable=exam_out_var, width=70).grid(row=1, column=1, padx=8, pady=6)

ttk.Button(exam_frame, text="Run Exam Solver", command=run_exam_schedule).grid(row=2, column=1, sticky="e", padx=8, pady=10)

# Personal Tab
profile_frame = ttk.LabelFrame(tab_personal, text="User Profile")
profile_frame.pack(fill=tk.X, padx=12, pady=8)

profile = load_profile()

role_var = tk.StringVar(value=profile.get("role", "student"))
dept_var = tk.StringVar(value=profile.get("department", ""))
level_var = tk.StringVar(value=profile.get("level", ""))
semester_var = tk.StringVar(value=profile.get("semester", ""))

ttk.Label(profile_frame, text="Role:").grid(row=0, column=0, padx=8, pady=6, sticky="w")
ttk.Radiobutton(profile_frame, text="Student", variable=role_var, value="student").grid(row=0, column=1, padx=8, pady=6, sticky="w")
ttk.Radiobutton(profile_frame, text="Lecturer", variable=role_var, value="lecturer").grid(row=0, column=2, padx=8, pady=6, sticky="w")

ttk.Label(profile_frame, text="Department:").grid(row=1, column=0, padx=8, pady=6, sticky="w")
dept_entry = ttk.Entry(profile_frame, textvariable=dept_var, width=30)
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
    }
    save_profile(profile_data)
    refresh_personal_lists()
    log("✅ Profile saved")

ttk.Button(profile_frame, text="Save Profile", command=save_profile_changes).grid(row=3, column=1, padx=8, pady=8, sticky="e")

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

btn_frame = ttk.Frame(tab_personal)
btn_frame.pack(pady=5)
ttk.Button(btn_frame, text="Refresh Suggestions", command=refresh_personal_lists).pack(side=tk.LEFT, padx=5)
ttk.Button(btn_frame, text="Export CSV", command=export_personal_csv).pack(side=tk.LEFT, padx=5)
ttk.Button(btn_frame, text="Export ICS", command=export_personal_ics).pack(side=tk.LEFT, padx=5)
ttk.Button(btn_frame, text="Export PDF", command=lambda: export_personal_pdf()).pack(side=tk.LEFT, padx=5)

# Log Section
log_frame = ttk.LabelFrame(root, text="Status Log")
log_frame.pack(fill=tk.BOTH, expand=False, padx=15, pady=10)

log_text = tk.Text(log_frame, height=6, state="disabled")
log_text.pack(fill=tk.BOTH, expand=True, padx=8, pady=6)

status_var = tk.StringVar(value="Ready")
status_label = ttk.Label(log_frame, textvariable=status_var)
status_label.pack(anchor="w", padx=8, pady=(0, 4))

progress_bar = ttk.Progressbar(log_frame, mode="determinate", maximum=100)
progress_bar.pack(fill=tk.X, padx=8, pady=(0, 8))

ensure_output_dir()
refresh_personal_lists()
root.mainloop()
