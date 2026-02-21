import pandas as pd
import re
import os
import numpy as np
import random

def clean_data(input_csv, output_csv: str, prefixes_csv="general_csv", keywords = "dept.csv"):
    """
    Advanced cleanup script using Anchor Based alignment to fix complex PDF extraction errors

    """
    if not os.path.exists(input_csv):
        return None

    try:
       general_csv = pd.read_csv(prefixes_csv)['prefix'].str.upper().tolist()
       dept_csv = pd.read_csv(keywords)['keywords'].str.upper().tolist()
    except:
        general_csv = ["ENGL", "RELT", "RELB", "RELG", "HLTH", "PEAC", "GNED", "SOCI", "AFST", "FREN", "CMME", "PSYC", "MATH"]
        dept_csv = ["NUMERICAL", "DISCRETE", "COMPUTER", "PROGRAMMING", "DATA", "ANALYSIS"]

    #2. Identify header row and anchor
    df = pd.read_csv(input_csv, header=None)
    header_row_index, col_map = None, {}
    for idx, row in df.iterrows():
        row_str =" ".join(row.dropna().astype(str)).upper()
        if "LECTURER" in row_str and ("COURSE" in row_str or "CODE" in row_str):
            header_row_index = idx
            for col_idx, cell in enumerate(row):
                c_upper = str(cell).upper()
                if "LECTURER" in c_upper: col_map[col_idx] = "lecturer_name"
                elif "COURSE" in c_upper or "CODE" in c_upper: col_map[col_idx] = "course_info"
                elif "CREDIT" in c_upper: col_map[col_idx] = "credit_hours"
                elif "CLASSROM" in c_upper or "ROOM" in c_upper: col_map[col_idx] = "room_name"
                elif "DAYS" in c_upper or "DAY" in c_upper: col_map[col_idx] = "day"
                elif "TIMINGS" in c_upper or "TIME" in c_upper: col_map[col_idx] = "timings"
                elif "SEMESTER" in c_upper or "SEM" in c_upper: col_map[col_idx] = "Semester"
            break
    if header_row_index is None:
        return None
    anchor_col_idx = next((idx for idx, name in col_map.items() if name == "course_info"), None)

    #3. Dynamic Row Alignment
    cleaned_rows = []
    course_pattern = r'[A-Z]{4}\s*\d{3}'
    for _, row in df.iloc[header_row_index + 1:].iterrows():
        row_values = [v if str(v).upper() not in ["GENERAL", "DEPARTMENT", "NAN", ""] else np.nan for v in row]
        actual_code_idx = next((i for i, val in enumerate(row_values) if re.search(course_pattern, str(val))), None)

        if actual_code_idx is None: continue
        offset = actual_code_idx - anchor_col_idx
        aligned_row = [np.nan] * len(row_values)
        for i, val in enumerate(row_values):
            target_idx = i - offset
            if 0 <= target_idx < len(row_values):
                aligned_row[target_idx] = val
        row_data = {col_name : aligned_row[col_idx] for col_idx, col_name in col_map.items() if col_idx < len(aligned_row)}
        cleaned_rows.append(row_data)

    df_cleaned = pd.DataFrame(cleaned_rows).dropna(subset=['course_info', 'lecturer_name'], how='all')

    #4. Splitting & Categorization
    def split_info(info):
            m = re.match(r'^([A-Z]{4}\s*\d{3})\s*(.*)$', str(info).strip())
            return pd.Series([m.group(1), m.group(2)]) if m else pd.Series([info, ""])

    df_cleaned[['course_code', 'course_title']] = df_cleaned['course_info'].apply(split_info)

    if "timings" in df_cleaned.columns:
        df_cleaned[['start_time', 'end_time']] = df_cleaned['timings'].apply(lambda t: pd.Series(str(t).replace(" ", "").replace(".", ":").split("-")[:2] if "-" in str(t) else pd.Series([None, None])))
    df_cleaned["source_type"] = df_cleaned.apply(lambda r: "Departmental" if not any(p in str(r["course_code"]).upper() for p in general_csv) or any(k in str(r["course_title"]).upper() for k in dept_csv) else "General", axis=1)
    df_cleaned["course_level"] = df_cleaned["course_code"].apply(lambda c: int(re.search(r'\d', str(c)).group(0)) if re.search(r'\d', str(c)) else 0)

    # Ensure Semester column exists
    if "Semester" not in df_cleaned.columns:
        df_cleaned["Semester"] = ""
        
    # Fill missing/empty semester with random 1 or 2
    def fill_random_semester(val):
        s = str(val).strip()
        if not s or s.lower() == 'nan':
            return str(random.choice([1, 2]))
        return s
        
    df_cleaned["Semester"] = df_cleaned["Semester"].apply(fill_random_semester)

    #5. Merging & Exporting
    groups_cols = ['lecturer_name', 'day', 'room_name', 'start_time']

    def merge_block(group):
        res = group.iloc[0].copy()
        res['course_code'] = " / ".join(group['course_code'].unique())
        res['course_title'] = " / ".join(group['course_title'].unique())
        # Preserve Semester if it exists in group (take first non-empty)
        # Since we filled all above, just take the first one or unique ones joined if they differ (unlikely/undesirable?)
        # Let's take the first one to avoid "1/2"
        res['Semester'] = group['Semester'].iloc[0]
        return res
    df_final = df_cleaned.groupby(groups_cols, as_index=False, dropna=False).apply(lambda g: merge_block(g) if len(g) > 1 else g.iloc[0],
                                                                                   include_groups=False)
    
    # Reorder columns and exclude course_info
    cols = ['course_code', 'course_title', 'lecturer_name', 'Semester',  'source_type', 'course_level']
    # Add any other cols that exist, EXCLUDING course_info
    for c in df_final.columns:
        if c not in cols and c != 'course_info':
            cols.append(c)
    df_final = df_final[cols]


    df_final.to_csv(output_csv, index=False)
    print(f"Cleanup complete: {len(df_final)} sessions saved to {output_csv}")
    return df_final

if __name__ == "__main__":
    clean_data(input("Enter raw CSV: "), input("Enter clean CSV: "))
