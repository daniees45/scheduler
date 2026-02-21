# Scheduling Pipeline Documentation

## Overview
This document describes the complete scheduling pipeline from database to AI-generated schedule and back to database.

---

## 🔄 Complete Pipeline Flow

### **Phase 1: Data Export (Database → CSV)**
**File:** `web/api/sync.php`

**Purpose:** Export all scheduling data from MySQL to CSV files for AI processing

**Steps:**
1. Export rooms → `csv/general/rooms.csv`
2. Export lecturers with availability → `csv/general/lecturer_availability.csv`
3. Export courses → `csv/department/departmental_courses.csv`
4. Export special room assignments → `csv/general/special_rooms.csv`
5. Materialize any stored CSV files from database

**Output Files:**
```
csv/
├── general/
│   ├── rooms.csv
│   ├── lecturer_availability.csv
│   ├── special_rooms.csv
│   ├── curriculum.csv
│   └── vvu_general_schedule.csv
└── department/
    ├── departmental_courses.csv
    ├── computing_science_rooms.csv
    ├── nursing_rooms.csv
    └── theology_rooms.csv
```

---

### **Phase 2: API Call (Web → Flask)**
**File:** `web/generate.php` (JavaScript)

**Purpose:** Send scheduling request to Flask API

**Parameters Sent:**
```json
{
  "semester": "1",
  "input_file": "csv/department/departmental_courses.csv",
  "output_file": "csv/final/final_web_schedule.csv",
  "course_type": "Departmental",
  "department": "1",
  "availability_mode": "1",
  "exam_mode": false
}
```

**Endpoint:** `POST http://localhost:5000/generate`

---

### **Phase 3: AI Scheduling (Flask API)**
**File:** `app.py`

**Purpose:** Receive web request and orchestrate AI scheduling

**Flow:**
1. Validate input/output file paths (security check)
2. Extract scheduling parameters
3. Save initial progress status
4. Call `run_headless()` with all parameters
5. Return success/failure with accuracy score

---

### **Phase 4: Core Scheduling Engine (Python)**
**File:** `main_web.py` - `run_headless()` function

**Purpose:** Execute the AI scheduling algorithm

**Detailed Steps:**

#### 4.1 **Load AI Model**
```python
preference_model = load_trained_model(model_path="scheduling_model.pkl")
```
- Loads previously trained AI model with historical preferences
- Uses pattern recognition from past schedules

#### 4.2 **Department Detection**
```python
inferred_department = detect_department_from_courses(input_file)
```
- Analyzes course codes to determine department
- Maps courses to: CS/IT, Nursing, Theology, Business, etc.

#### 4.3 **Load Department-Specific Rooms**
```python
rooms_csv_path = get_department_room_file(inferred_department)
# e.g., "csv/department/computing_science_rooms.csv"
```
- Uses specialized rooms for each department
- CS courses → CS labs and computer rooms
- Nursing courses → Nursing labs and clinical rooms
- General courses → General classrooms

#### 4.4 **Load Special Room Assignments**
```python
special_rooms_path = "csv/general/special_rooms.csv"
data = load_combined_data(..., special_rooms_path=special_rooms_path)
```
- Pre-assigned courses (e.g., PEAC 100 → Basketball Court)
- Fixed time/room combinations
- Takes priority over AI assignment

#### 4.5 **Load General Schedule Blocks**
```python
blocked_blocks = load_general_schedule_blocks(general_schedule_path)
```
- Loads existing general courses schedule
- Prevents departmental courses from conflicting
- Blocks out already-occupied time slots

#### 4.6 **Pre-Flight Validation**
```python
if not pre_flight_check(data, min_lecturer_slots=3):
    return False, 0.0
```
- Validates data integrity
- Checks lecturer availability
- Ensures sufficient room capacity
- Verifies course requirements

#### 4.7 **Build Domain & Constraints**
```python
domain = build_domain(data)
constraints = make_constraints(
    data["sections"], 
    data["rooms"], 
    preference_model,
    blocked_blocks=blocked_blocks,
    lecturers=data["lecturers"],
    enable_flexibility=True
)
```

