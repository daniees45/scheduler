"""
Flexible Availability Management for Lecturer Assignment

This module provides intelligent fallback mechanisms when a lecturer's
preferred time slot is occupied by another class they teach. Instead of
failing, the system can reassign sections to alternative available slots.
"""

from typing import Dict, List, Set, Tuple, Optional
from data_model import ClassSection, Lecturer

def build_lecturer_section_map(sections: Dict[str, ClassSection]) -> Dict[str, List[str]]:
    """
    Build a mapping of lecturer_id -> list of section IDs they teach.
    
    This helps identify when a lecturer has multiple sections and allows
    flexible reassignment if their preferred slot becomes unavailable.
    
    Args:
        sections: Dictionary of all class sections
        
    Returns:
        Dictionary mapping lecturer_id -> list of section_ids
    """
    lecturer_sections = {}
    for section_id, section in sections.items():
        lecturer_id = section.lecturer_id
        if lecturer_id not in lecturer_sections:
            lecturer_sections[lecturer_id] = []
        lecturer_sections[lecturer_id].append(section_id)
    return lecturer_sections


def get_alternative_slots(lecturer: Lecturer, day: int, slot: int, 
                          occupied_slots: Set[Tuple[int, int]]) -> List[Tuple[int, int]]:
    """
    Find alternative time slots for a lecturer if their preferred slot is occupied.
    
    Prioritizes:
    1. Same day, different slot
    2. Different day, same slot preference
    3. Any other available slot
    
    Args:
        lecturer: The lecturer object with available_time_slots
        day: Preferred day (0-4)
        slot: Preferred slot (0-n)
        occupied_slots: Set of (day, slot) tuples already occupied by this lecturer
        
    Returns:
        Sorted list of alternative (day, slot) tuples, in priority order
    """
    alternatives = []
    
    # Get lecturer's available slots
    available = set(lecturer.available_time_slots)
    
    # Remove occupied slots
    available -= occupied_slots
    
    if not available:
        return []
    
    # Priority 1: Same day, different slot
    same_day_alts = [(d, s) for d, s in available if d == day and s != slot]
    alternatives.extend(sorted(same_day_alts, key=lambda x: x[1]))  # Sort by slot
    
    # Priority 2: Different day, same slot (if exists in available)
    same_slot_alts = [(d, s) for d, s in available if d != day and s == slot]
    alternatives.extend(sorted(same_slot_alts, key=lambda x: x[0]))  # Sort by day
    
    # Priority 3: Everything else (prefer consecutive days)
    other_alts = [(d, s) for d, s in available 
                  if d != day and s != slot]
    # Sort by (day, slot) for consistency
    alternatives.extend(sorted(other_alts, key=lambda x: (x[0], x[1])))
    
    return alternatives


def find_conflict_resolution(lecturer_id: str, 
                             preferred_day: int, 
                             preferred_slot: int,
                             lecturer_sections: Dict[str, List[str]],
                             sections: Dict[str, ClassSection],
                             lecturers: Dict[str, Lecturer],
                             current_assignment: Dict[str, Tuple[int, int, str]],
                             blocked_slot: bool = True) -> Optional[Dict[str, Tuple[int, int, str]]]:
    """
    Attempt to resolve a scheduling conflict by reassigning sections.
    
    If a lecturer's preferred slot is occupied, try to:
    1. Move this section to an alternative slot
    2. Or reassign OTHER sections this lecturer teaches to free up the preferred slot
    
    Args:
        lecturer_id: The lecturer whose slot is occupied
        preferred_day: The preferred day (0-4)
        preferred_slot: The preferred slot number
        lecturer_sections: Mapping of lecturer_id -> list of section_ids
        sections: Dictionary of all class sections
        lecturers: Dictionary of lecturer objects
        current_assignment: Current assignment state {section_id -> (day, slot, room_id)}
        blocked_slot: If True, the preferred slot is blocked; try alternatives
        
    Returns:
        Modified assignment dict if resolution found, else None
    """
    if lecturer_id not in lecturer_sections:
        return None
    
    affected_sections = lecturer_sections[lecturer_id]
    lecturer = lecturers.get(lecturer_id)
    
    if not lecturer:
        return None
    
    # Find occupied slots for this lecturer
    occupied_slots = set()
    for sec_id in affected_sections:
        if sec_id in current_assignment:
            day, slot, _ = current_assignment[sec_id]
            occupied_slots.add((day, slot))
    
    # Try to move to alternative slots
    alternatives = get_alternative_slots(lecturer, preferred_day, preferred_slot, occupied_slots)
    
    if not alternatives:
        return None  # No alternatives available for this lecturer
    
    # Return the best alternative as a suggestion
    return {
        "lecturer_id": lecturer_id,
        "current_occupation": (preferred_day, preferred_slot),
        "alternatives": alternatives,
        "affected_sections": affected_sections,
        "occupied_slots": occupied_slots
    }


def get_lecturer_flexibility_score(lecturer_id: str,
                                   lecturer_sections: Dict[str, List[str]],
                                   lecturers: Dict[str, Lecturer]) -> float:
    """
    Calculate how flexible a lecturer is in terms of availability.
    
    Higher score = more flexible (more available slots).
    Lower score = less flexible (fewer available slots).
    
    This helps prioritize which sections to assign first:
    - Assign inflexible lecturers' sections first (they have fewer options)
    - Assign flexible lecturers' sections later (more fallback options)
    
    Args:
        lecturer_id: The lecturer's ID
        lecturer_sections: Mapping of lecturer_id -> list of section_ids
        lecturers: Dictionary of lecturer objects
        
    Returns:
        Flexibility score (float, higher = more flexible)
    """
    lecturer = lecturers.get(lecturer_id)
    if not lecturer:
        return 0.0
    
    num_sections = len(lecturer_sections.get(lecturer_id, []))
    num_available_slots = len(lecturer.available_time_slots)
    
    if num_sections == 0:
        return 0.0
    
    # Score: available slots per section
    # If lecturer has many sections but few slots, score is low (inflexible)
    # If lecturer has few sections and many slots, score is high (flexible)
    flexibility = num_available_slots / max(num_sections, 1)
    
    return flexibility


