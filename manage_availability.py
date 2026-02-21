import pandas as pd
import os
from typing import List, Dict

def normalize_name(name: str) -> str:
    """Normalize lecturer name for matching"""
    if not isinstance(name, str):
        return ""
    name = name.replace(".", " ").replace("-", " ")
    return " ".join(name.split()).lower()

def prompt_add_lecturer(lecturer_name: str, availability_file: str = "lecturer_availability.csv") -> List[int]:
    """
    Prompt user to add availability for a new lecturer.
    
    Args:
        lecturer_name: Name of the lecturer
        availability_file: Path to lecturer_availability.csv
    
    Returns:
        List of available day indices (0=Mon, 1=Tue, etc.)
    """
    print(f"\n{'='*60}")
    print(f"[WARNING] Lecturer '{lecturer_name}' not found in {availability_file}")
    print(f"{'='*60}")
    print("Would you like to add availability for this lecturer?")
    print("1. Yes - Add availability now")
    print("2. No - Use default (all days available)")
    print(f"{'='*60}")
    
    while True:
        choice = input("Enter choice (1 or 2): ").strip()
        if choice in ["1", "2"]:
            break
        print("[ERROR] Invalid choice. Please enter 1 or 2.")
    
    if choice == "2":
        # Default: all days available
        print(f"[INFO] Using default: {lecturer_name} available all days (Mon-Fri)")
        return [0, 1, 2, 3, 4]
    
    # Prompt for specific days
    print(f"\nSelect available days for {lecturer_name} (separate with commas):")
    print("1=Monday, 2=Tuesday, 3=Wednesday, 4=Thursday, 5=Friday")
    print("Example: 1,2,3,4,5 for all days, or 1,3,5 for Mon/Wed/Fri")
    
    while True:
        days_input = input("Enter days: ").strip()
        try:
            # Parse input
            day_numbers = [int(d.strip()) for d in days_input.split(",")]
            # Validate range
            if all(1 <= d <= 5 for d in day_numbers):
                # Convert to 0-indexed
                available_days = [d - 1 for d in day_numbers]
                break
            else:
                print("[ERROR] Days must be between 1 and 5. Try again.")
        except ValueError:
            print("[ERROR] Invalid format. Use comma-separated numbers (e.g., 1,2,3)")
    
    # Save to CSV
    save_to_csv(lecturer_name, available_days, availability_file)
    
    day_names = ["Mon", "Tue", "Wed", "Thu", "Fri"]
    selected_names = [day_names[i] for i in available_days]
    print(f"✓ Saved: {lecturer_name} available on {', '.join(selected_names)}")
    
    return available_days

