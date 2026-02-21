# Tkinter AI Scheduling System - Final Enhancements

## Overview
The Tkinter standalone application has been enhanced with four major features to complete the personal scheduling system:

1. **User Profile Fields**
2. **Visual Progress Tracking**
3. **PDF Export**
4. **Conflict Details Dialog**

---

## 1. User Profile Fields

### Location
- **Tab**: Personal Scheduling Tab
- **Frame**: "User Profile" (at top of Personal tab)

### Features
- **Role Selection**: Student or Lecturer (radio buttons)
- **Department**: Text field for department/faculty name
- **Level**: Dropdown for student level (100, 200, 300, 400)
- **Semester**: Dropdown for semester (1 or 2)
- **Save Profile Button**: Persists profile to `user_profile.json`

### Implementation Details
```python
# Profile storage location
PROFILE_FILE = os.path.join(OUTPUT_DIR, "user_profile.json")

# Profile persistence functions
load_profile()      # Load from JSON on startup
save_profile(dict)  # Save changes to JSON
save_profile_changes()  # UI button handler
```

### Purpose
- Allows students/lecturers to filter institution timetable data by their context
- Department and level filter institutional schedule suggestions
- Semester selection ensures only relevant exam/course data is loaded

### Usage Flow
1. Select role (Student/Lecturer)
2. Enter department name or code
3. Select academic level (for students)
4. Choose semester
5. Click "Save Profile"
6. Profile automatically applies to all suggestions

---

## 2. Visual Progress Tracking

### Location
- **Widget**: Progress bar at bottom of Status Log section
- **Update Points**: Extraction, Cleaning, Timetable Solving, Exam Solving

### Features
- **Deterministic Progress**: Replaced indeterminate spinner with percentage bar
- **Step-by-Step Updates**: Each major operation tracked in 4-5 steps
- **Status Messages**: Real-time operation status with percentage

### Implementation Details
```python
# Progress tracking with percentages
set_busy(message: str, percent: int)  # 0-100% with status message
clear_busy(message: str)              # Reset to 0%

# Example workflow
set_busy("Extracting PDF...", 10)
set_busy("Processing extracted data...", 50)
set_busy("Saving to CSV...", 75)
set_busy("Extraction complete!", 100)
```

### Tracking by Operation
| Operation | Steps |
|-----------|-------|
| **PDF Extraction** | 10% load → 50% process → 75% save → 100% done |
| **Data Cleaning** | 15% load → 50% clean → 100% done |
| **Timetable Solving** | 20% load → 50% solve → 100% done |
| **Exam Solving** | 20% load → 50% solve → 100% done |

### Visual Feedback
- Progress bar fills from 0-100% as operation progresses
- Status label shows current step
- Log window appends completion messages

---

## 3. PDF Export

### Location
- **Button**: "Export PDF" in Personal tab (bottom row with CSV/ICS)
- **Output**: `personal_schedule.pdf` in `tkinter_app/output/`

### Features
- Exports personal schedule to formatted PDF document
- Includes user profile information (role, department, level, semester)
- Lists all scheduled personal events
- Shows top 15 ranked free slot suggestions with scores

### Implementation Details
```python
export_personal_pdf()  # Main export function
```

### PDF Contents
1. **Header**: "Personal Schedule"
2. **Profile Section**: Role, Department, Level, Semester
3. **Scheduled Events**: List of all personal events with times
4. **Suggestions Section**: Top 15 ranked free slots with:
   - Day and time
   - Reason (e.g., "90+ min focus", "low utilization")
   - AI ranking score

### Styling
- Title: 16pt bold
- Sections: 12pt bold headers
- Content: 9-10pt regular text
- Multi-cell support for long suggestion text

### Dependencies
Requires `fpdf2` package:
```bash
pip install fpdf2
```

---

## 4. Conflict Details Dialog

### Location
- **Trigger**: Double-click on any event in "Personal Events" list
- **Dialog**: New window showing conflict details

### Features
- Shows event being clicked with full details (title, day, time)
- Lists all overlapping/conflicting events
- Displays source and time for each conflict
- Interactive table showing: Source | Event | Day | Time

