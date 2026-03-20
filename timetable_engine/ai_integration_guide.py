"""
AI INTEGRATION GUIDE - Complete Usage Examples
Demonstrates how to use the unified AI scheduler with all 4 approaches
"""

# ============================================================================
# PART 1: BASIC SETUP
# ============================================================================

from timetable_engine.ai_unified_scheduler import AIUnifiedScheduler
from timetable_engine.data_loader import DataLoader
import pandas as pd


def load_scheduling_data():
    """Load course, lecturer, room, and time slot data"""
    loader = DataLoader()
    
    # Load courses
    courses = loader.load_courses()
    
    # Load lecturers
    lecturers = loader.load_lecturers()
    
    # Load rooms
    rooms = loader.load_rooms()
    
    # Load time slots
    time_slots = [
        "7:00am - 9:30am",
        "9:45am - 12:15pm",
        "1:00pm - 3:30pm",
        "3:45pm - 6:15pm"
    ]
    
    return courses, lecturers, rooms, time_slots


# ============================================================================
# PART 2: INITIALIZE UNIFIED SCHEDULER
# ============================================================================

def initialize_unified_scheduler():
    """Initialize the unified AI scheduler with all components"""
    
    # Load data
    courses, lecturers, rooms, time_slots = load_scheduling_data()
    
    # Create unified scheduler
    scheduler = AIUnifiedScheduler(
        data_path=".",
        courses=courses,
        lecturers=lecturers,
        rooms=rooms,
        time_slots=time_slots,
        enable_ga=True,
        enable_rl=True,
        enable_nn=True,
        enable_ensemble=True,
        verbose=True
    )
    
    return scheduler


# ============================================================================
# PART 3: RUN INDIVIDUAL SCHEDULERS
# ============================================================================

def run_genetic_algorithm_scheduler(scheduler):
    """
    Run Genetic Algorithm scheduler
    
    Best for:
    - Large-scale scheduling problems
    - When you have time for evolution
    - Need multiple solution paths
    """
    print("\n[EXAMPLE 1] Running Genetic Algorithm")
    print("-" * 70)
    
    schedule, quality, metadata = scheduler.schedule_with_ga()
    
    print(f"Schedule length: {len(schedule) if schedule else 0} assignments")
    print(f"Quality score: {quality:.2%}")
    print(f"Fitness: {metadata.get('fitness', 'N/A')}")
    print(f"Conflicts: {metadata.get('conflicts', 'N/A')}")
    print(f"Room utilization: {metadata.get('room_utilization', 'N/A'):.2%}")
    
    return schedule, quality, metadata


def run_reinforcement_learning_scheduler(scheduler):
    """
    Run Reinforcement Learning scheduler
    
    Best for:
    - Adaptive scheduling
    - Learning from constraints
    - Continuous improvement
    """
    print("\n[EXAMPLE 2] Running Reinforcement Learning")
    print("-" * 70)
    
    schedule, quality, metadata = scheduler.schedule_with_rl(num_episodes=100)
    
    print(f"Schedule length: {len(schedule) if schedule else 0} assignments")
    print(f"Quality score: {quality:.2%}")
    print(f"Training stats: {metadata.get('training_stats', {})}")
    
    return schedule, quality, metadata


def run_neural_network_scheduler(scheduler):
    """
    Run Neural Network scheduler
    
    Best for:
    - Complex pattern recognition
    - Learning from historical data
    - High-dimensional optimization
    """
    print("\n[EXAMPLE 3] Running Neural Network")
    print("-" * 70)
    
    schedule, quality, metadata = scheduler.schedule_with_nn()
    
    print(f"Schedule length: {len(schedule) if schedule else 0} assignments")
    print(f"Quality score: {quality:.2%}")
    print(f"Architecture: {metadata.get('architecture', 'N/A')}")
    print(f"Model params: {metadata.get('model_params', 'N/A')}")
    
    return schedule, quality, metadata


def run_ensemble_ml_scheduler(scheduler):
    """
    Run Ensemble ML scheduler
    
    Best for:
    - Baseline quality prediction
    - Feature-based classification
    - Leveraging historical patterns
    """
    print("\n[EXAMPLE 4] Running Ensemble ML")
    print("-" * 70)
    
    schedule, quality, metadata = scheduler.schedule_with_ensemble()
    
    print(f"Schedule length: {len(schedule) if schedule else 0} assignments")
    print(f"Quality score: {quality:.2%}")
    print(f"Model: {metadata.get('model', 'N/A')}")
    
    return schedule, quality, metadata


# ============================================================================
# PART 4: RUN ALL SCHEDULERS AND COMPARE
# ============================================================================

def run_all_schedulers_comparison(scheduler):
    """
    Run all 4 schedulers and compare their performance
    
    Optimal workflow:
    1. Run GA for 200 generations
    2. Run RL for 100 episodes
    3. Run NN with attention architecture
    4. Run Ensemble for baseline
    5. Compare quality scores
    6. Return best schedule
    """
    print("\n[EXAMPLE 5] Running All Schedulers - Comparison Mode")
    print("=" * 70)
    
    results = scheduler.schedule_all(
        use_rl_episodes=100,
        use_nn_training=False
    )
    
    print("\nComparison Results:")
    print("-" * 70)
    for method, score in results['scores'].items():
        print(f"{method:12} | Quality: {score:6.2%}")
    
    print(f"\nBest Method: {results['best_method']}")
    print(f"Best Score: {results['best_score']:.2%}")
    
    return results


