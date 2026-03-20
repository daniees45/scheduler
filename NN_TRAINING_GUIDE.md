# Training Neural Network Models for Scheduling

## Overview

You can now **explicitly train** Neural Network models and save them for reuse. This improves performance and allows you to build specialized models for different scheduling scenarios.

## Quick Start

### Train a Model from Historical Data

```bash
# Train with default settings (auto-detects historical_schedule.csv)
python3 train_nn_model.py

# Train with custom settings
python3 train_nn_model.py --epochs 50 --embedding-dim 64 --hidden-dim 256

# Train from specific CSV file
python3 train_nn_model.py --csv path/to/your/schedule_data.csv
```

### List Trained Models

```bash
python3 train_nn_model.py --list
```

### Use Trained Model in Your Code

```python
from timetable_engine.ai_unified_scheduler import AIUnifiedScheduler

scheduler = AIUnifiedScheduler(
    data_path=".",
    courses=courses,
    lecturers=lecturers,
    rooms=rooms,
    time_slots=time_slots,
    days=days,
    enable_nn=True
)

# Option 1: Auto-loads latest pre-trained model
schedule, quality, metadata = scheduler.schedule_with_nn()

# Option 2: Explicitly load a specific model
scheduler.load_nn_model("models/nn/nn_scheduler_20260302_123456.h5")
schedule, quality, metadata = scheduler.schedule_with_nn()
```

## How It Works

### Training Process

1. **Load Historical Data** - Reads from `temp/historical_schedule.csv` or your specified file
2. **Extract Entities** - Identifies unique courses, lecturers, rooms, time slots, days
3. **Initialize Model** - Creates NN architecture with specified dimensions
4. **Prepare Samples** - Converts historical records to training samples
   - Positive samples: Successful schedule assignments
   - Negative samples: Invalid/conflicting assignments
5. **Train Model** - Runs gradient descent to learn patterns
6. **Save Model** - Stores trained weights + metadata to `models/nn/`

### Model Files

After training, two files are created:

1. **Model weights**: `nn_scheduler_YYYYMMDD_HHMMSS.h5` (TensorFlow) or `.pkl` (scikit-learn)
2. **Metadata**: `nn_scheduler_YYYYMMDD_HHMMSS.json` containing:
   - Training stats (accuracy, loss, epochs)
   - Architecture details (embedding dim, hidden dim)
   - Data metadata (number of records, entities)
   - Timestamp and version info

### Auto-Loading Behavior

When you call `schedule_with_nn()`:

1. **Check if model already trained** → Use it
2. **Check for pre-trained models** → Load latest from `models/nn/`
3. **Check for historical data** → Auto-train from `historical_schedule.csv`
4. **Fall back to heuristics** → Use intelligent guessing

## Training Options

### Command-Line Arguments

```bash
python3 train_nn_model.py [OPTIONS]

Options:
  --data PATH           Data directory path (default: current directory)
  --csv PATH            Path to historical CSV file
  --epochs N            Number of training epochs (default: 50)
  --embedding-dim N     Embedding dimension (default: 32)
  --hidden-dim N        Hidden layer dimension (default: 128)
  --list                List all trained models
  --evaluate PATH       Evaluate a specific model
  --quiet               Reduce output verbosity
```

### Architecture Tuning

**Small/Fast Models** (good for < 50 courses):
```bash
python3 train_nn_model.py --embedding-dim 16 --hidden-dim 64 --epochs 30
```

**Medium Models** (50-200 courses):
```bash
python3 train_nn_model.py --embedding-dim 32 --hidden-dim 128 --epochs 50
```

**Large Models** (200+ courses):
```bash
python3 train_nn_model.py --embedding-dim 64 --hidden-dim 256 --epochs 100
```

## Best Practices

### 1. Generate Training Data First

Before training, build historical data:

```python
# Use other algorithms to generate initial schedules
scheduler = AIUnifiedScheduler(..., enable_ga=True, enable_rl=True)

# Generate multiple schedules
for i in range(10):
    schedule, _, _ = scheduler.schedule_with_ga()
    # Schedules auto-saved to historical_schedule.csv

# Now train on this data
```

```bash
python3 train_nn_model.py
```

### 2. Train Multiple Models for Different Scenarios

```bash
# Model for morning classes
python3 train_nn_model.py --csv data/morning_schedules.csv

# Model for afternoon classes  
python3 train_nn_model.py --csv data/afternoon_schedules.csv

# Model for specific department
python3 train_nn_model.py --csv data/cs_department.csv
```

### 3. Retrain Periodically

```bash
# Weekly: retrain with new data
python3 train_nn_model.py --epochs 50

# The new model becomes the default (most recent)
```

### 4. Save Custom Models

```python
from timetable_engine.ai_unified_scheduler import AIUnifiedScheduler

scheduler = AIUnifiedScheduler(...)

# Train or load model
schedule, _, _ = scheduler.schedule_with_nn()

# Save for later use
scheduler.save_nn_model("models/nn/my_custom_model.h5")
```

## Training Data Format

Your CSV should have these columns (from historical_schedule.csv):

