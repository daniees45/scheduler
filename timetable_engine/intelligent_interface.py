"""
Intelligent Scheduling Interface - Interactive workflow with ML learning
Unified platform: AI + ML + Conflict Detection + Pattern Recognition + CSP
Integrated: ScheduleHistoryLogger, AIModelManager, QLearningPreferenceModel, FeasibilityClassifier
"""

import sys
import os
from typing import Optional, List, Dict
import json
from datetime import datetime

# Add parent directory to path for imports
sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from timetable_engine.school_scheduler import SchoolScheduler
from timetable_engine.conflict_detector import ConflictDetector
from timetable_engine.pattern_recognizer import PatternRecognizer
from timetable_engine.model_manager import AIModelManager
from timetable_engine.schedule_history_logger import ScheduleHistoryLogger
from timetable_engine.ai_models import QLearningPreferenceModel, FeasibilityClassifier
from timetable_engine.models import ScheduleItem
from timetable_engine.ai_unified_scheduler import AIUnifiedScheduler
from timetable_engine.personal_scheduler import PersonalScheduler
from timetable_engine.ai_what_if_analyzer import ImpactAnalyzer
from timetable_engine.llm_interface_router import LLMInterfaceRouter
from timetable_engine.data_loader import DataLoader


def print_section(title: str):
    """Print a formatted section header"""
    print("\n" + "="*70)
    print(f"  {title}")
    print("="*70)