# ============================================================================
# PART 5: HYBRID APPROACH - COMBINE MULTIPLE SCHEDULERS
# ============================================================================

def hybrid_scheduling_pipeline(scheduler):
    """
    Hybrid approach combining multiple schedulers:
    1. Use GA to explore solution space
    2. Use RL to refine and adapt
    3. Use NN to validate patterns
    4. Use Ensemble to predict quality
    
    Returns the best combination
    """
    print("\n[EXAMPLE 6] Hybrid Scheduling Pipeline")
    print("=" * 70)
    
    # Step 1: GA explores solution space
    print("\nStep 1: GA explores solution space...")
    ga_schedule, ga_score, _ = scheduler.schedule_with_ga()
    
    # Step 2: RL refines the schedule
    print("Step 2: RL refines the schedule...")
    rl_schedule, rl_score, _ = scheduler.schedule_with_rl(num_episodes=50)
    
    # Step 3: NN validates patterns
    print("Step 3: NN validates patterns...")
    nn_schedule, nn_score, _ = scheduler.schedule_with_nn()
    
    # Step 4: Select best
    scores = {
        'GA': ga_score,
        'RL': rl_score,
        'NN': nn_score
    }
    
    best_method = max(scores, key=scores.get)
    best_score = scores[best_method]
    
    schedules = {
        'GA': ga_schedule,
        'RL': rl_schedule,
        'NN': nn_schedule
    }
    
    best_schedule = schedules[best_method]
    
    print(f"\nHybrid Results:")
    print("-" * 70)
    print(f"GA Score: {ga_score:.2%}")
    print(f"RL Score: {rl_score:.2%}")
    print(f"NN Score: {nn_score:.2%}")
    print(f"\nBest: {best_method} with {best_score:.2%}")
    
    return best_schedule, best_method, best_score


# ============================================================================
# PART 6: SAVE AND REPORT
# ============================================================================

def generate_and_save_report(scheduler):
    """Generate comprehensive report and save results"""
    
    # Generate report
    report = scheduler.get_report()
    print(report)
    
    # Save results
    scheduler.save_results("scheduling_results.json")
    
    # Save report to file
    with open("scheduling_report.txt", "w") as f:
        f.write(report)
    
    print("\nResults saved to:")
    print("  - scheduling_results.json")
    print("  - scheduling_report.txt")


# ============================================================================
# PART 7: COMPLETE WORKFLOW
# ============================================================================

def complete_scheduling_workflow():
    """Complete end-to-end scheduling workflow"""
    
    print("\n" + "="*70)
    print("COMPLETE SCHEDULING WORKFLOW")
    print("="*70)
    
    # 1. Initialize
    print("\n[Step 1] Initializing unified scheduler...")
    scheduler = initialize_unified_scheduler()
    
    # 2. Run comparison
    print("\n[Step 2] Running all schedulers...")
    results = run_all_schedulers_comparison(scheduler)
    
    # 3. Run hybrid approach
    print("\n[Step 3] Running hybrid pipeline...")
    best_schedule, best_method, best_score = hybrid_scheduling_pipeline(scheduler)
    
    # 4. Generate report
    print("\n[Step 4] Generating report...")
    generate_and_save_report(scheduler)
    
    # 5. Return best schedule
    print("\n[Step 5] Returning best schedule...")
    return best_schedule, best_method, best_score


# ============================================================================
# PART 8: ADVANCED USAGE - CUSTOM CONFIGURATIONS
# ============================================================================

def advanced_configuration_example():
    """
    Advanced configuration for specific use cases
    """
    
    courses, lecturers, rooms, time_slots = load_scheduling_data()
    
    # Configuration 1: Speed-optimized (fewer generations/episodes)
    print("\n[Advanced Config 1] Speed Optimized")
    scheduler_fast = AIUnifiedScheduler(
        courses=courses[:50],  # Smaller subset
        lecturers=lecturers[:20],
        rooms=rooms[:10],
        time_slots=time_slots,
        enable_ga=True,
        enable_rl=True,
        enable_nn=False,  # Disable NN for speed
        enable_ensemble=True
    )
    
    # Configuration 2: Quality-optimized (more generations/episodes)
    print("\n[Advanced Config 2] Quality Optimized")
    scheduler_quality = AIUnifiedScheduler(
        courses=courses,
        lecturers=lecturers,
        rooms=rooms,
        time_slots=time_slots,
        enable_ga=True,
        enable_rl=True,
        enable_nn=True,  # Enable all
        enable_ensemble=True
    )
    
    return scheduler_fast, scheduler_quality


# ============================================================================
# PART 9: MAIN EXECUTION
# ============================================================================

if __name__ == "__main__":
    
    # Run complete workflow
    best_schedule, best_method, best_score = complete_scheduling_workflow()
    
    print("\n" + "="*70)
    print("WORKFLOW COMPLETE")
    print("="*70)
    print(f"Best schedule generated by: {best_method}")
    print(f"Quality score: {best_score:.2%}")
    print(f"Schedule assignments: {len(best_schedule) if best_schedule else 0}")
