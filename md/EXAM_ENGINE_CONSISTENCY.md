# Exam Engine Consistency Documentation

## Overview
This document describes the complete parameter flow and consistency between PHP frontend and Python exam scheduler backend with real-time progress tracking.

---

## Parameter Flow: PHP → Python

### 1. **PHP Frontend (generate.php)**

#### Payload Structure Sent to `/generate/exam` Endpoint:
```javascript
{
  job_id: "exam_1234567890",
  use_session: true,
  csv_content: [...],          // Exam courses data
  csv_filename: "exam_courses.csv",
  
  // Exam-specific parameters
  exam_hall_name: "CAF UPSTAIRS",     // First hall name
  exam_halls: ["CAF UPSTAIRS", ...],  // Array of hall names
  exam_hall_capacity: 400,             // First hall capacity
  exam_hall_capacities: [400, ...],   // Array of capacities
  
  // General scheduling parameters
  department: "General",               // Department filter
  course_type: "Departmental",        // Course type
  semester: 1,                        // Semester number
  availability_mode: "2",             // Strict mode for exams
  exam_mode: true,                    // Enable exam mode
  output_filename: "exam_schedule"    // Output file name
}
```

---

### 2. **Flask Backend (app.py)**

#### Endpoint Handler: `/generate/exam`
```python
@app.route('/generate/exam', methods=['POST'])
def generate_exam():
    data = request.json or {}
    job_id = data.get('job_id', 'exam_' + str(int(time.time())))
    
    # Parameter extraction (PHP → Python mapping)
    department = data.get('department')           # ✓ Direct mapping
    hall_name = data.get('exam_hall_name')        # ✓ Maps to hall_name
    hall_capacity = data.get('exam_hall_capacity') # ✓ Maps to hall_capacity
    
    # Progress callback for real-time updates
    def _exam_progress(percent: int, message: str, placed: int = 0):
        save_progress(job_id, "running", percent, placed, message)
    
    # Call exam scheduler with progress tracking
    success = run_headless_exam(
        input_path,
        output_path,
        department=department,
        hall_name=hall_name,
        hall_capacity=hall_capacity,
        progress_callback=_exam_progress  # ✓ Real-time progress
    )
```

**Parameter Mapping Table:**

| PHP Parameter         | Python Parameter  | Type    | Purpose                          |
|-----------------------|-------------------|---------|----------------------------------|
| `exam_hall_name`      | `hall_name`       | string  | Exam hall name                   |
| `exam_hall_capacity`  | `hall_capacity`   | int     | Hall capacity override           |
| `department`          | `department`      | string  | Department filter for rooms      |
| `csv_content`         | Input CSV         | array   | Exam course data                 |
| `output_filename`     | `output_file`     | string  | Output schedule filename         |

---

### 3. **Exam Scheduler (exam_main_web.py)**

#### Function Signature:
```python
def run_headless_exam(
    input_file: str,           # CSV file path
    output_file: str,          # Output CSV path
    department: str | None = None,      # Department filter
    hall_name: str | None = None,       # Single hall name
    hall_capacity: int | None = None,   # Hall capacity override
    progress_callback: Optional[Callable] = None  # ✓ NEW: Progress tracking
) -> bool:
```

#### Progress Tracking Implementation:
```python
def _emit_progress(percent: int, message: str):
    """Helper to emit progress updates"""
    if progress_callback:
        progress_callback(percent, message, 0)

# Progress milestones during execution:
_emit_progress(5,   "Initializing exam scheduler...")
_emit_progress(10,  "Loading exam configuration...")
_emit_progress(20,  "Loading exam data and rooms...")
_emit_progress(35,  "Validating exam data...")
_emit_progress(45,  "Building exam domains...")
_emit_progress(50,  "Preparing exam constraints...")
_emit_progress(55,  "Initializing CSP solver...")
_emit_progress(60,  "AI solving exam constraints...")
# ... CSP solver increments 60% → 95%
_emit_progress(96,  "Exporting exam schedule...")
_emit_progress(98,  "Updating exam history...")
_emit_progress(100, "Exam schedule generated successfully")
```

#### CSP Solver Progress:
```python
# Track solver progress (60% → 95%)
solve_percent = [60]
def _solver_progress(msg: str):
    solve_percent[0] = min(95, solve_percent[0] + 1)
    _emit_progress(int(solve_percent[0]), "AI solving exam constraints...")

solver = CSP(
    variables=data["sections"],
    domains=domain,
    constraints=constraints,
    lecturers={},
    preferences={},
    progress_callback=_solver_progress,  # ✓ CSP reports progress
    timeout_seconds=120
)
```