class IntelligentSchedulingInterface:
    """Interactive interface for intelligent schedule generation with learning"""
    
    def __init__(self, data_path: str = "."):
        self.data_path = data_path
        self.scheduler = SchoolScheduler(data_path)
        # Availability management mode: 'automatic' or 'manual'
        self.availability_mode = "automatic"
        self.ai_scheduler = None
        
        # Initialize AI/ML components
        self.model_manager = AIModelManager(data_path)
        self.history_logger = ScheduleHistoryLogger(data_path)
        
        # Initialize AI models
        self.qlearn_model = QLearningPreferenceModel()
        self.feasibility_classifier = FeasibilityClassifier()
        
        # Try to load existing models
        self._load_existing_models()
        
        print(f"\n✓ AI Models initialized")
        print(f"  - Q-Learning model: {'Loaded' if self.model_manager.load_qlearn_model(self.qlearn_model) else 'New'}")
        print(f"  - Feasibility classifier: {'Loaded' if self.model_manager.load_feasibility_model(self.feasibility_classifier) else 'New'}")
    
    def _load_existing_models(self):
        """Attempt to load pre-trained models"""
        try:
            self.model_manager.load_qlearn_model(self.qlearn_model)
        except:
            pass
        
        try:
            self.model_manager.load_feasibility_model(self.feasibility_classifier)
        except:
            pass
    
    def verify_input_files(self):
        """Let user specify input CSV directory and verify files"""
        print_section("CONFIGURE INPUT DATA FILES")
        
        print("\nAlways using root project folder '.' as instructed.")
        user_path = "."
        
        self.specific_file = None
        self.data_path = user_path
        self.scheduler.data_path = user_path
        self.scheduler.loader.base_path = user_path
        self.history_logger = ScheduleHistoryLogger(user_path)
        self.model_manager = AIModelManager(user_path)
        print(f"✓ Using path: {self.data_path}")
        
        print_section("VERIFYING INPUT DATA FILES")
        
        print(f"\nData Path: {self.data_path}")
        print("\nExpected Input Files:")
        
        import glob
        required_files = {
            'lecturer_availability.csv': 'Lecturer Availability',
            'rooms.csv': 'Rooms',
            'level_100.csv': 'Level 100 Courses (Optional)',
            'level_200.csv': 'Level 200 Courses (Optional)',
            'level_300.csv': 'Level 300 Courses (Optional)',
            'level_400.csv': 'Level 400 Courses (Optional)',
        }
        
        found_count = 0
        for filename, description in required_files.items():
            filepath = os.path.join(self.data_path, filename)
            if os.path.exists(filepath):
                print(f"  ✓ {description:.<40} {filename}")
                found_count += 1
            else:
                print(f"  ○ {description:.<40} {filename}")
        
        # Also check for general.csv or any course files
        course_files = glob.glob(os.path.join(self.data_path, "*.csv"))
        course_files = [f for f in course_files if 'level' in os.path.basename(f).lower() or 'course' in os.path.basename(f).lower()]
        
        print(f"\n✓ Found {found_count + len(course_files)} data files")
        print("\nDepartment rooms will be auto-loaded based on department selection.")
        print("New lecturers will be automatically saved to:", 
              os.path.join(self.data_path, 'lecturer_availability.csv'))

    def _get_department_options(self):
        """List department options based strictly on root department_room files"""
        dept_dir = os.path.join(self.data_path, "department_room")
        departments = []
        if os.path.isdir(dept_dir):
            for filename in os.listdir(dept_dir):
                if filename.endswith("_rooms.csv"):
                    departments.append(filename.replace("_rooms.csv", ""))
        departments = sorted(set(departments))
        return ["general"] + departments

    def _build_schedule_records(self, schedule, dept_filter):
        """Normalize schedule items to dict records for logging/training"""
        records = []
        course_map = {}
        if getattr(self.scheduler, "courses", None):
            course_map = {c.code: c for c in self.scheduler.courses}

        for item in schedule:
            if hasattr(item, "course_code"):
                code = item.course_code.split(":")[0].strip()
                course = course_map.get(code)
                records.append({
                    "course_code": code,
                    "course_title": course.title if course else "",
                    "lecturer": item.lecturer,
                    "room_name": item.room_name,
                    "day": item.day,
                    "time_slot": item.time_slot,
                    "level": course.level if course else "",
                    "semester": course.semester if course else "",
                    "students": 30,
                    "credits": course.credits if course else "",
                    "department": dept_filter or ("general" if course and course.is_general else "")
                })
            else:
                records.append(item)

        return records

    def _prompt_availability_mode(self):
        """Prompt for availability mode before scheduling"""
        print_section("AVAILABILITY MANAGEMENT MODE")
        print("\nHow should the AI handle lecturer availability decisions?")
        print("\n1. AI AUTOMATIC")
        print("   - If a lecturer has insufficient available days, AI automatically")
        print("   - expands availability to find a valid schedule")
        print("   - (Faster, less interactive)")
        print("\n2. MANUAL CONTROL")
        print("   - If a lecturer has insufficient available days, you'll be prompted")
        print("   - You can choose to expand availability or let the solver try alternatives")
        print("   - (More control, more interactive)")

        choice = input("\nSelect mode (1 or 2, default 1): ").strip()
        if choice == "2":
            self.availability_mode = "manual"
            self.scheduler.availability_mode = "manual"
            print("\n✓ Mode set to MANUAL CONTROL")
        else:
            self.availability_mode = "automatic"
            self.scheduler.availability_mode = "automatic"
            print("\n✓ Mode set to AI AUTOMATIC")

    def _normalize_time_slot(self, time_slot: str) -> str:
        """Normalize time slot strings to match solver slots"""
        value = time_slot.strip().lower()
        value = value.replace(" am", "am").replace(" pm", "pm")
        value = " ".join(value.split())
        return value

    def _load_schedule_from_csv(self, csv_path: str, semester_filter: Optional[int] = None):
        """Load schedule items and course info from a saved CSV file"""
        import csv

        if not os.path.exists(csv_path):
            return [], {}

        schedule = []
        course_lookup = {}
        with open(csv_path, 'r') as f:
            reader = csv.DictReader(f)
            for row in reader:
                if semester_filter is not None:
                    try:
                        if int(row.get("Semester", 0)) != semester_filter:
                            continue
                    except ValueError:
                        continue

                time_slot = self._normalize_time_slot(row.get("Time", ""))
                if not time_slot:
                    continue

                course_code = row.get("Course Code", "").split(":")[0].strip()
                try:
                    course_level = int(row.get("course_level", 0) or 0)
                except ValueError:
                    course_level = 0
                try:
                    semester = int(row.get("Semester", 0) or 0)
                except ValueError:
                    semester = 0

                if course_code:
                    course_lookup[course_code] = {
                        "level": course_level,
                        "semester": semester,
                        "is_general": True
                    }

                schedule.append(ScheduleItem(
                    course_code=course_code,
                    day=row.get("Day", ""),
                    time_slot=time_slot,
                    room_name=row.get("Room Name", ""),
                    lecturer=row.get("Lecturer Name", "")
                ))

        return schedule, course_lookup


    def _prepare_ai_inputs(self, dept_filter: Optional[str], semester_filter: Optional[int], gen_schedule: list = None, specific_file: Optional[str] = None):
        """Prepare inputs for AI unified scheduler"""
        loader = DataLoader(self.data_path)
        courses, rooms = loader.load_all(dept_filter, semester_filter, specific_file=specific_file)

        course_dicts = []
        lecturers = set()
        lecturer_avail_dicts = {}
        for course in courses:
            lecturers.add(course.lecturer)
            if course.lecturer not in lecturer_avail_dicts:
                l_obj = loader.get_lecturer(course.lecturer)
                if l_obj:
                    lecturer_avail_dicts[course.lecturer] = l_obj.availability
            
            # Incorporate section into the code to ensure sections aren't deduplicated
            # Handle slashed codes (e.g., COSC 370 / INFT 370)
            if ' / ' in course.code:
                code_with_sec = course.code
            else:
                code_with_sec = course.code
                if hasattr(course, 'title') and '[Sec' in course.title:
                    import re
                    sec_match = re.search(r'\[Sec\s+([A-Za-z0-9]+)\]', course.title)
                    if sec_match:
                        code_with_sec = f"{course.code} [Sec {sec_match.group(1)}]"
            
            course_dicts.append({
                "code": code_with_sec,
                "title": course.title,
                "lecturer": course.lecturer,
                "level": course.level,
                "semester": course.semester,
                "credits": course.credits,
                "is_general": course.is_general,
                "enrollment": 30
            })

        room_dicts = [
            {"name": room.name, "capacity": room.capacity}
            for room in rooms
        ]

        # Perform pre-AI smart grouping (similarity detection)
        smart_groups = self._detect_smart_groups(courses)
        
        # Merge existing file-based groups with smart groups
        final_groups = getattr(loader, 'course_groups', {}).copy()
        for lead, others in smart_groups.items():
            if lead not in final_groups:
                final_groups[lead] = []
            for other in others:
                if other not in final_groups[lead]:
                    final_groups[lead].append(other)

        return courses, course_dicts, sorted(lecturers), room_dicts, self.scheduler.slots, final_groups, lecturer_avail_dicts

    def _detect_smart_groups(self, courses) -> Dict[str, List[str]]:
        """Detect course groups based on title similarity and numeric code matches for AI."""
        from .csp_solver import CSPSolver
        # Create a dummy solver to use its similarity logic
        dummy_solver = CSPSolver([], [], [], {})
        
        smart_groups = {}
        processed = set()
        
        for i, c1 in enumerate(courses):
            if c1.code in processed: continue
            
            # Decompose c1 code if it's slashed
            c1_sub_codes = [c.strip() for c in c1.code.split('/')] if ' / ' in c1.code else [c1.code]
            
            matches = []
            for j, c2 in enumerate(courses):
                if i == j or c2.code in processed: continue
                
                # Check same lecturer
                if c1.lecturer != c2.lecturer: continue
                
                # Check same semester
                if c1.semester != c2.semester: continue
                
                # Decompose c2 code if it's slashed
                c2_sub_codes = [c.strip() for c in c2.code.split('/')] if ' / ' in c2.code else [c2.code]
                
                is_match = False
                for c1_sub in c1_sub_codes:
                    for c2_sub in c2_sub_codes:
                        # Numeric code match
                        num1 = dummy_solver._extract_numeric_code(c1_sub)
                        num2 = dummy_solver._extract_numeric_code(c2_sub)
                        
                        if num1 and num2 and num1 == num2:
                            is_match = True
                            break
                    if is_match: break
                
                if not is_match:
                    # Title similarity (use full titles)
                    sim = dummy_solver._calculate_title_similarity(c1.title, c2.title)
                    if sim >= 0.60:
                        is_match = True
                
                if is_match:
                    matches.append(c2.code)
            
            if matches:
                smart_groups[c1.code] = matches
                processed.add(c1.code)
                for m in matches: processed.add(m)
                
        return smart_groups

    def _convert_ai_schedule_to_items(self, ai_schedule):
        """Convert AI schedule dicts to ScheduleItem list"""
        days = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday"]
        schedule_items = []
        for idx, item in enumerate(ai_schedule or []):
            day = item.get("day") or days[idx % len(days)]
            schedule_items.append(ScheduleItem(
                course_code=item.get("course_code", ""),
                day=day,
                time_slot=item.get("time_slot", ""),
                room_name=item.get("room", ""),
                lecturer=item.get("lecturer", "")
            ))
        return schedule_items


    def run_interactive_workflow(self):
        """Run the main interactive scheduling workflow"""
        while True:
            # Step 1: Verify input data files
            self.verify_input_files()
            
            print("\n" + "="*70)
            print("  MAIN MENU")
            print("="*70)
            print("\n[CLASS TIMETABLE ENGINE]")
            print("  1. Generate Schedule (Standard)")
            print("  2. Generate Schedule (AI Unified)")
            print("  3. Schedule Specific Department")
            
            print("\n[EXAM TIMETABLE ENGINE]")
            print("  4. Generate Exam Schedule")
            
            print("\n[SETTINGS & INSIGHTS]")
            print("  5. View Learning Insights")
            print("  6. Check Model Status")
            print("  7. Availability Management Mode")
            print("  8. Personal Schedule Mode")
            print("  9. AI Simulation Lab (What-If)")
            print("  10. Student AI Assistant (Chat)")
            print("  11. Train AI Models")
            print("  12. Exit")
            
            choice = input("\nEnter choice (1-12): ").strip()
            
            if choice == "1":
                self._generate_standard()
            elif choice == "2":
                self._generate_ai_unified()
            elif choice == "3":
                self._schedule_department()
            elif choice == "4":
                self._generate_exam_schedule()
            elif choice == "5":
                self._show_learning_insights()
            elif choice == "6":
                self._show_model_status()
            elif choice == "7":
                self._manage_availability_mode()
            elif choice == "8":
                self._personal_schedule_mode()
            elif choice == "9":
                self._ai_simulation_lab()
            elif choice == "10":
                self._student_ai_assistant()
            elif choice == "11":
                self._train_ai_models()
            elif choice == "12":
                print("\n✓ Goodbye!")
                break
            else:
                print("Invalid choice. Please try again.")
    
    def _generate_standard(self):
        """Generate schedule with complete intelligent workflow"""
        print_section("INTELLIGENT SCHEDULE GENERATION")
        print("Running complete workflow: CSP → Conflict Detection → Pattern Recognition → ML Analysis")

        self._prompt_availability_mode()

        departments = self._get_department_options()
        options = departments + ["none"]
        print("\nAvailable departments:")
        for i, dept in enumerate(options, 1):
            print(f"  {i}. {dept}")
        
        dept_choice = input("\nSelect department (number or name) [default: none]: ").strip()
        if not dept_choice or dept_choice == "none":
            dept_filter = None
        elif dept_choice.isdigit() and 1 <= int(dept_choice) <= len(options):
            dept_filter = options[int(dept_choice) - 1]
            if dept_filter == "none":
                dept_filter = None
        else:
            dept_filter = dept_choice

        semester_input = input("Enter semester (1/2/all) [default: 1]: ").strip().lower()
        if not semester_input or semester_input == "1":
            semester_filter = 1
        elif semester_input == "2":
            semester_filter = 2
        elif semester_input in ["all", "none"]:
            semester_filter = None
        else:
            try:
                semester_filter = int(semester_input)
            except ValueError:
                print("Invalid semester input. Using default (1).")
                semester_filter = 1
        
        specific_file_input = input("\nSpecific CSV file to schedule (Optional, press Enter to use department default): ").strip()
        specific_file = specific_file_input if specific_file_input else None

        gen_schedule = []
        gen_course_lookup = {}
        if dept_filter != "general":
            # Prompt for general schedule baseline
            print("\nEnter general schedule for baseline blocking (Optional).")
            gen_file_input = input("General Timetable Path [default: final/general_final.csv]: ").strip()
            
            gen_file = gen_file_input if gen_file_input else "final/general_final.csv"
            if not os.path.exists(gen_file):
                # Fallback to general_schedule.csv if default doesn't exist and no input provided
                if not gen_file_input and os.path.exists("general_schedule.csv"):
                    gen_file = "general_schedule.csv"
                elif gen_file_input:
                    print(f"⚠️ Warning: File not found: {gen_file}")
                    gen_file = None
                else:
                    gen_file = None
                
            if gen_file and os.path.exists(gen_file):
                print(f"✓ Loading general schedule baseline from: {gen_file}")
                gen_schedule, gen_course_lookup = self._load_schedule_from_csv(gen_file, semester_filter)
            else:
                print("⚠ No general schedule found at standard paths. Proceeding without general constraints.")

        # Load course groups for same courses with different codes
        from timetable_engine.data_loader import DataLoader
        temp_loader = DataLoader(self.data_path)
        course_groups = temp_loader.load_course_groups()
        if course_groups:
            print(f"\n✓ Loaded {len(course_groups)} course group mappings from same_courses.csv")

        print("\nPhase 1: Generating schedule using CSP solver...")
        schedule = self.scheduler.generate(
            dept_filter=dept_filter,
            semester_filter=semester_filter,
            interactive=True,
            existing_schedule=gen_schedule if gen_schedule else None,
            existing_course_lookup=gen_course_lookup if gen_course_lookup else None,
            allow_general_prompt=False,
            course_groups=course_groups if course_groups else None,
            specific_file=specific_file
        )
        
        print(f"\n✓ Schedule generated: {len(schedule)} courses scheduled")
        
        # Phase 2: ML Quality Prediction
        quality_score = 0
        feasibility_score = 0
        
        print("\nPhase 2: Running ML quality prediction...")
        try:
            schedule, quality = self.scheduler.generate_with_accuracy_feedback(
                dept_filter=dept_filter,
                semester_filter=semester_filter,
                interactive=False,
                specific_file=specific_file
            )
            if quality:
                quality_score = quality.get('overall_score', 0)
                print(f"  Quality Score: {quality_score:.1%}")
                print(f"  Grade: {quality.get('grade', 'N/A')}")
                print(f"  Conflict Risk: {quality.get('average_conflict_probability', 0):.1%}")

                model_status = self.scheduler.accuracy_predictor.get_model_status()
                if model_status.get("metrics"):
                    accuracy = model_status["metrics"].get("accuracy", "N/A")
                    print(f"  AI Accuracy: {accuracy}")
        except:
            pass  # ML is optional
        
        # Phase 3: Conflict Detection
        print("\nPhase 3: Analyzing conflicts...")
        conflict_count = 0
        try:
            courses, rooms = self.scheduler.loader.load_all(
                dept_filter=dept_filter,
                semester_filter=semester_filter
            )
            detector = ConflictDetector(courses, self.scheduler.loader.lecturers)
            conflicts = detector.detect_all_conflicts(schedule)
            quality_score = detector.calculate_overall_quality_score()
            conflict_count = len(conflicts)
            
            print(f"  Schedule Quality Score: {quality_score}/100")
            print(f"  Total Conflicts: {conflict_count}")
            
            if conflicts:
                severity_map = {}
                for c in conflicts:
                    severity = c.severity.name
                    severity_map[severity] = severity_map.get(severity, 0) + 1
                
                for severity, count in severity_map.items():
                    print(f"    {severity}: {count}")
        except Exception as e:
            print(f"  (Conflict analysis skipped)")
        
        # Phase 4: Pattern Recognition & Bottleneck Detection
        print("\nPhase 4: Pattern recognition and bottleneck analysis...")
        try:
            recognizer = PatternRecognizer()
            bottlenecks = recognizer.predict_bottlenecks(schedule)
            if bottlenecks:
                print(f"  ⚠ Potential bottlenecks detected:")
                for b in bottlenecks[:3]:  # Show first 3
                    print(f"    • {b['type']}: {b['target']} (High Density)")
            else:
                print(f"  ✓ No bottlenecks detected")
        except Exception as e:
            print(f"  (Pattern recognition skipped)")
        
        # Phase 5: Log schedule to history for AI training
        print("\nPhase 5: Logging schedule to history for AI training...")
        try:
            quality_metrics = {
                'conflicts': conflict_count,
                'quality_score': quality_score,
                'feasibility_score': feasibility_score
            }

            schedule_records = self._build_schedule_records(schedule, dept_filter)
            
            self.history_logger.log_class_schedule(schedule_records, quality_metrics)
            print(f"  ✓ Logged {len(schedule_records)} entries to history")
        except Exception as e:
            print(f"  (History logging skipped: {e})")
        
        # Phase 6: Train AI models on schedule data
        print("\nPhase 6: Training AI models on schedule data...")
        try:
            # Prepare training data from schedule
            experiences = []
            schedule_records = self._build_schedule_records(schedule, dept_filter)
            for item in schedule_records:
                lecturer = item.get('lecturer', 'N/A')
                day = item.get('day', 'N/A')
                time_slot = item.get('time_slot', 'N/A')
                room = item.get('room_name', 'N/A')
                
                # Create experience dict: required for Q-Learning
                reward = quality_score / 100.0 if quality_score > 0 else 0.5
                experiences.append({
                    'lecturer': lecturer,
                    'day': day,
                    'time_slot': time_slot,
                    'room': room,
                    'reward': reward
                })
            
            # Train Q-Learning model
            if experiences:
                self.qlearn_model.learn_from_episode(experiences)
                print(f"  ✓ Q-Learning model trained on {len(experiences)} experiences")
                
                # Train Feasibility Classifier
                for item in schedule_records:
                    self.feasibility_classifier.record_assignment(
                        item.get('lecturer', 'N/A'),
                        item.get('room_name', 'N/A'),
                        item.get('day', 'N/A'),
                        item.get('time_slot', 'N/A'),
                        was_feasible=(quality_score > 50)
                    )
                print(f"  ✓ Feasibility classifier trained on {len(schedule_records)} assignments")
            
        except Exception as e:
            print(f"  (Model training skipped: {e})")
        
        # Phase 7: Save trained models
        print("\nPhase 7: Saving trained models...")
        try:
            self.model_manager.save_qlearn_model(self.qlearn_model)
            print(f"  ✓ Q-Learning model saved to: history/qlearn_preferences.pkl")
            
            self.model_manager.save_feasibility_model(self.feasibility_classifier)
            print(f"  ✓ Feasibility classifier saved to: history/feasibility_classifier.pkl")
        except Exception as e:
            print(f"  (Model saving failed: {e})")
        
        print(f"\n✓ All analyses and training complete!")
        print(f"  Total courses scheduled: {len(schedule)}")
        
        # Save
        output_file = input("\nEnter output filename [default: final/general_final.csv]: ").strip()
        if not output_file:
            output_file = "final/general_final.csv"
        
        os.makedirs(os.path.dirname(output_file) or ".", exist_ok=True)
        self.scheduler.save_schedule(schedule, output_file)
        print(f"\n✓ Schedule saved to: {output_file}")
        input("\nPress Enter to return to the main menu...")

    def _generate_ai_unified(self):
        """Generate schedule using the unified AI scheduler"""
        print_section("AI UNIFIED SCHEDULING")
        
        self._prompt_availability_mode()

        departments = self._get_department_options()
        options = departments + ["none"]
        print("\nAvailable departments:")
        for i, dept in enumerate(options, 1):
            print(f"  {i}. {dept}")

        dept_choice = input("\nSelect department (number or name) [default: none]: ").strip()
        if not dept_choice or dept_choice == "none":
            dept_filter = None
        elif dept_choice.isdigit() and 1 <= int(dept_choice) <= len(options):
            dept_filter = options[int(dept_choice) - 1]
            if dept_filter == "none":
                dept_filter = None
        else:
            dept_filter = dept_choice

        semester_input = input("Enter semester (1/2/all) [default: 1]: ").strip().lower()
        if not semester_input or semester_input == "1":
            semester_filter = 1
        elif semester_input == "2":
            semester_filter = 2
        elif semester_input in ["all", "none"]:
            semester_filter = None
        else:
            try:
                semester_filter = int(semester_input)
            except ValueError:
                print("Invalid semester input. Using default (1).")
                semester_filter = 1

        specific_file_input = input("\nSpecific CSV file to schedule (Optional, press Enter to use department default): ").strip()
        specific_file = specific_file_input if specific_file_input else None

        gen_schedule = []
        gen_course_lookup = {}
        if dept_filter != "general":
            # Prompt for general schedule baseline
            print("\nEnter general schedule for baseline blocking (Optional).")
            gen_file_input = input("General Timetable Path [default: final/general_final.csv]: ").strip()
            
            gen_file = gen_file_input if gen_file_input else "final/general_final.csv"
            if not os.path.exists(gen_file):
                # Fallback to general_schedule.csv if default doesn't exist and no input provided
                if not gen_file_input and os.path.exists("general_schedule.csv"):
                    gen_file = "general_schedule.csv"
                elif gen_file_input:
                    print(f"⚠️ Warning: File not found: {gen_file}")
                    gen_file = None
                else:
                    gen_file = None
                
            if gen_file and os.path.exists(gen_file):
                print(f"✓ Loading general schedule baseline from: {gen_file}")
                gen_schedule, gen_course_lookup = self._load_schedule_from_csv(gen_file, semester_filter)
            else:
                print("⚠ No general schedule found at standard paths. Proceeding without general constraints.")

        # Check availability after filters are selected but before AI starts
        loader = DataLoader(self.data_path)
        courses_to_check, _ = loader.load_all(dept_filter, semester_filter, specific_file=specific_file)
        lecturers_objs = {}
        for c in courses_to_check:
            if c.lecturer not in lecturers_objs:
                lecturers_objs[c.lecturer] = loader.get_lecturer(c.lecturer)
        
        # Run availability check via the main scheduler
        self.scheduler.check_and_handle_availability(courses_to_check)

        courses, course_dicts, lecturers, room_dicts, time_slots, course_groups, lecturer_availability = self._prepare_ai_inputs(
            dept_filter,
            semester_filter,
            gen_schedule=gen_schedule,
            specific_file=specific_file
        )

        if not course_dicts or not lecturers or not room_dicts:
            print("\n✗ Missing data for AI scheduling.")
            if not course_dicts: print("  - Missing courses.")
            if not lecturers: print("  - Missing lecturers.")
            if not room_dicts: print("  - Missing rooms.")
            return

        self.ai_scheduler = AIUnifiedScheduler(
            data_path=self.data_path,
            courses=course_dicts,
            lecturers=lecturers,
            rooms=room_dicts,
            time_slots=time_slots,
            existing_schedule=gen_schedule,
            existing_course_lookup=gen_course_lookup,
            course_groups=course_groups,
            lecturer_availability=lecturer_availability,
            enable_ga=True,
            enable_rl=True,
            enable_nn=True,
            enable_ensemble=True,
            verbose=True,
            strict_departmental=True,
            is_general_session=(dept_filter == "general")
        )

        print("\nAI Scheduling Options:")
        print("  1. Run All (Compare & Pick Best)")
        print("  2. Genetic Algorithm")
        print("  3. Reinforcement Learning")
        print("  4. Neural Network")
        print("  5. Ensemble ML")

        method_choice = input("\nSelect method (1-5) [default: 1]: ").strip()
        if not method_choice or method_choice == "1":
            episodes = input("RL episodes for comparison [default: 50]: ").strip()
            try:
                episodes = int(episodes) if episodes else 50
            except ValueError:
                episodes = 50

            results = self.ai_scheduler.schedule_all(use_rl_episodes=episodes)
            ai_schedule = results.get("best_schedule") or []
            method_name = results.get("best_method") or "N/A"
            score = results.get("best_score", 0)
            print(f"\n✓ Best method: {method_name} (Score: {score:.2%})")
        elif method_choice == "2":
            ai_schedule, score, _ = self.ai_scheduler.schedule_with_ga()
            method_name = "Genetic Algorithm"
        elif method_choice == "3":
            episodes = input("RL episodes [default: 50]: ").strip()
            try:
                episodes = int(episodes) if episodes else 50
            except ValueError:
                episodes = 50
            ai_schedule, score, _ = self.ai_scheduler.schedule_with_rl(num_episodes=episodes)
            method_name = "Reinforcement Learning"
        elif method_choice == "4":
            ai_schedule, score, _ = self.ai_scheduler.schedule_with_nn()
            method_name = "Neural Network"
        elif method_choice == "5":
            ai_schedule, score, _ = self.ai_scheduler.schedule_with_ensemble()
            method_name = "Ensemble ML"
        else:
            print("Invalid method choice. Returning to main menu.")
            return

        schedule_items = self._convert_ai_schedule_to_items(ai_schedule)
        self.scheduler.courses = courses

        print(f"\nWINNER: {method_name} (Score: {score:.2%})")
        output_file = input("\nEnter output filename to save this schedule [default: final/ai_unified.csv]: ").strip()
        if not output_file:
            output_file = "final/ai_unified.csv"

        os.makedirs(os.path.dirname(output_file) or ".", exist_ok=True)
        self.scheduler.save_schedule(schedule_items, output_file)
        print(f"\n✓ AI schedule saved to: {output_file}")
        
        # Phase: Feedback & Learning
        # Self-train models on this successful outcome
        try:
            self.ai_scheduler.apply_feedback(ai_schedule, method_name)
        except Exception as e:
            pass
            
        input("\nPress Enter to return to the main menu...")
    
    def _generate_with_ml(self):
        """Generate schedule with enhanced ML-based accuracy feedback (same as standard now)"""
        # Now just calls the unified standard method since everything is integrated
        return self._generate_standard()
        
        print(f"\n✓ Schedule generated successfully!")
        print(f"  Total courses scheduled: {len(schedule)}")
        
        # Show quality metrics
        if quality.get("overall_score"):
            print(f"\n  Quality Metrics:")
            print(f"    Score: {quality['overall_score']:.1%}")
            print(f"    Grade: {quality['grade']}")
            print(f"    Conflict Risk: {quality['average_conflict_probability']:.1%}")
        
        # Save
        output_file = input("\nEnter output filename [default: final/general_final.csv]: ").strip()
        if not output_file:
            output_file = "final/general_final.csv"
        
        os.makedirs(os.path.dirname(output_file) or ".", exist_ok=True)
        self.scheduler.save_schedule(schedule, output_file)
    
    def _show_learning_insights(self):
        """Display learning insights from historical data"""
        print_section("LEARNING INSIGHTS")
        
        insights = self.scheduler.history_analyzer.get_learning_insights()
        
        # Time patterns
        if insights.get("time_patterns"):
            print("\n[Time Slot Patterns]")
            overall = insights["time_patterns"].get("overall", {})
            for slot, count in sorted(overall.items(), key=lambda x: -x[1])[:5]:
                print(f"  {slot}: {count} uses")
        
        # Recommendations
        if insights.get("recommendations"):
            print("\n[AI Recommendations]")
            for rec in insights["recommendations"]:
                print(f"  • {rec}")
        
        # Special room usage
        if insights.get("special_room_usage"):
            print("\n[Special Room Performance]")
            for room, data in insights["special_room_usage"].items():
                print(f"  {room}:")
                print(f"    - Uses: {data['count']}")
                print(f"    - Success Rate: {data['success_rate']:.1f}%")
    
    def _show_model_status(self):
        """Show AI model status and performance"""
        print_section("AI MODEL STATUS & LEARNING PROGRESS")
        
        # Show model manager status
        self.model_manager.list_available_models()
        
        # Show learning statistics
        print("\n" + "="*70)
        print("  LEARNING STATISTICS")
        print("="*70)
        
        try:
            # Q-Learning model stats
            print("\n[Q-Learning Preference Model]")
            print(f"  Lecturer preferences learned: {len(self.qlearn_model.lecturer_q_table)}")
            print(f"  Room preferences learned: {len(self.qlearn_model.room_q_table)}")
            print(f"  Lecturer-room affinities: {len(self.qlearn_model.lecturer_room_affinity)}")
            
            # Feasibility classifier stats
            print("\n[Feasibility Classifier]")
            print(f"  Feasible patterns recorded: {len(self.feasibility_classifier.feasible_patterns)}")
            print(f"  Infeasible patterns recorded: {len(self.feasibility_classifier.infeasible_patterns)}")
            print(f"  Total assignments evaluated: {self.feasibility_classifier.total_trained}")
            
        except Exception as e:
            print(f"  (Statistics unavailable: {e})")
        
        # Show history statistics
        print("\n[Schedule History]")
        try:
            stats = self.history_logger.get_schedule_statistics()
            print(f"  Total class schedules logged: {stats.get('total_class_entries', 0)}")
            print(f"  Total exam schedules logged: {stats.get('total_exam_entries', 0)}")
            print(f"  Average quality score: {stats.get('average_quality_score', 0):.2f}")
            print(f"  Average feasibility score: {stats.get('average_feasibility_score', 0):.2f}")
            
            # Time slot distribution
            if stats.get('time_slot_distribution'):
                print("\n  Time Slot Distribution (Top 5):")
                time_dist = stats['time_slot_distribution']
                for slot, count in sorted(time_dist.items(), key=lambda x: -x[1])[:5]:
                    print(f"    {slot}: {count} uses")
        except Exception as e:
            print(f"  (History statistics unavailable: {e})")
        
        # Show sklearn model status (if available)
        print("\n[ML Quality Predictor]")
        try:
            status = self.scheduler.accuracy_predictor.get_model_status()
            print(f"  sklearn Available: {'✓ Yes' if status['sklearn_available'] else '○ No'}")
            print(f"  Model Available: {'✓ Yes' if status['model_available'] else '○ No'}")
            print(f"  Model Type: {status['model_type'] or 'N/A'}")
            
            if status.get("metrics"):
                metrics = status["metrics"]
                print(f"\n  Model Performance:")
                print(f"    Accuracy: {metrics.get('accuracy', 'N/A')}")
                print(f"    Precision: {metrics.get('precision', 'N/A')}")
                print(f"    Recall: {metrics.get('recall', 'N/A')}")
                print(f"    F1 Score: {metrics.get('f1', 'N/A')}")
        except Exception as e:
            print(f"  (Predictor unavailable: {e})")
    
    def _schedule_department(self):
        """Schedule a specific department"""
        print_section("DEPARTMENT SCHEDULING")

        self._prompt_availability_mode()

        departments = self._get_department_options()
        
        print("\nAvailable departments:")
        for i, dept in enumerate(departments, 1):
            print(f"  {i}. {dept}")
        
        choice = input(f"\nSelect department (1-{len(departments)}, or enter name): ").strip()
        
        dept_filter = None
        if choice.isdigit() and 1 <= int(choice) <= len(departments):
            dept_filter = departments[int(choice) - 1]
        else:
            dept_filter = choice

        semester_input = input("Enter semester (1/2/all) [default: 1]: ").strip().lower()
        if not semester_input or semester_input == "1":
            semester_filter = 1
        elif semester_input == "2":
            semester_filter = 2
        elif semester_input in ["all", "none"]:
            semester_filter = None
        else:
            try:
                semester_filter = int(semester_input)
            except ValueError:
                print("Invalid semester input. Using default (1).")
                semester_filter = 1
        
        print(f"\nScheduling for: {dept_filter}")
        
        specific_file_input = input("\nSpecific CSV file to schedule (Optional, press Enter to use department default): ").strip()
        specific_file = specific_file_input if specific_file_input else None

        # General courses for blocking
        print("\nEnter general schedule for baseline blocking (Optional).")
        gen_file_input = input("General Timetable Path [default: final/general_final.csv]: ").strip()
        
        gen_file = gen_file_input if gen_file_input else "final/general_final.csv"
        if not os.path.exists(gen_file):
            if not gen_file_input and os.path.exists("general_schedule.csv"):
                gen_file = "general_schedule.csv"
            elif gen_file_input:
                print(f"⚠️ Warning: File not found: {gen_file}")
                gen_file = None
            else:
                gen_file = None

        gen_schedule = []
        gen_course_lookup = {}
        if gen_file and os.path.exists(gen_file):
            print(f"✓ Loading general schedule baseline from: {gen_file}")
            gen_schedule, gen_course_lookup = self._load_schedule_from_csv(gen_file, semester_filter)
        else:
            print("⚠ Proceeding without general constraints.")
        
        # Load course groups for same courses with different codes
        from timetable_engine.data_loader import DataLoader
        temp_loader = DataLoader(self.data_path)
        course_groups = temp_loader.load_course_groups()
        if course_groups:
            print(f"\n✓ Loaded {len(course_groups)} course group mappings from same_courses.csv")

        # Generate department schedule
        print("\n[Phase 2] Generating department schedule...")
        dept_schedule = self.scheduler.generate(
            dept_filter=dept_filter,
            semester_filter=semester_filter,
            interactive=True,
            existing_schedule=gen_schedule if gen_schedule else None,
            existing_course_lookup=gen_course_lookup if gen_course_lookup else None,
            allow_general_prompt=False,
            course_groups=course_groups if course_groups else None,
            specific_file=specific_file
        )
        dept_file = f"final/{dept_filter}_schedule.csv"
        os.makedirs(os.path.dirname(dept_file) or ".", exist_ok=True)
        self.scheduler.save_schedule(dept_schedule, dept_file)
        print(f"✓ Department schedule saved to {dept_file}")

        # Log department schedule to history
        try:
            schedule_records = self._build_schedule_records(dept_schedule, dept_filter)
            self.history_logger.log_class_schedule(schedule_records, quality_metrics=None)
            print(f"✓ Department schedule logged to history ({len(schedule_records)} entries)")
        except Exception as e:
            print(f"⚠ Could not log department schedule: {e}")
        input("\nPress Enter to return to the main menu...")
    
    def _manage_availability_mode(self):
        """Manage lecturer availability handling mode"""
        print_section("AVAILABILITY MANAGEMENT MODE")
        
        print("\nHow should the AI handle lecturer availability decisions?")
        print("\n1. AI AUTOMATIC")
        print("   - If a lecturer has insufficient available days, AI automatically")
        print("   - expands availability to find a valid schedule")
        print("   - (Faster, less interactive)")
        print("\n2. MANUAL CONTROL")
        print("   - If a lecturer has insufficient available days, you'll be prompted")
        print("   - You can choose to expand availability or let the solver try alternatives")
        print("   - (More control, more interactive)")
        
        current_mode = "AUTOMATIC" if self.availability_mode == "automatic" else "MANUAL CONTROL"
        print(f"\nCurrent Mode: {current_mode}")
        
        choice = input("\nSelect mode (1 or 2, or press Enter to keep current): ").strip()
        
        if choice == "1":
            self.availability_mode = "automatic"
            print("\n✓ Mode set to AI AUTOMATIC")
            print("  Lecturers with insufficient availability will be auto-expanded")
            self.scheduler.availability_mode = "automatic"
        elif choice == "2":
            self.availability_mode = "manual"
            print("\n✓ Mode set to MANUAL CONTROL")
            print("  You'll be prompted when lecturers need availability expansion")
            self.scheduler.availability_mode = "manual"
        else:
            print(f"\n✓ Mode unchanged: {current_mode}")

    def _generate_exam_schedule(self):
        """Interactive workflow for exam scheduling"""
        from timetable_engine.exam_scheduler import ExamScheduler
        print_section("EXAM TIMETABLE GENERATION")
        
        print("\nEnter Exam Hall names and capacities.")
        print("Format: 'Hall Name: Capacity, Hall Name: Capacity'")
        print("Example: 'Main Hall: 500, Auditorium: 200, Room 1: 50'")
        
        hall_input = input("\nEnter Halls: ").strip()
        if not hall_input:
            print("No halls provided. Returning to main menu.")
            return
            
        halls = []
        try:
            parts = [p.strip() for p in hall_input.split(',')]
            for p in parts:
                name, cap = p.split(':')
                halls.append({'name': name.strip(), 'capacity': int(cap.strip())})
            print(f"✓ Registered {len(halls)} exam halls.")
        except Exception as e:
            print(f"❌ Invalid format. Use 'Name: Capacity'. Error: {e}")
            return

        semester_input = input("\nEnter semester (1/2) [default: 1]: ").strip()
        semester = 1 # Default value
        if semester_input:
            try:
                semester = int(semester_input)
            except ValueError:
                print("Invalid semester input. Using default (1).")
                
        print("\nInput the csv file for general courses to block same level + semester (baseline)")
        gen_file = input("General schedule CSV path (optional): ").strip()
        gen_schedule = []
        gen_course_lookup = {}
        if gen_file:
            gen_schedule, gen_course_lookup = self._load_schedule_from_csv(gen_file, semester)

        print("\nEnter the input Timetable/Course List CSV (e.g., final/gg.csv)")
        input_file = input("Input CSV: ").strip()
        
        if not input_file:
            print("❌ Error: A specific timetable input is REQUIRED for exam scheduling.")
            print("Curriculum-wide exam scheduling is disabled to prevent data pollution.")
            input("\nPress Enter to return...")
            return

        print("\nPhase 1: Generating Exam Schedule...")
        exam_scheduler = ExamScheduler(self.data_path)
        # Use specific file if provided
        schedule = exam_scheduler.generate(
            halls=halls, 
            semester_filter=semester,
            specific_file=input_file,
            existing_schedule=gen_schedule if gen_schedule else None,
            existing_course_lookup=gen_course_lookup if gen_course_lookup else None
        )
        
        print(f"✓ Generated {len(schedule)} exam assignments.")
        
        # Save
        output_file = input("\nEnter output filename [default: final/exam_schedule.csv]: ").strip()
        if not output_file:
            output_file = "final/exam_schedule.csv"
            
        os.makedirs(os.path.dirname(output_file) or ".", exist_ok=True)
        self.scheduler.save_schedule(schedule, output_file)
        print(f"✓ Exam schedule saved to: {output_file}")
        input("\nPress Enter to return to main menu...")

    def _personal_schedule_mode(self):
        """Interactive workflow for the Personal Engine"""
        print_section("PERSONAL SCHEDULE MODE")
        
        p_scheduler = PersonalScheduler(self.data_path)
        
        # 1. Profile Setup / Load
        print("Select Action:")
        print("1. Create New Profile")
        print("2. Load Existing Profile")
        startup_choice = input("Choice (1-2): ").strip()
        
        name = ""
        role = "Student"
        semester = 1
        
        if startup_choice == "2":
            name = input("Enter your profile name: ").strip()
            if p_scheduler.load_profile(name):
                print(f"✓ Profile for '{name}' loaded successfully.")
                role = p_scheduler.profile.role
                semester = p_scheduler.profile.semester
            else:
                print(f"❌ Profile not found. Switching to creation mode.")
                startup_choice = "1"
        
        if startup_choice == "1":
            name = input("Enter your name: ").strip()
            print("\nSelect Role:")
            print("1. Student")
            print("2. Lecturer")
            role_choice = input("Choice (1-2): ").strip()
            role = "Student" if role_choice == "1" else "Lecturer"
            
            level = None
            dept = None
            if role == "Student":
                level_in = input("Enter your level (e.g. 100): ").strip()
                try:
                    level = int(level_in)
                    if level >= 100: level //= 100
                except:
                    level = 1
                
                sem_in = input("Enter your current semester (1 or 2): ").strip()
                try:
                    semester = int(sem_in)
                except:
                    semester = 1
                dept = input("Enter your department: ").strip()
            else:
                semester = 1 # Default
                
            p_scheduler.create_profile(name, role, level=level, semester=semester, department=dept)
            p_scheduler.save_profile()
            print(f"✓ Profile created and saved for '{name}'.")

        filtered_schedule = []
        
        while True:
            print(f"\n--- {name.upper()}'S PERSONAL DASHBOARD ({role}, Sem {semester}) ---")
            print("1. Load Master Timetables (General + Dept)")
            print("2. Add Appointment (Specific Day/Time)")
            print("3. Add Task (Target Date, Deadline)")
            print("4. View Integrated Schedule")
            print("5. View Workload Analytics")
            print("6. AI: Suggest Quality Study Slots")
            print("7. Task Tracker")
            print("8. Export to iCal/Calendar")
            print("9. Return to Main Menu")
            
            choice = input("\nEnter choice (1-9): ").strip()
            
            if choice == "1":
                print("\nProvide paths to your Timetable CSV files:")
                gen_path = input("1. General Timetable Path: ").strip()
                dept_path = input("2. Departmental Timetable Path: ").strip()
                
                filtered_schedule = []
                loaded_count = 0
                
                for path in [gen_path, dept_path]:
                    if not path: continue
                    if not os.path.exists(path):
                        # Try relative to data_path
                        full_path = os.path.join(self.data_path, path)
                        if os.path.exists(full_path):
                            path = full_path
                        else:
                            print(f"⚠️ Warning: File not found: {path}. Skipping.")
                            continue
                    
                    batch = p_scheduler.load_master_timetable(path)
                    filtered_schedule.extend(batch)
                    loaded_count += len(batch)
                
                print(f"✓ Integrated {loaded_count} relevant course assignments from 2 sources.")

            elif choice == "2":
                e_name = input("Appointment Name: ").strip()
                e_day = input("Day (Monday-Friday): ").strip()
                print("Slots:")
                print("  1. 9:00am - 12:00pm")
                print("  2. 2:00pm - 5:00pm")
                e_slot_idx = input("Choice (1-2): ").strip()
                e_slot = "9:00am - 12:00pm" if e_slot_idx == "1" else "2:00pm - 5:00pm"
                e_loc = input("Location (Optional): ").strip()
                e_priority = input("Priority (High/Medium/Low): ").strip() or "Medium"
                
                if p_scheduler.add_personal_event(e_name, e_day, e_slot, e_priority, category="Appointment", location=e_loc):
                    print(f"✓ Appointment '{e_name}' added.")
            
            elif choice == "3":
                t_name = input("Task Name: ").strip()
                t_deadline = input("Deadline (YYYY-MM-DD): ").strip()
                t_hours = input("Estimated Hours: ").strip() or "1.0"
                t_prio = input("Priority (High/Medium/Low): ").strip() or "Medium"
                
                if p_scheduler.add_task(t_name, t_deadline, float(t_hours), t_prio):
                    print(f"✓ Task '{t_name}' added to tracker.")

            elif choice == "4":
                print_section(f"{name}'S INTEGRATED SCHEDULE")
                combined = filtered_schedule + p_scheduler.profile.personal_events
                # Sort by day/time
                days_order = {"Monday": 1, "Tuesday": 2, "Wednesday": 3, "Thursday": 4, "Friday": 5}
                combined.sort(key=lambda x: (days_order.get(x.day, 6), x.time_slot))
                
                if not combined and not p_scheduler.profile.tasks:
                    print("Schedule is empty.")
                else:
                    print("--- Fixed Events ---")
                    for item in combined:
                        if hasattr(item, 'course_code'): # ScheduleItem
                            print(f"[CLASS] {item.day:<10} | {item.time_slot:<20} | {item.course_code:<10} | {item.room_name}")
                        else: # PersonalEvent
                            status = "[✓]" if item.is_completed else "[ ]"
                            print(f"{status} [EVENT] {item.day:<10} | {item.time_slot:<20} | {item.name:<10} | {item.location or 'N/A'}")
                    
                    print("\n--- Pending Tasks ---")
                    for t in p_scheduler.profile.tasks:
                        if not t.is_completed:
                            print(f"- {t.name:<20} | Due: {t.deadline:<12} | {t.priority} Priority ({t.estimated_hours}h)")

            elif choice == "5":
                print_section("WORKLOAD ANALYTICS")
                analytics = p_scheduler.get_workload_analytics(filtered_schedule + p_scheduler.profile.personal_events)
                print(f"Total Academic/Task Hours: {analytics['total_estimated_hours']}h")
                print(f"Active Class Days: {analytics['days_per_week']}")
                print(f"Pending Tasks: {analytics['pending_tasks']}")
                print(f"Intensity Score: {analytics['intensity_score']}%")
                if analytics['intensity_score'] > 80:
                    print("⚠️ WARNING: Very high workload! Adaptive suggestion: Defer low-priority tasks.")

            elif choice == "6":
                print_section("AI QUALITY STUDY SUGGESTIONS")
                suggestions = p_scheduler.generate_study_suggestions(filtered_schedule)
                if not suggestions:
                    print("No specialized study slots found.")
                else:
                    print("AI identified these slots based on your Productivity Patterns:")
                    for i, s in enumerate(suggestions):
                        prio_icon = "⭐" if s.priority == "High" else "  "
                        print(f"{i+1}. {prio_icon} {s.day}: {s.time_slot} ({s.name})")
                    
                    add_choice = input("\nAdd suggestions to schedule? (yes/no): ").strip().lower()
                    if add_choice == "yes":
                        for s in suggestions:
                            p_scheduler.add_personal_event(s.name, s.day, s.time_slot, s.priority, s.category)
                        print("✓ Study suggestions added.")

            elif choice == "7":
                print_section("TASK TRACKER")
                tasks = p_scheduler.profile.tasks
                if not tasks:
                    print("No tasks found.")
                else:
                    for i, t in enumerate(tasks):
                        status = "✓" if t.is_completed else " "
                        print(f"{i+1}. [{status}] {t.name:<20} | {t.deadline}")
                    
                    t_idx = input("\nEnter task number to toggle: ").strip()
                    try:
                        idx = int(t_idx) - 1
                        if p_scheduler.toggle_task(idx):
                            print(f"✓ Task updated.")
                    except:
                        print("❌ Invalid selection.")

            elif choice == "8":
                print("\nCalendar Export")
                out_file = os.path.join(self.data_path, f"{name.replace(' ', '_')}_schedule.ics")
                combined = filtered_schedule + p_scheduler.profile.personal_events
                if p_scheduler.export_to_ical(combined, out_file):
                    print(f"✓ Schedule exported to: {out_file}")

            elif choice == "9":
                break

    def _ai_simulation_lab(self):
        """Administrator workflow for What-If Analysis"""
        print_section("AI SIMULATION LAB")
        print("Analyze the impact of resource loss on your master timetable.")
        
        # 1. Setup
        analyzer = ImpactAnalyzer(self.data_path)
        print("\nSelect Baseline Timetable (CSV):")
        import glob
        csv_files = glob.glob(os.path.join(self.data_path, "*.csv"))
        if not csv_files:
            print("❌ No CSV files found.")
            return

        for i, f in enumerate(csv_files):
            print(f"{i+1}. {os.path.basename(f)}")
        
        f_idx = input("\nEnter baseline file number: ").strip()
        try:
            target_file = csv_files[int(f_idx)-1]
            if not analyzer.load_scenario(target_file):
                 print("❌ Failed to load scenario.")
                 return
        except:
            print("❌ Invalid selection.")
            return

        # 2. Configure Mutation
        print("\nSpecify Simulated Loss:")
        print("A. Building Closure (e.g. 'Baobab')")
        print("B. Specific Room Closure (e.g. 'American High')")
        print("C. Resource Loss (Building + Room)")
        
        m_choice = input("Choice (A/B/C): ").strip().upper()
        exclude_rooms = []
        exclude_buildings = []
        
        if m_choice in ["A", "C"]:
            b_name = input("Enter Building Name to exclude: ").strip()
            exclude_buildings.append(b_name)
        if m_choice in ["B", "C"]:
            r_name = input("Enter Room Name to exclude: ").strip()
            exclude_rooms.append(r_name)

        # 3. Analyze
        print("\nPhase 1: Calculating Ripple Effects...")
        report = analyzer.simulate_resource_loss(exclude_rooms, exclude_buildings)
        
        print_section("IMPACAT ANALYSIS REPORT")
        print(f"BASELINE: {os.path.basename(target_file)}")
        print(f"TOTAL CLASSES: {report['total_classes']}")
        print(f"DISPLACED CLASSES: {report['displaced_count']}")
        print(f"AFFECTED ROOMS: {', '.join(report['affected_rooms'])}")
        
        print(f"\nCRITICALITY SCORE: {100 - report['feasibility_score']}% (High means hard to recover)")
        print(f"AUTO-MIGRATION FEASIBILITY: {report['feasibility_score']}%")
        
        if report['recommendations']:
            print("\nSuggested Re-routing (Top 5):")
            for rec in report['recommendations'][:5]:
                status_icon = "✓" if rec['status'] == "Solvable" else "❌"
                print(f"  {status_icon} {rec['course']}: {rec['original_room']} -> {rec['suggested_room']} ({rec['time']})")

        input("\nPress Enter to return to main menu...")

    def _student_ai_assistant(self):
        """Natural Language interface for Students"""
        print_section("STUDENT AI ASSISTANT")
        print("Ask anything about your schedule or university gaps.")
        print("(Type 'exit' to return)")
        
        # Initialize router
        p_scheduler = PersonalScheduler(self.data_path)
        # Check if a profile was created in Personal Mode already
        # (This is a simplified multi-context check)
        
        router = LLMInterfaceRouter(p_scheduler, self.scheduler)
        
        while True:
            query = input("\nYou: ").strip()
            if query.lower() == 'exit':
                break
            
            # Simulated context for this demo
            ctx = {'filtered_schedule': []} 
            
            response = router.process_query(query, ctx)
            print(f"\nAI Assistant: {response}")
    def _train_ai_models(self):
        """Unified training flow for all AI models"""
        print_section("AI MODEL TRAINING")
        print("Learning from historical data to improve future scheduling accuracy...")
        
        # Load full data to context the models
        loader = DataLoader(self.data_path)
        courses, rooms = loader.load_all(dept_filter=None, semester_filter=None)
        
        # Collect lecturers
        from timetable_engine.models import Lecturer
        lecturers = sorted(list(set(c.lecturer for c in courses if c.lecturer)))
        
        # Setup course metadata
        course_dicts = []
        for c in courses:
            course_dicts.append({
                'code': c.code,
                'title': c.title,
                'credits': c.credits,
                'level': c.level,
                'semester': c.semester,
                'is_general': getattr(c, 'is_general', False),
                'lecturer': c.lecturer,
                'enrollment': getattr(c, 'enrollment', 30)
            })
            
        time_slots = [
            "7:00am - 9:30am",
            "9:30am - 12:00pm",
            "12:00pm - 2:00pm",
            "2:00pm - 5:00pm",
            "5:00pm - 7:30pm"
        ]
        
        # Initialize scheduler
        from timetable_engine.ai_unified_scheduler import AIUnifiedScheduler
        unified_scheduler = AIUnifiedScheduler(
            data_path=self.data_path,
            courses=course_dicts,
            lecturers=lecturers,
            rooms=[{"name": r.name, "capacity": r.capacity} for r in rooms],
            time_slots=time_slots,
            verbose=True
        )
        
        print("\nStarting multi-model training...")
        results = unified_scheduler.train_models()
        
        print("\n" + "="*70)
        print("TRAINING SUMMARY")
        print("="*70)
        for model, status in results.items():
            icon = "✓" if status.get('status') in ['trained', 'success'] else "✗"
            print(f"{icon} {model:12}: {status.get('message', 'Completed')}")
            if 'accuracy' in status:
                print(f"    - Accuracy: {status['accuracy']*100:.1f}%")
        print("="*70)
        
        input("\nPress Enter to return to main menu...")


def main():
    """Main entry point"""
    # Get data path from argument or use current directory
    data_path = sys.argv[1] if len(sys.argv) > 1 else "."
    
    # Verify path exists
    if not os.path.exists(data_path):
        print(f"Error: Data path not found: {data_path}")
        sys.exit(1)
    
    # Run interface
    interface = IntelligentSchedulingInterface(data_path)
    try:
        interface.run_interactive_workflow()
    except KeyboardInterrupt:
        print("\n\n✓ Scheduling interrupted by user")
        sys.exit(0)
    except Exception as e:
        print(f"\n✗ Error: {e}")
        sys.exit(1)

if __name__ == "__main__":
    main()
