from typing import List, Dict, Tuple, Any, Callable, Optional
from data_model import ClassSection, Lecturer
import random
from datetime import datetime
import time

Assignment = Dict[str, Any]  # A mapping from variable id to assigned value
Domain = Dict[str, List[Any]]  # A mapping from variable id to list of possible values
Constraint = Callable[[Assignment, str, Any], bool]

import concurrent.futures
import os

def _solve_worker(csp_instance, var_id, value):
    """
    Module-level worker function for unpickling and running a CSP search branch.
    """
    # Disable file logging for workers to avoid I/O conflicts
    csp_instance.log_file = os.devnull
    csp_instance.progress_callback = None
    
    assignment = {var_id: value}
    sec = csp_instance.vars_by_id[var_id]
    
    # Initialize O(1) structures for this branch
    day, slot, room = value
    csp_instance.room_allocations.add((room, day, slot))
    if sec.lecturer_id:
        csp_instance.lecturer_allocations.add((sec.lecturer_id, day, slot))
    if sec.cohorts:
        time_key = (day, slot)
        if time_key not in csp_instance.cohort_allocations:
            csp_instance.cohort_allocations[time_key] = set()
        csp_instance.cohort_allocations[time_key].update(sec.cohorts)
        
    try:
        return csp_instance.backtrack(assignment)
    except TimeoutError:
        return None
    except Exception as e:
        print(f"[WORKER ERROR] {e}")
        return None

# Import flexibility management for handling occupied lecturer slots
try:
    from flexible_availability import FlexibilityManager
    FLEXIBILITY_ENABLED = True
except ImportError:
    FLEXIBILITY_ENABLED = False

