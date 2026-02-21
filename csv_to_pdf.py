import csv
import os
import sys
import argparse

# Try to import fpdf, print helpful error if missing
try:
    from fpdf import FPDF
    from fpdf.enums import XPos, YPos
except ImportError:
    print("Error: The 'fpdf2' library is required to generate PDFs.")
    print("Please install it by running: pip install fpdf2")
    sys.exit(1)

class TimetablePDF(FPDF):
    def __init__(self, orientation='L', custom_headers=None):
        super().__init__(orientation=orientation)
        self.custom_headers = custom_headers or []
        
    def header(self):
        # We handle the header manually inside the table logic to keep it integrated with the grid
        return

    def footer(self):
        self.set_y(-15)
        self.set_font('Helvetica', 'I', 8)
        self.set_text_color(0, 0, 0) # Black for footer
        self.cell(0, 10, f'Page {self.page_no()}/{{nb}}', border=0, 
                  new_x=XPos.RIGHT, new_y=YPos.TOP, align='C')

def create_pdf(csv_input, pdf_output, custom_headers=None):
    """
    Convert CSV schedule to formatted PDF.
    Auto-detects format (Exam vs Class) based on CSV headers.
    
    Args:
        csv_input: Path to input CSV file
        pdf_output: Path to output PDF file
        custom_headers: List of 4 custom header lines (optional)
    """
    if not os.path.exists(csv_input):
        print(f"Error: {csv_input} not found.")
        return False

    # Detect CSV format by checking headers for "Invigilator" column
    is_exam_format = False
    with open(csv_input, 'r', encoding='utf-8-sig') as f:
        reader = csv.reader(f)
        headers = next(reader, [])
        headers_lower = [h.strip().lower() for h in headers]
        is_exam_format = 'invigilator' in headers_lower

    # PDF Configuration
    pdf = TimetablePDF(orientation='L', custom_headers=custom_headers)
    pdf.alias_nb_pages()
    pdf.add_page()
    
    # Define Columns and Widths based on format detection
    if is_exam_format:
        # Exam format: Course Code, Course Title, Invigilator, No of Students, Level, Cohorts, Room, Day, Time
        columns = [
            # ("COURSE CODE", 30),
            ("COURSE TITLE", 120),
            ("INVIGILATOR", 45),
            ("STUDENTS", 22),
            ("EXAM HALL", 35),
            ("DAY", 23),
            ("TIME", 32)
        ]
        mapping = {
            # "COURSE CODE": "Course Code",
            "COURSE TITLE":  "Course Title" ,
            "INVIGILATOR": "Invigilator",
            "STUDENTS": "No of Students",
            "EXAM HALL": "Room",
            "DAY": "Day",
            "TIME": "Time"
        }
    else:
        # Class format: Course Code, Course Title, Credit Hrs, Lecturer Name, Room Name, Day, Time
        columns = [
            ("LECTURER", 55),
            ("COURSE CODE & TITLE", 91),
            ("CREDIT HRS", 21),
            ("CLASSROOM", 40),
            ("DAYS", 25),
            ("TIMINGS", 45)
        ]
        mapping = {
            "LECTURER": "Lecturer Name",
            "COURSE CODE & TITLE": "Course Code & Title",
            "CREDIT HRS": "Credit Hrs",
            "CLASSROOM": "Room Name",
            "DAYS": "Day",
            "TIMINGS": "Time"
        }
    
    total_w = sum(w for _, w in columns)

    # --- TOP HEADER SECTION (Integrated into Grid) ---
    pdf.set_font('Helvetica', 'B', 12)
    pdf.set_text_color(0, 0, 0) # Black for headers
    
    # Use custom headers if provided, otherwise use format-specific defaults
    if custom_headers and len(custom_headers) >= 4:
        headers = custom_headers[:4]
    else:
        if is_exam_format:
            headers = [
                "VALLEY VIEW UNIVERSITY",
                "EXAMINATION TIMETABLE",
                "SECOND SEMESTER - 2025 / 2026 ACADEMIC YEAR",
                "EXAM SCHEDULE"
            ]
        else:
            headers = [
                "VALLEY VIEW UNIVERSITY",
                "COMPUTER SCIENCE, INFORMATION TECHNOLOGY, BUSINESS INFORMATION SYSTEMS AND MATHEMATICAL SCIENCES",
                "SECOND SEMESTER - 2025 / 2026 ACADEMIC YEAR",
                "TEACHING TIMETABLE"
            ]
    
    for h_line in headers:
        pdf.cell(total_w, 10, h_line, border=1, align='C', new_x=XPos.LMARGIN, new_y=YPos.NEXT)
    
    # --- COLUMN HEADERS ---
    pdf.set_font('Helvetica', 'B', 10)
    for name, width in columns:
        pdf.cell(width, 10, name, border=1, align='C', new_x=XPos.RIGHT, new_y=YPos.TOP)
    pdf.ln()

    # Data Source Loading
    with open(csv_input, 'r', encoding='utf-8-sig') as f:
        reader = csv.DictReader(f)
        source_rows = list(reader)
    
    # Process rows based on format
    if is_exam_format:
        # Exam format: Use rows as-is, no expansion needed
        expanded_rows = source_rows
        # Sort by Day, then Time, then Course Code
        expanded_rows.sort(key=lambda x: (x.get("Day", ""), x.get("Time", ""), x.get("Course Code", "")))
        sort_key = "Day"  # Group by day for exams
    else:
        # Class format: Row Expansion Logic for combined courses
        expanded_rows = []
        for row in source_rows:
            codes = row.get("Course Code", "").split(" / ")
            titles = row.get("Course Title", "").split(" / ")
            num_parts = max(len(codes), len(titles))
            for i in range(num_parts):
                new_row = row.copy()
                c = codes[i].strip() if i < len(codes) else codes[-1].strip()
                t = titles[i].strip() if i < len(titles) else titles[-1].strip()
                new_row["Course Code & Title"] = f"{c} - {t}"
                expanded_rows.append(new_row)
        # Sort by Lecturer
        expanded_rows.sort(key=lambda x: x.get("Lecturer Name", ""))
        sort_key = "Lecturer Name"
    
    pdf.set_font('Helvetica', '', 9)
    current_group = None
    
    for row in expanded_rows:
        group_value = row.get(sort_key, "")
        
        # Add blank separator row between groups
        if current_group is not None and group_value != current_group:
            # Draw blank row with borders
            pdf.set_fill_color(255, 255, 255)
            for _, width in columns:
                pdf.cell(width, 4, "", border=1, new_x=XPos.RIGHT, new_y=YPos.TOP)
            pdf.ln()
            
            # Check for page break
            if pdf.get_y() > 180:
                pdf.add_page()
                # Repeat Column Headers on New Page
                pdf.set_font('Helvetica', 'B', 10)
                pdf.set_text_color(0, 0, 0)
                for name, width in columns:
                    pdf.cell(width, 10, name, border=1, align='C', new_x=XPos.RIGHT, new_y=YPos.TOP)
                pdf.ln()
                pdf.set_font('Helvetica', '', 9)
            
        current_group = group_value
        row_height = 8

        # --- DATA ROW COLOR: RED ---
        pdf.set_text_color(255, 0, 0) 

        for col_name, width in columns:
            csv_key = mapping[col_name]
            text = str(row.get(csv_key, ""))
            
            # Alignment Logic based on format
            if is_exam_format:
                # Exam format: Left align text columns, center others
                align = 'L' if col_name in ["COURSE TITLE", "INVIGILATOR", "COHORTS"] else 'C'
            else:
                # Class format: Left for lecturer and course, center for others
                align = 'L' if col_name in ["LECTURER", "COURSE CODE & TITLE"] else 'C'
            
            # Padding for Left Alignment
            display_text = f" {text}" if align == 'L' else text
            
            # Truncate
            max_chars = int(width * 0.9)
            if len(display_text) > max_chars:
                display_text = display_text[:max_chars-3] + "..."
            
            pdf.cell(width, row_height, display_text, border=1, 
                        new_x=XPos.RIGHT, new_y=YPos.TOP, align=align)
        
        pdf.ln()
    
    # Reset color for final status
    pdf.set_text_color(0, 0, 0)
    # Output the PDF
    try:
        pdf.output(pdf_output)
        print(f"Success! Timetable converted to {pdf_output}")
        return True
    except Exception as e:
        print(f"Error saving PDF: {e}")
        return False


