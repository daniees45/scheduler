import sys
import os

# Add parent directory to sys.path
sys.path.append(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from timetable_engine.school_scheduler import SchoolScheduler
from timetable_engine.exam_scheduler import ExamScheduler
from timetable_engine.pattern_recognizer import PatternRecognizer
from timetable_engine.conflict_detector import ConflictDetector
from timetable_engine.schedule_override import ScheduleOverrideManager, ScheduleChangeController
from timetable_engine.soft_constraint_weights import SoftConstraintWeightingSystem
from timetable_engine.intelligent_interface import IntelligentSchedulingInterface
import json

def get_user_input(prompt, default=None):
    if default:
        res = input(f"{prompt} (default '{default}'): ").strip()
        return res if res else default
    return input(f"{prompt}: ").strip()

def validate_input_path(path):
    """Validate if path is a file or directory and return normalized path"""
    import os
    
    if not path:
        return None, "Path cannot be empty"
    
    # Expand home directory
    path = os.path.expanduser(path)
    
    if os.path.isdir(path):
        return path, None
    elif os.path.isfile(path):
        # If it's a file, return its directory
        return os.path.dirname(path) or '.', None
    else:
        return None, f"Path '{path}' does not exist"

def get_input_path_with_retries(max_attempts=3):
    """Get input path with retry logic"""
    attempts = 0
    while attempts < max_attempts:
        attempts += 1
        print(f"\n[Attempt {attempts}/{max_attempts}]")
        print("Enter input path:")
        print("  - For current directory: '.'")
        print("  - For a folder: '/path/to/folder'")
        print("  - For a CSV file: '/path/to/file.csv' (will use its folder)")
        
        input_path = get_user_input("Input path", default=".")
        
        valid_path, error = validate_input_path(input_path)
        if valid_path:
            return valid_path
        
        print(f"❌ Error: {error}")
        
        if attempts < max_attempts:
            print(f"Please try again...")
    
    print(f"❌ Failed after {max_attempts} attempts")
    return None

def get_output_path_with_retries(max_attempts=3):
    """Get output file path with retry logic"""
    attempts = 0
    while attempts < max_attempts:
        attempts += 1
        print(f"\n[Attempt {attempts}/{max_attempts}]")
        print("Enter output file path:")
        print("  - Filename only (saves in current dir): 'schedule.csv'")
        print("  - Full path: '/path/to/schedule.csv'")
        
        output_path = get_user_input("Output path", default="generated_timetable.csv")
        
        import os
        output_dir = os.path.dirname(output_path) if os.path.dirname(output_path) else '.'
        
        # Check if directory is writable
        if os.path.isdir(output_dir) or output_dir == '.':
            if os.access(output_dir if output_dir != '.' else '.', os.W_OK):
                return output_path
            else:
                print(f"❌ Error: No write permission in '{output_dir}'")
        else:
            print(f"❌ Error: Directory '{output_dir}' does not exist")
        
        if attempts < max_attempts:
            print(f"Please try again...")
    
    print(f"❌ Failed after {max_attempts} attempts")
    return None

def main():
    print("\n" + "="*70)
    print("   POWERED SCHEDULING SYSTEM - UNIFIED INTELLIGENT PLATFORM")
    print("="*70)
    print("\nIntegrated Features:")
    print("  ✓ CSP Constraint Satisfaction")
    print("  ✓ ML-Based Quality Prediction")
    print("  ✓ Automatic Conflict Detection")
    print("  ✓ Pattern Recognition & Bottleneck Analysis")
    print("  ✓ Self-Learning AI System")
    print("  ✓ Availability Management")
    print("  ✓ Special Rooms Lock Enforcement")
    print("  ✓ Department Blocking")

    print("\n\nSelect Mode:")
    print("1. ⭐ Run Intelligent Scheduling (Unified - RECOMMENDED)")
    print("   All features run automatically in one workflow")
    print("")
    print("2. Advanced Options")
    print("   - Conflict Analysis")
    print("   - Constraint Configuration")
    print("   - Schedule Override Management")
    print("")
    print("3. Exit")
    
    mode_choice = input("\nChoice (1-3, default 1): ").strip() or "1"
    
    if mode_choice == "1":
        return run_intelligent_interface()
    elif mode_choice == "2":
        return run_advanced_options()
    elif mode_choice == "3":
        print("\n✓ Goodbye!")
        return
    else:
        print("Invalid choice. Running intelligent interface...")
        return run_intelligent_interface()

def run_advanced_options():
    """Advanced options menu"""
    print("\n" + "="*70)
    print("   ADVANCED OPTIONS")
    print("="*70)
    
    print("\nSelect Option:")
    print("1. Conflict Analysis")
    print("2. Constraint Configuration")
    print("3. Schedule Override Management")
    print("4. Back to Main Menu")
    
    choice = input("\nChoice (1-4): ").strip()
    
    if choice == "1":
        return analyze_conflicts()
    elif choice == "2":
        return configure_constraints()
    elif choice == "3":
        return manage_overrides()
    else:
        return main()

def run_intelligent_interface():
    """Run the intelligent scheduling interface"""
    print("\n" + "="*70)
    print("   INTELLIGENT SCHEDULING INTERFACE")
    print("="*70)
    
    # Get data path
    print("\nEnter data path (default: current directory)")
    data_path = get_user_input("Data path", default=".")
    
    valid_path, error = validate_input_path(data_path)
    if not valid_path:
        print(f"❌ Error: {error}")
        return
    
    print(f"✓ Using path: {valid_path}")
    
    # Run interface
    try:
        interface = IntelligentSchedulingInterface(valid_path)
        interface.run_interactive_workflow()
    except KeyboardInterrupt:
        print("\n\n✓ Scheduling interrupted by user")
    except Exception as e:
        print(f"\n❌ Error: {e}")
        import traceback
        traceback.print_exc()



def analyze_conflicts():
    """Analyze conflicts in a schedule"""
    print("\n[CONFLICT ANALYSIS]")
    schedule_file = get_user_input("Enter schedule filename to analyze", default="generated_timetable.csv")
    
    if not os.path.exists(schedule_file):
        print(f"File not found: {schedule_file}")
        return
    
    # Load schedule
    import csv
    schedule = []
    with open(schedule_file, 'r') as f:
        reader = csv.DictReader(f)
        # Mapping from CSV headers to ScheduleItem fields
        field_map = {
            'Course Code': 'course_code',
            'Day': 'day',
            'Time': 'time_slot',
            'Room Name': 'room_name',
            'Lecturer Name': 'lecturer',
            # Add more mappings if needed
        }
        from timetable_engine.models import ScheduleItem
        for row in reader:
            mapped = {field_map.get(k, k): v for k, v in row.items() if field_map.get(k, k) in ScheduleItem.__dataclass_fields__}
            schedule.append(ScheduleItem(**mapped))
    
    # Create detector and analyze
    input_path = get_user_input("Enter input path for course data", default=".")
    detector = None
    try:
        school = SchoolScheduler(input_path)
        # Load all courses to get the metadata needed for conflict detection
        courses, rooms = school.loader.load_all(dept_filter='all', semester_filter=None)
        
        # Ensure all lecturers are loaded (ConflictDetector needs lecturer objects)
        lecturers = {}
        for c in courses:
            if c.lecturer not in lecturers:
                lecturers[c.lecturer] = school.loader.get_lecturer(c.lecturer)
        
        detector = ConflictDetector(courses, lecturers)
    except Exception as e:
        print(f"Could not create conflict detector: {e}")
        import traceback
        traceback.print_exc()
    if detector:
        conflicts = detector.detect_all_conflicts(schedule)
        report = detector.generate_conflict_report()
        print(f"\n{json.dumps(report, indent=2)}")
    else:
        print("Could not create conflict detector (see error above)")

def configure_constraints():
    """Configure soft constraint weights"""
    print("\n[CONSTRAINT CONFIGURATION]")
    
    weights = SoftConstraintWeightingSystem()
    
    print("\nAvailable Presets:")
    for i, preset in enumerate(weights.presets.keys(), 1):
        print(f"{i}. {preset}")
    
    preset_choice = input("Select preset (1-4) or custom (0): ").strip()
    
    if preset_choice in ["1", "2", "3", "4"]:
        preset_names = list(weights.presets.keys())
        selected = preset_names[int(preset_choice) - 1]
        weights.apply_preset(selected)
        print(f"Applied preset: {selected}")
    
    # Show current configuration
    config = weights.get_weight_config()
    print(f"\nCurrent Configuration:")
    print(f"Priority Score: {config['priority_score']}")
    print("\nConstraint Weights:")
    for name, weight in config['normalized_weights'].items():
        print(f"  {name}: {weight:.2%}")

def manage_overrides():
    """Manage schedule overrides"""
    print("\n[SCHEDULE OVERRIDE MANAGEMENT]")
    
    override_mgr = ScheduleOverrideManager()
    
    print("\nOptions:")
    print("1. View pending overrides")
    print("2. Generate audit report")
    print("3. Export audit log")
    
    choice = input("Choice (1-3): ").strip()
    
    if choice == "1":
        pending = override_mgr.get_pending_overrides()
        print(f"\nPending Overrides: {len(pending)}")
        for i, override in enumerate(pending):
            print(f"\n{i+1}. {override.course_code}")
            print(f"   Type: {override.override_type}")
            print(f"   Reason: {override.reason}")
    
    elif choice == "2":
        report = override_mgr.generate_audit_report()
        print(f"\n{json.dumps(report, indent=2)}")
    
    elif choice == "3":
        override_mgr.export_audit_log()
        print("Audit log exported to: override_audit.json")

if __name__ == "__main__":
    main()
