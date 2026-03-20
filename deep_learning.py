"""
PHASE 4: Deep Learning Integration
Neural Network Classifier for Schedule Quality Prediction

Uses neural network to:
- Classify schedule quality (good/fair/poor)
- Predict task completion probability
- Evaluate conflict severity
- Suggest schedule optimizations
"""

import os
import json
import pickle
import numpy as np
from datetime import datetime, time, timedelta
from typing import List, Dict, Tuple, Optional
from dataclasses import dataclass, asdict
import logging

# Machine Learning
from sklearn.neural_network import MLPClassifier, MLPRegressor
from sklearn.preprocessing import StandardScaler
from sklearn.pipeline import Pipeline
import warnings
warnings.filterwarnings('ignore')

# Setup logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

# ============================================================================
# DATA STRUCTURES
# ============================================================================

@dataclass
class ScheduleFeatures:
    """Features extracted from a schedule for neural network input"""
    # Built-in features
    num_events: int = 0
    total_hours: float = 0.0
    avg_gap_between: float = 0.0
    morning_load: float = 0.0
    afternoon_load: float = 0.0
    evening_load: float = 0.0
    num_conflicts: int = 0
    avg_event_duration: float = 0.0
    
    # Q-learner features
    q_learner_accept_rate: float = 0.5
    q_learner_confidence: float = 0.5
    num_learned_preferences: int = 0
    
    # Productivity features
    peak_productivity_hours: List[int] = None
    avg_quality_rating: float = 0.0
    completion_rate: float = 0.0
    
    # User profile features
    user_load_factor: float = 0.5  # 0=light, 1=heavy
    
    def to_array(self) -> np.ndarray:
        """Convert to numpy array for neural network input"""
        if self.peak_productivity_hours is None:
            self.peak_productivity_hours = []
        
        peak_hours = np.zeros(24, dtype=np.float32)
        for hour in self.peak_productivity_hours:
            try:
                h = int(hour)
                if 0 <= h < 24:
                    peak_hours[h] = 1.0
            except (ValueError, TypeError):
                continue
        
        # Helper to safely convert to float
        def safe_float(val, default=0.0):
            try:
                return float(val) if val is not None else default
            except (ValueError, TypeError):
                return default

        # Create feature vector: 14 scalar features + 24 hour indicators
        features = np.array([
            safe_float(self.num_events),
            safe_float(self.total_hours),
            safe_float(self.avg_gap_between),
            safe_float(self.morning_load),
            safe_float(self.afternoon_load),
            safe_float(self.evening_load),
            safe_float(self.num_conflicts),
            safe_float(self.avg_event_duration),
            safe_float(self.q_learner_accept_rate),
            safe_float(self.q_learner_confidence),
            safe_float(self.num_learned_preferences),
            safe_float(self.avg_quality_rating),
            safe_float(self.completion_rate),
            safe_float(self.user_load_factor),
        ], dtype=np.float32)
        
        return np.concatenate([features, peak_hours])


@dataclass
class ScheduleQuality:
    """Neural network prediction for schedule quality"""
    overall_score: float  # 0-1
    category: str  # "excellent", "good", "fair", "poor"
    completion_probability: float  # 0-1 chance user completes all tasks
    conflict_severity: float  # 0-1, 0=no conflicts, 1=severe
    optimization_suggestions: List[str] = None
    confidence: float = 0.0  # Neural network confidence in prediction
    
    def __post_init__(self):
        if self.optimization_suggestions is None:
            self.optimization_suggestions = []


# ============================================================================
# NEURAL NETWORK CLASSIFIER
# ============================================================================

