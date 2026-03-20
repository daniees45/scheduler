"""
QUICK REFERENCE - AI UNIFIED SCHEDULER
Essential information for using the integrated AI system
"""

# ============================================================================
# QUICK START (5 MINUTES)
# ============================================================================

"""
Minimal example to get scheduling running:

from timetable_engine.ai_unified_scheduler import AIUnifiedScheduler

# Prepare data
courses = [{'code': 'CS101', 'name': 'Intro CS'}, ]
lecturers = ['Dr. Smith']
rooms = [{'name': 'LR1', 'capacity': 100}]
time_slots = ['7:00am - 9:30am']

# Create scheduler
scheduler = AIUnifiedScheduler(
    courses=courses,
    lecturers=lecturers,
    rooms=rooms,
    time_slots=time_slots
)

# Run all schedulers and get best
results = scheduler.schedule_all()
print(f"Best method: {results['best_method']}")
print(f"Quality: {results['best_score']:.2%}")
"""


# ============================================================================
# FILE STRUCTURE
# ============================================================================

FILE_STRUCTURE = """
timetable_engine/
├── ai_unified_scheduler.py           [★ MAIN INTEGRATION FILE]
│   └── AIUnifiedScheduler class
│       ├── schedule_with_ga()        [Genetic Algorithm]
│       ├── schedule_with_rl()        [Reinforcement Learning]
│       ├── schedule_with_nn()        [Neural Networks]
│       ├── schedule_with_ensemble()  [ML Ensemble]
│       ├── schedule_all()            [Run all 4]
│       ├── get_best_schedule()       [Return best]
│       └── save_results()            [Save to JSON]
│
├── genetic_algorithm_optimizer.py    [GA IMPLEMENTATION]
│   └── GeneticAlgorithmScheduler
│       ├── evolve()                  [Main GA loop]
│       └── get_best_schedule()       [Extract schedule]
│
├── reinforcement_learning_scheduler.py [RL IMPLEMENTATION]
│   └── ReinforcementLearningScheduler
│       ├── train()                   [RL training]
│       └── get_schedule()            [Extract schedule]
│
├── neural_network_scheduler.py       [NN IMPLEMENTATION]
│   └── NeuralNetworkScheduler
│       ├── train()                   [NN training]
│       ├── schedule()                [Generate schedule]
│       └── predict_validity()        [Validate assignments]
│
├── schedule_accuracy_predictor_v2.py [ML ENSEMBLE]
│   └── EnsembleSchedulePredictor
│       └── predict_schedule_quality(schedule)
│
├── ai_integration_guide.py           [USAGE EXAMPLES]
│   ├── run_genetic_algorithm_scheduler()
│   ├── run_reinforcement_learning_scheduler()
│   ├── run_neural_network_scheduler()
│   ├── run_ensemble_ml_scheduler()
│   ├── run_all_schedulers_comparison()
│   ├── hybrid_scheduling_pipeline()
│   └── complete_scheduling_workflow()
│
└── test_ai_unified_scheduler.py      [COMPREHENSIVE TESTS]
    └── TestAIUnifiedScheduler
        ├── Test initialization
        ├── Test each scheduler
        ├── Test unified scheduling
        ├── Test reporting
        └── Test edge cases
"""


# ============================================================================
# SCHEDULER CAPABILITIES MATRIX
# ============================================================================

CAPABILITIES_MATRIX = """
╔════════════════════════════════════════════════════════════════════════════╗
║ SCHEDULER COMPARISON                                                       ║
╠═════════════╦════════════╦══════════╦════════════╦═════════════════════════╣
║ Feature     ║ GA         ║ RL       ║ NN         ║ Ensemble ML             ║
╠═════════════╬════════════╬══════════╬════════════╬═════════════════════════╣
║ Speed       ║ Medium     ║ Medium   ║ Slow       ║ Fast                    ║
║ Quality     ║ High       ║ Medium   ║ Very High  ║ Medium                  ║
║ Scalability ║ Good       ║ Good     ║ Limited    ║ Excellent               ║
║ Learning    ║ No         ║ Yes      ║ Yes        ║ No (frozen model)       ║
║ Constraints ║ Enforced   ║ Enforced ║ Validated  ║ Feature-based           ║
║ Best For    ║ Complex    ║ Adaptive ║ Patterns   ║ Quick baseline          ║
║             ║ problems   ║ schedules│ learning   ║                         ║
╚═════════════╩════════════╩══════════╩════════════╩═════════════════════════╝
"""


