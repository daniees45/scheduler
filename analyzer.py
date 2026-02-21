import pandas as pd
import os
import pickle
from pandas.errors import EmptyDataError
from q_learner import QLearner

TIME_TO_SLOT = {
    "7:00am": 0, "07:00am": 0, "7:00": 0, "7:00 am": 0,
    "10:00am": 1, "10:00": 1, "10:00 am": 1,
    "2:00pm": 2, "14:00": 2, "2:00 pm": 2,
    "5:00pm": 3, "17:00": 3, "5:00 pm": 3
}


day_map = {"Mon":0, "Tue":1, "Wed":2, "Thu":3, "Fri":4,
           "Monday":0, "Tuesday":1, "Wednesday":2, "Thursday":3, "Friday":4}

def train_model(history_data: str, feedback_data: str = "user_feedback.csv",
                model_save_path: str = "scheduling_model.pkl"):
    """
    Trains the AI intelligence model using historical scheduling data and user feedback.
    """
    
    lecturer_weights = {}
    room_weights = {}
    slot_popularity = {}
    custom_conflicts = {}
    
    #1. Load historical scheduling data
    if os.path.exists(history_data):
        try:
            history_df = pd.read_csv(history_data, on_bad_lines='skip')
        except EmptyDataError:
            history_df = pd.DataFrame()

        history_df.columns = [col.strip().lower() for col in history_df.columns]
        
        for _, row in history_df.iterrows():
            lecturer = str(row.get('lecturer_name', '')).strip()
            day_str = str(row.get('day', '')).strip()
            time_str = str(row.get('time', str(row.get('start_time', '')))).split("_")[0].strip().lower()
            room = str(row.get('room_name', '')).strip().replace(" ", "_")
            course = str(row.get('course_code', '')).strip()
            day_idx = day_map.get(day_str)
            slot_idx = TIME_TO_SLOT.get(time_str)
            
            if day_idx is not None and slot_idx is not None:
                if lecturer:
                    key = (lecturer, day_idx, slot_idx)
                    lecturer_weights[key] = lecturer_weights.get(key, 0) + 1
                if room and course:
                    key = (course, room)
                    room_weights[key] = room_weights.get(key, 0) + 1
                s_key = (day_idx, slot_idx)
                slot_popularity[s_key] = slot_popularity.get(s_key, 0) + 1
    #2. Load user feedback data
    if os.path.exists(feedback_data):
        try:
            feedback_df = pd.read_csv(feedback_data)
        except EmptyDataError:
            feedback_df = pd.DataFrame()

        feedback_df.columns = [col.strip().lower() for col in feedback_df.columns]
        required_feedback_cols = {"feedback_type", "item_1", "item_2"}
        if not required_feedback_cols.issubset(set(feedback_df.columns)):
            feedback_df = pd.DataFrame(columns=["feedback_type", "item_1", "item_2", "weight/value"])
        
        for _, row in feedback_df.iterrows():
            feedback_type = str(row.get('feedback_type', '')).upper()
            item1 = str(row.get('item_1', '')).strip()
            item2 = str(row.get('item_2', '')).strip()
            value = row.get('weight/value', 50) # Default neutral weight
            
            if feedback_type == "CONFLICT":
                #item1 and item2 are course code that shouldn't clash
                custom_conflicts[(item1, item2)] = value
                
            elif feedback_type == "PREFERENCE":
                #item1 could be lecturer or course
                #item2 could be time slot or room
                day_idx = day_map.get(item2)
                if day_idx is not None:
                    #Apply a massive boost to the model weights
                    
                    for s in range(3):
                        lecturer_weights[(item1, day_idx, s)] = lecturer_weights.get((item1, day_idx, s), 0) + value 
                        
    model_data = {
        "lecturer_slots": lecturer_weights,
        "course_rooms": room_weights,
        "global_slots": slot_popularity,
        "custom_conflicts": custom_conflicts
    }
    
    with open(model_save_path, "wb") as f:
        pickle.dump(model_data, f)
    print(f"Model trained and saved to {model_save_path}")
    return model_data

