# Design Errors Analysis - VVU Scheduler System

**Date**: March 12, 2026  
**Version**: Comprehensive Design Review

---

## 🔴 CRITICAL ERRORS

### 1. B2 Cache Invalidation Failure (HIGH SEVERITY)

**Problem**: PHP saves updated CSVs to B2, but Python layer uses stale cached versions

**Location**:
- [load_data.py](load_data.py#L110-L125): B2 download with caching enabled
- [b2_handler.py](b2_handler.py#L57): Cache checks before B2 validation
- [b2_cache_handler.py](b2_cache_handler.py): No metadata tracking for versions

**Root Cause**: 
```python
# load_data.py line 110
b2 = B2Handler(enable_cache=True, cache_dir="temp/b2_cache")
b2.download_folder("csv/", temp_dir)  # force=False (default)
```

The `force=False` parameter means cache is **always** used if file exists locally. No mechanism checks if B2 has a newer version.

**Design Defect**: Cache system lacks:
- ❌ File modification timestamp tracking
- ❌ B2 version/ETag comparison
- ❌ Automatic cache expiration strategy
- ❌ Cache invalidation signals from PHP uploads

**Evidence from logs**:
```
[CACHE HIT] Using cached version: csv/final/schedule_ml.csv
[CACHE HIT] Using cached version: csv/general/rooms.csv
[INFO] B2 Sync complete. Using temp files.
```

Despite `save_rooms.php` uploading to B2, scheduler uses old cache.

**Impact**:
- Room configurations don't update when changed in PHP interface
- Schedule generation uses outdated/incorrect data
- API returns HTTP 500 due to inconsistent data state

---

### 2. Missing Cache Metadata & Versioning (HIGH SEVERITY)

**Problem**: B2CacheHandler exists but `b2_cache_handler.py` is identical to `b2_handler.py`

**Location**: 
- [b2_cache_handler.py](b2_cache_handler.py#L1-50): File content identical to b2_handler.py
- [b2_handler.py](b2_handler.py#L6-16): References non-existent B2CacheHandler

**Code Analysis**:
```python
# b2_handler.py line 18
if enable_cache:
    try:
        from b2_cache_handler import B2CacheHandler
        self.cache_handler = B2CacheHandler(config_path, cache_dir)
```

But `b2_cache_handler.py` defines `B2Handler`, NOT `B2CacheHandler` → **Import fails silently**

**Design Defect**:
- ❌ Cache handler class incorrectly named/implemented
- ❌ No cache metadata file (cache_metadata.json) to track file versions
- ❌ No ETag/timestamp comparison mechanism
- ❌ Error silently falls back to non-cache mode

**Impact**: 
Caching is supposed to be enabled but silently fails, causing unexpected behavior.

---

### 3. Loose Exception Handling (MEDIUM SEVERITY)

**Problem**: Cache import failures silently ignored without logging actual cause

**Location**: [b2_handler.py](b2_handler.py#L13-16)

```python
if enable_cache:
    try:
        from b2_cache_handler import B2CacheHandler
        self.cache_handler = B2CacheHandler(config_path, cache_dir)
    except Exception as e:
        print(f"[WARNING] Failed to enable B2 caching, falling back to direct mode: {e}")
        self.enable_cache = False  # Silent degradation - users don't know caching failed
```

**Design Defect**:
- ❌ Exception caught but behavior changed silently (enable_cache set to False)
- ❌ No indication whether B2CacheHandler exists or method signature wrong
- ❌ Users/operators can't diagnose why cache isn't working
- ❌ Inconsistent retry logic: should try non-cache or fail loudly?

**Pattern Problem**: Multiple bare `except Exception` blocks in app.py (lines 90, 217, 229)

---

### 4. Architectural Violation: PHP↔Python Data Sync (MEDIUM SEVERITY)

**Problem**: Bidirectional sync between PHP (save_rooms.php) and Python (load_data.py) lacks coordination

**Design Flow**:
```
PHP: save_rooms.php → Upload to B2
                   ↓
Python: load_data.py → Download from B2 (but uses stale cache!)
                    ↓
                Python loads outdated data
       Scheduler produces wrong results
```

**Root Cause - No Sync Protocol**:
- ❌ PHP doesn't signal Python that files were updated
- ❌ Python doesn't validate cache freshness
- ❌ No "cache buster" mechanism (query params, version numbers, timestamps)
- ❌ No health checks comparing PHP uploaded vs Python loaded file hashes

**Location**:
- PHP: `web/api/save_rooms.php` → uploads to B2
- Python: `load_data.py` line 110-125 → downloads with cache
- No coordination between the two

**Design Pattern Needed**: 
- Shared cache metadata file in B2 with file timestamps/hashes
- OR force=True on critical files after PHP uploads
- OR implement cache expiration policy (TTL-based invalidation)

---

### 5. Type Inconsistency: Department Parameter (MEDIUM SEVERITY)

**Problem**: Department passed as both **string** and **ID** across layers

**Location**:
- [app.py](app.py#L350): `department: dept` (from string selector)
- [main_web.py](main_web.py#L45): `department="1"` parameter documented as ID
- [builder.py](builder.py#L60): Department used for room file matching (expects string like "CS/IT/BBIS")

**Evidence**:
```python
# main_web.py line 45
department="1",  # ← Comments say it's an ID

# app.py line 350  
dept = data.get('department', 'General')  # ← Actual value is "CS/IT/BBIS" string

# load_data.py (implicit)
get_department_room_file(department)  # ← Expects string like "CS/IT/BBIS"
```

**Design Defect**:
- ❌ Ambiguous parameter semantics (string vs ID)
- ❌ No type hints or documentation consistency
- ❌ Silent type coercion in several places
- ❌ Potential for wrong room file loading if ID format changes

---

### 6. Missing Error Recovery in CSP Solver (MEDIUM SEVERITY)

**Problem**: HTTP 500 error when CSP solver times out / fails, but no graceful degradation

**Location**: [app.py](app.py#L517)

```python
except Exception as e:
    remote_log("SCHEDULE_GEN_ERROR", str(e), "error")
    save_progress(job_id, "error", 0, 0, str(e))
    return jsonify({"status": "error", "message": str(e)}), 500  # ← Raw 500
```

**Design Defect**:
- ❌ No cascade to genetic algorithm or RL fallback
- ❌ No partial solution (best-effort subset of courses)
- ❌ 500 error instead of user-friendly message with recommendations
- ❌ Error message exposed to frontend (security: stack traces leak internals)

**Expected Pattern**:
```
CSP timeout
  ↓ (log and fallback)
Try Genetic Algorithm
  ↓ (if failed, timeout)
Try Simulated Annealing
  ↓ (if failed)
Return partial solution + warnings
```

---

### 7. Unused Import Statements (LOW SEVERITY)

**Location**: [app.py](app.py#L1-70)

Multiple imports wrapped in try-except but never validated:
```python
try:
    from main_web import run_headless
except ImportError as e:
    run_headless = None

# Later in code, used without null checks in many places
```

**Problem**:
- ❌ `run_headless` could be None but no guards in `/generate` endpoint
- ❌ Bare except blocks (line 217, 229, 579) catch all exceptions including KeyboardInterrupt
- ❌ No logging of import failures to help debug missing modules

---

## 🟡 MODERATE ERRORS

### 8. Configuration Parameter Explosion (MAINTAINABILITY)

**Location**: [main_web.py](main_web.py#L32-39)

```python
def run_headless(
    input_file, mode_choice, output_file, ai_preference,
    course_type="Departmental",
    department="1",
    availability_mode="1",
    exam_mode=False,
    model="csp",
    general_schedule_path=None,
    progress_session_id=None,
    weight_room=10.0,           # ← Magic numbers
    weight_lecturer=5.0,        # ← No validation
    weight_balance=8.0,         # ← No constraints
    progress_callback=None
):
```

**Design Defect**:
- ❌ 14+ parameters with defaults and unclear semantics
- ❌ Magic numbers not validated or constrained
- ❌ No configuration object/dataclass
- ❌ Weight parameters range unclear (1-20? 0-1? percent?)

**Refactor**: Use dataclass or config dict pattern

---

### 9. Data Flow Complexity: Session Data Handling (MAINTAINABILITY)

**Location**: [app.py](app.py#L295-320)

```python
use_session = data.get('use_session', False)

if use_session:
    csv_content = data.get('csv_content')
    if csv_content:
        tmp_file = tempfile.NamedTemporaryFile(...)
        # Write as CSV or string?
        if isinstance(csv_content, list):
            writer = csv.writer(tmp_file)
            for row in csv_content:
                if isinstance(row, list):
                    writer.writerow(row)
                elif isinstance(row, tuple):
                    writer.writerow(list(row))
                else:
                    writer.writerow([row])
        else:
            tmp_file.write(str(csv_content))
```

**Design Defect**:
- ❌ Type checking chain instead of validation schema
- ❌ Implicit format coercion (tuple→list→string)
- ❌ No validation of CSV structure or required columns
- ❌ Multiple code paths for same logical operation

---

### 10. Bare Exceptions Anti-Pattern (CODE QUALITY)

**Locations**: [app.py](app.py#L217-229)

```python
try:
    classifier = get_classifier()
    status["classifier_accuracy"] = classifier.last_accuracy
except:  # ← Bare except without type or logging
    pass
```

**Design Defects**:
- ❌ Bare `except:` catches KeyboardInterrupt, SystemExit
- ❌ No exception type specified → catches too much
- ❌ Silent pass without logging
- ❌ Difficult to debug when classifier loading fails

**Python Best Practices Violated**:
- PEP 8 discourages bare except
- Makes debugging impossible
- Can mask real errors

---

## 🟢 SUGGESTIONS TO FIX

### Priority 1 (URGENT - Fix First)

#### A. Implement Cache Versioning

Create `cache_metadata.json` in B2:
```json
{
  "csv/general/rooms.csv": {
    "etag": "abc123def456",
    "modified": "2026-03-12T08:20:00Z",
    "version": 3
  }
}
```

Update `b2_handler.py` to:
1. Download metadata on startup
2. Compare local file ETag/timestamp with B2
3. Invalidate cache if mismatch

#### B. Fix B2CacheHandler Import

Rename class in `b2_cache_handler.py`:
```python
# CORRECT implementation with metadata tracking
class B2CacheHandler:
    def __init__(self, config_path, cache_dir, metadata_file=None):
        self.metadata = self._load_metadata(metadata_file)
        # ...check file versions
```

#### C. Add Force-Fresh Downloads for Critical Files

In `load_data.py` line 122:
```python
# CRITICAL data must always be fresh from B2
b2.download_folder("csv/", temp_dir, force=True)  # Changed!
b2.download_file("csv/general/rooms.csv", path, force=True)
b2.download_file("csv/general/special_rooms.csv", path, force=True)
```

### Priority 2 (HIGH)

#### D. Implement Cascade Fallbacks

```python
# app.py generate() endpoint
success, accuracy = run_headless(...)
if not success and model == "csp":
    # Try genetic algorithm as backup
    success, accuracy = run_headless(..., model="ga")
if not success:
    # Try ensemble as final fallback
    success, accuracy = run_headless(..., model="ensemble")
```

#### E. Add Type Hints & Validation

```python
from dataclasses import dataclass
from typing import Optional, Literal

@dataclass
class ScheduleConfig:
    course_type: Literal["Departmental", "General"]
    department: str  # "CS/IT/BBIS", "General", etc.
    weight_room: float  # Range: 1-20
    weight_lecturer: float  # Range: 1-20
    weight_balance: float  # Range: 1-20
```

---

## SUMMARY TABLE

| Error | Severity | Component | Fix Effort | Impact |
|-------|----------|-----------|-----------|--------|
| Cache invalidation | 🔴 CRITICAL | B2Handler | 2h | Data freshness |
| Missing B2CacheHandler | 🔴 CRITICAL | b2_handler.py | 1h | Cache non-functional |
| Type inconsistency (dept) | 🟡 MEDIUM | app.py/main_web.py | 1.5h | Room selection bugs |
| Bare exceptions | 🟡 MEDIUM | app.py | 1h | Debugging issues |
| Config explosion | 🟡 MEDIUM | main_web.py | 2h | Maintainability |
| No fallback solvers | 🟡 MEDIUM | app.py | 3h | Robustness |
| Session data handling | 🟡 MEDIUM | app.py | 2h | Type safety |

---

## RECOMMENDATIONS

1. **Immediate**: Implement cache versioning + force-fresh downloads
2. **Within sprint**: Fix B2CacheHandler class definition
3. **Refactor**: Replace bare except with specific types + logging
4. **Architecture**: Add cache invalidation protocol between PHP and Python
5. **Testing**: Add integration tests for B2 sync workflow