# ============================================================================
# METHOD SELECTION GUIDE
# ============================================================================

METHOD_SELECTION = """
Use GENETIC ALGORITHM when:
  ✓ Problem is complex (100+ courses)
  ✓ You have time for evolution (5-10 minutes)
  ✓ You want to explore solution space broadly
  ✓ Hard constraints must always be satisfied
  
Use REINFORCEMENT LEARNING when:
  ✓ Schedule needs continuous adaptation
  ✓ You have feedback from previous schedules
  ✓ You want machine learning from constraints
  ✓ Time to optimize is available (3-5 minutes)
  
Use NEURAL NETWORKS when:
  ✓ You have historical schedule data
  ✓ You want to learn complex patterns
  ✓ Problem is highly non-linear
  ✓ You can train in advance (offline)
  
Use ENSEMBLE ML when:
  ✓ You need FAST BASELINE quality
  ✓ You want quick predictions (seconds)
  ✓ Feature-based classification is sufficient
  ✓ No time for training available
"""


# ============================================================================
# CONFIGURATION EXAMPLES
# ============================================================================

CONFIGURATIONS = """
# Configuration 1: SPEED OPTIMIZED
scheduler = AIUnifiedScheduler(
    courses=courses[:50],           # Smaller subset
    lecturers=lecturers[:20],
    rooms=rooms[:10],
    time_slots=time_slots,
    enable_ga=True,                 # Fast GA
    enable_rl=True,                 # Quick RL
    enable_nn=False,                # Skip NN (slow)
    enable_ensemble=True,           # Very fast
    verbose=False
)
results = scheduler.schedule_all(use_rl_episodes=10)

# Configuration 2: QUALITY OPTIMIZED
scheduler = AIUnifiedScheduler(
    courses=courses,                # Use all data
    lecturers=lecturers,
    rooms=rooms,
    time_slots=time_slots,
    enable_ga=True,                 # Full GA evolution
    enable_rl=True,                 # Full RL training
    enable_nn=True,                 # Train NN
    enable_ensemble=True,           # Baseline
    verbose=True
)
results = scheduler.schedule_all(use_rl_episodes=100)

# Configuration 3: GA ONLY
scheduler = AIUnifiedScheduler(
    courses=courses,
    lecturers=lecturers,
    rooms=rooms,
    time_slots=time_slots,
    enable_ga=True,
    enable_rl=False,
    enable_nn=False,
    enable_ensemble=False
)
schedule, quality, metadata = scheduler.schedule_with_ga()

# Configuration 4: HYBRID PIPELINE
scheduler = AIUnifiedScheduler(
    courses=courses,
    lecturers=lecturers,
    rooms=rooms,
    time_slots=time_slots,
    enable_ga=True,
    enable_rl=True,
    enable_nn=False,
    enable_ensemble=True
)
# GA explores → RL refines → Ensemble validates → Return best
"""


# ============================================================================
# PERFORMANCE BENCHMARKS
# ============================================================================

PERFORMANCE_BENCHMARKS = """
TYPICAL PERFORMANCE ON MEDIUM DATASET (100 courses, 50 lecturers, 20 rooms):

Genetic Algorithm:
  - Time:      ~2-3 minutes (200 generations)
  - Quality:   75-85%
  - Best For:  Complex multi-objective problems

Reinforcement Learning:
  - Time:      ~2-3 minutes (100 episodes)
  - Quality:   65-75%
  - Best For:  Adaptive scenarios

Neural Network (Attention):
  - Time:      ~5-10 minutes (training)
  - Quality:   80-90%
  - Best For:  Pattern-heavy datasets

Ensemble ML:
  - Time:      <1 second
  - Quality:   60-70%
  - Best For:  Quick baselines

COMBINED (All 4):
  - Time:      ~15 minutes total
  - Best Quality: 85-90%
  - Winner: Usually GA or NN
"""