def prompt_expand_availability(lecturer_name: str, current_days: List[int], 
                                availability_file: str = "lecturer_availability.csv") -> List[int]:
    """
    Prompt user to expand availability for a lecturer with limited days.
    
    Args:
        lecturer_name: Name of the lecturer
        current_days: List of currently available day indices
        availability_file: Path to lecturer_availability.csv
    
    Returns:
        List of available day indices (may be unchanged or expanded)
    """
    day_names = ["Mon", "Tue", "Wed", "Thu", "Fri"]
    current_names = [day_names[i] for i in current_days]
    
    print(f"\n{'='*60}")
    print(f"[INFO] Lecturer '{lecturer_name}' has limited availability:")
    print(f"  Currently available: {', '.join(current_names)} ({len(current_days)} days)")
    print(f"{'='*60}")
    print("Would you like to expand availability?")
    print("1. Yes - Add more days")
    print("2. No - AI will work with current availability")
    print(f"{'='*60}")
    
    while True:
        choice = input("Enter choice (1 or 2): ").strip()
        if choice in ["1", "2"]:
            break
        print("[ERROR] Invalid choice. Please enter 1 or 2.")
    
    if choice == "2":
        # Keep current availability - AI will use what's available
        print(f"[INFO] AI will schedule {lecturer_name} on available days: {', '.join(current_names)}")
        return current_days
    
    # Show available days to add
    available_to_add = [i for i in range(5) if i not in current_days]
    if not available_to_add:
        print("[INFO] Lecturer already available all days!")
        return current_days
    
    print(f"\nAdd additional days (current: {', '.join(current_names)}):")
    for i in available_to_add:
        print(f"{i+1}={day_names[i]}")
    print("Enter days to add (comma-separated):")
    
    while True:
        days_input = input("Days to add: ").strip()
        if not days_input:
            print("[INFO] No days added. Keeping current availability.")
            return current_days
        
        try:
            day_numbers = [int(d.strip()) for d in days_input.split(",")]
            # Convert to 0-indexed
            new_days = [d - 1 for d in day_numbers]
            # Validate they're actually available to add
            if all(d in available_to_add for d in new_days):
                # Combine with current days
                updated_days = sorted(set(current_days + new_days))
                break
            else:
                print(f"[ERROR] Can only add: {', '.join([day_names[i] for i in available_to_add])}")
        except ValueError:
            print("[ERROR] Invalid format. Use comma-separated numbers.")
    
    # Save updated availability
    save_to_csv(lecturer_name, updated_days, availability_file)
    
    updated_names = [day_names[i] for i in updated_days]
    print(f"✓ Updated: {lecturer_name} now available on {', '.join(updated_names)}")
    
    return updated_days

def save_to_csv(lecturer_name: str, available_days: List[int], 
                availability_file: str = "lecturer_availability.csv"):
    """
    Save or update lecturer availability in CSV file.
    
    Args:
        lecturer_name: Name of the lecturer
        available_days: List of available day indices (0=Mon, 1=Tue, etc.)
        availability_file: Path to CSV file
    """
    # Create availability row
    availability_row = {
        'lecturer_name': lecturer_name,
        'Mon': 1 if 0 in available_days else 0,
        'Tue': 1 if 1 in available_days else 0,
        'Wed': 1 if 2 in available_days else 0,
        'Thu': 1 if 3 in available_days else 0,
        'Fri': 1 if 4 in available_days else 0
    }
    
    # Load existing CSV or create new
    if os.path.exists(availability_file):
        df = pd.read_csv(availability_file)
        
        # Check if lecturer already exists
        norm_name = normalize_name(lecturer_name)
        existing_idx = None
        for idx, row in df.iterrows():
            if normalize_name(row['lecturer_name']) == norm_name:
                existing_idx = idx
                break
        
        if existing_idx is not None:
            # Update existing row
            for col in ['Mon', 'Tue', 'Wed', 'Thu', 'Fri']:
                df.at[existing_idx, col] = availability_row[col]
        else:
            # Append new row
            df = pd.concat([df, pd.DataFrame([availability_row])], ignore_index=True)
    else:
        # Create new file
        df = pd.DataFrame([availability_row])
    
    # Save to CSV
    df.to_csv(availability_file, index=False)

