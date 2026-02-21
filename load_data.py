
import pandas as pd
import os
import shutil
from typing import  Dict, List
from data_model import Lecturer, Room, Course, ClassSection

day_to_index = {"Mon":0, "Tue":1, "Wed":2, "Thu":3, "Fri":4,
                "Monday":0, "Tuesday":1, "Wednesday":2, "Thursday":3, "Friday":4}

def normalize_name(name: str) -> str:
    if not isinstance(name, str):
        return ""
    name = name .replace(".", " ").replace("-", " ")
    return " ".join(name.split()).lower()

def normalize_program_label(label: str) -> str:
    if not isinstance(label, str):
        return ""
    return " ".join(label.replace("/", " ").replace("-", " ").split()).lower()

def split_department_labels(raw: str) -> List[str]:
    if not isinstance(raw, str):
        return []
    parts = [p.strip() for p in raw.replace("/", ",").replace(";", ",").split(",")]
    return [p for p in parts if p]

def get_department_group(course_code: str) -> str:
    """Categorize course into department groups based on prefix."""
    code = str(course_code).upper()
    
    # CS/IT/BBIS Group
    if any(prefix in code for prefix in ["COSC", "INFT", "BBIS", "CSCD"]):
        return "CS/IT/BBIS"
    # Business Group
    if any(prefix in code for prefix in ["ACCT", "BUSI", "MGMT", "ECON", "MKTG", "FNCE"]):
        return "Business"
    # Education Group
    if any(prefix in code for prefix in ["EDUC", "PEDC", "TEAC", "CLED"]):
        return "Education"
    # Development Studies Group
    if any(prefix in code for prefix in ["DEVS", "INTL", "AFRI", "AFRN"]):
        return "DevelopmentStudies"
    # Biomedical Engineering Group
    if any(prefix in code for prefix in ["BIOM", "ENGR", "BENG", "HLTC"]):
        return "BiomedicalEngineering"
    # Nursing Group
    if any(prefix in code for prefix in ["NURS", "RNSG", "MIDW"]):
        return "Nursing"
    # Theology Group
    if any(prefix in code for prefix in ["RELB", "RELT"]):
        return "Theology"
    return "General"


def get_department_room_file(department: str) -> str:
    """Map department name to its dedicated room CSV file."""
    base_dir = "csv"
    # Check if we are running in B2 mode (temp dir blocks existing)
    if os.path.exists("temp/csv"):
        base_dir = "temp/csv"
    
    room_file_map = {
        "CS/IT/BBIS": f"{base_dir}/department/computing_science_rooms.csv",
        "Business": f"{base_dir}/department/business_rooms.csv",
        "Education": f"{base_dir}/department/education_rooms.csv",
        "DevelopmentStudies": f"{base_dir}/department/development_studies_rooms.csv",
        "BiomedicalEngineering": f"{base_dir}/department/biomedical_engineering_rooms.csv",
        "Nursing": f"{base_dir}/department/nursing_rooms.csv",
        "Theology": f"{base_dir}/department/theology_rooms.csv",
        "General": f"{base_dir}/general/rooms.csv"
    }
    return room_file_map.get(department, f"{base_dir}/general/rooms.csv")

def guess_semester(course_code: str, title: str) -> str:
    """Guesses if a course belongs to Semester 1 or 2 based on its code/title."""
    code = str(course_code).upper()
    title_upper = str(title).upper()
    
    # 1. Check for explicit "I" or "II" or "1" or "2" in title
    if any(x in title_upper for x in [" II", " 2", "PART 2", "SKILLS II"]):
        return "2"
    if any(x in title_upper for x in [" I", " 1", "PART 1", "SKILLS I"]):
        return "1"
    
    # 2. Check course code digits (heuristic: odd level-digits sometimes mean Sem 1, even Sem 2?? Not reliable)
    # Let's use a more robust hash-based alternation if we can't tell, to balance load.
    # But first, specific VVU patterns if known.
    
    # Default: Alternating based on code to spread load if we have no clue
    return "1" if hash(code) % 2 == 0 else "2"

