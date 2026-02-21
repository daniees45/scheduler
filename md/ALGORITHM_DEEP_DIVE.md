# ARCHITECTURE & ALGORITHM DEEP DIVE

## System Architecture Overview

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                    UNIVERSITY TIMETABLE SCHEDULER v1.0                       │
│                         Hybrid AI Engine (VVU)                              │
└─────────────────────────────────────────────────────────────────────────────┘

┌──────────────────────┐         ┌──────────────────────┐         ┌─────────────────────┐
│   USER INTERFACES    │         │   DATA SOURCES       │         │  AI COMPONENTS      │
├──────────────────────┤         ├──────────────────────┤         ├─────────────────────┤
│ • CLI (main.py)      │         │ • CSV Input Files    │         │ 🤖 CSP Solver      │
│ • Web (dashboard.php)│ ───────▶│ • Curriculum Data    │ ───────▶│ 🧠 Model Trainer   │
│ • REST API (Flask)   │         │ • Room Database      │         │ 📊 Analyzer        │
└──────────────────────┘         │ • Lecturer Avail.    │         │ ⚙️ Constraint Eng.  │
                                 │ • Historical Data    │         └─────────────────────┘
                                 └──────────────────────┘                    │
                                                                             │
                                         ┌───────────────────────────────────┘
                                         │
                                         v
                                 ┌─────────────────┐
                                 │  SCHEDULING    │
                                 │   CORE ENGINE  │
                                 ├─────────────────┤
                                 │ 1. Build Domain │
                                 │ 2. Set Rules    │
                                 │ 3. Backtrack    │
                                 │ 4. Validate     │
                                 │ 5. Optimize     │
                                 └────────┬────────┘
                                         │
                        ┌────────────────┼────────────────┐
                        │                │                │
                        v                v                v
                   ┌─────────┐    ┌────────────┐    ┌──────────────┐
                   │ SUCCESS │    │   TIMEOUT  │    │   FALLBACK   │
                   │ EXPORT  │    │ DIAGNOSIS  │    │ GREEDY/GA    │
                   │  CSV    │    │ WHY FAILED?│    │ HEURISTICS   │
                   └────┬────┘    └────────────┘    └──────────────┘
                        │
                        v
                   ┌─────────────────────┐
                   │  SELF-LEARNING LOOP │
                   ├─────────────────────┤
                   │ • Append to history │
                   │ • Retrain model     │
                   │ • Get smarter       │
                   └─────────────────────┘
                        │
                        v
                   ┌──────────────────┐
                   │ FINAL SCHEDULE   │
                   │ (PDF / CSV)      │
                   └──────────────────┘
```

## Detailed Algorithm Flow: CSP Backtracking Solver

```
╔════════════════════════════════════════════════════════════════════════════╗
║                     CSP BACKTRACKING ALGORITHM                             ║
║         (Constraint Satisfaction Problem with MRV Heuristic)              ║
╚════════════════════════════════════════════════════════════════════════════╝

INPUT: 
  • Variables: 100 class sections to schedule
  • Domains: Each section has ~200 possible (day, slot, room) combinations
  • Constraints: 4 hard constraints (no conflicts)
  • Preferences: Historical scores from analyzer.py

ALGORITHM:
─────────────────────────────────────────────────────────────────────────────

┌─ FUNCTION: backtrack(assignment)
│
│  1. BASE CASE: Is assignment complete?
│     ├─ YES: return assignment (SUCCESS!)
│     └─ NO:  continue to step 2
│
│  2. SELECT VARIABLE
│     │
│     ├─ Choose: Unassigned variable with FEWEST valid options (MRV)
│     │           (Most constrained first = maximum pruning)
│     │
│     └─ WHY? If a variable has only 1 option, pick it now to fail fast
│         If we wait, we might waste time on other variables
│
│  3. ORDER VALUES
│     │
│     ├─ Sort domain values by score:
│     │   score = get_value_score(var_id, value)
│     │           ↓
│     │           Check if (day, slot) in lecturer.available_time_slots
│     │           ↓
│     │           Add historical preference weights
│     │           ↓
│     │           Add small random noise (avoid ties)
│     │
│     └─ Try highest-scoring values first (prefer historically successful slots)
│
│  4. FOR EACH value in sorted_domain_values:
│
│     a) CONSISTENCY CHECK
│        ├─ Temporarily add: assignment[var_id] = value
│        │
│        ├─ Check all constraints:
│        │  • no_lecturer_conflict(assignment, var_id, value)
│        │    → Does lecturer already teach at same day/slot?
│        │
│        │  • no_room_conflict(assignment, var_id, value)
│        │    → Is room already occupied at this time?
│        │
│        │  • no_student_cohort_conflict(assignment, var_id, value)
│        │    → Would students have conflicting classes?
│        │
│        │  • no_blocked_slot_conflict(assignment, var_id, value)
│        │    → Is this time blocked by General Schedule?
│        │
│        └─ If ANY constraint fails: skip this value, try next
│
│     b) TENTATIVE ASSIGNMENT OK?
│        ├─ YES: assignment[var_id] = value (KEEP IT)
│        │
│        └─ RECURSE: result = backtrack(assignment)
│           │
│           ├─ If result is not None: return result (SOLUTION FOUND!)
│           │
│           └─ If result is None: undo assignment (BACKTRACK)
│               assignment.pop(var_id)
│               Try next value... (loop back to step 4)
│
│     c) NO MORE VALUES?
│        └─ Log error: "REJECTED: {course} - all {N} slots caused conflicts"
│           Return None (forcing parent to backtrack)
│
└─ RETURN: assignment (or None if impossible)

