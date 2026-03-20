from typing import List, Dict, Optional, Any, Tuple
from dataclasses import dataclass, field
from datetime import datetime
import json

@dataclass
class OverrideRecord:
    """Record of a manual scheduling override"""
    timestamp: str
    user_id: str  # Who made the override
    course_code: str
    original_values: Dict[str, Any]  # What was changed
    new_values: Dict[str, Any]
    reason: str
    override_type: str  # "force_placement", "force_time_change", "force_room_change", etc.
    approved_by: Optional[str] = None
    approval_timestamp: Optional[str] = None
    status: str = "pending"  # pending, approved, rejected, applied

class ScheduleOverrideManager:
    """Manages manual scheduling overrides with audit trail"""
    
    def __init__(self):
        self.overrides: List[OverrideRecord] = []
        self.approved_overrides: List[OverrideRecord] = []
        self.override_log_file = "override_audit.json"
        self.approval_chain = {}  # role -> can_approve_type
        self._init_approval_chain()
    
    def _init_approval_chain(self):
        """Initialize who can approve what"""
        self.approval_chain = {
            "admin": ["all"],
            "department_head": ["faculty_override", "room_change"],
            "registrar": ["all"],
            "scheduler": ["force_placement"]
        }
    
    def propose_override(self, course_code: str, user_id: str, override_type: str,
                        original_values: Dict, new_values: Dict, reason: str) -> OverrideRecord:
        """Propose a manual override (creates pending record)"""
        override = OverrideRecord(
            timestamp=datetime.now().isoformat(),
            user_id=user_id,
            course_code=course_code,
            original_values=original_values,
            new_values=new_values,
            reason=reason,
            override_type=override_type,
            status="pending"
        )
        self.overrides.append(override)
        return override
    
    def approve_override(self, override_id: int, approver_id: str, approver_role: str,
                        approval_reason: Optional[str] = None) -> Tuple[bool, str]:
        """Approve a pending override"""
        if override_id >= len(self.overrides):
            return False, "Override not found"
        
        override = self.overrides[override_id]
        
        # Check if approver has permission
        allowed_types = self.approval_chain.get(approver_role, [])
        if "all" not in allowed_types and override.override_type not in allowed_types:
            return False, f"Role '{approver_role}' cannot approve '{override.override_type}'"
        
        override.approved_by = approver_id
        override.approval_timestamp = datetime.now().isoformat()
        override.status = "approved"
        self.approved_overrides.append(override)
        
        return True, f"Override approved by {approver_id}"
    
    def reject_override(self, override_id: int, rejector_id: str, reason: str) -> Tuple[bool, str]:
        """Reject a pending override"""
        if override_id >= len(self.overrides):
            return False, "Override not found"
        
        override = self.overrides[override_id]
        override.status = "rejected"
        override.approved_by = rejector_id
        override.approval_timestamp = datetime.now().isoformat()
        
        return True, f"Override rejected by {rejector_id}: {reason}"
    
    def apply_override(self, override_id: int, schedule_item) -> Tuple[bool, str]:
        """Apply an approved override to a schedule item"""
        if override_id >= len(self.overrides):
            return False, "Override not found"
        
        override = self.overrides[override_id]
        
        if override.status != "approved":
            return False, f"Cannot apply override with status: {override.status}"
        
        try:
            # Apply new values to schedule item
            for key, value in override.new_values.items():
                setattr(schedule_item, key, value)
            
            override.status = "applied"
            return True, "Override applied successfully"
        
        except Exception as e:
            return False, f"Error applying override: {str(e)}"
    
    def get_pending_overrides(self) -> List[OverrideRecord]:
        """Get all pending approval overrides"""
        return [o for o in self.overrides if o.status == "pending"]
    
    def get_override_history(self, course_code: Optional[str] = None) -> List[OverrideRecord]:
        """Get override history, optionally filtered by course"""
        if course_code:
            return [o for o in self.overrides if o.course_code == course_code]
        return self.overrides
    
    def get_user_override_activity(self, user_id: str) -> Dict:
        """Get all overrides proposed by a user"""
        user_overrides = [o for o in self.overrides if o.user_id == user_id]
        return {
            "user": user_id,
            "total_proposed": len(user_overrides),
            "approved": len([o for o in user_overrides if o.status == "approved"]),
            "pending": len([o for o in user_overrides if o.status == "pending"]),
            "rejected": len([o for o in user_overrides if o.status == "rejected"]),
            "applied": len([o for o in user_overrides if o.status == "applied"]),
            "overrides": user_overrides
        }
    
    def generate_audit_report(self) -> Dict:
        """Generate comprehensive audit report"""
        return {
            "total_overrides": len(self.overrides),
            "pending": len(self.get_pending_overrides()),
            "approved": len([o for o in self.overrides if o.status == "approved"]),
            "rejected": len([o for o in self.overrides if o.status == "rejected"]),
            "applied": len([o for o in self.overrides if o.status == "applied"]),
            "by_type": self._count_by_type(),
            "by_user": self._count_by_user(),
            "audit_trail": self._serialize_overrides()
        }
    
    def _count_by_type(self) -> Dict[str, int]:
        """Count overrides by type"""
        counts = {}
        for override in self.overrides:
            override.override_type = override.override_type or "unknown"
            counts[override.override_type] = counts.get(override.override_type, 0) + 1
        return counts
    
    def _count_by_user(self) -> Dict[str, int]:
        """Count overrides by proposing user"""
        counts = {}
        for override in self.overrides:
            counts[override.user_id] = counts.get(override.user_id, 0) + 1
        return counts
    
    def _serialize_overrides(self) -> List[Dict]:
        """Convert overrides to serializable format"""
        return [
            {
                "timestamp": o.timestamp,
                "user": o.user_id,
                "course": o.course_code,
                "type": o.override_type,
                "original": o.original_values,
                "new": o.new_values,
                "reason": o.reason,
                "status": o.status,
                "approved_by": o.approved_by,
                "approval_time": o.approval_timestamp
            }
            for o in self.overrides
        ]
    
    def export_audit_log(self, filename: str = None) -> bool:
        """Export audit trail to JSON file"""
        if not filename:
            filename = self.override_log_file
        
        try:
            with open(filename, 'w') as f:
                json.dump(self.generate_audit_report(), f, indent=2)
            return True
        except Exception as e:
            print(f"Error exporting audit log: {e}")
            return False
    
    def import_audit_log(self, filename: str) -> bool:
        """Import audit trail from JSON file"""
        try:
            with open(filename, 'r') as f:
                data = json.load(f)
            
            # Reconstruct overrides from audit trail
            for override_data in data.get("audit_trail", []):
                override = OverrideRecord(
                    timestamp=override_data["timestamp"],
                    user_id=override_data["user"],
                    course_code=override_data["course"],
                    original_values=override_data["original"],
                    new_values=override_data["new"],
                    reason=override_data["reason"],
                    override_type=override_data["type"],
                    approved_by=override_data.get("approved_by"),
                    approval_timestamp=override_data.get("approval_time"),
                    status=override_data["status"]
                )
                self.overrides.append(override)
                if override.status == "approved":
                    self.approved_overrides.append(override)
            
            return True
        except Exception as e:
            print(f"Error importing audit log: {e}")
            return False

