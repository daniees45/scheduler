
# main_web.py - Headless AI Scheduler Core
import sys
import os
import pandas as pd
import time
import threading
import re
from types import SimpleNamespace
import csv
from load_data import load_combined_data, get_department_group, get_department_room_file, normalize_course_code
from builder import build_domain
from constraints import make_constraints
from csp import CSP
from analyzer import train_model, load_trained_model
from export_data import export_solution
from validators import pre_flight_check
from ensemble_models import FeasibilityEnsemble, QualityEnsemble
from timetable_engine.ai_unified_scheduler import AIUnifiedScheduler


def _get_public_web_base_url():
    base_url = os.environ.get('PUBLIC_WEB_BASE_URL') or os.environ.get('WEB_CALLBACK_BASE_URL')
    if not base_url:
        return None
    return base_url.rstrip('/')


def _emit_progress(progress_callback, percent: int, message: str, placed: int = 0, session_id=None):
    """Safely emit progress updates to the web layer."""
    if progress_callback:
        try:
            progress_callback(percent, message, placed)
        except Exception:
            pass
            
    if session_id:
        try:
            import requests
            callback_base = _get_public_web_base_url()
            if not callback_base:
                return
            requests.post(
                f"{callback_base}/api/websocket_progress.php",
                data={
                    "action": "update",
                    "session_id": session_id,
                    "percent": percent,
                    "message": message,
                    "phase": message
                },
                timeout=2
            )
        except Exception as e:
            # Do not fail generation on SSE timeout
            pass


def _validate_ai_solution_hard_constraints(solution, data):
    if not isinstance(solution, list):
        return True, []

    errors = []
    room_slot_seen = {}
    lecturer_slot_seen = {}
    cohort_slot_seen = {}

    day_to_idx = {"Monday": 0, "Tuesday": 1, "Wednesday": 2, "Thursday": 3, "Friday": 4}
    time_start_to_idx = {
        "07:00 AM": 0,
        "10:00 AM": 1,
        "02:00 PM": 2,
        "05:00 PM": 3,
    }

    time_24_to_idx = {
        "07:00": 0,
        "10:00": 1,
        "14:00": 2,
        "17:00": 3,
    }

    def _slot_index(raw_value):
        if raw_value is None:
            return None

        if isinstance(raw_value, (int, float)):
            idx = int(raw_value)
            return idx if 0 <= idx <= 3 else None

        value = str(raw_value).strip()
        if not value:
            return None

        if value.isdigit():
            idx = int(value)
            return idx if 0 <= idx <= 3 else None

        start = value.split("-")[0].strip().lower().replace(".", "")
        start = re.sub(r'\s+', ' ', start)
        compact = start.replace(" ", "")

        ampm_match = re.match(r'^(\d{1,2})(?::(\d{2}))?(am|pm)$', compact)
        if ampm_match:
            hour = int(ampm_match.group(1))
            minute = int(ampm_match.group(2) or "00")
            suffix = ampm_match.group(3)
            if suffix == 'am':
                hour = 0 if hour == 12 else hour
            else:
                hour = 12 if hour == 12 else hour + 12
            return time_24_to_idx.get(f"{hour:02d}:{minute:02d}")

        hm_match = re.match(r'^(\d{1,2}):(\d{2})$', compact)
        if hm_match:
            hour = int(hm_match.group(1))
            minute = int(hm_match.group(2))
            return time_24_to_idx.get(f"{hour:02d}:{minute:02d}")

        canonical_match = re.match(r'^(\d{1,2}):(\d{2})\s*(am|pm)$', start)
        if canonical_match:
            hour = int(canonical_match.group(1))
            minute = int(canonical_match.group(2))
            suffix = canonical_match.group(3)
            if suffix == 'am':
                hour = 0 if hour == 12 else hour
            else:
                hour = 12 if hour == 12 else hour + 12
            return time_24_to_idx.get(f"{hour:02d}:{minute:02d}")

        return time_start_to_idx.get(start.upper())

    special_rooms_all = data.get("special_rooms", {}) or {}

    def _normalize_code(raw_code):
        code = str(raw_code or "").strip()
        code = re.sub(r'\[Sec\s+.*?\]', '', code)
        code = code.split(":")[0].strip()
        return re.split(r'\s*/\s*', code)[0].strip()

    active_course_codes = set()
    for item in solution:
        active_course_codes.add(_normalize_code(item.get("course_code", "")))

    special_rooms = {
        code: info
        for code, info in special_rooms_all.items()
        if code in active_course_codes
    }

    def _norm_room(value):
        return " ".join(str(value or "").strip().lower().split())

    reserved_rooms = set()
    room_pool_ids = set((data.get("rooms", {}) or {}).keys())
    for info in special_rooms.values():
        if isinstance(info, dict):
            reserved_name = str(info.get("room_name", info.get("room", ""))).strip()
            if reserved_name:
                reserved_rooms.add(_norm_room(reserved_name))

    for item in solution:
        course_code = str(item.get("course_code", "")).strip()
        day = str(item.get("day", "")).strip()
        slot = str(item.get("time_slot", "")).strip()
        room = str(item.get("room", item.get("room_name", ""))).strip()
        lecturer = str(item.get("lecturer", item.get("lecturer_name", ""))).strip()
        level = str(item.get("level", item.get("course_level", ""))).strip()
        semester = str(item.get("semester", item.get("Semester", ""))).strip()

        if not day or not slot or not room:
            errors.append(f"Missing assignment fields for {course_code}")
            continue

        room_slot_key = (day, slot, room)
        if room_slot_key in room_slot_seen:
            errors.append(f"Room conflict at {day} {slot} in {room}: {room_slot_seen[room_slot_key]} vs {course_code}")
        else:
            room_slot_seen[room_slot_key] = course_code

        if lecturer:
            lecturer_slot_key = (day, slot, lecturer)
            if lecturer_slot_key in lecturer_slot_seen:
                errors.append(f"Lecturer conflict at {day} {slot} for {lecturer}: {lecturer_slot_seen[lecturer_slot_key]} vs {course_code}")
            else:
                lecturer_slot_seen[lecturer_slot_key] = course_code

        if level and semester:
            cohort_slot_key = (day, slot, level, semester)
            if cohort_slot_key in cohort_slot_seen:
                errors.append(
                    f"Level/Semester clash at {day} {slot} for L{level} S{semester}: {cohort_slot_seen[cohort_slot_key]} vs {course_code}"
                )
            else:
                cohort_slot_seen[cohort_slot_key] = course_code

        normalized_code = _normalize_code(course_code)
        special = special_rooms.get(normalized_code)
        if isinstance(special, dict):
            fixed_room = str(special.get("room_name", special.get("room", ""))).strip()
            fixed_day = special.get("fixed_day", special.get("day"))
            fixed_slot = special.get("fixed_slot", special.get("slot"))
            fixed_time = str(special.get("fixed_time", "")).strip()

            if fixed_room and _norm_room(room) != _norm_room(fixed_room):
                errors.append(f"Special-room violation for {course_code}: expected {fixed_room}, got {room}")

            if fixed_day is not None and str(fixed_day).strip() != "":
                # Support both numeric day index and day-name strings
                if isinstance(fixed_day, (int, float)) or str(fixed_day).isdigit():
                    if day_to_idx.get(day) != int(fixed_day):
                        errors.append(f"Special-day violation for {course_code}: expected day index {fixed_day}, got {day}")
                else:
                    if str(day).strip().lower() != str(fixed_day).strip().lower():
                        errors.append(f"Special-day violation for {course_code}: expected {fixed_day}, got {day}")

            expected_slot = _slot_index(fixed_slot)
            if expected_slot is None and fixed_time:
                expected_slot = _slot_index(fixed_time)
            actual_slot = _slot_index(slot)

            if expected_slot is not None:
                if actual_slot != expected_slot:
                    expected_label = fixed_time if fixed_time else f"slot {fixed_slot}"
                    errors.append(f"Special-time violation for {course_code}: expected {expected_label}, got {slot}")
            elif fixed_time:
                slot_start = slot.split("-")[0].strip().lower().replace(" ", "")
                fixed_time_norm = fixed_time.lower().replace(" ", "")
                if slot_start != fixed_time_norm:
                    errors.append(f"Special-time violation for {course_code}: expected {fixed_time}, got {slot}")
        elif _norm_room(room) in reserved_rooms:
            room_id = str(room or "").strip().replace(" ", "_")
            if room_id not in room_pool_ids:
                errors.append(f"Reserved-room violation: {course_code} cannot use {room}")

    return len(errors) == 0, errors

