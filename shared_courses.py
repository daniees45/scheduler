"""
Shared course grouping logic:
- Identifies courses that should be scheduled at the same time across departments
- Supports course code aliases (COSC↔CSCD, INFT↔INFD, BBIS↔BIS)
- Supports fuzzy title matching (≥85% similarity)
"""

import csv
import os
from typing import Dict, List, Set, Tuple
from difflib import SequenceMatcher

def load_shared_course_data(
    curriculum_path: str = "csv/general/curriculum.csv",
    aliases_path: str = "csv/general/shared_course_aliases.csv"
) -> Tuple[Dict[str, str], Dict[str, List[str]]]:
    """
    Load shared-course grouping info.
    
    Returns:
        alias_map: {alias_code -> canonical_code}
        shared_groups: {canonical_key -> [section_ids]}
    """
    alias_map = {}
    if os.path.exists(aliases_path):
        with open(aliases_path, 'r', encoding='utf-8') as f:
            reader = csv.DictReader(f)
            for row in reader:
                canonical = row['canonical_code'].strip().upper()
                alias = row['alias_code'].strip().upper()
                if canonical and alias:
                    alias_map[alias] = canonical
    
    return alias_map


def normalize_code(code: str, alias_map: Dict[str, str]) -> str:
    """Normalize course code using alias map."""
    if not isinstance(code, str):
        return ""
    cleaned = " ".join(code.strip().upper().split())
    return alias_map.get(cleaned, cleaned)


def string_similarity(s1: str, s2: str) -> float:
    """Calculate similarity ratio (0-1) between two strings."""
    return SequenceMatcher(None, s1.lower(), s2.lower()).ratio()


def build_shared_course_groups(
    sections: List,  # List of ClassSection objects
    curriculum_path: str = "csv/general/curriculum.csv",
    aliases_path: str = "csv/general/shared_course_aliases.csv",
    title_similarity_threshold: float = 0.85
) -> Dict[str, str]:
    """
    Build mapping of section_id -> shared_group_id for courses that should align.
    
    Strategy:
    1. Group by (canonical_code, level, semester) - same course across depts
    2. Add title-based matching for similar courses
    3. Return section_id -> shared_group_id
    """
    
    alias_map = load_shared_course_data(curriculum_path, aliases_path)
    
    # Map: (canonical_code, level, semester) -> list of section IDs
    code_groups: Dict[Tuple[str, str, str], List[str]] = {}
    
    # Map: section_id -> (code, level, semester, title, type)
    section_meta = {}
    
    for section in sections:
        canonical_code = normalize_code(section.course_code, alias_map)
        level = section.course_level
        semester = section.semester or "?"
        key = (canonical_code, level, semester)
        
        if key not in code_groups:
            code_groups[key] = []
        code_groups[key].append(section.id)
        
        section_meta[section.id] = {
            'code': canonical_code,
            'level': level,
            'semester': semester,
            'title': section.section_title,
            'type': section.course_type,
            'course_type_orig': section.course_type,
        }
    
    # Assign shared group IDs
    section_to_group = {}
    group_counter = 0
    
    # Step 1: Group by exact code + level + semester
    for (code, level, semester), section_ids in code_groups.items():
        if len(section_ids) > 1:
            group_id = f"shared_code_{group_counter}"
            group_counter += 1
            for sec_id in section_ids:
                section_to_group[sec_id] = group_id
    
    # Step 2: Fuzzy title matching for unassigned sections
    assigned_sections = set(section_to_group.keys())
    unassigned = [sec_id for sec_id in section_meta.keys() if sec_id not in assigned_sections]
    
    for i, sec_id1 in enumerate(unassigned):
        if sec_id1 in section_to_group:
            continue
        
        meta1 = section_meta[sec_id1]
        matches = []
        
        for sec_id2 in unassigned[i+1:]:
            if sec_id2 in section_to_group:
                continue
            
            meta2 = section_meta[sec_id2]
            
            # Only match if level + semester match
            if meta1['level'] != meta2['level'] or meta1['semester'] != meta2['semester']:
                continue
            
            # Check title similarity
            similarity = string_similarity(meta1['title'], meta2['title'])
            if similarity >= title_similarity_threshold:
                matches.append((sec_id2, similarity))
        
        if matches:
            group_id = f"shared_title_{group_counter}"
            group_counter += 1
            section_to_group[sec_id1] = group_id
            for sec_id2, _ in matches:
                section_to_group[sec_id2] = group_id
    
    return section_to_group


def apply_shared_group_ids(sections: List, shared_group_map: Dict[str, str]) -> None:
    """Modify sections in-place to set shared_group_id attribute."""
    for section in sections:
        if section.id in shared_group_map:
            section.shared_group_id = shared_group_map[section.id]