```csv
course_code,course_title,lecturer,room,day,time,level,semester,credits,enrollment,capacity,room_type
CS101,Intro to CS,Dr. Smith,Room A,Monday,07:00 AM - 09:30 AM,100,1,3,45,50,Lecture
CS102,Programming,Dr. Jones,Lab 1,Tuesday,10:00 AM - 12:30 PM,100,1,3,30,30,Lab
...
```

**Minimum Requirements:**
- At least 10 records (50+ recommended)
- Valid course, lecturer, room, day, time data
- No missing critical fields

## Advanced Usage

### Python API

```python
from train_nn_model import NNModelTrainer

# Initialize trainer
trainer = NNModelTrainer(data_path=".", verbose=True)

# Train model
result = trainer.train_model(
    historical_csv="temp/historical_schedule.csv",
    epochs=50,
    embedding_dim=32,
    hidden_dim=128
)

if result:
    print(f"Model saved: {result['model_info']['model_file']}")
    print(f"Accuracy: {result['training_stats']['final_accuracy']:.2%}")

# List all models
models = trainer.list_models()
for model in models:
    print(f"{model['model_name']}: {model['training_stats']['final_accuracy']:.2%}")

# Evaluate model
trainer.evaluate_model("models/nn/nn_scheduler_20260302_123456.h5")
```

### Custom Training Data

```python
import pandas as pd
from train_nn_model import NNModelTrainer

# Create custom training data
data = {
    'course_code': ['CS101', 'CS102', 'MATH101'],
    'lecturer': ['Dr. A', 'Dr. B', 'Dr. C'],
    'room': ['R1', 'R2', 'R3'],
    'day': ['Monday', 'Tuesday', 'Wednesday'],
    'time': ['07:00 AM - 09:30 AM'] * 3,
    # ... other columns
}
df = pd.DataFrame(data)
df.to_csv('custom_training.csv', index=False)

# Train on custom data
trainer = NNModelTrainer()
result = trainer.train_model(historical_csv='custom_training.csv')
```

### Model Management

```python
from pathlib import Path

# Find all models
model_dir = Path("models/nn")
models = list(model_dir.glob("*.h5")) + list(model_dir.glob("*.pkl"))

# Load specific version
from timetable_engine.ai_unified_scheduler import AIUnifiedScheduler
scheduler = AIUnifiedScheduler(..., enable_nn=True)
scheduler.load_nn_model("models/nn/nn_scheduler_20260301_100000.h5")

# Generate with this specific model
schedule, quality, metadata = scheduler.schedule_with_nn()
```

## Troubleshooting

### "Training data not found"
**Solution**: Generate schedules first to create `temp/historical_schedule.csv`
```bash
# Use web interface or Python API to generate schedules
# Then run training
python3 train_nn_model.py
```

### "Insufficient data to train model"
**Solution**: Need at least 10 records, 50+ recommended
```bash
# Generate more schedules with GA/RL first
# Or combine multiple CSV files
```

### "Model file not found"
**Solution**: Check path and file extension
```python
# Correct: use full path with extension
scheduler.load_nn_model("models/nn/my_model.h5")

# Wrong: missing extension or path
scheduler.load_nn_model("my_model")
```

### Training is slow
**Solutions**:
- Reduce epochs: `--epochs 20`
- Reduce dimensions: `--embedding-dim 16 --hidden-dim 64`
- Use smaller training dataset
- Check CPU/GPU usage

### Low accuracy (< 80%)
**Solutions**:
- More training data (100+ records)
- More epochs: `--epochs 100`
- Larger model: `--embedding-dim 64 --hidden-dim 256`
- Check data quality (no errors/duplicates)

## Performance Tips

1. **Pre-train once, reuse many times** - Training takes time, inference is instant
2. **Start small, grow as needed** - Begin with 32/128 dimensions, scale up if needed
3. **Monitor accuracy** - 90%+ is good, 95%+ is excellent
4. **Update periodically** - Retrain monthly with new scheduling patterns
5. **Version your models** - Keep multiple versions for rollback

## Integration with Web Interface

### Backend (app.py)

```python
# Auto-loads latest pre-trained model
scheduler = AIUnifiedScheduler(..., enable_nn=True)
schedule, quality, metadata = scheduler.schedule_with_nn()

# Check if using pre-trained model
if metadata.get('training_stats', {}).get('status') == 'pre-trained':
    print(f"Using model: {metadata['training_stats']['model_name']}")
```

### Frontend Display

Show users which model is being used:
```javascript
if (metadata.training_stats && metadata.training_stats.status === 'pre-trained') {
    console.log(`Using pre-trained model: ${metadata.training_stats.model_name}`);
    // Display in UI: "Using AI Model: nn_scheduler_20260302_123456"
}
```

## Summary

**Before**: NN auto-trained every time (slow) or used heuristics (less accurate)  
**Now**: Train once, use forever. Pre-trained models load instantly.

**Workflow**:
1. Generate schedules → builds historical_schedule.csv
2. Train model → creates optimized NN model  
3. Deploy → model auto-loads on schedule generation
4. Enjoy → instant, high-quality schedules

**Benefits**:
- ⚡ Faster generation (pre-trained loads instantly)
- 🎯 Better quality (trained on your actual data)
- 🔧 Customizable (different models for different scenarios)
- 📊 Trackable (version history, accuracy metrics)
- 🔄 Reusable (train once, use many times)

---

**Ready to train?** Run: `python3 train_nn_model.py`