def check_and_prompt_availability(lecturers_in_input: List[str], 
                                   availability_file: str = "lecturer_availability.csv",
                                   min_days_threshold: int = 3) -> Dict[str, List[int]]:
    """
    Check lecturer availability and prompt for missing or limited entries.
    """
    availability_map = {}
    
    # 1. Ask High-Level Preference: AI vs Manual
    print("\n" + "="*60)
    print("AVAILABILITY MANAGEMENT PREFERENCE")
    print("="*60)
    print("How should the AI handle lecturer availability gaps?")
    print("1. AI Automatic - AI will intelligently expand days to find a solution")
    print("2. Manual Control - Prompt me for every missing or limited lecturer")
    print("="*60)
    
    while True:
        mode_choice = input("Enter choice (1 or 2): ").strip()
        if mode_choice in ["1", "2"]: break
        print("[ERROR] Invalid choice.")
    
    ai_decides = (mode_choice == "1")

    # Load existing availability
    existing_availability = {}
    if os.path.exists(availability_file):
        df = pd.read_csv(availability_file)
        for _, row in df.iterrows():
            name = str(row['lecturer_name']).strip()
            norm_name = normalize_name(name)
            days = []
            for i, day in enumerate(['Mon', 'Tue', 'Wed', 'Thu', 'Fri']):
                if str(row.get(day, 0)) == "1":
                    days.append(i)
            existing_availability[norm_name] = (name, days)
    
    # Check each lecturer
    for lecturer in lecturers_in_input:
        norm_name = normalize_name(lecturer)
        
        if norm_name in existing_availability:
            original_name, days = existing_availability[norm_name]
            
            # Check if availability is limited
            if len(days) < min_days_threshold:
                if ai_decides:
                    # AI expands automatically to all 5 days for this run
                    days = [0, 1, 2, 3, 4]
                else:
                    # Manual prompt to expand or stay limited
                    days = prompt_expand_availability_refined(original_name, days, availability_file)
            
            availability_map[lecturer] = days
        else:
            # Lecturer not found
            if ai_decides:
                # Default to all days for new lecturers
                days = [0, 1, 2, 3, 4]
            else:
                days = prompt_add_lecturer(lecturer, availability_file)
            availability_map[lecturer] = days
    
    return availability_map

def reload_lecturer_availability(lecturer_obj, 
                                  availability_file: str = "lecturer_availability.csv",
                                  slot_ids: List[int] = None):
    """
    Reload a lecturer's available_time_slots from the CSV file and update it.
    
    Args:
        lecturer_obj: data_model.Lecturer object to update
        availability_file: Path to lecturer_availability.csv
        slot_ids: List of time slot IDs available (0-4 for 5 time slots)
    
    Returns:
        Updated set of (day, slot) tuples for available_time_slots
    """
    if not os.path.exists(availability_file):
        print(f"[ERROR] Availability file not found: {availability_file}")
        return lecturer_obj.available_time_slots
    
    # Read CSV to find lecturer's current available days
    df = pd.read_csv(availability_file)
    norm_name = normalize_name(lecturer_obj.name)
    
    available_days = []
    for _, row in df.iterrows():
        if normalize_name(str(row['lecturer_name']).strip()) == norm_name:
            for i, day in enumerate(['Mon', 'Tue', 'Wed', 'Thu', 'Fri']):
                if str(row.get(day, 0)) == "1":
                    available_days.append(i)
            break
    
    # If not found or empty, default to all days
    if not available_days:
        print(f"[WARNING] {lecturer_obj.name} not found in {availability_file}, defaulting to all days")
        available_days = [0, 1, 2, 3, 4]
    
    # Default slot IDs if not provided
    if slot_ids is None:
        slot_ids = list(range(5))  # Standard 5 time slots
    
    # Rebuild available_time_slots set: (day, slot) tuples
    new_slots = set()
    for day in available_days:
        for slot in slot_ids:
            new_slots.add((day, slot))
    
    # Update the lecturer object
    lecturer_obj.available_time_slots = new_slots
    
    day_names = ["Mon", "Tue", "Wed", "Thu", "Fri"]
    selected_names = [day_names[i] for i in available_days]
    print(f"[INFO] Reloaded {lecturer_obj.name} availability: {', '.join(selected_names)} ({len(new_slots)} total slots)")
    
    return new_slots