if __name__ == "__main__":
    # Support both old-style args and new argparse format
    parser = argparse.ArgumentParser(description='Convert CSV schedule to PDF with auto-format detection')
    parser.add_argument('--input', '-i', dest='input_csv', help='Input CSV file path')
    parser.add_argument('--output', '-o', dest='output_pdf', help='Output PDF file path')
    parser.add_argument('--h1', default="", help='Header line 1 (auto-detected if not provided)')
    parser.add_argument('--h2', default="", help='Header line 2 (auto-detected if not provided)')
    parser.add_argument('--h3', default="", help='Header line 3 (auto-detected if not provided)')
    parser.add_argument('--h4', default="", help='Header line 4 (auto-detected if not provided)')
    
    # Also support positional arguments for backward compatibility
    parser.add_argument('positional_input', nargs='?', help='Input CSV file (positional)')
    parser.add_argument('positional_output', nargs='?', help='Output PDF file (positional)')
    
    args = parser.parse_args()
    
    # Determine input and output paths
    default_input = "vvu_final_4.csv"
    default_output = "vvu_final_timetable.pdf"
    
    in_csv = args.input_csv or args.positional_input
    out_pdf = args.output_pdf or args.positional_output
    
    # Interactive mode if no args provided
    if not in_csv:
        print("CSV to PDF Converter with Auto-Format Detection")
        in_csv = input(f"Enter input CSV [{default_input}]: ").strip() or default_input
    
    if not out_pdf:
        out_pdf = input(f"Enter output PDF [{default_output}]: ").strip() or default_output
    
    if not out_pdf.endswith(".pdf"):
        out_pdf += ".pdf"
    
    # Extract custom headers (only if all 4 are provided)
    custom_headers = None
    if args.h1 and args.h2 and args.h3 and args.h4:
        custom_headers = [args.h1, args.h2, args.h3, args.h4]
    
    # Generate PDF
    success = create_pdf(in_csv, out_pdf, custom_headers)
    sys.exit(0 if success else 1)