def load_level_data() -> Dict[str, int]:

    mapping = {}
    for level in [100, 200,300,400]:
        file_path = f"level_{level}.csv"
        if os.path.exists(file_path):
            df = pd.read_csv(file_path)
            for code in df['course_code'].unique():
                mapping[str(code).strip().upper()] = level // 100
    return mapping

def load_combined_data(paths: List[str],
                       availability_path: str = "csv/general/lecturer_availability.csv",
                       special_rooms_path: str = "csv/general/special_rooms.csv",
                       rooms_csv_path: str = "csv/general/rooms.csv",
                       curriculum_path: str = "csv/general/curriculum.csv",
                       shared_courses_path: str = "csv/general/shared_courses.csv",
                       shared_aliases_path: str = "csv/general/shared_course_aliases.csv",
                       override_course_type: str = None,
                       interactive: bool = True) :
    from analyzer import TIME_TO_SLOT
    
    # B2 Integration: Download files to temp directory
    try:
        from b2_handler import B2Handler
        b2 = B2Handler()
        if b2.s3:
            print("[INFO] B2 enabled. Downloading CSVs from cloud...")
            temp_dir = "temp"
            os.makedirs(temp_dir, exist_ok=True)

            # Ensure we don't accidentally use stale CSVs from previous runs.
            temp_csv_dir = os.path.join(temp_dir, "csv")
            if os.path.exists(temp_csv_dir):
                shutil.rmtree(temp_csv_dir, ignore_errors=True)
            
            # Download specific files we know we need
            # We could download the whole 'csv' folder
            b2.download_folder("csv/", temp_dir)
            
            # Also download history and feedback if they exist (try both locations)
            # We enforce saving to csv/general for history in B2 moving forward
            b2.download_file("csv/general/historical_schedule.csv", os.path.join(temp_dir, "historical_schedule.csv"))
            b2.download_file("user_feedback.csv", os.path.join(temp_dir, "user_feedback.csv"))
            
            # Update paths to point to temp/csv/...
            # The structure in B2 is csv/general/..., so in temp it will be temp/csv/general/...
            
            base_temp = os.path.join(temp_dir, "csv")
            
            # Helper to permit local override if B2 download failed or we want to use local dev files? 
            # No, user wants "no more local saving", so B2 is the source of truth.
            
            def update_path(p):
                # If p starts with csv/, remap to temp/csv/
                # If p is just a filename (e.g. from tests), we might need to handle it.
                if p.startswith("csv/"):
                     return os.path.join(temp_dir, p)
                return p 

            def _to_b2_temp_path(p: str) -> str:
                """
                Force support CSV paths to use the freshly downloaded B2 mirror.
                Accepts both csv/... and temp/csv/... inputs.
                """
                if not isinstance(p, str) or not p:
                    return p
                normalized = p.replace("\\", "/")
                if normalized.startswith("temp/csv/"):
                    normalized = normalized[len("temp/"):]
                if normalized.startswith("csv/"):
                    return os.path.join(temp_dir, normalized)
                return p

            # FORCE support files to B2 source-of-truth (always use latest cloud updates)
            availability_path = _to_b2_temp_path(availability_path)
            special_rooms_path = _to_b2_temp_path(special_rooms_path)
            rooms_csv_path = _to_b2_temp_path(rooms_csv_path)
            curriculum_path = _to_b2_temp_path(curriculum_path)
            shared_courses_path = _to_b2_temp_path(shared_courses_path)
            shared_aliases_path = _to_b2_temp_path(shared_aliases_path)
                 
            # Also update the input paths list
            new_paths = []
            for p in paths:
                # If the caller generated a local temp file under csv/, prefer that local file.
                # Otherwise use the downloaded B2 mirror if present.
                if p.startswith("csv/"):
                    b2_mirrored_path = os.path.join(temp_dir, p)
                    if os.path.exists(p):
                        new_paths.append(p)
                    elif os.path.exists(b2_mirrored_path):
                        new_paths.append(b2_mirrored_path)
                    else:
                        new_paths.append(p)
                else:
                    # It might be a root file like 'courses_input.csv'
                    # Try downloading it if not exists locally?
                    # For now just append
                    new_paths.append(p)
            paths = new_paths

            print(f"[INFO] B2 CSV paths in use: rooms={rooms_csv_path}, special={special_rooms_path}, availability={availability_path}")
            
            print("[INFO] B2 Sync complete. Using temp files.")

    except Exception as e:
        print(f"[WARNING] B2 Sync failed: {e}. Falling back to local files.")


    
    dfs = []
    for path in paths:
        if os.path.exists(path):
            dfs.append(pd.read_csv(path))
    if not dfs:
        raise FileNotFoundError("No valid data files found.")
    combined_df = pd.concat(dfs, ignore_index=True)
    combined_df.columns = [c.strip() for c in combined_df.columns]
    from validators import validate_required_columns
    required_cols = ["course_code", "lecturer_name"]
    issues = validate_required_columns(combined_df, required_cols, "input course CSV")
    if issues:
        raise ValueError("; ".join(issues))
    combined_df = combined_df.dropna(subset=['course_code', 'lecturer_name'])
    
    level_map = load_level_data()
    
    # Load shared-course aliases (canonical_code, alias_code)
    alias_to_canonical = {}
    if os.path.exists(shared_aliases_path):
        alias_df = pd.read_csv(shared_aliases_path)
        alias_df.columns = [c.strip().lower() for c in alias_df.columns]
        if 'canonical_code' in alias_df.columns and 'alias_code' in alias_df.columns:
            for _, row in alias_df.iterrows():
                canonical = str(row['canonical_code']).strip().upper()
                alias = str(row['alias_code']).strip().upper()
                if canonical and alias:
                    alias_to_canonical[alias] = canonical

    def normalize_course_code(code: str) -> str:
        if not isinstance(code, str):
            return ""
        cleaned = " ".join(code.strip().upper().split())
        return alias_to_canonical.get(cleaned, cleaned)

    #Load curriculum data and identify cohorts
    course_cohorts = {}
    all_programs = set()
    if os.path.exists(curriculum_path):
        curriculum_df = pd.read_csv(curriculum_path)
        for _, row in curriculum_df.iterrows():
            course_code = normalize_course_code(str(row['course_code']))
            program = str(row['program']).strip()
            level = str(row['level']).strip()
            cohort_id = f"{program}_{level}"
            all_programs.add(program)
            if course_code not in course_cohorts:
                course_cohorts[course_code] = set()
            course_cohorts[course_code].add(cohort_id)

    # Build normalized program lookup for shared-course mapping
    program_by_norm = {}
    for program in all_programs:
        program_by_norm[normalize_program_label(program)] = program

    alias_map = {
        "cs": ["computer science", "comp sci", "cs"],
        "it": ["information technology", "info tech", "it"],
        "bis": ["business information system", "bis"],
        "business": ["business", "business admin", "business administration"],
    }

    def resolve_programs_from_labels(labels: List[str]) -> List[str]:
        resolved = set()
        norm_programs = list(program_by_norm.keys())
        for label in labels:
            norm_label = normalize_program_label(label)
            if norm_label in program_by_norm:
                resolved.add(program_by_norm[norm_label])
                continue

            matched = False
            for alias_key, alias_terms in alias_map.items():
                if norm_label == alias_key or any(norm_label == term for term in alias_terms):
                    for prog_norm in norm_programs:
                        if any(term in prog_norm for term in alias_terms):
                            resolved.add(program_by_norm[prog_norm])
                            matched = True
                    break

            if matched:
                continue

            # Fallback: substring match against program names
            for prog_norm in norm_programs:
                if norm_label and norm_label in prog_norm:
                    resolved.add(program_by_norm[prog_norm])

        return list(resolved)

    # Load shared (cross-department) courses
    shared_course_map = {}
    if os.path.exists(shared_courses_path) and os.path.getsize(shared_courses_path) > 0:
        try:
            shared_df = pd.read_csv(shared_courses_path)
            shared_df.columns = [c.strip().lower() for c in shared_df.columns]
            for _, row in shared_df.iterrows():
                course_code = normalize_course_code(str(row.get('course_code', '')))
                if not course_code:
                    continue

                raw_departments = str(row.get('department', row.get('departments', ''))).strip()
                dept_labels = split_department_labels(raw_departments)
                programs = resolve_programs_from_labels(dept_labels)

                # Optional: shared course-specific level/semester overrides
                shared_level = row.get('course_level', None)
                shared_semester = row.get('semester', None)

                if isinstance(shared_level, str) and shared_level.strip():
                    try:
                        shared_level = int(float(shared_level))
                    except Exception:
                        shared_level = None
                elif isinstance(shared_level, (int, float)):
                    shared_level = int(shared_level)
                else:
                    shared_level = None

                if isinstance(shared_semester, str):
                    shared_semester = shared_semester.strip()
                    if not shared_semester or shared_semester.lower() == 'nan':
                        shared_semester = None
                elif shared_semester is None:
                    shared_semester = None
                else:
                    shared_semester = str(shared_semester).strip()

                shared_course_map[course_code] = {
                    "programs": programs,
                    "level": shared_level,
                    "semester": shared_semester,
                    "raw_departments": raw_departments
                }
        except Exception as e:
            print(f"[WARNING] Error reading shared_courses.csv: {e}")
            shared_course_map = {}
    # Load Configuration (needed for slot counts)
    config = {
        "days": ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday"],
        "slots_per_day": 4,
        "strict_capacity": False
    }

    if os.path.exists("config.json"):
        import json
        try:
            with open("config.json", "r") as f:
                loaded_conf = json.load(f)
                config.update(loaded_conf)
        except Exception as e:
            print(f"[WARNING] Could not load config.json: {e}")

    slots_per_day = int(config.get("slots_per_day", 4))

    #Build lecturers with interactive availability management
    from manage_availability import check_and_prompt_availability
    
    # Get unique lecturer names from input
    unique_lecturers = combined_df['lecturer_name'].unique()
    lecturer_names_list = [str(name).strip() for name in unique_lecturers]
    
    # Check availability and prompt if needed
    lecturer_availability_map = {}
    if interactive:
        print("\n[INFO] Checking lecturer availability...")
        lecturer_availability_map = check_and_prompt_availability(
            lecturers_in_input=lecturer_names_list,
            availability_file=availability_path,
            min_days_threshold=3  # Prompt if lecturer has < 3 days available
        )
    else:
        # In non-interactive mode, load existing availability without prompting
        if os.path.exists(availability_path):
            try:
                avail_df = pd.read_csv(availability_path)
                avail_df.columns = [c.strip() for c in avail_df.columns]
                day_cols = ["Mon", "Tue", "Wed", "Thu", "Fri"]
                for _, row in avail_df.iterrows():
                    name = str(row.get("lecturer_name", "")).strip()
                    if not name:
                        continue
                    available_days = []
                    for i, day in enumerate(day_cols):
                        raw_val = row.get(day, 1)
                        try:
                            val = int(raw_val) if pd.notna(raw_val) else 1
                        except Exception:
                            val = 1
                        if val == 1:
                            available_days.append(i)
                    lecturer_availability_map[name] = available_days
            except Exception as e:
                print(f"[WARNING] Could not parse lecturer availability CSV: {e}")
    
    lecturers: Dict[str, Lecturer] = {}
    for name in combined_df['lecturer_name'].unique():
        name = str(name).strip()
        norm_name = normalize_name(name)
        
        # Get availability from the map (already prompted if needed)
        available_days = lecturer_availability_map.get(name, list(range(5)))
        available_time_slots = [(d, s) for d in available_days for s in range(slots_per_day)]
        lecturers[name.replace(" ", "_")] = Lecturer(id=name.replace(" ", "_"), name=name, available_time_slots=available_time_slots)

        
    #Build rooms with department affiliation
    room_db = {}
    room_dept_map = {}  # room_name -> department
    room_type_map = {}  # room_name -> room_type
    
    # Determine if we're using a department-specific room pool
    is_dept_specific = not rooms_csv_path.endswith("general/rooms.csv")
    
    # Step 1: Load rooms from the specified rooms_csv_path
    if os.path.exists(rooms_csv_path):
        rooms_df = pd.read_csv(rooms_csv_path)
        for _, row in rooms_df.iterrows():
            rname = str(row['room_name']).strip()
            cap = int(row.get('capacity', 30))
            dept = str(row.get('department', 'General')).strip()
            rtype = str(row.get('room_type', 'lecture')).strip()
            
            room_db[rname] = cap
            room_dept_map[rname] = dept
            room_type_map[rname] = rtype
    
    # Step 2: Detect input departments
    input_departments = set()
    for _, row in combined_df.iterrows():
        course_code = str(row['course_code']).strip().upper()
        dept = get_department_group(course_code)
        if dept != "General":
            input_departments.add(dept)
    
    # Step 3: Load department-specific room files (ONLY if using general rooms.csv)
    # If rooms_csv_path is already a department-specific file, skip this step
    # to avoid loading additional rooms beyond what was explicitly specified
    if not is_dept_specific:
        for dept in input_departments:
            dept_room_file = get_department_room_file(dept)
            if not dept_room_file.endswith("/rooms.csv") and os.path.exists(dept_room_file):
                try:
                    dept_rooms_df = pd.read_csv(dept_room_file)
                    for _, row in dept_rooms_df.iterrows():
                        rname = str(row['room_name']).strip()
                        cap = int(row.get('capacity', 30))
                        # Department-specific rooms are tagged with their department
                        dept_assigned = dept if not rname.startswith(('American', 'Baobab', 'Bulley', 'MET', 'CC')) else 'General'
                        
                        # Only add if not already in room_db (avoid duplicates)
                        if rname not in room_db:
                            room_db[rname] = cap
                            room_dept_map[rname] = dept_assigned
                            room_type_map[rname] = str(row.get('room_type', 'lecture')).strip()
                except Exception as e:
                    print(f"[WARNING] Error loading {dept_room_file}: {e}")
    else:
        print(f"[INFO] Using department-specific room pool. Skipping auto-load of additional department rooms.")
        
    rooms : Dict[str, Room] = {}
    
    # Logic: If using the general 'rooms.csv', we can be flexible.
    # If using a specific departmental pool, ONLY use rooms from that file to avoid leakage.
    is_custom_pool = not rooms_csv_path.endswith("general/rooms.csv")
    
    if is_custom_pool:
        all_rooms = list(room_db.keys())
    else:
        input_rooms = set(combined_df['room_name'].dropna().unique()) if 'room_name' in combined_df.columns else set()
        all_rooms = list(input_rooms | set(room_db.keys()))

    for room_name in all_rooms:
        room_id = room_name.replace(" ", "_")
        capacity = int(room_db.get(room_name, 30))  # Default capacity
        dept = room_dept_map.get(room_name, "General")
        rtype = room_type_map.get(room_name, "lecture")
        rooms[room_id] = Room(id=room_id, name=room_name, capacity=capacity, 
                     room_type=rtype, department=dept,
                     available_time_slots=[(d, s) for d in range(5) for s in range(slots_per_day)])
        
    #Build Sections
    courses : Dict[str, Course] = {}
    sections : List[ClassSection] = []
    seen = set()
    for idx, row in combined_df.iterrows():
        course_code = str(row['course_code']).strip().upper()
        canonical_course_code = normalize_course_code(course_code)
        lecturer_name = str(row['lecturer_name']).strip()
        
        # Use user override if provided, otherwise check CSV, otherwise default to Departmental
        if override_course_type:
            section_type = override_course_type
        else:
            section_type = str(row.get("source_type", "Departmental")).strip()
            
        level = level_map.get(course_code, int(row.get('course_level', 0))) 
        
        if course_code not in courses:
            # Map credit hours to lessons
            cred_str = str(row.get('credit_hours', 'NC')).strip().upper()
            if cred_str == 'NC':
                lessons = 1
            else:
                try:
                    lessons = int(float(cred_str))
                except:
                    lessons = 2 # Default fallback
            
            courses[course_code] = Course(code=course_code, title=str(row.get('course_title', '')).strip(),
                                          credit_hours=cred_str,
                                          required_room_type="lecture",
                                          required_lessons=lessons)



        #smart locking for general courses
        fixed_day, fixed_slot = None, None
        if section_type == "General" and "day" in combined_df.columns and "start_time" in combined_df.columns:
            day = str(row.get("day", "")).strip()
            start_time = str(row.get("start_time", str(row.get("time", "")))).split("-")[0].strip().lower()
            if day in day_to_index and start_time in TIME_TO_SLOT:
                fixed_day = day_to_index[day]
                fixed_slot = TIME_TO_SLOT[start_time]

        #Build cohorts: General courses belong to all programs of that level
        cohorts = course_cohorts.get(canonical_course_code, set())
        
        # Semester parsing
        semester = str(row.get('Semester', '')).strip()
        if not semester or semester.lower() == 'nan':
             # Try to guess semester to avoid capacity bottleneck
             semester = guess_semester(course_code, str(row.get("course_title", "")))

        if section_type == "General":
            base_cohort = f"General_{level*100}_Sem{semester}"
            cohorts.add(base_cohort)
            
            # Also add for specific programs if needed
            for program in all_programs:
                prog_cohort = f"{program}_{level*100}_Sem{semester}"
                cohorts.add(prog_cohort)

        # Shared (cross-department) course cohorts
        if canonical_course_code in shared_course_map:
            shared_info = shared_course_map[canonical_course_code]
            shared_level = shared_info.get("level")
            shared_semester = shared_info.get("semester")

            # Use shared overrides if provided; otherwise use course-level inference
            if shared_level is None:
                shared_level = level * 100
            if shared_semester is None:
                shared_semester = semester

            for program in shared_info.get("programs", []):
                shared_cohort = f"{program}_{shared_level}_Sem{shared_semester}"
                cohorts.add(shared_cohort)

                
        # DEPT COURSE: Check if it conflicts with GENERAL schedule
        # If 'blocked_slots' is passed (from General Schedule CSV), we add constraints
        # Logic: If this is a Dept course for Level 100 Sem 1, it must not clash with General Level 100 Sem 1
        
        # (This logic is usually handled in the solver constraints, so we just pass cohorts here)
        
        # Determine owning department (for room allocation purposes)
        dept_group = get_department_group(course_code)
        owning_dept = dept_group  # Directly use the department group
        
        # Get credit hours from curriculum or default to "3"
        credit_hours = courses.get(course_code, Course(course_code, "", "3", "", 0)).credit_hours if course_code in courses else "3"
        
        sec_id = f"{course_code}_{idx}"
        sections.append(ClassSection(
            id=sec_id,
            course_code=course_code,
            lecturer_id=lecturer_name.replace(" ", "_"),
            section_title=str(row.get("course_title", course_code)).strip(),
            course_type=section_type,
            course_level=str(level*100),
            enrollment=int(row.get('enrollment', 30)),
            cohorts=cohorts,
            fixed_day=fixed_day,
            semester=semester,
            departmental_group=dept_group,
            owning_department=owning_dept,
            credit_hours=str(credit_hours).strip()
        ))
    
    # Load special rooms configuration for pre-assigned courses
    special_rooms = {}
    sr_df = None
    if os.path.exists(special_rooms_path):
        try:
            sr_df = pd.read_csv(special_rooms_path)
        except Exception as e:
            print(f"[WARNING] Could not load special_rooms.csv: {e}")
            sr_df = None
            
    if sr_df is not None and 'course_code' in sr_df.columns and 'room_name' in sr_df.columns:
        for _, row in sr_df.iterrows():
            c_code = str(row['course_code']).strip().upper()
            r_name = str(row['room_name']).strip()
            
            # Parse fixed day if specified
            fixed_day_idx = None
            if 'fixed_day' in sr_df.columns:
                day_str = str(row['fixed_day']).strip()
                if day_str and day_str != 'nan':
                    fixed_day_idx = day_to_index.get(day_str)
            
            # Parse fixed time if specified
            slot_idx = None
            if 'fixed_time' in sr_df.columns:
                t_str = str(row['fixed_time']).strip().lower()
                if t_str and t_str != 'nan':
                    slot_idx = TIME_TO_SLOT.get(t_str)
            
            # Store as dict with metadata
            special_rooms[c_code] = {
                "room": r_name,
                "day": fixed_day_idx,  # None if not specified
                "slot": slot_idx        # None if not specified
            }

    # Ensure every special-room target exists in rooms pool.
    # This is critical when using department-specific room files that may omit a global/special room.
    if special_rooms:
        # Build auxiliary capacity map from global rooms if available
        global_rooms_candidates = [
            os.path.join("temp", "csv", "general", "rooms.csv"),
            "csv/general/rooms.csv",
            "rooms.csv"
        ]
        global_capacity_map = {}
        for candidate in global_rooms_candidates:
            if os.path.exists(candidate):
                try:
                    gdf = pd.read_csv(candidate)
                    for _, grow in gdf.iterrows():
                        gname = str(grow.get('room_name', '')).strip()
                        if not gname:
                            continue
                        try:
                            gcap = int(grow.get('capacity', 30))
                        except Exception:
                            gcap = 30
                        global_capacity_map[gname] = gcap
                except Exception:
                    pass

        for info in special_rooms.values():
            target_name = info.get("room", "") if isinstance(info, dict) else str(info)
            target_name = str(target_name).strip()
            if not target_name:
                continue

            target_id = target_name.replace(" ", "_")
            if target_id not in rooms:
                cap = global_capacity_map.get(target_name, room_db.get(target_name, 30))
                rooms[target_id] = Room(
                    id=target_id,
                    name=target_name,
                    capacity=int(cap),
                    room_type=room_type_map.get(target_name, "lecture"),
                    department=room_dept_map.get(target_name, "General"),
                    available_time_slots=[(d, s) for d in range(5) for s in range(slots_per_day)]
                )

    # Build shared course groups (sections that should align across departments)
    from shared_courses import build_shared_course_groups, apply_shared_group_ids
    shared_group_map = build_shared_course_groups(sections, curriculum_path, shared_aliases_path)
    apply_shared_group_ids(sections, shared_group_map)

    return {
        "sections": sections,
        "lecturers": lecturers,
        "rooms": rooms,
        "course_cohorts": course_cohorts,
        "courses": courses,
        "special_rooms": special_rooms,
        "config": config
    }

