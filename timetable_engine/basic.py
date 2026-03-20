# basic.py

import tabula
import pandas as pd
import os
import sys

# Import clean_data from clean_up.py
sys.path.append(os.path.dirname(__file__))
from clean_up import clean_data

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
    dfs = tabula.read_pdf(pdf_file, pages="all", multiple_tables=True, lattice=True)
    if dfs:
        all_data = pd.concat(dfs, ignore_index=True)
        # Save raw CSV
        raw_csv = "raw.csv"
        all_data.to_csv(raw_csv, index=False)
        print(f"Success! Raw data saved to {raw_csv}.")

        # Automatically process and save to clean output
        clean_csv = input("Enter filename to save CLEANED CSV (e.g., clean/clean_output.csv): ")
        print(f"Cleaning and saving to {clean_csv}...")
        clean_data(raw_csv, clean_csv)
        print(f"Cleaned data saved to {clean_csv}")
    else:
        print("No tables found in PDF.")

if __name__ == "__main__":
    run_extraction()
