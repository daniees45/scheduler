# basic.py
import tabula
import pandas as pd
import os

def run_extraction():
    """
    Extracts tables from a user-specified PDF into a single raw CSV.
    """
    print("VVU Table Extractor")
    pdf_file = input("Enter the PDF filename (e.g., gen_vvu.pdf): ")

    if not os.path.exists(pdf_file):
        print(f"Error: {pdf_file} not found.")
        return

    print(f"Extracting tables from {pdf_file}...")
    # lattice=True is best for recognizing grid lines in VVU timetables
    dfs = tabula.read_pdf(pdf_file, pages="all", multiple_tables=True, lattice=True)
    
    if dfs:
        all_data = pd.concat(dfs, ignore_index=True)
        out_csv = input("Save to :")
        all_data.to_csv(out_csv, index=False)
        print(f"Success! Raw data saved to {out_csv}")
    else:
        print("No tables found in PDF.")

if __name__ == "__main__":
    run_extraction()
