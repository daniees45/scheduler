"""
INTELLIGENT SCHEDULING SYSTEM - SELF-LEARNING MODE
Complete System Documentation
"""

## OVERVIEW
The system now includes advanced self-learning capabilities with ML accuracy 
prediction, conflict detection, and intelligent workflow management.

## KEY FEATURES IMPLEMENTED

### 1. SPECIAL ROOMS LOCK ENFORCEMENT
- Location: timetable_engine/csp_solver.py
- Ensures courses with special room constraints are always scheduled in their 
  designated rooms
- Enforcement in: is_consistent() method and special room tracking
- Tracking saved to: history/special_room_tracking.json

### 2. GENERAL VS DEPARTMENT COURSE BLOCKING
- Location: timetable_engine/course_dependency_manager.py
- Detects when both general and department courses of same level/semester exist
- Prompts user to schedule general courses FIRST
- Prevents conflicts by separating course types into different time blocks
- General courses: 7:00am-9:30am, 10:00am-12:30pm
- Department courses: 2:00pm-4:30pm, 5:00pm-6:00pm

### 3. HISTORICAL DATA LEARNING
- Location: timetable_engine/historical_data_analyzer.py
- Records every schedule generation in history/schedule_instances.jsonl
- Analyzes patterns in:
  - Time slot popularity
  - Conflict patterns by level/semester
  - Special room effectiveness
  - Lecturer availability patterns
- Generates recommendations based on historical data
- Stores insights in:
  - history/time_patterns.json
  - history/conflict_patterns.json
  - history/special_room_tracking.json

### 4. SKLEARN-BASED ACCURACY MODEL
- Location: timetable_engine/schedule_accuracy_predictor.py
- Machine Learning Model: RandomForestClassifier
- Features engineered from schedule attributes:
  - Time slot (early/mid/late)
  - Day of week (0-4 scale)
  - Course level (1-4)
  - Semester (1-2)
  - Lecturer experience approximation
  - Room capacity ratio
  - Special constraint flag

### 5. SELF-LEARNING SYSTEM
- Automatically learns from each schedule generation
- Collects training data on successful vs conflicting placements
- Model metrics tracked in history/model_metrics.json
- Continuously improves prediction accuracy
- Recommendations adapt based on historical success/failure patterns

### 6. INTELLIGENT WORKFLOW
- Location: timetable_engine/intelligent_interface.py
- Two-phase scheduling for mixed course types:
  - Phase 1: Schedule general courses (no department conflicts)
  - Phase 2: Schedule department courses with conflict detection
- Interactive prompts guide users through the process
- ML-based quality feedback after each generation

## CORE COMPONENTS

### CourseDependencyManager
```python
from timetable_engine.course_dependency_manager import CourseDependencyManager

dm = CourseDependencyManager()
dm.register_courses(courses)
should_order, message = dm.check_scheduling_order()

# Get courses by type and level/semester
general = dm.get_general_courses_for_level_sem(1, 1)
dept = dm.get_dept_courses_for_level_sem(1, 1)

# Predict conflicts using historical data
conflicts, confidence = dm.predict_conflicts(course, time_slot, day)
```

### HistoricalDataAnalyzer
```python
from timetable_engine.historical_data_analyzer import HistoricalDataAnalyzer

analyzer = HistoricalDataAnalyzer()

# Get learning insights
insights = analyzer.get_learning_insights()

# Record new schedule
analyzer.record_schedule(schedule_items, conflicts)

# Analyze patterns
time_patterns = analyzer.analyze_time_slot_patterns()
conflict_patterns = analyzer.analyze_conflict_patterns()
room_usage = analyzer.analyze_special_room_usage()

# Save insights to history
analyzer.save_insights()
```

### ScheduleAccuracyPredictor (ML Model)
```python
from timetable_engine.schedule_accuracy_predictor import ScheduleAccuracyPredictor

predictor = ScheduleAccuracyPredictor()

# Get model status
status = predictor.get_model_status()

# Predict conflict likelihood
conflict_prob, metrics = predictor.predict_conflict_likelihood(schedule_entry)

# Predict schedule quality
quality = predictor.predict_schedule_quality(schedule_items)

# Train on feedback
feedback = predictor.train_on_feedback(training_data)
```

