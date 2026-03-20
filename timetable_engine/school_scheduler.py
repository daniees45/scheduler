from .data_loader import DataLoader
from .csp_solver import CSPSolver
from .models import Day
from .course_dependency_manager import CourseDependencyManager
from .historical_data_analyzer import HistoricalDataAnalyzer
from .schedule_accuracy_predictor import ScheduleAccuracyPredictor

class SchoolScheduler:
    def __init__(self, data_path: str):
        self.data_path = data_path
        self.loader = DataLoader(data_path)
        self.slots = ["7:00am - 9:30am", "10:00am - 12:30pm", "2:00pm - 4:30pm", "5:00pm - 6:00pm"]
        
        # Initialize learning components
        self.dependency_manager = CourseDependencyManager(data_path)
        self.history_analyzer = HistoricalDataAnalyzer(data_path)
        self.accuracy_predictor = ScheduleAccuracyPredictor(data_path)
        
        # Availability management mode
        self.availability_mode = "automatic"
        self.courses = []
        self.rooms = []
        
        # Load initial data
        self.load_data()
        
    def load_data(self, dept_filter=None, semester_filter=None):
        """Pre-load courses and rooms into instance variables."""
        courses, rooms = self.loader.load_all(dept_filter, semester_filter)
        self.courses = [c.__dict__ if hasattr(c, '__dict__') else c for c in courses]
        self.rooms = [{"name": r.name, "capacity": r.capacity} for r in rooms]
        return self.courses, self.rooms
    def generate(
        self,
        dept_filter=None,
        semester_filter=None,
        interactive=False,
        existing_schedule=None,
        existing_course_lookup=None,
        allow_general_prompt=True,
        course_groups=None,
        specific_file=None,
        is_exam=False
    ):
        courses, rooms = self.loader.load_all(dept_filter, semester_filter, specific_file=specific_file)
        course_groups = course_groups or getattr(self.loader, 'course_groups', {})

        # Exam scheduling: prompt for other department CSV if not specified
        if is_exam and not dept_filter and interactive:
            print("\nExam scheduling requires awareness of other departments' timetables to avoid conflicts.")
            other_dept_csv = input("Please provide the CSV file for other department's exam timetable (or leave blank to skip): ").strip()
            if other_dept_csv:
                try:
                    import csv
                    with open(other_dept_csv, 'r') as f:
                        reader = csv.DictReader(f)
                        other_dept_schedule = [row for row in reader]
                    # Optionally, merge or check conflicts here
                    print(f"✓ Loaded {len(other_dept_schedule)} entries from {other_dept_csv} for cross-department conflict checking.")
                    # You can add logic here to use other_dept_schedule in conflict checks
                except Exception as e:
                    print(f"✗ Failed to load {other_dept_csv}: {e}")
        
        # Register courses for dependency management
        self.dependency_manager.register_courses(courses)
        
        # Check if we need to schedule general courses first (intelligent workflow)
        should_schedule_general, message = self.dependency_manager.check_scheduling_order()
        if should_schedule_general and interactive and allow_general_prompt and not existing_schedule:
            print("\n" + "="*70)
            print(message)
            print("="*70)
            response = input("\nDo you want to schedule general courses FIRST to prevent conflicts? (y/n, default y): ").lower()
            if response != 'n':
                # First pass: schedule general courses
                print("\n[Phase 1] Scheduling GENERAL COURSES...")
                general_courses = [c for c in courses if c.is_general]
                if general_courses:
                    gen_schedule = self._schedule_courses(
                        general_courses,
                        rooms,
                        courses,
                        interactive,
                        existing_schedule=existing_schedule,
                        existing_course_lookup=existing_course_lookup,
                        course_groups=course_groups
                    )
                    print(f"✓ General courses phase complete: {len(gen_schedule)} courses scheduled")
                
                # Second pass: schedule department courses with conflict detection
                print("\n[Phase 2] Scheduling DEPARTMENT COURSES (with conflict detection)...")
                dept_courses = [c for c in courses if not c.is_general]
                if dept_courses:
                    dept_schedule = self._schedule_courses(
                        dept_courses,
                        rooms,
                        courses,
                        interactive,
                        existing_schedule=gen_schedule,
                        existing_course_lookup=existing_course_lookup,
                        course_groups=course_groups
                    )
                    print(f"✓ Department courses phase complete: {len(dept_schedule)} courses scheduled")
                    schedule = gen_schedule + dept_schedule
                else:
                    schedule = gen_schedule
            else:
                # Normal scheduling
                schedule = self._schedule_courses(
                    courses,
                    rooms,
                    courses,
                    interactive,
                    existing_schedule=existing_schedule,
                    existing_course_lookup=existing_course_lookup,
                    course_groups=course_groups
                )
        else:
            # Normal scheduling
            schedule = self._schedule_courses(
                courses,
                rooms,
                courses,
                interactive,
                existing_schedule=existing_schedule,
                existing_course_lookup=existing_course_lookup,
                course_groups=course_groups
            )
        
        # Store courses for later use
        self.courses = courses
        return schedule
    
    def _schedule_courses(
        self,
        courses,
        rooms,
        all_courses,
        interactive,
        existing_schedule=None,
        existing_course_lookup=None,
        course_groups=None
    ):
        """Internal method to schedule a subset of courses"""
        # Ensure lecturer objects are fetched/interacted with
        lecturers = {}
        for c in all_courses:
            if c.lecturer not in lecturers:
                lecturers[c.lecturer] = self.loader.get_lecturer(c.lecturer, interactive=interactive)
        
        solver = CSPSolver(
            courses,
            rooms,
            self.slots,
            lecturers,
            base_path=self.data_path,
            availability_mode=self.availability_mode,
            course_groups=course_groups
        )
        return solver.solve(
            existing_schedule=existing_schedule,
            existing_course_lookup=existing_course_lookup
        )

    def check_and_handle_availability(self, courses_to_check):
        """Check availability for a set of courses before scheduling"""
        lecturers = {}
        for c in courses_to_check:
            if c.lecturer not in lecturers:
                lecturers[c.lecturer] = self.loader.get_lecturer(c.lecturer)
        
        # Build solver just for the check
        from .csp_solver import CSPSolver
        solver = CSPSolver(
            courses=[], # dummy
            rooms=[],   # dummy
            slots=self.slots,
            lecturers=lecturers,
            base_path=self.data_path,
            availability_mode=self.availability_mode
        )
        solver.check_and_handle_availability()
    
    def generate_with_accuracy_feedback(self, dept_filter=None, semester_filter=None, interactive=True, course_groups=None, specific_file=None):
        """Generate schedule with ML-based accuracy prediction and feedback"""
        schedule = self.generate(dept_filter, semester_filter, interactive, course_groups=course_groups, specific_file=specific_file)
        
        # Convert schedule to format suitable for prediction
        schedule_items = []
        for item in schedule:
            course_code = item.course_code.split(":")[0].strip() if ":" in item.course_code else item.course_code
            course = next((c for c in self.courses if c.code == course_code), None)
            
            if course:
                entry = {
                    "course_code": course_code,
                    "time_slot": item.time_slot,
                    "day": item.day,
                    "level": course.level,
                    "semester": course.semester,
                    "lecturer": item.lecturer,
                    "room_capacity": 50,  # Default
                    "enrollment": 30,  # Default
                    "is_general": course.is_general,
                    "has_special_constraint": course_code in self._get_special_rooms()
                }
                schedule_items.append(entry)
        
        # Predict schedule quality
        quality = self.accuracy_predictor.predict_schedule_quality(schedule_items)
        
        if interactive:
            print("\n" + "="*70)
            print("SCHEDULE QUALITY ANALYSIS")
            print("="*70)
            print(f"Overall Score: {quality['overall_score']:.1%}")
            print(f"Grade: {quality['grade']}")
            print(f"Average Conflict Probability: {quality['average_conflict_probability']:.1%}")
            print(f"Items Scheduled: {quality['item_count']}")
            print("="*70)
        
        # Record schedule in history for learning
        self.history_analyzer.record_schedule(schedule_items)
        self.history_analyzer.save_insights()
        
        return schedule, quality
    
    def _get_special_rooms(self):
        """Get special room constraints"""
        import csv
        import os
        special_rooms = set()
        path = os.path.join(self.data_path, "special_rooms.csv")
        if not os.path.exists(path):
            path = "temp/csv/general/special_rooms.csv"
            
        if os.path.exists(path):
            try:
                with open(path, 'r') as f:
                    reader = csv.DictReader(f)
                    for row in reader:
                        if row.get('course_code'):
                            special_rooms.add(str(row['course_code']).strip().upper())
            except:
                pass
        return special_rooms

    def save_schedule(self, schedule, filename="school_timetable.csv"):
        import csv
        import re # Import re for regex operations
        
        # Check if this is an exam schedule based on metadata presence or filename
        is_exam = "exam" in filename.lower() or (len(schedule) > 0 and (getattr(schedule[0], 'cohort', "") != "" if not isinstance(schedule[0], dict) else schedule[0].get('cohort', "") != ""))

        # Create a mapping of display name to course details
        course_map = {}
        courses_available = hasattr(self, 'courses') and self.courses and isinstance(self.courses, list)
        if courses_available:
            for course in self.courses:
                course_map[getattr(course, 'display_name', getattr(course, 'code', None))] = course
        
        # Get special rooms for tracking
        special_rooms_set = self._get_special_rooms()
        
        def format_time(time_str):
            """Convert time from '7:00am' format to '7:00 AM' format"""
            result = time_str.lower()
            result = result.replace('am', ' AM').replace('pm', ' PM')
            # Clean up any double spaces
            while '  ' in result:
                result = result.replace('  ', ' ')
            return result
        
        with open(filename, 'w', newline='') as f:
            if is_exam:
                fieldnames = ["Course Code", "Course Title", "Invigilator", "No of Students", "Level", "Cohorts", "Room", "Day", "Time"]
            else:
                fieldnames = [
                    "Course Code", "Course Title", "Credit Hrs", "Lecturer Name", 
                    "Room Name", "Day", "Time", "course_level", "Semester", 
                    "start_time", "no_of_students", "enrollment"
                ]
            
            writer = csv.DictWriter(f, fieldnames=fieldnames)
            writer.writeheader()
            
            for item in schedule:
                # Handle both object (CSP/Exam) and dict (AI) types
                is_dict = isinstance(item, dict)
                is_dict = isinstance(item, dict)
                raw_course_code = item.get('course_code') if is_dict else item.course_code
                raw_course_title = item.get('course_title', '') if is_dict else (getattr(item, 'course_title', "") or "")
                
                # Decompose slashed entries if present
                import re
                codes = [c.strip() for c in re.split(r'\s*/\s*', raw_course_code)]
                
                # If title is missing or empty, try to get it from course metadata
                if not raw_course_title:
                    import re
                    lookup_part = re.sub(r'\[Sec\s+.*?\]', '', codes[0]).split(":")[0].strip()
                    course_meta = next((c for c in self.courses if c.code == lookup_part or c.code == codes[0] or (hasattr(c, 'aliases') and lookup_part in getattr(c, 'aliases', []))), None)
                    if course_meta:
                        raw_course_title = course_meta.title
                
                titles = [t.strip() for t in re.split(r'\s*/\s*', raw_course_title)] if raw_course_title else [""]
                
                # Common fields for all sub-courses in this slot
                lecturer = item.get('lecturer') if is_dict else item.lecturer
                room_name = item.get('room') if is_dict else (item.room_name if hasattr(item, 'room_name') else "")
                day = item.get('day') if is_dict else item.day
                time_slot = item.get('time_slot') if is_dict else item.time_slot
                formatted_time = format_time(time_slot)
                
                # Expand each sub-course into its own row
                num_courses = max(len(codes), len(titles))
                for idx in range(num_courses):
                    current_raw_code = codes[idx] if idx < len(codes) else codes[0]
                    current_raw_title = titles[idx] if idx < len(titles) else titles[0]

                    # Preserving section markers and using individual titles as requested
                    course_code = current_raw_code.strip()
                    course_title = current_raw_title.strip()

                    import re
                    # We want the Course Code column to show just the code without the title
                    # E.g. "PEAC 100: Physical Activity [Sec A]" -> "PEAC 100 [Sec A]"
                    display_course_code = course_code
                    if ":" in course_code:
                        base_code = course_code.split(":")[0].strip()
                        section_match = re.search(r'(\[Sec\s+.*?\])', course_code)
                        cohort_match = re.search(r'(\(.*?\))$', course_code)
                        
                        suffixes = []
                        if section_match:
                            suffixes.append(section_match.group(1))
                        if cohort_match:
                            suffixes.append(cohort_match.group(1))
                            
                        if suffixes:
                            display_course_code = f"{base_code} {' '.join(suffixes)}"
                        else:
                            display_course_code = base_code

                    # Get metadata for this specific sub-course
                    clean_code = re.sub(r'\[Sec\s+.*?\]', '', course_code).split(":")[0].strip()
                    course = None
                    if courses_available:
                        course = next((c for c in self.courses if c.code == clean_code or c.code == course_code or (hasattr(c, 'aliases') and clean_code in getattr(c, 'aliases', []))), None)
                        if not course:
                            for c in self.courses:
                                if hasattr(c, 'aliases') and clean_code in getattr(c, 'aliases', []):
                                    course = c
                                    break

                    if is_exam:
                        row = {
                            "Course Code": display_course_code,
                            "Course Title": course_title or (course.title if course else ""),
                            "Invigilator": lecturer,
                            "No of Students": item.get('enrollment', 40) if is_dict else getattr(item, 'enrollment', 40),
                            "Level": (item.get('level', 0) if is_dict else getattr(item, 'level', 0)) or (course.level if course else 0),
                            "Cohorts": item.get('cohort', '') if is_dict else getattr(item, 'cohort', ''),
                            "Room": room_name,
                            "Day": day,
                            "Time": formatted_time
                        }
                    else:
                        # Prefer course metadata, fallback to item fields
                        credit_hrs = (course.credits if course and hasattr(course, 'credits') else "") or (item.get('credits', '') if is_dict else "")
                        course_level = (course.level if course and hasattr(course, 'level') else "") or (item.get('level', '') if is_dict else "")
                        semester = (course.semester if course and hasattr(course, 'semester') else "") or (item.get('semester', '') if is_dict else "")
                        enrollment = item.get('enrollment', "30") if is_dict else getattr(item, 'enrollment', "30")

                        time_parts = time_slot.split("-")
                        start_time_raw = time_parts[0].strip() if time_parts else time_slot
                        start_time = format_time(start_time_raw)

                        row = {
                            "Course Code": display_course_code,
                            "Course Title": course_title or (course.title if course else ""),
                            "Credit Hrs": credit_hrs,
                            "Lecturer Name": lecturer,
                            "Room Name": room_name,
                            "Day": day,
                            "Time": formatted_time,
                            "course_level": course_level,
                            "Semester": semester,
                            "start_time": start_time,
                            "no_of_students": enrollment,
                            "enrollment": enrollment
                        }

                    # Write row for this sub-course
                    writer.writerow(row)

                    # Track special room usage (if applicable)
                    if not is_exam and course_code in special_rooms_set:
                        self._track_special_room_usage(course_code, room_name, course.level if course else 1)
        
        print(f"✓ Schedule saved to {filename}")
    
    def _track_special_room_usage(self, course_code, room_name, level):
        """Track special room usage for learning"""
        import json
        import os
        
        history_path = os.path.join(self.data_path, "history", "special_room_tracking.json")
        
        data = {}
        try:
            if os.path.exists(history_path):
                with open(history_path, 'r') as f:
                    data = json.load(f)
        except:
            pass
        
        # Record usage
        key = f"{course_code}_{room_name}"
        if key not in data:
            data[key] = {"count": 0, "level": level, "room": room_name}
        data[key]["count"] += 1
        
        # Save
        try:
            os.makedirs(os.path.dirname(history_path), exist_ok=True)
            with open(history_path, 'w') as f:
                json.dump(data, f, indent=2)
        except:
            pass
