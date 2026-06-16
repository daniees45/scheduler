"""
Student Feedback Integration for Schedule Clash Resolution
- Allows students to report course clashes
- Scheduler will use these as hard constraints in the next optimization
"""
import csv
from typing import List, Tuple
import os

FEEDBACK_FILE = 'csv/general/student_clashes.csv'

def add_feedback(student_id: str, course1: str, course2: str, semester: str, timetable_file: str = "", reason: str = ""):
    """Append a feedback record to the CSV file."""
    with open(FEEDBACK_FILE, 'a', newline='') as f:
        writer = csv.writer(f)
        writer.writerow([student_id, course1, course2, semester, timetable_file, reason])

def load_feedback() -> List[dict]:
    """Load all feedback records as dicts."""
    try:
        with open(FEEDBACK_FILE, 'r') as f:
            reader = csv.reader(f)
            headers = next(reader, None)
            if not headers: return []
            return [dict(zip(headers, row)) for row in reader if row]
    except FileNotFoundError:
        return []

def get_student_clash_constraints(semester: str, output_file: str = None) -> List[Tuple[str, str, str]]:
    """Return list of (student_id, course1, course2) for the given semester and optional timetable_file."""
    try:
        from load_data import normalize_course_code
    except ImportError:
        normalize_course_code = lambda x: x # Fallback

    clashes = []
    for row in load_feedback():
        sem = row.get('semester', '')
        if sem != semester and semester not in [None, "3"]:
            continue
            
        tf = row.get('timetable_file', '')
        if output_file and tf and tf != os.path.basename(output_file):
            # Optional: strict matching by output file
            pass

        sid = row.get('student_id', '')
        c1 = normalize_course_code(row.get('course1', ''))
        c2 = normalize_course_code(row.get('course2', ''))
        if c1 and c2:
            clashes.append((sid, c1, c2))
    return clashes