def load_trained_model(model_path: str = "scheduling_model.pkl"):
    """
    Loads the trained AI intelligence model from disk.
    """
    if os.path.exists(model_path):
        with open(model_path, "rb") as f:
            model_data = pickle.load(f)
        print(f"Model loaded from {model_path}")
        return model_data
    return {}


# ============================================================================
# Q-Learning Integration for Real-Time Preference Learning
# ============================================================================

# Global Q-Learner instance (initialized once at startup)
_q_learner = None

def initialize_q_learner(model_path: str = "q_model.pkl", learning_rate: float = 0.1, 
                         discount_factor: float = 0.95):
    """
    Initialize the Q-Learning agent for preference learning
    
    Args:
        model_path: Path to save/load Q-learning model
        learning_rate: Alpha parameter for Q-learning (0-1)
        discount_factor: Gamma parameter for Q-learning (0-1)
    
    Returns:
        QLearner instance
    """
    global _q_learner
    _q_learner = QLearner(model_path, learning_rate, discount_factor)
    return _q_learner

def get_q_learner():
    """Get the global Q-Learner instance"""
    global _q_learner
    if _q_learner is None:
        _q_learner = QLearner('q_model.pkl')
    return _q_learner

def record_schedule_preference(course_code: str, day: str, start_time: str, 
                               room_name: str, user_accepted: bool = True, 
                               lecturer_name: str = '', reason: str = ''):
    """
    Record user feedback on a scheduled assignment (to be called after scheduling)
    
    Args:
        course_code: Course code (e.g., 'CSC301')
        day: Day name (e.g., 'Monday')
        start_time: Time slot (e.g., '10:00am')
        room_name: Room name
        user_accepted: True if user accepted, False if they would change it
        lecturer_name: Lecturer name (optional)
        reason: Reason for feedback (optional)
    """
    learner = get_q_learner()
    action = 'accept' if user_accepted else 'change'
    learner.record_feedback(course_code, day, start_time, room_name, action, 
                           lecturer=lecturer_name, reason=reason)

def get_assignment_preference_score(course_code: str, day: str, start_time: str, 
                                   room_name: str) -> float:
    """
    Get the learned preference score for an assignment (0-1)
    Higher score = more user prefers this assignment
    
    Args:
        course_code, day, start_time, room_name: Assignment details
    
    Returns:
        float: Preference score [0, 1]
    """
    learner = get_q_learner()
    return learner.get_preference_score(course_code, day, start_time, room_name)

def get_top_preferred_assignments(course_code: str, k: int = 5) -> list:
    """
    Get top-k preferred assignments for a course based on Q-learning
    
    Args:
        course_code: Course code
        k: Number of top assignments to return
    
    Returns:
        List of (state_key, preference_score, day, start_time, room) tuples
    """
    learner = get_q_learner()
    return learner.get_top_assignments(course_code, k)

def record_schedule_batch(schedule_df):
    """
    Record preferences for an entire schedule at once
    Each row marks assignments as accepted (assumed positive feedback)
    
    Args:
        schedule_df: DataFrame with columns: course_code, day, start_time, room_name, lecturer_name
    """
    learner = get_q_learner()
    count = 0
    
    for _, row in schedule_df.iterrows():
        try:
            learner.record_feedback(
                course_code=row.get('course_code', ''),
                day=row.get('day', ''),
                slot=row.get('start_time', ''),
                room=row.get('room_name', ''),
                action='accept',  # Assume initial schedule is acceptable
                lecturer=row.get('lecturer_name', ''),
                reason='Initial schedule'
            )
            count += 1
        except Exception as e:
            print(f"[Q-LEARN] Error recording feedback for {row.get('course_code', 'UNKNOWN')}: {e}")
    
    print(f"[Q-LEARN] Recorded feedback for {count} assignments")

def save_q_learner_model():
    """Save the Q-learning model to disk"""
    learner = get_q_learner()
    return learner.save()

def export_learned_preferences(output_csv: str = 'learned_preferences.csv'):
    """Export learned preferences to CSV for analysis"""
    learner = get_q_learner()
    return learner.export_learned_preferences(output_csv)

def get_q_learner_statistics():
    """Get Q-learning statistics and metadata"""
    learner = get_q_learner()
    return learner.get_statistics()