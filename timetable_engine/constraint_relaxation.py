from typing import List, Dict, Tuple, Optional
from dataclasses import dataclass
from .conflict_detector import ConflictRecord, ConflictType, ConstraintSeverity
from .models import ScheduleItem, Course, Room

@dataclass
class RelaxationOption:
    """Represents a possible constraint relaxation"""
    action_type: str  # "move_to_slot", "move_to_room", "reassign_lecturer", etc.
    schedule_item: ScheduleItem
    new_value: any
    affected_conflicts: List[ConflictRecord] = None
    new_conflicts_introduced: int = 0
    net_benefit: float = 0.0  # Positive = improvement
    feasibility_score: float = 0.0  # 0-1, how likely to work

class ConstraintRelaxationEngine:
    """Handles constraint relaxation when scheduling fails"""
    
    def __init__(self, courses: List[Course], rooms: List[Room], slots: List[str], 
                 lecturers: Dict, all_schedule_items: List[ScheduleItem] = None):
        self.courses = courses
        self.rooms = rooms
        self.slots = slots
        self.lecturers = lecturers
        self.all_schedule_items = all_schedule_items or []
        self.relaxation_history = []
        
    def suggest_relaxations(self, conflict: ConflictRecord, current_schedule: List[ScheduleItem],
                           max_suggestions: int = 5) -> List[RelaxationOption]:
        """Suggest ways to relax a constraint to resolve conflict"""
        options = []
        
        if conflict.conflict_type == ConflictType.ROOM_DOUBLE_BOOKING:
            options.extend(self._relax_room_conflict(conflict, current_schedule))
        elif conflict.conflict_type == ConflictType.LECTURER_DOUBLE_BOOKING:
            options.extend(self._relax_lecturer_conflict(conflict, current_schedule))
        elif conflict.conflict_type == ConflictType.FRIDAY_RESTRICTION:
            options.extend(self._relax_friday_restriction(conflict, current_schedule))
        elif conflict.conflict_type == ConflictType.CREDIT_HOUR_MISMATCH:
            options.extend(self._relax_credit_hour(conflict, current_schedule))
        elif conflict.conflict_type == ConflictType.DEPARTMENT_ROOM_MISMATCH:
            options.extend(self._relax_department_mismatch(conflict, current_schedule))
        elif conflict.conflict_type == ConflictType.GROUP_SEPARATION:
            options.extend(self._relax_group_separation(conflict, current_schedule))
        
        # Sort by net benefit (highest first)
        options.sort(key=lambda x: x.net_benefit, reverse=True)
        return options[:max_suggestions]
    
    def _relax_room_conflict(self, conflict: ConflictRecord, schedule: List[ScheduleItem]) -> List[RelaxationOption]:
        """Relax room double-booking conflict"""
        options = []
        conflicting_items = conflict.involved_schedules
        
        for item in conflicting_items:
            # Option 1: Move to different time slot
            for new_slot in self.slots:
                if new_slot == item.time_slot:
                    continue
                if not self._slot_has_conflict(item.room_name, item.day, new_slot, schedule, item):
                    option = RelaxationOption(
                        action_type="move_to_different_slot",
                        schedule_item=item,
                        new_value=new_slot,
                        feasibility_score=0.8,
                        net_benefit=1.0
                    )
                    options.append(option)
            
            # Option 2: Move to different room
            for room in self.rooms:
                if room.name == item.room_name:
                    continue
                if not self._has_room_conflict(room.name, item.day, item.time_slot, schedule, item):
                    option = RelaxationOption(
                        action_type="move_to_different_room",
                        schedule_item=item,
                        new_value=room.name,
                        feasibility_score=0.7,
                        net_benefit=0.9
                    )
                    options.append(option)
        
        return options
    
    def _relax_lecturer_conflict(self, conflict: ConflictRecord, schedule: List[ScheduleItem]) -> List[RelaxationOption]:
        """Relax lecturer double-booking conflict"""
        options = []
        conflicting_items = conflict.involved_schedules
        
        for item in conflicting_items:
            # Option 1: Move course to different time slot
            for new_slot in self.slots:
                if new_slot == item.time_slot:
                    continue
                if not self._lecturer_has_conflict(item.lecturer, item.day, new_slot, schedule, item):
                    option = RelaxationOption(
                        action_type="move_to_different_slot",
                        schedule_item=item,
                        new_value=new_slot,
                        feasibility_score=0.75,
                        net_benefit=0.95
                    )
                    options.append(option)
            
            # Option 2: Reassign to different lecturer
            for lecturer_name in self.lecturers:
                if lecturer_name == item.lecturer:
                    continue
                option = RelaxationOption(
                    action_type="reassign_lecturer",
                    schedule_item=item,
                    new_value=lecturer_name,
                    feasibility_score=0.5,  # Low - may not have expertise
                    net_benefit=0.7
                )
                options.append(option)
        
        return options
    
    def _relax_friday_restriction(self, conflict: ConflictRecord, schedule: List[ScheduleItem]) -> List[RelaxationOption]:
        """Relax Friday afternoon restriction"""
        options = []
        item = conflict.involved_schedules[0]
        
        # Move to Friday morning
        for morning_slot in ["7:00am - 9:30am", "10:00am - 12:30pm"]:
            if not self._slot_has_conflict(item.room_name, "Friday", morning_slot, schedule, item):
                option = RelaxationOption(
                    action_type="move_to_friday_morning",
                    schedule_item=item,
                    new_value=morning_slot,
                    feasibility_score=0.95,
                    net_benefit=0.8
                )
                options.append(option)
        
        # Move to different day
        for day in ["Monday", "Tuesday", "Wednesday", "Thursday"]:
            for slot in self.slots[:3]:  # Avoid evening slots
                if not self._slot_has_conflict(item.room_name, day, slot, schedule, item):
                    option = RelaxationOption(
                        action_type="move_to_different_day",
                        schedule_item=item,
                        new_value=(day, slot),
                        feasibility_score=0.9,
                        net_benefit=1.0
                    )
                    options.append(option)
                    break
        
        return options
    
    def _relax_credit_hour(self, conflict: ConflictRecord, schedule: List[ScheduleItem]) -> List[RelaxationOption]:
        """Relax credit hour violation"""
        options = []
        item = conflict.involved_schedules[0]
        
        # Move to earlier time slot (not evening)
        for earlier_slot in ["7:00am - 9:30am", "10:00am - 12:30pm", "2:00pm - 4:30pm"]:
            if earlier_slot == item.time_slot:
                continue
            if not self._slot_has_conflict(item.room_name, item.day, earlier_slot, schedule, item):
                option = RelaxationOption(
                    action_type="move_away_from_evening",
                    schedule_item=item,
                    new_value=earlier_slot,
                    feasibility_score=0.85,
                    net_benefit=0.9
                )
                options.append(option)
        
        return options
    
    def _relax_department_mismatch(self, conflict: ConflictRecord, schedule: List[ScheduleItem]) -> List[RelaxationOption]:
        """Relax department room mismatch"""
        options = []
        item = conflict.involved_schedules[0]
        
        # Move to appropriate department room
        for room in self.rooms:
            if room.name == item.room_name:
                continue
            # Check if room is in correct department (simplified check)
            if not self._has_room_conflict(room.name, item.day, item.time_slot, schedule, item):
                option = RelaxationOption(
                    action_type="move_to_correct_dept_room",
                    schedule_item=item,
                    new_value=room.name,
                    feasibility_score=0.7,
                    net_benefit=0.6
                )
                options.append(option)
        
        return options
    
    def _relax_group_separation(self, conflict: ConflictRecord, schedule: List[ScheduleItem]) -> List[RelaxationOption]:
        """Relax group course separation"""
        options = []
        items = conflict.involved_schedules
        
        # Try to find a common slot for all courses in group
        for day in ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday"]:
            for slot in self.slots:
                all_can_fit = all(
                    not self._slot_has_conflict(item.room_name, day, slot, schedule, item)
                    for item in items
                )
                
                if all_can_fit:
                    option = RelaxationOption(
                        action_type="group_to_common_slot",
                        schedule_item=items[0],
                        new_value=(day, slot, items),
                        feasibility_score=0.8,
                        net_benefit=0.85
                    )
                    options.append(option)
        
        return options
    
    def _slot_has_conflict(self, room: str, day: str, slot: str, schedule: List[ScheduleItem],
                          original_item: ScheduleItem = None) -> bool:
        """Check if time slot would create room conflict"""
        for item in schedule:
            if item == original_item:
                continue
            if item.room_name == room and item.day == day and item.time_slot == slot:
                return True
        return False
    
    def _has_room_conflict(self, room: str, day: str, slot: str, schedule: List[ScheduleItem],
                          original_item: ScheduleItem = None) -> bool:
        """Check if room has conflicts"""
        return self._slot_has_conflict(room, day, slot, schedule, original_item)
    
    def _lecturer_has_conflict(self, lecturer: str, day: str, slot: str, schedule: List[ScheduleItem],
                              original_item: ScheduleItem = None) -> bool:
        """Check if lecturer is available at slot"""
        for item in schedule:
            if item == original_item:
                continue
            if item.lecturer == lecturer and item.day == day and item.time_slot == slot:
                return True
        return False
    
    def apply_relaxation(self, option: RelaxationOption, schedule: List[ScheduleItem]) -> Tuple[bool, str]:
        """Apply a relaxation option to the schedule"""
        try:
            item = option.schedule_item
            
            if option.action_type == "move_to_different_slot":
                item.time_slot = option.new_value
            elif option.action_type == "move_to_different_room":
                item.room_name = option.new_value
            elif option.action_type == "reassign_lecturer":
                item.lecturer = option.new_value
            elif option.action_type == "move_to_friday_morning":
                item.time_slot = option.new_value
            elif option.action_type == "move_to_different_day":
                day, slot = option.new_value
                item.day = day
                item.time_slot = slot
            elif option.action_type == "move_away_from_evening":
                item.time_slot = option.new_value
            elif option.action_type == "move_to_correct_dept_room":
                item.room_name = option.new_value
            elif option.action_type == "group_to_common_slot":
                day, slot, grouped_items = option.new_value
                for grouped_item in grouped_items:
                    grouped_item.day = day
                    grouped_item.time_slot = slot
            
            # Record in history
            self.relaxation_history.append({
                "action": option.action_type,
                "course": item.course_code,
                "old_value": item,
                "new_value": option.new_value
            })
            
            return True, f"Applied {option.action_type} successfully"
        
        except Exception as e:
            return False, f"Error applying relaxation: {str(e)}"
    
    def create_adjustment_scenario(self, relaxations: List[RelaxationOption],
                                   original_schedule: List[ScheduleItem]) -> List[ScheduleItem]:
        """Create hypothetical schedule with relaxations applied"""
        import copy
        scenario_schedule = copy.deepcopy(original_schedule)
        
        for relaxation in relaxations:
            self.apply_relaxation(relaxation, scenario_schedule)
        
        return scenario_schedule
    
    def reset_history(self):
        """Clear relaxation history"""
        self.relaxation_history = []
    
    def get_relaxation_summary(self) -> Dict:
        """Get summary of all relaxations applied"""
        return {
            "total_relaxations": len(self.relaxation_history),
            "by_action_type": self._count_by_field("action", self.relaxation_history),
            "affected_courses": list(set(r["course"] for r in self.relaxation_history)),
            "history": self.relaxation_history
        }
    
    @staticmethod
    def _count_by_field(field: str, items: List[Dict]) -> Dict:
        """Count items by field value"""
        counts = {}
        for item in items:
            val = item.get(field)
            counts[val] = counts.get(val, 0) + 1
        return counts