def prompt_expand_availability_for_scheduling(lecturer_name: str, current_days: List[int],
                                               availability_file: str = "lecturer_availability.csv") -> bool:
    """
    Prompt user during scheduling to expand availability for a specific lecturer.
    This is called when the solver cannot allocate due to insufficient lecturer availability.
    
    Args:
        lecturer_name: Name of the lecturer
        current_days: List of currently available day indices
        availability_file: Path to lecturer_availability.csv
    
    Returns:
        True if availability was expanded, False if user kept it the same
    """
    day_names = ["Mon", "Tue", "Wed", "Thu", "Fri"]
    current_names = [day_names[i] for i in current_days]
    
    print(f"\n{'='*70}")
    print(f"[SCHEDULING ALERT] Cannot allocate '{lecturer_name}' due to limited availability!")
    print(f"{'='*70}")
    print(f"Current availability: {', '.join(current_names)} ({len(current_days)} days)")
    print(f"\nWould you like to expand {lecturer_name}'s availability to find a valid schedule?")
    print("1. Yes - Add more days")
    print("2. No - Keep current availability (scheduling will likely fail)")
    print(f"{'='*70}")
    
    while True:
        choice = input("Enter choice (1 or 2): ").strip()
        if choice in ["1", "2"]:
            break
        print("[ERROR] Invalid choice. Please enter 1 or 2.")
    
    if choice == "2":
        print(f"[INFO] Keeping {lecturer_name}'s availability as-is.")
        return False
    
    # Show available days to add
    available_to_add = [i for i in range(5) if i not in current_days]
    if not available_to_add:
        print("[INFO] Lecturer already available all days!")
        return False
    
    print(f"\nSelect additional days (current: {', '.join(current_names)}):")
    for i in available_to_add:
        print(f"  {i+1}={day_names[i]}")
    print("Example: 1,3,5 or 2,4")
    print("Enter days to add (comma-separated):")
    
    while True:
        days_input = input("Days to add: ").strip()
        if not days_input:
            print("[INFO] No days added.")
            return False
        
        try:
            day_numbers = [int(d.strip()) for d in days_input.split(",")]
            # Convert to 0-indexed
            new_days = [d - 1 for d in day_numbers]
            # Validate they're actually available to add
            if all(0 <= d < 5 and d in available_to_add for d in new_days):
                # Combine with current days
                updated_days = sorted(set(current_days + new_days))
                break
            else:
                invalid = [d for d in new_days if d not in available_to_add]
                print(f"[ERROR] Invalid days: {invalid}. Can only add: {', '.join([day_names[i] for i in available_to_add])}")
        except ValueError:
            print("[ERROR] Invalid format. Use comma-separated numbers (e.g., 1,3,5).")
    
    # Save updated availability
    save_to_csv(lecturer_name, updated_days, availability_file)
    
    updated_names = [day_names[i] for i in updated_days]
    print(f"✓ Expanded: {lecturer_name} now available on {', '.join(updated_names)}")
    print(f"  Resuming scheduling with expanded availability...\n")
    
    return True


def prompt_expand_availability_refined(lecturer_name: str, current_days: List[int], 
                                        availability_file: str = "lecturer_availability.csv") -> List[int]:
    """Refined manual prompt for < 3 days choice."""
    day_names = ["Mon", "Tue", "Wed", "Thu", "Fri"]
    current_names = [day_names[i] for i in current_days]
    
    print(f"\n{'='*60}")
    print(f"[INFO] Lecturer '{lecturer_name}' has limited availability (< 3 days):")
    print(f"  Available: {', '.join(current_names)}")
    print(f"{'='*60}")
    print("Choose an option:")
    print("1. Expand - Add more days manually")
    print("2. Proceed with Limited - AI only uses these specific days")
    print(f"{'='*60}")
    
    while True:
        choice = input("Enter choice (1 or 2): ").strip()
        if choice in ["1", "2"]: break
    
    if choice == "2":
        return current_days
    
    # If choice is 1, reuse the original expansion logic
    return prompt_expand_availability(lecturer_name, current_days, availability_file)

if __name__ == "__main__":
    # Test the system
    print("Testing Lecturer Availability Management")
    print("="*60)
    
    test_lecturers = ["Dr. Test Lecturer", "Prof. Limited Days"]
    result = check_and_prompt_availability(test_lecturers)
    
    print("\n" + "="*60)
    print("Final Availability:")
    for lect, days in result.items():
        day_names = ["Mon", "Tue", "Wed", "Thu", "Fri"]
        print(f"  {lect}: {', '.join([day_names[i] for i in days])}")
