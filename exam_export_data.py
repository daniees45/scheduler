import csv


def export_exam_solution(solution, data, out_path: str):
    days = data["config"]["days"]
    slot_times = data["config"].get("slot_times", {})
    sections_by_id = {sec.id: sec for sec in data["sections"]}
    courses = data["courses"]
    rooms = data["rooms"]
    group_by_course = data["config"].get("group_sections_by_course", False)

    rows = []
    rows.append([
        "Course Code",
        "Course Title",
        "Invigilator",
        "No of Students",
        "Level",
        "Cohorts",
        "Room",
        "Day",
        "Time"
    ])

    for sec_id, (day_idx, slot_idx, room_id) in solution.items():
        sec = sections_by_id[sec_id]
        course = courses[sec.course_code]
        room = rooms[room_id]

        day_name = days[day_idx]
        slot_time = slot_times.get(slot_idx, (str(slot_idx), ""))
        time_label = f"{slot_time[0]} - {slot_time[1]}" if slot_time else str(slot_idx)

        # If grouped by course, note it in title
        if group_by_course:
            title = f"{sec.section_title} (All Sections)"
        else:
            title = sec.section_title

        rows.append([
            course.code,
            title,
            sec.lecturer_id,
            sec.enrollment,
            sec.course_level,
            "; ".join(sorted(sec.cohorts)),
            room.name,
            day_name,
            time_label
        ])

    with open(out_path, "w", newline="", encoding="utf-8") as f:
        writer = csv.writer(f)
        writer.writerows(rows)
    print(f"Exam timetable exported to {out_path}")
