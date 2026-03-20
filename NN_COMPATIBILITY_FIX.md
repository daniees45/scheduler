# Neural Network Model Compatibility Fix

## Problem
The pre-trained NN model was trained on 15 courses but you tried to schedule 37 courses. The model's embeddings are fixed-size and can't handle courses outside the trained range.

## Solution Implemented  

### 1. Model Compatibility Checking
Added `is_model_compatible()` method that checks if loaded model can handle the current problem size:
- Compares trained dimensions vs current dimensions
- Returns false if current problem is larger

### 2. Dimension Tracking
Models now save and load dimension metadata:
- `_meta.json` file stores: num_courses, num_lecturers, num_rooms, num_slots
- Checked before using pre-trained model

### 3. Graceful Fallback
When model is incompatible:
- **predict_validity()** - Falls back to heuristics for out-of-range indices
- **schedule()** - Shows clear warning message
- **ai_unified_scheduler** - Attempts auto-training or skips NN

### 4. Clear User Messages
```
[NN] ⚠ Model incompatible with current problem size - using heuristics
[NN]    Model trained for: 15 courses, 14 lecturers, 6 rooms
[NN]    Current problem: 37 courses, 20 lecturers, 10 rooms
[NN]    Tip: Retrain model with current data: python3 train_nn_model.py
```

## How to Fix Your Specific Issue

### Option 1: Use Different Algorithm (Immediate Fix)
Instead of NN, use:
- **Genetic Algorithm** - Works for any problem size
- **Reinforcement Learning** - Also handles any size
- **Ensemble** - Combines multiple approaches

### Option 2: Retrain Model (Permanent Fix)

Your current model was trained on historical data with only 15 courses. To handle 37 courses, you need to retrain with representative data. However, since historical_schedule.csv also only has 15 courses, you need to:

1. **Generate schedules with other algorithms first:**
   ```bash
   # Use GA or RL to generate schedules with all 37 courses
   # This builds historical_schedule.csv with complete data
   ```

2. **Then retrain NN:**
   ```bash
   python3 train_nn_model.py --epochs 20
   ```

### Option 3: Use NN with Heuristic Fallback (Current Behavior)
The NN now automatically falls back to heuristics for courses it can't handle. This means:
- ✅ No crashes
- ✅ Schedules still generate
- ⚠ Quality may be lower than pure NN

## Technical Details

### What Changed

**neural_network_scheduler.py:**
```python
# Track model dimensions
self.model_num_courses = self.num_courses
self.model_num_lecturers = self.num_lecturers
self.model_num_rooms = self.num_rooms
self.model_num_slots = self.num_slots

# Check compatibility
def is_model_compatible(self) -> bool:
    return (
        self.model_num_courses >= self.num_courses and
        self.model_num_lecturers >= self.num_lecturers and
        self.model_num_rooms >= self.num_rooms and
        self.model_num_slots >= self.num_slots
    )

# Fall back for out-of-range indices
def predict_validity(...):
    if (course_idx >= self.model_num_courses ...):
        # Use heuristic instead of model
        return heuristic_confidence()
```

**ai_unified_scheduler.py:**
```python
# Check compatibility before using pre-trained model
if model_loaded and scheduler.is_model_compatible():
    # Use model
else:
    # Fall back to auto-training or skip
```

## What Happens Now

### When You Generate with NN (37 courses):

1. **Load Phase:**
   - Finds pre-trained model: `nn_scheduler_20260302_051447.h5`
   - Loads metadata: 15 courses trained
   - Checks compatibility: 37 > 15 → INCOMPATIBLE

2. **Fallback Phase:**
   - Shows warning message
   - Uses heuristics for predictions
   - Generates schedule successfully
   - No crashes!

3. **Result:**
   - Schedule completes
   - Quality may be lower than trained NN
   - But works reliably

## Recommendation

For production use with 37 courses:

1. **Short term**: Use **Genetic Algorithm** or **Ensemble** methods
   - These don't have size limitations
   - Work great for any problem size
   - Proven reliable in your system

2. **Long term**: Build proper NN training data
   - Generate 50-100 schedules with GA/RL first
   - These populate historical_schedule.csv
   - Then retrain NN with complete data
   - NN will work perfectly for 37+ courses

## Testing

The fix has been tested and:
- ✅ Model compatibility checking works
- ✅ Heuristic fallback prevents crashes  
- ✅ Clear warning messages displayed
- ✅ Schedules generate successfully
- ✅ No 500 errors

## Files Modified
- `timetable_engine/neural_network_scheduler.py`
  - Added `is_model_compatible()`, dimension tracking, heuristic fallback
- `timetable_engine/ai_unified_scheduler.py`
  - Added compatibility check before loading pre-trained models
- `train_nn_model.py`
  - Uses scheduler's save_model() which includes metadata

Your scheduling system should now work without crashes! 🎉
