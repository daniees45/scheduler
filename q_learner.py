"""
Q-Learning for Real-Time User Preference Learning
Learns from schedule adjustments and adapts preferences dynamically
Uses reinforcement learning to optimize for user satisfaction
"""

import os
import json
import pickle
import pandas as pd
import numpy as np
from collections import defaultdict
from datetime import datetime


class QLearner:
    """
    Q-Learning agent that learns user preferences from schedule adjustments
    
    States: (course_code, day, slot, room)
    Actions: Accept (reward=1) or Change (reward=-1)
    Goal: Learn which assignments users prefer
    """
    
    def __init__(self, model_path='q_model.pkl', learning_rate=0.1, discount_factor=0.95):
        """
        Initialize Q-Learning agent
        
        Args:
            model_path: Path to save/load Q-values
            learning_rate (alpha): How much to update Q-values (0-1)
            discount_factor (gamma): Importance of future rewards (0-1)
        """
        self.model_path = model_path
        self.alpha = learning_rate  # Learning rate
        self.gamma = discount_factor  # Discount factor
        
        # Q-table: Dictionary of Q-values
        # Key: state (course, day, slot, room)
        # Value: dict with action -> Q-value
        self.q_table = defaultdict(lambda: {'accept': 0.0, 'change': 0.0})
        
        # Metadata
        self.metadata = {
            'total_updates': 0,
            'total_accepts': 0,
            'total_changes': 0,
            'last_updated': None,
            'episodes': 0,
            'avg_reward': 0.0
        }
        
        # Reward history for averaging
        self.reward_history = []
        
        # Feedback log
        self.feedback_log = []
        
        self.load()
    
    @staticmethod
    def state_key(course_code, day, slot, room):
        """Create unique state key"""
        return f"{course_code}|{day}|{slot}|{room}"
    
    @staticmethod
    def parse_state_key(state_key):
        """Parse state key back to components"""
        parts = state_key.split('|')
        if len(parts) == 4:
            return parts
        return None, None, None, None
    
    def record_feedback(self, course_code, day, slot, room, action, lecturer='', reason=''):
        """
        Record user feedback on a scheduling assignment
        
        Args:
            course_code: Course code (e.g., 'CSC301')
            day: Day name (e.g., 'Monday')
            slot: Time slot (e.g., '10:00am')
            room: Room name
            action: 'accept' or 'change'
            lecturer: Lecturer name (optional)
            reason: Reason for change (optional)
        """
        state = self.state_key(course_code, day, slot, room)
        
        # Reward signal
        reward = 1.0 if action == 'accept' else -1.0
        
        # Q-learning update
        self._update_q_value(state, action, reward)
        
        # Log feedback
        feedback_entry = {
            'timestamp': datetime.now().isoformat(),
            'course_code': course_code,
            'day': day,
            'slot': slot,
            'room': room,
            'lecturer': lecturer,
            'action': action,
            'reward': reward,
            'reason': reason
        }
        self.feedback_log.append(feedback_entry)
        
        # Update metadata
        self.metadata['total_updates'] += 1
        if action == 'accept':
            self.metadata['total_accepts'] += 1
        else:
            self.metadata['total_changes'] += 1
        
        self.reward_history.append(reward)
        self.metadata['avg_reward'] = np.mean(self.reward_history[-100:])  # Last 100
        self.metadata['episodes'] += 1
        self.metadata['last_updated'] = datetime.now().isoformat()
        
        print(f"[Q-LEARN] Updated {state}: action={action}, reward={reward:.2f}, Q={self.q_table[state][action]:.3f}")
    
    def _update_q_value(self, state, action, reward, next_state=None):
        """
        Update Q-value using Q-learning formula
        Q(s,a) = Q(s,a) + α * (R + γ * max(Q(s',a')) - Q(s,a))
        
        Args:
            state: Current state
            action: Action taken ('accept' or 'change')
            reward: Reward received
            next_state: Next state (if available)
        """
        current_q = self.q_table[state][action]
        
        # Calculate max Q-value of next state
        if next_state:
            max_next_q = max(self.q_table[next_state].values())
        else:
            max_next_q = 0.0
        
        # Q-learning update
        new_q = current_q + self.alpha * (reward + self.gamma * max_next_q - current_q)
        self.q_table[state][action] = new_q
    
    def get_preference_score(self, course_code, day, slot, room):
        """
        Get user preference score for an assignment (0-1)
        Higher = more preferred
        
        Args:
            course_code, day, slot, room: Assignment details
        
        Returns:
            float: Preference score [0, 1]
        """
        state = self.state_key(course_code, day, slot, room)
        
        # Get accept Q-value
        accept_q = self.q_table[state]['accept']
        change_q = self.q_table[state]['change']
        
        # Convert to probability using softmax-like approach
        # Q-values range roughly [-1, 1], map to [0, 1]
        if accept_q + change_q == 0:
            return 0.5  # No feedback yet
        
        # Normalize: preference = (accept_q - change_q) / max_possible
        preference = (accept_q - change_q) / 2.0  # Normalize to roughly [0, 1]
        preference = max(0.0, min(1.0, preference))  # Clamp to [0, 1]
        
        return preference
    
    def get_top_assignments(self, course_code, k=5):
        """
        Get top-k preferred assignments for a course based on learned preferences
        
        Args:
            course_code: Course code
            k: Number of top assignments to return
        
        Returns:
            List of (state, preference_score) sorted by preference
        """
        preferences = []
        
        # Find all states for this course
        for state, q_vals in self.q_table.items():
            course, day, slot, room = self.parse_state_key(state)
            if course == course_code:
                accept_q = q_vals.get('accept', 0.0)
                change_q = q_vals.get('change', 0.0)
                preference = (accept_q - change_q) / 2.0
                preference = max(0.0, min(1.0, preference))
                preferences.append((state, preference, day, slot, room))
        
        # Sort by preference descending
        preferences.sort(key=lambda x: x[1], reverse=True)
        
        return preferences[:k]
    
    def get_statistics(self):
        """Get learning statistics"""
        total_states = len(self.q_table)
        learned_preferences = sum(
            1 for state_vals in self.q_table.values()
            if abs(state_vals['accept'] - state_vals['change']) > 0.1
        )
        
        return {
            **self.metadata,
            'total_states': total_states,
            'learned_preferences': learned_preferences,
            'feedback_log_size': len(self.feedback_log)
        }
    
    def save(self):
        """Save Q-model to disk"""
        try:
            model_data = {
                'q_table': dict(self.q_table),
                'metadata': self.metadata,
                'feedback_log': self.feedback_log[-1000:]  # Keep last 1000 entries
            }
            
            with open(self.model_path, 'wb') as f:
                pickle.dump(model_data, f)
            
            print(f"[Q-LEARN] Model saved to {self.model_path}")
            print(f"  Total states: {len(self.q_table)}")
            print(f"  Total updates: {self.metadata['total_updates']}")
            print(f"  Accept rate: {self.metadata['total_accepts'] / max(1, self.metadata['total_updates']):.1%}")
            return True
        except Exception as e:
            print(f"[ERROR] Failed to save Q-model: {e}")
            return False
    
    def load(self):
        """Load Q-model from disk"""
        if not os.path.exists(self.model_path):
            print(f"[Q-LEARN] No existing model. Starting fresh.")
            return False
        
        try:
            with open(self.model_path, 'rb') as f:
                model_data = pickle.load(f)
            
            # Restore Q-table (convert back to defaultdict)
            loaded_q = model_data.get('q_table', {})
            self.q_table = defaultdict(lambda: {'accept': 0.0, 'change': 0.0})
            for state, q_vals in loaded_q.items():
                self.q_table[state] = q_vals
            
            self.metadata = model_data.get('metadata', self.metadata)
            self.feedback_log = model_data.get('feedback_log', [])
            
            print(f"[Q-LEARN] Model loaded from {self.model_path}")
            print(f"  Learned from {self.metadata['total_updates']} user interactions")
            print(f"  Average reward: {self.metadata['avg_reward']:.2f}")
            return True
        except Exception as e:
            print(f"[ERROR] Failed to load Q-model: {e}")
            return False
    
    def export_learned_preferences(self, output_csv='learned_preferences.csv'):
        """
        Export learned preferences to CSV for analysis
        
        Args:
            output_csv: Output file path
        """
        try:
            rows = []
            for state, q_vals in self.q_table.items():
                course, day, slot, room = self.parse_state_key(state)
                accept_q = q_vals.get('accept', 0.0)
                change_q = q_vals.get('change', 0.0)
                preference = (accept_q - change_q) / 2.0
                preference = max(0.0, min(1.0, preference))
                
                rows.append({
                    'course_code': course,
                    'day': day,
                    'slot': slot,
                    'room': room,
                    'accept_q': accept_q,
                    'change_q': change_q,
                    'preference_score': preference
                })
            
            df = pd.DataFrame(rows).sort_values('preference_score', ascending=False)
            df.to_csv(output_csv, index=False)
            print(f"[Q-LEARN] Exported {len(rows)} learned preferences to {output_csv}")
            return True
        except Exception as e:
            print(f"[ERROR] Failed to export preferences: {e}")
            return False
    
    def reset_learning(self):
        """Reset Q-model (start fresh learning)"""
        self.q_table = defaultdict(lambda: {'accept': 0.0, 'change': 0.0})
        self.feedback_log = []
        self.reward_history = []
        self.metadata = {
            'total_updates': 0,
            'total_accepts': 0,
            'total_changes': 0,
            'last_updated': None,
            'episodes': 0,
            'avg_reward': 0.0
        }
        print("[Q-LEARN] Model reset. Starting fresh learning.")