def load_general_schedule_blocks(general_csv_path: str, semester: str = None) -> List[dict]:
    """
    Parses a General Schedule CSV to identify BUSY slots for cohorts.
    Returns a list of blocked time slots:
    [{'sem': '1', 'level': '100', 'day': 0, 'slot': (800, 1000)}, ...]
    """
    blocks = []
    if not os.path.exists(general_csv_path):
        return blocks
        
    from analyzer import TIME_TO_SLOT
    day_to_index = {"Monday": 0, "Tuesday": 1, "Wednesday": 2, "Thursday": 3, "Friday": 4, 
                    "Mon": 0, "Tue": 1, "Wed": 2, "Thu": 3, "Fri": 4}
    import pandas as pd
    
    try:
        df = pd.read_csv(general_csv_path)
        # Normalize column names to lowercase for case-insensitive matching
        df.columns = df.columns.str.lower().str.strip()
        
        # Ensure necessary columns
        req_cols = ['course_level', 'day', 'start_time']
        if not all(c in df.columns for c in req_cols):
            missing = [c for c in req_cols if c not in df.columns]
            print(f"[WARNING] General schedule missing columns: {', '.join(missing)}")
            return blocks
            
        for _, row in df.iterrows():
            try:
                level = str(int(float(row['course_level']))) if row['course_level'] and str(row['course_level']) != 'nan' else '100'
                sem = str(row.get('semester', '')).strip()
                if not sem or sem.lower() == 'nan': sem = None 
                if semester and sem and sem != semester:
                    continue
                
                day_str = str(row['day']).strip()
                time_str = str(row['start_time']).split('-')[0].strip().lower()
                
                if day_str in day_to_index and time_str in TIME_TO_SLOT:
                    day_idx = day_to_index[day_str]
                    slot_obj = TIME_TO_SLOT[time_str] # (start, end)
                    
                    blocks.append({
                        'level': level,
                        'semester': sem,
                        'day': day_idx,
                        'slot': slot_obj
                    })
            except Exception:
                continue
    except Exception as e:
        print(f"[WARNING] Could not load general schedule: {e}")
            
    return blocks
