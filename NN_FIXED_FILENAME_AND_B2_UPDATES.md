# Neural Network Fixed Filename & B2 Updates

## Summary
Fixed the NN model to use a fixed filename instead of timestamps, verified historical schedule appending, and ensured B2 uploads for all models and historical data.

## Changes Made

### 1. Fixed NN Model Filename (train_nn_model.py)

**Previous Behavior:**
- Models saved with timestamp: `nn_scheduler_20260302_051447.h5`
- Created multiple model versions that cluttered the directory
- Hard to reference specific model files

**New Behavior:**
- Models saved with fixed filename: `nn_scheduler.h5` (or `.pkl`)
- Single model file that gets overwritten on retraining
- Metadata tracks last training timestamp in `nn_scheduler.json`

**Code Changes:**
```python
# Line 282-283: Changed from timestamp to fixed name
# OLD:
timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
model_name = f"nn_scheduler_{timestamp}"

# NEW:
model_name = "nn_scheduler"

# Line 299: Added timestamp tracking in metadata
'last_trained': timestamp,  # Instead of 'timestamp'
```

**Files Modified:**
- [train_nn_model.py](train_nn_model.py#L282-L283)
- [train_nn_model.py](train_nn_model.py#L299)

---

### 2. Updated Model Loading (ai_unified_scheduler.py)

**Previous Behavior:**
- Used glob pattern to find latest model: `glob("*.h5")`
- Selected based on modification time
- Could load outdated models

**New Behavior:**
- Looks for specific fixed filenames:
  - `models/nn/nn_scheduler.h5` (TensorFlow)
  - `models/nn/nn_scheduler.pkl` (sklearn fallback)
- Prioritizes .h5 over .pkl
- Cleaner, more predictable model loading

**Code Changes:**
```python
# Lines 477-492: Changed from glob pattern to fixed filename lookup
# OLD:
model_files = list(model_dir.glob("*.h5")) + list(model_dir.glob("*.pkl"))
if model_files:
    latest_model = max(model_files, key=lambda p: p.stat().st_mtime)

# NEW:
model_h5 = model_dir / "nn_scheduler.h5"
model_pkl = model_dir / "nn_scheduler.pkl"
latest_model = None

if model_h5.exists():
    latest_model = model_h5
elif model_pkl.exists():
    latest_model = model_pkl
```

**Files Modified:**
- [ai_unified_scheduler.py](timetable_engine/ai_unified_scheduler.py#L477-L492)

---

### 3. Historical Schedule Appending - Verified ✓

**Class Timetable (main.py):**
- Lines 564-573: Appends every generated schedule to `historical_schedule.csv`
- Creates timestamped versions: `historical_schedule_20260302_051447.csv`
- Appends to master cumulative file for training

**Exam Timetable (exam_main_web.py):**
- Lines 136-145: Appends every generated exam schedule to `historical_exam_schedule.csv`
- Creates timestamped versions: `historical_exam_schedule_20260302_051447.csv`
- Appends to master cumulative file for training

**Verification Status:** ✅ WORKING
- Both class and exam schedules are automatically appended to historical files
- Works regardless of scheduling method (CSP, GA, RL, NN)

---

### 4. B2 Cloud Storage - Enhanced

#### A. Model Upload to B2 (main.py)

**Previous Behavior:**
- Uploaded: `scheduling_model.pkl`, `q_model.pkl`, `feasibility_classifier.pkl`
- NN model NOT uploaded

**New Behavior:**
- Added NN model upload with fixed filename
- Uploads both model and metadata files

**Code Changes:**
```python
# Lines 617-632: Added NN model upload
# Upload NN model if it exists (fixed filename)
nn_model_h5 = "models/nn/nn_scheduler.h5"
nn_model_pkl = "models/nn/nn_scheduler.pkl"
nn_model_meta = "models/nn/nn_scheduler.json"
if os.path.exists(nn_model_h5):
    b2.upload_file(nn_model_h5, "models/nn_scheduler.h5")
    print(f"[B2] Uploaded NN model: models/nn_scheduler.h5")
elif os.path.exists(nn_model_pkl):
    b2.upload_file(nn_model_pkl, "models/nn_scheduler.pkl")
    print(f"[B2] Uploaded NN model: models/nn_scheduler.pkl")
if os.path.exists(nn_model_meta):
    b2.upload_file(nn_model_meta, "models/nn_scheduler.json")
    print(f"[B2] Uploaded NN metadata: models/nn_scheduler.json")
```

**B2 Paths:**
- `models/nn_scheduler.h5` (or `.pkl`) - NN model
- `models/nn_scheduler.json` - Model metadata

**Files Modified:**
- [main.py](main.py#L617-L632)

---

#### B. Model Download from B2 (main.py)

**Previous Behavior:**
- Downloaded: `scheduling_model.pkl`, `q_model.pkl`, `feasibility_classifier.pkl`
- NN model NOT downloaded on startup

**New Behavior:**
- Downloads NN model and metadata on startup if available
- Creates `models/nn/` directory if not exists
- Tries both .h5 and .pkl formats

**Code Changes:**
```python
# Lines 256-276: Added NN model download
# Download NN model if exists (try both .h5 and .pkl)
nn_model_dir = "models/nn"
os.makedirs(nn_model_dir, exist_ok=True)
if not os.path.exists(f"{nn_model_dir}/nn_scheduler.h5"):
    try:
        b2.download_file("models/nn_scheduler.h5", f"{nn_model_dir}/nn_scheduler.h5")
        print(f"[B2] Downloaded NN model: nn_scheduler.h5")
    except:
        pass  # Model might not exist yet or might be .pkl
if not os.path.exists(f"{nn_model_dir}/nn_scheduler.pkl"):
    try:
        b2.download_file("models/nn_scheduler.pkl", f"{nn_model_dir}/nn_scheduler.pkl")
        print(f"[B2] Downloaded NN model: nn_scheduler.pkl")
    except:
        pass
if not os.path.exists(f"{nn_model_dir}/nn_scheduler.json"):
    try:
        b2.download_file("models/nn_scheduler.json", f"{nn_model_dir}/nn_scheduler.json")
        print(f"[B2] Downloaded NN metadata: nn_scheduler.json")
    except:
        pass
```

**Files Modified:**
- [main.py](main.py#L256-L276)

---

#### C. Historical Schedule Upload - Verified ✓

**Class Timetable:**
- Uploads timestamped version: `csv/history/historical_schedule_20260302_051447.csv`
- Uploads master cumulative: `csv/general/historical_schedule.csv`

**Exam Timetable:**
- Uploads timestamped version: `csv/history/exam_historical_exam_schedule_20260302_051447.csv`
- Uploads master cumulative: `csv/general/historical_exam_schedule.csv`

**Verification Status:** ✅ WORKING
- All historical files uploaded to B2 after each schedule generation
- Both timestamped versions (backup) and master files (training data)

---

## Benefits

### 1. Simplified Model Management
- ✅ No more cluttered model directories with timestamped files
- ✅ Always know which model is active: `nn_scheduler.h5`
- ✅ Training history preserved in `nn_scheduler.json` metadata

### 2. Reliable Model Persistence
- ✅ NN models automatically backed up to B2 cloud storage
- ✅ Models synced across deployments
- ✅ Metadata includes training timestamp and architecture details

### 3. Complete Historical Data Tracking
- ✅ Every class schedule appended to `historical_schedule.csv`
- ✅ Every exam schedule appended to `historical_exam_schedule.csv`
- ✅ All historical data uploaded to B2
- ✅ Timestamped versions for version control

### 4. Cloud-First Architecture
- ✅ Models persist across server restarts
- ✅ Historical data available for distributed training
- ✅ Automatic backup and restore functionality

---

## Usage

### Training a New NN Model

```bash
# Train with historical data
python3 train_nn_model.py

# Output:
# ✓ Model saved: models/nn/nn_scheduler.h5
# ✓ Metadata saved: models/nn/nn_scheduler.json
```

The model will be auto-uploaded to B2 on next schedule generation.

### Manual B2 Sync

Historical data and models auto-sync after every schedule generation:
- Class: `main.py` (lines 600-636)
- Exam: `exam_main_web.py` (lines 147-165)

### Checking Model Status

```python
import json
with open('models/nn/nn_scheduler.json') as f:
    meta = json.load(f)
    print(f"Last trained: {meta['last_trained']}")
    print(f"Accuracy: {meta['training_stats']['final_accuracy']}")
    print(f"Courses: {meta['architecture']['num_courses']}")
```

---

## Migration Notes

### For Existing Installations

1. **Old timestamped models still work** but will be ignored
2. **Next training will create fixed filename**
3. **B2 upload starts immediately** on next schedule generation

### Cleanup (Optional)

```bash
# Remove old timestamped models (keep latest for backup)
cd models/nn/
ls -t nn_scheduler_*.h5 | tail -n +2 | xargs rm -f
ls -t nn_scheduler_*.pkl | tail -n +2 | xargs rm -f
```

---

## Testing Checklist

- [x] NN model saves with fixed filename
- [x] Model loading finds fixed filename
- [x] Historical schedule appending works
- [x] B2 upload for NN models
- [x] B2 download for NN models on startup
- [x] B2 upload for historical schedules (already working)
- [x] Metadata tracks last training timestamp
- [x] Compatible with existing compatibility fix (NN_COMPATIBILITY_FIXED.md)

---

## Files Modified

1. **train_nn_model.py** - Fixed model filename
2. **ai_unified_scheduler.py** - Updated model loading
3. **main.py** - Added NN model B2 upload/download

## Files Verified (No Changes Needed)

1. **main.py** - Historical appending already working ✓
2. **exam_main_web.py** - Exam historical appending already working ✓

---

## Related Documentation

- [NN_COMPATIBILITY_FIXED.md](NN_COMPATIBILITY_FIXED.md) - Model dimension compatibility
- [HISTORICAL_STORAGE_CHANGES.md](HISTORICAL_STORAGE_CHANGES.md) - Historical data architecture
- [b2_handler.py](b2_handler.py) - B2 cloud storage implementation

---

**Implementation Date:** 2026-03-02  
**Status:** ✅ COMPLETE  
**Tested:** Compatible with existing NN compatibility fix