class ScheduleChangeController:
    """High-level controller for schedule changes with validation"""
    
    def __init__(self, override_manager: ScheduleOverrideManager, conflict_detector):
        self.override_manager = override_manager
        self.conflict_detector = conflict_detector
        self.change_log = []
    
    def request_schedule_change(self, course_code: str, user_id: str, changes: Dict,
                               reason: str) -> Tuple[bool, str, Dict]:
        """Request a schedule change with validation"""
        
        # Validate changes
        allowed_fields = ["day", "time_slot", "room_name", "lecturer"]
        invalid_fields = set(changes.keys()) - set(allowed_fields)
        if invalid_fields:
            return False, f"Invalid fields: {invalid_fields}", {}
        
        # Detect override type
        if "day" in changes or "time_slot" in changes:
            override_type = "time_change"
        elif "room_name" in changes:
            override_type = "room_change"
        elif "lecturer" in changes:
            override_type = "lecturer_change"
        else:
            override_type = "general_override"
        
        # Create override record
        override = self.override_manager.propose_override(
            course_code=course_code,
            user_id=user_id,
            override_type=override_type,
            original_values={},  # Would be populated with current schedule
            new_values=changes,
            reason=reason
        )
        
        return True, "Change request submitted for approval", {"override_id": len(self.override_manager.overrides) - 1}
    
    def get_change_impact_analysis(self, override_id: int, current_schedule) -> Dict:
        """Analyze impact of a proposed change"""
        if override_id >= len(self.override_manager.overrides):
            return {"error": "Override not found"}
        
        override = self.override_manager.overrides[override_id]
        
        # Create hypothetical schedule with change applied
        import copy
        test_schedule = copy.deepcopy(current_schedule)
        
        # Find and modify the relevant item
        for item in test_schedule:
            if item.course_code == override.course_code:
                for key, value in override.new_values.items():
                    setattr(item, key, value)
                break
        
        # Detect conflicts in new schedule
        conflicts = self.conflict_detector.detect_all_conflicts(test_schedule)
        
        return {
            "proposed_change": override.override_type,
            "new_values": override.new_values,
            "new_conflicts_count": len(conflicts),
            "new_conflicts": conflicts,
            "impact_score": len(conflicts) / max(1, len(test_schedule))
        }
