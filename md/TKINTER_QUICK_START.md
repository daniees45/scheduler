# Quick Start: New Personal Scheduler Features

## What's New in This Update? 🎉

Your Tkinter AI Scheduling application now has 4 powerful new features for better personal schedule management.

---

## 1️⃣ User Profile (Top of Personal Tab)

**What it does**: Stores your role, department, level, and semester so the AI suggests relevant schedules.

**How to use**:
1. Open the Personal Scheduling tab
2. In the "User Profile" section:
   - Select your role: Student or Lecturer
   - Enter your department (e.g., "Computer Science")
   - Select your level (100, 200, 300, 400) if you're a student
   - Select your semester (1 or 2)
3. Click **Save Profile**
4. Your profile is automatically used for all suggestions

**Why it matters**: The AI can filter out courses/exams that aren't relevant to you.

---

## 2️⃣ Progress Tracking (Status Bar)

**What it does**: Shows you exactly how far along each operation is (percentage).

**How to use**:
- Run any operation (Extract PDF, Clean Data, Solve Timetable, etc.)
- Watch the progress bar fill from 0-100%
- See the status message explaining the current step

**Example steps for PDF Extraction**:
```
10% - Extracting PDF...
50% - Processing extracted data...
75% - Saving to CSV...
100% - Extraction complete!
```

**Why it matters**: No more guessing - you know exactly how long to wait.

---

## 3️⃣ PDF Export (Export PDF Button)

**What it does**: Saves your personal schedule as a professional PDF document.

**How to use**:
1. Add some personal events in the Personal tab
2. Generate AI suggestions (click "Refresh Suggestions")
3. Click **Export PDF** button (bottom row)
4. PDF is saved to: `tkinter_app/output/personal_schedule.pdf`

**What's in the PDF**:
- Your profile info (role, department, level, semester)
- All your scheduled events
- Top 15 AI-recommended free time slots with scores
- Ready to print or email!

**Why it matters**: Easy to share your schedule or keep a backup.

---

## 4️⃣ Conflict Details Dialog (Double-Click Events)

**What it does**: Shows you exactly what conflicts with a conflicted event.

**How to use**:
1. Look for events with a ⚠️ marker (red background)
2. **Double-click** the conflicted event
3. A dialog opens showing:
   - Your event details
   - Everything it conflicts with
   - The source of each conflict (Timetable, Exam, Personal)
   - The exact time of the conflict

**Example**: You added "Study Group 2-3pm Monday" but it conflicts with your "CSC 101 Timetable 2:30-3:30pm Monday"

**Why it matters**: Understand why events conflict so you can reschedule intelligently.

---

## Quick Reference

| Feature | Location | Action |
|---------|----------|--------|
| **Profile** | Top of Personal tab | Fill in fields → Save Profile |
| **Progress** | Status bar at bottom | Run any operation & watch |
| **PDF Export** | Button in Personal tab | Click "Export PDF" |
| **Conflicts** | Event list in Personal tab | Double-click any ⚠️ event |

---

## Common Workflows

### Workflow 1: Create Your First Schedule
```
1. Open app → Personal tab
2. Set your profile (role, department, level, semester)
3. Click "Save Profile"
4. Add 2-3 personal events (Study, Work, Gym, etc.)
5. Click "Refresh Suggestions"
6. See AI-ranked free slots to use
7. Click "Export PDF" to save
```

### Workflow 2: Check Conflicts
```
1. Add event with red background (⚠️)
2. Double-click the event
3. Dialog shows conflicting events
4. Review conflict times
5. Delete event or reschedule
```

### Workflow 3: Share Your Schedule
```
1. Add events
2. Get suggestions (Refresh)
3. Click "Export PDF"
4. Email the PDF or print it
5. Or export to CSV/ICS for calendar apps
```

---

## Tips & Tricks

💡 **Profile is persistent**: Once you save it, it stays saved even after closing the app

💡 **Progress bar resets**: After each operation, it goes back to 0% and says "Ready"

💡 **PDF includes profile**: Your department/level/semester info appears at the top of the PDF

💡 **Conflicts show source**: You can tell if a conflict is from your timetable, exam, or another personal event

💡 **Export all formats**: You can export to CSV (spreadsheet), ICS (calendar), and PDF (print)

---

## Troubleshooting

**Q: "fpdf2 not installed" error when exporting PDF?**  
A: Run `pip install fpdf2` in your terminal

**Q: Profile not saving?**  
A: Make sure the app has write permission to `tkinter_app/output/` folder

**Q: Conflict dialog shows nothing?**  
A: The event might not actually conflict. The ⚠️ marker would show if it did.

**Q: Progress bar stuck at 100%?**  
A: This is normal - click another button to reset it

---

## File Locations

Your personal data is stored in:
```
tkinter_app/output/
├── user_profile.json          ← Your profile (role, dept, level, semester)
├── personal_events.csv        ← Your events
├── personal_schedule.pdf      ← PDF export
├── personal_schedule.csv      ← CSV export
└── personal_schedule.ics      ← Calendar export
```

---

## Need Help?

Check the full documentation in `TKINTER_ENHANCEMENTS.md` for technical details and advanced usage.

Enjoy better personal scheduling! 📅✨
