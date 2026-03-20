# ensemble_models.py
"""
Ensemble models for feasibility and schedule quality prediction.
Implements Random Forest and Gradient Boosting combinations.
"""

import os
import pickle
import pandas as pd
import numpy as np
import joblib
from sklearn.ensemble import RandomForestClassifier, GradientBoostingClassifier
from sklearn.model_selection import train_test_split
from sklearn.metrics import accuracy_score, precision_score, recall_score, f1_score

class BaseEnsemble:
    def __init__(self, model_path):
        self.model_path = model_path
        self.model = None
        self.feature_names = []
        self.metadata = {'trained': False}

    @staticmethod
    def extract_level(course_code):
        if not course_code: return 1
        for char in str(course_code):
            if char.isdigit(): return int(char)
        return 1

    @staticmethod
    def day_to_number(day_str):
        day_map = {'Mon': 0, 'Tue': 1, 'Wed': 2, 'Thu': 3, 'Fri': 4,
                   'Monday': 0, 'Tuesday': 1, 'Wednesday': 2, 'Thursday': 3, 'Friday': 4}
        return day_map.get(str(day_str)[:3].strip(), 0)

    @staticmethod
    def slot_to_number(time_str):
        slot_map = {'7:00': 0, '10:00': 1, '2:00': 2, '5:00': 3}
        time_norm = str(time_str).lower().strip()
        for k, v in slot_map.items():
            if k in time_norm: return v
        return 1

    def _extract_features(self, row):
        try:
            return {
                'course_level': self.extract_level(row.get('course_code', '')),
                'day': self.day_to_number(row.get('day', '')),
                'slot': self.slot_to_number(row.get('start_time', row.get('time', ''))),
                'enrollment': int(row.get('enrollment', 30)) if row.get('enrollment') else 30,
            }
        except Exception:
            return None

    def prepare_training_data(self, historical_schedules_csv, failures_csv=None):
        features_list = []
        labels_list = []
        
        if os.path.exists(historical_schedules_csv):
            success_df = pd.read_csv(historical_schedules_csv, on_bad_lines='skip')
            for _, row in success_df.iterrows():
                feature_dict = self._extract_features(row)
                if feature_dict:
                    features_list.append(feature_dict)
                    labels_list.append(1)
        
        if failures_csv and os.path.exists(failures_csv):
            failure_df = pd.read_csv(failures_csv, on_bad_lines='skip')
            for _, row in failure_df.iterrows():
                feature_dict = self._extract_features(row)
                if feature_dict:
                    features_list.append(feature_dict)
                    labels_list.append(0)
        
        if not features_list:
            return None, None
        
        X = pd.DataFrame(features_list)
        y = np.array(labels_list)
        self.feature_names = X.columns.tolist()
        return X, y

    def save(self):
        joblib.dump({
            'model': self.model,
            'feature_names': self.feature_names,
            'metadata': self.metadata
        }, self.model_path)

    def load(self):
        if os.path.exists(self.model_path):
            data = joblib.load(self.model_path)
            self.model = data['model']
            self.feature_names = data['feature_names']
            self.metadata = data['metadata']
            return True
        return False

class FeasibilityEnsemble(BaseEnsemble):
    def __init__(self, model_path='feasibility_ensemble.pkl'):
        super().__init__(model_path)
        self.model = RandomForestClassifier(n_estimators=100, random_state=42, class_weight='balanced')

    def train(self, X, y):
        self.feature_names = X.columns.tolist()
        self.model.fit(X, y)
        self.metadata['trained'] = True
        return True

    def predict_feasibility(self, course_code, day, slot, enrollment=30):
        if not self.metadata['trained']: return 0.5
        features = pd.DataFrame([{
            'course_level': self.extract_level(course_code),
            'day': self.day_to_number(day),
            'slot': self.slot_to_number(slot),
            'enrollment': enrollment
        }])
        probs = self.model.predict_proba(features)[0]
        if len(probs) == 1:
            return 1.0 if self.model.classes_[0] == 1 else 0.0
        return probs[1]
        
    def predict_feasibility_batch(self, course_code, values, days, config, enrollment=30):
        if not self.metadata.get('trained', False):
            return [0.5] * len(values)
            
        course_lvl = self.extract_level(course_code)
        
        feature_dicts = []
        for day_idx, slot_idx, _ in values:
            day_name = days[day_idx]
            slot_name = config.get('slot_times', {}).get(slot_idx, ('Unknown', ''))
            if isinstance(slot_name, tuple):
                slot_name = slot_name[0]
                
            feature_dicts.append({
                'course_level': course_lvl,
                'day': self.day_to_number(day_name),
                'slot': self.slot_to_number(slot_name),
                'enrollment': enrollment
            })
            
        if not feature_dicts:
            return []
            
        features = pd.DataFrame(feature_dicts)
        probs = self.model.predict_proba(features)
        
        if probs.shape[1] == 1:
            return [1.0 if self.model.classes_[0] == 1 else 0.0] * len(values)
            
        return probs[:, 1].tolist()

class QualityEnsemble(BaseEnsemble):
    def __init__(self, model_path='quality_ensemble.pkl'):
        super().__init__(model_path)
        self.model = GradientBoostingClassifier(n_estimators=100, random_state=42)

    def train(self, X, y):
        self.feature_names = X.columns.tolist()
        self.model.fit(X, y)
        self.metadata['trained'] = True
        return True

    def predict_quality(self, features_dict):
        if not self.metadata['trained']: return "Unknown"
        # Assuming y labels are 0: Poor, 1: Fair, 2: Good, 3: Excellent
        label_map = {0: "Poor", 1: "Fair", 2: "Good", 3: "Excellent"}
        X = pd.DataFrame([features_dict])
        pred = self.model.predict(X)[0]
        return label_map.get(pred, "Unknown")