class CSP:
    def __init__(self, 
                 variables: List[ClassSection],
                    domains: Domain,
                    constraints: List[Constraint],
                    lecturers: Dict[str, Lecturer],
                    rooms: Dict[str, Any] = None,  # Added rooms
                    preferences: Dict = None,
                    progress_callback: Optional[Callable[[str], None]] = None,
                    log_file : str = "csp_log.txt",
                    timeout_seconds: int = 30,
                    max_slot_load: int = 4,
                    interactive: bool = False,
                    availability_file: str = "lecturer_availability.csv",
                    availability_decision_mode: str = "ai_automatic",
                    config: Dict = None):
        self.variables = variables
        self.domains = domains
        self.constraints = constraints
        self.lecturers = lecturers
        self.rooms = rooms or {}  # Store rooms
        self.preferences = preferences or {}
        self.progress_callback = progress_callback
        self.log_file = log_file
        self.timeout_seconds = timeout_seconds
        self.max_slot_load = max_slot_load
        self.start_time = None
        self.interactive = interactive
        self.availability_file = availability_file
        self.availability_decision_mode = availability_decision_mode  # "ai_automatic" or "manual_control"
        self.config = config or {}
        self.metrics = {
            "variables_assigned": 0,
            "backtrack_count": 0,
            "constraint_violations": 0,
            "solve_time": 0.0
        }
        
        # --- NEW: O(1) Constraint Tracking Structures ---
        self.room_allocations = set()      # Stores: (room_id, day, slot)
        self.lecturer_allocations = set()  # Stores: (lecturer_id, day, slot)
        self.cohort_allocations = {}      # Stores: (day, slot) -> set(cohort_names)
        
        # Optimization: map IDs to sections for O(1) lookup
        self.vars_by_id = {var.id: var for var in variables}
        
        # --- NEW: Unary Domain Pre-filtering ---
        # Filter domains before search begins using unary constraints
        self._pre_filter_domains()
        
        # Initialize flexibility manager for intelligent reassignment
        if FLEXIBILITY_ENABLED:
            try:
                sections_dict = {var.id: var for var in variables}
                self.flexibility_manager = FlexibilityManager(sections_dict, lecturers)
                self.log("✓ Flexibility Manager enabled - AI can reassign sections if lecturer slots occupied")
            except Exception as e:
                self.flexibility_manager = None
                self.log(f"[WARNING] Could not initialize FlexibilityManager: {e}")
        else:
            self.flexibility_manager = None
            
        #Initialize logging
        with open(self.log_file, 'w') as f:
            f.write(f"----AI Solver Session Started at {datetime.now()}----\n")

    def _pre_filter_domains(self):
        """
        Permanently removes invalid values from domains using unary constraints
        that only depend on the variable and the value itself (no assignment dependency).
        """
        initial_domain_size = sum(len(d) for d in self.domains.values())
        
        # We need to extract the specific unary constraints from the self.constraints list
        # Since they are wrapped in lambdas/functions, it's easier to just call them directly
        # if we know what they are, or we evaluate constraints that pass with an empty assignment.
        
        var_ids = {var.id for var in self.variables}
        for var_id in list(self.domains.keys()):
            if var_id not in var_ids:
                continue
                
            valid_values = []
            for val in self.domains[var_id]:
                # A unary constraint should pass even with an empty assignment
                # If a constraint fails with an empty assignment, it MUST be invalid for ALL assignments.
                is_valid = True
                for constraint in self.constraints:
                    if not constraint({}, var_id, val, csp_instance=self):
                        is_valid = False
                        break
                
                if is_valid:
                    valid_values.append(val)
            
            self.domains[var_id] = valid_values
            
        final_domain_size = sum(len(d) for d in self.domains.values())
        diff = initial_domain_size - final_domain_size
        if diff > 0:
            print(f"[OPTIMIZATION] Pre-filtered {diff} impossible assignments from search space.")
            
        # VERY IMPORTANT: If any variable's domain was filtered to 0, the entire CSP is mathematically unsolvable.
        # Track this state so we can abort immediately instead of thrashing for 120 seconds.
        self.has_empty_domains = False
        for var_id in var_ids:
            if not self.domains.get(var_id):
                self.has_empty_domains = True
                print(f"[CRITICAL] Pre-filtering eliminated ALL possible slots for {self.vars_by_id[var_id].course_code}. Schedule is impossible.")
    
    def log(self, message: str, is_error: bool = False):
        timestamp = datetime.now().strftime("%H:%M:%S")
        prefix = "[ERROR]" if is_error else "[INFO]"
        log_message = f"{timestamp} {prefix} {message}\n"
        
        with open(self.log_file, 'a') as f:
            f.write(log_message + "\n")
        
        if self.progress_callback:
            self.progress_callback(log_message)

    def save_metrics(self, file_path: str = "solve_metrics.log"):
        metrics_log = (
            "\n=== SOLVE METRICS ===\n"
            f"Variables Assigned: {self.metrics['variables_assigned']}\n"
            f"Backtracks: {self.metrics['backtrack_count']}\n"
            f"Constraint Violations: {self.metrics['constraint_violations']}\n"
            f"Solve Time: {self.metrics['solve_time']:.2f}s\n"
            "======================\n"
        )
        with open(file_path, "a") as f:
            f.write(metrics_log)
    
    def get_value_score(self, var_id: str, value: Any, assignment: Optional[Assignment] = None) -> float:
        """
        Calculate a score for a given value based on lecturer preferences and flexibility.
        Higher scores indicate more preferred values.
        
        Scoring factors:
        1. Lecturer availability (100 pts if available, 0 if not)
        2. Department Room Match (50 pts if room matches course department)
        3. Lecturer flexibility (prefer slots that leave flexible lecturers more options)
        4. Room preferences (2x multiplier)
        5. Time preferences from AI model (5x multiplier)
        6. High-demand slot penalty (discourage overused time slots)
        """
        day_id, slot_id, room_id = value
        sec = self.vars_by_id[var_id]
        lecturer = self.lecturers.get(sec.lecturer_id)
        
        score = 0.0
        
        # Dynamic Weights from UI What-If Analyzer
        w_room = float(self.config.get('weight_room', 10.0))
        w_lecturer = float(self.config.get('weight_lecturer', 5.0))
        w_balance = float(self.config.get('weight_balance', 8.0))

        # Factor 1: Lecturer availability (hard constraint, but with soft preference)
        if lecturer and (day_id, slot_id) in lecturer.available_time_slots:
            score += 100.0 * (w_lecturer / 5.0)  # Baseline is 5.0, so 5.0/5.0 = 1x multiplier
        else:
            score += 10.0 * (w_lecturer / 5.0)   # Allow unavailable as last resort, but penalize
        
        # Factor 2: Department Room Preference
        if self.rooms and room_id in self.rooms:
            room = self.rooms[room_id]
            strict_dept = self.config.get('strict_departmental', False)
            
            # Use same token-based check as ConflictDetector or builder
            course_dept = str(sec.owning_department).lower().replace("/", " ").replace("-", " ")
            room_dept = str(room.department).lower().replace("/", " ").replace("-", " ")
            
            # Simple exact match or subset check
            is_match = (course_dept == room_dept) or (course_dept in room_dept) or (room_dept in course_dept)
            
            if is_match:
                score += 50.0 * (w_room / 10.0) # Baseline is 10.0
            elif strict_dept:
                # In strict mode, if it's not a match, we give it a massive penalty
                # Although domain pruning should have removed these already, this is double safety
                score -= 1000.0
            elif room.department == "General":
                # Fallback to general room (permitted in non-strict mode)
                score += 0.0
            else:
                # Borrowing from another department (discouraged)
                score -= 20.0 * (w_room / 10.0)
        
        # Factor 3: Lecturer flexibility consideration
        # Prefer assigning flexible lecturers to slots, save inflexible lecturers' slots
        if self.flexibility_manager:
            flexibility = self.flexibility_manager.flexibility_scores.get(sec.lecturer_id, 0)
            # High flexibility = prefer to use these slots (they have many options)
            # Low flexibility = avoid using these slots (save them for critical classes)
            score += (flexibility * 10) * (w_lecturer / 5.0)
        
        # Factor 4: Room preferences from AI model
        if self.preferences:
            r_key = (sec.course_code, room_id)
            score += self.preferences.get("course_room_preferences", {}).get(r_key, 0) * 2.0 * (w_room / 10.0)
        
        # Factor 5: Time preferences from AI model
        if self.preferences:
            l_key = (sec.lecturer_id.replace("_", " "), day_id, slot_id)
            score += self.preferences.get("lecturer_time_preferences", {}).get(l_key, 0) * 5.0 * (w_lecturer / 5.0)

        # Factor 6: High-demand slot penalty (spread classes across time slots)
        if assignment:
            current_load = sum(1 for _, (d, s, _) in assignment.items() if d == day_id and s == slot_id)
            if current_load >= self.max_slot_load:
                overload = current_load - self.max_slot_load + 1
                score -= (overload * 20.0) * (w_balance / 8.0)  # Baseline is 8.0
        
        return score
    
    def is_consistent(self, assignment: Assignment, var_id: str, value: Any) -> bool:
        # Check timeout
        if time.time() - self.start_time > self.timeout_seconds:
            self.log(f"Timeout exceeded ({self.timeout_seconds}s). Stopping search.", is_error=True)
            raise TimeoutError(f"CSP solver timed out after {self.timeout_seconds} seconds")
        
        # We don't actually add it to the assignment dict here because 
        # the constraints expect the *current* state of the world plus the candidate value.
        
        # To support constraints that haven't been migrated to O(1) yet,
        # we still temporarily add to assignment
        assignment[var_id] = value
        
        for constraint in self.constraints:
            if not constraint(assignment, var_id, value, csp_instance=self):
                self.metrics["constraint_violations"] += 1
                del assignment[var_id]
                return False
                
        del assignment[var_id]
        return True
    
    def select_unassigned_variable(self, assignment: Assignment) -> Optional[str]:
        """
        Select the next unassigned variable using Minimum Remaining Values (MRV) heuristic.
        
        With flexibility management enabled, prioritizes:
        1. Sections with fixed day/time (must be scheduled first)
        2. Sections with inflexible lecturers (few time slot options)
        3. Sections with flexible lecturers (more options available as backup)
        """
        unassigned_vars = [var.id for var in self.variables if var.id not in assignment]
        if not unassigned_vars:
            return None
            
        # CRITICAL MRV OPTIMIZATION: Always check for variables with 0 available values FIRST.
        # If a variable has 0 legal values based on current assignment, the search branch is DEAD.
        # Returning it forces an immediate backtrack, bypassing flexibility ordering thrashing.
        for var_id in unassigned_vars:
            # Check against pure domain first (if it naturally has 0 slots, it's impossible)
            if not self.domains.get(var_id):
                return var_id
                
            # Fast consistency check
            legal_count = sum(1 for val in self.domains[var_id] if self.is_consistent(assignment, var_id, val))
            if legal_count == 0:
                return var_id
        
        # If flexibility manager is enabled, use its priority ordering
        if self.flexibility_manager:
            priority_order = self.flexibility_manager.get_reassignment_priority()
            # Filter to only unassigned variables
            priority_unassigned = [v for v in priority_order if v in unassigned_vars]
            if priority_unassigned:
                # Pick the first unassigned variable in priority order
                return priority_unassigned[0]
        
        # Fallback to MRV heuristic (if no priority manager)
        best_var = None
        best_score = float('inf')
        
        for var_id in unassigned_vars:
            legal_count = 0
            for value in self.domains[var_id]:
                if self.is_consistent(assignment, var_id, value):
                    legal_count += 1
            
            if legal_count < best_score:
                best_score = legal_count
                best_var = var_id
            
            # Early exit optimization: If a variable has 0 legal values, it's a dead end. Return it immediately to trigger backtrack.
            # If a variable has 1 legal value, it's forced, so return it immediately as it's the best possible MRV score.
            if best_score == 0:
                self.log(f"Variable {var_id} has no legal values left.", is_error=True)
                return var_id
            elif best_score == 1:
                return var_id
                
        return best_var if best_var else unassigned_vars[0]    
    
    def backtrack(self, assignment: Assignment) -> Assignment | None:
        if len(assignment) == len(self.variables):
            return assignment

        var_id = self.select_unassigned_variable(assignment)
        if var_id is None:
            return None
        
        sec = self.vars_by_id[var_id]
        domain_values = self.domains[var_id]
        
        # Score and sort values, prioritizing better options
        # This allows flexible lecturers to use alternative slots
        domain_values.sort(key=lambda val: self.get_value_score(var_id, val, assignment) + random.uniform(0, 0.1), reverse=True) 
        
        attempts = 0
        for value in domain_values:
            attempts += 1
            if self.is_consistent(assignment, var_id, value):
                assignment[var_id] = value
                self.metrics["variables_assigned"] += 1
                day, slot, room = value
                
                # --- UPDATE O(1) TRACKING SETS ---
                self.room_allocations.add((room, day, slot))
                if sec.lecturer_id:
                    self.lecturer_allocations.add((sec.lecturer_id, day, slot))
                if sec.cohorts:
                    time_key = (day, slot)
                    if time_key not in self.cohort_allocations:
                        self.cohort_allocations[time_key] = set()
                    
                    # Store previously active cohorts at this time so we can revert cleanly
                    prior_cohorts = self.cohort_allocations[time_key].copy()
                    
                    # Add our cohorts
                    self.cohort_allocations[time_key].update(sec.cohorts)
                
                # Log assignment for debugging flexibility
                if self.flexibility_manager:
                    lecturer = self.lecturers.get(sec.lecturer_id)
                    if lecturer and (day, slot) not in lecturer.available_time_slots:
                        self.log(f"[FLEXIBLE] Assigned {sec.course_code} to alternative slot (day={day}, slot={slot}) "
                                f"- lecturer {sec.lecturer_id} not normally available, but using flexible assignment")
                
                result = self.backtrack(assignment)
                if result is not None:
                    return result
                
                # --- BACKTRACK: REVERT O(1) TRACKING SETS ---
                # Use discard() (not remove()) for idempotent cleanup
                # Multiple branches might add the same tuple; cleanup must be safe
                self.room_allocations.discard((room, day, slot))
                if sec.lecturer_id:
                    self.lecturer_allocations.discard((sec.lecturer_id, day, slot))
                if sec.cohorts:
                    self.cohort_allocations[(day, slot)] = prior_cohorts
                
                del assignment[var_id]
                self.metrics["backtrack_count"] += 1
        
        # All attempts failed - check if flexibility could have helped
        if self.flexibility_manager and attempts < len(domain_values):
            suggestion = self.flexibility_manager.suggest_alternative_assignment(var_id, -1, -1)
            if suggestion:
                self.log(f"[FLEXIBILITY] {sec.course_code}: {suggestion['num_alternatives']} alternatives available. "
                        f"Lecturer {sec.lecturer_id} could be reassigned if prior binding decisions were reconsidered.",
                        is_error=False)
        
        self.log(f"REJECTED: Could not place {sec.section_title}. All {len(domain_values)} attempted slots caused conflicts.", is_error=True)
        
        # CHECK IF AVAILABILITY IS THE BOTTLENECK
        # If interactive mode and lecturer has limited availability, try prompting to expand
        if self.interactive and sec.lecturer_id in self.lecturers:
            self._try_expand_availability_interactive(var_id, sec)
        
        return None
    
    def _try_expand_availability_interactive(self, var_id: str, sec: ClassSection):
        """
        Check if a section failed due to lecturer availability constraint.
        If so, handle based on availability_decision_mode:
        - "ai_automatic": Automatically expand availability to all days
        - "manual_control": Prompt user to decide
        """
        try:
            from manage_availability import (
                prompt_expand_availability_for_scheduling, 
                reload_lecturer_availability
            )
        except ImportError:
            self.log("[WARNING] Could not import manage_availability for interactive prompts", is_error=True)
            return
        
        lecturer = self.lecturers.get(sec.lecturer_id)
        if not lecturer:
            return
        
        # Count how many domain values are blocked due to lecturer availability
        availability_blocked = 0
        total_slots = len(self.domains[var_id])
        
        for day, slot, room in self.domains[var_id]:
            if (day, slot) not in lecturer.available_time_slots:
                availability_blocked += 1
        
        # If a significant portion is blocked by availability, it's likely the root cause
        availability_ratio = availability_blocked / total_slots if total_slots > 0 else 0
        
        self.log(f"[ANALYSIS] {sec.course_code}: {availability_blocked}/{total_slots} domain slots blocked by {sec.lecturer_id}'s availability ({availability_ratio*100:.0f}%)", 
                is_error=False)
        
        # If more than 50% blocked by availability, attempt expansion
        if availability_ratio >= 0.5:
            current_days = [i for i in range(5) if any((i, s) in lecturer.available_time_slots for s in range(5))]
            
            expanded = False
            
            if self.availability_decision_mode == "ai_automatic":
                # AI automatically expands to all days
                self.log(f"[AI AUTOMATIC] Expanding {sec.lecturer_id}'s availability to all days (Mon-Fri)", is_error=False)
                
                # Update the CSV automatically
                from manage_availability import save_to_csv
                save_to_csv(lecturer.name, [0, 1, 2, 3, 4], self.availability_file)
                expanded = True
                
            elif self.availability_decision_mode == "manual_control":
                # Prompt user for manual decision
                self.log(f"[MANUAL CONTROL] Prompting user to expand {sec.lecturer_id}'s availability", is_error=False)
                expanded = prompt_expand_availability_for_scheduling(
                    lecturer.name,
                    current_days,
                    self.availability_file
                )
            
            if expanded:
                # Reload the lecturer's availability from CSV
                reload_lecturer_availability(lecturer, self.availability_file)
                self.log(f"[SUCCESS] Reloaded availability for {lecturer.name}. Retrying scheduling with expanded availability...", 
                        is_error=False)
                
                # Update domain values to include newly available slots
                new_domain_values = []
                for day, slot, room in self.domains[var_id]:
                    # Now lecturer might have more available slots
                    if (day, slot) in lecturer.available_time_slots:
                        new_domain_values.append((day, slot, room))
                
                if len(new_domain_values) > len([v for v in self.domains[var_id] 
                        if (v[0], v[1]) in lecturer.available_time_slots]):
                    self.domains[var_id] = new_domain_values
                    self.log(f"[UPDATE] Domain for {sec.course_code} expanded to {len(new_domain_values)} slots", is_error=False)
            else:
                self.log(f"[INFO] Availability for {lecturer.name} not expanded. Scheduling will likely fail.", is_error=False)
    
    def solve(self) -> Assignment | None:
        self.start_time = time.time()
        self.log("Starting CSP solver...")
        
        # Log flexibility info if enabled
        if self.flexibility_manager:
            total_sections = len(self.variables)
            lecturer_count = len(self.lecturers)
            avail_scores = list(self.flexibility_manager.flexibility_scores.values())
            if avail_scores:
                avg_flexibility = sum(avail_scores) / len(avail_scores)
                self.log(f"Flexibility Management Active: {total_sections} sections, {lecturer_count} lecturers, "
                        f"avg flexibility score: {avg_flexibility:.2f}")
        
        assignment: Assignment = {}
        
        # Parallel Multiprocessing Optimization:
        # Instead of taking the first unassigned variable and trying its values sequentially,
        # we branch via ProcessPoolExecutor.
        if getattr(self, 'has_empty_domains', False):
            self.log("CSP solver could not start. One or more domains were perfectly pruned to 0 values.", is_error=True)
            self.run_diagnosis()
            return None
            
        var_id = self.select_unassigned_variable(assignment)
        if var_id is None:
            return assignment # Everything already assigned
            
        domain_values = self.domains[var_id]
        if not domain_values:
            self.log("CSP solver could not find a solution (First variable has empty domain).", is_error=True)
            self.run_diagnosis()
            return None
            
        # Sort values based on heuristic
        domain_values.sort(key=lambda val: self.get_value_score(var_id, val, assignment) + random.uniform(0, 0.1), reverse=True)
        
        # Temporarily nullify unpickleable attributes
        original_callback = self.progress_callback
        self.progress_callback = None
        
        # Multiprocessing DISABLED for exam scheduling stability
        # Using simple sequential backtracking instead
        result = self.backtrack(assignment)
        
        if result is not None:
            self.log("CSP solver found a solution.")
        else:
            self.log("CSP solver could not find a solution.", is_error=True)
            self.run_diagnosis()
            
        self.metrics["solve_time"] = time.time() - self.start_time
        self.save_metrics()
        return result

    def run_diagnosis(self):
        """
        Runs a diagnostic pass to explain WHY the schedule failed.
        """
        if self.config.get("disable_failure_diagnosis", False):
            self.log("Failure diagnosis skipped by configuration.")
            return

        from diagnostics import generate_conflict_heatmap
        
        print("\n" + "="*60)
        print("DIAGNOSTIC REPORT: SCHEDULING FAILURE ANALYSIS")
        print("="*60)
        self.log("Running failure diagnosis...")
        
        day_names = ["Mon", "Tue", "Wed", "Thu", "Fri"]
        generate_conflict_heatmap(self.variables, self.domains, day_names)

        # 1. Check Global Capacity
        total_slots = sum(len(lect.available_time_slots) for lect in self.lecturers.values())
        total_required = len(self.variables)
        print(f"\n[Analysis] Total Sections to Schedule: {total_required}")

        # Note: This checks lecturer capacity, but room capacity is also a factor.
        
        # 2. Greedy Attempt to identify blocker
        assignment: Assignment = {}
        unassigned = self.variables[:]
        
        # Sort by most constrained (heuristic: fewest domain values)
        unassigned.sort(key=lambda v: len(self.domains[v.id]))
        
        for var in unassigned:
            var_id = var.id
            valid_values = []
            conflict_reasons = {} # Map constraint_name -> count
            
            # Try to find a valid assignment
            for value in self.domains[var_id]:
                # Custom consistent check that records failures
                temp_assignment = assignment.copy()
                temp_assignment[var_id] = value
                
                is_valid = True
                for constraint in self.constraints:
                    if not constraint(temp_assignment, var_id, value, csp_instance=self):
                        is_valid = False
                        name = getattr(constraint, "__name__", str(constraint))
                        conflict_reasons[name] = conflict_reasons.get(name, 0) + 1
                        # Continue checking other constraints? No, usually one is enough to block.
                        # But to get full stats, maybe we want to see ALL blockers?
                        # For now, break on first failure to mimic solver behavior, 
                        # but recording the specific constraint is key.
                        break 
                
                if is_valid:
                    valid_values.append(value)
            
            if valid_values:
                # Assign the first valid one (Greedy)
                # Ideally we'd pick the "least constraining" one, but simple greedy is fine for diagnosis placement
                assignment[var_id] = valid_values[0]
            else:
                # FAILURE FOUND
                print(f"\n[CRITICAL FAILURE] Could not schedule: {var.section_title} ({var.course_code})")
                print(f"  Lecturer: {var.lecturer_id.replace('_', ' ')}")
                
                total_domain_size = len(self.domains[var_id])
                print(f"  Total possible slots allowed by domain: {total_domain_size}")
                
                print("\n  REASON FOR BLOCKAGE:")
                for reason, count in conflict_reasons.items():
                    pct = (count / total_domain_size) * 100
                    
                    reason_human = reason
                    if "lecturer" in reason: reason_human = "Lecturer Availability/Conflict"
                    elif "room" in reason: reason_human = "Room Occupied"
                    elif "cohort" in reason: reason_human = "Student Cohort Conflict"
                    
                    print(f"  - {reason_human}: Blocked {count} slots ({pct:.1f}%)")
                    
                # specific check for PEAC 100 or special rooms
                if total_domain_size == 1:
                    print("\n  [TIP] This course has a very restricted domain (only 1 option).")
                    print("  Check 'special_rooms.csv' or if it's forced to a specific day/time.")
                
                # Check if lecturer is the bottleneck
                lecturer = self.lecturers.get(var.lecturer_id)
                if lecturer:
                   avail_count = len(lecturer.available_time_slots)
                   print(f"\n  [INFO] Lecturer {lecturer.name} has {avail_count} available slots total.")
                   if avail_count < 3:
                       print("  -> REVIEW: Lecturer has very limited availability. Consider expanding in 'lecturer_availability.csv'.")
                
                print("="*60 + "\n")
                return # Stop after reporting the first major blocker
        
        print("\n[INFO] Diagnosis check passed in greedy mode??")
        print("This implies the failure is due to complex deep interaction or backtracking limits, not a simple bottleneck.")
        print("Try increasing the timeout or checking for circular resource contention.")
        print("="*60 + "\n")


    def calculate_accuracy(self, assignment: Assignment) -> float:
        """
        Calculates the accuracy score of the schedule using sklearn.metrics.accuracy_score if available.
        Accuracy is defined as the percentage of classes scheduled during a lecturer's explicitly available hours.
        """
        total = len(assignment)
        if total == 0: return 0.0
        
        # Safe import for sklearn
        try:
            from sklearn.metrics import accuracy_score
            has_sklearn = True
        except ImportError:
            has_sklearn = False
            
        y_true = []
        y_pred = []
        
        matches = 0 # Keep manual counter for fallback/speed
        
        for var_id, value in assignment.items():
            day, slot, _ = value
            sec = self.vars_by_id[var_id]
            lecturer = self.lecturers.get(sec.lecturer_id)
            
            is_match = 0
            if lecturer and (day, slot) in lecturer.available_time_slots:
                is_match = 1
                matches += 1
            
            if has_sklearn:
                y_true.append(1) # We always DESIRE a match (ideal state)
                y_pred.append(is_match) # Actual state
        
        if has_sklearn:
            return accuracy_score(y_true, y_pred) * 100.0
        else:
            return (matches / total) * 100.0

