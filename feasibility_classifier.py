"""
Feasibility Classifier: ML-based prediction of schedule assignment success
Predicts if a (course, day, slot, room) combination will succeed before CSP solver tries it
Uses RandomForestClassifier trained on historical schedules
"""

import os
import pickle
import pandas as pd
import numpy as np
SHAP_AVAILABLE = False
shap = None
from sklearn.ensemble import RandomForestClassifier
from sklearn.preprocessing import LabelEncoder
from sklearn.model_selection import train_test_split
from sklearn.metrics import accuracy_score, precision_score, recall_score, f1_score


class FeasibilityClassifier:
    """
    Predicts if a scheduling assignment (course, day, slot, room) will succeed.
    Features: course_level, day, slot, room_capacity, lecturer_experience, enrollment
    """
    
    def __init__(self, model_path='feasibility_classifier.pkl'):
        self.model_path = model_path
        self.classifier = None
        self.label_encoders = {}
        self.feature_names = []
        self.metadata = {
            'trained': False,
            'accuracy': 0.0,
            'precision': 0.0,
            'recall': 0.0,
            'f1': 0.0,
            'training_samples': 0,
            'feature_importances': {}
        }
    
    @staticmethod
    def extract_level(course_code):
        """Extract course level from code (e.g., CSC301 -> 3)"""
        if not course_code:
            return 1
        for char in str(course_code):
            if char.isdigit():
                return int(char)
        return 1
    
    @staticmethod
    def day_to_number(day_str):
        """Convert day name to number"""
        day_map = {
            'Mon': 0, 'Monday': 0,
            'Tue': 1, 'Tuesday': 1,
            'Wed': 2, 'Wednesday': 2,
            'Thu': 3, 'Thursday': 3,
            'Fri': 4, 'Friday': 4
        }
        return day_map.get(str(day_str)[:3].strip(), 0)
    
    @staticmethod
    def slot_to_number(time_str):
        """Convert time to slot number (0-3)"""
        slot_map = {
            '7:00': 0, '7:00am': 0, '7:00 am': 0,
            '10:00': 1, '10:00am': 1, '10:00 am': 1,
            '2:00': 2, '2:00pm': 2, '2:00 pm': 2,
            '5:00': 3, '5:00pm': 3, '5:00 pm': 3
        }
        time_normalized = str(time_str).lower().split('-')[0].strip()
        for key in slot_map:
            if key in time_normalized:
                return slot_map[key]
        return 1  # Default to mid-morning
    
    def prepare_training_data(self, historical_schedules_csv, failures_csv=None):
        """
        Prepare training data from successful schedules (and failures if available)
        
        Args:
            historical_schedules_csv: CSV with successful schedules
            failures_csv: Optional CSV with failed assignments (outcome=0)
        
        Returns:
            (X, y) - feature DataFrame and label array
        """
        features_list = []
        labels_list = []
        
        # Load successful schedules
        if os.path.exists(historical_schedules_csv):
            success_df = pd.read_csv(historical_schedules_csv, on_bad_lines='skip')
            
            for _, row in success_df.iterrows():
                feature_dict = self._extract_features(row)
                if feature_dict:
                    features_list.append(feature_dict)
                    labels_list.append(1)  # Success label
        
        # Load failures if available
        if failures_csv and os.path.exists(failures_csv):
            failure_df = pd.read_csv(failures_csv, on_bad_lines='skip')
            
            for _, row in failure_df.iterrows():
                feature_dict = self._extract_features(row)
                if feature_dict:
                    features_list.append(feature_dict)
                    labels_list.append(0)  # Failure label
        
        if not features_list:
            print("[WARNING] No training data found for feasibility classifier")
            return None, None
        
        # Convert to DataFrame
        X = pd.DataFrame(features_list)
        y = np.array(labels_list)
        
        # Store feature names
        self.feature_names = X.columns.tolist()
        
        print(f"[CLASSIFIER] Prepared {len(X)} training samples ({(y==1).sum()} successes, {(y==0).sum()} failures)")
        
        return X, y
    
    def _extract_features(self, row):
        """Extract features from a schedule row"""
        try:
            feature_dict = {
                'course_level': self.extract_level(row.get('course_code', '')),
                'day': self.day_to_number(row.get('day', '')),
                'slot': self.slot_to_number(row.get('start_time', '')),
                'enrollment': int(row.get('enrollment', 30)) if row.get('enrollment') else 30,
            }
            return feature_dict
        except Exception as e:
            print(f"[WARNING] Failed to extract features: {e}")
            return None
    
    def train(self, X, y, test_size=0.2, random_state=42):
        """
        Train the RandomForestClassifier
        
        Args:
            X: Feature DataFrame
            y: Label array
            test_size: Train/test split ratio
            random_state: Random seed for reproducibility
        """
        if X is None or y is None:
            print("[ERROR] No training data provided")
            return False
        
        # Split data
        X_train, X_test, y_train, y_test = train_test_split(
            X, y, test_size=test_size, random_state=random_state
        )
        
        # Train classifier
        self.classifier = RandomForestClassifier(
            n_estimators=100,
            max_depth=15,
            min_samples_split=5,
            min_samples_leaf=2,
            random_state=random_state,
            n_jobs=-1,
            class_weight='balanced'  # Handle imbalanced classes
        )
        
        self.classifier.fit(X_train, y_train)
        
        # Evaluate
        y_pred = self.classifier.predict(X_test)
        
        accuracy = accuracy_score(y_test, y_pred)
        precision = precision_score(y_test, y_pred, zero_division=0)
        recall = recall_score(y_test, y_pred, zero_division=0)
        f1 = f1_score(y_test, y_pred, zero_division=0)
        
        # Store feature importances
        feature_importance_dict = dict(zip(
            self.feature_names,
            self.classifier.feature_importances_
        ))
        
        self.metadata['trained'] = True
        self.metadata['accuracy'] = accuracy
        self.metadata['precision'] = precision
        self.metadata['recall'] = recall
        self.metadata['f1'] = f1
        self.metadata['training_samples'] = len(X_train)
        self.metadata['feature_importances'] = feature_importance_dict
        
        print(f"[CLASSIFIER] Training Results:")
        print(f"  Accuracy:  {accuracy:.2%}")
        print(f"  Precision: {precision:.2%}")
        print(f"  Recall:    {recall:.2%}")
        print(f"  F1 Score:  {f1:.2%}")
        print(f"  Feature Importances: {feature_importance_dict}")
        
        return True

    def explain_model(self, X, output_csv='shap_values.csv', output_plot='shap_summary.png',
                      max_samples=200, random_state=42):
        """
        Generate SHAP explanations for the trained model.

        Args:
            X: Feature DataFrame used for explanation
            output_csv: Path to save SHAP value summaries (CSV)
            output_plot: Path to save SHAP summary plot (PNG)
            max_samples: Max samples to explain (for speed)
            random_state: Random seed for sampling

        Returns:
            Dict with summary stats or None if SHAP not available
        """
        try:
            import shap
            print("[SHAP] SHAP module imported successfully")
        except ImportError as e:
            print(f"[SHAP] SHAP is not installed. Install with: pip install shap")
            print(f"[SHAP] Error details: {e}")
            return None
        
        if self.classifier is None:
            print("[SHAP] No trained model available for explanation.")
            return None
        if X is None or len(X) == 0:
            print("[SHAP] No data provided for explanation.")
            return None

        try:
            # Sample for speed
            if len(X) > max_samples:
                X_sample = X.sample(n=max_samples, random_state=random_state)
            else:
                X_sample = X

            explainer = shap.TreeExplainer(self.classifier)
            shap_values = explainer.shap_values(X_sample)

            # For binary classification, shap_values is a list [class0, class1]
            if isinstance(shap_values, list) and len(shap_values) > 1:
                shap_matrix = shap_values[1]
            else:
                shap_matrix = shap_values

            # Compute mean absolute SHAP values for global importance
            mean_abs = np.abs(shap_matrix).mean(axis=0)
            feature_importance = pd.DataFrame({
                'feature': X_sample.columns.tolist(),
                'mean_abs_shap': mean_abs
            }).sort_values('mean_abs_shap', ascending=False)

            feature_importance.to_csv(output_csv, index=False)
            print(f"[SHAP] Saved SHAP feature importance to {output_csv}")

            # Save plot if matplotlib available
            try:
                shap.summary_plot(shap_matrix, X_sample, show=False)
                import matplotlib.pyplot as plt
                plt.tight_layout()
                plt.savefig(output_plot, dpi=150)
                plt.close()
                print(f"[SHAP] Saved SHAP summary plot to {output_plot}")
            except Exception as plot_err:
                print(f"[SHAP] Plot skipped: {plot_err}")

            return {
                'samples_explained': len(X_sample),
                'output_csv': output_csv,
                'output_plot': output_plot
            }
        except Exception as e:
            print(f"[SHAP] Explanation error: {e}")
            return None
    
    def predict_feasibility(self, course_code, day, slot, enrollment=30):
        """
        Predict if a specific assignment will succeed
        
        Args:
            course_code: Course code (e.g., 'CSC301')
            day: Day name (e.g., 'Monday')
            slot: Time slot (e.g., '10:00am')
            enrollment: Number of students
        
        Returns:
            float: Probability of success [0, 1]
        """
        if self.classifier is None:
            return 0.5  # Default: uncertain
        
        try:
            features = pd.DataFrame([{
                'course_level': self.extract_level(course_code),
                'day': self.day_to_number(day),
                'slot': self.slot_to_number(slot),
                'enrollment': enrollment
            }])
            
            # Predict probability
            proba = self.classifier.predict_proba(features)[0]
            
            # Handle case where model only has 1 class (all successes)
            # If only one class exists, proba will be [1.0] for that class
            if len(proba) == 1:
                # Only one class in training data - assume it's success (class 1)
                prob_success = 1.0
            else:
                # Normal case: get probability of class 1 (success)
                prob_success = proba[1]
            
            return prob_success
        except Exception as e:
            print(f"[WARNING] Prediction error: {e}")
            return 0.5
    
    def predict_batch(self, assignments):
        """
        Predict feasibility for multiple assignments
        
        Args:
            assignments: List of dicts with keys: course_code, day, slot, enrollment
        
        Returns:
            List of probabilities [0, 1]
        """
        if self.classifier is None:
            return [0.5] * len(assignments)
        
        try:
            features = pd.DataFrame([
                {
                    'course_level': self.extract_level(a.get('course_code', '')),
                    'day': self.day_to_number(a.get('day', '')),
                    'slot': self.slot_to_number(a.get('slot', '')),
                    'enrollment': a.get('enrollment', 30)
                }
                for a in assignments
            ])
            
            probs = self.classifier.predict_proba(features)
            
            # Handle case where model only has 1 class (all successes)
            if probs.shape[1] == 1:
                # Only one class in training data - assume it's success (class 1)
                return [1.0] * len(assignments)
            else:
                # Normal case: get probability of class 1 (success)
                return probs[:, 1].tolist()
        except Exception as e:
            print(f"[WARNING] Batch prediction error: {e}")
            return [0.5] * len(assignments)
    
    def save(self):
        """Save model to disk"""
        try:
            model_data = {
                'classifier': self.classifier,
                'label_encoders': self.label_encoders,
                'feature_names': self.feature_names,
                'metadata': self.metadata
            }
            
            with open(self.model_path, 'wb') as f:
                pickle.dump(model_data, f)
            
            print(f"[CLASSIFIER] Model saved to {self.model_path}")
            return True
        except Exception as e:
            print(f"[ERROR] Failed to save model: {e}")
            return False
    
    def load(self):
        """Load model from disk"""
        if not os.path.exists(self.model_path):
            print(f"[WARNING] Model file not found: {self.model_path}")
            return False
        
        try:
            with open(self.model_path, 'rb') as f:
                model_data = pickle.load(f)
            
            self.classifier = model_data.get('classifier')
            self.label_encoders = model_data.get('label_encoders', {})
            self.feature_names = model_data.get('feature_names', [])
            self.metadata = model_data.get('metadata', {})
            
            print(f"[CLASSIFIER] Model loaded from {self.model_path}")
            print(f"  Accuracy: {self.metadata.get('accuracy', 0):.2%}")
            print(f"  Trained: {self.metadata.get('trained', False)}")
            return True
        except Exception as e:
            print(f"[ERROR] Failed to load model: {e}")
            return False


# Demo/Testing
if __name__ == "__main__":
    # Example usage
    classifier = FeasibilityClassifier('my_feasibility_model.pkl')
    
    # Training (if historical data available)
    X, y = classifier.prepare_training_data('historical_schedule.csv')
    if X is not None:
        classifier.train(X, y)
        classifier.save()
    
    # Prediction
    print("\n--- Testing Predictions ---")
    prob = classifier.predict_feasibility('CSC301', 'Monday', '10:00am', enrollment=35)
    print(f"CSC301 on Monday 10am (35 students): {prob:.2%} success probability")
