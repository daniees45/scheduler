# B2 Cache Fix Testing Guide

## Overview
This guide will help you verify that the B2 cache invalidation fix is working correctly. The core issue was that despite PHP uploading fresh CSV files to B2, the Python scheduler was always using stale cached versions.

---

## Quick Validation (2 minutes)

### Step 1: Run the Validation Script
```bash
cd /Applications/XAMPP/xamppfiles/htdocs/scheduler
python3 validate_b2_fix.py
```

Expected output:
```
✅ Metadata file exists: temp/b2_cache/.b2_metadata.json
✅ force=True found in load_data.py
✅ No bare except blocks found - good!
✅ B2CacheHandler class found
```

---

## Deep Testing (10 minutes)

### Prerequisites
Ensure you have:
- Python venv activated: `.venv/bin/activate`
- B2 credentials configured in environment
- Access to production scheduler dashboard

### Test 1: Fresh Download on First Run

**Objective**: Verify that the first run downloads files from B2 with force=True

**Steps**:
1. Clear the local cache:
   ```bash
   rm -rf temp/b2_cache/*
   ```

2. Run the scheduler with debug logging:
   ```bash
   python3 -u app.py 2>&1 | tee test_run_1.log
   ```

3. Monitor for these log messages:
   ```
   [B2] Force-refreshing all CSVs from B2 (ignore cache)...
   [B2] Downloading csv/general/rooms.csv with force=True
   [B2] Downloading csv/general/special_rooms.csv with force=True
   ```

**Success Criteria**:
- ✅ The `[B2] Force-refreshing` message appears
- ✅ No `[CACHE HIT]` messages appear (files downloaded fresh)
- ✅ Metadata file created at `temp/b2_cache/.b2_metadata.json`

**Failure Indicators**:
- ❌ Only `[CACHE HIT]` messages (cache not being bypassed)
- ❌ No metadata file created
- ❌ HTTP 500 errors due to invalid data

---

### Test 2: Cache Validation on Second Run

**Objective**: Verify that subsequent runs use cache validation (ETag checking) but respect force=True override

**Steps**:
1. Run the scheduler again (without clearing cache):
   ```bash
   python3 -u app.py 2>&1 | tee test_run_2.log
   ```

2. Monitor for either:
   - `[CACHE HIT] Using cached version: csv/...` (if B2 file unchanged, metadata matches)
   - `[CACHE MISS]` (if B2 file changed, ETag differs)

**Success Criteria**:
- ✅ Second run completes faster (cache validation instead of full download)
- ✅ Metadata file has been updated with timestamps
- ✅ Correct behavior: `force=True` forces download, but cache validation still checks ETags

---

### Test 3: PHP→Python Sync Workflow

**Objective**: Verify that when PHP updates data in B2, Python immediately uses the fresh data

**Steps**:

1. **Note the current state**:
   - Check current scheduler output
   - Note any room assignments or availability

2. **Modify data via PHP interface**:
   - Go to `http://localhost/scheduler/index.php`
   - Change something (add a room, modify availability)
   - The PHP script saves this to B2 via `save_rooms.php`

3. **Clear B2 cache in Python**:
   ```bash
   rm -rf temp/b2_cache/*
   ```

4. **Run scheduler immediately**:
   ```bash
   python3 app.py 2>&1 | tee test_sync.log
   ```

5. **Check the output**:
   - Review the generated schedule
   - Verify it includes the changes made in PHP

**Success Criteria**:
- ✅ Log shows `[B2] Force-refreshing all CSVs from B2`
- ✅ Generated schedule reflects PHP changes
- ✅ No HTTP 500 errors
- ✅ No stale data in output

**Failure Indicators**:
- ❌ Schedule doesn't reflect PHP changes
- ❌ "Using cached version" appears in logs (should be downloading)
- ❌ HTTP 500 error with "KeyError" or invalid data

---

### Test 4: Error Handling Improvements

**Objective**: Verify that exception handling is now visible in logs

**Steps**:
1. Simulate a temporary B2 connection issue:
   ```bash
   # Add this to the top of load_data.py temporarily to test error paths
   python3 -c "import os; os.environ['B2_SKIP_DOWNLOAD'] = '1'" && python3 app.py
   ```

2. Monitor logs for exception messages:
   ```
   [ERROR] B2CacheHandler error for ...
   [WARNING] force=True but cache was used for ...
   ```

3. Remove the test override and verify normal operation resumes

**Success Criteria**:
- ✅ Exceptions appear in logs with full details
- ✅ App gracefully continues or fails with clear error message
- ✅ No silent failures

---

## Performance Testing (Optional)

### Scenario: Cache Efficiency

