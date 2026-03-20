import csv
import os
import re
from typing import List, Dict, Any, Optional
from .models import Course, Room, Day, Lecturer

class DataLoader:
    def __init__(self, base_path: str):
        self.base_path = base_path
        self.courses = []
        self.rooms = {}
        self.lecturers = {}
        self.aliases = {}
        self.levels = self._load_levels()

    def _load_levels(self):
        levels = {}
        # Search for level files in base_path
        for i in range(1, 5):
            path = self._resolve_path(f"level_{i}00.csv")
            if os.path.exists(path):
                with open(path, 'r') as f:
                    reader = csv.DictReader(f)
                    for row in reader:
                        levels[row['course_code']] = i
        return levels

    def _resolve_path(self, filename: str) -> str:
        """Resolve path with fallback to parent directory"""
        path = os.path.join(self.base_path, filename)
        if not os.path.exists(path):
            parent_path = os.path.join(os.path.dirname(self.base_path.rstrip(os.sep)) or '.', filename)
            if os.path.exists(parent_path):
                return parent_path
        return path

    def load_lecturer_availability(self, interactive=False):
        path = self._resolve_path("lecturer_availability.csv")
        days = ["Mon", "Tue", "Wed", "Thu", "Fri"]
        day_map = {"Mon": "Monday", "Tue": "Tuesday", "Wed": "Wednesday", "Thu": "Thursday", "Fri": "Friday"}
        
        if os.path.exists(path):
            with open(path, 'r') as f:
                reader = csv.DictReader(f)
                for row in reader:
                    name = row['lecturer_name']
                    avail = {day_map[d]: row[d] == '1' for d in days}
                    self.lecturers[name] = Lecturer(name, avail)

    def get_lecturer(self, name, interactive=False):
        if name not in self.lecturers:
            if interactive:
                print(f"\nAvailability missing for lecturer: {name}")
                print("Enter availability for 5 days: Monday, Tuesday, Wednesday, Thursday, Friday")
                print("Use: 0 = Not available, 1 = Available")
                print("Format examples:")
                print("  - '0,1,1,0,1' for specific days")
                print("  - '1,1,1,1,1' or 'none' or just press Enter for all days available")
                
                user_input = input(f"Availability for {name} (5 values separated by commas): ").strip()
                
                # Parse the input
                avail = {}
                days = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday"]
                
                if not user_input or user_input.lower() == "none":
                    # Default: all days available
                    avail = {d: True for d in days}
                    print(f"✓ {name} set to available all days")
                else:
                    # Parse comma-separated values
                    try:
                        values = user_input.split(',')
                        values = [v.strip() for v in values]
                        
                        # If only 1 value provided, use it for all days
                        if len(values) == 1:
                            val = values[0]
                            avail = {d: val in ['1', 'yes', 'y', 'true'] for d in days}
                            days_available = sum(1 for v in avail.values() if v)
                            print(f"✓ {name}: {days_available}/5 days available")
                        else:
                            # Match values to days
                            if len(values) != 5:
                                raise ValueError(f"Expected 5 values, got {len(values)}")
                            
                            avail = {}
                            for day, val in zip(days, values):
                                # Accept 0/1, y/n, yes/no, true/false
                                is_available = val in ['1', 'yes', 'y', 'true', 'Yes', 'True']
                                avail[day] = is_available
                            
                            days_available = sum(1 for v in avail.values() if v)
                            print(f"✓ {name}: {days_available}/5 days available")

                            status = [f"{d}: {'OK' if avail[d] else 'NO'}" for d in days]
                            print(f"  {', '.join(status)}")
                    
                    except ValueError as e:
                        print(f"⚠ Invalid input: {e}. Using default (all days available)")
                        avail = {d: True for d in days}
                
                self.lecturers[name] = Lecturer(name, avail)
                # Save new lecturer to CSV
                self._save_lecturer_to_csv(name, avail)
            else:
                # Default to all True
                self.lecturers[name] = Lecturer(name, {d: True for d in ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday"]})
        return self.lecturers[name]
    
    def _save_lecturer_to_csv(self, name: str, availability: dict):
        """Save newly added lecturer to lecturer_availability.csv"""
        import csv
        
        path = self._resolve_path("lecturer_availability.csv")
        day_map_rev = {v: k for k, v in {"Mon": "Monday", "Tue": "Tuesday", "Wed": "Wednesday", "Thu": "Thursday", "Fri": "Friday"}.items()}
        
        try:
            # Check if file exists and has headers
            file_exists = os.path.exists(path)
            
            with open(path, 'a', newline='') as f:
                writer = csv.DictWriter(f, fieldnames=['lecturer_name', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri'])
                
                # Write header if file is new
                if not file_exists or os.path.getsize(path) == 0:
                    writer.writeheader()
                
                # Write lecturer data
                row = {'lecturer_name': name}
                for day_full, day_short in day_map_rev.items():
                    row[day_short] = '1' if availability.get(day_full, True) else '0'
                
                writer.writerow(row)
            
            print(f"  ✓ Saved {name} to lecturer_availability.csv")
        
        except Exception as e:
            print(f"  ⚠ Could not save lecturer to CSV: {e}")

    def _populate_group_keys(self, courses: list[Course]):
        """Populate the group_key for each Course based on loaded groups"""
        if not hasattr(self, 'course_groups') or not self.course_groups:
            return
            
        for course in courses:
            # Check if this course code belongs to a group
            if course.code in self.course_groups:
                # Use the sorted group members joined as a unique key
                group_members = sorted(self.course_groups[course.code])
                course.group_key = f"group_{'_'.join(group_members)}"

    def load_aliases(self):
        path = self._resolve_path("shared_course_aliases.csv")
        if os.path.exists(path):
            with open(path, 'r') as f:
                reader = csv.DictReader(f)
                for row in reader:
                    self.aliases[row['alias_code']] = row['canonical_code']

    def load_course_groups(self):
        """Load same_courses.csv and same_coursesid.csv to return groups of equivalent courses.
        Returns: Dict[str, List[str]] - maps each course code to list of all codes in its group
        """
        course_groups = {}
        
        # 1. Title/Content similarity groups (same_courses.csv)
        path = self._resolve_path("same_courses.csv")
        if os.path.exists(path):
            with open(path, 'r') as f:
                reader = csv.reader(f)
                next(reader) # skip header
                for row in reader:
                    if not row: continue
                    line_text = ",".join(row)
                    codes = [c.strip().upper() for c in line_text.split(',') if c.strip()]
                    if len(codes) > 1:
                        for code in codes:
                            course_groups[code] = codes
        
        # 2. Forced same-time group IDs (same_coursesid.csv)
        # This maps to shared_group_id in the models
        path_ids = self._resolve_path("same_coursesid.csv")
        self.shared_group_ids = {}
        if os.path.exists(path_ids):
            with open(path_ids, 'r') as f:
                reader = csv.DictReader(f)
                for row in reader:
                    code = (row.get('course_code') or "").strip().upper()
                    group_id = (row.get('group_id') or "").strip()
                    if code and group_id:
                        self.shared_group_ids[code] = group_id
        
        return course_groups

    def load_rooms(self, dept_filter=None):
        self.rooms.clear() # Prevent accumulation from previous load calls
        # General rooms (skip when a department filter is set)
        use_general_rooms = not dept_filter or dept_filter == "general"
        path = self._resolve_path("rooms.csv")
        if use_general_rooms and os.path.exists(path):
            with open(path, 'r') as f:
                reader = csv.DictReader(f)
                for row in reader:
                    self.rooms[row['room_name']] = Room(row['room_name'], int(row['capacity'] or 0))

        # Specialized logic for dept rooms
        if dept_filter and dept_filter != "general":
            # Mapping for common names to filenames
            raw_target = dept_filter.lower().strip()
            mappings = {
                "computer": "computing_science",
                "cs": "computing_science",
                "computer science": "computing_science",
                "computing science": "computing_science",
                "IT" : "computing_science"
                "BIS" "computing_science"
            }
            mapped_dept = mappings.get(raw_target, raw_target.replace(" ", "_"))
            dept_room_file = f"{mapped_dept}_rooms.csv"
            
            # Try finding in department_room folder
            dept_path_rel = os.path.join("department_room", dept_room_file)
            dept_path = self._resolve_path(dept_path_rel)
            if os.path.exists(dept_path):
                with open(dept_path, 'r') as f:
                    reader = csv.DictReader(f)
                    for row in reader:
                        self.rooms[row['room_name']] = Room(
                            row['room_name'],
                            int(row['capacity'] or 0),
                            department=dept_filter
                        )

    def load_courses(self, dept_filter=None, semester_filter=None, specific_file=None):
        self.courses = [] # Clear previous courses to prevent accumulation
        files = []
        if specific_file:
            # If a full path or just a filename is provided
            files = [specific_file]
        elif dept_filter and dept_filter.lower().strip() == "general":
            files = ["general.csv"]
        elif dept_filter and dept_filter.lower().strip() in ["computer", "computing_science", "cs", "computer science", "computing science"]:
            files = ["computer.csv"]
        else:
            files = ["general.csv", "computer.csv"] # default load both

        for file in files:
            # Try full path first, then relative to base_path
            path = file if os.path.isabs(file) else os.path.join(self.base_path, file)
            if not os.path.exists(path):
                # Try relative to base_path if it was just a filename
                path = os.path.join(self.base_path, os.path.basename(file))
                if not os.path.exists(path):
                    continue
            
            with open(path, 'r', encoding='utf-8-sig') as f:
                reader = csv.DictReader(f)
                headers = reader.fieldnames
                if not headers: continue
                
                # Normalize headers for case-insensitive matching
                header_map = {h.strip().lower(): h for h in headers}
                
                # Detect if this is a "Scheduled Timetable" (class schedule)
                # Check for common timetable identifiers in a case-insensitive way
                is_timetable = any(k in header_map for k in ["lecturer name", "room name", "time", "day"])
                
                # Column mappings based on format
                    if is_timetable:
                        # Mapping for Generated Class Timetable format
                        col_code = header_map.get("course code")
                        col_title = header_map.get("course title")
                        col_sem = header_map.get("semester")
                        col_level = header_map.get("course_level") or header_map.get("level")
                        col_lecturer = header_map.get("lecturer name") or header_map.get("invigilator")
                        col_credits = header_map.get("credit hrs") or header_map.get("credits")
                        col_enrollment = header_map.get("enrollment") or header_map.get("no_of_students")
                    else:
                        # Mapping for Raw Curriculum format
                        col_code = header_map.get("course_code") or header_map.get("code")
                        col_title = header_map.get("course_title") or header_map.get("title")
                        col_sem = header_map.get("semester")
                        col_level = header_map.get("course_level") or header_map.get("level")
                        col_lecturer = header_map.get("lecturer_name") or header_map.get("lecturer")
                        col_credits = header_map.get("credit_hours") or header_map.get("credits")
                        col_enrollment = header_map.get("enrollment") or header_map.get("no_of_students")
                    
                    # New Root-Specific Columns
                    col_dept_group = header_map.get("departmental_group")
                    col_source_type = header_map.get("source_type")

                    # Loop through rows
                    for row in reader:
                        if not any(row.values()): continue
                        
                        code = str(row.get(col_code) or "").strip().upper()
                        if not code: continue
                        
                        title = str(row.get(col_title) or "").strip()
                        sem_val = str(row.get(col_sem) or "").strip()
                        level_val = row.get(col_level)
                        lecturer = str(row.get(col_lecturer) or "TBA").strip()
                        credits = str(row.get(col_credits) or "3").strip()
                        enrollment = int(float(row.get(col_enrollment) or 30))
                        
                        # Identify department group
                        dept_group = str(row.get(col_dept_group) or "General").strip()
                        is_general = str(row.get(col_source_type) or "").strip().lower() == "general" or dept_group == "General"

                        # Level inference
                        level = 1
                        if code in self.levels:
                            level = self.levels[code]
                        elif level_val:
                            try:
                                level = int(float(level_val))
                                if level >= 100: level = level // 100
                            except: pass

                        course = Course(
                            code=code,
                            title=title,
                            level=level,
                            semester=int(float(sem_val)) if sem_val and sem_val.isdigit() else 1,
                            credits=credits,
                            lecturer=lecturer,
                            is_general=is_general,
                            enrollment=enrollment,
                            departmental_group=dept_group,
                            shared_group_id=self.shared_group_ids.get(code) if hasattr(self, 'shared_group_ids') else None,
                            id=row.get('id') or f"{code}_{len(self.courses)}"
                        )
                        
                        if semester_filter and str(course.semester) != str(semester_filter):
                            continue
                            
                        self.courses.append(course)
                        try:
                            if val and int(val) != semester_filter:
                                continue
                        except:
                            pass
                    
                    code_raw = row.get(col_code, '')
                    if not code_raw: continue
                    
                    title_raw = row.get(col_title, '')
                    
                    # Deduplicate if loading from a timetable, but keep different sections
                    # Derive a unique key that includes section if possible
                    section_val = ""
                    if '[Sec' in title_raw:
                        import re
                        s_match = re.search(r'\[Sec\s+([A-Za-z0-9]+)\]', title_raw)
                        if s_match:
                            section_val = s_match.group(1)
                    
                    # Only deduplicate if perfectly identical code + section
                    dedup_key = f"{code_raw}_{section_val}"
                    if is_timetable and dedup_key in processed_codes:
                        continue
                    processed_codes.add(dedup_key)
                    
                    # Handle multiple codes in one row (shared/aliased) - mostly for raw format
                    codes = [c.strip() for c in re.split(r'/|&', code_raw)]
                    titles = [t.strip() for t in title_raw.split('/')]
                    
                    if len(titles) < len(codes):
                        titles.extend([titles[-1]] * (len(codes) - len(titles)))
                    
                    for idx, c in enumerate(codes):
                        # If multiple codes/titles, treat as a single course entity
                        if len(codes) > 1:
                            canonical_codes = [self.aliases.get(code, code) for code in codes]
                            combined_code = " / ".join(canonical_codes)
                            combined_title = " / ".join([t for t in titles])
                            level_val = row.get(col_level, 0)
                            try:
                                level = int(level_val) if level_val else 0
                            except:
                                level = 0
                            if level == 0:
                                level = self.levels.get(canonical_codes[0], 0)
                            normalized_title = re.sub(r'\[Sec [A-Z0-9]+\]', '', combined_title).strip().lower()
                            numeric_part = re.search(r'\d+', combined_code)
                            num_val = numeric_part.group() if numeric_part else ""
                            group_key = f"group_{'_'.join(sorted(canonical_codes))}"
                            try:
                                sem_val = int(row.get(col_sem, 1))
                            except:
                                sem_val = 1
                            try:
                                enrollment_val = int(row.get(col_enrollment, 30))
                            except:
                                enrollment_val = 30
                            course = Course(
                                code=combined_code,
                                title=combined_title,
                                level=level,
                                semester=sem_val,
                                credits=row.get(col_credits, "3"),
                                lecturer=row.get(col_lecturer, "TBA"),
                                is_general=(row.get('source_type') == 'General'),
                                enrollment=enrollment_val,
                                aliases=[alias for alias in canonical_codes],
                                group_key=group_key
                            )
                            self.courses.append(course)
                            break # Only add one course for the group
                        else:
                            canonical = self.aliases.get(c, c)
                            level_val = row.get(col_level, 0)
                            try:
                                level = int(level_val) if level_val else 0
                            except:
                                level = 0
                            if level == 0:
                                level = self.levels.get(canonical, 0)
                            specific_title = titles[idx] if idx < len(titles) else titles[-1]
                            normalized_title = re.sub(r'\[Sec [A-Z0-9]+\]', '', specific_title).strip().lower()
                            numeric_part = re.search(r'\d+', c)
                            num_val = numeric_part.group() if numeric_part else ""
                            group_key = f"group_{normalized_title}"
                            if not normalized_title and num_val:
                                group_key = f"num_{num_val}"
                            try:
                                sem_val = int(row.get(col_sem, 1))
                            except:
                                sem_val = 1
                            try:
                                enrollment_val = int(row.get(col_enrollment, 30))
                            except:
                                enrollment_val = 30
                            course = Course(
                                code=c,
                                title=specific_title,
                                level=level,
                                semester=sem_val,
                                credits=row.get(col_credits, "3"),
                                lecturer=row.get(col_lecturer, "TBA"),
                                is_general=(row.get('source_type') == 'General'),
                                enrollment=enrollment_val,
                                aliases=[alias for alias in codes if alias != c],
                                group_key=group_key
                            )
                            self.courses.append(course)

    def load_all(self, dept_filter=None, semester_filter=None, interactive_avail=False, specific_file=None):
        self.load_aliases()
        self.load_lecturer_availability()
        self.load_rooms(dept_filter)
        self.course_groups = self.load_course_groups()
        self.load_courses(dept_filter, semester_filter, specific_file)
        self._populate_group_keys(self.courses)
        if self.courses:
            print(f"✓ Parallel data loader: {len(self.courses)} courses loaded for {dept_filter or 'all'}")
        return self.courses, list(self.rooms.values())