# ============================================================================
# DATA REQUIREMENTS
# ============================================================================

DATA_REQUIREMENTS = """
REQUIRED DATA STRUCTURES:

courses = [
    {
        'code': 'CS101',
        'name': 'Intro to Computer Science',
        'lecturer': 'Dr. Smith',
        'capacity': 50,
        'hours_per_week': 3
    },
    ...
]

lecturers = [
    'Dr. Smith',
    'Dr. Jones',
    ...
]

rooms = [
    {
        'name': 'LR1',
        'capacity': 100,
        'building': 'Building A'
    },
    ...
]

time_slots = [
    '7:00am - 9:30am',
    '9:45am - 12:15pm',
    '1:00pm - 3:30pm',
    ...
]
"""


# ============================================================================
# OUTPUT FORMAT
# ============================================================================

OUTPUT_FORMAT = """
SCHEDULE ASSIGNMENT FORMAT:

schedule = [
    {
        'course_code': 'CS101',
        'lecturer': 'Dr. Smith',
        'room': 'LR1',
        'time_slot': '7:00am - 9:30am',
        'day': 'Monday',
        'capacity': 50
    },
    ...
]

RESULTS DICTIONARY FORMAT:

results = {
    'schedules': {
        'GA': [assignments],
        'RL': [assignments],
        'NN': [assignments],
        'Ensemble': [assignments]
    },
    'scores': {
        'GA': 0.82,
        'RL': 0.71,
        'NN': 0.87,
        'Ensemble': 0.68
    },
    'metadata': {
        'GA': {...},
        'RL': {...},
        'NN': {...},
        'Ensemble': {...}
    },
    'best_schedule': [assignments],
    'best_method': 'NN',
    'best_score': 0.87
}
"""


# ============================================================================
# TROUBLESHOOTING
# ============================================================================

TROUBLESHOOTING = """
PROBLEM: "GA scheduler not initialized"
SOLUTION: Check that courses, lecturers, rooms, time_slots are not None/empty

PROBLEM: "RL returns low quality"
SOLUTION: Increase num_episodes or adjust reward weights in rl_scheduler

PROBLEM: "NN training is very slow"
SOLUTION: Use smaller dataset, reduce hidden_dim, or disable NN

PROBLEM: "All schedulers return None"
SOLUTION: Verify data format matches requirements, check data_path exists

PROBLEM: "Schedule has conflicts"
SOLUTION: Check hard constraints are enabled, run with constraint checking

PROBLEM: "Quality scores are all similar"
SOLUTION: Verify course/lecturer/room data is diverse enough

PROBLEM: Memory error with large dataset
SOLUTION: Use enable_nn=False, reduce population_size/episodes

PROBLEM: "Results not saving to file"
SOLUTION: Check write permissions on data_path directory
"""


# ============================================================================
# IMPLEMENTATION CHECKLIST
# ============================================================================

IMPLEMENTATION_CHECKLIST = """
✓ STEP 1: DATA PREPARATION
  [ ] Load courses table
  [ ] Load lecturers
  [ ] Load rooms with capacities
  [ ] Define time slots
  [ ] Validate all data not None/empty

✓ STEP 2: INITIALIZATION
  [ ] Create AIUnifiedScheduler instance
  [ ] Enable/disable schedulers as needed
  [ ] Check verbose=True for debugging

✓ STEP 3: SCHEDULING
  [ ] Option A: Run individual schedulers
      [ ] schedule_with_ga()
      [ ] schedule_with_rl(num_episodes)
      [ ] schedule_with_nn()
      [ ] schedule_with_ensemble()
  
  [ ] Option B: Run all schedulers
      [ ] schedule_all(use_rl_episodes, use_nn_training)
      [ ] Review comparison results
      [ ] Get best_schedule

✓ STEP 4: VALIDATION
  [ ] Check schedule has no conflicts
  [ ] Verify all courses assigned
  [ ] Confirm quality score > threshold
  [ ] Validate constraint satisfaction

✓ STEP 5: REPORTING
  [ ] Call scheduler.get_report()
  [ ] Save results: scheduler.save_results()
  [ ] Export best_schedule to CSV/JSON
  [ ] Archive results with timestamp

✓ STEP 6: OPTIMIZATION (if needed)
  [ ] Adjust GA generations: 100-500
  [ ] Adjust RL episodes: 50-200
  [ ] Enable NN if data available
  [ ] Run hybrid pipeline for best result
"""


