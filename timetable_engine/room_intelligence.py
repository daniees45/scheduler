from typing import List, Dict, Optional, Tuple
from dataclasses import dataclass, field
from enum import Enum

class RoomType(Enum):
    LECTURE_HALL = "lecture_hall"
    SEMINAR = "seminar"
    LAB = "lab"
    STUDIO = "studio"
    AUDITORIUM = "auditorium"
    TUTORIAL = "tutorial"

class RoomFeature(Enum):
    PROJECTOR = "projector"
    WHITEBOARD = "whiteboard"
    LAB_EQUIPMENT = "lab_equipment"
    AV_SETUP = "av_setup"
    WIFI = "wifi"
    SOUNDPROOF = "soundproof"
    ACCESSIBLE = "accessible"
    TIERED_SEATING = "tiered_seating"

@dataclass
class RoomProfile:
    """Enhanced room representation with capabilities"""
    name: str
    capacity: int
    department: Optional[str] = None
    room_type: RoomType = RoomType.LECTURE_HALL
    features: List[RoomFeature] = field(default_factory=list)
    building: Optional[str] = None
    floor: Optional[int] = None
    is_special: bool = False
    utilization_score: float = 0.0  # 0-1, how well room is being used
    suitability_cache: Dict = field(default_factory=dict)
    maintenance_schedule: Dict = field(default_factory=dict)  # day -> available_slots
    
    def has_feature(self, feature: RoomFeature) -> bool:
        return feature in self.features
    
    def get_capacity_utilization(self, course_enrollment: int) -> float:
        """Calculate how well course fits in room"""
        if self.capacity == 0:
            return 0.0
        
        utilization = course_enrollment / self.capacity
        
        # Ideal is 70-90% utilization
        if 0.7 <= utilization <= 0.9:
            return 1.0
        elif 0.5 <= utilization < 0.7:
            return 0.8
        elif 0.9 < utilization <= 1.0:
            return 0.85
        elif utilization > 1.0:
            return 0.0  # Over capacity
        else:
            return 0.6  # Under 50% utilized
    
    def get_feature_match_score(self, required_features: List[RoomFeature]) -> float:
        """Score how well room matches required features"""
        if not required_features:
            return 1.0
        
        matches = sum(1 for feature in required_features if self.has_feature(feature))
        return matches / len(required_features)

@dataclass
class CourseRequirement:
    """Course requirements for room assignment"""
    code: str
    enrollment: int
    room_type_preference: RoomType = RoomType.LECTURE_HALL
    required_features: List[RoomFeature] = field(default_factory=list)
    department: Optional[str] = None
    level: int = 100
    is_lab: bool = False
    is_practical: bool = False

