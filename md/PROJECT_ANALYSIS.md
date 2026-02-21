# Project Analysis: VVU AI-Powered Timetable & Exam Scheduler

**Date:** 13 Feb 2026  
**Workspace:** `/Applications/XAMPP/xamppfiles/htdocs/vvu-scheduler`

## 1. Overview
This project is a hybrid AI scheduling system for university timetables and exam timetables. It combines a **Constraint Satisfaction Problem (CSP) solver**, **historical learning**, **machine learning feasibility prediction**, **reinforcement learning (Q‑learning)**, and a **Genetic Algorithm (GA) fallback**. It includes a **web admin portal** (Flask + templates + PHP integration), a **REST API** for scheduling calls, and data processing utilities for PDF extraction and data cleanup.

Primary goals:
- Generate conflict‑free course schedules.
- Respect lecturer availability and department-specific room pools.
- Prevent student cohort clashes (including general vs departmental restrictions).
- Use historical data to prioritize good slots.
- Learn from user feedback over time.
- Produce printable/exportable outputs (CSV → PDF, ICS, dashboards).

## 2. Architecture & Workflow
### 2.1 High-Level Pipeline
1. **Extraction** (`basic.py`) — PDF timetable extraction to CSV.
2. **Cleaning & Alignment** (`clean_up.py`) — clean raw data into a consistent format.
3. **Load & Normalize** (`load_data.py`) — build domain entities (lecturers, rooms, courses, sections).
4. **Constraint Modeling** (`constraints.py`) — enforce hard and soft scheduling rules.
5. **CSP Solver** (`csp.py`) — search for a valid assignment.
6. **ML Pruning** (`feasibility_classifier.py` + `builder.py`) — trim poor candidates.
7. **GA Fallback** (`genetic_algorithm.py`) — near‑optimal fallback if CSP fails.
8. **Export** (`export_data.py`, `csv_to_pdf.py`) — output CSV and PDF.
9. **Learning Loop** (`analyzer.py`, `q_learner.py`) — update weights and preferences.

### 2.2 Web & API Layer
- **Web dashboard** (`app.py`) with authentication, file management, schedule generation, calendar views, and exports.
- **AI service API** (`ai_service.py`) exposes `/solve` and `/solve_exam` endpoints.
- **Headless schedulers** (`main_web.py`, `exam_main_web.py`) run without user prompts.

## 3. Core Components & Concepts
### 3.1 CSP Solver (Symbolic AI)
**File:** `csp.py`  
Key ideas:
- Backtracking search with MRV (Minimum Remaining Values).
- Scoring & ordering values by lecturer availability and preference weights.
- Timeout handling + diagnostics.
- Flexible assignment logic to recover from availability bottlenecks.

### 3.2 Constraints (Hard/Soft)
**File:** `constraints.py`  
Key constraints:
- No lecturer double‑booking.
- No room double‑booking.
- No cohort clashes (including semester‑aware filtering).
- Shared-course alignment across departments (with section‑aware matching).
- Department‑room compatibility.
- General schedule blocking for department timetables.
- Optional flexible lecturer assignment.

### 3.3 Domain Construction
**File:** `builder.py`  
Builds candidate slots for each class section, with:
- Special room locking.
- Departmental room prioritization.
- Capacity filtering (optional).
- ML pruning via feasibility classifier.

### 3.4 Historical Preference Learning
**File:** `analyzer.py`  
Learns from historical schedules and user feedback CSVs:
- Lecturer time preferences
- Course-room pairing preferences
- Global slot popularity
- Custom conflict hints

### 3.5 ML Feasibility Classifier
**File:** `feasibility_classifier.py`  
RandomForest model trained from historical schedules:
- Predicts success probability of a `(course, day, slot, enrollment)` assignment.
- Used to prune weak domain candidates.
- SHAP explainability output:
  - `shap_feature_importance.csv`
  - `shap_summary.png`

### 3.6 Q-Learning (Reinforcement Learning)
**File:** `q_learner.py` + integration in `analyzer.py` and `main.py`  
Learns user preferences from accept/change feedback:
- State = `(course, day, slot, room)`
- Actions = `accept` / `change`
- Reward = +1 / -1
- Persists to `q_model.pkl`

### 3.7 Genetic Algorithm Fallback
**File:** `genetic_algorithm.py`  
Used when CSP fails or times out:
- Population‑based search
- Mutation, crossover, elitism
- Fitness = 1 – (violations / max violations)

### 3.8 Exam Scheduling System
Files:
- `exam_load_data.py`
- `exam_builder.py`
- `exam_constraints.py`
- `exam_export_data.py`
- `exam_main.py` / `exam_main_web.py`

