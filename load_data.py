import pandas as pd
import os
import shutil
import re
from typing import  Dict, List
from data_model import Lecturer, Room, Course, ClassSection

# Global alias map (will be populated if available)
_alias_to_canonical = {}

def normalize_course_code(code: str) -> str:
    if not isinstance(code, str):
        return ""
    # 1. Basic cleaning
    cleaned = " ".join(code.strip().upper().split())
    # 2. Strip potential suffix like "[SEC A]" or ": Elements..."
    base = cleaned.split("[SEC")[0].split(":")[0].strip()
    
    # 3. Aggressive normalization: Extract core code (e.g. "COSC 124" from "COSC 124 Procedural Programming")
    match = re.match(r'^([A-Z]{2,4}\s*\d{2,4})', base)
    if match:
         extracted = match.group(1)
         letters = "".join(re.findall(r'[A-Z]', extracted))
         numbers = "".join(re.findall(r'\d', extracted))
         base = f"{letters} {numbers}"
    
    # Apply alias mapping
    return _alias_to_canonical.get(base, base)

def normalize_level_token(raw_level) -> str:
    text = str(raw_level or '').strip()
    if not text or text.lower() == 'nan':
        return ''
    try:
        value = int(float(text))
        if 0 < value < 10:
            value *= 100
        return str(value)
    except Exception:
        return text

def normalize_semester_token(raw_semester) -> str:
    text = str(raw_semester or '').strip()
    if not text or text.lower() == 'nan':
        return ''
    return text

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
    if any(prefix in code for prefix in ["BIOM", "ENGR", "BENG", "HLTC", "BMET"]):
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

    department_raw = str(department or "General").strip()
    department_key = department_raw.lower().replace(" ", "").replace("_", "").replace("-", "")

    alias_map = {
        "general": "General",
        "cs/it/bbis": "CS/IT/BBIS",
        "csitbbis": "CS/IT/BBIS",
        "computingscience": "CS/IT/BBIS",
        "Computer Science": "CS/IT/BBIS",
        "Computing Science": "CS/IT/BBIS",
        "business": "Business",
        "education": "Education",
        "developmentstudies": "DevelopmentStudies",
        "biomedicalengineering": "BiomedicalEngineering",
        "nursing": "Nursing",
        "theology": "Theology",
    }
    canonical_department = alias_map.get(department_key, department_raw)

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
    return room_file_map.get(canonical_department, f"{base_dir}/general/rooms.csv")

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
        candidates = [
            os.path.join("temp", "csv", "general", f"level_{level}.csv"),
            os.path.join("csv", "general", f"level_{level}.csv"),
            f"level_{level}.csv"
        ]
        for file_path in candidates:
            if os.path.exists(file_path):
                df = pd.read_csv(file_path)
                for code in df['course_code'].unique():
                    mapping[str(code).strip().upper()] = level // 100
                break
    return mapping

