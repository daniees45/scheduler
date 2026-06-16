"""
Neural Network Scheduler - Production Implementation
Deep learning based timetable scheduling with advanced architectures
"""

import numpy as np
import json
import os
import random
import pickle
from typing import Dict, List, Tuple, Optional, Any
from dataclasses import dataclass
from datetime import datetime

os.environ["TF_USE_LEGACY_KERAS"] = "1"

try:
    import tensorflow as tf
    from tf_keras import layers, models, optimizers, callbacks
    HAS_TENSORFLOW = True
except ImportError:
    HAS_TENSORFLOW = False
    try:
        from sklearn.neural_network import MLPRegressor
        from sklearn.preprocessing import StandardScaler
        HAS_SKLEARN = True
    except ImportError:
        HAS_SKLEARN = False
    
    if not HAS_TENSORFLOW:
        print("Warning: TensorFlow not installed. Falling back to Scikit-Learn for Neural Network Scheduler.")


@dataclass
class ScheduleFeatures:
    """Feature representation for neural network"""
    course_embedding: np.ndarray
    lecturer_embedding: np.ndarray
    room_embedding: np.ndarray
    slot_embedding: np.ndarray
    context_features: np.ndarray  # Utilization, balance, etc.


class NeuralNetworkScheduler:
    """
    Neural Network based scheduler using deep learning
    """
    
    def __init__(self,
                 courses: List[Dict[str, Any]],
                 lecturers: List[str],
                 rooms: List[Dict[str, Any]],
                 time_slots: List[str],
                 days: Optional[List[str]] = None,
                 special_room_constraints: Optional[Dict[str, Dict[str, str]]] = None,
                 enforce_lecturer_assignment: bool = True,
                 embedding_dim: int = 32,
                 hidden_dim: int = 128,
                 architecture: str = 'attention',
                 learning_rate: float = 0.001,
                 batch_size: int = 32,
                 epochs: int = 100,
                 existing_schedule: List[Any] = None,
                 existing_course_lookup: Dict[str, Any] = None,
                 course_groups: Optional[Dict[str, List[str]]] = None,
                 lecturer_availability: Optional[Dict[str, Dict[str, bool]]] = None,
                 shared_course_aliases: Optional[Dict[str, str]] = None,
                 verbose: bool = True,
                 strict_departmental: bool = True,
                 reserved_rooms: Optional[set] = None):
        
        if not HAS_TENSORFLOW and not HAS_SKLEARN:
            raise ImportError("Neural Network Scheduler requires either TensorFlow or Scikit-Learn.")
        
        self.courses = courses
        self.lecturers = lecturers
        self.rooms = rooms
        self.time_slots = time_slots
        self.days = days or ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday"]
        self.enforce_lecturer_assignment = enforce_lecturer_assignment
        self.shared_course_aliases = {
            " ".join(str(k).strip().upper().split()): " ".join(str(v).strip().upper().split())
            for k, v in (shared_course_aliases or {}).items()
            if str(k).strip() and str(v).strip()
        }
        self.course_groups = {
            self._normalize_course_code(k): [self._normalize_course_code(x) for x in (v or [])]
            for k, v in (course_groups or {}).items()
            if self._normalize_course_code(k)
        }
        self.lecturer_availability = lecturer_availability or {}
        
        # Filter special_room_constraints to active room pool only
        all_special_constraints = special_room_constraints or {}
        available_room_names_normalized = {
            " ".join(str(r.get('name', '')).strip().lower().split())
            for r in self.rooms
            if str(r.get('name', '')).strip()
        }
        self.special_room_constraints = {
            self._normalize_course_code(code): info
            for code, info in all_special_constraints.items()
            if isinstance(info, dict)
        }
        
        # Build normalized reserved rooms set
        if reserved_rooms:
            self.reserved_rooms = reserved_rooms
            self.reserved_rooms_normalized = {
                self._normalize_room_name(name)
                for name in self.reserved_rooms
                if self._normalize_room_name(name)
            }
        else:
            self.reserved_rooms = set()
            self.reserved_rooms_normalized = set()
        
        self.embedding_dim = embedding_dim
        self.hidden_dim = hidden_dim
        self.architecture = architecture
        self.learning_rate = learning_rate
        self.batch_size = batch_size
        self.epochs = epochs
        self.verbose = verbose
        self.strict_departmental = strict_departmental
        
        self.existing_schedule = existing_schedule or []
        self.existing_course_lookup = existing_course_lookup or {}
        
        self.num_courses = len(courses)
        self.num_lecturers = len(lecturers)
        self.num_rooms = len(rooms)
        self.num_days = len(self.days)
        self.num_slots_per_day = len(time_slots) if time_slots else 0
        self.num_slots = self.num_days * self.num_slots_per_day
        self.num_context_features = 13
        
        self.is_sklearn_trained = False
        self.scaler = None
        self.model = self._build_model()
        
        # Track model compatibility
        self.model_num_courses = self.num_courses
        self.model_num_lecturers = self.num_lecturers
        self.model_num_rooms = self.num_rooms
        self.model_num_slots = self.num_slots
        self.skip_autotraining = False  # Flag set when incompatible model detected

        self.training_history = {'loss': [], 'accuracy': [], 'epoch': 0}
        self.best_schedule = []
        self.best_score = 0

    def _normalize_course_code(self, course_code: str) -> str:
        if not course_code: return ""
        code = course_code.split(" [Sec")[0].strip()
        code = " ".join(code.upper().split())
        return self.shared_course_aliases.get(code, code)

    def _get_shared_block_keys(self, course: Dict[str, Any]) -> List[str]:
        if not isinstance(course, dict):
            return []
        return [str(k) for k in (course.get('_shared_block_keys', []) or []) if str(k).strip()]
    
    def _normalize_room_name(self, room_name: str) -> str:
        """Normalize room names for consistent comparison"""
        if not room_name:
            return ""
        return " ".join(str(room_name).strip().lower().split())
    
    def _room_matches(self, room_a: str, room_b: str) -> bool:
        """Check if two room names match using normalized comparison"""
        return self._normalize_room_name(room_a) == self._normalize_room_name(room_b)
    
    def _slot_matches_fixed_time(self, slot_value: str, fixed_time_value: str) -> bool:
        """Check if slot start time matches fixed time using normalized comparison"""
        if not fixed_time_value:
            return True
        slot_start = str(slot_value or "").split("-")[0].strip()
        return self._normalize_time(slot_start) == self._normalize_time(fixed_time_value)

    def _get_special_constraint(self, course_code: str) -> Dict[str, str]:
        norm_code = self._normalize_course_code(course_code)
        return self.special_room_constraints.get(norm_code, {})

    def _get_course(self, course_code: str) -> Optional[Dict[str, Any]]:
        norm_code = self._normalize_course_code(course_code)
        for course in self.courses:
            if self._normalize_course_code(course.get('code', '')) == norm_code:
                return course
        return None

    def _normalize_time(self, time_str: str) -> str:
        if not time_str: return ""
        t = str(time_str).lower().strip().replace(" ", "")
        try:
            if ":" in t:
                parts = t.split(":")
                h = "".join(filter(str.isdigit, parts[0]))
                m = "".join(filter(str.isdigit, parts[1]))
                suffix = "pm" if "pm" in t else "am"
                if h and m:
                    return f"{int(h):02d}:{int(m):02d} {suffix.upper()}"
            return t
        except:
            return t

    def _is_credit_hour_restricted(self, course: Dict[str, Any], slot: str) -> bool:
        special = self._get_special_constraint(course.get('code', ''))
        if special: return False

        credits_raw = str(course.get('credits', '')).strip()
        if not credits_raw or credits_raw.upper() == "NC":
            return False

        try:
            credits = float(credits_raw)
        except (ValueError, TypeError):
            credits = 2.0

        if credits >= 3 and self.time_slots:
            norm_slot = self._normalize_time(slot.split(" - ")[0])
            norm_last = self._normalize_time(self.time_slots[-1].split(" - ")[0])
            return norm_slot == norm_last
        return False

    def _is_intentional_pairing(self, code1: str, code2: str) -> bool:
        if not code1 or not code2: return False
        c1, c2 = self._normalize_course_code(code1), self._normalize_course_code(code2)
        if c1 == c2: return True
        
        course1 = self._get_course(code1)
        course2 = self._get_course(code2)
        
        if course1 and course2:
            s1, s2 = course1.get('shared_group_id'), course2.get('shared_group_id')
            if s1 and s2 and s1 == s2: return True
            t1, t2 = course1.get('title', '').lower(), course2.get('title', '').lower()
            if t1 and t2 and (t1 in t2 or t2 in t1): return True
            
        if c1 in self.course_groups and c2 in self.course_groups[c1]: return True
        if c2 in self.course_groups and c1 in self.course_groups[c2]: return True
        return False

    def _constraint_violation_features(self, course: Dict[str, Any], lecturer: str, room: Dict[str, Any], day: str, slot: str) -> list:
        # [room_conflict, lecturer_conflict, credit, special, group, friday, capacity, level_semester]
        violations = [0] * 8
        if self._is_credit_hour_restricted(course, slot): violations[2] = 1
        special = self._get_special_constraint(course.get('code', ''))
        if special:
            room_name_special = special.get('room_name')
            if room_name_special and not self._room_matches(room['name'], room_name_special): violations[3] = 1
            if special.get('fixed_day') and day != special['fixed_day']: violations[3] = 1
            if special.get('fixed_time') and not self._slot_matches_fixed_time(slot, special['fixed_time']): violations[3] = 1
        elif self._normalize_room_name(room['name']) in self.reserved_rooms_normalized:
            violations[3] = 1
            
        if day.lower() == "friday" and (not self.time_slots or slot not in self.time_slots[:2]): violations[5] = 1
        if course.get('enrollment', 30) > room.get('capacity', 30): violations[6] = 1
        return violations

    def _assignment_valid(self, course: Dict[str, Any], lecturer: str, room: Dict[str, Any], day: str, slot: str,
                           room_occupancy: Dict, lecturer_occupied: Dict, level_semester_slots: Dict, group_slots: Dict) -> bool:
        course_code = course.get('code', '')
        fixed_lecturer = course.get('lecturer') if self.enforce_lecturer_assignment else None
        if fixed_lecturer and lecturer != fixed_lecturer: return False

        # Constraint 0b: Smart Locking and Special room constraints
        special = self._get_special_constraint(course_code)
        fixed_day = course.get('fixed_day')
        fixed_time = course.get('fixed_time')
        fixed_room = course.get('fixed_room')

        # Check Smart Locking (course-level)
        if fixed_day and day != fixed_day: return False
        if fixed_time:
            if self._normalize_time(fixed_time) != self._normalize_time(slot.split(" - ")[0]): return False
        if fixed_room and room['name'] != fixed_room: return False

        # Check special_rooms.csv constraints with normalized room matching
        if special:
            room_name_special = special.get('room_name')
            if room_name_special and not self._room_matches(room['name'], room_name_special): return False
            if special.get('fixed_day') and day != special['fixed_day']: return False
            if special.get('fixed_time'):
                if not self._slot_matches_fixed_time(slot, special['fixed_time']): return False
        elif not fixed_room and self._normalize_room_name(room['name']) in self.reserved_rooms_normalized:
            return False

        if self._is_credit_hour_restricted(course, slot): return False
        if day.lower() == "friday" and (not self.time_slots or slot not in self.time_slots[:2]): return False
        if room['capacity'] < course.get('enrollment', 30): return False

        if self.strict_departmental and not course.get('is_general', False):
            # Bypass if room is explicitly fixed/requested
            if room.get('name') == course.get('fixed_room'):
                pass
            else:
                special = self._get_special_constraint(course_code)
                if special and special.get('room_name') == room.get('name'):
                    pass
                else:
                    course_grp = course.get('departmental_group', course.get('department', "General"))
                    room_dept = room.get('department', "General")
                    
                    rd = str(room_dept).lower().replace("/", " ").replace("-", " ")
                    cg = str(course_grp).lower().replace("/", " ").replace("-", " ")
                    is_match = (rd == cg) or (rd in cg) or (cg in rd)
                    if not is_match:
                        return False

        slot_key = f"{day}_{slot}"
        for occupant in room_occupancy.get(room['name'], {}).get(slot_key, []):
            if not self._is_intentional_pairing(course_code, occupant): return False
        for occupant in lecturer_occupied.get(lecturer, {}).get(slot_key, []):
            if not self._is_intentional_pairing(course_code, occupant): return False
        if self.lecturer_availability.get(lecturer) and not self.lecturer_availability[lecturer].get(day, True): return False

        level_key = f"{course.get('level')}_{course.get('semester')}_{day}_{slot}"
        for occupant in level_semester_slots.get(level_key, []):
            if not self._is_intentional_pairing(course_code, occupant): return False
        for shared_key in self._get_shared_block_keys(course):
            shared_slot_key = f"shared::{shared_key}::{day}::{slot}"
            for occupant in level_semester_slots.get(shared_slot_key, []):
                if not self._is_intentional_pairing(course_code, occupant): return False

        shared_id = course.get('shared_group_id')
        if shared_id and shared_id in group_slots:
            if (day, slot) != group_slots[shared_id]: return False
        normalized_code = self._normalize_course_code(course_code)
        if normalized_code in self.course_groups:
            group_id = "_".join(sorted(self.course_groups[normalized_code]))
            if group_id in group_slots and (day, slot) != group_slots[group_id]: return False

        return True

    def _build_model(self) -> Any:
        if not HAS_TENSORFLOW:
            return MLPRegressor(hidden_layer_sizes=(self.hidden_dim, self.hidden_dim), max_iter=self.epochs) if HAS_SKLEARN else None
        
        course_in = layers.Input(shape=(1,), name='course')
        lecturer_in = layers.Input(shape=(1,), name='lecturer')
        room_in = layers.Input(shape=(1,), name='room')
        slot_in = layers.Input(shape=(1,), name='slot')
        context_in = layers.Input(shape=(self.num_context_features,), name='context')
        
        def embed(inp, n, name):
            e = layers.Embedding(n + 1, self.embedding_dim, name=name)(inp)
            return layers.Flatten()(e)
            
        c_e = embed(course_in, self.num_courses, 'course_emb')
        l_e = embed(lecturer_in, self.num_lecturers, 'lecturer_emb')
        r_e = embed(room_in, self.num_rooms, 'room_emb')
        s_e = embed(slot_in, self.num_slots, 'slot_emb')
        
        x = layers.Concatenate()([c_e, l_e, r_e, s_e, context_in])
        for _ in range(2):
            x = layers.Dense(self.hidden_dim, activation='relu')(x)
            x = layers.BatchNormalization()(x)
            x = layers.Dropout(0.3)(x)
        
        out = layers.Dense(1, activation='sigmoid')(x)
        return models.Model(inputs=[course_in, lecturer_in, room_in, slot_in, context_in], outputs=out)

    def _get_violation_feature_count(self) -> int:
        """Returns the number of violation features used in constraint checking."""
        return 8  # [room_conflict, lecturer_conflict, credit, special, group, friday, capacity, level_semester]
    
    def get_total_feature_count(self) -> int:
        """Returns the total number of features (context + violations)."""
        return 5 + self._get_violation_feature_count()  # 5 context features + 8 violation features = 13
    
    def extract_features(self, course_idx: int, lecturer_idx: int, room_idx: int, slot_idx: int, context: Dict) -> Tuple:
        ctx = np.array([context.get('room_utilization', 0), context.get('lecturer_load', 0),
                        context.get('conflict_count', 0)/100, context.get('capacity_match', 0.5),
                        context.get('diversity_score', 0.5)], dtype=np.float32)
        course = self.courses[course_idx] if 0 <= course_idx < len(self.courses) else {}
        lecturer = self.lecturers[lecturer_idx] if 0 <= lecturer_idx < len(self.lecturers) else ''
        room = self.rooms[room_idx] if 0 <= room_idx < len(self.rooms) else {}
        day = self.days[slot_idx // self.num_slots_per_day] if self.num_slots_per_day > 0 else ''
        slot = self.time_slots[slot_idx % self.num_slots_per_day] if self.num_slots_per_day > 0 else ''
        violations = np.array(self._constraint_violation_features(course, lecturer, room, day, slot), dtype=np.float32)
        return (np.array([course_idx]), np.array([lecturer_idx]), np.array([room_idx]), np.array([slot_idx]), np.concatenate([ctx, violations]))

    def train(self, training_data: Any, labels: np.ndarray) -> Dict:
        if not HAS_TENSORFLOW:
            X = np.concatenate([np.array(d).reshape(len(labels), -1) for d in training_data], axis=1) if isinstance(training_data, (list, tuple)) else training_data
            self.scaler = StandardScaler()
            X_scaled = self.scaler.fit_transform(X)
            self.model.fit(X_scaled, labels)
            self.is_sklearn_trained = True
            acc = self.model.score(X_scaled, labels)
            return {'final_loss': 1.0 - acc, 'final_accuracy': acc, 'epochs_trained': 1}
            
        # TensorFlow training - use fast training for small datasets
        num_samples = len(labels)
        use_validation = num_samples >= 100  # Only use validation split for larger datasets
        fast_epochs = min(20, self.epochs) if num_samples < 200 else self.epochs
        
        self.model.compile(optimizer=optimizers.Adam(self.learning_rate), loss='binary_crossentropy', metrics=['accuracy'])
        hist = self.model.fit(
            training_data, labels, 
            batch_size=min(self.batch_size, num_samples // 2),
            epochs=fast_epochs,
            verbose=0, 
            validation_split=0.1 if use_validation else 0
        )
        return {'final_loss': float(hist.history['loss'][-1]), 'final_accuracy': float(hist.history.get('accuracy', [0])[-1]), 'epochs_trained': fast_epochs}

    def predict_validity(self, course_idx: int, lecturer_idx: int, room_idx: int, slot_idx: int, context: Dict) -> Tuple[bool, float]:
        # Check if indices are within trained model's range BEFORE extracting features
        # This prevents TensorFlow Embedding errors for out-of-range indices
        if (course_idx >= self.model_num_courses or 
            lecturer_idx >= self.model_num_lecturers or 
            room_idx >= self.model_num_rooms or 
            slot_idx >= self.model_num_slots):
            # Fall back to heuristic for out-of-range indices
            course = self.courses[course_idx] if 0 <= course_idx < len(self.courses) else {}
            room = self.rooms[room_idx] if 0 <= room_idx < len(self.rooms) else {}
            
            capacity_match = 1.0 if room.get('capacity', 0) >= course.get('enrollment', 30) else 0.5
            utilization = context.get('room_utilization', 0.5)
            lecturer_load = context.get('lecturer_load', 0.5)
            balance_score = 1.0 - abs(utilization - 0.5) - abs(lecturer_load - 0.5)
            balance_score = max(0.0, min(1.0, balance_score))
            heuristic_conf = (capacity_match * 0.4 + balance_score * 0.6)
            return heuristic_conf > 0.5, float(heuristic_conf)
        
        # Now safe to extract features since indices are in range
        feats = self.extract_features(course_idx, lecturer_idx, room_idx, slot_idx, context)
        
        if not HAS_TENSORFLOW:
            if not self.is_sklearn_trained:
                # Use heuristic confidence instead of random when not trained
                course = self.courses[course_idx] if 0 <= course_idx < len(self.courses) else {}
                room = self.rooms[room_idx] if 0 <= room_idx < len(self.rooms) else {}
                
                # Calculate heuristic confidence based on:
                # 1. Room capacity match
                # 2. Utilization balance
                # 3. Context scores
                capacity_match = 1.0 if room.get('capacity', 0) >= course.get('enrollment', 30) else 0.5
                utilization = context.get('room_utilization', 0.5)
                lecturer_load = context.get('lecturer_load', 0.5)
                
                # Prefer balanced utilization (not too high, not too low)
                balance_score = 1.0 - abs(utilization - 0.5) - abs(lecturer_load - 0.5)
                balance_score = max(0.0, min(1.0, balance_score))
                
                # Combined heuristic confidence
                heuristic_conf = (capacity_match * 0.4 + balance_score * 0.6)
                return heuristic_conf > 0.5, float(heuristic_conf)
            X = np.concatenate([f.reshape(1, -1) for f in feats], axis=1)
            p = self.model.predict(self.scaler.transform(X))[0]
            return p > 0.5, float(np.clip(p, 0.0, 1.0))
        p = self.model.predict(tuple(np.expand_dims(f, 0) for f in feats), verbose=0)[0][0]
        return p > 0.5, float(np.clip(p, 0.0, 1.0))

    def schedule(self, max_attempts: int = 2000) -> Tuple[List[Dict], float]:
        schedule, quality = [], 0
        room_occ, lect_occ, lvl_occ, grp_slots = {r['name']: {} for r in self.rooms}, {l: {} for l in self.lecturers}, {}, {}
        
        # Seed
        for it in self.existing_schedule:
            sk = f"{it.day}_{it.time_slot}"
            room_occ.setdefault(it.room_name, {}).setdefault(sk, []).append(it.course_code)
            lect_occ.setdefault(it.lecturer, {}).setdefault(sk, []).append(it.course_code)
            existing_code = self._normalize_course_code(it.course_code.split(":")[0].strip())
            info = self.existing_course_lookup.get(existing_code)
            if info is None:
                info = self._get_course(existing_code)
            if info: lvl_occ.setdefault(f"{info.get('level')}_{info.get('semester')}_{sk}", []).append(it.course_code)
            if info:
                for shared_key in info.get('_shared_block_keys', []) or []:
                    lvl_occ.setdefault(f"shared::{shared_key}::{it.day}::{it.time_slot}", []).append(it.course_code)
            code = self._normalize_course_code(it.course_code.split(':')[0].strip())
            if code in self.course_groups: grp_slots["_".join(sorted(self.course_groups[code]))] = (it.day, it.time_slot)

        # Check if model is trained
        is_trained = getattr(self, 'is_sklearn_trained', False) or (HAS_TENSORFLOW and self.model is not None)
        
        if self.verbose:
            if is_trained and self.is_model_compatible():
                print(f"[NN] ✓ Using trained model for scheduling {len(self.courses)} courses...")
            elif is_trained and not self.is_model_compatible():
                print(f"[NN] ⚠ Model incompatible with current problem size - using heuristics")
                print(f"[NN]    Model trained for: {self.model_num_courses} courses, {self.model_num_lecturers} lecturers, {self.model_num_rooms} rooms")
                print(f"[NN]    Current problem: {len(self.courses)} courses, {len(self.lecturers)} lecturers, {len(self.rooms)} rooms")
                print(f"[NN]    Tip: Retrain model with current data: python3 train_nn_model.py")
            else:
                print(f"[NN] ⚠ Model not trained - using heuristic confidence scores")
                print(f"[NN] Scheduling {len(self.courses)} courses...")

        
        for c_idx, course in enumerate(self.courses):
            code = self._normalize_course_code(course.get('code', 'UNKNOWN'))
            candidates = []
            fixed_day = course.get('fixed_day')
            fixed_time = course.get('fixed_time')
            fixed_room = course.get('fixed_room')

            # Pre-calculate valid index ranges for fixed courses
            spec = self._get_special_constraint(code)
            f_day = fixed_day or spec.get('fixed_day')
            f_time = fixed_time or spec.get('fixed_time')
            f_room = fixed_room or spec.get('room_name')

            valid_days = [i for i, d in enumerate(self.days) if d == f_day] if f_day in self.days else range(len(self.days))
            
            # For time slots, use normalized matching as they might be ranges
            if f_time:
                valid_ts = [i for i, s in enumerate(self.time_slots) if self._slot_matches_fixed_time(s, f_time)]
                if not valid_ts: valid_ts = range(len(self.time_slots)) # Fallback if no match
            else:
                valid_ts = range(len(self.time_slots))
                
            valid_rooms = [i for i, r in enumerate(self.rooms) if self._room_matches(r['name'], f_room)] if f_room else range(len(self.rooms))

            for _ in range(100):
                l_idx = np.random.randint(0, self.num_lecturers)
                r_idx = np.random.choice(valid_rooms)
                d_idx = np.random.choice(valid_days)
                t_idx = np.random.choice(valid_ts)
                
                s_idx = d_idx * self.num_slots_per_day + t_idx
                if d_idx >= len(self.days) or t_idx >= len(self.time_slots): continue
                l, r, d, s = self.lecturers[l_idx], self.rooms[r_idx], self.days[d_idx], self.time_slots[t_idx]
                
                if self._assignment_valid(course, l, r, d, s, room_occ, lect_occ, lvl_occ, grp_slots):
                    ctx = {'room_utilization': len(room_occ[r['name']]) / (self.num_slots + 1), 'lecturer_load': len(lect_occ[l]) / (self.num_slots + 1)}
                    _, conf = self.predict_validity(c_idx, l_idx, r_idx, s_idx, ctx)
                    candidates.append((conf, {'lecturer': l, 'room': r['name'], 'day': d, 'time_slot': s, 'confidence': conf}))
                if len(candidates) >= 5: break
            
            best = None
            if candidates:
                best = max(candidates, key=lambda x: x[0])[1]
            else:
                # Robust fallback for hard cases - but MUST still respect valid indices
                for _ in range(500):
                    l_idx = np.random.randint(0, self.num_lecturers)
                    r_idx = np.random.choice(valid_rooms)
                    d_idx = np.random.choice(valid_days)
                    t_idx = np.random.choice(valid_ts)
                    
                    s_idx = d_idx * self.num_slots_per_day + t_idx
                    if d_idx >= len(self.days) or t_idx >= len(self.time_slots): continue
                    
                    l, r, d, s = self.lecturers[l_idx], self.rooms[r_idx], self.days[d_idx], self.time_slots[t_idx]
                    if self._assignment_valid(course, l, r, d, s, room_occ, lect_occ, lvl_occ, grp_slots):
                        best = {'lecturer': l, 'room': r['name'], 'day': d, 'time_slot': s, 'confidence': 0.5}
                        break

            
            if not best: best = {'lecturer': course.get('lecturer', self.lecturers[0]), 'room': self.rooms[0]['name'], 'day': self.days[0], 'time_slot': self.time_slots[0], 'confidence': 0.1}
            
            sk = f"{best['day']}_{best['time_slot']}"
            room_occ.setdefault(best['room'], {}).setdefault(sk, []).append(code)
            lect_occ.setdefault(best['lecturer'], {}).setdefault(sk, []).append(code)
            lvl_occ.setdefault(f"{course.get('level')}_{course.get('semester')}_{sk}", []).append(code)
            for shared_key in self._get_shared_block_keys(course):
                lvl_occ.setdefault(f"shared::{shared_key}::{best['day']}::{best['time_slot']}", []).append(code)
            
            s_id = course.get('shared_group_id')
            if s_id: grp_slots[s_id] = (best['day'], best['time_slot'])
            if code in self.course_groups: grp_slots["_".join(sorted(self.course_groups[code]))] = (best['day'], best['time_slot'])
            
            # Preserve the original course title (with section identifier like [Sec A] / [Sec B]).
            # Storing it here avoids the later dict-keyed lookup which collapses same-code sections.
            schedule.append({'course_code': code, 'section_id': course.get('section_id'), 'course_title': course.get('title', ''), 'lecturer': best['lecturer'], 'room': best['room'], 'day': best['day'], 'time_slot': best['time_slot']})
            quality += best['confidence']
            if self.verbose and (c_idx+1) % 20 == 0: print(f"  [NN] {int((c_idx+1)/len(self.courses)*100)}% complete")

        avg_q = quality / len(schedule) if schedule else 0
        self.best_schedule, self.best_score = schedule, avg_q
        return schedule, avg_q

    def save_model(self, filepath: str) -> bool:
        try:
            if not HAS_TENSORFLOW:
                with open(filepath + ".pkl", 'wb') as f:
                    pickle.dump({'model': self.model, 'scaler': self.scaler, 'trained': self.is_sklearn_trained}, f)
                return True
            self.model.save(filepath)
            return True
        except: return False

    def is_model_compatible(self) -> bool:
        """Check if loaded model is compatible with current problem size"""
        compatible = (
            self.model_num_courses >= self.num_courses and
            self.model_num_lecturers >= self.num_lecturers and
            self.model_num_rooms >= self.num_rooms and
            self.model_num_slots >= self.num_slots
        )
        if not compatible and self.verbose:
            print(f"[NN WARNING] Model incompatible with current problem size:")
            print(f"   Model: {self.model_num_courses} courses, {self.model_num_lecturers} lecturers, {self.model_num_rooms} rooms")
            print(f"   Current: {self.num_courses} courses, {self.num_lecturers} lecturers, {self.num_rooms} rooms")
        return compatible
    
    def save_model(self, filepath: str) -> bool:
        """Save trained model to file"""
        try:
            if not HAS_TENSORFLOW:
                # Save sklearn model
                pkl_path = filepath if filepath.endswith('.pkl') else filepath + '.pkl'
                with open(pkl_path, 'wb') as f:
                    pickle.dump({
                        'model': self.model,
                        'scaler': self.scaler,
                        'trained': self.is_sklearn_trained,
                        'num_courses': self.model_num_courses,
                        'num_lecturers': self.model_num_lecturers,
                        'num_rooms': self.model_num_rooms,
                        'num_slots': self.model_num_slots
                    }, f)
                if self.verbose:
                    print(f"[NN] Model saved to {pkl_path}")
                return True
            
            # Save TensorFlow model
            h5_path = filepath if filepath.endswith('.h5') else filepath + '.h5'
            self.model.save(h5_path)
            
            # Save metadata separately for TensorFlow models.
            # Keep both filenames for backward compatibility with existing upload/download code.
            import json
            meta_path = h5_path.replace('.h5', '_meta.json')
            legacy_meta_path = h5_path.replace('.h5', '.json')
            metadata = {
                'num_courses': self.model_num_courses,
                'num_lecturers': self.model_num_lecturers,
                'num_rooms': self.model_num_rooms,
                'num_slots': self.model_num_slots
            }
            with open(meta_path, 'w') as f:
                json.dump(metadata, f)
            with open(legacy_meta_path, 'w') as f:
                json.dump(metadata, f)
            
            if self.verbose:
                print(f"[NN] Model saved to {h5_path}")
            return True
        except Exception as e:
            if self.verbose:
                print(f"[NN ERROR] Failed to save model: {e}")
            return False
    
    def load_model(self, filepath: str) -> bool:
        """Load trained model from file"""
        try:
            if not HAS_TENSORFLOW:
                pkl_path = filepath if filepath.endswith('.pkl') else filepath + '.pkl'
                if os.path.exists(pkl_path):
                    with open(pkl_path, 'rb') as f:
                        d = pickle.load(f)
                        self.model = d['model']
                        self.scaler = d['scaler']
                        self.is_sklearn_trained = d.get('trained', True)
                        # Load dimension info if available
                        self.model_num_courses = d.get('num_courses', self.num_courses)
                        self.model_num_lecturers = d.get('num_lecturers', self.num_lecturers)
                        self.model_num_rooms = d.get('num_rooms', self.num_rooms)
                        self.model_num_slots = d.get('num_slots', self.num_slots)
                    if self.verbose:
                        print(f"[NN] Model loaded from {pkl_path}")
                    return self.is_model_compatible()
                return False
            
            # Load TensorFlow model
            h5_path = filepath if filepath.endswith('.h5') else filepath + '.h5'
            if os.path.exists(h5_path):
                loaded_model = models.load_model(h5_path)
                
                # Try to load metadata
                import json
                meta_path = h5_path.replace('.h5', '_meta.json')
                legacy_meta_path = h5_path.replace('.h5', '.json')
                if os.path.exists(meta_path) or os.path.exists(legacy_meta_path):
                    chosen_meta_path = meta_path if os.path.exists(meta_path) else legacy_meta_path
                    with open(chosen_meta_path, 'r') as f:
                        meta = json.load(f)
                        self.model_num_courses = meta.get('num_courses', self.num_courses)
                        self.model_num_lecturers = meta.get('num_lecturers', self.num_lecturers)
                        self.model_num_rooms = meta.get('num_rooms', self.num_rooms)
                        self.model_num_slots = meta.get('num_slots', self.num_slots)
                else:
                    # No metadata, assume incompatible for safety
                    if self.verbose:
                        print(f"[NN WARNING] No metadata found for model, assuming incompatible")
                    return False
                
                # Check compatibility before assigning to self.model
                if self.is_model_compatible():
                    self.model = loaded_model
                    if self.verbose:
                        print(f"[NN] Model loaded from {h5_path}")
                    return True
                else:
                    # Model is incompatible - don't assign it and skip auto-training
                    self.skip_autotraining = True  # Signal to skip auto-training
                    if self.verbose:
                        print(f"[NN] Model loaded from {h5_path}")
                    # Keep model as None to force heuristics
                    return False
            return False
        except Exception as e:
            if self.verbose:
                print(f"[NN ERROR] Failed to load model: {e}")
            return False

    def get_schedule(self) -> List[Dict]: return self.best_schedule
    
    def pre_train_from_history(self, history_path: str) -> Dict:
        import csv
        if not os.path.exists(history_path): return {"status": "error", "message": "History file not found"}
        X_list, y_list = [], []
        try:
            with open(history_path, 'r') as f:
                reader = csv.reader(f)
                for row in reader:
                    if not row or len(row) < 13: continue
                    h_code, h_lect, h_room, h_day, h_slot = row[1].strip(), row[3].strip(), row[4].strip(), row[5].strip(), row[6].strip()
                    try: h_label = 1 if int(row[12].strip()) > 0 else 0
                    except: h_label = 0
                    c_idx = next((i for i, c in enumerate(self.courses) if c['code'] == h_code or h_code in c['code']), None)
                    l_idx = next((i for i, l in enumerate(self.lecturers) if l == h_lect), None)
                    r_idx = next((i for i, r in enumerate(self.rooms) if r['name'] == h_room), None)
                    d_i = next((i for i, d in enumerate(self.days) if d == h_day), 0)
                    t_i = next((i for i, s in enumerate(self.time_slots) if s == h_slot), 0)
                    if None not in (c_idx, l_idx, r_idx):
                        feat = self.extract_features(c_idx, l_idx, r_idx, d_i * len(self.time_slots) + t_i, {'room_utilization': 0.5, 'lecturer_load': 0.5})
                        X_list.append(feat)
                        y_list.append(1.0 if h_label == 0 else 0.0)
            if not X_list: return {"status": "skipped", "message": "No valid samples"}
            X = [np.array([f[i] for f in X_list]) for i in range(5)]
            return self.train(X, np.array(y_list))
        except Exception as e: return {"status": "error", "message": str(e)}

    def get_training_info(self) -> Dict:
        params = self.model.coefs_[0].size if hasattr(self.model, 'coefs_') else (self.model.count_params() if hasattr(self.model, 'count_params') else 0)
        return {'architecture': self.architecture, 'trained_epochs': self.training_history['epoch'], 'best_quality': self.best_score, 'parameters': params}