class PreferenceFeedbackCollector:
    """
    Interactive tool to collect user feedback on scheduled courses
    For gathering data for Q-learning
    """
    
    def __init__(self, q_learner):
        self.q_learner = q_learner
    
    def collect_feedback_interactive(self, schedule_df):
        """
        Interactively collect feedback on a schedule
        
        Args:
            schedule_df: DataFrame with columns: course_code, lecturer_name, day, start_time, room_name
        """
        print("\n" + "="*70)
        print("SCHEDULE FEEDBACK COLLECTION")
        print("="*70)
        print("Review each course assignment and indicate if you accept or would change it.")
        print("This helps the AI learn your preferences for future schedules.\n")
        
        for idx, row in schedule_df.iterrows():
            print(f"\n[{idx+1}/{len(schedule_df)}] {row.get('course_code', 'UNKNOWN')}")
            print(f"  Lecturer: {row.get('lecturer_name', 'N/A')}")
            print(f"  Day/Time: {row.get('day', 'N/A')} at {row.get('start_time', 'N/A')}")
            print(f"  Room: {row.get('room_name', 'N/A')}")
            
            while True:
                response = input("\n  Accept this assignment? (y/n/skip): ").strip().lower()
                if response in ['y', 'yes']:
                    reason = input("  Reason (optional): ").strip() or ""
                    self.q_learner.record_feedback(
                        row.get('course_code', ''),
                        row.get('day', ''),
                        row.get('start_time', ''),
                        row.get('room_name', ''),
                        'accept',
                        lecturer=row.get('lecturer_name', ''),
                        reason=reason
                    )
                    break
                elif response in ['n', 'no']:
                    reason = input("  Why would you change it? ").strip() or "User preference"
                    self.q_learner.record_feedback(
                        row.get('course_code', ''),
                        row.get('day', ''),
                        row.get('start_time', ''),
                        row.get('room_name', ''),
                        'change',
                        lecturer=row.get('lecturer_name', ''),
                        reason=reason
                    )
                    break
                elif response in ['s', 'skip']:
                    print("  Skipped.")
                    break
                else:
                    print("  Invalid. Enter y/n/skip.")
        
        print("\n" + "="*70)
        print("Feedback collection complete!")
        stats = self.q_learner.get_statistics()
        print(f"Total feedback collected: {stats['total_updates']}")
        print(f"Accept rate: {stats['total_accepts'] / max(1, stats['total_updates']):.1%}")
        print("="*70)
        
        self.q_learner.save()