**Constraints Include:**
- No time conflicts for lecturers
- Room capacity must fit class size
- Respect lecturer availability windows
- Honor special room assignments
- Avoid general schedule conflicts
- Balance teaching load
- Minimize gaps between classes

#### 4.8 **Solve CSP (Constraint Satisfaction Problem)**
```python
solver = CSP(
    data["sections"], 
    domain, 
    constraints, 
    data["lecturers"], 
    preference_model,
    timeout_seconds=120
)
solution = solver.solve()
```

**Algorithm:**
- Uses backtracking with AI heuristics
- Forward checking for efficiency
- Q-Learning for optimization
- Deep learning for quality prediction
- Bidirectional feedback for improvement

#### 4.9 **Export Solution**
```python
export_solution(solution, data, out_path=output_file)
```
- Writes schedule to CSV: `csv/final/final_web_schedule.csv`
- Format: Course Code, Course Name, Credits, Lecturer, Room, Day, Time

#### 4.10 **Calculate Accuracy**
```python
total_sections = len(data["sections"])
placed_sections = len(solution)
accuracy = (placed_sections / total_sections * 100)
```
- Returns percentage of successfully scheduled courses

#### 4.11 **Retrain AI Model**
```python
train_model(history_data="csv/general/historical_schedule.csv", 
            model_save_path="scheduling_model.pkl")
```
- Learns from new schedule
- Improves future scheduling decisions

**Returns:** `(success: bool, accuracy: float)`

---

### **Phase 5: Import Results (CSV → Database)**
**File:** `web/api/update_db.php`

**Purpose:** Import AI-generated schedule back into MySQL

**Steps:**
1. Read generated CSV: `csv/final/final_web_schedule.csv`
2. Parse each scheduled class
3. Match courses, lecturers, rooms to database IDs
4. Update `sections` table with assignments:
   - `room_id`
   - `assigned_day`
   - `assigned_time`
5. Commit all changes

**SQL Update:**
```sql
UPDATE sections 
SET room_id = ?, assigned_day = ?, assigned_time = ? 
WHERE course_id = ? AND lecturer_id = ?
```

---

### **Phase 6: Version & Archive**
**File:** `web/api/schedule_versions.php`

**Purpose:** Save schedule version for history tracking

**Steps:**
1. Create version record in `schedule_versions` table
2. Store schedule metadata (name, description, timestamp)
3. Generate PDF export link
4. Enable rollback to previous versions

---

## 📊 Data Flow Diagram

```
┌─────────────┐
│  MySQL DB   │
│  (courses,  │
│  lecturers, │
│   rooms)    │
└──────┬──────┘
       │ PHASE 1: sync.php
       ▼
┌─────────────┐
│  CSV Files  │
│  (csv/      │
│  general/   │
│  dept/)     │
└──────┬──────┘
       │ PHASE 2: generate.php
       ▼
┌─────────────┐
│  Flask API  │
│  (port 5000)│
└──────┬──────┘
       │ PHASE 3: app.py
       ▼
┌─────────────┐
│run_headless │
│  - Load AI  │
│  - Detect   │
│  - Solve    │
│  - Export   │
└──────┬──────┘
       │ PHASE 4: main_web.py
       ▼
┌─────────────┐
│  Output CSV │
│  (csv/final/│
│  schedule)  │
└──────┬──────┘
       │ PHASE 5: update_db.php
       ▼
┌─────────────┐
│  MySQL DB   │
│  (sections  │
│  updated)   │
└──────┬──────┘
       │ PHASE 6: versions.php
       ▼
┌─────────────┐
│  Archived   │
│  + PDF Link │
└─────────────┘
```

---

## 🔍 Key Components

### **1. Department-Specific Room Allocation**
**Function:** `get_department_room_file(department)`  
**Location:** `load_data.py`

Maps departments to specialized room files:
```python
{
    "CS/IT/BBIS": "csv/department/computing_science_rooms.csv",
    "Nursing": "csv/department/nursing_rooms.csv",
    "Theology": "csv/department/theology_rooms.csv",
    "General": "csv/general/rooms.csv"
}
```

### **2. Special Room Assignments**
**File:** `csv/general/special_rooms.csv`

Format:
```csv
course_code,room_name,fixed_day,fixed_time
PEAC 100,B. Ball Court,Monday,5:00pm
```

