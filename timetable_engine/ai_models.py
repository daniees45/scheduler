"""
Q-Learning Preference Model - Learns lecturer/room preferences from scheduling data
Feasibility Classifier - Predicts if a schedule assignment is feasible
"""

import os
import pickle
import numpy as np
from typing import Dict, Tuple, List, Any
from collections import defaultdict


class QLearningPreferenceModel:
    """Q-Learning model for lecturer and room scheduling preferences"""
    
    def __init__(self, learning_rate: float = 0.1, discount_factor: float = 0.9):
        self.learning_rate = learning_rate
        self.discount_factor = discount_factor
        
        # Q-table for lecturer preferences: (lecturer, day, time_slot) -> reward
        self.lecturer_q_table = defaultdict(lambda: defaultdict(float))
        
        # Q-table for room preferences: (room, day, time_slot) -> reward
        self.room_q_table = defaultdict(lambda: defaultdict(float))
        
        # Track lecturer-room affinity
        self.lecturer_room_affinity = defaultdict(lambda: defaultdict(float))
        
        self.episode_count = 0
    
    def update_preference(self, lecturer: str, day: str, time_slot: str, reward: float):
        """Update Q-value for lecturer preference"""
        key = f"{day}_{time_slot}"
        current_q = self.lecturer_q_table[lecturer][key]
        new_q = current_q + self.learning_rate * (reward - current_q)
        self.lecturer_q_table[lecturer][key] = new_q
    
    def update_room_preference(self, room: str, day: str, time_slot: str, reward: float):
        """Update Q-value for room preference"""
        key = f"{day}_{time_slot}"
        current_q = self.room_q_table[room][key]
        new_q = current_q + self.learning_rate * (reward - current_q)
        self.room_q_table[room][key] = new_q
    
    def update_affinity(self, lecturer: str, room: str, compatibility_score: float):
        """Update lecturer-room affinity score"""
        key = f"affinity_{lecturer}_{room}"
        current = self.lecturer_room_affinity[lecturer][room]
        new_value = current + self.learning_rate * (compatibility_score - current)
        self.lecturer_room_affinity[lecturer][room] = new_value
    
    def get_best_slot_for_lecturer(self, lecturer: str) -> Tuple[str, str]:
        """Get the best (day, time_slot) for a lecturer"""
        if lecturer not in self.lecturer_q_table or not self.lecturer_q_table[lecturer]:
            return "Monday", "7:00am - 9:30am"
        
        best_slot = max(self.lecturer_q_table[lecturer].items(), key=lambda x: x[1])
        day, time_slot = best_slot[0].split('_', 1)
        return day, time_slot
    
    def get_best_room_for_preference(self, room: str) -> float:
        """Get the preference score for a room"""
        if room not in self.room_q_table or not self.room_q_table[room]:
            return 0.5
        
        best_score = max(self.room_q_table[room].values())
        return best_score
    
    def learn_from_episode(self, experiences: List[Dict[str, Any]]):
        """Learn from a scheduling episode"""
        for exp in experiences:
            lecturer = exp.get('lecturer')
            room = exp.get('room')
            day = exp.get('day')
            time_slot = exp.get('time_slot')
            reward = exp.get('reward', 0.5)
            
            if lecturer:
                self.update_preference(lecturer, day, time_slot, reward)
            if room:
                self.update_room_preference(room, day, time_slot, reward)
            if lecturer and room:
                self.update_affinity(lecturer, room, reward)
        
        self.episode_count += 1
    
    def save(self, filepath: str):
        """Save model to pickle file"""
        try:
            with open(filepath, 'wb') as f:
                pickle.dump({
                    'lecturer_q_table': dict(self.lecturer_q_table),
                    'room_q_table': dict(self.room_q_table),
                    'lecturer_room_affinity': dict(self.lecturer_room_affinity),
                    'episode_count': self.episode_count,
                    'learning_rate': self.learning_rate,
                    'discount_factor': self.discount_factor
                }, f)
            print(f"✓ Q-Learning model saved to {filepath}")
            return True
        except Exception as e:
            print(f"✗ Error saving model: {e}")
            return False
    
    def load(self, filepath: str):
        """Load model from pickle file"""
        try:
            with open(filepath, 'rb') as f:
                data = pickle.load(f)
                self.lecturer_q_table = defaultdict(lambda: defaultdict(float), data['lecturer_q_table'])
                self.room_q_table = defaultdict(lambda: defaultdict(float), data['room_q_table'])
                self.lecturer_room_affinity = defaultdict(lambda: defaultdict(float), data['lecturer_room_affinity'])
                self.episode_count = data['episode_count']
                self.learning_rate = data['learning_rate']
                self.discount_factor = data['discount_factor']
            print(f"✓ Q-Learning model loaded from {filepath}")
            return True
        except Exception as e:
            print(f"✗ Error loading model: {e}")
            return False


