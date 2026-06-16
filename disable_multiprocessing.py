#!/usr/bin/env python3
"""Patch csp.py to disable multiprocessing"""

# Read original file
with open('/Applications/XAMPP/xamppfiles/htdocs/scheduler/csp.py', 'r') as f:
    content = f.read()

# Find and replace the ProcessPoolExecutor section
old_section = '''        try:
            # Determine worker count based on CPU cores
            max_workers = min(os.cpu_count() or 4, len(domain_values))
            if max_workers < 1: max_workers = 1
            
            # Start parallel search!
            self.log(f"[MULTIPROCESSING] Spawning {max_workers} processes for {len(domain_values)} top-level branches of var '{var_id}'...")
            
            with concurrent.futures.ProcessPoolExecutor(max_workers=max_workers) as executor:
                future_to_val = {}
                for val in domain_values:
                    # Check constituency purely at top level first
                    if self.is_consistent(assignment, var_id, val):
                        future = executor.submit(_solve_worker, self, var_id, val)
                        future_to_val[future] = val
                
                if not future_to_val:
                    # All initial values inconsistent
                    self.log("All top-level values for first variable are inconsistent.", is_error=True)
                    self.progress_callback = original_callback
                    self.run_diagnosis()
                    return None
                
                # Wait for the first successful completion
                try:
                    while future_to_val:
                        timeout_left = self.timeout_seconds - (time.time() - self.start_time)
                        if timeout_left <= 0:
                            raise concurrent.futures.TimeoutError("Global timeout exceeded")
                            
                        # Wait for the next future to complete, periodically checking timeout
                        done, not_done = concurrent.futures.wait(
                            future_to_val.keys(), 
                            timeout=min(2.0, timeout_left),
                            return_when=concurrent.futures.FIRST_COMPLETED
                        )
                        
                        for future in done:
                            val = future_to_val.pop(future)
                            res = future.result()
                            if res is not None:
                                # Success! Cancel remaining futures
                                for f in future_to_val.keys():
                                    f.cancel()
                                
                                self.progress_callback = original_callback
                                self.log("CSP solver found a solution (via parallel searching).")
                                
                                self.metrics["solve_time"] = time.time() - self.start_time
                                self.save_metrics()
                                return res
                                
                        if not done and timeout_left <= 2.0:
                            raise concurrent.futures.TimeoutError("Global timeout exceeded")
                except concurrent.futures.TimeoutError:
                    self.log(f"Global parallel timeout exceeded ({self.timeout_seconds}s). Stopping search.", is_error=True)
                    for f in future_to_val.keys():
                        f.cancel()
                    self.progress_callback = original_callback
                    self.run_diagnosis()
                    
                    self.metrics["solve_time"] = time.time() - self.start_time
                    self.save_metrics()
                    return None
                        
        except Exception as e:
            self.log(f"Multiprocessing error or pickling failed: {e}. Falling back to sequential backtrack.", is_error=True)
            self.progress_callback = original_callback
            
            # Fallback to standard backtrack
            result = self.backtrack(assignment)
            
            if result is not None:
                self.log("CSP solver found a solution.")
            else:
                self.log("CSP solver could not find a solution.", is_error=True)
                self.run_diagnosis()
                
            self.metrics["solve_time"] = time.time() - self.start_time
            self.save_metrics()
            return result
            
        # If we exited the pool and didn't return a res, we failed.
        self.progress_callback = original_callback
        self.log("CSP solver could not find a solution via any parallel branch.", is_error=True)
        self.run_diagnosis()
        
        self.metrics["solve_time"] = time.time() - self.start_time
        self.save_metrics()
        return None'''

new_section = '''        # Multiprocessing DISABLED for exam scheduling stability
        # Using simple sequential backtracking instead
        result = self.backtrack(assignment)
        
        if result is not None:
            self.log("CSP solver found a solution.")
        else:
            self.log("CSP solver could not find a solution.", is_error=True)
            self.run_diagnosis()
            
        self.metrics["solve_time"] = time.time() - self.start_time
        self.save_metrics()
        return result'''

if old_section in content:
    new_content = content.replace(old_section, new_section)
    with open('/Applications/XAMPP/xamppfiles/htdocs/scheduler/csp.py', 'w') as f:
        f.write(new_content)
    print("✓ Successfully patched csp.py - multiprocessing disabled")
else:
    print("✗ Old section not found")