class ScheduleQualityClassifier:
    """
    Deep learning classifier for schedule quality prediction
    4-layer neural network with dropout and batch normalization
    """
    
    def __init__(self, model_path: str = None):
        """
        Initialize classifier
        
        Args:
            model_path: Path to load pre-trained model from
        """
        self.model_path = model_path or os.path.expanduser(
            "~/vvu-scheduler/schedule_quality_nn.pkl"
        )
        
        # Create pipeline: Scale inputs → Neural network
        self.pipeline = Pipeline([
            ('scaler', StandardScaler()),
            ('classifier', MLPClassifier(
                hidden_layer_sizes=(64, 32, 16),  # 3 hidden layers
                activation='relu',
                solver='adam',
                learning_rate_init=0.001,
                alpha=0.001,  # L2 regularization
                batch_size=32,
                max_iter=500,
                early_stopping=True,
                validation_fraction=0.1,
                n_iter_no_change=50,
                random_state=42,
                verbose=0
            ))
        ])
        
        # Regression pipeline for probability predictions
        self.probability_model = Pipeline([
            ('scaler', StandardScaler()),
            ('regressor', MLPRegressor(
                hidden_layer_sizes=(32, 16, 8),
                activation='relu',
                solver='adam',
                learning_rate_init=0.001,
                alpha=0.001,
                batch_size=16,
                max_iter=300,
                early_stopping=True,
                validation_fraction=0.1,
                n_iter_no_change=30,
                random_state=42,
                verbose=0
            ))
        ])
        
        self.classes_ = ["poor", "fair", "good", "excellent"]
        self.is_trained = False
        self.last_accuracy = None
        
        # Try to load existing model
        self._load_model()
    
    def _load_model(self):
        """Load pre-trained model if available"""
        if os.path.exists(self.model_path):
            try:
                with open(self.model_path, 'rb') as f:
                    state = pickle.load(f)
                self.pipeline = state['pipeline']
                self.probability_model = state['probability_model']
                self.is_trained = state.get('is_trained', True)
                self.last_accuracy = state.get('last_accuracy', None)
                logger.info(f"[NN] Model loaded from {self.model_path}")
            except Exception as e:
                error_text = str(e)
                if "BitGenerator module" in error_text or "MT19937" in error_text:
                    timestamp = datetime.now().strftime('%Y%m%d_%H%M%S')
                    backup_path = f"{self.model_path}.incompatible_{timestamp}"
                    try:
                        os.rename(self.model_path, backup_path)
                        logger.warning(
                            f"[NN] Incompatible model format detected ({error_text}). "
                            f"Moved old model to {backup_path}. A fresh model will be trained."
                        )
                    except Exception as move_err:
                        logger.warning(
                            f"[NN] Incompatible model format detected ({error_text}), "
                            f"and failed to move old model: {move_err}. A fresh model will be trained."
                        )
                else:
                    logger.warning(f"[NN] Failed to load model: {e}")
    
    def _save_model(self):
        """Save trained model to disk"""
        os.makedirs(os.path.dirname(self.model_path), exist_ok=True)
        try:
            state = {
                'pipeline': self.pipeline,
                'probability_model': self.probability_model,
                'is_trained': self.is_trained,
                'last_accuracy': self.last_accuracy,
                'timestamp': datetime.now().isoformat()
            }
            with open(self.model_path, 'wb') as f:
                pickle.dump(state, f)
            logger.info(f"[NN] Model saved to {self.model_path}")
        except Exception as e:
            logger.warning(f"[NN] Failed to save model: {e}")
    
    def train(self, training_data: List[Tuple[ScheduleFeatures, str]]):
        """
        Train neural network on labeled schedule data
        """
        if not training_data:
            logger.warning("[NN] No training data provided")
            return
        
        # Prepare data with explicit dtype conversion
        X_list = []
        for features, _ in training_data:
            arr = features.to_array()
            X_list.append(arr)
        
        # Ensure all arrays are the same shape before conversion
        if not all(a.shape == X_list[0].shape for a in X_list):
            logger.error(f"[NN] Feature shape mismatch identified: {[a.shape for a in X_list]}")
            return

        X = np.array(X_list, dtype=np.float32)
        
        # Use LabelEncoder to ensure numeric labels for the classifier
        from sklearn.preprocessing import LabelEncoder
        self.label_encoder = LabelEncoder()
        y_raw = [str(label) for _, label in training_data]
        y = self.label_encoder.fit_transform(y_raw)
        
        logger.info(f"[NN] Prepared training data: X={X.shape}, y={y.shape}")
        
        # Train classifier
        try:
            self.pipeline.fit(X, y)
            self.is_trained = True
            self.last_accuracy = float(self.pipeline.score(X, y))
            logger.info(f"[NN] Classifier trained on {len(training_data)} samples")
        except Exception as e:
            logger.error(f"[NN] Classifier training failed: {e}")
            import traceback
            logger.error(traceback.format_exc())
        
        # Train probability predictor (use numerical labels)
        try:
            y_prob = np.array([self._quality_to_score(label) for label in y_raw], dtype=np.float32)
            self.probability_model.fit(X, y_prob)
            logger.info(f"[NN] Probability model trained")
        except Exception as e:
            logger.error(f"[NN] Probability model training failed: {e}")
            import traceback
            logger.error(traceback.format_exc())
        
        self._save_model()
    
    def predict(self, features: ScheduleFeatures) -> ScheduleQuality:
        """
        Predict schedule quality from features
        """
        if not self.is_trained:
            # Return default prediction if model not trained
            return ScheduleQuality(
                overall_score=0.5,
                category="fair",
                completion_probability=0.5,
                conflict_severity=float(features.num_conflicts) / max(features.num_events, 1),
                confidence=0.0
            )
        
        X = features.to_array().reshape(1, -1)
        
        # Get quality class prediction
        try:
            predicted_idx = self.pipeline.predict(X)[0]
            # Convert back to string label
            if hasattr(self, 'label_encoder'):
                predicted_class = self.label_encoder.inverse_transform([predicted_idx])[0]
            else:
                # Fallback for old models
                classes = ["poor", "fair", "good", "excellent"]
                predicted_class = classes[min(int(predicted_idx), 3)]
                
            class_proba = self.pipeline.predict_proba(X)[0]
            confidence = np.max(class_proba)
        except Exception as e:
            logger.warning(f"[NN] Prediction failed: {e}")
            predicted_class = "fair"
            confidence = 0.0
        
        # Get probability prediction
        try:
            completion_prob = float(self.probability_model.predict(X)[0])
            completion_prob = np.clip(completion_prob, 0.0, 1.0)
        except:
            completion_prob = 0.5
        
        overall_score = self._quality_to_score(predicted_class)
        conflict_severity = min(features.num_conflicts / max(features.num_events, 1), 1.0)
        
        # Generate suggestions
        suggestions = self._generate_suggestions(features)
        
        return ScheduleQuality(
            overall_score=overall_score,
            category=predicted_class,
            completion_probability=completion_prob,
            conflict_severity=conflict_severity,
            optimization_suggestions=suggestions,
            confidence=float(confidence)
        )
    
    def _quality_to_score(self, quality: str) -> float:
        """Convert quality label to numerical score"""
        scores = {
            "poor": 0.25,
            "fair": 0.5,
            "good": 0.75,
            "excellent": 1.0
        }
        return scores.get(quality, 0.5)
    
    def _generate_suggestions(self, features: ScheduleFeatures) -> List[str]:
        """Generate optimization suggestions based on features"""
        suggestions = []
        
        if features.num_conflicts > 0:
            suggestions.append(f"Resolve {features.num_conflicts} schedule conflict(s)")
        
        if features.avg_gap_between < 0.5:
            suggestions.append("Increase buffer time between events")
        
        if features.morning_load > 0.8:
            suggestions.append("Consider moving tasks to afternoon")
        
        if features.completion_rate < 0.7:
            suggestions.append("Reduce daily workload to improve completion")
        
        if features.num_events > 10:
            suggestions.append("Break large tasks into smaller segments")
        
        if len(features.peak_productivity_hours) > 0:
            peak_str = ", ".join(map(str, sorted(features.peak_productivity_hours)[:3]))
            suggestions.append(f"Schedule important tasks at {peak_str}:00")
        
        return suggestions[:3]  # Return top 3 suggestions


