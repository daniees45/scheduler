"""
Q-LEARNER INTEGRATION MODULE
Activates reinforcement learning for personal schedule preference learning
Monitors user actions and learns time slot preferences over time
"""

import os
import json
from datetime import datetime, time, timedelta
from typing import List, Tuple, Dict, Optional
import numpy as np

from q_learner import QLearner
from personal_scheduler import BusyBlock, Suggestion

# ============================================================================
# CONFIGURATION
# ============================================================================

Q_LEARNER_MODEL_PATH = os.path.join(
    os.path.dirname(__file__), 
    "tkinter_app", "output", "q_learner_model.pkl"
)

LEARNING_LOG_PATH = os.path.join(
    os.path.dirname(__file__),
    "tkinter_app", "output", "q_learner_log.json"
)

# ============================================================================
# GLOBAL Q-LEARNER INSTANCE
# ============================================================================

_global_q_learner = None

def get_q_learner() -> QLearner:
    """Get or create global Q-learner instance"""
    global _global_q_learner
    if _global_q_learner is None:
        _global_q_learner = QLearner(model_path=Q_LEARNER_MODEL_PATH)
    return _global_q_learner


def initialize_q_learning():
    """Initialize Q-learning system"""
    learner = get_q_learner()
    stats = learner.get_statistics()
    
    print("\n" + "="*70)
    print("Q-LEARNING SYSTEM INITIALIZED")
    print("="*70)
    print(f"Model Path: {Q_LEARNER_MODEL_PATH}")
    print(f"Total Learned States: {stats['total_states']}")
    print(f"Total Updates: {stats['total_updates']}")
    print(f"Accept Rate: {stats['total_accepts'] / max(1, stats['total_updates']):.1%}")
    print(f"Last Updated: {stats['last_updated']}")
    print("="*70 + "\n")
    
    return learner


# ============================================================================
# RECORD USER ACTIONS
# ============================================================================

def record_task_scheduled(
    task_name: str,
    day: str,
    start_time: time,
    end_time: time,
    task_category: str = "general",
    reason: str = ""
) -> bool:
    """
    Record when user schedules a personal task
    Called when user confirms a time slot for a personal task
    
    Args:
        task_name: Task/event name
        day: Day of week
        start_time: Start time
        end_time: End time
        task_category: Category (study, work, personal, etc.)
        reason: Optional reason for scheduling
    
    Returns:
        True if recorded successfully
    """
    learner = get_q_learner()
    
    # Create state from time slot
    hour = start_time.hour
    slot_label = f"{hour}:00"
    
    try:
        # Record as "accept" - user accepted this time slot
        learner.record_feedback(
            course_code=task_category,  # Use category as identifier
            day=day,
            slot=slot_label,
            room="personal",  # All personal tasks in "personal" room
            action="accept",
            lecturer=task_name,  # Full task name as lecturer
            reason=reason
        )
        
        # Log event
        log_entry = {
            "timestamp": datetime.now().isoformat(),
            "event_type": "task_scheduled",
            "task": task_name,
            "day": day,
            "time": f"{start_time.strftime('%H:%M')}-{end_time.strftime('%H:%M')}",
            "category": task_category,
            "reason": reason
        }
        _append_to_log(log_entry)
        
        print(f"[Q-LEARN] Task scheduled: {task_name} on {day} at {slot_label}")
        return True
        
    except Exception as e:
        print(f"[Q-LEARN ERROR] Failed to record task scheduling: {e}")
        return False


def record_task_rescheduled(
    task_name: str,
    original_day: str,
    original_time: time,
    new_day: str,
    new_time: time,
    task_category: str = "general",
    reason: str = ""
) -> bool:
    """
    Record when user reschedules a personal task
    Called when user moves task away from suggested time
    
    Args:
        task_name: Task name
        original_day: Original day
        original_time: Original time
        new_day: New day
        new_time: New time
        task_category: Task category
        reason: Reason for reschedule
    
    Returns:
        True if recorded successfully
    """
    learner = get_q_learner()
    
    orig_hour = original_time.hour
    orig_slot = f"{orig_hour}:00"
    new_hour = new_time.hour
    new_slot = f"{new_hour}:00"
    
    try:
        # Record original slot as "change" - user rejected this
        learner.record_feedback(
            course_code=task_category,
            day=original_day,
            slot=orig_slot,
            room="personal",
            action="change",
            lecturer=task_name,
            reason=f"Moved to {new_day} {new_slot}"
        )
        
        # Then record new slot as "accept"
        learner.record_feedback(
            course_code=task_category,
            day=new_day,
            slot=new_slot,
            room="personal",
            action="accept",
            lecturer=task_name,
            reason=reason
        )
        
        log_entry = {
            "timestamp": datetime.now().isoformat(),
            "event_type": "task_rescheduled",
            "task": task_name,
            "original": f"{original_day} {original_time.strftime('%H:%M')}",
            "new": f"{new_day} {new_time.strftime('%H:%M')}",
            "category": task_category,
            "reason": reason
        }
        _append_to_log(log_entry)
        
        print(f"[Q-LEARN] Task rescheduled: {task_name} from {original_day} to {new_day}")
        return True
        
    except Exception as e:
        print(f"[Q-LEARN ERROR] Failed to record task rescheduling: {e}")
        return False


