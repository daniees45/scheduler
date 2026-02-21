"""
Personal Scheduler Database Integration
Provides personalized schedule recommendations based on enrolled courses and learning from patterns.
"""
import mysql.connector
from datetime import datetime, time
from typing import List, Dict, Tuple, Optional
import json
import os
from personal_scheduler import BusyBlock, Suggestion, build_personal_schedule, parse_time_safe


class PersonalSchedulerDB:
    """Integrates personal scheduler with database for enrolled courses and learning."""
    
    def __init__(self, db_config: Dict[str, str]):
        """Initialize with database configuration."""
        self.db_config = db_config
        self.learning_file = "data/enrollment_patterns.json"
        
    def connect(self):
        """Create database connection."""
        return mysql.connector.connect(**self.db_config)
    
    def get_enrolled_courses_schedule(self, user_id: int) -> List[BusyBlock]:
        """
        Get the schedule blocks for a student's enrolled courses.
        Returns list of BusyBlock objects representing class times.
        """
        conn = self.connect()
        cursor = conn.cursor(dictionary=True)
        
        query = """
            SELECT 
                c.course_code, 
                c.course_title, 
                s.assigned_day, 
                s.assigned_time,
                e.semester,
                l.name as lecturer_name
            FROM student_enrollments e
            JOIN courses c ON e.course_id = c.id
            LEFT JOIN sections s ON s.course_id = c.id
            LEFT JOIN lecturers l ON s.lecturer_id = l.id
            WHERE e.user_id = %s
            AND s.assigned_day IS NOT NULL 
            AND s.assigned_time IS NOT NULL
        """
        
        cursor.execute(query, (user_id,))
        results = cursor.fetchall()
        
        busy_blocks = []
        for row in results:
            if not row['assigned_day'] or not row['assigned_time']:
                continue
                
            # Parse time range (e.g., "09:00-11:00" or "9:00 AM - 11:00 AM")
            time_str = row['assigned_time']
            try:
                if '-' in time_str:
                    parts = time_str.split('-')
                    start_time = parse_time_safe(parts[0].strip())
                    end_time = parse_time_safe(parts[1].strip())
                    
                    if start_time and end_time:
                        label = f"{row['course_code']}: {row['course_title']}"
                        block = BusyBlock(
                            day=row['assigned_day'],
                            start=start_time,
                            end=end_time,
                            label=label,
                            source='enrolled_course'
                        )
                        busy_blocks.append(block)
            except Exception as e:
                print(f"Error parsing time for {row['course_code']}: {e}")
                continue
        
        cursor.close()
        conn.close()
        
        return busy_blocks
    
    def get_student_info(self, user_id: int) -> Dict:
        """Get student information including department, level, etc."""
        conn = self.connect()
        cursor = conn.cursor(dictionary=True)
        
        query = """
            SELECT name, email, department, level, role
            FROM users
            WHERE id = %s
        """
        
        cursor.execute(query, (user_id,))
        result = cursor.fetchone()
        
        cursor.close()
        conn.close()
        
        return result or {}
    
    def get_enrolled_course_ids(self, user_id: int) -> List[int]:
        """Get list of course IDs the student is enrolled in."""
        conn = self.connect()
        cursor = conn.cursor()
        
        query = "SELECT course_id FROM student_enrollments WHERE user_id = %s"
        cursor.execute(query, (user_id,))
        
        course_ids = [row[0] for row in cursor.fetchall()]
        
        cursor.close()
        conn.close()
        
        return course_ids
    
    def record_enrollment_pattern(self, user_id: int, course_id: int, metadata: Dict):
        """
        Record enrollment pattern for learning.
        Tracks user preferences, time of enrollment, and course characteristics.
        """
        pattern = {
            'user_id': user_id,
            'course_id': course_id,
            'timestamp': datetime.now().isoformat(),
            'metadata': metadata
        }
        
        # Load existing patterns
        patterns = self._load_patterns()
        
        # Add new pattern
        patterns.append(pattern)
        
        # Keep only last 1000 patterns
        patterns = patterns[-1000:]
        
        # Save patterns
        self._save_patterns(patterns)
    
    def _load_patterns(self) -> List[Dict]:
        """Load enrollment patterns from file."""
        if os.path.exists(self.learning_file):
            try:
                with open(self.learning_file, 'r') as f:
                    return json.load(f)
            except:
                return []
        return []
    
    def _save_patterns(self, patterns: List[Dict]):
        """Save enrollment patterns to file."""
        os.makedirs(os.path.dirname(self.learning_file) or '.', exist_ok=True)
        with open(self.learning_file, 'w') as f:
            json.dump(patterns, f, indent=2)
    
    def get_course_recommendations(self, user_id: int, limit: int = 5) -> List[Dict]:
        """
        Get personalized course recommendations based on:
        1. Student's enrolled courses
        2. Historical enrollment patterns
        3. Department and level matching
        4. Time slot preferences learned from history
        """
        conn = self.connect()
        cursor = conn.cursor(dictionary=True)
        
        # Get student info
        student_info = self.get_student_info(user_id)
        if not student_info:
            return []
        
        department = student_info.get('department')
        level = student_info.get('level')
        
        # Get already enrolled courses
        enrolled_ids = self.get_enrolled_course_ids(user_id)
        
        # Get available courses not yet enrolled
        placeholders = ','.join(['%s'] * len(enrolled_ids)) if enrolled_ids else '0'
        
        query = f"""
            SELECT 
                c.id, 
                c.course_code, 
                c.course_title, 
                c.type,
                c.department,
                c.level,
                l.name as lecturer_name,
                COUNT(e.id) as enrollment_count
            FROM courses c
            LEFT JOIN lecturers l ON c.lecturer_id = l.id
            LEFT JOIN student_enrollments e ON c.id = e.course_id
            WHERE c.level = %s
            AND (c.type = 'General' OR c.department = %s)
            AND c.id NOT IN ({placeholders})
            GROUP BY c.id
            ORDER BY enrollment_count DESC, c.course_code
            LIMIT %s
        """
        
        params = [level, department] + enrolled_ids + [limit]
        cursor.execute(query, params)
        
        recommendations = cursor.fetchall()
        
        # Enhance recommendations with learning insights
        patterns = self._load_patterns()
        for rec in recommendations:
            # Calculate recommendation score based on patterns
            rec['score'] = self._calculate_recommendation_score(
                rec, 
                student_info, 
                patterns
            )
            rec['reason'] = self._generate_recommendation_reason(
                rec, 
                student_info, 
                patterns
            )
        
        # Sort by score
        recommendations.sort(key=lambda x: x.get('score', 0), reverse=True)
        
        cursor.close()
        conn.close()
        
        return recommendations
    
    def _calculate_recommendation_score(self, course: Dict, student_info: Dict, patterns: List[Dict]) -> float:
        """Calculate recommendation score based on various factors."""
        score = 50.0  # Base score
        
        # Type bonus (General courses are universally applicable)
        if course.get('type') == 'General':
            score += 10.0
        
        # Department match bonus
        if course.get('department') == student_info.get('department'):
            score += 15.0
        
        # Popularity bonus (more students enrolled)
        enrollment_count = course.get('enrollment_count', 0)
        score += min(enrollment_count * 2, 20.0)
        
        # Pattern-based bonus (similar students enrolled in this)
        similar_enrollments = sum(
            1 for p in patterns 
            if p.get('course_id') == course['id']
            and p.get('metadata', {}).get('department') == student_info.get('department')
        )
        score += min(similar_enrollments * 5, 15.0)
        
        return score
    
    def _generate_recommendation_reason(self, course: Dict, student_info: Dict, patterns: List[Dict]) -> str:
        """Generate human-readable reason for recommendation."""
        reasons = []
        
        if course.get('type') == 'General':
            reasons.append("general course for all students")
        
        if course.get('department') == student_info.get('department'):
            reasons.append(f"matches your {student_info.get('department')} department")
        
        enrollment_count = course.get('enrollment_count', 0)
        if enrollment_count > 10:
            reasons.append(f"popular course ({enrollment_count} students enrolled)")
        
        if reasons:
            return "Recommended: " + ", ".join(reasons)
        
        return "Recommended based on your profile"
    
    def generate_study_schedule_suggestions(self, user_id: int, base_dir: str = "./") -> Tuple[List[BusyBlock], List[Suggestion]]:
        """
        Generate personalized study schedule suggestions.
        Takes enrolled courses into account and finds optimal study time slots.
        """
        # Get enrolled course schedules
        enrolled_blocks = self.get_enrolled_courses_schedule(user_id)
        
        # Get student info to determine role
        student_info = self.get_student_info(user_id)
        role = student_info.get('role', 'student')
        
        # Build personal schedule with enrolled courses as busy blocks
        busy_blocks, suggestions = build_personal_schedule(
            base_dir=base_dir,
            personal_events=enrolled_blocks,
            role=role,
            min_minutes=60,
            day_start=time(7, 0),
            day_end=time(21, 0)
        )
        
        # Enhance suggestions with course-specific study recommendations
        enrolled_course_ids = self.get_enrolled_course_ids(user_id)
        
        for suggestion in suggestions[:5]:  # Top 5 suggestions
            suggestion.title = "Recommended Study Time"
            suggestion.reason = f"Free time slot suitable for studying. {suggestion.reason}"
        
        return busy_blocks, suggestions


def create_scheduler_from_env() -> PersonalSchedulerDB:
    """Create PersonalSchedulerDB instance from environment variables or defaults."""
    db_config = {
        'host': os.getenv('DB_HOST', 'localhost'),
        'user': os.getenv('DB_USER', 'root'),
        'password': os.getenv('DB_PASSWORD', ''),
        'database': os.getenv('DB_NAME', 'vvu_scheduler')
    }
    return PersonalSchedulerDB(db_config)


if __name__ == '__main__':
    # Example usage
    scheduler = create_scheduler_from_env()
    
    # Example: Get recommendations for user 1
    recommendations = scheduler.get_course_recommendations(user_id=1, limit=5)
    print("Course Recommendations:")
    for rec in recommendations:
        print(f"  - {rec['course_code']}: {rec['course_title']}")
        print(f"    Reason: {rec['reason']}")
        print(f"    Score: {rec['score']:.1f}")
        print()