---

### 4. **Configuration Files**

#### exam_config.json (Default Configuration)
```json
{
  "days": ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday"],
  "slots_per_day": 2,
  "strict_capacity": true,
  "slot_times": {
    "0": ["9:00 AM", "12:00 PM"],
    "1": ["2:00 PM", "5:00 PM"]
  },
  "max_exams_per_day_per_cohort": 2,
  "cohort_mode": "level_semester",
  "single_room": false,
  "default_room_name": null,
  "max_students_per_hall": 300,
  "friday_only_first_slot": true,
  "group_sections_by_course": true
}
```

**Configuration Overrides:**
When `hall_name` is provided, the system automatically applies:
```python
exam_config_overrides = {
    "single_room": True,
    "default_room_name": hall_name
}
```

When `hall_capacity` is provided, room capacity is overridden:
```python
rooms_override = {hall_name: hall_capacity}
```

---

## Progress Tracking Flow

### 1. **PHP Progress Polling (generate.php)**
```javascript
// Poll progress every 1 second
pollInterval = setInterval(async () => {
    const p = await apiGet('/progress?t=' + new Date().getTime());
    if (p.status === 'running' && p.percent) {
        document.getElementById('progressBar').style.width = p.percent + '%';
        document.getElementById('progressStats').innerText = 
            `${p.percent}% - ${p.message}`;
    }
}, 1000);
```

### 2. **Flask Progress Endpoint (app.py)**
```python
@app.route('/progress', methods=['GET'])
def get_progress():
    if os.path.exists(PROGRESS_FILE):
        with open(PROGRESS_FILE, 'r') as f:
            data = json.load(f)
            return jsonify(data)
    return jsonify({"status": "idle", "percent": 0}), 200
```

### 3. **Progress File (ai_progress.json)**
```json
{
  "job_id": "exam_1234567890",
  "status": "running",
  "percent": 75,
  "placed": 0,
  "message": "AI solving exam constraints...",
  "timestamp": "2026-02-20T15:30:45.123456"
}
```

---

## Constraint Consistency

### Active Constraints (exam_constraints.py)

1. **No Multiple Exams Same Day** (Configurable)
   - Controlled by: `max_exams_per_day_per_cohort` in exam_config.json
   - Default: `2` (allows 2 exams per day per cohort)
   - Set to `0` for unlimited exams per day

2. **Room Capacity Overflow** (Always Active)
   - Controlled by: `max_students_per_hall` in exam_config.json
   - Default: `300` students per hall
   - Ensures total enrollment in concurrent exams ≤ hall capacity

3. **Student Cohort Conflict** (DISABLED)
   - Status: Commented out in `make_exam_constraints()`
   - Reason: Level 100 Semester 1 and 2 allowed to overlap
   - Code: Line 110 in exam_constraints.py is commented

### Constraint Configuration Matrix

| Constraint              | Status   | Config Parameter               | Default Value |
|-------------------------|----------|--------------------------------|---------------|
| Cohort Conflict         | DISABLED | N/A (commented out)            | N/A           |
| Max Exams Per Day       | ACTIVE   | max_exams_per_day_per_cohort   | 2             |
| Room Capacity Overflow  | ACTIVE   | max_students_per_hall          | 300           |

---

## Testing Instructions

### 1. **Test Progress Bar Updates**

#### Step 1: Start Flask Backend
```bash
cd /Applications/XAMPP/xamppfiles/htdocs/vvu-scheduler
python3 app.py
```
Expected output:
```
[INFO] Flask server running on http://127.0.0.1:5000
```

#### Step 2: Access Generate Page
```
http://localhost/web/generate.php
```

#### Step 3: Generate Exam Schedule
1. Select "Exam Timetable" mode
2. Upload exam CSV or select saved timetable
3. Enter exam hall name: `CAF UPSTAIRS`
4. Enter hall capacity: `400`
5. Click "Generate Exam Timetable"

#### Step 4: Observe Progress Bar
Watch the progress bar increment through these stages:
- 5%: Initializing exam scheduler...
- 10%: Loading exam configuration...
- 20%: Loading exam data and rooms...
- 35%: Validating exam data...
- 45%: Building exam domains...
- 50%: Preparing exam constraints...
- 55%: Initializing CSP solver...
- 60-95%: AI solving exam constraints... (increments during solving)
- 96%: Exporting exam schedule...
- 98%: Updating exam history...
- 100%: Exam schedule generated successfully

