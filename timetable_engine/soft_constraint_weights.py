from typing import Dict, List, Optional
from dataclasses import dataclass, field

@dataclass
class ConstraintWeight:
    """Represents weight for a single soft constraint"""
    constraint_name: str
    weight: float  # 0.0 to 1.0, how much this constraint matters in scoring
    priority: int  # 1-10, higher = more important
    enabled: bool = True
    description: str = ""

class SoftConstraintWeightingSystem:
    """Manages soft constraint weights and priorities for optimization"""
    
    def __init__(self):
        self.weights: Dict[str, ConstraintWeight] = {}
        self.presets = {}
        self._init_default_weights()
        self._init_presets()
    
    def _init_default_weights(self):
        """Initialize default soft constraint weights"""
        default_constraints = {
            "room_utilization": ConstraintWeight(
                constraint_name="room_utilization",
                weight=0.7,
                priority=8,
                description="Minimize room idle time gaps"
            ),
            "lecturer_workload": ConstraintWeight(
                constraint_name="lecturer_workload",
                weight=0.6,
                priority=7,
                description="Distribute lecturer teaching hours evenly"
            ),
            "student_load": ConstraintWeight(
                constraint_name="student_load",
                weight=0.8,
                priority=9,
                description="Avoid clustering same-level courses"
            ),
            "course_gaps": ConstraintWeight(
                constraint_name="course_gaps",
                weight=0.5,
                priority=6,
                description="Reduce gaps between lectures for same cohort"
            ),
            "lecturer_gaps": ConstraintWeight(
                constraint_name="lecturer_gaps",
                weight=0.4,
                priority=5,
                description="Minimize gaps in lecturer schedule"
            ),
            "room_consolidation": ConstraintWeight(
                constraint_name="room_consolidation",
                weight=0.55,
                priority=6,
                description="Keep same course in same room"
            ),
            "lunch_break_preference": ConstraintWeight(
                constraint_name="lunch_break_preference",
                weight=0.3,
                priority=4,
                description="Maintain lunch break between courses"
            ),
            "minimize_back_to_back": ConstraintWeight(
                constraint_name="minimize_back_to_back",
                weight=0.45,
                priority=5,
                description="Avoid back-to-back classes when possible"
            ),
            "early_course_preference": ConstraintWeight(
                constraint_name="early_course_preference",
                weight=0.2,
                priority=3,
                description="Prefer morning classes over afternoon"
            ),
            "building_proximity": ConstraintWeight(
                constraint_name="building_proximity",
                weight=0.35,
                priority=4,
                description="Keep related courses in same building"
            ),
            "group_coherence": ConstraintWeight(
                constraint_name="group_coherence",
                weight=0.65,
                priority=8,
                description="Keep grouped courses at same time"
            ),
            "exam_spacing": ConstraintWeight(
                constraint_name="exam_spacing",
                weight=0.75,
                priority=8,
                description="Space out exams for same level"
            ),
        }
        
        for name, constraint in default_constraints.items():
            self.weights[name] = constraint
    
    def _init_presets(self):
        """Initialize optimization presets"""
        self.presets = {
            "balanced": {
                "room_utilization": 0.7,
                "lecturer_workload": 0.6,
                "student_load": 0.8,
                "course_gaps": 0.5,
                "lecturer_gaps": 0.4,
                "group_coherence": 0.65,
            },
            "student_friendly": {
                "room_utilization": 0.5,
                "lecturer_workload": 0.4,
                "student_load": 0.95,
                "course_gaps": 0.8,
                "lecturer_gaps": 0.3,
                "group_coherence": 0.9,
                "minimize_back_to_back": 0.75,
                "lunch_break_preference": 0.7,
            },
            "resource_efficient": {
                "room_utilization": 0.95,
                "lecturer_workload": 0.8,
                "student_load": 0.6,
                "course_gaps": 0.2,
                "lecturer_gaps": 0.7,
                "group_coherence": 0.5,
                "room_consolidation": 0.85,
            },
            "minimal": {
                "room_utilization": 0.3,
                "lecturer_workload": 0.3,
                "student_load": 0.3,
                "course_gaps": 0.2,
                "lecturer_gaps": 0.2,
                "group_coherence": 0.1,
            }
        }
    
    def set_weight(self, constraint_name: str, weight: float) -> bool:
        """Set weight for a constraint"""
        if constraint_name not in self.weights:
            return False
        
        if not 0.0 <= weight <= 1.0:
            return False
        
        self.weights[constraint_name].weight = weight
        return True
    
    def set_priority(self, constraint_name: str, priority: int) -> bool:
        """Set priority for a constraint"""
        if constraint_name not in self.weights:
            return False
        
        if not 1 <= priority <= 10:
            return False
        
        self.weights[constraint_name].priority = priority
        return True
    
    def enable_constraint(self, constraint_name: str) -> bool:
        """Enable a constraint"""
        if constraint_name not in self.weights:
            return False
        self.weights[constraint_name].enabled = True
        return True
    
    def disable_constraint(self, constraint_name: str) -> bool:
        """Disable a constraint"""
        if constraint_name not in self.weights:
            return False
        self.weights[constraint_name].enabled = False
        return True
    
    def apply_preset(self, preset_name: str) -> bool:
        """Apply a predefined weight preset"""
        if preset_name not in self.presets:
            return False
        
        preset = self.presets[preset_name]
        for constraint_name, weight in preset.items():
            if constraint_name in self.weights:
                self.weights[constraint_name].weight = weight
        
        return True
    
    def get_enabled_weights(self) -> Dict[str, float]:
        """Get all enabled constraint weights"""
        return {
            name: constraint.weight
            for name, constraint in self.weights.items()
            if constraint.enabled
        }
    
    def get_weight_vector(self) -> List[float]:
        """Get weight vector for optimization algorithms"""
        return [c.weight for c in self.weights.values() if c.enabled]
    
    def normalize_weights(self) -> Dict[str, float]:
        """Normalize weights to sum to 1.0"""
        enabled = self.get_enabled_weights()
        total = sum(enabled.values())
        
        if total == 0:
            return {}
        
        return {name: weight / total for name, weight in enabled.items()}
    
    def calculate_priority_score(self) -> float:
        """Calculate overall priority score"""
        enabled_constraints = [c for c in self.weights.values() if c.enabled]
        if not enabled_constraints:
            return 0
        
        return sum(c.priority * c.weight for c in enabled_constraints) / len(enabled_constraints)
    
    def get_top_priority_constraints(self, top_n: int = 5) -> List[ConstraintWeight]:
        """Get top N constraints by priority"""
        enabled = [c for c in self.weights.values() if c.enabled]
        sorted_constraints = sorted(enabled, key=lambda c: c.priority * c.weight, reverse=True)
        return sorted_constraints[:top_n]
    
    def get_weight_config(self) -> Dict:
        """Export current weight configuration"""
        return {
            "constraints": {
                name: {
                    "weight": constraint.weight,
                    "priority": constraint.priority,
                    "enabled": constraint.enabled,
                    "description": constraint.description
                }
                for name, constraint in self.weights.items()
            },
            "normalized_weights": self.normalize_weights(),
            "priority_score": self.calculate_priority_score()
        }
    
    def load_weight_config(self, config: Dict) -> bool:
        """Load weight configuration from dict"""
        try:
            for constraint_name, config_data in config.get("constraints", {}).items():
                if constraint_name in self.weights:
                    self.weights[constraint_name].weight = config_data.get("weight", 0.5)
                    self.weights[constraint_name].priority = config_data.get("priority", 5)
                    self.weights[constraint_name].enabled = config_data.get("enabled", True)
            return True
        except Exception as e:
            print(f"Error loading weight config: {e}")
            return False
    
    def calculate_schedule_fitness_soft(self, schedule, metrics_dict: Dict) -> float:
        """
        Calculate fitness score based on soft constraints (0-100)
        
        Args:
            schedule: The schedule to evaluate
            metrics_dict: Dict with computed metrics like:
                - "room_utilization_score": 0-1
                - "lecturer_workload_variance": 0-1
                - "student_load_balance": 0-1
                - "gap_score": 0-1
                - etc.
        """
        enabled_weights = self.normalize_weights()
        total_score = 0
        
        for constraint_name, weight in enabled_weights.items():
            metric_key = f"{constraint_name}_score"
            metric_value = metrics_dict.get(metric_key, 0.5)  # Default to neutral
            total_score += metric_value * weight
        
        return round(total_score * 100, 2)
    
    def get_constraint_impact(self, constraint_name: str, delta: float) -> Dict:
        """Estimate impact of changing a constraint weight"""
        if constraint_name not in self.weights:
            return {}
        
        current_weight = self.weights[constraint_name].weight
        new_weight = max(0, min(1, current_weight + delta))
        weight_change = new_weight - current_weight
        
        return {
            "constraint": constraint_name,
            "current_weight": current_weight,
            "new_weight": new_weight,
            "weight_change": weight_change,
            "priority": self.weights[constraint_name].priority,
            "estimated_impact": "high" if abs(weight_change) > 0.2 else "medium" if abs(weight_change) > 0.05 else "low"
        }
    
    def reset_to_defaults(self):
        """Reset all weights to defaults"""
        self.weights.clear()
        self._init_default_weights()

class ConstraintProfile:
    """Represents a complete constraint configuration for specific scenarios"""
    
    def __init__(self, name: str, weighting_system: SoftConstraintWeightingSystem):
        self.name = name
        self.weighting_system = weighting_system
        self.created_at = None
        self.description = ""
        self.weights_snapshot = {}
    
    def save_profile(self):
        """Save current weights as profile"""
        self.weights_snapshot = {
            name: constraint.weight
            for name, constraint in self.weighting_system.weights.items()
        }
        self.created_at = str(__import__('datetime').datetime.now())
    
    def load_profile(self):
        """Load profile weights"""
        for constraint_name, weight in self.weights_snapshot.items():
            self.weighting_system.set_weight(constraint_name, weight)
    
    def get_profile_summary(self) -> Dict:
        """Get profile summary"""
        return {
            "name": self.name,
            "description": self.description,
            "created": self.created_at,
            "weights": self.weights_snapshot
        }
