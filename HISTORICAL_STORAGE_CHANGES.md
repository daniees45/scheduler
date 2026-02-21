# Historical Schedule Storage - Hybrid Approach

## 🎯 Current System

### **Hybrid Approach: Best of Both Worlds**

Each schedule generation creates:
1. **Timestamped file** - `historical_schedule_YYYYMMDD_HHMMSS.csv` (for version control)
2. **Master cumulative file** - `historical_schedule.csv` (for training - all data combined)

**Benefits:**
- ✅ Version control via timestamped files
- ✅ Easy rollback to any specific generation
- ✅ Simple training with one master file
- ✅ No timestamp metadata columns cluttering data
- ✅ Track individual generations AND have complete history

---

## 📝 How It Works

### Course Schedules (`main.py`)

**For each generation:**
```python
# 1. Save timestamped version (version control)
history_timestamped = 'historical_schedule_20260221_143022.csv'
new_results.to_csv(history_timestamped, index=False)

# 2. Append to master file (training data)
if os.path.exists('historical_schedule.csv'):
    new_results.to_csv('historical_schedule.csv', mode='a', header=False, index=False)
else:
    new_results.to_csv('historical_schedule.csv', index=False)
```

**B2 Uploads:**
- Timestamped: `csv/history/historical_schedule_20260221_143022.csv`
- Master: `csv/general/historical_schedule.csv` (complete cumulative)

### Exam Schedules (`exam_main_web.py`)

**Same hybrid approach:**
- Timestamped: `historical_exam_schedule_20260221_143022.csv`
- Master: `historical_exam_schedule.csv`

**B2 Uploads:**
- Timestamped: `csv/history/exam_historical_exam_schedule_20260221_143022.csv`
- Master: `csv/general/historical_exam_schedule.csv`

---

## 📂 File Structure

### Local Files:
```
scheduler/
├── generated_schedule_20260221_143022.csv       # Final output
├── historical_schedule_20260221_143022.csv      # Timestamped archive
├── historical_schedule_20260221_154500.csv      # Next generation
├── historical_schedule.csv                       # MASTER (all generations)
├── historical_exam_schedule_20260221_160000.csv # Exam timestamped
└── historical_exam_schedule.csv                  # MASTER (all exams)
```

### B2 Storage:
```
vvu-scheduler/
├── csv/
│   ├── final/                                    # Generated schedules
│   │   ├── schedule_20260221_143022.csv
│   │   └── exam_schedule_final.csv
│   ├── history/                                  # Timestamped versions
│   │   ├── historical_schedule_20260221_143022.csv
│   │   ├── historical_schedule_20260221_154500.csv
│   │   └── exam_historical_exam_schedule_20260221_160000.csv
│   └── general/                                  # Master cumulative files
│       ├── historical_schedule.csv               # ALL course schedules
│       └── historical_exam_schedule.csv          # ALL exam schedules
```

---

## 📊 Data Structure

**No timestamp metadata columns** - just clean schedule data:

```csv
Course Code,Course Title,Credit Hrs,Lecturer Name,Room Name,Day,Time,...
CS 101,Intro to CS,3,Dr. Smith,Room 101,Monday,8:00am,...
CS 102,Data Structures,3,Dr. Jones,Room 102,Tuesday,10:00am,...
```

**Timestamp information is in the filename only:**
- `historical_schedule_20260221_143022.csv` ← Generated Feb 21, 2026 at 14:30:22

---

## 🎯 Use Cases

### For Version Control / Rollback:
```bash
# List all generations
ls -lh historical_schedule_*.csv

# Load specific generation
df = pd.read_csv('historical_schedule_20260221_143022.csv')
```

### For Training AI Models:
```python
# Simply load the master file (all data combined)
df = pd.read_csv('historical_schedule.csv')
# Contains ALL generations appended together
```

### For Cleanup:
```bash
# Keep last 20 timestamped files
ls -t historical_schedule_*.csv | tail -n +21 | xargs rm

# Master file continues to grow (contains all training data)
```

---

## ✅ Advantages

1. **Version Control** - Timestamped files let you track/rollback
2. **Easy Training** - Master file has all data in one place
3. **Clean Data** - No metadata columns cluttering the CSV
4. **Dual Benefits** - Get individual tracking AND cumulative history
5. **Flexible Cleanup** - Delete old timestamped files without losing training data
6. **B2 Efficiency** - Master file provides easy download of complete history

---

## 🚀 What Gets Uploaded to B2

**Every generation uploads 3 files:**

1. **Final schedule** → `csv/final/schedule_20260221_143022.csv`
2. **Timestamped history** → `csv/history/historical_schedule_20260221_143022.csv`
3. **Master cumulative** → `csv/general/historical_schedule.csv` (updated)

---

## 📋 Management

### View Master File Statistics:
```bash
wc -l historical_schedule.csv          # Total records
ls -lh historical_schedule.csv         # File size
head -n 10 historical_schedule.csv     # First 10 lines
```

### Manage Timestamped Files:
```bash
# Count generations
ls historical_schedule_*.csv | wc -l

# Delete old generations (keep training data in master)
ls -t historical_schedule_*.csv | tail -n +21 | xargs rm
```

### Download from B2:
```bash
# Download master cumulative file (all training data)
GET /web/api/download_historical_archive.php?type=course

# Download specific generation
GET /web/api/download_historical_archive.php?timestamp=20260221_143022
```

---

## 🎉 Summary

**Hybrid System:**
- ✅ Each generation saved as **timestamped file** (version control)
- ✅ All data appended to **master file** (training)
- ✅ No timestamp columns in data (clean CSVs)
- ✅ Both uploaded to B2 (dual backup)
- ✅ Easy to manage and train models

**Best of both worlds!** 🚀

---

*Updated: February 21, 2026*
