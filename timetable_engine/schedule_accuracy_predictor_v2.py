"""
Schedule Accuracy Predictor v2 - Enhanced ML with Ensemble Models & Advanced Feature Engineering
"""

import json
import os
import pickle
import numpy as np
from typing import Tuple, Dict, List, Optional
from datetime import datetime
from collections import Counter

try:
    from sklearn.ensemble import RandomForestClassifier, GradientBoostingClassifier, VotingClassifier
    from sklearn.neural_network import MLPClassifier
    from sklearn.preprocessing import StandardScaler, LabelEncoder
    from sklearn.model_selection import train_test_split, cross_val_score, GridSearchCV
    from sklearn.metrics import accuracy_score, precision_score, recall_score, f1_score, roc_auc_score, confusion_matrix
    HAS_SKLEARN = True
except ImportError:
    HAS_SKLEARN = False
    print("Warning: sklearn not installed. Install with: pip install scikit-learn numpy")


class AdvancedFeatureEngineer:
    """Advanced feature engineering with 15+ features for schedule quality prediction"""
    
    # Time slot mapping
    TIME_SLOTS = {
        "7:00am - 9:30am": {"slot_id": 0, "time_type": "early", "lecture_hours": 2.5},
        "9:30am - 12:00pm": {"slot_id": 1, "time_type": "mid_morning", "lecture_hours": 2.5},
        "12:00pm - 2:00pm": {"slot_id": 2, "time_type": "lunch", "lecture_hours": 2.0},
        "2:00pm - 5:00pm": {"slot_id": 3, "time_type": "afternoon", "lecture_hours": 3.0},
        "5:00pm - 7:30pm": {"slot_id": 4, "time_type": "evening", "lecture_hours": 2.5},
    }
    
    # Day weights (impact on scheduling quality)
    DAY_WEIGHTS = {
        "Monday": 0.8,
        "Tuesday": 0.85,
        "Wednesday": 0.9,
        "Thursday": 0.85,
        "Friday": 0.7,  # Friday classes tend to have lower attendance
        "Saturday": 0.6
    }
    
    # Domain expertise knowledge for course difficulty
    DOMAIN_DIFFICULTY = {
        "COSC": 4,  # Computer Science - harder
        "MATH": 4,
        "INFT": 3.5,  # Information Technology - medium-hard
        "BBIS": 2.5,  # Business - easier
        "EDUC": 2.5,
        "NURS": 3,  # Nursing - medium
        "BMED": 4,  # Biomedical - hard
        "DVST": 2,  # Development Studies - easier
    }
    
    @staticmethod
    def engineer_features(schedule_entry: Dict, schedule_context: Optional[Dict] = None) -> np.ndarray:
        """
        Extract and engineer 15+ features from schedule entry
        
        Features:
        1. Time Slot ID (0-4) - Early, mid-morning, lunch, afternoon, evening
        2. Time Type Impact (0-1) - Normalized schedule preference
        3. Day of Week (0-5) - Monday to Saturday
        4. Day Preference Weight (0-1) - How good/bad this day is for scheduling
        5. Course Level (1-4) - Difficulty/seniority
        6. Semester (1-2)
        7. Room Capacity Match (0-1) - How well room fits enrollment
        8. Room Utilization Rate (0-1) - How full is the room
        9. Lecturer Availability Score (0-1) - Estimated availability
        10. Lecturer Experience Level (1-5) - Years/reputation proxy
        11. Consecutive Hours on Same Day (0-1) - Lecturer workload
        12. Department Scheduling Difficulty (1-5) - Domain expertise
        13. Credits/Weight (0.5-4.0) - Course importance
        14. Conflict Likelihood (0-1) - Historical conflict patterns
        15. Room Quality Score (0-1) - Special vs regular room
        """
        features = []
        
        # 1. Time Slot ID
        time = schedule_entry.get("time_slot", "7:00am - 9:30am")
        time_slot_info = AdvancedFeatureEngineer.TIME_SLOTS.get(time, {"slot_id": 0, "time_type": "early"})
        features.append(time_slot_info["slot_id"])  # 0-4
        
        # 2. Time Type Impact (0-1)
        # Early morning = 0.6, mid-morning = 0.9, lunch = 0.7, afternoon = 0.95, evening = 0.5
        time_type_impact = {"early": 0.6, "mid_morning": 0.9, "lunch": 0.7, "afternoon": 0.95, "evening": 0.5}
        features.append(time_type_impact.get(time_slot_info.get("time_type"), 0.7))
        
        # 3. Day of Week
        day = schedule_entry.get("day", "Monday")
        day_map = {"Monday": 0, "Tuesday": 1, "Wednesday": 2, "Thursday": 3, "Friday": 4, "Saturday": 5}
        features.append(day_map.get(day, 0))
        
        # 4. Day Preference Weight
        day_weight = AdvancedFeatureEngineer.DAY_WEIGHTS.get(day, 0.7)
        features.append(day_weight)
        
        # 5. Course Level
        level = schedule_entry.get("level", 1)
        features.append(min(4, max(1, level)))
        
        # 6. Semester
        semester = schedule_entry.get("semester", 1)
        features.append(min(2, max(1, semester)))
        
        # 7. Room Capacity Match
        room_cap = schedule_entry.get("room_capacity", 50)
        enrolled = schedule_entry.get("enrollment", 30)
        if room_cap > 0:
            # Ideal is when capacity is slightly above enrollment (90-110%)
            capacity_match = 1.0 - abs((enrolled / room_cap) - 1.0)
            capacity_match = max(0.0, min(1.0, capacity_match))
        else:
            capacity_match = 0.5
        features.append(capacity_match)
        
        # 8. Room Utilization Rate
        utilization = min(1.0, enrolled / max(room_cap, 1))
        features.append(utilization)
        
        # 9. Lecturer Availability Score
        # Based on how many time slots are available for this lecturer
        lecturer_availability = schedule_entry.get("lecturer_availability_ratio", 0.7)
        features.append(max(0.0, min(1.0, lecturer_availability)))
        
        # 10. Lecturer Experience Level (1-5)
        # Proxy: parse from lecturer field if available, otherwise estimate
        lecturer = schedule_entry.get("lecturer", "")
        exp_level = min(5, max(1, len(lecturer) // 4))  # Name length as proxy
        features.append(exp_level)
        
        # 11. Consecutive Hours on Same Day
        # How many hours the lecturer already teaches on this day (normalized 0-1)
        consecutive_hours = schedule_entry.get("lecturer_daily_load", 0)
        consecutive_normalized = min(1.0, consecutive_hours / 8.0)  # Max 8 hours reasonable
        features.append(consecutive_normalized)
        
        # 12. Department Scheduling Difficulty
        # Extract course code prefix
        course_code = schedule_entry.get("course_code", "")
        dept_prefix = course_code.split()[0] if " " in course_code else course_code
        diff_level = AdvancedFeatureEngineer.DOMAIN_DIFFICULTY.get(dept_prefix, 3)
        features.append(diff_level / 5.0)  # Normalize 0-1
        
        # 13. Credits/Weight (0.5-4.0)
        credits_str = schedule_entry.get("credits", "3")
        try:
            credits = float(credits_str)
        except:
            credits = 3.0
        credits_normalized = min(1.0, credits / 4.0)
        features.append(credits_normalized)
        
        # 14. Conflict Likelihood (0-1)
        # Historical pattern or estimate based on room and time
        conflict_likelihood = schedule_entry.get("conflict_likelihood", 0.0)
        features.append(max(0.0, min(1.0, conflict_likelihood)))
        
        # 15. Room Quality Score (0-1)
        # Special rooms get higher score (labs, studios) vs regular classrooms
        is_special = schedule_entry.get("is_special_room", False)
        room_quality = 0.9 if is_special else 0.6
        features.append(room_quality)
        
        # 16. BONUS: Lecturer-Room Suitability (0-1)
        # Some lecturers prefer special rooms (labs), others prefer regular classrooms
        lecturer_specialty = schedule_entry.get("lecturer_specialty_match", 0.7)
        features.append(max(0.0, min(1.0, lecturer_specialty)))
        
        # 17. BONUS: Enrollment Growth Factor (0-1)
        # Historical enrollment trends
        enrollment_stability = schedule_entry.get("enrollment_stability", 0.8)
        features.append(max(0.0, min(1.0, enrollment_stability)))
        
        # 18. BONUS: Time Between Classes (0-1)
        # For multi-slot lecturers, gaps between classes (avoid back-to-back)
        time_gap_factor = schedule_entry.get("time_gap_factor", 0.7)
        features.append(max(0.0, min(1.0, time_gap_factor)))
        
        # 19. Department Alignment (0-1)
        # 1.0 if course department matches room department, 0.5 if either is General, 0.0 if mismatch
        course_dept = schedule_entry.get("departmental_group", "General")
        room_dept = schedule_entry.get("room_department", "General")
        if course_dept == room_dept or course_dept == "General" or room_dept == "General":
            features.append(1.0 if course_dept == room_dept else 0.5)
        else:
            features.append(0.0)
            
        # 20. Shared Group Sync (0-1)
        # 1.0 if part of a shared group and synced (context needed), 0.5 otherwise
        shared_id = schedule_entry.get("shared_group_id")
        features.append(1.0 if shared_id else 0.5)
        
        return np.array(features).reshape(1, -1)
    
    @staticmethod
    def get_feature_names() -> List[str]:
        """Return feature names for interpretability"""
        return [
            "time_slot_id",
            "time_type_impact",
            "day_of_week",
            "day_preference_weight",
            "course_level",
            "semester",
            "room_capacity_match",
            "room_utilization_rate",
            "lecturer_availability_score",
            "lecturer_experience_level",
            "consecutive_hours_per_day",
            "department_difficulty",
            "credits_weight",
            "conflict_likelihood",
            "room_quality_score",
            "lecturer_room_suitability",
            "enrollment_stability",
            "time_gap_factor",
            "department_alignment",
            "group_sync_status"
        ]


class EnsembleSchedulePredictor:
    """Ensemble ML model combining multiple algorithms for robust predictions"""
    
    def __init__(self, base_path: str = "."):
        self.base_path = base_path
        self.history_dir = os.path.join(base_path, "history")
        os.makedirs(self.history_dir, exist_ok=True)
        
        self.ensemble_model = None
        self.rf_model = None
        self.gb_model = None
        self.mlp_model = None
        
        self.scaler = StandardScaler()
        self.label_encoders = {}
        self.metrics = {}
        self.feature_importance = {}
        self.has_sklearn = HAS_SKLEARN
        
        if self.has_sklearn:
            self._load_or_create_models()
    
    def _load_or_create_models(self):
        """Load existing models or create new ones"""
        model_path = os.path.join(self.history_dir, "ensemble_model.pkl")
        scaler_path = os.path.join(self.history_dir, "scaler.pkl")
        
        # Try to load existing models
        load_success = False
        if os.path.exists(model_path) and os.path.exists(scaler_path):
            try:
                with open(model_path, 'rb') as f:
                    self.ensemble_model = pickle.load(f)
                with open(scaler_path, 'rb') as f:
                    self.scaler = pickle.load(f)
                print("✓ Loaded existing ensemble model")
                load_success = True
            except Exception as e:
                print(f"Warning: Could not load models: {e}")
        
        # Always create individual models (needed even if ensemble exists)
        # or for training new models
        self.rf_model = RandomForestClassifier(
            n_estimators=200,
            max_depth=15,
            min_samples_split=5,
            min_samples_leaf=2,
            random_state=42,
            n_jobs=-1,
            class_weight='balanced'
        )
        
        self.gb_model = GradientBoostingClassifier(
            n_estimators=150,
            learning_rate=0.05,
            max_depth=5,
            min_samples_split=5,
            min_samples_leaf=2,
            random_state=42,
            subsample=0.8
        )
        
        self.mlp_model = MLPClassifier(
            hidden_layer_sizes=(128, 64, 32),
            learning_rate_init=0.001,
            max_iter=500,
            random_state=42,
            early_stopping=True,
            validation_fraction=0.1,
            n_iter_no_change=20
        )
        
        if not load_success:
            print("✓ Created new ensemble models (RF, GB, MLP)")
    
    def train(self, training_data: List[Tuple[Dict, int]], validation_split: float = 0.2) -> Dict:
        """
        Train ensemble model with cross-validation
        
        Args:
            training_data: List of (schedule_entry, label) pairs
            validation_split: Fraction for validation set
        
        Returns:
            Training metrics dictionary
        """
        if not HAS_SKLEARN:
            return {"status": "error", "message": "sklearn not available"}
        
        if len(training_data) < 50:
            return {
                "status": "skipped",
                "reason": f"Insufficient training data: {len(training_data)}, need >= 50",
                "count": len(training_data)
            }
        
        # Extract features and labels
        X = []
        y = []
        invalid_count = 0
        
        for entry, label in training_data:
            try:
                features = AdvancedFeatureEngineer.engineer_features(entry)
                X.append(features[0])
                y.append(label)
            except Exception as e:
                invalid_count += 1
                continue
        
        if len(X) < 50:
            return {
                "status": "skipped",
                "reason": f"Not enough valid samples: {len(X)}, need >= 50",
                "invalid": invalid_count
            }
        
        X = np.array(X)
        y = np.array(y)
        
        # Split data
        X_train, X_test, y_train, y_test = train_test_split(
            X, y, test_size=validation_split, random_state=42, stratify=y
        )
        
        # Scale features
        X_train_scaled = self.scaler.fit_transform(X_train)
        X_test_scaled = self.scaler.transform(X_test)
        
        # Train individual models
        print(f"Training on {len(X_train)} samples, validating on {len(X_test)} samples...")
        
        self.rf_model.fit(X_train_scaled, y_train)
        print("  ✓ Random Forest trained")
        
        self.gb_model.fit(X_train_scaled, y_train)
        print("  ✓ Gradient Boosting trained")
        
        self.mlp_model.fit(X_train_scaled, y_train)
        print("  ✓ Neural Network trained")
        
        # Create and fit voting ensemble
        self.ensemble_model = VotingClassifier(
            estimators=[
                ('rf', self.rf_model),
                ('gb', self.gb_model),
                ('mlp', self.mlp_model)
            ],
            voting='soft'
        )
        self.ensemble_model.fit(X_train_scaled, y_train)
        print("  ✓ Voting Ensemble created and fitted")
        
        # Evaluate
        y_pred = self.ensemble_model.predict(X_test_scaled)
        y_pred_proba = self.ensemble_model.predict_proba(X_test_scaled)
        
        accuracy = accuracy_score(y_test, y_pred)
        precision = precision_score(y_test, y_pred, zero_division=0)
        recall = recall_score(y_test, y_pred, zero_division=0)
        f1 = f1_score(y_test, y_pred, zero_division=0)
        
        try:
            roc_auc = roc_auc_score(y_test, y_pred_proba[:, 1])
        except:
            roc_auc = 0.0
        
        # Feature importance from RF
        feature_importance = dict(zip(
            AdvancedFeatureEngineer.get_feature_names(),
            self.rf_model.feature_importances_
        ))
        
        # Sort by importance
        self.feature_importance = dict(sorted(
            feature_importance.items(),
            key=lambda x: x[1],
            reverse=True
        ))
        
        self.metrics = {
            "status": "trained",
            "training_samples": len(X_train),
            "validation_samples": len(X_test),
            "total_samples": len(training_data),
            "invalid_samples": invalid_count,
            "accuracy": float(accuracy),
            "precision": float(precision),
            "recall": float(recall),
            "f1": float(f1),
            "roc_auc": float(roc_auc),
            "timestamp": datetime.now().isoformat(),
            "top_5_features": dict(list(self.feature_importance.items())[:5])
        }
        
        # Save models
        self._save_models()
        
        return self.metrics
    
    def predict_class(self, schedule_entry: Dict) -> Tuple[int, float, Dict]:
        """
        Predict conflict class (0=good, 1=conflict)
        
        Returns:
            Tuple of (predicted_class, confidence, metrics)
        """
        if not HAS_SKLEARN or self.ensemble_model is None:
            return 0, 0.5, {"error": "Model not available"}
        
        try:
            features = AdvancedFeatureEngineer.engineer_features(schedule_entry)
            features_scaled = self.scaler.transform(features)
            
            prediction = self.ensemble_model.predict(features_scaled)[0]
            probabilities = self.ensemble_model.predict_proba(features_scaled)[0]
            confidence = float(max(probabilities))
            
            # Get individual model predictions
            rf_pred = self.rf_model.predict(features_scaled)[0]
            gb_pred = self.gb_model.predict(features_scaled)[0]
            mlp_pred = self.mlp_model.predict(features_scaled)[0]
            
            metrics = {
                "predicted_class": int(prediction),
                "confidence": confidence,
                "probability_good": float(probabilities[0]),
                "probability_conflict": float(probabilities[1]),
                "rf_prediction": int(rf_pred),
                "gb_prediction": int(gb_pred),
                "mlp_prediction": int(mlp_pred),
                "ensemble_agreement": int(rf_pred == gb_pred == mlp_pred)
            }
            
            return int(prediction), confidence, metrics
        
        except Exception as e:
            return 0, 0.5, {"error": str(e)}
    
    def predict_schedule_quality(self, schedule_items: List[Dict]) -> Dict:
        """Predict overall schedule quality with detailed breakdown"""
        if not schedule_items:
            return {"error": "No schedule items provided"}
        
        predictions = []
        confidences = []
        conflict_items = []
        
        for idx, item in enumerate(schedule_items):
            pred_class, confidence, _ = self.predict_class(item)
            predictions.append(pred_class)
            confidences.append(confidence)
            
            if pred_class == 1:  # Conflict
                conflict_items.append({
                    "index": idx,
                    "course_code": item.get("course_code", ""),
                    "confidence": confidence
                })
        
        # Calculate metrics
        conflict_count = sum(predictions)
        overall_quality = 1.0 - (conflict_count / len(predictions)) if predictions else 0.5
        avg_confidence = np.mean(confidences) if confidences else 0.5
        
        # Grade assignment
        if overall_quality >= 0.95:
            grade = "A+"
        elif overall_quality >= 0.90:
            grade = "A"
        elif overall_quality >= 0.85:
            grade = "B+"
        elif overall_quality >= 0.80:
            grade = "B"
        elif overall_quality >= 0.70:
            grade = "C"
        elif overall_quality >= 0.60:
            grade = "D"
        else:
            grade = "F"
        
        return {
            "overall_quality_score": float(overall_quality),
            "grade": grade,
            "total_items": len(schedule_items),
            "conflict_count": int(conflict_count),
            "conflict_percentage": float(conflict_count / len(schedule_items) * 100) if predictions else 0.0,
            "average_confidence": float(avg_confidence),
            "conflict_items": conflict_items[:5],  # Top 5 conflicts
            "model_available": HAS_SKLEARN,
            "model_trained": self.ensemble_model is not None
        }
    
    def _save_models(self):
        """Save trained models to disk"""
        try:
            model_path = os.path.join(self.history_dir, "ensemble_model.pkl")
            scaler_path = os.path.join(self.history_dir, "scaler.pkl")
            metrics_path = os.path.join(self.history_dir, "model_metrics.json")
            
            with open(model_path, 'wb') as f:
                pickle.dump(self.ensemble_model, f)
            
            with open(scaler_path, 'wb') as f:
                pickle.dump(self.scaler, f)
            
            with open(metrics_path, 'w') as f:
                json.dump(self.metrics, f, indent=2)
            
            print(f"✓ Models saved to {self.history_dir}")
        except Exception as e:
            print(f"Warning: Could not save models: {e}")
    
    def get_model_status(self) -> Dict:
        """Get detailed model status"""
        return {
            "sklearn_available": self.has_sklearn,
            "model_available": self.ensemble_model is not None,
            "has_rf_model": self.rf_model is not None,
            "has_gb_model": self.gb_model is not None,
            "has_mlp_model": self.mlp_model is not None,
            "metrics": self.metrics,
            "feature_importance": self.feature_importance,
            "feature_count": len(AdvancedFeatureEngineer.get_feature_names()),
            "feature_names": AdvancedFeatureEngineer.get_feature_names()
        }
    
    def get_feature_importance_summary(self) -> Dict:
        """Get human-readable feature importance summary"""
        if not self.feature_importance:
            return {"error": "Model not trained yet"}
        
        summary = {}
        for idx, (feature, importance) in enumerate(self.feature_importance.items(), 1):
            summary[f"{idx}. {feature}"] = f"{importance:.4f} ({importance*100:.1f}%)"
        
        return summary
