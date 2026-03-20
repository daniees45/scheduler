"""
Model Manager - Handles AI model persistence and management
Saves/loads all AI models to/from model/ directory as .pkl files
"""

import os
import pickle
from typing import Dict, Any


class AIModelManager:
    """Manages persistence of all AI models to .pkl files"""
    
    def __init__(self, base_path: str = "."):
        self.base_path = base_path
        self.model_dir = os.path.join(base_path, "history")
        
        # Ensure model directory exists
        os.makedirs(self.model_dir, exist_ok=True)
        
        # Model file paths
        self.qlearn_model_file = os.path.join(self.model_dir, "qlearn_preferences.pkl")
        self.feasibility_model_file = os.path.join(self.model_dir, "feasibility_classifier.pkl")
        self.ensemble_model_file = os.path.join(self.model_dir, "ensemble_model.pkl")
        self.csp_config_file = os.path.join(self.model_dir, "csp_config.pkl")
    
    def save_qlearn_model(self, model):
        """Save Q-Learning preference model"""
        try:
            model.save(self.qlearn_model_file)
            return True
        except Exception as e:
            print(f"✗ Error saving Q-Learning model: {e}")
            return False
    
    def load_qlearn_model(self, model):
        """Load Q-Learning preference model"""
        try:
            if os.path.exists(self.qlearn_model_file):
                model.load(self.qlearn_model_file)
                return True
            return False
        except Exception as e:
            print(f"✗ Error loading Q-Learning model: {e}")
            return False
    
    def save_feasibility_model(self, model):
        """Save feasibility classifier"""
        try:
            model.save(self.feasibility_model_file)
            return True
        except Exception as e:
            print(f"✗ Error saving feasibility model: {e}")
            return False
    
    def load_feasibility_model(self, model):
        """Load feasibility classifier"""
        try:
            if os.path.exists(self.feasibility_model_file):
                model.load(self.feasibility_model_file)
                return True
            return False
        except Exception as e:
            print(f"✗ Error loading feasibility model: {e}")
            return False
    
    def save_ensemble_model(self, model_dict: Dict[str, Any]):
        """Save all models as an ensemble"""
        try:
            with open(self.ensemble_model_file, 'wb') as f:
                pickle.dump(model_dict, f)
            print(f"✓ Ensemble model saved to {self.ensemble_model_file}")
            return True
        except Exception as e:
            print(f"✗ Error saving ensemble model: {e}")
            return False
    
    def load_ensemble_model(self) -> Dict[str, Any]:
        """Load all models from ensemble"""
        try:
            if os.path.exists(self.ensemble_model_file):
                with open(self.ensemble_model_file, 'rb') as f:
                    model_dict = pickle.load(f)
                print(f"✓ Ensemble model loaded from {self.ensemble_model_file}")
                return model_dict
            return {}
        except Exception as e:
            print(f"✗ Error loading ensemble model: {e}")
            return {}
    
    def save_csp_config(self, config: Dict[str, Any]):
        """Save CSP solver configuration"""
        try:
            with open(self.csp_config_file, 'wb') as f:
                pickle.dump(config, f)
            print(f"✓ CSP configuration saved to {self.csp_config_file}")
            return True
        except Exception as e:
            print(f"✗ Error saving CSP config: {e}")
            return False
    
    def load_csp_config(self) -> Dict[str, Any]:
        """Load CSP solver configuration"""
        try:
            if os.path.exists(self.csp_config_file):
                with open(self.csp_config_file, 'rb') as f:
                    config = pickle.load(f)
                print(f"✓ CSP configuration loaded from {self.csp_config_file}")
                return config
            return {}
        except Exception as e:
            print(f"✗ Error loading CSP config: {e}")
            return {}
    
    def get_model_status(self) -> Dict[str, Any]:
        """Get status of all models"""
        return {
            'qlearn_exists': os.path.exists(self.qlearn_model_file),
            'feasibility_exists': os.path.exists(self.feasibility_model_file),
            'ensemble_exists': os.path.exists(self.ensemble_model_file),
            'csp_config_exists': os.path.exists(self.csp_config_file),
            'model_dir': self.model_dir,
            'models': [
                f for f in os.listdir(self.model_dir) if f.endswith('.pkl')
            ] if os.path.exists(self.model_dir) else []
        }
    
    def list_available_models(self):
        """List all available models"""
        status = self.get_model_status()
        
        print("\n" + "="*70)
        print("  AVAILABLE AI MODELS")
        print("="*70)
        print(f"\nModel Directory: {self.model_dir}")
        print("\nModels:")
        
        if status['qlearn_exists']:
            print(f"  ✓ Q-Learning Preferences...... qlearn_preferences.pkl")
        else:
            print(f"  ○ Q-Learning Preferences...... (not trained yet)")
        
        if status['feasibility_exists']:
            print(f"  ✓ Feasibility Classifier...... feasibility_classifier.pkl")
        else:
            print(f"  ○ Feasibility Classifier...... (not trained yet)")
        
        if status['csp_config_exists']:
            print(f"  ✓ CSP Configuration........... csp_config.pkl")
        else:
            print(f"  ○ CSP Configuration........... (not configured yet)")
        
        if status['ensemble_exists']:
            print(f"  ✓ Ensemble Model.............. ensemble_model.pkl")
        else:
            print(f"  ○ Ensemble Model.............. (not created yet)")
        
        print(f"\nTotal Models: {len(status['models'])}")
