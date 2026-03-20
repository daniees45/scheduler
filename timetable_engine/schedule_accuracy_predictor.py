"""
Schedule Accuracy Predictor - Uses machine learning to predict and improve schedule quality
"""

import json
import os
import numpy as np
from typing import Tuple, Dict, List
from datetime import datetime

try:
    from sklearn.ensemble import RandomForestClassifier, GradientBoostingClassifier
    from sklearn.preprocessing import StandardScaler, LabelEncoder
    from sklearn.model_selection import train_test_split
    from sklearn.metrics import accuracy_score, precision_score, recall_score, f1_score
    HAS_SKLEARN = True
except ImportError:
    HAS_SKLEARN = False
    print("Warning: sklearn not installed. Install with: pip install scikit-learn")


class ScheduleAccuracyPredictor:
    """Predicts schedule quality and conflict likelihood using ML"""
    
    def __init__(self, base_path: str = "."):
        self.base_path = base_path
        self.history_dir = os.path.join(base_path, "history")
        os.makedirs(self.history_dir, exist_ok=True)
        
        self.model = None
        self.scaler = StandardScaler()
        self.label_encoders = {}
        self.metrics = {}
        self.has_sklearn = HAS_SKLEARN
        
        if self.has_sklearn:
            self._load_or_create_model()
    
    def _load_or_create_model(self):
        """Load existing model or create a new one"""
        model_path = os.path.join(self.history_dir, "accuracy_model.json")
        
        if os.path.exists(model_path):
            try:
                with open(model_path, 'r') as f:
                    model_data = json.load(f)
                    # NOTE: sklearn models typically require pickle, not JSON
                    # This is for metadata; actual model retraining is recommended
                    print("Note: Models should be saved with pickle for production use")
            except:
                self.model = RandomForestClassifier(n_estimators=100, random_state=42)
        else:
            self.model = RandomForestClassifier(n_estimators=100, random_state=42)
    
    def engineer_features(self, schedule_entry: Dict) -> np.ndarray:
        """Convert schedule entry to feature vector
        
        Features:
        - Time slot (early/mid/late)
        - Day of week (0-4)
        - Course level (1-4)
        - Semester (1-2)
        - Lecturer experience (1-5, estimated)
        - Room capacity ratio
        - Is special constraint (0/1)
        """
        features = []
        
        # Time slot feature (0=early, 1=mid, 2=late)
        time = schedule_entry.get("time_slot", "7:00am - 9:30am")
        if "5:00pm" in time:
            time_feature = 2
        elif "2:00pm" in time:
            time_feature = 1
        else:
            time_feature = 0
        features.append(time_feature)
        
        # Day of week (0-4)
        day = schedule_entry.get("day", "Monday")
        day_map = {"Monday": 0, "Tuesday": 1, "Wednesday": 2, "Thursday": 3, "Friday": 4}
        features.append(day_map.get(day, 0))
        
        # Level and semester
        features.append(schedule_entry.get("level", 1))
        features.append(schedule_entry.get("semester", 1))
        
        # Lecturer experience (proxy: name length as simple heuristic)
        lecturer = schedule_entry.get("lecturer", "")
        lecturer_exp = min(5, len(lecturer) // 5)
        features.append(lecturer_exp)
        
        # Room capacity ratio (0-1)
        room_cap = schedule_entry.get("room_capacity", 50)
        enrolled = schedule_entry.get("enrollment", 30)
        cap_ratio = min(1.0, enrolled / max(room_cap, 1))
        features.append(cap_ratio)
        
        # Special constraint (0/1)
        features.append(1 if schedule_entry.get("has_special_constraint") else 0)
        
        return np.array(features).reshape(1, -1)
    
    def predict_conflict_likelihood(self, schedule_entry: Dict) -> Tuple[float, Dict]:
        """Predict probability of conflict for given schedule entry
        
        Returns:
            Tuple of (conflict_probability, confidence_metrics)
        """
        if not HAS_SKLEARN or self.model is None:
            return 0.5, {"error": "ML model not available"}
        
        try:
            features = self.engineer_features(schedule_entry)
            probabilities = self.model.predict_proba(features)
            conflict_prob = probabilities[0][1]  # Probability of class 1 (conflict)
            
            metrics = {
                "conflict_probability": float(conflict_prob),
                "confidence": float(max(probabilities[0])),
                "model_status": "active"
            }
            
            return conflict_prob, metrics
        except Exception as e:
            return 0.5, {"error": str(e), "model_status": "error"}
    
    def predict_schedule_quality(self, schedule_items: List[Dict]) -> Dict:
        """Predict overall quality of a schedule
        
        Returns quality metrics with scores
        """
        if not schedule_items:
            return {"error": "No schedule items provided"}
        
        scores = []
        conflict_probs = []
        
        for item in schedule_items:
            conflict_prob, _ = self.predict_conflict_likelihood(item)
            conflict_probs.append(conflict_prob)
            
            # Score is inverse of conflict probability
            score = 1.0 - conflict_prob
            scores.append(score)
        
        avg_score = np.mean(scores) if scores else 0.5
        max_conflict = max(conflict_probs) if conflict_probs else 0.0
        
        # Calculate grade
        if avg_score >= 0.9:
            grade = "A"
        elif avg_score >= 0.8:
            grade = "B"
        elif avg_score >= 0.7:
            grade = "C"
        elif avg_score >= 0.6:
            grade = "D"
        else:
            grade = "F"
        
        return {
            "overall_score": float(avg_score),
            "grade": grade,
            "average_conflict_probability": float(np.mean(conflict_probs)),
            "max_conflict_probability": float(max_conflict),
            "item_count": len(schedule_items),
            "model_available": HAS_SKLEARN
        }
    
    def train_on_feedback(self, training_data: List[Tuple[Dict, int]]):
        """Train model using historical schedule data with labels
        
        Args:
            training_data: List of (schedule_entry, label) where label is 0 (good) or 1 (conflict)
        """
        if not HAS_SKLEARN:
            return {"status": "error", "message": "sklearn not available"}
        
        if len(training_data) < 10:
            return {"status": "skipped", "reason": "Not enough training data", "count": len(training_data)}
        
        X = []
        y = []
        
        for entry, label in training_data:
            try:
                features = self.engineer_features(entry)
                X.append(features[0])
                y.append(label)
            except:
                continue
        
        if len(X) < 10:
            return {"status": "skipped", "reason": "Not enough valid training samples"}
        
        X = np.array(X)
        y = np.array(y)
        
        # Split data
        X_train, X_test, y_train, y_test = train_test_split(X, y, test_size=0.2, random_state=42)
        
        # Train model
        self.model.fit(X_train, y_train)
        
        # Evaluate
        y_pred = self.model.predict(X_test)
        
        metrics = {
            "status": "trained",
            "samples": len(training_data),
            "accuracy": float(accuracy_score(y_test, y_pred)),
            "precision": float(precision_score(y_test, y_pred, zero_division=0)),
            "recall": float(recall_score(y_test, y_pred, zero_division=0)),
            "f1": float(f1_score(y_test, y_pred, zero_division=0))
        }
        
        self.metrics = metrics
        self._save_metrics()
        
        return metrics
    
    def _save_metrics(self):
        """Save model metrics to history"""
        path = os.path.join(self.history_dir, "model_metrics.json")
        try:
            with open(path, 'w') as f:
                data = {
                    "timestamp": datetime.now().isoformat(),
                    "metrics": self.metrics
                }
                json.dump(data, f, indent=2)
        except Exception as e:
            print(f"Warning: Could not save metrics: {e}")
    
    def get_model_status(self) -> Dict:
        """Get current model status and performance"""
        model_type = None
        if self.model is not None:
            model_type = self.model.__class__.__name__
        
        return {
            "sklearn_available": self.has_sklearn,
            "model_available": self.model is not None,
            "metrics": self.metrics,
            "model_type": model_type
        }