## USAGE WORKFLOWS

### Standard Workflow (Non-Interactive)
```python
from timetable_engine.school_scheduler import SchoolScheduler

scheduler = SchoolScheduler(".")
schedule = scheduler.generate(dept_filter=None, interactive=False)
scheduler.save_schedule(schedule, "final/general.csv")
```

### Intelligent Workflow (Interactive with Learning)
```python
scheduler = SchoolScheduler(".")
schedule, quality = scheduler.generate_with_accuracy_feedback(
    dept_filter=None, 
    interactive=True
)
print(f"Quality Score: {quality['overall_score']:.1%}")
print(f"Grade: {quality['grade']}")
scheduler.save_schedule(schedule, "final/general.csv")
```

### Command Line Interface
```bash
python -m timetable_engine.intelligent_interface .
```

Menu options:
1. Generate Schedule (Standard)
2. Generate Schedule (With ML Quality Feedback)
3. View Learning Insights
4. Check Model Status
5. Schedule Specific Department
6. Exit

## QUALITY METRICS

The system provides accuracy grades:
- A: 90%+ confidence (excellent schedule)
- B: 80-89% confidence (good schedule)
- C: 70-79% confidence (acceptable schedule)
- D: 60-69% confidence (poor schedule)
- F: <60% confidence (needs review)

## HISTORY AND LEARNING DATA

Stored in ./history/ directory:
- schedule_instances.jsonl: All generated schedules (JSONL format)
- conflict_patterns.json: Known conflict patterns
- time_patterns.json: Time slot usage patterns
- special_room_tracking.json: Special room effectiveness
- model_metrics.json: ML model performance metrics
- daily_distribution.json: Day load distribution insights
- lecturer_patterns.json: Lecturer availability patterns
- room_patterns.json: Room utilization patterns

## SPECIAL ROOM ENFORCEMENT

Special room constraints are:
1. Loaded from special_rooms.csv on every schedule generation
2. Enforced as HARD constraints (must be satisfied)
3. Tracked for historical analysis
4. Considered for priority scheduling
5. Recorded with success/failure metrics

Courses with special room constraints are scheduled FIRST
to ensure their assignments don't fail.

## CONFLICT DETECTION

The system identifies and tracks:
1. Lecturer double-booking (same person, same time/day)
2. Room conflicts (same room, same time/day)
3. Level/semester conflicts (same level/sem course at same time/day)
4. Department blocking violations
5. Special room constraint violations
6. Availability violations (lecturer not available)

## DEPENDENCIES

Required:
- Python 3.7+
- pandas (for data analysis)

Optional but recommended:
- scikit-learn (for ML accuracy prediction)
  Install with: pip install scikit-learn

## PERFORMANCE NOTES

- First generation: ~2-5 seconds
- Subsequent generations: ~1-3 seconds (cached data)
- Learning model training: ~0.5-1 second per 100 schedules
- Historical analysis: ~0.1-0.3 seconds

## ASSUMPTIONS

1. Special rooms.csv contains definitive room assignments
2. Historical data builds progressively with each generation
3. Course metadata (level, semester, type) is accurate
4. Lecturer availability is defined in lecturer_availability.csv
5. Department identification is based on course code prefixes

## TROUBLESHOOTING

If sklearn is not installed:
- System still works, but ML features disabled
- Install with: pip install scikit-learn
- Then regenerate a schedule to retrain

If history directory becomes corrupted:
- Delete history/ directory
- Next generation will create fresh history
- Recommendations will restart learning process

If model performance is poor:
- More schedule generations improve accuracy
- System learns from successes and failures
- Monitor metrics in history/model_metrics.json
"""

# This is a reference document - can be viewed as:
# python -c "from timetable_engine import documentation; print(documentation.__doc__)"