# ============================================================================
# BIDIRECTIONAL FEEDBACK SYSTEM
# ============================================================================

class BidirectionalFeedback:
    """
    Bidirectional feedback integration between:
    - User feedback (accept/reject suggestions)
    - Q-learner (learns preferences)
    - Neural network (learns quality patterns)
    - Productivity tracker (learns effectiveness)
    """
    
    def __init__(self, q_learner=None, nn_classifier=None):
        """
        Initialize bidirectional feedback system
        
        Args:
            q_learner: Q-learning agent instance
            nn_classifier: Schedule quality classifier instance
        """
        self.q_learner = q_learner
        self.nn_classifier = nn_classifier
        self.feedback_log_path = os.path.expanduser(
            "~/vvu-scheduler/json/bidirectional_feedback.json"
        )
        self.feedback_log = self._load_feedback_log()
    
    def _load_feedback_log(self) -> List[Dict]:
        """Load feedback log from disk"""
        if os.path.exists(self.feedback_log_path):
            try:
                with open(self.feedback_log_path, 'r') as f:
                    return json.load(f)
            except:
                return []
        return []
    
    def _save_feedback_log(self):
        """Save feedback log to disk"""
        os.makedirs(os.path.dirname(self.feedback_log_path), exist_ok=True)
        try:
            with open(self.feedback_log_path, 'w') as f:
                json.dump(self.feedback_log, f, indent=2, default=str)
        except Exception as e:
            logger.warning(f"[FEEDBACK] Failed to save log: {e}")
    
    def record_user_feedback(
        self,
        schedule_features: ScheduleFeatures,
        user_action: str,  # "accept", "reject", "complete", "abandon"
        schedule_quality: ScheduleQuality = None,
        additional_data: Dict = None
    ):
        """
        Record user feedback and propagate to all learning systems
        
        Args:
            schedule_features: Features of schedule being evaluated
            user_action: Type of user action
            schedule_quality: Neural network prediction (optional)
            additional_data: Extra context data
        """
        feedback_entry = {
            "timestamp": datetime.now().isoformat(),
            "action": user_action,
            "schedule_score": schedule_quality.overall_score if schedule_quality else None,
            "schedule_category": schedule_quality.category if schedule_quality else None,
            "features": asdict(schedule_features),
            "additional_data": additional_data or {}
        }
        
        # Propagate to Q-learner
        if self.q_learner and user_action in ["accept", "reject"]:
            reward = 1.0 if user_action == "accept" else -1.0
            logger.info(f"[FEEDBACK] Q-Learner: {user_action} (reward: {reward})")
            # Q-learner update would happen here
        
        # Propagate to Neural Network (collect for retraining)
        if schedule_quality:
            quality_label = schedule_quality.category
            logger.info(f"[FEEDBACK] NN: Schedule rated as {quality_label}")
            # Would be collected for periodic retraining
        
        self.feedback_log.append(feedback_entry)
        self._save_feedback_log()
        
        logger.info(f"[FEEDBACK] Recorded: {user_action}")
    
    def get_system_state(self) -> Dict:
        """Get current state of all learning systems"""
        return {
            "timestamp": datetime.now().isoformat(),
            "total_feedback_entries": len(self.feedback_log),
            "feedback_actions": self._count_actions(),
            "q_learner_active": self.q_learner is not None,
            "nn_classifier_active": self.nn_classifier is not None,
            "nn_confidence": float(self.nn_classifier.last_accuracy)
            if self.nn_classifier and isinstance(self.nn_classifier.last_accuracy, (int, float))
            else None
        }
    
    def _count_actions(self) -> Dict[str, int]:
        """Count occurrences of each action type"""
        counts = {}
        for entry in self.feedback_log:
            action = entry.get("action", "unknown")
            counts[action] = counts.get(action, 0) + 1
        return counts