**Priority:** Highest (overrides AI assignment)

### **3. General Schedule Dependency**
**File:** `csv/general/vvu_general_schedule.csv`

- Loaded automatically
- Blocks time slots already used by general courses
- Prevents conflicts between departmental and general schedules

### **4. AI Model Persistence**
**File:** `scheduling_model.pkl`

- Trained on historical schedules
- Learns lecturer preferences
- Recognizes optimal time patterns
- Continuously improves with each schedule

---

## ⚙️ Configuration Parameters

### **Scheduling Parameters:**

| Parameter | Values | Description |
|-----------|--------|-------------|
| `semester` | 1, 2, 3 | Academic semester |
| `course_type` | Departmental, General | Category of courses |
| `department` | 1-4 | 1=CS, 2=Nursing, 3=Theology, 4=General |
| `availability_mode` | 1, 2 | 1=AI Auto-expand, 2=Strict file data |
| `exam_mode` | true/false | Exam scheduling vs class scheduling |

### **File Paths:**

**Input Files:**
- Default: `csv/department/departmental_courses.csv`
- Can be any CSV with proper format

**Output Files:**
- Default: `csv/final/final_web_schedule.csv`
- Exam: `csv/final/exam_schedule_draft.csv`

---

## 🧪 Testing the Pipeline

### **1. Test Data Export:**
```bash
curl http://localhost/vvu-scheduler/web/api/sync.php
```
**Expected:** JSON success message + CSV files created

### **2. Test Flask API:**
```bash
curl -X POST http://localhost:5000/health
```
**Expected:** `{"status": "ok", "components": {...}}`

### **3. Test Full Pipeline:**
1. Open: `http://localhost/vvu-scheduler/web/generate.php`
2. Click "Start Generation"
3. Watch progress logs
4. Verify success with accuracy percentage

### **4. Verify Database Update:**
```sql
SELECT course_id, room_id, assigned_day, assigned_time 
FROM sections 
WHERE assigned_day IS NOT NULL
LIMIT 10;
```

---

## 🐛 Common Issues & Solutions

### **Issue: "AI Engine Offline"**
**Solution:** Start Flask server:
```bash
python3 app.py
```

### **Issue: "Input file not found"**
**Solution:** Run sync first to export DB to CSV:
```bash
curl http://localhost/vvu-scheduler/web/api/sync.php
```

### **Issue: "No valid schedule found"**
**Causes:**
- Insufficient rooms
- Lecturer availability too restrictive
- Conflicting constraints
- Special room conflicts

**Solution:** Review constraints, add more rooms, or relax availability

### **Issue: "Accuracy below 60%"**
**Solution:** Check:
- Room capacities match class sizes
- Lecturer availability is reasonable
- No over-constrained courses

---

## 📈 Performance Metrics

**Typical Performance:**
- Small dataset (< 50 courses): 10-30 seconds
- Medium dataset (50-150 courses): 30-90 seconds
- Large dataset (> 150 courses): 2-5 minutes

**Accuracy Targets:**
- Excellent: > 95%
- Good: 85-95%
- Acceptable: 70-85%
- Poor: < 70% (needs constraint review)

---

## 🔒 Security Considerations

1. **Path Validation:** All file paths validated against directory traversal
2. **RBAC:** Only admins can generate schedules
3. **SQL Injection:** All queries use prepared statements  
4. **CORS:** Configured for localhost development

---

## 📚 Related Files

**Core Scheduling:**
- `main_web.py` - Main scheduling engine
- `load_data.py` - Data loading and validation
- `builder.py` - Domain building
- `constraints.py` - Constraint definitions
- `csp.py` - CSP solver
- `export_data.py` - Result export

**AI/ML:**
- `deep_learning.py` - Quality prediction
- `q_learner.py` - Optimization learning
- `analyzer.py` - Model training
- `feasibility_classifier.py` - Feasibility prediction

**Web Integration:**
- `app.py` - Flask REST API
- `web/generate.php` - UI and workflow controller
- `web/api/sync.php` - Database export
- `web/api/update_db.php` - Database import

---

**Last Updated:** February 14, 2026  
**Version:** 2.0  
**Status:** ✅ Production Ready