# ============================================================================
# COMMON PATTERNS
# ============================================================================

COMMON_PATTERNS = """
PATTERN 1: Get best schedule quickly
---
scheduler = AIUnifiedScheduler(courses, lecturers, rooms, slots)
_, quality, _ = scheduler.schedule_with_ensemble()
best = scheduler.get_best_schedule()

PATTERN 2: Compare all methods  
---
results = scheduler.schedule_all()
for method, score in results['scores'].items():
    print(f"{method}: {score:.2%}")

PATTERN 3: Quality-first approach
---
results = scheduler.schedule_all(use_rl_episodes=100)
best = results['best_schedule']
qa_report = scheduler.get_report()

PATTERN 4: Specific method focus
---
ga_schedule, ga_score, ga_meta = scheduler.schedule_with_ga()
rl_schedule, rl_score, rl_meta = scheduler.schedule_with_rl(100)
best = ga_schedule if ga_score > rl_score else rl_schedule

PATTERN 5: Continuous scheduling
---
for semester in semesters:
    scheduler = AIUnifiedScheduler(semester_courses, ...)
    results = scheduler.schedule_all()
    scheduler.save_results(f"schedule_{semester}.json")
"""


# ============================================================================
# API QUICK REFERENCE
# ============================================================================

API_QUICK_REFERENCE = """
AIUnifiedScheduler Methods:

schedule_with_ga()
├─ Returns: (schedule, quality_score, metadata)
└─ Best for: Complex problems, exploring space

schedule_with_rl(num_episodes=100)
├─ Returns: (schedule, quality_score, metadata)
└─ Best for: Adaptive learning

schedule_with_nn(training_data=None, labels=None)
├─ Returns: (schedule, quality_score, metadata)
└─ Best for: Pattern recognition

schedule_with_ensemble()
├─ Returns: (schedule, quality_score, metadata)
└─ Best for: Quick baseline

schedule_all(use_rl_episodes=50, use_nn_training=False)
├─ Returns: dict with schedules, scores, metadata, best_*
└─ Best for: Comprehensive comparison

get_best_schedule()
├─ Returns: best_schedule from all runs
└─ Usage: After schedule_all()

get_report()
├─ Returns: formatted string report
└─ Usage: For summary/logging

save_results(filename="results.json")
├─ Returns: True/False (success)
└─ Usage: Archive results
"""


# ============================================================================
# PRINT ALL REFERENCES
# ============================================================================

if __name__ == "__main__":
    print(FILE_STRUCTURE)
    print("\n" + "="*80 + "\n")
    print(CAPABILITIES_MATRIX)
    print("\n" + "="*80 + "\n")
    print(METHOD_SELECTION)
    print("\n" + "="*80 + "\n")
    print(PERFORMANCE_BENCHMARKS)
    print("\n" + "="*80 + "\n")
    print(DATA_REQUIREMENTS)
    print("\n" + "="*80 + "\n")
    print(TROUBLESHOOTING)
    print("\n" + "="*80 + "\n")
    print(IMPLEMENTATION_CHECKLIST)
    print("\n" + "="*80 + "\n")
    print(COMMON_PATTERNS)
    print("\n" + "="*80 + "\n")
    print(API_QUICK_REFERENCE)