class FeasibilityClassifier:
    """Predicts if a schedule assignment is feasible"""
    
    def __init__(self):
        # Track feasible vs infeasible assignments
        self.feasible_patterns = defaultdict(int)
        self.infeasible_patterns = defaultdict(int)
        self.total_trained = 0
    
    def extract_features(self, lecturer: str, room: str, day: str, time_slot: str, 
                        current_load: int, max_load: int = 4) -> np.ndarray:
        """Extract features for feasibility prediction"""
        # Features: [lecturer_encoded, room_encoded, day_encoded, time_encoded, load_ratio]
        
        # Encode categorical features
        day_map = {'Monday': 0, 'Tuesday': 1, 'Wednesday': 2, 'Thursday': 3, 'Friday': 4}
        time_map = {'7:00am - 9:30am': 0, '10:00am - 12:30pm': 1, '2:00pm - 4:30pm': 2, '5:00pm - 6:00pm': 3}
        
        lecturer_hash = hash(lecturer) % 100
        room_hash = hash(room) % 100
        day_val = day_map.get(day, 0)
        time_val = time_map.get(time_slot, 0)
        load_ratio = current_load / max_load
        
        return np.array([lecturer_hash / 100, room_hash / 100, day_val / 5, time_val / 4, load_ratio])
    
    def predict_feasibility(self, lecturer: str, room: str, day: str, time_slot: str,
                           current_load: int) -> Tuple[bool, float]:
        """Predict if assignment is feasible and return confidence"""
        features = self.extract_features(lecturer, room, day, time_slot, current_load)
        pattern_key = f"{day}_{time_slot}"
        
        # Simple heuristic-based prediction
        feasible_count = self.feasible_patterns.get(pattern_key, 0)
        infeasible_count = self.infeasible_patterns.get(pattern_key, 0)
        
        total = feasible_count + infeasible_count
        if total == 0:
            # Default for unknown patterns
            return True, 0.5
        
        feasibility_score = feasible_count / total
        is_feasible = feasibility_score > 0.5
        
        return is_feasible, feasibility_score
    
    def record_assignment(self, lecturer: str, room: str, day: str, time_slot: str,
                         was_feasible: bool):
        """Record whether an assignment was feasible"""
        pattern_key = f"{day}_{time_slot}"
        
        if was_feasible:
            self.feasible_patterns[pattern_key] += 1
        else:
            self.infeasible_patterns[pattern_key] += 1
        
        self.total_trained += 1
    
    def save(self, filepath: str):
        """Save classifier to pickle file"""
        try:
            with open(filepath, 'wb') as f:
                pickle.dump({
                    'feasible_patterns': dict(self.feasible_patterns),
                    'infeasible_patterns': dict(self.infeasible_patterns),
                    'total_trained': self.total_trained
                }, f)
            print(f"✓ Feasibility classifier saved to {filepath}")
            return True
        except Exception as e:
            print(f"✗ Error saving classifier: {e}")
            return False
    
    def load(self, filepath: str):
        """Load classifier from pickle file"""
        try:
            with open(filepath, 'rb') as f:
                data = pickle.load(f)
                self.feasible_patterns = defaultdict(int, data['feasible_patterns'])
                self.infeasible_patterns = defaultdict(int, data['infeasible_patterns'])
                self.total_trained = data['total_trained']
            print(f"✓ Feasibility classifier loaded from {filepath}")
            return True
        except Exception as e:
            print(f"✗ Error loading classifier: {e}")
            return False
