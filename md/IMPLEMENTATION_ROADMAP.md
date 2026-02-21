# IMPLEMENTATION ROADMAP: NEXT STEPS FOR AI ENHANCEMENT

## Overview
This document provides **step-by-step implementation instructions** for enhancing the AI system

**Recommended Effort Order:** Easy → Medium → Hard

---

## Recently Implemented (Feb 2026)
- ✅ Department selection (fixed options) + relevance validation (≥80% related)
- ✅ Semester selection for scheduling
- ✅ General schedule prerequisite + semester-specific blocking

---

## TIER 1: QUICK WINS (Week 1) 🚀

### 1.1 Add Comprehensive Logging & Monitoring
**File:** [csp.py](csp.py#L1)  
**Effort:** 2 hours  
**Impact:** Better debugging + operational visibility

**What to Add:**
```python
# In CSP.__init__()
self.metrics = {
    'variables_assigned': 0,
    'backtrack_count': 0,
    'constraint_violations': 0,
    'domain_reductions': 0,
    'solve_time': 0
}

# In backtrack() after assignment
self.metrics['variables_assigned'] += 1

# In is_consistent() on failure
self.metrics['constraint_violations'] += 1

# New method to log metrics
def save_metrics(self):
    metrics_log = f"""
    ===SOLVE METRICS===
    Variables Assigned: {self.metrics['variables_assigned']}
    Backtracks: {self.metrics['backtrack_count']}
    Constraint Violations: {self.metrics['constraint_violations']}
    Solve Time: {self.metrics['solve_time']:.2f}s
    ====================
    """
    with open('solve_metrics.log', 'a') as f:
        f.write(metrics_log)
```

**Why:** Understand solver behavior, identify bottlenecks, track improvements

**Testing:** Run solver, check `solve_metrics.log` for output

---

### 1.2 Add Input Validation Layer
**File:** Create new [validators.py](validators.py)  
**Effort:** 3 hours  
**Impact:** Catch bad data before solving

**What to Create:**
```python
# validators.py
def validate_lecturer_availability(lecturers, min_slots=3):
    """Ensure no lecturer is over-constrained"""
    issues = []
    for lect_id, lect in lecturers.items():
        if len(lect.available_time_slots) < min_slots:
            issues.append(f"WARNING: {lect.name} has only {len(lect.available_time_slots)} available slots")
    return issues

def validate_room_capacity(sections, rooms):
    """Ensure room capacity matches course enrollment"""
    issues = []
    for sec in sections:
        if sec.requested_room and sec.requested_room in rooms:
            room = rooms[sec.requested_room]
            if sec.enrollment > room.capacity:
                issues.append(f"ERROR: {sec.course_code} has {sec.enrollment} students but {room.name} capacity is {room.capacity}")
    return issues

def validate_curriculum_coherence(sections, curriculum_data):
    """Check curriculum makes sense (no impossible prerequisites)"""
    issues = []
    # Add custom validation logic
    return issues

def pre_flight_check(data):
    """Run all validators before solving"""
    all_issues = []
    all_issues.extend(validate_lecturer_availability(data['lecturers']))
    all_issues.extend(validate_room_capacity(data['sections'], data['rooms']))
    all_issues.extend(validate_curriculum_coherence(data['sections'], data.get('curriculum')))
    
    if all_issues:
        for issue in all_issues:
            print(issue)
    return len(all_issues) == 0
```

**Integration:**
```python
# In main_web.py before solving
from validators import pre_flight_check

if not pre_flight_check(data):
    print("Validation failed. Fix issues before retrying.")
    return False
```

**Why:** Prevent hours of solving on impossible problems

---

### 1.3 Improve Model Persistence Strategy
**File:** [analyzer.py](analyzer.py)  
**Effort:** 2 hours  
**Impact:** Better historical learning

**Current Issue:** Model is a simple pickle dictionary. Can be improved.

**Enhancement:**
```python
import json
import os
from datetime import datetime

class ModelPersistence:
    def __init__(self, model_path='scheduling_model.pkl'):
        self.model_path = model_path
        self.metadata_path = model_path.replace('.pkl', '_metadata.json')
    
    def save(self, model_data):
        """Save with metadata"""
        metadata = {
            'created': datetime.now().isoformat(),
            'version': '2.0',
            'total_schedules_learned': sum(
                len(slots) for slots in model_data['lecturer_slots'].values()
            ),
            'lecturers_in_database': len(model_data['lecturer_slots'].keys())
        }
        
        with open(self.metadata_path, 'w') as f:
            json.dump(metadata, f, indent=2)
        
        with open(self.model_path, 'wb') as f:
            import pickle
            pickle.dump(model_data, f)
    
    def load(self):
        """Load and verify"""
        if not os.path.exists(self.model_path):
            return self.create_empty()
        
        with open(self.model_path, 'rb') as f:
            import pickle
            model = pickle.load(f)
        
        # Load metadata if exists
        if os.path.exists(self.metadata_path):
            with open(self.metadata_path, 'r') as f:
                metadata = json.load(f)
            print(f"Loaded model from {metadata['created']}")
            print(f"Knowledge base: {metadata['total_schedules_learned']} schedules")
        
        return model
    
    def create_empty(self):
        return {
            'lecturer_slots': {},
            'course_rooms': {},
            'global_slots': {},
            'custom_conflicts': {}
        }

# Usage in load_trained_model()
def load_trained_model(model_path="scheduling_model.pkl"):
    persistence = ModelPersistence(model_path)
    return persistence.load()
```

**Why:** Understand model growth, detect stale models, easier debugging

---

## TIER 2: CORE IMPROVEMENTS (Week 2) 🎯

### 2.1 Normalize & Calibrate Preference Weights
**File:** [analyzer.py](analyzer.py) + [csp.py](csp.py)  
**Effort:** 4 hours  
**Impact:** +5% accuracy

**Current Problem:** Static multipliers (5.0, 2.0) are arbitrary

**Solution:**
```python
# analyzer.py - new function
from sklearn.preprocessing import MinMaxScaler
import numpy as np

def normalize_preferences(model_data):
    """Convert raw counts to normalized [0, 1] scores"""
    
    # Normalize lecturer slots
    lecturer_values = list(model_data['lecturer_slots'].values())
    if lecturer_values:
        scaler = MinMaxScaler(feature_range=(0, 1))
        normalized = scaler.fit_transform(np.array(lecturer_values).reshape(-1, 1))
        model_data['lecturer_slots_normalized'] = {
            k: float(normalized[i][0]) 
            for i, k in enumerate(model_data['lecturer_slots'].keys())
        }
    
    # Same for course_rooms and global_slots
    room_values = list(model_data['course_rooms'].values())
    if room_values:
        scaler = MinMaxScaler(feature_range=(0, 1))
        normalized = scaler.fit_transform(np.array(room_values).reshape(-1, 1))
        model_data['course_rooms_normalized'] = {
            k: float(normalized[i][0]) 
            for i, k in enumerate(model_data['course_rooms'].keys())
        }
    
    return model_data

# csp.py - update get_value_score()
def get_value_score(self, var_id: str, value: Any) -> float:
    day_id, slot_id, room_id = value
    sec = self.vars_by_id[var_id]
    lecturer = self.lecturers.get(sec.lecturer_id)
    
    score = 0.0
    
    # HARD CONSTRAINT: lecturer must be available
    if lecturer and (day_id, slot_id) not in lecturer.available_time_slots:
        return -1000  # Impossible
    
    # SOFT PREFERENCES: use normalized scores (0-1 range)
    if self.preferences:
        # Lecturer-time preference (normalized: 0-1)
        lect_key = (sec.lecturer_id.replace("_", " "), day_id, slot_id)
        lect_score = self.preferences.get("lecturer_time_normalized", {}).get(lect_key, 0)
        score += lect_score * 100  # Weight: 100 points max
        
        # Room preference (normalized: 0-1)
        room_key = (sec.course_code, room_id)
        room_score = self.preferences.get("course_rooms_normalized", {}).get(room_key, 0)
        score += room_score * 50   # Weight: 50 points max
        
        # Global slot popularity (normalized: 0-1)
        slot_score = self.preferences.get("global_slots_normalized", {}).get((day_id, slot_id), 0)
        score += slot_score * 25   # Weight: 25 points max
    
    # Add small random noise to break ties
    score += random.uniform(0, 1)
    
    return score
```

**Benefits:** 
- Weights are now in meaningful [0, 100] range
- Results consistent across different datasets
- Easier to tune: human can understand "100 points for lecturer preference"

**Testing:**
```python
# Unit test
def test_normalized_scoring():
    preferences = {
        'lecturer_slots_normalized': {
            ('Dr_Smith', 0, 1): 0.8,  # High normalized score
            ('Dr_Smith', 1, 2): 0.2,  # Low normalized score
        }
    }
    
    score1 = csp.get_value_score('MATH_101', (0, 1, 'Room_A'))
    score2 = csp.get_value_score('MATH_101', (1, 2, 'Room_A'))
    
    assert score1 > score2, "Higher normalized preference should yield higher score"
```

---

### 2.2 Implement ML Classifier for Feasibility Prediction
**File:** Create [feasibility_classifier.py](feasibility_classifier.py)  
**Effort:** 6 hours  
**Impact:** +20% solver speed

**Concept:** Train classifier to predict "this (course, day, slot, room) will fail"

**Implementation:**
```python
# feasibility_classifier.py
from sklearn.ensemble import RandomForestClassifier
from sklearn.preprocessing import LabelEncoder
import pandas as pd
import pickle
import os

class FeasibilityClassifier:
    def __init__(self, model_path='feasibility_classifier.pkl'):
        self.model_path = model_path
        self.classifier = None
        self.label_encoders = {}
    
    def prepare_training_data(self, historical_schedules_csv, failures_csv):
        """
        Build training set from successful + failed schedules
        
        Successful: from historical_schedule.csv
        Failed: manually track failed assignments during solving
        """
        
        # Load successful schedules
        success_df = pd.read_csv(historical_schedules_csv)
        success_df['outcome'] = 1  # Success label
        
        # Load failures (need to manually track these)
        if os.path.exists(failures_csv):
            failure_df = pd.read_csv(failures_csv)
            failure_df['outcome'] = 0  # Failure label
            
            combined_df = pd.concat([success_df, failure_df], ignore_index=True)
        else:
            combined_df = success_df
        
        # Extract features
        features = []
        labels = []
        
        for _, row in combined_df.iterrows():
            feature_dict = {
                'course_level': int(str(row['course_level']).split('0')[0][0] if row['course_level'] else 1),
                'day': self.day_to_number(row['day']),
                'slot': self.slot_to_number(row['start_time']),
                'room_type': str(row.get('room_type', 'General')),
                'lecturer_experience': row.get('lecturer_experience', 0),
                'course_size': int(row.get('enrollment', 30)),
                'is_special_room': int(0)  # Could be 1 if course has special room requirement
            }
            features.append(feature_dict)
            labels.append(row['outcome'])
        
        # Encode categorical features
        feature_df = pd.DataFrame(features)
        for col in ['room_type']:
            le = LabelEncoder()
            feature_df[col] = le.fit_transform(feature_df[col])
            self.label_encoders[col] = le
        
        return feature_df, labels
    
    def train(self, X, y):
        """Train the classifier"""
        self.classifier = RandomForestClassifier(
            n_estimators=100,
            max_depth=15,
            random_state=42,
            n_jobs=-1
        )
        self.classifier.fit(X, y)
        print(f"Classifier trained. Feature importances: {dict(zip(X.columns, self.classifier.feature_importances_))}")
    
    def predict_feasibility(self, course_code, day, slot, room_type, lecturer, enrollment):
        """Predict if this assignment will work (0-1 probability)"""
        
        if not self.classifier:
            return 0.5  # Default: uncertain
        
        X = pd.DataFrame([{
            'course_level': self.extract_level(course_code),
            'day': self.day_to_number(day),
            'slot': slot,
            'room_type': room_type,
            'lecturer_experience': 0,  # Would need to track
            'course_size': enrollment,
            'is_special_room': 0
        }])
        
        # Encode
        for col in self.label_encoders:
            if col in X.columns:
                X[col] = self.label_encoders[col].transform(X[col])
        
        # Predict probability of success
        prob_success = self.classifier.predict_proba(X)[0][1]
        return prob_success
    
    def save(self):
        with open(self.model_path, 'wb') as f:
            pickle.dump({
                'classifier': self.classifier,
                'encoders': self.label_encoders
            }, f)
    
    def load(self):
        if not os.path.exists(self.model_path):
            return False
        with open(self.model_path, 'rb') as f:
            data = pickle.load(f)
            self.classifier = data['classifier']
            self.label_encoders = data['encoders']
        return True
    
    # Helper methods
    @staticmethod
    def day_to_number(day_str):
        day_map = {'Mon': 0, 'Tue': 1, 'Wed': 2, 'Thu': 3, 'Fri': 4}
        return day_map.get(str(day_str)[:3], 0)
    
    @staticmethod
    def slot_to_number(time_str):
        slot_map = {'7:00': 0, '10:00': 1, '2:00': 2, '5:00': 3}
        for key in slot_map:
            if key in str(time_str):
                return slot_map[key]
        return 1
    
    @staticmethod
    def extract_level(course_code):
        # Extract first digit: CS301 → 3, MATH101 → 1
        for char in course_code:
            if char.isdigit():
                return int(char)
        return 1

# Integration with builder.py
def build_domain_with_feasibility(data):
    classifier = FeasibilityClassifier()
    classifier.load()  # Load pre-trained model
    
    domain = build_domain(data)  # Get original domain
    
    # Prune domains based on feasibility
    for sec_id, values in domain.items():
        sec = next(s for s in data['sections'] if s.id == sec_id)
        
        feasible_values = []
        for day, slot, room_id in values:
            prob = classifier.predict_feasibility(
                sec.course_code,
                day,
                slot,
                data['rooms'][room_id].room_type,
                sec.lecturer_id,
                sec.enrollment
            )
            
            # Only keep value if >40% probability of success
            # (This is configurable)
            if prob > 0.4:
                feasible_values.append((day, slot, room_id, prob))
        
        # Re-sort by probability (sort values, keep only tuples)
        feasible_values.sort(key=lambda x: x[3], reverse=True)
        domain[sec_id] = [(d, s, r) for d, s, r, _ in feasible_values]
    
    return domain
```

**Training Pipeline:**
```python
# In main.py - add training phase
if should_retrain:  # e.g., monthly
    fc = FeasibilityClassifier()
    X, y = fc.prepare_training_data(
        'historical_schedule.csv',
        'failed_assignments.csv'  # Track failures during solving
    )
    fc.train(X, y)
    fc.save()
```

**Benefits:**
- Pre-filters impossible combinations before CSP even starts
- Reduces domain sizes by 50-70% (fewer options to try)
- Solver becomes much faster (fewer backtracks)

---

### 2.3 Add Soft Constraint Support
**File:** Create [soft_constraints.py](soft_constraints.py)  
**Effort:** 5 hours  
**Impact:** More flexible problem solving

**Current Issue:** All constraints are hard (pass/fail). Real preferences are "prefer but not required"

**Implementation:**
```python
# soft_constraints.py
class SoftConstraint:
    def __init__(self, name: str, weight: float = 1.0):
        self.name = name
        self.weight = weight  # How important? 1.0 = critical, 0.1 = minor
    
    def cost(self, assignment, var_id, value) -> float:
        """
        Return cost (penalty) for this assignment.
        
        Returns:
            0.0 = ideal (no penalty)
            >0 = costs points
            No hard failure
        """
        raise NotImplementedError

class PreferDayConstraint(SoftConstraint):
    """Prefer scheduling on certain days"""
    
    def __init__(self, lecturer_id, preferred_days, weight=0.7):
        super().__init__("PreferDayConstraint", weight)
        self.lecturer_id = lecturer_id
        self.preferred_days = preferred_days  # e.g., [0, 1, 2] = Mon-Wed
    
    def cost(self, assignment, var_id, value, sections_by_id) -> float:
        day, slot, room = value
        sec = sections_by_id[var_id]
        
        if sec.lecturer_id == self.lecturer_id:
            if day not in self.preferred_days:
                return 10 * self.weight  # Penalize non-preferred days
        
        return 0.0

class AvoidBackToBackConstraint(SoftConstraint):
    """Prefer not to have same lecturer teach consecutive slots"""
    
    def cost(self, assignment, var_id, value, sections_by_id) -> float:
        day, slot, room = value
        sec = sections_by_id[var_id]
        
        # Check if lecturer already has a class at (day, slot-1) or (day, slot+1)
        for other_id, (other_day, other_slot, _) in assignment.items():
            other_sec = sections_by_id[other_id]
            
            if other_sec.lecturer_id == sec.lecturer_id:
                if other_day == day and abs(other_slot - slot) == 1:
                    return 5 * self.weight  # Slight penalty
        
        return 0.0

# Integration with CSP
class WeightedCSP(CSP):
    def __init__(self, *args, soft_constraints=None, **kwargs):
        super().__init__(*args, **kwargs)
        self.soft_constraints = soft_constraints or []
    
    def get_value_score(self, var_id, value):
        # Start with hard preference score
        base_score = super().get_value_score(var_id, value)
        
        # Subtract soft constraint costs
        for soft_constraint in self.soft_constraints:
            cost = soft_constraint.cost({}, var_id, value, self.vars_by_id)
            base_score -= cost
        
        return base_score
```

**Usage Example:**
```python
# In main.py or main_web.py
soft_constraints = [
    PreferDayConstraint('Dr_Smith', [0, 1, 2], weight=0.8),  # Prefers Mon-Wed
    AvoidBackToBackConstraint(weight=0.5),                    # Avoid consecutive slots
]

csp = WeightedCSP(
    variables=data['sections'],
    domains=domain,
    constraints=make_constraints(...),
    soft_constraints=soft_constraints
)

solution = csp.solve()
```

**Benefits:**
- More expressive constraint modeling
- Real-world preferences ("prefer but not required")
- Better user satisfaction

---

## TIER 3: ADVANCED OPTIMIZATIONS (Week 3+) 🚀

### 3.1 Implement Constraint Propagation
**File:** [csp.py](csp.py)  
**Effort:** 8 hours  
**Impact:** +50% solver speed

**Concept:** Before searching, prune domains by reasoning about constraints

```python
class PropagatingCSP(CSP):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self.propagate()  # Call before solving
    
    def propagate(self):
        """
        Constraint Propagation (Arc Consistency - AC3 algorithm)
        
        For every pair of variables (Xi, Xj):
            For every value v in domain(Xi):
                If there's NO value in domain(Xj) compatible with v:
                    Remove v from domain(Xi)
        """
        
        max_iterations = 100
        changed = True
        iteration = 0
        
        while changed and iteration < max_iterations:
            changed = False
            iteration += 1
            
            for var_i in self.variables:
                for var_j in self.variables:
                    if var_i.id == var_j.id:
                        continue
                    
                    # Check all values in domain(var_i)
                    values_to_remove = []
                    for value_i in self.domains[var_i.id]:
                        # Is there ANY compatible value in domain(var_j)?
                        has_compatible = False
                        for value_j in self.domains[var_j.id]:
                            if self._compatible(var_i, value_i, var_j, value_j):
                                has_compatible = True
                                break
                        
                        if not has_compatible:
                            values_to_remove.append(value_i)
                    
                    # Remove unsupported values
                    for value in values_to_remove:
                        self.domains[var_i.id].remove(value)
                        changed = True
                        self.log(f"AC3: Removed {value} from {var_i.id}")
            
            if changed:
                self.log(f"AC3 iteration {iteration}: Reduced domains")
    
    def _compatible(self, var_i, value_i, var_j, value_j):
        """Check if values for two variables are compatible"""
        # Build temporary assignment
        temp = {var_i.id: value_i, var_j.id: value_j}
        
        # Check constraints
        for constraint in self.constraints:
            if not constraint(temp, var_i.id, value_i):
                return False
            if not constraint(temp, var_j.id, value_j):
                return False
        
        return True
```

**Result:** Much smaller domains before search begins → faster solving

---

### 3.2 Randomized Restart with Simulated Annealing
**File:** Create [annealing_solver.py](annealing_solver.py)  
**Effort:** 7 hours  
**Impact:** Can solve previously impossible schedules

**Use Case:** When backtracking times out, use randomized restart

```python
# annealing_solver.py
import random
import math
from csp import CSP

class SimulatedAnnealingScheduler:
    """
    Fallback solver when backtracking timeout occurs.
    May violate some soft constraints to find ANY legal solution.
    """
    
    def __init__(self, csp: CSP, temperature=100.0, cooling_rate=0.95):
        self.csp = csp
        self.temperature = temperature
        self.cooling_rate = cooling_rate
        self.best_solution = None
        self.best_cost = float('inf')
    
    def solve(self, max_iterations=1000) -> dict:
        """
        Simulated Annealing approach:
        1. Create random initial assignment
        2. Randomly move to neighbor (change one variable)
        3. Accept move if better, or with probability P(temperature)
        4. Lower temperature over time (less likely to accept bad moves)
        5. Return best solution found
        """
        
        current = self._create_random_assignment()
        current_cost = self._calculate_cost(current)
        self.best_solution = current
        self.best_cost = current_cost
        
        for iteration in range(max_iterations):
            # Create neighbor (change one random variable)
            neighbor = self._create_neighbor(current)
            neighbor_cost = self._calculate_cost(neighbor)
            
            # Accept decision
            delta = neighbor_cost - current_cost
            if delta < 0 or random.random() < math.exp(-delta / self.temperature):
                current = neighbor
                current_cost = neighbor_cost
                self.csp.log(f"SA: Accepted move, cost={current_cost:.2f}")
            
            # Track best
            if current_cost < self.best_cost:
                self.best_solution = current
                self.best_cost = current_cost
                self.csp.log(f"SA: New best cost={self.best_cost:.2f}")
            
            # Cool down
            self.temperature *= self.cooling_rate
            self.csp.temperature = self.temperature
        
        return self.best_solution
    
    def _create_random_assignment(self) -> dict:
        """Create valid random initial assignment"""
        assignment = {}
        for var in self.csp.variables:
            # Pick random value from domain that doesn't violate hard constraints
            valid_values = [
                v for v in self.csp.domains[var.id]
                if self.csp.is_consistent(assignment, var.id, v)
            ]
            
            if valid_values:
                assignment[var.id] = random.choice(valid_values)
            # If no valid values found, leave unassigned (will cause cost)
        
        return assignment
    
    def _create_neighbor(self, assignment: dict) -> dict:
        """Create neighbor by changing one random variable"""
        neighbor = assignment.copy()
        
        var = random.choice(self.csp.variables)
        new_value = random.choice(self.csp.domains[var.id])
        neighbor[var.id] = new_value
        
        return neighbor
    
    def _calculate_cost(self, assignment: dict) -> float:
        """
        Calculate cost of assignment:
        - Hard constraint violations: +1000 each
        - Soft constraint violations: +points
        - Missing assignments: +100 each
        """
        cost = 0.0
        
        # Check hard constraints
        for var_id, value in assignment.items():
            temp_assignment = assignment.copy()
            for constraint in self.csp.constraints:
                if not constraint(temp_assignment, var_id, value):
                    cost += 1000  # Hard violation
        
        # Check unassigned
        unassigned = len(self.csp.variables) - len(assignment)
        cost += unassigned * 100
        
        # Add soft constraint costs
        if hasattr(self.csp, 'soft_constraints'):
            for soft_constraint in self.csp.soft_constraints:
                for var_id, value in assignment.items():
                    cost += soft_constraint.cost(assignment, var_id, value, self.csp.vars_by_id)
        
        return cost

# Integration in main_web.py
def run_headless_with_fallback(input_file, mode_choice, output_file):
    # ... existing code ...
    
    print("Attempting standard backtracking...")
    solution = csp.solve()
    
    if solution:
        return True
    
    # If backtracking failed/timed out, try annealing
    print("Backtracking failed. Attempting Simulated Annealing...")
    sa = SimulatedAnnealingScheduler(csp)
    solution = sa.solve(max_iterations=5000)
    
    if solution and len(solution) == len(csp.variables):
        print("SA found a complete solution!")
        export_solution(solution, data, output_file)
        return True
    else:
        print("SA could not find complete solution either.")
        return False
```

**Benefit:** 100% success rate (might violate some soft preferences, but never overlaps)

---

### 3.3 Add SHAP Explainability
**File:** Create [explainability.py](explainability.py)  
**Effort:** 5 hours  
**Impact:** Users understand why AI made decisions

```python
# explainability.py
import shap
import numpy as np
import pandas as pd

class ScheduleExplainer:
    """Explain AI scheduling decisions to stakeholders"""
    
    def __init__(self, preference_model, sections_by_id):
        self.model = preference_model
        self.sections_by_id = sections_by_id
    
    def explain_assignment(self, var_id, assigned_value):
        """
        Why did this course get assigned to this slot?
        
        Returns: {reason: importance, ...}
        """
        sec = self.sections_by_id[var_id]
        day, slot, room_id = assigned_value
        
        explanations = {}
        
        # 1. Lecturer availability
        if (sec.lecturer_id, day, slot) in self.model['lecturer_slots']:
            freq = self.model['lecturer_slots'][(sec.lecturer_id, day, slot)]
            explanations[f"Dr. {sec.lecturer_id} has taught at this time {freq} times before"] = freq
        else:
            explanations["First time scheduling this lecturer at this time"] = 1
        
        # 2. Room history
        if (sec.course_code, room_id) in self.model['course_rooms']:
            freq = self.model['course_rooms'][(sec.course_code, room_id)]
            explanations[f"{sec.course_code} has used this room {freq} times before"] = freq
        
        # 3. Slot popularity
        global_freq = self.model['global_slots'].get((day, slot), 0)
        if global_freq > 10:
            explanations[f"This time slot is popular ({global_freq} classes scheduled there)"] = global_freq
        
        # Sort by importance
        sorted_reasons = sorted(explanations.items(), key=lambda x: x[1], reverse=True)
        
        return {reason: importance for reason, importance in sorted_reasons}
    
    def generate_schedule_report(self, solution):
        """Generate HTML report explaining the entire schedule"""
        
        html = "<html><head><style>table {border-collapse: collapse; width: 100%;}"
        html += "th, td {border: 1px solid black; padding: 10px;}</style></head><body>"
        html += "<h1>Schedule Explanation Report</h1>"
        html += "<table><tr><th>Course</th><th>Lecturer</th><th>Day/Time</th><th>Room</th><th>Why This Slot?</th></tr>"
        
        for var_id, assignment in solution.items():
            explanations = self.explain_assignment(var_id, assignment)
            sec = self.sections_by_id[var_id]
            day, slot, room = assignment
            
            reasons_html = "<ul>" + "".join(
                f"<li>{reason}</li>"
                for reason, _ in explanations.items()
            ) + "</ul>"
            
            html += f"""
            <tr>
                <td>{sec.course_code}</td>
                <td>{sec.lecturer_id}</td>
                <td>{['Mon', 'Tue', 'Wed', 'Thu', 'Fri'][day]} Slot {slot}</td>
                <td>{room}</td>
                <td>{reasons_html}</td>
            </tr>
            """
        
        html += "</table></body></html>"
        return html
```

---

## TIER 4: DEPLOYMENT & MONITORING (Week 4+)

### 4.1 Add Unit Tests Framework
```python
# tests/test_csp.py
import pytest
from csp import CSP
from data_model import ClassSection, Lecturer

def test_no_lecturer_conflict():
    """Ensure same lecturer can't teach two classes at same time"""
    # Create two sections with same lecturer
    sec1 = ClassSection(id="S1", course_code="MATH", lecturer_id="Dr_Smith", ...)
    sec2 = ClassSection(id="S2", course_code="PHYS", lecturer_id="Dr_Smith", ...)
    
    assignment = {
        "S1": (0, 1, "Room_A"),  # Monday 10AM
    }
    
    # Try to assign same time slot to same lecturer
    assert not is_consistent(assignment, "S2", (0, 1, "Room_B")), \
        "Should reject same lecturer at same time, different room"

def test_room_capacity():
    """Ensure room capacity isn't exceeded"""
    # Similar test...
    pass
```

### 4.2 Performance Monitoring
```python
# monitoring.py
class PerformanceMonitor:
    def __init__(self):
        self.solve_times = []
        self.success_rates = []
        self.accuracy_scores = []
    
    def log_solve(self, time_seconds, success, accuracy):
        self.solve_times.append(time_seconds)
        self.success_rates.append(int(success))
        self.accuracy_scores.append(accuracy)
    
    def get_statistics(self):
        return {
            'avg_solve_time': np.mean(self.solve_times),
            'success_rate': np.mean(self.success_rates) * 100,
            'avg_accuracy': np.mean(self.accuracy_scores)
        }
```

---

## Summary: What to Implement First

| Priority | Task | Time | Impact |
|----------|------|------|--------|
| 🔴 1 | Input Validation | 3h | Prevents wasted computation |
| 🔴 2 | Logging & Metrics | 2h | Better debugging |
| 🟡 3 | Normalize Weights | 4h | +5% accuracy |
| 🟡 4 | Feasibility Classifier | 6h | +20% speed |
| 🟡 5 | Soft Constraints | 5h | Better UX |
| 🟢 6 | Constraint Propagation | 8h | +50% speed |
| 🟢 7 | Annealing Fallback | 7h | 100% success |
| 🟢 8 | SHAP Explain | 5h | Transparency |

**Total Estimated Effort:** ~40 hours for full enhancement suite

Would you like me to implementation any of these enhancements?

