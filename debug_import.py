import sys
import os
import traceback

sys.path.append(os.getcwd())

try:
    from main_web import run_headless
    print("Successfully imported run_headless")
except Exception:
    traceback.print_exc()
