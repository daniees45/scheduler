from main_web import run_headless
print("Running headless...")
try:
    run_headless("temp/csv/general/courses_input.csv", 2, "csv/general/schedule_test.csv", 1)
except Exception as e:
    import traceback
    traceback.print_exc()