def record_suggestion_accepted(
    suggestion: Suggestion,
    task_category: str = "general"
) -> bool:
    """
    Record when user accepts an AI suggestion
    Positive reinforcement for good suggestions
    
    Args:
        suggestion: Suggestion object (day, start, end, title, score)
        task_category: Task category
    
    Returns:
        True if recorded
    """
    learner = get_q_learner()
    
    hour = suggestion.start.hour
    slot_label = f"{hour}:00"
    
    try:
        learner.record_feedback(
            course_code=task_category,
            day=suggestion.day,
            slot=slot_label,
            room="personal",
            action="accept",
            lecturer=suggestion.title,
            reason=f"Accepted suggestion (score: {suggestion.score:.2f})"
        )
        
        log_entry = {
            "timestamp": datetime.now().isoformat(),
            "event_type": "suggestion_accepted",
            "suggestion": suggestion.title,
            "day": suggestion.day,
            "time": f"{suggestion.start.strftime('%H:%M')}-{suggestion.end.strftime('%H:%M')}",
            "score": suggestion.score,
            "reason": suggestion.reason
        }
        _append_to_log(log_entry)
        
        print(f"[Q-LEARN] Suggestion accepted: {suggestion.title} on {suggestion.day}")
        return True
        
    except Exception as e:
        print(f"[Q-LEARN ERROR] Failed to record suggestion acceptance: {e}")
        return False


def record_suggestion_rejected(
    suggestion: Suggestion,
    task_category: str = "general",
    reason: str = ""
) -> bool:
    """
    Record when user rejects an AI suggestion
    Negative reinforcement for poor suggestions
    
    Args:
        suggestion: Suggestion object
        task_category: Task category
        reason: Why rejected
    
    Returns:
        True if recorded
    """
    learner = get_q_learner()
    
    hour = suggestion.start.hour
    slot_label = f"{hour}:00"
    
    try:
        learner.record_feedback(
            course_code=task_category,
            day=suggestion.day,
            slot=slot_label,
            room="personal",
            action="change",
            lecturer=suggestion.title,
            reason=f"Rejected: {reason}"
        )
        
        log_entry = {
            "timestamp": datetime.now().isoformat(),
            "event_type": "suggestion_rejected",
            "suggestion": suggestion.title,
            "day": suggestion.day,
            "time": f"{suggestion.start.strftime('%H:%M')}-{suggestion.end.strftime('%H:%M')}",
            "score": suggestion.score,
            "rejection_reason": reason
        }
        _append_to_log(log_entry)
        
        print(f"[Q-LEARN] Suggestion rejected: {suggestion.title}")
        return True
        
    except Exception as e:
        print(f"[Q-LEARN ERROR] Failed to record suggestion rejection: {e}")
        return False


# ============================================================================
# GET PERSONALIZED SUGGESTIONS
# ============================================================================

def get_preference_score(
    day: str,
    hour: int,
    task_category: str = "general"
) -> float:
    """
    Get user preference score for a time slot (0-1)
    Higher = more preferred based on learned behavior
    
    Args:
        day: Day of week
        hour: Hour of day
        task_category: Task category
    
    Returns:
        Preference score [0, 1]
    """
    learner = get_q_learner()
    slot_label = f"{hour}:00"
    
    score = learner.get_preference_score(
        course_code=task_category,
        day=day,
        slot=slot_label,
        room="personal"
    )
    
    return score


def rank_suggestions_by_preference(
    suggestions: List[Suggestion],
    task_category: str = "general",
    base_weight: float = 0.7
) -> List[Tuple[Suggestion, float]]:
    """
    Re-rank suggestions using learned preferences
    Blends AI scoring (base) with learned preferences
    
    Args:
        suggestions: List of suggestions to rank
        task_category: Task category for preference lookup
        base_weight: Weight for base suggestion score (0.7 = 70% base, 30% preference)
    
    Returns:
        List of (suggestion, final_score) tuples
    """
    ranked = []
    
    for suggestion in suggestions:
        hour = suggestion.start.hour
        pref_score = get_preference_score(suggestion.day, hour, task_category)
        
        # Blend scores: 70% original score, 30% learned preference
        final_score = (base_weight * suggestion.score) + ((1 - base_weight) * pref_score)
        
        ranked.append((suggestion, final_score))
    
    # Sort by final score descending
    ranked.sort(key=lambda x: x[1], reverse=True)
    
    return ranked


# ============================================================================
# SAVE & STATISTICS
# ============================================================================

def save_q_learner_model():
    """Save Q-learner model to disk"""
    learner = get_q_learner()
    learner.save()
    print("[Q-LEARN] Model saved successfully")