─────────────────────────────────────────────────────────────────────────────

EXAMPLE TRACE:
─────────────────────────────────────────────────────────────────────────────

Step 1: Start with empty assignment {}
         Variables to assign: [MATH101, PHYS201, CS301, ...]

Step 2: Select MATH101 (has smallest domain: 5 options)
         
Step 3: Sort options by score:
         1. (Mon, slot1, Room_A) - score: 45.2  ← Try this first
         2. (Tue, slot2, Room_B) - score: 38.1
         3. (Wed, slot1, Room_C) - score: 22.3
         4. (Thu, slot3, Room_A) - score: 15.0
         5. (Fri, slot2, Room_B) - score: 8.5

Step 4a: Try (Mon, slot1, Room_A)
         Check: Is Dr. Smith teaching elsewhere Mon slot1? NO ✓
                Is Room_A occupied Mon slot1? NO ✓
                Do students have conflicting courses? NO ✓
         → VALID! Assign: MATH101 → (Mon, slot1, Room_A)

Step 4b: Recurse with assignment {MATH101: (Mon, slot1, Room_A)}
         Now process next variable: PHYS201
         ...same process...

         → Eventually either:
         ✓ All variables assigned → SUCCESS
         ✗ Hit dead-end for some variable → BACKTRACK

Step 5: If PHYS201 fails with all 5 options tried:
        → Backtrack to MATH101
        → Try next option: (Tue, slot2, Room_B)
        → Retry downstream variables with new state

UNTIL: Either success (all variables assigned) or 
       timeout (30 seconds exceeded) or 
       all combinations exhausted (rare, usually timeout first)
```

## Key Data Structures

```
DOMAIN REPRESENTATION:
─────────────────────────────────────────────────────────────────────────────

domains = {
    "MATH101_Sec1": [
        (0, 1, "Room_A"),   # (day=0=Monday, slot=1=10AM, room_id)
        (0, 2, "Room_A"),
        (1, 1, "Room_B"),
        (2, 0, "Room_A"),
        ...                 # ~200 possibilities
    ],
    "PHYS201_Sec2": [
        (0, 0, "Lab_1"),
        (1, 2, "Lab_1"),
        ...
    ],
    ...
}


SOLUTION REPRESENTATION:
─────────────────────────────────────────────────────────────────────────────

solution = {
    "MATH101_Sec1": (0, 1, "Room_A"),    # Assigned Monday 10AM in Room_A
    "PHYS201_Sec2": (2, 2, "Lab_1"),     # Assigned Wednesday 2PM in Lab_1
    "CS301_Sec1":   (1, 0, "CS_LAB"),    # Assigned Tuesday 7AM in CS_LAB
    ...
}


CONSTRAINT REPRESENTATION:
─────────────────────────────────────────────────────────────────────────────

constraints = [
    lecturer_conflict_wrapper,        # Function that returns bool
    no_room_conflict,                 # Function that returns bool
    cohort_conflict_wrapper,          # Function that returns bool
    blocked_slot_wrapper              # Function that returns bool (optional)
]

Each constraint is a CALLABLE:
    constraint(assignment, var_id, value) → bool
    
    Returns True:  Value is legal (no conflicts)
    Returns False: Value breaks this constraint
```

## Preference Model Structure

```
HISTORICAL LEARNING MODEL:
─────────────────────────────────────────────────────────────────────────────

model_data = {
    "lecturer_slots": {
        ("Dr_Smith", 0, 1):    8,  # Dr. Smith taught at Mon 10AM 8 times
        ("Dr_Smith", 1, 1):    5,  # Dr. Smith taught at Tue 10AM 5 times
        ("Dr_Jones", 2, 2):    12, # Dr. Jones taught at Wed 2PM 12 times
        ...
    },
    
    "course_rooms": {
        ("MATH101", "Room_A"):    10, # MATH101 was in Room_A 10 times
        ("CS301", "CS_LAB"):      8,  # CS301 was in CS_LAB 8 times
        ...
    },
    
    "global_slots": {
        (0, 1): 45,  # Monday 10AM is popular (45 classes scheduled there)
        (1, 1): 38,  # Tuesday 10AM (38 classes)
        (4, 2): 12,  # Friday 2PM (12 classes)
        ...
    },
    
    "custom_conflicts": {
        ("MATH101", "PHYS201"): 50,  # Penalty for scheduling together
        ...
    }
}

SCORING DURING SOLVER:
─────────────────────────────────────────────────────────────────────────────

