import re
from typing import List, Dict, Any, Optional
from .personal_scheduler import PersonalScheduler
from .school_scheduler import SchoolScheduler

class LLMInterfaceRouter:
    """A natural language processing layer that routes user queries to the appropriate engine methods"""
    
    def __init__(self, personal_scheduler: PersonalScheduler, school_scheduler: SchoolScheduler):
        self.ps = personal_scheduler
        self.ss = school_scheduler

    def process_query(self, query: str, context: Dict[str, Any] = None) -> str:
        query = query.lower().strip()
        context = context or {}
        
        # 1. Identify Intent
        
        # INTENT: "Where is my next class?"
        if any(kw in query for kw in ["where", "next", "class", "next class"]):
            return self._handle_next_class(context)
            
        # INTENT: "Suggest a study gap"
        if any(kw in query for kw in ["study", "gap", "suggest", "study slot"]):
            return self._handle_study_suggestions(context)
            
        # INTENT: "Are there free rooms?"
        if any(kw in query for kw in ["free rooms", "available rooms", "empty rooms"]):
            return self._handle_room_queries(query)
            
        # INTENT: "Which lecturer is in [Room]?"
        if "lecturer" in query and "room" in query:
             return self._handle_lecturer_lookup(query)

        return "I'm sorry, I couldn't understand that query. You can ask things like 'Where is my next class?' or 'Suggest some study gaps'."

    def _handle_next_class(self, context) -> str:
        if not self.ps.profile:
            return "Please create or select a personal profile first."
        
        # Logic: Current time is hard to simulate without real-time, 
        # so we show the first class of the day or week from the filtered schedule.
        # This is a placeholder for real time-based query
        return "Based on your schedule, your next major lecture is [Course Code] in [Room] at [Time]."

    def _handle_study_suggestions(self, context) -> str:
        if not self.ps.profile:
            return "Please create a profile first so I can see your gaps."
        
        # Use existing logic
        suggestions = self.ps.generate_study_suggestions(context.get('filtered_schedule', []))
        if not suggestions:
            return "Your schedule is currently packed! No major gaps found."
            
        resp = "I found some great times for you to hit the books:\n"
        for s in suggestions[:3]:
            resp += f" - {s.day} at {s.time_slot} ({s.name})\n"
        return resp

    def _handle_room_queries(self, query) -> str:
        # Extract day/time if possible
        # e.g., "Rooms free at 10 AM Monday"
        return "Checking room availability... Found [Room A], [Room B] are unoccupied in that slot."

    def _handle_lecturer_lookup(self, query) -> str:
        return "Checking the master timetable... [Lecturer Name] is currently scheduled there."
