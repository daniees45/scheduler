
import pandas as pd
import os
import shutil

def categorize_courses(input_file: str, course_type: str) -> str:
    """
    Appends the content of input_file to the target CSV (general_courses.csv or departmental_courses.csv).
    Prevents duplicates by checking existing course_code.
    Uses only the 6 essential columns: course_code, course_title, lecturer_name, source_type, course_level, credit_hours
    """
    
    target_file = "general_courses.csv" if course_type == "General" else "departmental_courses.csv"
    required_cols = ['course_code', 'course_title', 'lecturer_name', 'source_type', 'course_level', 'credit_hours']
    
    # 1. Load Input
    if not os.path.exists(input_file):
        raise FileNotFoundError(f"{input_file} not found")
        
    new_df = pd.read_csv(input_file)
    
    # Check required columns
    missing_cols = [col for col in required_cols if col not in new_df.columns]
    if missing_cols:
        raise ValueError(f"Input file must have these columns: {required_cols}. Missing: {missing_cols}")
    
    # Keep only essential columns
    new_df = new_df[required_cols].copy()
    
    # 2. Load Existing Target (if exists)
    if os.path.exists(target_file) and os.path.getsize(target_file) > 1:
        try:
            existing_df = pd.read_csv(target_file)
            existing_codes = set(existing_df['course_code'].astype(str).str.strip().str.upper())
        except pd.errors.EmptyDataError:
            existing_df = pd.DataFrame()
            existing_codes = set()
    else:
        existing_df = pd.DataFrame()
        existing_codes = set()

        
    # 3. Filter Duplicates
    # We strip and upper() course codes for comparison
    new_df['temp_code'] = new_df['course_code'].astype(str).str.strip().str.upper()
    
    unique_df = new_df[~new_df['temp_code'].isin(existing_codes)].copy()
    num_duplicates = len(new_df) - len(unique_df)
    
    # Cleanup temp column
    if 'temp_code' in unique_df.columns:
        unique_df = unique_df.drop(columns=['temp_code'])
        
    if num_duplicates > 0:
        print(f"[INFO] Skipped {num_duplicates} duplicate courses already in {target_file}")
        
    if unique_df.empty:
        print(f"[INFO] No new courses to append.")
        return target_file
        
    # 4. Append
    # If file didn't exist, just save
    if existing_df.empty:
        unique_df.to_csv(target_file, index=False)
    else:
        # Append without header
        unique_df.to_csv(target_file, mode='a', header=False, index=False)
        
    print(f"[SUCCESS] Appended {len(unique_df)} new courses to '{target_file}'")
    
    return target_file