Features:
- Slot limits per day
- Exam hall capacity enforcement
- Single-room or multi-room modes
- Section grouping by course
- Invigilator assignment
- Semester‑aware cohort grouping

## 4. Web Application Features
**File:** `app.py`  
Key features:
- Role‑based authentication (super admin, faculty admin, lecturer, student)
- File upload & preview (CSV/PDF)
- Scheduler execution and progress API
- PDF and ICS export
- Calendar view
- Diagnostics reports
- Preferences management with CSV sync

## 5. Data & Configuration
### 5.1 Primary CSV Data
- `courses_input.csv`, `departmental_courses.csv`, `general.csv`, `vvu_clean.csv`
- `lecturer_availability.csv`
- `rooms.csv` and department-specific room files
- `special_rooms.csv`
- `shared_courses.csv` and `shared_course_aliases.csv`
- `level_100.csv` to `level_400.csv`
- `curriculum.csv`

### 5.2 Model Artifacts
- `scheduling_model.pkl` — historical preference model
- `feasibility_classifier.pkl` — RandomForest feasibility model
- `q_model.pkl` — Q‑learning preferences

### 5.3 Config Files
- `exam_config.json` — exam solver configuration
- `config.json` (optional) — solver slot configuration

## 6. Requirements
From `requirements.txt`:
- `flask`, `pandas`, `tabula-py`, `flask-sqlalchemy`, `flask-login`, `flask-bcrypt`, `flask-wtf`, `email_validator`, `fpdf`, `ics`

Additional Python dependencies used in code:
- `scikit-learn` (classifier + accuracy metrics)
- `numpy` (ML + GA)
- `shap` (explainability)
- `matplotlib` (optional, SHAP plots)

## 7. Advantages
- **Hard conflict-free scheduling** with CSP.
- **Hybrid AI** combines symbolic constraints and statistical learning.
- **Self‑learning** from historical schedules and user feedback.
- **GA fallback** handles complex or over‑constrained cases.
- **Exam scheduling integrated** with robust capacity handling.
- **Web interface** for admins, including exports and diagnostics.

## 8. Disadvantages / Limitations
- **Data quality dependency**: clean input is essential.
- **Availability bottlenecks** can still block scheduling.
- **Explainability is ML‑only** (CSP logic not fully explainable).
- **Large data** may require longer solve times.
- **SQL sync is partial** (not all CSVs auto‑synced from DB).

## 9. Key Functions & Responsibilities
### Scheduling
- `main.py: main()` — interactive scheduling pipeline
- `main_web.py: run_headless()` — non‑interactive scheduling for web
- `csp.py: CSP.solve()` — main CSP solver
- `builder.py: build_domain()` — domain creation (candidate assignments)
- `constraints.py: make_constraints()` — constructs rule set
- `export_data.py: export_solution()` — output schedule CSV

### Learning
- `analyzer.py: train_model()` — build preference model from history
- `feasibility_classifier.py: train()` — fit RandomForest classifier
- `feasibility_classifier.py: explain_model()` — SHAP outputs
- `q_learner.py: record_feedback()` — update Q-learning model

### Exams
- `exam_main.py: main()` — interactive exam schedule
- `exam_main_web.py: run_headless_exam()` — headless exam run
- `exam_builder.py: build_exam_domain()` — exam domain generation
- `exam_constraints.py: make_exam_constraints()` — exam rules

### Web & API
- `ai_service.py: /solve` — API entrypoint for scheduling
- `ai_service.py: /solve_exam` — API entrypoint for exam scheduling
- `app.py` — full dashboard and administration

## 10. Outputs
- **Schedules**: CSV exports in `final_web_schedule.csv` and related files
- **Exams**: CSV in `final_exam_schedule.csv` or custom path
- **PDF**: `csv_to_pdf.py` output
- **ICS**: Calendar export
- **SHAP**: `shap_feature_importance.csv`, `shap_summary.png`

## 11. Recommendations / Future Improvements
- Add automated DB→CSV sync for all entities (rooms, courses, curricula).
- Add time-slot granularity per lecturer (not just day-level availability).
- Add multi-objective scoring dashboard (room utilization, lecturer fairness, student gaps).
- Add per‑department conflict reporting.
- Add versioned exports and audit trails.

---

**Summary:** This is a complete, production-oriented hybrid AI scheduler with CSP, ML pruning, RL learning, and a web control plane. It balances strict constraints with adaptive learning, supports exam timetabling, and provides exports for downstream use.