def get_q_learner_statistics() -> Dict:
    """
    Get Q-learner learning statistics
    
    Returns:
        Dictionary with learning metrics
    """
    learner = get_q_learner()
    stats = learner.get_statistics()
    
    return {
        "status": "Active",
        "total_states": stats.get('total_states', 0),
        "total_updates": stats.get('total_updates', 0),
        "total_accepts": stats.get('total_accepts', 0),
        "total_changes": stats.get('total_changes', 0),
        "accept_rate": stats.get('total_accepts', 0) / max(1, stats.get('total_updates', 1)),
        "learned_preferences": stats.get('learned_preferences', 0),
        "episodes": stats.get('episodes', 0),
        "avg_reward": stats.get('avg_reward', 0.0),
        "last_updated": stats.get('last_updated', 'Never')
    }


def print_learning_metrics():
    """Print learning metrics summary"""
    stats = get_q_learner_statistics()
    
    print("\n" + "="*70)
    print("Q-LEARNING METRICS")
    print("="*70)
    print(f"Status: {stats['status']}")
    print(f"Total States Learned: {stats['total_states']}")
    print(f"Total Updates: {stats['total_updates']}")
    print(f"Accepts: {stats['total_accepts']} | Changes: {stats['total_changes']}")
    print(f"Accept Rate: {stats['accept_rate']:.1%}")
    print(f"Preferences Learned: {stats['learned_preferences']}")
    print(f"Episodes: {stats['episodes']}")
    print(f"Avg Reward: {stats['avg_reward']:.3f}")
    print(f"Last Updated: {stats['last_updated']}")
    print("="*70 + "\n")


# ============================================================================
# HELPER FUNCTIONS
# ============================================================================

def _append_to_log(entry: Dict):
    """Append entry to learning log"""
    try:
        # Load existing log
        if os.path.exists(LEARNING_LOG_PATH):
            with open(LEARNING_LOG_PATH, 'r') as f:
                log = json.load(f)
        else:
            log = []
        
        # Append entry
        log.append(entry)
        
        # Save (keep last 1000 entries)
        with open(LEARNING_LOG_PATH, 'w') as f:
            json.dump(log[-1000:], f, indent=2, default=str)
    except Exception as e:
        print(f"[Q-LEARN ERROR] Failed to append to log: {e}")


# ============================================================================
# EXPORT & REPORTING
# ============================================================================

def export_learning_report() -> Dict:
    """
    Export comprehensive learning report
    
    Returns:
        Dictionary with all learning data
    """
    learner = get_q_learner()
    stats = learner.get_statistics()
    
    report = {
        "timestamp": datetime.now().isoformat(),
        "statistics": stats,
        "feedback_log_size": len(learner.feedback_log),
        "recently_learned": [],
        "top_preferred_times": get_top_preferred_times()
    }
    
    # Get recent feedback entries
    if learner.feedback_log:
        report["recently_learned"] = learner.feedback_log[-10:]
    
    return report


def get_top_preferred_times(category: str = "general", num_slots: int = 5) -> List[Dict]:
    """
    Get user's top preferred time slots based on learned behavior
    
    Args:
        category: Task category
        num_slots: Number of top slots to return
    
    Returns:
        List of preferred time slots with preference scores
    """
    learner = get_q_learner()
    
    days = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"]
    hours = list(range(8, 18))  # 8am to 5pm
    
    slots = []
    for day in days:
        for hour in hours:
            slot_label = f"{hour}:00"
            score = learner.get_preference_score(category, day, slot_label, "personal")
            if score > 0.0:  # Only include slots with positive preference
                slots.append({
                    "day": day,
                    "time": slot_label,
                    "preference_score": float(score)
                })
    
    # Sort by preference score descending
    slots.sort(key=lambda x: x["preference_score"], reverse=True)
    
    return slots[:num_slots]


# ============================================================================
# MAIN
# ============================================================================

if __name__ == "__main__":
    # Test the module
    print("Testing Q-Learning Integration...")
    
    learner = initialize_q_learning()
    
    # Simulate some user actions
    print("\nSimulating user task scheduling...")
    record_task_scheduled("Study for exam", "Monday", time(10, 0), time(11, 0), task_category="study")
    record_task_scheduled("Lunch", "Tuesday", time(12, 0), time(13, 0), task_category="personal")
    record_task_scheduled("Lab work", "Wednesday", time(14, 0), time(15, 30), task_category="work")
    
    print("\nSimulating user task rescheduling...")
    record_task_rescheduled(
        "Study for exam",
        "Monday", time(10, 0),
        "Wednesday", time(14, 0),
        task_category="study",
        reason="Conflict with class"
    )
    
    print("\nSimulating user accepting suggestions...")
    suggestion = Suggestion(
        day="Thursday",
        start=time(15, 0),
        end=time(16, 0),
        title="Suggested slot",
        reason="Available slot",
        score=0.85
    )
    record_suggestion_accepted(suggestion, task_category="study")
    
    print("\nLearning Statistics:")
    print_learning_metrics()
    
    print("\nSaving model...")
    save_q_learner_model()
    
    print("✅ Q-Learning Integration test complete!")
