import csv

SLOT_TIME = {
    0: ("7:00 AM", "9:30 AM"),
    1: ("10:00 AM", "12:30 PM"),
    2: ("2:00 PM", "4:30 PM"),
    3: ("5:00 PM", "6:00 PM")
}




def export_solution(solution, data, out_path : str):
    """
    Exports the scheduling solution to a CSV file.
    """
    days = data["config"]["days"]
    sections_by_id = {sec.id: sec for sec in data["sections"]}
    courses = data["courses"]
    lecturers = data["lecturers"]
    rooms = data["rooms"]
    
    rows = []
    
    rows.append(["Course Code", 
                 "Course Title", "Credit Hrs", "Lecturer Name", "Room Name", "Day", "Time", 
                 "course_level", "Semester", "start_time", "no_of_students", "enrollment"])
    
    # Check if the solution is from AIUnifiedScheduler (list of dicts)
    if isinstance(solution, list):
        for item in solution:
            rows.append([
                item.get("course_code", ""),
                item.get("course_title", item.get("course_code", "")),
                item.get("credits", "3"),
                item.get("lecturer", ""),
                item.get("room", ""),
                item.get("day", ""),
                item.get("time_slot", ""),
                item.get("level", ""),
                item.get("semester", ""),
                item.get("time_slot", "").split("-")[0].strip(),
                item.get("enrollment", 30),
                item.get("enrollment", 30)
            ])
    else:
        # Iterate over the standard CSP solution and build rows
        for sec_id, (day_idx, slot_idx, room_id) in solution.items():
            sec = sections_by_id[sec_id]
            course = courses[sec.course_code]
            lecturer = lecturers.get(sec.lecturer_id)
            room = rooms.get(room_id)
            
            room_name = room.name if room else f"{room_id}"
            lecturer_name = lecturer.name if lecturer else f"{sec.lecturer_id}"
            
            day_name = days[day_idx]
            start_time, end_time = SLOT_TIME[slot_idx]
            
            rows.append([
                course.code,
                sec.section_title,
                course.credit_hours,
                lecturer_name,
                room_name,
                day_name,
                f"{start_time} - {end_time}",
                sec.course_level,  # Add course level for blocking
                sec.semester if sec.semester else "",  # Add semester for filtering
                start_time,  # Add start_time separately for parsing
                sec.enrollment,  # no_of_students for exam loader
                sec.enrollment   # enrollment for backward compatibility
            ])

        
    #Write to CSV
    with open(out_path, "w", newline='', encoding='utf-8') as f:
        writer = csv.writer(f)
        writer.writerows(rows)
    print(f"Schedule exported to {out_path}")