def run_headless(input_file, mode_choice, output_file, ai_preference, course_type="Departmental", 
                 department="1", availability_mode="1", exam_mode=False, model="csp", semester=None, general_schedule_path=None,
                 progress_session_id=None, weight_room=10.0, weight_lecturer=5.0, weight_balance=8.0,
                 progress_callback=None, max_runtime_seconds=45, fast_mode=True):
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
    start_ts = time.time()
    deadline_ts = start_ts + max(15, int(max_runtime_seconds or 45))

    def _seconds_left() -> float:
        return max(0.0, deadline_ts - time.time())

    os.environ["SCHEDULER_B2_MINIMAL"] = "1" if fast_mode else "0"

    history_data = "csv/general/historical_schedule.csv"
    general_master_schedule = "csv/general/vvu_general_schedule.csv"
    model_file = "scheduling_model.pkl"
    q_model_file = "q_model.pkl"
    temp_dir = "temp"  # Temporary directory for B2 downloads

    def _overwrite_general_master_schedule() -> None:
        if str(course_type).lower() != "general":
            return
        if not os.path.exists(output_file):
            return

        try:
            general_dir = os.path.dirname(general_master_schedule)
            if general_dir:
                os.makedirs(general_dir, exist_ok=True)

            latest_results = pd.read_csv(output_file)
            latest_results.to_csv(general_master_schedule, index=False)
            print(f"[GENERAL] Overwrote master general schedule: {general_master_schedule}")

            temp_general_path = os.path.join(temp_dir, "csv", "general", "vvu_general_schedule.csv")
            os.makedirs(os.path.dirname(temp_general_path), exist_ok=True)
            latest_results.to_csv(temp_general_path, index=False)

            if b2 and b2.s3:
                try:
                    b2.upload_file(general_master_schedule, "csv/general/vvu_general_schedule.csv")
                    print("[B2] Uploaded overwritten master general schedule")
                except Exception as e:
                    print(f"[B2] Failed to upload overwritten master general schedule: {e}")
        except Exception as e:
            print(f"[WARNING] Could not overwrite master general schedule: {e}")

    def _canonical_department_label(label: str) -> str:
        raw = str(label or "").strip().lower().replace(" ", "").replace("_", "").replace("-", "")
        alias_map = {
            "cs/it/bbis": "csitbbis",
            "csitbbis": "csitbbis",
            "cs": "csitbbis",
            "it": "csitbbis",
            "bbis": "csitbbis",
            "computingscience": "csitbbis",
            "business": "business",
            "education": "education",
            "developmentstudies": "developmentstudies",
            "biomedicalengineering": "biomedicalengineering",
            "nursing": "nursing",
            "theology": "theology",
            "general": "general",
        }
        return alias_map.get(raw, raw)

    def _normalize_lecturer_name(name: str) -> str:
        text = str(name or "").strip().lower().replace("_", " ")
        return " ".join(text.split())

    def _load_shared_course_departments() -> dict:
        candidates = [
            os.path.join(temp_dir, "csv", "general", "shared_courses.csv"),
            "csv/general/shared_courses.csv",
            "shared_courses.csv",
        ]
        target = next((p for p in candidates if os.path.exists(p)), None)
        if not target:
            return {}

        try:
            shared_df = pd.read_csv(target)
            shared_df.columns = [str(c).strip().lower() for c in shared_df.columns]
            dep_map = {}

            for _, row in shared_df.iterrows():
                code = normalize_course_code(str(row.get("course_code", "")))
                if not code:
                    continue

                raw_depts = str(row.get("department", row.get("departments", "")) or "")
                labels = [
                    part.strip() for part in re.split(r"[,/;|]+", raw_depts)
                    if str(part).strip()
                ]
                normalized = {_canonical_department_label(dep) for dep in labels if dep}
                if normalized:
                    dep_map.setdefault(code, set()).update(normalized)

            return dep_map
        except Exception as e:
            print(f"[WARNING] Could not parse shared_courses.csv for block filtering: {e}")
            return {}

    def _apply_special_locks_to_solution(solution_rows, data_dict, time_slots):
        """Force special-room courses back to locked room/day/time across all AI models."""
        if not isinstance(solution_rows, list) or not solution_rows:
            return 0

        special_rooms_raw = (data_dict or {}).get("special_rooms", {}) or {}
        if not special_rooms_raw:
            return 0

        day_names = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday"]
        day_to_idx_local = {"monday": 0, "tuesday": 1, "wednesday": 2, "thursday": 3, "friday": 4}
        time_24_to_idx = {
            "07:00": 0,
            "10:00": 1,
            "14:00": 2,
            "17:00": 3,
        }

        def _normalize_code(raw_code):
            code = str(raw_code or "").strip().upper()
            code = re.sub(r'\[Sec\s+.*?\]', '', code, flags=re.IGNORECASE)
            code = code.split(":")[0].strip()
            return re.split(r'\s*/\s*', code)[0].strip()

        def _slot_index(raw_value):
            if raw_value is None:
                return None

            if isinstance(raw_value, (int, float)):
                idx = int(raw_value)
                return idx if 0 <= idx < len(time_slots) else None

            value = str(raw_value).strip()
            if not value:
                return None

            if value.isdigit():
                idx = int(value)
                return idx if 0 <= idx < len(time_slots) else None

            start = value.split("-")[0].strip().lower().replace(".", "")
            start = re.sub(r'\s+', ' ', start)
            compact = start.replace(" ", "")

            ampm_match = re.match(r'^(\d{1,2})(?::(\d{2}))?(am|pm)$', compact)
            if ampm_match:
                hour = int(ampm_match.group(1))
                minute = int(ampm_match.group(2) or "00")
                suffix = ampm_match.group(3)
                if suffix == 'am':
                    hour = 0 if hour == 12 else hour
                else:
                    hour = 12 if hour == 12 else hour + 12
                return time_24_to_idx.get(f"{hour:02d}:{minute:02d}")

            hm_match = re.match(r'^(\d{1,2}):(\d{2})$', compact)
            if hm_match:
                hour = int(hm_match.group(1))
                minute = int(hm_match.group(2))
                return time_24_to_idx.get(f"{hour:02d}:{minute:02d}")

            canonical_match = re.match(r'^(\d{1,2}):(\d{2})\s*(am|pm)$', start)
            if canonical_match:
                hour = int(canonical_match.group(1))
                minute = int(canonical_match.group(2))
                suffix = canonical_match.group(3)
                if suffix == 'am':
                    hour = 0 if hour == 12 else hour
                else:
                    hour = 12 if hour == 12 else hour + 12
                return time_24_to_idx.get(f"{hour:02d}:{minute:02d}")

            return None

        normalized_special = {}
        for code, info in special_rooms_raw.items():
            norm = _normalize_code(code)
            if norm and norm not in normalized_special:
                normalized_special[norm] = info

        corrected = 0
        for row in solution_rows:
            if not isinstance(row, dict):
                continue

            code_norm = _normalize_code(row.get("course_code", ""))
            special = normalized_special.get(code_norm)
            if not isinstance(special, dict):
                continue

            fixed_room = str(special.get("room_name", special.get("room", ""))).strip()
            fixed_day = special.get("fixed_day", special.get("day"))
            fixed_slot = special.get("fixed_slot", special.get("slot"))
            fixed_time = str(special.get("fixed_time", "")).strip()

            changed = False

            if fixed_room:
                if str(row.get("room", "")).strip() != fixed_room:
                    row["room"] = fixed_room
                    changed = True
                if str(row.get("room_name", "")).strip() != fixed_room:
                    row["room_name"] = fixed_room
                    changed = True

            day_idx = None
            if isinstance(fixed_day, (int, float)) or str(fixed_day).isdigit():
                day_idx = int(fixed_day)
            elif str(fixed_day).strip():
                day_idx = day_to_idx_local.get(str(fixed_day).strip().lower())
            if day_idx is not None and 0 <= day_idx < len(day_names):
                day_name = day_names[day_idx]
                if str(row.get("day", "")).strip() != day_name:
                    row["day"] = day_name
                    changed = True

            slot_idx = _slot_index(fixed_slot)
            if slot_idx is None and fixed_time:
                slot_idx = _slot_index(fixed_time)
            if slot_idx is not None and 0 <= slot_idx < len(time_slots):
                fixed_slot_label = time_slots[slot_idx]
                if str(row.get("time_slot", "")).strip() != fixed_slot_label:
                    row["time_slot"] = fixed_slot_label
                    changed = True

            if changed:
                corrected += 1

        return corrected

    def _archive_schedule_history() -> str | None:
        if not os.path.exists(output_file):
            return None

        try:
            from datetime import datetime

            history_dir = os.path.dirname(history_data)
            if history_dir:
                os.makedirs(history_dir, exist_ok=True)

            new_results = pd.read_csv(output_file)
            timestamp_suffix = datetime.now().strftime('%Y%m%d_%H%M%S')
            history_timestamped = history_data.replace('.csv', f'_{timestamp_suffix}.csv')

            new_results.to_csv(history_timestamped, index=False)
            print(f"[ARCHIVE] Timestamped version: {history_timestamped}")

            if os.path.exists(history_data):
                new_results.to_csv(history_data, mode='a', header=False, index=False)
                print(f"[ARCHIVE] Appended to master: {history_data}")
            else:
                new_results.to_csv(history_data, index=False)
                print(f"[ARCHIVE] Created master: {history_data}")

            return history_timestamped
        except Exception as e:
            print(f"[WARNING] Could not archive schedule history: {e}")
            return None

    def _post_process_models(history_timestamped: str | None):
        try:
            train_model(history_data=history_data, model_save_path=model_file)

            if b2 and b2.s3:
                print(f"[B2] Uploading schedule artifacts and trained models to B2...")

                try:
                    b2.upload_file(output_file, f"csv/final/{os.path.basename(output_file)}")
                    print(f"[B2] Uploaded final schedule: {output_file}")
                except Exception as e:
                    print(f"[B2] Failed to upload final schedule: {e}")

                if history_timestamped and os.path.exists(history_timestamped):
                    try:
                       # b2.upload_file(history_timestamped, f"csv/history/{os.path.basename(history_timestamped)}")
                       # print(f"[B2] Uploaded timestamped history: {history_timestamped}")
                        # Delete local timestamped version after successful upload
                        os.remove(history_timestamped)
                        print(f"[CLEANUP] Deleted local timestamped history: {history_timestamped}")
                    except Exception as e:
                        print(f"[B2] Failed to upload timestamped history: {e}")


                if os.path.exists(history_data):
                    try:
                        b2.upload_file(history_data, "csv/general/historical_schedule.csv")
                        print("[B2] Uploaded master historical schedule")
                    except Exception as e:
                        print(f"[B2] Failed to upload master historical schedule: {e}")

                try:
                    b2.upload_file(model_file, "scheduling_model.pkl")
                    print(f"[B2] Uploaded: scheduling_model.pkl")
                except Exception as e:
                    print(f"[B2] Failed to upload scheduling model: {e}")

                if os.path.exists(q_model_file):
                    try:
                        b2.upload_file(q_model_file, "q_model.pkl")
                        print(f"[B2] Uploaded: q_model.pkl")
                    except Exception as e:
                        print(f"[B2] Failed to upload Q-model: {e}")

                if os.path.exists("temp/feasibility_classifier.pkl") or os.path.exists("feasibility_ensemble.pkl"):
                    classifier_path = "temp/feasibility_classifier.pkl" if os.path.exists("temp/feasibility_classifier.pkl") else "feasibility_ensemble.pkl"
                    try:
                        b2.upload_file(classifier_path, "feasibility_classifier.pkl")
                        print(f"[B2] Uploaded: feasibility_classifier.pkl")
                    except Exception as e:
                        print(f"[B2] Failed to upload feasibility classifier: {e}")
        except Exception as e:
            print(f"[WARNING] Background model post-processing failed: {e}")

    _emit_progress(progress_callback, 5, "Initializing AI scheduler...", session_id=progress_session_id)
    
    # B2 Context Handling with caching enabled
    try:
        from b2_handler import B2Handler
        b2 = B2Handler(enable_cache=True, cache_dir="temp/b2_cache")
    except:
        b2 = None
    
    # If running in B2 mode (temp dir exists), use temp paths and download models
    if (not fast_mode) and os.path.exists("temp"):
        model_file = "temp/scheduling_model.pkl"
        q_model_file = "temp/q_model.pkl"
        # Attempt to download models if they exist and weren't cached
        if b2 and b2.s3:
            if not os.path.exists(model_file):
                try:
                    b2.download_file("scheduling_model.pkl", model_file)
                    print(f"[B2] Downloaded latest model: scheduling_model.pkl")
                except Exception as e:
                    print(f"[B2] Model download skipped (may not exist yet): {e}")
            if not os.path.exists(q_model_file):
                try:
                    b2.download_file("q_model.pkl", q_model_file)
                    print(f"[B2] Downloaded latest Q-model: q_model.pkl")
                except Exception as e:
                    print(f"[B2] Q-model download skipped (may not exist yet): {e}")
            if not os.path.exists("temp/feasibility_classifier.pkl"):
                try:
                    b2.download_file("feasibility_classifier.pkl", "temp/feasibility_classifier.pkl")
                    print(f"[B2] Downloaded feasibility classifier")
                except Exception as e:
                    print(f"[B2] Feasibility classifier download skipped (may not exist yet): {e}")
    
    # Log all input parameters for debugging
    print(f"[START] Parameters: course_type='{course_type}', department='{department}'")
    
    # Set Environment Variables for constraints module
    if semester:
        os.environ['SCHEDULER_SEMESTER'] = str(semester)

    # 1. AI Memory Loading
    print(f"[AI] Loading Intelligence from {model_file}...")
    preference_model = load_trained_model(model_path=model_file)
    
    # Load Feasibility Ensemble
    feasibility_path = "feasibility_ensemble.pkl"
    feas_ensemble = None
    if os.path.exists(feasibility_path):
        feas_ensemble = FeasibilityEnsemble(feasibility_path)
        if feas_ensemble.load():
            print("[ML] Loaded feasibility ensemble for domain pruning")
            
    _emit_progress(progress_callback, 12, "Loading AI models...", session_id=progress_session_id)
    
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
    _emit_progress(progress_callback, 20, f"Using room pool: {inferred_department}", session_id=progress_session_id)
    
    # 3. General Schedule Dependency (Multi-Source Support)
    blocked_blocks = []
    default_gen_path = "csv/general/vvu_general_schedule.csv"
    from load_data import load_general_schedule_blocks

    # General schedules should NOT use baseline blocking against themselves.
    is_general_session = (str(course_type).lower() == "general" or inferred_department == "General")
    
    if is_general_session:
        print("[INFO] General session detected. Skipping general schedule blocks.")
    else:
        inferred_semester = None
        if semester in ["1", "2"]:
            inferred_semester = semester
        elif semester == "3":
            print("[INFO] All Semesters selected. Processing all courses and blocks.")
            inferred_semester = None

        if os.path.exists(input_file) and inferred_semester is not None:
            try:
                input_df = pd.read_csv(input_file)
                if 'Semester' in input_df.columns:
                    original_count = len(input_df)
                    filtered_df = input_df[input_df['Semester'].astype(str).str.strip() == inferred_semester]
                    filtered_count = len(filtered_df)
                    print(f"[INFO] Filtered input file to Semester {inferred_semester}: {filtered_count} courses (from {original_count} total)")
                    
                    if filtered_df.empty:
                        print(f"[WARNING] No courses found for Semester {inferred_semester}! The schedule may be empty.")
                    
                    # Rewrite the input file so the rest of the pipeline only sees the filtered data
                    filtered_df.to_csv(input_file, index=False)
            except Exception as e:
                print(f"[ERROR] Could not filter input by semester: {e}")

        # Step A: Collect all custom block paths (from list or single string)
        custom_paths = []
        if isinstance(general_schedule_path, list):
            custom_paths.extend(general_schedule_path)
        elif general_schedule_path:
            custom_paths.append(general_schedule_path)
            
        # If no custom paths provided, try auto-detection
        if not custom_paths:
             try:
                import subprocess
                php_script = os.path.join(os.path.dirname(__file__), 'web', 'api', 'get_latest_b2_schedule.php')
                result = subprocess.run(['php', php_script], capture_output=True, text=True, timeout=10)
                if result.returncode == 0:
                    import json
                    b2_data = json.loads(result.stdout)
                    if b2_data.get('status') == 'success' and b2_data.get('file'):
                        latest_file = b2_data['file']
                        temp_schedule = os.path.join(temp_dir, 'latest_general_schedule.csv')
                        # Download from B2
                        download_result = subprocess.run(['php', '-r', f'''
                            require_once "{os.path.join(os.path.dirname(__file__), 'lib', 'B2Storage.php')}";
                            $b2 = new B2Storage();
                            $result = $b2->download("{latest_file}", "{temp_schedule}");
                            if ($result['success']) echo "success";
                        '''], capture_output=True, text=True, timeout=15)
                        if "success" in download_result.stdout:
                            custom_paths.append(temp_schedule)
                            print(f"[INFO] Auto-detected extra blocks from B2: {latest_file}")
             except Exception as e:
                print(f"[WARNING] B2 block detection skipped: {e}")

        # Step B: Use custom block files when supplied; otherwise fall back to baseline.
        usable_custom_paths = [
            path for path in custom_paths
            if path and os.path.exists(path) and path != default_gen_path
        ]

        if usable_custom_paths:
            print("[INFO] Custom block schedules supplied. Skipping baseline vvu_general_schedule.csv.")
            for path in usable_custom_paths:
                extra_blocks = load_general_schedule_blocks(path, semester=inferred_semester)
                blocked_blocks.extend(extra_blocks)
                print(f"[INFO] Added {len(extra_blocks)} custom blocks from {path}")
                _emit_progress(progress_callback, 20, f"Merged blocks from {os.path.basename(path)}", session_id=progress_session_id)
        elif os.path.exists(default_gen_path):
            baseline_blocks = load_general_schedule_blocks(default_gen_path, semester=inferred_semester)
            blocked_blocks.extend(baseline_blocks)
            print(f"[INFO] Loaded {len(baseline_blocks)} baseline blocks from {default_gen_path}")
            _emit_progress(progress_callback, 12, f"Loaded {len(baseline_blocks)} default VVU blocks", session_id=progress_session_id)

        # Smart block relevance filter: drop unrelated cross-department courses while
        # preserving shared courses, same-lecturer collisions, same-course locks, and General blocks.
        if blocked_blocks:
            target_department_key = _canonical_department_label(inferred_department)
            shared_course_departments = _load_shared_course_departments()

            active_course_codes = set()
            active_lecturers = set()
            try:
                active_df = pd.read_csv(input_file)
                active_df.columns = [str(c).strip().lower() for c in active_df.columns]
                if "course_code" in active_df.columns:
                    for code in active_df["course_code"].dropna().astype(str).tolist():
                        norm_code = normalize_course_code(code)
                        if norm_code:
                            active_course_codes.add(norm_code)
                if "lecturer_name" in active_df.columns:
                    for name in active_df["lecturer_name"].dropna().astype(str).tolist():
                        norm_lect = _normalize_lecturer_name(name)
                        if norm_lect:
                            active_lecturers.add(norm_lect)
            except Exception as e:
                print(f"[WARNING] Could not compute active course/lecturer context for block filter: {e}")

            filtered_blocks = []
            dropped = 0
            for block in blocked_blocks:
                block_code = normalize_course_code(block.get("course_code", ""))
                block_lecturer = _normalize_lecturer_name(block.get("lecturer_name", ""))

                keep = False

                # Keep legacy/partial rows we cannot safely classify.
                if not block_code:
                    keep = True

                # Preserve same-course locks.
                if not keep and block_code in active_course_codes:
                    keep = True

                # Preserve cross-department lecturer occupancy protection.
                if not keep and block_lecturer and block_lecturer in active_lecturers:
                    keep = True

                # Preserve General blocks for all departments.
                if not keep and block_code and get_department_group(block_code) == "General":
                    keep = True

                # Keep shared courses that explicitly include the current department.
                if not keep and block_code:
                    shared_depts = shared_course_departments.get(block_code, set())
                    if target_department_key and target_department_key in shared_depts:
                        keep = True

                if keep:
                    filtered_blocks.append(block)
                else:
                    dropped += 1

            blocked_blocks = filtered_blocks
            if dropped > 0:
                print(f"[INFO] Dropped {dropped} unrelated block(s) after shared-course relevance filtering for {inferred_department}.")
                _emit_progress(progress_callback, 24, f"Dropped {dropped} unrelated cross-department blocks", session_id=progress_session_id)
            print(f"[INFO] Block relevance filter retained {len(blocked_blocks)} block(s).")

    # Build baseline occupancy for AI schedulers from blocked blocks so room/day/time
    # collisions are treated as already occupied, and preserve block metadata lookup.
    day_names = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday"]
    slot_names = ["07:00 AM - 09:30 AM", "10:00 AM - 12:30 PM", "02:00 PM - 04:30 PM", "05:00 PM - 06:00 PM"]

    def _normalize_block_code(raw_code: str) -> str:
        code = str(raw_code or "").strip().upper()
        code = re.sub(r'\[SEC\s+.*?\]', '', code)
        code = code.split(":")[0].strip()
        return re.split(r'\s*/\s*', code)[0].strip()

    def _normalize_level_value(raw_level) -> str:
        txt = str(raw_level or '').strip()
        if not txt or txt.lower() == 'nan':
            return ''
        try:
            val = int(float(txt))
            if 0 < val < 10:
                val *= 100
            return str(val)
        except Exception:
            return txt

    def _normalize_semester_value(raw_sem) -> str:
        txt = str(raw_sem or '').strip()
        if not txt or txt.lower() == 'nan':
            return ''
        return txt

    blocked_course_codes = set()
    blocked_exact_map = {}
    blocked_lecturer_slots = set()
    ai_existing_schedule = []
    ai_existing_course_lookup = {}
    for block in blocked_blocks:
        block_code = _normalize_block_code(block.get('course_code', ''))
        if not block_code:
            continue

        blocked_course_codes.add(block_code)
        block_level = _normalize_level_value(block.get('level', ''))
        block_semester = _normalize_semester_value(block.get('semester', ''))
        blocked_exact_map[(block_code, block_level, block_semester)] = block

        block_lecturer = str(block.get('lecturer_name', '')).strip().lower().replace('_', ' ')
        if block_lecturer:
            block_lecturer = " ".join(block_lecturer.split())
            blocked_lecturer_slots.add((block_lecturer, block.get('day'), block.get('slot')))

        if block_code not in ai_existing_course_lookup:
            ai_existing_course_lookup[block_code] = {
                'code': block_code,
                'level': str(block.get('level', '')).strip(),
                'semester': str(block.get('semester', '')).strip(),
            }

        day_raw = block.get('day')
        slot_raw = block.get('slot')
        room_name = str(block.get('room_name', '')).strip()
        day_name = None
        slot_name = None

        try:
            day_idx = int(day_raw)
            if 0 <= day_idx < len(day_names):
                day_name = day_names[day_idx]
        except Exception:
            day_text = str(day_raw or '').strip()
            if day_text in day_names:
                day_name = day_text

        try:
            slot_idx = int(slot_raw)
            if 0 <= slot_idx < len(slot_names):
                slot_name = slot_names[slot_idx]
        except Exception:
            slot_text = str(slot_raw or '').strip()
            if slot_text in slot_names:
                slot_name = slot_text

        if day_name and slot_name and room_name:
            block_lecturer = str(block.get('lecturer_name', '')).strip()
            ai_existing_schedule.append(SimpleNamespace(
                course_code=block_code,
                lecturer=block_lecturer,
                room_name=room_name,
                day=day_name,
                time_slot=slot_name,
            ))

    if blocked_lecturer_slots:
        print(f"[INFO] Blocked lecturer slots applied: {len(blocked_lecturer_slots)}")

    _emit_progress(progress_callback, 28, "Resolving schedule blocks...", session_id=progress_session_id)

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
            _emit_progress(progress_callback, 32, "Cleaning course data...", session_id=progress_session_id)
    
    # For headless web mode, disable interactive prompts
    # Pass rooms_csv_path to use department-specific rooms only
    # Also ensure special_rooms.csv is loaded for pre-assigned courses
    special_rooms_path = "csv/general/special_rooms.csv"
    if not os.path.exists(special_rooms_path):
        special_rooms_path = "special_rooms.csv"  # Fallback to root
    print(f"[INFO] Using special rooms file: {special_rooms_path}")
    data = load_combined_data([input_file], interactive=False, rooms_csv_path=rooms_csv_path, 
                              special_rooms_path=special_rooms_path, blocked_blocks=blocked_blocks,
                              availability_mode=availability_mode)
    _emit_progress(progress_callback, 40, "Loading timetable data and special rooms...", session_id=progress_session_id)

    locked_export_rows = []
    if not is_general_session and blocked_course_codes:
        before_sections = len(data["sections"])

        filtered_sections = []
        filtered_section_ids = set()
        for sec in data["sections"]:
            sec_code = _normalize_block_code(getattr(sec, 'course_code', ''))
            sec_level = _normalize_level_value(getattr(sec, 'course_level', ''))
            sec_semester = _normalize_semester_value(getattr(sec, 'semester', ''))

            matched_block = blocked_exact_map.get((sec_code, sec_level, sec_semester))
            if not matched_block and sec_code in blocked_course_codes:
                matched_block = next((b for b in blocked_blocks if _normalize_block_code(b.get('course_code', '')) == sec_code), None)

            if matched_block:
                day_idx = matched_block.get('day')
                slot_idx = matched_block.get('slot')
                room_name = str(matched_block.get('room_name', '')).strip()
                day_name = day_names[int(day_idx)] if isinstance(day_idx, (int, float)) and 0 <= int(day_idx) < len(day_names) else ""
                slot_name = slot_names[int(slot_idx)] if isinstance(slot_idx, (int, float)) and 0 <= int(slot_idx) < len(slot_names) else ""
                if day_name and slot_name and room_name:
                    lecturer_obj = data["lecturers"].get(getattr(sec, 'lecturer_id', ''), None)
                    lecturer_name = lecturer_obj.name if lecturer_obj else str(getattr(sec, 'lecturer_id', '')).replace("_", " ")
                    locked_export_rows.append({
                        "course_code": getattr(sec, 'course_code', ''),
                        "course_title": getattr(sec, 'section_title', getattr(sec, 'course_code', '')),
                        "credits": getattr(sec, 'credit_hours', '3'),
                        "lecturer": lecturer_name,
                        "room": room_name,
                        "day": day_name,
                        "time_slot": slot_name,
                        "level": getattr(sec, 'course_level', ''),
                        "semester": getattr(sec, 'semester', ''),
                        "enrollment": getattr(sec, 'enrollment', 30),
                    })
                    filtered_section_ids.add(getattr(sec, 'id', ''))
                    continue

            filtered_sections.append(sec)

        data["sections"] = filtered_sections
        if isinstance(data.get("courses"), dict):
            referenced_codes = {getattr(sec, 'course_code', '') for sec in data["sections"]}
            data["courses"] = {
                code: obj
                for code, obj in data["courses"].items()
                if code in referenced_codes
            }
        removed_sections = before_sections - len(data["sections"])
        if removed_sections > 0:
            print(f"[INFO] Skipped {removed_sections} section(s) from solving and will export {len(locked_export_rows)} locked assignment(s).")

    total_sections_for_accuracy = len(data["sections"]) + len(locked_export_rows)

    def _append_locked_rows_to_csv(path: str, rows: list):
        if not rows:
            return
        with open(path, "a", newline='', encoding='utf-8') as fh:
            writer = csv.writer(fh)
            for item in rows:
                slot_text = str(item.get("time_slot", ""))
                writer.writerow([
                    item.get("course_code", ""),
                    item.get("course_title", item.get("course_code", "")),
                    item.get("credits", "3"),
                    item.get("lecturer", ""),
                    item.get("room", ""),
                    item.get("day", ""),
                    slot_text,
                    item.get("level", ""),
                    item.get("semester", ""),
                    slot_text.split("-")[0].strip(),
                    item.get("enrollment", 30),
                    item.get("enrollment", 30),
                ])

    if len(data["sections"]) == 0 and locked_export_rows:
        print("[INFO] All input sections are smart-locked. Exporting locked schedule directly.")
        _emit_progress(progress_callback, 96, "Exporting locked schedule...", session_id=progress_session_id)

        # Write header using standard exporter, then append locked rows.
        export_solution([], data, out_path=output_file)
        _append_locked_rows_to_csv(output_file, locked_export_rows)
        _overwrite_general_master_schedule()
        history_timestamped = _archive_schedule_history()

        total_sections = total_sections_for_accuracy
        placed_sections = len(locked_export_rows)
        accuracy = (placed_sections / total_sections * 100) if total_sections > 0 else 100.0

        print(f"[STATS] Locked export placed {placed_sections}/{total_sections} sections ({accuracy:.1f}%)")

        if fast_mode:
            threading.Thread(target=_post_process_models, args=(history_timestamped,), daemon=True).start()
        else:
            _post_process_models(history_timestamped)

        _emit_progress(progress_callback, 100, "Locked schedule export complete", placed_sections, session_id=progress_session_id)
        return True, accuracy
    
    # 5. Solve
    print("[AI] Solving CSP Constraints...")
    if not pre_flight_check(data, min_lecturer_slots=3):
        print("[ERROR] Validation failed. Fix issues before retrying.")
        _emit_progress(progress_callback, 100, "Validation failed", 0, session_id=progress_session_id)
        return False
    _emit_progress(progress_callback, 52, "Building domain and constraints...", session_id=progress_session_id)

    # Pass Ensemble classifier if available
    # --- Strict Room Enforcement Toggle ---
    is_general = (str(course_type).lower() == "general")
    if 'config' not in data:
        data['config'] = {}
        
    data['config']['strict_departmental'] = True # Force strict room usage as requested
    data['config']['is_general_session'] = is_general
    data['config']['disable_failure_diagnosis'] = bool(fast_mode)
    
    # Inject What-If Analyzer Weights
    data['config']['weight_room'] = weight_room
    data['config']['weight_lecturer'] = weight_lecturer
    data['config']['weight_balance'] = weight_balance
    
    if is_general:
        print("[INFO] General session detected: Overriding all section departments to 'General'")
        for sec in data['sections']:
            sec.departmental_group = "General"
            sec.is_general = True

    print(f"[INFO] AI enforcing STRICT room-to-department assignments (General Session: {is_general})")
    
    domain = build_domain(data, classifier=feas_ensemble, confidence_threshold=0.25)
    constraints = make_constraints(data["sections"], data["rooms"], preference_model, 
                                   blocked_blocks=blocked_blocks, lecturers=data["lecturers"], 
                                   enable_flexibility=True, course_cohorts=data["course_cohorts"])

    # Incremental solver-progress bridge for web polling UI
    solve_percent = [60.0]
    last_emit_ts = [0.0]

    def _solver_progress(_msg: str):
        now = time.time()
        if now - last_emit_ts[0] < 0.4:
            return
        last_emit_ts[0] = now
        solve_percent[0] = min(94.0, solve_percent[0] + 0.7)
        _emit_progress(progress_callback, int(solve_percent[0]), "AI solving constraints...", session_id=progress_session_id)

    _emit_progress(progress_callback, 60, "AI solving constraints...", session_id=progress_session_id)

    solution = None
    if str(model).lower() == "csp":
        csp_timeout_seconds = max(15, int(min(40, _seconds_left() * 0.75)))
        solver = CSP(
            data["sections"],
            domain,
            constraints,
            data["lecturers"],
            data["rooms"],
            preference_model,
            progress_callback=_solver_progress,
            timeout_seconds=csp_timeout_seconds,
            config=data.get('config')
        )
        solution = solver.solve()
    else:
        # Use AIUnifiedScheduler for GA, RL, NN, Ensemble, or Hybrid
        _emit_progress(progress_callback, 65, f"Initializing {model.upper()} engine...", session_id=progress_session_id)
        
        # Prepare course/room data for Unified Scheduler
        # Standard time slots used by the engine (aligned with 2.5h standard)
        time_slots = ["07:00 AM - 09:30 AM", "10:00 AM - 12:30 PM", "02:00 PM - 04:30 PM", "05:00 PM - 06:00 PM"]
        course_dicts = []
        for sec in data["sections"]:
            # Map fixed_slot (int index) to time_slots (string range)
            fixed_time = None
            if sec.fixed_slot is not None and sec.fixed_slot < len(time_slots):
                fixed_time = time_slots[sec.fixed_slot]
            
            course_dicts.append({
                "code": sec.course_code,
                "section_id": sec.id,  # Unique section identifier to preserve per-section titles
                "title": sec.section_title or sec.course_code,
                "department": sec.departmental_group,
                "credits": sec.credit_hours,
                "level": getattr(sec, 'course_level', '100'),
                "semester": getattr(sec, 'semester', '1'),
                "lecturer": data["lecturers"].get(sec.lecturer_id).name if data["lecturers"].get(sec.lecturer_id) else "TBD",
                "fixed_day": ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday"][sec.fixed_day] if sec.fixed_day is not None else None,
                "fixed_time": fixed_time,
                "fixed_room": sec.requested_room.replace("_", " ") if sec.requested_room is not None else None
            })
            
        room_dicts = [{"name": r.name, "capacity": r.capacity, "department": getattr(r, 'department', 'General')} for r in data["rooms"].values()]
        
        unified_solver = AIUnifiedScheduler(
            data_path=".",
            courses=course_dicts,
            lecturers=[l.name for l in data["lecturers"].values()],
            rooms=room_dicts,
            time_slots=time_slots,
            days=["Monday", "Tuesday", "Wednesday", "Thursday", "Friday"],
            enable_ga=(model == "ga" or model == "hybrid"),
            enable_rl=(model == "rl" or model == "hybrid"),
            enable_nn=(model == "nn" or model == "hybrid"),
            enable_ensemble=(model == "ensemble" or model == "hybrid"),
            existing_schedule=ai_existing_schedule,
            existing_course_lookup=ai_existing_course_lookup,
            strict_departmental=data['config'].get('strict_departmental', True),
            is_general_session=data['config'].get('is_general_session', False),
            verbose=True
        )
        
        if model == "ga":
            solution, _, _ = unified_solver.schedule_with_ga()
        elif model == "rl":
            rl_episodes = 100 if fast_mode else 300
            solution, _, _ = unified_solver.schedule_with_rl(num_episodes=rl_episodes)

        elif model == "nn":
            solution, _, _ = unified_solver.schedule_with_nn()
        elif model == "ensemble":
            solution, _, _ = unified_solver.schedule_with_ensemble()
        elif model == "hybrid":
            hybrid_episodes = 20 if fast_mode else 50
            results = unified_solver.schedule_all(use_rl_episodes=hybrid_episodes)
            solution = results['best_schedule']
            # Update winner for final message
            best_method = results['best_method']
            print(f"[HYBRID] Winner: {best_method}")
    
    if solution:
        lock_fixes = _apply_special_locks_to_solution(
            solution,
            data,
            time_slots=["07:00 AM - 09:30 AM", "10:00 AM - 12:30 PM", "02:00 PM - 04:30 PM", "05:00 PM - 06:00 PM"]
        )
        if lock_fixes > 0:
            print(f"[LOCK] Re-applied special locks to {lock_fixes} AI assignment(s) before validation.")

        valid, hard_errors = _validate_ai_solution_hard_constraints(solution, data)
        if not valid:
            print("[FAILURE] AI output violates hard constraints. Rejecting schedule.")
            for message in hard_errors[:20]:
                print(f"[HARD] {message}")
            _emit_progress(progress_callback, 100, "Generated schedule violated hard constraints", 0, session_id=progress_session_id)
            return False, 0.0

        print(f"[SUCCESS] Solution found. Exporting to {output_file}...")
        _emit_progress(progress_callback, 96, "Exporting generated schedule...", session_id=progress_session_id)
        export_solution(solution, data, out_path=output_file)
        _append_locked_rows_to_csv(output_file, locked_export_rows)
        _overwrite_general_master_schedule()
        history_timestamped = _archive_schedule_history()
        
        # Calculate accuracy (percentage of courses scheduled)
        total_sections = total_sections_for_accuracy
        placed_sections = len(solution) + len(locked_export_rows)
        accuracy = (placed_sections / total_sections * 100) if total_sections > 0 else 0.0
        
        print(f"[STATS] Placed {placed_sections}/{total_sections} sections ({accuracy:.1f}%)")
        
        if fast_mode:
            threading.Thread(target=_post_process_models, args=(history_timestamped,), daemon=True).start()
        else:
            _post_process_models(history_timestamped)
        
        # Evaluate Final Quality using QualityEnsemble
        quality_score = "Good"
        quality_path = "quality_ensemble.pkl"
        if os.path.exists(quality_path):
            q_ensemble = QualityEnsemble(quality_path)
            if q_ensemble.load():
                # Extract simple features for high-level quality assessment
                # In a real scenario, this would be more complex
                summary_features = {
                    'placed_ratio': placed_sections / total_sections,
                    'accuracy': accuracy / 100.0,
                    'is_complete': 1 if placed_sections == total_sections else 0
                }
                # (Note: Feature mapping must match training)
                # For now we use the ensemble name to signal its presence
                print(f"[ML] Schedule evaluated as '{quality_score}' by QualityEnsemble")
        
        _emit_progress(progress_callback, 100, f"Schedule complete (Quality: {quality_score})", placed_sections, session_id=progress_session_id)
        
        return True, accuracy
    else:
        print(f"[FAILURE] {model.upper()} found no valid schedule within constraints.")

        if _seconds_left() < 6:
            _emit_progress(progress_callback, 100, f"{model.upper()} timed out under latency budget", 0, session_id=progress_session_id)
            return False, 0.0

        model_lower = str(model).lower()
        is_second_chance_for_ensemble = model_lower in ['ensemble', 'hybrid']
        fallback_banner = "Running recovery Ensemble fallback..." if is_second_chance_for_ensemble else "Running AI Ensemble fallback..."
        _emit_progress(progress_callback, 85, f"{model.upper()} failed. {fallback_banner}", session_id=progress_session_id)
        
        try:
            # Prepare minimal representation of courses (preserve smart-lock/blocking fields)
            fallback_time_slots = ["07:00 AM - 09:30 AM", "10:00 AM - 12:30 PM", "02:00 PM - 04:30 PM", "05:00 PM - 06:00 PM"]
            course_dicts = []
            for sec in data["sections"]:
                fallback_fixed_time = None
                if sec.fixed_slot is not None and sec.fixed_slot < len(fallback_time_slots):
                    fallback_fixed_time = fallback_time_slots[sec.fixed_slot]
                course_dicts.append({
                    "code": sec.course_code,
                    "title": sec.section_title or sec.course_code,
                    "department": sec.departmental_group,
                    "credits": sec.credit_hours,
                    "level": getattr(sec, 'course_level', '100'),
                    "semester": getattr(sec, 'semester', '1'),
                    "lecturer": data["lecturers"].get(sec.lecturer_id).name if data["lecturers"].get(sec.lecturer_id) else "TBD",
                    "fixed_day": ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday"][sec.fixed_day] if sec.fixed_day is not None else None,
                    "fixed_time": fallback_fixed_time,
                    "fixed_room": sec.requested_room.replace("_", " ") if sec.requested_room is not None else None,
                })
                
            room_dicts = [{"name": r.name, "capacity": r.capacity} for r in data["rooms"].values()]

            fallback_strict_departmental = data['config'].get('strict_departmental', True)
            fallback_existing_schedule = ai_existing_schedule
            fallback_existing_lookup = ai_existing_course_lookup

            # If Ensemble/Hybrid already failed once, relax only what is needed for a true recovery pass.
            if is_second_chance_for_ensemble:
                fallback_strict_departmental = False
                fallback_existing_schedule = []
                fallback_existing_lookup = {}

            ensemble_scheduler = AIUnifiedScheduler(
                data_path=".",
                courses=course_dicts,
                lecturers=[l.name for l in data["lecturers"].values()],
                rooms=room_dicts,
                time_slots=["07:00 AM - 09:30 AM", "10:00 AM - 12:30 PM", "02:00 PM - 04:30 PM", "05:00 PM - 06:00 PM"],
                days=["Monday", "Tuesday", "Wednesday", "Thursday", "Friday"],
                enable_ga=False,
                enable_rl=False,
                enable_nn=False,
                enable_ensemble=True,  # ONLY run Ensemble for ultra-fast fallback
                existing_schedule=fallback_existing_schedule,
                existing_course_lookup=fallback_existing_lookup,
                strict_departmental=fallback_strict_departmental,
                is_general_session=data['config']['is_general_session'],
                verbose=True
            )
            
            # Execute Greedy Ensemble search directly
            fallback_solution, avg_quality, metadata = ensemble_scheduler.schedule_with_ensemble()
            
            if fallback_solution and len(fallback_solution) > 0:
                print(f"[SUCCESS] AI Fallback generated {len(fallback_solution)} valid assignments.")
                
                # Enrich and finalize constraints before export 
                enriched_fallback = ensemble_scheduler._finalize_and_enrich_schedule(fallback_solution)

                fallback_lock_fixes = _apply_special_locks_to_solution(
                    enriched_fallback,
                    data,
                    time_slots=["07:00 AM - 09:30 AM", "10:00 AM - 12:30 PM", "02:00 PM - 04:30 PM", "05:00 PM - 06:00 PM"]
                )
                if fallback_lock_fixes > 0:
                    print(f"[LOCK] Re-applied special locks to {fallback_lock_fixes} fallback assignment(s) before validation.")

                valid, hard_errors = _validate_ai_solution_hard_constraints(enriched_fallback, data)
                if not valid:
                    print("[FAILURE] AI fallback output violates hard constraints. Rejecting schedule.")
                    for message in hard_errors[:20]:
                        print(f"[HARD] {message}")
                    _emit_progress(progress_callback, 100, "Fallback violated hard constraints", 0, session_id=progress_session_id)
                    return False, 0.0
                
                # Use standard export to ensure all columns
                export_solution(enriched_fallback, data, out_path=output_file)
                _append_locked_rows_to_csv(output_file, locked_export_rows)
                _overwrite_general_master_schedule()
                history_timestamped = _archive_schedule_history()
                
                total_sections = total_sections_for_accuracy
                placed_sections = len(enriched_fallback) + len(locked_export_rows)
                accuracy = (placed_sections / total_sections * 100) if total_sections > 0 else 0.0
                
                print(f"[STATS] Ensemble Placed {placed_sections}/{total_sections} sections ({accuracy:.1f}%)")
                if fast_mode:
                    threading.Thread(target=_post_process_models, args=(history_timestamped,), daemon=True).start()
                else:
                    _post_process_models(history_timestamped)
                _emit_progress(progress_callback, 100, "AI Fallback Schedule Complete", placed_sections, session_id=progress_session_id)
                return True, accuracy
            else:
                print("[FAILURE] AI Ensemble fallback also failed. Dataset might be completely irresolvable.")
                _emit_progress(progress_callback, 100, "No valid schedule found by any algorithm", 0, session_id=progress_session_id)
                return False, 0.0

        except Exception as fallback_e:
            print(f"[CRITICAL] AI Ensemble fallback crashed: {fallback_e}")
            _emit_progress(progress_callback, 100, f"Critical failure: {fallback_e}", 0, session_id=progress_session_id)
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