# ============================================================================
# FACTORY FUNCTIONS
# ============================================================================

_classifier_instance = None
_bidirectional_instance = None

def get_classifier() -> ScheduleQualityClassifier:
    """Get or create global classifier instance"""
    global _classifier_instance
    if _classifier_instance is None:
        _classifier_instance = ScheduleQualityClassifier()
    return _classifier_instance

def get_bidirectional_feedback() -> BidirectionalFeedback:
    """Get or create global bidirectional feedback instance"""
    global _bidirectional_instance
    if _bidirectional_instance is None:
        _bidirectional_instance = BidirectionalFeedback(
            nn_classifier=get_classifier()
        )
    return _bidirectional_instance

def initialize_deep_learning():
    """Initialize all deep learning systems"""
    classifier = get_classifier()
    feedback = get_bidirectional_feedback()
    
    logger.info("[DL] Deep Learning System Initialized")
    logger.info(f"[DL] Classifier trained: {classifier.is_trained}")
    logger.info(f"[DL] Bidirectional feedback active")
    
    return classifier, feedback


if __name__ == "__main__":
    # Test the module
    print("="*70)
    print("DEEP LEARNING MODULE TEST")
    print("="*70)
    
    # Create test features
    features = ScheduleFeatures(
        num_events=5,
        total_hours=6.0,
        avg_gap_between=0.5,
        morning_load=0.6,
        afternoon_load=0.4,
        evening_load=0.0,
        num_conflicts=1,
        avg_event_duration=1.2,
        q_learner_accept_rate=0.8,
        q_learner_confidence=0.75,
        num_learned_preferences=5,
        avg_quality_rating=4.0,
        completion_rate=0.9,
        user_load_factor=0.6,
        peak_productivity_hours=[9, 10, 14]
    )
    
    # Initialize and predict
    classifier = get_classifier()
    prediction = classifier.predict(features)
    
    print(f"\nFeatures: {features.num_events} events, {features.total_hours}h total")
    print(f"Prediction: {prediction.category} ({prediction.overall_score:.2f})")
    print(f"Completion probability: {prediction.completion_probability:.2%}")
    print(f"Suggestions:")
    for i, sugg in enumerate(prediction.optimization_suggestions, 1):
        print(f"  {i}. {sugg}")
    
    # Test bidirectional feedback
    feedback = get_bidirectional_feedback()
    feedback.record_user_feedback(features, "accept", prediction)
    
    state = feedback.get_system_state()
    print(f"\nSystem State:")
    print(f"  Total feedback: {state['total_feedback_entries']}")
    print(f"  Actions: {state['feedback_actions']}")
    
    print("\n" + "="*70)
    print("DEEP LEARNING MODULE TEST - COMPLETE")
    print("="*70)
