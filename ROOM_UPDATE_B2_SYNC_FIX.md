# Frontend Room Updates → B2 Auto-Sync Fix

## Problem Identified
When updating rooms in the frontend, the changes were:
- ✅ Saved to MySQL database
- ❌ NOT exported to B2 CSV files
- ❌ Python scheduler downloads old CSV from B2
- Result: Updates never applied to schedules

## Root Cause
The save endpoint (`web/api/save_data_edits.php`) only updated the database but didn't trigger a CSV export to B2.

## Solution Implemented

### New File: `web/api/export_db_to_csv.php`
- Exports rooms, lecturers, and courses from database to CSV
- Uploads CSVs to B2 Cloud Storage
- Also saves locally for backup

### Updated: `web/api/save_data_edits.php`
Now includes automatic export after any save:

**Before:**
```php
if ($stmt->execute()) {
    echo json_encode(['status' => 'success', 'message' => 'Room saved successfully']);
}
```

**After:**
```php
if ($stmt->execute()) {
    include 'export_db_to_csv.php';  // ← Auto-export to B2
    echo json_encode(['status' => 'success', 'message' => 'Room saved and exported to B2']);
}
```

## Data Flow (Fixed)

### Update Frontend
```
User edits room in UI
    ↓
POST /web/api/save_data_edits.php
    ├─ Save to MySQL ✅
    ├─ Include export_db_to_csv.php
    │   ├─ Export rooms.csv ✅
    │   ├─ Export lecturers.csv ✅
    │   ├─ Export courses.csv ✅
    │   └─ Upload to B2 ✅
    └─ Response: "Room saved and exported to B2"
    
Next scheduler run:
    → Downloads fresh rooms.csv from B2 ✅
    → Gets updated room data ✅
```

## What Gets Exported

| Table | CSV File | B2 Path |
|-------|----------|---------|
| rooms | rooms.csv | csv/general/rooms.csv |
| lecturers | lecturers.csv | csv/general/lecturers.csv |
| courses | courses.csv | csv/general/courses.csv |

## Verification Steps

### 1. Update a Room in Frontend
- Go to `Settings → Manage Data → Rooms`
- Add or edit a room
- Click "Save"

### 2. Check PHP Response
Look for:
```json
{
  "status": "success",
  "message": "Room saved and exported to B2",
  "exported": true
}
```

### 3. Verify B2 Upload
- Check B2 Console: `vvu-scheduler/csv/general/rooms.csv`
- File should have recent timestamp
- Or check local copy: `csv/general/rooms.csv`

### 4. Run Scheduler
- Generate a schedule
- Check logs for:
```
[B2] Force-refreshing input CSVs from B2 (ignore cache)...
[B2] Downloading csv/general/ (rooms, courses, etc)...
[INFO] B2 CSV paths in use: rooms=temp/csv/general/rooms.csv, ...
```

### 5. Verify Schedule Uses Updated Data
- Check generated schedule for new/updated room assignments
- Should use the room you just added/modified

## Automatic Sync Features

✅ **Instant Sync**: Updates exported immediately after save
✅ **B2 Backup**: Files uploaded to B2 and saved locally
✅ **Error Handling**: If B2 fails, still saves locally
✅ **Timestamp**: Export includes timestamp for tracking
✅ **All Types**: Rooms, lecturers, and courses all exported

## Files Modified

| File | Changes |
|------|---------|
| `web/api/export_db_to_csv.php` | **NEW** - Export DB to CSV |
| `web/api/save_data_edits.php` | Updated lines 20-35 (rooms), 55-75 (lecturers), 98-118 (courses) |

## Testing Checklist

- [ ] Edit a room name in frontend
- [ ] See "exported to B2" in response
- [ ] Check `csv/general/rooms.csv` has the change
- [ ] Run scheduler  
- [ ] Verify scheduler uses updated room data
- [ ] Edit a lecturer availability
- [ ] Edit a course
- [ ] All changes reflected in next schedule

## Future Enhancements

Optional improvements:
- [ ] Add export log viewer in UI
- [ ] Show B2 sync status per-table
- [ ] Batch exports (combine multiple edits before export)
- [ ] Rollback capability (keep version history in B2)
- [ ] Real-time notification when scheduled runs complete
