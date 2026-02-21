# diagnostics.py
import pandas as pd
import os
from load_data import normalize_name

def run_health_check(input_csv, availability_csv):
    print("========================================")
    print("   VVU TIMETABLE HEALTH DIAGNOSTICS")
    print("========================================\n")

    if not os.path.exists(input_csv):
        print(f"[ERROR] {input_csv} not found.")
        return

    df = pd.read_csv(input_csv)
    avail_df = pd.read_csv(availability_csv)
    
    # 1. Lecturer Workload Check
    print("1. Lecturer Workload vs. Availability:")
    lect_avail_map = {}
    for _, row in avail_df.iterrows():
        name = normalize_name(str(row["lecturer_name"]))
        avail_count = sum([1 for d in ["Mon", "Tue", "Wed", "Thu", "Fri"] if str(row.get(d, 0)) == "1"]) * 3
        lect_avail_map[name] = avail_count

    for name in df['lecturer_name'].unique():
        needed = len(df[df['lecturer_name'] == name])
        norm_name = normalize_name(name)
        available = lect_avail_map.get(norm_name, 15) # Default to 15 if missing
        
        if needed > available:
            print(f"  [CRITICAL] {name}: Needs {needed} slots, has {available} available.")
        elif needed > (available * 0.8):
            print(f"  [WARNING] {name}: Needs {needed} slots, has {available} available (Tight).")

    # 2. Student Cohort Bottlenecks
    print("\n2. Student Level Analysis (Potential Clashes):")
    # For each level, how many classes do they have?
    level_counts = df.groupby('course_level').size()
    total_slots_in_week = 14 # Mon-Thu (3 slots) + Fri (2 slots)
    
    for level, count in level_counts.items():
        if count > total_slots_in_week:
            print(f"  [CRITICAL] Level {level}00: Has {count} courses but only {total_slots_in_week} slots exist in a week!")
        elif count > (total_slots_in_week * 0.7):
            print(f"  [WARNING] Level {level}00: Has {count} courses. This level is very crowded ({count}/{total_slots_in_week} slots).")

    print("\n========================================")
    print("        DIAGNOSTICS COMPLETE")
    print("========================================")

def generate_conflict_heatmap(variables, domains, day_names):
    """
    Analyzes domain sizes and identifies the 'bottleneck' slots that are 
    most contested by different course sections.
    """
    print("\n" + "-"*60)
    print("PROACTIVE BOTTLENECK ANALYSIS (HEATMAP)")
    print("-"*60)
    
    # Count how many sections can potentially fit into each (day, slot)
    slot_contention = {} # (day, slot) -> count
    
    for var_id in domains:
        seen_in_var = set()
        for day, slot, room_id in domains[var_id]:
            pair = (day, slot)
            if pair not in seen_in_var:
                slot_contention[pair] = slot_contention.get(pair, 0) + 1
                seen_in_var.add(pair)
                
    # Sort slots by contention level
    sorted_slots = sorted(slot_contention.items(), key=lambda x: x[1], reverse=True)
    
    print(f"{'DAY':<10} | {'SLOT':<6} | {'DEMAND (Sections)':<18} | {'STATUS'}")
    print("-" * 60)
    
    for (day, slot), count in sorted_slots[:10]: # Show top 10 most contested
        status = "[CRITICAL]" if count > 5 else "[HIGH]"
        print(f"{day_names[day]:<10} | {slot:<6} | {count:<18} | {status}")
    
    print("-" * 60)
    print("[Tip] If a slot has high demand, consider moving some lecturers' availability.")

if __name__ == "__main__":
    file_to_check = input("Enter the clean CSV to diagnose (e.g., cs_clean_2.csv): ")
    run_health_check(file_to_check, "lecturer_availability.csv")