get_value_score(var_id="MATH101_Sec1", value=(0, 1, "Room_A")) → float

    score = 0.0
    
    # Check hard constraint (lecturer can work at this time)
    if (day=0, slot=1) in Dr_Smith.available_time_slots:
        score += 100.0  # Strong boost for available time
    
    # Add soft preferences
    score += preferences["lecturer_time_preferences"]
            .get((Dr_Smith, day=0, slot=1), 0) * 5.0
    
    score += preferences["course_room_preferences"]
            .get(("MATH101", "Room_A"), 0) * 2.0
    
    return score
    
→ Used to sort domain values: higher score = tried first
```

## Constraint Satisfaction Formalization

```
CSP DEFINITION:
═════════════════════════════════════════════════════════════════════════════

Variables:       X = {MATH101, PHYS201, CS301, ...}  (class sections)

Domains:         D = {
                     D(MATH101) = {(Mon,10AM,Room_A), (Tue,2PM,Room_B), ...},
                     D(PHYS201) = {...},
                     ...
                 }

Constraints:     C = {
                     C1: ∀ i,j: lecturer(Xi) = lecturer(Xj) 
                         ⟹ day(Xi) ≠ day(Xj) ∨ slot(Xi) ≠ slot(Xj)
                         (No lecturer double-booking)
                     
                     C2: ∀ i,j: room(Xi) = room(Xj) 
                         ⟹ day(Xi) ≠ day(Xj) ∨ slot(Xi) ≠ slot(Xj)
                         (No room double-booking)
                     
                     C3: ∀ i,j: cohort(Xi) ∩ cohort(Xj) ≠ ∅ 
                         ⟹ day(Xi) ≠ day(Xj) ∨ slot(Xi) ≠ slot(Xj)
                         (No student cohort clashes)
                     
                     C4: ∀ i: (level(Xi), semester(Xi), day(Xi), slot(Xi))
                       ∉ General_Schedule_Blocked_Times
                       (Respect General Schedule, semester-specific)
                 }

Goal:            Find assignment A where:
                 ∀ var in X: A[var] ∈ D[var]
                 ∀ constraint C in C: C(A) is satisfied
                 
                 Optimize: maximize Σ score(Xi, A[Xi])

Algorithm:       BACKTRACKING SEARCH + MRV HEURISTIC + VALUE ORDERING
```

## Performance Analysis

```
COMPUTATIONAL COMPLEXITY:
─────────────────────────────────────────────────────────────────────────────

Worst Case (without optimizations):   O(d^n)
  where: d = domain size (~200)
         n = number of variables (~100)
  → ~200^100 possibilities (IMPOSSIBLE)

With MRV + Constraint Propagation:    O(d^(n/k)) where k = effectiveness
  → Dramatically reduced in practice

Real VVU Data:
  • Input: ~100 class sections
  • Average domain size: 180-220 values per section
  • Typical solve time: 2-8 seconds
  • Timeout: 30 seconds
  • Success rate: ~95% (if data is reasonable)
  
Scaling Characteristics:
  • 100 classes:  2-8 sec   ✓ Fast
  • 200 classes:  15-25 sec ✓ Acceptable
  • 300 classes:  45-120 sec ⚠ Slow, may timeout
  • 400+ classes: Timeout required optimization
```

## What Happens When Solve Fails

```
FAILURE DIAGNOSIS FLOW:
─────────────────────────────────────────────────────────────────────────────

Solver timeout/fail
    ↓
Invoke run_diagnosis()
    ↓
Greedy Attempt:
  • Sort variables by domain size (most constrained first)
  • Try to assign each variable greedily
  • Record which constraint blocks each one
    ↓
Report Results:
  • CRITICAL FAILURE: {course} cannot be scheduled
  • Reason: {constraint} blocked {N} out of {total} possible slots
  • Examples:
    - "Lecturer Availability/Conflict: Blocked 198 slots (99%)"
      → Dr. Smith is only available 2 time slots total
    - "Room Occupied: Blocked 150 slots (75%)"
      → All suitable rooms are booked
    - "Student Cohort Conflict: Blocked 180 slots (90%)"
      → Every possible slot conflicts with another course
    ↓
Recommend Actions:
  • Expand lecturer availability
  • Add more rooms
  • Split course into multiple sections
  • Change course level/requirements
  • Check special_rooms.csv for errors
  • Ensure General timetable exists for the selected semester
```

---

## Summary Table: What Makes the AI Work

| Component | Type | Purpose | Status |
|-----------|------|---------|--------|
| **Backtracking Search** | Algorithm | Core solving engine | ✅ Proven technique for CSP |
| **MRV Heuristic** | Optimization | Variable selection | ✅ Reduces search space |
| **Value Scoring** | Learning | Preference ordering | ⚠️ Could be more sophisticated |
| **Constraint Engine** | Logic | Ensures legality | ✅ 4 comprehensive checks |
| **Timeout Handling** | Safety | Prevents infinite loops | ✅ 30-second limit with diagnostics |
| **Historical Model** | Learning | Self-improvement | ⚠️ Only frequency-based, not ML |
| **Diagnostics** | Debugging | Explains failures | ✅ Identifies bottlenecks |