### Implementation Details
```python
show_conflict_details(event_item)  # Called on double-click event

# Bound to event_list widget
event_list.bind("<Double-1>", lambda e: show_conflict_details(...))
```

### Dialog Contents
1. **Event Details**: Event title, day, start-end time
2. **Conflicts Table**: 
   - **Source**: Institution, Personal, Exam, or Timetable
   - **Event**: Event name or description
   - **Day**: Day of week
   - **Time**: Start-end time
3. **Close Button**: Dismiss dialog

### User Workflow
1. Notice ⚠️ marker on conflicting event
2. Double-click the event row
3. Dialog opens showing:
   - What event conflicts with it
   - Which source (timetable, exam, other personal)
   - Exact times of conflict
4. Review and plan accordingly
5. Delete event if desired

---

## File Structure Changes

### New/Modified Files
```
tkinter_app/
├── personal_scheduler_ui.py          # Enhanced with all 4 features
└── output/
    ├── user_profile.json             # Profile persistence (NEW)
    ├── personal_events.csv           # Personal events
    ├── personal_schedule.csv         # Export (CSV)
    ├── personal_schedule.ics         # Export (ICS)
    └── personal_schedule.pdf         # Export (PDF) - NEW
```

---

## Configuration & Usage

### Starting the Application
```bash
cd /Applications/XAMPP/xamppfiles/htdocs/vvu-scheduler
python3 tkinter_app/personal_scheduler_ui.py
```

### Typical Workflow
1. **Tab 1 - Data Prep**: Extract PDF → Clean data (with progress tracking)
2. **Tab 2 - Timetable**: Generate timetable schedule
3. **Tab 3 - Exams**: Generate exam schedule
4. **Tab 4 - Personal**:
   - Set up profile (role, department, level, semester)
   - Add personal events
   - View AI-ranked free slot suggestions
   - Export to PDF/CSV/ICS
   - Double-click conflicting events to see details

### Exporting Options
- **CSV**: Spreadsheet format with events + suggestions
- **ICS**: Calendar format for importing to Outlook/Google Calendar
- **PDF**: Printable document with full schedule and profile

---

## Technical Integration

### Progress Bar Widget
- Type: `ttk.Progressbar` with deterministic mode
- Maximum: 100 (hardcoded)
- Updates: Via `set_busy(message, percent)` calls
- Reset: Via `clear_busy()` after operation

### Profile Storage
- Format: JSON file
- Location: `tkinter_app/output/user_profile.json`
- Keys: `role`, `department`, `level`, `semester`
- Persistence: Auto-loaded on app startup

### Conflict Detection
- Uses existing `detect_conflicts()` from `personal_scheduler.py`
- Compares candidate event against:
  - Institution timetable blocks
  - Exam schedule blocks
  - Other personal events
- Returns list of `BusyBlock` objects with overlaps

### PDF Export
- Library: `fpdf2` (fpdf module)
- Fallback: Shows error if package not installed
- Content: Events from CSV + suggestions from AI solver

---

## Troubleshooting

### Issue: "fpdf2 not installed" error
**Solution**: 
```bash
pip install fpdf2
```

### Issue: Conflict dialog shows no conflicts
**Possible Causes**:
- Event actually has no conflicts (check ⚠️ marker)
- Timetable/exam CSVs not loaded yet
- Data format issue

**Solution**: Ensure timetable/exam schedules are generated first

### Issue: Profile doesn't save
**Possible Causes**:
- Output directory not writable
- JSON write error

**Solution**: Check that `tkinter_app/output/` directory exists and is writable

### Issue: Progress bar stuck at 100%
**Solution**: Normally resets when operation completes; if stuck, click another button to trigger `clear_busy()`

---

## Summary

These four enhancements complete the personal scheduling system:

| Feature | Purpose | Impact |
|---------|---------|--------|
| **User Profile** | Filter schedules by context | Personal suggestions match student/lecturer constraints |
| **Progress Tracking** | Show operation status | Users understand what's happening during long operations |
| **PDF Export** | Printable schedule | Easy sharing/printing of personal schedule |
| **Conflict Details** | Understand conflicts | Users can make informed decisions about event placement |

All features integrate seamlessly with existing components and maintain the modular architecture of the scheduling system.