def load_combined_data(paths: List[str],
                       availability_path: str = "csv/general/lecturer_availability.csv",
                       special_rooms_path: str = "csv/general/special_rooms.csv",
                       rooms_csv_path: str = "csv/general/rooms.csv",
                       curriculum_path: str = "csv/general/curriculum.csv",
                       shared_courses_path: str = "csv/general/shared_courses.csv",
                       shared_aliases_path: str = "csv/general/shared_course_aliases.csv",
                       override_course_type: str = None,
                       interactive: bool = True,
                       blocked_blocks: List[dict] = None,
                       availability_mode: str = "1") :
    from analyzer import TIME_TO_SLOT
    
    # B2 Integration: Download files to temp directory with caching
    try:
        from b2_handler import B2Handler
        # Enable caching to avoid re-downloading unchanged files
        b2 = B2Handler(enable_cache=True, cache_dir="temp/b2_cache")
        if b2.s3:
            print("[INFO] B2 enabled with caching. Checking for updated files...")
            temp_dir = "temp"
            os.makedirs(temp_dir, exist_ok=True)
            minimal_b2_sync = os.getenv("SCHEDULER_B2_MINIMAL", "0") == "1"
            force_b2_refresh = os.getenv("SCHEDULER_B2_FORCE_REFRESH", "0") == "1"

            # Download only the exact input files needed by the current solve.
            if force_b2_refresh:
                print("[B2] Force-refreshing required input CSVs from B2 (ignore cache)...")
            else:
                print("[B2] Syncing required input CSVs from B2 (cache-first)...")

            required_b2_keys = set()
            department_csv_basenames = {
                "departmental_courses.csv",
                "computing_science_rooms.csv",
                "nursing_rooms.csv",
                "theology_rooms.csv",
                "business_rooms.csv",
                "education_rooms.csv",
                "biomedical_engineering_rooms.csv",
                "development_studies_rooms.csv",
            }
            general_csv_basenames = {
                "rooms.csv",
                "lecturer_availability.csv",
                "special_rooms.csv",
                "curriculum.csv",
                "shared_courses.csv",
                "shared_course_aliases.csv",
                "historical_schedule.csv",
                "level_100.csv",
                "level_200.csv",
                "level_300.csv",
                "level_400.csv",
                "vvu_general_schedule.csv",
                "exam_rooms.csv",
            }

            def _normalize_support_csv_key(path_value: str):
                if not isinstance(path_value, str) or not path_value.strip():
                    return path_value

                normalized = path_value.strip().replace("\\", "/")
                if normalized.startswith("temp/csv/"):
                    normalized = normalized[len("temp/"):]
                if normalized.startswith("csv/"):
                    return normalized

                base_name = os.path.basename(normalized)
                if base_name in department_csv_basenames:
                    return f"csv/department/{base_name}"
                if base_name in general_csv_basenames:
                    return f"csv/general/{base_name}"

                return normalized

            def _maybe_add_b2_key(path_value: str):
                if not isinstance(path_value, str) or not path_value.strip():
                    return

                normalized = _normalize_support_csv_key(path_value)
                if isinstance(normalized, str) and normalized.startswith("csv/"):
                    required_b2_keys.add(normalized)

            for path_value in paths:
                _maybe_add_b2_key(path_value)

            _maybe_add_b2_key(availability_path)
            _maybe_add_b2_key(special_rooms_path)
            _maybe_add_b2_key(rooms_csv_path)
            _maybe_add_b2_key(curriculum_path)
            _maybe_add_b2_key(shared_courses_path)
            _maybe_add_b2_key(shared_aliases_path)

            # Level maps are used as a fallback when input rows do not contain course_level.
            for level in [100, 200, 300, 400]:
                required_b2_keys.add(f"csv/general/level_{level}.csv")

            if not minimal_b2_sync:
                required_b2_keys.add("csv/general/historical_schedule.csv")

            for b2_key in sorted(required_b2_keys):
                local_target = os.path.join(temp_dir, b2_key)
                b2.download_file(b2_key, local_target, force=force_b2_refresh)
            
            # Skip csv/final/ - that's output only, no need to download
            print("[B2] Skipping csv/final/ (output folder, not needed as input)")
            
            # Optional artifacts for learning/analytics (not required for solving)
            if not minimal_b2_sync:
                b2.download_file("user_feedback.csv", os.path.join(temp_dir, "user_feedback.csv"), force=force_b2_refresh)
            
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
                normalized = _normalize_support_csv_key(p)
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

    # Load shared-course aliases (canonical_code, alias_code)
    global _alias_to_canonical
    _alias_to_canonical = {}
    alias_to_canonical = _alias_to_canonical
    if os.path.exists(shared_aliases_path):
        try:
            alias_df = pd.read_csv(shared_aliases_path)
            alias_df.columns = [c.strip().lower() for c in alias_df.columns]
            if 'canonical_code' in alias_df.columns and 'alias_code' in alias_df.columns:
                for _, row in alias_df.iterrows():
                    canonical = str(row['canonical_code']).strip().upper()
                    alias = str(row['alias_code']).strip().upper()
                    if canonical and alias:
                        alias_to_canonical[alias] = canonical
        except Exception as e:
            print(f"[WARNING] Could not load aliases: {e}")

    # (Normalization functions moved to global scope)

    # --- Step 1: Load Input Data ---
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

    # --- Step 1b: Load Configuration and Curriculum (Needed for subsequent steps) ---
    config = {"days": ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday"], "slots_per_day": 4, "strict_capacity": False}
    if os.path.exists("config.json"):
        import json
        try:
            with open("config.json", "r") as f:
                config.update(json.load(f))
        except: pass
    slots_per_day = int(config.get("slots_per_day", 4))
    level_map = load_level_data()

    course_cohorts = {}
    all_programs = set()
    if os.path.exists(curriculum_path):
        try:
            curriculum_df = pd.read_csv(curriculum_path)
            for _, row in curriculum_df.iterrows():
                course_code = normalize_course_code(str(row['course_code']))
                program, level, sem = str(row['program']).strip(), str(row['level']).strip(), str(row.get('semester', '')).strip()
                cohort_id = f"{program}_{level}_Sem{sem}" if sem else f"{program}_{level}"
                all_programs.add(program)
                if course_code not in course_cohorts: course_cohorts[course_code] = set()
                course_cohorts[course_code].add(cohort_id)
        except: pass

    # --- Step 2: Load Room Pool (Moved Up) ---
    room_db = {}
    room_dept_map = {}
    room_type_map = {}
    is_dept_specific = not rooms_csv_path.endswith("general/rooms.csv")
    inferred_dept = "General"
    if is_dept_specific:
        fname = os.path.basename(rooms_csv_path).lower()
        # Explicit mapping of filename patterns to department names
        dept_file_map = {
            "computing_science_rooms": "CS/IT/BBIS",
            "business_rooms": "Business",
            "education_rooms": "Education",
            "development_studies_rooms": "DevelopmentStudies",
            "biomedical_engineering_rooms": "BiomedicalEngineering",
            "nursing_rooms": "Nursing",
            "theology_rooms": "Theology"
        }
        for pattern, dept_name in dept_file_map.items():
            if pattern in fname:
                inferred_dept = dept_name
                break
    
    if os.path.exists(rooms_csv_path):
        rooms_df = pd.read_csv(rooms_csv_path)
        for _, row in rooms_df.iterrows():
            rname = str(row['room_name']).strip()
            csv_dept = str(row.get('department', 'General')).strip()
            dept = inferred_dept if (csv_dept == "General" and inferred_dept != "General") else csv_dept
            room_db[rname] = int(row.get('capacity', 30))
            room_dept_map[rname] = dept
            room_type_map[rname] = str(row.get('room_type', 'lecture')).strip()
            
    rooms : Dict[str, Room] = {}
    for room_name in list(room_db.keys()):
        room_id = room_name.replace(" ", "_")
        rooms[room_id] = Room(id=room_id, name=room_name, capacity=room_db[room_name], 
                     room_type=room_type_map.get(room_name, "lecture"), 
                     department=room_dept_map.get(room_name, "General"),
                     available_time_slots=[(d, s) for d in range(5) for s in range(slots_per_day)])

    # --- Step 3: Load Lecturers (Moved Up) ---
    from manage_availability import check_and_prompt_availability
    unique_lecturers = combined_df['lecturer_name'].unique()
    lecturer_names_list = [str(name).strip() for name in unique_lecturers]
    
    lecturer_availability_map = {}
    if interactive:
        lecturer_availability_map = check_and_prompt_availability(lecturers_in_input=lecturer_names_list, availability_file=availability_path, min_days_threshold=3)
    else:
        if os.path.exists(availability_path):
            try:
                avail_df = pd.read_csv(availability_path)
                day_cols = ["Mon", "Tue", "Wed", "Thu", "Fri"]
                for _, row in avail_df.iterrows():
                    name = str(row.get("lecturer_name", "")).strip()
                    if not name: continue
                    available_days = [i for i, day in enumerate(day_cols) if int(row.get(day, 1)) == 1]
                    lecturer_availability_map[name] = available_days
            except: pass
    
    lecturers: Dict[str, Lecturer] = {}
    for name in lecturer_names_list:
        available_days = lecturer_availability_map.get(name, list(range(5)))
        if not interactive and availability_mode == "1" and len(available_days) < 3:
            available_days = [0, 1, 2, 3, 4]
        available_time_slots = [(d, s) for d in available_days for s in range(slots_per_day)]
        lecturers[name.replace(" ", "_")] = Lecturer(id=name.replace(" ", "_"), name=name, available_time_slots=available_time_slots)

    # --- Step 4: Load Special Rooms ---
    active_course_codes = {
        normalize_course_code(str(code).strip().upper())
        for code in combined_df['course_code'].dropna().astype(str).tolist()
    }
    special_rooms = {}
    if os.path.exists(special_rooms_path):
        try:
            sr_df = pd.read_csv(special_rooms_path)
            for _, row in sr_df.iterrows():
                c_code, r_name = str(row['course_code']).strip().upper(), str(row['room_name']).strip()
                canonical_special_code = normalize_course_code(c_code)
                if canonical_special_code not in active_course_codes:
                    # Ignore special-room records for courses not being solved in this session.
                    continue

                r_id = r_name.replace(" ", "_")
                if r_id not in rooms:
                    # Never inject out-of-scope rooms into the active pool.
                    print(f"[INFO] Skipping out-of-pool special room '{r_name}' for {c_code}")
                    continue

                special_rooms[c_code] = {
                    "room": r_name,
                    "day": day_to_index.get(str(row.get('fixed_day', '')).strip()),
                    "slot": TIME_TO_SLOT.get(str(row.get('fixed_time', '')).strip().lower())
                }
        except Exception as e:
            print(f"[WARNING] Failed to load special rooms: {e}")
    else:
        print(f"[DEBUG] Special rooms file not found at {special_rooms_path}")


    # --- Step 5: Load Block-based Smart Locks (Inject Foreign Data) ---
    course_to_fixed_exact = {}
    course_to_fixed_code_only = {}
    if blocked_blocks:
        for block in blocked_blocks:
            code = normalize_course_code(block.get('course_code', ''))
            if code:
                # 1. Inject Foreign Room if missing
                r_name = block.get('room_name')
                # Do not inject foreign rooms into the global pool.
                pass
                
                # 2. Inject Foreign Lecturer if missing
                l_name = block.get('lecturer_name')
                if l_name:
                    l_id = l_name.replace(" ", "_")
                    if l_id not in lecturers:
                        print(f"[INFO] Injecting Foreign Block Lecturer {l_name} for {code}")
                        lecturers[l_id] = Lecturer(id=l_id, name=l_name, available_time_slots=[(d, s) for d in range(5) for s in range(slots_per_day)])

                block_level, block_semester = normalize_level_token(block.get('level', '')), normalize_semester_token(block.get('semester', ''))
                requested_room_id = str(r_name or '').strip().replace(" ", "_")
                allowed_locked_room = r_name if requested_room_id in rooms else None
                if r_name and allowed_locked_room is None:
                    print(f"[INFO] Ignoring locked room '{r_name}' for {code}: not in active room pool")

                lock_info = {'day': block['day'], 'slot': block['slot'], 'room': allowed_locked_room, 'lecturer': l_name}
                course_to_fixed_exact[(code, block_level, block_semester)] = lock_info
                course_to_fixed_code_only[code] = lock_info
                print(f"[SMART LOCK] Captured block for {code} on day {block['day']} slot {block['slot']} in room {r_name} with lecturer {l_name}")

    # Build normalized program lookup for shared-course mapping
    program_by_norm = {normalize_program_label(p): p for p in all_programs}
    alias_map = {"cs": ["computer science", "comp sci", "cs"], "it": ["information technology", "info tech", "it"], "bis": ["business information system", "bis"], "business": ["business", "business admin", "business administration"]}

    def resolve_programs_from_labels(labels: List[str]) -> List[str]:
        resolved = set()
        norm_programs = list(program_by_norm.keys())
        for label in labels:
            norm_label = normalize_program_label(label)
            if norm_label in program_by_norm: resolved.add(program_by_norm[norm_label]); continue
            matched = False
            for alias_key, alias_terms in alias_map.items():
                if norm_label == alias_key or any(norm_label == term for term in alias_terms):
                    for prog_norm in norm_programs:
                        if any(term in prog_norm for term in alias_terms): resolved.add(program_by_norm[prog_norm]); matched = True
                    break
            if not matched:
                for prog_norm in norm_programs:
                    if norm_label and norm_label in prog_norm: resolved.add(program_by_norm[prog_norm])
        return list(resolved)

    # Load shared (cross-department) courses
    shared_course_map = {}
    if os.path.exists(shared_courses_path) and os.path.getsize(shared_courses_path) > 0:
        try:
            shared_df = pd.read_csv(shared_courses_path)
            shared_df.columns = [c.strip().lower() for c in shared_df.columns]
            for _, row in shared_df.iterrows():
                course_code = normalize_course_code(str(row.get('course_code', '')))
                if not course_code: continue
                dept_labels = split_department_labels(str(row.get('department', row.get('departments', ''))).strip())
                programs = resolve_programs_from_labels(dept_labels)
                shared_level, shared_semester = row.get('course_level'), row.get('semester')
                try: shared_level = int(float(shared_level))
                except: shared_level = None
                shared_semester = str(shared_semester).strip() if shared_semester and str(shared_semester).lower() != 'nan' else None
                shared_course_map[course_code] = {"programs": programs, "level": shared_level, "semester": shared_semester}
        except: pass

    # Build Sections
    courses : Dict[str, Course] = {}
    sections : List[ClassSection] = []
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
        normalized_level = normalize_level_token(level)
        
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



        # Semester parsing
        semester = str(row.get('Semester', '')).strip()
        if not semester or semester.lower() == 'nan':
            # Try to guess semester to avoid capacity bottleneck
            semester = guess_semester(course_code, str(row.get("course_title", "")))
        normalized_semester = normalize_semester_token(semester)

        # Smart locking (Override everything with original block data if available)
        fixed_day, fixed_slot, fixed_room, fixed_lecturer = None, None, None, None
        
        # 1. Direct CSV override
        if section_type == "General" and "day" in combined_df.columns and "start_time" in combined_df.columns:
            day, start_time = str(row.get("day", "")).strip(), str(row.get("start_time", str(row.get("time", "")))).split("-")[0].strip().lower()
            if day in day_to_index and start_time in TIME_TO_SLOT:
                fixed_day, fixed_slot = day_to_index[day], TIME_TO_SLOT[start_time]
        
        # 2. Block-based locking (Keep original day, time, lecturer, room)
        if fixed_day is None:
            lock_key = (canonical_course_code, normalized_level, normalized_semester)
            lock_info = course_to_fixed_exact.get(lock_key, course_to_fixed_code_only.get(canonical_course_code))
            if lock_info:
                fixed_day, fixed_slot, fixed_room, fixed_lecturer = lock_info['day'], lock_info['slot'], lock_info['room'], lock_info['lecturer']
                print(f"[INFO] Strictly locking {course_code} to original schedule: Day {fixed_day}, Slot {fixed_slot}, Room {fixed_room}, Lecturer {fixed_lecturer}")

        cohorts = course_cohorts.get(canonical_course_code, set())
        if section_type == "General":
            cohort_level = int(normalized_level) if normalized_level.isdigit() else 100
            cohorts.add(f"General_{cohort_level}_Sem{semester}")
            for program in all_programs: cohorts.add(f"{program}_{cohort_level}_Sem{semester}")

        if canonical_course_code in shared_course_map:
            shared_info = shared_course_map[canonical_course_code]
            s_level = shared_info.get("level") if shared_info.get("level") is not None else level * 100
            s_semester = shared_info.get("semester") if shared_info.get("semester") is not None else semester
            for program in shared_info.get("programs", []): cohorts.add(f"{program}_{s_level}_Sem{s_semester}")

        dept_group = get_department_group(course_code)
        
        # Determine effective lecturer ID (use fixed_lecturer if locked)
        final_lecturer_name = fixed_lecturer if fixed_lecturer else lecturer_name
        requested_room = str(fixed_room).strip().replace(" ", "_").replace("nan", "").replace("Nan", "") if fixed_room else None
        
        sections.append(ClassSection(
            id=f"{course_code}_{idx}",
            course_code=course_code,
            lecturer_id=final_lecturer_name.replace(" ", "_"),
            section_title=str(row.get("course_title", course_code)).strip(),
            course_type=section_type,
            course_level=normalized_level or "100",
            enrollment=int(row.get('enrollment', 30)),
            cohorts=cohorts,
            fixed_day=fixed_day,
            fixed_slot=fixed_slot,
            requested_room=requested_room,
            semester=semester,
            departmental_group=dept_group,
            owning_department=dept_group,
            credit_hours=str(row.get('credit_hours', '3')).strip()
        ))
    
    # Ensure every special-room target exists in rooms pool (Validated earlier)


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
    Parses one or more General Schedule CSVs to identify BUSY slots for cohorts.
    Accepts a single path string, a comma-separated string, or a list of paths.
    """
    if not general_csv_path:
        return []

    # Handle multiple paths (comma-separated or list)
    paths = []
    if isinstance(general_csv_path, list):
        paths = general_csv_path
    elif isinstance(general_csv_path, str):
        paths = [p.strip() for p in general_csv_path.split(',') if p.strip()]
    
    all_blocks = []
    for path in paths:
        if not os.path.exists(path):
            print(f"[WARNING] Block file not found: {path}")
            continue
        
        print(f"[INFO] Loading blocks from {path}...")
        blocks = _load_single_block_file(path, semester)
        all_blocks.extend(blocks)
        
    return all_blocks

def _load_single_block_file(path: str, semester: str = None) -> List[dict]:
    blocks = []
    from analyzer import TIME_TO_SLOT
    day_to_index = {"Monday": 0, "Tuesday": 1, "Wednesday": 2, "Thursday": 3, "Friday": 4, 
                    "Mon": 0, "Tue": 1, "Wed": 2, "Thu": 3, "Fri": 4}
    try:
        df = pd.read_csv(path)
        # Normalize column names for flexible matching
        df.columns = [str(c).lower().strip().replace(" ", "_") for c in df.columns]
        
        # Mapping of required data to possible column synonyms
        col_mappings = {
            'level': ['course_level', 'level', 'year'],
            'semester': ['semester', 'sem'],
            'day': ['day', 'days'],
            'start_time': ['start_time', 'time', 'start'],
            'course_code': ['course_code', 'course_id', 'code', 'course'],
            'room_name': ['room_name', 'room', 'room_id', 'venue'],
            'lecturer_name': ['lecturer_name', 'lecturer', 'staff_name', 'instructor', 'invigilator']
        }

        def get_val(row, key, default=None):
            for syn in col_mappings.get(key, []):
                if syn in row:
                    val = row[syn]
                    if val is not None and str(val).lower() != 'nan':
                        return str(val).strip()
            return default

        for _, row in df.iterrows():
            try:
                # Level processing
                l_val = get_val(row, 'level')
                if l_val:
                    try:
                        level = str(int(float(l_val)))
                    except Exception:
                        level = l_val
                else:
                    level = '100'

                # Semester processing
                sem = get_val(row, 'semester')
                if semester and sem and str(sem) != str(semester):
                    continue
                
                day_str = get_val(row, 'day')
                time_val = get_val(row, 'start_time')
                if not day_str or not time_val:
                    continue

                day_str = day_str.title()
                time_str = time_val.split('-')[0].strip().lower()
                
                if day_str in day_to_index and time_str in TIME_TO_SLOT:
                    day_idx = day_to_index[day_str]
                    slot_obj = TIME_TO_SLOT[time_str]
                    
                    course_code = normalize_course_code(get_val(row, 'course_code', ''))
                    room_name = get_val(row, 'room_name')
                    lecturer_name = get_val(row, 'lecturer_name')
                    
                    blocks.append({
                        'course_code': course_code,
                        'level': level,
                        'semester': sem,
                        'day': day_idx,
                        'slot': slot_obj,
                        'room_name': room_name,
                        'lecturer_name': lecturer_name
                    })
            except Exception:
                continue
    except Exception as e:
        print(f"[WARNING] Could not load general schedule: {e}")
            
    return blocks
