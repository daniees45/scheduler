import csv
import os

def repair_csv(file_path):
    if not os.path.exists(file_path):
        print(f"File {file_path} not found.")
        return

    new_rows = []
    # Desired header for 7 columns
    target_header = ["Course Code", "Course Title", "Credit Hrs", "Lecturer Name", "Room Name", "Day", "Time"]
    
    with open(file_path, 'r', encoding='utf-8') as f:
        # Use a raw reader to handle potential line noise
        reader = csv.reader(f)
        rows = list(reader)

    if not rows:
        return

    header = rows[0]
    
    # If the first row is already the 7-column header, check others
    # Otherwise, update it
    
    for i, row in enumerate(rows):
        if i == 0:
            new_rows.append(target_header)
            continue
            
        if len(row) == 6:
            # Old format: Code, Title, Lecturer, Room, Day, Time
            # New format: Code, Title, CR, Lecturer, Room, Day, Time
            # Insert empty CR at index 2
            row.insert(2, "NC")
            new_rows.append(row)
        elif len(row) == 7:
            # Already matches new format
            new_rows.append(row)
        else:
            # Skip or log malformed rows
            print(f"Skipping malformed row at line {i+1}: {row}")

    with open(file_path, 'w', newline='', encoding='utf-8') as f:
        writer = csv.writer(f)
        writer.writerows(new_rows)
    
    print(f"Successfully repaired {file_path}. All rows normalized to 7 columns.")

if __name__ == "__main__":
    repair_csv("historical_schedule.csv")
