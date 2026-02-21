from exam_load_data import load_exam_data
from exam_builder import build_exam_domain
from exam_constraints import make_exam_constraints
from exam_export_data import export_exam_solution
from csp import CSP
import os


def main():
    current_input = input("Enter the path to the exam data CSV file: ").strip()
    if not current_input.endswith(".csv"):
        current_input += ".csv"

    final_output = input("Enter the desired output CSV file path for the exam timetable: ").strip()
    if not final_output.endswith(".csv"):
        final_output += ".csv"

    rooms_path = "csv/general/exam_rooms.csv" if os.path.exists("csv/general/exam_rooms.csv") else "csv/general/rooms.csv"
    if rooms_path == "csv/general/exam_rooms.csv":
        print("[INFO] Using exam-specific room pool: csv/general/exam_rooms.csv")
    else:
        print("[INFO] Using general room pool: csv/general/rooms.csv")

    print("Loading exam data...")
    data = load_exam_data([current_input], rooms_csv_path=rooms_path)

    print("Building exam domains and constraints...")
    domain = build_exam_domain(data)
    max_exams_per_day = data["config"].get("max_exams_per_day_per_cohort", 1)
    max_students_per_hall = int(data["config"].get("max_students_per_hall", 0))
    constraints = make_exam_constraints(
        data["sections"],
        data["rooms"],
        max_exams_per_day=max_exams_per_day,
        max_students_per_hall=max_students_per_hall
    )

    print("Initializing CSP solver for exams...")
    csp = CSP(
        variables=data["sections"],
        domains=domain,
        constraints=constraints,
        lecturers={},
        preferences={}
    )

    print("Solving the exam scheduling problem...")
    solution = csp.solve()
    if solution is None:
        print("\n[Failed] No valid exam timetable found")
        return

    print("Exporting the exam timetable...")
    export_exam_solution(solution, data, out_path=final_output)
    print(f"\nDONE! Exam timetable saved to {final_output}.")


if __name__ == "__main__":
    main()