def should_reassign_section(section_id: str,
                            conflict_type: str,
                            lecturer_sections: Dict[str, List[str]],
                            sections: Dict[str, ClassSection],
                            lecturers: Dict[str, Lecturer]) -> Tuple[bool, str]:
    """
    Determine if a section should be reassigned when conflicts arise.
    
    Decision criteria:
    1. Does the lecturer teach other sections?
    2. Is the lecturer flexible (has multiple available slots)?
    3. Is this section lower priority (e.g., elective vs core)?
    
    Args:
        section_id: The section that might need reassignment
        conflict_type: Type of conflict ("lecturer_time", "room", "cohort", etc.)
        lecturer_sections: Mapping of lecturer_id -> list of section_ids
        sections: Dictionary of all class sections
        lecturers: Dictionary of lecturer objects
        
    Returns:
        Tuple of (should_reassign: bool, reason: str)
    """
    section = sections.get(section_id)
    if not section:
        return False, "Section not found"
    
    lecturer_id = section.lecturer_id
    lecturer = lecturers.get(lecturer_id)
    
    if not lecturer:
        return False, "Lecturer not found"
    
    # Check if lecturer teaches multiple sections
    num_sections = len(lecturer_sections.get(lecturer_id, []))
    if num_sections <= 1:
        return False, "Lecturer teaches only this section (cannot reassign)"
    
    # Check accessibility
    num_available = len(lecturer.available_time_slots)
    if num_available < num_sections:
        return False, "Instructor has insufficient time slots for all sections"
    
    # If conflict is lecturer_time and we have flexibility, reassign
    if conflict_type == "lecturer_time":
        flexibility = get_lecturer_flexibility_score(lecturer_id, lecturer_sections, lecturers)
        if flexibility > 1.5:  # More than 1.5 slots per section
            return True, f"Lecturer is flexible (score: {flexibility:.2f})"
        elif flexibility > 1.0:
            return True, f"Lecturer has some flexibility (score: {flexibility:.2f})"
    
    return False, f"Cannot reassign section {section_id} due to constraints"


class FlexibilityManager:
    """
    Manages flexible assignment strategy for handling occupied lecturer slots.
    
    Tracks:
    - Which sections each lecturer teaches
    - Which time slots are occupied
    - Alternative arrangements when conflicts arise
    """
    
    def __init__(self, sections: Dict[str, ClassSection], 
                 lecturers: Dict[str, Lecturer]):
        self.sections = sections
        self.lecturers = lecturers
        self.lecturer_sections = build_lecturer_section_map(sections)
        self.flexibility_scores = {}
        
        # Pre-calculate flexibility scores for all lecturers
        for lecturer_id in lecturers:
            self.flexibility_scores[lecturer_id] = get_lecturer_flexibility_score(
                lecturer_id, self.lecturer_sections, lecturers
            )
    
    def get_reassignment_priority(self) -> List[str]:
        """
        Get section IDs sorted by priority for assignment.
        
        Assigns inflexible lecturers first (limited options),
        then flexible lecturers (more options available).
        
        Returns:
            List of section IDs sorted by assignment priority
        """
        section_priorities = []
        
        for section_id, section in self.sections.items():
            lecturer_id = section.lecturer_id
            flexibility_score = self.flexibility_scores.get(lecturer_id, 0)
            
            # Lower score = higher priority (assign first)
            # Also prioritize sections that have fixed constraints
            priority = flexibility_score
            
            if section.fixed_day is not None or section.fixed_slot is not None:
                # Fixed sections have even higher priority
                priority -= 100  # Negative score = highest priority
            
            section_priorities.append((section_id, priority))
        
        # Sort by priority (ascending = assign high-priority first)
        section_priorities.sort(key=lambda x: x[1])
        return [sec_id for sec_id, _ in section_priorities]
    
    def suggest_alternative_assignment(self, section_id: str, 
                                      blocked_day: int, 
                                      blocked_slot: int) -> Optional[Dict]:
        """
        Suggest alternative assignments when a section's preferred slot is blocked.
        
        Args:
            section_id: Section that needs alternative
            blocked_day: The blocked day
            blocked_slot: The blocked slot
            
        Returns:
            Dictionary with alternatives, or None if no alternatives exist
        """
        section = self.sections.get(section_id)
        if not section:
            return None
        
        lecturer = self.lecturers.get(section.lecturer_id)
        if not lecturer:
            return None
        
        # Get occupied slots for this lecturer
        occupied = set()
        for other_id, other_section in self.sections.items():
            if other_id != section_id and other_section.lecturer_id == section.lecturer_id:
                if other_section.fixed_day is not None and other_section.fixed_slot is not None:
                    occupied.add((other_section.fixed_day, other_section.fixed_slot))
        
        # Find alternatives
        alternatives = get_alternative_slots(
            lecturer, blocked_day, blocked_slot, occupied
        )
        
        return {
            "section_id": section_id,
            "lecturer": section.lecturer_id,
            "blocked_slot": (blocked_day, blocked_slot),
            "alternatives": alternatives[:5],  # Top 5 alternatives
            "num_alternatives": len(alternatives),
            "flexibility_score": self.flexibility_scores.get(section.lecturer_id, 0)
        }
