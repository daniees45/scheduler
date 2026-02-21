"""
PHASE 4: Training Data Generator
Generates synthetic training data for neural network initialization
"""

import numpy as np
from datetime import time
from typing import List, Tuple
from deep_learning import ScheduleFeatures, ScheduleQualityClassifier

def generate_training_data(num_samples: int = 1000) -> List[Tuple[ScheduleFeatures, str]]:
    """
    Generate synthetic training data for schedule quality classification
    
    Args:
        num_samples: Number of training examples to generate
    
    Returns:
        List of (ScheduleFeatures, quality_label) tuples
    """
    training_data = []
    np.random.seed(42)
    
    for _ in range(num_samples):
        # Generate random schedule features
        num_events = np.random.randint(2, 12)
        total_hours = np.random.uniform(2, 10)
        avg_gap = np.random.uniform(0, 2)
        morning_load = np.random.uniform(0, 1)
        afternoon_load = np.random.uniform(0, 1)
        evening_load = np.random.uniform(0, 0.5)
        num_conflicts = np.random.randint(0, 3)
        avg_duration = total_hours / max(num_events, 1)
        q_accept_rate = np.random.uniform(0.4, 1.0)
        q_confidence = np.random.uniform(0.3, 1.0)
        learned_prefs = np.random.randint(0, 15)
        quality_rating = np.random.uniform(2, 5)
        completion_rate = np.random.uniform(0.4, 1.0)
        user_load = np.random.uniform(0, 1)
        peak_hours = [int(h) for h in np.random.choice(24, size=np.random.randint(1, 4), replace=False)]
        
        features = ScheduleFeatures(
            num_events=int(num_events),
            total_hours=float(total_hours),
            avg_gap_between=float(avg_gap),
            morning_load=float(morning_load),
            afternoon_load=float(afternoon_load),
            evening_load=float(evening_load),
            num_conflicts=int(num_conflicts),
            avg_event_duration=float(avg_duration),
            q_learner_accept_rate=float(q_accept_rate),
            q_learner_confidence=float(q_confidence),
            num_learned_preferences=int(learned_prefs),
            avg_quality_rating=float(quality_rating),
            completion_rate=float(completion_rate),
            user_load_factor=float(user_load),
            peak_productivity_hours=peak_hours
        )
        
        # Determine quality label based on heuristic rules
        quality_label = _classify_schedule_quality(features)
        training_data.append((features, quality_label))
    
    return training_data


def _classify_schedule_quality(features: ScheduleFeatures) -> str:
    """
    Use heuristic rules to classify schedule quality
    Used for generating training labels
    """
    score = 0.0
    
    # Positive factors
    if features.num_conflicts == 0:
        score += 0.25
    if features.avg_gap_between >= 0.5:
        score += 0.15
    if features.completion_rate > 0.8:
        score += 0.2
    if features.q_learner_accept_rate > 0.7:
        score += 0.1
    if len(features.peak_productivity_hours) > 0:
        score += 0.1
    if features.num_events <= 6:
        score += 0.1
    
    # Negative factors
    if features.num_conflicts > 1:
        score -= 0.2
    if features.morning_load > 0.9:
        score -= 0.1
    if features.completion_rate < 0.5:
        score -= 0.2
    if features.total_hours > 10:
        score -= 0.15
    
    # Classify based on score
    if score >= 0.8:
        return "excellent"
    elif score >= 0.5:
        return "good"
    elif score >= 0.2:
        return "fair"
    else:
        return "poor"


def train_classifier_on_synthetic_data(num_samples: int = 1000):
    """
    Train neural network classifier on synthetic data
    
    Args:
        num_samples: Number of training examples
    """
    print("="*70)
    print("NEURAL NETWORK TRAINING: SYNTHETIC DATA")
    print("="*70)
    
    # Generate training data
    print(f"\n[1] Generating {num_samples} synthetic training examples...")
    training_data = generate_training_data(num_samples)
    
    # Count labels
    label_counts = {}
    for _, label in training_data:
        label_counts[label] = label_counts.get(label, 0) + 1
    
    print(f"    Generated samples by quality:")
    for label in ["poor", "fair", "good", "excellent"]:
        count = label_counts.get(label, 0)
        pct = 100 * count / len(training_data)
        print(f"      {label:10s}: {count:4d} ({pct:5.1f}%)")
    
    # Train classifier
    print(f"\n[2] Training neural network classifier...")
    from deep_learning import get_classifier
    classifier = get_classifier()
    classifier.train(training_data)
    
    print(f"    ✓ Classifier trained and saved")
    print(f"    ✓ Model path: {classifier.model_path}")
    
    # Evaluate on training data
    print(f"\n[3] Evaluating classifier accuracy...")
    correct = 0
    for features, true_label in training_data[:100]:  # Evaluate on sample
        prediction = classifier.predict(features)
        if prediction.category == true_label:
            correct += 1
    
    accuracy = 100 * correct / 100
    print(f"    Accuracy on sample: {accuracy:.1f}%")
    
    print("\n" + "="*70)
    print("NEURAL NETWORK TRAINING - COMPLETE")
    print("="*70)
    
    return classifier


if __name__ == "__main__":
    train_classifier_on_synthetic_data(1000)