**Test**:
- Time first run with force=True: `~2-5 seconds` (full B2 download)
- Time second run with metadata validation: `~0.5-1 seconds` (only ETag check)
- Cache should reduce API calls from ~10 to ~2-3 per run

**Run**:
```bash
time python3 app.py 2>&1 | head -20
```

**Expected**:
```
real    0m2.345s  # First run
real    0m0.789s  # Second run (with cache validation)
```

---

## Validation Checklist

Use this checklist to confirm everything is working:

### Code Changes ✓
- [ ] `load_data.py` uses `force=True` for CSV downloads
- [ ] `b2_handler.py` has try-catch blocks with logging
- [ ] `app.py` has specific exception types (no bare except)
- [ ] `b2_cache_handler.py` has all required methods (_load_metadata, _save_metadata, _get_b2_file_info, _is_file_cached)

### Functional Tests ✓
- [ ] First run downloads fresh files from B2
- [ ] Cache metadata file is created and updated
- [ ] Second run validates cache via ETag comparison
- [ ] PHP changes to CSVs are reflected in Python output
- [ ] Scheduler produces valid schedule (no 500 errors)
- [ ] Error messages are visible in logs (no silent failures)

### Performance ✓
- [ ] First run: 2-5 seconds (B2 download)
- [ ] Subsequent runs: <1 second (cache validation)
- [ ] B2 API calls reduced compared to before

---

## Debugging: Common Issues

### Issue 1: Still Seeing `[CACHE HIT]` Constantly
**Root Cause**: `force=True` not being passed to B2 download

**Solution**:
```bash
# Check load_data.py line 122
grep -n "download_folder.*force=" load_data.py

# Should see: download_folder("csv/", temp_dir, force=True)
# Not:        download_folder("csv/", temp_dir)
```

### Issue 2: Metadata File Not Created
**Root Cause**: B2CacheHandler not being used or cache directory missing

**Solution**:
```bash
# Ensure cache directory exists
mkdir -p temp/b2_cache

# Check B2 credentials are valid
python3 -c "from b2_handler import B2Handler; b = B2Handler(); print('✅ B2 connection OK')"
```

### Issue 3: Still Getting 500 Errors with Stale Data
**Root Cause**: Cache is still not being invalidated OR data in B2 itself is corrupt

**Solution**:
1. Clear cache completely: `rm -rf temp/b2_cache/*`
2. Re-upload CSV files from PHP interface
3. Check B2 bucket directly: `aws s3 ls s3://vvu-scheduler/csv/ --recursive`
4. Verify force=True being printed in logs

### Issue 4: Performance Degradation (Taking 30+ seconds)
**Root Cause**: force=True downloading all files every run (defeating cache)

**Solution**:
This is actually correct behavior! The fix prioritizes **data freshness** over speed.
- First run: Downloads all files (2-5s)
- Subsequent runs within same session: Cache validation (~1s)
- If you need faster subsequent runs, implement TTL-based caching

---

## Expected Log Output

### Healthy System (After Fix Applied)
```
[B2] Force-refreshing all CSVs from B2 (ignore cache)...
[B2] Downloading csv/general/rooms.csv with force=True
[B2] Downloading csv/general/special_rooms.csv with force=True
[B2] Downloading csv/general/availability.csv with force=True
[B2] Downloading csv/history/schedule.csv with force=True
[B2] Downloading csv/analytics/feedback.csv with force=True
[INFO] All CSV files loaded successfully
[INFO] Generating exam schedule...
[INFO] Schedule generation completed in 2.34 seconds
✓ Scheduler ready on http://localhost:5000
```

### Broken System (Before Fix)
```
[CACHE HIT] Using cached version: csv/general/rooms.csv
[CACHE HIT] Using cached version: csv/general/special_rooms.csv
[ERROR] Invalid room assignments (stale cache)
[ERROR] HTTP 500: Internal Server Error
```

---

## Next Steps After Validation

### If All Tests Pass ✅
1. Commit changes to version control
2. Deploy to production scheduler
3. Monitor logs for 24 hours to confirm stability
4. Document any issues encountered

### If Tests Fail ❌
1. Run `python3 validate_b2_fix.py` to identify what's wrong
2. Check that all 6 files were modified (see B2_CACHE_FIX_SUMMARY.md)
3. Review the git diff to see actual changes
4. Contact support with log excerpts

---

## References

- **Root Cause**: [DESIGN_ERRORS_ANALYSIS.md](DESIGN_ERRORS_ANALYSIS.md)
- **Implementation Details**: [B2_CACHE_FIX_SUMMARY.md](B2_CACHE_FIX_SUMMARY.md)
- **B2 Integration**: [b2_cache_handler.py](b2_cache_handler.py)
- **Data Loading**: [load_data.py](load_data.py#L110-L130)