class RoomIntelligenceEngine:
    """Intelligent room assignment and management"""
    
    def __init__(self):
        self.rooms: Dict[str, RoomProfile] = {}
        self.course_requirements: Dict[str, CourseRequirement] = {}
        self.assignment_history: List[Dict] = []
        self.utilization_stats: Dict = {}
    
    def add_room(self, room: RoomProfile) -> bool:
        """Register a room"""
        self.rooms[room.name] = room
        return True
    
    def add_course_requirement(self, requirement: CourseRequirement):
        """Register course room requirements"""
        self.course_requirements[requirement.code] = requirement
    
    def find_suitable_room(self, course_requirement: CourseRequirement,
                          available_rooms: List[RoomProfile] = None) -> Optional[RoomProfile]:
        """Find best room for a course"""
        if not available_rooms:
            available_rooms = list(self.rooms.values())
        
        # Filter rooms that meet minimum requirements
        suitable = []
        for room in available_rooms:
            # Must have enough capacity
            if room.capacity < course_requirement.enrollment:
                continue
            
            # Type matching (prefer exact match, allow lecture hall as fallback)
            if room.room_type != course_requirement.room_type_preference:
                if room.room_type != RoomType.LECTURE_HALL:
                    continue
            
            # Department matching (if specified)
            if course_requirement.department and room.department:
                if course_requirement.department.lower() not in room.department.lower():
                    if not any(prefix in room.department.lower() for prefix in ["general", "shared"]):
                        continue
            
            suitable.append(room)
        
        if not suitable:
            return None
        
        # Score and rank by suitability
        scored_rooms = []
        for room in suitable:
            score = self._calculate_room_suitability_score(room, course_requirement)
            scored_rooms.append((room, score))
        
        # Sort by score (highest first)
        scored_rooms.sort(key=lambda x: x[1], reverse=True)
        
        return scored_rooms[0][0] if scored_rooms else None
    
    def find_multiple_suitable_rooms(self, course_requirement: CourseRequirement,
                                    count: int = 3) -> List[Tuple[RoomProfile, float]]:
        """Find multiple suitable rooms ranked by score"""
        available_rooms = list(self.rooms.values())
        
        suitable = []
        for room in available_rooms:
            if room.capacity < course_requirement.enrollment:
                continue
            
            if room.room_type != course_requirement.room_type_preference:
                if room.room_type != RoomType.LECTURE_HALL:
                    continue
            
            score = self._calculate_room_suitability_score(room, course_requirement)
            suitable.append((room, score))
        
        suitable.sort(key=lambda x: x[1], reverse=True)
        return suitable[:count]
    
    def _calculate_room_suitability_score(self, room: RoomProfile,
                                        course_requirement: CourseRequirement) -> float:
        """Calculate room suitability score (0-100)"""
        scores = []
        weights = []
        
        # Capacity utilization score (40% weight)
        capacity_score = room.get_capacity_utilization(course_requirement.enrollment)
        scores.append(capacity_score * 100)
        weights.append(0.4)
        
        # Feature match score (30% weight)
        feature_score = room.get_feature_match_score(course_requirement.required_features)
        scores.append(feature_score * 100)
        weights.append(0.3)
        
        # Room type match score (20% weight)
        type_match = 100 if room.room_type == course_requirement.room_type_preference else 60
        scores.append(type_match)
        weights.append(0.2)
        
        # Utilization efficiency (10% weight)
        utilization_score = room.utilization_score * 100
        scores.append(utilization_score)
        weights.append(0.1)
        
        # Weighted average
        total_score = sum(s * w for s, w in zip(scores, weights))
        return round(total_score, 2)
    
    def get_room_building_distance(self, room1: str, room2: str) -> float:
        """Calculate distance between two rooms (simplified)"""
        if room1 not in self.rooms or room2 not in self.rooms:
            return float('inf')
        
        r1 = self.rooms[room1]
        r2 = self.rooms[room2]
        
        # Same room = 0
        if r1.name == r2.name:
            return 0
        
        # Different buildings = 100 (far)
        if r1.building != r2.building:
            return 100
        
        # Same floor = 10
        if r1.floor == r2.floor:
            return 10
        
        # Adjacent floors = 30
        if abs((r1.floor or 0) - (r2.floor or 0)) == 1:
            return 30
        
        # Multiple floors = 50
        return 50
    
    def optimize_room_consolidation(self, schedule_items: List[Dict]) -> Dict:
        """Consolidate courses of same level/semester to minimize building travel"""
        student_cohorts = {}
        
        for item in schedule_items:
            key = f"level_{item.get('level')}_sem_{item.get('semester')}"
            if key not in student_cohorts:
                student_cohorts[key] = []
            student_cohorts[key].append(item)
        
        consolidation_recommendations = []
        
        for cohort_key, items in student_cohorts.items():
            if len(items) < 2:
                continue
            
            # Find room with best centrality
            room_distances = {}
            for item in items:
                for room_name in self.rooms.keys():
                    if room_name not in room_distances:
                        room_distances[room_name] = 0
                    
                    current_room = item.get('room')
                    if current_room:
                        room_distances[room_name] += self.get_room_building_distance(current_room, room_name)
            
            if room_distances:
                best_room = min(room_distances, key=room_distances.get)
                consolidation_recommendations.append({
                    "cohort": cohort_key,
                    "recommended_room": best_room,
                    "total_distance_saved": sum(room_distances.values()),
                    "affected_courses": len(items)
                })
        
        return {
            "total_recommendations": len(consolidation_recommendations),
            "recommendations": consolidation_recommendations
        }
    
    def calculate_room_utilization_stats(self, schedule_items: List[Dict]) -> Dict:
        """Calculate statistics on room utilization"""
        room_usage = {}
        room_hours = {}
        
        # Assign hours value to each slot
        slot_hours = {
            "7:00am - 9:30am": 2.5,
            "10:00am - 12:30pm": 2.5,
            "2:00pm - 4:30pm": 2.5,
            "5:00pm - 6:00pm": 1.0
        }
        
        for item in schedule_items:
            room = item.get('room')
            if not room or room not in self.rooms:
                continue
            
            if room not in room_usage:
                room_usage[room] = 0
                room_hours[room] = 0
            
            room_usage[room] += 1
            room_hours[room] += slot_hours.get(item.get('time_slot'), 2.5)
        
        # Calculate efficiency for each room
        room_stats = {}
        total_available_hours = 20 * 5  # 4 slots * 5 days = 20 hours/week
        
        for room_name in self.rooms.keys():
            if room_name not in room_usage:
                room_stats[room_name] = {
                    "room": room_name,
                    "courses": 0,
                    "hours_used": 0,
                    "utilization_percent": 0,
                    "efficiency": "unused"
                }
            else:
                hours = room_hours[room_name]
                utilization = (hours / total_available_hours) * 100
                
                if utilization > 80:
                    efficiency = "high"
                elif utilization > 50:
                    efficiency = "good"
                elif utilization > 20:
                    efficiency = "moderate"
                else:
                    efficiency = "low"
                
                room_stats[room_name] = {
                    "room": room_name,
                    "courses": room_usage[room_name],
                    "hours_used": round(hours, 1),
                    "utilization_percent": round(utilization, 1),
                    "efficiency": efficiency
                }
        
        return {
            "total_rooms": len(self.rooms),
            "utilized_rooms": len([r for r in room_stats.values() if r["courses"] > 0]),
            "average_utilization": round(sum(r["hours_used"] for r in room_stats.values()) / len(self.rooms) / total_available_hours * 100, 1),
            "room_stats": room_stats
        }
    
    def get_room_recommendations_report(self) -> Dict:
        """Generate comprehensive room recommendations report"""
        return {
            "total_rooms": len(self.rooms),
            "rooms_by_type": self._count_by_type(),
            "rooms_by_capacity": self._analyze_capacity_distribution(),
            "rooms_by_building": self._count_by_building(),
            "rooms_with_special_features": self._rooms_with_features()
        }
    
    def _count_by_type(self) -> Dict:
        """Count rooms by type"""
        counts = {}
        for room in self.rooms.values():
            room_type = room.room_type.value
            counts[room_type] = counts.get(room_type, 0) + 1
        return counts
    
    def _analyze_capacity_distribution(self) -> Dict:
        """Analyze room capacity distribution"""
        capacities = sorted([room.capacity for room in self.rooms.values()])
        
        return {
            "min_capacity": min(capacities) if capacities else 0,
            "max_capacity": max(capacities) if capacities else 0,
            "average_capacity": round(sum(capacities) / len(capacities), 1) if capacities else 0,
            "median_capacity": capacities[len(capacities)//2] if capacities else 0
        }
    
    def _count_by_building(self) -> Dict:
        """Count rooms by building"""
        buildings = {}
        for room in self.rooms.values():
            building = room.building or "Unknown"
            buildings[building] = buildings.get(building, 0) + 1
        return buildings
    
    def _rooms_with_features(self) -> Dict[str, int]:
        """Count rooms with specific features"""
        feature_count = {}
        for feature in RoomFeature:
            count = sum(1 for room in self.rooms.values() if room.has_feature(feature))
            if count > 0:
                feature_count[feature.value] = count
        return feature_count
