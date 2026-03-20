
import os
import pandas as pd
from unittest.mock import patch
from load_data import load_combined_data, load_general_schedule_blocks

def test_shared_locking():
    # Mock check_and_prompt_availability to avoid interactive prompt
    with patch("manage_availability.check_and_prompt_availability"):
        print("--- Testing Shared Course Locking Logic ---")
        
        # 1. Create a mock block file (schedule_bio1.csv)
        # Note: Using the column names exactly as provided in the user's snippet
        block_data = {
            'Course Code': ['COSC 113'],
            'Course Title': ['Elements of Programming [SEC A]'],
            'Room Name': ['BME1'],
            'Day': ['Thursday'],
            'start_time': ['2:00 PM'],
            'course_level': [100], 
            'Semester': [1]
        }
        block_df = pd.DataFrame(block_data)
        block_file = "temp_test_block.csv"
        block_df.to_csv(block_file, index=False)
        
        # 2. Create a mock courses file (departmental)
        courses_data = {
            'course_code': ['COSC 113', 'COSC 114'],
            'course_title': ['Elements of Programming', 'Other Course'],
            'lecturer_name': ['R. Amponsah', 'Dr. Smith'],
            'enrollment': [50, 40],
            'course_level': [1, 1],
            'Semester': [1, 1]
        }
        courses_file = "temp_test_courses.csv"
        pd.DataFrame(courses_data).to_csv(courses_file, index=False)
        
        try:
            # Load blocks
            print(f"Loading blocks from {block_file}...")
            blocks = load_general_schedule_blocks(block_file, semester="1")
            print(f"Loaded {len(blocks)} blocks.")
            for b in blocks:
                print(f"  Block: {b}")
                
            # Verify COSC 113 block info
            cosc_block = next((b for b in blocks if b['course_code'] == 'COSC 113'), None)
            assert cosc_block is not None, "COSC 113 block not found!"
            assert cosc_block['day'] == 3, f"Expected Day 3 (Thursday), got {cosc_block['day']}"
            assert cosc_block['room_name'] == 'BME1', f"Expected room BME1, got {cosc_block['room_name']}"
            
            # Load combined data
            print("\nLoading combined data with blocks...")
            data = load_combined_data(
                paths=[courses_file],
                blocked_blocks=blocks
            )
            sections = data["sections"]
            
            print(f"Loaded {len(sections)} sections.")
            cosc_sec = next((s for s in sections if s.course_code == 'COSC 113'), None)
            assert cosc_sec is not None, "COSC 113 section not found!"
            
            print(f"COSC 113 Section Info:")
            print(f"  Fixed Day: {cosc_sec.fixed_day}")
            print(f"  Fixed Slot: {cosc_sec.fixed_slot}")
            print(f"  Requested Room: {cosc_sec.requested_room}")
            print(f"  Cohorts: {cosc_sec.cohorts}")
            
            # Assertions for smart locking
            assert cosc_sec.fixed_day == 3, "COSC 113 Day not locked correctly!"
            assert cosc_sec.requested_room == 'BME1', "COSC 113 Room not locked correctly!"
            
            # Verify that another section in the SAME cohort does NOT get locked to that slot
            other_sec = next((s for s in sections if s.course_code == 'COSC 114'), None)
            assert other_sec.fixed_day is None, "COSC 114 should NOT be locked!"
            
            print("\n[SUCCESS] Shared course locking verified!")
            
        finally:
            # Cleanup
            if os.path.exists(block_file): os.remove(block_file)
            if os.path.exists(courses_file): os.remove(courses_file)

if __name__ == "__main__":
    test_shared_locking()