### 2. **Test Parameter Consistency**

#### Verify PHP Sends Correct Parameters:
Open browser console (F12) and check network tab:
```javascript
// Request Payload
{
  "exam_hall_name": "CAF UPSTAIRS",
  "exam_hall_capacity": 400,
  "department": "General",
  ...
}
```

#### Verify Python Receives Parameters:
Check Flask terminal output:
```
[EXAM] Processing /path/to/exam_input.csv...
[INFO] Exam hall requested: CAF UPSTAIRS
[INFO] Using provided hall capacity: 400
[INFO] Max exams per cohort per day: 2 (0 = unlimited)
[INFO] Max students per hall: 300
[INFO] Total sections to schedule: 18
[INFO] Available exam rooms: 1
```

### 3. **Test Progress Tracking**

#### Monitor Progress File:
```bash
watch -n 0.5 'cat /Applications/XAMPP/xamppfiles/htdocs/vvu-scheduler/ai_progress.json'
```

Expected updates:
```json
{"job_id": "exam_xxx", "status": "running", "percent": 5, "message": "Initializing..."}
{"job_id": "exam_xxx", "status": "running", "percent": 20, "message": "Loading data..."}
{"job_id": "exam_xxx", "status": "running", "percent": 75, "message": "AI solving..."}
{"job_id": "exam_xxx", "status": "success", "percent": 100, "message": "Complete"}
```

---

## Troubleshooting

### Progress Bar Not Updating
**Problem:** Progress bar stuck at 0%
**Solution:**
1. Check Flask backend is running: `curl http://127.0.0.1:5000/health`
2. Verify progress file is being written: `ls -l ai_progress.json`
3. Check browser console for JavaScript errors

### Parameters Not Passed Correctly
**Problem:** Python receives null/None values
**Solution:**
1. Check PHP payload in browser Network tab
2. Verify Flask receives data: Check terminal output
3. Ensure parameter names match mapping table above

### Exam Scheduling Times Out
**Problem:** CSP solver exceeds 120s timeout
**Solution:**
1. Increase timeout in exam_main_web.py: `timeout_seconds=300`
2. Reduce number of sections to schedule
3. Increase hall capacity: `max_students_per_hall=500`
4. Disable max_exams_per_day: Set to `0` in exam_config.json

---

## Summary

### ✅ Consistency Achieved

1. **Parameter Mapping:** PHP → Python parameters correctly mapped
2. **Progress Tracking:** Real-time progress from 5% → 100% with meaningful messages
3. **Configuration:** exam_config.json properly loaded with runtime overrides
4. **Constraints:** Aligned with PHP expectations (cohort conflicts disabled)
5. **CSP Solver:** Receives progress callback for live updates
6. **Error Handling:** Validation errors reported with progress updates

### 🎯 Key Features

- **Real-time Progress:** Updates every 1 second via polling
- **Meaningful Messages:** Each progress milestone shows clear status
- **Timeout Protection:** 2-minute CSP timeout prevents indefinite hangs
- **Configuration Flexibility:** Runtime overrides for hall name/capacity
- **Section Merging:** Multiple sections of same course auto-merged for exams
- **History Tracking:** Results appended to historical_exam_schedule.csv

---

## Changes Made (2026-02-20)

### Modified Files:

1. **exam_main_web.py**
   - Added `progress_callback` parameter to function signature
   - Added `_emit_progress()` helper function
   - Added progress tracking at 10+ checkpoints throughout execution
   - Added `_solver_progress()` callback for CSP solver
   - Passed `progress_callback` to CSP constructor

2. **app.py**
   - Added `_exam_progress()` callback function in `/generate/exam` endpoint
   - Passed progress callback to `run_headless_exam()`
   - Real-time progress saving via `save_progress()` function

### No Changes Required:
- **generate.php**: Already polls `/progress` endpoint correctly
- **exam_config.json**: Configuration parameters already consistent
- **exam_constraints.py**: Constraints already aligned with requirements
- **exam_load_data.py**: Config loading already supports overrides

---

## Contact & Support

For issues or questions about exam engine consistency:
1. Check Flask logs: `tail -f flask_log.txt`
2. Check PHP logs: `tail -f /Applications/XAMPP/xamppfiles/logs/error_log`
3. Review this documentation for parameter mappings
4. Test progress tracking using instructions above

---

**Last Updated:** February 20, 2026  
**Version:** 1.0  
**Status:** ✅ Production Ready