# Demo/Testing
if __name__ == "__main__":
    # Example usage
    q_learner = QLearner('my_q_model.pkl')
    
    # Simulate some user feedback
    print("\n--- Simulating User Feedback ---")
    q_learner.record_feedback('CSC301', 'Monday', '10:00am', 'Room A', 'accept', 'Dr. Smith')
    q_learner.record_feedback('CSC301', 'Monday', '2:00pm', 'Room A', 'change', 'Dr. Smith', 'Too late')
    q_learner.record_feedback('CSC301', 'Tuesday', '10:00am', 'Room B', 'accept', 'Dr. Smith')
    q_learner.record_feedback('ENG111', 'Wednesday', '10:00am', 'Room C', 'accept', 'Prof. Jones')
    
    # Get preference scores
    print("\n--- Preference Scores ---")
    score1 = q_learner.get_preference_score('CSC301', 'Monday', '10:00am', 'Room A')
    score2 = q_learner.get_preference_score('CSC301', 'Monday', '2:00pm', 'Room A')
    print(f"CSC301 Mon 10am Room A: {score1:.2%} preferred")
    print(f"CSC301 Mon 2pm Room A: {score2:.2%} preferred")
    
    # Get top assignments
    print("\n--- Top Assignments for CSC301 ---")
    top = q_learner.get_top_assignments('CSC301', k=3)
    for state, score, day, slot, room in top:
        print(f"  {day} {slot} in {room}: {score:.2%} preferred")
    
    # Save and show stats
    q_learner.save()
    print("\n--- Statistics ---")
    stats = q_learner.get_statistics()
    print(json.dumps(stats, indent=2, default=str))
    
    # Export preferences
    q_learner.export_learned_preferences('preferences.csv')
