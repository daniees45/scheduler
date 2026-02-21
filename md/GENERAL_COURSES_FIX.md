# General Courses & B2 Download Fixes

## Issues Fixed

### 1. **B2 Download Error: "Failed to download file from B2"**
**Problem**: Downloaded files had no content
**Root Cause**: `B2Storage::download()` method wasn't properly handling the `SaveAs` parameter - AWS SDK writes directly to file when SaveAs is used, but code was trying to read from response body

**Fix Applied** (`lib/B2Storage.php`):
- When `SaveAs` parameter is provided, AWS SDK writes directly to disk
- Verify file exists and has size > 0 before returning success
- Return `content: null` when using SaveAs (content is in the file, not response)

```php
// BEFORE: Tried to read from body even with SaveAs
$result = $this->client->getObject($params);
$content = (string) $result['Body'];  // ❌ Empty when SaveAs used!

// AFTER: Handle SaveAs separately
if ($savePath) {
    $result = $this->client->getObject($params);
    if (file_exists($savePath) && filesize($savePath) > 0) {
        return ['success' => true, 'content' => null, 'error' => null];  // ✓
    }
}
```

### 2. **AI Using Department-Specific Rooms for General Courses**
**Problem**: When generating "General" schedules, AI inferred department (e.g., "Theology") from course codes and used theology_rooms.csv instead of general rooms

**Root Cause**: Department inference logic didn't check the course_type parameter - it always tried to infer department from the input file

**Fixes Applied** (`main_web.py`):

#### Fix 2a: Immediate Department Assignment for General Courses
```python
# BEFORE: Always inferred from courses
inferred_department = "General"  # Placeholder
# ... code that overwrote this ...
inferred_department = <inferred from courses>  # ❌

# AFTER: Check course_type first
if str(course_type).lower() == "general":
    inferred_department = "General"  # ✓ Don't infer!
    print("[INFO] General course type detected - using General rooms")
else:
    # Only infer for departmental courses
    inferred_department = <inferred from courses>  # ✓
```

#### Fix 2b: Filter Input CSV to Only General Courses
When course_type is "General", filter the input to only rows with `source_type="General"`

```python
if str(course_type).lower() == "general":
    # Filter to only "General" courses before loading
    general_only = df[df['source_type'] == 'General'].copy()
    general_only.to_csv(filtered_file, index=False)  # ✓
    input_file = filtered_file
```

This ensures:
- General schedules use **general rooms** (`csv/general/rooms.csv`)
- Only **General-category courses** are scheduled
- No cross-department inference

### 3. **Enhanced Debugging Logging**
Added parameter logging at the start of `run_headless()`:
```
[START] Parameters: course_type='General', department='General'
[INFO] General course type detected - using General rooms
[DATA] Filtering to include ONLY General courses...
[DATA] Filtered OUT X non-General courses
[DATA] Using Y General courses from filtered file
[INFO] Using rooms file: temp/csv/general/rooms.csv
```

## What You Need To Do

### 1. **Restart Flask Server**
Restart the Python Flask app so it loads the updated module:
```bash
# Kill existing process
pkill -f "python.*app.py"

# Restart
python app.py
```

### 2. **Test the Fixes**
1. Go to generate.php
2. Select **"General Courses"** as Course Category
3. Upload a CSV with mixed courses or general courses
4. Verify logs show:
   - `[START] Parameters: course_type='General'`
   - `[INFO] General course type detected - using General rooms`
   - `[INFO] Using rooms file: temp/csv/general/rooms.csv` ✓ (NOT dept-specific)
   - `[DATA] Filtering to include ONLY General courses...`

### 3. **Test B2 Downloads**
Visit `/web/test_b2_download.php` to verify:
- ✓ Test 1: List files works
- ✓ Test 2: Download without SaveAs (content in response) works
- ✓ Test 3: Download with SaveAs (write to temp file) works
- ✓ Test 4: API endpoint receives data correctly

## Technical Details

### B2Storage Fix Impact
- Affects all file downloads from B2: schedules, rooms, courses, etc.
- `download()` now correctly handles both scenarios:
  1. **With SaveAs**: Returns `{'success': true, 'content': null}` + file on disk
  2. **Without SaveAs**: Returns `{'success': true, 'content': '..string..'}` + no file

### Course Filtering Impact
- Input file must have `source_type` column for filtering to work
- Columns checked: `source_type` (e.g., "General", "Departmental", "Theology", etc.)
- If `source_type` column missing, all courses processed (fallback behavior)

## Files Modified

1. `lib/B2Storage.php` - Fixed download() method
2. `main_web.py` - Added parameter logging, course_type check, course filtering
3. `web/test_b2_download.php` - NEW diagnostic tool

## Expected Behavior After Fix

**Before**: General schedule → Theology department inferred → theology_rooms used → ❌

**After**: 
- General courses selected
- Input filtered to only "General" source_type
- General rooms used exclusively
- Theology/CS/etc. courses excluded from scheduling
- ✓ Correct general schedules generated

